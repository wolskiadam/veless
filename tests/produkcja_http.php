<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Rozszerzenie Produkcja przez HTTP: prawdziwe strony panelu z extensions/produkcja - materiały i dostawy, wyrób z recepturą
// i kosztem, partia z numerem (wzór z Rejestru Partii) zdejmująca materiały i dokładająca sztuki do produktu CRM, braki,
// kosz i przywracanie, wycena robocza, dekoder, inwentaryzacja, numer partii przy pakowaniu i na karcie partii, etykiety,
// test spalania ze zdjęciem, import CSV i kopii Rejestru Partii, uprawnienia ról. Baza SQLite.
// i uprawnienia ról (podgląd nie dodaje ani nie usuwa), odczyt zdjęcia przez udawany serwer OpenAI. Baza SQLite.
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
    if ($data !== null) {
        // Z plikiem (CURLFile) wysyłka multipart, jak formularz ze zdjęciem.
        $multipart = array_filter($data, static fn($v): bool => $v instanceof CURLFile) !== [];
        curl_setopt($h, CURLOPT_POSTFIELDS, $multipart ? $data : http_build_query($data));
    }
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

$temp = sys_get_temp_dir() . '/crm-produkcja-http-' . bin2hex(random_bytes(6));
foreach (['public/admin/assets', 'public/pack/assets', 'config', 'storage/security', 'lang', 'extensions'] as $d) { mkdir("$temp/$d", 0700, true); }
$process = null;
try {
    file_put_contents("$temp/.env", "TOTP_REQUIRE_ADMIN=0\nTOTP_KEY_FILE=$temp/storage/security/totp.key\n");
    file_put_contents("$temp/storage/security/totp.key", base64_encode(random_bytes(32)));
    foreach (glob("$root/public/admin/*.php") as $f) { if (!in_array(basename($f), ['bootstrap_admin.php', 'db_admin.php'], true)) { copy($f, "$temp/public/admin/" . basename($f)); } }
    copy("$root/public/admin/assets/loc-picker.js", "$temp/public/admin/assets/loc-picker.js");
    foreach (glob("$root/lang/*.php") as $f) { copy($f, "$temp/lang/" . basename($f)); }
    foreach (glob("$root/public/pack/*.php") as $f) { copy($f, "$temp/public/pack/" . basename($f)); }
    foreach (['packing.js', 'packing.css'] as $f) { copy("$root/public/pack/assets/$f", "$temp/public/pack/assets/$f"); }
    // Rozszerzenie zainstalowane jak przez FTP (extensions/produkcja).
    exec('cp -r ' . escapeshellarg("$root/extensions/produkcja") . ' ' . escapeshellarg("$temp/extensions/produkcja"));
    file_put_contents("$temp/public/admin/bootstrap_admin.php", '<?php \Pase\Support\PagePermissions::migrate($pdo);');

    // Konfiguracja jak config/config.php, ale z SQLite i kodem z repozytorium.
    $boot = '<?php if (!defined("PASE_ROOT")) { define("PASE_ROOT", ' . var_export($temp, true) . ');'
        . 'spl_autoload_register(static function($c) { if (str_starts_with($c, "Pase\\\\")) require ' . var_export("$root/src/", true)
        . ' . str_replace("\\\\", "/", substr($c, 5)) . ".php"; });'
        . '\Pase\Support\Env::load(PASE_ROOT . "/.env");'
        . 'if (!function_exists("t")) { function t($k, $v = []) { return \Pase\Support\I18n::t($k, $v); } } }'
        . 'return ["db" => [], "allegro" => []];';
    file_put_contents("$temp/config/config.php", $boot);
    file_put_contents("$temp/config/database.php", '<?php return static function ($db) { $pdo = new PDO("sqlite:" . PASE_ROOT . "/test.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); \\Pase\\Support\\Runtime::setPdo($pdo); return $pdo; };');
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
    $pdo->exec("INSERT INTO order_statuses (status_key,label,color,position) VALUES ('new','Nowe','#8a8f98',1),('processing','W realizacji','#c28a2b',2),('shipped','Wysłane','#2f8f5b',3)");
    $pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT, is_active INTEGER, config TEXT)');
    $pdo->exec("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES (1, 'woocommerce', 'Mój Sklep', 1, '{}')");
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, pase_stock INTEGER, pase_price REAL, images TEXT, ean TEXT, actual_stock INTEGER NULL, actual_stock_set_at TEXT NULL)');
    $pdo->exec("INSERT INTO products (id, sku, name, pase_stock, pase_price, actual_stock) VALUES (1, 'GB-1L', 'GINGERBREAD 1l', 5, 49.9, 3), (2, 'MW-1L', 'MULLED WINE 1l', 5, 39.9, NULL)");
    $pdo->exec("CREATE TABLE woo_orders (id INTEGER PRIMARY KEY, woo_order_id INTEGER UNIQUE, integration_id INTEGER, pase_number INTEGER, order_number TEXT, status TEXT,
        pase_status TEXT, currency TEXT, total REAL, customer_name TEXT, customer_email TEXT, date_created TEXT, imported_at TEXT, updated_at TEXT,
        lifecycle TEXT DEFAULT 'active', payload TEXT, local_items TEXT NULL, local_edited_at TEXT NULL, local_billing_email TEXT NULL,
        local_billing_phone TEXT NULL, local_invoice TEXT NULL, local_pickup_point TEXT NULL, forwarded_to TEXT NULL, client_token TEXT NULL,
        automations_done INTEGER DEFAULT 0, internal_status TEXT NULL)");
    $pdo->exec('CREATE TABLE shipments (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, courier_code TEXT, waybill_no TEXT, bl_order_id TEXT,
        order_ref TEXT, price REAL, status TEXT, label_link TEXT, request_payload TEXT, response_payload TEXT, created_at TEXT,
        tracking_status TEXT, tracking_code TEXT, tracking_carrier TEXT, tracking_at TEXT, tracking_checked_at TEXT, tracking_events TEXT)');
    $pdo->exec("CREATE TABLE order_messages (id INTEGER PRIMARY KEY, woo_order_id INTEGER, sender TEXT, author_name TEXT, body TEXT,
        attachment_path TEXT, attachment_name TEXT, is_read INTEGER DEFAULT 0, created_at TEXT)");
    $pdo->exec("CREATE TABLE order_documents (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, provider TEXT, document_type TEXT,
        remote_id TEXT, status TEXT DEFAULT 'issued', message TEXT, created_at TEXT, updated_at TEXT)");
    foreach ([\Pase\Services\OrderReturns::class, \Pase\Services\AllegroFeedback::class, \Pase\Services\Customers::class,
        \Pase\Services\DocumentIssueGuard::class, \Pase\Services\OrderMerge::class, \Pase\Services\OrderMargins::class,
        \Pase\Services\Packing::class] as $svc) { $svc::migrate($pdo); }
    $pdo->exec('CREATE TABLE product_mappings (id INTEGER PRIMARY KEY, sku TEXT, allegro_offer_id TEXT)');
    $pdo->exec('CREATE TABLE email_templates (id INTEGER PRIMARY KEY, tpl_key TEXT, name TEXT, subject TEXT, body TEXT, is_active INTEGER, mail_account_id INTEGER NULL)');
    $pdo->exec("CREATE TABLE automation_rules (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, event TEXT, is_active INTEGER, priority INTEGER,
        conditions TEXT, actions TEXT, action_type TEXT NULL, action_params TEXT NULL)");
    $order = static fn(string $street, array $items, float $total): string => json_encode([
        'status' => 'processing', 'date_paid' => '2026-09-20T10:00:00', 'total' => (string) $total,
        'billing' => ['first_name' => 'Anna', 'last_name' => 'Kowalska', 'email' => 'anna@example.com', 'address_1' => $street, 'postcode' => '00-001', 'city' => 'Warszawa'],
        'shipping' => ['first_name' => 'Anna', 'last_name' => 'Kowalska', 'address_1' => $street, 'postcode' => '00-001', 'city' => 'Warszawa'],
        'line_items' => $items, 'shipping_lines' => [['method_title' => 'Kurier', 'total' => '15.00']]]);
    $oins = $pdo->prepare('INSERT INTO woo_orders (woo_order_id, integration_id, pase_number, order_number, status, pase_status, currency, total, customer_name, customer_email, date_created, imported_at, payload)
        VALUES (?,1,?,?,?,?,?,?,?,?,?,?,?)');
    $oins->execute([501, 11, '501', 'processing', 'new', 'PLN', 114.8, 'Anna Kowalska', 'anna@example.com', '2026-09-20 10:00:00', '2026-09-20 10:01:00',
        $order('Lipowa 1', [['product_id' => 1, 'sku' => 'GB-1L', 'name' => 'GINGERBREAD 1l', 'quantity' => 2, 'price' => 49.9, 'total' => '99.80']], 114.8)]);
    $oins->execute([502, 12, '502', 'processing', 'new', 'PLN', 54.9, 'Anna Kowalska', 'anna@example.com', '2026-09-21 10:00:00', '2026-09-21 10:01:00',
        $order('Polna 2', [['product_id' => 2, 'sku' => 'MW-1L', 'name' => 'MULLED WINE 1l', 'quantity' => 1, 'price' => 39.9, 'total' => '39.90']], 54.9)]);

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes);
    fclose($pipes[0]);
    for ($i = 0; $i < 100; ++$i) { $p = @stream_socket_client('tcp://' . $address, $e1, $e2, 0.1); if ($p) { fclose($p); break; } usleep(10000); }

    $boss = login('boss');
    $ola = login('ola');
    $vi = login('vi');
    $url = static fn(string $page, array $q = []): string => 'admin/ext.php?' . http_build_query(['page' => 'produkcja.' . $page] + $q);
    $post = static function (CurlHandle $h, string $page, array $data, array $q = []) use ($url): array {
        $csrf = csrfOf(request($h, 'admin/ext.php?page=produkcja.material&nowy=1')['body']);
        return request($h, $url($page, $q), ['csrf' => $csrf] + $data);
    };
    $flash = static fn(CurlHandle $h): string => request($h, 'admin/ext.php?page=produkcja.pulpit')['body'];
    $locId = static fn(array $r): string => preg_match('/[?&]id=([a-f0-9]{16})/', urldecode((string) (preg_match('/Location: (\S+)/', $r['headers'], $m) ? $m[1] : '')), $mm) ? $mm[1] : '';
    $stock = static fn(string $name): float => (float) $pdo->query("SELECT COALESCE(SUM(d.remaining),0) FROM ext_prod_deliveries d JOIN ext_prod_materials m ON m.id = d.material_id
        WHERE m.name = " . $pdo->quote($name) . " AND d.deleted_at IS NULL")->fetchColumn();
    $actual = static fn(): int => (int) $pdo->query('SELECT actual_stock FROM products WHERE id = 1')->fetchColumn();

    // ---- Wtyczka, menu, pusty pulpit
    $pl = request($boss, 'admin/plugins.php');
    ok(str_contains($pl['body'], 'Produkcja') && str_contains($pl['body'], 'name="cfg[pattern]"') && str_contains($pl['body'], 'name="cfg[stock_target]"'), 'Plugins page lists the extension with its settings');
    $r = request($boss, 'admin/plugins.php', ['csrf' => csrfOf($pl['body']), 'action' => 'ext_settings', 'slug' => 'produkcja',
        'cfg' => ['pattern' => '{DC}{QTY}{MC}{YC}{WC}{SC}{WK}{PC}', 'stock_target' => 'actual', 'hourly_rate' => '30', 'fixed_monthly' => '600', 'label_w' => '50', 'label_h' => '30',
        'maker' => "Klub Woskarzy\nul. Testowa 1, Warszawa"]]);
    ok($r['status'] === 200 && str_contains($r['body'], 'Zapisano ustawienia'), 'Settings are saved');
    ok(str_contains($pl['body'], 'href="ext.php?page=produkcja.import"') && str_contains($pl['body'], 'Import i eksport danych')
        && !str_contains((string) $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'EXT_PRODUKCJA'")->fetchColumn(), '"import"'), 'Import is linked from the plugin settings (link field stores nothing)');
    $home = request($vi, 'admin/index.php')['body'];
    ok(preg_match('#Produkcja\s*<svg#', $home) === 1 && str_contains($home, 'href="ext.php?page=produkcja.pulpit"') && str_contains($home, 'href="ext.php?page=produkcja.testy"'), 'Own menu tab with the pages');
    $d = request($ola, $url('pulpit'));
    ok($d['status'] === 200 && str_contains($d['body'], 'Zacznij od tego, co masz na półce') && str_contains($d['body'], '+ Dodaj materiał')
        && !str_contains($d['body'], 'produkcja.import'), 'Empty dashboard shows the first steps (import only in settings)');
    ok(!str_contains(request($vi, $url('materialy'))['body'], '+ Dodaj materiał'), 'Viewer sees no add button');
    ok(request($vi, $url('material_zapisz'), ['csrf' => csrfOf($home), 'name' => 'X'])['status'] === 403, 'Viewer cannot save');

    // ---- Materiały ze stanem początkowym, dostawa
    $mat = static function (array $in) use ($post, $ola, $locId): string { return $locId($post($ola, 'material_zapisz', $in)); };
    $wax = $mat(['name' => 'Wosk sojowy Golden 464', 'category' => 'wosk', 'unit' => 'g', 'code' => '02', 'min_stock' => '1000', 'stock' => '5000', 'price_total' => '100']);
    $scent = $mat(['name' => 'Olejek Piernik', 'category' => 'zapach', 'unit' => 'g', 'code' => 'AB', 'stock' => '600', 'price_total' => '120']);
    $wick = $mat(['name' => 'Knot bawełniany ECO 6', 'category' => 'knot', 'unit' => 'szt', 'stock' => '100', 'price_total' => '50']);
    $jar = $mat(['name' => 'Słoik 180 ml', 'category' => 'pojemnik', 'unit' => 'szt', 'stock' => '20', 'price_total' => '60', 'fill_g' => '180']);
    ok(strlen($wax) === 16 && strlen($jar) === 16 && $stock('Wosk sojowy Golden 464') == 5000.0 && $stock('Słoik 180 ml') == 20.0, 'Materials are added with their starting stock');
    $r = $post($ola, 'material_zapisz', ['name' => 'wosk sojowy golden 464', 'category' => 'wosk']);
    ok($r['status'] === 200 && str_contains($r['body'], 'już jest na liście'), 'Duplicate material name is refused');
    $r = $post($ola, 'dostawa', ['material_id' => $wax, 'qty' => '2000', 'price_total' => '50', 'lot_no' => 'L-123', 'expires_at' => date('Y-m-d', strtotime('+10 days')), 'supplier' => 'Hurtownia']);
    ok($r['status'] === 302 && $stock('Wosk sojowy Golden 464') == 7000.0, 'Delivery adds stock');
    $card = request($vi, $url('material', ['id' => $wax]))['body'];
    ok(str_contains($card, 'L-123') && str_contains($card, '7') && str_contains($card, 'kg') && str_contains($card, 'Hurtownia') && !str_contains($card, 'Przyjmij dostawę'), 'Material card shows deliveries (viewer without the form)');
    $list = request($vi, $url('materialy'))['body'];
    ok(str_contains($list, 'Wosk sojowy Golden 464') && str_contains($list, '21,43') && str_contains($list, '/kg'), 'List shows the weighted price per kg');

    // ---- Wyrób z recepturą i kosztem
    $r = $post($ola, 'wyrob_zapisz', ['name' => 'Świeca Piernik 180', 'sku' => 'GB-1L', 'price' => '49,90', 'vat' => '23', 'shelf_life_months' => '24', 'min_stock' => '10',
        'recipe' => [['material_id' => $wax, 'qty' => '180'], ['material_id' => $scent, 'qty' => '14'], ['material_id' => $wick, 'qty' => '1'], ['material_id' => $jar, 'qty' => '1'], ['material_id' => '', 'qty' => '']]]);
    $prod = $locId($r);
    ok($r['status'] === 302 && strlen($prod) === 16 && (int) $pdo->query("SELECT COUNT(*) FROM ext_prod_recipe WHERE product_id = '$prod'")->fetchColumn() === 4, 'Product with a 4-line recipe');
    $w = request($vi, $url('wyrob', ['id' => $prod]))['body'];
    ok(str_contains($w, '10,16') && str_contains($w, 'GINGERBREAD 1l') && str_contains($w, '20 szt.') && str_contains($w, 'Próg opłacalności'), 'Product card: unit cost 10,16 zł, CRM product, can make 20, break-even');
    $r = $post($ola, 'wyrob_zapisz', ['name' => 'Inna świeca', 'sku' => 'GB-1L']);
    ok(str_contains($r['body'], 'ma już wyrób'), 'SKU used by another product is refused');

    // ---- Partia: numer, zdjęcie materiałów, dołożenie sztuk
    $code = json_decode(request($ola, $url('partia_kod', ['product_id' => $prod, 'produced_at' => '2026-11-04', 'qty' => 10]))['body'], true);
    ok(($code['code'] ?? '') === 'AD010LX02ABBA', 'Batch number preview follows the Rejestr Partii pattern: ' . ($code['code'] ?? ''));
    $r = $post($ola, 'partia_nowa', ['product_id' => $prod, 'qty' => '10', 'produced_at' => '2026-11-04', 'work_hours' => '2', 'save' => '1']);
    $b1 = $locId($r);
    $row = $pdo->query("SELECT * FROM ext_prod_batches WHERE id = '$b1'")->fetch(PDO::FETCH_ASSOC);
    ok($r['status'] === 302 && $row['code'] === 'AD010LX02ABBA' && $row['expires_at'] === '2028-11-04' && (float) $row['cost_labor'] === 60.0, 'Batch saved with code, best-before and labour cost');
    ok($stock('Wosk sojowy Golden 464') == 5200.0 && $stock('Olejek Piernik') == 460.0 && $stock('Słoik 180 ml') == 10.0 && $actual() === 13
        && (int) $pdo->query('SELECT pase_stock FROM products WHERE id = 1')->fetchColumn() === 5, 'Batch takes materials and adds 10 to the actual stock only');
    $fifo = $pdo->query("SELECT remaining FROM ext_prod_deliveries WHERE material_id = '$wax' ORDER BY created_at, rowid")->fetchAll(PDO::FETCH_COLUMN);
    ok((float) $fifo[0] == 3200.0 && (float) $fifo[1] == 2000.0, 'Wax taken from the oldest delivery first');
    $bc = request($vi, $url('partia', ['id' => $b1]))['body'];
    ok(str_contains($bc, 'AD010LX02ABBA') && str_contains($bc, 'Dołożono do produktu GB-1L') && str_contains($bc, 'Robocizna') && str_contains($bc, 'Etykiety'), 'Batch card shows what happened');

    $r = $post($ola, 'partia_nowa', ['product_id' => $prod, 'qty' => '15', 'produced_at' => '2026-11-04', 'save' => '1']);
    ok($r['status'] === 200 && str_contains($r['body'], 'Brakuje materiałów: Słoik 180 ml') && $stock('Słoik 180 ml') == 10.0, 'Batch larger than stock is refused');
    $r = $post($ola, 'partia_nowa', ['product_id' => $prod, 'qty' => '15', 'produced_at' => '2026-11-04', 'allow_short' => '1', 'save' => '1']);
    $b2 = $locId($r);
    ok($r['status'] === 302 && $stock('Słoik 180 ml') == 0.0 && $actual() === 28 && str_contains(request($vi, $url('partia', ['id' => $b2]))['body'], 'zabrakło'), 'Saving despite shortage takes what is there');
    $r = $post($ola, 'partia_usun', ['id' => $b2]);
    ok($stock('Słoik 180 ml') == 10.0 && $actual() === 13 && str_contains(request($vi, $url('partie', ['widok' => 'kosz']))['body'], 'AD015LX02ABBA'), 'Delete puts back materials and product stock, batch goes to the bin');
    $post($ola, 'partia_przywroc', ['id' => $b2]);
    ok($stock('Słoik 180 ml') == 0.0 && $actual() === 28, 'Restore from the bin takes them again');
    $post($ola, 'partia_usun', ['id' => $b2]);

    // ---- Edycja partii (ilość 10 → 8)
    $r = $post($ola, 'partia_nowa', ['id' => $b1, 'product_id' => $prod, 'qty' => '8', 'produced_at' => '2026-11-04', 'keep_code' => '1', 'save' => '1']);
    ok($r['status'] === 302 && $stock('Słoik 180 ml') == 12.0 && $actual() === 11 && $pdo->query("SELECT code FROM ext_prod_batches WHERE id = '$b1'")->fetchColumn() === 'AD010LX02ABBA',
        'Editing a batch re-applies the difference and keeps the number');

    // ---- Wycena robocza i zatwierdzenie
    $r = $post($ola, 'partia_nowa', ['product_id' => $prod, 'qty' => '5', 'produced_at' => '2026-11-05', 'draft' => '1', 'save' => '1']);
    $dr = $locId($r);
    $drow = $pdo->query("SELECT * FROM ext_prod_batches WHERE id = '$dr'")->fetch(PDO::FETCH_ASSOC);
    ok(str_starts_with($drow['code'], 'WYC-20261105-') && (int) $drow['is_draft'] === 1 && $stock('Słoik 180 ml') == 12.0 && (float) $drow['cost_materials'] > 0, 'Draft costs the batch without touching stock');
    $post($ola, 'partia_zatwierdz', ['id' => $dr]);
    ok($pdo->query("SELECT code FROM ext_prod_batches WHERE id = '$dr'")->fetchColumn() === 'AE005LX02ABBA' && $stock('Słoik 180 ml') == 7.0 && $actual() === 16, 'Approving the draft gives a real number and takes stock');

    // ---- Dekoder, lista, pulpit
    $dec = request($vi, $url('partie', ['dekoduj' => 'ad010lx02abba']))['body'];
    ok(str_contains($dec, 'listopad') && str_contains($dec, '2026') && str_contains($dec, 'Wosk sojowy Golden 464') && str_contains($dec, 'Olejek Piernik') && str_contains($dec, 'bawełniany')
        && str_contains($dec, 'To partia'), 'Decoder reads the number and finds the batch');
    $dash = request($vi, $url('pulpit'))['body'];
    ok(str_contains($dash, 'L-123') && str_contains($dash, 'Ostatnie partie') && str_contains($dash, 'Do zrobienia'), 'Dashboard shows expiring deliveries and recent batches');

    // ---- Inwentaryzacja
    $post($ola, 'inwentaryzacja', ['counted' => [$wax => '4000', $scent => '']]);
    ok($stock('Wosk sojowy Golden 464') == 4000.0 && $stock('Olejek Piernik') == 418.0
        && (int) $pdo->query("SELECT COUNT(*) FROM ext_prod_moves WHERE kind = 'inwentaryzacja'")->fetchColumn() >= 1, 'Inventory sets the counted stock');
    $post($ola, 'inwentaryzacja', ['counted' => [$jar => '9']]);
    ok($stock('Słoik 180 ml') == 9.0, 'Inventory surplus becomes a delivery');

    // ---- Pakowanie: numer partii przy pozycji
    $pack = request($ola, 'admin/packing.php');
    $o = request($ola, 'admin/packing_api.php?action=order&id=501')['json'];
    ok(($o['order']['ext']['produkcja']['items']['0'][0] ?? '') === 'AE005LX02ABBA', 'Packing gets batch hints for the candle line');
    $csrf = preg_match('/"csrf":"([a-f0-9]+)"/', $pack['body'], $m) ? $m[1] : csrfOf($pack['body']);
    $r = request($ola, 'admin/packing_api.php', ['action' => 'ext_produkcja_partia', 'id' => '501', 'idx' => '0', 'code' => 'ad010lx02abba'], ['X-CSRF-Token: ' . $csrf, 'X-Requested-With: pack']);
    ok(($r['json']['ok'] ?? false) === true && ($r['json']['known'] ?? false) === true, 'Packing saves the batch number' . (($r['json']['error'] ?? '') !== '' ? ': ' . $r['json']['error'] : ''));
    $o = request($ola, 'admin/packing_api.php?action=order&id=501')['json'];
    ok(($o['order']['ext']['produkcja']['assigned']['0'] ?? '') === 'AD010LX02ABBA', 'Assigned number comes back to the packing screen');
    ok(str_contains(request($vi, $url('partia', ['id' => $b1]))['body'], 'order_view.php?id=501'), 'Batch card lists the order that got it');
    $vpack = request($vi, 'admin/packing.php');
    $vcsrf = preg_match('/"csrf":"([a-f0-9]+)"/', $vpack['body'], $m) ? $m[1] : csrfOf($vpack['body']);
    $r = request($vi, 'admin/packing_api.php', ['action' => 'ext_produkcja_partia', 'id' => '501', 'idx' => '0', 'code' => 'X'], ['X-CSRF-Token: ' . $vcsrf, 'X-Requested-With: pack']);
    ok(($r['json']['ok'] ?? true) === false && $pdo->query('SELECT batch_code FROM ext_prod_order_batches WHERE order_id = 501')->fetchColumn() === 'AD010LX02ABBA', 'Viewer cannot assign a batch');

    // ---- Etykiety
    $lab = request($vi, $url('etykieta', ['id' => $b1, 'n' => 3]))['body'];
    ok(substr_count($lab, 'class="l"') === 3 && str_contains($lab, 'AD010LX02ABBA') && str_contains($lab, '04.11.2028') && str_contains($lab, 'Klub Woskarzy'), 'Label sheet with number, best-before and maker');

    // ---- Test spalania ze zdjęciem
    $img = imagecreatetruecolor(40, 60);
    imagejpeg($img, "$temp/test.jpg");
    $tf = request($ola, $url('test', ['wyrob' => $prod]));
    $r = request($ola, $url('test'), ['csrf' => csrfOf($tf['body']), 'product_id' => $prod, 'batch_id' => $b1, 'tested_at' => '2026-11-04', 'wick' => 'ECO 6', 'result' => 'ok',
        'm[0][h]' => '1', 'm[0][pool]' => '45', 'm[0][flame]' => '20', 'm[1][h]' => '4', 'm[1][pool]' => '70', 'm[1][soot]' => '1', 'm[2][h]' => '5', 'notes' => 'Pełne jeziorko po 4 h',
        'photo' => new CURLFile("$temp/test.jpg", 'image/jpeg', 'test.jpg')]);
    $tid = $locId($r);
    $trow = $pdo->query("SELECT * FROM ext_prod_tests WHERE id = '$tid'")->fetch(PDO::FETCH_ASSOC);
    ok($r['status'] === 302 && count(json_decode($trow['measurements'], true)) === 2 && $trow['photo'] !== '' && is_file("$temp/storage/uploads/produkcja/" . $trow['photo']), 'Burn test saved with measurements and photo');
    $tl = request($vi, $url('testy'))['body'];
    ok(str_contains($tl, 'Świeca Piernik 180') && str_contains($tl, 'Zaliczony') && str_contains($tl, 'AD010LX02ABBA'), 'Burn tests list');
    $ph = request($vi, $url('test_zdjecie', ['id' => $tid]));
    ok($ph['status'] === 200 && str_contains($ph['headers'], 'image/jpeg'), 'Photo is served after login');

    // ---- Import CSV i kopii Rejestru Partii
    $r = $post($ola, 'import', ['kind' => 'csv', 'text' => "nazwa;kategoria;jednostka;kod;stan;cena;minimum\nGoldenwax 464;wosk;kg;01;20;380;5\n;;;\nKnot drewniany L;knot;szt;;50;25;"]);
    ok($r['status'] === 302 && $stock('Goldenwax 464') == 20000.0 && (float) $pdo->query("SELECT min_stock FROM ext_prod_materials WHERE name = 'Goldenwax 464'")->fetchColumn() == 5000.0
        && $stock('Knot drewniany L') == 50.0, 'CSV import adds materials with stock in base units');
    $bne = json_encode([
        'batch_waxes' => [['id' => '1', 'wax_name' => 'Goldenwax Y50', 'wax_code' => '01'], ['id' => '2', 'wax_name' => 'Kerasoy Pillar', 'wax_code' => '07']],
        'batch_scents' => [['id' => '1', 'scent_name' => '-Brak zapachu-', 'scent_code' => 'AA'], ['id' => '30', 'scent_name' => 'Wanilia', 'scent_code' => 'VA']],
        'batch_wick_types' => [['id' => '1', 'wick_name' => 'Brak', 'wick_code' => 'X'], ['id' => '2', 'wick_name' => 'Bawełniany N39', 'wick_code' => 'B']],
        'batch_containers' => [['id' => '1', 'container_name' => 'Szklany', 'type' => null, 'weight_g' => '200']],
        'batch_employees' => [['id' => '1', 'employee_name' => 'Adam Wolski', 'hourly_rate' => '40']],
        'batch_parties' => [['id' => '3', 'party_name' => 'Seria 1', 'party_code' => 'Z']],
        'batch_supplies' => [['id' => '7', 'item_type' => 'scent', 'parent_id' => '30', 'supply_number' => '1250925', 'manufacturer' => 'Easy Candle', 'date_received' => '2025-12-06',
            'is_active' => '1', 'expiration_date' => '2026-10-01', 'price_total' => '45', 'quantity_total' => '0.5', 'unit' => 'kg']],
        'batch_records' => [['id' => '6', 'batch_code' => 'AL001BW02AAB', 'production_date' => '2025-02-11', 'wax_type' => 'Kerasoy Pillar', 'scent' => '-Brak zapachu-', 'quantity' => '1',
            'product_name' => 'Walec Zakręcony Duży', 'concentration' => '', 'container' => 'Forma', 'wick_type' => 'Bawełniany N39', 'party' => null, 'employee' => 'Adam Wolski', 'shelf_life_months' => '12']],
        'batch_clp_templates' => [],
    ]);
    $r = $post($ola, 'import', ['kind' => 'bne', 'text' => $bne]);
    $f = $flash($ola);
    ok($r['status'] === 302 && str_contains($f, '5 materiałów, 1 dostaw, 1 wyrobów, 1 partii, 1 osób, 1 zmian'), 'Rejestr Partii backup is imported');
    $sup = $pdo->query("SELECT d.* FROM ext_prod_deliveries d JOIN ext_prod_materials m ON m.id = d.material_id WHERE m.name = 'Wanilia'")->fetch(PDO::FETCH_ASSOC);
    ok((float) $sup['qty'] == 500.0 && (float) $sup['remaining'] == 0.0 && $sup['lot_no'] === '1250925' && (float) $pdo->query("SELECT fill_g FROM ext_prod_materials WHERE name = 'Szklany'")->fetchColumn() == 200.0,
        'Imported deliveries keep lot, price and quantity (stock left for the inventory)');
    $post($ola, 'import', ['kind' => 'bne', 'text' => $bne]);
    ok(str_contains($flash($ola), '0 materiałów, 0 dostaw, 0 wyrobów, 0 partii, 0 osób, 0 zmian'), 'Importing again adds nothing');
    $imp = request($vi, $url('partie', ['q' => 'AL001']))['body'];
    ok(str_contains($imp, 'AL001BW02AAB') && str_contains($imp, 'import'), 'Imported batch is in the history');
    $dec = request($vi, $url('partie', ['dekoduj' => 'AL001BW07AABZ']))['body'];
    ok(str_contains($dec, 'Kerasoy Pillar') && str_contains($dec, 'Seria 1'), 'Decoder uses imported codes and shifts');
    $r = $post($ola, 'import', ['kind' => 'bne', 'text' => '{"foo":1}']);
    ok(str_contains($r['body'], 'To nie jest kopia z Rejestru Partii'), 'Wrong JSON is refused');

    // ---- Eksport i import partii (CSV)
    $ex = request($vi, $url('partie_eksport'));
    $csv = $ex["body"];
    ok($ex['status'] === 200 && str_contains($ex['headers'], 'text/csv') && str_contains($ex['headers'], 'partie-') && str_starts_with($csv, "\xEF\xBB\xBF")
        && str_contains($csv, '"Numer partii";"Data produkcji";Produkt;SKU') && str_contains($csv, 'AD010LX02ABBA') && str_contains($csv, 'AL001BW02AAB'), 'Viewer exports batches to CSV');
    $line = array_values(array_filter(explode("\n", $csv), static fn($l) => str_starts_with($l, 'AD010LX02ABBA')))[0] ?? '';
    ok(str_contains($line, ';2026-11-04;') && str_contains($line, 'Świeca Piernik 180') && preg_match('/;#\d+/', $line) === 1 && str_contains($line, 'Wosk sojowy'), 'Exported batch has date, product, materials and orders: ' . $line);
    $exf = request($vi, $url('partie_eksport', ['q' => 'AL001']))['body'];
    ok(str_contains($exf, 'AL001BW02AAB') && !str_contains($exf, 'AD010LX02ABBA'), 'Export follows the list filters');
    $before = (int) $pdo->query('SELECT COUNT(*) FROM ext_prod_batches')->fetchColumn();
    $r = $post($ola, 'import', ['kind' => 'partie', 'text' => $csv]);
    ok($r['status'] === 302 && str_contains($flash($ola), 'dodano 0') && (int) $pdo->query('SELECT COUNT(*) FROM ext_prod_batches')->fetchColumn() === $before, 'Re-importing our own export adds nothing');
    $stockBefore = (int) $pdo->query("SELECT actual_stock FROM products WHERE sku = 'GB-1L'")->fetchColumn();
    // Eksport „Partie CSV” z Rejestru Partii: przecinek, data d.m.Y, bez numeru w jednym wierszu, Windows-1250.
    $old = iconv('UTF-8', 'CP1250', "Kod,Data,Produkt,Zapach,Wosk,Ilość,Pracownik\nOLD001,14.03.2025,Świeca Piernik 180,Piernik,Sojowy,12,Ola\n,15.03.2025,Wosk zapachowy,Wanilia,Rzepakowy,30,Adam\nOLD003,,Bez daty,,,1,\n");
    $r = $post($ola, 'import', ['kind' => 'partie', 'text' => $old]);
    $f = $flash($ola);
    $o1 = $pdo->query("SELECT b.*, p.name AS pname FROM ext_prod_batches b LEFT JOIN ext_prod_products p ON p.id = b.product_id WHERE b.code = 'OLD001'")->fetch(PDO::FETCH_ASSOC);
    $o2 = $pdo->query("SELECT * FROM ext_prod_batches WHERE code = 'IMP-20250315-01'")->fetch(PDO::FETCH_ASSOC);
    ok($r['status'] === 302 && str_contains($f, 'dodano 2') && str_contains($f, 'nowych wyrobów 1') && str_contains($f, 'pominięto 1 bez daty') && str_contains($f, 'Wiersz 4'), 'Old CSV batches are imported');
    ok($o1 && $o1['produced_at'] === '2025-03-14' && (int) $o1['qty'] === 12 && $o1['person'] === 'Ola' && $o1['product_id'] === $prod && str_contains($o1['notes'], 'Zapach: Piernik')
        && (int) $o1['imported'] === 1 && $o2 && $o2['product_name'] === 'Wosk zapachowy', 'Imported batches keep date, quantity, person and link to existing products');
    ok((int) $pdo->query("SELECT actual_stock FROM products WHERE sku = 'GB-1L'")->fetchColumn() === $stockBefore, 'Imported history does not change product stock');
    $post($ola, 'import', ['kind' => 'partie', 'text' => $old]);
    ok(str_contains($flash($ola), 'pominięto 1 z numerem'), 'Importing the same file again skips known numbers');
    $r = $post($ola, 'import', ['kind' => 'partie', 'text' => "Produkt;Ilość\nX;1"]);
    ok(str_contains($r['body'], 'Nie znalazłem kolumny z datą'), 'File without a date column is refused');
    $r = request($vi, $url('import', ['co' => 'partie']));
    ok($r['status'] !== 200 || !str_contains($r['body'], 'name="kind" value="partie"'), 'Viewer cannot open the import');
    $mx = request($vi, $url('materialy_eksport'));
    ok($mx['status'] === 200 && str_contains($mx['body'], 'nazwa;kategoria;jednostka;kod;stan') && str_contains($mx["body"], '"Goldenwax 464";Wosk;g;01;20000;380;5000'), 'Materials export in the import format');

    // ---- Partia bez wyrobu (jak w Rejestrze Partii)
    $fm = request($ola, $url('partia_nowa', ['bez' => 1]))['body'];
    ok(str_contains($fm, 'name="product_name"') && str_contains($fm, 'name="f[wosk][id]"') && str_contains($fm, 'name="free" value="1"') && !str_contains($fm, 'name="product_id"'), 'Batch form without a product');
    $code = json_decode(request($ola, $url('partia_kod', ['free' => 1, 'produced_at' => '2026-11-07', 'qty' => 6, 'f' => ['wosk' => ['id' => $wax], 'zapach' => ['id' => $scent], 'knot' => ['id' => $wick]]]))['body'], true);
    ok(str_starts_with($code['code'] ?? '', 'AG006LX02ABB'), 'Live number from the chosen wax, scent and wick: ' . ($code['code'] ?? ''));
    $waxBefore = $stock('Wosk sojowy Golden 464');
    $scentBefore = $stock('Olejek Piernik');
    $crmBefore = $actual();
    $r = $post($ola, 'partia_nowa', ['free' => 1, 'save' => 1, 'product_name' => 'Świeca sojowa Lawenda', 'shelf_life_months' => '18', 'qty' => '6', 'produced_at' => '2026-11-07',
        'f' => ['wosk' => ['id' => $wax, 'qty' => '600'], 'zapach' => ['id' => $scent, 'qty' => ''], 'knot' => ['id' => $wick, 'qty' => '6'], 'pojemnik' => ['id' => '', 'qty' => '']]]);
    $fid = $locId($r);
    $fb = $pdo->query("SELECT * FROM ext_prod_batches WHERE id = '$fid'")->fetch(PDO::FETCH_ASSOC);
    ok($r['status'] === 302 && $fb && $fb['product_id'] === null && $fb['product_name'] === 'Świeca sojowa Lawenda' && str_starts_with($fb['code'], 'AG006LX02ABB')
        && $fb['expires_at'] === '2028-05-07' && (int) $fb['qty'] === 6, 'Batch without a product is saved: ' . ($fb['code'] ?? ''));
    ok($stock('Wosk sojowy Golden 464') == $waxBefore - 600 && $stock('Olejek Piernik') == $scentBefore && $actual() === $crmBefore,
        'Only materials with a quantity leave the stock, no CRM product is touched');
    $fc = request($vi, $url('partia', ['id' => $fid]))['body'];
    ok(str_contains($fc, 'Świeca sojowa Lawenda') && str_contains($fc, 'Wosk sojowy Golden 464') && str_contains($fc, 'Olejek Piernik'), 'Card lists the chosen materials');
    $fe = request($ola, $url('partia_nowa', ['id' => $fid]))['body'];
    ok(str_contains($fe, 'value="Świeca sojowa Lawenda"') && str_contains($fe, 'value="600"') && str_contains($fe, 'value="18"'), 'Edit form is filled from the batch');
    $post($ola, 'partia_usun', ['id' => $fid]);
    ok($stock('Wosk sojowy Golden 464') == $waxBefore, 'Trash gives the wax back');
    $post($ola, 'partia_przywroc', ['id' => $fid]);
    ok($stock('Wosk sojowy Golden 464') == $waxBefore - 600, 'Restore takes it again');
    $r = $post($ola, 'partia_nowa', ['free' => 1, 'save' => 1, 'draft' => 1, 'product_name' => 'Lawenda próbna', 'qty' => '2', 'produced_at' => '2026-11-08',
        'f' => ['wosk' => ['id' => $wax, 'qty' => '300']]]);
    $did = $locId($r);
    ok($stock('Wosk sojowy Golden 464') == $waxBefore - 600, 'Free draft does not take materials');
    $post($ola, 'partia_zatwierdz', ['id' => $did]);
    $db = $pdo->query("SELECT * FROM ext_prod_batches WHERE id = '$did'")->fetch(PDO::FETCH_ASSOC);
    ok((int) $db['is_draft'] === 0 && $db['product_name'] === 'Lawenda próbna' && $stock('Wosk sojowy Golden 464') == $waxBefore - 900, 'Approved free draft takes the materials');
    $r = $post($ola, 'partia_nowa', ['free' => 1, 'save' => 1, 'product_name' => '', 'qty' => '1']);
    ok(str_contains($r['body'], 'Wpisz nazwę produktu'), 'Name is required without a product');

    // ---- Osoby i zmiany
    $post($ola, 'ludzie', ['person_name' => ['Adam Wolski', 'Ola'], 'person_rate' => ['40', '25'], 'shift_name' => ['Seria 1', 'Poranna'], 'shift_code' => ['z', 'p']]);
    $sh = $pdo->query("SELECT id FROM ext_prod_dict WHERE kind = 'shift' AND name = 'Poranna'")->fetchColumn();
    $code = json_decode(request($ola, $url('partia_kod', ['product_id' => $prod, 'produced_at' => '2026-11-06', 'qty' => 2, 'shift' => $sh]))['body'], true);
    ok(($code['code'] ?? '') === 'AF002LX02ABBP', 'Shift letter goes into the number: ' . ($code['code'] ?? ''));

    $warnings = preg_grep('/PHP (Warning|Fatal|Parse|Deprecated|Notice)|Rozszerzenie .* błąd/', file("$temp/server.log") ?: []);
    ok($warnings === [], 'No PHP warnings or extension errors' . ($warnings ? ': ' . implode(' | ', array_map('trim', $warnings)) : ''));
    echo "PASS: {$checks} Produkcja HTTP checks\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (getenv('KEEP_TEMP')) { echo "temp: $temp\n"; } else { exec('rm -rf ' . escapeshellarg($temp)); }
}
