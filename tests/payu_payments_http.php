<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Płatność PayU na stronie zamówienia przez HTTP: automatyczne przypisanie, dane transakcji, zwrot z potwierdzeniem
// (payu_refund.php), uprawnienia. Prawdziwe strony panelu, SQLite, atrapa PayU jako drugi serwer na 127.0.0.1.
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

$temp = sys_get_temp_dir() . '/crm-payu-pay-http-' . bin2hex(random_bytes(6));
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
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, pase_stock INTEGER, pase_price REAL, images TEXT, ean TEXT)');
    $pdo->exec("INSERT INTO products (id, sku, name, pase_stock, pase_price) VALUES (1, 'GB-1L', 'GINGERBREAD 1l', 5, 49.9), (2, 'MW-1L', 'MULLED WINE 1l', 5, 39.9)");
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
        $order('Polna 2', [['product_id' => 2, 'sku' => 'MW-1L', 'name' => 'MULLED WINE 1l', 'quantity' => 1, 'price' => 39.9, 'total' => '39.90']], 54.9)]);

    // Atrapa PayU: płatność opłacona kartą (120,00 PLN), zwroty zapisywane do pliku.
    mkdir("$temp/payu", 0700, true);
    file_put_contents("$temp/payu/index.php", '<?php
        $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH); header("Content-Type: application/json"); $O = "WZGF5FFDRJ140731GUEST000P01";
        if ($path === "/pl/standard/user/oauth/authorize") { echo json_encode(["access_token" => "tok"]); return; }
        if (($_SERVER["HTTP_AUTHORIZATION"] ?? "") !== "Bearer tok") { http_response_code(401); echo "{}"; return; }
        $log = __DIR__ . "/refunds.json"; $refunds = is_file($log) ? json_decode(file_get_contents($log), true) : [];
        if ($path === "/api/v2_1/orders/$O") { echo json_encode(["orders" => [["orderId" => $O, "status" => "COMPLETED", "totalAmount" => "12000", "currencyCode" => "PLN", "orderCreateDate" => "2026-09-20T10:00:00+02:00",
            "payMethod" => ["type" => "PBL"], "merchantPosId" => "4455667", "description" => "Zamówienie 501", "extOrderId" => "501-abc",
            "buyer" => ["email" => "anna@example.com", "phone" => "600100200", "firstName" => "Anna", "lastName" => "Kowalska", "delivery" => ["street" => "Polna 1", "city" => "Kraków", "postalCode" => "30-001"]]]],
            "properties" => [["name" => "PAYMENT_ID", "value" => "5018440622"]]]); return; }
        if ($path === "/api/v2_1/orders/$O/transactions") { echo json_encode(["transactions" => [["payMethod" => ["value" => "c"], "card" => ["cardData" => ["cardNumberMasked" => "543402******4014", "cardScheme" => "MC", "cardResponseCodeDesc" => "000 - OK"]]]]]); return; }
        if ($path === "/api/v2_1/orders/$O/refunds" && $_SERVER["REQUEST_METHOD"] === "GET") { echo json_encode(["refunds" => array_values($refunds)]); return; }
        if ($path === "/api/v2_1/orders/$O/refunds") { $r = json_decode(file_get_contents("php://input"), true)["refund"]; $id = (string) (5000000100 + count($refunds));
            $refunds[$id] = ["refundId" => $id, "extRefundId" => $r["extRefundId"], "amount" => (int) $r["amount"], "currencyCode" => "PLN", "description" => $r["description"], "status" => "PENDING"];
            file_put_contents($log, json_encode($refunds)); echo json_encode(["orderId" => $O, "refund" => $refunds[$id], "status" => ["statusCode" => "SUCCESS"]]); return; }
        http_response_code(404); echo json_encode(["status" => ["statusCode" => "DATA_NOT_FOUND"]]);');
    $free = static function (): string { $s = stream_socket_server('tcp://127.0.0.1:0'); $a = stream_socket_get_name($s, false); fclose($s); return $a; };
    $payuAddr = $free();
    $payuProc = proc_open([PHP_BINARY, '-S', $payuAddr, "$temp/payu/index.php"], [0 => ['pipe', 'r'], 1 => ['file', "$temp/payu.log", 'a'], 2 => ['file', "$temp/payu.log", 'a']], $pp);
    fclose($pp[0]);
    $address = $free();
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes, null, ['CRM_TEST_PAYU_URL' => 'http://' . $payuAddr] + getenv());
    fclose($pipes[0]);
    foreach ([$address, $payuAddr] as $a) {
        for ($i = 0; $i < 100; ++$i) { $p = @stream_socket_client('tcp://' . $a, $e1, $e2, 0.1); if ($p) { fclose($p); break; } usleep(10000); }
    }
    $refundsLog = static fn(): array => is_file("$temp/payu/refunds.json") ? json_decode((string) file_get_contents("$temp/payu/refunds.json"), true) : [];

    $boss = login('boss');
    $page = request($boss, 'admin/order_view.php?id=501');
    ok($page['status'] === 200 && str_contains($page['body'], 'Płatność PayU') && str_contains($page['body'], 'WZGF5FFDRJ140731GUEST000P01')
        && str_contains($page['body'], 'karta MC 543402******4014') && str_contains($page['body'], '120,00 PLN') && str_contains($page['body'], 'przypisana automatycznie'),
        'Order page shows the PayU payment linked automatically with transaction data');
    ok(str_contains($page['body'], 'payu_refund.php?order=501&amp;payment=') || str_contains($page['body'], 'payu_refund.php?order=501&payment='), 'Refund button for editors');
    $det = strpos($page['body'], 'Szczegóły płatności');
    $btn = strpos($page['body'], 'payu_refund.php?order=501');
    ok($det !== false && $btn !== false && $btn > $det && str_contains(substr($page['body'], strrpos(substr($page['body'], 0, $btn), '<details'), $btn - strrpos(substr($page['body'], 0, $btn), '<details')), 'payu-details')
        && !str_contains(substr($page['body'], strrpos(substr($page['body'], 0, $btn), '<details'), $btn - strrpos(substr($page['body'], 0, $btn), '<details')), '</details>'),
        'Refund button only inside the collapsed payment details');
    ok(str_contains($page['body'], '5018440622') && str_contains($page['body'], '4455667') && str_contains($page['body'], 'Zamówienie 501') && str_contains($page['body'], '501-abc')
        && str_contains($page['body'], '600100200') && str_contains($page['body'], 'Polna 1') && str_contains($page['body'], '30-001') && str_contains($page['body'], '000 - OK')
        && str_contains($page['body'], 'Dane płacącego') && str_contains($page['body'], 'Przebieg operacji'),
        'Payment details like the PayU panel: transaction id, POS, title, buyer, card status');
    $page502 = request($boss, 'admin/order_view.php?id=502');
    ok($page502['status'] === 200 && str_contains($page502['body'], 'Płatność PayU'), 'Another PayU order without a known payment still shows the section');

    $pid = (int) $pdo->query('SELECT id FROM payu_order_payments WHERE woo_order_id = 501')->fetchColumn();
    $form = request($boss, "admin/payu_refund.php?order=501&payment=$pid");
    ok($form['status'] === 200 && str_contains($form['body'], 'Można zwrócić') && str_contains($form['body'], 'value="120,00"'), 'Refund form prefilled with refundable amount');
    $r = request($boss, 'admin/payu_refund.php', ['csrf' => csrfOf($form['body']), 'action' => 'prepare', 'order' => 501, 'payment' => $pid, 'amount' => '150']);
    ok(str_contains($r['body'], 'większa niż możliwa do zwrotu') && $refundsLog() === [], 'Amount above refundable refused, nothing sent');
    $r = request($boss, 'admin/payu_refund.php', ['csrf' => csrfOf($form['body']), 'action' => 'prepare', 'order' => 501, 'payment' => $pid, 'amount' => '49,90', 'description' => 'Zwrot za świecę']);
    ok(str_contains($r['body'], 'Potwierdź zwrot') && str_contains($r['body'], 'Tak, zwróć 49,90 PLN') && $refundsLog() === [], 'Confirmation screen, nothing sent yet');
    preg_match('/name="ext_id" value="([^"]+)"/', $r['body'], $m);
    $go = ['csrf' => csrfOf($r['body']), 'action' => 'refund', 'order' => 501, 'payment' => $pid, 'amount' => '4990', 'description' => 'Zwrot za świecę', 'ext_id' => $m[1] ?? ''];
    ok(str_contains($r['body'], 'name="confirmed"') && str_contains($r['body'], 'Potwierdzam zwrot') && str_contains($r['body'], 'confirm('), 'Confirmation needs a checkbox and a browser prompt');
    $r = request($boss, 'admin/payu_refund.php', $go);
    ok($r['status'] === 200 && str_contains($r['body'], 'Zaznacz') && str_contains($r['body'], 'Tak, zwróć 49,90 PLN') && $refundsLog() === [], 'Refund without ticking the confirmation refused, nothing sent');
    $go['confirmed'] = '1';
    $r = request($boss, 'admin/payu_refund.php', $go);
    $sent = array_values($refundsLog());
    ok(in_array($r['status'], [302, 303], true) && str_contains($r['headers'], 'order_view.php?id=501') && count($sent) === 1 && $sent[0]['amount'] === 4990 && $sent[0]['extRefundId'] === $m[1], 'Refund sent after confirmation, back to the order');
    request($boss, 'admin/payu_refund.php', $go);
    ok(count($refundsLog()) === 1, 'Repeated confirmation sends nothing');
    $page = request($boss, 'admin/order_view.php?id=501');
    ok(str_contains($page['body'], 'Zwroty PayU') && str_contains($page['body'], '49,90 PLN') && str_contains($page['body'], 'do zwrotu zostało 70,10 PLN'), 'Order page lists the refund and what is left');

    $vi = login('vi');
    $page = request($vi, 'admin/order_view.php?id=501');
    ok(str_contains($page['body'], 'WZGF5FFDRJ140731GUEST000P01') && !str_contains($page['body'], 'payu_refund.php'), 'Viewer sees payment data without refund button');
    ok(request($vi, "admin/payu_refund.php?order=501&payment=$pid")['status'] === 403 && count($refundsLog()) === 1, 'Viewer cannot open the refund page');

    $warnings = preg_grep('/PHP (Warning|Fatal|Parse|Deprecated|Notice)/', file("$temp/server.log") ?: []);
    ok($warnings === [], 'No PHP warnings' . ($warnings ? ': ' . implode(' | ', array_map('trim', $warnings)) : ''));
    echo "PASS: {$checks} PayU order payment HTTP checks\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (isset($payuProc) && is_resource($payuProc)) { proc_terminate($payuProc); proc_close($payuProc); }
    exec('rm -rf ' . escapeshellarg($temp));
}
