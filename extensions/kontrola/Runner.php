<?php
declare(strict_types=1);

namespace PaseExt\Kontrola;

use Pase\Repository\SettingsRepository;

/**
 * Przebieg sprawdzeń. W tle (cron.tick co minutę) przebieg zaczyna się co N minut z ustawień i idzie porcjami:
 * w jednej minucie najwyżej ~25 s sprawdzeń, reszta w kolejnych - test połączenia ze sklepem potrafi czekać 30 s,
 * a worker ma jeden przebieg na minutę. Przycisk „Sprawdź teraz” robi wszystko od razu.
 *
 * Po skończonym przebiegu: mail, gdy pojawiło się (albo zniknęło) czerwone światło. Ten sam zestaw czerwonych
 * sprawdzeń przypomina się najwyżej raz na dobę.
 */
final class Runner
{
    public const RUN_KEY = 'EXT_KONTROLA_RUN';
    public const ALERT_KEY = 'EXT_KONTROLA_ALERT';
    private const TICK_BUDGET = 25.0;

    /**
     * @param array<string,mixed> $cfg ustawienia rozszerzenia
     * @param (\Closure(string,string,string): bool)|null $mail wysyłka (adres, temat, HTML) -> wysłano
     */
    public function __construct(
        private readonly Checks $checks,
        private readonly Store $store,
        private readonly SettingsRepository $settings,
        private readonly array $cfg,
        private readonly ?\Closure $mail = null,
    ) {}

    /** Co ile minut (0 = tylko ręcznie). */
    public function every(): int
    {
        return max(0, (int) ($this->cfg['every'] ?? 60));
    }

    /** @return array{started:int,finished:int,pending:list<string>,by:string} */
    public function state(): array
    {
        $s = json_decode((string) ($this->settings->get(self::RUN_KEY, '') ?? ''), true);
        $s = is_array($s) ? $s : [];
        return ['started' => (int) ($s['started'] ?? 0), 'finished' => (int) ($s['finished'] ?? 0),
            'pending' => array_values(array_filter((array) ($s['pending'] ?? []), 'is_string')), 'by' => (string) ($s['by'] ?? '')];
    }

    private function saveState(array $s): void
    {
        $this->settings->setMany([self::RUN_KEY => (string) json_encode($s, JSON_UNESCAPED_UNICODE)]);
    }

    /** Krok pracy w tle. @return string|null co zrobiono (do logu) */
    public function tick(int $now): ?string
    {
        $s = $this->state();
        if ($s['pending'] === []) {
            $every = $this->every();
            if ($every <= 0 || $now - $s['started'] < $every * 60) {
                return null;
            }
            $s = $this->start($now, 'cron');
        } elseif ($now - $s['started'] > 3 * 3600) {
            $s = $this->start($now, 'cron');   // przerwany przebieg (np. padł worker) - od nowa
        }
        $done = $this->step($s, $now, self::TICK_BUDGET);
        return $done > 0 ? "Kontrola stanu: sprawdzono {$done}" . ($this->state()['pending'] === [] ? ', koniec przebiegu.' : '.') : null;
    }

    /** Wszystkie sprawdzenia od razu (przycisk „Sprawdź teraz”). */
    public function runAll(int $now, string $by): void
    {
        $this->step($this->start($now, $by), $now, INF);
    }

    private function start(int $now, string $by): array
    {
        $s = ['started' => $now, 'finished' => $this->state()['finished'], 'pending' => array_keys($this->checks->definitions()), 'by' => $by];
        // Znacznik przed pracą: błąd w sprawdzeniu nie może zaczynać przebiegu co minutę.
        $this->saveState($s);
        return $s;
    }

    /** @return int ile sprawdzeń zrobiono */
    private function step(array $s, int $now, float $budget): int
    {
        $defs = $this->checks->definitions();
        $t0 = microtime(true);
        $done = 0;
        while ($s['pending'] !== [] && microtime(true) - $t0 < $budget) {
            $id = array_shift($s['pending']);
            if (isset($defs[$id])) {
                $this->store->save($this->checks->run($id, $defs[$id]), $now);
                $done++;
            }
            $this->saveState($s);
        }
        if ($s['pending'] === []) {
            $s['finished'] = $now;
            $this->saveState($s);
            $this->store->prune(array_keys($defs));
            $this->alert($now);
        }
        return $done;
    }

    /** Wyniki do strony: zapisane, a sprawdzenia „na żywo” (cron, kolejka) przeliczone teraz. @return array<string,array<string,mixed>> */
    public function results(int $now): array
    {
        $saved = $this->store->results();
        $out = [];
        foreach ($this->checks->definitions() as $id => $def) {
            if (!empty($def['live'])) {
                $out[$id] = $this->checks->run($id, $def) + ['checked_at' => gmdate('Y-m-d H:i:s', $now), 'live' => true, 'since' => $saved[$id]['since'] ?? ''];
            } elseif (isset($saved[$id])) {
                $out[$id] = $saved[$id];
            } else {
                $out[$id] = ['id' => $id, 'group' => $def['group'], 'title' => $def['title'], 'state' => 'none', 'summary' => 'Jeszcze nie sprawdzono.'];
            }
        }
        return $out;
    }

    public function recipient(): string
    {
        $to = trim((string) ($this->cfg['email'] ?? ''));
        if ($to === '') {
            $to = trim((string) ($this->settings->get('OPS_ALERT_EMAIL', '') ?? ''));
        }
        return filter_var($to, FILTER_VALIDATE_EMAIL) ? $to : '';
    }

    /** Mail po przebiegu, gdy zmienił się zestaw czerwonych sprawdzeń. */
    private function alert(int $now): void
    {
        if (($this->cfg['alerts'] ?? '1') === '0' || $this->mail === null) {
            return;
        }
        $bad = array_filter($this->store->results(), static fn(array $r): bool => $r['state'] === 'bad');
        ksort($bad);
        $fp = implode(',', array_keys($bad));
        $prev = json_decode((string) ($this->settings->get(self::ALERT_KEY, '') ?? ''), true);
        $prev = is_array($prev) ? $prev : ['fp' => '', 'at' => 0];
        $changed = $fp !== (string) ($prev['fp'] ?? '');
        $remind = $fp !== '' && !$changed && $now - (int) ($prev['at'] ?? 0) >= 86400;
        if (!$changed && !$remind) {
            return;
        }
        $to = $this->recipient();
        // Zapis przed wysyłką: awaria SMTP nie może sprawić, że mail idzie co przebieg.
        $this->settings->setMany([self::ALERT_KEY => (string) json_encode(['fp' => $fp, 'at' => $now])]);
        if ($to === '' || ($fp === '' && (string) ($prev['fp'] ?? '') === '')) {
            return;
        }
        $url = rtrim((string) ($this->settings->get('APP_BASE_URL', '') ?? ''), '/');
        $link = preg_match('#^https?://#i', $url) ? $url . '/admin/ext.php?page=kontrola.stan' : '';
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        if ($fp === '') {
            $subject = 'Kontrola stanu: wszystko znów działa';
            $html = '<p>Poprzednie problemy ustąpiły - żadne sprawdzenie nie świeci na czerwono.</p>';
        } else {
            $subject = 'Kontrola stanu: ' . Checks::plural(count($bad), 'problem', 'problemy', 'problemów') . ' w CRM';
            $html = '<p>' . ($remind ? 'Przypomnienie - te problemy nadal trwają:' : 'Kontrola stanu znalazła problemy, które trzeba sprawdzić:') . '</p><ul>';
            foreach ($bad as $r) {
                $html .= '<li><b>' . $e(Checks::GROUPS[$r['group']][0] ?? '') . ' → ' . $e((string) $r['title']) . '</b>: ' . $e((string) ($r['summary'] ?? '')) . '</li>';
            }
            $html .= '</ul>';
        }
        if ($link !== '') {
            $html .= '<p><a href="' . $e($link) . '">Otwórz System → Kontrola stanu</a></p>';
        }
        try {
            ($this->mail)($to, $subject, $html);
        } catch (\Throwable) {
            // Brak SMTP nie może wywrócić przebiegu - stan jest na stronie.
        }
    }
}
