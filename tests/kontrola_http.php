<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Rozszerzenie Kontrola stanu przez HTTP: strona System → Kontrola stanu z extensions/kontrola na prawdziwym panelu,
// „Sprawdź teraz” (test połączeń, w tym sklepu na udawanym serwerze WooCommerce, stany, zamówienia, paczki), naprawa
// „Ponów zadania z błędem”, uprawnienia ról, filtr ?ids= na liście zamówień i sprawdzenie KSeF przez hak health.checks.
// Z SHOTS=katalog i NODE_PATH zapisuje zrzuty (tests/kontrola_ui.js). Baza SQLite.
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

$temp = sys_get_temp_dir() . '/crm-kontrola-http-' . bin2hex(random_bytes(6));
foreach (['public/admin/assets', 'public/pack/assets', 'config', 'storage/security', 'storage/logs', 'lang', 'extensions', 'integrations', 'fake-woo'] as $d) { mkdir("$temp/$d", 0700, true); }
$process = null;
$fake = null;
try {
    file_put_contents("$temp/.env", "TOTP_REQUIRE_ADMIN=0\nTOTP_KEY_FILE=$temp/storage/security/totp.key\n");
    file_put_contents("$temp/storage/security/totp.key", base64_encode(random_bytes(32)));
    foreach (glob("$root/public/admin/*.php") as $f) { if (!in_array(basename($f), ['bootstrap_admin.php', 'db_admin.php'], true)) { copy($f, "$temp/public/admin/" . basename($f)); } }
    copy("$root/public/admin/assets/loc-picker.js", "$temp/public/admin/assets/loc-picker.js");
    foreach (glob("$root/lang/*.php") as $f) { copy($f, "$temp/lang/" . basename($f)); }
    foreach (glob("$root/public/pack/*.php") as $f) { copy($f, "$temp/public/pack/" . basename($f)); }
    foreach (['packing.js', 'packing.css'] as $f) { copy("$root/public/pack/assets/$f", "$temp/public/pack/assets/$f"); }
    // Rozszerzenia jak przez FTP; wtyczki integracji sklepu i InPost (prawdziwy test połączenia).
    foreach (['kontrola', 'ksef'] as $x) { exec('cp -r ' . escapeshellarg("$root/extensions/$x") . ' ' . escapeshellarg("$temp/extensions/$x")); }
    foreach (['woocommerce', 'inpost'] as $x) { exec('cp -r ' . escapeshellarg("$root/integrations/$x") . ' ' . escapeshellarg("$temp/integrations/$x")); }
    file_put_contents("$temp/public/admin/bootstrap_admin.php", '<?php \Pase\Support\PagePermissions::migrate($pdo);');

    $boot = '<?php if (!defined("PASE_ROOT")) { define("PASE_ROOT", ' . var_export($temp, true) . ');'
        . 'spl_autoload_register(static function($c) { if (str_starts_with($c, "Pase\\\\")) require ' . var_export("$root/src/", true)
        . ' . str_replace("\\\\", "/", substr($c, 5)) . ".php";'
        . ' if (str_starts_with($c, "PasePlugin\\\\")) { $p = explode("\\\\", substr($c, 11)); $p[0] = strtolower($p[0]); $f = PASE_ROOT . "/integrations/" . implode("/", $p) . ".php"; if (is_file($f)) require $f; } });'
        . '\Pase\Support\Env::load(PASE_ROOT . "/.env");'
        . 'if (!function_exists("t")) { function t($k, $v = []) { return \Pase\Support\I18n::t($k, $v); } } }'
        . 'return ["db" => [], "allegro" => []];';
    file_put_contents("$temp/config/config.php", $boot);
    file_put_contents("$temp/config/database.php", '<?php return static function ($db) { $pdo = new PDO("sqlite:" . PASE_ROOT . "/test.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); \\Pase\\Support\\Runtime::setPdo($pdo); return $pdo; };');
    file_put_contents("$temp/public/admin/db_admin.php", '<?php $config = require dirname(__DIR__, 2) . "/config/config.php"; $pdo = (require PASE_ROOT . "/config/database.php")($config["db"]);');

    // Udawany sklep WooCommerce: zamówienia (test połączenia), produkty po ID i warianty.
    file_put_contents("$temp/fake-woo/router.php", '<?php
        header("Content-Type: application/json");
        $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
        if (($_GET["consumer_key"] ?? "") !== "ck_test") { http_response_code(401); echo "{}"; return; }
        if ($path === "/wp-json/wc/v3/orders") { echo "[]"; return; }
        if ($path === "/wp-json/wc/v3/products") { echo json_encode([["id" => 101, "manage_stock" => true, "stock_quantity" => 2], ["id" => 102, "manage_stock" => true, "stock_quantity" => 3],
            ["id" => 103, "manage_stock" => false, "stock_quantity" => null]]); return; }
        if ($path === "/wp-json/wc/v3/products/200/variations") { echo json_encode([["id" => 201, "manage_stock" => true, "stock_quantity" => 7]]); return; }
        http_response_code(404); echo "{}";');
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $fakeAddr = stream_socket_get_name($sock, false); fclose($sock);
    $fake = proc_open([PHP_BINARY, '-S', $fakeAddr, "$temp/fake-woo/router.php"], [0 => ['pipe', 'r'], 1 => ['file', "$temp/fake.log", 'a'], 2 => ['file', "$temp/fake.log", 'a']], $fp);

    $pdo = new PDO("sqlite:$temp/test.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT, role TEXT, password_hash TEXT, is_active INTEGER, session_version INTEGER, totp_secret TEXT, totp_last_step INTEGER DEFAULT -1, totp_recovery_hashes TEXT, last_login TEXT, totp_required INTEGER NOT NULL DEFAULT 0, email TEXT)');
    $ins = $pdo->prepare('INSERT INTO admin_users (id,username,display_name,role,password_hash,is_active,session_version) VALUES (?,?,?,?,?,1,1)');
    foreach ([1 => ['boss', 'Adam', 'admin'], 2 => ['ola', 'Ola', 'editor'], 3 => ['vi', 'Wiktor', 'viewer']] as $id => [$u, $n, $role]) {
        $ins->execute([$id, $u, $n, $role, password_hash('Only-Test-Password-42', PASSWORD_DEFAULT)]);
    }
    PagePermissions::migrate($pdo);
    \Pase\Services\AuditTrail::migrate($pdo);
    $now = time();
    $ago = static fn(int $sec): string => gmdate('Y-m-d H:i:s', $now - $sec);
    $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE order_statuses (id INTEGER PRIMARY KEY, status_key TEXT, label TEXT, color TEXT, position INTEGER)');
    $pdo->exec("INSERT INTO order_statuses (status_key,label,color,position) VALUES ('new','Nowe','#8a8f98',1),('processing','W realizacji','#c28a2b',2),('shipped','Wysłane','#2f8f5b',3)");
    $pdo->exec('CREATE TABLE integrations (id INTEGER PRIMARY KEY, platform_name TEXT, access_token TEXT, refresh_token TEXT, expires_at TEXT, webhook_secret TEXT, meta TEXT)');
    $pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT, is_active INTEGER, config TEXT, webhook_secret TEXT, access_token TEXT, refresh_token TEXT, expires_at TEXT, created_at TEXT, updated_at TEXT)');
    $pdo->prepare("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES (1, 'woocommerce', 'Sklep Świece', 1, ?), (2, 'inpost', 'InPost', 1, '{}')")
        ->execute([json_encode(['base_url' => 'http://' . $fakeAddr, 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test'])]);
    $pdo->exec("CREATE TABLE job_queue (id INTEGER PRIMARY KEY, job_type TEXT, payload TEXT, dedup_key TEXT UNIQUE, status TEXT, attempts INTEGER DEFAULT 0, max_attempts INTEGER DEFAULT 3,
        reserved_at TEXT, reserved_token TEXT, available_at TEXT, last_error TEXT, result TEXT, created_at TEXT, updated_at TEXT)");
    $q = $pdo->prepare("INSERT INTO job_queue (job_type, payload, dedup_key, status, attempts, available_at, last_error, created_at, updated_at) VALUES (?, '{}', ?, 'failed', 3, ?, ?, ?, ?)");
    $q->execute(['woo.order.import', 'a', $ago(7200), 'WooCommerce zwróciło HTTP 500 przy pobieraniu zamówienia.', $ago(7200), $ago(3600)]);
    $q->execute(['woo.order.import', 'b', $ago(5400), 'WooCommerce zwróciło HTTP 500 przy pobieraniu zamówienia.', $ago(5400), $ago(1800)]);
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, pase_stock INTEGER, pase_price REAL, images TEXT, ean TEXT, source_integration_id INTEGER, woo_product_id INTEGER, woo_variation_id INTEGER, updated_at TEXT)');
    $pdo->exec("INSERT INTO products (id, sku, name, pase_stock, source_integration_id, woo_product_id, woo_variation_id) VALUES
        (1, 'GB-1L', 'Gingerbread 1l', 5, 1, 101, NULL), (2, 'MW-1L', 'Mulled Wine 1l', 3, 1, 102, NULL), (3, 'VN-1L', 'Vanilla 1l', 4, 1, 103, NULL), (4, 'SW-S', 'Świeca sojowa S', 7, 1, 200, 201)");
    $pdo->exec('CREATE TABLE product_mappings (id INTEGER PRIMARY KEY, sku TEXT, allegro_offer_id TEXT)');
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
    // Faktury z KSeF (tabela rozszerzenia KSeF) - włącza sprawdzenie „opłacone bez paragonu i faktury”.
    require_once "$root/extensions/ksef/Invoices.php";
    new \PaseExt\Ksef\Invoices($pdo);
    foreach ([\Pase\Services\OrderReturns::class, \Pase\Services\AllegroFeedback::class, \Pase\Services\Customers::class,
        \Pase\Services\DocumentIssueGuard::class, \Pase\Services\OrderMerge::class, \Pase\Services\OrderMargins::class,
        \Pase\Services\Packing::class] as $svc) { $svc::migrate($pdo); }
    $pdo->exec('CREATE TABLE email_templates (id INTEGER PRIMARY KEY, tpl_key TEXT, name TEXT, subject TEXT, body TEXT, is_active INTEGER, mail_account_id INTEGER NULL)');
    $pdo->exec("CREATE TABLE automation_rules (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, event TEXT, is_active INTEGER, priority INTEGER,
        conditions TEXT, actions TEXT, action_type TEXT NULL, action_params TEXT NULL)");
    $order = static fn(array $items, string $paid = '2026-11-10T10:00:00'): string => json_encode([
        'status' => 'processing', 'date_paid' => $paid, 'total' => '64.80',
        'billing' => ['first_name' => 'Anna', 'last_name' => 'Kowalska', 'email' => 'anna@example.com', 'address_1' => 'Lipowa 1', 'postcode' => '00-001', 'city' => 'Warszawa'],
        'shipping' => ['first_name' => 'Anna', 'last_name' => 'Kowalska', 'address_1' => 'Lipowa 1', 'postcode' => '00-001', 'city' => 'Warszawa'],
        'line_items' => $items, 'shipping_lines' => [['method_id' => 'flat_rate:1', 'method_title' => 'Kurier', 'total' => '15.00']]]);
    $oins = $pdo->prepare('INSERT INTO woo_orders (woo_order_id, integration_id, pase_number, order_number, status, pase_status, currency, total, customer_name, customer_email, date_created, imported_at, payload)
        VALUES (?,1,?,?,?,?,?,?,?,?,?,?,?)');
    $gb = ['product_id' => 101, 'sku' => 'GB-1L', 'name' => 'Gingerbread 1l', 'quantity' => 1, 'price' => 49.8, 'total' => '49.80'];
    $oins->execute([601, 601, '601', 'processing', 'processing', 'PLN', 64.8, 'Anna Kowalska', 'anna@example.com', $ago(86400), $ago(86400),
        $order([$gb, ['product_id' => 999, 'sku' => 'XMAS-TREE', 'name' => 'Choinka zapachowa', 'quantity' => 1, 'total' => '15.00']])]);
    $oins->execute([602, 602, '602', 'completed', 'shipped', 'PLN', 64.8, 'Jan Nowak', 'jan@example.com', $ago(3 * 86400), $ago(3 * 86400), $order([$gb])]);
    $oins->execute([603, 603, '603', 'processing', 'processing', 'PLN', 64.8, 'Ola Lis', 'ola@example.com', $ago(4 * 86400), $ago(4 * 86400), $order([$gb])]);
    $oins->execute([604, 604, '604', 'completed', 'shipped', 'PLN', 64.8, 'Ewa Kot', 'ewa@example.com', $ago(5 * 86400), $ago(5 * 86400), $order([$gb])]);
    foreach ([601, 602, 604] as $oid) {
        $pdo->prepare("INSERT INTO ext_ksef_invoices (woo_order_id, env, status, number, issue_date, gross, created_at, updated_at) VALUES (?, 'prod', 'accepted', ?, '2026-11-10', 64.8, ?, ?)")
            ->execute([$oid, 'FV/' . $oid, $ago(86400), $ago(86400)]);
    }
    $pdo->prepare("INSERT INTO shipments (woo_order_id, courier_code, waybill_no, status, created_at, tracking_status) VALUES (604, 'inpost', '682000111222333444555666', 'created', ?, 'delivered'),
        (603, 'dpd', '1000123456789U', 'created', ?, NULL)")->execute([$ago(5 * 86400), $ago(3 * 86400)]);
    $set = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)');
    foreach (['WORKER_LAST_RUN_AT' => (string) ($now - 30), 'SYNC_ORDERS_EVERY' => '5', 'SYNC_ORDERS_AT' => (string) ($now - 100),
        'SYNC_TRACKING_EVERY' => '60', 'SYNC_TRACKING_AT' => (string) ($now - 4 * 3600), 'TOKENS_REFRESH_AT' => (string) ($now - 60),
        'OPS_ALERT_EMAIL' => 'szef@example.com'] as $k => $v) { $set->execute([$k, $v]); }

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes,
        null, array_merge(getenv(), ['no_proxy' => '127.0.0.1,localhost', 'NO_PROXY' => '127.0.0.1,localhost', 'http_proxy' => '', 'HTTP_PROXY' => '']));
    fclose($pipes[0]);
    foreach ([$fakeAddr, $address] as $a) { for ($i = 0; $i < 100; ++$i) { $p = @stream_socket_client('tcp://' . $a, $e1, $e2, 0.1); if ($p) { fclose($p); break; } usleep(10000); } }

    $boss = login('boss');
    $ola = login('ola');
    $vi = login('vi');

    // ---- Wtyczka, menu, pusta strona
    $pl = request($boss, 'admin/plugins.php');
    ok(str_contains($pl['body'], 'Kontrola stanu') && str_contains($pl['body'], 'name="cfg[every]"') && str_contains($pl['body'], 'name="cfg[email]"'), 'Plugins page lists the extension with its settings');
    ok(str_contains(request($vi, 'admin/index.php')['body'], 'href="ext.php?page=kontrola.stan"'), 'Menu entry for every role');
    $pg = request($vi, 'admin/ext.php?page=kontrola.stan');
    ok($pg['status'] === 200 && str_contains($pg['body'], 'Pierwsze sprawdzenie jeszcze się nie odbyło') && !str_contains($pg['body'], 'Sprawdź teraz')
        && str_contains($pg['body'], 'Cron działa'), 'Viewer sees the page (live checks already filled) without the run button');
    ok(request($vi, 'admin/ext.php?page=kontrola.sprawdz', ['csrf' => csrfOf(request($vi, 'admin/index.php')['body'])])['status'] === 403, 'Viewer cannot run the checks');

    // ---- Sprawdź teraz
    $pg = request($ola, 'admin/ext.php?page=kontrola.stan');
    ok(str_contains($pg['body'], 'Sprawdź teraz'), 'Editor has the run button');
    $r = request($ola, 'admin/ext.php?page=kontrola.sprawdz', ['csrf' => csrfOf($pg['body'])]);
    ok($r['status'] === 302 && str_contains($r['headers'], 'page=kontrola.stan'), 'Run redirects back');
    $res = [];
    foreach ($pdo->query('SELECT check_id, state, data FROM ext_kontrola_results')->fetchAll() as $row) { $res[$row['check_id']] = [$row['state'], json_decode($row['data'], true)]; }
    ok($res['conn.acc.1'][0] === 'ok' && str_contains($res['conn.acc.1'][1]['summary'], 'Połączenie OK'), 'Shop connection tested on the fake WooCommerce');
    ok($res['conn.acc.2'][0] === 'bad' && str_contains($res['conn.acc.2'][1]['summary'], 'Uzupełnij token API ShipX'), 'InPost without a token is red');
    ok($res['conn.allegro'][0] === 'skip', 'Allegro not used is skipped');
    ok($res['conn.ksef'][0] === 'warn' && str_contains($res['conn.ksef'][1]['summary'], 'Brakuje: NIP firmy, token KSeF'), 'KSeF adds its own check through health.checks');
    $stock = $pdo->query("SELECT * FROM ext_kontrola_stock WHERE channel = 'woo:1'")->fetchAll();
    ok(count($stock) === 1 && (int) $stock[0]['product_id'] === 1 && (int) $stock[0]['remote'] === 2 && $res['data.stock_woo.1'][0] === 'ok',
        'Shop stock compared (product without stock management skipped), first difference waits for confirmation');
    ok($res['data.orders_no_product'][0] === 'warn' && $res['data.shipped_no_tracking'][0] === 'warn' && $res['data.paid_no_document'][0] === 'warn'
        && $res['ship.stale'][0] === 'warn', 'Order and shipment checks');
    $pg = request($ola, 'admin/ext.php?page=kontrola.stan');
    $b = $pg['body'];
    ok(str_contains($b, '1 problem wymaga uwagi') && str_contains($b, 'Ostatnie pełne sprawdzenie') && str_contains($b, 'uruchomił Ola')
        && str_contains($b, 'Ponów zadania z błędem') && str_contains($b, 'index.php?ids=601') && str_contains($b, 'XMAS-TREE „Choinka zapachowa”'),
        'Page shows the headline, who ran it, fix buttons and links to orders');
    ok(strpos($b, '>Połączenia</h2>') < strpos($b, '>Zadania w tle</h2>') && strpos($b, '>Zadania w tle</h2>') < strpos($b, '>Zgodność danych</h2>')
        && strpos($b, '>Zgodność danych</h2>') < strpos($b, '>Przesyłki</h2>'), 'Groups in order');
    ok(!str_contains(request($vi, 'admin/ext.php?page=kontrola.stan')['body'], 'name="fix"'), 'Viewer sees no fix buttons');

    // ---- Potwierdzona różnica stanu i wysłanie stanu z CRM
    $pdo->exec("UPDATE ext_kontrola_stock SET first_seen = '" . $ago(3600) . "'");
    request($ola, 'admin/ext.php?page=kontrola.sprawdz', ['csrf' => csrfOf($pg['body'])]);
    $b = request($ola, 'admin/ext.php?page=kontrola.stan')['body'];
    ok(str_contains($b, '1 produkt ma w sklepie inny stan niż w CRM') && str_contains($b, 'CRM 5 szt., w sklepie 2 szt.') && str_contains($b, 'Wyślij stany z CRM'), 'Confirmed stock difference with the push button');

    // ---- Naprawa: ponów zadania z błędem
    $r = request($ola, 'admin/ext.php?page=kontrola.napraw', ['csrf' => csrfOf($b), 'fix' => 'queue.retry', 'check' => 'tasks.queue_failed']);
    $b = request($ola, 'admin/ext.php?page=kontrola.stan')['body'];
    ok($r['status'] === 302 && str_contains($r['headers'], '#tasks.queue_failed') && str_contains($b, 'Ponowiono 2 zadania')
        && (int) $pdo->query("SELECT COUNT(*) FROM job_queue WHERE status = 'pending'")->fetchColumn() === 2, 'Retry fix resets failed jobs and shows a message');
    ok(request($vi, 'admin/ext.php?page=kontrola.napraw', ['csrf' => csrfOf(request($vi, 'admin/index.php')['body']), 'fix' => 'queue.retry'])['status'] === 403, 'Viewer cannot run fixes');
    $r = request($ola, 'admin/ext.php?page=kontrola.napraw', ['csrf' => csrfOf($b), 'fix' => 'nieznana']);
    ok(str_contains(request($ola, 'admin/ext.php?page=kontrola.stan')['body'], 'Nieznana naprawa'), 'Unknown fix is refused');

    // ---- Lista zamówień z ?ids=
    $l = request($ola, 'admin/index.php?ids=601,603');
    ok(str_contains($l['body'], 'Pokazuję tylko wybrane zamówienia (2)') && str_contains($l['body'], 'Anna Kowalska') && str_contains($l['body'], 'Ola Lis')
        && !str_contains($l['body'], 'Jan Nowak'), 'Orders list filtered by ids');

    // ---- Zrzuty (opcjonalnie): SHOTS=katalog NODE_PATH=... php tests/kontrola_http.php
    $pdo->exec("UPDATE ext_kontrola_results SET since = '" . $ago(2 * 3600) . "' WHERE state = 'bad'");
    $pdo->exec("UPDATE job_queue SET status = 'failed', updated_at = '" . $ago(1800) . "'");
    if (($shots = getenv('SHOTS')) && getenv('NODE_PATH')) {
        passthru('node ' . escapeshellarg(__DIR__ . '/kontrola_ui.js') . ' ' . escapeshellarg($base) . ' ' . escapeshellarg($shots), $rc);
        ok($rc === 0, 'Screenshots saved');
    }

    $warnings = preg_grep('/PHP (Warning|Fatal|Parse|Deprecated|Notice)|Rozszerzenie .* błąd/', file("$temp/server.log") ?: []);
    ok($warnings === [], 'No PHP warnings or extension errors' . ($warnings ? ': ' . implode(' | ', array_map('trim', $warnings)) : ''));
    echo "PASS: {$checks} Kontrola stanu HTTP checks\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
    if (is_file("$temp/server.log")) { echo "--- server.log ---\n", substr((string) file_get_contents("$temp/server.log"), -4000), "\n"; }
    exit(1);
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (is_resource($fake)) { proc_terminate($fake); proc_close($fake); }
    if (getenv('KEEP_TEMP')) { echo "temp: $temp\n"; } else { exec('rm -rf ' . escapeshellarg($temp)); }
}
