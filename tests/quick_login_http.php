<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Szybkie logowanie innym urządzeniem (telefon ↔ komputer), na kopiach prawdziwych kontrolerów w tymczasowym katalogu.
// Podmieniony jest tylko bootstrap bazy (SQLite): bez produkcyjnego .env, bazy i poczty.
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Pase\\')) {
        require $root . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    }
});
use Pase\Support\AdminSession;
use Pase\Support\QuickLogin;

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
function client(string $agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0 Safari/537.36'): CurlHandle {
    $handle = curl_init();
    curl_setopt_array($handle, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_USERAGENT => $agent,
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
/** Nowe urządzenie otwiera „Zaloguj innym urządzeniem”: zwraca [kod, csrf]. */
function startComputer(CurlHandle $handle): array {
    $page = request($handle, 'login_quick.php');
    if (!preg_match('/data-code="([A-Z0-9]{8})"/', $page['body'], $match)) {
        throw new RuntimeException('Missing quick login code (HTTP ' . $page['status'] . ')');
    }
    return [$match[1], csrf($page['body'])];
}
function poll(CurlHandle $handle, string $token): string {
    $response = request($handle, 'login_quick.php', ['csrf' => $token, 'action' => 'poll']);
    return (string) (json_decode($response['body'], true)['status'] ?? 'invalid');
}
/** Telefon otwiera link z QR i wybiera akcję. */
function phoneDecide(CurlHandle $phone, string $code, string $action): array {
    $lookup = request($phone, 'quick_approve.php?code=' . $code);
    $page = request($phone, 'quick_approve.php?pending=1');
    $result = request($phone, 'quick_approve.php', ['csrf' => csrf($page['body']), 'action' => $action]);
    return [$lookup, $page, $result];
}

$temp = sys_get_temp_dir() . '/crm-quick-login-' . bin2hex(random_bytes(8));
mkdir($temp, 0700);
$process = null;
try {
    mkdir($temp . '/public/admin/assets', 0700, true);
    mkdir($temp . '/storage/security', 0700, true);
    file_put_contents($temp . '/.env', "TOTP_REQUIRE_ADMIN=0\n");
    foreach (['auth.php', 'login.php', 'login_quick.php', 'quick_approve.php', 'security.php', 'header.php', 'nav.php', 'footer.php',
        'assets/qrcodegen.js', 'assets/quick-login.js', 'assets/quick-code-input.js'] as $file) {
        copy($root . '/public/admin/' . $file, $temp . '/public/admin/' . $file);
    }
    file_put_contents($temp . '/public/admin/bootstrap_admin.php', '<?php // Schema prepared by the test.');
    file_put_contents($temp . '/public/admin/index.php', '<?php require __DIR__."/auth.php"; echo "PROTECTED:" . $_SESSION["pase_username"];');
    $bootstrap = '<?php define("PASE_ROOT", ' . var_export($temp, true) . ');'
        . 'spl_autoload_register(static function($class) { if(str_starts_with($class, "Pase\\\\")) require '
        . var_export($root . '/src/', true) . '.str_replace("\\\\", "/", substr($class,5)).".php"; });'
        . '\\Pase\\Support\\Env::load(PASE_ROOT."/.env");'
        . '$pdo=new PDO("sqlite:".PASE_ROOT."/test.sqlite",null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);'
        . 'function t($key,$vars=[]) {return \\Pase\\Support\\I18n::t($key,$vars);}';
    file_put_contents($temp . '/public/admin/db_admin.php', $bootstrap);
    $pdo = new PDO('sqlite:' . $temp . '/test.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT, email TEXT, role TEXT, password_hash TEXT, is_active INTEGER, session_version INTEGER, totp_secret TEXT, totp_required INTEGER NOT NULL DEFAULT 0, totp_last_step INTEGER DEFAULT -1, totp_recovery_hashes TEXT, last_login TEXT)');
    $stmt = $pdo->prepare("INSERT INTO admin_users (id,username,role,password_hash,is_active,session_version) VALUES (?,?,'admin',?,1,1)");
    foreach ([1 => 'tester', 2 => 'other'] as $id => $name) {
        $stmt->execute([$id, $name, password_hash('Only-Test-Password-42', PASSWORD_DEFAULT)]);
    }
    QuickLogin::migrate($pdo);

    // Warstwa usługi: kody, jednorazowość, podpis konta.
    ok(QuickLogin::normalize(' abcd-efgh ') === 'ABCDEFGH', 'Code normalized from typed form');
    foreach (['', 'ABCDEFG', 'ABCDEFGHJ', 'ABCD0FGH', 'ABCDIFGH', 'ABCD EFG!'] as $bad) {
        ok(QuickLogin::normalize($bad) === '', 'Malformed code rejected: ' . $bad);
    }
    $service = new QuickLogin($pdo);
    $user = $pdo->query('SELECT * FROM admin_users WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    $started = $service->start('10.0.0.1', 'test');
    ok($pdo->query('SELECT COUNT(*) FROM admin_quick_logins WHERE code_hash = ' . $pdo->quote($started['code'])
        . ' OR browser_hash = ' . $pdo->quote($started['secret']))->fetchColumn() === 0, 'Code and browser secret stored only as hashes');
    ok($service->poll($started['id'], $started['secret'])['status'] === 'pending', 'Unapproved request pending');
    ok($service->poll($started['id'], str_repeat('0', 64))['status'] === 'expired', 'Wrong browser secret learns nothing');
    ok($service->approve($started['id'], $user, '10.0.0.2'), 'Phone approves');
    ok(!$service->approve($started['id'], $user, '10.0.0.2'), 'Approval works once');
    ok($service->poll($started['id'], str_repeat('0', 64))['status'] === 'expired', 'Approved request unusable without browser secret');
    $result = $service->poll($started['id'], $started['secret']);
    ok($result['status'] === 'approved' && (int) $result['user']['id'] === 1, 'Initiating browser receives account');
    ok($service->poll($started['id'], $started['secret'])['status'] === 'expired', 'Approval consumed once');
    $late = $service->start('10.0.0.1', 'test', time() - QuickLogin::TTL - 1);
    ok($service->findPending($late['code']) === null && !$service->approve($late['id'], $user, 'x'), 'Expired code cannot be approved');
    $changed = $service->start('10.0.0.1', 'test');
    $service->approve($changed['id'], $user, '10.0.0.2');
    $pdo->exec("UPDATE admin_users SET totp_secret='v1:enabled' WHERE id=1");
    ok($service->poll($changed['id'], $changed['secret'])['status'] === 'expired', 'Security change after approval revokes it');
    $pdo->exec('UPDATE admin_users SET totp_secret=NULL WHERE id=1');
    ok(QuickLogin::describeAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/604.1') === 'Safari, iOS', 'Device description for approval screen');

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

    ok(str_contains(request(client(), 'login.php')['body'], 'href="login_quick.php"'), 'Login page offers phone login');
    ok(str_contains(request(client(), 'quick_approve.php')['headers'], 'Location: login.php'), 'Approval page requires login');

    $phone = client('Mozilla/5.0 (Linux; Android 14) Chrome/130.0 Mobile Safari/537.36');
    passwordLogin($phone);
    $computer = client();
    [$code, $token] = startComputer($computer);
    $page = request($computer, 'login_quick.php');
    ok(str_contains($page['body'], 'data-code="' . $code . '"'), 'Reload keeps the same code while valid');
    ok(str_contains($page['headers'], 'no-store') && str_contains($page['headers'], "script-src 'self'"), 'Code page not cached and script-restricted');
    ok(poll($computer, $token) === 'pending', 'Computer waits for approval');
    ok(request($computer, 'login_quick.php', ['action' => 'poll'])['status'] === 419, 'Polling requires CSRF');

    $attacker = client();
    [, $attackerToken] = startComputer($attacker);
    request($phone, 'quick_approve.php?code=ZZZZZZZZ');
    ok(str_contains(request($phone, 'quick_approve.php')['body'], 'Nie znaleziono ważnego kodu'), 'Unknown code rejected on phone');
    [$lookup, $confirm] = phoneDecide($phone, strtolower(QuickLogin::format($code)), 'approve');
    ok(str_contains($lookup['headers'], 'Location: quick_approve.php?pending=1'), 'QR link redirects so code leaves the address bar');
    ok(str_contains($confirm['body'], 'Chrome, Windows') && str_contains($confirm['body'], '127.0.0.1'), 'Phone sees requesting device and IP');
    ok(request($phone, 'quick_approve.php', ['action' => 'approve'])['status'] === 419, 'Approval requires CSRF');
    ok(poll($attacker, $attackerToken) === 'pending', 'Approval does not leak to another browser');
    ok(str_contains(request($attacker, 'index.php')['headers'], 'Location: login.php'), 'Other browser still logged out');
    ok(poll($computer, $token) === 'ok', 'Initiating computer logged in after approval');
    ok(request($computer, 'index.php')['body'] === 'PROTECTED:tester', 'Computer has the approving account session');
    ok(str_contains(request($computer, 'login_quick.php')['headers'], 'Location: index.php'), 'Logged-in computer skips code page');
    request($phone, 'quick_approve.php?code=' . $code);
    ok(str_contains(request($phone, 'quick_approve.php')['body'], 'Nie znaleziono ważnego kodu'), 'Used code cannot be approved again');

    // Odwrotnie: zalogowany komputer wpisuje kod pokazany na niezalogowanym telefonie.
    $newPhone = client('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/604.1');
    [$code, $token] = startComputer($newPhone);
    $form = request($computer, 'quick_approve.php');
    ok(str_contains($form['body'], 'Kod z ekranu nowego urządzenia'), 'Logged-in computer offers manual code entry');
    ok(str_contains($form['body'], 'src="assets/quick-code-input.js"') && str_contains($form['headers'], "script-src 'self'"), 'Code field loads automatic dash formatting');
    $typed = request($computer, 'quick_approve.php', ['csrf' => csrf($form['body']), 'action' => 'lookup', 'code' => strtolower($code)]);
    ok(str_contains($typed['headers'], 'Location: quick_approve.php?pending=1'), 'Code typed without dash found on computer');
    $confirm = request($computer, 'quick_approve.php?pending=1');
    ok(str_contains($confirm['body'], 'Safari, iOS'), 'Computer sees the phone asking to log in');
    request($computer, 'quick_approve.php', ['csrf' => csrf($confirm['body']), 'action' => 'approve']);
    ok(poll($newPhone, $token) === 'ok' && request($newPhone, 'index.php')['body'] === 'PROTECTED:tester', 'Phone logged in by approving on computer');

    $rejected = client();
    [$code, $token] = startComputer($rejected);
    phoneDecide($phone, $code, 'reject');
    ok(poll($rejected, $token) === 'rejected', 'Rejected on phone');
    ok(str_contains(request($rejected, 'index.php')['headers'], 'Location: login.php'), 'Rejected computer stays logged out');

    $revoked = client();
    [$code, $token] = startComputer($revoked);
    phoneDecide($phone, $code, 'approve');
    $pdo->exec('UPDATE admin_users SET session_version = session_version + 1 WHERE id = 1');
    ok(poll($revoked, $token) === 'expired', 'Log out everywhere before pickup revokes approval');
    ok(str_contains(request($revoked, 'index.php')['headers'], 'Location: login.php'), 'Revoked computer stays logged out');

    $phone = client(); passwordLogin($phone);
    $expired = client();
    [$code, $token] = startComputer($expired);
    $pdo->exec('UPDATE admin_quick_logins SET expires_at = ' . (time() - 1) . " WHERE status = 'pending'");
    request($phone, 'quick_approve.php?code=' . $code);
    ok(str_contains(request($phone, 'quick_approve.php')['body'], 'Nie znaleziono ważnego kodu'), 'Expired code cannot be approved');
    ok(poll($expired, $token) === 'expired', 'Computer told the code expired');
    $renewed = request($expired, 'login_quick.php', ['csrf' => $token, 'action' => 'new']);
    ok($renewed['status'] === 303 && !str_contains(request($expired, 'login_quick.php')['body'], 'data-code="' . $code . '"'), 'New code can be generated');

    $history = request($phone, 'security.php')['body'];
    ok(str_contains($history, 'Zalogowano') && str_contains($history, 'Odrzucono') && str_contains($history, 'Unieważniono'), 'Security page shows quick login history');

    $pdo->exec('DELETE FROM admin_quick_logins');
    $flood = client();
    [, $floodToken] = startComputer($flood);
    for ($i = 0; $i < 40; ++$i) { request($flood, 'login_quick.php', ['csrf' => $floodToken, 'action' => 'new']); }
    ok((int) $pdo->query('SELECT COUNT(*) FROM admin_quick_logins')->fetchColumn() <= 30, 'Code generation rate limited per IP');

    // Zalogowane urządzenia w Bezpieczeństwo konta → 2FA.
    $pdo->exec('DELETE FROM admin_devices');
    $laptop = client(); passwordLogin($laptop);
    $mobile = client('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/604.1'); passwordLogin($mobile);
    $stranger = client(); passwordLogin($stranger, 'other');
    ok(request($stranger, 'index.php')['body'] === 'PROTECTED:other', 'Second account logged in');
    $page = request($laptop, 'security.php');
    ok(str_contains($page['body'], 'Zalogowane urządzenia') && str_contains($page['body'], 'Safari, iOS')
        && substr_count($page['body'], 'to urządzenie') === 1, 'Security page lists devices and marks the current one');
    ok(!str_contains($page['body'], 'Wylogowano pozostałe') && substr_count($page['body'], 'value="device_logout"') === 1, 'Only other devices get a logout button');
    $mobileId = (int) $pdo->query("SELECT id FROM admin_devices WHERE user_agent LIKE '%iPhone%' AND revoked_at IS NULL")->fetchColumn();
    $strangerId = (int) $pdo->query('SELECT id FROM admin_devices WHERE user_id = 2')->fetchColumn();
    ok(request($laptop, 'security.php', ['action' => 'device_logout', 'device' => $mobileId])['status'] === 419, 'Device logout requires CSRF');
    request($laptop, 'security.php', ['csrf' => csrf($page['body']), 'action' => 'device_logout', 'device' => $strangerId]);
    ok(request($stranger, 'index.php')['body'] === 'PROTECTED:other', 'Cannot log out another account device');
    request($laptop, 'security.php', ['csrf' => csrf($page['body']), 'action' => 'device_logout', 'device' => $mobileId]);
    ok(str_contains(request($mobile, 'index.php')['headers'], 'Location: login.php'), 'Logged-out device loses access on next request');
    ok(request($laptop, 'index.php')['body'] === 'PROTECTED:tester', 'Current device keeps working');
    ok(!str_contains(request($laptop, 'security.php')['body'], 'Safari, iOS'), 'Logged-out device disappears from the list');
    $second = client(); passwordLogin($second); $third = client(); passwordLogin($third);
    request($laptop, 'security.php', ['csrf' => csrf($page['body']), 'action' => 'devices_logout_others']);
    ok(str_contains(request($second, 'index.php')['headers'], 'Location: login.php') && str_contains(request($third, 'index.php')['headers'], 'Location: login.php')
        && request($laptop, 'index.php')['body'] === 'PROTECTED:tester', 'Log out all other devices keeps only this one');
    request($laptop, 'index.php?logout=1');
    ok((int) $pdo->query('SELECT COUNT(*) FROM admin_devices WHERE user_id = 1 AND revoked_at IS NULL')->fetchColumn() === 0, 'Regular logout closes the device entry');
    $_SESSION = [];
    $user = $pdo->query('SELECT * FROM admin_users WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    $devices = new \Pase\Support\AdminDevices($pdo);
    ok($devices->check($user) && isset($_SESSION['pase_device_token']), 'Session from before this feature is registered, not logged out');
    ok(count($devices->active($user, 86400)) === 1, 'Registered legacy session listed');
    $pdo->exec('UPDATE admin_users SET session_version = session_version + 1 WHERE id = 1');
    $user = $pdo->query('SELECT * FROM admin_users WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    ok($devices->active($user, 86400) === [], 'Sessions revoked by password or 2FA change leave the list');

    $log = file_get_contents($temp . '/server.log');
    if (preg_match_all('/^.*PHP (?:Fatal|Warning|Notice|Parse|Deprecated).*$/m', $log, $problems)) { echo implode("\n", $problems[0]), "\n"; }
    ok(!preg_match('/PHP (?:Fatal|Warning|Notice|Parse|Deprecated)/', $log), 'Controllers produce no PHP warnings or errors');
    echo "PASS: {$checks} quick login checks\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    $pdo = null;
    // Remove only this test's randomly named temporary fixture, never workspace files.
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($temp);
}
