<?php
declare(strict_types=1);

/**
 * System → PayU: saldo sklepów PayU i zlecanie wypłat na konto bankowe zapisane w PayU.
 *  - każdy sklep PayU to osobne konto wtyczki (Konfiguracja → Integracje → PayU) i osobna karta na tej stronie,
 *  - saldo czytane przy każdym wejściu (tylko odczyt),
 *  - wypłata w dwóch krokach: kwota → ekran potwierdzenia → „Tak, zleć wypłatę”; dopiero wtedy idzie do PayU.
 * Tylko administrator (albo konto z nadanym uprawnieniem „Edycja” do tej strony). Szczegóły: Services\PayuPayouts.
 */

use Pase\Services\PayuPayouts;

require __DIR__ . '/auth.php';
requireRole(['admin']);
header('Cache-Control: no-store');
/** @var PDO $pdo */

$PAGE_TITLE = 'PayU - saldo i wypłaty';
$PAGE_KEY   = 'payu';
if (!PayuPayouts::available()) {
    require __DIR__ . '/header.php';
    echo '<div class="card"><p>Wtyczka PayU jest wyłączona albo usunięta. Włącz ją w Konfiguracja → Wtyczki (administrator).</p></div>';
    require __DIR__ . '/footer.php';
    exit;
}
PayuPayouts::migrate($pdo);
$payu = new PayuPayouts($pdo);
$accounts = $payu->accounts();
$canEdit = canEdit();
$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$confirm = null;       // ekran potwierdzenia: account, amount, description, ext_id, currency
$formAccount = 0;      // sklep, w którego formularzu jest błąd / wpisane wartości
$formError = null;
$formAmount = null;
$formDescription = 'Wypłata z CRM';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if (!$canEdit) {
        flash('Brak uprawnień do zlecania wypłat.', 'err');
        redirectAfterPost('payu.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    $accountId = (int) ($_POST['account'] ?? 0);
    try {
        if ($action === 'prepare') {
            $formAccount = $accountId;
            $formAmount = (string) ($_POST['amount'] ?? '');
            $formDescription = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 100);
            $balance = $payu->balance($accountId);
            $confirm = [
                'account' => $accountId,
                'amount' => $payu->validateAmount($formAmount, $balance['available']),
                'description' => $formDescription,
                'ext_id' => PayuPayouts::newExtId(),
                'currency' => $balance['currency'],
            ];
        } elseif ($action === 'order') {
            $row = $payu->order($accountId, (int) ($_POST['amount'] ?? 0), (string) ($_POST['description'] ?? ''), (string) ($_POST['ext_id'] ?? ''), currentUserName());
            flash('Zlecono wypłatę ' . PayuPayouts::money((int) $row['amount'], (string) $row['currency']) . ' ze sklepu ' . $payu->shopLabel($row) . ' (numer PayU ' . $row['payout_id']
                . ', status: ' . PayuPayouts::statusLabel((string) $row['status']) . '). Pieniądze trafią na konto bankowe zapisane w PayU.');
            redirectAfterPost('payu.php');
        } elseif ($action === 'match') {
            \Pase\Services\PayuPayments::migrate($pdo);
            $m = (new \Pase\Services\PayuPayments($pdo, $payu))->matchRecent();
            flash('Sprawdzono zamówień opłaconych przez PayU: ' . $m['checked'] . ', przypisano płatności: ' . $m['linked']
                . ($m['failed'] ? ', nie znaleziono w PayU: ' . $m['failed'] : '') . ($m['remaining'] ? '. Zostały kolejne - kliknij jeszcze raz.' : '.'), $m['failed'] ? 'err' : 'ok');
            redirectAfterPost('payu.php');
        } elseif ($action === 'refresh') {
            $row = $payu->refresh((int) ($_POST['id'] ?? 0));
            flash('Status wypłaty: ' . PayuPayouts::statusLabel((string) $row['status']) . '.');
            redirectAfterPost('payu.php');
        }
    } catch (\Throwable $ex) {
        if ($action === 'prepare') {
            $formError = $ex->getMessage();
        } else {
            flash($ex->getMessage(), 'err');
            redirectAfterPost('payu.php');
        }
    }
}

// Saldo każdego sklepu (przy potwierdzeniu właśnie odczytane saldo tego sklepu jest świeże - bez drugiego zapytania).
$shops = [];
foreach ($accounts as $acc) {
    $id = (int) $acc['id'];
    $shop = ['acc' => $acc, 'configured' => $payu->configured($id), 'sandbox' => $payu->sandbox($id), 'balance' => null, 'error' => null];
    if ($shop['configured']) {
        try {
            $shop['balance'] = $payu->balance($id);
        } catch (\Throwable $ex) {
            $shop['error'] = $ex->getMessage();
            $shop['balance'] = $payu->lastBalance($id);
        }
    }
    $shops[] = $shop;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $payu->refreshOpen();
}
// Suma dostępnych środków, gdy sklepów jest kilka i wszystkie w jednej walucie.
$currencies = array_unique(array_filter(array_map(static fn(array $s): ?string => $s['balance']['currency'] ?? null, $shops)));
$sumAvailable = count($shops) > 1 && count($currencies) === 1
    ? array_sum(array_map(static fn(array $s): int => (int) ($s['balance']['available'] ?? 0), $shops)) : null;
$history = $payu->history();
$fmtDate = static fn(?string $d): string => $d ? date('d.m.Y H:i', strtotime($d)) : '—';
$pill = static fn(string $s): string => $s === 'REALIZED' ? 'ok' : (in_array($s, ['ERROR', 'CANCELED', 'UNKNOWN'], true) ? 'bad' : 'warn');

require __DIR__ . '/header.php';
?>
<style>
    .pu-muted { color:var(--ink-2); font-size:12.5px; }
    .pu-num { font-family:var(--font-num); font-variant-numeric:tabular-nums; white-space:nowrap; }
    .pu-cols { display:flex; gap:32px; flex-wrap:wrap; align-items:flex-end; }
    .pu-big { font-size:28px; font-weight:600; }
    .pu-head { display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:baseline; margin:0 0 12px; }
    .pu-head h3 { margin:0; }
    .pu-form { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-top:16px; padding-top:16px; border-top:1px solid var(--line); }
    .pu-form label { display:flex; flex-direction:column; gap:4px; font-size:13px; }
    .pu-pill { display:inline-block; padding:1px 8px; border-radius:999px; font-size:12px; background:#fff1d6; color:#975500; }
    .pu-pill.ok { background:#e6f4ea; color:#137333; }
    .pu-pill.bad { background:#fce8e6; color:#c5221f; }
    .pu-confirm { border:2px solid var(--accent); }
</style>

<?php if ($accounts === []): ?>
<div class="card">
    <p>Najpierw połącz CRM z PayU: dodaj konto dla każdego sklepu PayU i wpisz jego Id punktu płatności (client_id), klucz OAuth (client_secret) i Id sklepu (shopId).</p>
    <?php if (isAdmin()): ?><a class="btn" href="integration_edit.php?type=payu">Dodaj sklep PayU</a><?php endif; ?>
</div>
<?php else: ?>

<?php if ($confirm !== null): $cAcc = $payu->account($confirm['account']); ?>
<div class="card pu-confirm">
    <h3 style="margin-top:0">Potwierdź wypłatę z PayU</h3>
    <p>Wypłacić <b class="pu-num"><?= $e(PayuPayouts::money($confirm['amount'], $confirm['currency'])) ?></b> z salda sklepu <b><?= $e($cAcc['name'] ?? '') ?></b> na konto bankowe zapisane w PayU dla tego sklepu?
        <?php if ($confirm['description'] !== ''): ?><br><span class="pu-muted">Tytuł: <?= $e($confirm['description']) ?></span><?php endif; ?></p>
    <p class="pu-muted">Wypłaty zleconej w PayU nie da się cofnąć z CRM. Przed wysłaniem CRM jeszcze raz sprawdzi dostępne saldo.</p>
    <form method="post" style="display:flex; gap:8px; flex-wrap:wrap">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="order">
        <input type="hidden" name="account" value="<?= (int) $confirm['account'] ?>">
        <input type="hidden" name="amount" value="<?= (int) $confirm['amount'] ?>">
        <input type="hidden" name="description" value="<?= $e($confirm['description']) ?>">
        <input type="hidden" name="ext_id" value="<?= $e($confirm['ext_id']) ?>">
        <button class="btn" type="submit" onclick="this.disabled=true; this.form.submit();">Tak, zleć wypłatę <?= $e(PayuPayouts::money($confirm['amount'], $confirm['currency'])) ?></button>
        <a class="btn secondary" href="payu.php">Anuluj</a>
    </form>
</div>
<?php endif; ?>

<?php if ($sumAvailable !== null): ?>
<div class="card">
    <div class="pu-muted">Razem dostępne do wypłaty we wszystkich sklepach (<?= count($shops) ?>)</div>
    <div class="pu-big pu-num"><?= $e(PayuPayouts::money($sumAvailable, (string) reset($currencies))) ?></div>
</div>
<?php endif; ?>

<?php foreach ($shops as $shop): $acc = $shop['acc']; $id = (int) $acc['id']; $b = $shop['balance']; ?>
<div class="card">
    <div class="pu-head">
        <h3><?= $e($acc['name']) ?><?= $shop['sandbox'] ? ' <span class="pu-pill">sandbox - konto testowe</span>' : '' ?></h3>
        <span class="pu-muted"><?php if ($b !== null): ?><?= ($b['name'] ?? '') !== '' && $b['name'] !== $acc['name'] ? 'w PayU: ' . $e($b['name']) . ' · ' : '' ?>odczyt <?= $e($fmtDate($b['read_at'] ?? null)) ?> · <a href="payu.php">Odśwież</a><?php endif; ?>
            <?php if (isAdmin()): ?> · <a href="integration_edit.php?id=<?= $id ?>">ustawienia</a><?php endif; ?></span>
    </div>
    <?php if (!$shop['configured']): ?>
        <p class="pu-muted">Brak kluczy PayU dla tego sklepu. Uzupełnij client_id, client_secret i Id sklepu w ustawieniach.</p>
    <?php else: ?>
        <?php if ($shop['error'] !== null): ?><div class="flash err">Odczyt salda z PayU: <?= $e($shop['error']) ?><?= $b ? ' Poniżej ostatni odczyt.' : '' ?></div><?php endif; ?>
        <?php if ($b !== null): ?>
            <div class="pu-cols">
                <div><div class="pu-muted">Dostępne do wypłaty</div><div class="pu-big pu-num"><?= $e(PayuPayouts::money((int) $b['available'], (string) $b['currency'])) ?></div></div>
                <div><div class="pu-muted">Saldo sklepu</div><div class="pu-num" style="font-size:18px"><?= $e(PayuPayouts::money((int) $b['total'], (string) $b['currency'])) ?></div></div>
            </div>
            <?php if ($canEdit && $confirm === null): ?>
            <?php if ($formError !== null && $formAccount === $id): ?><div class="flash err" style="margin-top:12px"><?= $e($formError) ?></div><?php endif; ?>
            <form method="post" class="pu-form">
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="prepare">
                <input type="hidden" name="account" value="<?= $id ?>">
                <label>Kwota wypłaty (<?= $e($b['currency']) ?>)
                    <input name="amount" class="pu-num" inputmode="decimal" required style="width:160px"
                           value="<?= $e($formAccount === $id && $formAmount !== null ? $formAmount : number_format(((int) $b['available']) / 100, 2, ',', '')) ?>"></label>
                <label>Tytuł (opcjonalnie)
                    <input name="description" maxlength="100" style="width:260px" value="<?= $e($formAccount === $id ? $formDescription : 'Wypłata z CRM') ?>"></label>
                <button class="btn" type="submit">Zleć wypłatę…</button>
            </form>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<p class="pu-muted">„Dostępne” to saldo po odjęciu blokad i rezerwy wypłat - tylko tyle można wypłacić. Pieniądze trafiają na konto bankowe zapisane w PayU dla danego sklepu; przed wysłaniem zobaczysz ekran potwierdzenia.
    <?php if (isAdmin()): ?><a href="integration_edit.php?type=payu">+ Dodaj kolejny sklep PayU</a><?php endif; ?></p>

<?php if ($canEdit): ?>
<div class="card">
    <h3 style="margin-top:0">Płatności PayU przy zamówieniach</h3>
    <p class="pu-muted">Zamówienie ze sklepu opłacone przez PayU dostaje płatność automatycznie przy otwarciu (numer płatności podaje sklep). Dane transakcji i zwrot przez PayU są na stronie zamówienia.
        Tym przyciskiem przypiszesz płatności od razu do wszystkich takich zamówień (po <?= 40 ?> na kliknięcie, tylko odczyt z PayU).</p>
    <form method="post"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="match">
        <button class="btn secondary" type="submit">Dopasuj płatności PayU do zamówień</button></form>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="card">
    <h3 style="margin-top:0">Wypłaty zlecone z CRM</h3>
    <?php if (!$history): ?>
        <p class="pu-muted">Jeszcze nie zlecono żadnej wypłaty z CRM.</p>
    <?php else: ?>
    <table>
        <thead><tr><th>Data</th><th>Sklep</th><th>Kwota</th><th>Tytuł</th><th>Status</th><th>Numer PayU</th><th>Zlecił</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($history as $p): $s = strtoupper((string) $p['status']); ?>
            <tr>
                <td class="pu-num"><?= $e($fmtDate($p['created_at'])) ?></td>
                <td><?= $e($payu->shopLabel($p)) ?></td>
                <td class="pu-num"><?= $e(PayuPayouts::money((int) $p['amount'], (string) $p['currency'])) ?><?= (int) $p['sandbox'] ? ' <span class="pu-muted">(sandbox)</span>' : '' ?></td>
                <td><?= $e($p['description']) ?></td>
                <td><span class="pu-pill <?= $pill($s) ?>"><?= $e(PayuPayouts::statusLabel($s)) ?></span>
                    <?php if ((string) $p['error'] !== ''): ?><div class="pu-muted"><?= $e($p['error']) ?></div><?php endif; ?></td>
                <td class="pu-num pu-muted"><?= $e($p['payout_id'] ?: '—') ?></td>
                <td><?= $e($p['created_by']) ?></td>
                <td><?php if ($canEdit && (string) $p['payout_id'] !== '' && !in_array($s, PayuPayouts::FINAL, true)): ?>
                    <form method="post"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="refresh"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                        <button class="btn secondary" type="submit" style="padding:3px 10px">Sprawdź status</button></form>
                <?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
