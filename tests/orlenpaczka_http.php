<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// ORLEN Paczka przez HTTP: zakładka „Nadaj przez" na karcie zamówienia, punkt z zamówienia i wyszukiwarka punktów,
// nadanie (zapis przesyłki), etykieta, śledzenie (↻) i anulowanie. Prawdziwe strony, baza SQLite, atrapa API ORLEN Paczka.
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

$temp = sys_get_temp_dir() . '/crm-orlen-http-' . bin2hex(random_bytes(6));
foreach (['public/admin/assets', 'config', 'storage/security', 'lang', 'integrations/orlenpaczka', 'integrations/allegro'] as $d) { mkdir("$temp/$d", 0700, true); }
$process = null;
try {
    file_put_contents("$temp/.env", "TOTP_REQUIRE_ADMIN=0\n");
    foreach (glob("$root/public/admin/*.php") as $f) { if (!in_array(basename($f), ['bootstrap_admin.php', 'db_admin.php'], true)) { copy($f, "$temp/public/admin/" . basename($f)); } }
    copy("$root/public/webhook_plugin.php", "$temp/public/webhook_plugin.php");
    foreach (glob("$root/public/admin/assets/*.js") as $f) { copy($f, "$temp/public/admin/assets/" . basename($f)); }
    foreach (['orlenpaczka', 'allegro'] as $p) { foreach (glob("$root/integrations/{$p}/*.php") as $f) { copy($f, "$temp/integrations/{$p}/" . basename($f)); } }
    foreach (glob("$root/lang/*.php") as $f) { copy($f, "$temp/lang/" . basename($f)); }
    file_put_contents("$temp/public/admin/bootstrap_admin.php", '<?php \Pase\Support\PagePermissions::migrate($pdo);');

    // Atrapa API ORLEN Paczka w procesie serwera: odpowiedzi z pliku, wywołania zapisywane do orlen-calls.log.
    $label = base64_encode('%PDF-1.4 etykieta ORLEN');
    $soap = static fn(string $op, string $inner) => '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
        . "<{$op}Response xmlns=\"https://91.242.220.103/WebServicePwR\"><{$op}Result>{$inner}</{$op}Result></{$op}Response></soap:Body></soap:Envelope>";
    $pt = static fn(string $code, string $psd, string $street, string $city, string $zip) => "<LocationWithAllData2><DestinationCode>{$code}</DestinationCode>"
        . "<StreetName>{$street}</StreetName><BuildingNumber>1</BuildingNumber><City>{$city}</City><Zipcode>{$zip}</Zipcode><PSD>{$psd}</PSD><Available>T</Available><PointType>APM</PointType></LocationWithAllData2>";
    $responses = [
        'GenerateLabelBusinessPackListTwo' => $soap('GenerateLabelBusinessPackListTwo', '<Err>0</Err><ErrDes>saved</ErrDes><PackCode_RUCH>2100055566677</PackCode_RUCH><DestinationCode>WA-116263-K1-02</DestinationCode><PackPrice>1099</PackPrice><PackPaid>true</PackPaid>') . '',
        'LabelPrintDuplicateListTwo'       => str_replace('</LabelPrintDuplicateListTwoResult>', "</LabelPrintDuplicateListTwoResult><LabelData>{$label}</LabelData>",
            $soap('LabelPrintDuplicateListTwo', '<LabelPrintDuplicateListTwo><PackCode>2100055566677</PackCode><Err>0</Err><ErrDes>saved</ErrDes></LabelPrintDuplicateListTwo>')),
        'PutCustomerPackCanceled'          => $soap('PutCustomerPackCanceled', '<Err>000</Err><ErrDes>saved</ErrDes><PackCode>2100055566677</PackCode>'),
        'GiveMePackStatusFullHistoryList'  => $soap('GiveMePackStatusFullHistoryList', '<PackStatus><PackCode>2100055566677</PackCode><Trans>200</Trans><Trans_Des>Zaawizowana do PwR</Trans_Des><Data>2026-10-08T10:00:00Z</Data><Attribute /></PackStatus>'
            . '<PackStatus><PackCode>2100055566677</PackCode><Trans>240</Trans><Trans_Des>W transporcie do ekspedycji u kuriera</Trans_Des><Data>2026-10-08T15:30:00Z</Data><Attribute /></PackStatus>'),
        'WebhookRegister'   => $soap('WebhookRegister', '<Err>0</Err><ErrDes /><Data><Status>Active</Status><Annotation>Webhook registered and activated</Annotation></Data>'),
        'WebhookStatus'     => $soap('WebhookStatus', '<Err>0</Err><ErrDes /><Data><Status>Active</Status><Annotation>Webhook registered and activated</Annotation><NotificationUrl>x</NotificationUrl></Data>'),
        'WebhookUnregister' => $soap('WebhookUnregister', '<Err>0</Err><ErrDes /><Data><Status>Unregistered</Status><Annotation>Webhook unregistered</Annotation></Data>'),
        'GiveMeAllLocationWithAllDataWithZipCode' => $soap('GiveMeAllLocationWithAllDataWithZipCode',
            $pt('WA-116263-K1-02', '116263', 'MARSZAŁKOWSKA', 'Warszawa', '00-950') . $pt('KR-300100-A1-01', '300100', 'FLORIAŃSKA', 'Kraków', '31-019')),
    ];
    file_put_contents("$temp/orlen-responses.json", json_encode($responses));
    file_put_contents("$temp/orlen-mock.php", '<?php \PasePlugin\Orlenpaczka\OrlenPaczkaClient::$transport = static function (string $op, string $xml): string {'
        . ' file_put_contents(PASE_ROOT . "/orlen-calls.log", json_encode(["op" => $op, "xml" => $xml]) . "\n", FILE_APPEND);'
        . ' $r = json_decode((string) file_get_contents(PASE_ROOT . "/orlen-responses.json"), true);'
        . ' if (!isset($r[$op])) { throw new RuntimeException("no mock for " . $op); } return $r[$op]; };');

    $boot = '<?php if (!defined("PASE_ROOT")) { define("PASE_ROOT", ' . var_export($temp, true) . ');'
        . 'spl_autoload_register(static function($c) { if (str_starts_with($c, "Pase\\\\")) require ' . var_export("$root/src/", true)
        . ' . str_replace("\\\\", "/", substr($c, 5)) . ".php";'
        . ' if (str_starts_with($c, "PasePlugin\\\\")) { $p = explode("\\\\", substr($c, 11)); $p[0] = strtolower($p[0]); $f = PASE_ROOT . "/integrations/" . implode("/", $p) . ".php"; if (is_file($f)) require $f; } });'
        . '\Pase\Support\Env::load(PASE_ROOT . "/.env");'
        . 'require PASE_ROOT . "/orlen-mock.php";'
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
    $orlenCfg = ['partner_id' => 'PARTNER01', 'partner_key' => 'TajneHaslo', 'env' => 'production', 'sender_company' => 'Sklep Testowy',
        'sender_street' => 'Testowa', 'sender_building' => '1', 'sender_postcode' => '00-001', 'sender_city' => 'Warszawa',
        'sender_phone' => '444555666', 'sender_email' => 'sklep@example.com'];
    $pdo->prepare("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES (1, 'woocommerce', 'Mój Sklep', 1, '{}'), (2, 'orlenpaczka', 'ORLEN Paczka', 1, ?)")
        ->execute([json_encode($orlenCfg)]);
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
        'meta_data' => [['key' => '_orlen_point', 'value' => '116263']],
        'line_items' => [['product_id' => 1, 'sku' => 'GB-1L', 'name' => 'GINGERBREAD 1l', 'quantity' => 1, 'price' => 49.9, 'total' => '49.90']],
        'shipping_lines' => [['method_title' => 'ORLEN Paczka', 'total' => '15.00']]]);
    $pdo->prepare("INSERT INTO woo_orders (woo_order_id, integration_id, pase_number, order_number, status, pase_status, currency, total, customer_name, customer_email, date_created, imported_at, payload)
        VALUES (501, 1, 11, '501', 'processing', 'new', 'PLN', 64.9, 'Anna Kowalska', 'anna@example.com', '2026-10-07 10:00:00', '2026-10-07 10:01:00', ?)")->execute([$payload]);

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes);
    fclose($pipes[0]);
    for ($i = 0; $i < 100; ++$i) { $p = @stream_socket_client('tcp://' . $address, $e1, $e2, 0.1); if ($p) { fclose($p); break; } usleep(10000); }
    $calls = static fn(): array => array_map(static fn($l) => json_decode($l, true), array_filter(explode("\n", (string) @file_get_contents("$temp/orlen-calls.log"))));

    $boss = login('boss');
    $page = request($boss, 'admin/order_view.php?id=501');
    ok($page['status'] === 200 && str_contains($page['body'], 'data-integration="2"') && str_contains($page['body'], 'Nadaj przez ORLEN Paczka'), 'Order page offers ORLEN Paczka in „Nadaj przez"');
    ok(str_contains($page['body'], 'class="plugin-logo"') && str_contains($page['body'], 'fill="#db3830"') && !str_contains($page['body'], '📦 Nadaj przez'), 'ORLEN Paczka logo instead of the box emoji on the send button');
    $gallery = request($boss, 'admin/integrations.php');
    ok($gallery['status'] === 200 && preg_match('/integration_edit\.php\?type=orlenpaczka.*?<span class="ip-icon"[^>]*><span class="plugin-logo"/s', $gallery['body']) === 1, 'ORLEN Paczka logo in the integrations gallery');
    ok(str_contains($page['body'], 'class="cp-point"') && str_contains($page['body'], 'id="cp-points-2"'), 'Pickup point field in the courier form');
    preg_match('/var CSRF = "([a-f0-9]+)"/', $page['body'], $m);
    $csrf = $m[1] ?? '';
    ok($csrf !== '', 'CSRF token for the courier form');
    $post = static fn(array $data) => request($boss, 'admin/courier_shipment.php', $data + ['csrf' => $csrf, 'integration' => 2, 'woo_order_id' => 501])['json'];

    $sv = $post(['action' => 'services']);
    ok($sv['ok'] && array_column($sv['services']['services'], 'id') === ['auto', 'S', 'M', 'L'], 'Services: ORLEN Paczka sizes');
    ok($sv['services']['point']['value'] === 'XX-116263-00-00' && $calls() === [], 'Point from the WooCommerce order, no API call to open the form');

    $pts = $post(['action' => 'points', 'q' => 'kraków']);
    ok($pts['ok'] && count($pts['points']) === 1 && $pts['points'][0]['code'] === 'KR-300100-A1-01', 'Point search by city');
    ok(is_file("$temp/storage/cache/orlenpaczka-points.json"), 'Point list cached in storage/cache');
    $sv = $post(['action' => 'services']);
    ok($sv['services']['point']['value'] === 'WA-116263-K1-02' && str_contains($sv['services']['point']['note'], 'MARSZAŁKOWSKA'), 'Order point resolved to its full code once the list is cached');

    $q = $post(['action' => 'quote', 'service' => 'auto', 'weight' => 1, 'side_x' => 30, 'side_y' => 25, 'side_z' => 15]);
    ok($q['ok'] && abs($q['price'] - 10.99) < 0.001, 'Quote: size M at the standard ORLEN price');

    $send = $post(['action' => 'send', 'service' => 'auto', 'weight' => 1, 'side_x' => 30, 'side_y' => 25, 'side_z' => 15,
        'label_format' => 'PDF', 'handover' => 'point', 'point' => 'WA-116263-K1-02', 'service_label' => 'Dobierz gabaryt']);
    ok($send['ok'] && $send['waybill_no'] === '2100055566677', 'Shipment sent');
    $sh = $pdo->query('SELECT * FROM shipments')->fetch(PDO::FETCH_ASSOC);
    ok($sh['integration_id'] == 2 && $sh['bl_order_id'] === '2100055566677' && $sh['status'] === 'created' && abs((float) $sh['price'] - 10.99) < 0.001, 'Shipment saved with number and cost');
    ok(json_decode($sh['request_payload'], true)['point'] === 'WA-116263-K1-02', 'Chosen point kept in the shipment history');
    $last = $calls()[count($calls()) - 1];
    ok($last['op'] === 'GenerateLabelBusinessPackListTwo' && str_contains($last['xml'], '<DestinationCode>WA-116263-K1-02</DestinationCode>')
        && str_contains($last['xml'], '<PhoneNumber>600700800</PhoneNumber>') && str_contains($last['xml'], '<BoxSize>M</BoxSize>'), 'API got the point, receiver phone and size');

    $page = request($boss, 'admin/order_view.php?id=501');
    ok(str_contains($page['body'], '2100055566677') && str_contains($page['body'], 'orlenpaczka.pl/sledz-paczke/?numer=2100055566677'), 'Order page shows the parcel with the ORLEN tracking link');

    $lbl = request($boss, 'admin/label_download.php?shipment=' . $sh['id'] . '&printer=A4');
    ok($lbl['status'] === 200 && str_starts_with($lbl['body'], '%PDF') && str_contains($lbl['headers'], 'application/pdf'), 'Label downloads as PDF');

    $trk = request($boss, 'admin/shipment_track.php', ['csrf' => $csrf, 'shipment' => $sh['id']])['json'];
    ok($trk['ok'] && $trk['status'] === 'in_transit', 'Tracking ↻ reads the status from ORLEN Paczka');
    $row = $pdo->query('SELECT tracking_carrier, tracking_events FROM shipments WHERE id = ' . (int) $sh['id'])->fetch(PDO::FETCH_ASSOC);
    ok($row['tracking_carrier'] === 'ORLEN' && count(json_decode($row['tracking_events'], true)) === 2, 'Tracking history stored');

    $viewer = login('vi');
    $vpage = request($viewer, 'admin/order_view.php?id=501');
    ok(!str_contains($vpage['body'], 'Nadaj przez ORLEN Paczka'), 'Viewer does not get the send form');
    $deny = request($viewer, 'admin/courier_shipment.php', ['csrf' => $csrf, 'integration' => 2, 'woo_order_id' => 501, 'action' => 'send']);
    ok($deny['status'] !== 200 || empty($deny['json']['ok']), 'Viewer cannot send');

    // --- Uniwersalny Pusher: włączenie w Integracjach i powiadomienia od ORLEN Paczka ---
    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('APP_BASE_URL', " . $pdo->quote(rtrim($base, '/')) . ")");
    $ip = request($boss, 'admin/integration_edit.php?id=2');
    ok($ip['status'] === 200 && str_contains($ip['body'], 'Włącz Uniwersalny Pusher') && str_contains($ip['body'], 'Stan: wyłączony'), 'Integration page offers the pusher');
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $ip['body'], $m);
    $on = request($boss, 'admin/integration_edit.php?id=2', ['csrf' => $m[1], 'plugin_action' => 'pusher_on']);
    $acc = json_decode((string) $pdo->query('SELECT config FROM integration_accounts WHERE id = 2')->fetchColumn(), true);
    ok($on['status'] === 200 && str_contains($on['body'], 'Pusher włączony') && $acc['pusher_active'] === '1' && $acc['partner_id'] === 'PARTNER01', 'Pusher on: registered, rest of the config kept');
    $tok = (string) $acc['pusher_token'];
    $reg = array_values(array_filter($calls(), static fn($c) => $c['op'] === 'WebhookRegister'))[0] ?? null;
    ok($reg !== null && str_contains($reg['xml'], '<NotificationUrl>' . rtrim($base, '/') . '/webhook_plugin.php?a=2&amp;k=' . $tok . '</NotificationUrl>')
        && str_contains($reg['xml'], '<Password>' . $tok . '</Password>'), 'WebhookRegister got the CRM address and the token');
    $hook = static function (array $body, array $headers = [], string $query = '') use ($base): array {
        $h = curl_init($base . 'webhook_plugin.php?a=2' . $query);
        curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '',
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers)]);
        $out = curl_exec($h);
        return ['status' => curl_getinfo($h, CURLINFO_RESPONSE_CODE), 'body' => $out];
    };
    $bearer = ['Authorization: Bearer ' . $tok];
    $test = $hook(['PackCode' => 9999999999999, 'PackCodePrev' => null, 'Status' => '200', 'Updated' => '2000-01-01T00:00:00', 'Operator' => 'ORLEN-PACZKA'], $bearer);
    ok($test['status'] === 200, 'Test notification 9999999999999 answered 200');
    ok(str_contains(request($boss, 'admin/integration_edit.php?id=2')['body'], 'testowe (paczka 9999999999999)'), 'Integration page shows the test notification arrived');
    ok($hook(['PackCode' => 2100055566677, 'Status' => '690', 'Updated' => '2026-10-08T17:05:00.1'])['status'] === 401, 'Notification without the token: 401');
    ok($hook(['PackCode' => 2100055566677, 'Status' => '690', 'Updated' => '2026-10-08T17:05:00.1'], $bearer, '&a=999')['status'] === 404
        && $hook([], [], '')['status'] === 401, 'Unknown account: 404, empty body without token: 401');
    $pushed = $hook(['PackCode' => 2100055566677, 'PackCodePrev' => null, 'Status' => '690', 'Updated' => '2026-10-08T17:05:00.225694', 'Operator' => 'ORLEN-PACZKA'], $bearer);
    $row = $pdo->query('SELECT tracking_status, tracking_events FROM shipments WHERE id = ' . (int) $sh['id'])->fetch(PDO::FETCH_ASSOC);
    ok($pushed['status'] === 200 && $row['tracking_status'] === 'ready_for_pickup' && count(json_decode($row['tracking_events'], true)) === 3, 'Notification 690: parcel waits at the point, event added');
    $hook(['PackCode' => 2100055566677, 'PackCodePrev' => null, 'Status' => '690', 'Updated' => '2026-10-08T17:05:00.225694', 'Operator' => 'ORLEN-PACZKA'], $bearer);
    ok(count(json_decode((string) $pdo->query('SELECT tracking_events FROM shipments WHERE id = ' . (int) $sh['id'])->fetchColumn(), true)) === 3, 'Repeated notification stored once');
    $viaQuery = $hook(['PackCode' => 2100055566677, 'Status' => '610', 'Updated' => '2026-10-09T08:00:00'], [], '&k=' . $tok);
    ok($viaQuery['status'] === 200 && $pdo->query('SELECT tracking_status FROM shipments WHERE id = ' . (int) $sh['id'])->fetchColumn() === 'out_for_delivery', 'Token in the address works when the server drops Authorization');
    $notActive = request($boss, 'admin/integration_edit.php?id=2');
    ok(str_contains($notActive['body'], 'Wyłącz Pusher') && str_contains($notActive['body'], 'paczka 2100055566677, status 610'), 'Integration page shows the last notification');
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $notActive['body'], $m);
    $st = request($boss, 'admin/integration_edit.php?id=2', ['csrf' => $m[1], 'plugin_action' => 'pusher_status']);
    ok(str_contains($st['body'], 'Pusher w ORLEN Paczka: Active'), 'Check pusher status');
    $off = request($boss, 'admin/integration_edit.php?id=2', ['csrf' => $m[1], 'plugin_action' => 'pusher_off']);
    $acc = json_decode((string) $pdo->query('SELECT config FROM integration_accounts WHERE id = 2')->fetchColumn(), true);
    ok(str_contains($off['body'], 'Pusher wyłączony') && $acc['pusher_active'] === '' && $acc['sender_city'] === 'Warszawa', 'Pusher off');
    ok(request($boss, 'admin/integration_edit.php?id=2', ['csrf' => 'zly', 'plugin_action' => 'pusher_on'])['status'] === 419
        && $calls()[count($calls()) - 1]['op'] === 'WebhookUnregister', 'Pusher action needs a valid CSRF token');

    $cancel = request($boss, 'admin/shipment_cancel.php', ['csrf' => $csrf, 'shipment' => $sh['id']])['json'];
    ok($cancel['ok'] && $pdo->query('SELECT status FROM shipments WHERE id = ' . (int) $sh['id'])->fetchColumn() === 'cancelled', 'Cancel through ORLEN Paczka');
    ok($calls()[count($calls()) - 1]['op'] === 'PutCustomerPackCanceled', 'Cancel called the API');

    echo "\nAll {$checks} ORLEN Paczka HTTP checks passed.\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (getenv('KEEP_TEMP')) { echo "temp: $temp\n"; } else { exec('rm -rf ' . escapeshellarg($temp)); }
}
