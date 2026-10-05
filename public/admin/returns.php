<?php
declare(strict_types=1);

/**
 * Zwroty i korekty — zwroty zgłoszone na Allegro (pobierane automatycznie) i zwroty dodane ręcznie.
 *
 * Widoki:
 *   returns.php                      lista zwrotów
 *   returns.php?new=<woo_order_id>   nowy zwrot do zamówienia
 *   returns.php?id=<id>              szczegóły zwrotu i trzy kroki (stan, korekta, zwrot pieniędzy)
 *   returns.php?id=<id>&step=correction|refund   ekran potwierdzenia kroku nieodwracalnego
 *
 * Korekta w wFirma i zwrot pieniędzy wykonują się tylko po zaznaczeniu potwierdzenia i kliknięciu.
 */

use Pase\Queue\Queue;
use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\SettingsRepository;
use Pase\Repository\WooOrderRepository;
use Pase\Services\OrderReturns;
use PasePlugin\Allegro\AllegroPlugin;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor', 'viewer']);
header('Cache-Control: no-store');
/** @var PDO $pdo */

$config   = require PASE_ROOT . '/config/config.php';
$returns  = new OrderReturns($pdo);
$orders   = new WooOrderRepository($pdo);
$intRepo  = new IntegrationAccountRepository($pdo);
$settings = new SettingsRepository($pdo);

$id    = (int) ($_GET['id'] ?? 0);
$step  = (string) ($_GET['step'] ?? '');
$newFor = (int) ($_GET['new'] ?? 0);

$allegroClient = static fn() => AllegroPlugin::makeClient($pdo, $config['allegro'] ?? []);
$wfirmaClientFor = static function (array $doc) use ($intRepo): object {
    $integration = $intRepo->find((int) $doc['integration_id']);
    if (!$integration || $integration['type'] !== 'wfirma' || empty($integration['is_active'])) {
        throw new RuntimeException('Integracja wFirma, na której wystawiono fakturę, jest niedostępna lub wyłączona.');
    }
    return new \PasePlugin\Wfirma\WfirmaClient($integration['config']);
};
$wooClientFor = static function (array $order) use ($intRepo): object {
    $integration = $intRepo->find((int) ($order['integration_id'] ?? 0));
    $client = $integration ? \Pase\Plugin\IntegrationClient::for('woocommerce', $integration['config'] ?? []) : null;
    if ($client === null || !method_exists($client, 'createRefund')) {
        throw new RuntimeException('Integracja sklepu tego zamówienia jest niedostępna lub wyłączona.');
    }
    return $client;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if (!canEdit()) {
        flash('Brak uprawnień do zmian.', 'err');
        redirectAfterPost();
    }
    $action = (string) ($_POST['action'] ?? '');
    $rid = (int) ($_POST['return_id'] ?? 0);
    $back = $rid > 0 ? 'returns.php?id=' . $rid : null;
    try {
        switch ($action) {
            case 'sync':
                $client = $allegroClient();
                if ($client->bearerToken() === null) {
                    throw new RuntimeException('Konto Allegro nie jest połączone.');
                }
                $settings->setMany([OrderReturns::SYNC_AT_KEY => (string) time()]);
                $r = $returns->syncAllegro($client);
                if (!$r['ok']) {
                    throw new RuntimeException('Allegro: ' . $r['message']);
                }
                flash('Pobrano zwroty z Allegro (' . $r['count'] . ' z ostatnich ' . OrderReturns::SYNC_DAYS_BACK . ' dni).');
                break;
            case 'create':
                $orderId = (int) ($_POST['woo_order_id'] ?? 0);
                $qty = array_map('intval', (array) ($_POST['qty'] ?? []));
                $rid = $returns->createManual($orderId, $qty, (string) ($_POST['reason'] ?? ''), (string) ($_POST['note'] ?? ''), (string) ($_POST['waybill'] ?? ''));
                $back = 'returns.php?id=' . $rid;
                flash('Dodano zwrot. Przyjmij towar na stan, a potem wystaw korektę i zwróć pieniądze.');
                break;
            case 'note':
                $returns->saveNote($rid, (string) ($_POST['note'] ?? ''));
                flash('Zapisano notatkę.');
                break;
            case 'link_order':
                $ref = trim((string) ($_POST['order_ref'] ?? ''));
                $stmt = $pdo->prepare('SELECT woo_order_id FROM woo_orders WHERE pase_number = ? OR order_number = ? OR woo_order_id = ? LIMIT 1');
                $stmt->execute([ctype_digit($ref) ? (int) $ref : -1, $ref, ctype_digit($ref) ? $ref : '-1']);
                $found = $stmt->fetchColumn();
                if ($found === false) {
                    throw new RuntimeException('Nie znaleziono zamówienia o numerze ' . $ref . '.');
                }
                $returns->linkOrder($rid, (int) $found);
                flash('Powiązano zwrot z zamówieniem.');
                break;
            case 'restock':
                $qty = array_map('intval', (array) ($_POST['qty'] ?? []));
                $r = $returns->restock($rid, $qty, new Queue($pdo));
                $msg = $r['restocked'] === [] ? 'Nie doliczono żadnych sztuk.'
                    : 'Doliczono do stanu: ' . implode(', ', array_map(static fn($d) => $d['sku'] . ' +' . $d['quantity'], $r['restocked'])) . '. Stan zostanie wysłany do sklepu.';
                if ($r['skipped'] !== []) {
                    $msg .= ' Bez produktu w magazynie CRM (stan nie zmieniony): ' . implode(', ', $r['skipped']) . '.';
                }
                flash($msg, $r['skipped'] !== [] ? 'err' : 'ok');
                break;
            case 'correction':
                if (($_POST['confirm'] ?? '') !== '1') {
                    throw new RuntimeException('Zaznacz potwierdzenie przed wystawieniem korekty.');
                }
                $ret = $returns->find($rid);
                $doc = $ret && $ret['woo_order_id'] !== null ? $returns->invoiceFor((int) $ret['woo_order_id']) : null;
                if ($doc === null) {
                    throw new RuntimeException('Do zamówienia nie ma faktury z wFirma w CRM.');
                }
                $r = $returns->issueCorrection($rid, $wfirmaClientFor($doc), (array) ($_POST['ret'] ?? []), (string) ($_POST['reason'] ?? ''));
                flash('Wystawiono korektę w wFirma (ID ' . $r['document_id'] . ', zwrot ' . number_format($r['amount'], 2, ',', ' ') . ').');
                break;
            case 'refund':
                if (($_POST['confirm'] ?? '') !== '1') {
                    throw new RuntimeException('Zaznacz potwierdzenie przed zwrotem pieniędzy.');
                }
                $method = (string) ($_POST['method'] ?? '');
                $ret = $returns->find($rid);
                $order = $ret && $ret['woo_order_id'] !== null ? $orders->find((int) $ret['woo_order_id']) : null;
                $opts = [
                    'quantities'  => (array) ($_POST['qty'] ?? []),
                    'delivery'    => (string) ($_POST['delivery'] ?? '0'),
                    'comment'     => (string) ($_POST['comment'] ?? ''),
                    'via_gateway' => ($_POST['via_gateway'] ?? '') === '1',
                    'amount'      => (string) ($_POST['amount'] ?? ''),
                ];
                if ($method === 'allegro') {
                    $opts['client'] = $allegroClient();
                } elseif ($method === 'woo' && $order !== null) {
                    $opts['client'] = $wooClientFor($order);
                } elseif ($method === 'payu') {
                    \Pase\Services\PayuPayments::migrate($pdo);
                    $opts['client'] = new \Pase\Services\PayuPayments($pdo, new \Pase\Services\PayuPayouts($pdo));
                    $opts['user'] = currentUserName();
                }
                $r = $returns->refund($rid, $method, $opts);
                flash($r['message']);
                break;
            case 'confirm_done':
            case 'confirm_absent':
                if (!isAdmin()) {
                    throw new RuntimeException('Tylko administrator może rozstrzygnąć krok z nieznanym wynikiem.');
                }
                if (($_POST['verified'] ?? '') !== '1') {
                    throw new RuntimeException('Potwierdź, że sprawdzono wynik w wFirma / Allegro / sklepie.');
                }
                $s = (string) ($_POST['step'] ?? '');
                if ($action === 'confirm_done') {
                    $returns->confirmDone($rid, $s, (string) ($_POST['remote_id'] ?? ''));
                    flash('Zapisano wynik. Nie wysłano żadnego żądania.');
                } else {
                    $returns->confirmAbsent($rid, $s);
                    flash('Odblokowano krok. Nie wysłano żadnego żądania.');
                }
                break;
            default:
                throw new RuntimeException('Nieznana akcja.');
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
        if ($action === 'create') {
            $back = 'returns.php?new=' . (int) ($_POST['woo_order_id'] ?? 0);
        }
    }
    redirectAfterPost($back);
}

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$money = static fn($v, string $cur = 'PLN'): string => number_format((float) $v, 2, ',', ' ') . ' ' . ($cur === 'PLN' ? 'zł' : $cur);
$qtyFmt = static fn(float $v): string => rtrim(rtrim(number_format($v, 4, ',', ''), '0'), ',');
$orderLabel = static function (?array $order): string {
    if ($order === null) {
        return '—';
    }
    return '#' . ($order['pase_number'] ?? $order['woo_order_id']) . ((int) $order['woo_order_id'] >= 9000000000 ? ' (Allegro)' : ' (sklep ' . ($order['order_number'] ?? '') . ')');
};
$stepBadge = static function (?string $state, string $doneLabel) use ($e): string {
    return match ($state) {
        OrderReturns::STEP_DONE      => '<span class="rt-badge rt-ok">✓ ' . $e($doneLabel) . '</span>',
        OrderReturns::STEP_UNCERTAIN => '<span class="rt-badge rt-warn">⚠ do sprawdzenia</span>',
        OrderReturns::STEP_PENDING   => '<span class="rt-badge rt-warn">w toku</span>',
        default                      => '<span class="rt-badge">do zrobienia</span>',
    };
};

$PAGE_TITLE = 'Zwroty';
$PAGE_KEY   = 'orders';
require __DIR__ . '/header.php';
?>
<style>
.rt-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;background:#eef0f3;color:#445;white-space:nowrap}
.rt-ok{background:#e5f4ea;color:#1c6b36}.rt-warn{background:#fff3dc;color:#8a5a00}.rt-bad{background:#fde8e6;color:#a3341f}
.rt-table{width:100%;border-collapse:collapse}.rt-table th,.rt-table td{padding:8px;border-bottom:1px solid #eee;text-align:left;vertical-align:top}
.rt-scroll{overflow-x:auto}.rt-muted{color:#777;font-size:13px}.rt-steps{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr))}
.rt-num{width:70px}.rt-confirm{background:#fff8ec;border:1px solid #f0d9a8;border-radius:8px;padding:12px;margin:12px 0}
.rt-head{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center}
</style>
<?php

// ============================================================
//  Nowy zwrot do zamówienia
// ============================================================
if ($newFor > 0):
    $order = $orders->find($newFor);
    if ($order === null): ?>
        <div class="card"><p>Nie znaleziono zamówienia.</p><a class="btn secondary" href="returns.php">Wróć</a></div>
    <?php else:
        $lines = OrderReturns::orderLines($order); ?>
        <div class="card">
            <strong>Nowy zwrot — zamówienie <?= $e($orderLabel($order)) ?>, <?= $e($order['customer_name'] ?? '') ?></strong>
            <p class="rt-muted">Wpisz, ile sztuk klient odesłał. Zapisanie zwrotu niczego nie wysyła: stan, korektę i zwrot pieniędzy robisz w kolejnych krokach.</p>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="woo_order_id" value="<?= (int) $order['woo_order_id'] ?>">
                <div class="rt-scroll"><table class="rt-table">
                    <tr><th>Produkt</th><th>SKU</th><th>W zamówieniu</th><th>Cena</th><th>Zwracane</th></tr>
                    <?php foreach ($lines as $l): ?>
                        <tr>
                            <td><?= $e($l['name']) ?></td><td><?= $e($l['sku'] ?? '—') ?></td><td><?= (int) $l['quantity'] ?></td>
                            <td><?= $e($money($l['price'], (string) ($order['currency'] ?? 'PLN'))) ?></td>
                            <td><input class="rt-num" type="number" min="0" max="<?= (int) $l['quantity'] ?>" name="qty[<?= $e($l['key']) ?>]" value="0"></td>
                        </tr>
                    <?php endforeach; ?>
                </table></div>
                <p><label>Powód <select name="reason">
                    <?php foreach (OrderReturns::REASON_LABELS as $k => $label): ?><option value="<?= $e($k) ?>"<?= $k === 'NO_REASON' ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?>
                </select></label>
                <label style="margin-left:12px">Numer przesyłki zwrotnej <input name="waybill" maxlength="128"></label></p>
                <p><label>Notatka<br><textarea name="note" rows="2" style="width:100%" maxlength="2000"></textarea></label></p>
                <button class="btn" type="submit">Zapisz zwrot</button>
                <a class="btn secondary" href="order_view.php?id=<?= (int) $order['woo_order_id'] ?>">Anuluj</a>
            </form>
        </div>
    <?php endif;

// ============================================================
//  Szczegóły zwrotu
// ============================================================
elseif ($id > 0):
    $ret = $returns->find($id);
    if ($ret === null): ?>
        <div class="card"><p>Nie znaleziono zwrotu.</p><a class="btn secondary" href="returns.php">Wróć</a></div>
    <?php else:
        $order = $ret['woo_order_id'] !== null ? $orders->find((int) $ret['woo_order_id']) : null;
        $isAllegroOrder = $order !== null && (int) $order['woo_order_id'] >= 9000000000;
        $currency = (string) ($ret['items'][0]['currency'] ?? $order['currency'] ?? 'PLN');
        $invoiceDoc = $order !== null ? $returns->invoiceFor((int) $order['woo_order_id']) : null;
        $value = OrderReturns::itemsValue($ret['items']);
        ?>
        <div class="card">
            <div class="rt-head">
                <strong>↩ Zwrot <?= $e($ret['reference_number'] ?: '#' . $ret['id']) ?> — <?= $e(OrderReturns::statusLabel((string) $ret['status'])) ?></strong>
                <span><a class="btn secondary" href="returns.php">Lista zwrotów</a>
                <?php if ($order !== null): ?><a class="btn secondary" href="order_view.php?id=<?= (int) $order['woo_order_id'] ?>">Zamówienie <?= $e($orderLabel($order)) ?></a><?php endif; ?></span>
            </div>
            <p class="rt-muted">
                Źródło: <?= $ret['source'] === 'allegro' ? 'Allegro (zgłoszony przez kupującego)' : 'dodany w CRM' ?>
                · Kupujący: <?= $e($ret['buyer'] ?: '—') ?>
                · Zgłoszono: <?= $e(($ret['remote_created_at'] ?? $ret['created_at']) . ' UTC') ?>
                <?php if ($ret['waybill']): ?> · Przesyłka: <?= $e(trim($ret['carrier'] . ' ' . $ret['waybill'])) ?><?php endif; ?>
            </p>
            <div class="rt-scroll"><table class="rt-table">
                <tr><th>Produkt</th><th>SKU</th><th>Ilość</th><th>Cena</th><th>Powód</th></tr>
                <?php foreach ($ret['items'] as $it): ?>
                    <tr><td><?= $e($it['name']) ?></td><td><?= $e($it['sku'] ?? '—') ?></td><td><?= (int) $it['quantity'] ?></td>
                        <td><?= $e($money($it['price'], $currency)) ?></td>
                        <td><?= $e(OrderReturns::reasonLabel((string) ($it['reason'] ?? ''))) ?><?= !empty($it['comment']) ? '<br><span class="rt-muted">„' . $e($it['comment']) . '”</span>' : '' ?></td></tr>
                <?php endforeach; ?>
                <tr><td colspan="3"><strong>Wartość zwracanych pozycji</strong></td><td colspan="2"><strong><?= $e($money($value, $currency)) ?></strong></td></tr>
            </table></div>
            <?php if (!empty($ret['bank_account'])): $b = $ret['bank_account']; ?>
                <p class="rt-muted">Konto do zwrotu podane przez kupującego: <?= $e(trim(($b['owner'] ?? '') . ' ' . ($b['accountNumber'] ?? $b['iban'] ?? ''))) ?></p>
            <?php endif; ?>
            <?php if ($order === null): ?>
                <div class="rt-confirm">Tego zwrotu nie udało się powiązać z zamówieniem w CRM (zamówienie Allegro <?= $e($ret['order_ref'] ?: '—') ?>).
                    <?php if (canEdit()): ?>
                    <form method="post" style="margin-top:8px">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="link_order"><input type="hidden" name="return_id" value="<?= (int) $ret['id'] ?>">
                        <label>Numer zamówienia w CRM <input name="order_ref" required></label> <button class="btn secondary">Powiąż</button>
                    </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <form method="post" style="margin-top:10px">
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="note"><input type="hidden" name="return_id" value="<?= (int) $ret['id'] ?>">
                <label>Notatka<br><textarea name="note" rows="2" style="width:100%" maxlength="2000"<?= canEdit() ? '' : ' readonly' ?>><?= $e($ret['note'] ?? '') ?></textarea></label>
                <?php if (canEdit()): ?><button class="btn secondary" type="submit">Zapisz notatkę</button><?php endif; ?>
            </form>
        </div>

        <?php if ($step === 'correction' && $ret['correction_state'] === null && $invoiceDoc !== null && canEdit()):
            // ---------- Potwierdzenie korekty ----------
            $invError = '';
            $inv = null;
            try {
                $r = $wfirmaClientFor($invoiceDoc)->getInvoice((string) $invoiceDoc['remote_id']);
                $inv = $r['ok'] ? $r['invoice'] : null;
                $invError = $r['ok'] ? '' : $r['message'];
            } catch (Throwable $ex) {
                $invError = $ex->getMessage();
            }
            ?>
            <div class="card">
                <strong>Faktura korygująca w wFirma</strong>
                <?php if ($inv === null): ?>
                    <p class="rt-bad rt-badge">Nie udało się odczytać faktury z wFirma: <?= $e($invError) ?></p>
                    <p><a class="btn secondary" href="returns.php?id=<?= (int) $ret['id'] ?>">Wróć</a></p>
                <?php else:
                    $suggest = OrderReturns::suggestCorrection($inv['lines'], $ret['items']); ?>
                    <p class="rt-muted">Korekta do faktury <?= $e($inv['number'] ?: 'ID ' . $inv['id']) ?>. Ilości zwracane podpowiedziano po nazwach produktów — sprawdź je.
                        Pozycja, której nie zmieniasz, zostaje na korekcie bez zmian.</p>
                    <form method="post" id="rt-corr">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="correction"><input type="hidden" name="return_id" value="<?= (int) $ret['id'] ?>">
                        <div class="rt-scroll"><table class="rt-table">
                            <tr><th>Pozycja faktury</th><th>Ilość na fakturze</th><th>Cena brutto</th><th>Zwracana ilość</th><th>Po korekcie</th></tr>
                            <?php foreach ($inv['lines'] as $i => $l): ?>
                                <tr data-price="<?= $e($l['price']) ?>" data-count="<?= $e($l['count']) ?>">
                                    <td><?= $e($l['name']) ?></td><td><?= $e($qtyFmt($l['count'])) ?></td><td><?= $e($money($l['price'], $inv['currency'] ?: 'PLN')) ?></td>
                                    <td><input class="rt-num" type="number" step="any" min="0" max="<?= $e($l['count']) ?>" name="ret[<?= $i ?>]" value="<?= $e($qtyFmt((float) $suggest[$i])) ?>"></td>
                                    <td class="rt-after"></td>
                                </tr>
                            <?php endforeach; ?>
                        </table></div>
                        <p><strong>Kwota korekty: <span id="rt-corr-sum"></span></strong></p>
                        <p><label>Przyczyna korekty <input name="reason" value="Zwrot towaru" maxlength="200" style="width:60%"></label></p>
                        <div class="rt-confirm"><label><input type="checkbox" name="confirm" value="1" required>
                            Potwierdzam wystawienie faktury korygującej w wFirma. Korekty nie da się cofnąć z CRM — w razie błędu trzeba ją usunąć lub skorygować w wFirma.</label></div>
                        <button class="btn" type="submit">Wystaw korektę w wFirma</button>
                        <a class="btn secondary" href="returns.php?id=<?= (int) $ret['id'] ?>">Anuluj</a>
                    </form>
                    <script>
                    (function () {
                        const f = document.getElementById('rt-corr');
                        const fmt = v => v.toLocaleString('pl-PL', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                        function calc() {
                            let sum = 0;
                            f.querySelectorAll('tr[data-price]').forEach(tr => {
                                const ret = parseFloat(tr.querySelector('input').value.replace(',', '.')) || 0;
                                const cnt = parseFloat(tr.dataset.count);
                                sum += ret * parseFloat(tr.dataset.price);
                                tr.querySelector('.rt-after').textContent = String(Math.max(0, +(cnt - ret).toFixed(4))).replace('.', ',');
                            });
                            document.getElementById('rt-corr-sum').textContent = '−' + fmt(sum);
                        }
                        f.addEventListener('input', calc); calc();
                    })();
                    </script>
                <?php endif; ?>
            </div>

        <?php elseif ($step === 'refund' && $ret['refund_state'] === null && canEdit()):
            // ---------- Potwierdzenie zwrotu pieniędzy ----------
            $payload = $order !== null ? (json_decode((string) ($order['payload'] ?? ''), true) ?: []) : [];
            $deliveryCost = $isAllegroOrder ? (float) ($payload['delivery']['cost']['amount'] ?? 0) : 0.0;
            // Zamówienie ze sklepu z opłaconą płatnością PayU w CRM - można zwrócić bezpośrednio przez PayU (Services\PayuPayments).
            $payuPaid = null;
            if ($order !== null && !$isAllegroOrder && \Pase\Services\PayuPayouts::available()) {
                \Pase\Services\PayuPayments::migrate($pdo);
                $payuPays = new \Pase\Services\PayuPayments($pdo, new \Pase\Services\PayuPayouts($pdo));
                foreach ($payuPays->payments((int) $order['woo_order_id']) as $pp) {
                    if ($pp['status'] === \Pase\Services\PayuPayments::PAID) { $payuPaid = $pp + ['refundable' => $payuPays->refundable($pp)]; }
                }
            }
            $method = (string) ($_GET['method'] ?? ($order === null ? 'manual' : ($isAllegroOrder ? 'allegro' : ($payuPaid !== null ? 'payu' : 'woo'))));
            ?>
            <div class="card">
                <strong>Zwrot pieniędzy</strong>
                <p class="rt-muted">
                    <?php if ($payuPaid !== null): ?><a href="?id=<?= (int) $ret['id'] ?>&step=refund&method=payu"<?= $method === 'payu' ? ' style="font-weight:700"' : '' ?>>Przez PayU</a> · <?php endif; ?>
                    <a href="?id=<?= (int) $ret['id'] ?>&step=refund&method=<?= $isAllegroOrder ? 'allegro' : 'woo' ?>"<?= in_array($method, ['allegro', 'woo'], true) ? ' style="font-weight:700"' : '' ?>><?= $isAllegroOrder ? 'Przez Allegro' : 'W sklepie WooCommerce' ?></a>
                    · <a href="?id=<?= (int) $ret['id'] ?>&step=refund&method=manual"<?= $method === 'manual' ? ' style="font-weight:700"' : '' ?>>Zwrot zrobiony poza CRM (np. przelew)</a>
                </p>
                <form method="post" id="rt-ref">
                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="refund"><input type="hidden" name="return_id" value="<?= (int) $ret['id'] ?>">
                    <input type="hidden" name="method" value="<?= $e($method) ?>">
                    <?php if ($method === 'manual'): ?>
                        <p class="rt-muted">CRM tylko zapisze, że pieniądze zwrócono. Nic nie zostanie wysłane do Allegro ani do sklepu.</p>
                        <p><label>Zwrócona kwota <input name="amount" inputmode="decimal" value="<?= $e(number_format($value, 2, ',', '')) ?>" required></label></p>
                        <p><label>Opis (np. data przelewu) <input name="comment" maxlength="200" style="width:60%"></label></p>
                        <div class="rt-confirm"><label><input type="checkbox" name="confirm" value="1" required> Potwierdzam, że pieniądze zostały zwrócone klientowi.</label></div>
                        <button class="btn" type="submit">Odnotuj zwrot pieniędzy</button>
                    <?php else: ?>
                        <div class="rt-scroll"><table class="rt-table">
                            <tr><th>Produkt</th><th>Zwracane</th><th>Cena</th><th>Zwrot pieniędzy za</th></tr>
                            <?php foreach ($ret['items'] as $i => $it): ?>
                                <tr data-price="<?= $e($it['price']) ?>"><td><?= $e($it['name']) ?></td><td><?= (int) $it['quantity'] ?></td><td><?= $e($money($it['price'], $currency)) ?></td>
                                    <td><input class="rt-num" type="number" min="0" max="<?= (int) $it['quantity'] ?>" name="qty[<?= $i ?>]" value="<?= (int) $it['quantity'] ?>"> szt.</td></tr>
                            <?php endforeach; ?>
                        </table></div>
                        <?php if ($isAllegroOrder): ?>
                            <p><label>Zwrot kosztu dostawy <input class="rt-num" name="delivery" inputmode="decimal" value="0" data-extra></label>
                                <span class="rt-muted">koszt dostawy w zamówieniu: <?= $e($money($deliveryCost, $currency)) ?></span></p>
                            <p><label>Komentarz dla kupującego <input name="comment" maxlength="100" style="width:60%"></label></p>
                            <p class="rt-muted">Allegro zwróci pieniądze kupującemu tą samą metodą płatności. Wymaga, by aplikacja Allegro miała uprawnienie do zwrotów płatności (allegro:api:payments:write).</p>
                        <?php elseif ($method === 'payu' && $payuPaid !== null): ?>
                            <p><label>Dodatkowa kwota (np. koszt wysyłki) <input class="rt-num" name="delivery" inputmode="decimal" value="0" data-extra></label></p>
                            <p><label>Tytuł zwrotu <input name="comment" value="Zwrot towaru" maxlength="100" style="width:60%"></label></p>
                            <p class="rt-muted">PayU zwróci pieniądze kupującemu na kartę / konto, z którego zapłacił (płatność <?= $e($payuPaid['payu_order_id']) ?>, <?= $e($payuPaid['pay_method'] ?? '') ?>).
                                Można zwrócić jeszcze <?= $e(\Pase\Services\PayuPayouts::money((int) $payuPaid['refundable'], (string) $payuPaid['currency'])) ?>. Sklep WooCommerce nie jest zmieniany.</p>
                        <?php else: ?>
                            <p><label>Dodatkowa kwota (np. koszt wysyłki) <input class="rt-num" name="delivery" inputmode="decimal" value="0" data-extra></label></p>
                            <p><label>Powód <input name="comment" value="Zwrot towaru" maxlength="200" style="width:60%"></label></p>
                            <p><label><input type="checkbox" name="via_gateway" value="1"> Zwróć pieniądze przez bramkę płatności sklepu (jeśli ją obsługuje)</label><br>
                                <span class="rt-muted">Bez zaznaczenia sklep tylko zapisze zwrot przy zamówieniu, a pieniądze przelewasz sam.</span></p>
                        <?php endif; ?>
                        <p><strong>Kwota zwrotu: <span id="rt-ref-sum"></span></strong></p>
                        <div class="rt-confirm"><label><input type="checkbox" name="confirm" value="1" required>
                            Potwierdzam zwrot pieniędzy klientowi. Tej operacji nie da się cofnąć.</label></div>
                        <button class="btn" type="submit">Zwróć pieniądze</button>
                        <script>
                        (function () {
                            const f = document.getElementById('rt-ref');
                            function calc() {
                                let sum = 0;
                                f.querySelectorAll('tr[data-price]').forEach(tr => { sum += (parseFloat(tr.querySelector('input').value) || 0) * parseFloat(tr.dataset.price); });
                                const x = f.querySelector('[data-extra]');
                                sum += x ? (parseFloat(x.value.replace(',', '.')) || 0) : 0;
                                document.getElementById('rt-ref-sum').textContent = sum.toLocaleString('pl-PL', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            }
                            f.addEventListener('input', calc); calc();
                        })();
                        </script>
                    <?php endif; ?>
                    <a class="btn secondary" href="returns.php?id=<?= (int) $ret['id'] ?>">Anuluj</a>
                </form>
            </div>

        <?php else:
            // ---------- Trzy kroki ----------
            ?>
            <div class="rt-steps">
                <div class="card">
                    <div class="rt-head"><strong>1. Przyjęcie na stan</strong><?= $stepBadge($ret['restocked_at'] !== null ? 'done' : null, 'przyjęto') ?></div>
                    <?php if ($ret['restocked_at'] !== null): ?>
                        <p class="rt-muted">Przyjęto <?= $e($ret['restocked_at']) ?> UTC (<?= $e($ret['restocked_by'] ?? '') ?>).</p>
                    <?php elseif (canEdit()): ?>
                        <p class="rt-muted">Dolicza sztuki do stanu w CRM i wysyła nowy stan do sklepu. Uszkodzonych sztuk nie przyjmuj — zmniejsz ilość.</p>
                        <form method="post" onsubmit="return confirm('Doliczyć te sztuki do stanu magazynu?')">
                            <input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="restock"><input type="hidden" name="return_id" value="<?= (int) $ret['id'] ?>">
                            <?php foreach ($ret['items'] as $i => $it): ?>
                                <p style="margin:4px 0"><input class="rt-num" type="number" min="0" max="<?= (int) $it['quantity'] ?>" name="qty[<?= $i ?>]" value="<?= (int) $it['quantity'] ?>"> × <?= $e($it['name']) ?><?= empty($it['sku']) ? ' <span class="rt-muted">(brak SKU)</span>' : '' ?></p>
                            <?php endforeach; ?>
                            <button class="btn" type="submit">Przyjmij na stan</button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="rt-head"><strong>2. Korekta faktury (wFirma)</strong><?= $stepBadge($ret['correction_state'], 'wystawiona') ?></div>
                    <?php if ($ret['correction_state'] === 'done'): ?>
                        <p class="rt-muted"><?= $e($ret['correction_message'] ?? '') ?> <?= $e(($ret['correction_at'] ?? '') . ' UTC') ?></p>
                        <a class="btn secondary" target="_blank" rel="noopener noreferrer" href="https://wfirma.pl/invoices/view/<?= $e(rawurlencode((string) $ret['correction_remote_id'])) ?>">Otwórz korektę w wFirma ↗</a>
                    <?php elseif ($ret['correction_state'] !== null): ?>
                        <p>Nie wiadomo, czy wFirma wystawiła korektę: <?= $e($ret['correction_message'] ?? '') ?></p>
                        <p class="rt-muted">Sprawdź w wFirma listę faktur korygujących. Ponowne wystawienie jest zablokowane, żeby nie powstał duplikat.</p>
                    <?php elseif ($order === null): ?>
                        <p class="rt-muted">Najpierw powiąż zwrot z zamówieniem.</p>
                    <?php elseif ($invoiceDoc === null): ?>
                        <p class="rt-muted">Do zamówienia nie wystawiono w CRM faktury wFirma. Paragon albo fakturę wystawioną poza CRM skoryguj bezpośrednio w wFirma.</p>
                    <?php elseif (canEdit()): ?>
                        <p class="rt-muted">Najpierw zobaczysz pozycje faktury i kwotę korekty. Nic nie zostanie wysłane bez potwierdzenia.</p>
                        <a class="btn" href="returns.php?id=<?= (int) $ret['id'] ?>&step=correction">Przygotuj korektę…</a>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="rt-head"><strong>3. Zwrot pieniędzy</strong><?= $stepBadge($ret['refund_state'], 'zwrócono') ?></div>
                    <?php if ($ret['refund_state'] === 'done'): ?>
                        <p class="rt-muted"><?= $ret['refund_amount'] !== null ? $e($money($ret['refund_amount'], $currency)) . ' · ' : '' ?><?= $e($ret['refund_message'] ?? '') ?> <?= $e(($ret['refund_at'] ?? '') . ' UTC') ?></p>
                    <?php elseif ($ret['refund_state'] !== null): ?>
                        <p>Nie wiadomo, czy zwrot pieniędzy się wykonał: <?= $e($ret['refund_message'] ?? '') ?></p>
                        <p class="rt-muted">Sprawdź w <?= $isAllegroOrder ? 'Allegro (Sprzedaż → Zwroty wpłat)' : 'sklepie i na koncie' ?>. Ponowienie jest zablokowane, żeby nie zwrócić pieniędzy dwa razy.</p>
                    <?php elseif (canEdit()): ?>
                        <?php if (!empty($ret['refund_message'])): ?><p class="rt-badge rt-bad"><?= $e($ret['refund_message']) ?></p><?php endif; ?>
                        <p class="rt-muted">Najpierw zobaczysz kwotę. Nic nie zostanie wysłane bez potwierdzenia.</p>
                        <a class="btn" href="returns.php?id=<?= (int) $ret['id'] ?>&step=refund">Przygotuj zwrot pieniędzy…</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (isAdmin()): foreach (['correction' => ['Korekta', 'ID korekty w wFirma'], 'refund' => ['Zwrot pieniędzy', 'ID zwrotu (Allegro / sklep), jeśli jest']] as $s => [$label, $idLabel]):
                if ($ret[$s . '_state'] !== 'uncertain') { continue; } ?>
                <div class="card">
                    <strong><?= $e($label) ?> — rozstrzygnij po sprawdzeniu</strong>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="return_id" value="<?= (int) $ret['id'] ?>"><input type="hidden" name="step" value="<?= $s ?>">
                        <p><label><?= $e($idLabel) ?> <input name="remote_id"></label></p>
                        <p><label><input type="checkbox" name="verified" value="1" required> Sprawdziłem wynik na właściwym koncie. Odblokowanie po błędnym potwierdzeniu braku może spowodować duplikat.</label></p>
                        <button class="btn" name="action" value="confirm_done">Operacja się wykonała — zapisz</button>
                        <button class="btn secondary" name="action" value="confirm_absent">Operacji nie ma — odblokuj</button>
                    </form>
                </div>
            <?php endforeach; endif; ?>
        <?php endif; ?>
    <?php endif;

// ============================================================
//  Lista
// ============================================================
else:
    $filter = in_array($_GET['f'] ?? '', ['open', 'done'], true) ? (string) $_GET['f'] : '';
    $q = trim((string) ($_GET['q'] ?? ''));
    $rows = $returns->list($filter, $q, 200);
    $lastSync = (int) ($settings->get(OrderReturns::SYNC_AT_KEY, '0') ?? '0');
    ?>
    <div class="card">
        <div class="rt-head">
            <div><strong>↩ Zwroty</strong>
                <p class="rt-muted">Zwroty z Allegro pobierają się same co <?= OrderReturns::SYNC_EVERY_MIN ?> min<?= $lastSync ? ' (ostatnio ' . $e(date('Y-m-d H:i', $lastSync)) . ')' : '' ?>.
                    Zwrot do zamówienia ze sklepu dodasz na stronie zamówienia przyciskiem „Zgłoś zwrot”.</p></div>
            <?php if (canEdit()): ?>
                <form method="post"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="sync">
                    <button class="btn secondary" type="submit">Pobierz zwroty z Allegro teraz</button></form>
            <?php endif; ?>
        </div>
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0">
            <select name="f">
                <option value="">Wszystkie</option>
                <option value="open"<?= $filter === 'open' ? ' selected' : '' ?>>Do zrobienia</option>
                <option value="done"<?= $filter === 'done' ? ' selected' : '' ?>>Zakończone w CRM</option>
            </select>
            <input name="q" value="<?= $e($q) ?>" placeholder="Kupujący, numer, produkt, przesyłka">
            <button class="btn secondary" type="submit">Filtruj</button>
        </form>
        <?php if ($rows === []): ?>
            <p class="rt-muted">Brak zwrotów.</p>
        <?php else: ?>
            <div class="rt-scroll"><table class="rt-table">
                <tr><th>Zgłoszono</th><th>Zwrot</th><th>Kupujący</th><th>Produkty</th><th>Wartość</th><th>Status</th><th>Stan</th><th>Korekta</th><th>Pieniądze</th></tr>
                <?php foreach ($rows as $r):
                    $cur = (string) ($r['items'][0]['currency'] ?? 'PLN'); ?>
                    <tr>
                        <td><?= $e(substr((string) ($r['remote_created_at'] ?? $r['created_at']), 0, 16)) ?></td>
                        <td><a href="returns.php?id=<?= (int) $r['id'] ?>"><?= $e($r['reference_number'] ?: '#' . $r['id']) ?></a><br><span class="rt-muted"><?= $r['source'] === 'allegro' ? 'Allegro' : 'CRM' ?></span></td>
                        <td><?= $e($r['buyer'] ?: '—') ?></td>
                        <td><?= $e(implode(', ', array_map(static fn($it) => $it['quantity'] . '× ' . $it['name'], $r['items']))) ?></td>
                        <td><?= $e($money(OrderReturns::itemsValue($r['items']), $cur)) ?></td>
                        <td><?= $e(OrderReturns::statusLabel((string) $r['status'])) ?><?= $r['woo_order_id'] === null ? '<br><span class="rt-badge rt-warn">bez zamówienia</span>' : '' ?></td>
                        <td><?= $stepBadge($r['restocked_at'] !== null ? 'done' : null, 'tak') ?></td>
                        <td><?= $stepBadge($r['correction_state'], 'tak') ?></td>
                        <td><?= $stepBadge($r['refund_state'], 'tak') ?></td>
                    </tr>
                <?php endforeach; ?>
            </table></div>
        <?php endif; ?>
    </div>
<?php endif;

require __DIR__ . '/footer.php';
