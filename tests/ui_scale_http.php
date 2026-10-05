<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Skala interfejsu panelu (Aa w pasku nawigacji): zapis per konto (user_layouts), zoom w <head> na każdej stronie.
// Prawdziwe products.php i ui_scale_save.php na SQLite; gdy jest Playwright - klikanie w przeglądarce (tests/ui_scale_ui.js).
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Pase\\')) { require $root . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Support\PagePermissions;

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

$temp = sys_get_temp_dir() . '/crm-uiscale-http-' . bin2hex(random_bytes(6));
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

    ok(\Pase\Support\UiScale::normalize('83') === 85 && \Pase\Support\UiScale::normalize(10) === 60 && \Pase\Support\UiScale::normalize(999) === 130
        && \Pase\Support\UiScale::normalize('abc') === 100, 'Scale is rounded to 5% and kept within 60-130%');

    $boss = login('boss');
    $page = request($boss, 'admin/products.php');
    ok($page['status'] === 200 && str_contains($page['body'], '--ui-zoom:1;') && str_contains($page['body'], 'html { zoom:var(--ui-zoom); }'), 'Default scale is 100%');
    ok(str_contains($page['body'], 'id="navScale"') && str_contains($page['body'], '<span class="nav-scale-val">100%</span>'), 'Scale switch is in the navigation bar');
    $csrf = csrfOf($page['body']);

    $r = request($boss, 'admin/ui_scale_save.php', ['csrf' => 'bad', 'scale' => '80']);
    ok($r['status'] === 419 && $pdo->query('SELECT COUNT(*) FROM user_layouts')->fetchColumn() == 0, 'Save without CSRF token is refused');
    $r = request($boss, 'admin/ui_scale_save.php', ['csrf' => $csrf, 'scale' => '80']);
    ok($r['status'] === 200 && ($r['json']['scale'] ?? null) === 80, 'Scale 80% saved');
    $r = request($boss, 'admin/ui_scale_save.php', ['csrf' => $csrf, 'scale' => '80']);
    ok($pdo->query("SELECT COUNT(*) FROM user_layouts WHERE user_id = 1 AND view_key = 'ui_scale'")->fetchColumn() == 1, 'Saving again keeps one row');
    $page = request($boss, 'admin/products.php');
    ok(str_contains($page['body'], '--ui-zoom:0.8;') && str_contains($page['body'], '<span class="nav-scale-val">80%</span>'), 'Page opens at 80%');

    // Nowa sesja (inna przeglądarka) - skala z konta, nie z ciasteczka.
    $again = login('boss');
    ok(str_contains(request($again, 'admin/products.php')['body'], '--ui-zoom:0.8;'), 'Scale follows the account to a new session');
    $ola = login('ola');
    ok(str_contains(request($ola, 'admin/products.php')['body'], '--ui-zoom:1;'), 'Other users keep their own scale');
    $vi = login('vi');
    $vp = request($vi, 'admin/products.php');
    $r = request($vi, 'admin/ui_scale_save.php', ['csrf' => preg_match('/csrf = "([a-f0-9]+)"/', $vp['body'], $m) ? $m[1] : '', 'scale' => '115']);
    ok(($r['json']['scale'] ?? null) === 115 && str_contains(request($vi, 'admin/products.php')['body'], '--ui-zoom:1.15;'), 'Read-only account can set its own scale');
    request($boss, 'admin/ui_scale_save.php', ['csrf' => $csrf, 'scale' => '100']);

    $node = trim((string) shell_exec('command -v node'));
    $hasPw = $node !== '' && trim((string) shell_exec('NODE_PATH=' . escapeshellarg((string) getenv('NODE_PATH')) . ' ' . escapeshellarg($node)
        . ' -e "require.resolve(\'playwright\'); console.log(1)" 2>/dev/null')) === '1';
    if ($hasPw) {
        passthru('BASE=' . escapeshellarg($base) . ' NODE_PATH=' . escapeshellarg((string) getenv('NODE_PATH')) . ' ' . escapeshellarg($node) . ' ' . escapeshellarg("$root/tests/ui_scale_ui.js"), $rc);
        if ($rc !== 0) { echo implode('', preg_grep('/PHP |\[5\d\d\]/', file("$temp/server.log") ?: [])); }
        ok($rc === 0, 'Browser checks passed');
    } else {
        echo "SKIP: browser checks (no Playwright; set NODE_PATH)\n";
    }
    $warnings = preg_grep('/PHP (Warning|Fatal|Parse|Deprecated)/', file("$temp/server.log") ?: []);
    ok($warnings === [], 'No PHP warnings' . ($warnings ? ': ' . implode(' | ', array_map('trim', $warnings)) : ''));
    echo "PASS: {$checks} UI scale checks\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    exec('rm -rf ' . escapeshellarg($temp));
}
