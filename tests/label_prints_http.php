<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Licznik druku etykiet (Services\LabelPrints): każde pobranie/druk etykiety zapisane, drugi druk tylko po
// potwierdzeniu (again=1), plakietka „Wydrukowano N×” na karcie zamówienia. Prawdziwe strony, SQLite,
// atrapa wtyczki kurierskiej. Gdy jest Playwright (NODE_PATH), sprawdza okienko ostrzeżenia - tests/label_prints_ui.js.
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

$temp = sys_get_temp_dir() . '/crm-label-prints-http-' . bin2hex(random_bytes(6));
foreach (['public/admin/assets', 'public/pack/assets', 'config', 'storage/security', 'lang', 'integrations/fakecourier'] as $d) { mkdir("$temp/$d", 0700, true); }
$process = null;
try {
    file_put_contents("$temp/.env", "TOTP_REQUIRE_ADMIN=0\n");
    foreach (glob("$root/public/admin/*.php") as $f) { if (!in_array(basename($f), ['bootstrap_admin.php', 'db_admin.php'], true)) { copy($f, "$temp/public/admin/" . basename($f)); } }
    copy("$root/public/admin/assets/loc-picker.js", "$temp/public/admin/assets/loc-picker.js");
    copy("$root/public/shop_logo.php", "$temp/public/shop_logo.php");
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
        attachment_path TEXT, attachment_name TEXT, is_read INTEGER DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE order_documents (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, provider TEXT, document_type TEXT,
        remote_id TEXT, status TEXT DEFAULT 'issued', message TEXT, created_at TEXT, updated_at TEXT)");
    foreach ([\Pase\Services\OrderReturns::class, \Pase\Services\AllegroFeedback::class, \Pase\Services\Customers::class,
        \Pase\Services\DocumentIssueGuard::class, \Pase\Services\OrderMerge::class, \Pase\Services\OrderPayment::class] as $svc) { $svc::migrate($pdo); }
    \Pase\Services\EmailLog::migrate($pdo);
    $pdo->exec("CREATE TABLE email_templates (id INTEGER PRIMARY KEY, tpl_key TEXT, name TEXT, subject TEXT, body TEXT, is_active INTEGER, mail_account_id INTEGER NULL)");
    $pdo->exec("INSERT INTO email_templates (tpl_key, name, subject, body, is_active) VALUES ('basic_processing', 'Realizujemy Twoje zamówienie', 's', '<p>x</p>', 1), ('basic_ready', 'Twoje zamówienie jest gotowe do wysłania', 's', '<p>x</p>', 1)");

    // Atrapa kuriera: etykieta to mały PDF, liczba pobrań w pliku (sprawdzamy, że ponowny druk bez zgody nie pyta przewoźnika).
    file_put_contents("$temp/integrations/fakecourier/register.php", '<?php
return new class extends \Pase\Plugin\AbstractPlugin implements \Pase\Plugin\Contract\Courier {
    public function manifest(): \Pase\Plugin\PluginManifest { return new \Pase\Plugin\PluginManifest(type: "fakecourier", name: "Kurier testowy", capabilities: [\Pase\Plugin\Capability::COURIER]); }
    public function testConnection(): array { return ["ok" => true, "message" => ""]; }
    public function listServices(array $parcel): array { return []; }
    public function listPaymentOptions(): array { return []; }
    public function quote(array $params): array { return ["ok" => false, "price" => null, "message" => ""]; }
    public function createShipment(array $shipment): array { return ["ok" => false, "message" => "", "waybill_no" => null, "external_id" => null, "price" => null, "label_link" => null]; }
    public function getLabel(string $externalId, string $format = "A4"): array {
        file_put_contents(PASE_ROOT . "/label-calls.log", $externalId . "\n", FILE_APPEND);
        return ["ok" => true, "content" => "%PDF-1.4 etykieta " . $externalId, "filename" => "etykieta-" . $externalId . ".pdf", "mime" => "application/pdf", "message" => ""];
    }
    public function cancelShipment(string $externalId): array { return ["ok" => true, "message" => ""]; }
};');
    $labelCalls = static fn(): int => count(file("$temp/label-calls.log", FILE_IGNORE_NEW_LINES) ?: []);
    $pdo->exec("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES (2, 'fakecourier', 'Kurier testowy', 1, '{}')");
    $pdo->exec("CREATE TABLE print_jobs (id INTEGER PRIMARY KEY, kind TEXT, target TEXT, format TEXT, filename TEXT, content_b64 TEXT, status TEXT, error TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, sent_at TEXT, done_at TEXT)");

    $payload = json_encode(['id' => 551, 'status' => 'processing', 'line_items' => [['product_id' => 1, 'sku' => 'GB-1L', 'name' => 'GINGERBREAD 1l', 'quantity' => 1, 'price' => 49.9, 'total' => '49.90']],
        'billing' => ['first_name' => 'Jan', 'last_name' => 'Testowy', 'email' => 'jan@example.com'], 'shipping_lines' => [['method_id' => 'flat_rate', 'method_title' => 'Kurier']],
        'shipping' => ['first_name' => 'Jan', 'last_name' => 'Testowy', 'address_1' => 'Testowa 1', 'postcode' => '00-001', 'city' => 'Warszawa', 'country' => 'PL']]);
    $insOrder = $pdo->prepare("INSERT INTO woo_orders (woo_order_id, integration_id, pase_number, order_number, status, pase_status, currency, total, customer_name,
        customer_email, date_created, imported_at, payload) VALUES (?, 1, ?, ?, 'processing', 'processing', 'PLN', 49.9, 'Jan Testowy', 'jan@example.com', '2026-11-18 10:00:00', '2026-11-18 10:00:00', ?)");
    $insOrder->execute([551, 551, '551', $payload]);
    $insOrder->execute([552, 552, '552', $payload]);
    $pdo->exec("INSERT INTO shipments (id, woo_order_id, integration_id, courier_code, waybill_no, bl_order_id, status, created_at) VALUES
        (71, 551, 2, 'Kurier testowy', 'WB000071', 'EXT-71', 'created', '2026-11-18 11:00:00'),
        (72, 552, 2, 'Kurier testowy', 'WB000072', 'EXT-72', 'created', '2026-11-18 11:00:00')");

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes);
    fclose($pipes[0]);
    for ($i = 0; $i < 100; ++$i) { $p = @stream_socket_client('tcp://' . $address, $e1, $e2, 0.1); if ($p) { fclose($p); break; } usleep(10000); }

    $boss = login('boss');
    $ola = login('ola');

    // --- przed pierwszym drukiem: zero, bez plakietki ---
    $page = request($boss, 'admin/order_view.php?id=551');
    ok($page['status'] === 200 && str_contains($page['body'], 'class="lbl-menu" data-ship="71" data-prints="0"'), 'Order page: label menu knows the shipment was never printed');
    ok(preg_match('/<span class="lbl-count" data-ship="71"\s+hidden/', $page['body']) === 1, 'Order page: no badge before the first print');

    // --- pierwszy druk (okno druku) przechodzi od razu i jest zapisany ---
    $first = request($boss, 'admin/label_download.php?shipment=71&printer=A4&print=1');
    ok($first['status'] === 200 && str_contains($first['body'], 'data:application/pdf;base64,') && stripos($first['headers'], 'X-Label-Prints: 1') !== false, 'First print goes straight through and is counted');
    $row = $pdo->query('SELECT * FROM shipment_label_prints')->fetch(PDO::FETCH_ASSOC);
    ok((int) $row['shipment_id'] === 71 && (int) $row['woo_order_id'] === 551 && $row['mode'] === 'print' && $row['user_name'] === 'Adam' && (int) $row['user_id'] === 1, 'Print saved with order, mode and who printed');

    // --- drugi raz bez potwierdzenia: ostrzeżenie, bez pytania przewoźnika ---
    $calls = $labelCalls();
    $again = request($ola, 'admin/label_download.php?shipment=71&printer=A4');
    ok($again['status'] === 409 && str_contains($again['body'], 'Ta etykieta była już drukowana') && str_contains($again['body'], 'Adam')
        && str_contains($again['body'], 'Każda paczka musi mieć własną etykietę'), 'Second download without confirmation shows the warning with who printed first');
    ok(str_contains($again['body'], 'label_download.php?shipment=71&amp;printer=A4&amp;again=1') && $labelCalls() === $calls, 'Warning offers „Drukuj ponownie” and does not fetch the label');
    ok((int) $pdo->query('SELECT COUNT(*) FROM shipment_label_prints')->fetchColumn() === 1, 'Warning alone is not counted as a print');

    preg_match('/var CSRF = "([a-f0-9]+)"/', $page['body'], $m);
    $csrf = $m[1] ?? '';
    $agent = request($boss, 'admin/label_download.php?shipment=71&printer=A4&agent=1', ['csrf' => $csrf]);
    ok($agent['json']['ok'] === false && $agent['json']['already'] === true && $agent['json']['count'] === 1 && str_contains($agent['json']['warning'], 'Adam'), 'Agent print of an already printed label asks for confirmation');
    ok((int) $pdo->query('SELECT COUNT(*) FROM print_jobs')->fetchColumn() === 0, 'Nothing sent to the print agent without confirmation');

    // --- świadome potwierdzenie (again=1) drukuje i liczy dalej ---
    $agent = request($boss, 'admin/label_download.php?shipment=71&printer=A4&agent=1&again=1', ['csrf' => $csrf]);
    ok($agent['json']['ok'] === true && $agent['json']['prints'] === 2 && (int) $pdo->query('SELECT COUNT(*) FROM print_jobs')->fetchColumn() === 1, 'Confirmed reprint goes to the agent and counts as the 2nd print');
    $dl = request($ola, 'admin/label_download.php?shipment=71&printer=A4&again=1');
    ok($dl['status'] === 200 && str_starts_with($dl['body'], '%PDF') && stripos($dl['headers'], 'X-Label-Prints: 3') !== false, 'Confirmed download counts as the 3rd print');

    // --- karta zamówienia: czerwona plakietka z historią ---
    $page = request($boss, 'admin/order_view.php?id=551');
    ok(str_contains($page['body'], 'data-ship="71" data-prints="3"') && str_contains($page['body'], 'Wydrukowano już 3×. Pierwszy raz') && str_contains($page['body'], ', ostatnio '), 'Order page: menu knows 3 prints and the warning text');
    ok(preg_match('/<span class="lbl-count many" data-ship="71"[^>]*title="[^"]*Ola · pobranie[^"]*Adam · agent druku[^"]*Adam · druk z wyborem drukarki"[^>]*>Wydrukowano 3×<\/span>/', $page['body']) === 1, 'Order page: red „Wydrukowano 3×” badge with history (newest first)');
    $page2 = request($boss, 'admin/order_view.php?id=552');
    ok(str_contains($page2['body'], 'data-ship="72" data-prints="0"'), 'Other shipment is not affected');

    // --- lista zamówień: bez plakietki (Klub: niepotrzebna na liście, wystarczy karta) ---
    $list = request($boss, 'admin/index.php');
    ok($list['status'] === 200 && !str_contains($list['body'], 'Wydrukowano'), 'Orders list shows no print badge');
    request($boss, 'admin/label_download.php?shipment=72&printer=A4');   // jeden druk - przeglądarka sprawdza ostrzeżenie na 72

    // --- historia zmian zamówienia ---
    ok((int) $pdo->query("SELECT COUNT(*) FROM audit_events WHERE order_id = 551 AND action = 'shipment.label_printed'")->fetchColumn() === 3, 'Each print is in the order history');
    $hist = request($boss, 'admin/order_history.php?id=551');
    ok(str_contains($hist['body'], 'Druk / pobranie etykiety przesyłki'), 'Order history names the event');

    $node = trim((string) shell_exec('command -v node'));
    $hasPw = $node !== '' && trim((string) shell_exec('NODE_PATH=' . escapeshellarg((string) getenv('NODE_PATH')) . ' ' . escapeshellarg($node)
        . ' -e "require.resolve(\'playwright\'); console.log(1)" 2>/dev/null')) === '1';
    if ($hasPw) {
        passthru('BASE=' . escapeshellarg($base) . ' NODE_PATH=' . escapeshellarg((string) getenv('NODE_PATH')) . ' ' . escapeshellarg($node) . ' ' . escapeshellarg("$root/tests/label_prints_ui.js"), $rc);
        if ($rc !== 0) { echo implode('', preg_grep('/PHP |\[5\d\d\]/', file("$temp/server.log") ?: [])); }
        ok($rc === 0, 'Browser checks passed');
        ok((int) $pdo->query('SELECT COUNT(*) FROM shipment_label_prints WHERE shipment_id = 72')->fetchColumn() === 2, 'Browser: confirmed reprint was counted');
    } else {
        echo "SKIP: browser checks (no Playwright; set NODE_PATH)\n";
    }
    $warnings = preg_grep('/PHP (Warning|Fatal|Parse|Deprecated)/', file("$temp/server.log") ?: []);
    ok($warnings === [], 'No PHP warnings in the server log');

    echo "\nAll {$checks} label print checks passed.\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (getenv('KEEP_TEMP')) { echo "temp: $temp\n"; } else { exec('rm -rf ' . escapeshellarg($temp)); }
}
