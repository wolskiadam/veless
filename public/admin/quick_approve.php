<?php
declare(strict_types=1);

/**
 * Szybkie logowanie - strona na ZALOGOWANYM urządzeniu (telefon albo komputer). Pokazuje, kto prosi o logowanie
 * (przeglądarka, system, IP) i pozwala je zatwierdzić albo odrzucić.
 */

use Pase\Support\Logger;
use Pase\Support\QuickLogin;
use Pase\Support\RateLimiter;

require __DIR__ . '/auth.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
/** @var PDO $pdo */
/** @var array $account */

$quick = new QuickLogin($pdo);
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

/** Kod z QR (GET) albo wpisany ręcznie (POST). Limit chroni przed zgadywaniem kodów. */
$lookup = static function (string $code) use ($quick, $account, $ip): ?array {
    $limiter = new RateLimiter(PASE_ROOT . '/storage/security/quick_login.json');
    if (!$limiter->consume(['approve-account:' . $account['id'] => [20, 900], 'approve-ip:' . $ip => [60, 900]])) {
        flash('Zbyt wiele prób. Spróbuj ponownie za 15 minut.', 'err');
        return null;
    }
    $request = $quick->findPending($code);
    if ($request === null) {
        flash('Nie znaleziono ważnego kodu. Sprawdź kod na komputerze albo wygeneruj nowy.', 'err');
        return null;
    }
    $_SESSION['quick_approve'] = ['id' => (int) $request['id'], 'expires' => (int) $request['expires_at']];
    return $request;
};

$request = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrfCheck();
    $action = $_POST['action'] ?? '';
    if ($action === 'lookup') {
        if ($lookup(is_string($_POST['code'] ?? null) ? $_POST['code'] : '') === null) {
            redirectAfterPost('quick_approve.php');
        }
        redirectAfterPost('quick_approve.php?pending=1');
    }
    $pending = $_SESSION['quick_approve'] ?? null;
    unset($_SESSION['quick_approve']);
    if (!is_array($pending) || (int) ($pending['expires'] ?? 0) <= time()) {
        flash('Kod wygasł. Wygeneruj nowy na urządzeniu, które chcesz zalogować.', 'err');
    } elseif ($action === 'approve') {
        if ($quick->approve((int) $pending['id'], $account, $ip)) {
            Logger::info('Szybkie logowanie: zatwierdzono na zalogowanym urządzeniu', ['user_id' => (int) $account['id'], 'request' => (int) $pending['id']]);
            flash('Zatwierdzono. Drugie urządzenie za chwilę się zaloguje.');
        } else {
            flash('Kod wygasł albo został już użyty.', 'err');
        }
    } elseif ($action === 'reject') {
        $quick->reject((int) $pending['id'], $account, $ip);
        flash('Odrzucono logowanie. Jeśli to nie Ty generowałeś kod, zmień hasło.');
    }
    redirectAfterPost('quick_approve.php');
}

if (isset($_GET['code']) && is_string($_GET['code'])) {
    // Link z QR: szukamy kodu, a potem przekierowujemy, żeby kod nie został w historii.
    redirectAfterPost($lookup($_GET['code']) === null ? 'quick_approve.php' : 'quick_approve.php?pending=1');
}
$pending = $_SESSION['quick_approve'] ?? null;
if (isset($_GET['pending']) && is_array($pending) && (int) $pending['expires'] > time()) {
    $st = $pdo->prepare("SELECT * FROM admin_quick_logins WHERE id = ? AND status = 'pending'");
    $st->execute([(int) $pending['id']]);
    $request = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$PAGE_TITLE = 'Zatwierdź logowanie urządzenia';
$PAGE_KEY = 'security';
require __DIR__ . '/header.php';
$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="card" style="max-width:560px">
<?php if ($request !== null): ?>
    <h2>Zalogować to urządzenie na Twoje konto?</h2>
    <p><strong><?= $e(QuickLogin::describeAgent((string) $request['request_agent'])) ?></strong><br>
        Adres IP: <?= $e((string) $request['request_ip']) ?><br>
        Kod: <?= $e(date('H:i:s', (int) $request['created_at'])) ?>, ważny jeszcze <?= max(0, (int) $request['expires_at'] - time()) ?> s</p>
    <p class="flash err">Zatwierdzaj tylko wtedy, gdy sam(a) widzisz ten kod na swoim drugim urządzeniu. Jeśli ktoś podał Ci kod lub link, odrzuć.</p>
    <form method="post" style="display:flex;gap:10px;flex-wrap:wrap">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <button class="btn" name="action" value="approve">Zatwierdź logowanie</button>
        <button class="btn secondary" name="action" value="reject">Odrzuć</button>
    </form>
<?php else: ?>
    <h2>Zatwierdź logowanie urządzenia</h2>
    <p>Na nowym urządzeniu (telefonie lub komputerze) otwórz stronę logowania i wybierz <strong>Zaloguj innym urządzeniem</strong>. Wpisz poniżej pokazany tam kod albo, jeśli kod jest na ekranie komputera, zeskanuj QR aparatem tego telefonu.</p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="lookup">
        <p><label>Kod z ekranu nowego urządzenia<br>
            <input id="quick-code" name="code" maxlength="12" inputmode="text" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="ABCD-EFGH" required
                   style="font:600 20px ui-monospace,monospace;letter-spacing:.1em;text-transform:uppercase"></label></p>
        <button class="btn" type="submit">Dalej</button>
    </form>
<?php endif; ?>
    <p style="margin-top:18px"><a href="security.php">Historia szybkich logowań</a></p>
</div>
<script src="assets/quick-code-input.js"></script>
<?php require __DIR__ . '/footer.php'; ?>
