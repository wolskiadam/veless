<?php
declare(strict_types=1);

/**
 * Instalator pełnej kopii CRM (plik trafia do ZIP-a z System → Kopia zapasowa → Pełna kopia systemu).
 *
 * Na nowym serwerze leży obok crm-pakiet-<hex>.zip (zaszyfrowany AES-256). W przeglądarce:
 *   1. hasło kopii (bez niego nic się nie dzieje),
 *   2. dane pustej bazy MySQL/MariaDB i nowy adres panelu,
 *   3. rozpakowanie plików, import bazy (w porcjach - limity czasu shared hostingu),
 *   4. nowy .env, nowy adres w ustawieniach, klucz 2FA, usunięcie pakietu i samego instalatora.
 *
 * Samodzielny plik: nie korzysta z kodu CRM (jeszcze go nie ma w katalogu).
 */

if (PHP_SAPI === 'cli') { fwrite(STDERR, "Otwórz install.php w przeglądarce.\n"); exit(1); }

define('INSTALLER_BUDGET', (float) (getenv('CRM_INSTALLER_BUDGET') ?: 15)); // sekund pracy na jedno żądanie (env tylko dla testów)
const INSTALLER_MAX_ATTEMPTS = 20;      // błędnych haseł, potem blokada
const INSTALLER_BLOCK_BEGIN = '# BEGIN crm-installer';
const INSTALLER_BLOCK_END = '# END crm-installer';

$started = microtime(true);
$dir = __DIR__;
$work = $dir . '/storage/installer';
$stateFile = $dir . '/.crm-install-state.json';
$attemptsFile = $dir . '/.crm-install-attempts';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$https = (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
session_name('crm_installer');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => $https, 'path' => '/']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));

// ------------------------------------------------------------------ pomocnicze

function h(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

function page(string $title, string $body, bool $autoContinue = false): never
{
    $csrf = h($_SESSION['csrf'] ?? '');
    $auto = $autoContinue
        ? '<form method="post" id="cont"><input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="action" value="continue">'
          . '<p><button type="submit">Kontynuuj</button></p></form><script>setTimeout(function(){document.getElementById("cont").submit();},300);</script>'
        : '';
    echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . h($title) . ' - instalator CRM</title><style>'
        . 'body{font:15px/1.5 system-ui,sans-serif;background:#f4f5f7;color:#1d2330;margin:0;padding:24px 16px}'
        . 'main{max-width:640px;margin:0 auto;background:#fff;border:1px solid #dde1e7;border-radius:10px;padding:24px}'
        . 'h1{font-size:20px;margin:0 0 12px}label{display:block;font-size:13px;color:#4a5263;margin:12px 0 4px}'
        . 'input{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #c8ced8;border-radius:6px;font:inherit}'
        . 'button{margin-top:16px;padding:9px 18px;border:0;border-radius:6px;background:#2f5bea;color:#fff;font:inherit;cursor:pointer}'
        . '.err{background:#fdecec;color:#8a1c1c;padding:10px 12px;border-radius:6px;margin:12px 0}'
        . '.ok{background:#e9f7ee;color:#1d5c34;padding:10px 12px;border-radius:6px;margin:12px 0}'
        . '.hint{font-size:13px;color:#5b6475}code,pre{background:#f1f3f6;border-radius:4px;padding:2px 5px;font-size:13px;word-break:break-all;white-space:pre-wrap}'
        . 'pre{padding:8px 10px}li{margin:6px 0}progress{width:100%}</style></head><body><main><h1>' . h($title) . '</h1>'
        . $body . $auto . '</main></body></html>';
    exit;
}

function fail(string $message, string $extra = ''): never
{
    page('Instalacja CRM', '<div class="err">' . h($message) . '</div>' . $extra);
}

function csrfCheck(): void
{
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
        fail('Sesja wygasła. Odśwież stronę i spróbuj ponownie.');
    }
}

function loadState(string $file): ?array
{
    if (!is_file($file)) { return null; }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function saveState(string $file, array $state): void
{
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false || !rename($tmp, $file)) {
        throw new RuntimeException('Nie można zapisać postępu instalacji (brak miejsca lub uprawnień do katalogu).');
    }
}

function safeEntry(string $name): bool
{
    if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || str_contains($name, ':')
        || preg_match('/[\x00-\x1f]/', $name) || str_contains($name, '//') || str_ends_with($name, '/')) { return false; }
    foreach (explode('/', $name) as $part) { if ($part === '.' || $part === '..') { return false; } }
    return true;
}

function openPackage(string $path, string $password): array
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) { throw new RuntimeException('Nie można otworzyć pakietu - plik jest uszkodzony lub niepełny (wgraj go ponownie).'); }
    $zip->setPassword($password);
    $stat = $zip->statName('package.json');
    $raw = $stat && $stat['size'] < 64 * 1024 * 1024 ? $zip->getFromName('package.json') : false;
    $manifest = $raw === false ? null : json_decode($raw, true);
    if (!is_array($manifest)) { $zip->close(); throw new InvalidArgumentException('Nieprawidłowe hasło kopii.'); }
    if (($manifest['format'] ?? '') !== 'crm-full-v1' || !is_array($manifest['files'] ?? null)) {
        $zip->close(); throw new RuntimeException('To nie jest pakiet pełnej kopii CRM albo pochodzi z nieobsługiwanej wersji.');
    }
    return [$zip, $manifest];
}

/** Gdzie trafia wpis z pakietu (null = pomiń). */
function targetFor(string $entry, string $dir, string $work): ?string
{
    return match (true) {
        $entry === 'database.jsonl' => $work . '/database.jsonl',
        $entry === 'secrets/totp.key' => $work . '/totp.key',
        $entry === 'app/.env' => $work . '/source.env',
        $entry === 'app/install.php', str_starts_with($entry, 'app/storage/installer/') => null,
        str_starts_with($entry, 'app/') => $dir . '/' . substr($entry, 4),
        default => null,
    };
}

function extractEntry(ZipArchive $zip, string $entry, array $expected, string $target): void
{
    $parent = dirname($target);
    if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) { throw new RuntimeException('Nie można utworzyć katalogu: ' . $parent); }
    if (is_link($target)) { throw new RuntimeException('W miejscu pliku jest dowiązanie symboliczne: ' . $target); }
    $stream = $zip->getStream($entry);
    if (!$stream) { throw new RuntimeException('Nie można odczytać z pakietu: ' . $entry); }
    $tmp = $target . '.crm-part';
    $out = fopen($tmp, 'wb');
    if (!$out) { fclose($stream); throw new RuntimeException('Nie można zapisać pliku: ' . $target); }
    $hash = hash_init('sha256'); $bytes = 0;
    try {
        while (!feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);
            if ($chunk === false) { throw new RuntimeException('Błąd odczytu pakietu: ' . $entry); }
            if ($chunk === '' ) { if (feof($stream)) { break; } throw new RuntimeException('Błąd odczytu pakietu: ' . $entry); }
            $bytes += strlen($chunk); hash_update($hash, $chunk);
            if (fwrite($out, $chunk) !== strlen($chunk)) { throw new RuntimeException('Brak miejsca na dysku przy zapisie: ' . $entry); }
        }
    } finally { fclose($stream); fclose($out); }
    if ($bytes !== (int) $expected['size'] || !hash_equals((string) $expected['sha256'], hash_final($hash))) {
        @unlink($tmp);
        throw new RuntimeException('Suma kontrolna się nie zgadza (uszkodzony pakiet): ' . $entry);
    }
    if (!rename($tmp, $target)) { @unlink($tmp); throw new RuntimeException('Nie można zapisać pliku: ' . $target); }
    if (str_contains($target, '/storage/security/') || str_contains($target, '/storage/installer/')) { chmod($target, 0600); }
}

function connectDb(array $db): PDO
{
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['name']),
        $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $pdo->exec("SET time_zone = '+00:00'");
    $pdo->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    return $pdo;
}

function ident(string $name): string
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) { throw new RuntimeException('Nieobsługiwana nazwa tabeli/kolumny w kopii.'); }
    return '`' . $name . '`';
}

/** Import snapshotu (format crm-db-v1) od zapisanego miejsca. true = skończone. */
function importDatabase(PDO $pdo, string $file, array &$st, float $deadline, callable $save): bool
{
    $in = fopen($file, 'rb');
    if (!$in || fseek($in, (int) $st['offset']) !== 0) { throw new RuntimeException('Nie można odczytać kopii bazy.'); }
    $maria = stripos((string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'mariadb') !== false;
    $batch = []; $cols = null; $bytes = 0; $pos = (int) $st['offset'];
    $flush = function () use (&$batch, &$cols, &$bytes, &$pos, &$st, $pdo, $save): void {
        if (!$batch) { return; }
        $row = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $sql = 'INSERT INTO ' . ident($st['table']) . ' (' . implode(',', array_map('ident', $cols)) . ') VALUES '
            . implode(',', array_fill(0, count($batch), $row));
        $pdo->beginTransaction();
        try { $pdo->prepare($sql)->execute(array_merge(...$batch)); $pdo->commit(); }
        catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        $st['counts'][$st['table']] += count($batch); $st['offset'] = $pos;
        $batch = []; $bytes = 0;
        $save($st);
    };
    try {
        while (($line = fgets($in)) !== false) {
            $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (isset($record['row'])) {
                if ($st['table'] === null) { throw new RuntimeException('Uszkodzona kopia bazy.'); }
                $keys = array_keys($record['row']);
                if ($cols !== $keys || count($batch) >= 200 || count($batch) * count($keys) > 20000 || $bytes > 2 * 1024 * 1024) { $flush(); $cols = $keys; }
                $values = [];
                foreach ($record['row'] as $value) {
                    $decoded = $value === null ? null : base64_decode($value, true);
                    if ($decoded === false) { throw new RuntimeException('Uszkodzona kopia bazy.'); }
                    $values[] = $decoded; $bytes += $decoded === null ? 0 : strlen($decoded);
                }
                $batch[] = $values; $pos = (int) ftell($in);
            } elseif (isset($record['table'])) {
                $flush();
                $table = (string) $record['table']; $schema = (string) $record['schema'];
                if (!preg_match('/^CREATE TABLE\s/i', $schema) || isset($st['counts'][$table])) { throw new RuntimeException('Uszkodzona kopia bazy.'); }
                if ($maria) { $schema = preg_replace('/utf8mb4_0900_\w+/', 'utf8mb4_unicode_ci', $schema); }
                $pdo->exec('DROP TABLE IF EXISTS ' . ident($table));
                $pdo->exec($schema);
                $st['table'] = $table; $st['counts'][$table] = 0; $cols = null;
                $pos = (int) ftell($in); $st['offset'] = $pos; $save($st);
            } elseif (isset($record['format'])) {
                if ($record['format'] !== 'crm-db-v1' || ($record['driver'] ?? '') !== 'mysql') { throw new RuntimeException('Kopia bazy ma nieobsługiwany format.'); }
                $pos = (int) ftell($in); $st['offset'] = $pos;
            } elseif (isset($record['end'])) {
                $flush();
                if ($record['end'] != $st['counts']) { throw new RuntimeException('Liczba zaimportowanych wierszy nie zgadza się z kopią.'); }
                foreach ($st['counts'] as $table => $count) {
                    if ((int) $pdo->query('SELECT COUNT(*) FROM ' . ident($table))->fetchColumn() !== $count) {
                        throw new RuntimeException('Liczba wierszy w tabeli ' . $table . ' nie zgadza się z kopią.');
                    }
                }
                return true;
            } elseif (!isset($record['index'])) {
                throw new RuntimeException('Uszkodzona kopia bazy.');
            }
            if (microtime(true) > $deadline) { $flush(); return false; }
        }
        throw new RuntimeException('Kopia bazy jest niepełna.');
    } finally { fclose($in); }
}

function envLine(string $key, string $value): string
{
    return $key . '="' . $value . '"';
}

/** Nowy .env: dane nowej bazy i adresu, bez ścieżek ze starego serwera. */
function buildEnv(string $source, array $db, string $oldBase, string $newBase): string
{
    $set = ['DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_NAME' => $db['name'], 'DB_USER' => $db['user'],
        'DB_PASS' => $db['pass'], 'ALLEGRO_REDIRECT_URI' => $newBase . '/auth_allegro_callback.php'];
    $comment = ['TOTP_KEY_FILE', 'BACKUP_DIR', 'BACKUP_WEB_ROOT', 'BACKUP_KEY_FILE'];
    $out = []; $done = [];
    foreach (preg_split('/\r\n|\n|\r/', $source) as $line) {
        $trim = trim($line);
        if ($trim !== '' && $trim[0] !== '#' && str_contains($trim, '=')) {
            [$key, $value] = explode('=', $trim, 2); $key = trim($key); $value = trim($value);
            if (array_key_exists($key, $set)) {
                if (!isset($done[$key])) { $out[] = envLine($key, $set[$key]); $done[$key] = true; }
                continue;
            }
            if (in_array($key, $comment, true)) {
                $out[] = '# ' . $trim . '   # wyłączone przez instalator (ścieżka ze starego serwera)';
                continue;
            }
            $quoted = strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'");
            $plain = $quoted ? substr($value, 1, -1) : $value;
            if ($oldBase !== '' && $oldBase !== $newBase && str_starts_with($plain, $oldBase)) {
                $out[] = envLine($key, $newBase . substr($plain, strlen($oldBase)));
                continue;
            }
        }
        $out[] = $line;
    }
    while ($out !== [] && trim(end($out)) === '') { array_pop($out); }
    foreach ($set as $key => $value) { if (!isset($done[$key])) { $out[] = envLine($key, $value); } }
    return implode("\n", $out) . "\n";
}

function replaceBase(mixed $value, string $old, string $new): mixed
{
    if (is_string($value)) { return str_starts_with($value, $old) ? $new . substr($value, strlen($old)) : $value; }
    if (is_array($value)) { foreach ($value as $k => $v) { $value[$k] = replaceBase($v, $old, $new); } }
    return $value;
}

/** Adres panelu w ustawieniach i integracjach (to, co w CRM robi Konfiguracja → Adres URL panelu). */
function updateStoredUrls(PDO $pdo, string $oldBase, string $newBase): void
{
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('settings', $tables, true)) {
        if ($oldBase !== '' && $oldBase !== $newBase) {
            $update = $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
            foreach ($pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR) as $key => $value) {
                if ($value === null) { continue; }
                $changed = str_replace([$oldBase, str_replace('/', '\\/', $oldBase)], [$newBase, str_replace('/', '\\/', $newBase)], $value);
                if ($changed !== $value) { $update->execute([$changed, $key]); }
            }
        }
        $upsert = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $upsert->execute(['APP_BASE_URL', $newBase]);
        $upsert->execute(['ALLEGRO_REDIRECT_URI', $newBase . '/auth_allegro_callback.php']);
    }
    if (in_array('integration_accounts', $tables, true)) {
        $update = $pdo->prepare('UPDATE integration_accounts SET config = ? WHERE id = ?');
        foreach ($pdo->query('SELECT id, type, config FROM integration_accounts')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $config = json_decode((string) $row['config'], true);
            if (!is_array($config)) { continue; }
            $new = $oldBase !== '' && $oldBase !== $newBase ? replaceBase($config, $oldBase, $newBase) : $config;
            if ($row['type'] === 'allegro' && array_key_exists('redirect_uri', $new)) { $new['redirect_uri'] = $newBase . '/auth_allegro_callback.php'; }
            if ($new !== $config) { $update->execute([json_encode($new, JSON_UNESCAPED_UNICODE), $row['id']]); }
        }
    }
}

function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) { return; }
    foreach (scandir($path) ?: [] as $entry) { if ($entry !== '.' && $entry !== '..') { removeTree($path . '/' . $entry); } }
    @rmdir($path);
}

function detectBaseUrl(bool $https): string
{
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $path = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/install.php'))), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $path . '/public';
}

function dbForm(array $db, string $baseUrl, bool $https): string
{
    $v = static fn(string $k, string $d = '') => h($db[$k] ?? $d);
    return '<form method="post"><input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '"><input type="hidden" name="action" value="configure">'
        . '<p class="hint">Baza musi być <strong>pusta</strong> - utwórz ją wcześniej w panelu hostingu (MySQL lub MariaDB).</p>'
        . '<label>Host bazy</label><input name="db_host" value="' . $v('host', 'localhost') . '" required>'
        . '<label>Port</label><input name="db_port" value="' . $v('port', '3306') . '" inputmode="numeric" required>'
        . '<label>Nazwa bazy</label><input name="db_name" value="' . $v('name') . '" required>'
        . '<label>Użytkownik bazy</label><input name="db_user" value="' . $v('user') . '" required autocomplete="off">'
        . '<label>Hasło bazy</label><input type="password" name="db_pass" autocomplete="off">'
        . '<label>Adres CRM na nowym serwerze (katalog public/)</label><input name="base_url" value="' . h($baseUrl) . '" required>'
        . '<p class="hint">Wykryty automatycznie z adresu tej strony. Panel będzie pod tym adresem + <code>/admin/</code>.'
        . ($https ? '' : ' <strong>Strona jest otwarta bez https</strong> - jeśli domena ma już certyfikat SSL, otwórz instalator przez https://.') . '</p>'
        . '<button type="submit">Instaluj</button></form>';
}

// ------------------------------------------------------------------ przebieg

$state = loadState($stateFile);
$packages = array_values(array_filter(glob($dir . '/crm-pakiet-*.zip') ?: [], static fn($p) => preg_match('/^crm-pakiet-[a-f0-9]{32}\.zip$/D', basename($p)) && is_file($p)));
if (count($packages) !== 1) {
    fail(count($packages) === 0
        ? 'Nie znaleziono pakietu crm-pakiet-….zip obok install.php. Wgraj całą zawartość pobranej kopii do tego katalogu.'
        : 'W katalogu jest więcej niż jeden pakiet crm-pakiet-….zip. Zostaw tylko ten, który chcesz zainstalować.');
}
$package = $packages[0];

// Wymagania serwera.
$problems = [];
if (version_compare(PHP_VERSION, '8.1.0', '<')) { $problems[] = 'PHP 8.1 lub nowszy (jest ' . PHP_VERSION . ') - zmień wersję PHP w panelu hostingu.'; }
if (!extension_loaded('pdo_mysql')) { $problems[] = 'Rozszerzenie PHP pdo_mysql.'; }
if (!class_exists(ZipArchive::class) || !ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, false)) { $problems[] = 'Rozszerzenie PHP zip z obsługą AES-256.'; }
if (!is_writable($dir)) { $problems[] = 'Prawo zapisu do katalogu ' . $dir . '.'; }
if ($problems) {
    fail('Serwer nie spełnia wymagań CRM:', '<ul>' . implode('', array_map(static fn($p) => '<li>' . h($p) . '</li>', $problems)) . '</ul>');
}

$action = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? (string) ($_POST['action'] ?? '') : '';
if ($action !== '') { csrfCheck(); }

// 1. Hasło kopii.
$unlocked = isset($_SESSION['password']) && ($_SESSION['package'] ?? '') === basename($package);
if (!$unlocked) {
    $attempts = is_file($attemptsFile) ? (int) file_get_contents($attemptsFile) : 0;
    if ($attempts >= INSTALLER_MAX_ATTEMPTS) {
        fail('Za dużo błędnych haseł. Usuń plik .crm-install-attempts z tego katalogu (przez FTP / menedżer plików), żeby spróbować ponownie.');
    }
    $error = '';
    if ($action === 'unlock') {
        try {
            [$zip] = openPackage($package, (string) ($_POST['password'] ?? ''));
            $zip->close();
            session_regenerate_id(true);
            $_SESSION['password'] = (string) $_POST['password'];
            $_SESSION['package'] = basename($package);
            @unlink($attemptsFile);
            header('Location: ' . basename(__FILE__), true, 303);
            exit;
        } catch (InvalidArgumentException $e) {
            file_put_contents($attemptsFile, (string) ($attempts + 1));
            sleep(2);
            $error = $e->getMessage();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
    page('Instalacja CRM z pełnej kopii', ($error !== '' ? '<div class="err">' . h($error) . '</div>' : '')
        . '<p>Podaj hasło, które zostało wpisane przy tworzeniu kopii. Bez niego pakietu nie da się odczytać.</p>'
        . '<form method="post"><input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '"><input type="hidden" name="action" value="unlock">'
        . '<label>Hasło kopii</label><input type="password" name="password" required autofocus autocomplete="off"><button type="submit">Dalej</button></form>');
}

try {
    [$zip, $manifest] = openPackage($package, (string) $_SESSION['password']);
} catch (Throwable $e) {
    unset($_SESSION['password']);
    fail($e->getMessage());
}
$save = static function (array $st) use ($stateFile): void { saveState($stateFile, $st); };

try {
    // 2. Baza i adres.
    $needDb = $state === null || !isset($_SESSION['db']);
    if ($needDb) {
        if ($state === null && (is_file($dir . '/config/config.php') || is_file($dir . '/.env'))) {
            fail('W tym katalogu jest już zainstalowany CRM. Instalator działa tylko w pustym katalogu - wgraj kopię do nowego katalogu albo usuń stare pliki.');
        }
        $error = ''; $db = $_SESSION['db'] ?? [];
        $baseUrl = $state['base_url'] ?? detectBaseUrl($https);
        if ($action === 'configure') {
            $db = ['host' => trim((string) ($_POST['db_host'] ?? '')), 'port' => (int) ($_POST['db_port'] ?? 0), 'name' => trim((string) ($_POST['db_name'] ?? '')),
                'user' => trim((string) ($_POST['db_user'] ?? '')), 'pass' => (string) ($_POST['db_pass'] ?? '')];
            $baseUrl = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/');
            try {
                if ($db['host'] === '' || $db['name'] === '' || $db['user'] === '' || $db['port'] < 1 || $db['port'] > 65535) { throw new RuntimeException('Uzupełnij dane bazy.'); }
                if (preg_match('/[\r\n"]/', implode('', [$db['host'], $db['name'], $db['user'], $db['pass']]))) { throw new RuntimeException('Dane bazy nie mogą zawierać znaku " ani nowej linii.'); }
                if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $baseUrl) || preg_match('/[\s"]/', $baseUrl)) { throw new RuntimeException('Podaj pełny adres, np. https://nowa-domena.pl/public'); }
                try { $pdo = connectDb($db); }
                catch (PDOException $e) { throw new RuntimeException('Nie udało się połączyć z bazą: ' . $e->getMessage()); }
                if ($state === null && $pdo->query('SHOW TABLES')->fetchAll()) {
                    throw new RuntimeException('Baza ' . $db['name'] . ' nie jest pusta. Użyj nowej, pustej bazy - instalator niczego nie nadpisuje.');
                }
                $_SESSION['db'] = $db;
                if ($state === null) {
                    if (!is_dir($work) && !mkdir($work, 0700, true)) { throw new RuntimeException('Nie można utworzyć katalogu storage/installer.'); }
                    file_put_contents($work . '/.htaccess', "Require all denied\n");
                    $state = ['phase' => 'extract', 'index' => 0, 'base_url' => $baseUrl, 'offset' => 0, 'table' => null, 'counts' => [], 'started_at' => gmdate('c')];
                    $save($state);
                }
                header('Location: ' . basename(__FILE__), true, 303);
                exit;
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }
        $info = '<div class="ok">Hasło poprawne. Kopia z ' . h(date('d.m.Y H:i', strtotime((string) $manifest['created_at']))) . ' UTC'
            . (($manifest['app_version'] ?? '') !== '' ? ', wersja ' . h($manifest['app_version']) : '') . ', plików: ' . count($manifest['files']) . '.</div>';
        page($state === null ? 'Dane nowej bazy' : 'Wznowienie instalacji', ($error !== '' ? '<div class="err">' . h($error) . '</div>' : '') . $info
            . ($state !== null ? '<p>Instalacja została przerwana. Podaj ponownie dane bazy, żeby ją dokończyć.</p>' : '')
            . dbForm($db, $baseUrl, $https));
    }

    $deadline = $started + INSTALLER_BUDGET;
    @set_time_limit(120);
    ignore_user_abort(true);

    // 3. Pliki.
    if ($state['phase'] === 'extract') {
        $entries = array_keys($manifest['files']);
        $total = count($entries);
        while ($state['index'] < $total) {
            $entry = (string) $entries[$state['index']];
            if (!safeEntry($entry)) { throw new RuntimeException('Pakiet zawiera niebezpieczną ścieżkę: ' . $entry); }
            $target = targetFor($entry, $dir, $work);
            if ($target !== null) {
                extractEntry($zip, $entry, $manifest['files'][$entry], $target);
                if ($entry === 'app/.htaccess') {
                    // Blokada pakietu musi działać do końca instalacji, także po podmianie .htaccess na ten z CRM.
                    file_put_contents($target, "\n" . INSTALLER_BLOCK_BEGIN . "\n<FilesMatch \"^(crm-pakiet-.*\\.zip|\\.crm-install.*|PRZENIESIENIE\\.txt)$\">\n    Require all denied\n</FilesMatch>\n" . INSTALLER_BLOCK_END . "\n", FILE_APPEND);
                }
            }
            $state['index']++;
            if (microtime(true) > $deadline) { break; }
        }
        if ($state['index'] >= $total) {
            if (!is_file($work . '/database.jsonl')) { throw new RuntimeException('W pakiecie brakuje kopii bazy.'); }
            $state['phase'] = 'database';
        }
        $save($state);
        $pct = $total ? (int) floor($state['index'] * 100 / $total) : 100;
        page('Rozpakowywanie plików', '<p>Pliki: ' . $state['index'] . ' z ' . $total . '.</p><progress max="100" value="' . $pct . '"></progress>'
            . '<p class="hint">Nie zamykaj tej karty - strona sama przechodzi dalej.</p>', true);
    }

    // 4. Baza.
    $pdo = connectDb($_SESSION['db']);
    if ($state['phase'] === 'database') {
        $done = importDatabase($pdo, $work . '/database.jsonl', $state, $deadline, $save);
        if ($done) { $state['phase'] = 'finalize'; }
        $save($state);
        $tables = count($manifest['db_tables'] ?? []);
        $rows = array_sum($manifest['db_tables'] ?? []); $doneRows = array_sum($state['counts']);
        page('Import bazy danych', '<p>Tabele: ' . count($state['counts']) . ' z ' . $tables . ', wiersze: ' . number_format($doneRows, 0, ',', ' ')
            . ' z ' . number_format($rows, 0, ',', ' ') . '.</p><progress max="' . max(1, $rows) . '" value="' . $doneRows . '"></progress>'
            . '<p class="hint">Nie zamykaj tej karty - strona sama przechodzi dalej.</p>', true);
    }

    // 5. Konfiguracja i sprzątanie.
    if ($state['phase'] !== 'finalize') { throw new RuntimeException('Nieznany etap instalacji. Usuń plik .crm-install-state.json i zacznij od nowa na pustej bazie.'); }
    $db = $_SESSION['db']; $newBase = (string) $state['base_url']; $oldBase = (string) ($manifest['source_base_url'] ?? '');
    updateStoredUrls($pdo, $oldBase, $newBase);
    $sourceEnv = is_file($work . '/source.env') ? (string) file_get_contents($work . '/source.env') : "APP_ENV=production\nAPP_DEBUG=false\n";
    $env = buildEnv($sourceEnv, $db, $oldBase, $newBase);
    if (file_put_contents($dir . '/.env.crm-part', $env) === false || !chmod($dir . '/.env.crm-part', 0600) || !rename($dir . '/.env.crm-part', $dir . '/.env')) {
        throw new RuntimeException('Nie można zapisać pliku .env.');
    }
    if (is_file($work . '/totp.key')) {
        // Klucz 2FA leżał poza aplikacją; na nowym serwerze w domyślnym miejscu (TOTP_KEY_FILE wyłączony w .env).
        if (!is_dir($dir . '/storage/security')) { mkdir($dir . '/storage/security', 0700, true); }
        if (!rename($work . '/totp.key', $dir . '/storage/security/totp.key')) { throw new RuntimeException('Nie można zapisać klucza 2FA.'); }
        chmod($dir . '/storage/security/totp.key', 0600);
    }
    foreach (['sessions', 'backups'] as $sub) { if (!is_dir($dir . '/storage/' . $sub)) { @mkdir($dir . '/storage/' . $sub, 0700, true); } }
    $htaccess = $dir . '/.htaccess';
    if (is_file($htaccess)) {
        $content = (string) file_get_contents($htaccess);
        $content = preg_replace('/\n?' . preg_quote(INSTALLER_BLOCK_BEGIN, '/') . '.*?' . preg_quote(INSTALLER_BLOCK_END, '/') . '\n?/s', "\n", $content);
        if (!isset($manifest['files']['app/.htaccess'])) { @unlink($htaccess); } else { file_put_contents($htaccess, rtrim($content) . "\n"); }
    }
    $zip->close(); $zip = null;
    @unlink($package);
    removeTree($work);
    @unlink($stateFile); @unlink($attemptsFile); @unlink($dir . '/INSTRUKCJA.txt'); @unlink($dir . '/PRZENIESIENIE.txt');
    $selfDeleted = @unlink(__FILE__);

    $webhooks = [];
    try {
        foreach ($pdo->query("SELECT id, name FROM integration_accounts WHERE type = 'woocommerce' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $w) {
            $webhooks[] = '<li>' . h($w['name']) . ': <code>' . h($newBase . '/webhook_woo_order.php?integration=' . (int) $w['id']) . '</code></li>';
        }
    } catch (Throwable) {}
    $_SESSION = [];
    session_destroy();
    $cron = '* * * * * /usr/bin/php ' . $dir . '/cli/worker.php >> ' . $dir . '/storage/cron.log 2>&1';
    $monitor = '*/5 * * * * /usr/bin/php ' . $dir . '/cli/monitor.php';
    page('CRM zainstalowany', '<div class="ok">Pliki i baza zostały przeniesione (tabel: ' . count($state['counts']) . ', wierszy: '
        . number_format(array_sum($state['counts']), 0, ',', ' ') . '). Pakiet z danymi został usunięty z serwera.</div>'
        . ($selfDeleted ? '' : '<div class="err">Nie udało się usunąć install.php - usuń go ręcznie przez FTP.</div>')
        . '<p><a href="' . h($newBase . '/admin/') . '"><strong>Otwórz panel CRM</strong></a> - zaloguj się tym samym kontem co na starym serwerze.</p>'
        . '<p><strong>Do zrobienia ręcznie:</strong></p><ol>'
        . '<li>Na <strong>starym</strong> serwerze usuń zadania cron CRM (albo wyłącz stary CRM). Inaczej oba systemy pobierałyby te same zamówienia i wystawiały dokumenty. Po pierwszym odświeżeniu tokenu Allegro stary CRM i tak straci połączenie z Allegro.</li>'
        . '<li>Na nowym serwerze dodaj cron co minutę (ścieżkę do PHP sprawdź w panelu hostingu):<pre>' . h($cron) . '</pre>Opcjonalnie monitor co 5 minut:<pre>' . h($monitor) . '</pre></li>'
        . '<li>W aplikacji Allegro (apps.developer.allegro.pl) dodaj adres przekierowania:<pre>' . h($newBase . '/auth_allegro_callback.php') . '</pre></li>'
        . ($webhooks ? '<li>W WooCommerce (Ustawienia → Zaawansowane → Webhooki) zmień adres dostawy:<ul>' . implode('', $webhooks) . '</ul></li>' : '')
        . '<li>Domena, DNS i certyfikat SSL ustawia się w panelu hostingu. Sprawdź też Konfiguracja → Synchronizacja, czy worker działa.</li>'
        . '</ol>');
} catch (Throwable $e) {
    if (isset($zip) && $zip instanceof ZipArchive) { @$zip->close(); }
    $msg = $e instanceof PDOException ? 'Błąd bazy danych: ' . $e->getMessage() : $e->getMessage();
    fail($msg, '<p class="hint">Postęp jest zapisany - po usunięciu przyczyny odśwież stronę, a instalacja będzie kontynuowana.</p>');
}
