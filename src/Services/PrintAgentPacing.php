<?php
declare(strict_types=1);

namespace Pase\Services;

use Pase\Repository\SettingsRepository;

/**
 * Jak często agent drukarki ma pytać serwer o wydruki. Serwer odpowiada polem
 * „next_poll” (sekundy), agent od wersji 1.1 się do niego stosuje.
 *
 *  - w trakcie pracy (ktoś korzysta z panelu albo niedawno drukowano): PRINT_POLL_ACTIVE (domyślnie 4 s),
 *  - bez pracy od PRINT_IDLE_AFTER minut: PRINT_POLL_IDLE (domyślnie 30 s),
 *  - wydruki wstrzymane w panelu: rzadko (jak bez pracy) i żadnych zadań,
 *  - po wydaniu zadania, gdy w kolejce czeka kolejne: od razu (1 s).
 *
 * Aktywność w panelu to znacznik pliku storage/stats/panel-activity, odświeżany przez
 * auth.php najwyżej raz na minutę (bez zapisu do bazy). Tło panelu (dzwoneczek co minutę)
 * się nie liczy - otwarta, nieużywana karta nie trzyma agenta w szybkim trybie.
 */
final class PrintAgentPacing
{
    public const LAST_JOB_KEY = 'PRINT_LAST_JOB_AT';
    private const ACTIVITY_FILE = '/storage/stats/panel-activity';

    /** Wywoływane przy każdej stronie panelu (auth.php). */
    public static function touchPanelActivity(string $root, string $script): void
    {
        if (in_array(basename($script), ['notifications.php', 'layout_save.php'], true)) {
            return;
        }
        $file = $root . self::ACTIVITY_FILE;
        $mtime = @filemtime($file);
        if ($mtime === false || $mtime < time() - 60) {
            if (!is_dir(dirname($file))) { @mkdir(dirname($file), 0700, true); }
            @touch($file);
        }
    }

    public static function lastPanelActivity(string $root): int
    {
        clearstatcache(true, $root . self::ACTIVITY_FILE);
        return (int) (@filemtime($root . self::ACTIVITY_FILE) ?: 0);
    }

    /** Czy trwa praca: aktywność w panelu albo wydruk w ostatnich PRINT_IDLE_AFTER minutach. */
    public static function isActive(SettingsRepository $settings, string $root, ?int $now = null): bool
    {
        $now ??= time();
        $window = TaskTimings::get($settings, 'PRINT_IDLE_AFTER') * 60;
        $lastJob = (int) ($settings->get(self::LAST_JOB_KEY, '0') ?? '0');
        return $now - self::lastPanelActivity($root) <= $window || $now - $lastJob <= $window;
    }

    /** Za ile sekund agent ma zapytać ponownie. */
    public static function nextPoll(SettingsRepository $settings, string $root, bool $paused, bool $morePending, ?int $now = null): int
    {
        if ($paused) {
            return TaskTimings::get($settings, 'PRINT_POLL_IDLE');
        }
        if ($morePending) {
            return 1;
        }
        return self::isActive($settings, $root, $now)
            ? TaskTimings::get($settings, 'PRINT_POLL_ACTIVE')
            : TaskTimings::get($settings, 'PRINT_POLL_IDLE');
    }

    /** 'active' / 'idle' - do pokazania w programie, czy ktoś pracuje w panelu. */
    public static function mode(SettingsRepository $settings, string $root, ?int $now = null): string
    {
        return self::isActive($settings, $root, $now) ? 'active' : 'idle';
    }

    /** Aktualna wersja agenta w katalogu agent/ (AGENT_VERSION w print_agent.py) albo null. */
    public static function latestAgentVersion(string $root): ?string
    {
        $src = @file_get_contents($root . '/agent/print_agent.py', false, null, 0, 20000);
        return is_string($src) && preg_match('/^AGENT_VERSION\s*=\s*"([0-9.]+)"/m', $src, $m) ? $m[1] : null;
    }
}
