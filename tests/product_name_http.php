<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Zmiana nazwy produktu w karcie produktu przez HTTP (baza SQLite, udawany sklep WooCommerce):
// produkt bez sklepu - tylko CRM; produkt ze sklepu - PUT name do Woo; odmowa sklepu - nazwa bez zmian;
// wariant - tylko CRM z ostrzeżeniem; przeglądający nie widzi formularza i nie może zapisać.
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

$temp = sys_get_temp_dir() . '/crm-product-name-http-' . bin2hex(random_bytes(6));
foreach (['public/admin/assets', 'public/pack/assets', 'config', 'storage/security', 'lang', 'woo', 'integrations'] as $d) { mkdir("$temp/$d", 0700, true); }
$process = $shop = null;
try {
    file_put_contents("$temp/.env", "TOTP_REQUIRE_ADMIN=0\n");
    foreach (glob("$root/public/admin/*.php") as $f) { if (!in_array(basename($f), ['bootstrap_admin.php', 'db_admin.php'], true)) { copy($f, "$temp/public/admin/" . basename($f)); } }
    copy("$root/public/admin/assets/loc-picker.js", "$temp/public/admin/assets/loc-picker.js");
    foreach (glob("$root/lang/*.php") as $f) { copy($f, "$temp/lang/" . basename($f)); }
    exec('cp -r ' . escapeshellarg("$root/integrations/woocommerce") . ' ' . escapeshellarg("$temp/integrations/"));
    file_put_contents("$temp/public/admin/bootstrap_admin.php", '<?php \Pase\Support\PagePermissions::migrate($pdo);');

    $boot = '<?php if (!defined("PASE_ROOT")) { define("PASE_ROOT", ' . var_export($temp, true) . ');'
        . 'spl_autoload_register(static function($c) { if (str_starts_with($c, "Pase\\\\")) require ' . var_export("$root/src/", true)
        . ' . str_replace("\\\\", "/", substr($c, 5)) . ".php"; });'
        . 'spl_autoload_register(static function($c) { if (str_starts_with($c, "PasePlugin\\\\Woocommerce\\\\")) require PASE_ROOT . "/integrations/woocommerce/" . substr($c, 23) . ".php"; });'
        . '\Pase\Support\Env::load(PASE_ROOT . "/.env");'
        . 'if (!function_exists("t")) { function t($k, $v = []) { return \Pase\Support\I18n::t($k, $v); } } }'
        . 'return ["db" => [], "allegro" => []];';
    file_put_contents("$temp/config/config.php", $boot);
    file_put_contents("$temp/config/database.php", '<?php return static fn($db) => new PDO("sqlite:" . PASE_ROOT . "/test.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);');
    file_put_contents("$temp/public/admin/db_admin.php", '<?php $config = require dirname(__DIR__, 2) . "/config/config.php"; $pdo = (require PASE_ROOT . "/config/database.php")($config["db"]);');

    // Udawany sklep: zapisuje każde żądanie, produkt 300 odrzuca (np. brak uprawnień klucza).
    file_put_contents("$temp/woo/index.php", '<?php file_put_contents(__DIR__ . "/requests.log", $_SERVER["REQUEST_METHOD"] . " " . parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) . " " . file_get_contents("php://input") . "\n", FILE_APPEND);'
        . 'header("Content-Type: application/json");'
        . 'if (str_contains($_SERVER["REQUEST_URI"], "/products/300")) { http_response_code(401); echo json_encode(["message" => "Brak uprawnień"]); return; }'
        . 'echo json_encode(["id" => 1]);');
    $free = static function (): string { $s = stream_socket_server('tcp://127.0.0.1:0'); $a = stream_socket_get_name($s, false); fclose($s); return $a; };
    $shopAddr = $free();

    $pdo = new PDO("sqlite:$temp/test.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT, role TEXT, password_hash TEXT, is_active INTEGER, session_version INTEGER, totp_secret TEXT, totp_last_step INTEGER DEFAULT -1, totp_recovery_hashes TEXT, last_login TEXT, totp_required INTEGER NOT NULL DEFAULT 0, email TEXT)');
    $ins = $pdo->prepare('INSERT INTO admin_users (id,username,display_name,role,password_hash,is_active,session_version) VALUES (?,?,?,?,?,1,1)');
    foreach ([1 => ['boss', 'Adam', 'admin'], 3 => ['vi', 'Wiktor', 'viewer']] as $id => [$u, $n, $role]) {
        $ins->execute([$id, $u, $n, $role, password_hash('Only-Test-Password-42', PASSWORD_DEFAULT)]);
    }
    PagePermissions::migrate($pdo);
    \Pase\Services\AuditTrail::migrate($pdo);
    $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
    $pdo->exec('CREATE TABLE order_statuses (id INTEGER PRIMARY KEY, status_key TEXT, label TEXT, color TEXT, position INTEGER)');
    $pdo->exec("INSERT INTO order_statuses (status_key,label,color,position) VALUES ('new','Nowe','#8a8f98',1)");
    $pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT, is_active INTEGER, config TEXT)');
    $pdo->exec('CREATE TABLE product_mappings (id INTEGER PRIMARY KEY, sku TEXT, allegro_offer_id TEXT)');
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, pase_stock INTEGER, pase_price REAL, source_integration_id INTEGER, woo_product_id INTEGER,
        woo_variation_id INTEGER, payload TEXT, imported_at TEXT, updated_at TEXT, description TEXT, short_description TEXT, images TEXT, categories TEXT, attributes TEXT,
        ean TEXT, weight REAL, length REAL, width REAL, height REAL, custom_fields TEXT, allegro_sections TEXT)');
    $pins = $pdo->prepare('INSERT INTO products (id, sku, name, pase_stock, source_integration_id, woo_product_id, woo_variation_id) VALUES (?,?,?,?,?,?,?)');
    $pins->execute([1, 'LOCAL-1', 'Świeca lokalna', 5, null, null, null]);
    $pins->execute([2, 'moj-sklep-1', 'Dostęp Miesięczny', 9999, 1, 200, null]);
    $pins->execute([3, 'ODMOWA-1', 'Stara nazwa', 1, 1, 300, null]);
    $pins->execute([4, 'GB-1L-BRAZ', 'Gingerbread', 3, 1, 400, 401]);
    $pdo->prepare("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES (1, 'woocommerce', 'Mój Sklep', 1, ?)")
        ->execute([json_encode(['base_url' => "http://$shopAddr", 'consumer_key' => 'ck', 'consumer_secret' => 'cs'])]);
    $pdo->exec("CREATE TABLE woo_orders (id INTEGER PRIMARY KEY, woo_order_id INTEGER UNIQUE, integration_id INTEGER, pase_status TEXT, status TEXT, currency TEXT, total REAL,
        date_created TEXT, lifecycle TEXT DEFAULT 'active', payload TEXT, local_items TEXT NULL, local_edited_at TEXT NULL)");
    \Pase\Services\OrderMargins::migrate($pdo);

    $address = $free();
    $base = 'http://' . $address . '/';
    $env = ['no_proxy' => '*', 'NO_PROXY' => '*', 'PATH' => getenv('PATH')];
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes, null, $env);
    fclose($pipes[0]);
    $shop = proc_open([PHP_BINARY, '-S', $shopAddr, "$temp/woo/index.php"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/shop.log", 'a'], 2 => ['file', "$temp/shop.log", 'a']], $sp, null, $env);
    fclose($sp[0]);
    foreach ([$address, $shopAddr] as $a) {
        for ($i = 0; $i < 100; ++$i) { $p = @stream_socket_client('tcp://' . $a, $e1, $e2, 0.1); if ($p) { fclose($p); break; } usleep(10000); }
    }
    $nameOf = static fn(int $id): string => (string) $pdo->query("SELECT name FROM products WHERE id = $id")->fetchColumn();
    $shopLog = static fn(): string => (string) @file_get_contents("$temp/woo/requests.log");
    $save = static function (CurlHandle $h, int $id, string $name): string {
        $page = request($h, "admin/product_view.php?id=$id");
        return request($h, "admin/product_view.php?id=$id", ['csrf' => csrfOf($page['body']), 'action' => 'save_name', 'name' => $name])['body'];
    };

    $boss = login('boss');
    $page = request($boss, 'admin/product_view.php?id=2');
    ok($page['status'] === 200 && str_contains($page['body'], 'name="action" value="save_name"') && str_contains($page['body'], 'value="Dostęp Miesięczny"'), 'Name is an editable field');
    ok(str_contains($page['body'], 'nowa nazwa trafi też do WooCommerce'), 'Shop product explains the name goes to the shop');

    $body = $save($boss, 1, '  Świeca   sojowa  ');
    ok(str_contains($body, 'Zapisano nazwę produktu.') && $nameOf(1) === 'Świeca sojowa' && $shopLog() === '', 'Product without shop: saved in CRM only, spaces tidied');

    $body = $save($boss, 2, 'Dostęp Miesięczny — Mój Sklep');
    ok(str_contains($body, 'Zapisano nazwę w CRM i w sklepie „Mój Sklep”') && $nameOf(2) === 'Dostęp Miesięczny — Mój Sklep', 'Shop product: saved in CRM');
    ok(str_contains($shopLog(), 'PUT /wp-json/wc/v3/products/200 {"name":"Dost\\u0119p Miesi\\u0119czny \\u2014 Mój Sklep"}')
        || str_contains($shopLog(), 'PUT /wp-json/wc/v3/products/200 {"name":"Dostęp Miesięczny — Mój Sklep"}'), 'Shop product: name sent to WooCommerce');

    $body = $save($boss, 3, 'Nowa nazwa');
    ok(str_contains($body, 'odrzucił zmianę (HTTP 401: Brak uprawnień)') && $nameOf(3) === 'Stara nazwa', 'Shop refuses: name unchanged, reason shown');

    $before = $shopLog();
    $body = $save($boss, 4, 'Gingerbread brązowy');
    ok(str_contains($body, 'To wariant produktu ze sklepu') && $nameOf(4) === 'Gingerbread brązowy' && $shopLog() === $before, 'Variant: CRM only with warning, nothing sent');

    $body = $save($boss, 1, '   ');
    ok(str_contains($body, 'Nazwa produktu: od 1 do 255 znaków.') && $nameOf(1) === 'Świeca sojowa', 'Empty name refused');

    $vi = login('vi');
    $page = request($vi, 'admin/product_view.php?id=1');
    ok($page['status'] === 200 && !str_contains($page['body'], 'value="save_name"') && str_contains($page['body'], 'Świeca sojowa'), 'Viewer sees name without the form');
    request($vi, 'admin/product_view.php?id=1', ['csrf' => csrfOf($page['body']), 'action' => 'save_name', 'name' => 'Hack']);
    ok($nameOf(1) === 'Świeca sojowa', 'Viewer cannot rename');

    $warnings = preg_grep('/PHP (Warning|Fatal|Parse|Deprecated|Notice)/', file("$temp/server.log") ?: []);
    ok($warnings === [], 'No PHP warnings' . ($warnings ? ': ' . implode(' | ', array_map('trim', $warnings)) : ''));
    echo "PASS: {$checks} product name HTTP checks\n";
} finally {
    foreach ([$process, $shop] as $pr) { if (is_resource($pr)) { proc_terminate($pr); proc_close($pr); } }
    exec('rm -rf ' . escapeshellarg($temp));
}
