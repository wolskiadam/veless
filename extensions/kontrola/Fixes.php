<?php
declare(strict_types=1);

namespace PaseExt\Kontrola;

use PDO;
use Pase\Queue\Queue;

/**
 * Przyciski „Napraw” przy sprawdzeniach. Każda naprawa robi to samo, co operator zrobiłby ręcznie
 * w innym miejscu panelu (Kolejka → Ponów, Magazyn → wyślij stan, Zamówienie → Sprawdź status przesyłki).
 * Nic nie usuwa i nie zmienia zamówień.
 */
final class Fixes
{
    /**
     * @param (\Closure(): object)|null $tracking śledzenie przesyłek (checkOne); null = z konfiguracji Allegro
     * @param object|null $queue kolejka zadań (enqueue); null = Pase\Queue\Queue
     */
    public function __construct(private readonly PDO $pdo, private readonly ?\Closure $tracking = null, private readonly ?object $queue = null) {}

    /** @return string komunikat dla operatora */
    public function run(string $id, int $now): string
    {
        if ($id === 'queue.retry') {
            $st = $this->pdo->prepare("UPDATE job_queue SET status = 'pending', attempts = 0, reserved_at = NULL, available_at = ?, last_error = NULL
                WHERE status = 'failed' AND updated_at >= ?");
            $st->execute([gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $now - 7 * 86400)]);
            return 'Ponowiono ' . Checks::plural($st->rowCount(), 'zadanie', 'zadania', 'zadań') . '. Wykonają się w najbliższym przebiegu crona.';
        }
        if ($id === 'queue.unstick') {
            $st = $this->pdo->prepare("UPDATE job_queue SET status = 'pending', reserved_at = NULL, available_at = ? WHERE status = 'reserved' AND reserved_at < ?");
            $st->execute([gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $now - 20 * 60)]);
            return 'Odblokowano ' . Checks::plural($st->rowCount(), 'zadanie', 'zadania', 'zadań') . '.';
        }
        if (str_starts_with($id, 'stock.push:')) {
            $channel = substr($id, strlen('stock.push:'));
            $store = new Store($this->pdo);
            $rows = $store->stock($channel);
            $queue = $this->queue ?? new Queue($this->pdo);
            $ids = [];
            foreach ($rows as $r) {
                $pid = (int) $r['product_id'];
                if (isset($ids[$pid])) {
                    continue;
                }
                $ids[$pid] = true;
                // Ten sam rodzaj zadania co zapis stanu na karcie produktu: stan z CRM do sklepu i na Allegro.
                $queue->enqueue('woo.stock.push', ['product_id' => $pid, 'what' => 'stock'], 'woo.stock.push:' . $pid . ':kontrola:' . $now);
            }
            $store->markPushed($channel, array_keys($ids), $now);
            return $ids === [] ? 'Nie ma rozbieżności do wysłania.'
                : 'Wysłano stan z CRM dla ' . Checks::plural(count($ids), 'produktu', 'produktów', 'produktów') . '. Kolejne sprawdzenie pokaże, czy się zgadza.';
        }
        if ($id === 'tracking.refresh') {
            $st = $this->pdo->prepare("SELECT id FROM shipments WHERE status = 'created' AND waybill_no IS NOT NULL AND waybill_no <> ''
                AND (tracking_status IS NULL OR tracking_status = '') AND created_at <= ? ORDER BY created_at DESC LIMIT 25");
            $st->execute([gmdate('Y-m-d H:i:s', $now - 86400)]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            if ($ids === []) {
                return 'Nie ma paczek do sprawdzenia.';
            }
            $tracker = $this->tracking !== null ? ($this->tracking)() : \Pase\Support\HealthBridge::tracker($this->pdo);
            $found = 0;
            foreach ($ids as $sid) {
                try {
                    $r = $tracker->checkOne($sid);
                    if (!empty($r['status'])) {
                        $found++;
                    }
                } catch (\Throwable) {
                }
            }
            return 'Sprawdzono ' . Checks::plural(count($ids), 'paczkę', 'paczki', 'paczek') . ', status ma teraz ' . $found . '.'
                . ($found < count($ids) ? ' Reszty przewoźnik nadal nie zna - sprawdź numer na stronie przewoźnika.' : '');
        }
        throw new \RuntimeException('Nieznana naprawa.');
    }
}
