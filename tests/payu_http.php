<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// PayU przez HTTP: strona System → PayU (saldo, wypłata z potwierdzeniem, historia) na prawdziwych stronach panelu,
// baza SQLite, atrapa PayU jako drugi serwer na 127.0.0.1. Tylko administrator; edytor i magazynier dostają 403.
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

$temp = sys_get_temp_dir() . '/crm-payu-http-' . bin2hex(random_bytes(6));
foreach (['public/admin/assets', 'public/pack/assets', 'config', 'storage/security', 'lang'] as $d) { mkdir("$temp/$d", 0700, true); }
$process = null;
try {
    file_put_contents("$temp/.env", "TOTP_REQUIRE_ADMIN=0\n");
    foreach (glob("$root/public/admin/*.php") as $f) { if (!in_array(basename($f), ['bootstrap_admin.php', 'db_admin.php'], true)) { copy($f, "$temp/public/admin/" . basename($f)); } }
    mkdir("$temp/integrations/payu", 0700, true);
    foreach (glob("$root/integrations/payu/*.php") as $f) { copy($f, "$temp/integrations/payu/" . basename($f)); }
    foreach (glob("$root/lang/*.php") as $f) { copy($f, "$temp/lang/" . basename($f)); }
    file_put_contents("$temp/public/admin/bootstrap_admin.php", '<?php \Pase\Support\PagePermissions::migrate($pdo);');

    // Konfiguracja jak config/config.php, ale z SQLite i kodem z repozytorium.
    $boot = '<?php if (!defined("PASE_ROOT")) { define("PASE_ROOT", ' . var_export($temp, true) . ');'
        . 'spl_autoload_register(static function($c) { if (str_starts_with($c, "Pase\\\\")) require ' . var_export("$root/src/", true)
        . ' . str_replace("\\\\", "/", substr($c, 5)) . ".php";'
        . ' if (str_starts_with($c, "PasePlugin\\\\Payu\\\\")) require PASE_ROOT . "/integrations/payu/" . substr($c, 16) . ".php"; });'
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
    $pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT, is_active INTEGER, config TEXT, webhook_secret TEXT NULL, access_token TEXT NULL, refresh_token TEXT NULL, token_expires_at TEXT NULL)');

    // Atrapa PayU: token, saldo 2208,39 PLN, przyjęcie wypłaty (zapisuje ją do pliku), status REALIZED.
    mkdir("$temp/payu", 0700, true);
    file_put_contents("$temp/payu/index.php", '<?php
        $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH); header("Content-Type: application/json");
        if ($path === "/pl/standard/user/oauth/authorize") { parse_str(file_get_contents("php://input"), $f); echo json_encode(["access_token" => "tok-" . $f["client_id"], "expires_in" => 43199]); return; }
        $auth = $_SERVER["HTTP_AUTHORIZATION"] ?? "";
        if ($auth === "Bearer tok-2") {
            if ($path === "/api/v2_1/shops/Sh0p2XYZ") { echo json_encode(["shopId" => "Sh0p2XYZ", "name" => "Drugi", "currencyCode" => "PLN", "balance" => ["currencyCode" => "PLN", "total" => "5000", "available" => "5000"]]); return; }
            if ($path === "/api/v2_1/payouts") { file_put_contents(__DIR__ . "/posted2.log", file_get_contents("php://input") . "\n", FILE_APPEND); http_response_code(201); echo json_encode(["payout" => ["payoutId" => "two-1", "status" => "PENDING"]]); return; }
            http_response_code(404); echo "{}"; return;
        }
        if ($auth !== "Bearer tok-300746") { http_response_code(401); echo "{}"; return; }
        if ($path === "/api/v2_1/shops/SHOP1234") { echo json_encode(["shopId" => "SHOP1234", "name" => "Mój Sklep", "currencyCode" => "PLN", "balance" => ["currencyCode" => "PLN", "total" => "225839", "available" => "220839"]]); return; }
        if ($path === "/api/v2_1/payouts" && $_SERVER["REQUEST_METHOD"] === "POST") { $b = file_get_contents("php://input"); file_put_contents(__DIR__ . "/posted.log", $b . "\n", FILE_APPEND);
            http_response_code(201); echo json_encode(["payout" => ["payoutId" => "p-" . count(file(__DIR__ . "/posted.log")), "status" => "PENDING"], "status" => ["statusCode" => "SUCCESS"]]); return; }
        if (str_starts_with($path, "/api/v2_1/payouts/")) { echo json_encode(["payout" => ["payoutId" => basename($path), "status" => "REALIZED"], "status" => ["statusCode" => "SUCCESS"]]); return; }
        http_response_code(404); echo "{}";');
    $free = static function (): string { $s = stream_socket_server('tcp://127.0.0.1:0'); $a = stream_socket_get_name($s, false); fclose($s); return $a; };
    $payuAddr = $free();
    $payuProc = proc_open([PHP_BINARY, '-S', $payuAddr, "$temp/payu/index.php"], [0 => ['pipe', 'r'], 1 => ['file', "$temp/payu.log", 'a'], 2 => ['file', "$temp/payu.log", 'a']], $pp);
    fclose($pp[0]);

    $address = $free();
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes, null, ['CRM_TEST_PAYU_URL' => 'http://' . $payuAddr] + getenv());
    fclose($pipes[0]);
    foreach ([$address, $payuAddr] as $a) {
        for ($i = 0; $i < 100; ++$i) { $p = @stream_socket_client('tcp://' . $a, $e1, $e2, 0.1); if ($p) { fclose($p); break; } usleep(10000); }
    }

    $boss = login('boss');
    $page = request($boss, 'admin/payu.php');
    ok($page['status'] === 200 && str_contains($page['body'], 'Najpierw połącz CRM z PayU') && str_contains($page['body'], 'integration_edit.php?type=payu'), 'Without keys: page asks to connect PayU');
    ok(str_contains($page['body'], 'href="payu.php"'), 'Admin sees PayU in the menu');

    $pdo->exec("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES (7, 'payu', 'PayU', 1, '" . json_encode(['client_id' => '300746', 'client_secret' => 'sekret', 'shop_id' => 'SHOP1234', 'environment' => 'sandbox']) . "')");
    $page = request($boss, 'admin/payu.php');
    ok($page['status'] === 200 && str_contains($page['body'], '2 208,39 PLN') && str_contains($page['body'], '2 258,39 PLN') && str_contains($page['body'], 'Mój Sklep'), 'Balance shown (available and total)');
    ok(str_contains($page['body'], 'sandbox') && str_contains($page['body'], 'value="2208,39"'), 'Sandbox warning; amount prefilled with available balance');
    ok(!str_contains($page['body'], 'Razem dostępne'), 'One shop: no total across shops');

    $r = request($boss, 'admin/payu.php', ['csrf' => csrfOf($page['body']), 'action' => 'prepare', 'account' => 7, 'amount' => '3000', 'description' => 'x']);
    ok($r['status'] === 200 && str_contains($r['body'], 'większa niż dostępne') && !is_file("$temp/payu/posted.log"), 'Amount above available refused, nothing sent');
    $r = request($boss, 'admin/payu.php', ['csrf' => csrfOf($page['body']), 'action' => 'prepare', 'account' => 7, 'amount' => '1 000,50', 'description' => 'Wypłata wrzesień']);
    ok($r['status'] === 200 && str_contains($r['body'], 'Potwierdź wypłatę z PayU') && str_contains($r['body'], 'Tak, zleć wypłatę 1 000,50 PLN') && !is_file("$temp/payu/posted.log"),
        'Confirmation screen, nothing sent yet');
    preg_match('/name="ext_id" value="([^"]+)"/', $r['body'], $m);
    $order = ['csrf' => csrfOf($r['body']), 'action' => 'order', 'account' => 7, 'amount' => '100050', 'description' => 'Wypłata wrzesień', 'ext_id' => $m[1] ?? ''];
    $r = request($boss, 'admin/payu.php', $order);
    $posted = json_decode(trim((string) @file_get_contents("$temp/payu/posted.log")), true);
    ok(in_array($r['status'], [302, 303], true) && $posted === ['shopId' => 'SHOP1234', 'payout' => ['extPayoutId' => $m[1], 'amount' => 100050, 'description' => 'Wypłata wrzesień']], 'Payout sent to PayU after confirmation');
    $row = $pdo->query('SELECT * FROM payu_payouts')->fetch(PDO::FETCH_ASSOC);
    ok($row['payout_id'] === 'p-1' && $row['created_by'] === 'Adam' && (int) $row['amount'] === 100050, 'Payout stored with PayU id and who ordered it');
    $r = request($boss, 'admin/payu.php', $order);
    ok(count(file("$temp/payu/posted.log")) === 1, 'Repeated confirmation (refresh) sends nothing');
    $page = request($boss, 'admin/payu.php');
    ok(str_contains($page['body'], 'już zlecona') && str_contains($page['body'], 'Wypłata wrzesień') && str_contains($page['body'], 'zrealizowana'), 'History shows the payout, status refreshed');
    ok(str_contains($page['body'], 'name="period"') && str_contains($page['body'], '<option value="month" selected>'), 'Payout list has a period selector, this month by default');
    $pdo->exec("UPDATE payu_payouts SET created_at = '2020-01-15 10:00:00'");
    $old = request($boss, 'admin/payu.php?period=month');
    $all = request($boss, 'admin/payu.php?period=all');
    ok(str_contains($old['body'], 'Brak wypłat w tym okresie') && !str_contains($old['body'], 'Wypłata wrzesień') && str_contains($all['body'], 'Wypłata wrzesień'), 'Old finished payouts hidden from this month, visible under all');
    $pdo->exec("UPDATE payu_payouts SET created_at = '" . date('Y-m-d H:i:s') . "'");

    // Drugi sklep PayU: osobne konto, osobne saldo i formularz, suma dostępnych.
    $pdo->exec("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES (8, 'payu', 'Sklep drugi', 1, '" . json_encode(['client_id' => '2', 'client_secret' => 's2', 'shop_id' => 'Sh0p2XYZ', 'environment' => 'production']) . "')");
    $page = request($boss, 'admin/payu.php');
    ok(substr_count($page['body'], 'name="action" value="prepare"') === 2 && str_contains($page['body'], 'Sklep drugi') && str_contains($page['body'], '50,00 PLN')
        && str_contains($page['body'], 'Razem dostępne do wypłaty we wszystkich sklepach (2)') && str_contains($page['body'], '2 258,39 PLN'), 'Two shops: balance and payout form for each, total across shops');
    $r = request($boss, 'admin/payu.php', ['csrf' => csrfOf($page['body']), 'action' => 'prepare', 'account' => 8, 'amount' => '60']);
    ok(str_contains($r['body'], 'większa niż dostępne') && str_contains($r['body'], '50,00 PLN'), 'Second shop checked against its own balance');
    $r = request($boss, 'admin/payu.php', ['csrf' => csrfOf($page['body']), 'action' => 'prepare', 'account' => 8, 'amount' => '40', 'description' => 'Drugi sklep']);
    ok(str_contains($r['body'], 'z salda sklepu <b>Sklep drugi</b>'), 'Confirmation names the shop');
    preg_match('/name="ext_id" value="([^"]+)"/', $r['body'], $m2);
    request($boss, 'admin/payu.php', ['csrf' => csrfOf($r['body']), 'action' => 'order', 'account' => 8, 'amount' => '4000', 'description' => 'Drugi sklep', 'ext_id' => $m2[1] ?? '']);
    $posted2 = json_decode(trim((string) @file_get_contents("$temp/payu/posted2.log")), true);
    ok(($posted2['shopId'] ?? '') === 'Sh0p2XYZ' && count(file("$temp/payu/posted.log")) === 1, 'Payout sent with the second shop keys only');
    $page = request($boss, 'admin/payu.php');
    ok(str_contains($page['body'], '<td>Sklep drugi</td>') && str_contains($page['body'], '<td>PayU</td>'), 'History shows which shop each payout came from');

    $ola = login('ola');
    ok(request($ola, 'admin/payu.php')['status'] === 403, 'Editor cannot open PayU');
    ok(!str_contains(request($ola, 'admin/index.php')['body'], 'href="payu.php"'), 'Editor does not see PayU in the menu');
    $vi = login('vi');
    ok(request($vi, 'admin/payu.php', ['csrf' => 'x', 'action' => 'order'] + $order)['status'] !== 303 && count(file("$temp/payu/posted.log")) === 1, 'Viewer cannot order a payout');

    $warnings = preg_grep('/PHP (Warning|Fatal|Parse|Deprecated|Notice)/', file("$temp/server.log") ?: []);
    ok($warnings === [], 'No PHP warnings' . ($warnings ? ': ' . implode(' | ', array_map('trim', $warnings)) : ''));
    echo "PASS: {$checks} PayU HTTP checks\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (isset($payuProc) && is_resource($payuProc)) { proc_terminate($payuProc); proc_close($payuProc); }
    exec('rm -rf ' . escapeshellarg($temp));
}
