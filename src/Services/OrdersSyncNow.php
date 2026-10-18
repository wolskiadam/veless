<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Queue\JobRunner;
use Pase\Queue\Queue;
use Pase\Repository\SettingsRepository;
use Pase\Support\Logger;

/**
 * Przycisk „Pobierz zamówienia teraz” na liście zamówień (orders_sync_now.php).
 *
 * Cykliczne pobieranie (Scheduler, SYNC_ORDERS_EVERY - np. co 10 minut) zostaje bez zmian; tu robimy to samo
 * od razu na żądanie operatora, który wie, że zamówienie już jest:
 *   1. Pobranie nowych zamówień ze sklepów i z Allegro (Scheduler::pullOrdersNow) - kolejkuje import,
 *      dedup chroni przed dublem z cronem lub webhookiem.
 *   2. Przetworzenie zakolejkowanych importów zamówień od razu (JobRunner), w limicie czasu żądania -
 *      resztę dokończy worker w tle.
 *   3. ERLI i TikTok Shop (osobne przebiegi workera, razem ze stanami i przesyłkami) - kasujemy ich znacznik,
 *      więc sprawdzi je najbliższy przebieg crona (co minutę), zamiast czekać pełny interwał.
 */
final class OrdersSyncNow
{
    /** Ile sekund żądania może zająć przetwarzanie kolejki - zostawia zapas na limit czasu PHP na hostingu. */
    public const PROCESS_BUDGET_SEC = 20;

    /** @param array<string,mixed> $config pełny config z config/config.php */
    public function __construct(
        private readonly PDO $pdo,
        private readonly SettingsRepository $settings,
        private readonly array $config = [],
        private readonly ?string $root = null
    ) {}

    /**
     * @return array{ok:bool, message:string, queued:int, imported:int, failed:int, waiting:int}
     */
    public function run(): array
    {
        $out = ['ok' => false, 'message' => '', 'queued' => 0, 'imported' => 0, 'failed' => 0, 'waiting' => 0];

        if ($this->root !== null && (new Handover($this->pdo, $this->settings, $this->root))->isAway()) {
            $out['message'] = 'Praca jest przeniesiona na drugą instalację - tam pobierają się zamówienia.';
            return $out;
        }
        $control = new ProcessControl($this->settings);
        foreach (['worker', 'orders'] as $process) {
            if ($control->isPaused($process)) {
                $out['message'] = 'Pobieranie zamówień jest wstrzymane (System → Obciążenie serwera). Wznów je, żeby pobrać zamówienia.';
                return $out;
            }
        }

        $queue = new Queue($this->pdo);
        try {
            $out['queued'] = (new Scheduler($this->pdo, $this->settings, $queue, $this->config))->pullOrdersNow();
        } catch (\Throwable $e) {
            Logger::warn('Pobierz zamówienia teraz: ' . $e->getMessage());
            $out['message'] = 'Nie udało się pobrać zamówień: ' . $e->getMessage();
            return $out;
        }
        $this->settings->setMany(['ERLI_RUN_AT' => '0', 'TIKTOKSHOP_RUN_AT' => '0']);

        if (!$control->isPaused('queue')) {
            $jobs = new JobRunner($this->pdo, $this->config);
            $deadline = microtime(true) + self::PROCESS_BUDGET_SEC;
            while (microtime(true) < $deadline && ($job = $queue->reserveNext(JobRunner::ORDER_IMPORT_TYPES)) !== null) {
                try {
                    $jobs->handle((string) $job['job_type'], json_decode((string) $job['payload'], true) ?: []);
                    $queue->complete((int) $job['id']);
                    $out['imported']++;
                } catch (\Throwable $e) {
                    // Ponowienie z opóźnieniem jak w workerze (Queue::fail).
                    $queue->fail($job, $e->getMessage());
                    $out['failed']++;
                }
            }
        }
        $out['waiting'] = $this->waitingImports();

        $out['ok'] = true;
        $out['message'] = $this->summary($out);
        Logger::info('Pobierz zamówienia teraz: ' . $out['message']);
        return $out;
    }

    /** Importy zamówień, które jeszcze czekają w kolejce (dokończy je worker). */
    private function waitingImports(): int
    {
        $types = JobRunner::ORDER_IMPORT_TYPES;
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM job_queue WHERE status IN (?, ?) AND job_type IN ('
            . implode(',', array_fill(0, count($types), '?')) . ')');
        $stmt->execute(['pending', 'reserved', ...$types]);
        return (int) $stmt->fetchColumn();
    }

    /** @param array{queued:int, imported:int, failed:int, waiting:int} $r */
    private function summary(array $r): string
    {
        if ($r['queued'] === 0 && $r['imported'] === 0 && $r['waiting'] === 0) {
            return 'Sprawdzono sklepy i Allegro - brak nowych zamówień.';
        }
        $msg = $r['imported'] > 0 ? "Pobrano zamówienia: {$r['imported']}." : 'Sprawdzono sklepy i Allegro.';
        if ($r['waiting'] > 0) {
            $msg .= " Jeszcze {$r['waiting']} w kolejce - dopiszą się w ciągu minuty.";
        }
        if ($r['failed'] > 0) {
            $msg .= " Nie udało się: {$r['failed']} (ponowienie w tle).";
        }
        return $msg;
    }
}
