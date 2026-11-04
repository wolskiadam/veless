<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Rozszerzenie Messenger i Instagram (extensions/meta) przez HTTP: ustawienia z tokenem, token strony z tokenu użytkownika
// systemowego, pobieranie rozmów z Messengera i Instagrama (udawany serwer Graph API), lista i rozmowa, zdjęcie tylko
// po zalogowaniu, nieprzeczytane, odpowiedź w oknie 24 h (i blokada po nim), „Utwórz zamówienie” z danymi z wiadomości,
// połączenie zamówienia z rozmową (ikona na liście, sekcja na karcie), uprawnienia ról, wygasły token. Baza SQLite.
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
        CURLOPT_TIMEOUT => 15, CURLOPT_PROXY => '', CURLOPT_HEADER => true]);
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
    return ['status' => curl_getinfo($h, CURLINFO_RESPONSE_CODE), 'headers' => substr($res, 0, $len), 'body' => substr($res, $len)];
}
function login(string $user): CurlHandle {
    $h = client();
    $page = request($h, 'admin/login.php');
    $r = request($h, 'admin/login.php', ['csrf' => csrfOf($page['body']), 'username' => $user, 'password' => 'Only-Test-Password-42']);
    if (!in_array($r['status'], [302, 303], true)) { throw new RuntimeException('Login failed for ' . $user); }
    return $h;
}
function csrfOf(string $body): string {
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $m);
    return $m[1] ?? '';
}
function follow(CurlHandle $h, array $r): array {
    preg_match('/^Location:\s*(\S+)/mi', $r['headers'], $m);
    return request($h, 'admin/' . ltrim((string) ($m[1] ?? 'index.php'), '/'));
}

$temp = sys_get_temp_dir() . '/crm-meta-http-' . bin2hex(random_bytes(6));
foreach (['public/admin/assets', 'public/pack/assets', 'config', 'storage/security', 'lang', 'extensions', 'integrations/woocommerce', 'fake'] as $d) { mkdir("$temp/$d", 0700, true); }
$process = $fake = null;
try {
    file_put_contents("$temp/.env", "TOTP_REQUIRE_ADMIN=0\nTOTP_KEY_FILE=$temp/storage/security/totp.key\n");
    file_put_contents("$temp/storage/security/totp.key", base64_encode(random_bytes(32)));
    foreach (glob("$root/public/admin/*.php") as $f) { if (!in_array(basename($f), ['bootstrap_admin.php', 'db_admin.php'], true)) { copy($f, "$temp/public/admin/" . basename($f)); } }
    copy("$root/public/admin/assets/loc-picker.js", "$temp/public/admin/assets/loc-picker.js");
    foreach (glob("$root/lang/*.php") as $f) { copy($f, "$temp/lang/" . basename($f)); }
    foreach (glob("$root/integrations/woocommerce/*.php") as $f) { copy($f, "$temp/integrations/woocommerce/" . basename($f)); }
    exec('cp -r ' . escapeshellarg("$root/extensions/meta") . ' ' . escapeshellarg("$temp/extensions/meta"));
    file_put_contents("$temp/public/admin/bootstrap_admin.php", '<?php \Pase\Support\PagePermissions::migrate($pdo);');
    $boot = '<?php if (!defined("PASE_ROOT")) { define("PASE_ROOT", ' . var_export($temp, true) . ');'
        . 'spl_autoload_register(static function($c) { if (str_starts_with($c, "Pase\\\\")) require ' . var_export("$root/src/", true)
        . ' . str_replace("\\\\", "/", substr($c, 5)) . ".php";'
        . ' if (str_starts_with($c, "PasePlugin\\\\")) { [$v, $r] = explode("\\\\", substr($c, 11), 2); require PASE_ROOT . "/integrations/" . strtolower($v) . "/" . $r . ".php"; } });'
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
    $pdo->exec("INSERT INTO order_statuses (status_key,label,color,position) VALUES ('new','Nowe','#8a8f98',1),('processing','W realizacji','#c28a2b',2)");
    $pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT, is_active INTEGER, config TEXT, webhook_secret TEXT NULL, access_token TEXT NULL, refresh_token TEXT NULL, token_expires_at TEXT NULL)');
    $pdo->exec("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES (1, 'woocommerce', 'Mój Sklep', 1, '{}')");
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, pase_stock INTEGER, actual_stock INTEGER NULL, pase_price REAL, images TEXT, ean TEXT)');
    $pdo->exec("INSERT INTO products (id, sku, name, pase_stock, pase_price) VALUES (1, 'GB-1L', 'GINGERBREAD 1l', 5, 49.9)");
    $pdo->exec("CREATE TABLE woo_orders (id INTEGER PRIMARY KEY, woo_order_id INTEGER UNIQUE, integration_id INTEGER, pase_number INTEGER, order_number TEXT, status TEXT,
        pase_status TEXT, currency TEXT, total REAL, customer_name TEXT, customer_email TEXT, date_created TEXT, imported_at TEXT, updated_at TEXT,
        lifecycle TEXT DEFAULT 'active', payload TEXT, local_items TEXT NULL, local_edited_at TEXT NULL, local_billing_email TEXT NULL,
        local_billing_phone TEXT NULL, local_invoice TEXT NULL, local_pickup_point TEXT NULL, forwarded_to TEXT NULL, client_token TEXT NULL,
        automations_done INTEGER DEFAULT 0, internal_status TEXT NULL, no_shipping INTEGER NULL)");
    $pdo->exec('CREATE TABLE shipments (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, courier_code TEXT, waybill_no TEXT, bl_order_id TEXT,
        order_ref TEXT, price REAL, status TEXT, label_link TEXT, request_payload TEXT, response_payload TEXT, created_at TEXT,
        tracking_status TEXT, tracking_code TEXT, tracking_carrier TEXT, tracking_at TEXT, tracking_checked_at TEXT, tracking_events TEXT)');
    $pdo->exec("CREATE TABLE order_messages (id INTEGER PRIMARY KEY, woo_order_id INTEGER, sender TEXT, author_name TEXT, body TEXT,
        attachment_path TEXT, attachment_name TEXT, is_read INTEGER DEFAULT 0, created_at TEXT)");
    $pdo->exec("CREATE TABLE order_documents (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, provider TEXT, document_type TEXT,
        remote_id TEXT, status TEXT DEFAULT 'issued', message TEXT, created_at TEXT, updated_at TEXT)");
    foreach ([\Pase\Services\OrderReturns::class, \Pase\Services\AllegroFeedback::class, \Pase\Services\Customers::class,
        \Pase\Services\DocumentIssueGuard::class, \Pase\Services\OrderMerge::class, \Pase\Services\OrderPayment::class] as $svc) { $svc::migrate($pdo); }
    $pdo->exec('CREATE TABLE product_mappings (id INTEGER PRIMARY KEY, sku TEXT, allegro_offer_id TEXT)');
    $pdo->exec('CREATE TABLE email_templates (id INTEGER PRIMARY KEY, tpl_key TEXT, name TEXT, subject TEXT, body TEXT, is_active INTEGER, mail_account_id INTEGER NULL)');
    $pdo->exec("CREATE TABLE automation_rules (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, event TEXT, is_active INTEGER, priority INTEGER,
        conditions TEXT, actions TEXT, action_type TEXT NULL, action_params TEXT NULL)");

    // Udawany Graph API: token użytkownika systemowego -> token strony, Messenger i Instagram połączony ze stroną,
    // wysyłka odpowiedzi. Pliki-flagi w katalogu fake/ zmieniają odpowiedzi (nowa wiadomość, wygasły token).
    $png = (static function (): string { $i = imagecreatetruecolor(8, 8); ob_start(); imagepng($i); return (string) ob_get_clean(); })();
    file_put_contents("$temp/fake/img.png", $png);
    file_put_contents("$temp/fake/router.php", <<<'PHP'
<?php
$d = __DIR__;
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $q);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
file_put_contents("$d/requests.log", $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . ' | ' . $auth . "\n", FILE_APPEND);
if ($path === '/img.png') { header('Content-Type: image/png'); readfile("$d/img.png"); return; }
header('Content-Type: application/json');
$err = static function (int $http, int $code, string $msg): void { http_response_code($http); echo json_encode(['error' => ['message' => $msg, 'type' => 'OAuthException', 'code' => $code]]); };
if (is_file("$d/expired")) { $err(400, 190, 'Error validating access token: Session has expired'); return; }
$t = static fn(int $ago): string => gmdate('Y-m-d\TH:i:sO', time() - $ago);
$self = 'http://' . $_SERVER['HTTP_HOST'];
if ($auth === 'Bearer user-token') {
    if ($path === '/v26.0/me/accounts') { echo json_encode(['data' => [['id' => '111', 'name' => 'Klub Woskarzy', 'access_token' => 'page-token']]]); return; }
    $err(400, 100, 'Use a page token'); return;
}
if ($auth !== 'Bearer page-token') { $err(400, 190, 'Invalid OAuth access token.'); return; }
if ($path === '/v26.0/me/accounts') { $err(400, 100, '(#100) Tried accessing nonexisting field (accounts) on node type (Page)'); return; }
if ($path === '/v26.0/me') { echo json_encode(['id' => '111', 'name' => 'Klub Woskarzy', 'instagram_business_account' => ['id' => '999', 'username' => 'klubwoskarzy']]); return; }
if ($path === '/v26.0/ig1') { echo json_encode(['name' => 'Ola Wosk', 'username' => 'ola.swiece', 'id' => 'ig1']); return; }
if ($path === '/v26.0/me/conversations' && ($q['platform'] ?? '') === 'messenger') {
    $anna = ['name' => 'Anna Nowak', 'id' => 'p1']; $page = ['name' => 'Klub Woskarzy', 'id' => '111'];
    $msgs = [
        ['id' => 'm_3', 'created_time' => $t(3000), 'from' => $anna, 'message' => '', 'attachments' => ['data' => [['mime_type' => 'image/png', 'image_data' => ['url' => "$self/img.png"]]]]],
        ['id' => 'm_2', 'created_time' => $t(3300), 'from' => $page, 'message' => 'Dzień dobry! Już sprawdzamy.'],
        ['id' => 'm_1', 'created_time' => $t(3600), 'from' => $anna, 'message' => "Dzień dobry, poproszę 2 świece Gingerbread\nAnna Nowak\nul. Lipowa 12/3\n00-123 Warszawa\ntel 600 700 800"],
    ];
    if (is_file("$d/more")) { array_unshift($msgs, ['id' => 'm_4', 'created_time' => $t(0), 'from' => $anna, 'message' => 'Jeszcze jedno pytanie']); }
    echo json_encode(['data' => [
        ['id' => 't_anna', 'updated_time' => is_file("$d/more") ? $t(0) : $t(3000), 'participants' => ['data' => [$anna, $page]], 'messages' => ['data' => $msgs]],
        ['id' => 't_jan', 'updated_time' => $t(3 * 86400), 'participants' => ['data' => [['name' => 'Jan Kowal', 'id' => 'p2'], $page]],
         'messages' => ['data' => [['id' => 'm_9', 'created_time' => $t(3 * 86400), 'from' => ['name' => 'Jan Kowal', 'id' => 'p2'], 'message' => 'Czy wysyłacie za granicę?']]]],
    ], 'paging' => ['cursors' => ['before' => 'a', 'after' => 'b']]]);
    return;
}
if ($path === '/v26.0/me/conversations' && ($q['platform'] ?? '') === 'instagram') {
    $ola = ['username' => 'ola.swiece', 'id' => 'ig1']; $me = ['username' => 'klubwoskarzy', 'id' => '999'];
    echo json_encode(['data' => [['id' => 'aWdfZAG06MTpJR01lc3NhZA2VUaHJlYWQ6MTc4NDE0', 'updated_time' => $t(600), 'participants' => ['data' => [$me, $ola]],
        'messages' => ['data' => [['id' => 'aWdGGiblWZ_1', 'created_time' => $t(600), 'from' => $ola, 'to' => ['data' => [$me]], 'message' => 'Paczkomat KRA01N proszę, 512 345 678']]]]]]);
    return;
}
if ($path === '/v26.0/me/messages' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    file_put_contents("$d/sent.json", file_get_contents('php://input'));
    echo json_encode(['recipient_id' => 'p1', 'message_id' => 'm_sent_1']); return;
}
$err(404, 803, 'Unknown path ' . $path);
PHP);
    $free = static function (): string { $s = stream_socket_server('tcp://127.0.0.1:0'); $a = stream_socket_get_name($s, false); fclose($s); return $a; };
    $fakeAddr = $free();
    $fake = proc_open([PHP_BINARY, '-S', $fakeAddr, "$temp/fake/router.php"], [0 => ['pipe', 'r'], 1 => ['file', "$temp/fake.log", 'a'], 2 => ['file', "$temp/fake.log", 'a']], $fp);
    $address = $free();
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes, null,
        ['META_FB_URL' => "http://$fakeAddr", 'META_IG_URL' => "http://$fakeAddr"] + getenv());
    fclose($pipes[0]);
    foreach ([$address, $fakeAddr] as $a) {
        for ($i = 0; $i < 100; ++$i) { $p = @stream_socket_client('tcp://' . $a, $e1, $e2, 0.1); if ($p) { fclose($p); break; } usleep(10000); }
    }

    $boss = login('boss');
    $ola = login('ola');
    $vi = login('vi');

    // ---- Bez tokenu: pusta strona z instrukcją
    $pl = request($boss, 'admin/plugins.php');
    ok(str_contains($pl['body'], 'Messenger i Instagram') && str_contains($pl['body'], 'name="cfg[page_token]"') && str_contains($pl['body'], 'name="cfg[do_ig]"')
        && str_contains($pl['body'], 'id="pl-cfg-extension-meta"'), 'Plugins page lists the extension with its settings dialog');
    ok(str_contains($pl['body'], 'Nowe zamówienie') && str_contains($pl['body'], 'Meta (Facebook Messenger i Instagram)'), 'Permissions show the new-order form hooks');
    ok(str_contains(request($boss, 'admin/index.php')['body'], 'href="ext.php?page=meta.rozmowy"'), 'Menu entry');
    $empty = request($vi, 'admin/ext.php?page=meta.rozmowy');
    ok($empty['status'] === 200 && str_contains($empty['body'], 'Połącz stronę Facebook i Instagram') && str_contains($empty['body'], 'page=meta.instrukcja'), 'Without a token the page explains how to connect');
    $help = request($vi, 'admin/ext.php?page=meta.instrukcja');
    ok(str_contains($help['body'], 'Użytkownicy systemowi') && str_contains($help['body'], '<code>instagram_manage_messages</code>') && str_contains($help['body'], '<ol>'), 'Setup instructions are shown in the CRM');
    $olaCsrf = csrfOf(request($ola, 'admin/index.php')['body']);
    $r = request($ola, 'admin/ext.php?page=meta.pobierz', ['csrf' => $olaCsrf]);
    ok(str_contains(follow($ola, $r)['body'], 'Najpierw wpisz token Meta'), 'Sync without a token asks for it');

    // ---- Token użytkownika systemowego -> token strony
    $r = request($boss, 'admin/plugins.php', ['csrf' => csrfOf($pl['body']), 'action' => 'ext_settings', 'slug' => 'meta',
        'cfg' => ['page_token' => 'user-token', 'page_id' => '', 'do_fb' => '1', 'do_ig' => 'page', 'auto_every' => '5', 'first_days' => '30', 'api_version' => 'v26.0']]);
    ok($r['status'] === 200 && str_contains($r['body'], 'Zapisano ustawienia'), 'Settings are saved');
    $stored = (string) $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'EXT_META'")->fetchColumn();
    ok(str_contains($stored, '"page_token":"enc:v1:') && !str_contains($stored, 'user-token'), 'Token is stored encrypted');
    ok(request($vi, 'admin/ext.php?page=meta.pobierz', ['csrf' => csrfOf(request($vi, 'admin/index.php')['body'])])['status'] === 403, 'Viewer cannot sync');

    $r = request($ola, 'admin/ext.php?page=meta.pobierz', ['csrf' => $olaCsrf]);
    $page = follow($ola, $r);
    ok(str_contains($page['body'], 'Messenger (Klub Woskarzy): 4 nowe wiadomości') && str_contains($page['body'], 'Instagram (@klubwoskarzy): 1 nowa wiadomość'), 'Sync reports new messages per channel');
    ok((int) $pdo->query('SELECT COUNT(*) FROM ext_meta_conversations')->fetchColumn() === 3 && (int) $pdo->query('SELECT COUNT(*) FROM ext_meta_messages')->fetchColumn() === 5, 'Conversations and messages are saved');
    $cache = (string) $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'EXT_META_PAGE_TOKEN'")->fetchColumn();
    ok(str_starts_with($cache, 'enc:v1:'), 'Page token taken from the system user token is cached encrypted');
    $log = (string) file_get_contents("$temp/fake/requests.log");
    ok(!str_contains($log, 'access_token=') && str_contains($log, '| Bearer page-token'), 'Token goes in the Authorization header, never in the URL');
    $img = $pdo->query("SELECT attachments FROM ext_meta_messages WHERE body = ''")->fetchColumn();
    $att = json_decode((string) $img, true)[0] ?? [];
    ok(($att['type'] ?? '') === 'image' && is_file("$temp/storage/uploads/meta/" . ($att['file'] ?? '-')), 'Photo from a message is downloaded outside the web root');
    ok((int) $pdo->query('SELECT SUM(unread) FROM ext_meta_conversations')->fetchColumn() === 0, 'First import does not flood with unread badges');

    // ---- Lista i rozmowa
    $b = $page['body'];
    ok(str_contains($b, 'Anna Nowak') && str_contains($b, 'Ola Wosk') && str_contains($b, 'Jan Kowal') && strpos($b, 'Anna Nowak') < strpos($b, 'Jan Kowal'), 'List shows both channels, newest first');
    $anna = (string) $pdo->query("SELECT conv_key FROM ext_meta_conversations WHERE peer_name = 'Anna Nowak'")->fetchColumn();
    $jan = (string) $pdo->query("SELECT conv_key FROM ext_meta_conversations WHERE peer_name = 'Jan Kowal'")->fetchColumn();
    $igc = (string) $pdo->query("SELECT conv_key FROM ext_meta_conversations WHERE platform = 'ig'")->fetchColumn();
    ok(str_contains(request($vi, 'admin/ext.php?page=meta.rozmowy&f=ig')['body'], 'Ola Wosk') && !str_contains(request($vi, 'admin/ext.php?page=meta.rozmowy&f=ig')['body'], 'Anna Nowak'), 'Instagram tab shows only Instagram');
    ok(str_contains(request($vi, 'admin/ext.php?page=meta.rozmowy&q=Lipowa')['body'], 'Anna Nowak') && !str_contains(request($vi, 'admin/ext.php?page=meta.rozmowy&q=Lipowa')['body'], 'Jan Kowal'), 'Search finds words in messages');
    $t = request($ola, 'admin/ext.php?page=meta.rozmowy&c=' . $anna);
    ok(str_contains($t['body'], 'mt-wrap has-conv') && str_contains($t['body'], 'ul. Lipowa 12/3') && str_contains($t['body'], 'class="mt-msg me"')
        && str_contains($t['body'], 'name="mm[]"') && str_contains($t['body'], 'form="mt-order"') && str_contains($t['body'], 'Utwórz </span>zamówienie'), 'Conversation shows bubbles with pick boxes and the order button');
    ok(preg_match('~src="(ext\.php\?page=meta\.plik&amp;f=[a-f0-9]{32}\.png)"~', $t['body'], $m) === 1, 'Photo is shown from the CRM, not from Meta');
    $src = html_entity_decode($m[1]);
    $f = request($vi, 'admin/' . $src);
    ok($f['status'] === 200 && $f['body'] === $png && str_contains($f['headers'], 'Content-Type: image/png'), 'Photo is served to a logged-in user');
    ok(request(client(), 'admin/' . $src)['status'] !== 200, 'Photo is not served without login');
    ok(str_contains($t['body'], 'name="text"') && str_contains($t['body'], 'Odpowiedź możliwa do'), 'Reply form within 24 hours');
    $tj = request($ola, 'admin/ext.php?page=meta.rozmowy&c=' . $jan);
    ok(!str_contains($tj['body'], 'name="text"') && str_contains($tj['body'], 'Minęły 24 godziny') && str_contains($tj['body'], 'business.facebook.com'), 'After 24 hours the reply is blocked with a Business Suite link');
    $tv = request($vi, 'admin/ext.php?page=meta.rozmowy&c=' . $anna);
    ok(!str_contains($tv['body'], 'name="text"') && !str_contains($tv['body'], 'Utwórz zamówienie') && !str_contains($tv['body'], 'name="mm[]"'), 'Viewer reads without reply or order buttons');

    // ---- Nowa wiadomość: nieprzeczytane
    usleep(1_100_000);   // nowa wiadomość przychodzi po pierwszym pobraniu
    touch("$temp/fake/more");
    request($ola, 'admin/ext.php?page=meta.pobierz', ['csrf' => csrfOf($t['body'])]);
    $l = request($vi, 'admin/ext.php?page=meta.rozmowy');
    ok(str_contains($l['body'], 'mt-row unread') && str_contains($l['body'], '<span class="mt-badge">1</span>') && str_contains($l['body'], 'Jeszcze jedno pytanie'), 'New message is unread with a badge');
    request($vi, 'admin/ext.php?page=meta.rozmowy&c=' . $anna);
    ok((int) $pdo->query("SELECT unread FROM ext_meta_conversations WHERE conv_key = '$anna'")->fetchColumn() === 0, 'Opening the conversation marks it read');
    request($ola, 'admin/ext.php?page=meta.pobierz', ['csrf' => csrfOf($t['body'])]);
    ok((int) $pdo->query("SELECT COUNT(*) FROM ext_meta_messages WHERE conv_key = '$anna'")->fetchColumn() === 4, 'Syncing again does not duplicate messages');

    // ---- Odpowiedź
    ok(request($vi, 'admin/ext.php?page=meta.wyslij', ['csrf' => csrfOf(request($vi, 'admin/index.php')['body']), 'c' => $anna, 'text' => 'x'])['status'] === 403, 'Viewer cannot reply');
    $r = request($ola, 'admin/ext.php?page=meta.wyslij', ['csrf' => csrfOf($t['body']), 'c' => $anna, 'text' => 'Dziękujemy, wysyłamy jutro!']);
    $sent = json_decode((string) @file_get_contents("$temp/fake/sent.json"), true);
    $after = follow($ola, $r);
    ok(($sent['recipient']['id'] ?? '') === 'p1' && ($sent['message']['text'] ?? '') === 'Dziękujemy, wysyłamy jutro!' && ($sent['messaging_type'] ?? '') === 'RESPONSE', 'Reply goes to Meta to the right person');
    ok(str_contains($after['body'], 'Wysłano odpowiedź') && str_contains($after['body'], 'Dziękujemy, wysyłamy jutro!') && str_contains($after['body'], '· Ola'), 'Reply is shown in the conversation with who sent it');
    @unlink("$temp/fake/sent.json");
    $r = request($ola, 'admin/ext.php?page=meta.wyslij', ['csrf' => csrfOf($t['body']), 'c' => $jan, 'text' => 'Tak']);
    ok(!is_file("$temp/fake/sent.json") && str_contains(follow($ola, $r)['body'], 'Minęły 24 godziny'), 'Reply after 24 hours is not sent');

    // ---- Zamówienie z rozmowy
    $m1 = (string) $pdo->query("SELECT msg_key FROM ext_meta_messages WHERE body LIKE 'Dzień dobry, poproszę%'")->fetchColumn();
    $nf = request($ola, 'admin/order_new.php?meta=' . $anna . '&mm[]=' . $m1);
    $nb = $nf['body'];
    ok(str_contains($nb, 'name="billing_first_name" value="Anna"') && str_contains($nb, 'name="billing_last_name" value="Nowak"')
        && str_contains($nb, 'name="billing_phone" value="600700800"') && str_contains($nb, 'name="billing_address_1" value="ul. Lipowa 12/3"')
        && str_contains($nb, 'name="billing_postcode" value="00-123"') && str_contains($nb, 'name="billing_city" value="Warszawa"'), 'New order form is filled from the picked message');
    ok(str_contains($nb, 'poproszę 2 świece Gingerbread') && str_contains($nb, 'name="ext_meta_conv" value="' . $anna . '"') && str_contains($nb, 'Dane z rozmowy z'), 'Picked message goes to the notes and the form remembers the conversation');
    $ig = request($ola, 'admin/order_new.php?meta=' . $igc)['body'];
    ok(str_contains($ig, 'name="pp_id" id="ppId" value="KRA01N"') && str_contains($ig, 'value="InPost Paczkomat"') && str_contains($ig, 'name="billing_phone" value="512345678"')
        && str_contains($ig, 'name="billing_first_name" value="Ola"') && !str_contains($ig, 'Paczkomat KRA01N proszę</textarea>'), 'Without picking, data comes from all customer messages, no notes');
    ok(str_contains(request($ola, 'admin/order_new.php?meta=' . str_repeat('a', 40))['body'], 'ext_meta_conv') === false, 'Unknown conversation is ignored');

    $r = request($ola, 'admin/order_new.php', ['csrf' => csrfOf($nb), 'ext_meta_conv' => $anna, 'shop_id' => '1',
        'billing_first_name' => 'Anna', 'billing_last_name' => 'Nowak', 'billing_email' => '', 'billing_phone' => '600700800',
        'billing_address_1' => 'ul. Lipowa 12/3', 'billing_postcode' => '00-123', 'billing_city' => 'Warszawa', 'billing_country' => 'PL',
        'item_sku' => ['GB-1L'], 'item_name' => [''], 'item_price' => ['49.90'], 'item_qty' => ['2'],
        'shipping_method' => 'Kurier', 'shipping_cost' => '15', 'payment_method' => 'bacs', 'pase_status' => 'new', 'customer_note' => '']);
    $oid = (int) $pdo->query('SELECT MAX(woo_order_id) FROM woo_orders')->fetchColumn();
    ok(in_array($r['status'], [302, 303], true) && $oid > 0 && $pdo->query("SELECT conv_key FROM ext_meta_orders WHERE woo_order_id = $oid")->fetchColumn() === $anna, 'Saved order is linked to the conversation');
    $ov = request($vi, 'admin/order_view.php?id=' . $oid);
    ok(str_contains($ov['body'], '💬 Rozmowa') && str_contains($ov['body'], 'Anna Nowak') && str_contains($ov['body'], 'page=meta.rozmowy&amp;c=' . $anna), 'Order page has the conversation section with a link');
    $ol = request($vi, 'admin/index.php');
    ok(str_contains($ol['body'], 'x_meta') && str_contains($ol['body'], 'Zamówienie z rozmowy: Messenger - Anna Nowak'), 'Orders list shows the conversation icon');
    $tc = request($vi, 'admin/ext.php?page=meta.rozmowy&c=' . $anna);
    ok(str_contains($tc['body'], 'Zamówienia z tej rozmowy') && str_contains($tc['body'], 'order_view.php?id=' . $oid), 'Conversation lists its orders');
    // Błąd w formularzu: rozmowa nie ginie.
    $r = request($ola, 'admin/order_new.php', ['csrf' => csrfOf($nb), 'ext_meta_conv' => $anna, 'billing_first_name' => 'Anna', 'billing_email' => 'zly@', 'item_sku' => [''], 'item_name' => [''], 'item_price' => [''], 'item_qty' => ['1']]);
    ok($r['status'] === 200 && str_contains($r['body'], 'name="ext_meta_conv" value="' . $anna . '"'), 'Form error keeps the conversation link');

    // ---- Wygasły token
    touch("$temp/fake/expired");
    $r = request($ola, 'admin/ext.php?page=meta.pobierz', ['csrf' => csrfOf($t['body'])]);
    ok(str_contains(follow($ola, $r)['body'], 'Meta odrzuciła token'), 'Expired token is explained in Polish');
    ok($pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key = 'EXT_META_PAGE_TOKEN'")->fetchColumn() == 0, 'Cached page token is dropped after Meta rejects it');
    unlink("$temp/fake/expired");

    $warnings = preg_grep('/PHP (Warning|Fatal|Parse|Deprecated|Notice)|Rozszerzenie .* błąd/', file("$temp/server.log") ?: []);
    ok($warnings === [], 'No PHP warnings or extension errors' . ($warnings ? ': ' . implode(' | ', array_map('trim', $warnings)) : ''));
    echo "PASS: {$checks} Messenger i Instagram HTTP checks\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (is_resource($fake)) { proc_terminate($fake); proc_close($fake); }
    if (getenv('KEEP_TEMP')) { echo "temp: $temp\n"; } else { exec('rm -rf ' . escapeshellarg($temp)); }
}
