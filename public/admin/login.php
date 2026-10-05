<?php
declare(strict_types=1);

/**
 * Logowanie do panelu PASE. Wzorzec z sds-generator/login.php.
 * NIE includuje auth.php (uniknięcie pętli przekierowań) - tylko db_admin.php.
 */

// Najpierw bootstrap (config + autoloader), dopiero potem sesja - Session korzysta
// z PASE_ROOT i z klas PASE, więc kolejność jest tu istotna.
require_once __DIR__ . '/db_admin.php';

\Pase\Support\Session::startAdmin();

require_once __DIR__ . '/bootstrap_admin.php'; // utwórz tabelę + domyślnego admina
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
/** @var PDO $pdo */

// Język ekranu logowania: przełącznik ?setlang= (zapamiętany), inaczej przeglądarka.
if (isset($_GET['setlang']) && \Pase\Support\I18n::isAvailable((string) $_GET['setlang'])) {
    $_SESSION['pase_lang'] = preg_replace('/[^a-z]/', '', strtolower((string) $_GET['setlang']));
}
\Pase\Support\I18n::setLocale(
    $_SESSION['pase_lang'] ?? \Pase\Support\I18n::detect(null, $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null)
);

// Jeśli już zalogowany - na dashboard.
if (isset($_SESSION['pase_user_id'])) {
    header('Location: index.php');
    exit;
}

$error = null;
$_SESSION['login_csrf'] ??= bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $csrf = $_POST['csrf'] ?? null;

    if (is_string($csrf) && hash_equals($_SESSION['login_csrf'], $csrf) && $username !== '' && $password !== '') {
        $stmt = $pdo->prepare(
            'SELECT *
             FROM admin_users WHERE username = ?'
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $limiter = new \Pase\Support\RateLimiter(PASE_ROOT . '/storage/security/login.json');
        // Existing accounts use DB identity, so collation aliases share a limit.
        $accountKey = $user ? 'id:' . $user['id'] : 'name:' . mb_strtolower($username);
        $allowed = $limiter->consume([
            'account:' . $accountKey => [10, 900],
            'ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') => [50, 900],
        ]);
        if (!$allowed) {
            http_response_code(429);
            header('Retry-After: 900');
            $error = 'Zbyt wiele prób logowania. Spróbuj ponownie za 15 minut.';
        } elseif ($user && !$user['is_active']) {
            $error = t('login.error');
        } elseif ($user && password_verify($password, $user['password_hash'])) {
            if (!empty($user['totp_secret'])) {
                $language = $_SESSION['pase_lang'] ?? 'pl';
                $_SESSION = ['pase_lang' => $language];
                session_regenerate_id(true);
                // No authenticated user ID until the second factor succeeds.
                $_SESSION['mfa_pending'] = ['id' => (int) $user['id'],
                    'stamp' => \Pase\Support\AdminSession::stamp($user), 'expires' => time() + 300];
                header('Location: login_mfa.php', true, 303);
                exit;
            }
            \Pase\Support\AdminSession::login($pdo, $user, false);
            header('Location: index.php');
            exit;
        } else {
            $error = t('login.error');
        }
    } else {
        $error = t('login.error');
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\Pase\Support\I18n::locale()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(t('login.title')) ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
    <style>
        :root { --accent:#9c6b2e; --accent-soft:#f2e6d0; --ink:#22252b; --ink-2:#6d7076; --line:#e6e3da; --nav-bg:#1c1f24; }
        * { box-sizing:border-box; }
        html, body { overflow-x:hidden; overscroll-behavior:none; }
        body { display:flex; align-items:center; justify-content:center; min-height:100vh; min-height:100dvh; margin:0; padding:16px; background:var(--nav-bg); font-family:'Plus Jakarta Sans',system-ui,sans-serif; }
        .login-card { width:100%; max-width:380px; background:#fff; padding:40px; border-radius:14px; box-shadow:0 1px 2px rgba(0,0,0,.06), 0 24px 48px -20px rgba(0,0,0,.4); }
        .login-card h1 { margin:0 0 24px; color:var(--ink); font-size:22px; font-weight:800; letter-spacing:-.01em; line-height:1.25; }
        .error-msg { background:#fbe6e1; color:#a3341f; padding:10px; border-radius:8px; margin-bottom:20px; text-align:center; font-size:14px; }
        label { font-weight:600; font-size:.9em; color:var(--ink-2); display:block; margin-bottom:5px; }
        input { width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:8px; font-size:16px; font-family:inherit; margin-bottom:18px; box-sizing:border-box; }
        input:focus { border-color:var(--accent); outline:none; box-shadow:0 0 0 3px var(--accent-soft); }
        .btn-login { width:100%; padding:12px; background:var(--accent); color:#fff; border:0; border-radius:8px; font-size:15px; font-weight:700; font-family:inherit; cursor:pointer; transition:background .15s; }
        .btn-login:hover { background:#835a24; }
        .pw-row { display:flex; justify-content:space-between; align-items:baseline; gap:10px; }
        .forgot { font-size:.82em; color:var(--ink-2); margin-bottom:5px; }
        .forgot:hover { color:var(--ink); }
        .btn-phone { display:block; margin-top:12px; padding:11px; text-align:center; border:1px solid var(--line); border-radius:8px; color:var(--ink); font-weight:600; font-size:14px; text-decoration:none; }
        .btn-phone:hover { border-color:var(--accent); background:var(--accent-soft); }
        /* Telefon: bez przesuwania na boki; 16px w polach, żeby iOS nie powiększał strony przy wpisywaniu. */
        @media (max-width:480px) { .login-card { padding:28px 22px; } }
    </style>
</head>
<body>
    <div class="login-card">
        <h1><?= htmlspecialchars(t('login.heading')) ?></h1>
        <?php if ($error): ?><div class="error-msg"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if (\Pase\Support\Demo::on()): ?>
        <form method="POST" class="demo-box" style="background:var(--accent-soft);border-radius:10px;padding:16px;margin-bottom:22px;text-align:center">
            <p style="margin:0 0 12px;line-height:1.45">To wersja demonstracyjna z fikcyjnymi danymi.<br>Login i hasło: <b>demo</b></p>
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="username" value="<?= htmlspecialchars(\Pase\Support\Demo::USERNAME) ?>">
            <input type="hidden" name="password" value="<?= htmlspecialchars(\Pase\Support\Demo::PASSWORD) ?>">
            <button type="submit" class="btn-login">Wejdź do demo</button>
        </form>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <label for="username"><?= htmlspecialchars(t('login.user')) ?></label>
            <input type="text" id="username" name="username" required autofocus autocomplete="username" value="<?= htmlspecialchars(is_string($_POST['username'] ?? null) ? $_POST['username'] : '') ?>">
            <div class="pw-row"><label for="password"><?= htmlspecialchars(t('login.pass')) ?></label><?php if (!\Pase\Support\Demo::on()): ?><a href="forgot_password.php" class="forgot"><?= htmlspecialchars(t('login.forgot')) ?></a><?php endif; ?></div>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
            <button type="submit" class="btn-login"><?= htmlspecialchars(t('login.submit')) ?></button>
        </form>
        <?php if (!\Pase\Support\Demo::on()): ?><a href="login_quick.php" class="btn-phone">Zaloguj innym urządzeniem</a><?php endif; ?>
        <p style="text-align:center;margin:18px 0 0">
            <?php foreach (\Pase\Support\I18n::available() as $code => $name):
                $cur = $code === \Pase\Support\I18n::locale(); ?>
                <a href="login.php?setlang=<?= htmlspecialchars($code) ?>"
                   style="font-size:12px;margin:0 5px;text-decoration:none;<?= $cur ? 'font-weight:700;color:#9c6b2e' : 'color:#aaa' ?>"><?= htmlspecialchars($name) ?></a>
            <?php endforeach; ?>
        </p>
    </div>
</body>
</html>
