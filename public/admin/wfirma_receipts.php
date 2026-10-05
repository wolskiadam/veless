<?php
declare(strict_types=1);

/**
 * Zamówienia → Synchronizacja z wFirma: paragony i faktury wystawione w wFirma poza CRM (np. przez BaseLinker)
 * dopasowane do zamówień. wFirma jest tylko czytana; powiązanie zapisuje się wyłącznie w CRM
 * (order_documents), więc zamówienie dostaje zieloną ikonę PAR albo FV. Szczegóły: Services\WfirmaReceiptMatcher.
 */

use Pase\Services\WfirmaReceiptMatcher;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
header('Cache-Control: no-store');
/** @var PDO $pdo */

$matcher = new WfirmaReceiptMatcher($pdo);
[$from, $to] = WfirmaReceiptMatcher::range((string) ($_REQUEST['from'] ?? ''), (string) ($_REQUEST['to'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if (!canEdit()) {
        flash('Brak uprawnień do zmian.', 'err');
        redirectAfterPost();
    }
    $choices = [];
    foreach ((array) ($_POST['order'] ?? []) as $remoteId => $orderId) {
        if ((int) $orderId > 0) { $choices[(string) $remoteId] = (int) $orderId; }
    }
    try {
        $r = $matcher->link($from, $to, $choices);
        flash('Powiązano dokumentów: ' . $r['linked'] . '.'
            . ($r['skipped'] ? ' Pominięte (już powiązane albo nie pasują): ' . implode(', ', array_slice($r['skipped'], 0, 10)) . '.' : ''),
            $r['skipped'] ? 'err' : 'ok');
    } catch (\Throwable $e) {
        flash('Powiązanie dokumentów: ' . $e->getMessage(), 'err');
    }
    redirectAfterPost('wfirma_receipts.php?' . http_build_query(['from' => $from, 'to' => $to, 'check' => 1]));
}

$preview = null;
$error = null;
if (($_GET['check'] ?? '') === '1') {
    try {
        $preview = $matcher->preview($from, $to);
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$counts = ['sure' => 0, 'probable' => 0, 'ambiguous' => 0];
foreach ($preview['rows'] ?? [] as $row) { $counts[$row['level']]++; }
$levelLabels = ['sure' => 'Pewne', 'probable' => 'Prawdopodobne', 'ambiguous' => 'Do wyboru'];
$money = static fn(float $v): string => number_format($v, 2, ',', ' ') . ' zł';
$e = static fn(mixed $v): string => htmlspecialchars((string) $v);

$PAGE_TITLE = 'Synchronizacja z wFirma';
$PAGE_KEY   = 'orders';
require __DIR__ . '/header.php';
?>
<style>
    .wr-muted { color:var(--ink-2); font-size:12.5px; }
    .wr-num { font-family:var(--font-num); font-variant-numeric:tabular-nums; text-align:right; white-space:nowrap; }
    .wr-level { display:inline-block; padding:2px 9px; border-radius:999px; font-size:12px; font-weight:600; white-space:nowrap; }
    .wr-level.sure { background:#e6f4ea; color:#137333; }
    .wr-level.probable { background:#fff1d6; color:#975500; }
    .wr-level.ambiguous { background:#eef0f6; color:#4b5563; }
    .wr-summary { display:flex; gap:18px; flex-wrap:wrap; margin:6px 0 14px; }
    .wr-summary b { font-family:var(--font-num); font-size:20px; display:block; }
    .wr-table select { max-width:360px; }
    .wr-kind { font-size:11.5px; color:var(--ink-2); border:1px solid var(--line); border-radius:4px; padding:0 5px; white-space:nowrap; }
    .wr-table a { color:inherit; text-decoration:underline; text-decoration-color:var(--line); text-underline-offset:3px; }
    .wr-table a:hover { color:var(--accent); }
</style>

<div class="card">
    <p style="margin-top:0">Paragony i faktury wystawione w wFirma poza CRM (np. przez BaseLinker) można tu przypisać do zamówień. CRM tylko <b>czyta</b> wFirma — niczego tam nie tworzy ani nie zmienia. Po powiązaniu zamówienie ma zieloną ikonę PAR (paragon) albo FV (faktura).</p>
    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="check" value="1">
        <label class="wr-muted">Dokumenty od<br><input type="date" name="from" value="<?= $e($from) ?>"></label>
        <label class="wr-muted">do<br><input type="date" name="to" value="<?= $e($to) ?>"></label>
        <button class="btn" type="submit">Sprawdź w wFirma</button>
        <span class="wr-muted">Maksymalnie <?= WfirmaReceiptMatcher::MAX_DAYS ?> dni naraz.</span>
    </form>
</div>

<?php if ($error !== null): ?>
    <div class="flash err"><?= $e($error) ?></div>
<?php elseif ($preview !== null): ?>
<div class="card">
    <div class="wr-summary">
        <div><b><?= $counts['sure'] ?></b><span class="wr-muted">pewnych dopasowań</span></div>
        <div><b><?= $counts['probable'] ?></b><span class="wr-muted">prawdopodobnych</span></div>
        <div><b><?= $counts['ambiguous'] ?></b><span class="wr-muted">do wyboru</span></div>
        <div><b><?= count($preview['unmatched']) ?></b><span class="wr-muted">bez pasującego zamówienia</span></div>
        <div><b><?= (int) $preview['already'] ?></b><span class="wr-muted">już powiązanych</span></div>
    </div>
    <?php if ($preview['truncated']): ?>
        <p class="flash err">W tym okresie jest bardzo dużo dokumentów — pokazano tylko część. Wybierz krótszy okres.</p>
    <?php endif; ?>

    <?php if ($preview['rows'] === []): ?>
        <p class="wr-muted">Brak paragonów i faktur do powiązania w tym okresie.</p>
    <?php else: ?>
    <p class="wr-muted">Pewne dopasowania są zaznaczone. Prawdopodobne i te „do wyboru” wybierz sam z listy. Nic się nie zapisze, dopóki nie klikniesz „Powiąż wybrane”.</p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="from" value="<?= $e($from) ?>">
        <input type="hidden" name="to" value="<?= $e($to) ?>">
        <table class="wr-table">
            <tr><th>Dokument w wFirma</th><th>Data</th><th class="wr-num">Kwota</th><th>Kupujący</th><th>Dopasowanie</th><th>Zamówienie</th></tr>
            <?php foreach ($preview['rows'] as $row): $rc = $row['receipt']; ?>
            <tr>
                <td><a href="https://wfirma.pl/invoices/view/<?= $e(rawurlencode($rc['id'])) ?>" target="_blank" rel="noopener noreferrer"><strong><?= $e($rc['number'] ?: $rc['id']) ?></strong> ↗</a> <span class="wr-kind"><?= WfirmaReceiptMatcher::isInvoice($rc['type']) ? 'Faktura' : 'Paragon' ?></span><?php if ($rc['text'] !== ''): ?><div class="wr-muted"><?= $e(mb_strimwidth($rc['text'], 0, 80, '…')) ?></div><?php endif; ?></td>
                <td><?= $e($rc['date']) ?></td>
                <td class="wr-num"><?= $money((float) $rc['total']) ?></td>
                <td><?= $e($rc['buyer'] ?: '—') ?><?php if (($rc['nip'] ?? '') !== ''): ?><div class="wr-muted">NIP <?= $e($rc['nip']) ?></div><?php endif; ?></td>
                <td><span class="wr-level <?= $e($row['level']) ?>"><?= $e($levelLabels[$row['level']]) ?></span><div class="wr-muted"><?= $e($row['reason']) ?></div></td>
                <td>
                    <select name="order[<?= $e($rc['id']) ?>]">
                        <option value="0">— nie wiąż —</option>
                        <?php foreach ($row['candidates'] as $o): ?>
                            <option value="<?= (int) $o['woo_order_id'] ?>" <?= $row['level'] === 'sure' ? 'selected' : '' ?>>
                                <?= $e($o['order_number']) ?><?= !empty($o['merged_numbers']) ? $e(' + ' . implode(', ', $o['merged_numbers']) . ' (scalone)') : '' ?> · <?= $e(substr((string) $o['date_created'], 0, 10)) ?> · <?= $e($o['customer_name'] ?? '') ?> · <?= $money((float) $o['total']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (count($row['candidates']) === 1): ?>
                        <div class="wr-muted"><a href="order_view.php?id=<?= (int) $row['candidates'][0]['woo_order_id'] ?>" target="_blank">Otwórz zamówienie ↗</a></div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php if (canEdit()): ?>
            <p style="margin-bottom:0"><button class="btn" type="submit">Powiąż wybrane</button></p>
        <?php endif; ?>
    </form>
    <?php endif; ?>

    <?php if ($preview['unmatched'] !== []): ?>
    <details style="margin-top:16px">
        <summary style="cursor:pointer">Dokumenty bez pasującego zamówienia (<?= count($preview['unmatched']) ?>)</summary>
        <p class="wr-muted">Nie znaleziono zamówienia z tą kwotą w ciągu 30 dni przed dokumentem ani numeru zamówienia w opisie. Zamówienie mogło już zostać usunięte z CRM albo ma już paragon lub fakturę.</p>
        <table>
            <tr><th>Dokument</th><th>Data</th><th class="wr-num">Kwota</th><th>Kupujący</th></tr>
            <?php foreach (array_slice($preview['unmatched'], 0, 200) as $rc): ?>
                <tr><td><?= $e($rc['number'] ?: $rc['id']) ?> <span class="wr-kind"><?= WfirmaReceiptMatcher::isInvoice($rc['type']) ? 'Faktura' : 'Paragon' ?></span></td><td><?= $e($rc['date']) ?></td><td class="wr-num"><?= $money((float) $rc['total']) ?></td><td><?= $e($rc['buyer'] ?: '—') ?></td></tr>
            <?php endforeach; ?>
        </table>
    </details>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
