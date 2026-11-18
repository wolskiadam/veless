<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use ZipArchive;

/**
 * Aktualizacja systemu z paczki wydania (System → Aktualizacja).
 *
 * Paczka to ten sam plik veless-<wersja>.zip, którym instaluje się system (cli/build_release.php):
 * package.json z sumami SHA-256 i pliki w app/. Aktualizacja:
 *   1. sprawdza paczkę (format, sumy, wersję - bez cofania do starszej),
 *   2. sprawdza prawa zapisu do wszystkich plików, które zmieni,
 *   3. robi kopię zmienianych plików kodu (storage/backups/update/<data>-<wersja>.zip) - do cofnięcia,
 *   4. wstrzymuje workera (ten sam lock co cron), podmienia pliki (każdy przez plik tymczasowy + rename),
 *   5. usuwa pliki, których nowa wersja już nie ma - tylko te z poprzedniej paczki i niezmienione lokalnie,
 *   6. zapisuje manifest wersji (storage/release.json); migracje bazy robi pierwsze kolejne żądanie.
 *
 * Nigdy nie dotyka .env, storage/ ani plików spoza paczki (np. własnych wtyczek w extensions/).
 * Instalacji wdrażanej z GitHuba (config/build.json bez numeru wersji, np. sklep AWiEN) nie aktualizuje.
 */
final class AppUpdater
{
    public const FORMAT = 'veless-release-v1';
    private const KEEP_BACKUPS = 3;
    private const NAME_RE = '/^veless-(\d+(?:\.\d+){1,3})\.zip$/D';

    public function __construct(private readonly PDO $pdo, private readonly string $root) {}

    public function incomingDir(): string { return $this->root . '/storage/updates'; }
    public function backupDir(): string { return $this->root . '/storage/backups/update'; }
    public function manifestFile(): string { return $this->root . '/storage/release.json'; }

    /** Wersja obecnie zainstalowana (z build.json paczki) albo null, gdy system nie pochodzi z paczki wydania. */
    public function installedVersion(): ?string
    {
        $data = json_decode((string) @file_get_contents($this->root . '/config/build.json'), true);
        $v = is_array($data) && is_string($data['version'] ?? null) ? $data['version'] : '';
        return preg_match('/^\d+(\.\d+){1,3}$/D', $v) ? $v : null;
    }

    /** Powód, dla którego aktualizacja z paczki jest wyłączona, albo null. */
    public function blockedReason(): ?string
    {
        if (is_file($this->root . '/config/build.json') && $this->installedVersion() === null) {
            return 'Ta instalacja jest aktualizowana automatycznie przy wdrożeniu z GitHuba. Aktualizacja z paczki jest tu wyłączona, żeby nie nadpisać wdrożenia.';
        }
        if (is_dir($this->root . '/.git')) {
            return 'Ta instalacja jest kopią repozytorium Git (katalog .git). Aktualizuj ją przez git pull.';
        }
        if (!class_exists(ZipArchive::class)) {
            return 'Brak rozszerzenia PHP zip.';
        }
        return null;
    }

    /** Paczki czekające w storage/updates (wgrane przez stronę albo przez FTP). @return list<array{file:string,size:int,mtime:int}> */
    public function incoming(): array
    {
        $out = [];
        foreach (glob($this->incomingDir() . '/veless-*.zip') ?: [] as $path) {
            if (is_file($path) && preg_match(self::NAME_RE, basename($path))) {
                $out[] = ['file' => basename($path), 'size' => (int) filesize($path), 'mtime' => (int) filemtime($path)];
            }
        }
        usort($out, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $out;
    }

    public function path(string $file): string
    {
        if (!preg_match(self::NAME_RE, $file) || !is_file($this->incomingDir() . '/' . $file)) {
            throw new \RuntimeException('Nie ma takiej paczki.');
        }
        return $this->incomingDir() . '/' . $file;
    }

    /** Wgranie paczki z formularza. Zwraca nazwę pliku w storage/updates. */
    public function importUpload(string $tmpPath): string
    {
        $manifest = $this->readManifest($tmpPath);
        $this->ensureDir($this->incomingDir());
        $name = 'veless-' . $manifest['version'] . '.zip';
        $dest = $this->incomingDir() . '/' . $name;
        $ok = is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $dest) : copy($tmpPath, $dest);
        if (!$ok) { throw new \RuntimeException('Nie udało się zapisać wgranej paczki.'); }
        @chmod($dest, 0600);
        return $name;
    }

    public function delete(string $file): void
    {
        @unlink($this->path($file));
    }

    /**
     * Co zmieni paczka.
     * @return array{version:string,installed:?string,created_at:string,files:int,changed:list<string>,added:list<string>,removed:list<string>,unwritable:list<string>,downgrade:bool,same:bool}
     */
    public function inspect(string $zipPath): array
    {
        $manifest = $this->readManifest($zipPath);
        $old = $this->previousManifest();
        $changed = []; $added = []; $unwritable = [];
        foreach ($manifest['files'] as $entry => $meta) {
            $rel = substr($entry, 4);
            if (!$this->writableTarget($rel)) { continue; }
            $target = $this->root . '/' . $rel;
            if (!is_file($target)) {
                $added[] = $rel;
                if (!$this->canCreate($target)) { $unwritable[] = $rel; }
            } elseif (filesize($target) !== (int) $meta['size'] || hash_file('sha256', $target) !== $meta['sha256']) {
                $changed[] = $rel;
                if (!is_writable($target) || !is_writable(dirname($target))) { $unwritable[] = $rel; }
            }
        }
        $removed = [];
        foreach ($old['files'] ?? [] as $entry => $meta) {
            $rel = substr((string) $entry, 4);
            if (isset($manifest['files'][$entry]) || !$this->writableTarget($rel)) { continue; }
            $target = $this->root . '/' . $rel;
            // Tylko pliki niezmienione od poprzedniej wersji (cudzych poprawek nie kasujemy).
            if (is_file($target) && hash_file('sha256', $target) === ($meta['sha256'] ?? '')) { $removed[] = $rel; }
        }
        $installed = $this->installedVersion();
        return [
            'version' => (string) $manifest['version'], 'installed' => $installed, 'created_at' => (string) ($manifest['created_at'] ?? ''),
            'files' => count($manifest['files']), 'changed' => $changed, 'added' => $added, 'removed' => $removed, 'unwritable' => $unwritable,
            'downgrade' => $installed !== null && version_compare((string) $manifest['version'], $installed, '<'),
            'same' => $installed !== null && version_compare((string) $manifest['version'], $installed, '=='),
        ];
    }

    /**
     * Aktualizacja. Zwraca podsumowanie (wersje, liczby plików, nazwa kopii do cofnięcia).
     * @return array{from:?string,to:string,changed:int,added:int,removed:int,backup:string}
     */
    public function apply(string $zipPath): array
    {
        if (($why = $this->blockedReason()) !== null) { throw new \RuntimeException($why); }
        $plan = $this->inspect($zipPath);
        if ($plan['downgrade']) {
            throw new \RuntimeException('Paczka ma starszą wersję (' . $plan['version'] . ') niż zainstalowana (' . $plan['installed'] . '). Cofnięcie wersji nie jest obsługiwane - użyj kopii sprzed aktualizacji.');
        }
        if ($plan['unwritable']) {
            throw new \RuntimeException('Brak prawa zapisu do ' . count($plan['unwritable']) . ' plików, np. ' . implode(', ', array_slice($plan['unwritable'], 0, 3))
                . '. Popraw uprawnienia (np. w menedżerze plików hostingu) i spróbuj ponownie - nic nie zostało zmienione.');
        }
        $manifest = $this->readManifest($zipPath);

        // Worker nie może działać w trakcie podmiany plików - bierzemy jego lock.
        $this->ensureDir($this->root . '/storage');
        $lock = fopen($this->root . '/storage/worker.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) { fclose($lock); }
            throw new \RuntimeException('Właśnie działa zadanie w tle (cron). Spróbuj ponownie za minutę.');
        }
        ftruncate($lock, 0); fwrite($lock, (string) time()); fflush($lock);
        $zip = null; $backup = '';
        try {
            $backup = $this->backup(array_merge($plan['changed'], $plan['removed']), $plan['added'], $plan['installed'], $plan['version']);
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) { throw new \RuntimeException('Nie można otworzyć paczki.'); }
            foreach (array_merge($plan['changed'], $plan['added']) as $rel) {
                $this->extract($zip, 'app/' . $rel, $manifest['files']['app/' . $rel]);
            }
            $zip->close(); $zip = null;
            foreach ($plan['removed'] as $rel) { @unlink($this->root . '/' . $rel); }
            $this->writeManifest($manifest);
        } catch (\Throwable $e) {
            if ($zip instanceof ZipArchive) { @$zip->close(); }
            // Coś poszło nie tak w połowie - przywracamy pliki z kopii, żeby nie zostawić mieszanki wersji.
            if ($backup !== '') {
                try { $this->restore($backup); } catch (\Throwable $r) { \Pase\Support\Logger::error('Aktualizacja: nie udało się cofnąć zmian - ' . $r->getMessage()); }
            }
            throw $e;
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
        if (function_exists('opcache_reset')) { @opcache_reset(); }
        // Migracje: ten proces ma już wczytany stary Migrator, więc uruchomi je pierwsze kolejne żądanie
        // (config/database.php robi to przy każdym starcie) - strona po aktualizacji od razu przekierowuje.
        $this->prune();
        \Pase\Support\Logger::info('Aktualizacja systemu: ' . ($plan['installed'] ?? '?') . ' → ' . $plan['version'], ['changed' => count($plan['changed']), 'added' => count($plan['added']), 'removed' => count($plan['removed'])]);
        return ['from' => $plan['installed'], 'to' => $plan['version'], 'changed' => count($plan['changed']), 'added' => count($plan['added']), 'removed' => count($plan['removed']), 'backup' => basename($backup)];
    }

    /** Kopie plików sprzed aktualizacji. @return list<array{file:string,from:string,to:string,created_at:string,size:int}> */
    public function backups(): array
    {
        $out = [];
        foreach (glob($this->backupDir() . '/update-*.zip') ?: [] as $path) {
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::RDONLY) !== true) { continue; }
            $meta = json_decode((string) $zip->getFromName('update.json'), true);
            $zip->close();
            if (!is_array($meta)) { continue; }
            $out[] = ['file' => basename($path), 'from' => (string) ($meta['from'] ?? ''), 'to' => (string) ($meta['to'] ?? ''),
                'created_at' => (string) ($meta['created_at'] ?? ''), 'size' => (int) filesize($path)];
        }
        usort($out, static fn($a, $b) => strcmp($b['file'], $a['file']));
        return $out;
    }

    /** Cofnięcie aktualizacji: pliki z kopii wracają, dodane przez aktualizację znikają. */
    public function rollback(string $file): array
    {
        if (!preg_match('/^update-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}\.zip$/D', $file) || !is_file($this->backupDir() . '/' . $file)) {
            throw new \RuntimeException('Nie ma takiej kopii.');
        }
        $meta = $this->restore($this->backupDir() . '/' . $file);
        if (function_exists('opcache_reset')) { @opcache_reset(); }
        \Pase\Support\Logger::info('Cofnięto aktualizację ' . ($meta['to'] ?? '?') . ' → ' . ($meta['from'] ?? '?'));
        return $meta;
    }

    // ------------------------------------------------------------------ pomocnicze

    /** @return array<string,mixed> */
    private function readManifest(string $zipPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) { throw new \RuntimeException('To nie jest poprawny plik ZIP (albo został wgrany tylko w części).'); }
        try {
            $stat = $zip->statName('package.json');
            $raw = $stat && $stat['size'] < 16 * 1024 * 1024 ? $zip->getFromName('package.json') : false;
            $m = $raw === false ? null : json_decode($raw, true);
            if (!is_array($m) || ($m['format'] ?? '') !== self::FORMAT || !is_array($m['files'] ?? null)
                || !is_string($m['version'] ?? null) || !preg_match('/^\d+(\.\d+){1,3}$/D', $m['version'])) {
                throw new \RuntimeException('To nie jest paczka aktualizacji Veless (veless-<wersja>.zip).');
            }
            foreach ($m['files'] as $entry => $meta) {
                $entry = (string) $entry;
                if (!str_starts_with($entry, 'app/') || !self::safePath(substr($entry, 4)) || !is_array($meta)
                    || !isset($meta['size'], $meta['sha256']) || $zip->statName($entry) === false) {
                    throw new \RuntimeException('Paczka jest niekompletna lub uszkodzona (' . $entry . ').');
                }
            }
            foreach (['app/config/config.php', 'app/src/Support/Migrator.php', 'app/config/build.json'] as $must) {
                if (!isset($m['files'][$must])) { throw new \RuntimeException('W paczce brakuje ' . substr($must, 4) . '.'); }
            }
            return $m;
        } finally { $zip->close(); }
    }

    private static function safePath(string $rel): bool
    {
        if ($rel === '' || str_starts_with($rel, '/') || str_contains($rel, '\\') || str_contains($rel, ':') || str_contains($rel, '//')
            || preg_match('/[\x00-\x1f]/', $rel)) { return false; }
        foreach (explode('/', $rel) as $part) { if ($part === '.' || $part === '..' || $part === '') { return false; } }
        return true;
    }

    /** Pliki, których aktualizacja nigdy nie rusza. */
    private function writableTarget(string $rel): bool
    {
        if ($rel === '.env' || (str_starts_with($rel, '.env.') && $rel !== '.env.example')) { return false; }
        if (str_starts_with($rel, 'storage/')) { return in_array($rel, ['storage/.htaccess', 'storage/.gitkeep', 'storage/security/.htaccess'], true); }
        return true;
    }

    private function canCreate(string $target): bool
    {
        $dir = dirname($target);
        while (!is_dir($dir) && $dir !== dirname($dir)) { $dir = dirname($dir); }
        return is_writable($dir);
    }

    private function extract(ZipArchive $zip, string $entry, array $meta): void
    {
        $target = $this->root . '/' . substr($entry, 4);
        $this->ensureDir(dirname($target), 0755);
        if (is_link($target)) { throw new \RuntimeException('W miejscu pliku jest dowiązanie symboliczne: ' . $target); }
        $stream = $zip->getStream($entry);
        if (!$stream) { throw new \RuntimeException('Nie można odczytać z paczki: ' . $entry); }
        $tmp = $target . '.upd-part';
        $out = fopen($tmp, 'wb');
        if (!$out) { fclose($stream); throw new \RuntimeException('Nie można zapisać pliku: ' . $target); }
        $hash = hash_init('sha256'); $bytes = 0;
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 1048576);
                if ($chunk === false) { throw new \RuntimeException('Błąd odczytu paczki: ' . $entry); }
                if ($chunk === '') { break; }
                $bytes += strlen($chunk); hash_update($hash, $chunk);
                if (fwrite($out, $chunk) !== strlen($chunk)) { throw new \RuntimeException('Brak miejsca na dysku przy zapisie: ' . $entry); }
            }
        } finally { fclose($stream); fclose($out); }
        if ($bytes !== (int) $meta['size'] || !hash_equals((string) $meta['sha256'], hash_final($hash))) {
            @unlink($tmp);
            throw new \RuntimeException('Suma kontrolna się nie zgadza (uszkodzona paczka): ' . $entry);
        }
        $perms = is_file($target) ? (fileperms($target) & 0777) : 0644;
        if (!rename($tmp, $target)) { @unlink($tmp); throw new \RuntimeException('Nie można podmienić pliku: ' . $target); }
        @chmod($target, $perms);
    }

    /** Kopia plików, które aktualizacja zmieni lub usunie (+ lista dodawanych, do usunięcia przy cofnięciu). */
    private function backup(array $existing, array $added, ?string $from, string $to): string
    {
        $this->ensureDir($this->backupDir());
        @file_put_contents($this->root . '/storage/backups/.htaccess', "Require all denied\n");
        $path = $this->backupDir() . '/update-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) { throw new \RuntimeException('Nie można utworzyć kopii przed aktualizacją.'); }
        foreach ($existing as $rel) { $zip->addFile($this->root . '/' . $rel, 'app/' . $rel); }
        $prev = is_file($this->manifestFile()) ? (string) file_get_contents($this->manifestFile()) : null;
        $zip->addFromString('update.json', json_encode(['from' => $from, 'to' => $to, 'created_at' => gmdate('c'), 'added' => array_values($added),
            'had_manifest' => $prev !== null], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($prev !== null) { $zip->addFromString('release.json', $prev); }
        if (!$zip->close()) { @unlink($path); throw new \RuntimeException('Nie udało się zapisać kopii przed aktualizacją (brak miejsca?).'); }
        @chmod($path, 0600);
        return $path;
    }

    /** @return array<string,mixed> metadane kopii */
    private function restore(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) { throw new \RuntimeException('Nie można otworzyć kopii.'); }
        try {
            $meta = json_decode((string) $zip->getFromName('update.json'), true);
            if (!is_array($meta)) { throw new \RuntimeException('Uszkodzona kopia aktualizacji.'); }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (!str_starts_with($name, 'app/') || !self::safePath(substr($name, 4)) || !$this->writableTarget(substr($name, 4))) { continue; }
                $target = $this->root . '/' . substr($name, 4);
                $this->ensureDir(dirname($target), 0755);
                $data = $zip->getFromIndex($i);
                if ($data === false || file_put_contents($target . '.upd-part', $data) === false || !rename($target . '.upd-part', $target)) {
                    throw new \RuntimeException('Nie można przywrócić pliku: ' . $target);
                }
            }
            foreach ((array) ($meta['added'] ?? []) as $rel) {
                if (is_string($rel) && self::safePath($rel) && $this->writableTarget($rel)) { @unlink($this->root . '/' . $rel); }
            }
            $prev = $zip->getFromName('release.json');
            if ($prev !== false) { file_put_contents($this->manifestFile(), $prev); }
            elseif (empty($meta['had_manifest'])) { @unlink($this->manifestFile()); }
            return $meta;
        } finally { $zip->close(); }
    }

    /** @return array<string,mixed> manifest poprzedniej paczki (pusty, gdy brak) */
    private function previousManifest(): array
    {
        $data = json_decode((string) @file_get_contents($this->manifestFile()), true);
        return is_array($data) && ($data['format'] ?? '') === self::FORMAT ? $data : [];
    }

    private function writeManifest(array $manifest): void
    {
        $this->ensureDir(dirname($this->manifestFile()));
        file_put_contents($this->manifestFile(), json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @chmod($this->manifestFile(), 0600);
    }

    private function prune(): void
    {
        $all = glob($this->backupDir() . '/update-*.zip') ?: [];
        rsort($all);
        foreach (array_slice($all, self::KEEP_BACKUPS) as $old) { @unlink($old); }
        foreach (glob($this->incomingDir() . '/veless-*.zip') ?: [] as $pkg) { @unlink($pkg); }
    }

    private function ensureDir(string $dir, int $mode = 0700): void
    {
        if (!is_dir($dir) && !@mkdir($dir, $mode, true) && !is_dir($dir)) { throw new \RuntimeException('Nie można utworzyć katalogu ' . $dir . '.'); }
    }
}
