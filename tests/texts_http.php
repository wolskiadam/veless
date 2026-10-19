<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Konfiguracja → Teksty przez HTTP: lista tekstów ze słownika, zapis zmiany (porządkowanej do zwykłego
// tekstu), zmieniony tekst widoczny w panelu, przywrócenie domyślnego, uprawnienia.
// Prawdziwe strony panelu na SQLite, bez sieci.
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Pase\\')) { require $root . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Support\PagePermissions;

$checks = 0;
function ok(bool $condition, string $name): void { global $checks; if (!$condition) { throw new RuntimeException('FAIL: ' . $name); } ++$checks; echo "OK: {$name}\n"; }
function client(): CurlHandle {
    $h = curl_init();
    curl_setopt_array($h, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10, CURLOPT_PROXY => '', CURLOPT_HEADER => false]);
    return $h;
}
function request(CurlHandle $h, string $path, ?array $data = null): array {
    global $base;
    curl_setopt($h, CURLOPT_URL, $base . $path);
    curl_setopt($h, CURLOPT_POST, $data !== null);
    if ($data !== null) { curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($data)); } else { curl_setopt($h, CURLOPT_HTTPGET, true); }
    $res = curl_exec($h);
    if ($res === false) { throw new RuntimeException(curl_error($h)); }
    return ['status' => curl_getinfo($h, CURLINFO_RESPONSE_CODE), 'body' => (string) $res];
}
function login(string $user): CurlHandle {
    $h = client();
    $page = request($h, 'admin/login.php');
    request($h, 'admin/login.php', ['csrf' => csrfOf($page['body']), 'username' => $user, 'password' => 'Only-Test-Password-42']);
    return $h;
}
function csrfOf(string $body): string {
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $m);
    return $m[1] ?? '';
}

$temp = sys_get_temp_dir() . '/crm-texts-http-' . bin2hex(random_bytes(6));
foreach (['public/admin/assets', 'config', 'storage/security', 'lang'] as $d) { mkdir("$temp/$d", 0700, true); }
$process = null;
try {
    file_put_contents("$temp/.env", "TOTP_REQUIRE_ADMIN=0\n");
    foreach (glob("$root/public/admin/*.php") as $f) { if (!in_array(basename($f), ['bootstrap_admin.php', 'db_admin.php'], true)) { copy($f, "$temp/public/admin/" . basename($f)); } }
    foreach (glob("$root/lang/*.php") as $f) { copy($f, "$temp/lang/" . basename($f)); }
    file_put_contents("$temp/public/admin/bootstrap_admin.php", '<?php \Pase\Support\PagePermissions::migrate($pdo);');
    // Jak config/config.php: teksty zmienione w panelu wczytywane z tabeli settings.
    $boot = '<?php if (!defined("PASE_ROOT")) { define("PASE_ROOT", ' . var_export($temp, true) . ');'
        . 'spl_autoload_register(static function($c) { if (str_starts_with($c, "Pase\\\\")) require ' . var_export("$root/src/", true)
        . ' . str_replace("\\\\", "/", substr($c, 5)) . ".php"; });'
        . '\Pase\Support\Env::load(PASE_ROOT . "/.env");'
        . '\Pase\Support\I18n::useOverrides((new PDO("sqlite:" . PASE_ROOT . "/test.sqlite"))->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR));'
        . 'if (!function_exists("t")) { function t($k, $v = []) { return \Pase\Support\I18n::t($k, $v); } } }'
        . 'return ["db" => [], "allegro" => []];';
    file_put_contents("$temp/config/config.php", $boot);
    file_put_contents("$temp/config/database.php", '<?php return static fn($db) => new PDO("sqlite:" . PASE_ROOT . "/test.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);');
    file_put_contents("$temp/public/admin/db_admin.php", '<?php $config = require dirname(__DIR__, 2) . "/config/config.php"; $pdo = (require PASE_ROOT . "/config/database.php")($config["db"]);');

    $pdo = new PDO("sqlite:$temp/test.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT, role TEXT, password_hash TEXT, is_active INTEGER, session_version INTEGER, totp_secret TEXT, totp_last_step INTEGER DEFAULT -1, totp_recovery_hashes TEXT, last_login TEXT, totp_required INTEGER NOT NULL DEFAULT 0, email TEXT)');
    $ins = $pdo->prepare('INSERT INTO admin_users (id,username,display_name,role,password_hash,is_active,session_version) VALUES (?,?,?,?,?,1,1)');
    foreach ([1 => ['boss', 'Adam', 'admin'], 3 => ['vi', 'Wiktor', 'viewer']] as $id => [$u, $n, $role]) {
        $ins->execute([$id, $u, $n, $role, password_hash('Only-Test-Password-42', PASSWORD_DEFAULT)]);
    }
    PagePermissions::migrate($pdo);
    \Pase\Services\AuditTrail::migrate($pdo);
    $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
    $pdo->exec("INSERT INTO settings VALUES ('APP_BASE_URL', 'https://crm.example')");

    $free = static function (): string { $s = stream_socket_server('tcp://127.0.0.1:0'); $a = stream_socket_get_name($s, false); fclose($s); return $a; };
    $address = $free();
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes);
    fclose($pipes[0]);
    for ($i = 0; $i < 100; ++$i) { $p = @stream_socket_client('tcp://' . $address, $e1, $e2, 0.1); if ($p) { fclose($p); break; } usleep(10000); }

    $boss = login('boss');
    $page = request($boss, 'admin/texts.php');
    ok($page['status'] === 200 && str_contains($page['body'], 'Teksty systemu') && str_contains($page['body'], 'name="text[client.verify.title]"'), 'Page lists dictionary texts');
    ok(str_contains($page['body'], 'href="texts.php"'), 'Page is in the menu');
    $client = request($boss, 'admin/texts.php?group=client&q=' . rawurlencode('tożsamość'));
    ok(str_contains($client['body'], 'client.verify.intro') && str_contains($client['body'], '<option value="client" selected>') && !str_contains($client['body'], 'name="text[nav.home]"'), 'Filters by area and search');

    // Zapis: tylko zmieniony tekst trafia do bazy; HTML i proste cudzysłowy są porządkowane.
    $saved = request($boss, 'admin/texts.php', ['csrf' => csrfOf($page['body']), 'text' => [
        'nav.home' => 'Start <b>"panelu"</b> it\'s',
        'orders.selected' => 'Wybrano',
        'client.verify.submit' => 'Pokaż zamówienie',
    ]]);
    $rows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'text.%' ORDER BY setting_key")->fetchAll(PDO::FETCH_KEY_PAIR);
    ok($rows === ['text.pl.nav.home' => 'Start b„panelu”/b it’s', 'text.pl.orders.selected' => 'Wybrano'], 'Only changed texts stored, cleaned to plain text');
    ok(str_contains($saved['body'], 'zmienione 2') && str_contains($saved['body'], 'brak: {n}'), 'Save reports count and missing placeholder');
    $after = request($boss, 'admin/texts.php?changed=1');
    ok(str_contains($after['body'], 'Domyślnie: Strona główna') && !str_contains($after['body'], 'name="text[client.verify.title]"'), 'Changed-only filter shows the default next to the change');
    ok(str_contains($after['body'], 'title="Start b„panelu”/b it’s"'), 'Changed text shown in the panel menu');

    // Przywróć: wraca do tekstu z pliku.
    request($boss, 'admin/texts.php', ['csrf' => csrfOf($after['body']), 'reset' => 'nav.home']);
    ok($pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key = 'text.pl.nav.home'")->fetchColumn() == 0, 'Reset removes the change');
    // Wpisanie z powrotem tekstu domyślnego też usuwa zmianę.
    request($boss, 'admin/texts.php', ['csrf' => csrfOf($after['body']), 'text' => ['orders.selected' => '{n} zaznaczonych']]);
    ok($pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key LIKE 'text.%'")->fetchColumn() == 0, 'Default text again removes the change');
    ok($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'APP_BASE_URL'")->fetchColumn() === 'https://crm.example', 'Other settings untouched');

    // Język angielski: osobne zmiany.
    $en = request($boss, 'admin/texts.php?lang=en&group=common');
    ok(str_contains($en['body'], 'value="Cancel"'), 'English dictionary shown');
    request($boss, 'admin/texts.php?lang=en&group=common', ['csrf' => csrfOf($en['body']), 'text' => ['common.cancel' => 'Abort']]);
    ok($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'text.en.common.cancel'")->fetchColumn() === 'Abort', 'English change stored per language');

    $vi = login('vi');
    $denied = request($vi, 'admin/texts.php');
    ok(!str_contains($denied['body'], 'Zapisz teksty'), 'Viewer cannot edit texts');

    $log = (string) @file_get_contents("$temp/server.log");
    ok(!preg_match('/PHP (Fatal|Warning|Notice|Deprecated)/', $log), 'No PHP errors or warnings in the server log');
    echo "All $checks checks passed\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (getenv('KEEP_TEMP')) { echo "temp: $temp\n"; } else { exec('rm -rf ' . escapeshellarg($temp)); }
}
