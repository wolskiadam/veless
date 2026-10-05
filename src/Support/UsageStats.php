<?php
declare(strict_types=1);

namespace Pase\Support;

/**
 * Lekki licznik obciążenia (System → Obciążenie serwera).
 *
 * Każde żądanie do systemu (panel, strona zamówienia klienta, webhooki, cron) zapisuje
 * na koniec: rodzaj, czas trwania i szczyt pamięci. Każde wywołanie zewnętrznego API
 * (Allegro, WooCommerce, wFirma, BLPaczka…) zapisuje usługę i czy się udało.
 *
 * Dane lądują w plikach storage/stats/usage-RRRR-MM-DD.json (jeden na dzień, kubełki
 * godzinowe, czas UTC), nie w bazie - żeby licznik sam nie dokładał zapytań do MySQL.
 * Zapis jest zbiorczy: w trakcie żądania liczniki rosną w pamięci, a plik jest
 * aktualizowany raz, przy zakończeniu skryptu (z blokadą pliku). Pliki starsze niż
 * 35 dni są usuwane. Każdy błąd zapisu jest cicho pomijany - statystyki nigdy nie mogą
 * zepsuć właściwej pracy systemu.
 */
final class UsageStats
{
    private const KEEP_DAYS = 35;

    private static bool $booted = false;
    private static float $start = 0.0;
    /** @var array<string,array{n:int,err:int}> */
    private static array $api = [];

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        self::$start = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        register_shutdown_function([self::class, 'flush']);
    }

    /** Wywołanie zewnętrznego API (z Logger::apiResponse). */
    public static function api(string $service, int $status): void
    {
        $k = strtolower(preg_replace('/[^a-z0-9_.-]/i', '', $service) ?: 'inne');
        self::$api[$k] ??= ['n' => 0, 'err' => 0];
        self::$api[$k]['n']++;
        if ($status < 200 || $status >= 400) {
            self::$api[$k]['err']++;
        }
    }

    /** Rodzaj żądania na podstawie wywołanego pliku. */
    public static function kind(): string
    {
        if (PHP_SAPI === 'cli') {
            return 'cron';
        }
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $file = basename($script);
        if (str_starts_with($file, 'webhook_')) {
            return 'webhook';
        }
        if (str_contains($script, '/admin/')) {
            return in_array($file, ['notifications.php', 'shipment_track.php'], true) || str_starts_with($file, 'print_agent') ? 'panel_bg' : 'panel';
        }
        if (in_array($file, ['order.php', 'order_attachment.php', 'geocode.php'], true)) {
            return 'klient';
        }
        return str_starts_with($file, 'print_agent') ? 'drukarka' : 'inne';
    }

    public static function flush(): void
    {
        try {
            $ms  = (int) round((microtime(true) - self::$start) * 1000);
            $mem = (int) round(memory_get_peak_usage(true) / 1048576);
            $dir = PASE_ROOT . '/storage/stats';
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                return;
            }
            $day  = gmdate('Y-m-d');
            $hour = gmdate('H');
            $file = "{$dir}/usage-{$day}.json";
            $fh = @fopen($file, 'c+');
            if ($fh === false) {
                return;
            }
            if (!flock($fh, LOCK_EX)) {
                fclose($fh);
                return;
            }
            $raw  = stream_get_contents($fh);
            $data = json_decode($raw !== false && $raw !== '' ? $raw : '{}', true) ?: [];

            $kind = self::kind();
            $r = $data['req'][$hour][$kind] ?? ['n' => 0, 'ms' => 0, 'max' => 0, 'mem' => 0];
            $r['n']++;
            $r['ms']  += $ms;
            $r['max']  = max($r['max'], $ms);
            $r['mem']  = max($r['mem'], $mem);
            $data['req'][$hour][$kind] = $r;
            if (http_response_code() >= 500) {
                $data['err5'][$hour] = ($data['err5'][$hour] ?? 0) + 1;
            }
            foreach (self::$api as $svc => $a) {
                $x = $data['api'][$hour][$svc] ?? ['n' => 0, 'err' => 0];
                $x['n']   += $a['n'];
                $x['err'] += $a['err'];
                $data['api'][$hour][$svc] = $x;
            }

            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) json_encode($data));
            fflush($fh);
            flock($fh, LOCK_UN);
            fclose($fh);

            // Sprzątanie starych plików - raz na ~200 żądań wystarczy.
            if (random_int(1, 200) === 1) {
                foreach (glob($dir . '/usage-*.json') ?: [] as $f) {
                    if (preg_match('/usage-(\d{4}-\d{2}-\d{2})\.json$/', $f, $m) && strtotime($m[1]) < time() - self::KEEP_DAYS * 86400) {
                        @unlink($f);
                    }
                }
            }
        } catch (\Throwable) {
            // Statystyki są pomocnicze - nigdy nie przerywamy przez nie żądania.
        }
    }

    /**
     * Dane z ostatnich $days dni (UTC).
     * @return array<string,array<string,mixed>> dzień => {req, api, err5}
     */
    public static function read(int $days): array
    {
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', time() - $i * 86400);
            $f = PASE_ROOT . "/storage/stats/usage-{$day}.json";
            $out[$day] = is_file($f) ? (json_decode((string) @file_get_contents($f), true) ?: []) : [];
        }
        return $out;
    }
}
