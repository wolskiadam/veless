<?php
declare(strict_types=1);

/**
 * Szybkie logowanie - strona na NOWYM komputerze. Pokazuje kod i QR, które zatwierdza
 * inne zalogowane urządzenie, telefon albo komputer (quick_approve.php). Szczegóły i zabezpieczenia: Pase\Support\QuickLogin.
 */

use Pase\Support\AdminSession;
use Pase\Support\Logger;
use Pase\Support\QuickLogin;
use Pase\Support\RateLimiter;
use Pase\Support\Session;

require_once __DIR__ . '/db_admin.php';
Session::startAdmin();
require_once __DIR__ . '/bootstrap_admin.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; connect-src 'self'; style-src 'unsafe-inline'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
/** @var PDO $pdo */

if (isset($_SESSION['pase_user_id'])) {
    header('Location: index.php');
    exit;
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
$quick = new QuickLogin($pdo);
$_SESSION['login_csrf'] ??= bin2hex(random_bytes(32));
$error = null;

/** Nowy kod dla tej przeglądarki, z limitem na IP (każdy kod to wiersz w bazie). */
$startRequest = static function () use ($quick, $ip, $agent): bool {
    $limiter = new RateLimiter(PASE_ROOT . '/storage/security/quick_login.json');
    if (!$limiter->consume(['start-ip:' . $ip => [30, 900]])) {
        unset($_SESSION['quick_login']);
        return false;
    }
    $_SESSION['quick_login'] = $quick->start($ip, $agent);
    return true;
};

$state = $_SESSION['quick_login'] ?? null;
$valid = is_array($state) && isset($state['id'], $state['secret'], $state['code'], $state['expires']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = $_POST['csrf'] ?? null;
    $action = $_POST['action'] ?? '';
    $wantsJson = $action === 'poll';
    if (!is_string($csrf) || !hash_equals($_SESSION['login_csrf'], $csrf)) {
        http_response_code(419);
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['status' => 'error']));
        }
        $error = 'Sesja formularza wygasła. Odśwież stronę.';
    } elseif ($action === 'new') {
        if (!$startRequest()) {
            http_response_code(429);
            header('Retry-After: 900');
            $error = 'Zbyt wiele prób. Spróbuj ponownie za 15 minut.';
        } else {
            header('Location: login_quick.php', true, 303);
            exit;
        }
    } elseif ($action === 'poll' || $action === 'check') {
        $result = $valid ? $quick->poll((int) $state['id'], (string) $state['secret']) : ['status' => 'expired', 'user' => null];
        if ($result['status'] === 'approved') {
            $user = $result['user'];
            // Telefon był w pełni zalogowany (auth.php wymaga 2FA, jeśli jest włączone),
            // a podpis konta nie zmienił się od zatwierdzenia.
            AdminSession::login($pdo, $user, !empty($user['totp_secret']), 'quick');
            Logger::info('Szybkie logowanie: zalogowano nowe urządzenie', ['user_id' => (int) $user['id'], 'ip' => $ip]);
            $quick->notify($user, $ip, $agent);
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                exit(json_encode(['status' => 'ok']));
            }
            header('Location: index.php', true, 303);
            exit;
        }
        if ($result['status'] !== 'pending') {
            unset($_SESSION['quick_login']);
        }
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['status' => $result['status']]));
        }
        $error = match ($result['status']) {
            'pending' => 'Logowanie nie zostało jeszcze zatwierdzone na drugim urządzeniu.',
            'rejected' => 'Logowanie odrzucono na drugim urządzeniu.',
            default => 'Kod wygasł. Wygeneruj nowy.',
        };
        $state = $_SESSION['quick_login'] ?? null;
        $valid = is_array($state);
    }
} elseif (!$valid || (int) $state['expires'] <= time()) {
    if (!$startRequest()) {
        http_response_code(429);
        header('Retry-After: 900');
        $error = 'Zbyt wiele prób. Spróbuj ponownie za 15 minut.';
    }
    $state = $_SESSION['quick_login'] ?? null;
    $valid = is_array($state);
}

$active = $valid && (int) $state['expires'] > time();
$code = $valid ? (string) $state['code'] : '';
$csrfToken = htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8');
$e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Zaloguj innym urządzeniem</title>
<style>
html,body{overflow-x:hidden;overscroll-behavior:none}body{margin:0;min-height:100vh;min-height:100dvh;padding:16px;box-sizing:border-box;display:grid;place-items:center;background:#1c1f24;color:#22252b;font:16px system-ui}
main{background:white;padding:32px 24px;border-radius:14px;width:100%;max-width:420px;box-sizing:border-box;text-align:center}
/* Na telefonie QR nie ma sensu: nikt nie skanuje ekranu telefonu. */
@media (max-width:700px),(pointer:coarse){.qr-only{display:none!important}}
h1{font-size:22px;margin:0 0 12px}p{line-height:1.5}
.code{font:700 32px ui-monospace,monospace;letter-spacing:.12em;margin:8px 0;user-select:all}
canvas{max-width:220px;width:100%;image-rendering:pixelated}
button{font:inherit;width:100%;padding:12px;margin:8px 0;border-radius:8px;border:0;background:#9c6b2e;color:white;cursor:pointer}
.secondary{background:#eee;color:#222}.muted{color:#6d7076;font-size:14px}
.error{padding:12px;background:#fbe6e1;color:#a3341f;border-radius:8px}a{color:#9c6b2e}
</style></head><body><main>
<h1>Zaloguj innym urządzeniem</h1>
<?php if ($error !== null): ?><p class="error" role="alert"><?= $e($error) ?></p><?php endif; ?>
<?php if ($active): ?>
<div id="quick" data-code="<?= $e($code) ?>" data-expires-in="<?= max(0, (int) $state['expires'] - time()) ?>">
    <p>Na urządzeniu, na którym jesteś już zalogowany do CRM (telefonie lub komputerze), otwórz <strong>2FA → Zatwierdź logowanie urządzenia</strong> i wpisz kod<span class="qr-only">. Telefonem możesz też po prostu zeskanować kod QR</span>:</p>
    <canvas id="quick-qr" class="qr-only" role="img" aria-label="Kod QR do zatwierdzenia logowania"></canvas>
    <div class="code"><?= $e(QuickLogin::format($code)) ?></div>
    <p class="muted" id="quick-status">Kod ważny jeszcze <span id="quick-left"><?= max(0, (int) $state['expires'] - time()) ?></span> s. Strona zaloguje się sama po zatwierdzeniu.</p>
    <form method="post"><input type="hidden" name="csrf" value="<?= $csrfToken ?>">
        <button class="secondary" name="action" value="check">Sprawdź, czy zatwierdzono</button></form>
</div>
<?php endif; ?>
<form method="post" id="quick-new" <?= $active ? 'hidden' : '' ?>><input type="hidden" name="csrf" value="<?= $csrfToken ?>">
    <button name="action" value="new">Wygeneruj nowy kod</button></form>
<p><a href="login.php">Zaloguj się hasłem</a></p>
<p class="muted">Nie zatwierdzaj kodu, który ktoś Ci podał lub przysłał. Zatwierdzaj tylko kod widoczny na Twoim ekranie.</p>
</main>
<?php if ($active): ?>
<script src="assets/qrcodegen.js"></script>
<script src="assets/quick-login.js" data-csrf="<?= $csrfToken ?>" id="quick-script"></script>
<?php endif; ?>
</body></html>
