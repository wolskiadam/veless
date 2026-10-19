<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Rozszerzenie KSeF przez HTTP: prawdziwe strony panelu z extensions/ksef - ustawienia na stronie Wtyczki, token
// (zapis zaszyfrowany, tylko administrator), sekcja karty zamówienia, podgląd e-faktury, lista, pobieranie XML/UPO,
// ikona na liście zamówień i akcja w edytorze automatyzacji. Bez połączeń z KSeF (wysyłkę sprawdza tests/ksef.php). Baza SQLite.
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

$temp = sys_get_temp_dir() . '/crm-ksef-http-' . bin2hex(random_bytes(6));
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
    // Rozszerzenie zainstalowane jak przez FTP (extensions/ksef).
    exec('cp -r ' . escapeshellarg("$root/extensions/ksef") . ' ' . escapeshellarg("$temp/extensions/ksef"));
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

    // ---- Ustawienia z manifestu na stronie Wtyczki
    $pl = request($boss, 'admin/plugins.php');
    ok(str_contains($pl['body'], 'KSeF - e-faktury') && str_contains($pl['body'], 'name="cfg[number_format]"') && str_contains($pl['body'], 'name="cfg[environment]"')
        && !str_contains($pl['body'], '>Rozszerzenie</span>') && str_contains($pl['body'], 'data-open="pl-cfg-extension-ksef"')
        && preg_match('#Uprawnienia:.*?Połączenie zewnętrzne.*?Karta zamówienia#s', $pl['body']) === 1
        && str_contains($pl['body'], 'name="cfg[do_sales]" value="1" checked') && str_contains($pl['body'], 'name="cfg[do_purchases]" value="1">'), 'Plugins page lists the KSeF extension with its settings');
    $r = request($boss, 'admin/plugins.php', ['csrf' => csrfOf($pl['body']), 'action' => 'ext_settings', 'slug' => 'ksef', 'cfg' => [
        'environment' => 'test', 'nip' => '526-025-02-74', 'seller_name' => 'Klub Testowy sp. z o.o.', 'seller_address1' => 'ul. Prosta 1',
        'seller_address2' => '00-001 Warszawa', 'number_format' => 'KSEF/{nr}/{mm}/{rrrr}', 'default_vat' => '23', 'vat_mode' => 'vat', 'do_sales' => '1', 'do_sales_list' => '1', 'do_purchases' => '1', 'purchases_days' => '30']]);
    ok($r['status'] === 200 && str_contains($r['body'], 'Zapisano ustawienia'), 'Settings are saved');

    // ---- Strona Faktury KSeF; token w ustawieniach wtyczki
    $page = request($boss, 'admin/ext.php?page=ksef.faktury');
    ok($page['status'] === 200 && str_contains($page['body'], 'Faktury KSeF') && str_contains($page['body'], 'Testowe (TE)') && str_contains($page['body'], 'token KSeF')
        && !str_contains($page['body'], 'name="token"') && str_contains($page['body'], 'Sprawdź połączenie'), 'Admin sees the connection state and test button, no token form');
    $vp = request($vi, 'admin/ext.php?page=ksef.faktury');
    ok($vp['status'] === 200 && !str_contains($vp['body'], 'Sprawdź połączenie'), 'Viewer sees the list without the connection test');
    ok(str_contains($pl['body'], 'name="cfg[token_test]"') && str_contains($pl['body'], 'name="cfg[token_prod]"'), 'Plugin settings have a token field per environment');
    ok(request($ola, 'admin/plugins.php')['status'] === 403, 'Editor cannot open plugin settings');
    $pl = request($boss, 'admin/plugins.php');
    $r = request($boss, 'admin/plugins.php', ['csrf' => csrfOf($pl['body']), 'action' => 'ext_settings', 'slug' => 'ksef', 'cfg' => [
        'environment' => 'test', 'nip' => '526-025-02-74', 'seller_name' => 'Klub Testowy sp. z o.o.', 'seller_address1' => 'ul. Prosta 1',
        'seller_address2' => '00-001 Warszawa', 'number_format' => 'KSEF/{nr}/{mm}/{rrrr}', 'default_vat' => '23', 'vat_mode' => 'vat', 'do_sales' => '1', 'do_sales_list' => '1', 'do_purchases' => '1',
        'purchases_days' => '30', 'token_test' => 'token-z-panelu']]);
    $stored = (string) $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'EXT_KSEF'")->fetchColumn();
    ok($r['status'] === 200 && str_contains($stored, '"token_test":"enc:v1:') && !str_contains($stored, 'token-z-panelu') && !str_contains($r['body'], 'token-z-panelu')
        && str_contains($r['body'], 'zapisany (zostaw puste') && str_contains($r['body'], 'name="cfg_clear[token_test]"'), 'Token is saved encrypted and never shown back');
    $page = request($boss, 'admin/ext.php?page=ksef.faktury');
    ok(str_contains($page['body'], 'zapisany') && !str_contains($page['body'], 'token-z-panelu'), 'Connection page confirms the token');

    // ---- Karta zamówienia i podgląd
    $card = request($boss, 'admin/order_view.php?id=501');
    ok($card['status'] === 200 && str_contains($card['body'], 'data-section="x_ksef"') && str_contains($card['body'], 'Podgląd i wysyłka do KSeF'), 'Order card has the KSeF section');
    $pv = request($ola, 'admin/ext.php?page=ksef.wystaw&id=501');
    ok($pv['status'] === 200 && str_contains($pv['body'], 'GINGERBREAD 1l') && str_contains($pv['body'], 'Anna Kowalska') && str_contains($pv['body'], 'osoba prywatna, bez NIP')
        && str_contains($pv['body'], '114,80 PLN') && str_contains($pv['body'], 'Wyślij do KSeF'), 'Editor sees the invoice preview with the send button');
    ok(request($vi, 'admin/ext.php?page=ksef.wystaw&id=501')['status'] === 403, 'Viewer cannot open the send page');
    ok(request($boss, 'admin/ext.php?page=ksef.plik&id=501&co=xml')['status'] === 404, 'No file before the invoice exists');

    // ---- E-faktura przyjęta (wpis jak po wysyłce): karta, pliki, lista, ikona
    $xml = '<?xml version="1.0" encoding="UTF-8"?><Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/"/>';
    $pdo->prepare("INSERT INTO ext_ksef_invoices (woo_order_id, env, status, number, issue_date, gross, buyer, ksef_number, session_ref, invoice_ref, xml, upo, message, created_by, created_at, updated_at)
        VALUES (501, 'test', 'accepted', 'KSEF/1/10/2026', '2026-10-14', 114.8, 'Anna Kowalska', '5260250274-20261014-0100A0B1C2D3-4E', 'S', 'I', ?, '<UPO/>', 'Przyjęta w KSeF.', 'Adam', '2026-10-14 10:00:00', '2026-10-14 10:00:00')")
        ->execute([$xml]);
    $card = request($boss, 'admin/order_view.php?id=501');
    ok(str_contains($card['body'], 'KSEF/1/10/2026') && str_contains($card['body'], '5260250274-20261014-0100A0B1C2D3-4E')
        && str_contains($card['body'], 'https://qr-test.ksef.mf.gov.pl/invoice/5260250274/14-10-2026/'), 'Card shows the KSeF number and the verification link');
    $f = request($vi, 'admin/ext.php?page=ksef.plik&id=501&co=xml');
    ok($f['status'] === 200 && $f['body'] === $xml && str_contains($f['headers'], 'filename="KSEF_1_10_2026.xml"'), 'XML downloads');
    $f = request($vi, 'admin/ext.php?page=ksef.plik&id=501&co=upo');
    ok($f['status'] === 200 && $f['body'] === '<UPO/>', 'UPO downloads');
    $pv = request($ola, 'admin/ext.php?page=ksef.wystaw&id=501');
    $r = request($ola, 'admin/ext.php?page=ksef.wystaw', ['csrf' => csrfOf($pv['body']), 'id' => 501]);
    ok($r['status'] === 302 && str_contains(request($ola, 'admin/order_view.php?id=501')['body'], 'ma już e-fakturę'), 'Sending again is refused with a message');
    $list = request($boss, 'admin/ext.php?page=ksef.faktury');
    ok(str_contains($list['body'], 'KSEF/1/10/2026') && str_contains($list['body'], 'przyjęta w KSeF'), 'List page shows the e-invoice');
    $idx = request($boss, 'admin/index.php');
    ok($idx['status'] === 200 && str_contains($idx['body'], 'data-kind="x_ksef"') && str_contains($idx['body'], 'href="ext.php?page=ksef.faktury"'), 'Order list has the icon and the menu entry');
    $au = request($boss, 'admin/automations.php');
    ok($au['status'] === 200 && str_contains($au['body'], '"x_ksef_invoice":{"label":"Wyślij e-fakturę do KSeF"'), 'Automation editor offers the KSeF action');

    // ---- Faktury zakupowe (wpis jak po pobraniu z KSeF)
    $zk = request($vi, 'admin/ext.php?page=ksef.zakupy');
    ok($zk['status'] === 200 && str_contains($zk['body'], 'Faktury zakupowe KSeF') && str_contains($zk['body'], 'Jeszcze nic nie pobrano') && !str_contains($zk['body'], 'page=ksef.pobierz'),
        'Viewer sees the empty purchase list without the fetch button');
    ok(request($vi, 'admin/ext.php?page=ksef.pobierz', ['csrf' => csrfOf(request($vi, 'admin/index.php')['body'])])['status'] === 403, 'Viewer cannot fetch from KSeF');
    $fa = (string) file_get_contents(__DIR__ . '/fixtures/ksef/zakup.xml');
    $pdo->prepare("INSERT INTO ext_ksef_purchases (env, ksef_number, invoice_number, invoice_type, seller_nip, seller_name, issue_date, storage_date, net, vat, gross, currency, invoice_hash, xml, created_at)
        VALUES ('test', '7740001454-20261014-AAAAAAAAAAAA-06', 'FV 7/10/2026', 'Vat', '7740001454', 'Hurtownia Pszczelarska', '2026-10-14', '2026-10-14T11:00:00+00:00', 150.38, 35.61, 185.99, 'PLN', ?, ?, '2026-10-14 13:00:00')")
        ->execute([base64_encode(hash('sha256', $fa, true)), $fa]);
    $zk = request($ola, 'admin/ext.php?page=ksef.zakupy&q=Hurtownia&m=2026-10');
    ok(str_contains($zk['body'], 'FV 7/10/2026') && str_contains($zk['body'], '185,99') && str_contains($zk['body'], 'page=ksef.pobierz') && str_contains($zk['body'], 'name="od"') && str_contains($zk['body'], 'Pobierz z okresu'), 'Editor sees the purchase list with search and the fetch button');
    $one = request($vi, 'admin/ext.php?page=ksef.zakup&k=7740001454-20261014-AAAAAAAAAAAA-06');
    ok($one['status'] === 200 && str_contains($one['body'], 'Hurtownia Pszczelarska') && str_contains($one['body'], 'Kwota należności ogółem') && str_contains($one['body'], 'qr-test.ksef.mf.gov.pl/invoice/7740001454/'),
        'Purchase invoice preview');
    $f = request($vi, 'admin/ext.php?page=ksef.zakupxml&k=7740001454-20261014-AAAAAAAAAAAA-06');
    ok($f['status'] === 200 && $f['body'] === $fa, 'Purchase invoice XML downloads');
    $pdfRes = request($vi, 'admin/ext.php?page=ksef.zakuppdf&k=7740001454-20261014-AAAAAAAAAAAA-06');
    ok($pdfRes['status'] === 200 && str_starts_with($pdfRes['body'], '%PDF-1.4') && str_ends_with($pdfRes['body'], "%%EOF\n") && str_contains($one['body'], 'Pobierz PDF')
        && str_contains($one['body'], '<svg'), 'Purchase invoice PDF downloads and the preview has the PDF button and QR code');
    ok(request($vi, 'admin/ext.php?page=ksef.zakuppdf&k=nie-ma')['status'] === 404, 'Missing purchase invoice PDF is 404');
    $menu = request($boss, 'admin/index.php')['body'];
    $reg = request($boss, 'admin/ext.php?page=ksef.rejestr');
    ok(substr_count($menu, 'href="ext.php?page=ksef.rejestr"') >= 1 && str_contains($menu, 'Rejestr faktur KSeF') && !str_contains($menu, 'href="ext.php?page=ksef.zakupy"')
        && !str_contains($menu, 'href="ext.php?page=ksef.sprzedaz"') && $reg['status'] === 302 && str_contains($reg['headers'] ?? '', 'page=ksef.sprzedaz'),
        'One menu entry for KSeF invoices, opening the sales tab');
    $sl = request($vi, 'admin/ext.php?page=ksef.sprzedaz');
    $pdo->prepare("INSERT INTO ext_ksef_sales (env, ksef_number, invoice_number, invoice_type, buyer_nip, buyer_name, issue_date, storage_date, net, vat, gross, currency, invoice_hash, xml, created_at)
        VALUES ('test', '5260250274-20261010-BBBBBBBBBBBB-01', 'KSEF/9/10/2026', 'Vat', '1132853869', 'Pasieka Pod Lipą', '2026-10-10', '2026-10-10T09:00:00+00:00', 100, 23, 123, 'PLN', ?, ?, '2026-10-14 13:00:00')")
        ->execute([base64_encode(hash('sha256', $fa, true)), $fa]);
    $sl2 = request($ola, 'admin/ext.php?page=ksef.sprzedaz&m=2026-10');
    ok($sl['status'] === 200 && str_contains($sl['body'], 'Faktury sprzedażowe KSeF') && str_contains($sl2['body'], 'Pasieka Pod Lipą') && str_contains($sl2['body'], 'page=ksef.pobierzsp')
        && str_contains($sl2['body'], 'href="ext.php?page=ksef.zakupy"') && str_contains($zk['body'] ?? '', 'href="ext.php?page=ksef.sprzedaz"'),
        'Sales invoices list with fetch button for editors and tabs between sales and purchases');
    $spdf = request($vi, 'admin/ext.php?page=ksef.sprzedazpdf&k=5260250274-20261010-BBBBBBBBBBBB-01');
    ok($spdf['status'] === 200 && str_starts_with($spdf['body'], '%PDF-1.4'), 'Sales invoice PDF downloads');

    $warnings = preg_grep('/PHP (Warning|Fatal|Parse|Deprecated|Notice)|Rozszerzenie .* błąd/', file("$temp/server.log") ?: []);
    ok($warnings === [], 'No PHP warnings or extension errors' . ($warnings ? ': ' . implode(' | ', array_map('trim', $warnings)) : ''));
    echo "PASS: {$checks} KSeF HTTP checks\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (getenv('KEEP_TEMP')) { echo "temp: $temp\n"; } else { exec('rm -rf ' . escapeshellarg($temp)); }
}
