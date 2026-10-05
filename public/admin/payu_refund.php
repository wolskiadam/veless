<?php
declare(strict_types=1);

/**
 * Zwrot pieniędzy przez PayU dla płatności przypisanej do zamówienia (Services\PayuPayments).
 * Dwa kroki: kwota → ekran potwierdzenia (obowiązkowe zaznaczenie „Potwierdzam” + okno potwierdzenia) → „Tak, zwróć”;
 * dopiero wtedy zwrot idzie do PayU. Bez zaznaczenia serwer odrzuca zwrot.
 * Uprawnienia jak moduł Zwroty (edycja). Po zleceniu wraca na stronę zamówienia.
 */

use Pase\Services\PayuPayments;
use Pase\Services\PayuPayouts;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
header('Cache-Control: no-store');
/** @var PDO $pdo */

$wooOrderId = (int) ($_REQUEST['order'] ?? 0);
$paymentId = (int) ($_REQUEST['payment'] ?? 0);
$back = 'order_view.php?id=' . $wooOrderId;
$e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

PayuPayments::migrate($pdo);
$svc = new PayuPayments($pdo, new PayuPayouts($pdo));
$payment = $svc->payment($paymentId);
if (!PayuPayouts::available() || $payment === null || (int) $payment['woo_order_id'] !== $wooOrderId) {
    flash('Nie ma takiej płatności PayU przy tym zamówieniu.', 'err');
    redirectAfterPost($back);
}
if (!canEdit()) {
    flash('Brak uprawnień do zwrotów.', 'err');
    redirectAfterPost($back);
}

$confirm = null;
$formError = null;
$formAmount = null;
$formDescription = 'Zwrot';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'prepare') {
            $formAmount = (string) ($_POST['amount'] ?? '');
            $formDescription = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 100);
            $payment = $svc->refresh($paymentId);
            $max = $svc->refundable($payment);
            $amount = PayuPayouts::parseAmount($formAmount);
            if ($amount === null || $amount <= 0) {
                throw new RuntimeException('Wpisz kwotę zwrotu, np. 49,90.');
            }
            if ($amount > $max) {
                throw new RuntimeException('Kwota ' . PayuPayouts::money($amount, (string) $payment['currency']) . ' jest większa niż możliwa do zwrotu ' . PayuPayouts::money($max, (string) $payment['currency']) . '.');
            }
            $confirm = ['amount' => $amount, 'description' => $formDescription, 'ext_id' => PayuPayments::newExtId()];
        } elseif ($action === 'refund') {
            if (($_POST['confirmed'] ?? '') !== '1') {
                $formAmount = number_format((int) ($_POST['amount'] ?? 0) / 100, 2, ',', '');
                $formDescription = (string) ($_POST['description'] ?? '');
                $confirm = ['amount' => (int) ($_POST['amount'] ?? 0), 'description' => $formDescription, 'ext_id' => (string) ($_POST['ext_id'] ?? '')];
                throw new RuntimeException('Zaznacz „Potwierdzam zwrot”, żeby zlecić zwrot. Nic nie zostało wysłane do PayU.');
            }
            $r = $svc->refund($paymentId, (int) ($_POST['amount'] ?? 0), (string) ($_POST['description'] ?? ''), (string) ($_POST['ext_id'] ?? ''), currentUserName());
            flash('Zlecono zwrot ' . PayuPayouts::money((int) $r['amount'], (string) $r['currency']) . ' przez PayU (status: ' . PayuPayments::refundLabel((string) $r['status'])
                . '). PayU odda pieniądze kupującemu tą samą metodą, którą zapłacił.');
            redirectAfterPost($back);
        }
    } catch (\Throwable $ex) {
        if ($action === 'prepare' || $confirm !== null) {
            $formError = $ex->getMessage();
        } else {
            flash($ex->getMessage(), 'err');
            redirectAfterPost($back);
        }
    }
}

$refundable = $svc->refundable($payment);
$refCur = (string) ($payment['currency'] ?: 'PLN');
$PAGE_TITLE = 'Zwrot przez PayU';
$PAGE_KEY = 'returns';
require __DIR__ . '/header.php';
?>
<div class="card" style="max-width:640px">
    <p><a href="<?= $e($back) ?>">← Wróć do zamówienia</a></p>
    <table style="font-size:13px">
        <tr><th style="width:40%">Płatność PayU</th><td style="font-family:var(--font-num)"><?= $e($payment['payu_order_id']) ?></td></tr>
        <tr><th>Zapłacono</th><td><?= $e(PayuPayouts::money((int) $payment['amount'], $refCur)) ?><?= (string) $payment['pay_method'] !== '' ? ' · ' . $e($payment['pay_method']) : '' ?></td></tr>
        <tr><th>Można zwrócić</th><td><b><?= $e(PayuPayouts::money($refundable, $refCur)) ?></b></td></tr>
        <?php if ((string) $payment['buyer'] !== ''): ?><tr><th>Płacący</th><td><?= $e($payment['buyer']) ?></td></tr><?php endif; ?>
    </table>

    <?php if ($confirm !== null): ?>
        <div style="margin-top:14px;padding:14px;border:2px solid var(--accent);border-radius:10px">
            <h3 style="margin-top:0">Potwierdź zwrot</h3>
            <?php if ($formError !== null): ?><div class="flash err"><?= $e($formError) ?></div><?php endif; ?>
            <p>Zwrócić kupującemu <b><?= $e(PayuPayouts::money($confirm['amount'], $refCur)) ?></b> przez PayU? <span style="color:#888">Tytuł: <?= $e($confirm['description'] ?: 'Zwrot') ?></span></p>
            <p style="color:#888;font-size:13px">PayU odda pieniądze tą samą metodą, którą klient zapłacił. Zwrotu nie da się cofnąć z CRM. Sklep WooCommerce i zwroty w module Zwroty nie są zmieniane.</p>
            <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"
                  onsubmit="if(!this.confirmed.checked){alert('Zaznacz „Potwierdzam zwrot”.');return false;} if(!confirm(<?= $e(json_encode('Na pewno zwrócić ' . PayuPayouts::money($confirm['amount'], $refCur) . ' klientowi przez PayU? Tego nie da się cofnąć.', JSON_UNESCAPED_UNICODE)) ?>)){return false;} this.querySelector('button[type=submit]').disabled=true; return true;">
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="refund">
                <input type="hidden" name="order" value="<?= $wooOrderId ?>"><input type="hidden" name="payment" value="<?= $paymentId ?>">
                <input type="hidden" name="amount" value="<?= (int) $confirm['amount'] ?>">
                <input type="hidden" name="description" value="<?= $e($confirm['description']) ?>">
                <input type="hidden" name="ext_id" value="<?= $e($confirm['ext_id']) ?>">
                <label style="flex-basis:100%;display:flex;gap:8px;align-items:flex-start;font-size:13.5px;margin-bottom:6px">
                    <input type="checkbox" name="confirmed" value="1" required style="margin-top:3px">
                    <span>Potwierdzam zwrot <b><?= $e(PayuPayouts::money($confirm['amount'], $refCur)) ?></b> klientowi przez PayU. Rozumiem, że tego nie da się cofnąć.</span></label>
                <button class="btn" type="submit">Tak, zwróć <?= $e(PayuPayouts::money($confirm['amount'], $refCur)) ?></button>
                <a class="btn secondary" href="<?= $e($back) ?>">Anuluj</a>
            </form>
        </div>
    <?php elseif ($refundable > 0): ?>
        <?php if ($formError !== null): ?><div class="flash err" style="margin-top:12px"><?= $e($formError) ?></div><?php endif; ?>
        <form method="post" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-top:14px">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="prepare">
            <input type="hidden" name="order" value="<?= $wooOrderId ?>"><input type="hidden" name="payment" value="<?= $paymentId ?>">
            <label style="display:flex;flex-direction:column;gap:4px;font-size:13px">Kwota zwrotu (<?= $e($refCur) ?>)
                <input name="amount" inputmode="decimal" required style="width:150px" value="<?= $e($formAmount ?? number_format($refundable / 100, 2, ',', '')) ?>"></label>
            <label style="display:flex;flex-direction:column;gap:4px;font-size:13px">Tytuł
                <input name="description" maxlength="100" style="width:240px" value="<?= $e($formDescription) ?>"></label>
            <button class="btn" type="submit">Dalej</button>
        </form>
        <p style="color:#888;font-size:12.5px">Po kliknięciu „Dalej” zobaczysz ekran potwierdzenia - do tego momentu nic nie jest wysyłane. Zwrot towaru z przyjęciem na stan i korektą zrobisz w module Zwroty (sposób „Przez PayU”).</p>
    <?php else: ?>
        <p style="margin-top:14px">Z tej płatności nie ma już czego zwrócić<?= $payment['status'] !== PayuPayments::PAID ? ' (płatność nie jest opłacona)' : '' ?>.</p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
