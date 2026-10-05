<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use ZipArchive;

/**
 * Pełna kopia systemu do przeniesienia na inny serwer/domenę (System → Kopia zapasowa).
 *
 * Wynik to zwykły ZIP z trzema plikami:
 *   install.php            - instalator (src/Installer/install.php), uruchamiany w przeglądarce na nowym serwerze,
 *   crm-pakiet-<hex>.zip   - WSZYSTKO z CRM (kod, .env, cała baza, pliki storage, klucz 2FA)
 *                            zaszyfrowane AES-256 hasłem podanym przy tworzeniu,
 *   PRZENIESIENIE.txt (instrukcja + dane do ręcznych kroków, bez sekretów), .htaccess (blokada pobrania pakietu z WWW, zanim instalator skończy).
 *
 * Pakiet zawiera klucze API i dane klientów: tylko administrator, katalog storage/backups/full
 * zablokowany dla WWW, plik usuwany po pobraniu (i najpóźniej po RETENTION sekundach).
 */
final class FullSystemPackage
{
    public const MIN_PASSWORD = 12;
    public const FORMAT = 'crm-full-v1';
    public const RETENTION = 86400;
    private const NAME_RE = '/^crm-pelna-kopia-\d{8}-\d{6}-[a-f0-9]{8}\.zip$/D';

    /** Katalogi/pliki pomijane w całym drzewie (jak w deploy.yml: nie ma ich na serwerze albo są zbędne). */
    private const SKIP_ANYWHERE = ['.git', '.github', '.DS_Store', '__pycache__', 'node_modules', '.ftp-deploy-sync-state.json'];
    private const SKIP_TOP = ['tests', 'agent-app', '_to_delete_tmp_stage', 'pase.code-workspace'];
    /** Stan chwilowy lub inne kopie - nie przenosimy (sesje wygasną, logi zaczną się od nowa). */
    private const SKIP_STORAGE = ['backups', 'sessions', 'cache', 'plugin_backups', 'installer', 'ADMIN_PASSWORD.txt'];
    /** Już skompresowane - bez ponownej kompresji (szybciej, mniej CPU na shared hostingu). */
    private const STORED_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'zip', 'gz', 'tgz', 'dmg', 'exe', 'msi', 'docx', 'xlsx', 'mp4'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $root,
        private readonly string $appVersion = '',
    ) {}

    public function dir(): string
    {
        $dir = rtrim($this->root, '/') . '/storage/backups/full';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Nie można utworzyć katalogu kopii (storage/backups/full).');
        }
        foreach ([dirname($dir), $dir] as $d) {
            if (!is_file($d . '/.htaccess')) { @file_put_contents($d . '/.htaccess', "Require all denied\n"); }
        }
        return $dir;
    }

    public function path(string $file): string
    {
        if (!preg_match(self::NAME_RE, $file)) { throw new \RuntimeException('Nieprawidłowa nazwa kopii.'); }
        $path = $this->dir() . '/' . $file;
        if (!is_file($path) || is_link($path)) { throw new \RuntimeException('Nie ma takiej kopii.'); }
        return $path;
    }

    /** @return list<array{file:string,created_at:int,size:int}> najnowsze pierwsze */
    public function list(): array
    {
        $out = [];
        foreach (scandir($this->dir()) ?: [] as $file) {
            if (!preg_match(self::NAME_RE, $file)) { continue; }
            $path = $this->dir() . '/' . $file;
            $out[] = ['file' => $file, 'created_at' => (int) filemtime($path), 'size' => (int) filesize($path)];
        }
        usort($out, static fn($a, $b) => $b['created_at'] <=> $a['created_at']);
        return $out;
    }

    public function delete(string $file): void
    {
        if (!unlink($this->path($file))) { throw new \RuntimeException('Nie udało się usunąć kopii.'); }
    }

    /** Usuwa pakiety starsze niż RETENTION i porzucone katalogi robocze. */
    public function prune(?int $now = null): int
    {
        $now ??= time(); $removed = 0;
        foreach (scandir($this->dir()) ?: [] as $name) {
            $path = $this->dir() . '/' . $name;
            if (is_link($path) || $now - (int) @filemtime($path) < self::RETENTION) { continue; }
            if (preg_match(self::NAME_RE, $name) && is_file($path)) { $removed += (int) @unlink($path); }
            if (preg_match('/^stage-[a-f0-9]{16}$/D', $name) && is_dir($path)) { self::removeTree($path); ++$removed; }
        }
        return $removed;
    }

    /**
     * Buduje pakiet i zwraca nazwę pliku w dir().
     * @param string $sourceBaseUrl obecny adres katalogu public/ (APP_BASE_URL) - instalator podmieni go na nowy
     */
    public function build(string $password, string $sourceBaseUrl = ''): string
    {
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw new \RuntimeException('Hasło do pełnej kopii musi mieć co najmniej ' . self::MIN_PASSWORD . ' znaków.');
        }
        if (!class_exists(ZipArchive::class) || !ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true)) {
            throw new \RuntimeException('Serwer nie obsługuje szyfrowania ZIP AES-256 (rozszerzenie PHP zip).');
        }
        $root = rtrim($this->root, '/');
        $stage = $this->dir() . '/stage-' . bin2hex(random_bytes(8));
        if (!mkdir($stage, 0700)) { throw new \RuntimeException('Nie można utworzyć katalogu roboczego kopii.'); }
        $name = 'crm-pelna-kopia-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.zip';
        $partial = $this->dir() . '/' . $name . '.partial';
        try {
            // 1. Baza: logiczny snapshot w jednej transakcji (DatabaseSnapshot, ten sam format co CLI).
            //    Strefa czasowa UTC, żeby kolumny TIMESTAMP nie przesunęły się na serwerze o innej strefie.
            $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
            $tz = $mysql ? (string) $this->pdo->query('SELECT @@session.time_zone')->fetchColumn() : '';
            if ($mysql) { $this->pdo->exec("SET time_zone = '+00:00'"); }
            try {
                $db = DatabaseSnapshot::export($this->pdo, $stage . '/database.jsonl');
            } finally {
                if ($mysql) { $this->pdo->prepare('SET time_zone = ?')->execute([$tz]); }
            }

            // 2. Pliki aplikacji, .env, storage (załączniki, grafiki...) i klucz 2FA spoza aplikacji.
            $files = ['database.jsonl' => $stage . '/database.jsonl'];
            $skipped = [];
            foreach ($this->appFiles($root, $skipped) as $relative => $path) { $files['app/' . $relative] = $path; }
            $totpFile = self::envValue($root . '/.env', 'TOTP_KEY_FILE');
            if ($totpFile !== null && $totpFile !== '' && is_file($totpFile) && !is_link($totpFile)) {
                $files['secrets/totp.key'] = $totpFile;
            }

            // 3. Zaszyfrowany pakiet z manifestem (rozmiar + SHA-256 każdego pliku - instalator to sprawdza).
            $inner = $stage . '/crm-pakiet-' . bin2hex(random_bytes(16)) . '.zip';
            $manifest = [
                'format' => self::FORMAT, 'created_at' => gmdate('c'), 'app_version' => $this->appVersion,
                'source_base_url' => rtrim($sourceBaseUrl, '/'), 'db_driver' => $db['driver'], 'db_tables' => $db['tables'],
                'skipped_links' => $skipped, 'files' => [],
            ];
            $zip = new ZipArchive();
            if ($zip->open($inner, ZipArchive::CREATE | ZipArchive::EXCL) !== true) { throw new \RuntimeException('Nie można utworzyć pakietu.'); }
            $zip->setPassword($password);
            foreach ($files as $entry => $path) {
                $hash = hash_file('sha256', $path); $size = filesize($path);
                if ($hash === false || $size === false || !$zip->addFile($path, $entry)
                    || !$zip->setEncryptionName($entry, ZipArchive::EM_AES_256)) {
                    $zip->close();
                    throw new \RuntimeException('Nie można dodać pliku do pakietu: ' . $entry);
                }
                if (in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), self::STORED_EXT, true)) {
                    $zip->setCompressionName($entry, ZipArchive::CM_STORE);
                }
                $manifest['files'][$entry] = ['size' => $size, 'sha256' => $hash];
            }
            if (!$zip->addFromString('package.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                || !$zip->setEncryptionName('package.json', ZipArchive::EM_AES_256) || !$zip->close()) {
                throw new \RuntimeException('Nie można zapisać pakietu (brak miejsca na dysku?).');
            }
            @unlink($stage . '/database.jsonl');

            // 4. Zewnętrzny ZIP do pobrania: instalator + pakiet + instrukcja.
            $installer = $root . '/src/Installer/install.php';
            if (!is_file($installer)) { throw new \RuntimeException('Brak pliku instalatora (src/Installer/install.php).'); }
            $out = new ZipArchive();
            if ($out->open($partial, ZipArchive::CREATE | ZipArchive::EXCL) !== true) { throw new \RuntimeException('Nie można utworzyć pliku kopii.'); }
            $ok = $out->addFile($installer, 'install.php')
                && $out->addFile($inner, basename($inner)) && $out->setCompressionName(basename($inner), ZipArchive::CM_STORE)
                && $out->addFromString('PRZENIESIENIE.txt', $this->guide($sourceBaseUrl, $manifest['created_at']))
                && $out->addFromString('.htaccess', self::HTACCESS);
            if (!$ok || !$out->close()) { throw new \RuntimeException('Nie można zapisać pliku kopii (brak miejsca na dysku?).'); }
            chmod($partial, 0600);
            if (!rename($partial, $this->dir() . '/' . $name)) { throw new \RuntimeException('Nie można zapisać pliku kopii.'); }
            return $name;
        } finally {
            if (is_file($partial)) { @unlink($partial); }
            self::removeTree($stage);
        }
    }

    /** Blokada dla WWW do czasu, aż instalator skończy (nazwę pakietu i tak trudno zgadnąć). */
    public const HTACCESS = "# Instalator CRM: pakiet z danymi nie może być pobierany z przeglądarki.\n"
        . "<FilesMatch \"^(crm-pakiet-.*\\.zip|\\.crm-install.*|PRZENIESIENIE\\.txt)$\">\n    Require all denied\n</FilesMatch>\n";

    /** @param list<string> $skipped @return array<string,string> ścieżka względna => bezwzględna */
    private function appFiles(string $root, array &$skipped): array
    {
        $files = [];
        $walk = function (string $dir, string $prefix) use (&$walk, &$files, &$skipped): void {
            $entries = scandir($dir);
            if ($entries === false) { throw new \RuntimeException('Nie można odczytać katalogu: ' . ($prefix === '' ? '.' : $prefix)); }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || in_array($entry, self::SKIP_ANYWHERE, true)) { continue; }
                $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;
                if ($prefix === '' && in_array($entry, self::SKIP_TOP, true)) { continue; }
                if ($prefix === 'storage' && in_array($entry, self::SKIP_STORAGE, true)) { continue; }
                if (str_starts_with($relative, 'storage/') && preg_match('/\.(log|lock)$/', $entry)) { continue; }
                if (preg_match('#^storage/security/[^/]+\.json$#', $relative)) { continue; } // limiter logowania
                $path = $dir . '/' . $entry;
                if (is_link($path)) { $skipped[] = $relative; continue; }
                if (is_dir($path)) { $walk($path, $relative); continue; }
                if (is_file($path)) { $files[$relative] = $path; }
            }
        };
        $walk($root, '');
        return $files;
    }

    private static function envValue(string $envPath, string $key): ?string
    {
        if (!is_readable($envPath)) { return null; }
        foreach (file($envPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) { continue; }
            [$k, $v] = explode('=', $line, 2);
            if (trim($k) !== $key) { continue; }
            $v = trim($v);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'")) { $v = substr($v, 1, -1); }
            return $v;
        }
        return null;
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        if (!is_dir($path)) { return; }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') { self::removeTree($path . '/' . $entry); }
        }
        @rmdir($path);
    }

    /**
     * Instrukcja przeniesienia z danymi do ręcznych kroków (cron, Allegro, WooCommerce, domena).
     * Leży POZA szyfrowaniem - tylko adresy, nazwy i ścieżki, żadnych haseł, kluczy ani tokenów.
     * Adresy zależne od nowej domeny są wzorami z {NOWY_ADRES} do podstawienia.
     */
    public function guide(string $sourceBaseUrl = '', ?string $createdAt = null): string
    {
        $old = rtrim($sourceBaseUrl, '/');
        $root = rtrim($this->root, '/');
        $new = '{NOWY_ADRES}';
        $woo = []; $allegro = [];
        try {
            foreach ($this->pdo->query('SELECT id, type, name, is_active, config FROM integration_accounts ORDER BY type, id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cfg = json_decode((string) $row['config'], true) ?: [];
                $state = (int) $row['is_active'] === 1 ? '' : ' (nieaktywna)';
                if ($row['type'] === 'woocommerce') {
                    $woo[] = '   - ' . $row['name'] . $state . ($cfg['base_url'] ?? '' ? ' - sklep ' . $cfg['base_url'] : '') . "\n"
                        . '       teraz: ' . ($old !== '' ? $old : '{STARY_ADRES}') . '/webhook_woo_order.php?integration=' . (int) $row['id'] . "\n"
                        . '       nowy:  ' . $new . '/webhook_woo_order.php?integration=' . (int) $row['id'];
                }
                if ($row['type'] === 'allegro') {
                    $allegro[] = '   - ' . $row['name'] . $state . ' (' . (($cfg['env'] ?? '') === 'sandbox' ? 'sandbox' : 'produkcja') . ')'
                        . (($cfg['redirect_uri'] ?? '') !== '' ? ', obecny adres przekierowania: ' . $cfg['redirect_uri'] : '');
                }
            }
        } catch (\Throwable) {}
        $date = (new \DateTimeImmutable($createdAt ?? 'now'))->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('d.m.Y H:i');
        $lines = [
            'PRZENIESIENIE CRM NA NOWY SERWER',
            '================================',
            'Kopia z: ' . $date . ($this->appVersion !== '' ? ', wersja ' . $this->appVersion : ''),
            'Obecny adres CRM (katalog public/): ' . ($old !== '' ? $old : '(nie ustawiony w Konfiguracja → Adres URL panelu)'),
            'Obecny katalog na serwerze: ' . $root,
            '',
            'Plik nie zawiera haseł, kluczy API ani tokenów - są tylko w zaszyfrowanym pakiecie.',
            $new . ' = nowy adres CRM, np. https://nowa-domena.pl/public (instalator wykryje go sam),',
            '{NOWY_KATALOG} = katalog na nowym serwerze, do którego wgrasz kopię.',
            '',
            'A. INSTALACJA',
            '1. Na nowym hostingu utwórz PUSTĄ bazę MySQL/MariaDB i użytkownika z pełnymi prawami do niej.',
            '2. Wgraj całą zawartość ZIP-a (install.php, crm-pakiet-....zip, PRZENIESIENIE.txt, .htaccess)',
            '   do pustego katalogu, na który wskazuje nowa domena lub jej podkatalog.',
            '3. Otwórz w przeglądarce https://nowa-domena.pl/install.php (albo .../podkatalog/install.php).',
            '4. Podaj hasło kopii i dane nowej bazy. Instalator rozpakuje pliki, zaimportuje bazę,',
            '   ustawi nowy adres w CRM, a na końcu usunie pakiet i siebie.',
            '5. Panel: ' . $new . '/admin/ - logowanie tymi samymi kontami co teraz.',
            '',
            'B. CRON (zadania cykliczne)',
            '   Na STARYM serwerze usuń (albo wyłącz stary CRM) - inaczej oba CRM-y pobierałyby te same',
            '   zamówienia i wystawiały dokumenty:',
            '       * * * * * /usr/bin/php ' . $root . '/cli/worker.php >> ' . $root . '/storage/cron.log 2>&1',
            '       */5 * * * * /usr/bin/php ' . $root . '/cli/monitor.php   (jeśli był dodany)',
            '   Na NOWYM serwerze dodaj (ścieżkę do PHP sprawdź w panelu hostingu, np. /usr/local/bin/php):',
            '       * * * * * /usr/bin/php {NOWY_KATALOG}/cli/worker.php >> {NOWY_KATALOG}/storage/cron.log 2>&1',
            '       */5 * * * * /usr/bin/php {NOWY_KATALOG}/cli/monitor.php   (opcjonalnie, alarmy e-mail)',
            '   Instalator na końcu pokaże te linie z prawdziwą ścieżką.',
            '',
            'C. ALLEGRO - adres przekierowania (apps.developer.allegro.pl → Twoja aplikacja → Adresy przekierowania)',
            '   teraz: ' . ($old !== '' ? $old : '{STARY_ADRES}') . '/auth_allegro_callback.php',
            '   dodaj: ' . $new . '/auth_allegro_callback.php',
        ];
        if ($allegro) { $lines[] = '   Konta Allegro w CRM:'; array_push($lines, ...$allegro); }
        $lines[] = '   W CRM instalator ustawi nowy adres sam. Stary CRM straci połączenie z Allegro';
        $lines[] = '   po pierwszym odświeżeniu tokenu w nowym - to normalne przy przeprowadzce.';
        $lines[] = '';
        $lines[] = 'D. WOOCOMMERCE - adres dostawy webhooka (WP Admin → WooCommerce → Ustawienia → Zaawansowane → Webhooki)';
        if ($woo) { array_push($lines, ...$woo); } else { $lines[] = '   Brak podłączonych sklepów WooCommerce - nic do zmiany.'; }
        array_push($lines,
            '',
            'E. DOMENA, DNS, SSL',
            '   W panelu nowego hostingu: podepnij domenę (lub subdomenę), ustaw DNS u rejestratora',
            '   na nowy serwer i włącz certyfikat SSL (np. Let\'s Encrypt), zanim otworzysz install.php.',
            '',
            'F. PO PRZENIESIENIU',
            '   - Konfiguracja → Synchronizacja: sprawdź, czy worker działa.',
            '   - Konfiguracja → Adres URL panelu: potwierdź nowy adres i adresy webhooków.',
            '   - wFirma i przewoźnicy działają przez klucze API - nie wymagają zmian adresu.',
            '   - Linki w wysłanych wcześniej mailach do klientów prowadzą na stary adres.',
            '',
            'Pakiet zawiera klucze API, hasła i dane klientów. Przechowuj ZIP bezpiecznie',
            'i usuń go z komputera, gdy nie będzie już potrzebny.',
        );
        return implode("\n", $lines) . "\n";
    }
}
