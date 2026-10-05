<?php
declare(strict_types=1);

namespace Pase\Plugin;

use Pase\Support\Logger;

/**
 * Instalacja/usuwanie wtyczek integracji z paczki ZIP (panel → Wtyczki).
 *
 * UWAGA BEZPIECZEŃSTWA: wtyczka to obcy kod PHP wykonywany z pełnymi prawami aplikacji.
 * Instalator NIE jest sandboxem - jedynie utrudnia ewidentne nadużycia:
 *  - waliduje strukturę (register.php) i manifest,
 *  - skanuje kod pod kątem niebezpiecznych funkcji (ostrzeżenie),
 *  - robi backup poprzedniej wersji katalogu (możliwość cofnięcia).
 * Instaluj wyłącznie wtyczki z zaufanego źródła.
 */
final class PluginInstaller
{
    /** Funkcje, których obecność w kodzie wtyczki jest podejrzana (skan ostrzegawczy). */
    private const DANGEROUS = [
        'eval', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen',
        'pcntl_exec', 'assert', 'create_function', 'base64_decode', 'gzinflate',
        'gzuncompress', 'str_rot13', 'unserialize',
    ];

    public static function integrationsDir(): string
    {
        return PASE_ROOT . '/integrations';
    }

    /** Katalog wtyczek danego rodzaju: 'integration' (integrations/) albo 'extension' (extensions/). */
    public static function dirFor(string $kind): string
    {
        return $kind === 'extension' ? ExtensionRegistry::dir() : self::integrationsDir();
    }

    /**
     * Rodzaj wtyczki z paczki: rozszerzenie, gdy register.php wskazuje klasę z PaseExt\,
     * w przeciwnym razie integracja (PasePlugin\). Klasy nie da się jeszcze załadować
     * (paczka leży w katalogu tymczasowym), więc patrzymy na tekst.
     */
    public static function kindOf(string $pluginRoot): string
    {
        $code = (string) @file_get_contents($pluginRoot . '/register.php');
        return preg_match('/\bPaseExt\\\\/', $code) ? 'extension' : 'integration';
    }

    public static function backupDir(): string
    {
        return PASE_ROOT . '/storage/plugin_backups';
    }

    /**
     * Instaluje wtyczkę z przesłanego pliku ZIP ($_FILES[...]).
     *
     * @param array{name?:string,tmp_name?:string,error?:int,size?:int} $file
     * @return array{ok:bool, message:string, slug?:string, warnings?:string[]}
     */
    /** Wgrywanie ZIP z panelu można wyłączyć w .env (PLUGIN_UPLOAD=0) - wtyczki wtedy tylko przez FTP/repozytorium. */
    public static function uploadAllowed(): bool
    {
        return \Pase\Support\Env::bool('PLUGIN_UPLOAD', true);
    }

    public static function installFromZip(array $file): array
    {
        if (!self::uploadAllowed()) {
            return ['ok' => false, 'message' => 'Wgrywanie wtyczek z panelu jest wyłączone (PLUGIN_UPLOAD=0 w .env).'];
        }
        if (!class_exists(\ZipArchive::class)) {
            return ['ok' => false, 'message' => 'Brak rozszerzenia PHP zip na serwerze — nie można rozpakować paczki.'];
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Nie przesłano pliku lub błąd wysyłki.'];
        }
        if (strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'zip') {
            return ['ok' => false, 'message' => 'Oczekiwano pliku .zip.'];
        }

        $tmpDir = sys_get_temp_dir() . '/pase_plugin_' . bin2hex(random_bytes(6));
        if (!mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            return ['ok' => false, 'message' => 'Nie można utworzyć katalogu tymczasowego.'];
        }

        try {
            $zip = new \ZipArchive();
            if ($zip->open($file['tmp_name']) !== true) {
                return ['ok' => false, 'message' => 'Nie można otworzyć archiwum ZIP.'];
            }
            // Ochrona przed zip-slip: odrzuć wpisy z ../ lub ścieżką absolutną.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false || str_contains($name, '..') || str_starts_with($name, '/')) {
                    $zip->close();
                    return ['ok' => false, 'message' => 'Archiwum zawiera niedozwolone ścieżki (..).'];
                }
            }
            $zip->extractTo($tmpDir);
            $zip->close();

            // Znajdź katalog wtyczki: ten, który zawiera register.php (na 1. lub 2. poziomie).
            $pluginRoot = self::locatePluginRoot($tmpDir);
            if ($pluginRoot === null) {
                return ['ok' => false, 'message' => 'W paczce nie znaleziono pliku register.php — to nie jest wtyczka CRM.'];
            }

            // Slug = nazwa katalogu docelowego (z register.php / nazwy folderu).
            $slug = strtolower(basename($pluginRoot));
            if (!preg_match('/^[a-z0-9_]+$/', $slug)) {
                return ['ok' => false, 'message' => "Nieprawidłowa nazwa katalogu wtyczki: „{$slug}” (dozwolone a-z0-9_)."];
            }

            // Skan niebezpiecznych funkcji (ostrzeżenie, nie blokada).
            $warnings = self::scanDangerous($pluginRoot);

            // Rozszerzenie trafia do extensions/, integracja do integrations/; ta sama nazwa
            // w drugim katalogu to konflikt (strona Wtyczki i kopie zapasowe idą po nazwie).
            $kind = self::kindOf($pluginRoot);
            if (is_dir(self::dirFor($kind === 'extension' ? 'integration' : 'extension') . '/' . $slug)) {
                return ['ok' => false, 'message' => "Nazwa „{$slug}” jest już zajęta przez wtyczkę innego rodzaju."];
            }
            if (!is_dir(self::dirFor($kind))) {
                @mkdir(self::dirFor($kind), 0755, true);
            }

            // Backup istniejącej wersji (jeśli jest) i instalacja.
            $dest = self::dirFor($kind) . '/' . $slug;
            if (is_dir($dest)) {
                self::backup($slug, $dest);
                self::rrmdir($dest);
            }
            if (!self::rmove($pluginRoot, $dest)) {
                return ['ok' => false, 'message' => 'Nie udało się przenieść wtyczki do katalogu ' . basename(self::dirFor($kind)) . '/.'];
            }

            // Weryfikacja po instalacji: rejestr musi rozpoznać wtyczkę i jej manifest.
            PluginRegistry::reset();
            ExtensionRegistry::reset();
            $plugin = $kind === 'extension' ? self::tryLoadExtension($dest) : self::tryLoad($dest);
            if ($plugin === null) {
                // Wadliwa wtyczka - usuń, przywróć backup jeśli był.
                self::rrmdir($dest);
                self::restoreLatestBackup($slug, $dest);
                PluginRegistry::reset();
                ExtensionRegistry::reset();
                return ['ok' => false, 'message' => 'Wtyczka nie przeszła walidacji (register.php nie zwraca poprawnej klasy / manifestu). Wycofano instalację.'];
            }

            self::recordFingerprint($kind, $slug, $dest);
            Logger::info("Plugin: zainstalowano wtyczkę „{$slug}” przez panel.");
            return [
                'ok' => true,
                'message' => 'Wtyczka zainstalowana: ' . $plugin->manifest()->name . ' (' . $plugin->manifest()->version . ').',
                'slug' => $slug,
                'kind' => $kind,
                'warnings' => $warnings,
            ];
        } catch (\Throwable $e) {
            Logger::error('Plugin install: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Błąd instalacji: ' . $e->getMessage()];
        } finally {
            self::rrmdir($tmpDir);
        }
    }

    /** Usuwa wtyczkę (z backupem). */
    public static function uninstall(string $slug, string $kind = 'integration'): array
    {
        if (!preg_match('/^[a-z0-9_]+$/', $slug)) {
            return ['ok' => false, 'message' => 'Nieprawidłowy identyfikator wtyczki.'];
        }
        $dir = self::dirFor($kind) . '/' . $slug;
        if (!is_dir($dir)) {
            return ['ok' => false, 'message' => 'Wtyczka nie istnieje.'];
        }
        self::backup($slug, $dir);
        self::rrmdir($dir);
        self::forgetFingerprint($kind, $slug);
        PluginRegistry::reset();
        ExtensionRegistry::reset();
        Logger::info("Plugin: usunięto wtyczkę „{$slug}” (backup zachowany).");
        return ['ok' => true, 'message' => "Usunięto wtyczkę „{$slug}”. Kopia zapasowa zachowana."];
    }

    /** Włącza/wyłącza wtyczkę przez plik-znacznik .disabled w jej katalogu. */
    public static function setEnabled(string $slug, bool $enabled, string $kind = 'integration'): array
    {
        if (!preg_match('/^[a-z0-9_]+$/', $slug) || !is_dir(self::dirFor($kind) . '/' . $slug)) {
            return ['ok' => false, 'message' => 'Nieprawidłowy identyfikator wtyczki.'];
        }
        $flag = self::dirFor($kind) . '/' . $slug . '/.disabled';
        if ($enabled) {
            if (is_file($flag)) {
                @unlink($flag);
            }
        } else {
            @file_put_contents($flag, "disabled\n");
        }
        PluginRegistry::reset();
        ExtensionRegistry::reset();
        return ['ok' => true, 'message' => $enabled ? 'Wtyczka włączona.' : 'Wtyczka wyłączona.'];
    }

    // ===== integralność wtyczek wgranych przez panel =====

    /** Plik z odciskami (SHA-256) wtyczek zainstalowanych z ZIP - poza webrootem, jak inne dane bezpieczeństwa. */
    public static function fingerprintFile(): string
    {
        return (defined('PASE_ROOT') ? PASE_ROOT : dirname(__DIR__, 2)) . '/storage/security/plugins.json';
    }

    /** SHA-256 całego katalogu wtyczki (ścieżki + treść plików, bez znacznika .disabled). */
    public static function fingerprint(string $dir): string
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            $rel = substr($f->getPathname(), strlen($dir) + 1);
            if ($f->isFile() && $rel !== '.disabled') {
                $files[str_replace('\\', '/', $rel)] = hash_file('sha256', $f->getPathname());
            }
        }
        ksort($files);
        return hash('sha256', json_encode($files));
    }

    /**
     * Wtyczki wgrane z ZIP, których pliki zmieniły się poza panelem (np. podrzucony plik PHP).
     * Tylko ostrzeżenie na stronie Wtyczki: wtyczki z repozytorium i FTP nie mają odcisku.
     * @return list<array{kind:string,slug:string,installed_at:string}>
     */
    public static function modifiedSinceInstall(): array
    {
        $out = [];
        foreach (self::fingerprints() as $id => $rec) {
            [$kind, $slug] = explode('/', (string) $id, 2) + [1 => ''];
            $dir = self::dirFor($kind === 'extension' ? 'extension' : 'integration') . '/' . $slug;
            if (is_dir($dir) && self::fingerprint($dir) !== ($rec['sha256'] ?? '')) {
                $out[] = ['kind' => $kind, 'slug' => $slug, 'installed_at' => (string) ($rec['installed_at'] ?? '')];
            }
        }
        return $out;
    }

    /** @return array<string,array{sha256:string,installed_at:string}> */
    private static function fingerprints(): array
    {
        $raw = @file_get_contents(self::fingerprintFile());
        $data = $raw === false ? [] : json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private static function saveFingerprints(array $data): void
    {
        $file = self::fingerprintFile();
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0700, true);
        }
        @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($file, 0600);
    }

    private static function recordFingerprint(string $kind, string $slug, string $dir): void
    {
        $data = self::fingerprints();
        $data[$kind . '/' . $slug] = ['sha256' => self::fingerprint($dir), 'installed_at' => gmdate('c')];
        self::saveFingerprints($data);
    }

    private static function forgetFingerprint(string $kind, string $slug): void
    {
        $data = self::fingerprints();
        unset($data[$kind . '/' . $slug]);
        self::saveFingerprints($data);
    }

    // ===== helpers =====

    private static function locatePluginRoot(string $base): ?string
    {
        if (is_file($base . '/register.php')) {
            return $base;
        }
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            if (is_file($sub . '/register.php')) {
                return $sub;
            }
        }
        return null;
    }

    /** @return string[] ostrzeżenia o znalezionych niebezpiecznych funkcjach */
    private static function scanDangerous(string $dir): array
    {
        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }
            $code = (string) @file_get_contents($f->getPathname());
            foreach (self::DANGEROUS as $fn) {
                if (preg_match('/\b' . preg_quote($fn, '/') . '\s*\(/i', $code)) {
                    $hits[] = $fn . '() w ' . basename($f->getPathname());
                }
            }
        }
        return array_values(array_unique($hits));
    }

    private static function tryLoadExtension(string $dir): ?Contract\ExtensionPlugin
    {
        if (!is_file($dir . '/register.php')) {
            return null;
        }
        try {
            $ext = ExtensionRegistry::loadFile($dir . '/register.php');
            if ($ext !== null && $ext->manifest()->type === basename($dir)) {
                return $ext;
            }
        } catch (\Throwable $e) {
            Logger::warn('Extension tryLoad: ' . $e->getMessage());
        }
        return null;
    }

    private static function tryLoad(string $dir): ?Contract\IntegrationPlugin
    {
        $reg = $dir . '/register.php';
        if (!is_file($reg)) {
            return null;
        }
        try {
            $ret = require $reg;
            $obj = $ret instanceof Contract\IntegrationPlugin ? $ret
                : (is_string($ret) && class_exists($ret) ? new $ret() : null);
            if ($obj instanceof Contract\IntegrationPlugin) {
                $obj->manifest(); // wymuś walidację manifestu
                return $obj;
            }
        } catch (\Throwable $e) {
            Logger::warn('Plugin tryLoad: ' . $e->getMessage());
        }
        return null;
    }

    private static function backup(string $slug, string $dir): void
    {
        $bdir = self::backupDir();
        if (!is_dir($bdir) && !mkdir($bdir, 0750, true) && !is_dir($bdir)) {
            return;
        }
        $dest = $bdir . '/' . $slug . '_' . date('Ymd_His');
        self::rcopy($dir, $dest);
    }

    private static function restoreLatestBackup(string $slug, string $dest): void
    {
        $backups = glob(self::backupDir() . '/' . $slug . '_*', GLOB_ONLYDIR) ?: [];
        if ($backups === []) {
            return;
        }
        rsort($backups); // najnowszy
        self::rcopy($backups[0], $dest);
    }

    private static function rmove(string $src, string $dst): bool
    {
        if (@rename($src, $dst)) {
            return true;
        }
        // rename bywa zawodne między urządzeniami (tmp -> projekt) - kopiuj+usuń.
        if (self::rcopy($src, $dst)) {
            self::rrmdir($src);
            return is_dir($dst);
        }
        return false;
    }

    private static function rcopy(string $src, string $dst): bool
    {
        if (!is_dir($dst) && !mkdir($dst, 0750, true) && !is_dir($dst)) {
            return false;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $target = $dst . '/' . $it->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    @mkdir($target, 0750, true);
                }
            } else {
                @copy($item->getPathname(), $target);
            }
        }
        return true;
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
