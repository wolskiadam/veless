<?php
declare(strict_types=1);

/**
 * Marketplace → InPost vonHalsky → Zwroty i reklamacje.
 *  - Zwroty (/returns): przyjęcie albo odrzucenie zwrotu zgłoszonego przez klienta. Przyjęcie nie oddaje pieniędzy samo -
 *    kwotę zwraca się przyciskiem „Zwróć pieniądze” na stronie zamówienia, gdy towar dojdzie.
 *  - Reklamacje (/claims): uznanie z pełnym zwrotem, częściowy zwrot (procent, o który prosi klient) albo odrzucenie
 *    z uzasadnieniem.
 * Lista odświeża się co VonhalskyReturns::SYNC_EVERY_MIN min (worker) albo przyciskiem „Pobierz teraz”.
 */

use Pase\Services\Vonhalsky;
use Pase\Services\VonhalskyReturns;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$PAGE_TITLE = 'InPost vonHalsky - zwroty i reklamacje';
$PAGE_KEY   = 'vonhalsky';
require __DIR__ . '/_vonhalsky_page.php';
/** @var array $vhAcc @var array $vhAccounts @var Vonhalsky $vh @var VonhalskyReturns $vhReturns */
$accId = (int) $vhAcc['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = (string) ($_POST['action'] ?? '');
    $rowId = (int) ($_POST['row'] ?? 0);
    if (!canEdit()) {
        flash('Brak uprawnień do zmian.', 'err');
    } elseif ($action === 'sync') {
        try {
            $n = $vhReturns->sync($vhAcc, $vh->client($vhAcc), true);
            flash('Pobrano zwroty i reklamacje z InPost vonHalsky' . ($n > 0 ? ' - nowych: ' . $n : '') . '.');
        } catch (\Throwable $e) {
            flash($e->getMessage(), 'err');
        }
    } elseif (in_array($action, ['return_accept', 'return_reject'], true)) {
        $r = $vhReturns->decideReturn($rowId, $action === 'return_accept');
        flash($r['message'], $r['ok'] ? 'ok' : 'err');
    } elseif (in_array($action, ['claim_refund', 'claim_partial', 'claim_reject'], true)) {
        $r = $vhReturns->resolveClaim($rowId, substr($action, 6), (string) ($_POST['comment'] ?? ''));
        flash($r['message'], $r['ok'] ? 'ok' : 'err');
    }
    redirectAfterPost();
}

$tab = ($_GET['tab'] ?? '') === 'claims' ? 'claims' : 'returns';
$open = ($_GET['all'] ?? '') !== '1';
$e = static fn($v): string => htmlspecialchars((string) $v);
$dt = static fn(?string $v): string => $v ? substr($v, 0, 16) : '—';

if ($tab === 'returns') {
    $st = $pdo->prepare('SELECT r.*, w.pase_number, w.customer_name FROM vonhalsky_returns r LEFT JOIN woo_orders w ON w.woo_order_id = r.woo_order_id
        WHERE r.integration_id = ?' . ($open ? " AND r.status = 'NEW'" : '') . ' ORDER BY r.created_at DESC, r.id DESC LIMIT 200');
} else {
    $st = $pdo->prepare('SELECT c.*, w.pase_number, w.customer_name FROM vonhalsky_claims c LEFT JOIN woo_orders w ON w.woo_order_id = c.woo_order_id
        WHERE c.integration_id = ?' . ($open ? " AND c.state = 'RESOLUTION_IN_PROGRESS'" : '') . ' ORDER BY c.created_at DESC, c.id DESC LIMIT 200');
}
$st->execute([$accId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$counts = [
    'returns' => (int) $pdo->query("SELECT COUNT(*) FROM vonhalsky_returns WHERE integration_id = " . $accId . " AND status = 'NEW'")->fetchColumn(),
    'claims'  => (int) $pdo->query("SELECT COUNT(*) FROM vonhalsky_claims WHERE integration_id = " . $accId . " AND state = 'RESOLUTION_IN_PROGRESS'")->fetchColumn(),
];
$csrf = csrfToken();
$self = static fn(array $over = []): string => 'vonhalsky_returns.php?' . http_build_query(array_merge(['acc' => $accId, 'tab' => $tab, 'all' => $open ? null : '1'], $over));

require __DIR__ . '/header.php';
?>
<style>
    .vr-tabs { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:12px; }
    .vr-tabs a { padding:6px 12px; border-radius:999px; border:1px solid var(--line); text-decoration:none; color:var(--ink-2); font-size:13px; }
    .vr-tabs a.active { background:var(--accent-soft); border-color:var(--accent); color:var(--ink); font-weight:600; }
    .vr-muted { color:var(--ink-3); font-size:12px; }
    .vr-act { display:flex; gap:6px; flex-wrap:wrap; justify-content:flex-end; align-items:center; }
    .vr-act .btn { padding:3px 10px; font-size:12px; }
    .vr-act input[type=text] { padding:4px 8px; font-size:13px; width:200px; }
</style>
<?= vhAccountPicker($vhAccounts, $vhAcc, ['tab' => $tab]) ?>
<?= vhConnectionNotice($vh, $vhAcc) ?>

<div class="card">
    <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
        <div class="vr-tabs" style="margin:0">
            <a href="<?= $e($self(['tab' => 'returns'])) ?>" class="<?= $tab === 'returns' ? 'active' : '' ?>">Zwroty<?= $counts['returns'] ? ' (' . $counts['returns'] . ')' : '' ?></a>
            <a href="<?= $e($self(['tab' => 'claims'])) ?>" class="<?= $tab === 'claims' ? 'active' : '' ?>">Reklamacje<?= $counts['claims'] ? ' (' . $counts['claims'] . ')' : '' ?></a>
            <a href="<?= $e($self(['all' => $open ? '1' : null])) ?>"><?= $open ? 'Pokaż też zakończone' : 'Tylko czekające na decyzję' ?></a>
        </div>
        <?php if (canEdit()): ?>
        <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="sync"><input type="hidden" name="acc" value="<?= $accId ?>">
            <button class="btn secondary" type="submit">↻ Pobierz teraz</button></form>
        <?php endif; ?>
    </div>
    <p class="vr-muted" style="margin:0 0 12px">
        <?= $tab === 'returns'
            ? 'Przyjęcie zwrotu nie oddaje pieniędzy samo - gdy towar dojdzie, zwróć kwotę przyciskiem „Zwróć pieniądze” na stronie zamówienia, a towar przyjmij na magazyn jak zwykle.'
            : 'Uznanie reklamacji zleca InPost zwrot pieniędzy klientowi (całość albo procent, o który prosi). Odrzucenie wymaga uzasadnienia, które zobaczy klient.' ?>
        Lista odświeża się co <?= VonhalskyReturns::SYNC_EVERY_MIN ?> min.
    </p>

    <?php if (!$rows): ?>
        <p class="vr-muted" style="margin:0"><?= $open ? 'Nic nie czeka na decyzję.' : 'Brak ' . ($tab === 'returns' ? 'zwrotów' : 'reklamacji') . '.' ?></p>
    <?php elseif ($tab === 'returns'): ?>
        <div style="overflow-x:auto">
        <table>
            <tr><th>Zamówienie</th><th>Produkty i powód</th><th>Status</th><th>Zgłoszony / termin</th><th></th></tr>
            <?php foreach ($rows as $r): $lines = VonhalskyReturns::lineNames($r['data']); ?>
                <tr>
                    <td><?= $r['woo_order_id'] ? '<a href="order_view.php?id=' . (int) $r['woo_order_id'] . '">#' . $e($r['pase_number'] ?? $r['woo_order_id']) . '</a><div class="vr-muted">' . $e($r['customer_name']) . '</div>' : '<span class="vr-muted">' . $e($r['vh_order_id']) . '</span>' ?></td>
                    <td><?= $e(implode(', ', $lines) ?: '—') ?><?php if ($r['reason']): ?><div class="vr-muted">Powód: <?= $e($r['reason']) ?></div><?php endif; ?>
                        <?php if ($r['tracking_number']): ?><div class="vr-muted">Paczka zwrotna: <?= $e($r['tracking_number']) ?><?= $r['delivered_at'] ? ' · doręczona ' . $e($dt($r['delivered_at'])) : '' ?></div><?php endif; ?></td>
                    <td><span class="pill <?= $r['status'] === 'NEW' ? 'warn' : ($r['status'] === 'ACCEPTED' ? 'ok' : 'muted') ?>"><?= $e(VonhalskyReturns::RETURN_LABELS[(string) $r['status']] ?? $r['status']) ?></span>
                        <?php if ($r['action_error']): ?><div class="vr-muted" style="color:var(--danger-ink)"><?= $e($r['action_error']) ?></div><?php endif; ?></td>
                    <td><?= $e($dt($r['created_at'])) ?><?= $r['expires_at'] && $r['status'] === 'NEW' ? '<div class="vr-muted">decyzja do ' . $e($dt($r['expires_at'])) . '</div>' : '' ?></td>
                    <td>
                        <?php if (canEdit() && $r['status'] === 'NEW'): ?>
                            <div class="vr-act">
                                <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="acc" value="<?= $accId ?>"><input type="hidden" name="row" value="<?= (int) $r['id'] ?>">
                                    <input type="hidden" name="action" value="return_accept"><button class="btn" type="submit">Przyjmij</button></form>
                                <form method="post" onsubmit="return confirm('Odrzucić zwrot w InPost vonHalsky?')"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="acc" value="<?= $accId ?>"><input type="hidden" name="row" value="<?= (int) $r['id'] ?>">
                                    <input type="hidden" name="action" value="return_reject"><button class="btn secondary" type="submit">Odrzuć</button></form>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto">
        <table>
            <tr><th>Zamówienie</th><th>Reklamacja</th><th>Status</th><th>Złożona / termin</th><th></th></tr>
            <?php foreach ($rows as $r):
                $d = $r['state'] === 'RESOLUTION_IN_PROGRESS' ? $vhReturns->claimDetails($r) : (json_decode((string) $r['data'], true) ?: []);
                $spec = (array) ($d['specification'] ?? []);
                $pct = isset($spec['partialRefundPercent']) ? (float) $spec['partialRefundPercent'] : null; ?>
                <tr>
                    <td><?= $r['woo_order_id'] ? '<a href="order_view.php?id=' . (int) $r['woo_order_id'] . '">#' . $e($r['pase_number'] ?? $r['woo_order_id']) . '</a><div class="vr-muted">' . $e($r['customer'] ?: $r['customer_name']) . '</div>' : '<span class="vr-muted">' . $e($r['vh_order_id']) . '</span>' ?></td>
                    <td style="max-width:380px">
                        <strong><?= $e($r['claim_type'] ?: 'Reklamacja') ?></strong>
                        <div class="vr-muted">Klient chce: <?= $e(VonhalskyReturns::RESOLUTION_LABELS[(string) $r['requested']] ?? ($r['requested'] ?: '—')) ?><?= $pct !== null && $r['requested'] === 'PARTIAL_REFUND' ? ' (' . $e(rtrim(rtrim(number_format($pct, 2, ',', ''), '0'), ',')) . '%)' : '' ?></div>
                        <?php if (!empty($spec['issueDescription'])): ?><div style="font-size:13px;margin-top:4px;white-space:pre-line"><?= $e(mb_substr((string) $spec['issueDescription'], 0, 800)) ?></div><?php endif; ?>
                        <?php if ($lines = VonhalskyReturns::lineNames($r['data'])): ?><div class="vr-muted">Produkty: <?= $e(implode(', ', $lines)) ?></div><?php endif; ?>
                    </td>
                    <td><span class="pill <?= $r['state'] === 'RESOLUTION_IN_PROGRESS' ? 'warn' : ($r['state'] === 'APPROVED' ? 'ok' : 'muted') ?>"><?= $e(VonhalskyReturns::CLAIM_LABELS[(string) $r['state']] ?? $r['state']) ?></span>
                        <?php if ($r['resolution']): ?><div class="vr-muted"><?= $e(VonhalskyReturns::RESOLUTION_LABELS[(string) $r['resolution']] ?? $r['resolution']) ?></div><?php endif; ?>
                        <?php if ($r['action_error']): ?><div class="vr-muted" style="color:var(--danger-ink)"><?= $e($r['action_error']) ?></div><?php endif; ?></td>
                    <td><?= $e($dt($r['created_at'])) ?><?= $r['expires_at'] && $r['state'] === 'RESOLUTION_IN_PROGRESS' ? '<div class="vr-muted">decyzja do ' . $e($dt($r['expires_at'])) . '</div>' : '' ?></td>
                    <td>
                        <?php if (canEdit() && $r['state'] === 'RESOLUTION_IN_PROGRESS'): ?>
                            <form method="post" class="vr-act">
                                <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="acc" value="<?= $accId ?>"><input type="hidden" name="row" value="<?= (int) $r['id'] ?>">
                                <input type="text" name="comment" placeholder="Komentarz dla klienta" aria-label="Komentarz dla klienta">
                                <button class="btn" type="submit" name="action" value="claim_refund" onclick="return confirm('Uznać reklamację z pełnym zwrotem pieniędzy?')">Uznaj - pełny zwrot</button>
                                <?php if ($r['requested'] === 'PARTIAL_REFUND'): ?>
                                    <button class="btn secondary" type="submit" name="action" value="claim_partial" onclick="return confirm('Uznać reklamację z częściowym zwrotem, o który prosi klient?')">Częściowy zwrot<?= $pct !== null ? ' ' . $e(rtrim(rtrim(number_format($pct, 2, ',', ''), '0'), ',')) . '%' : '' ?></button>
                                <?php endif; ?>
                                <button class="btn secondary" type="submit" name="action" value="claim_reject" onclick="if(!this.form.comment.value.trim()){alert('Wpisz uzasadnienie odrzucenia.');return false;}return confirm('Odrzucić reklamację?')">Odrzuć</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/footer.php'; ?>
