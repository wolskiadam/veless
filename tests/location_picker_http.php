<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Wybór lokalizacji z wyszukiwarką (public/admin/_loc_picker.php + assets/loc-picker.js) przez HTTP:
// prawdziwe products.php i product_view.php, baza SQLite, 300 półek. Zapis wiersza, akcja zbiorcza, filtr.
// Gdy jest Playwright (NODE_PATH), dodatkowo wyszukuje i wybiera w przeglądarce - tests/location_picker_ui.js.
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

$temp = sys_get_temp_dir() . '/crm-locpick-http-' . bin2hex(random_bytes(6));
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
    $wl->assign(1, $loc('R2-B'));

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes);
    fclose($pipes[0]);
    for ($i = 0; $i < 100; ++$i) { $p = @stream_socket_client('tcp://' . $address, $e1, $e2, 0.1); if ($p) { fclose($p); break; } usleep(10000); }

    $boss = login('boss');
    $page = request($boss, 'admin/products.php');
    ok($page['status'] === 200, 'Product list opens');
    ok(substr_count($page['body'], '<option') < 50, 'Location list is not repeated as <option>s in every row');
    ok(substr_count($page['body'], 'window.CRM_LOCATIONS') === 1 && str_contains($page['body'], '"code":"R7-K"') && str_contains($page['body'], 'Świece zapachowe 1 l'),
        'All 300 locations sent once, with descriptions');
    ok(preg_match('/<input type="hidden" name="loc\[1\]" value="' . $loc('R2-B') . '" form="saveAllForm">.*?R2-B/s', $page['body']) === 1, 'Row shows the current shelf');
    ok(preg_match('/name="location_id" value="" form="bulkLocForm" id="bulkLocValue">.*?wybierz/s', $page['body']) === 1, 'Bulk action starts with nothing chosen');
    $csrf = csrfOf($page['body']);

    // Zapis wiersza: te same pola co dawny <select> (loc[ID] + loc_old[ID]).
    $r = request($boss, 'admin/products.php', ['csrf' => $csrf, 'action' => 'save_all', 'loc' => [1 => $loc('R2-B'), 2 => $loc('R7-K')], 'loc_old' => [1 => $loc('R2-B'), 2 => '']]);
    ok(in_array($r['status'], [200, 302, 303], true) && ($wl->forProducts([2])[2]['code'] ?? '') === 'R7-K', 'Row picker value saves the location');
    $r = request($boss, 'admin/products.php', ['csrf' => $csrf, 'action' => 'bulk_location', 'location_id' => $loc('R12-Y'), 'ids' => [1, 3]]);
    ok(array_column($wl->forProducts([1, 3]), 'code') === ['R12-Y', 'R12-Y'], 'Bulk action sets the chosen location');
    $page = request($boss, 'admin/products.php?loc=' . $loc('R12-Y'));
    ok(str_contains($page['body'], 'GB-1L') && !str_contains($page['body'], 'MW-1L') && preg_match('/name="loc" value="' . $loc('R12-Y') . '" id="loc">.*?R12-Y/s', $page['body']) === 1,
        'Filter by location keeps the choice');

    $page = request($boss, 'admin/product_view.php?id=2');
    ok($page['status'] === 200 && preg_match('/name="location_id" value="' . $loc('R7-K') . '">.*?R7-K · Regał 7, półka K/s', $page['body']) === 1, 'Product page shows code and place');
    request($boss, 'admin/product_view.php?id=2', ['csrf' => csrfOf($page['body']), 'action' => 'save_location', 'location_id' => '']);
    ok($wl->forProducts([2]) === [], 'Product page can clear the location');

    // Niski stan: minimum zbiorczo z listy, własne minimum w podglądzie, próg domyślny i filtr.
    $page = request($boss, 'admin/products.php');
    $r = request($boss, 'admin/products.php', ['csrf' => csrfOf($page['body']), 'action' => 'bulk_min', 'min' => '2', 'ids' => [1, 3]]);
    ok(str_contains($r['body'], 'Minimalny stan 2 ustawiony dla 2 produktów') && $pdo->query('SELECT min_stock FROM products WHERE id = 3')->fetchColumn() == 2, 'Bulk minimum saved');
    $page = request($boss, 'admin/products.php?stock_op=low');
    ok(str_contains($page['body'], 'MW-1L') && !str_contains($page['body'], 'GB-1L') && str_contains($page['body'], 'stock-chip on') && str_contains($page['body'], 'Próg domyślny: 5'),
        'Filter "Niski stan" uses own minimum (2) and the default (5)');
    $page = request($boss, 'admin/product_view.php?id=2');
    $r = request($boss, 'admin/product_view.php?id=2', ['csrf' => csrfOf($page['body']), 'action' => 'save_min_stock', 'min_stock' => '1']);
    ok(str_contains($r['body'], 'Zapisano minimalny stan: 1') && str_contains($r['body'], 'name="min_stock" type="number" min="0" value="1"'), 'Product page saves its minimum');
    $r = request($boss, 'admin/products.php', ['csrf' => csrfOf($r['body']), 'action' => 'low_default', 'low_default' => '']);
    ok(str_contains($r['body'], 'Próg domyślny: wyłączony') && !str_contains(request($boss, 'admin/products.php?stock_op=low')['body'], 'MW-1L'), 'Default threshold can be switched off');
    $pdo->exec('UPDATE products SET pase_stock = 0 WHERE id = 3');
    $page = request($boss, 'admin/products.php?stock_op=low');
    $r = request($boss, 'admin/products.php?stock_op=low', ['csrf' => csrfOf($page['body']), 'action' => 'bulk_min', 'min_mode' => 'off', 'min' => '', 'ids' => [2]]);
    ok(str_contains($r['body'], 'Nie pilnuj produktów ze stanem 0') && $pdo->query('SELECT min_stock FROM products WHERE id = 2')->fetchColumn() == -1, 'Bulk "nie pilnuj stanu"');
    $pdo->exec('UPDATE products SET min_stock = NULL WHERE id = 3');
    $r = request($boss, 'admin/products.php?stock_op=low', ['csrf' => csrfOf($r['body']), 'action' => 'untrack_zero']);
    ok(str_contains($r['body'], 'Nie pilnujemy już stanu 1 produktów') && str_contains(request($boss, 'admin/products.php?stock_op=untracked')['body'], 'nie pilnowany'), 'Sold-out products untracked in one click');
    $page = request($boss, 'admin/product_view.php?id=3');
    ok(str_contains($page['body'], 'name="untracked" value="1" checked'), 'Product page shows "nie pilnuj stanu"');
    $pdo->exec('UPDATE products SET min_stock = NULL, pase_stock = 5');

    // Przeglądarka: wyszukiwanie i wybór klawiaturą/myszą (gdy jest Playwright).
    $node = trim((string) shell_exec('command -v node'));
    $hasPw = $node !== '' && trim((string) shell_exec('NODE_PATH=' . escapeshellarg((string) getenv('NODE_PATH')) . ' ' . escapeshellarg($node)
        . ' -e "require.resolve(\'playwright\'); console.log(1)" 2>/dev/null')) === '1';
    if ($hasPw) {
        passthru('BASE=' . escapeshellarg($base) . ' NODE_PATH=' . escapeshellarg((string) getenv('NODE_PATH')) . ' ' . escapeshellarg($node) . ' ' . escapeshellarg("$root/tests/location_picker_ui.js"), $rc);
        if ($rc !== 0) { echo implode('', preg_grep('/PHP |\[5\d\d\]/', file("$temp/server.log") ?: [])); }
        ok($rc === 0, 'Browser checks passed');
        ok($wl->forProducts([3])[3]['code'] === 'R7-K', 'Location picked by search in the browser was saved');
    } else {
        echo "SKIP: browser checks (no Playwright; set NODE_PATH)\n";
    }
    $warnings = preg_grep('/PHP (Warning|Fatal|Parse|Deprecated).*(products|product_view|_loc_picker|LowStock)\.php/', file("$temp/server.log") ?: []);
    ok($warnings === [], 'No PHP warnings on these pages' . ($warnings ? ': ' . implode(' | ', array_map('trim', $warnings)) : ''));
    echo "PASS: {$checks} location picker checks\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    exec('rm -rf ' . escapeshellarg($temp));
}
