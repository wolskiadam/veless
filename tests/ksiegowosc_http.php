<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Rozszerzenie Księgowość przez HTTP: prawdziwe strony panelu z extensions/ksiegowosc - menu tylko dla osób z dostępem,
// własne uprawnienia (brak / podgląd / księgowanie / zarządzanie, niezależne od roli CRM), dane firmy z kontrolą NIP-u,
// rok obrotowy z wzorcowym planem kont, konto analityczne, dokument z dekretacją (szkic, błąd bilansu, zaksięgowanie),
// storno, obroty i salda (z CSV), zapisy na koncie, dziennik, historia zmian, skrzynka zdarzeń z automation.event.
// Baza SQLite.
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

$temp = sys_get_temp_dir() . '/crm-ksiegowosc-http-' . bin2hex(random_bytes(6));
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
    // Rozszerzenie zainstalowane jak przez FTP (extensions/ksiegowosc).
    exec('cp -r ' . escapeshellarg("$root/extensions/ksiegowosc") . ' ' . escapeshellarg("$temp/extensions/ksiegowosc"));
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
    foreach ([1 => ['boss', 'Adam', 'admin'], 2 => ['ola', 'Ola', 'editor'], 3 => ['vi', 'Wiktor', 'viewer'], 4 => ['jan', 'Jan', 'editor']] as $id => [$u, $n, $role]) {
        $ins->execute([$id, $u, $n, $role, password_hash('Only-Test-Password-42', PASSWORD_DEFAULT)]);
    }
    PagePermissions::migrate($pdo);
    \Pase\Services\AuditTrail::migrate($pdo);
    $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
    $pdo->exec('CREATE TABLE order_statuses (id INTEGER PRIMARY KEY, status_key TEXT, label TEXT, color TEXT, position INTEGER)');
    $pdo->exec("INSERT INTO order_statuses (status_key,label,color,position) VALUES ('new','Nowe','#8a8f98',1),('processing','W realizacji','#c28a2b',2),('shipped','Wysłane','#2f8f5b',3)");
    $pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT, is_active INTEGER, config TEXT)');
    $pdo->exec("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES (1, 'woocommerce', 'Mój Sklep', 1, '{}')");
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, pase_stock INTEGER, pase_price REAL, images TEXT, ean TEXT, actual_stock INTEGER NULL, actual_stock_set_at TEXT NULL)');
    $pdo->exec("INSERT INTO products (id, sku, name, pase_stock, pase_price, actual_stock) VALUES (1, 'GB-1L', 'GINGERBREAD 1l', 5, 49.9, 3), (2, 'MW-1L', 'MULLED WINE 1l', 5, 39.9, NULL)");
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
    $jan = login('jan');
    $url = static fn(string $page, array $q = []): string => 'admin/ext.php?' . http_build_query(['page' => 'ksiegowosc.' . $page] + $q);
    $post = static function (CurlHandle $h, string $page, array $data, array $q = []) use ($url): array {
        $csrf = csrfOf(request($h, 'admin/index.php')['body']);
        return request($h, $url($page, $q), ['csrf' => $csrf] + $data);
    };
    $loc = static fn(array $r): string => urldecode((string) (preg_match('/Location: (\S+)/', $r['headers'], $m) ? $m[1] : ''));
    $locId = static fn(array $r): string => preg_match('/[?&]id=([a-f0-9]{16})/', $loc($r), $mm) ? $mm[1] : '';
    $one = static fn(string $sql) => $pdo->query($sql)->fetchColumn();

    // ---- Wtyczka, menu, dostęp
    $pl = request($boss, 'admin/plugins.php')['body'];
    ok(str_contains($pl, 'Księgowość') && str_contains($pl, 'ext.php?page=ksiegowosc.dostep'), 'Plugins page lists the extension with its links');
    $home = request($boss, 'admin/index.php')['body'];
    ok(preg_match('#Księgowość\s*<svg#', $home) === 1 && str_contains($home, 'href="ext.php?page=ksiegowosc.pulpit"') && str_contains($home, 'href="ext.php?page=ksiegowosc.obroty"'), 'Admin has the Księgowość menu tab');
    $home = request($jan, 'admin/index.php')['body'];
    ok(!str_contains($home, 'ksiegowosc.pulpit'), 'User without access has no menu tab');
    $d = request($jan, $url('pulpit'));
    ok($d['status'] === 200 && str_contains($d['body'], 'Brak dostępu do księgowości'), 'User without access is stopped');
    ok(str_contains(request($jan, $url('obroty_csv'))['headers'], ' 403'), 'Raw CSV refused without access');
    $d = request($boss, $url('pulpit'))['body'];
    ok(str_contains($d, 'Księgowość jest gotowa do ustawienia') && str_contains($d, 'ext_ksg_'), 'Empty dashboard shows the first steps');
    ok((int) $one("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE 'ext_ksg_%'") === 8, 'Own tables created on first use');

    $acc = request($boss, $url('dostep'))['body'];
    ok(str_contains($acc, 'name="level[2]"') && str_contains($acc, 'name="level[4]"') && !str_contains($acc, 'name="level[1]"') && str_contains($acc, 'Pełny (administrator CRM)'), 'Access page lists users (admin fixed)');
    $r = $post($boss, 'dostep_zapisz', ['level' => [2 => 'book', 3 => 'view', 4 => 'none', 1 => 'none']]);
    ok($r['status'] === 302 && $one('SELECT level FROM ext_ksg_access WHERE user_id = 2') === 'book' && $one('SELECT level FROM ext_ksg_access WHERE user_id = 3') === 'view'
        && $one('SELECT COUNT(*) FROM ext_ksg_access WHERE user_id IN (1, 4)') == 0, 'Access saved (admin row ignored)');
    ok(str_contains(request($ola, 'admin/index.php')['body'], 'ksiegowosc.pulpit') && str_contains(request($vi, 'admin/index.php')['body'], 'ksiegowosc.pulpit'), 'Menu shows for users with access');
    ok(str_contains(request($ola, $url('dostep'))['body'], 'Brak dostępu do księgowości'), 'Book level cannot manage access');
    $r = $post($ola, 'dostep_zapisz', ['level' => [4 => 'manage']]);
    ok(str_contains($r['body'], 'Brak dostępu') && $one('SELECT COUNT(*) FROM ext_ksg_access WHERE user_id = 4') == 0, 'Book level cannot grant access');
    $tabs = request($ola, $url('pulpit'))['body'];
    ok(str_contains($tabs, 'ksiegowosc.dokumenty') && !str_contains($tabs, 'ksiegowosc.dostep"'), 'Tabs follow the access level');

    // ---- Dane firmy
    $r = $post($boss, 'firma_zapisz', ['c' => ['name' => 'Klub Woskarzy sp. z o.o.', 'nip' => '952-228-22-85', 'legal_form' => 'spzoo', 'vat_period' => 'month']]);
    ok(str_contains(request($boss, $url('firma'))['body'], 'złą sumę kontrolną'), 'Wrong NIP rejected');
    $r = $post($boss, 'firma_zapisz', ['c' => ['name' => 'Klub Woskarzy sp. z o.o.', 'nip' => 'PL 952-228-22-84', 'city' => 'Warszawa', 'legal_form' => 'spzoo', 'vat_period' => 'month'], 'inbox' => '1']);
    $c = json_decode((string) $one("SELECT v FROM ext_ksg_meta WHERE k = 'company'"), true);
    ok(($c['nip'] ?? '') === 'PL9522282284' && $c['city'] === 'Warszawa' && $one("SELECT v FROM ext_ksg_meta WHERE k = 'inbox'") === '1', 'Company data saved in the module database');
    ok($one("SELECT COUNT(*) FROM settings WHERE setting_key LIKE '%KSG%' OR setting_key LIKE '%KSIEGOWOSC%'") == 0, 'Nothing written to CRM settings');

    // ---- Rok obrotowy i plan kont
    ok(str_contains(request($ola, $url('dokumenty'))['body'], 'Najpierw załóż rok obrotowy'), 'Documents need a year first');
    $r = $post($ola, 'rok_zapisz', ['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'chart' => 'template']);
    ok((int) $one('SELECT COUNT(*) FROM ext_ksg_years') === 0, 'Book level cannot create a year');
    $r = $post($boss, 'rok_zapisz', ['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'chart' => 'template']);
    ok($r['status'] === 302 && (int) $one('SELECT COUNT(*) FROM ext_ksg_accounts') > 40, 'Year created with the template chart');
    $yid = (string) $one('SELECT id FROM ext_ksg_years');
    $plan = request($vi, $url('konta'))['body'];
    ok(str_contains($plan, 'Rachunek bieżący') && str_contains($plan, 'Kapitał zakładowy') && !str_contains($plan, 'Dodaj konto'), 'Viewer sees the chart without the form');
    $r = $post($boss, 'konto_zapisz', ['year_id' => $yid, 'number' => '200-01', 'name' => 'Allegro <b>', 'kind' => 'AP']);
    ok($r['status'] === 302 && str_contains(request($boss, $url('konta'))['body'], 'Allegro &lt;b&gt;'), 'Analytic account added (escaped)');
    $r = $post($boss, 'konto_zapisz', ['year_id' => $yid, 'number' => '200-01', 'name' => 'Dubel', 'kind' => 'AP']);
    ok($r['status'] === 200 && str_contains($r['body'], 'już jest w planie kont') && str_contains($r['body'], 'value="Dubel"'), 'Duplicate account refused, form keeps input');

    // ---- Dokument: szkic, błąd bilansu, zaksięgowanie
    $form = request($ola, $url('dokument_nowy'))['body'];
    ok(str_contains($form, '<datalist id="kg-accounts">') && str_contains($form, '130-01 Rachunek bieżący') && !str_contains($form, 'value="130 Rachunki bankowe"'), 'Form suggests only posting accounts');
    ok(str_contains(request($vi, $url('dokument_nowy'))['body'], 'Brak dostępu'), 'Viewer cannot open the form');
    $r = $post($ola, 'dokument_zapisz', ['type' => 'BO', 'posting_date' => '2026-01-01', 'description' => 'Bilans otwarcia', 'save' => '1',
        'lines' => [['account' => '130-01 Rachunek bieżący', 'debit' => '10 000,00', 'credit' => ''], ['account' => '800', 'debit' => '', 'credit' => '9000']]]);
    $bo = $locId($r);
    $view = request($ola, $url('dokument', ['id' => $bo]))['body'];
    ok($r['status'] === 302 && str_contains($view, 'BO/1/01/2026') && str_contains($view, 'Szkic') && str_contains($view, 'nie bilansują'), 'Unbalanced draft saved with a warning');
    $r = $post($ola, 'dokument_ksieguj', ['id' => $bo]);
    ok($one("SELECT status FROM ext_ksg_docs WHERE id = '$bo'") === 'draft' && str_contains(request($ola, $url('dokument', ['id' => $bo]))['body'], 'Nie można zaksięgować'), 'Unbalanced draft is not posted');
    $r = $post($ola, 'dokument_zapisz', ['type' => 'PK', 'posting_date' => '2026-01-01', 'description' => '', 'lines' => [['account' => '100', 'debit' => '1', 'credit' => '']]]);
    ok($r['status'] === 200 && str_contains($r['body'], 'Wpisz opis operacji') && str_contains($r['body'], 'value="100"'), 'Invalid document shows the form again with input');
    $r = $post($ola, 'dokument_zapisz', ['id' => $bo, 'type' => 'BO', 'posting_date' => '2026-01-01', 'description' => 'Bilans otwarcia', 'post' => '1',
        'lines' => [['account' => '130-01', 'debit' => '10 000,00', 'credit' => ''], ['account' => '800', 'debit' => '', 'credit' => '10000']]]);
    ok($one("SELECT status FROM ext_ksg_docs WHERE id = '$bo'") === 'posted' && (int) $one("SELECT journal_no FROM ext_ksg_docs WHERE id = '$bo'") === 1, 'Save and post gives journal position 1');
    $view = request($vi, $url('dokument', ['id' => $bo]))['body'];
    ok(str_contains($view, 'Zaksięgowany') && str_contains($view, 'pozycja dziennika <b>1</b>') && !str_contains($view, 'Wystornuj') && !str_contains($view, 'Popraw'), 'Viewer sees a posted document without actions');
    $r = $post($ola, 'dokument_zapisz', ['id' => $bo, 'type' => 'BO', 'posting_date' => '2026-01-01', 'description' => 'Zmiana', 'lines' => [['account' => '100', 'debit' => '1'], ['account' => '800', 'credit' => '1']]]);
    ok(str_contains($r['body'], 'popraw go stornem') && $one("SELECT description FROM ext_ksg_docs WHERE id = '$bo'") === 'Bilans otwarcia', 'Posted document cannot be changed');
    $r = $post($vi, 'dokument_ksieguj', ['id' => $bo]);
    ok($r['status'] === 403, 'CRM viewer role cannot post at all');

    $r = $post($ola, 'dokument_zapisz', ['type' => 'FS', 'number' => 'FV/1/2026', 'posting_date' => '2026-02-10', 'description' => 'Sprzedaż świec', 'counterparty' => 'Anna Kowalska', 'post' => '1',
        'lines' => [['account' => '200-01', 'debit' => '1230', 'credit' => ''], ['account' => '700', 'credit' => '1000'], ['account' => '222', 'credit' => '230', 'description' => 'VAT 23%']]]);
    $fs = $locId($r);
    ok((int) $one("SELECT journal_no FROM ext_ksg_docs WHERE id = '$fs'") === 2, 'Sales invoice posted as position 2');
    $list = request($vi, $url('dokumenty', ['q' => 'świec']))['body'];
    ok(str_contains($list, 'FV/1/2026') && !str_contains($list, 'BO/1/01/2026') && str_contains($list, "1\u{a0}230,00"), 'Document list with search');

    // ---- Storno
    $r = $post($ola, 'dokument_storno', ['id' => $fs, 'date' => '2026-02-28', 'reason' => 'zły kontrahent']);
    $st = $locId($r);
    $sv = request($ola, $url('dokument', ['id' => $st]))['body'];
    ok(str_contains($sv, 'ST/1/02/2026') && str_contains($sv, 'Storno dokumentu') && str_contains($sv, '-1') && (int) $one("SELECT journal_no FROM ext_ksg_docs WHERE id = '$st'") === 3, 'Storno posted as position 3');
    ok(str_contains(request($ola, $url('dokument', ['id' => $fs]))['body'], 'Wystornowany'), 'Original marked reversed');

    // ---- Zestawienia
    $tb = request($vi, $url('obroty', ['od' => '2026-01-01', 'do' => '2026-12-31']))['body'];
    ok(str_contains($tb, '✓ Obroty Wn i Ma są równe') && str_contains($tb, 'Kapitał zakładowy') && !str_contains($tb, 'Środki trwałe w budowie'), 'Trial balance balanced, empty accounts hidden');
    $csv = request($vi, $url('obroty_csv', ['od' => '2026-01-01', 'do' => '2026-12-31']));
    ok(str_contains($csv['headers'], 'text/csv') && str_contains($csv['body'], '130-01;"Rachunek bieżący";10000,00;0,00;10000,00;0,00;10000,00;0,00') && str_contains($csv['body'], 'Allegro <b>'), 'CSV export');
    $aid = (string) $one("SELECT id FROM ext_ksg_accounts WHERE number = '700'");
    $kl = request($vi, $url('konto', ['id' => $aid]))['body'];
    ok(str_contains($kl, 'FV/1/2026') && str_contains($kl, 'ST/1/02/2026') && str_contains($kl, 'Przychody ze sprzedaży produktów'), 'Account ledger lists entries');
    $j = request($vi, $url('dziennik'))['body'];
    ok(strpos($j, 'Poz. 1') < strpos($j, 'Poz. 2') && str_contains($j, 'Poz. 3'), 'Journal in order');
    $dash = request($ola, $url('pulpit'))['body'];
    ok(str_contains($dash, 'obroty Wn i Ma są równe') && str_contains($dash, 'Klub Woskarzy sp. z o.o.'), 'Dashboard shows the books check');

    // ---- Zamknięcie okresu
    $post($boss, 'rok_blokada', ['id' => $yid, 'locked_until' => '2026-02-28']);
    $r = $post($ola, 'dokument_zapisz', ['type' => 'PK', 'posting_date' => '2026-02-15', 'description' => 'Po terminie', 'post' => '1',
        'lines' => [['account' => '409', 'debit' => '5'], ['account' => '100', 'credit' => '5']]]);
    $late = $locId($r);
    ok($one("SELECT status FROM ext_ksg_docs WHERE id = '$late'") === 'draft' && str_contains(request($ola, $url('dokument', ['id' => $late]))['body'], 'jest zamknięty'), 'Locked period keeps the document as a draft');
    $r = $post($ola, 'dokument_usun', ['id' => $late]);
    ok($one("SELECT COUNT(*) FROM ext_ksg_docs WHERE id = '$late'") == 0, 'Draft deleted');

    // ---- Historia i skrzynka
    $h = request($boss, $url('zmiany'))['body'];
    ok(str_contains($h, 'Zaksięgowanie') && str_contains($h, 'Storno') && str_contains($h, 'Zamknięcie okresu') && str_contains($h, 'Ola'), 'History of changes');
    $pdo->exec("INSERT INTO ext_ksg_inbox (id, event, ref_type, ref_id, payload, received_at, processed_at, result) VALUES
        ('aaaaaaaaaaaaaaaa', 'order.imported', 'order', '501', '{\"total\":\"114.80\",\"currency\":\"PLN\",\"billing\":{\"first_name\":\"Anna\",\"last_name\":\"Kowalska\"}}', '2026-11-10 10:00:00', NULL, '')");
    $ib = request($boss, $url('skrzynka'))['body'];
    ok(str_contains($ib, 'Nowe zamówienie') && str_contains($ib, 'Anna Kowalska') && str_contains($ib, '114.80 PLN') && str_contains($ib, 'włączone'), 'Inbox page lists events');

    // ---- Moduł nie dotknął tabel CRM
    ok((int) $one('SELECT COUNT(*) FROM woo_orders') === 0 && (int) $one('SELECT pase_stock FROM products WHERE id = 1') === 5, 'CRM tables untouched');

    $warnings = preg_grep('/PHP (Warning|Fatal|Parse|Deprecated|Notice)|Rozszerzenie .* błąd/', file("$temp/server.log") ?: []);
    ok($warnings === [], 'No PHP warnings or extension errors' . ($warnings ? ': ' . implode(' | ', array_map('trim', $warnings)) : ''));
    echo "PASS: {$checks} Księgowość HTTP checks\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (getenv('KEEP_TEMP')) { echo "temp: $temp\n"; } else { exec('rm -rf ' . escapeshellarg($temp)); }
}
