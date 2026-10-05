<?php
declare(strict_types=1);

/**
 * Ustawienia e-mail (Konfiguracja → E-mail).
 *
 * Konta nadawcy (SMTP) - po jednym na sklep/markę, np. „Mój Sklep" i „Druga marka". Każdy sklep
 * (źródło zamówień) ma przypisane konto: klient sklepu X dostaje maile z adresu sklepu X.
 * Szablon e-mail może wymusić konkretne konto (Szablony e-mail → „Wyślij z").
 */

use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\SettingsRepository;
use Pase\Services\MailAccounts;
use Pase\Services\Mailer;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$settings = new SettingsRepository($pdo);
$accounts = new MailAccounts($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_account') {
        $id = (int) ($_POST['id'] ?? 0) ?: null;
        $from = trim((string) ($_POST['from_email'] ?? ''));
        $reply = trim((string) ($_POST['reply_to'] ?? ''));
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            flash('Podaj poprawny adres nadawcy.', 'err');
            redirectAfterPost();
            exit;
        }
        $review = trim((string) ($_POST['review_link'] ?? ''));
        if ($review !== '' && !preg_match('~^https?://~i', $review)) {
            flash('Link do opinii musi zaczynać się od https://', 'err');
            redirectAfterPost();
            exit;
        }
        if ($reply !== '' && !filter_var($reply, FILTER_VALIDATE_EMAIL)) {
            flash('Adres „Odpowiedz do" jest niepoprawny.', 'err');
            redirectAfterPost();
            exit;
        }
        $id = $accounts->save($id, [
            'name' => (string) ($_POST['name'] ?? ''), 'from_email' => $from, 'from_name' => (string) ($_POST['from_name'] ?? ''),
            'reply_to' => $reply, 'host' => (string) ($_POST['host'] ?? ''), 'port' => (string) ($_POST['port'] ?? ''),
            'user' => (string) ($_POST['user'] ?? ''), 'pass' => (string) ($_POST['pass'] ?? ''),
            'secure' => (string) ($_POST['secure'] ?? 'tls'),
            'review_link' => $review,
        ]);
        try {
            if (!empty($_POST['logo_remove'])) {
                $accounts->removeLogo($id);
            } elseif (($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $accounts->setLogo($id, $_FILES['logo']);
            }
        } catch (\RuntimeException $e) {
            flash('Zapisano konto e-mail, ale logo nie: ' . $e->getMessage(), 'err');
            header('Location: email_settings.php?edit=' . $id, true, 303);
            exit;
        }
        flash('Zapisano konto e-mail.');
        header('Location: email_settings.php', true, 303);
        exit;
    }

    if ($action === 'default') {
        $accounts->setDefault((int) ($_POST['id'] ?? 0));
        flash('Ustawiono konto domyślne.');
    } elseif ($action === 'delete') {
        $accounts->delete((int) ($_POST['id'] ?? 0));
        flash('Usunięto konto e-mail. Sklepy i szablony, które go używały, wysyłają teraz z konta domyślnego.');
    } elseif ($action === 'test') {
        $acc = $accounts->find((int) ($_POST['id'] ?? 0));
        $to  = trim((string) ($_POST['test_to'] ?? ''));
        if ($acc === null) {
            flash('Nie znaleziono konta.', 'err');
        } else {
            [$ok, $msg] = (new Mailer(MailAccounts::toMailerConfig($acc)))->send(
                $to,
                'Veless — test SMTP (' . $acc['name'] . ')',
                '<p>To jest testowa wiadomość z systemu <strong>Veless</strong> z konta <strong>'
                    . htmlspecialchars((string) $acc['name']) . '</strong>.</p><p>Jeśli ją widzisz, wysyłka działa.</p>',
                ['type' => 'smtp_test']
            );
            flash(($ok ? '✅ Test wysłany: ' : '❌ Test nieudany: ') . $msg, $ok ? 'ok' : 'err');
        }
    } elseif ($action === 'map') {
        $accounts->setSourceMap(array_map('intval', (array) ($_POST['map'] ?? [])));
        flash('Zapisano przypisanie sklepów do kont e-mail.');
    } elseif ($action === 'alerts') {
        $alertEmail = trim((string) ($_POST['alert_email'] ?? ''));
        $alertsEnabled = ($_POST['alerts_enabled'] ?? '') === '1';
        if (($alertEmail !== '' && !filter_var($alertEmail, FILTER_VALIDATE_EMAIL)) || ($alertsEnabled && $alertEmail === '')) {
            flash('Podaj poprawny adres odbiorcy alarmów. Włączenie alarmów wymaga adresu e-mail.', 'err');
        } else {
            $settings->setMany(['OPS_ALERT_EMAIL' => $alertEmail, 'OPS_ALERT_ENABLED' => $alertsEnabled ? '1' : '0']);
            flash('Zapisano ustawienia alarmów.');
        }
    }
    redirectAfterPost();
}

$list    = $accounts->all();
$map     = $accounts->sourceMap();
$editId  = (int) ($_GET['edit'] ?? 0);
$edit    = $editId ? $accounts->find($editId) : null;
$showNew = isset($_GET['new']) || $list === [];
$sources = array_values(array_filter((new IntegrationAccountRepository($pdo))->all(),
    static fn($a) => in_array($a['type'], ['woocommerce', 'allegro'], true)));
$s = $settings->all();

$PAGE_TITLE = 'E-mail (SMTP)';
$PAGE_KEY   = 'email_settings';
require __DIR__ . '/header.php';

/** Formularz konta (nowe albo edycja). */
function mailAccountForm(?array $a): void
{
    $v = static fn(string $k, string $d = '') => htmlspecialchars((string) ($a[$k] ?? $d));
    $secure = (string) ($a['secure'] ?? 'tls');
    $hasPass = ($a['pass'] ?? '') !== '';
    ?>
    <form method="post" class="ma-form" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="save_account">
        <input type="hidden" name="id" value="<?= (int) ($a['id'] ?? 0) ?>">
        <div class="ma-grid ma-2">
            <div><label>Nazwa konta (dla Ciebie)</label><input name="name" value="<?= $v('name') ?>" placeholder="np. Mój Sklep"></div>
            <div><label>Nazwa nadawcy (widzi klient)</label><input name="from_name" value="<?= $v('from_name') ?>" placeholder="Mój Sklep"></div>
            <div><label>Adres nadawcy (From)</label><input name="from_email" type="email" required value="<?= $v('from_email') ?>" placeholder="admin@example.com"></div>
            <div><label>Odpowiedz do (Reply-To, opcjonalnie)</label><input name="reply_to" type="email" value="<?= $v('reply_to') ?>" placeholder="(jak adres nadawcy)"></div>
            <div style="grid-column:1/-1"><label>Logo sklepu do maili (PNG / JPG / GIF, max 300 KB) — {{shop_logo}} w szablonach</label>
                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                    <?php if (!empty($a['logo_data'])): ?>
                        <img src="data:<?= htmlspecialchars((string) $a['logo_mime']) ?>;base64,<?= htmlspecialchars((string) $a['logo_data']) ?>" alt="Logo" style="max-width:220px;max-height:90px;border:1px solid var(--line);border-radius:6px;padding:4px;background:#fff">
                        <label style="display:flex;gap:6px;align-items:center;font-size:13px"><input type="checkbox" name="logo_remove" value="1" style="width:auto"> Usuń logo</label>
                    <?php endif; ?>
                    <input type="file" name="logo" accept="image/png,image/jpeg,image/gif" style="width:auto">
                </div>
            </div>
            <div style="grid-column:1/-1"><label>Link do opinii o sklepie (np. Google, opcjonalnie) — {{review_link}} w szablonie „Oceń nas"</label><input name="review_link" type="url" value="<?= $v('review_link') ?>" placeholder="https://g.page/r/.../review"></div>
        </div>
        <div class="ma-grid ma-3">
            <div><label>Serwer SMTP</label><input name="host" value="<?= $v('host') ?>" placeholder="np. smtp.twojhosting.pl"></div>
            <div><label>Port</label><input name="port" type="number" value="<?= $v('port', '587') ?>"></div>
            <div><label>Szyfrowanie</label>
                <select name="secure">
                    <option value="tls" <?= $secure === 'tls' ? 'selected' : '' ?>>STARTTLS (587)</option>
                    <option value="ssl" <?= $secure === 'ssl' ? 'selected' : '' ?>>SSL/TLS (465)</option>
                    <option value="none" <?= $secure === 'none' ? 'selected' : '' ?>>Brak (25)</option>
                </select></div>
        </div>
        <div class="ma-grid ma-2">
            <div><label>Login</label><input name="user" value="<?= $v('user') ?>" placeholder="zwykle adres e-mail"></div>
            <div><label>Hasło <?= $hasPass ? '(zapisane — wpisz, by zmienić)' : '' ?></label>
                <input name="pass" type="password" autocomplete="new-password" placeholder="<?= $hasPass ? '••••••••' : '' ?>"></div>
        </div>
        <p style="margin-top:14px;display:flex;gap:8px">
            <button class="btn" type="submit">Zapisz konto</button>
            <a class="btn secondary" href="email_settings.php">Anuluj</a>
        </p>
    </form>
    <?php
}
?>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
        <div>
            <strong>Konta nadawcy</strong>
            <p style="color:#888;font-size:13px;margin:4px 0 0">Osobny adres i serwer SMTP dla każdego sklepu. Klient dostaje maile z konta sklepu, w którym kupił.</p>
        </div>
        <?php if (!$showNew && !$edit): ?><a class="btn" href="?new=1">＋ Dodaj konto</a><?php endif; ?>
    </div>

    <?php if ($list): ?>
        <table style="margin-top:14px">
            <tr><th>Konto</th><th>Nadawca</th><th>Serwer</th><th></th></tr>
            <?php foreach ($list as $a): ?>
                <tr>
                    <td><strong><?= htmlspecialchars((string) $a['name']) ?></strong>
                        <?php if ((int) $a['is_default'] === 1): ?> <span class="pill ok">domyślne</span><?php endif; ?></td>
                    <td><?= htmlspecialchars((string) $a['from_name']) ?> <span style="color:#888">&lt;<?= htmlspecialchars((string) $a['from_email']) ?>&gt;</span>
                        <?php if (!empty($a['reply_to'])): ?><div style="color:#888;font-size:12px">odpowiedzi → <?= htmlspecialchars((string) $a['reply_to']) ?></div><?php endif; ?></td>
                    <td style="color:#666"><?= htmlspecialchars($a['host'] !== '' ? $a['host'] . ':' . $a['port'] : '—') ?></td>
                    <td style="text-align:right;white-space:nowrap">
                        <a class="btn secondary" href="?edit=<?= (int) $a['id'] ?>" style="padding:4px 10px;font-size:13px">Edytuj</a>
                        <?php if ((int) $a['is_default'] !== 1): ?>
                            <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="default"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                <button class="btn secondary" type="submit" style="padding:4px 10px;font-size:13px">Ustaw domyślne</button></form>
                            <form method="post" style="display:inline" onsubmit="return confirm('Usunąć to konto e-mail?')"><input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                <button class="btn danger" type="submit" style="padding:4px 10px;font-size:13px">Usuń</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr class="ma-test-row">
                    <td colspan="4">
                        <form method="post" class="ma-test"><input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="test"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                            <input name="test_to" type="email" required placeholder="adres do testu" value="<?= htmlspecialchars((string) $a['from_email']) ?>">
                            <button class="btn secondary" type="submit" style="padding:4px 10px;font-size:13px">Wyślij test</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <?php if ($edit): ?>
        <hr class="ma-hr"><strong>Edycja: <?= htmlspecialchars((string) $edit['name']) ?></strong>
        <?php mailAccountForm($edit); ?>
    <?php elseif ($showNew): ?>
        <hr class="ma-hr"><strong><?= $list ? 'Nowe konto' : 'Dodaj pierwsze konto nadawcy' ?></strong>
        <?php mailAccountForm(null); ?>
    <?php endif; ?>
</div>

<?php if ($list): ?>
<div class="card">
    <strong>Z którego konta wysyła sklep</strong>
    <p style="color:#888;font-size:13px;margin:4px 0 12px">Dotyczy e-maili z automatyzacji, wiadomości do klienta i nazwy sklepu na stronie zamówienia oraz wydrukach.
        Szablon e-mail może wymusić inne konto (Szablony e-mail → „Wyślij z").</p>
    <?php if ($sources === []): ?>
        <p style="color:#888">Brak sklepów — dodaj integrację WooCommerce lub Allegro.</p>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="map">
            <table>
                <tr><th>Sklep / kanał sprzedaży</th><th>Wysyłaj z konta</th></tr>
                <?php foreach ($sources as $src): $cur = (int) ($map[(string) $src['id']] ?? 0); ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $src['name']) ?> <span style="color:#888;font-size:12px">(<?= $src['type'] === 'allegro' ? 'Allegro' : 'WooCommerce' ?>)</span></td>
                        <td>
                            <select name="map[<?= (int) $src['id'] ?>]" style="min-width:260px">
                                <option value="0">— konto domyślne —</option>
                                <?php foreach ($list as $a): ?>
                                    <option value="<?= (int) $a['id'] ?>" <?= $cur === (int) $a['id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['name'] . ' <' . $a['from_email'] . '>') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <p style="margin-top:12px"><button class="btn" type="submit">Zapisz przypisanie</button></p>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <form method="post">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="alerts">
        <strong>Alarmy o awariach</strong>
        <div class="ma-grid ma-2" style="margin-top:10px">
            <div><label for="alert_email">Adres odbiorcy alarmów</label>
                <input id="alert_email" name="alert_email" type="email" maxlength="254" value="<?= htmlspecialchars($s['OPS_ALERT_EMAIL'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
            <div style="align-self:end"><label style="display:flex;gap:6px;align-items:center;font-size:14px;color:#333"><input type="checkbox" name="alerts_enabled" value="1"
                <?= ($s['OPS_ALERT_ENABLED'] ?? '0') === '1' ? 'checked' : '' ?>> Włącz alarmy e-mail</label></div>
        </div>
        <p style="color:#888;font-size:12px">Wysyłane z konta domyślnego. Wymagają osobnego zadania monitorującego.</p>
        <p><button class="btn secondary" type="submit">Zapisz alarmy</button></p>
    </form>
    <p style="color:#888;font-size:12px;margin:8px 0 0">
        Ustawienia strony zamówienia klienta (weryfikacja, adres bazowy, powiadomienia) znajdziesz w zakładce <a href="client_settings.php">Strona klienta</a>.
    </p>
</div>

<style>
.ma-grid{display:grid;gap:12px;margin-top:12px}
.ma-2{grid-template-columns:1fr 1fr}
.ma-3{grid-template-columns:2fr 1fr 1fr}
.ma-grid label, .ma-form label{font-size:12px;color:#888;display:block;margin-bottom:3px}
.ma-grid input, .ma-grid select{width:100%}
.ma-hr{margin:18px 0 10px;border:0;border-top:1px solid #eee}
.ma-test-row td{padding-top:0;border-top:0}
.ma-test{display:flex;gap:6px;align-items:center}
.ma-test input{max-width:260px;padding:4px 8px;font-size:13px}
@media (max-width:700px){.ma-2,.ma-3{grid-template-columns:1fr}}
</style>

<?php require __DIR__ . '/footer.php'; ?>
