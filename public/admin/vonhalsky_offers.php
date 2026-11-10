<?php
declare(strict_types=1);

/**
 * Marketplace → InPost vonHalsky → Oferty i stany: oferty sklepu vonHalsky obok produktów magazynu CRM.
 *  - dopasowanie: ręczne połączenie z produktem, a bez niego SKU oferty (albo identyfikator zewnętrzny) = SKU w CRM,
 *  - ręczne łączenie: wpisz SKU albo EAN produktu z magazynu - stan, cena i pozycje zamówień pójdą po tym połączeniu,
 *  - status oferty w InPost (sprawdzana / opublikowana / odrzucona z powodem), zamknięcie i wznowienie oferty,
 *  - filtry: bez produktu w CRM, odrzucone, z błędem stanu, inny stan niż w CRM.
 * Lista ofert odświeża się co Vonhalsky::CATALOG_EVERY_MIN min albo przyciskiem „Pobierz oferty”.
 */

use Pase\Services\Vonhalsky;
use Pase\Services\VonhalskyOffers;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$PAGE_TITLE = 'InPost vonHalsky - oferty i stany';
$PAGE_KEY   = 'vonhalsky';
require __DIR__ . '/_vonhalsky_page.php';
/** @var array $vhAcc @var array $vhAccounts @var Vonhalsky $vh @var VonhalskyOffers $vhOffers */
$accId = (int) $vhAcc['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = (string) ($_POST['action'] ?? '');
    $offerId = trim((string) ($_POST['offer_id'] ?? ''));
    if (!canEdit()) {
        flash('Brak uprawnień do zmian.', 'err');
    } elseif ($action === 'refresh') {
        try {
            flash('Pobrano ' . $vh->refreshCatalog($vhAcc, $vh->client($vhAcc), true) . ' ofert z InPost vonHalsky.');
        } catch (\Throwable $e) {
            flash($e->getMessage(), 'err');
        }
    } elseif ($action === 'link' && $offerId !== '') {
        $r = $vhOffers->linkOffer($accId, $offerId, trim((string) ($_POST['product'] ?? '')) ?: null);
        flash($r['message'], $r['ok'] ? 'ok' : 'err');
    } elseif ($action === 'unlink' && $offerId !== '') {
        $r = $vhOffers->linkOffer($accId, $offerId, null);
        flash($r['message'], $r['ok'] ? 'ok' : 'err');
    } elseif (in_array($action, ['close', 'reopen'], true) && $offerId !== '') {
        $r = $vhOffers->setClosed($vhAcc, $offerId, $action === 'close');
        flash($r['message'], $r['ok'] ? 'ok' : 'err');
    }
    redirectAfterPost();
}

$views = [
    ''         => 'Wszystkie',
    'unlinked' => 'Bez produktu w CRM',
    'rejected' => 'Odrzucone przez InPost',
    'error'    => 'Stan nieprzyjęty',
    'diff'     => 'Inny stan niż w CRM',
];
$view = isset($views[$_GET['view'] ?? '']) ? (string) ($_GET['view'] ?? '') : '';
$q    = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

$where = ['o.integration_id = ?'];
$params = [$accId];
if ($view === 'unlinked') {
    $where[] = 'p.id IS NULL';
} elseif ($view === 'rejected') {
    $where[] = "o.offer_status = 'REJECTED'";
} elseif ($view === 'error') {
    $where[] = 'o.error IS NOT NULL';
} elseif ($view === 'diff') {
    $where[] = 'p.id IS NOT NULL AND p.pase_stock IS NOT NULL AND (o.vh_stock IS NULL OR o.vh_stock <> (CASE WHEN p.pase_stock < 0 THEN 0 ELSE p.pase_stock END))';
}
if ($q !== '') {
    $where[] = '(o.match_sku LIKE ? OR o.offer_id LIKE ? OR o.product_name LIKE ? OR p.sku LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
$from = 'FROM vonhalsky_offers o LEFT JOIN products p ON ' . Vonhalsky::OFFER_PRODUCT_JOIN . ' WHERE ' . implode(' AND ', $where);
$cnt = $pdo->prepare('SELECT COUNT(*) ' . $from);
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$st = $pdo->prepare('SELECT o.*, p.id AS product_id, p.sku AS crm_sku, p.name AS crm_name, p.pase_stock ' . $from
    . ' ORDER BY o.product_name, o.id LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$link = static function (array $over) use ($view, $accId, $q): string {
    $p = array_filter(array_merge(['acc' => $accId, 'view' => $view, 'q' => $q], $over), static fn($v) => $v !== '' && $v !== null && $v !== 0);
    return 'vonhalsky_offers.php' . ($p ? '?' . http_build_query($p) : '');
};
$e = static fn($v): string => htmlspecialchars((string) $v);
$csrf = csrfToken();

require __DIR__ . '/header.php';
?>
<style>
    .vo-tabs { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:12px; }
    .vo-tabs a { padding:6px 12px; border-radius:999px; border:1px solid var(--line); text-decoration:none; color:var(--ink-2); font-size:13px; }
    .vo-tabs a.active { background:var(--accent-soft); border-color:var(--accent); color:var(--ink); font-weight:600; }
    .vo-muted { color:var(--ink-3); font-size:12px; }
    .vo-num { font-family:var(--font-num); font-variant-numeric:tabular-nums; text-align:right; }
    .vo-link { display:flex; gap:6px; align-items:center; margin-top:4px; }
    .vo-link input { width:150px; padding:4px 8px; font-size:13px; }
    .vo-link .btn, .vo-act .btn { padding:3px 10px; font-size:12px; }
    .vo-act { display:flex; gap:6px; justify-content:flex-end; flex-wrap:wrap; }
</style>
<?= vhAccountPicker($vhAccounts, $vhAcc) ?>
<?= vhConnectionNotice($vh, $vhAcc) ?>
<?php if (($vhAcc['config']['push_stock'] ?? 'on') === 'off'): ?>
    <div class="flash" style="margin-bottom:12px">Wysyłanie stanów do vonHalsky jest wyłączone w ustawieniach konta.</div>
<?php endif; ?>

<div class="card">
    <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
        <p style="margin:0;font-size:13px;color:var(--ink-2);max-width:720px">
            Oferta łączy się z produktem po SKU. Gdy oferta nie ma SKU albo ma inne niż w magazynie, wpisz przy niej SKU albo EAN produktu z CRM -
            stan, cena i pozycje nowych zamówień pójdą po tym połączeniu.
        </p>
        <?php if (canEdit()): ?>
        <form method="post" style="margin:0">
            <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="refresh"><input type="hidden" name="acc" value="<?= $accId ?>">
            <button class="btn secondary" type="submit">↻ Pobierz oferty z vonHalsky</button>
        </form>
        <?php endif; ?>
    </div>
    <div class="vo-tabs">
        <?php foreach ($views as $k => $label): ?>
            <a href="<?= $e($link(['view' => $k, 'page' => null])) ?>" class="<?= $k === $view ? 'active' : '' ?>"><?= $e($label) ?></a>
        <?php endforeach; ?>
    </div>
    <form method="get" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:12px">
        <input type="hidden" name="acc" value="<?= $accId ?>">
        <?php if ($view !== ''): ?><input type="hidden" name="view" value="<?= $e($view) ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= $e($q) ?>" placeholder="SKU, nazwa albo ID oferty" style="min-width:220px">
        <button class="btn secondary" type="submit">Szukaj</button>
        <span class="vo-muted"><?= $total ?> ofert · lista odświeża się co <?= Vonhalsky::CATALOG_EVERY_MIN ?> min</span>
    </form>

    <?php if (!$rows): ?>
        <p class="vo-muted" style="margin:0"><?= $view !== '' || $q !== '' ? 'Brak ofert dla tych filtrów.' : 'Jeszcze nie pobrano ofert z vonHalsky. Kliknij „Pobierz oferty z vonHalsky” albo poczekaj na synchronizację.' ?></p>
    <?php else: ?>
        <div style="overflow-x:auto">
        <table>
            <tr><th>Oferta w vonHalsky</th><th>Status</th><th>Produkt w CRM</th><th class="vo-num">Stan vH</th><th class="vo-num">Stan CRM</th><th>Ostatnio wysłany</th><th></th></tr>
            <?php foreach ($rows as $r):
                $crm = $r['pase_stock'] !== null ? max(0, (int) $r['pase_stock']) : null;
                $differs = $r['product_id'] !== null && $crm !== null && ($r['vh_stock'] === null || (int) $r['vh_stock'] !== $crm);
                $ost = (string) ($r['offer_status'] ?? '');
                $manual = $r['linked_product_id'] !== null; ?>
                <tr>
                    <td><?= $e($r['product_name']) ?><div class="vo-muted">SKU <?= $r['match_sku'] !== null ? $e($r['match_sku']) : '—' ?> · <?= $e($r['offer_id']) ?></div></td>
                    <td>
                        <span class="pill <?= $ost === 'PUBLISHED' ? 'ok' : ($ost === 'REJECTED' ? 'bad' : 'muted') ?>"><?= $e(VonhalskyOffers::OFFER_STATUS_LABELS[$ost] ?? ($ost ?: '—')) ?></span>
                        <?php if ($r['problems']): ?><div class="vo-muted" style="color:var(--danger-ink);max-width:280px"><?= $e(mb_substr((string) $r['problems'], 0, 300)) ?></div><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['product_id'] !== null): ?>
                            <a href="product_view.php?id=<?= (int) $r['product_id'] ?>"><?= $e($r['crm_name']) ?></a>
                            <div class="vo-muted">SKU <?= $e($r['crm_sku']) ?> · <?= $manual ? 'połączona ręcznie' : 'po SKU' ?></div>
                            <?php if ($manual && canEdit()): ?>
                                <form method="post" class="vo-link"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="acc" value="<?= $accId ?>">
                                    <input type="hidden" name="action" value="unlink"><input type="hidden" name="offer_id" value="<?= $e($r['offer_id']) ?>">
                                    <button class="btn secondary" type="submit">Rozłącz</button></form>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="pill warn">brak produktu</span>
                        <?php endif; ?>
                        <?php if (canEdit() && ($r['product_id'] === null || $manual)): ?>
                            <form method="post" class="vo-link"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="acc" value="<?= $accId ?>">
                                <input type="hidden" name="action" value="link"><input type="hidden" name="offer_id" value="<?= $e($r['offer_id']) ?>">
                                <input type="text" name="product" placeholder="SKU albo EAN z CRM" aria-label="SKU albo EAN produktu z CRM" required>
                                <button class="btn secondary" type="submit"><?= $manual ? 'Zmień' : 'Połącz' ?></button></form>
                        <?php endif; ?>
                    </td>
                    <td class="vo-num"<?= $differs ? ' style="color:var(--warn-ink);font-weight:600"' : '' ?>><?= $r['vh_stock'] !== null ? (int) $r['vh_stock'] : '—' ?></td>
                    <td class="vo-num"><?= $crm !== null ? $crm : '—' ?></td>
                    <td>
                        <?= $r['pushed_at'] !== null ? $e(substr((string) $r['pushed_at'], 0, 16)) . ' <span class="vo-muted">(' . (int) $r['pushed_qty'] . ' szt.)</span>' : '<span class="vo-muted">jeszcze nie</span>' ?>
                        <?php if ($r['error'] !== null): ?><div class="vo-muted" style="color:var(--danger-ink)"><?= $e($r['error']) ?></div><?php endif; ?>
                    </td>
                    <td>
                        <?php if (canEdit() && in_array($ost, ['PUBLISHED', 'SOLDOUT', 'CLOSED'], true)): ?>
                            <form method="post" class="vo-act" <?= $ost !== 'CLOSED' ? 'onsubmit="return confirm(\'Zamknąć ofertę w vonHalsky? Kupujący przestaną ją widzieć.\')"' : '' ?>>
                                <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="acc" value="<?= $accId ?>">
                                <input type="hidden" name="offer_id" value="<?= $e($r['offer_id']) ?>">
                                <input type="hidden" name="action" value="<?= $ost === 'CLOSED' ? 'reopen' : 'close' ?>">
                                <button class="btn secondary" type="submit"><?= $ost === 'CLOSED' ? 'Wznów' : 'Zamknij' ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php
        $pagerPage = $page; $pagerPages = $pages;
        $pagerLink = static fn(int $p): string => $link(['page' => $p]);
        require __DIR__ . '/_pager.php';
        ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/footer.php'; ?>
