<?php
declare(strict_types=1);

/**
 * „Nie pamiętasz hasła?" - wysyła link do ustawienia nowego hasła na e-mail konta.
 * Komunikat po wysłaniu jest zawsze ten sam, żeby nie dało się sprawdzać, jakie konta istnieją.
 */

require_once __DIR__ . '/db_admin.php';
\Pase\Support\Session::startAdmin();
require_once __DIR__ . '/bootstrap_admin.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
/** @var PDO $pdo */

\Pase\Support\I18n::setLocale($_SESSION['pase_lang'] ?? \Pase\Support\I18n::detect(null, $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null));
if (isset($_SESSION['pase_user_id'])) {
    header('Location: index.php');
    exit;
}

$_SESSION['login_csrf'] ??= bin2hex(random_bytes(32));
$sent = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $who  = is_string($_POST['who'] ?? null) ? trim($_POST['who']) : '';
    $csrf = $_POST['csrf'] ?? null;
    if (!is_string($csrf) || !hash_equals($_SESSION['login_csrf'], $csrf)) {
        $error = 'Sesja wygasła — spróbuj jeszcze raz.';
    } elseif ($who === '') {
        $error = 'Podaj login albo adres e-mail.';
    } else {
        $limiter = new \Pase\Support\RateLimiter(PASE_ROOT . '/storage/security/password_reset.json');
        $allowed = $limiter->consume([
            'who:' . mb_strtolower($who)                    => [3, 3600],
            'ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')  => [10, 3600],
        ]);
        if ($allowed) {
            try {
                (new \Pase\Support\PasswordReset($pdo))->request($who);
            } catch (\Throwable $e) {
                \Pase\Support\Logger::error('Reset hasła: ' . $e->getMessage());
            }
        }
        // Ten sam komunikat niezależnie od wyniku (także po przekroczeniu limitu).
        $sent = true;
    }
}

$cardTitle = 'Nie pamiętasz hasła?';
require __DIR__ . '/_auth_card.php';
?>
        <h1>Nie pamiętasz hasła?</h1>
        <?php if ($sent): ?>
            <div class="ok-msg">Jeśli konto istnieje i ma przypisany adres e-mail, wysłaliśmy na niego link do ustawienia nowego hasła. Link działa przez 60 minut. Sprawdź też folder spam.</div>
            <p class="lead">Konto nie ma adresu e-mail? Poproś administratora panelu o ustawienie nowego hasła (System → Użytkownicy).</p>
        <?php else: ?>
            <p class="lead">Podaj login albo adres e-mail konta. Wyślemy link do ustawienia nowego hasła.</p>
            <?php if ($error): ?><div class="error-msg"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <label for="who">Login lub e-mail</label>
                <input type="text" id="who" name="who" required autofocus autocomplete="username" value="<?= htmlspecialchars(is_string($_POST['who'] ?? null) ? $_POST['who'] : '') ?>">
                <button type="submit" class="btn-login">Wyślij link</button>
            </form>
        <?php endif; ?>
        <a class="back" href="login.php">← Wróć do logowania</a>
<?php authCardEnd();
