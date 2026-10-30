<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Rozszerzenie Paragony przez HTTP: prawdziwe strony panelu z extensions/paragony - dodanie paragonu ze zdjęciem,
// lista miesiącami z sumami, wyszukiwanie, podgląd, zdjęcie tylko po zalogowaniu, poprawianie, ZIP miesiąca, usuwanie
// i uprawnienia ról (podgląd nie dodaje ani nie usuwa). Baza SQLite.
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

$temp = sys_get_temp_dir() . '/crm-paragony-http-' . bin2hex(random_bytes(6));
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
    // Rozszerzenie zainstalowane jak przez FTP (extensions/paragony).
    exec('cp -r ' . escapeshellarg("$root/extensions/paragony") . ' ' . escapeshellarg("$temp/extensions/paragony"));
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

    // ---- Wtyczka na stronie Wtyczki, pusta lista
    $pl = request($boss, 'admin/plugins.php');
    ok(str_contains($pl['body'], 'Paragony') && str_contains($pl['body'], 'name="cfg[nip]"'), 'Plugins page lists the extension with its setting');
    $r = request($boss, 'admin/plugins.php', ['csrf' => csrfOf($pl['body']), 'action' => 'ext_settings', 'slug' => 'paragony', 'cfg' => ['nip' => '526-025-02-74']]);
    ok($r['status'] === 200 && str_contains($r['body'], 'Zapisano ustawienia'), 'Settings are saved');
    $list = request($ola, 'admin/ext.php?page=paragony.lista');
    ok($list['status'] === 200 && str_contains($list['body'], 'Brak paragonów') && str_contains($list['body'], '+ Dodaj paragon'), 'Empty list with the add button for editors');
    ok(str_contains(request($boss, 'admin/index.php')['body'], 'href="ext.php?page=paragony.lista"'), 'Menu entry');
    ok(!str_contains(request($vi, 'admin/ext.php?page=paragony.lista')['body'], '+ Dodaj paragon'), 'Viewer sees no add button');
    ok(request($vi, 'admin/ext.php?page=paragony.dodaj')['status'] === 403, 'Viewer cannot open the form');

    // ---- Dodanie paragonu ze zdjęciem
    $form = request($ola, 'admin/ext.php?page=paragony.dodaj');
    ok($form['status'] === 200 && str_contains($form['body'], 'enctype="multipart/form-data"') && str_contains($form['body'], 'name="photo"')
        && str_contains($form['body'], '526-025-02-74'), 'Form has the photo field and the company NIP reminder');
    $img = imagecreatetruecolor(40, 60);
    imagefill($img, 0, 0, imagecolorallocate($img, 250, 250, 240));
    imagejpeg($img, "$temp/paragon.jpg");
    file_put_contents("$temp/nie.jpg", '<?php echo 1;');
    $fields = ['csrf' => csrfOf($form['body']), 'purchase_date' => '2026-10-05', 'seller' => 'Castorama Polska', 'seller_nip' => '527-10-08-454',
        'gross' => '1 234,50', 'vat' => '230,85', 'payment' => 'card', 'receipt_no' => '1234/56', 'description' => 'Kartony i taśma do wysyłek'];
    $r = request($ola, 'admin/ext.php?page=paragony.dodaj', $fields + ['photo' => new CURLFile("$temp/nie.jpg", 'image/jpeg', 'nie.jpg')]);
    ok($r['status'] === 200 && str_contains($r['body'], 'To nie jest zdjęcie') && str_contains($r['body'], 'value="Castorama Polska"'), 'A file that is not a photo is refused and the form keeps the values');
    $r = request($ola, 'admin/ext.php?page=paragony.dodaj', ['photo' => null] + $fields);
    ok($r['status'] === 200 && str_contains($r['body'], 'Dodaj zdjęcie paragonu'), 'Photo is required');
    $r = request($ola, 'admin/ext.php?page=paragony.dodaj', $fields + ['photo' => new CURLFile("$temp/paragon.jpg", 'image/jpeg', 'IMG_2026.jpg')]);
    $row = $pdo->query('SELECT * FROM ext_paragony_receipts')->fetch(PDO::FETCH_ASSOC);
    ok($r['status'] === 302 && str_contains($r['headers'], 'page=paragony.lista&y=2026#p-') && $row['gross'] == 1234.5 && $row['vat'] == 230.85
        && $row['seller_nip'] === '5271008454' && $row['mime'] === 'image/jpeg' && is_file("$temp/storage/uploads/paragony/" . $row['file']), 'Receipt is saved with the photo outside the web root');
    $pdo->prepare("INSERT INTO ext_paragony_receipts (id, purchase_date, seller, seller_nip, receipt_no, gross, vat, payment, description, file, file_name, mime, created_by, created_at, updated_at)
        VALUES (?, ?, ?, '', '', ?, NULL, 'cash', ?, ?, 'x.jpg', 'image/jpeg', 'Ola', '2026-09-01 10:00:00', '2026-09-01 10:00:00')")
        ->execute(['aaaaaaaaaaaaaaaa', '2026-09-12', 'Biedronka', 15.99, 'Woda', $row['file']]);
    $pdo->prepare("INSERT INTO ext_paragony_receipts (id, purchase_date, seller, seller_nip, receipt_no, gross, vat, payment, description, file, file_name, mime, created_by, created_at, updated_at)
        VALUES (?, ?, ?, '', '', ?, NULL, '', '', 'brak.jpg', 'x.jpg', 'image/jpeg', 'Ola', '2026-09-01 10:00:00', '2026-09-01 10:00:00')")
        ->execute(['bbbbbbbbbbbbbbbb', '2026-09-20', 'Orlen', 100]);

    // ---- Lista miesiącami
    $list = request($vi, 'admin/ext.php?page=paragony.lista&y=2026');
    $b = $list['body'];
    ok(str_contains($b, '>Październik 2026</h3>') && str_contains($b, '>Wrzesień 2026</h3>') && strpos($b, 'Październik 2026') < strpos($b, 'Wrzesień 2026')
        && str_contains($b, '3 paragony') && str_contains($b, "1\u{00A0}350,49\u{00A0}zł") && str_contains($b, '2 paragony · <b>115,99'), 'List is grouped by month, newest first, with sums');
    ok(str_contains($b, 'Castorama Polska') && str_contains($b, 'NIP 5271008454') && str_contains($b, 'Kartony i taśma') && str_contains($b, '05.10.2026<span class="pg-pay"> · Karta')
        && str_contains($b, 'page=paragony.zip&amp;m=2026-10'), 'Row shows seller, NIP, description, date, payment and the month ZIP link');
    $s = request($vi, 'admin/ext.php?page=paragony.lista&y=2026&q=' . urlencode('527-10'));
    ok(str_contains($s['body'], 'Castorama') && !str_contains($s['body'], 'Biedronka'), 'Search by NIP with dashes');
    $s = request($vi, 'admin/ext.php?page=paragony.lista&y=2026&q=woda');
    ok(str_contains($s['body'], 'Biedronka') && !str_contains($s['body'], 'Castorama'), 'Search by description');
    ok(str_contains(request($vi, 'admin/ext.php?page=paragony.lista&y=2025')['body'], 'Brak paragonów z 2025'), 'Other year is empty');

    // ---- Podgląd, plik
    $id = $row['id'];
    $v = request($vi, 'admin/ext.php?page=paragony.zobacz&id=' . $id);
    ok($v['status'] === 200 && str_contains($v['body'], '1234/56') && str_contains($v['body'], '230,85') && !str_contains($v['body'], 'Popraw'), 'Viewer sees the receipt without edit buttons');
    $f = request($vi, 'admin/ext.php?page=paragony.plik&id=' . $id);
    ok($f['status'] === 200 && $f['body'] === file_get_contents("$temp/paragon.jpg") && str_contains($f['headers'], 'Content-Type: image/jpeg'), 'Photo is served to a logged-in user');
    ok(request(client(), 'admin/ext.php?page=paragony.plik&id=' . $id)['status'] !== 200, 'Photo is not served without login');
    ok(request($vi, 'admin/ext.php?page=paragony.plik&id=bbbbbbbbbbbbbbbb')['status'] === 404, 'Missing file is 404');

    // ---- Poprawienie bez nowego zdjęcia
    $e = request($ola, 'admin/ext.php?page=paragony.dodaj&id=' . $id);
    ok(str_contains($e['body'], 'Popraw paragon') && str_contains($e['body'], 'value="1234,50"') && !preg_match('/name="photo"[^>]*required/', $e['body']), 'Edit form is filled in, photo optional');
    $r = request($ola, 'admin/ext.php?page=paragony.dodaj', ['id' => $id, 'photo' => null] + array_merge($fields, ['csrf' => csrfOf($e['body']), 'purchase_date' => '2026-09-30', 'gross' => '99,90', 'vat' => '']));
    $row2 = $pdo->query("SELECT * FROM ext_paragony_receipts WHERE id = '$id'")->fetch(PDO::FETCH_ASSOC);
    ok($r['status'] === 302 && $row2['purchase_date'] === '2026-09-30' && $row2['gross'] == 99.9 && $row2['vat'] === null && $row2['file'] === $row['file'], 'Edit keeps the photo');

    // ---- ZIP miesiąca
    $z = request($vi, 'admin/ext.php?page=paragony.zip&m=2026-09');
    file_put_contents("$temp/m.zip", $z['body']);
    $zip = new ZipArchive();
    ok($z['status'] === 200 && $zip->open("$temp/m.zip") === true, 'Month ZIP downloads');
    $names = [];
    for ($i = 0; $i < $zip->numFiles; ++$i) { $names[] = $zip->getNameIndex($i); }
    $csv = (string) $zip->getFromName('zestawienie-2026-09.csv');
    ok(in_array('2026-09-12_Biedronka_15,99.jpg', $names, true) && in_array('2026-09-30_Castorama-Polska_99,90.jpg', $names, true) && count($names) === 3
        && str_contains($csv, '"Castorama Polska";"5271008454"') && str_contains($csv, '"Razem";"";"";"";"215,89"'), 'ZIP has the photos named for the accountant and the CSV summary');
    $zip->close();

    // ---- Usuwanie
    ok(request($vi, 'admin/ext.php?page=paragony.usun', ['csrf' => csrfOf(request($vi, 'admin/index.php')['body']), 'id' => $id])['status'] === 403, 'Viewer cannot delete');
    $e = request($ola, 'admin/ext.php?page=paragony.zobacz&id=' . $id);
    ok(str_contains($e['body'], 'Popraw') && str_contains($e['body'], 'page=paragony.usun'), 'Editor sees edit and delete');
    $pdo->exec("UPDATE ext_paragony_receipts SET file = 'cccccccccccccccccccccccccccccccc.jpg' WHERE id = 'aaaaaaaaaaaaaaaa'"); // drugi wpis nie dzieli już pliku
    $r = request($ola, 'admin/ext.php?page=paragony.usun', ['csrf' => csrfOf($e['body']), 'id' => $id]);
    ok($r['status'] === 302 && $pdo->query("SELECT COUNT(*) FROM ext_paragony_receipts WHERE id = '$id'")->fetchColumn() == 0
        && !is_file("$temp/storage/uploads/paragony/" . $row['file']), 'Delete removes the row and the photo');

    $warnings = preg_grep('/PHP (Warning|Fatal|Parse|Deprecated|Notice)|Rozszerzenie .* błąd/', file("$temp/server.log") ?: []);
    ok($warnings === [], 'No PHP warnings or extension errors' . ($warnings ? ': ' . implode(' | ', array_map('trim', $warnings)) : ''));
    echo "PASS: {$checks} Paragony HTTP checks\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (getenv('KEEP_TEMP')) { echo "temp: $temp\n"; } else { exec('rm -rf ' . escapeshellarg($temp)); }
}
