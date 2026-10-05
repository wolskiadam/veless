<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Wysłane e-maile przez HTTP: liczniki, filtry i lista na prawdziwej stronie panelu (baza SQLite).
// Adresy klientów widzą administrator i edytor; magazynier (viewer) nie ma dostępu.
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

$temp = sys_get_temp_dir() . '/crm-email-log-http-' . bin2hex(random_bytes(6));
foreach (['public/admin/assets', 'public/pack/assets', 'config', 'storage/security', 'lang'] as $d) { mkdir("$temp/$d", 0700, true); }
$process = null;
try {
    file_put_contents("$temp/.env", "TOTP_REQUIRE_ADMIN=0\n");
    foreach (glob("$root/public/admin/*.php") as $f) { if (!in_array(basename($f), ['bootstrap_admin.php', 'db_admin.php'], true)) { copy($f, "$temp/public/admin/" . basename($f)); } }
    copy("$root/public/admin/assets/loc-picker.js", "$temp/public/admin/assets/loc-picker.js");
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
    $pdo->exec('CREATE TABLE email_templates (id INTEGER PRIMARY KEY, tpl_key TEXT, name TEXT, subject TEXT, body TEXT, is_active INTEGER)');
    $pdo->exec("INSERT INTO email_templates (tpl_key, name, subject, body, is_active) VALUES ('order_shipped', 'Zamówienie wysłane', 's', 'b', 1)");
    \Pase\Services\EmailLog::migrate($pdo);
    $log = new \Pase\Services\EmailLog($pdo);
    $log->record(['type' => 'automation', 'template' => 'order_shipped', 'order_id' => 501], 'anna@example.test', 'Twoja paczka jest w drodze', 'sklep@example.test', true);
    $log->record(['type' => 'automation', 'template' => 'order_shipped', 'order_id' => 502], 'jan@example.test', 'Twoja paczka jest w drodze', 'sklep@example.test', false, 'Błąd SMTP: 550 mailbox unavailable');
    $log->record(['type' => 'order_message', 'order_id' => 501], 'anna@example.test', 'Wiadomość do zamówienia #501', 'marka2@example.test', true);
    $pdo->exec("INSERT INTO email_log (created_at, type, recipient, subject, sender, status) VALUES ('2020-01-01 10:00:00', 'smtp_test', 'old@example.test', 'Stary test', 'sklep@example.test', 'sent')");

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes);
    fclose($pipes[0]);
    for ($i = 0; $i < 100; ++$i) { $p = @stream_socket_client('tcp://' . $address, $e1, $e2, 0.1); if ($p) { fclose($p); break; } usleep(10000); }

    $boss = login('boss');
    $page = request($boss, 'admin/email_log.php');
    ok($page['status'] === 200 && str_contains($page['body'], 'Wysłane e-maile'), 'Sent emails page opens for admin');
    ok(preg_match('~<div class="num">3</div><div class="lbl">Wszystkie~', $page['body']) === 1
        && preg_match('~<div class="num"[^>]*>1</div><div class="lbl">Nieudane~', $page['body']) === 1, 'Totals for the last 30 days (old entry outside the period)');
    ok(str_contains($page['body'], 'Automatyzacja (szablon e-mail)') && str_contains($page['body'], 'Zamówienie wysłane')
        && str_contains($page['body'], 'marka2@example.test'), 'Counts by type, template name and sender account');
    ok(str_contains($page['body'], 'mailbox unavailable') && str_contains($page['body'], 'order_view.php?id=502'), 'Failure reason and order link in the list');
    ok(!str_contains($page['body'], 'old@example.test'), 'Entries outside the period hidden');

    $page = request($boss, 'admin/email_log.php?period=custom&from=2020-01-01&to=2020-01-01');
    ok(str_contains($page['body'], 'old@example.test') && !str_contains($page['body'], 'jan@example.test'), 'Custom date range');
    $page = request($boss, 'admin/email_log.php?status=failed');
    ok(str_contains($page['body'], 'jan@example.test') && !str_contains($page['body'], 'Wiadomość do zamówienia #501'), 'Failed-only filter');
    $page = request($boss, 'admin/email_log.php?order=501&q=' . urlencode('anna@'));
    ok(str_contains($page['body'], 'Wiadomość do zamówienia #501') && !str_contains($page['body'], 'jan@example.test'), 'Order and recipient filters');

    $ola = login('ola');
    ok(request($ola, 'admin/email_log.php')['status'] === 200, 'Editor opens sent emails');
    $vi = login('vi');
    ok(request($vi, 'admin/email_log.php')['status'] === 403, 'Viewer cannot open sent emails');

    $warnings = preg_grep('/PHP (Warning|Fatal|Parse|Deprecated|Notice)/', file("$temp/server.log") ?: []);
    ok($warnings === [], 'No PHP warnings' . ($warnings ? ': ' . implode(' | ', array_map('trim', $warnings)) : ''));
    echo "PASS: {$checks} sent emails HTTP checks\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    exec('rm -rf ' . escapeshellarg($temp));
}
