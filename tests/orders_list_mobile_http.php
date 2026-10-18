<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Lista zamówień na telefonie: każde zamówienie to karta ze wszystkimi danymi (numer, status, klient, e-mail, źródło,
// dostawa, ikony, kwota, data, akcje) zamiast tabeli przewijanej w bok. Prawdziwa strona, SQLite. Gdy jest Playwright
// (NODE_PATH), sprawdza układ w przeglądarce - tests/orders_list_mobile_ui.js.
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

$temp = sys_get_temp_dir() . '/crm-orders-mobile-http-' . bin2hex(random_bytes(6));
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
    $pdo->exec("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES (1, 'woocommerce', 'Klub Woskarzy', 1, '{\"base_url\":\"https://www.klubwoskarzy.pl\"}'), (2, 'allegro', 'AWIEN Sp zoo', 1, '{}')");
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
    $order = static fn(string $email, string $status, string $doc): string => json_encode([
        'status' => $status, 'payment_method' => 'blik', 'payment_method_title' => 'Blik', 'date_paid' => '2026-10-16 10:00:00',
        'billing' => ['first_name' => 'Anna', 'last_name' => 'Kowalska', 'email' => $email, 'address_1' => 'Lipowa 1', 'postcode' => '00-001', 'city' => 'Warszawa',
            'company' => $doc === 'FV' ? 'Firma' : '', 'nip' => $doc === 'FV' ? '5250000000' : ''],
        'shipping' => ['first_name' => 'Anna', 'last_name' => 'Kowalska', 'address_1' => 'Lipowa 1', 'postcode' => '00-001', 'city' => 'Warszawa'],
        'line_items' => [['product_id' => 1, 'sku' => 'GB-1L', 'name' => 'GINGERBREAD 1l', 'quantity' => 1, 'price' => 49.9, 'total' => '49.90']],
        'shipping_lines' => [['method_title' => 'Kurier', 'total' => '15.00']]]);
    $oins = $pdo->prepare('INSERT INTO woo_orders (woo_order_id, integration_id, pase_number, order_number, status, pase_status, currency, total, customer_name, customer_email, date_created, imported_at, payload)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $oins->execute([92601, 1, 1, '92601', 'completed', 'shipped', 'PLN', 29.9, 'Nataliia Dihtiar', 'NATADIGTYAR0404@GMAIL.COM', '2026-10-16 15:40:09', '2026-10-16 15:41:00', $order('NATADIGTYAR0404@GMAIL.COM', 'completed', 'PAR')]);
    $oins->execute([92600, 1, 2, '92600', 'processing', 'processing', 'PLN', 9.9, 'Magdalena Woźniak', 'mw.soyacandles@gmail.com', '2026-10-16 15:37:41', '2026-10-16 15:38:00', $order('mw.soyacandles@gmail.com', 'processing', 'FV')]);
    // Allegro: długi numer (UUID), login kupującego, bardzo długi e-mail i przesyłka InPost.
    $allegroMail = 'syjdh90hjd+2d8f04c62aa31bb7@allegromail.pl';
    $oins->execute([9000000700, 2, 3, '5319caa0-c6c8-11f1-9f5a-0b8c1d2e3f40', 'READY_FOR_PROCESSING', 'new', 'PLN', 100.92, 'Wiktoria Michalowicz-Długonazwiskowa', $allegroMail, '2026-10-16 10:36:30', '2026-10-16 10:37:00',
        json_encode(['id' => '5319caa0-c6c8-11f1-9f5a-0b8c1d2e3f40', 'status' => 'READY_FOR_PROCESSING', 'buyer' => ['login' => 'Client:119195776', 'email' => $allegroMail],
            'line_items' => [['product_id' => 2, 'sku' => 'MW-1L', 'name' => 'MULLED WINE 1l', 'quantity' => 1, 'price' => 100.92, 'total' => '100.92']],
            'billing' => ['first_name' => 'Wiktoria', 'last_name' => 'Michalowicz', 'email' => $allegroMail], 'shipping' => ['first_name' => 'Wiktoria', 'last_name' => 'Michalowicz', 'city' => 'Kraków']])]);
    $pdo->exec("INSERT INTO shipments (woo_order_id, courier_code, waybill_no, status, tracking_status, created_at)
        VALUES (9000000700, 'InPost Paczkomaty', '620999673376004676945119', 'created', 'in_transit', '2026-10-16 11:00:00')");
    // Nieprzeczytana wiadomość od klienta - karta ma pomarańczowy pasek i znaczek ✉.
    $pdo->exec("INSERT INTO order_messages (woo_order_id, sender, author_name, body, is_read, created_at) VALUES (92600, 'customer', 'Magdalena', 'Czy mogę dopisać produkt?', 0, '2026-10-16 16:00:00')");

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes);
    fclose($pipes[0]);
    for ($i = 0; $i < 100; ++$i) { $p = @stream_socket_client('tcp://' . $address, $e1, $e2, 0.1); if ($p) { fclose($p); break; } usleep(10000); }

    $boss = login('boss');
    $list = request($boss, 'admin/index.php');
    ok($list['status'] === 200 && str_contains($list['body'], 'class="card orders-list-card"') && str_contains($list['body'], '<tr class="orders-head">'), 'List card and header row are marked for the phone layout');
    foreach (['number', 'source', 'status', 'client', 'email', 'amount', 'delivery', 'date', 'actions'] as $col) {
        ok(substr_count($list['body'], '<td data-col="' . $col . '"') === 3, "Every order row has its {$col} cell marked");
    }
    ok(str_contains($list['body'], 'action="orders_sync_now.php"'), 'Order list has the "Pobierz teraz" button');
    ok(!str_contains(request(login('vi'), 'admin/index.php')['body'], 'orders_sync_now.php'), 'Viewer does not get the "Pobierz teraz" button');
    $get = request($boss, 'admin/orders_sync_now.php');
    ok($get['status'] === 302 && str_contains($get['headers'], 'Location: index.php'), 'Opening the sync address directly just goes back to the list');
    ok(request($boss, 'admin/orders_sync_now.php', ['csrf' => 'bad'])['status'] === 419, 'Sync without a valid CSRF token is refused');
    $search = request($boss, 'admin/orders_search.php?q=Wiktoria');
    ok($search['status'] === 200 && str_contains((string) ($search['json']['html'] ?? ''), '<td data-col="client"'), 'Live search returns the same marked cells');
    // Numer z maila („zamówienie #3”) znajduje zamówienie po numerze CRM - z # tylko to jedno.
    $rows = static fn(array $r): int => substr_count((string) ($r['json']['html'] ?? ''), '<td data-col="client"');
    $hash = request($boss, 'admin/orders_search.php?q=' . urlencode('#3'));
    ok($rows($hash) === 1 && str_contains((string) $hash['json']['html'], 'Wiktoria'), 'Search for #3 finds the order with CRM number 3');
    ok($rows(request($boss, 'admin/orders_search.php?q=' . urlencode('#9'))) === 0, 'Search for #9 (no such CRM number) finds nothing');
    ok(str_contains(request($boss, 'admin/index.php?q=' . urlencode('#3'))['body'], 'Wiktoria'), 'Order list page finds #3 too');

    $node = trim((string) shell_exec('command -v node'));
    $hasPw = $node !== '' && trim((string) shell_exec('NODE_PATH=' . escapeshellarg((string) getenv('NODE_PATH')) . ' ' . escapeshellarg($node)
        . ' -e "require.resolve(\'playwright\'); console.log(1)" 2>/dev/null')) === '1';
    if ($hasPw) {
        passthru('BASE=' . escapeshellarg($base) . ' NODE_PATH=' . escapeshellarg((string) getenv('NODE_PATH')) . ' ' . escapeshellarg($node) . ' ' . escapeshellarg("$root/tests/orders_list_mobile_ui.js"), $rc);
        if ($rc !== 0) { echo implode('', preg_grep('/PHP |\[5\d\d\]/', file("$temp/server.log") ?: [])); }
        ok($rc === 0, 'Browser checks passed');
    } else {
        echo "SKIP: browser checks (no Playwright; set NODE_PATH)\n";
    }
    $warnings = preg_grep('/PHP (Warning|Fatal|Parse|Deprecated)/', file("$temp/server.log") ?: []);
    ok($warnings === [], 'No PHP warnings in the server log');

    echo "\nAll {$checks} order list mobile checks passed.\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (getenv('KEEP_TEMP')) { echo "temp: $temp\n"; } else { exec('rm -rf ' . escapeshellarg($temp)); }
}
