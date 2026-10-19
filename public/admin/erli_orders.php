<?php
declare(strict_types=1);

/**
 * Marketplace → Erli → Zamówienia i przesyłki: zamówienia pobrane z ERLI z ich stanem po obu stronach.
 *  - status w ERLI (płatność i status sprzedawcy), czy CRM zdjął / oddał stan, czy numer przesyłki doszedł do ERLI,
 *  - filtry: do wysłania (opłacone bez numeru w ERLI), błędy numerów przesyłek, anulowane.
 * Strona tylko czyta. Ponowne wysłanie numeru jest na stronie zamówienia (order_view.php, Services\Erli::retryTracking).
 */

use Pase\Services\Erli;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$PAGE_TITLE = 'Erli - zamówienia i przesyłki';
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
    ''          => 'Wszystkie',
    'to_ship'   => 'Do wysłania',
    'tracking_error' => 'Błąd numeru przesyłki',
    'cancelled' => 'Anulowane',
];
$view = isset($views[$_GET['view'] ?? '']) ? (string) ($_GET['view'] ?? '') : '';
$acc  = (int) ($_GET['acc'] ?? 0);
$q    = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

$where = ['1 = 1'];
$params = [];
if ($acc > 0) { $where[] = 'e.integration_id = ?'; $params[] = $acc; }
if ($view === 'to_ship') {
    $where[] = "e.erli_status = 'purchased' AND (e.seller_status IS NULL OR e.seller_status NOT IN ('" . implode("','", [...Erli::SHIPPED_SELLER_STATUSES, 'canceled']) . "'))";
} elseif ($view === 'tracking_error') {
    $where[] = "e.tracking_state = 'error'";
} elseif ($view === 'cancelled') {
    $where[] = "(e.erli_status = 'cancelled' OR e.seller_status = 'canceled')";
}
if ($q !== '') {
    $where[] = '(e.erli_order_id LIKE ? OR w.customer_name LIKE ? OR CAST(w.pase_number AS CHAR) = ? OR e.tracking_number LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, ltrim($q, '#'), $like);
}
$from = 'FROM erli_orders e LEFT JOIN woo_orders w ON w.woo_order_id = e.woo_order_id WHERE ' . implode(' AND ', $where);

$cnt = $pdo->prepare('SELECT COUNT(*) ' . $from);
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);

$st = $pdo->prepare('SELECT e.*, w.pase_number, w.customer_name, w.total, w.currency, w.date_created ' . $from
    . ' ORDER BY e.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$link = static function (array $over) use ($view, $acc, $q): string {
    $p = array_filter(array_merge(['view' => $view, 'acc' => $acc ?: '', 'q' => $q], $over), static fn($v) => $v !== '' && $v !== null && $v !== 0);
    return 'erli_orders.php' . ($p ? '?' . http_build_query($p) : '');
};
$e = static fn($v): string => htmlspecialchars((string) $v);

$stockLabels = ['taken' => ['zdjęty', 'ok'], 'returned' => ['oddany', 'muted']];
$trackingLabels = ['sent' => ['wysłany do ERLI', 'ok'], 'error' => ['błąd', 'bad'], 'skipped' => ['ERLI ma swój numer', 'muted']];

require __DIR__ . '/header.php';
?>
<style>
    .erli-tabs { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:12px; }
    .erli-tabs a { padding:6px 12px; border-radius:999px; border:1px solid var(--line); text-decoration:none; color:var(--ink-2); font-size:13px; }
    .erli-tabs a.active { background:var(--accent-soft); border-color:var(--accent); color:var(--ink); font-weight:600; }
    .erli-filters { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:12px; }
    .erli-muted { color:var(--ink-3); font-size:12px; }
</style>

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
        <input type="search" name="q" value="<?= $e($q) ?>" placeholder="Nr CRM, nr ERLI, klient, nr przesyłki" style="min-width:260px">
        <button class="btn secondary" type="submit">Szukaj</button>
        <span class="erli-muted"><?= $total ?> zamówień</span>
    </form>

    <?php if (!$rows): ?>
        <p class="erli-muted" style="margin:0">Brak zamówień<?= $view !== '' || $q !== '' ? ' dla tych filtrów' : ' z ERLI' ?>.</p>
    <?php else: ?>
        <div style="overflow-x:auto">
        <table>
            <tr><th>Zamówienie</th><th>Klient</th><th>Kwota</th><th>Status w ERLI</th><th>Stan w CRM</th><th>Numer przesyłki</th></tr>
            <?php foreach ($rows as $r):
                $stock = $stockLabels[(string) $r['stock_state']] ?? null;
                $track = $trackingLabels[(string) $r['tracking_state']] ?? null; ?>
                <tr>
                    <td>
                        <a href="order_view.php?id=<?= (int) $r['woo_order_id'] ?>"><strong>#<?= $e($r['pase_number'] ?? $r['woo_order_id']) ?></strong></a>
                        <div class="erli-muted">ERLI <?= $e($r['erli_order_id']) ?><?= count($accounts) > 1 ? ' · ' . $e($accountNames[(int) $r['integration_id']] ?? '') : '' ?></div>
                    </td>
                    <td><?= $e($r['customer_name'] ?? '') ?><div class="erli-muted"><?= $e(substr((string) ($r['date_created'] ?? ''), 0, 16)) ?></div></td>
                    <td style="white-space:nowrap"><?= $r['total'] !== null ? $e(number_format((float) $r['total'], 2, '.', ' ') . ' ' . ($r['currency'] ?: 'PLN')) : '' ?></td>
                    <td>
                        <?= $e(Erli::STATUS_LABELS[(string) $r['erli_status']] ?? (string) $r['erli_status']) ?>
                        <?php if ((string) $r['seller_status'] !== ''): ?><div class="erli-muted"><?= $e(Erli::SELLER_STATUS_LABELS[(string) $r['seller_status']] ?? (string) $r['seller_status']) ?></div><?php endif; ?>
                    </td>
                    <td><?= $stock ? '<span class="pill ' . $stock[1] . '">' . $stock[0] . '</span>' : '<span class="erli-muted">—</span>' ?></td>
                    <td>
                        <?php if ((string) $r['tracking_number'] !== ''): ?><span style="font-family:var(--font-num)"><?= $e($r['tracking_number']) ?></span><br><?php endif; ?>
                        <?= $track ? '<span class="pill ' . $track[1] . '">' . $track[0] . '</span>' : '<span class="erli-muted">jeszcze nie</span>' ?>
                        <?php if ((string) $r['tracking_error'] !== '' && $r['tracking_state'] === 'error'): ?><div class="erli-muted" style="color:var(--danger-ink)"><?= $e($r['tracking_error']) ?></div><?php endif; ?>
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
