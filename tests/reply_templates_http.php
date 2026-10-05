<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Szablony odpowiedzi w „Wiadomościach z klientem" na stronie zamówienia: wybór z aktywnych szablonów e-mail,
// dane zamówienia podstawione w treści, wysłanie trafia do wątku, podgląd bez wyboru. Prawdziwe strony, SQLite.
// Gdy jest Playwright (NODE_PATH), dodatkowo klika wybór szablonu w przeglądarce - tests/reply_templates_ui.js.
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

$temp = sys_get_temp_dir() . '/crm-reply-tpl-http-' . bin2hex(random_bytes(6));
foreach (['public/admin/assets', 'public/pack/assets', 'config', 'storage/security', 'lang'] as $d) { mkdir("$temp/$d", 0700, true); }
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

    $pdo->exec("CREATE TABLE email_templates (id INTEGER PRIMARY KEY, tpl_key TEXT, name TEXT, subject TEXT, body TEXT, is_active INTEGER, mail_account_id INTEGER NULL)");
    $tins = $pdo->prepare('INSERT INTO email_templates (tpl_key, name, subject, body, is_active) VALUES (?,?,?,?,?)');
    $tins->execute(['nieodebrana', 'Nieodebrana paczka', 'Paczka {{order_number}}', '<p>Dzień dobry {{customer_name}},</p><p>paczka <strong>{{waybill}}</strong> do zamówienia {{order_number}} wróciła do nas.<br>Pozdrawiamy</p>', 1]);
    $tins->execute(['ocen', 'Oceń nas', 'Ocena', '<p>Dziękujemy za zakup &amp; zapraszamy ponownie!</p>', 1]);
    $tins->execute(['stary', 'Stary szablon', 'x', '<p>nieaktywny</p>', 0]);
    $pdo->exec("CREATE TABLE mail_accounts (id INTEGER PRIMARY KEY, name TEXT, from_email TEXT, from_name TEXT, reply_to TEXT NULL, review_link TEXT NULL, logo_mime TEXT NULL, logo_data TEXT NULL,
        host TEXT, port TEXT, user TEXT, pass TEXT, secure TEXT, is_default INTEGER DEFAULT 0)");
    $pdo->exec("INSERT INTO mail_accounts (name, from_email, from_name, review_link, host, port, user, pass, secure, is_default)
        VALUES ('Lumio', 'hello@example.com', 'Lumio', 'https://g.page/r/PRZYKLAD123/review', 'smtp.example.com', '587', 'u', 'p', 'tls', 1)");
    // Podstawowe szablony (z BaseLinkera) - raz; „Oceń nas" nie dubluje się z istniejącym o tej nazwie.
    ok(\Pase\Services\BasicEmailTemplates::seedOnce($pdo) === 3, 'Basic templates are added, skipping one that already exists by name');
    $pdo->exec("DELETE FROM email_templates WHERE tpl_key = 'basic_invoice'");
    ok(\Pase\Services\BasicEmailTemplates::seedOnce($pdo) === 0, 'Basic templates are added only once (a deleted one does not come back)');
    $pdo->exec("INSERT INTO email_templates (tpl_key, name, subject, body, is_active) VALUES ('basic_invoice', 'Faktura', 'Faktura została wystawiona', "
        . $pdo->quote(\Pase\Services\BasicEmailTemplates::templates()[2]['body']) . ", 1)");
    $pdo->exec("INSERT INTO shipments (woo_order_id, integration_id, courier_code, waybill_no, status, created_at) VALUES (501, 1, 'inpost', '620000111222333', 'created', '2026-09-22 10:00:00')");

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes);
    fclose($pipes[0]);
    for ($i = 0; $i < 100; ++$i) { $p = @stream_socket_client('tcp://' . $address, $e1, $e2, 0.1); if ($p) { fclose($p); break; } usleep(10000); }

    $boss = login('boss');
    $page = request($boss, 'admin/order_view.php?id=501');
    ok($page['status'] === 200 && str_contains($page['body'], 'id="tplPickBtn"') && str_contains($page['body'], 'Szablony e-mail'), 'Order page shows the template picker above the reply box');
    ok(str_contains($page['body'], '>Nieodebrana paczka<') && str_contains($page['body'], '>Oceń nas<') && !str_contains($page['body'], 'Stary szablon'), 'Picker lists active templates only');
    preg_match('/<script type="application\/json" id="tplPickData">(.*?)<\/script>/s', $page['body'], $m);
    $tpls = array_column(json_decode($m[1] ?? '[]', true) ?: [], 'body', 'name');
    ok(str_starts_with($tpls['Nieodebrana paczka'] ?? '', "Dzień dobry Anna Kowalska,\n"), 'Template text has the customer name');
    ok(str_contains($tpls['Nieodebrana paczka'] ?? '', 'paczka 620000111222333 do zamówienia 501') && !str_contains($tpls['Nieodebrana paczka'] ?? '', '<'), 'Template text has waybill and order number, no HTML');
    ok(($tpls['Oceń nas'] ?? '') === 'Dziękujemy za zakup & zapraszamy ponownie!', 'HTML entities are decoded');

    ok(str_contains($page['body'], '>Realizujemy Twoje zamówienie<') && str_contains($page['body'], '>Twoje zamówienie jest gotowe do wysłania<') && str_contains($page['body'], '>Faktura<'), 'Basic templates are in the picker');
    $processing = $tpls['Realizujemy Twoje zamówienie'] ?? '';
    ok(str_contains($processing, "Zamówienie dotyczy produktów:\n") && str_contains($processing, '2 × GINGERBREAD 1l') && str_contains($processing, "Pozdrawiamy\nLumio"), 'Basic template lists the products and signs with the shop name');
    ok(str_contains($tpls['Twoje zamówienie jest gotowe do wysłania'] ?? '', "Numer przesyłki: 620000111222333\nŚledzenie przesyłki: https://inpost.pl/sledzenie-przesylek?number=620000111222333"), 'Tracking number and carrier tracking link are filled in');
    $pdo->exec("INSERT INTO shipments (woo_order_id, integration_id, courier_code, waybill_no, status, created_at) VALUES (501, 1, 'DPD', '0000123', 'error', '2026-09-23 10:00:00')");
    preg_match('/<script type="application\/json" id="tplPickData">(.*?)<\/script>/s', request($boss, 'admin/order_view.php?id=501')['body'], $mm);
    ok(str_contains(array_column(json_decode($mm[1] ?? '[]', true) ?: [], 'body', 'name')['Twoje zamówienie jest gotowe do wysłania'] ?? '', 'Numer przesyłki: 620000111222333'), 'Failed shipment does not replace the sent one');
    $et = request($boss, 'admin/email_templates.php');
    ok(str_contains($et['body'], '<code>{{waybill}}</code> <span class="var-chip-label">Numer przesyłki</span>') && str_contains($et['body'], '{{tracking_link}}'), 'Template editor chips carry Polish labels incl. tracking link');
    ok(str_contains($tpls['Oceń nas'] ?? '', 'Dziękujemy za zakup'), 'Existing template with the same name is kept, not replaced');
    $pdo->exec("UPDATE email_templates SET name = 'Oceń nas (stary)' WHERE tpl_key = 'ocen'");
    $pdo->exec("INSERT INTO email_templates (tpl_key, name, subject, body, is_active) VALUES ('basic_review', 'Oceń nas', 'Zamówienie dostarczone', "
        . $pdo->quote(\Pase\Services\BasicEmailTemplates::templates()[3]['body']) . ", 1)");
    $page2 = request($boss, 'admin/order_view.php?id=501');
    preg_match('/<script type="application\/json" id="tplPickData">(.*?)<\/script>/s', $page2['body'], $m2);
    $tpls2 = array_column(json_decode($m2[1] ?? '[]', true) ?: [], 'body', 'name');
    ok(str_contains($tpls2['Oceń nas'] ?? '', 'Możesz to zrobić tutaj: https://g.page/r/PRZYKLAD123/review'), 'Review template gets the shop review link');

    $r = request($boss, 'admin/order_view.php?id=501', ['csrf' => csrfOf($page['body']), 'action' => 'send_message', 'message_body' => $tpls['Nieodebrana paczka'] ?? '']);
    ok(in_array($r['status'], [302, 303], true) && str_contains((string) $pdo->query('SELECT body FROM order_messages WHERE woo_order_id = 501')->fetchColumn(), '620000111222333'), 'Sending the filled reply adds it to the thread');

    // Zamówienie z Allegro: odpowiedź idzie przez Centrum wiadomości Allegro, nie e-mailem (wysyłka: tests/allegro_order_message.php).
    $pdo->exec("INSERT INTO woo_orders (woo_order_id, integration_id, pase_number, order_number, status, pase_status, currency, total, customer_name, customer_email, date_created, imported_at, payload)
        VALUES (9000000517, 1, 517, 'cf-517', 'processing', 'new', 'PLN', 50, 'Darek', 'x@allegromail.pl', '2026-10-04 10:00:00', '2026-10-04 10:01:00', "
        . $pdo->quote(json_encode(['id' => 'cf-517', 'buyer' => ['login' => 'dareklim'], 'lineItems' => []])) . ")");
    $ap = request($boss, 'admin/order_view.php?id=9000000517');
    ok($ap['status'] === 200 && str_contains($ap['body'], 'Centrum wiadomości Allegro') && !str_contains($ap['body'], 'jest wysyłana e-mailem do klienta'), 'Allegro order says the reply goes via Allegro messages');

    // Testowa wysyłka szablonu z edytora szablonów.
    $te = request($boss, 'admin/email_templates.php?edit=1');
    ok(str_contains($te['body'], 'id="tplTestForm"') && str_contains($te['body'], 'Wyślij test'), 'Template editor has a test send form');
    $tr = request($boss, 'admin/email_templates.php', ['csrf' => csrfOf($te['body']), 'action' => 'test_send', 'id' => 1, 'test_to' => 'adam@example.com', 'test_order' => '999']);
    ok(str_contains($tr['body'], 'Nie znaleziono zamówienia 999') && str_contains($tr['body'], 'id="tplTestForm"'), 'Test send with an unknown order explains why and stays on the template');

    // Link do opinii w koncie e-mail sklepu (Konfiguracja → E-mail).
    $es = request($boss, 'admin/email_settings.php?edit=1');
    ok($es['status'] === 200 && str_contains($es['body'], 'name="review_link"') && str_contains($es['body'], 'PRZYKLAD123'), 'E-mail account form shows the review link');
    $acc = ['csrf' => csrfOf($es['body']), 'action' => 'save_account', 'id' => 1, 'name' => 'Lumio', 'from_email' => 'hello@example.com', 'from_name' => 'Lumio',
        'host' => 'smtp.example.com', 'port' => '587', 'user' => 'u', 'pass' => '', 'secure' => 'tls'];
    request($boss, 'admin/email_settings.php', $acc + ['review_link' => 'javascript:alert(1)']);
    ok($pdo->query('SELECT review_link FROM mail_accounts WHERE id = 1')->fetchColumn() === 'https://g.page/r/PRZYKLAD123/review', 'Non-http review link is refused');
    request($boss, 'admin/email_settings.php', $acc + ['review_link' => 'https://g.page/r/nowy/review']);
    ok($pdo->query('SELECT review_link FROM mail_accounts WHERE id = 1')->fetchColumn() === 'https://g.page/r/nowy/review'
        && $pdo->query('SELECT pass FROM mail_accounts WHERE id = 1')->fetchColumn() === 'p', 'Review link is saved per shop account (password kept)');

    // Logo sklepu: wgranie w koncie e-mail, publiczny adres obrazka, {{shop_logo}} w mailu.
    $png = "$temp/logo.png";
    $img = imagecreatetruecolor(40, 20); imagepng($img, $png);
    file_put_contents("$temp/fake.png", '<?php echo 1;');
    $upload = static function (string $file) use ($boss, $acc, $temp): void {
        global $base;
        $es = request($boss, 'admin/email_settings.php?edit=1');
        curl_setopt($boss, CURLOPT_URL, $base . 'admin/email_settings.php');
        curl_setopt($boss, CURLOPT_POST, true);
        curl_setopt($boss, CURLOPT_POSTFIELDS, ['csrf' => csrfOf($es['body'])] + $acc + ['review_link' => 'https://g.page/r/nowy/review', 'logo' => new CURLFile($file, 'image/png', 'logo.png')]);
        curl_exec($boss);
    };
    $upload("$temp/fake.png");
    ok($pdo->query('SELECT logo_data FROM mail_accounts WHERE id = 1')->fetchColumn() === null, 'Non-image logo is refused');
    $upload($png);
    ok($pdo->query('SELECT logo_mime FROM mail_accounts WHERE id = 1')->fetchColumn() === 'image/png', 'PNG logo is saved on the shop account');
    $es = request($boss, 'admin/email_settings.php?edit=1');
    ok(str_contains($es['body'], 'src="data:image/png;base64,') && str_contains($es['body'], 'name="logo_remove"'), 'Account form previews the logo with a remove option');
    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('APP_BASE_URL', " . $pdo->quote(rtrim($base, '/')) . ")");
    $cfg = (new \Pase\Services\MailAccounts($pdo))->mailerConfig(501);
    ok(str_contains($cfg['logo_url'], 'shop_logo.php?a=1&v='), 'Mail config has the public logo address');
    $logo = request(client(), substr($cfg['logo_url'], strlen($base)));
    ok($logo['status'] === 200 && str_contains($logo['headers'], 'image/png') && $logo['body'] === file_get_contents($png), 'Logo is served publicly for e-mail clients');
    ok(request(client(), 'shop_logo.php?a=99')['status'] === 404, 'Unknown account gives 404');
    $vars = (new \Pase\Automation\RuleEngine($pdo, new \Pase\Repository\AutomationRuleRepository($pdo)))->templateVars(501, json_decode((string) $pdo->query('SELECT payload FROM woo_orders WHERE woo_order_id = 501')->fetchColumn(), true));
    $html = \Pase\Services\Mailer::render(\Pase\Services\BasicEmailTemplates::templates()[0]['body'], $vars);
    ok(str_contains($html, '<p><img src="' . htmlspecialchars($cfg['logo_url']) . '" alt="Lumio"'), 'Basic template e-mail starts with the shop logo');
    $page3 = request($boss, 'admin/order_view.php?id=501');
    ok(!str_contains($page3['body'], 'shop_logo.php'), 'Reply picker text has no logo image');
    $es = request($boss, 'admin/email_settings.php?edit=1');
    request($boss, 'admin/email_settings.php', ['csrf' => csrfOf($es['body'])] + $acc + ['review_link' => 'https://g.page/r/nowy/review', 'logo_remove' => '1']);
    ok($pdo->query('SELECT logo_data FROM mail_accounts WHERE id = 1')->fetchColumn() === null, 'Logo can be removed');

    $viewer = login('vi');
    $vpage = request($viewer, 'admin/order_view.php?id=501');
    ok($vpage['status'] === 200 && !str_contains($vpage['body'], 'id="tplPickBtn"'), 'Viewer does not get the picker');

    $pdo->exec('DROP TABLE email_templates');
    $page = request($boss, 'admin/order_view.php?id=501');
    ok($page['status'] === 200 && str_contains($page['body'], 'Brak aktywnych szablonów'), 'Page still works without the templates table');
    $pdo->exec("CREATE TABLE email_templates (id INTEGER PRIMARY KEY, tpl_key TEXT, name TEXT, subject TEXT, body TEXT, is_active INTEGER, mail_account_id INTEGER NULL)");
    $pdo->exec("INSERT INTO email_templates (tpl_key, name, subject, body, is_active) VALUES ('a','Default','s','<p>Dzień dobry {{customer_name}}</p>',1),('b','Faktura','s','<p>W załączniku faktura do {{order_number}}.</p>',1)");

    // Dłuższy wątek: okno wiadomości ma własne przewijanie, długie wiadomości są zwinięte (sprawdza tests/reply_templates_ui.js).
    $mins = $pdo->prepare("INSERT INTO order_messages (woo_order_id, sender, author_name, body, created_at) VALUES (501, ?, ?, ?, ?)");
    for ($i = 1; $i <= 8; $i++) {
        $mins->execute([$i % 2 ? 'client' : 'staff', $i % 2 ? 'Anna Kowalska' : 'boss', "Wiadomość nr $i", sprintf('2026-09-2%d 10:00:00', $i)]);
    }
    $mins->execute(['staff', 'boss', str_repeat("Długa linia szablonu z podpisem sklepu.\n", 20), '2026-09-29 12:00:00']);

    $node = trim((string) shell_exec('command -v node'));
    $hasPw = $node !== '' && trim((string) shell_exec('NODE_PATH=' . escapeshellarg((string) getenv('NODE_PATH')) . ' ' . escapeshellarg($node)
        . ' -e "require.resolve(\'playwright\'); console.log(1)" 2>/dev/null')) === '1';
    if ($hasPw) {
        passthru('BASE=' . escapeshellarg($base) . ' NODE_PATH=' . escapeshellarg((string) getenv('NODE_PATH')) . ' ' . escapeshellarg($node) . ' ' . escapeshellarg("$root/tests/reply_templates_ui.js"), $rc);
        if ($rc !== 0) { echo implode('', preg_grep('/PHP |\[5\d\d\]/', file("$temp/server.log") ?: [])); }
        ok($rc === 0, 'Browser checks passed');
    } else {
        echo "SKIP: browser checks (no Playwright; set NODE_PATH)\n";
    }
    $warnings = preg_grep('/PHP (Warning|Fatal|Parse|Deprecated)/', file("$temp/server.log") ?: []);
    ok($warnings === [], 'No PHP warnings in the server log');

    echo "\nAll {$checks} reply template HTTP checks passed.\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (getenv('KEEP_TEMP')) { echo "temp: $temp\n"; } else { exec('rm -rf ' . escapeshellarg($temp)); }
}
