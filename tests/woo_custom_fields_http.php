<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Własne pola ze sklepu przez HTTP: Pola produktów pokazuje pola znalezione w WooCommerce, „Dodaj jako własne pole”
// uzupełnia produkty, a zakładka Własne pola produktu pokazuje wartość ze sklepu tylko do odczytu. Baza SQLite.
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

$temp = sys_get_temp_dir() . '/crm-woo-fields-http-' . bin2hex(random_bytes(6));
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
    $pins->execute([1, 'GB-1L', 'GINGERBREAD 1l', 5, 49.9]);
    $pdo->exec("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES (1, 'woocommerce', 'Mój Sklep', 1, '{}')");
    $pdo->exec("UPDATE products SET woo_product_id = id * 100");
    $pdo->exec("CREATE TABLE woo_orders (id INTEGER PRIMARY KEY, woo_order_id INTEGER UNIQUE, integration_id INTEGER, pase_status TEXT, status TEXT, currency TEXT, total REAL,
        date_created TEXT, lifecycle TEXT DEFAULT 'active', payload TEXT, local_items TEXT NULL, local_edited_at TEXT NULL)");
    \Pase\Services\OrderMargins::migrate($pdo);
    $pdo->exec('CREATE TABLE product_fields (id INTEGER PRIMARY KEY AUTOINCREMENT, field_key TEXT UNIQUE, label TEXT, type TEXT DEFAULT "text",
        options TEXT, categories TEXT, position INT DEFAULT 100, created_at TEXT)');
    $pdo->exec("INSERT INTO product_fields (field_key, label, type, options, categories, position) VALUES ('kolor', 'Kolor', 'text', '[]', '[]', 1)");
    $pdo->prepare("UPDATE products SET payload = ?, custom_fields = ? WHERE id = 1")->execute([json_encode(['meta_data' => [
        ['key' => 'sklad', 'value' => "Wosk sojowy\nKnot <b>bawełniany</b>"], ['key' => '_sklad', 'value' => 'field_abc123'],
        ['key' => '_yoast_wpseo_focuskw', 'value' => 'świeca'], ['key' => '_obok_opisu_img', 'value' => '77'], ['key' => '_yoast_wpseo_seo_title_score', 'value' => '61']]]), json_encode(['kolor' => 'brąz'])]);
    $pdo->exec('UPDATE products SET source_integration_id = 1 WHERE id = 1');
    \Pase\Services\WooCustomFields::migrate($pdo);
    $pdo->exec("INSERT INTO woo_media (integration_id, media_id, url) VALUES (1, 77, 'https://sklep.test/wp-content/uploads/jablko.jpg')");

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes);
    fclose($pipes[0]);
    for ($i = 0; $i < 100; ++$i) { $p = @stream_socket_client('tcp://' . $address, $e1, $e2, 0.1); if ($p) { fclose($p); break; } usleep(10000); }

    $boss = login('boss');
    $page = request($boss, 'admin/product_fields.php');
    ok($page['status'] === 200 && str_contains($page['body'], 'Pola znalezione w sklepie WooCommerce') && str_contains($page['body'], '<code>sklad</code>'), 'Field list shows fields found in the shop');
    ok(!str_contains($page['body'], 'field_abc123') && str_contains($page['body'], 'Pola techniczne wtyczek'), 'ACF reference hidden, plugin keys collapsed');
    $page = request($boss, 'admin/product_fields.php', ['csrf' => csrfOf($page['body']), 'action' => 'add_from_woo', 'woo_meta_key' => 'sklad']);
    ok(str_contains($page['body'], 'Wypełnione w 1 produktach') && str_contains($page['body'], '✓ pole'), 'Add as own field fills the product');
    $page = request($boss, 'admin/product_view.php?id=1');
    ok($page['status'] === 200 && str_contains($page['body'], '🛒 ze sklepu') && str_contains($page['body'], 'Knot &lt;b&gt;bawełniany&lt;/b&gt;</textarea>'), 'Product page shows shop value read-only');
    request($boss, 'admin/product_view.php?id=1', ['csrf' => csrfOf($page['body']), 'action' => 'save_custom', 'cf' => ['kolor' => 'czarny', 'sklad' => 'podmiana']]);
    $cf = json_decode((string) $pdo->query('SELECT custom_fields FROM products WHERE id = 1')->fetchColumn(), true);
    ok($cf === ['kolor' => 'czarny', 'sklad' => "Wosk sojowy\nKnot <b>bawełniany</b>"], 'Saving the form changes manual fields only');
    ok(str_contains(request($boss, 'admin/product_view.php?id=1')['body'], 'Wszystkie dodatkowe pola z WooCommerce dla tego produktu (4)'), 'Product page lists all shop fields');
    $page = request($boss, 'admin/product_fields.php');
    ok(substr_count($page['body'], '+ Jako zdjęcie') === 1 && str_contains($page['body'], 'id="wooFilter"'), 'Image button only for the image-like field (not SEO score); search box');
    $page = request($boss, 'admin/product_fields.php', ['csrf' => csrfOf($page['body']), 'action' => 'add_from_woo', 'type' => 'image', 'woo_meta_key' => '_obok_opisu_img']);
    ok(str_contains($page['body'], 'Zdjęcie (link)'), 'Field added as image');
    $body = request($boss, 'admin/product_view.php?id=1')['body'];
    ok(str_contains($body, '<img src="https://sklep.test/wp-content/uploads/jablko.jpg"') && str_contains($body, 'Otwórz zdjęcie ↗'), 'Product page shows the image with its link');
    $page = request($boss, 'admin/product_fields.php?edit=2');
    $page = request($boss, 'admin/product_fields.php', ['csrf' => csrfOf($page['body']), 'action' => 'update', 'id' => 2, 'label' => 'Skład surowcowy', 'type' => 'text', 'position' => 5, 'woo_meta_key' => '']);
    ok(str_contains($page['body'], 'Zapisano pole.') && str_contains($page['body'], 'ręcznie'), 'Shop link can be removed (field becomes manual)');

    $warnings = preg_grep('/PHP (Warning|Fatal|Parse|Deprecated|Notice)/', file("$temp/server.log") ?: []);
    ok($warnings === [], 'No PHP warnings' . ($warnings ? ': ' . implode(' | ', array_map('trim', $warnings)) : ''));
    echo "PASS: {$checks} shop custom fields HTTP checks\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    exec('rm -rf ' . escapeshellarg($temp));
}
