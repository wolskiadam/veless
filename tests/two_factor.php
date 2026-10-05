<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    }
});

use Pase\Support\Totp;
use Pase\Support\TwoFactorCipher;
use Pase\Support\AdminSession;
use Pase\Services\TwoFactorService;

$checks = 0;
function verify(bool $ok, string $name): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $name); }
    ++$checks; echo 'OK: ' . $name . "\n";
}
function rejects(callable $fn, string $name): void {
    try { $fn(); } catch (Throwable $e) { verify(true, $name); return; }
    verify(false, $name);
}

// SHA-1 vectors from RFC 6238 Appendix B, covering times beyond 2038.
$secret = Totp::encode('12345678901234567890');
verify($secret === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'Base32 encoding');
foreach ([59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471',
    1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'] as $time => $code) {
    verify(Totp::code($secret, intdiv($time, 30), 8) === $code, 'RFC 6238 vector ' . $time);
}
verify(Totp::match($secret, '287082', -1, 59) === 1, 'Six-digit Google Authenticator compatibility');
verify(Totp::match($secret, '287082', 1, 59) === null, 'Previously consumed step rejected');
verify(Totp::match($secret, '287082', -1, 89) === 1, 'One-step clock drift allowed');
verify(Totp::match($secret, '287082', -1, 120) === null, 'Expired code rejected');
foreach (['', '123', '1234567', '1e0000', 'abcdef', '１２３４５６'] as $bad) {
    verify(Totp::match($secret, $bad, -1, 59) === null, 'Malformed code rejected');
}
verify(strlen(Totp::generateSecret()) === 32, 'Random secret has 160 bits');
verify(Totp::generateSecret() !== Totp::generateSecret(), 'Secrets unique');
$uri = Totp::uri($secret, 'a+b@example.com', 'CRM Test');
verify(str_starts_with($uri, 'otpauth://totp/CRM%20Test:a%2Bb%40example.com?'), 'Enrollment URI label escaping');
parse_str(parse_url($uri, PHP_URL_QUERY), $params);
verify($params['secret'] === $secret && $params['period'] === '30' && $params['digits'] === '6' && $params['algorithm'] === 'SHA1', 'Enrollment URI standard parameters');

$cipher = new TwoFactorCipher(random_bytes(32));
$encrypted = $cipher->encrypt($secret, 1);
verify($cipher->decrypt($encrypted, 1) === $secret, 'AES-GCM round trip');
verify(!str_contains($encrypted, $secret), 'Ciphertext does not expose TOTP secret');
verify($encrypted !== $cipher->encrypt($secret, 1), 'Encryption uses random nonce');
rejects(fn() => $cipher->decrypt($encrypted, 2), 'Ciphertext cannot be swapped between accounts');
rejects(fn() => (new TwoFactorCipher(random_bytes(32)))->decrypt($encrypted, 1), 'Wrong key rejected');
$bytes = base64_decode(substr($encrypted, 3)); $bytes[28] = chr(ord($bytes[28]) ^ 1);
rejects(fn() => $cipher->decrypt('v1:' . base64_encode($bytes), 1), 'Tampered ciphertext rejected');
rejects(fn() => $cipher->decrypt('v1:bad', 1), 'Malformed ciphertext rejected');

$dir = sys_get_temp_dir() . '/crm-totp-test-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
try {
    rejects(fn() => TwoFactorCipher::fromFile($dir . '/missing.key'), 'Missing key is not silently replaced');
    $fileCipher = TwoFactorCipher::fromFile($dir . '/test.key', true);
    $testValue = $fileCipher->encrypt('secret', 4);
    verify(TwoFactorCipher::fromFile($dir . '/test.key')->decrypt($testValue, 4) === 'secret', 'Key survives process reload');
    verify((fileperms($dir . '/test.key') & 0777) === 0600, 'Key file private permissions');
} finally { unlink($dir . '/test.key'); rmdir($dir); }

// Real SQL, but an isolated in-memory database rather than production MySQL.
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT, role TEXT, password_hash TEXT, is_active INTEGER, session_version INTEGER, totp_secret TEXT, totp_last_step INTEGER DEFAULT -1, totp_recovery_hashes TEXT, last_login TEXT)');
$pdo->exec("INSERT INTO admin_users (id,username,role,password_hash,is_active,session_version) VALUES (1,'test','admin','hash',1,1)");
$load = fn() => $pdo->query('SELECT * FROM admin_users WHERE id=1')->fetch(PDO::FETCH_ASSOC);
$service = new TwoFactorService($pdo, $cipher);
$user = $load();
$oldSession = ['pase_auth_stamp' => AdminSession::stamp($user)];
verify(AdminSession::valid($user, $oldSession), 'Optional 2FA permits password-only account');
$codes = $service->enable($user, $encrypted, Totp::code($secret, intdiv(time(), 30)));
verify(is_array($codes) && count($codes) === 10 && count(array_unique($codes)) === 10, 'Enrollment produces ten unique recovery codes');
$user = $load();
verify($user['totp_secret'] === $encrypted && !str_contains($user['totp_recovery_hashes'], $codes[0]), 'Database stores encrypted secret and hashed recovery codes');
verify(!AdminSession::valid($user, $oldSession), 'Enrollment revokes pre-existing sessions');
verify(!AdminSession::valid($user, ['pase_auth_stamp' => AdminSession::stamp($user)]), 'Password alone cannot create authenticated 2FA session');
verify(AdminSession::valid($user, ['pase_auth_stamp' => AdminSession::stamp($user), 'pase_mfa_verified' => true]), 'Both factors permit session');
rejects(fn() => AdminSession::login($pdo, $user, false), 'Login helper refuses second-factor bypass');

$pending = ['stamp' => AdminSession::stamp($user), 'expires' => 1600];
verify(AdminSession::pendingValid($user, $pending, 1500), 'Pending login valid before expiry');
verify(!AdminSession::pendingValid($user, $pending, 1600), 'Pending login expires after deadline');
verify(!AdminSession::pendingValid(array_replace($user, ['session_version' => 9]), $pending, 1500), 'Password/account change revokes pending challenge');
verify(!AdminSession::pendingValid(array_replace($user, ['is_active' => 0]), $pending, 1500), 'Disabled account cannot finish challenge');

$time = time() + 90;
$otp = Totp::code($secret, intdiv($time, 30));
verify($service->consume($user, $otp, $time), 'Fresh OTP consumed with SQL');
verify(!$service->consume($user, $otp, $time), 'Stale concurrent snapshot cannot replay OTP');
verify(!$service->consume($load(), $otp, $time), 'Updated snapshot cannot replay OTP');
$user = $load();
verify($service->consume($user, strtolower($codes[0])), 'Recovery code accepted case-insensitively');
verify(!$service->consume($user, $codes[0]), 'Stale snapshot cannot reuse recovery code');
verify(!$service->consume($load(), $codes[0]), 'Recovery code is single use');
verify(count(json_decode($load()['totp_recovery_hashes'], true)) === 9, 'Only used recovery code removed');
$user = $load();
$newCodes = $service->regenerate($user);
verify(count($newCodes) === 10, 'Recovery regeneration');
verify(!$service->consume($load(), $codes[1]), 'Regeneration invalidates old unused codes');
verify(!$service->disable($user), 'Stale session cannot disable 2FA');
$user = $load();
verify($service->consume($user, $newCodes[0]), 'New recovery code works');
verify($service->disable($load()), 'Disable updates account');
verify($load()['totp_secret'] === null && $load()['totp_recovery_hashes'] === null, 'Disable removes secrets and recovery material');
verify(!$service->consume($load(), $newCodes[1]), 'Disabled factor cannot authenticate');
echo "PASS: {$checks} two-factor checks\n";
