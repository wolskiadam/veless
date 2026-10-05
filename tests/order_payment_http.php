<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Ręczne potwierdzenie wpłaty przez HTTP („Zapłacono: X z Y PLN” na stronie zamówienia): zapis kwoty,
// szybkie przyciski, filtr „Nieopłacone”, scalone zamówienia, uprawnienia. Prawdziwe strony panelu, baza SQLite.
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

$temp = sys_get_temp_dir() . '/crm-payment-http-' . bin2hex(random_bytes(6));
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
    $pdo->exec("INSERT INTO order_statuses (status_key,label,color,position) VALUES ('new','Nowe','#8a8f98',1),('processing','W realizacji','#c28a2b',2),('shipped','Wysłane','#2f8f5b',3)");
    $pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT, is_active INTEGER, config TEXT)');
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
        'status' => 'on-hold', 'payment_method' => 'blik', 'payment_method_title' => 'Blik', 'date_paid' => null, 'total' => (string) $total,
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

    $boss = login('boss');
    $page = request($boss, 'admin/order_view.php?id=501');
    ok($page['status'] === 200 && str_contains($page['body'], 'Nieopłacone') && str_contains($page['body'], 'id="paidEditBtn"'), 'Unpaid order shows the payment edit pencil');
    ok(str_contains($page['body'], 'data-amount="114.80"') && str_contains($page['body'], 'data-amount="0.00"'), 'Quick buttons offer the full amount and 0.00');
    $unpaidList = request($boss, 'admin/index.php?unpaid=1');
    ok(str_contains($unpaidList['body'], 'order_view.php?id=501'), 'Unpaid filter lists the order before payment');

    $r = request($boss, 'admin/order_view.php?id=501', ['csrf' => csrfOf($page['body']), 'action' => 'set_paid_amount', 'paid_amount' => '114,80']);
    ok(in_array($r['status'], [302, 303], true) && $pdo->query('SELECT paid_amount FROM woo_orders WHERE woo_order_id = 501')->fetchColumn() == 114.8, 'Full payment is saved (comma accepted)');
    $page = request($boss, 'admin/order_view.php?id=501');
    ok(str_contains($page['body'], 'Zapisano wpłatę 114.80') && str_contains($page['body'], 'potwierdzone ręcznie w CRM') && str_contains($page['body'], 'paid-amount paid-ok'), 'Order page shows it as paid manually');
    ok(json_decode((string) $pdo->query('SELECT payload FROM woo_orders WHERE woo_order_id = 501')->fetchColumn(), true)['date_paid'] === null, 'Shop data is untouched');
    $unpaidList = request($boss, 'admin/index.php?unpaid=1');
    ok(!str_contains($unpaidList['body'], 'order_view.php?id=501'), 'Paid order leaves the unpaid filter');

    $r = request($boss, 'admin/order_view.php?id=501', ['csrf' => csrfOf($page['body']), 'action' => 'set_paid_amount', 'paid_amount' => 'abc']);
    $page = request($boss, 'admin/order_view.php?id=501');
    ok(str_contains($page['body'], 'Podaj kwotę wpłaty') && $pdo->query('SELECT paid_amount FROM woo_orders WHERE woo_order_id = 501')->fetchColumn() == 114.8, 'Invalid amount is refused and nothing changes');

    $r = request($boss, 'admin/order_view.php?id=501', ['csrf' => csrfOf($page['body']), 'action' => 'set_paid_amount', 'paid_amount' => '50']);
    $page = request($boss, 'admin/order_view.php?id=501');
    ok(str_contains($page['body'], 'Opłacone częściowo') && str_contains($page['body'], 'paid-amount paid-warn'), 'Partial payment is shown as partial');

    $viewer = login('vi');
    $vpage = request($viewer, 'admin/order_view.php?id=501');
    ok(!str_contains($vpage['body'], 'id="paidEditBtn"'), 'Viewer does not get the pencil');
    request($viewer, 'admin/order_view.php?id=501', ['csrf' => csrfOf($vpage['body']), 'action' => 'set_paid_amount', 'paid_amount' => '0']);
    ok($pdo->query('SELECT paid_amount FROM woo_orders WHERE woo_order_id = 501')->fetchColumn() == 50, 'Viewer cannot change the payment');

    // Scalone: kwota na głównym obejmuje paczkę, dołączone odsyła do głównego.
    $pdo->exec("UPDATE woo_orders SET merged_into = 501, merged_at = '2026-09-21 12:00:00' WHERE woo_order_id = 502");
    $page = request($boss, 'admin/order_view.php?id=501');
    ok(str_contains($page['body'], 'data-amount="169.70"') && str_contains($page['body'], 'razem ze scalonymi'), 'Merged main order offers the parcel total');
    $page = request($boss, 'admin/order_view.php?id=502');
    ok(!str_contains($page['body'], 'id="paidEditBtn"') && str_contains($page['body'], 'Wpłatę za paczkę zaznaczasz na zamówieniu głównym'), 'Absorbed order points to the main order for payment');
    $page = request($boss, 'admin/order_view.php?id=501');
    request($boss, 'admin/order_view.php?id=501', ['csrf' => csrfOf($page['body']), 'action' => 'set_paid_amount', 'paid_amount' => '169.70']);
    $page = request($boss, 'admin/order_view.php?id=501');
    ok(str_contains($page['body'], 'paid-amount paid-ok'), 'Paying the parcel total marks the merged order paid');

    request($boss, 'admin/order_view.php?id=501', ['csrf' => csrfOf($page['body']), 'action' => 'set_paid_amount', 'paid_reset' => '1', 'paid_amount' => '169.70']);
    $page = request($boss, 'admin/order_view.php?id=501');
    ok($pdo->query('SELECT paid_amount FROM woo_orders WHERE woo_order_id = 501')->fetchColumn() === null && str_contains($page['body'], 'Nieopłacone'), 'Reset goes back to the shop status');
    $history = request($boss, 'admin/order_history.php?id=501');
    ok(str_contains($history['body'], 'Potwierdzenie wpłaty'), 'Payment changes are in the order history');

    echo "\nAll {$checks} order payment HTTP checks passed.\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (getenv('KEEP_TEMP')) { echo "temp: $temp\n"; } else { exec('rm -rf ' . escapeshellarg($temp)); }
}
