<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Marża przez HTTP: koszty zakupu (strona Koszty zakupu, lista produktów, produkt), import CSV i sekcja marży
// w Statystykach. Prawdziwe strony panelu, baza SQLite. Magazynier (viewer) nie widzi kosztów ani marży.
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

$temp = sys_get_temp_dir() . '/crm-margins-http-' . bin2hex(random_bytes(6));
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
    $pdo->exec("UPDATE products SET woo_product_id = id * 100");
    $pdo->exec("CREATE TABLE woo_orders (id INTEGER PRIMARY KEY, woo_order_id INTEGER UNIQUE, integration_id INTEGER, pase_status TEXT, status TEXT, currency TEXT, total REAL,
        date_created TEXT, lifecycle TEXT DEFAULT 'active', payload TEXT, local_items TEXT NULL, local_edited_at TEXT NULL)");
    $pdo->exec("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES (1, 'woocommerce', 'Mój Sklep', 1, '{}'), (3, 'allegro', 'Allegro', 1, '{}')");
    \Pase\Services\OrderMargins::migrate($pdo);
    $today = date('Y-m-d');
    $oins = $pdo->prepare('INSERT INTO woo_orders (woo_order_id, integration_id, pase_status, currency, total, date_created, payload) VALUES (?,?,?,?,?,?,?)');
    $oins->execute([1, 1, 'new', 'PLN', 123, "$today 10:00:00", json_encode(['line_items' => [
        ['sku' => 'GB-1L', 'name' => 'GINGERBREAD 1l', 'quantity' => 2, 'total' => '81.30', 'total_tax' => '18.70'],
        ['sku' => 'MW-1L', 'name' => 'MULLED WINE 1l', 'quantity' => 1, 'total' => '40.65', 'total_tax' => '9.35']]])]);
    $oins->execute([9000000001, 3, 'new', 'PLN', 49.9, "$today 11:00:00", json_encode(['id' => 'cf-1', 'lineItems' => [['id' => 'x']], 'line_items' => [
        ['product_id' => 1, 'sku' => 'GB-1L', 'name' => 'GINGERBREAD 1l', 'quantity' => 1, 'total' => '49.90']]])]);
    (new \Pase\Services\OrderMargins($pdo))->saveAllegroFees([['id' => 'e1', 'order' => ['id' => 'cf-1'], 'type' => ['id' => 'SUC', 'name' => 'Prowizja od sprzedaży'],
        'value' => ['amount' => '-6.15', 'currency' => 'PLN']]]);

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes);
    fclose($pipes[0]);
    for ($i = 0; $i < 100; ++$i) { $p = @stream_socket_client('tcp://' . $address, $e1, $e2, 0.1); if ($p) { fclose($p); break; } usleep(10000); }

    $boss = login('boss');
    $page = request($boss, 'admin/product_costs.php');
    ok($page['status'] === 200 && str_contains($page['body'], '0 z 3 produktów ma koszt zakupu'), 'Purchase costs page opens with coverage');
    ok(str_contains($page['body'], 'Sprzedane bez kosztu') && str_contains($page['body'], 'GB-1L'), 'Sold products without cost listed');
    $r = request($boss, 'admin/product_costs.php', ['csrf' => csrfOf($page['body']), 'action' => 'save', 'cost' => [1 => '20,00', 2 => '', 3 => ''], 'old' => [1 => '', 2 => '', 3 => '']]);
    ok(in_array($r['status'], [302, 303], true) && (float) $pdo->query('SELECT purchase_cost FROM products WHERE id = 1')->fetchColumn() === 20.0, 'Cost saved from the costs table');
    $r = request($boss, 'admin/product_costs.php', ['csrf' => csrfOf($page['body']), 'action' => 'import', 'csv' => "SKU;koszt\nMW-1L;15,5\nNIEMA;3"]);
    $page = request($boss, 'admin/product_costs.php');
    ok((float) $pdo->query('SELECT purchase_cost FROM products WHERE id = 2')->fetchColumn() === 15.5 && str_contains($page['body'], 'Nie znaleziono SKU: NIEMA'), 'CSV import saves and reports unknown SKU');
    $csv = request($boss, 'admin/product_costs.php?export=missing');
    ok(str_contains($csv['headers'], 'text/csv') && str_contains($csv['body'], 'CC-1L') && !str_contains($csv['body'], 'GB-1L'), 'CSV export of products without cost');

    $page = request($boss, 'admin/products.php');
    ok($page['status'] === 200 && !str_contains($page['body'], 'name="cost[') && !str_contains($page['body'], '>Koszt zakupu</th>'), 'Product list has no purchase cost column (only the product page)');
    $page = request($boss, 'admin/product_view.php?id=3');
    request($boss, 'admin/product_view.php?id=3', ['csrf' => csrfOf($page['body']), 'action' => 'save_cost', 'purchase_cost' => '8,25']);
    ok((float) $pdo->query('SELECT purchase_cost FROM products WHERE id = 3')->fetchColumn() === 8.25, 'Cost saved on the product page');

    $page = request($boss, 'admin/statistics.php');
    ok($page['status'] === 200 && str_contains($page['body'], 'Marża <span>') && str_contains($page['body'], 'Po opłatach Allegro'), 'Statistics show the margin section');
    // Sprzedaż netto 81.30 + 40.65 + 40.57 = 162.52; koszt 2*20 + 15.5 + 20 = 75.50; opłata 5.00 netto.
    ok(str_contains($page['body'], '162,52 zł') && str_contains($page['body'], '75,50 zł') && str_contains($page['body'], '87,02 zł') && str_contains($page['body'], '82,02 zł'),
        'Statistics margin amounts');

    $vi = login('vi');
    $page = request($vi, 'admin/statistics.php');
    ok($page['status'] === 200 && !str_contains($page['body'], 'Po opłatach Allegro'), 'Viewer sees statistics without margin');
    $page = request($vi, 'admin/products.php');
    ok($page['status'] === 200 && !str_contains($page['body'], 'Koszt zakupu'), 'Viewer does not see purchase costs on the product list');
    ok(request($vi, 'admin/product_costs.php')['status'] === 403, 'Viewer cannot open purchase costs');

    $warnings = preg_grep('/PHP (Warning|Fatal|Parse|Deprecated|Notice)/', file("$temp/server.log") ?: []);
    ok($warnings === [], 'No PHP warnings' . ($warnings ? ': ' . implode(' | ', array_map('trim', $warnings)) : ''));
    echo "PASS: {$checks} margin HTTP checks\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    exec('rm -rf ' . escapeshellarg($temp));
}
