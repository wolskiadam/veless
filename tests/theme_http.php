<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Kolor systemu (Konfiguracja → Wygląd): Support\Theme + prawdziwe appearance.php, products.php, login.php i manifest.php na SQLite.
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Pase\\')) { require $root . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Support\PagePermissions;
use Pase\Support\Theme;

$checks = 0;
function ok(bool $condition, string $name): void { global $checks; if (!$condition) { throw new RuntimeException('FAIL: ' . $name); } ++$checks; echo "OK: {$name}\n"; }
function client(): CurlHandle {
    $h = curl_init();
    curl_setopt_array($h, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10, CURLOPT_PROXY => '', CURLOPT_HEADER => true]);
    return $h;
}
function request(CurlHandle $h, string $path, ?array $data = null, array $headers = []): array {
    global $base;
    curl_setopt($h, CURLOPT_URL, $base . $path);
    curl_setopt($h, CURLOPT_POST, $data !== null);
    if ($data !== null) { curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($data)); }
    curl_setopt($h, CURLOPT_HTTPHEADER, $headers);
    $res = curl_exec($h);
    if ($res === false) { throw new RuntimeException(curl_error($h)); }
    $len = curl_getinfo($h, CURLINFO_HEADER_SIZE);
    $body = substr($res, $len);
    return ['status' => curl_getinfo($h, CURLINFO_RESPONSE_CODE), 'headers' => substr($res, 0, $len), 'body' => $body,
            'json' => json_decode($body, true)];
}
function login(string $user): CurlHandle {
    $h = client();
    $page = request($h, 'admin/login.php');
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $page['body'], $m);
    $r = request($h, 'admin/login.php', ['csrf' => $m[1] ?? '', 'username' => $user, 'password' => 'Only-Test-Password-42']);
    if (!in_array($r['status'], [302, 303], true)) { throw new RuntimeException('Login failed for ' . $user); }
    return $h;
}
function csrfOf(string $body): string {
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $m);
    return $m[1] ?? '';
}

$temp = sys_get_temp_dir() . '/crm-theme-http-' . bin2hex(random_bytes(6));
foreach (['public/admin/assets', 'public/pack/assets', 'config', 'storage/security', 'lang'] as $d) { mkdir("$temp/$d", 0700, true); }
$process = null;
try {
    file_put_contents("$temp/.env", "TOTP_REQUIRE_ADMIN=0\n");
    foreach (glob("$root/public/admin/*.php") as $f) { if (!in_array(basename($f), ['bootstrap_admin.php', 'db_admin.php'], true)) { copy($f, "$temp/public/admin/" . basename($f)); } }
    copy("$root/public/admin/assets/loc-picker.js", "$temp/public/admin/assets/loc-picker.js");
    foreach (glob("$root/lang/*.php") as $f) { copy($f, "$temp/lang/" . basename($f)); }
    file_put_contents("$temp/public/admin/bootstrap_admin.php", '<?php \Pase\Support\PagePermissions::migrate($pdo);');

    // Konfiguracja jak config/config.php, ale z SQLite i kodem z repozytorium.
    $boot = '<?php if (!defined("PASE_ROOT")) { define("PASE_ROOT", ' . var_export($temp, true) . ');'
        . 'spl_autoload_register(static function($c) { if (str_starts_with($c, "Pase\\\\")) require ' . var_export("$root/src/", true)
        . ' . str_replace("\\\\", "/", substr($c, 5)) . ".php"; });'
        . '\Pase\Support\Env::load(PASE_ROOT . "/.env");'
        . 'if (!function_exists("t")) { function t($k, $v = []) { return \Pase\Support\I18n::t($k, $v); } } }'
        . 'return ["db" => [], "allegro" => []];';
    file_put_contents("$temp/config/config.php", $boot);
    file_put_contents("$temp/config/database.php", '<?php return static fn($db) => new PDO("sqlite:" . PASE_ROOT . "/test.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);');
    file_put_contents("$temp/public/admin/db_admin.php", '<?php $config = require dirname(__DIR__, 2) . "/config/config.php"; $pdo = (require PASE_ROOT . "/config/database.php")($config["db"]);');

    $pdo = new PDO("sqlite:$temp/test.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT, role TEXT, password_hash TEXT, is_active INTEGER, session_version INTEGER, totp_secret TEXT, totp_last_step INTEGER DEFAULT -1, totp_recovery_hashes TEXT, last_login TEXT, totp_required INTEGER NOT NULL DEFAULT 0, email TEXT)');
    $ins = $pdo->prepare('INSERT INTO admin_users (id,username,display_name,role,password_hash,is_active,session_version) VALUES (?,?,?,?,?,1,1)');
    foreach ([1 => ['boss', 'Adam', 'admin'], 2 => ['ola', 'Ola', 'editor'], 3 => ['vi', 'Wiktor', 'viewer']] as $id => [$u, $n, $role]) {
        $ins->execute([$id, $u, $n, $role, password_hash('Only-Test-Password-42', PASSWORD_DEFAULT)]);
    }
    PagePermissions::migrate($pdo);
    \Pase\Services\AuditTrail::migrate($pdo);
    $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
    $pdo->exec('CREATE TABLE order_statuses (id INTEGER PRIMARY KEY, status_key TEXT, label TEXT, color TEXT, position INTEGER)');
    $pdo->exec("INSERT INTO order_statuses (status_key,label,color,position) VALUES ('new','Nowe','#8a8f98',1),('processing','W realizacji','#c28a2b',2),('packed','Spakowane','#2f8f5b',3),('hold','Wstrzymane','#b8412c',4)");
    $pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT, is_active INTEGER, config TEXT)');
    $pdo->exec('CREATE TABLE product_mappings (id INTEGER PRIMARY KEY, sku TEXT, allegro_offer_id TEXT)');
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, pase_stock INTEGER, pase_price REAL, source_integration_id INTEGER, woo_product_id INTEGER,
        woo_variation_id INTEGER, payload TEXT, imported_at TEXT, updated_at TEXT, description TEXT, short_description TEXT, images TEXT, categories TEXT, attributes TEXT,
        ean TEXT, weight REAL, length REAL, width REAL, height REAL, custom_fields TEXT, allegro_sections TEXT)');
    $pins = $pdo->prepare('INSERT INTO products (id, sku, name, pase_stock, pase_price) VALUES (?,?,?,?,?)');
    foreach ([[1, 'GB-1L', 'GINGERBREAD 1l'], [2, 'MW-1L', 'MULLED WINE 1l'], [3, 'CC-1L', 'CLEMENTINE CUPCAKE 1l']] as [$pid, $sku, $name]) {
        $pins->execute([$pid, $sku, $name, 5, 49.9]);
    }
    \Pase\Services\WarehouseLocations::migrate($pdo);
    $wl = new \Pase\Services\WarehouseLocations($pdo);
    for ($rack = 1; $rack <= 12; $rack++) {
        foreach (range('A', 'Y') as $shelf) { $wl->create((string) $rack, $shelf, null, $rack === 7 && $shelf === 'K' ? 'Świece zapachowe 1 l' : null); }
    }
    $loc = static fn(string $code): int => $wl->findByCode($code)['id'];
    $pdo->exec('CREATE TABLE user_layouts (user_id INTEGER NOT NULL, view_key TEXT NOT NULL, layout TEXT NOT NULL, updated_at TEXT, PRIMARY KEY (user_id, view_key))');

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes);
    fclose($pipes[0]);
    for ($i = 0; $i < 100; ++$i) { $p = @stream_socket_client('tcp://' . $address, $e1, $e2, 0.1); if ($p) { fclose($p); break; } usleep(10000); }


    // --- Support\Theme bez HTTP ---
    ok(Theme::normalize(null) === Theme::defaults() && Theme::css(null) === '', 'Default colours add no CSS');
    ok(Theme::normalize(['accent' => 'nope', 'tone' => 'pink']) === Theme::defaults(), 'Invalid colour and tone fall back to defaults');
    ok(Theme::normalize(['accent' => '#2F5BB7', 'tone' => 'cool']) === ['accent' => '#2f5bb7', 'tone' => 'cool'], 'Hex is normalised, tone kept');
    ok(Theme::hex('abc') === '#aabbcc' && Theme::hex('#12345') === null, 'Short hex expands, broken hex rejected');
    $light = Theme::normalize(['accent' => '#ffe066'])['accent'];
    ok($light !== '#ffe066' && Theme::contrastWithWhite($light) >= 3.0, 'Too light colour is darkened for white button text');
    foreach (Theme::PRESETS as [$name, $hex]) { ok(Theme::readable($hex) === $hex, "Preset {$name} is readable as is"); }
    $css = Theme::css(['accent' => '#2f5bb7', 'tone' => 'neutral']);
    ok(str_starts_with($css, ':root{--accent:#2f5bb7;--accent-hover:#') && str_contains($css, '--accent-soft:#') && str_contains($css, '--bg:#f5f6f7'), 'CSS variables derived from one colour');
    ok(Theme::load($pdo) === Theme::defaults(), 'Nothing saved = defaults');
    Theme::resetCache();

    $boss = login('boss');
    $page = request($boss, 'admin/products.php');
    ok($page['status'] === 200 && !str_contains($page['body'], 'id="ui-theme"') && str_contains($page['body'], '<meta name="theme-color" content="#9c6b2e">'), 'Panel looks as before with default colours');
    ok(str_contains($page['body'], 'href="appearance.php"'), 'Admin sees Konfiguracja → Wygląd in the menu');

    $ap = request($boss, 'admin/appearance.php');
    ok($ap['status'] === 200 && str_contains($ap['body'], 'Kolor systemu') && substr_count($ap['body'], 'name="preset"') === count(Theme::PRESETS) + 1, 'Appearance page lists presets and own colour');
    $r = request($boss, 'admin/appearance.php', ['csrf' => 'bad', 'accent' => '#2f5bb7', 'tone' => 'cool']);
    ok($pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key = 'ui_theme'")->fetchColumn() == 0, 'Save without CSRF token is refused');
    $r = request($boss, 'admin/appearance.php', ['csrf' => csrfOf($ap['body']), 'accent' => '#2F5BB7', 'tone' => 'cool']);
    ok(in_array($r['status'], [302, 303], true), 'Colours saved');
    ok(json_decode((string) $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'ui_theme'")->fetchColumn(), true) === ['accent' => '#2f5bb7', 'tone' => 'cool'], 'Stored as JSON in settings');

    $page = request($boss, 'admin/products.php');
    ok(str_contains($page['body'], '<style id="ui-theme">:root{--accent:#2f5bb7;') && str_contains($page['body'], '--bg:#f3f6fa')
        && str_contains($page['body'], '<meta name="theme-color" content="#2f5bb7">'), 'Every panel page uses the new colours');
    $ola = login('ola');
    ok(str_contains(request($ola, 'admin/products.php')['body'], '--accent:#2f5bb7'), 'Colours are shared by all users');
    ok(request($ola, 'admin/appearance.php')['status'] === 403, 'Editor cannot change colours');
    ok(str_contains(request(client(), 'admin/login.php')['body'], '--accent:#2f5bb7'), 'Login screen uses the new colour');
    ok((json_decode(request(client(), 'admin/manifest.php')['body'], true)['theme_color'] ?? null) === '#2f5bb7', 'Phone app colour follows the setting');

    $ap = request($boss, 'admin/appearance.php');
    request($boss, 'admin/appearance.php', ['csrf' => csrfOf($ap['body']), 'accent' => '#fff3b0', 'tone' => 'warm']);
    $saved = json_decode((string) $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'ui_theme'")->fetchColumn(), true);
    ok(($saved['accent'] ?? '') !== '#fff3b0' && Theme::contrastWithWhite((string) $saved['accent']) >= 3.0, 'Light colour from the form is saved darkened');
    ok(str_contains(request($boss, 'admin/products.php')['body'], 'przyciemniliśmy'), 'Admin is told the colour was darkened');

    $ap = request($boss, 'admin/appearance.php');
    request($boss, 'admin/appearance.php', ['csrf' => csrfOf($ap['body']), 'action' => 'reset']);
    $page = request($boss, 'admin/products.php');
    ok(!str_contains($page['body'], 'id="ui-theme"') && str_contains($page['body'], 'content="#9c6b2e"'), 'Reset brings back default colours');

    $warnings = preg_grep('/PHP (Warning|Fatal|Parse|Deprecated)/', file("$temp/server.log") ?: []);
    ok($warnings === [], 'No PHP warnings' . ($warnings ? ': ' . implode(' | ', array_map('trim', $warnings)) : ''));
    echo "PASS: {$checks} theme checks\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    exec('rm -rf ' . escapeshellarg($temp));
}
