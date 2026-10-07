<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Panel zwrotów przez HTTP: lista z panelem statusów, „Stwórz zwrot” z wyszukiwaniem po numerze przesyłki,
// nieodebrane przesyłki, karta zwrotu (stan pozycji, przywrócenie na stan, status obsługi), uprawnienia.
// Prawdziwe strony panelu na SQLite, bez sieci.
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Pase\\')) { require $root . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\OrderReturns;
use Pase\Support\PagePermissions;

$checks = 0;
function ok(bool $condition, string $name): void { global $checks; if (!$condition) { throw new RuntimeException('FAIL: ' . $name); } ++$checks; echo "OK: {$name}\n"; }
function client(): CurlHandle {
    $h = curl_init();
    curl_setopt_array($h, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10, CURLOPT_PROXY => '', CURLOPT_HEADER => false]);
    return $h;
}
function request(CurlHandle $h, string $path, ?array $data = null): array {
    global $base;
    curl_setopt($h, CURLOPT_URL, $base . $path);
    curl_setopt($h, CURLOPT_POST, $data !== null);
    if ($data !== null) { curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($data)); } else { curl_setopt($h, CURLOPT_HTTPGET, true); }
    $res = curl_exec($h);
    if ($res === false) { throw new RuntimeException(curl_error($h)); }
    return ['status' => curl_getinfo($h, CURLINFO_RESPONSE_CODE), 'body' => (string) $res];
}
function login(string $user): CurlHandle {
    $h = client();
    $page = request($h, 'admin/login.php');
    request($h, 'admin/login.php', ['csrf' => csrfOf($page['body']), 'username' => $user, 'password' => 'Only-Test-Password-42']);
    return $h;
}
function csrfOf(string $body): string {
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $m);
    return $m[1] ?? '';
}

$temp = sys_get_temp_dir() . '/crm-returns-http-' . bin2hex(random_bytes(6));
foreach (['public/admin/assets', 'config', 'storage/security', 'lang'] as $d) { mkdir("$temp/$d", 0700, true); }
$process = null;
try {
    file_put_contents("$temp/.env", "TOTP_REQUIRE_ADMIN=0\n");
    foreach (glob("$root/public/admin/*.php") as $f) { if (!in_array(basename($f), ['bootstrap_admin.php', 'db_admin.php'], true)) { copy($f, "$temp/public/admin/" . basename($f)); } }
    foreach (glob("$root/lang/*.php") as $f) { copy($f, "$temp/lang/" . basename($f)); }
    file_put_contents("$temp/public/admin/bootstrap_admin.php", '<?php \Pase\Support\PagePermissions::migrate($pdo);');
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
    foreach ([1 => ['boss', 'Adam', 'admin'], 3 => ['vi', 'Wiktor', 'viewer']] as $id => [$u, $n, $role]) {
        $ins->execute([$id, $u, $n, $role, password_hash('Only-Test-Password-42', PASSWORD_DEFAULT)]);
    }
    PagePermissions::migrate($pdo);
    \Pase\Services\AuditTrail::migrate($pdo);
    $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
    $pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT, is_active INTEGER, config TEXT, webhook_secret TEXT NULL, access_token TEXT NULL, refresh_token TEXT NULL, token_expires_at TEXT NULL)');
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, pase_stock INTEGER, actual_stock INTEGER NULL, pase_price REAL, images TEXT, ean TEXT)');
    $pdo->exec("INSERT INTO products (id, sku, name, pase_stock) VALUES (1, 'GB-1L', 'GINGERBREAD 1l', 5), (2, 'MW-1L', 'MULLED WINE 1l', 5)");
    $pdo->exec("CREATE TABLE woo_orders (id INTEGER PRIMARY KEY, woo_order_id INTEGER UNIQUE, integration_id INTEGER, pase_number INTEGER, order_number TEXT, status TEXT,
        pase_status TEXT, currency TEXT, total REAL, customer_name TEXT, customer_email TEXT, date_created TEXT, imported_at TEXT, updated_at TEXT,
        lifecycle TEXT DEFAULT 'active', payload TEXT, local_items TEXT NULL, local_billing_email TEXT NULL, local_billing_phone TEXT NULL)");
    $pdo->exec('CREATE TABLE shipments (id INTEGER PRIMARY KEY, woo_order_id INTEGER, courier_code TEXT, waybill_no TEXT, tracking_status TEXT, tracking_at TEXT)');
    $pdo->exec("CREATE TABLE order_documents (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, provider TEXT, document_type TEXT,
        remote_id TEXT, status TEXT DEFAULT 'issued', message TEXT, created_at TEXT, updated_at TEXT)");
    OrderReturns::migrate($pdo);
    $oins = $pdo->prepare('INSERT INTO woo_orders (woo_order_id, integration_id, pase_number, order_number, status, currency, customer_name, customer_email, date_created, payload)
        VALUES (?,1,?,?,?,?,?,?,?,?)');
    $items = [['id' => 55, 'sku' => 'GB-1L', 'name' => 'GINGERBREAD 1l', 'quantity' => 2, 'total' => '81.14', 'total_tax' => '18.66'],
              ['id' => 56, 'sku' => 'MW-1L', 'name' => 'MULLED WINE 1l', 'quantity' => 1, 'total' => '32.44', 'total_tax' => '7.46']];
    $oins->execute([501, 11, '501', 'completed', 'PLN', 'Anna Kowalska', 'anna@example.com', '2026-09-20 10:00:00',
        json_encode(['billing' => ['email' => 'anna@example.com', 'phone' => '+48 500 100 200'], 'line_items' => $items])]);
    $oins->execute([502, 12, '502', 'completed', 'PLN', 'Jan Nowak', 'jan@example.com', '2026-09-21 10:00:00', json_encode(['line_items' => $items])]);
    $pdo->exec("INSERT INTO shipments (woo_order_id, courier_code, waybill_no, tracking_status) VALUES (501, 'inpost', '620012345678901234567890', 'delivered'),
        (502, 'dpd', '1000123456789U', 'returned')");

    $free = static function (): string { $s = stream_socket_server('tcp://127.0.0.1:0'); $a = stream_socket_get_name($s, false); fclose($s); return $a; };
    $address = $free();
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes);
    fclose($pipes[0]);
    for ($i = 0; $i < 100; ++$i) { $p = @stream_socket_client('tcp://' . $address, $e1, $e2, 0.1); if ($p) { fclose($p); break; } usleep(10000); }

    $boss = login('boss');
    $list = request($boss, 'admin/returns.php');
    ok($list['status'] === 200 && str_contains($list['body'], 'Stwórz zwrot') && str_contains($list['body'], 'Obsługa zwrotu') && str_contains($list['body'], 'Przyjęty przez magazyn'), 'List shows the status panel and "Stwórz zwrot"');
    ok(str_contains($list['body'], '<span class="rt-cnt" style="background:#e8833a">1</span>Nieodebrane przesyłki'), 'Unclaimed parcel counted in the panel');

    // Stwórz zwrot: szukanie po numerze przesyłki wysłanej do klienta (ze spacjami ze skanera / etykiety).
    $find = request($boss, 'admin/returns.php?find=' . rawurlencode('6200 1234 5678 9012 3456 7890'));
    ok(str_contains($find['body'], 'Anna Kowalska') && str_contains($find['body'], 'numer przesyłki') && str_contains($find['body'], 'returns.php?new=501'), 'Order found by its waybill number');
    ok(str_contains(request($boss, 'admin/returns.php?find=nie-ma-takiego')['body'], 'Nie znaleziono zamówienia'), 'Unknown number says nothing was found');

    $unclaimed = request($boss, 'admin/returns.php?f=unclaimed');
    ok(str_contains($unclaimed['body'], '1000123456789U') && str_contains($unclaimed['body'], 'reason=NOT_COLLECTED'), 'Unclaimed parcels view offers a return for the order');
    $new = request($boss, 'admin/returns.php?new=502&all=1&reason=NOT_COLLECTED&waybill=1000123456789U');
    ok(str_contains($new['body'], 'value="1000123456789U"') && str_contains($new['body'], '<option value="NOT_COLLECTED" selected>'), 'New return form prefilled from the unclaimed parcel');
    request($boss, 'admin/returns.php', ['csrf' => csrfOf($new['body']), 'action' => 'create', 'woo_order_id' => 502, 'qty' => ['0' => 2, '1' => 1], 'reason' => 'NOT_COLLECTED', 'waybill' => '1000123456789U', 'note' => '']);
    $rid = (int) $pdo->query('SELECT id FROM order_returns WHERE woo_order_id = 502')->fetchColumn();
    ok($rid > 0, 'Return created');
    ok(!str_contains(request($boss, 'admin/returns.php?f=unclaimed')['body'], '1000123456789U'), 'Parcel leaves the unclaimed list once it has a return');

    // Karta zwrotu: stan pozycji, przywrócenie na stan tylko „Przyjętych”, statusy.
    $card = request($boss, "admin/returns.php?id=$rid");
    ok(str_contains($card['body'], 'Informacje o zwrocie') && str_contains($card['body'], 'Zwrócono:') && str_contains($card['body'], '— sprawdź —'), 'Return card shows lines to check and the refund summary');
    ok(!str_contains($card['body'], 'Przywróć na stan ('), 'No restock button before any line is accepted');
    request($boss, 'admin/returns.php', ['csrf' => csrfOf($card['body']), 'action' => 'line_status', 'return_id' => $rid, 'line' => 0, 'line_status' => 'accepted']);
    request($boss, 'admin/returns.php', ['csrf' => csrfOf($card['body']), 'action' => 'line_status', 'return_id' => $rid, 'line' => 1, 'line_status' => 'damaged']);
    ok((int) $pdo->query("SELECT pase_stock FROM products WHERE sku = 'GB-1L'")->fetchColumn() === 5, 'Marking a line does not touch stock');
    $card = request($boss, "admin/returns.php?id=$rid");
    ok(str_contains($card['body'], 'Przywróć na stan (2 szt.)'), 'Restock button counts only accepted units');
    $after = request($boss, 'admin/returns.php', ['csrf' => csrfOf($card['body']), 'action' => 'restock', 'return_id' => $rid]);
    ok((int) $pdo->query("SELECT pase_stock FROM products WHERE sku = 'GB-1L'")->fetchColumn() === 7 && (int) $pdo->query("SELECT pase_stock FROM products WHERE sku = 'MW-1L'")->fetchColumn() === 5, 'Accepted units back on stock, damaged not');
    ok(str_contains($after['body'], 'Częściowo (2 z 3 szt. na stanie)') && str_contains($after['body'], '✓ 2 / 2'), 'Card shows what went back to stock');
    ok($pdo->query("SELECT handling_status FROM order_returns WHERE id = $rid")->fetchColumn() === 'received', 'Return moved to "Przyjęty przez magazyn"');
    $again = request($boss, 'admin/returns.php', ['csrf' => csrfOf($after['body']), 'action' => 'restock', 'return_id' => $rid]);
    ok((int) $pdo->query("SELECT pase_stock FROM products WHERE sku = 'GB-1L'")->fetchColumn() === 7 && str_contains($again['body'], 'Oznacz pozycje'), 'Second click does not restock again');
    request($boss, 'admin/returns.php', ['csrf' => csrfOf($after['body']), 'action' => 'handling', 'return_id' => $rid, 'handling_status' => 'rejected']);
    $rej = request($boss, 'admin/returns.php?f=h_rejected');
    ok(str_contains($rej['body'], 'Zwrot odrzucony') && str_contains($rej['body'], "returns.php?id=$rid"), 'Rejected return listed under its status');
    ok(str_contains(request($boss, 'admin/returns.php?q=1000123456789U')['body'], "returns.php?id=$rid"), 'List search by waybill finds the return');
    ok(str_contains(request($boss, "admin/returns.php?id=$rid&step=refund")['body'], 'Potwierdzam zwrot pieniędzy klientowi'), 'Refund confirmation screen still reachable from the card');

    // Podgląd tylko do odczytu: bez przycisków zmian.
    $vi = login('vi');
    $roCard = request($vi, "admin/returns.php?id=$rid");
    ok($roCard['status'] === 200 && !str_contains($roCard['body'], 'name="line_status"') && !str_contains($roCard['body'], 'Stwórz zwrot</button>'), 'Viewer sees the card without edit controls');

    $log = (string) @file_get_contents("$temp/server.log");
    ok(!preg_match('/PHP (Fatal|Warning|Notice|Deprecated)/', $log), 'No PHP errors or warnings in the server log');
    echo "All $checks checks passed\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (getenv('KEEP_TEMP')) { echo "temp: $temp\n"; } else { exec('rm -rf ' . escapeshellarg($temp)); }
}
