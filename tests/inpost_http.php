<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// InPost (ShipX) przez HTTP: dodanie konta (token zapisany zaszyfrowany), zakładka „Nadaj przez" na karcie zamówienia,
// Paczkomat z zamówienia i wyszukiwarka, nadanie asynchroniczne (oczekuje -> numer po odświeżeniu karty), etykieta
// i anulowanie. Prawdziwe strony, baza SQLite, atrapa API ShipX.
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
        CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '', CURLOPT_HEADER => true]);
    return $h;
}
function request(CurlHandle $h, string $path, ?array $data = null): array {
    global $base;
    curl_setopt($h, CURLOPT_URL, $base . $path);
    curl_setopt($h, CURLOPT_POST, $data !== null);
    if ($data !== null) { curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($data)); }
    $res = curl_exec($h);
    if ($res === false) { throw new RuntimeException(curl_error($h)); }
    $len = curl_getinfo($h, CURLINFO_HEADER_SIZE);
    $body = substr($res, $len);
    return ['status' => curl_getinfo($h, CURLINFO_RESPONSE_CODE), 'headers' => substr($res, 0, $len), 'body' => $body, 'json' => json_decode($body, true)];
}
function login(string $user): CurlHandle {
    $h = client();
    $page = request($h, 'admin/login.php');
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $page['body'], $m);
    $r = request($h, 'admin/login.php', ['csrf' => $m[1] ?? '', 'username' => $user, 'password' => 'Only-Test-Password-42']);
    if (!in_array($r['status'], [302, 303], true)) { throw new RuntimeException('Login failed for ' . $user); }
    return $h;
}

$temp = sys_get_temp_dir() . '/crm-inpost-http-' . bin2hex(random_bytes(6));
foreach (['public/admin/assets', 'config', 'storage/security', 'lang', 'integrations/inpost', 'integrations/allegro', 'integrations/woocommerce'] as $d) { mkdir("$temp/$d", 0700, true); }
$process = null;
try {
    file_put_contents("$temp/.env", "TOTP_REQUIRE_ADMIN=0\n");
    file_put_contents("$temp/storage/security/totp.key", base64_encode(random_bytes(32)));
    foreach (glob("$root/public/admin/*.php") as $f) { if (!in_array(basename($f), ['bootstrap_admin.php', 'db_admin.php'], true)) { copy($f, "$temp/public/admin/" . basename($f)); } }
    copy("$root/public/webhook_plugin.php", "$temp/public/webhook_plugin.php");
    foreach (glob("$root/public/admin/assets/*.js") as $f) { copy($f, "$temp/public/admin/assets/" . basename($f)); }
    foreach (['inpost', 'allegro', 'woocommerce'] as $p) { foreach (glob("$root/integrations/{$p}/*.php") as $f) { copy($f, "$temp/integrations/{$p}/" . basename($f)); } }
    foreach (glob("$root/lang/*.php") as $f) { copy($f, "$temp/lang/" . basename($f)); }
    file_put_contents("$temp/public/admin/bootstrap_admin.php", '<?php \Pase\Support\PagePermissions::migrate($pdo);');

    // Atrapa API ShipX w procesie serwera: odpowiedzi z pliku („METODA ścieżka" => [kod, treść]), wywołania do inpost-calls.log.
    $setResponses = static function (array $r) use ($temp): void { file_put_contents("$temp/inpost-responses.json", json_encode($r)); };
    $created = ['id' => 9001, 'status' => 'created', 'tracking_number' => null, 'service' => 'inpost_locker_standard', 'offers' => []];
    $confirmed = ['id' => 9001, 'status' => 'confirmed', 'tracking_number' => '620000111222333444555666', 'service' => 'inpost_locker_standard',
        'custom_attributes' => ['target_point' => 'KRA010', 'sending_method' => 'parcel_locker'], 'selected_offer' => ['rate' => 13.99]];
    $responses = [
        'GET /v1/organizations/4321'           => [200, json_encode(['id' => 4321, 'name' => 'Sklep Testowy', 'services' => ['inpost_locker_standard']])],
        'POST /v1/organizations/4321/shipments' => [201, json_encode($created)],
        'GET /v1/shipments/9001'               => [200, json_encode($created)],
        'GET /v1/shipments/9001/label'         => [200, '%PDF-1.4 etykieta InPost'],
        'DELETE /v1/shipments/9001'            => [204, ''],
        'GET /v1/points'                       => [200, json_encode(['items' => [['name' => 'KRA010', 'location_description' => 'Przy Żabce',
            'address_details' => ['street' => 'Floriańska', 'building_number' => '1', 'post_code' => '31-019', 'city' => 'Kraków']]]])],
    ];
    $setResponses($responses);
    file_put_contents("$temp/inpost-mock.php", '<?php \\PasePlugin\\Inpost\\InpostPlugin::$sleep = static function (int $ms): void {};'
        . ' \\PasePlugin\\Inpost\\InpostClient::$transport = static function (string $method, string $url, array $headers, ?string $body): array {'
        . ' $key = $method . " " . parse_url($url, PHP_URL_PATH);'
        . ' file_put_contents(PASE_ROOT . "/inpost-calls.log", json_encode(["key" => $key, "url" => $url, "auth" => $headers["Authorization"] ?? "", "body" => $body]) . "\\n", FILE_APPEND);'
        . ' $r = json_decode((string) file_get_contents(PASE_ROOT . "/inpost-responses.json"), true);'
        . ' if (!isset($r[$key])) { throw new RuntimeException("no mock for " . $key); } return $r[$key]; };');

    $boot = '<?php if (!defined("PASE_ROOT")) { define("PASE_ROOT", ' . var_export($temp, true) . ');'
        . 'spl_autoload_register(static function($c) { if (str_starts_with($c, "Pase\\\\")) require ' . var_export("$root/src/", true)
        . ' . str_replace("\\\\", "/", substr($c, 5)) . ".php";'
        . ' if (str_starts_with($c, "PasePlugin\\\\")) { $p = explode("\\\\", substr($c, 11)); $p[0] = strtolower($p[0]); $f = PASE_ROOT . "/integrations/" . implode("/", $p) . ".php"; if (is_file($f)) require $f; } });'
        . '\Pase\Support\Env::load(PASE_ROOT . "/.env");'
        . 'require PASE_ROOT . "/inpost-mock.php";'
        . 'if (!function_exists("t")) { function t($k, $v = []) { return \Pase\Support\I18n::t($k, $v); } } }'
        . 'return ["db" => [], "allegro" => []];';
    file_put_contents("$temp/config/config.php", $boot);
    file_put_contents("$temp/config/database.php", '<?php return static function ($db) { $pdo = new PDO("sqlite:" . PASE_ROOT . "/test.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); \\Pase\\Support\\Runtime::setPdo($pdo); return $pdo; };');
    file_put_contents("$temp/public/admin/db_admin.php", '<?php $config = require dirname(__DIR__, 2) . "/config/config.php"; $pdo = (require PASE_ROOT . "/config/database.php")($config["db"]);');

    $pdo = new PDO("sqlite:$temp/test.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT, role TEXT, password_hash TEXT, is_active INTEGER, session_version INTEGER, totp_secret TEXT, totp_last_step INTEGER DEFAULT -1, totp_recovery_hashes TEXT, last_login TEXT, totp_required INTEGER NOT NULL DEFAULT 0, email TEXT)');
    $ins = $pdo->prepare('INSERT INTO admin_users (id,username,display_name,role,password_hash,is_active,session_version) VALUES (?,?,?,?,?,1,1)');
    foreach ([1 => ['boss', 'Adam', 'admin'], 3 => ['vi', 'Wiktor', 'viewer']] as $id => [$u, $n, $role]) {
        $ins->execute([$id, $u, $n, $role, password_hash('Only-Test-Password-42', PASSWORD_DEFAULT)]);
    }
    PagePermissions::migrate($pdo);
    \Pase\Services\AuditTrail::migrate($pdo);
    $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
    $pdo->exec('CREATE TABLE integrations (id INTEGER PRIMARY KEY, platform_name TEXT, webhook_secret TEXT, access_token TEXT, refresh_token TEXT, token_expires_at TEXT, meta TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE order_statuses (id INTEGER PRIMARY KEY, status_key TEXT, label TEXT, color TEXT, position INTEGER)');
    $pdo->exec("INSERT INTO order_statuses (status_key,label,color,position) VALUES ('new','Nowe','#8a8f98',1),('processing','W realizacji','#c28a2b',2)");
    $pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT, is_active INTEGER, config TEXT, webhook_secret TEXT)');
    $pdo->exec("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES (1, 'woocommerce', 'Mój Sklep', 1, '{}')");
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, pase_stock INTEGER, pase_price REAL, images TEXT, ean TEXT)');
    $pdo->exec("CREATE TABLE woo_orders (id INTEGER PRIMARY KEY, woo_order_id INTEGER UNIQUE, integration_id INTEGER, pase_number INTEGER, order_number TEXT, status TEXT,
        pase_status TEXT, currency TEXT, total REAL, customer_name TEXT, customer_email TEXT, date_created TEXT, imported_at TEXT, updated_at TEXT,
        lifecycle TEXT DEFAULT 'active', payload TEXT, local_items TEXT NULL, local_edited_at TEXT NULL, local_billing_email TEXT NULL,
        local_billing_phone TEXT NULL, local_invoice TEXT NULL, local_pickup_point TEXT NULL, forwarded_to TEXT NULL, client_token TEXT NULL,
        automations_done INTEGER DEFAULT 0, internal_status TEXT NULL)");
    $pdo->exec('CREATE TABLE shipments (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, courier_code TEXT, waybill_no TEXT, bl_order_id TEXT,
        order_ref TEXT, price REAL, status TEXT, label_link TEXT, request_payload TEXT, response_payload TEXT, created_at TEXT, updated_at TEXT,
        tracking_status TEXT, tracking_code TEXT, tracking_carrier TEXT, tracking_at TEXT, tracking_checked_at TEXT, tracking_events TEXT)');
    $pdo->exec("CREATE TABLE order_messages (id INTEGER PRIMARY KEY, woo_order_id INTEGER, sender TEXT, author_name TEXT, body TEXT,
        attachment_path TEXT, attachment_name TEXT, is_read INTEGER DEFAULT 0, created_at TEXT)");
    $pdo->exec("CREATE TABLE order_documents (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, provider TEXT, document_type TEXT,
        remote_id TEXT, status TEXT DEFAULT 'issued', message TEXT, created_at TEXT, updated_at TEXT)");
    $pdo->exec('CREATE TABLE automation_rules (id INTEGER PRIMARY KEY, name TEXT, event TEXT, conditions TEXT, actions TEXT, is_active INTEGER, priority INTEGER, created_at TEXT, updated_at TEXT)');
    foreach ([\Pase\Services\OrderReturns::class, \Pase\Services\AllegroFeedback::class, \Pase\Services\Customers::class,
        \Pase\Services\DocumentIssueGuard::class, \Pase\Services\OrderMerge::class, \Pase\Services\OrderPayment::class] as $svc) { $svc::migrate($pdo); }
    $payload = json_encode([
        'status' => 'processing', 'payment_method' => 'blik', 'payment_method_title' => 'Blik', 'total' => '64.90',
        'billing' => ['first_name' => 'Anna', 'last_name' => 'Kowalska', 'email' => 'anna@example.com', 'phone' => '+48 600 700 800', 'address_1' => 'Lipowa 1', 'postcode' => '00-001', 'city' => 'Warszawa'],
        'shipping' => ['first_name' => 'Anna', 'last_name' => 'Kowalska', 'address_1' => 'Lipowa 1', 'postcode' => '00-001', 'city' => 'Warszawa'],
        'meta_data' => [['key' => '_paczkomat_id', 'value' => 'KRA010']],
        'line_items' => [['product_id' => 1, 'sku' => 'GB-1L', 'name' => 'GINGERBREAD 1l', 'quantity' => 1, 'price' => 49.9, 'total' => '49.90']],
        'shipping_lines' => [['method_title' => 'Paczkomat InPost', 'total' => '15.00']]]);
    $pdo->prepare("INSERT INTO woo_orders (woo_order_id, integration_id, pase_number, order_number, status, pase_status, currency, total, customer_name, customer_email, date_created, imported_at, payload)
        VALUES (501, 1, 11, '501', 'processing', 'new', 'PLN', 64.9, 'Anna Kowalska', 'anna@example.com', '2026-10-07 10:00:00', '2026-10-07 10:01:00', ?)")->execute([$payload]);

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes);
    fclose($pipes[0]);
    for ($i = 0; $i < 100; ++$i) { $p = @stream_socket_client('tcp://' . $address, $e1, $e2, 0.1); if ($p) { fclose($p); break; } usleep(10000); }
    $calls = static fn(): array => array_map(static fn($l) => json_decode($l, true), array_filter(explode("\n", (string) @file_get_contents("$temp/inpost-calls.log"))));
    $lastCall = static function () use ($calls): array { $all = $calls(); return $all[count($all) - 1]; };

    $boss = login('boss');

    // --- dodanie konta InPost w Integracjach ---
    $form = request($boss, 'admin/integration_edit.php?type=inpost');
    ok($form['status'] === 200 && str_contains($form['body'], 'name="cfg_api_token"') && str_contains($form['body'], 'name="cfg_organization_id"'), 'Integration form shows token and organization fields');
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $form['body'], $m);
    $add = request($boss, 'admin/integration_edit.php?type=inpost', ['csrf' => $m[1], 'type' => 'inpost', 'name' => 'InPost', 'is_active' => '1',
        'cfg_api_token' => 'TOKEN-SECRET-123', 'cfg_organization_id' => '4321', 'cfg_env' => 'production', 'cfg_default_service' => 'locker_auto',
        'cfg_handover' => 'parcel_locker', 'cfg_dispatch_auto' => '0', 'cfg_cod' => '1', 'cfg_label_format' => 'PDF', 'cfg_pdf_type' => 'A6', 'cfg_price_small' => '12.99']);
    $accId = (int) $pdo->query("SELECT id FROM integration_accounts WHERE type = 'inpost'")->fetchColumn();
    $rawCfg = (string) $pdo->query("SELECT config FROM integration_accounts WHERE id = {$accId}")->fetchColumn();
    ok($accId > 0 && str_contains($rawCfg, '"organization_id":"4321"'), 'InPost account saved');
    ok(!str_contains($rawCfg, 'TOKEN-SECRET-123'), 'API token is stored encrypted, not in plain text');
    $test = request($boss, "admin/integration_edit.php?id={$accId}&action=test");
    ok(str_contains($test['body'], 'Połączono z InPost ShipX') && str_contains($test['body'], 'Sklep Testowy'), 'Test connection button works');
    ok($lastCall()['auth'] === 'Bearer TOKEN-SECRET-123', 'Decrypted token sent as Bearer');

    // --- karta zamówienia ---
    $page = request($boss, 'admin/order_view.php?id=501');
    ok($page['status'] === 200 && str_contains($page['body'], 'data-integration="' . $accId . '"') && str_contains($page['body'], 'Nadaj przez InPost'), 'Order page offers InPost in „Nadaj przez"');
    preg_match('/var CSRF = "([a-f0-9]+)"/', $page['body'], $m);
    $csrf = $m[1] ?? '';
    $post = static fn(array $data) => request($boss, 'admin/courier_shipment.php', $data + ['csrf' => $csrf, 'integration' => $accId, 'woo_order_id' => 501])['json'];

    $n = count($calls());
    $sv = $post(['action' => 'services']);
    ok($sv['ok'] && $sv['services']['default'] === 'locker_auto' && $sv['services']['point']['value'] === 'KRA010', 'Services: Paczkomat from the WooCommerce order');
    ok(count($calls()) === $n, 'Opening the form does not call ShipX');
    $pts = $post(['action' => 'points', 'q' => 'Kraków']);
    ok($pts['ok'] && $pts['points'][0]['code'] === 'KRA010' && str_contains($pts['points'][0]['label'], 'Floriańska 1'), 'Paczkomat search by city');
    $q = $post(['action' => 'quote', 'service' => 'locker_auto', 'weight' => 1, 'side_x' => 30, 'side_y' => 20, 'side_z' => 5]);
    ok($q['ok'] && abs($q['price'] - 12.99) < 0.001, 'Quote from the price in settings');

    $send = $post(['action' => 'send', 'service' => 'locker_auto', 'weight' => 1, 'side_x' => 30, 'side_y' => 20, 'side_z' => 5,
        'label_format' => 'PDF', 'handover' => 'parcel_locker', 'point' => 'KRA010', 'service_label' => 'Paczkomat']);
    ok($send['ok'] && $send['pending'] === true, 'Shipment created, waiting for the number from InPost');
    $sh = $pdo->query('SELECT * FROM shipments')->fetch(PDO::FETCH_ASSOC);
    ok($sh['status'] === 'pending' && $sh['bl_order_id'] === '9001' && abs((float) $sh['price'] - 12.99) < 0.001, 'Pending shipment saved with ShipX id and cost');
    $sent = json_decode((string) array_values(array_filter($calls(), fn($c) => $c['key'] === 'POST /v1/organizations/4321/shipments'))[0]['body'], true);
    ok($sent['custom_attributes']['target_point'] === 'KRA010' && $sent['parcels'] === [['template' => 'small']] && $sent['receiver']['phone'] === '600700800', 'ShipX got the Paczkomat, size and receiver phone');

    $responses['GET /v1/shipments/9001'] = [200, json_encode($confirmed)];
    $setResponses($responses);
    $page = request($boss, 'admin/order_view.php?id=501');
    $sh = $pdo->query('SELECT * FROM shipments')->fetch(PDO::FETCH_ASSOC);
    ok($sh['status'] === 'created' && $sh['waybill_no'] === '620000111222333444555666' && $sh['courier_code'] === 'InPost Paczkomat', 'Reopening the order finishes the shipment with its number');
    ok(str_contains($page['body'], '620000111222333444555666'), 'Order page shows the InPost number');

    $lbl = request($boss, 'admin/label_download.php?shipment=' . $sh['id'] . '&printer=A4');
    ok($lbl['status'] === 200 && str_starts_with($lbl['body'], '%PDF') && str_contains($lbl['headers'], 'application/pdf'), 'Label downloads as PDF');
    ok(str_contains($lastCall()['url'], '/v1/shipments/9001/label?format=Pdf&type=A6'), 'Label asked as PDF A6');

    $cancel = request($boss, 'admin/shipment_cancel.php', ['csrf' => $csrf, 'shipment' => $sh['id']])['json'];
    ok($cancel['ok'] && $pdo->query('SELECT status FROM shipments WHERE id = ' . (int) $sh['id'])->fetchColumn() === 'cancelled', 'Cancel through InPost');
    ok($lastCall()['key'] === 'DELETE /v1/shipments/9001', 'Cancel called the API');

    echo "\nAll {$checks} InPost HTTP checks passed.\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (getenv('KEEP_TEMP')) { echo "temp: $temp\n"; } else { exec('rm -rf ' . escapeshellarg($temp)); }
}
