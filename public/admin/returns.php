<?php
declare(strict_types=1);

/**
 * Zwroty i korekty — zwroty zgłoszone na Allegro (pobierane automatycznie) i zwroty dodane ręcznie.
 *
 * Widoki:
 *   returns.php[?f=<filtr>&q=<szukaj>]  lista zwrotów z panelem statusów (jak w BaseLinkerze)
 *   returns.php?find=<numer>         „Stwórz zwrot”: szukanie zamówienia po numerze, numerze przesyłki lub kliencie
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
                flash('Dodano zwrot. Oznacz pozycje (Przyjęte / Uszkodzone / Brak), przywróć je na stan, a potem wystaw korektę i zwróć pieniądze.');
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
            case 'line_status':
                $returns->setLineStatus($rid, (int) ($_POST['line'] ?? -1), (string) ($_POST['line_status'] ?? ''));
                flash('Zapisano stan pozycji. Stan magazynu zmienisz przyciskiem „Przywróć na stan”.');
                break;
            case 'handling':
                $hs = (string) ($_POST['handling_status'] ?? '');
                $returns->setHandling($rid, $hs === '' ? null : $hs);
                flash('Zmieniono status zwrotu na „' . OrderReturns::handlingLabel($hs === '' ? null : $hs) . '”.');
                break;
            case 'restock':
                $ret = $returns->find($rid);
                $qty = $ret !== null ? OrderReturns::acceptedToRestock($ret['items']) : [];
                if ($qty === []) {
                    throw new RuntimeException('Oznacz pozycje jako „Przyjęte”, żeby przywrócić je na stan.');
                }
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
.rt-layout{display:grid;grid-template-columns:240px minmax(0,1fr);gap:16px;align-items:start}
@media (max-width:900px){.rt-layout{grid-template-columns:1fr}}
.rt-side{position:sticky;top:12px}.rt-side .btn{display:block;text-align:center;margin-bottom:12px}
.rt-side h4{margin:14px 0 6px;font-size:13px;color:#555}.rt-side a.rt-f{display:flex;gap:8px;align-items:center;padding:4px 6px;border-radius:6px;color:inherit;text-decoration:none;font-size:14px}
.rt-side a.rt-f:hover{background:#f1f3f5}.rt-side a.rt-f.on{background:#e7eefb;font-weight:700}
.rt-cnt{min-width:24px;padding:0 5px;border-radius:4px;color:#fff;font-size:12px;text-align:center;line-height:18px}
.rt-status{display:inline-block;padding:2px 8px;border-radius:4px;color:#fff;font-size:12px;white-space:nowrap}
.rt-two{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px}
.rt-dl{display:grid;grid-template-columns:170px 1fr;gap:6px 12px;margin:0}.rt-dl dt{color:#666}.rt-dl dd{margin:0;overflow-wrap:anywhere}
.rt-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;padding-top:12px;border-top:1px solid #eee}
.rt-line select{padding:4px 6px;border-radius:6px;border:1px solid #ccd}
.rt-sum{display:inline-block;padding:3px 10px;border-radius:6px;background:#9aa3ad;color:#fff;font-weight:700}.rt-sum.full{background:#2e8b3e}
.rt-icons span{margin-right:4px;opacity:.35}.rt-icons span.on{opacity:1}
dialog.rt-dialog{border:0;border-radius:12px;padding:22px;max-width:640px;width:calc(100% - 32px)}
dialog.rt-dialog::backdrop{background:rgba(0,0,0,.35)}
</style>
<?php
$filterGroups = OrderReturns::filters();
$curFilter = (string) ($_GET['f'] ?? '');
?>
<div class="rt-layout">
<aside class="card rt-side">
    <?php if (canEdit()): ?><button class="btn" type="button" onclick="document.getElementById('rt-new').showModal()">＋ Stwórz zwrot</button><?php endif; ?>
    <a class="rt-f<?= $curFilter === '' && $id === 0 && $newFor === 0 && !isset($_GET['find']) ? ' on' : '' ?>" href="returns.php">📥 Wszystkie <span class="rt-muted">(<?= $returns->count() ?>)</span></a>
    <a class="rt-f<?= $curFilter === 'open' ? ' on' : '' ?>" href="returns.php?f=open">📝 Do zrobienia <span class="rt-muted">(<?= $returns->count('open') ?>)</span></a>
    <?php foreach ($filterGroups as $group => $fs): ?>
        <h4><?= $e($group) ?></h4>
        <?php foreach ($fs as $fk => [$flabel, $fcolor]):
            $fc = $fk === 'unclaimed' ? count($returns->unclaimedParcels()) : $returns->count($fk); ?>
            <a class="rt-f<?= $curFilter === $fk ? ' on' : '' ?>" href="returns.php?f=<?= $e($fk) ?>"><span class="rt-cnt" style="background:<?= $e($fcolor) ?>"><?= $fc ?></span><?= $e($flabel) ?></a>
        <?php endforeach; ?>
    <?php endforeach; ?>
</aside>
<main>
<?php if (canEdit()): ?>
<dialog class="rt-dialog" id="rt-new">
    <div class="rt-head"><strong style="font-size:18px">Stwórz zwrot</strong><button class="btn secondary" type="button" onclick="this.closest('dialog').close()">✕</button></div>
    <form method="get" action="returns.php" style="margin-top:14px">
        <p><label><strong>Dla istniejącego zamówienia</strong><br>
            <input name="find" required autofocus style="width:100%;padding:9px;margin-top:6px" placeholder="Numer zamówienia, numer przesyłki (także zwrotnej), e-mail lub nazwisko klienta"></label></p>
        <p class="rt-muted">Możesz zeskanować kod z etykiety paczki — CRM znajdzie zamówienie po numerze przesyłki wysłanej do klienta albo przesyłki zwrotnej.</p>
        <button class="btn" type="submit">Szukaj zamówienia</button>
    </form>
</dialog>
<?php endif;

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
                            <td><input class="rt-num" type="number" min="0" max="<?= (int) $l['quantity'] ?>" name="qty[<?= $e($l['key']) ?>]" value="<?= isset($_GET['all']) ? (int) $l['quantity'] : 0 ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                </table></div>
                <p><label>Powód <select name="reason">
                    <?php foreach (OrderReturns::REASON_LABELS as $k => $label): ?><option value="<?= $e($k) ?>"<?= $k === (string) ($_GET['reason'] ?? 'NO_REASON') ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?>
                </select></label>
                <label style="margin-left:12px">Numer przesyłki zwrotnej <input name="waybill" maxlength="128" value="<?= $e($_GET['waybill'] ?? '') ?>"></label></p>
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
        <?php
        $payloadD = $order !== null ? (json_decode((string) ($order['payload'] ?? ''), true) ?: []) : [];
        $buyerD = is_array($payloadD['buyer'] ?? null) ? $payloadD['buyer'] : [];
        $billingD = is_array($payloadD['billing'] ?? null) ? $payloadD['billing'] : [];
        $emailD = (string) ($order['local_billing_email'] ?? '') ?: (string) ($buyerD['email'] ?? $billingD['email'] ?? $order['customer_email'] ?? '');
        $phoneD = (string) ($order['local_billing_phone'] ?? '') ?: (string) ($buyerD['phoneNumber'] ?? $billingD['phone'] ?? '');
        $refunded = $ret['refund_state'] === 'done' ? (float) ($ret['refund_amount'] ?? 0) : 0.0;
        $toRestock = OrderReturns::acceptedToRestock($ret['items']);
        $restockedUnits = OrderReturns::restockedUnits($ret['items']);
        $totalUnits = array_sum(array_map(static fn($it) => (int) $it['quantity'], $ret['items']));
        $closedRet = in_array($ret['handling_status'], ['rejected', 'finished'], true);
        ?>
        <div class="rt-head" style="margin-bottom:12px">
            <h2 style="margin:0">↩ Zwrot <?= $e($ret['reference_number'] ?: '#' . $ret['id']) ?> <span style="font-weight:400;color:#555"><?= $e($order['customer_name'] ?? $ret['buyer'] ?? '') ?></span></h2>
            <a class="btn secondary" href="returns.php">↩ Powrót do listy zwrotów</a>
        </div>
        <div class="card">
            <div class="rt-scroll"><table class="rt-table">
                <tr><th>SKU</th><th>Nazwa produktu</th><th>Stan</th><th>Ilość</th><th>Cena</th><th>Na stanie</th></tr>
                <?php foreach ($ret['items'] as $i => $it):
                    $ls = (string) ($it['line_status'] ?? '');
                    $done = (int) ($it['restocked'] ?? 0); ?>
                    <tr>
                        <td><?= $e($it['sku'] ?? '—') ?></td>
                        <td><?= $e($it['name']) ?><?= $it['offer_id'] ?? null ? ' <span class="rt-muted">(' . $e($it['offer_id']) . ')</span>' : '' ?>
                            <br><span class="rt-muted">Powód: <?= $e(OrderReturns::reasonLabel((string) ($it['reason'] ?? ''))) ?><?= !empty($it['comment']) ? ' — „' . $e($it['comment']) . '”' : '' ?></span></td>
                        <td class="rt-line">
                            <?php if (canEdit()): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="line_status"><input type="hidden" name="return_id" value="<?= (int) $ret['id'] ?>"><input type="hidden" name="line" value="<?= (int) $i ?>">
                                    <select name="line_status" onchange="this.form.submit()" style="border-left:6px solid <?= $e(OrderReturns::LINE_STATUSES[$ls][1] ?? '#ddd') ?>">
                                        <?php if ($ls === ''): ?><option value="" selected disabled>— sprawdź —</option><?php endif; ?>
                                        <?php foreach (OrderReturns::LINE_STATUSES as $lk => [$ll]): ?>
                                            <option value="<?= $e($lk) ?>"<?= $ls === $lk ? ' selected' : '' ?><?= $done > 0 && $lk !== 'accepted' ? ' disabled' : '' ?>><?= $e($ll) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            <?php else: ?>
                                <?= $e(OrderReturns::LINE_STATUSES[$ls][0] ?? '—') ?>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $it['quantity'] ?></td>
                        <td><?= $e($money($it['price'], $currency)) ?></td>
                        <td><?= $done > 0 ? '<span class="rt-badge rt-ok">✓ ' . $done . ' / ' . (int) $it['quantity'] . '</span>' : '<span class="rt-muted">—</span>' ?><?= empty($it['sku']) ? '<br><span class="rt-muted">brak SKU</span>' : '' ?></td>
                    </tr>
                <?php endforeach; ?>
            </table></div>
            <?php if ($toRestock !== [] && canEdit()): ?>
                <form method="post" style="margin-top:10px" onsubmit="return confirm('Doliczyć <?= array_sum($toRestock) ?> szt. oznaczonych jako „Przyjęte” do stanu magazynu?')">
                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="restock"><input type="hidden" name="return_id" value="<?= (int) $ret['id'] ?>">
                    <button class="btn" type="submit">📦 Przywróć na stan (<?= array_sum($toRestock) ?> szt.)</button>
                    <span class="rt-muted">Doliczy sztuki „Przyjęte” do stanu w CRM i wyśle nowy stan do sklepu. „Uszkodzone” i „Brak” nie wracają na stan.</span>
                </form>
            <?php elseif ($restockedUnits === 0 && canEdit()): ?>
                <p class="rt-muted" style="margin-top:10px">Po rozpakowaniu paczki ustaw przy każdej pozycji: Przyjęte, Uszkodzone albo Brak. Potem pojawi się przycisk „Przywróć na stan”.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="rt-head"><strong style="font-size:17px">Informacje o zwrocie</strong></div>
            <div class="rt-two" style="margin-top:12px">
                <div>
                    <p>Zwrócono: <span class="rt-sum<?= $refunded > 0 && $refunded + 0.001 >= $value ? ' full' : '' ?>"><?= $e($money($refunded, $currency)) ?></span> z <strong><?= $e($money($value, $currency)) ?></strong>
                        <?php if ($ret['refund_state'] === 'done'): ?><br><span class="rt-muted"><?= $e($ret['refund_message'] ?? '') ?> <?= $e(($ret['refund_at'] ?? '') . ' UTC') ?></span><?php endif; ?></p>
                    <dl class="rt-dl">
                        <dt>Klient (login)</dt><dd><?= $e($ret['buyer'] ?: ($order['customer_name'] ?? '—')) ?></dd>
                        <dt>E-mail</dt><dd><?= $e($emailD ?: '—') ?></dd>
                        <dt>Telefon</dt><dd><?= $phoneD !== '' ? '<a href="tel:' . $e(preg_replace('/[^0-9+]/', '', $phoneD)) . '">' . $e($phoneD) . '</a>' : '—' ?></dd>
                        <dt>Źródło</dt><dd><?= $ret['source'] === 'allegro' ? 'Allegro (zgłoszony przez kupującego)' : 'dodany w CRM' ?></dd>
                        <?php if (!empty($ret['bank_account'])): $b = $ret['bank_account']; ?>
                            <dt>Konto do zwrotu</dt><dd><?= $e(trim(($b['owner'] ?? '') . ' ' . ($b['accountNumber'] ?? $b['iban'] ?? ''))) ?><?= !empty($b['swift']) ? ' · SWIFT ' . $e($b['swift']) : '' ?></dd>
                        <?php endif; ?>
                        <dt>Pracownik (stan)</dt><dd><?= $e($ret['restocked_by'] ?? '—') ?></dd>
                    </dl>
                    <form method="post" style="margin-top:10px">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="note"><input type="hidden" name="return_id" value="<?= (int) $ret['id'] ?>">
                        <label>Uwagi<br><textarea name="note" rows="2" style="width:100%" maxlength="2000"<?= canEdit() ? '' : ' readonly' ?>><?= $e($ret['note'] ?? '') ?></textarea></label>
                        <?php if (canEdit()): ?><button class="btn secondary" type="submit">Zapisz uwagi</button><?php endif; ?>
                    </form>
                </div>
                <div>
                    <dl class="rt-dl">
                        <dt>Status</dt><dd>
                            <?php if (canEdit()): ?>
                                <form method="post" style="display:flex;gap:6px;flex-wrap:wrap">
                                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="handling"><input type="hidden" name="return_id" value="<?= (int) $ret['id'] ?>">
                                    <select name="handling_status" style="border-left:6px solid <?= $e(OrderReturns::handlingColor($ret['handling_status'])) ?>;padding:4px 6px">
                                        <option value="">Zgłoszony zwrot</option>
                                        <?php foreach (OrderReturns::HANDLING as $hk => [$hl]): ?><option value="<?= $e($hk) ?>"<?= $ret['handling_status'] === $hk ? ' selected' : '' ?>><?= $e($hl) ?></option><?php endforeach; ?>
                                    </select>
                                    <button class="btn secondary" type="submit">Przenieś</button>
                                </form>
                            <?php else: ?>
                                <span class="rt-status" style="background:<?= $e(OrderReturns::handlingColor($ret['handling_status'])) ?>"><?= $e(OrderReturns::handlingLabel($ret['handling_status'])) ?></span>
                            <?php endif; ?></dd>
                        <dt>Zamówienie</dt><dd><?= $order !== null ? '<a href="order_view.php?id=' . (int) $order['woo_order_id'] . '">' . $e($orderLabel($order)) . ' ↗</a>' : '<span class="rt-badge rt-warn">nie powiązano (' . $e($ret['order_ref'] ?: '—') . ')</span>' ?></dd>
                        <dt>Faktura zam.</dt><dd><?= $invoiceDoc !== null ? '<a target="_blank" rel="noopener noreferrer" href="https://wfirma.pl/invoices/view/' . $e(rawurlencode((string) $invoiceDoc['remote_id'])) . '">faktura wFirma ↗</a>' : '<span class="rt-muted">brak faktury wFirma w CRM</span>' ?></dd>
                        <dt>Korekta zam.</dt><dd>
                            <?php if ($ret['correction_state'] === 'done'): ?>
                                <a target="_blank" rel="noopener noreferrer" href="https://wfirma.pl/invoices/view/<?= $e(rawurlencode((string) $ret['correction_remote_id'])) ?>">wystawiona ↗</a>
                            <?php elseif ($ret['correction_state'] !== null): ?>
                                <span class="rt-badge rt-warn">⚠ do sprawdzenia</span> <span class="rt-muted"><?= $e($ret['correction_message'] ?? '') ?></span>
                            <?php else: ?>—<?php endif; ?></dd>
                        <dt>Status na Allegro</dt><dd><?= $ret['source'] === 'allegro' ? $e(OrderReturns::statusLabel((string) $ret['status'])) : '—' ?></dd>
                        <dt>Zewnętrzny numer zwrotu</dt><dd><em><?= $e($ret['remote_id'] ?: '—') ?></em></dd>
                        <dt>Przesyłka zwrotna</dt><dd><?= $e(trim(($ret['carrier'] ?? '') . ' ' . ($ret['waybill'] ?? '')) ?: '—') ?></dd>
                        <dt>Data złożenia</dt><dd><?= $e(substr((string) ($ret['remote_created_at'] ?? $ret['created_at']), 0, 16)) ?> UTC</dd>
                        <dt>Data w statusie</dt><dd><?= $e($ret['handling_at'] ? substr((string) $ret['handling_at'], 0, 16) . ' UTC' : '—') ?></dd>
                        <dt>Realizacja</dt><dd><?= $restockedUnits === 0 ? 'Nie przywrócono na stan' : ($restockedUnits >= $totalUnits ? '🏬 Zrealizowany (przywrócono stany magazynowe)' : '🏬 Częściowo (' . $restockedUnits . ' z ' . $totalUnits . ' szt. na stanie)') ?></dd>
                    </dl>
                    <?php if ($ret['refund_state'] === 'uncertain'): ?>
                        <p class="rt-confirm">Nie wiadomo, czy zwrot pieniędzy się wykonał: <?= $e($ret['refund_message'] ?? '') ?><br>
                            <span class="rt-muted">Sprawdź w <?= $isAllegroOrder ? 'Allegro (Sprzedaż → Zwroty wpłat)' : 'sklepie / PayU i na koncie' ?>. Ponowienie jest zablokowane, żeby nie zwrócić pieniędzy dwa razy.</span></p>
                    <?php elseif ($ret['refund_state'] === null && !empty($ret['refund_message'])): ?>
                        <p class="rt-badge rt-bad"><?= $e($ret['refund_message']) ?></p>
                    <?php endif; ?>
                    <?php if (canEdit()): ?>
                        <div class="rt-actions">
                            <?php if ($ret['correction_state'] === null && $invoiceDoc !== null): ?>
                                <a class="btn secondary" href="returns.php?id=<?= (int) $ret['id'] ?>&step=correction">🧾 Wystaw korektę</a>
                            <?php endif; ?>
                            <?php if ($ret['refund_state'] === null): ?>
                                <a class="btn" href="returns.php?id=<?= (int) $ret['id'] ?>&step=refund">💲 Zwrot środków</a>
                            <?php endif; ?>
                            <?php if (!$closedRet): ?>
                                <form method="post" onsubmit="return confirm('Oznaczyć zwrot jako odrzucony? Nic nie zostanie wysłane do klienta ani na Allegro.')">
                                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="handling"><input type="hidden" name="return_id" value="<?= (int) $ret['id'] ?>"><input type="hidden" name="handling_status" value="rejected">
                                    <button class="btn secondary" type="submit">✕ Zwrot odrzucony</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="handling"><input type="hidden" name="return_id" value="<?= (int) $ret['id'] ?>"><input type="hidden" name="handling_status" value="finished">
                                    <button class="btn secondary" type="submit">✓ Zakończ</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <?php if ($invoiceDoc === null && $order !== null && $ret['correction_state'] === null): ?>
                            <p class="rt-muted">Korekta: do zamówienia nie wystawiono w CRM faktury wFirma. Paragon albo fakturę spoza CRM skoryguj w wFirma.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($order === null && canEdit()): ?>
                <div class="rt-confirm">Tego zwrotu nie udało się powiązać z zamówieniem w CRM (zamówienie <?= $e($ret['order_ref'] ?: '—') ?>).
                    <form method="post" style="margin-top:8px">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="link_order"><input type="hidden" name="return_id" value="<?= (int) $ret['id'] ?>">
                        <label>Numer zamówienia w CRM <input name="order_ref" required></label> <button class="btn secondary">Powiąż</button>
                    </form>
                </div>
            <?php endif; ?>
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
//  Stwórz zwrot: wyniki wyszukiwania zamówienia
// ============================================================
elseif (isset($_GET['find'])):
    $findQ = trim((string) $_GET['find']);
    $found = $returns->findOrders($findQ); ?>
    <div class="card">
        <div class="rt-head"><strong>Stwórz zwrot — szukasz: „<?= $e($findQ) ?>”</strong>
            <?php if (canEdit()): ?><button class="btn secondary" type="button" onclick="document.getElementById('rt-new').showModal()">Szukaj inaczej</button><?php endif; ?></div>
        <?php if ($found === []): ?>
            <p>Nie znaleziono zamówienia. Sprawdź numer zamówienia, numer przesyłki (wysłanej albo zwrotnej), e-mail lub nazwisko klienta.</p>
        <?php else: ?>
            <div class="rt-scroll"><table class="rt-table">
                <tr><th>Zamówienie</th><th>Klient</th><th>Data</th><th>Znaleziono po</th><th>Zwroty</th><th></th></tr>
                <?php foreach ($found as $o):
                    $existing = $returns->forOrder((int) $o['woo_order_id']); ?>
                    <tr>
                        <td><a href="order_view.php?id=<?= (int) $o['woo_order_id'] ?>"><?= $e($orderLabel($o)) ?></a></td>
                        <td><?= $e($o['customer_name'] ?? '') ?><br><span class="rt-muted"><?= $e($o['customer_email'] ?? '') ?></span></td>
                        <td><?= $e(substr((string) ($o['date_created'] ?? ''), 0, 16)) ?></td>
                        <td><?= $e($o['matched_by']) ?></td>
                        <td><?php foreach ($existing as $x): ?><a href="returns.php?id=<?= (int) $x['id'] ?>">↩ <?= $e($x['reference_number'] ?: '#' . $x['id']) ?></a><br><?php endforeach; ?><?= $existing === [] ? '—' : '' ?></td>
                        <td><?php if (canEdit()): ?><a class="btn" href="returns.php?new=<?= (int) $o['woo_order_id'] ?><?= $o['matched_by'] === 'numer przesyłki zwrotnej' ? '&waybill=' . rawurlencode($findQ) : '' ?>">＋ Stwórz zwrot</a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
            </table></div>
        <?php endif; ?>
    </div>
<?php

// ============================================================
//  Nieodebrane przesyłki (paczka wróciła do nadawcy)
// ============================================================
elseif ($curFilter === 'unclaimed'):
    $parcels = $returns->unclaimedParcels(); ?>
    <div class="card">
        <strong>Nieodebrane przesyłki</strong>
        <p class="rt-muted">Zamówienia, których paczka wróciła do nadawcy (wg śledzenia przesyłek), a w CRM nie ma jeszcze do nich zwrotu.</p>
        <?php if ($parcels === []): ?>
            <p class="rt-muted">Brak nieodebranych przesyłek bez zwrotu.</p>
        <?php else: ?>
            <div class="rt-scroll"><table class="rt-table">
                <tr><th>Zamówienie</th><th>Klient</th><th>Przesyłka</th><th>Zwrócona</th><th></th></tr>
                <?php foreach ($parcels as $o): ?>
                    <tr>
                        <td><a href="order_view.php?id=<?= (int) $o['woo_order_id'] ?>"><?= $e($orderLabel($o)) ?></a></td>
                        <td><?= $e($o['customer_name'] ?? '') ?></td>
                        <td><?= $e(trim(($o['courier_code'] ?? '') . ' ' . ($o['waybill_no'] ?? ''))) ?></td>
                        <td><?= $e(substr((string) ($o['tracking_at'] ?? ''), 0, 16)) ?></td>
                        <td><?php if (canEdit()): ?><a class="btn" href="returns.php?new=<?= (int) $o['woo_order_id'] ?>&all=1&reason=NOT_COLLECTED&waybill=<?= $e(rawurlencode((string) ($o['waybill_no'] ?? ''))) ?>">＋ Stwórz zwrot</a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
            </table></div>
        <?php endif; ?>
    </div>
<?php

// ============================================================
//  Lista
// ============================================================
else:
    $allFilters = ['open' => ['Do zrobienia', '#2f5fb3'], 'done' => ['Zakończone w CRM', '#2e8b3e']];
    foreach ($filterGroups as $fs) { $allFilters += $fs; }
    $filter = isset($allFilters[$curFilter]) ? $curFilter : '';
    $q = trim((string) ($_GET['q'] ?? ''));
    $perPage = 50;
    $page = max(1, (int) ($_GET['p'] ?? 1));
    $total = $returns->count($filter, $q);
    $rows = $returns->list($filter, $q, $perPage, ($page - 1) * $perPage);
    $lastSync = (int) ($settings->get(OrderReturns::SYNC_AT_KEY, '0') ?? '0');
    $pageUrl = static fn(int $p): string => 'returns.php?' . http_build_query(array_filter(['f' => $filter, 'q' => $q, 'p' => $p > 1 ? $p : null]));
    ?>
    <div class="card">
        <div class="rt-head">
            <div><strong style="font-size:18px"><?= $filter !== '' ? $e($allFilters[$filter][0]) : 'Wszystkie zwroty' ?></strong>
                <p class="rt-muted">Zwroty z Allegro pobierają się same co <?= OrderReturns::SYNC_EVERY_MIN ?> min<?= $lastSync ? ' (ostatnio ' . $e(date('Y-m-d H:i', $lastSync)) . ')' : '' ?>.</p></div>
            <?php if (canEdit()): ?>
                <form method="post"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="sync">
                    <button class="btn secondary" type="submit">↻ Pobierz zwroty z Allegro</button></form>
            <?php endif; ?>
        </div>
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0">
            <?php if ($filter !== ''): ?><input type="hidden" name="f" value="<?= $e($filter) ?>"><?php endif; ?>
            <input name="q" value="<?= $e($q) ?>" placeholder="Numer przesyłki, kupujący, numer zwrotu, produkt" style="flex:1;min-width:220px">
            <button class="btn secondary" type="submit">Szukaj</button>
            <?php if ($q !== ''): ?><a class="btn secondary" href="returns.php<?= $filter !== '' ? '?f=' . $e($filter) : '' ?>">✕</a><?php endif; ?>
        </form>
        <?php if ($rows === []): ?>
            <p class="rt-muted">Brak zwrotów<?= $q !== '' ? ' dla „' . $e($q) . '”' : '' ?>.
                <?php if ($q !== '' && canEdit()): ?><a href="returns.php?find=<?= $e(rawurlencode($q)) ?>">Szukaj zamówienia „<?= $e($q) ?>”, żeby utworzyć zwrot</a>.<?php endif; ?></p>
        <?php else: ?>
            <div class="rt-scroll"><table class="rt-table">
                <tr><th>Numer</th><th>Klient</th><th>Przedmioty</th><th>Informacje</th><th>Kwota</th><th>Status</th><th></th><th>Data utworzenia<br><span class="rt-muted">(w statusie)</span></th></tr>
                <?php foreach ($rows as $r):
                    $cur = (string) ($r['items'][0]['currency'] ?? 'PLN'); ?>
                    <tr>
                        <td><a href="returns.php?id=<?= (int) $r['id'] ?>"><strong><?= $e($r['reference_number'] ?: '#' . $r['id']) ?></strong></a><br><span class="rt-muted"><?= $r['source'] === 'allegro' ? 'Allegro' : 'CRM' ?></span></td>
                        <td><?= $e($r['buyer'] ?: '—') ?><?= $r['woo_order_id'] === null ? '<br><span class="rt-badge rt-warn">bez zamówienia</span>' : '' ?></td>
                        <td><?= $e(implode(', ', array_map(static fn($it) => $it['quantity'] . '× ' . $it['name'], $r['items']))) ?></td>
                        <td class="rt-muted"><?php if ($r['remote_id']): ?>Numer zwrotu (marketplace):<br><?= $e($r['remote_id']) ?><br><?php endif; ?>
                            <?php if ($r['waybill']): ?>Przesyłka: <?= $e(trim($r['carrier'] . ' ' . $r['waybill'])) ?><br><?php endif; ?>
                            <?= $r['source'] === 'allegro' ? 'Allegro: ' . $e(OrderReturns::statusLabel((string) $r['status'])) : '' ?></td>
                        <td><?= $e($money(OrderReturns::itemsValue($r['items']), $cur)) ?></td>
                        <td><span class="rt-status" style="background:<?= $e(OrderReturns::handlingColor($r['handling_status'])) ?>"><?= $e(OrderReturns::handlingLabel($r['handling_status'])) ?></span></td>
                        <td class="rt-icons" style="white-space:nowrap">
                            <span class="<?= $r['restocked_at'] !== null ? 'on' : '' ?>" title="<?= $r['restocked_at'] !== null ? 'Przywrócono na stan' : 'Nie przywrócono na stan' ?>">🏬</span>
                            <span class="<?= $r['correction_state'] === 'done' ? 'on' : '' ?>" title="<?= $r['correction_state'] === 'done' ? 'Korekta wystawiona' : 'Bez korekty' ?>">🧾</span>
                            <span class="<?= $r['refund_state'] === 'done' ? 'on' : '' ?>" title="<?= $r['refund_state'] === 'done' ? 'Pieniądze zwrócone' : 'Pieniądze niezwrócone' ?>">💲</span>
                            <?= in_array('uncertain', [$r['correction_state'], $r['refund_state']], true) ? '<span class="on" title="Wynik do sprawdzenia">⚠</span>' : '' ?>
                        </td>
                        <td><?= $e(substr((string) ($r['remote_created_at'] ?? $r['created_at']), 0, 16)) ?><br><span class="rt-muted"><?= $e($r['handling_at'] ? substr((string) $r['handling_at'], 0, 16) : '') ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </table></div>
            <p class="rt-muted" style="display:flex;gap:10px;align-items:center">
                <?= ($page - 1) * $perPage + 1 ?>–<?= min($total, $page * $perPage) ?> z <?= $total ?>
                <?php if ($page > 1): ?><a class="btn secondary" href="<?= $e($pageUrl($page - 1)) ?>">‹</a><?php endif; ?>
                <?php if ($page * $perPage < $total): ?><a class="btn secondary" href="<?= $e($pageUrl($page + 1)) ?>">›</a><?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
<?php endif; ?>
</main>
</div>
<?php
require __DIR__ . '/footer.php';
