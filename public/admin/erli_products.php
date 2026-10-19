<?php
declare(strict_types=1);

/**
 * Marketplace → Erli → Produkty i stany: produkty sklepu ERLI obok produktów magazynu CRM.
 *  - dopasowanie po SKU (SKU w ERLI, a bez niego identyfikator zewnętrzny = SKU w CRM),
 *  - stan w ERLI, stan w CRM, kiedy CRM ostatnio wysłał stan i błąd, jeśli ERLI go nie przyjęło,
 *  - filtry: niepołączone z magazynem, z błędem, różny stan.
 * Strona tylko czyta. Lista produktów odświeża się co Erli::CATALOG_EVERY_MIN min (albo „Pobierz produkty z ERLI” w ustawieniach konta).
 */

use Pase\Services\Erli;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$PAGE_TITLE = 'Erli - produkty i stany';
$PAGE_KEY   = 'erli';

if (!Erli::available()) {
    require __DIR__ . '/header.php';
    echo '<div class="card"><p>Wtyczka ERLI jest wyłączona albo usunięta. Włącz ją w Konfiguracja → Wtyczki (administrator).</p></div>';
    require __DIR__ . '/footer.php';
    exit;
}
Erli::migrate($pdo);

$accounts = array_values(array_filter(
    (new \Pase\Repository\IntegrationAccountRepository($pdo))->all(),
    static fn(array $a): bool => ($a['type'] ?? '') === Erli::TYPE
));
$accountNames = array_column($accounts, 'name', 'id');

$views = [
    ''         => 'Wszystkie',
    'unlinked' => 'Bez produktu w CRM',
    'error'    => 'Stan nieprzyjęty',
    'diff'     => 'Inny stan niż w CRM',
];
$view = isset($views[$_GET['view'] ?? '']) ? (string) ($_GET['view'] ?? '') : '';
$acc  = (int) ($_GET['acc'] ?? 0);
$q    = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

$where = ['1 = 1'];
$params = [];
if ($acc > 0) { $where[] = 'e.integration_id = ?'; $params[] = $acc; }
if ($view === 'unlinked') {
    $where[] = 'p.id IS NULL';
} elseif ($view === 'error') {
    $where[] = 'e.error IS NOT NULL';
} elseif ($view === 'diff') {
    $where[] = 'p.id IS NOT NULL AND p.pase_stock IS NOT NULL AND (e.erli_stock IS NULL OR e.erli_stock <> (CASE WHEN p.pase_stock < 0 THEN 0 ELSE p.pase_stock END))';
}
if ($q !== '') {
    $where[] = '(e.match_sku LIKE ? OR e.external_id LIKE ? OR e.product_name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
$from = 'FROM erli_products e LEFT JOIN products p ON p.sku = e.match_sku WHERE ' . implode(' AND ', $where);

$cnt = $pdo->prepare('SELECT COUNT(*) ' . $from);
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);

$st = $pdo->prepare('SELECT e.*, p.id AS product_id, p.name AS crm_name, p.pase_stock ' . $from
    . ' ORDER BY e.product_name, e.id LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$pushOff = [];
foreach ($accounts as $a) {
    if (($a['config']['push_stock'] ?? 'on') === 'off') { $pushOff[] = (string) $a['name']; }
}

$link = static function (array $over) use ($view, $acc, $q): string {
    $p = array_filter(array_merge(['view' => $view, 'acc' => $acc ?: '', 'q' => $q], $over), static fn($v) => $v !== '' && $v !== null && $v !== 0);
    return 'erli_products.php' . ($p ? '?' . http_build_query($p) : '');
};
$e = static fn($v): string => htmlspecialchars((string) $v);

require __DIR__ . '/header.php';
?>
<style>
    .erli-tabs { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:12px; }
    .erli-tabs a { padding:6px 12px; border-radius:999px; border:1px solid var(--line); text-decoration:none; color:var(--ink-2); font-size:13px; }
    .erli-tabs a.active { background:var(--accent-soft); border-color:var(--accent); color:var(--ink); font-weight:600; }
    .erli-filters { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:12px; }
    .erli-muted { color:var(--ink-3); font-size:12px; }
    .erli-num { font-family:var(--font-num); font-variant-numeric:tabular-nums; text-align:right; }
</style>

<?php if ($pushOff): ?>
    <div class="flash" style="margin-bottom:12px">Wysyłanie stanów do ERLI jest wyłączone w ustawieniach: <?= $e(implode(', ', $pushOff)) ?>.</div>
<?php endif; ?>

<div class="card">
    <div class="erli-tabs">
        <?php foreach ($views as $k => $label): ?>
            <a href="<?= $e($link(['view' => $k, 'page' => null])) ?>" class="<?= $k === $view ? 'active' : '' ?>"><?= $e($label) ?></a>
        <?php endforeach; ?>
    </div>
    <form method="get" class="erli-filters">
        <?php if ($view !== ''): ?><input type="hidden" name="view" value="<?= $e($view) ?>"><?php endif; ?>
        <?php if (count($accounts) > 1): ?>
            <select name="acc" onchange="this.form.submit()">
                <option value="">Wszystkie sklepy</option>
                <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= $acc === (int) $a['id'] ? 'selected' : '' ?>><?= $e($a['name']) ?></option><?php endforeach; ?>
            </select>
        <?php endif; ?>
        <input type="search" name="q" value="<?= $e($q) ?>" placeholder="SKU albo nazwa" style="min-width:220px">
        <button class="btn secondary" type="submit">Szukaj</button>
        <span class="erli-muted"><?= $total ?> produktów · lista z ERLI odświeża się co <?= Erli::CATALOG_EVERY_MIN ?> min</span>
    </form>

    <?php if (!$rows): ?>
        <p class="erli-muted" style="margin:0"><?= $view !== '' || $q !== '' ? 'Brak produktów dla tych filtrów.' : 'Jeszcze nie pobrano produktów z ERLI. W ustawieniach konta kliknij „Pobierz produkty z ERLI” albo poczekaj na synchronizację.' ?></p>
    <?php else: ?>
        <div style="overflow-x:auto">
        <table>
            <tr><th>Produkt w ERLI</th><th>SKU</th><th>Produkt w CRM</th><th class="erli-num">Stan ERLI</th><th class="erli-num">Stan CRM</th><th>Ostatnio wysłany</th></tr>
            <?php foreach ($rows as $r):
                $crm = $r['pase_stock'] !== null ? max(0, (int) $r['pase_stock']) : null;
                $differs = $r['product_id'] !== null && $crm !== null && ($r['erli_stock'] === null || (int) $r['erli_stock'] !== $crm); ?>
                <tr>
                    <td><?= $e($r['product_name']) ?><div class="erli-muted">ID <?= $e($r['external_id']) ?><?= count($accounts) > 1 ? ' · ' . $e($accountNames[(int) $r['integration_id']] ?? '') : '' ?></div></td>
                    <td style="font-family:var(--font-num)"><?= $e($r['match_sku']) ?></td>
                    <td><?= $r['product_id'] !== null
                        ? '<a href="product_view.php?id=' . (int) $r['product_id'] . '">' . $e($r['crm_name']) . '</a>'
                        : '<span class="pill warn">brak SKU w magazynie</span>' ?></td>
                    <td class="erli-num"<?= $differs ? ' style="color:var(--warn-ink);font-weight:600"' : '' ?>><?= $r['erli_stock'] !== null ? (int) $r['erli_stock'] : '—' ?></td>
                    <td class="erli-num"><?= $crm !== null ? $crm : '—' ?></td>
                    <td>
                        <?= $r['pushed_at'] !== null ? $e(substr((string) $r['pushed_at'], 0, 16)) . ' <span class="erli-muted">(' . (int) $r['pushed_qty'] . ' szt.)</span>' : '<span class="erli-muted">jeszcze nie</span>' ?>
                        <?php if ($r['error'] !== null): ?><div class="erli-muted" style="color:var(--danger-ink)"><?= $e($r['error']) ?></div><?php endif; ?>
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
