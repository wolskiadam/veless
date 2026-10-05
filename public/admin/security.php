<?php
declare(strict_types=1);

use Pase\Services\TwoFactorService;
use Pase\Support\AdminSession;
use Pase\Support\Env;
use Pase\Support\Totp;

require __DIR__ . '/auth.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$cspNonce = base64_encode(random_bytes(24));
header("Content-Security-Policy: default-src 'none'; script-src 'self' 'nonce-{$cspNonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$required = TwoFactorService::required($account);
$enabled = !empty($account['totp_secret']);
$setup = $_SESSION['totp_setup'] ?? null;
if (!is_array($setup) || ($setup['expires'] ?? 0) <= time()
    || !hash_equals(AdminSession::stamp($account), (string) ($setup['stamp'] ?? ''))) {
    unset($_SESSION['totp_setup']);
    $setup = null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrfCheck();
    $action = $_POST['action'] ?? '';
    if ($action === 'cancel_setup') {
        unset($_SESSION['totp_setup']);
        redirectAfterPost('security.php');
    }
    // Wylogowanie urządzeń tylko ogranicza dostęp, więc nie wymaga hasła.
    if ($action === 'device_logout' || $action === 'devices_logout_others') {
        $devices = new \Pase\Support\AdminDevices($pdo);
        $current = (int) ($_SESSION['pase_device_id'] ?? 0);
        $target = (int) ($_POST['device'] ?? 0);
        if ($action === 'devices_logout_others') {
            $count = $devices->revokeOthers((int) $account['id'], $current);
            flash($count > 0 ? 'Wylogowano pozostałe urządzenia.' : 'Nie ma innych zalogowanych urządzeń.');
        } elseif ($target === $current) {
            flash('To urządzenie wylogujesz przyciskiem „Wyloguj” w menu.', 'err');
        } elseif ($devices->revoke((int) $account['id'], $target)) {
            flash('Wylogowano urządzenie.');
        } else {
            flash('To urządzenie jest już wylogowane.', 'err');
        }
        redirectAfterPost('security.php');
    }
    if (!TwoFactorService::allowAttempt((int) $account['id'])) {
        flash('Zbyt wiele prób. Spróbuj ponownie za 15 minut.', 'err');
        redirectAfterPost('security.php');
    }
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $code = is_string($_POST['code'] ?? null) ? $_POST['code'] : '';
    if (!password_verify($password, $account['password_hash'])) {
        flash('Nieprawidłowe hasło.', 'err');
        redirectAfterPost('security.php');
    }
    try {
        $service = TwoFactorService::forDatabase($pdo);
        $codes = null;
        $changed = false;
        if ($action === 'start' && !$enabled) {
            $_SESSION['totp_setup'] = [
                'encrypted' => $service->protect(Totp::generateSecret(), (int) $account['id']),
                'stamp' => AdminSession::stamp($account), 'expires' => time() + 600,
            ];
            redirectAfterPost('security.php');
        } elseif ($action === 'enable' && !$enabled && $setup !== null) {
            $codes = $service->enable($account, $setup['encrypted'], $code);
            if ($codes === null) {
                flash('Nieprawidłowy kod lub konfiguracja konta uległa zmianie. Sprawdź czas w telefonie i spróbuj ponownie.', 'err');
            } else {
                $changed = true;
                $enabled = true;
            }
        } elseif (in_array($action, ['disable', 'regenerate'], true) && $enabled) {
            if ($action === 'disable' && $required) {
                flash('2FA jest wymagane dla tego konta. Nie można go wyłączyć.', 'err');
            } elseif (!$service->consume($account, $code)) {
                flash('Nieprawidłowy lub już użyty kod. Poczekaj na nowy kod albo użyj kodu odzyskiwania.', 'err');
            } elseif ($action === 'disable') {
                $changed = $service->disable($account);
                $enabled = !$changed;
            } else {
                $codes = $service->regenerate($account);
                $changed = $codes !== null;
            }
        } else {
            flash('Konfiguracja wygasła lub operacja jest niedostępna. Rozpocznij ponownie.', 'err');
        }
        if ($changed) {
            $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE id = ?');
            $stmt->execute([$account['id']]);
            $fresh = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$fresh || (int) $fresh['session_version'] !== (int) $account['session_version'] + 1
                || !hash_equals($account['password_hash'], $fresh['password_hash'])
                || $fresh['role'] !== $account['role']) {
                throw new RuntimeException('Account changed during verification');
            }
            AdminSession::login($pdo, $fresh, $enabled);
            if ($codes !== null) {
                $_SESSION['totp_recovery_once'] = ['codes' => $codes, 'expires' => time() + 300];
            }
            flash($enabled ? 'Zapisano ustawienia 2FA. Pozostałe sesje zostały unieważnione.' : 'Wyłączono 2FA. Pozostałe sesje zostały unieważnione.');
        }
    } catch (\Throwable $e) {
        flash('Nie udało się zmienić ustawień 2FA. Sprawdź konfigurację klucza szyfrowania i uprawnienia serwera.', 'err');
    }
    redirectAfterPost('security.php');
}

$codesOnce = $_SESSION['totp_recovery_once'] ?? null;
unset($_SESSION['totp_recovery_once']);
$codes = is_array($codesOnce) && ($codesOnce['expires'] ?? 0) > time() ? $codesOnce['codes'] : [];
$secret = $uri = null;
$setupError = null;
if (!$enabled && $setup !== null) {
    try {
        $service = TwoFactorService::forDatabase($pdo);
        $secret = $service->reveal($setup['encrypted'], (int) $account['id']);
        $uri = Totp::uri($secret, $account['username'], Env::get('TOTP_ISSUER', 'Veless') ?: 'Veless');
    } catch (\Throwable $e) {
        $setupError = 'Nie można odczytać konfiguracji 2FA. Sprawdź klucz szyfrowania.';
    }
}
$remaining = count(json_decode($account['totp_recovery_hashes'] ?? '[]', true) ?: []);
$PAGE_TITLE = 'Bezpieczeństwo konta — 2FA';
$PAGE_KEY = 'security';
require __DIR__ . '/header.php';
$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<style>
/* Szeroki ekran: 2FA po lewej, urządzenia i szybkie logowanie po prawej. Węższy: jedna kolumna. */
.security-grid { display:grid; grid-template-columns:minmax(0,1fr); gap:0 18px; align-items:start; }
@media (min-width:1100px) { .security-grid { grid-template-columns:minmax(0,1fr) minmax(0,1fr); } }
.security-grid > div { min-width:0; }
</style>
<div class="security-grid">
<div>
<div class="card">
    <h2>Google Authenticator — logowanie dwuetapowe</h2>
    <p>Po podaniu hasła CRM poprosi o kod z aplikacji. Działa też z innymi aplikacjami obsługującymi TOTP.</p>
    <p>Status: <strong><?= $enabled ? 'Włączone' : 'Wyłączone' ?></strong></p>
    <?php if ($required): ?><div class="flash <?= $enabled ? 'ok' : 'err' ?>">2FA jest wymagane dla tego konta. <?= $enabled ? 'Twoje konto spełnia ten wymóg.' : 'Skonfiguruj aplikację, aby uzyskać dostęp do pozostałych stron CRM.' ?></div><?php endif; ?>
    <?php if ($setupError): ?><div class="flash err"><?= $e($setupError) ?></div><?php endif; ?>

    <?php if ($codes !== []): ?>
    <section style="border:2px solid #9c6b2e;padding:18px;border-radius:10px;margin:18px 0">
        <h3>Zapisz kody odzyskiwania</h3>
        <p>Te kody są wyświetlane tylko raz. Każdy działa jeden raz zamiast kodu z telefonu, nadal wymagane jest hasło. Przechowuj je w menedżerze haseł lub bezpiecznym miejscu poza telefonem. Poprzedni zestaw nie jest już ważny.</p>
        <pre style="overflow:auto;user-select:all"><?php foreach ($codes as $recoveryCode) { echo $e($recoveryCode) . "\n"; } ?></pre>
    </section>
    <?php endif; ?>

    <?php if (!$enabled && $secret !== null): ?>
        <h3>1. Dodaj konto w aplikacji</h3>
        <p>W Google Authenticator wybierz „+” → „Zeskanuj kod QR”. Kod powstaje lokalnie — sekret nie jest wysyłany do zewnętrznego generatora.</p>
        <canvas id="totp-qr" data-uri="<?= $e($uri) ?>" role="img" aria-label="Kod QR do dodania konta w aplikacji" style="max-width:100%;image-rendering:pixelated"></canvas>
        <p>Możesz też wpisać klucz ręcznie (typ: na podstawie czasu):</p>
        <code style="overflow-wrap:anywhere;user-select:all"><?= $e($secret) ?></code>
        <p>Nie udostępniaj kodu QR ani klucza. Konfiguracja wygasa po 10 minutach.</p>
        <h3>2. Potwierdź włączenie 2FA</h3>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="enable">
            <p><label>Aktualne hasło<br><input type="password" name="password" required autocomplete="current-password"></label></p>
            <p><label>Kod z aplikacji<br><input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></label></p>
            <button class="btn" type="submit">Potwierdź i włącz 2FA</button>
        </form>
        <form method="post" style="margin-top:12px"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><button class="btn secondary" name="action" value="cancel_setup">Anuluj konfigurację</button></form>
        <script src="assets/qrcodegen.js"></script><script src="assets/totp-setup.js"></script>
    <?php elseif (!$enabled): ?>
        <p>Zainstaluj Google Authenticator, następnie potwierdź hasło, aby wyświetlić kod QR. Samo wyświetlenie QR nie włącza 2FA.</p>
        <form method="post"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="start">
            <p><label>Aktualne hasło<br><input type="password" name="password" autocomplete="current-password" required></label></p>
            <button class="btn" type="submit">Skonfiguruj 2FA</button>
        </form>
    <?php else: ?>
        <p>Pozostałe kody odzyskiwania: <strong><?= $remaining ?></strong>. Po użyciu kodu z aplikacji poczekaj na następny, zanim wykonasz kolejną operację.</p>
        <form method="post"><input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <p><label>Aktualne hasło<br><input type="password" name="password" autocomplete="current-password" required></label></p>
            <p><label>Kod z aplikacji lub kod odzyskiwania<br><input name="code" maxlength="64" autocomplete="one-time-code" spellcheck="false" required></label></p>
            <button class="btn" name="action" value="regenerate">Wygeneruj nowy zestaw kodów odzyskiwania</button>
            <?php if (!$required): ?><p><button class="btn secondary" name="action" value="disable">Wyłącz 2FA na tym koncie</button></p><?php endif; ?>
        </form>
    <?php endif; ?>
</div>
</div>
<div>
<?php
$deviceList = (new \Pase\Support\AdminDevices($pdo))->active($account, \Pase\Support\Session::lifetime());
$currentDevice = (int) ($_SESSION['pase_device_id'] ?? 0);
$methodLabels = ['password' => 'hasło', '2fa' => 'hasło i 2FA', 'quick' => 'szybkie logowanie', 'earlier' => 'wcześniejsze logowanie'];
?>
<div class="card" id="devices">
    <h2>Zalogowane urządzenia</h2>
    <p>Urządzenia, na których Twoje konto jest teraz zalogowane. Jeśli któregoś nie rozpoznajesz, wyloguj je i zmień hasło.</p>
    <div>
    <?php foreach ($deviceList as $device): $isCurrent = (int) $device['id'] === $currentDevice; ?>
        <div style="display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;justify-content:space-between;padding:12px 0;border-top:1px solid #e6e3da">
            <div style="min-width:0;flex:1 1 220px">
                <strong><?= $e(\Pase\Support\QuickLogin::describeAgent((string) $device['user_agent'])) ?></strong>
                <?php if ($isCurrent): ?><span style="color:#2e7d32;font-weight:700"> · to urządzenie</span><?php endif; ?><br>
                <small style="color:#6d7076;overflow-wrap:anywhere">Aktywne <?= $e(date('Y-m-d H:i', (int) $device['last_seen'])) ?> · IP <?= $e((string) $device['ip']) ?><br>
                    Zalogowano <?= $e(date('Y-m-d H:i', (int) $device['created_at'])) ?> (<?= $e($methodLabels[$device['method']] ?? (string) $device['method']) ?>)</small>
            </div>
            <?php if (!$isCurrent): ?>
            <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="device" value="<?= (int) $device['id'] ?>">
                <button class="btn secondary" name="action" value="device_logout">Wyloguj</button></form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
    <?php if (count($deviceList) > 1): ?>
    <form method="post" style="margin-top:14px"><input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <button class="btn" name="action" value="devices_logout_others">Wyloguj wszystkie inne urządzenia</button></form>
    <?php endif; ?>
</div>
<?php $quickHistory = (new \Pase\Support\QuickLogin($pdo))->recent((int) $account['id']); ?>
<div class="card">
    <h2>Szybkie logowanie innym urządzeniem</h2>
    <p>Na nowym urządzeniu (komputerze lub telefonie) wybierz <strong>Zaloguj innym urządzeniem</strong> na stronie logowania, a potem zatwierdź pokazany kod tutaj. Kod działa raz i wygasa po <?= \Pase\Support\QuickLogin::TTL ?> sekundach.</p>
    <p><a class="btn" href="quick_approve.php">Zatwierdź logowanie urządzenia</a></p>
    <?php if ($quickHistory !== []): ?>
    <h3>Ostatnie szybkie logowania</h3>
    <table class="table" style="width:100%;border-collapse:collapse"><thead><tr><th>Kiedy</th><th>Urządzenie</th><th>IP</th><th>Wynik</th></tr></thead><tbody>
    <?php foreach ($quickHistory as $row): ?>
        <tr><td><?= $row['decided_at'] ? $e(date('Y-m-d H:i', (int) $row['decided_at'])) : '' ?></td>
            <td><?= $e(\Pase\Support\QuickLogin::describeAgent((string) $row['request_agent'])) ?></td>
            <td><?= $e((string) $row['request_ip']) ?></td>
            <td><?= $e(['used' => 'Zalogowano', 'approved' => 'Zatwierdzono, nie odebrano', 'rejected' => 'Odrzucono', 'revoked' => 'Unieważniono'][$row['status']] ?? (string) $row['status']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
</div>
</div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
