<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// The production controllers are copied to a disposable fixture. Only the DB
// bootstrap is replaced: no production .env, database, API or printer is used.
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Pase\\')) {
        require $root . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    }
});
use Pase\Support\Totp;

$checks = 0;
function ok(bool $condition, string $name): void {
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $name); }
    ++$checks; echo "OK: {$name}\n";
}
function csrf(string $html): string {
    if (!preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $match)) {
        throw new RuntimeException('Missing CSRF in test response');
    }
    return $match[1];
}
function client(): CurlHandle {
    $handle = curl_init();
    curl_setopt_array($handle, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_HEADER => true]);
    return $handle;
}
function request(CurlHandle $handle, string $path, ?array $data = null): array {
    global $base;
    curl_setopt($handle, CURLOPT_URL, $base . $path);
    curl_setopt($handle, CURLOPT_POST, $data !== null);
    if ($data !== null) { curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($data)); }
    $result = curl_exec($handle);
    if ($result === false) { throw new RuntimeException(curl_error($handle)); }
    $length = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    return ['status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'headers' => substr($result, 0, $length), 'body' => substr($result, $length)];
}
function passwordLogin(CurlHandle $handle, string $username = 'tester'): array {
    $page = request($handle, 'login.php');
    return request($handle, 'login.php', ['csrf' => csrf($page['body']), 'username' => $username, 'password' => 'Only-Test-Password-42']);
}

$temp = sys_get_temp_dir() . '/crm-mfa-http-' . bin2hex(random_bytes(8));
mkdir($temp, 0700);
$process = null;
try {
    mkdir($temp . '/public/admin', 0700, true);
    mkdir($temp . '/storage/security', 0700, true);
    $env = $temp . '/.env';
    file_put_contents($env, "TOTP_REQUIRE_ADMIN=0\n");
    foreach (['auth.php', 'login.php', 'login_mfa.php', 'security.php', 'users.php', 'order_history.php', 'document_operations.php', 'header.php', 'nav.php', 'footer.php'] as $file) {
        copy($root . '/public/admin/' . $file, $temp . '/public/admin/' . $file);
    }
    file_put_contents($temp . '/public/admin/bootstrap_admin.php', '<?php // Schema prepared by the test.');
    file_put_contents($temp . '/public/admin/index.php', '<?php require __DIR__."/auth.php"; echo "PROTECTED";');
    $bootstrap = '<?php define("PASE_ROOT", ' . var_export($temp, true) . ');'
        . 'spl_autoload_register(static function($class) { if(str_starts_with($class, "Pase\\\\")) require '
        . var_export($root . '/src/', true) . '.str_replace("\\\\", "/", substr($class,5)).".php"; });'
        . '\\Pase\\Support\\Env::load(PASE_ROOT."/.env");'
        . '$pdo=new PDO("sqlite:".PASE_ROOT."/test.sqlite",null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);'
        . 'function t($key,$vars=[]) {return \\Pase\\Support\\I18n::t($key,$vars);}';
    file_put_contents($temp . '/public/admin/db_admin.php', $bootstrap);
    $pdo = new PDO('sqlite:' . $temp . '/test.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT, role TEXT, password_hash TEXT, is_active INTEGER, session_version INTEGER, totp_secret TEXT, totp_last_step INTEGER DEFAULT -1, totp_recovery_hashes TEXT, last_login TEXT)');
    $pdo->exec('ALTER TABLE admin_users ADD COLUMN totp_required INTEGER NOT NULL DEFAULT 0');
    $pdo->exec('ALTER TABLE admin_users ADD COLUMN email TEXT');
    $stmt = $pdo->prepare("INSERT INTO admin_users (id,username,role,password_hash,is_active,session_version) VALUES (?,?,'admin',?,1,1)");
    foreach ([1 => 'tester', 2 => 'required-admin'] as $id => $name) {
        $stmt->execute([$id, $name, password_hash('Only-Test-Password-42', PASSWORD_DEFAULT)]);
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    if (!$socket) { throw new RuntimeException('Cannot allocate loopback test port'); }
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', $temp . '/public/admin'],
        [0 => ['pipe','r'], 1 => ['file',$temp . '/server.log','a'], 2 => ['file',$temp . '/server.log','a']], $pipes);
    fclose($pipes[0]);
    $ready = false;
    for ($i = 0; $i < 100; ++$i) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($probe) { fclose($probe); $ready = true; break; }
        usleep(10000);
    }
    if (!$ready) { throw new RuntimeException('Local test server did not start'); }

    $a = client();
    $response = passwordLogin($a);
    ok(str_contains($response['headers'], 'Location: index.php'), 'Password-only login for unconfigured account');
    $page = request($a, 'security.php'); $token = csrf($page['body']);
    ok(str_contains($page['headers'], 'Cache-Control: no-store'), 'Security page not cached');
    ok(request($a, 'security.php', ['action'=>'start','password'=>'Only-Test-Password-42'])['status'] === 419, 'Enrollment requires CSRF');
    $response = request($a, 'security.php', ['action'=>'start','password'=>'Only-Test-Password-42','csrf'=>$token]);
    ok($response['status'] === 303, 'Password-confirmed enrollment starts');
    $page = request($a, 'security.php');
    if (!preg_match('/data-uri="([^"]+)"/', $page['body'], $match)) {
        preg_match_all('/<div class="flash[^>]*>(.*?)<\/div>/s', $page['body'], $messages);
        throw new RuntimeException('Missing enrollment URI (HTTP ' . $page['status'] . '): ' . implode('; ', array_map('strip_tags', $messages[1])));
    }
    parse_str(parse_url(html_entity_decode($match[1]), PHP_URL_QUERY), $parameters);
    $secret = $parameters['secret'];
    ok($pdo->query('SELECT totp_secret FROM admin_users WHERE id=1')->fetchColumn() === null, 'QR display alone does not enable MFA');
    $response = request($a, 'security.php', ['action'=>'enable','password'=>'Only-Test-Password-42','csrf'=>csrf($page['body']), 'code'=>Totp::code($secret, intdiv(time(),30))]);
    ok($response['status'] === 303, 'Enrollment confirmed by authenticator code');
    $page = request($a, 'security.php');
    preg_match_all('/[A-F0-9]{8}(?:-[A-F0-9]{8}){3}/', $page['body'], $matches);
    $codes = array_values(array_unique($matches[0]));
    ok(count($codes) === 10, 'Ten recovery codes displayed');
    ok(!str_contains(request($a, 'security.php')['body'], $codes[0]), 'Recovery codes shown only once');

    $b = client();
    ok(str_contains(passwordLogin($b)['headers'], 'Location: login_mfa.php'), 'Password redirects to second factor');
    ok(str_contains(request($b, 'index.php')['headers'], 'Location: login.php'), 'Pending login cannot access panel');
    $challenge = request($b, 'login_mfa.php'); $mfaToken = csrf($challenge['body']);
    ok(request($b, 'login_mfa.php', ['code'=>'123456'])['status'] === 419, 'Second factor requires CSRF');
    $response = request($b, 'login_mfa.php', ['code'=>'not-a-code','csrf'=>$mfaToken]);
    ok(str_contains($response['body'], 'Nieprawidłowy lub już użyty'), 'Wrong second factor rejected');
    $otp = Totp::code($secret, intdiv(time(),30)+1);
    ok(request($b, 'login_mfa.php', ['code'=>$otp,'csrf'=>$mfaToken])['status'] === 303, 'Valid OTP completes login');
    ok(request($b, 'index.php')['body'] === 'PROTECTED', 'Authenticated second factor unlocks panel');

    $c = client(); passwordLogin($c); $mfaToken = csrf(request($c, 'login_mfa.php')['body']);
    ok(str_contains(request($c, 'login_mfa.php', ['code'=>$otp,'csrf'=>$mfaToken])['body'], 'Nieprawidłowy lub już użyty'), 'Used OTP cannot authenticate another session');
    ok(request($c, 'login_mfa.php', ['code'=>$codes[0],'csrf'=>$mfaToken])['status'] === 303, 'Recovery code completes password login');
    $d = client(); passwordLogin($d); $mfaToken = csrf(request($d, 'login_mfa.php')['body']);
    ok(str_contains(request($d, 'login_mfa.php', ['code'=>$codes[0],'csrf'=>$mfaToken])['body'], 'Nieprawidłowy lub już użyty'), 'Used recovery code rejected on new session');

    file_put_contents($env, "TOTP_REQUIRE_ADMIN=1\n");
    $e = client(); passwordLogin($e, 'required-admin');
    ok(str_contains(request($e, 'index.php')['headers'], 'Location: security.php'), 'Mandatory MFA restricts administrator to enrollment');
    ok(str_contains(request($e, 'security.php')['body'], 'Skonfiguruj aplikację'), 'Mandatory enrollment page accessible');
    $token = csrf(request($a, 'security.php')['body']);
    request($a, 'security.php', ['action'=>'disable','password'=>'Only-Test-Password-42','csrf'=>$token,'code'=>$codes[1]]);
    ok($pdo->query('SELECT totp_secret FROM admin_users WHERE id=1')->fetchColumn() !== null, 'Mandatory policy blocks disabling MFA');
    file_put_contents($env, "TOTP_REQUIRE_ADMIN=0\n");
    request($a, 'security.php', ['action'=>'disable','password'=>'wrong','csrf'=>$token,'code'=>$codes[1]]);
    ok($pdo->query('SELECT totp_secret FROM admin_users WHERE id=1')->fetchColumn() !== null, 'Disable requires correct password');
    request($a, 'security.php', ['action'=>'disable','password'=>'Only-Test-Password-42','csrf'=>$token,'code'=>$codes[1]]);
    ok($pdo->query('SELECT totp_secret FROM admin_users WHERE id=1')->fetchColumn() === null, 'Password and recovery code allow disabling');
    ok(str_contains(request($b, 'index.php')['headers'], 'Location: login.php'), 'Factor change revokes other sessions');
    ok(str_contains(request($d, 'login_mfa.php')['headers'], 'Location: login.php'), 'Factor change revokes pending challenge');
    $usersToken = csrf(request($a, 'users.php?edit=2')['body']);
    $update = ['action'=>'update', 'id'=>2, 'role'=>'editor', 'is_active'=>'1', 'csrf'=>$usersToken, 'totp_required'=>'1'];
    ok(request($a, 'users.php', $update)['status'] === 303, 'Admin can require 2FA for an individual editor');
    ok((int)$pdo->query('SELECT totp_required FROM admin_users WHERE id=2')->fetchColumn() === 1, 'Per-user requirement persisted');
    ok(str_contains(request($e, 'index.php')['headers'], 'Location: login.php'), 'Changing requirement revokes existing session');
    passwordLogin($e, 'required-admin');
    ok(str_contains(request($e, 'index.php')['headers'], 'Location: security.php'), 'Editor with required 2FA restricted to enrollment');
    ok(str_contains(request($e, 'security.php')['body'], '2FA jest wymagane dla tego konta'), 'Requirement message applies to non-admin');
    unset($update['totp_required']);
    request($a, 'users.php', $update);
    // Clear the revoked session before requesting the password form again.
    request($e, 'index.php');
    passwordLogin($e, 'required-admin');
    ok(request($e, 'index.php')['body'] === 'PROTECTED', 'Removing per-user requirement restores optional enrollment');
    ok(request($e, 'users.php', ['csrf' => csrf(request($e, 'security.php')['body'])] + $update)['status'] === 403, 'Editor cannot change another user policy');
    ok(request($e, 'users.php', ['csrf' => 'wrong'] + $update)['status'] === 419, 'Panel POST without a valid CSRF token is rejected globally');
    \Pase\Services\AuditTrail::migrate($pdo);
    $audit = new \Pase\Services\AuditTrail($pdo);
    $_SESSION = ['pase_user_id' => 99, 'pase_username' => '<script>alert(1)</script>'];
    for ($i = 0; $i < 52; ++$i) {
        $audit->record(443, 'order.status_changed', ['pase_status' => 'new'], ['pase_status' => '<img src=x onerror=alert(1)>']);
    }
    $audit->record(444, 'order.status_changed', [], ['pase_status' => 'OTHER_ORDER_ONLY']);
    $history = request($a, 'order_history.php?id=443');
    ok($history['status'] === 200, 'Administrator can view order history');
    preg_match('/<nav\b[^>]*>(.*?)<\/nav>/s', $history['body'], $historyNav);
    ok(str_contains($historyNav[1] ?? '', 'href="order_history.php"'), 'History is in administrator navigation');
    ok(str_contains($history['headers'], 'no-store'), 'Order history is not cached');
    ok(!str_contains($history['body'], '<script>alert(1)</script>') && str_contains($history['body'], '&lt;script&gt;'), 'History actor escaped');
    ok(!str_contains($history['body'], '<img src=x') && str_contains($history['body'], '&lt;img'), 'History values escaped');
    ok(!str_contains($history['body'], 'OTHER_ORDER_ONLY'), 'History filtered by order');
    ok(substr_count($history['body'], '<summary>') === 50 && str_contains($history['body'], 'Starsze wpisy'), 'History paginated');
    $older = request($a, 'order_history.php?id=443&before=3');
    ok(substr_count($older['body'], '<summary>') === 2, 'History cursor returns remaining records');
    $editorHistory = request($e, 'order_history.php?id=443');
    ok($editorHistory['status'] === 200, 'Editor can view order history');
    preg_match('/<nav\b[^>]*>(.*?)<\/nav>/s', $editorHistory['body'], $editorNav);
    ok(str_contains($editorNav[1] ?? '', 'href="order_history.php"'), 'History is in editor navigation');
    \Pase\Services\DocumentIssueGuard::migrate($pdo);
    $pdo->exec('CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY); INSERT INTO woo_orders VALUES (900), (901);
        CREATE TABLE order_documents (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, provider TEXT, document_type TEXT, remote_id TEXT, status TEXT, message TEXT)');
    $guard = new \Pase\Services\DocumentIssueGuard($pdo);
    $attempt = $guard->reserve(900, 'receipt', 1); $guard->uncertain(900, 'receipt', $attempt, null);
    $pending = $guard->reserve(901, 'receipt', 1);
    ok(request($e, 'document_operations.php')['status'] === 403, 'Editor cannot resolve uncertain document operations');
    $operations = request($a, 'document_operations.php');
    ok($operations['status'] === 200, 'Administrator can inspect document operations');
    ok(request($a, 'document_operations.php', ['action'=>'absent'])['status'] === 419, 'Document reconciliation requires CSRF');
    $data = ['csrf'=>csrf($operations['body']), 'action'=>'absent', 'order_id'=>900, 'document_type'=>'receipt', 'attempt_token'=>$attempt];
    request($a, 'document_operations.php', $data);
    ok((int)$pdo->query('SELECT COUNT(*) FROM document_issue_operations WHERE order_id=900')->fetchColumn() === 1, 'Document reconciliation requires explicit verification');
    $data['verified'] = '1';
    ok(request($a, 'document_operations.php', $data)['status'] === 303, 'Confirmed reconciliation uses POST redirect GET');
    ok((int)$pdo->query('SELECT COUNT(*) FROM document_issue_operations WHERE order_id=900')->fetchColumn() === 0, 'Confirmed absence releases uncertain operation');
    $data['order_id'] = 901; $data['attempt_token'] = $pending;
    request($a, 'document_operations.php', $data);
    ok((int)$pdo->query('SELECT COUNT(*) FROM document_issue_operations WHERE order_id=901')->fetchColumn() === 1, 'Cannot release potentially running operation from panel');
    $data['action'] = 'link'; $data['remote_id'] = '123456';
    request($a, 'document_operations.php', $data);
    ok($pdo->query('SELECT remote_id FROM order_documents WHERE woo_order_id=901')->fetchColumn() === '123456', 'Confirmed existing document linked without issuing another');
    $pdo->exec("UPDATE admin_users SET role='viewer', session_version=session_version+1 WHERE id=2");
    request($e, 'index.php'); passwordLogin($e, 'required-admin');
    ok(request($e, 'order_history.php')['status'] === 403, 'Viewer cannot access audit history');
    $viewerPage = request($e, 'security.php');
    preg_match('/<nav\b[^>]*>(.*?)<\/nav>/s', $viewerPage['body'], $viewerNav);
    ok(isset($viewerNav[1]) && !str_contains($viewerNav[1], 'href="order_history.php"'), 'History hidden from viewer navigation');
    ok(str_contains(request(client(), 'order_history.php')['headers'], 'Location: login.php'), 'Anonymous history access requires login');
    $log = file_get_contents($temp . '/server.log');
    // Print the offending lines, otherwise a CI failure here says nothing about the cause.
    if (preg_match_all('/^.*PHP (?:Fatal|Warning|Notice|Parse).*$/m', $log, $problems)) { echo implode("\n", $problems[0]), "\n"; }
    ok(!preg_match('/PHP (?:Fatal|Warning|Notice|Parse)/', $log), 'Controllers produce no PHP warnings or errors');
    echo "PASS: {$checks} HTTP two-factor checks\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    $pdo = null;
    // Remove only this test's randomly named temporary fixture, never workspace files.
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($temp);
}
