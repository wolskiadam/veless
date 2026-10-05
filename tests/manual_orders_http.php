<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Ręczne zamówienie i link do płatności PayU przez HTTP: formularz „Nowe zamówienie”, błędy formularza, zapis, strona
// zamówienia (źródło, link do skopiowania), link z zamówienia ze sklepu, powiadomienie PayU, uprawnienia.
// Prawdziwe strony panelu, SQLite, atrapa PayU jako drugi serwer na 127.0.0.1.
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Pase\\')) { require $root . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\PayuPayments;
use Pase\Services\PayuPayouts;
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

$temp = sys_get_temp_dir() . '/crm-manual-orders-http-' . bin2hex(random_bytes(6));
foreach (['public/admin/assets', 'public/pack/assets', 'config', 'storage/security', 'lang'] as $d) { mkdir("$temp/$d", 0700, true); }
$process = null;
try {
    file_put_contents("$temp/.env", "TOTP_REQUIRE_ADMIN=0\n");
    foreach (glob("$root/public/admin/*.php") as $f) { if (!in_array(basename($f), ['bootstrap_admin.php', 'db_admin.php'], true)) { copy($f, "$temp/public/admin/" . basename($f)); } }
    copy("$root/public/admin/assets/loc-picker.js", "$temp/public/admin/assets/loc-picker.js");
    mkdir("$temp/integrations/payu", 0700, true);
    foreach (glob("$root/integrations/payu/*.php") as $f) { copy($f, "$temp/integrations/payu/" . basename($f)); }
    foreach (glob("$root/lang/*.php") as $f) { copy($f, "$temp/lang/" . basename($f)); }
    file_put_contents("$temp/public/admin/bootstrap_admin.php", '<?php \Pase\Support\PagePermissions::migrate($pdo);');

    // Konfiguracja jak config/config.php, ale z SQLite i kodem z repozytorium.
    $boot = '<?php if (!defined("PASE_ROOT")) { define("PASE_ROOT", ' . var_export($temp, true) . ');'
        . 'spl_autoload_register(static function($c) { if (str_starts_with($c, "Pase\\\\")) require ' . var_export("$root/src/", true)
        . ' . str_replace("\\\\", "/", substr($c, 5)) . ".php";'
        . ' if (str_starts_with($c, "PasePlugin\\\\Payu\\\\")) require PASE_ROOT . "/integrations/payu/" . substr($c, 16) . ".php"; });'
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
    $pdo->exec("INSERT INTO order_statuses (status_key,label,color,position) VALUES ('new','Nowe','#8a8f98',1),('processing','W realizacji','#c28a2b',2),('shipped','Wysłane','#2f8f5b',3)");
    $pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT, is_active INTEGER, config TEXT, webhook_secret TEXT NULL, access_token TEXT NULL, refresh_token TEXT NULL, token_expires_at TEXT NULL)');
    $pdo->exec("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES (7, 'payu', 'PayU sklep', 1, '" . json_encode(['client_id' => '300746', 'client_secret' => 's', 'shop_id' => 'SHOP1234']) . "')");
    $pdo->exec("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES (1, 'woocommerce', 'Mój Sklep', 1, '{}')");
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, pase_stock INTEGER, actual_stock INTEGER NULL, pase_price REAL, images TEXT, ean TEXT)');
    $pdo->exec("INSERT INTO products (id, sku, name, pase_stock, pase_price) VALUES (1, 'GB-1L', 'GINGERBREAD 1l', 5, 49.9), (2, 'MW-1L', 'MULLED WINE 1l', 5, 39.9)");
    $pdo->exec("CREATE TABLE woo_orders (id INTEGER PRIMARY KEY, woo_order_id INTEGER UNIQUE, integration_id INTEGER, pase_number INTEGER, order_number TEXT, status TEXT,
        pase_status TEXT, currency TEXT, total REAL, customer_name TEXT, customer_email TEXT, date_created TEXT, imported_at TEXT, updated_at TEXT,
        lifecycle TEXT DEFAULT 'active', payload TEXT, local_items TEXT NULL, local_edited_at TEXT NULL, local_billing_email TEXT NULL,
        local_billing_phone TEXT NULL, local_invoice TEXT NULL, local_pickup_point TEXT NULL, forwarded_to TEXT NULL, client_token TEXT NULL,
        automations_done INTEGER DEFAULT 0, internal_status TEXT NULL, no_shipping INTEGER NULL)");
    $pdo->exec('CREATE TABLE shipments (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, courier_code TEXT, waybill_no TEXT, bl_order_id TEXT,
        order_ref TEXT, price REAL, status TEXT, label_link TEXT, request_payload TEXT, response_payload TEXT, created_at TEXT,
        tracking_status TEXT, tracking_code TEXT, tracking_carrier TEXT, tracking_at TEXT, tracking_checked_at TEXT, tracking_events TEXT)');
    $pdo->exec("CREATE TABLE order_messages (id INTEGER PRIMARY KEY, woo_order_id INTEGER, sender TEXT, author_name TEXT, body TEXT,
        attachment_path TEXT, attachment_name TEXT, is_read INTEGER DEFAULT 0, created_at TEXT)");
    $pdo->exec("CREATE TABLE order_documents (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, provider TEXT, document_type TEXT,
        remote_id TEXT, status TEXT DEFAULT 'issued', message TEXT, created_at TEXT, updated_at TEXT)");
    foreach ([\Pase\Services\OrderReturns::class, \Pase\Services\AllegroFeedback::class, \Pase\Services\Customers::class,
        \Pase\Services\DocumentIssueGuard::class, \Pase\Services\OrderMerge::class, \Pase\Services\OrderPayment::class] as $svc) { $svc::migrate($pdo); }
    $order = static fn(string $street, array $items, float $total): string => json_encode([
        'status' => 'on-hold', 'payment_method' => 'payulistbanks', 'payment_method_title' => 'PayU', 'transaction_id' => 'WZGF5FFDRJ140731GUEST000P01', 'date_paid' => null, 'total' => (string) $total,
        'billing' => ['first_name' => 'Anna', 'last_name' => 'Kowalska', 'email' => 'anna@example.com', 'address_1' => $street, 'postcode' => '00-001', 'city' => 'Warszawa'],
        'shipping' => ['first_name' => 'Anna', 'last_name' => 'Kowalska', 'address_1' => $street, 'postcode' => '00-001', 'city' => 'Warszawa'],
        'line_items' => $items, 'shipping_lines' => [['method_title' => 'Kurier', 'total' => '15.00']]]);
    $oins = $pdo->prepare('INSERT INTO woo_orders (woo_order_id, integration_id, pase_number, order_number, status, pase_status, currency, total, customer_name, customer_email, date_created, imported_at, payload)
        VALUES (?,1,?,?,?,?,?,?,?,?,?,?,?)');
    $oins->execute([501, 11, '501', 'processing', 'new', 'PLN', 114.8, 'Anna Kowalska', 'anna@example.com', '2026-09-20 10:00:00', '2026-09-20 10:01:00',
        $order('Lipowa 1', [['product_id' => 1, 'sku' => 'GB-1L', 'name' => 'GINGERBREAD 1l', 'quantity' => 2, 'price' => 49.9, 'total' => '99.80']], 114.8)]);
    $oins->execute([502, 12, '502', 'processing', 'new', 'PLN', 54.9, 'Anna Kowalska', 'anna@example.com', '2026-09-21 10:00:00', '2026-09-21 10:01:00',
        $order('Polna 2', [['product_id' => 2, 'sku' => 'MW-1L', 'name' => 'MULLED WINE 1l', 'quantity' => 1, 'price' => 39.9, 'total' => '39.90', 'total_tax' => '9.18', 'variation_id' => 77]], 54.9)]);

    PayuPayouts::migrate($pdo);
    PayuPayments::migrate($pdo);
    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('APP_BASE_URL', 'https://crm.example.pl/public')");

    // Atrapa PayU: tworzenie płatności (302 + redirectUri) i jej stan z pliku (NEW / COMPLETED).
    mkdir("$temp/payu", 0700, true);
    file_put_contents("$temp/payu/index.php", '<?php
        $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH); header("Content-Type: application/json"); $d = __DIR__;
        if ($path === "/pl/standard/user/oauth/authorize") { echo json_encode(["access_token" => "tok"]); return; }
        if (($_SERVER["HTTP_AUTHORIZATION"] ?? "") !== "Bearer tok") { http_response_code(401); echo "{}"; return; }
        if ($path === "/api/v2_1/orders" && $_SERVER["REQUEST_METHOD"] === "POST") {
            $n = count(glob("$d/created-*.json")) + 1; $id = "LINK" . $n . "F5FFDRJ140731GUEST000P01";
            file_put_contents("$d/created-$n.json", file_get_contents("php://input"));
            http_response_code(302); echo json_encode(["status" => ["statusCode" => "SUCCESS"], "redirectUri" => "https://secure.payu.com/pay/?orderId=$id&token=t$n", "orderId" => $id]); return; }
        if (preg_match("~^/api/v2_1/orders/(LINK(\\d+)[A-Z0-9]+)$~", $path, $m)) {
            $req = json_decode(file_get_contents("$d/created-{$m[2]}.json"), true);
            echo json_encode(["orders" => [["orderId" => $m[1], "status" => is_file("$d/paid-{$m[2]}") ? "COMPLETED" : "NEW", "totalAmount" => $req["totalAmount"], "currencyCode" => "PLN",
                "extOrderId" => $req["extOrderId"], "description" => $req["description"], "orderCreateDate" => "2026-09-28T10:00:00+02:00"]]]); return; }
        if (preg_match("~^/api/v2_1/orders/LINK~", $path)) { echo json_encode(["refunds" => [], "transactions" => []]); return; }
        http_response_code(404); echo json_encode(["status" => ["statusCode" => "DATA_NOT_FOUND"]]);');
    $free = static function (): string { $s = stream_socket_server('tcp://127.0.0.1:0'); $a = stream_socket_get_name($s, false); fclose($s); return $a; };
    $payuAddr = $free();
    $payuProc = proc_open([PHP_BINARY, '-S', $payuAddr, "$temp/payu/index.php"], [0 => ['pipe', 'r'], 1 => ['file', "$temp/payu.log", 'a'], 2 => ['file', "$temp/payu.log", 'a']], $pp);
    fclose($pp[0]);
    $address = $free();
    $base = 'http://' . $address . '/';
    copy("$root/public/webhook_payu.php", "$temp/public/webhook_payu.php");
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes, null, ['CRM_TEST_PAYU_URL' => 'http://' . $payuAddr] + getenv());
    fclose($pipes[0]);
    foreach ([$address, $payuAddr] as $a) {
        for ($i = 0; $i < 100; ++$i) { $p = @stream_socket_client('tcp://' . $a, $e1, $e2, 0.1); if ($p) { fclose($p); break; } usleep(10000); }
    }
    $created = static fn(int $n): array => json_decode((string) @file_get_contents("$temp/payu/created-$n.json"), true) ?: [];

    $boss = login('boss');
    $list = request($boss, 'admin/index.php');
    ok(str_contains($list['body'], 'href="order_new.php"'), 'Orders list has the "+ Nowe zamówienie" button');
    $form = request($boss, 'admin/order_new.php');
    ok($form['status'] === 200 && str_contains($form['body'], 'Dane klienta') && str_contains($form['body'], '"GB-1L":{"n":"GINGERBREAD 1l","p":"49.90","s":5}'), 'Form shows with the warehouse catalogue');
    ok(str_contains($form['body'], 'Od razu utwórz link do płatności PayU') && str_contains($form['body'], 'PayU - link do płatności'), 'Form offers a PayU payment link');

    // Błąd formularza: nic nie zapisane, dane zostają w formularzu.
    $r = request($boss, 'admin/order_new.php', ['csrf' => csrfOf($form['body']), 'billing_first_name' => 'Ewa', 'billing_email' => 'ewa@', 'item_sku' => [''], 'item_name' => [''], 'item_price' => [''], 'item_qty' => ['1']]);
    ok($r['status'] === 200 && str_contains($r['body'], 'Nie zapisano zamówienia') && str_contains($r['body'], 'Dodaj co najmniej jedną pozycję') && str_contains($r['body'], 'value="Ewa"'), 'Invalid form is shown again with errors and entered data');
    ok((int) $pdo->query('SELECT COUNT(*) FROM woo_orders')->fetchColumn() === 2, 'Nothing saved on error');

    $r = request($boss, 'admin/order_new.php', ['csrf' => csrfOf($form['body']),
        'billing_first_name' => 'Ewa', 'billing_last_name' => 'Nowak', 'billing_email' => 'ewa@example.com', 'billing_phone' => '500600700',
        'billing_address_1' => 'Leśna 5', 'billing_postcode' => '60-001', 'billing_city' => 'Poznań', 'billing_country' => 'PL',
        'item_sku' => ['GB-1L', ''], 'item_name' => ['', 'Zestaw prezentowy'], 'item_price' => ['49.90', '20'], 'item_qty' => ['2', '1'],
        'shipping_method' => 'InPost Paczkomat', 'shipping_cost' => '13.99', 'payment_method' => 'payu_link', 'payu_create' => '1', 'payu_shop' => '7', 'payu_days' => '3',
        'pase_status' => 'new', 'customer_note' => 'Na prezent']);
    $id = (int) $pdo->query('SELECT MAX(woo_order_id) FROM woo_orders')->fetchColumn();
    ok(in_array($r['status'], [302, 303], true) && str_contains($r['headers'], 'order_view.php?id=' . $id) && $id === 8000000001, 'Order saved and user sent to the order page');
    $row = $pdo->query("SELECT * FROM woo_orders WHERE woo_order_id = $id")->fetch(PDO::FETCH_ASSOC);
    ok($row['integration_id'] === null && $row['order_number'] === 'R1' && (float) $row['total'] === 133.79 && (int) $row['pase_number'] === 13, 'Manual order: CRM only, R1, total with shipping, next CRM number');
    ok((int) $pdo->query("SELECT pase_stock FROM products WHERE sku = 'GB-1L'")->fetchColumn() === 3, 'Warehouse stock lowered by 2');
    $req = $created(1);
    ok(($req['totalAmount'] ?? '') === '13379' && ($req['validityTime'] ?? '') === '259200' && ($req['merchantPosId'] ?? '') === '300746'
        && ($req['notifyUrl'] ?? '') === 'https://crm.example.pl/public/webhook_payu.php' && ($req['buyer']['email'] ?? '') === 'ewa@example.com', 'PayU link created for the order total, 3 days, with notify URL and buyer');

    $page = request($boss, 'admin/order_view.php?id=' . $id);
    ok(str_contains($page['body'], 'Dodano zamówienie ręczne') && str_contains($page['body'], 'Utworzono link do płatności PayU'), 'Order page confirms order and link');
    ok(str_contains($page['body'], 'dodane ręcznie w CRM') && !str_contains($page['body'], 'Numer w sklepie') && !str_contains($page['body'], 'Status w sklepie'), 'Order page shows manual source instead of shop rows');
    ok(str_contains($page['body'], 'https://secure.payu.com/pay/?orderId=LINK1F5FFDRJ140731GUEST000P01&amp;token=t1') && str_contains($page['body'], 'czeka na zapłatę') && str_contains($page['body'], 'secondary payu-copy'), 'Link shown for copying, waiting for payment');
    ok(str_contains($page['body'], 'Zestaw prezentowy') && str_contains($page['body'], 'GINGERBREAD 1l') && str_contains($page['body'], '133.79'), 'Items and total on the order page');

    // Klient zapłacił: powiadomienie PayU -> odczyt z PayU -> wpłata na zamówieniu.
    touch("$temp/payu/paid-1");
    $pdo->exec("UPDATE payu_order_payments SET fetched_at = '2026-01-01 00:00:00'");
    $w = request(client(), 'webhook_payu.php', null);
    ok($w['status'] === 200, 'Webhook answers 200 to an empty ping');
    $w = (function () use ($base) { $c = curl_init($base . 'webhook_payu.php'); curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '',
        CURLOPT_POSTFIELDS => json_encode(['order' => ['orderId' => 'LINK1F5FFDRJ140731GUEST000P01', 'status' => 'COMPLETED']]), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
        $b = curl_exec($c); return ['status' => curl_getinfo($c, CURLINFO_RESPONSE_CODE), 'body' => $b]; })();
    ok($w['status'] === 200 && (float) $pdo->query("SELECT paid_amount FROM woo_orders WHERE woo_order_id = $id")->fetchColumn() === 133.79, 'PayU notification marks the order paid');
    $page = request($boss, 'admin/order_view.php?id=' . $id);
    ok(str_contains($page['body'], '>opłacony<') && !str_contains($page['body'], 'secondary payu-copy'), 'Paid link is shown as paid without the copy field');

    // Zamówienie ze sklepu (nieopłacone, przelew): link z sekcji „Płatność PayU”.
    $pdo->exec("UPDATE woo_orders SET payload = REPLACE(payload, '\"payment_method\":\"payulistbanks\"', '\"payment_method\":\"bacs\"'), payload = REPLACE(payload, 'WZGF5FFDRJ140731GUEST000P01', '') WHERE woo_order_id = 502");
    $page = request($boss, 'admin/order_view.php?id=502');
    ok(str_contains($page['body'], 'Utwórz link do płatności PayU') && str_contains($page['body'], 'name="link_amount" value="54.90"'), 'Unpaid shop order offers a payment link for the amount due');
    preg_match('/name="link_ext" value="([^"]+)"/', $page['body'], $m);
    $post = ['csrf' => csrfOf($page['body']), 'action' => 'payu_create_link', 'link_ext' => $m[1] ?? '', 'link_amount' => '20,00', 'link_shop' => '7', 'link_days' => '7', 'link_description' => 'Dopłata do zamówienia'];
    request($boss, 'admin/order_view.php?id=502', $post);
    request($boss, 'admin/order_view.php?id=502', $post);   // podwójne kliknięcie
    ok(count(glob("$temp/payu/created-*.json")) === 2 && ($created(2)['totalAmount'] ?? '') === '2000' && ($created(2)['description'] ?? '') === 'Dopłata do zamówienia', 'Link for 20.00 created once despite a double submit');
    $page = request($boss, 'admin/order_view.php?id=502');
    ok(str_contains($page['body'], 'LINK2F5FFDRJ140731GUEST000P01'), 'Shop order shows its link');
    $r = request($boss, 'admin/order_view.php?id=502', ['csrf' => csrfOf($page['body']), 'action' => 'payu_create_link', 'link_ext' => 'crmpay-502-x', 'link_amount' => '0', 'link_shop' => '7']);
    $page = request($boss, 'admin/order_view.php?id=502');
    ok(str_contains($page['body'], 'Podaj kwotę linku') && count(glob("$temp/payu/created-*.json")) === 2, 'Zero amount refused before PayU');

    // Przeglądający: bez przycisku i bez dostępu do formularza.
    $viewer = login('vi');
    $vl = request($viewer, 'admin/index.php');
    ok(!str_contains($vl['body'], 'href="order_new.php"'), 'Viewer has no "Nowe zamówienie" button');
    $vf = request($viewer, 'admin/order_new.php');
    ok(in_array($vf['status'], [302, 303, 403], true) && !str_contains($vf['body'], 'Dane klienta'), 'Viewer cannot open the form');
    $vp = request($viewer, 'admin/order_view.php?id=502');
    ok(!str_contains($vp['body'], 'Utwórz link do płatności PayU'), 'Viewer cannot create links');

    // Kopia danych klienta z poprzedniego zamówienia.
    $f2 = request($boss, 'admin/order_new.php?from=' . $id);
    ok(str_contains($f2['body'], 'value="Ewa"') && str_contains($f2['body'], 'value="Leśna 5"') && str_contains($f2['body'], 'value="ewa@example.com"'), 'Customer data copied from a previous order');
    $hist = request($boss, 'admin/order_history.php?id=' . $id);
    ok(str_contains($hist['body'], 'Dodanie zamówienia ręcznie'), 'Creation in the order history');
    $flt = request($boss, 'admin/index.php?source=manual');
    ok(str_contains($flt['body'], 'order_view.php?id=' . $id) && !str_contains($flt['body'], 'order_view.php?id=501') && str_contains($flt['body'], 'Ręczne'), 'Source filter "Dodane ręcznie" and badge');

    // Edycja produktów: zmiana nazwy pozycji (tylko w CRM).
    $page = request($boss, 'admin/order_view.php?id=502');
    ok(str_contains($page['body'], 'name="item_name[0]" value="MULLED WINE 1l"') && str_contains($page['body'], 'Zapisz zmiany'), 'Products editor has a name field');
    request($boss, 'admin/order_view.php?id=502', ['csrf' => csrfOf($page['body']), 'action' => 'save_items', 'qty' => ['2'], 'item_name' => ['  Grzane wino  1 l ']]);
    $li = json_decode((string) $pdo->query('SELECT local_items FROM woo_orders WHERE woo_order_id = 502')->fetchColumn(), true);
    ok(($li[0]['name'] ?? '') === 'Grzane wino 1 l' && (int) $li[0]['quantity'] === 2 && ($li[0]['sku'] ?? '') === 'MW-1L', 'Line renamed, quantity and SKU kept');
    ok(abs((float) ($li[0]['total_tax'] ?? 0) - 18.36) < 0.001 && (int) ($li[0]['variation_id'] ?? 0) === 77, 'Shop VAT scaled to new quantity, variant kept');
    $page = request($boss, 'admin/order_view.php?id=502');
    ok(str_contains($page['body'], 'faktura i paragon wystawione z CRM') && !str_contains($page['body'], 'ani na fakturę'), 'Note says edits go on invoices and receipts');
    ok(str_contains($page['body'], '<td>77</td>'), 'Variant id shown in the ID column');
    request($boss, 'admin/order_view.php?id=502', ['csrf' => csrfOf($page['body']), 'action' => 'save_items', 'qty' => ['2'], 'item_name' => ['']]);
    $li = json_decode((string) $pdo->query('SELECT local_items FROM woo_orders WHERE woo_order_id = 502')->fetchColumn(), true);
    ok(($li[0]['name'] ?? '') === 'Grzane wino 1 l', 'Empty name keeps the current name');

    // Zamówienie wirtualne: znika z filtra „Do wysyłki”.
    $need = request($boss, 'admin/index.php?need_shipping=1');
    ok(str_contains($need['body'], 'order_view.php?id=501'), 'Shop order is in the "to ship" filter');
    $page = request($boss, 'admin/order_view.php?id=501');
    ok(str_contains($page['body'], 'onchange="this.form.submit()"> Wirtualne</label>'), 'Order page has the virtual order switch');
    request($boss, 'admin/order_view.php?id=501', ['csrf' => csrfOf($page['body']), 'action' => 'set_no_shipping', 'no_shipping' => '1']);
    ok((int) $pdo->query('SELECT no_shipping FROM woo_orders WHERE woo_order_id = 501')->fetchColumn() === 1, 'Order marked virtual');
    $need = request($boss, 'admin/index.php?need_shipping=1');
    ok(!str_contains($need['body'], 'order_view.php?id=501'), 'Virtual order leaves the "to ship" filter');
    $page = request($boss, 'admin/order_view.php?id=501');
    ok(str_contains($page['body'], 'Oznaczono jako zamówienie wirtualne') && str_contains($page['body'], 'name="no_shipping" value="1" checked'), 'Switch shown as on');
    request($boss, 'admin/order_view.php?id=501', ['csrf' => csrfOf($page['body']), 'action' => 'set_no_shipping']);
    ok($pdo->query('SELECT no_shipping FROM woo_orders WHERE woo_order_id = 501')->fetchColumn() === 0, 'Switch off: needs shipping again (0 = manual choice, not auto-overridden)');
    request($viewer, 'admin/order_view.php?id=501', ['csrf' => csrfOf(request($viewer, 'admin/order_view.php?id=501')['body']), 'action' => 'set_no_shipping', 'no_shipping' => '1']);
    ok($pdo->query('SELECT no_shipping FROM woo_orders WHERE woo_order_id = 501')->fetchColumn() === 0, 'Viewer cannot change it');
    ok(str_contains(request($boss, 'admin/order_history.php?id=501')['body'], 'Zamówienie wirtualne (bez wysyłki)'), 'Change in the order history');

    // Filtr „Wysyłka" po etapie śledzenia przesyłki (W drodze, Doręczona...).
    $pdo->exec("INSERT INTO shipments (woo_order_id, courier_code, waybill_no, status, created_at, tracking_status) VALUES (502, 'InPost', '620000000000000000000001', 'created', '2026-09-22 10:00:00', 'in_transit')");
    $page = request($boss, 'admin/index.php');
    ok(str_contains($page['body'], '<option value="in_transit" >W drodze</option>'), 'Shipping filter offers tracking stages');
    $trk = request($boss, 'admin/index.php?need_shipping=in_transit');
    ok(str_contains($trk['body'], 'order_view.php?id=502') && !str_contains($trk['body'], 'order_view.php?id=501'), 'In-transit filter shows only the order with a shipment in transit');
    ok(str_contains($trk['body'], '<option value="in_transit" selected>'), 'Chosen tracking stage stays selected');
    $trk = request($boss, 'admin/index.php?need_shipping=delivered');
    ok(!str_contains($trk['body'], 'order_view.php?id=502'), 'Delivered filter skips an order still in transit');

    echo "\nAll {$checks} manual order HTTP checks passed.\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (isset($payuProc) && is_resource($payuProc)) { proc_terminate($payuProc); proc_close($payuProc); }
    if (getenv('KEEP_TEMP')) { echo "temp: $temp\n"; } else { exec('rm -rf ' . escapeshellarg($temp)); }
}
