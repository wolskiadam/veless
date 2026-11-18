<?php
declare(strict_types=1);

namespace Pase\Services;

use Pase\Repository\SettingsRepository;
use Pase\Support\Logger;

/**
 * Sprawdzanie i automatyczna instalacja nowych wersji z serwera aktualizacji (updates-server/).
 *
 * Raz dziennie worker (cron) pyta serwer o najnowszą wersję, podając: identyfikator instalacji (losowy),
 * zainstalowaną wersję, adres panelu i wersję PHP. Gdy jest nowsza, pobiera paczkę do storage/updates
 * (suma SHA-256 + podpis Ed25519) i pokazuje ją w System → Aktualizacja.
 *
 * Tryby (UPDATE_AUTO): 'patch' (domyślnie) - sam instaluje poprawki w tej samej linii (1.0.x → 1.0.y)
 * w godzinach UPDATE_AUTO_HOURS (domyślnie 1-5 czasu polskiego); 'all' - każdą wersję; 'off' - tylko pokazuje.
 * Serwer może wyłączyć samoczynną instalację danej wersji ("auto": false). Nieudana automatyczna próba
 * nie powtarza się dla tej samej wersji (zostaje przycisk w panelu).
 */
final class UpdateChecker
{
    public const MODES = ['patch' => 'Same poprawki (1.0.x) - zalecane', 'all' => 'Wszystkie nowe wersje', 'off' => 'Nie instaluj samo - tylko pokaż'];
    private const CHECK_EVERY = 86400;

    public function __construct(
        private readonly AppUpdater $updater,
        private readonly SettingsRepository $settings,
        private readonly string $root,
    ) {}

    /** Adres serwera: ustawienie z panelu albo adres zapisany w paczce wydania (config/build.json). */
    public function serverUrl(): string
    {
        $own = trim((string) $this->settings->get('UPDATE_SERVER_URL', ''));
        if ($own !== '') { return $own; }
        $build = json_decode((string) @file_get_contents($this->root . '/config/build.json'), true);
        return is_array($build) && is_string($build['update_url'] ?? null) ? $build['update_url'] : '';
    }

    public function mode(): string
    {
        $m = (string) $this->settings->get('UPDATE_AUTO', 'patch');
        return isset(self::MODES[$m]) ? $m : 'patch';
    }

    /** @return array{0:int,1:int} godziny [od, do) czasu polskiego */
    public function hours(): array
    {
        $raw = (string) $this->settings->get('UPDATE_AUTO_HOURS', '1-5');
        if (preg_match('/^(\d{1,2})-(\d{1,2})$/D', $raw, $m) && (int) $m[1] < (int) $m[2] && (int) $m[2] <= 24) { return [(int) $m[1], (int) $m[2]]; }
        return [1, 5];
    }

    public function installId(): string
    {
        $id = (string) $this->settings->get('UPDATE_INSTALL_ID', '');
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            $id = bin2hex(random_bytes(16));
            $this->settings->setMany(['UPDATE_INSTALL_ID' => $id]);
        }
        return $id;
    }

    /** Stan dla strony w panelu. @return array{server:string,mode:string,checked_at:int,latest:?array,error:string,auto_result:?array} */
    public function status(): array
    {
        $latest = json_decode((string) $this->settings->get('UPDATE_LATEST', ''), true);
        $auto = json_decode((string) $this->settings->get('UPDATE_AUTO_RESULT', ''), true);
        return ['server' => $this->serverUrl(), 'mode' => $this->mode(), 'checked_at' => (int) $this->settings->get('UPDATE_CHECKED_AT', '0'),
            'latest' => is_array($latest) ? $latest : null, 'error' => (string) $this->settings->get('UPDATE_LAST_ERROR', ''),
            'auto_result' => is_array($auto) ? $auto : null];
    }

    /** Czy podana wersja jest nowsza od zainstalowanej. */
    public function isNewer(string $version): bool
    {
        $installed = $this->updater->installedVersion();
        return $installed !== null && version_compare($version, $installed, '>');
    }

    /**
     * Zapytanie do serwera i pobranie paczki, jeśli jest nowsza. Zwraca opis najnowszej wersji (z 'file', gdy pobrana) albo null.
     * @return array<string,mixed>|null
     */
    public function check(): ?array
    {
        $server = $this->serverUrl();
        if ($server === '') { throw new \RuntimeException('Nie ustawiono adresu serwera aktualizacji.'); }
        $installed = $this->updater->installedVersion();
        if ($installed === null) { throw new \RuntimeException('Ta instalacja nie pochodzi z paczki wydania - nie ma czego porównać.'); }
        $this->settings->setMany(['UPDATE_CHECKED_AT' => (string) time()]);
        try {
            $site = (string) $this->settings->get('APP_BASE_URL', '');
            $query = http_build_query(['action' => 'check', 'install' => $this->installId(), 'version' => $installed, 'site' => $site,
                'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION, 'licence' => (string) $this->settings->get('LICENCE_KEY', '')]);
            $body = $this->http($server . (str_contains($server, '?') ? '&' : '?') . $query, 15);
            $data = json_decode($body, true);
            if (!is_array($data) || !array_key_exists('latest', $data)) { throw new \RuntimeException('Serwer aktualizacji odpowiedział w nieznanym formacie.'); }
            $latest = $data['latest'];
            if (!is_array($latest) || !isset($latest['version'], $latest['download'], $latest['sha256'], $latest['size'])
                || !preg_match('/^\d+(\.\d+){1,3}$/D', (string) $latest['version'])) {
                $this->settings->setMany(['UPDATE_LATEST' => '', 'UPDATE_LAST_ERROR' => (string) ($data['message'] ?? '')]);
                return null;
            }
            $info = ['version' => (string) $latest['version'], 'notes' => mb_substr((string) ($latest['notes'] ?? ''), 0, 2000),
                'created_at' => (string) ($latest['created_at'] ?? ''), 'auto' => ($latest['auto'] ?? true) !== false, 'file' => ''];
            if ($this->isNewer($info['version'])) {
                $info['file'] = $this->download((string) $latest['download'], $info['version'], (string) $latest['sha256'], (int) $latest['size']);
            }
            $this->settings->setMany(['UPDATE_LATEST' => json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'UPDATE_LAST_ERROR' => '']);
            return $info;
        } catch (\Throwable $e) {
            $this->settings->setMany(['UPDATE_LAST_ERROR' => $e->getMessage()]);
            throw $e;
        }
    }

    /** Instalacja pobranej paczki (z panelu albo z workera). Tylko z poprawnym podpisem. */
    public function install(bool $lockHeld = false): array
    {
        $latest = $this->status()['latest'];
        if (!$latest || ($latest['file'] ?? '') === '' || !$this->isNewer((string) $latest['version'])) {
            throw new \RuntimeException('Brak pobranej nowej wersji. Kliknij „Sprawdź teraz”.');
        }
        return $this->updater->apply($this->updater->path((string) $latest['file']), $lockHeld, true);
    }

    /** Przebieg workera (lock workera jest już wzięty). Nigdy nie rzuca wyjątku. true = zainstalowano nową wersję. */
    public function tick(?int $now = null): bool
    {
        $now ??= time();
        if ($this->updater->blockedReason() !== null || $this->serverUrl() === '' || $this->updater->installedVersion() === null) { return false; }
        $last = (int) $this->settings->get('UPDATE_CHECKED_AT', '0');
        // Rozrzut ±1 h między instalacjami, żeby nie pytały serwera w tej samej minucie.
        $spread = hexdec(substr($this->installId(), 0, 4)) % 3600;
        if ($now - $last >= self::CHECK_EVERY - $spread) {
            try { $this->check(); } catch (\Throwable $e) { Logger::warn('Aktualizacje: sprawdzenie nie powiodło się - ' . $e->getMessage()); return false; }
        }
        $latest = $this->status()['latest'];
        if (!$latest || ($latest['file'] ?? '') === '' || !$this->isNewer((string) $latest['version'])) { return false; }
        if (!$this->autoAllowed((string) $latest['version'], (bool) ($latest['auto'] ?? true), $now)) { return false; }
        if ((string) $this->settings->get('UPDATE_AUTO_FAILED', '') === $latest['version']) { return false; }
        try {
            $r = $this->install(true);
            $this->settings->setMany(['UPDATE_AUTO_RESULT' => json_encode(['ok' => true, 'at' => $now, 'from' => $r['from'], 'to' => $r['to']])]);
            Logger::info('Aktualizacje: zainstalowano automatycznie ' . $r['to']);
            return true;
        } catch (\Throwable $e) {
            $this->settings->setMany(['UPDATE_AUTO_FAILED' => (string) $latest['version'],
                'UPDATE_AUTO_RESULT' => json_encode(['ok' => false, 'at' => $now, 'to' => $latest['version'], 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE)]);
            Logger::error('Aktualizacje: automatyczna instalacja ' . $latest['version'] . ' nie powiodła się - ' . $e->getMessage());
        }
        return false;
    }

    public function autoAllowed(string $version, bool $serverAllows, int $now): bool
    {
        $mode = $this->mode();
        if ($mode === 'off' || !$serverAllows) { return false; }
        $installed = (string) $this->updater->installedVersion();
        if ($mode === 'patch') {
            $a = explode('.', $installed); $b = explode('.', $version);
            if (($a[0] ?? '') !== ($b[0] ?? '') || ($a[1] ?? '') !== ($b[1] ?? '')) { return false; }
        }
        [$from, $to] = $this->hours();
        $hour = (int) (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('G');
        return $hour >= $from && $hour < $to;
    }

    private function download(string $url, string $version, string $sha256, int $size): string
    {
        if (!preg_match('#^https?://#i', $url)) { throw new \RuntimeException('Nieprawidłowy adres pobrania.'); }
        if ($size < 1 || $size > 200 * 1048576) { throw new \RuntimeException('Nieprawidłowy rozmiar paczki.'); }
        $dir = $this->updater->incomingDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) { throw new \RuntimeException('Nie można utworzyć katalogu storage/updates.'); }
        $name = 'veless-' . $version . '.zip';
        $target = $dir . '/' . $name;
        if (is_file($target) && hash_file('sha256', $target) === strtolower($sha256)) {
            return $name;
        }
        $tmp = $target . '.part';
        $fh = fopen($tmp, 'wb');
        if (!$fh) { throw new \RuntimeException('Nie można zapisać paczki w storage/updates.'); }
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => 300, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                CURLOPT_FAILONERROR => true, CURLOPT_USERAGENT => 'Veless-Updater/1']);
            $ok = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
        } finally { fclose($fh); }
        if ($ok !== true) { @unlink($tmp); throw new \RuntimeException('Nie udało się pobrać paczki: ' . $err); }
        if (filesize($tmp) !== $size || !hash_equals(strtolower($sha256), hash_file('sha256', $tmp))) {
            @unlink($tmp); throw new \RuntimeException('Pobrana paczka jest niekompletna (suma kontrolna się nie zgadza).');
        }
        try {
            $plan = $this->updater->inspect($tmp);   // format + podpis (zły podpis = wyjątek)
            if ($plan['version'] !== $version) { throw new \RuntimeException('Paczka ma inną wersję niż zapowiedział serwer.'); }
            if ($plan['signature'] !== 'ok') {
                throw new \RuntimeException($plan['signature'] === 'unknown' ? 'Brak rozszerzenia PHP sodium - nie da się sprawdzić podpisu paczki.' : 'Paczka z serwera nie ma podpisu.');
            }
        } catch (\Throwable $e) { @unlink($tmp); throw $e; }
        foreach (glob($dir . '/veless-*.zip') ?: [] as $old) { if (basename($old) !== $name) { @unlink($old); } }
        if (!rename($tmp, $target)) { @unlink($tmp); throw new \RuntimeException('Nie można zapisać paczki.'); }
        @chmod($target, 0600);
        return $name;
    }

    private function http(string $url, int $timeout): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3, CURLOPT_USERAGENT => 'Veless-Updater/1']);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) { throw new \RuntimeException('Brak połączenia z serwerem aktualizacji: ' . $err); }
        if ($code !== 200) { throw new \RuntimeException('Serwer aktualizacji odpowiedział kodem ' . $code . '.'); }
        return (string) $body;
    }
}
