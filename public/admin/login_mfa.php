<?php
declare(strict_types=1);

use Pase\Support\AdminSession;
use Pase\Support\Session;
use Pase\Services\TwoFactorService;

require_once __DIR__ . '/db_admin.php';
Session::startAdmin();
require_once __DIR__ . '/bootstrap_admin.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$pending = $_SESSION['mfa_pending'] ?? [];
$stmt = $pdo->prepare('SELECT * FROM admin_users WHERE id = ?');
$stmt->execute([(int) ($pending['id'] ?? 0)]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!AdminSession::pendingValid($user, $pending)) {
    unset($_SESSION['mfa_pending']);
    header('Location: login.php');
    exit;
}
$_SESSION['mfa_csrf'] ??= bin2hex(random_bytes(32));
$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrf = $_POST['csrf'] ?? null;
    if (!is_string($csrf) || !hash_equals($_SESSION['mfa_csrf'], $csrf)) {
        http_response_code(419);
        $error = 'Sesja formularza wygasła. Odśwież stronę.';
    } elseif (($_POST['action'] ?? '') === 'cancel') {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: login.php', true, 303);
        exit;
    } elseif (!TwoFactorService::allowAttempt((int) $user['id'])) {
        http_response_code(429);
        header('Retry-After: 900');
        $error = 'Zbyt wiele prób. Spróbuj ponownie za 15 minut.';
    } else {
        try {
            $code = is_string($_POST['code'] ?? null) ? $_POST['code'] : '';
            if (TwoFactorService::forDatabase($pdo)->consume($user, $code)) {
                AdminSession::login($pdo, $user, true);
                header('Location: index.php', true, 303);
                exit;
            }
            $error = 'Nieprawidłowy lub już użyty kod. Wpisz aktualny kod z aplikacji albo kod odzyskiwania.';
        } catch (\Throwable $e) {
            http_response_code(503);
            $error = 'Weryfikacja 2FA jest chwilowo niedostępna. Skontaktuj się z administratorem.';
        }
    }
}
?>
<!doctype html>
<html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Potwierdzenie logowania</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#1c1f24;color:#22252b;font:16px system-ui}
main{background:white;padding:32px;border-radius:14px;width:min(390px,calc(100vw - 80px))}
input,button{font:inherit;box-sizing:border-box;width:100%;padding:12px;margin:12px 0;border-radius:8px;border:1px solid #ddd}
button{background:#9c6b2e;color:white;border:0;cursor:pointer}.secondary{background:#eee;color:#222}.error{padding:12px;background:#fbe6e1;color:#a3341f;border-radius:8px}p{line-height:1.5}
</style></head><body><main>
<h1>Potwierdź logowanie</h1>
<p>Wpisz sześciocyfrowy kod z Google Authenticator lub jeden z zapisanych kodów odzyskiwania. Ten krok wygasa po 5 minutach.</p>
<?php if ($error !== null): ?><p class="error" role="alert"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['mfa_csrf']) ?>">
<label for="code">Kod uwierzytelniający lub odzyskiwania</label>
<input id="code" name="code" type="text" maxlength="64" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" required autofocus>
<button type="submit">Zaloguj się</button>
</form>
<form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['mfa_csrf']) ?>"><button class="secondary" name="action" value="cancel">Wróć do logowania</button></form>
</main></body></html>
