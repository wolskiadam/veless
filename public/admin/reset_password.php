<?php
declare(strict_types=1);

/**
 * Ustawienie nowego hasła z linku z e-maila (?token=…). Link jednorazowy, ważny 60 minut.
 */

require_once __DIR__ . '/db_admin.php';
\Pase\Support\Session::startAdmin();
require_once __DIR__ . '/bootstrap_admin.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');   // token w adresie nie wycieknie w nagłówku Referer
/** @var PDO $pdo */

\Pase\Support\I18n::setLocale($_SESSION['pase_lang'] ?? \Pase\Support\I18n::detect(null, $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null));
$_SESSION['login_csrf'] ??= bin2hex(random_bytes(32));

$reset = new \Pase\Support\PasswordReset($pdo);
$token = is_string($_REQUEST['token'] ?? null) ? (string) $_REQUEST['token'] : '';
$user  = $reset->userForToken($token);
$error = null;
$done  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? null;
    if (!is_string($csrf) || !hash_equals($_SESSION['login_csrf'], $csrf)) {
        $error = 'Sesja wygasła — spróbuj jeszcze raz.';
    } else {
        $error = $reset->reset($token, (string) ($_POST['password'] ?? ''), (string) ($_POST['password2'] ?? ''));
        if ($error === null) {
            $done = true;
            // Bieżąca sesja (jeśli ktoś był zalogowany) też jest już nieważna - czyścimy ją.
            $lang = $_SESSION['pase_lang'] ?? null;
            $_SESSION = $lang ? ['pase_lang' => $lang] : [];
            session_regenerate_id(true);
        }
    }
}

$cardTitle = 'Ustaw nowe hasło';
require __DIR__ . '/_auth_card.php';
?>
        <h1>Ustaw nowe hasło</h1>
        <?php if ($done): ?>
            <div class="ok-msg">Hasło zostało zmienione. Wszystkie inne sesje tego konta zostały wylogowane.</div>
            <a class="btn-login" href="login.php" style="display:block;text-align:center;text-decoration:none">Zaloguj się</a>
        <?php elseif ($user === null): ?>
            <div class="error-msg">Link wygasł albo został już użyty.</div>
            <a class="btn-login" href="forgot_password.php" style="display:block;text-align:center;text-decoration:none">Wyślij nowy link</a>
            <a class="back" href="login.php">← Wróć do logowania</a>
        <?php else: ?>
            <p class="lead">Konto: <strong><?= htmlspecialchars((string) $user['username']) ?></strong>. Hasło musi mieć co najmniej <?= \Pase\Support\PasswordReset::MIN_LENGTH ?> znaków.</p>
            <?php if ($error): ?><div class="error-msg"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <input type="text" name="username" value="<?= htmlspecialchars((string) $user['username']) ?>" autocomplete="username" hidden>
                <label for="password">Nowe hasło</label>
                <input type="password" id="password" name="password" required minlength="<?= \Pase\Support\PasswordReset::MIN_LENGTH ?>" autocomplete="new-password" autofocus>
                <label for="password2">Powtórz hasło</label>
                <input type="password" id="password2" name="password2" required minlength="<?= \Pase\Support\PasswordReset::MIN_LENGTH ?>" autocomplete="new-password">
                <button type="submit" class="btn-login">Zapisz nowe hasło</button>
            </form>
        <?php endif; ?>
<?php authCardEnd();
