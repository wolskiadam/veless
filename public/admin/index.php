<?php
declare(strict_types=1);

/**
 * STRONA GŁÓWNA panelu = lista zamówień z WooCommerce (woo_orders).
 * Filtry (status Woo, status wewnętrzny, zakres dat, kwota od-do) + paginacja.
 * Import jest w osobnej zakładce (import_woo.php).
 */

use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\OrderStatusRepository;
use Pase\Repository\WooOrderRepository;
use Pase\Services\OrderStatusService;

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

$statusRepo = new OrderStatusRepository($pdo);
$statusList = $statusRepo->all();   // do dropdownów i mapy kolorów
$statusMap  = [];
foreach ($statusList as $s) {
    $statusMap[$s['status_key']] = $s;
}

// --- Akcje POST (zmiana statusu / cykl życia: kosz, przywróć, archiwizuj, usuń) ---
$bulkFlash = null;
$orderRepo = new WooOrderRepository($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEdit()) {
    csrfCheck();
    $act = $_POST['action'] ?? '';
    $ids = array_map('intval', $_POST['ids'] ?? []);

    if ($act === 'bulk_status') {
        $newStatus = $_POST['bulk_pase_status'] ?? '';
        if ($ids && $statusRepo->keyExists($newStatus)) {
            $svc = new OrderStatusService($pdo, new IntegrationAccountRepository($pdo), $statusRepo, $config['allegro'] ?? []);
            [$ok, $syncErr] = $svc->changeBulk($ids, $newStatus);
            $bulkFlash = t('orders.flash.status', ['ok' => $ok]) . ($syncErr ? t('orders.flash.syncerr', ['n' => $syncErr]) : '');
        } else {
            $bulkFlash = t('orders.flash.pickone');
        }
    } elseif ($act === 'trash') {
        $bulkFlash = t('orders.flash.trashed', ['n' => $orderRepo->setLifecycleMany($ids, 'trashed')]);
    } elseif ($act === 'restore') {
        $bulkFlash = t('orders.flash.restored', ['n' => $orderRepo->setLifecycleMany($ids, 'active')]);
    } elseif ($act === 'archive') {
        $bulkFlash = t('orders.flash.archived', ['n' => $orderRepo->setLifecycleMany($ids, 'archived')]);
    } elseif ($act === 'purge') {
        $bulkFlash = t('orders.flash.purged', ['n' => $orderRepo->purgeMany($ids)]);
    } elseif ($act === 'empty_trash') {
        $bulkFlash = t('orders.flash.emptied', ['n' => $orderRepo->emptyTrash()]);
    } elseif ($act === 'auto_archive') {
        // Ręczne wywołanie tego samego mechanizmu, co godzinna auto-archiwizacja
        // w cli/worker.php - przydatne, gdy cron jeszcze nie zdążył przetworzyć zaległości.
        $bulkFlash = t('orders.flash.autoarchived', ['n' => $orderRepo->autoArchiveOlderThan(90)]);
    }
}

// Widok cyklu życia: active (domyślny) | archived | trashed.
$view = in_array($_GET['view'] ?? '', ['archived', 'trashed'], true) ? $_GET['view'] : 'active';

// Liczniki cyklu życia (Archiwum/Kosz/aktywne) - liczone PO obsłudze POST,
// żeby panel boczny od razu pokazywał aktualny stan (np. licznik kosza po usunięciu).
$lifeCounts = ['active' => 0, 'archived' => 0, 'trashed' => 0];
try {
    $lc = $pdo->query("SELECT lifecycle, COUNT(*) AS n FROM woo_orders GROUP BY lifecycle")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($lc as $k => $n) {
        $lifeCounts[(string) $k] = (int) $n;
    }
} catch (\PDOException $e) {
    // kolumna lifecycle jeszcze nie istnieje (przed migracją) - wszystko aktywne
    try {
        $lifeCounts['active'] = (int) $pdo->query('SELECT COUNT(*) FROM woo_orders')->fetchColumn();
    } catch (\PDOException $e2) { /* brak tabeli */ }
}

// Widget statystyk pod panelem bocznym: liczba zamówień aktywnych dziś / w ostatnich
// 30 dniach / w bieżącym miesiącu, plus dane do wykresu dziennego (ostatnie 30 dni).
$statsToday  = 0;
$statsLast30 = 0;
$statsMonth  = 0;
$dailyCounts = [];
try {
    $statsToday = (int) $pdo->query(
        "SELECT COUNT(*) FROM woo_orders WHERE lifecycle = 'active' AND DATE(date_created) = CURDATE()"
    )->fetchColumn();
    $statsLast30 = (int) $pdo->query(
        "SELECT COUNT(*) FROM woo_orders WHERE lifecycle = 'active' AND date_created >= (NOW() - INTERVAL 30 DAY)"
    )->fetchColumn();
    $statsMonth = (int) $pdo->query(
        "SELECT COUNT(*) FROM woo_orders WHERE lifecycle = 'active' AND date_created >= DATE_FORMAT(NOW(), '%Y-%m-01')"
    )->fetchColumn();

    $dayRows = $pdo->query(
        "SELECT DATE(date_created) AS d, COUNT(*) AS n FROM woo_orders
         WHERE lifecycle = 'active' AND date_created >= (NOW() - INTERVAL 29 DAY)
         GROUP BY DATE(date_created)"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    for ($i = 29; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-{$i} days"));
        $dailyCounts[$day] = (int) ($dayRows[$day] ?? 0);
    }
} catch (\PDOException $e) {
    // tabela/kolumny jeszcze nie istnieją (przed migracją) - widget pokaże zera
}

/** Renderuje mini-wykres słupkowy (dzienne liczby zamówień) jako inline SVG. */
function ordersSparkChart(array $dailyCounts, string $ariaLabel): string
{
    $n = count($dailyCounts);
    if ($n === 0) {
        return '';
    }
    $max  = max(1, max($dailyCounts));
    $w    = 198;
    $h    = 60;
    $gap  = 2;
    $barW = ($w - ($n - 1) * $gap) / $n;

    $bars = '';
    $i = 0;
    foreach ($dailyCounts as $day => $count) {
        $barH  = $count > 0 ? max(2, round($count / $max * ($h - 4))) : 1.5;
        $x     = round($i * ($barW + $gap), 2);
        $y     = round($h - $barH, 2);
        $title = htmlspecialchars(date('d.m', strtotime($day)) . ': ' . $count);
        $bars .= '<rect x="' . $x . '" y="' . $y . '" width="' . round($barW, 2) . '" height="' . $barH
            . '" rx="1.5" style="fill:var(--brand);fill-opacity:' . ($count > 0 ? '0.85' : '0.18') . '">'
            . '<title>' . $title . '</title></rect>';
        $i++;
    }
    return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" height="' . $h
        . '" preserveAspectRatio="none" role="img" aria-label="' . htmlspecialchars($ariaLabel) . '">' . $bars . '</svg>';
}

// Filtrowanie/paginacja - wspólne z wyszukiwaniem na żywo (orders_search.php),
// patrz _orders_query.php. Wymaga $pdo, $view (już ustawione wyżej).
require __DIR__ . '/_orders_query.php';

$PAGE_TITLE = $view === 'archived' ? t('orders.title.archived')
    : ($view === 'trashed' ? t('orders.title.trashed') : t('orders.title'));
$PAGE_KEY   = 'orders';
$PAGE_TITLE_ACTIONS = (canEdit() && $view === 'active' ? '<a class="btn" href="order_new.php" style="margin-right:8px">+ Nowe zamówienie</a>' : '')
    . '<button type="button" id="ordersFilterToggle" class="filters-toggle" aria-expanded="'
    . ($hasActiveFilters ? 'true' : 'false') . '" aria-controls="ordersFilterBar">'
    . '<span class="filters-toggle-icon"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16l-6 7v5l-4 2v-7L4 5z"/></svg></span>'
    . '<span class="filters-toggle-label">' . htmlspecialchars(t('orders.filters_toggle')) . '</span>'
    . '<svg class="filters-toggle-chevron" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>'
    . '</button>';
// Style listy w <head> (patrz header.php) - bez tego ikony przez chwilę rysowały się w pełnym rozmiarze.
$PAGE_HEAD = <<<'CSS'
<style>
    .orders-perpage { display:flex; align-items:center; justify-content:center; gap:8px; margin-top:12px; font-size:12px; color:var(--ink-2); }
    .orders-perpage-select { padding:5px 8px; font-size:13px; font-family:var(--font-num); cursor:pointer; }
    .orders-layout { display:grid; grid-template-columns:230px minmax(0,1fr); gap:18px; align-items:start; }
    .orders-main { min-width:0; }
    .orders-table-scroll { width:100%; overflow-x:auto; }
    .orders-table th { white-space:nowrap; }
    .orders-table .sort-link { color:inherit; text-decoration:none; display:inline-flex; align-items:center; gap:4px; }
    .orders-table .sort-link:hover { color:var(--accent); }
    .orders-table .sort-link:focus-visible { outline:2px solid var(--accent); outline-offset:2px; border-radius:2px; }
    .orders-table .sort-arrow { font-size:9px; opacity:.35; }
    .orders-table .sort-link:hover .sort-arrow { opacity:.7; }
    .orders-table .sort-link.active { color:var(--ink); }
    .orders-table .sort-link.active .sort-arrow { opacity:1; color:var(--accent); }
    /* Kanał (logo z wtyczki integracji) i przewoźnik - Services\OrderChannelIcons. */
    .source-badge { display:inline-flex; align-items:center; gap:7px; font-size:13px; font-weight:600; white-space:nowrap; }
    .source-logo { display:inline-flex; width:22px; height:22px; flex:none; border-radius:6px; overflow:hidden; }
    .source-logo svg { width:22px; height:22px; display:block; }
    .source-sub { font-size:11px; color:var(--ink-2); margin-left:29px; }
    .col-delivery { white-space:nowrap; }
    .carrier-logo { display:inline-flex; vertical-align:middle; }
    .carrier-logo svg { width:46px; height:23px; display:block; }
    .carrier-other { display:inline-flex; align-items:center; gap:5px; font-size:12px; color:var(--ink-2); vertical-align:middle; }
    .carrier-other svg { width:18px; height:18px; flex:none; }
    .carrier-other-name { max-width:110px; overflow:hidden; text-overflow:ellipsis; }
    .col-amt { text-align:right; font-family:var(--font-num); font-variant-numeric:tabular-nums; font-weight:600; }
    .order-indicators { display:flex; gap:5px; margin-top:7px; }
    .order-indicator { position:relative; display:inline-flex; align-items:center; justify-content:center; width:25px; height:25px; border-radius:5px; text-decoration:none; }
    .order-indicator svg { width:19px; height:19px; }
    .order-indicator.ok { background:#e6f4ea; color:#137333; }
    .order-indicator.warn { background:#fff1d6; color:#975500; }
    .order-indicator.bad { background:#fce8e6; color:#c5221f; }
    .order-indicator.info { background:var(--accent-soft); color:var(--accent-ink); }
    .order-indicator.muted { background:#eef0f6; color:#6b7280; }
    .order-indicator.note { background:#fdf0b5; color:#7a5a00; box-shadow:inset 0 0 0 1px #e9c94a; }
    /* Opłacone częściowo: pół zielone, pół pomarańczowe. Wysłane / w drodze: niebieskie (zielone dopiero po doręczeniu). */
    .order-indicator.partial { background:linear-gradient(135deg, #cdebd6 0 50%, #ffe2ad 50% 100%); color:#4d5a12; }
    .order-indicator.transit { background:#e3edfb; color:#1d5fb8; }
    .order-indicator:focus-visible { outline:2px solid var(--accent); outline-offset:2px; }
    .order-indicator.has-text { width:auto; padding:0 6px 0 4px; gap:3px; }
    .order-indicator .ind-txt { font-size:11px; font-weight:800; letter-spacing:.02em; line-height:1; }

    .order-number-link { color:inherit; text-decoration:underline; text-decoration-color:#cbd3e1; text-underline-offset:3px; white-space:nowrap; }
    .order-number-link:hover { color:var(--accent); text-decoration-color:currentColor; }
    .order-number-link:focus-visible { outline:2px solid var(--accent); outline-offset:3px; border-radius:2px; }
    /* Nazwa klienta → karta klienta (customers.php?order=...). */
    .customer-link { color:inherit; text-decoration:none; }
    .customer-link:hover { color:var(--accent); text-decoration:underline; text-underline-offset:3px; }
    .customer-link:focus-visible { outline:2px solid var(--accent); outline-offset:3px; border-radius:2px; }
    .order-shop-id { display:block; color:var(--ink-2); font-family:var(--font-num); font-size:11px; }
    .order-source-number { display:inline-block; max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; vertical-align:bottom; }
    @media (max-width:1400px) { .orders-table th,.orders-table td { padding:9px 6px; } }

    .orders-side-col { display:flex; flex-direction:column; gap:14px; position:sticky; top:18px; }
    .orders-side { background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); padding:13px; }
    .orders-stats { padding:14px 16px; }
    .stats-widget-link { display:block; color:inherit; text-decoration:none; margin:-4px -6px 0; padding:4px 6px; border-radius:8px; transition:background .15s ease; }
    .stats-widget-link:hover { background:var(--accent-soft); }
    .stats-widget-link:hover .stats-widget-cta { color:var(--accent-hover); }
    .stats-row { display:flex; align-items:center; justify-content:space-between; padding:5px 0; font-size:13px; }
    .stats-row .stats-label { color:var(--ink-2); }
    .stats-row .stats-value { font-family:var(--font-num); font-variant-numeric:tabular-nums; font-weight:600; font-size:15px; color:var(--ink); }
    .stats-chart { margin-top:10px; padding-top:10px; border-top:1px solid var(--line); }
    .stats-widget-cta { display:flex; align-items:center; gap:5px; margin-top:10px; font-size:12px; font-weight:600; color:var(--accent); }
    .stats-archive-form { margin-top:12px; }
    .stats-archive-btn { width:100%; font-size:12px; padding:7px 10px; }
    .side-search { display:flex; align-items:center; gap:8px; padding:7px 11px; margin-bottom:2px;
        border:1px solid var(--line); border-radius:var(--radius-sm); background:var(--surface-2); }
    .side-search:focus-within { border-color:var(--accent); background:var(--surface); }
    .side-search-submit { display:inline-flex; padding:0; border:0; background:transparent; cursor:pointer; flex-shrink:0; border-radius:4px; }
    .side-search-submit:hover .side-search-icon, .side-search-submit:focus-visible .side-search-icon { color:var(--accent); }
    .side-search-submit:focus-visible { outline:2px solid var(--accent); outline-offset:2px; }
    .side-search-icon { width:15px; height:15px; color:var(--ink-3); flex-shrink:0; }
    .side-search-input { flex:1; min-width:0; border:0; background:transparent; padding:2px 0; font-size:14px; color:var(--ink); -webkit-appearance:none; appearance:none; }
    .side-search-input:focus { outline:none; box-shadow:none; }
    .side-search-input::-webkit-search-cancel-button { display:none; }
    .side-search-clear { display:inline-flex; align-items:center; justify-content:center; flex-shrink:0;
        width:18px; height:18px; padding:0; border:0; border-radius:50%; background:var(--muted-bg); color:var(--ink-2); cursor:pointer; }
    .side-search-clear:hover { background:var(--accent-soft); color:var(--accent-ink); }
    .side-search-clear[hidden] { display:none; }
    .side-item { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:9px 11px;
        border-radius:8px; text-decoration:none; color:var(--ink); font-size:14px; }
    .side-item:hover { background:var(--accent-soft); }
    .side-item.active { background:var(--accent-soft); color:var(--accent-ink); font-weight:600; }
    .side-label { display:flex; align-items:center; gap:8px; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .side-dot { width:10px; height:10px; border-radius:3px; flex-shrink:0; }
    .side-count { font-size:12px; font-weight:700; color:#fff; background:#9aa3af; border-radius:999px; min-width:22px; text-align:center; padding:1px 7px; flex-shrink:0; }
    .side-count:not(.has) { background:transparent; color:#bfc5cf; font-weight:400; }
    .side-item.active .side-count.has { background:var(--accent); }
    .side-icon { width:15px; height:15px; flex-shrink:0; opacity:.8; }
    .side-sep { height:1px; background:var(--line); margin:6px 4px; }
    .side-manage { color:var(--accent); font-size:13px; justify-content:flex-start; }
    @media (max-width:820px) { .orders-layout { grid-template-columns:1fr; } .orders-side-col { position:static; } }
    .actionbar { display:flex; align-items:center; gap:10px; margin-bottom:12px; padding:8px 12px; background:var(--surface-2); border:1px solid var(--line); border-radius:var(--radius-sm); flex-wrap:wrap; }
    .actionbar .btn { padding:7px 12px; font-size:13px; }
    .row-act { padding:6px 9px !important; font-size:13px; margin-left:2px; display:inline-flex; align-items:center; }
    .flag-menu { position:relative; }
    .flag-btn { background:var(--surface); border:1px solid var(--line); border-radius:var(--radius-sm); padding:8px 12px; cursor:pointer; font-size:14px; color:var(--ink); display:flex; align-items:center; gap:6px; }
    .flag-btn:hover { border-color:var(--accent); }
    .flag-dropdown { display:none; position:absolute; top:100%; left:0; margin-top:4px; background:var(--surface); border-radius:var(--radius-sm); box-shadow:0 10px 34px -10px rgba(20,18,12,.28); padding:6px; min-width:220px; z-index:50; }
    .flag-menu.open .flag-dropdown { display:block; }
    .flag-item { display:flex; align-items:center; gap:8px; width:100%; text-align:left; background:none; border:0; padding:9px 12px; border-radius:6px; cursor:pointer; font-size:14px; color:var(--ink); text-decoration:none; }
    .flag-item:hover { background:var(--accent-soft); }
    .dot { width:11px; height:11px; border-radius:50%; flex-shrink:0; }
    .row-unread { background:#fff8e6; }
    .row-unread:hover { background:#fff3d6; }
    .row-unread td:first-child { box-shadow:inset 3px 0 0 #f59e0b; }
    .row-merged td { opacity:.55; }
    .row-merged td:first-child { opacity:1; }
    .merge-pill { display:inline-block; margin-top:3px; padding:1px 8px; border-radius:999px; font-size:11px; font-weight:600; background:var(--surface-2); color:var(--ink-2); border:1px solid var(--line); text-decoration:none; white-space:nowrap; }
    a.merge-pill:hover { color:var(--accent); }
    .merge-pill.main { background:#eef0f6; color:#4b5563; }
    .msg-badge { display:inline-block; background:#f59e0b; color:#fff; font-size:11px; font-weight:700;
        border-radius:999px; padding:1px 7px; margin-left:6px; text-decoration:none; vertical-align:middle; }
    .msg-badge:hover { background:#d97706; }
    .filters-toggle { display:inline-flex; align-items:center; gap:9px; background:var(--surface); border:1px solid var(--line);
        border-radius:999px; padding:6px 16px 6px 6px; cursor:pointer; font-size:14px; font-weight:600;
        color:var(--ink); white-space:nowrap; }
    .filters-toggle:hover { border-color:var(--ink-3); background:var(--surface-2); }
    .filters-toggle-icon { display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px;
        border-radius:50%; border:1px solid var(--line); color:var(--ink-2); flex-shrink:0; }
    .filters-toggle-icon svg { width:13px; height:13px; }
    .filters-toggle-chevron { width:15px; height:15px; color:var(--ink-2); transition:transform .15s ease; flex-shrink:0; }
    .filters-toggle[aria-expanded="true"] .filters-toggle-chevron { transform:rotate(180deg); }
    /* Okienko „Opis ikon zamówienia” (klik w ikonę $ / FV / PAR / ciężarówkę). */
    .order-indicator { cursor:pointer; }
    #orderIconsDialog { width:min(720px, calc(100vw - 32px)); box-sizing:border-box; padding:22px 24px; border:1px solid var(--line); border-radius:16px; background:var(--surface); color:var(--ink); font-family:var(--font-ui); box-shadow:0 20px 60px rgba(0,0,0,.2); }
    #orderIconsDialog::backdrop { background:rgba(30,25,15,.45); }
    .oi-head { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
    .oi-head h2 { margin:0; font-size:20px; }
    .oi-close { margin-left:auto; background:none; border:0; font-size:24px; line-height:1; cursor:pointer; color:var(--ink-2); padding:4px 8px; }
    .oi-row { display:grid; grid-template-columns:56px minmax(0,1fr); align-items:start; gap:8px; padding:6px 0; }
    .oi-row > .order-indicator { justify-self:start; cursor:default; }
    .oi-main { flex:1 1 auto; min-width:0; }
    .oi-label { line-height:25px; }
    .oi-item { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-top:4px; }
    .oi-item .oi-text { flex:1 1 260px; min-width:0; overflow-wrap:anywhere; }
    .oi-item a { color:var(--accent); font-weight:600; }
    .oi-copy { display:flex; gap:6px; flex-wrap:wrap; }
    .oi-copy .btn { padding:4px 10px; font-size:12px; }
    .oi-track { display:flex; align-items:center; gap:10px; margin-top:6px; font-size:13px; }
    .oi-bar { width:160px; height:8px; border-radius:99px; background:#eef0f6; overflow:hidden; flex:0 0 auto; }
    .oi-bar span { display:block; height:100%; border-radius:99px; background:var(--accent); }
    .oi-bar.bad span { background:var(--danger-ink); }
    .oi-bar.ok span { background:#137333; }
    .oi-muted { color:var(--ink-3, #8a8f98); }
    .oi-note { font-size:12px; color:var(--ink-2); margin-top:3px; }
    .oi-foot { display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-top:18px; }
    .oi-all { margin-top:14px; padding-top:12px; border-top:1px solid var(--line); }
    .oi-all h3 { margin:10px 0 4px; font-size:13px; color:var(--ink-2); }
    .oi-all .oi-row { padding:3px 0; }
</style>
CSS;
require __DIR__ . '/header.php';
?>

<?php if ($noTable): ?>
<div class="flash err">
    Tabela <code>woo_orders</code> jeszcze nie istnieje. Powstanie automatycznie przy
    najbliższym połączeniu z bazą (auto-migracja). Odśwież stronę za chwilę.
</div>
<?php elseif (!empty($queryError)): ?>
<div class="flash err">
    Nie udało się pobrać listy zamówień — błąd zapytania do bazy.
    <?php if (isAdmin()): ?>
        <div style="margin-top:6px;font-size:12px;font-family:ui-monospace,monospace;word-break:break-all"><?= htmlspecialchars($queryError) ?></div>
    <?php endif; ?>
    <div style="margin-top:6px;font-size:12px">Spróbuj wyczyścić filtry: <a href="index.php">pokaż wszystkie zamówienia</a>.</div>
</div>
<?php endif; ?>

<?php
// Link statusu panelu bocznego: zachowuje widok i pozostałe filtry, podmienia status.
$qsBase = $_GET;
unset($qsBase['status'], $qsBase['p']);
$statusLink = static function (?string $key) use ($qsBase): string {
    $qs = $qsBase;
    if ($key !== null) { $qs['status'] = $key; } else { unset($qs['status']); }
    return 'index.php' . ($qs ? '?' . http_build_query($qs) : '');
};
// Link widoku (aktywne/archiwum/kosz) - czyści filtr statusu i stronę.
$viewLink = static function (string $v): string {
    return 'index.php' . ($v === 'active' ? '' : ('?view=' . $v));
};
// Ukryte pola dla mini-wyszukiwarki w panelu bocznym - zachowują pozostałe filtry.
$searchHidden = $_GET;
unset($searchHidden['q'], $searchHidden['p']);
?>

<div class="orders-layout">
<div class="orders-side-col">
<!-- ===== Panel boczny ===== -->
<aside class="orders-side">
    <form method="get" class="side-search" role="search">
        <?php foreach ($searchHidden as $k => $v): if (is_array($v)) { continue; } ?>
            <input type="hidden" name="<?= htmlspecialchars((string) $k) ?>" value="<?= htmlspecialchars((string) $v) ?>">
        <?php endforeach; ?>
        <button type="submit" class="side-search-submit" title="<?= htmlspecialchars(t('orders.search')) ?>" aria-label="<?= htmlspecialchars(t('orders.search')) ?>">
            <svg class="side-search-icon" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        </button>
        <input type="search" name="q" id="ordersSearchInput" value="<?= htmlspecialchars($q) ?>" placeholder="<?= htmlspecialchars(t('orders.search')) ?>" class="side-search-input" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other">
        <button type="submit" id="ordersSearchClear" class="side-search-clear" title="Wyczyść wyszukiwanie" aria-label="Wyczyść wyszukiwanie" <?= $q === '' ? 'hidden' : '' ?> onclick="document.getElementById('ordersSearchInput').value='';">
            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
    </form>
    <div class="side-sep"></div>
    <a href="<?= htmlspecialchars($statusLink(null)) ?>" class="side-item <?= ($view === 'active' && $fStatus === '') ? 'active' : '' ?>">
        <span class="side-label">
            <svg class="side-icon" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="3.5" rx="1"/><rect x="3" y="10.25" width="18" height="3.5" rx="1"/><rect x="3" y="15.5" width="18" height="3.5" rx="1"/></svg>
            <?= htmlspecialchars(t('orders.side.all')) ?>
        </span>
        <span class="side-count<?= $view === 'active' ? ' has' : '' ?>"><?= $view === 'active' ? (int) $ordersTotal : (int) $lifeCounts['active'] ?></span>
    </a>
    <div class="side-sep"></div>
    <?php if ($view === 'active'): ?>
        <?php foreach ($statusList as $s): $k = $s['status_key']; $n = $statusCounts[$k] ?? 0; ?>
            <a href="<?= htmlspecialchars($statusLink($k)) ?>" class="side-item <?= $fStatus === $k ? 'active' : '' ?>">
                <span class="side-label">
                    <span class="side-dot" style="background:<?= htmlspecialchars($s['color'] ?? '#888') ?>"></span>
                    <?= htmlspecialchars($s['label']) ?>
                </span>
                <span class="side-count<?= $n > 0 ? ' has' : '' ?>"><?= $n > 0 ? (int) $n : '–' ?></span>
            </a>
        <?php endforeach; ?>
        <a href="statuses.php" class="side-item side-manage">
            <svg class="side-icon" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M12 4.5v2M12 17.5v2M4.5 12h2M17.5 12h2M6.8 6.8l1.4 1.4M15.8 15.8l1.4 1.4M17.2 6.8l-1.4 1.4M8.2 15.8l-1.4 1.4"/></svg>
            <?= htmlspecialchars(t('orders.side.manage')) ?>
        </a>
    <?php else: ?>
        <a href="<?= htmlspecialchars($viewLink('active')) ?>" class="side-item">
            <span class="side-label">
                <svg class="side-icon" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 19l-7-7 7-7"/><path d="M4 12h16"/></svg>
                <?= htmlspecialchars(t('orders.side.back')) ?>
            </span>
        </a>
    <?php endif; ?>
    <div class="side-sep"></div>
    <a href="<?= htmlspecialchars($viewLink('archived')) ?>" class="side-item <?= $view === 'archived' ? 'active' : '' ?>">
        <span class="side-label">
            <svg class="side-icon" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="7" width="18" height="13" rx="1.5"/><path d="M3 7l2-4h14l2 4"/><path d="M9 12h6"/></svg>
            <?= htmlspecialchars(t('orders.side.archive')) ?>
        </span>
        <span class="side-count<?= $lifeCounts['archived'] > 0 ? ' has' : '' ?>"><?= $lifeCounts['archived'] > 0 ? (int) $lifeCounts['archived'] : '–' ?></span>
    </a>
    <a href="<?= htmlspecialchars($viewLink('trashed')) ?>" class="side-item <?= $view === 'trashed' ? 'active' : '' ?>">
        <span class="side-label">
            <svg class="side-icon" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="M6 7l1 13h10l1-13"/><path d="M10 11v6M14 11v6"/></svg>
            <?= htmlspecialchars(t('orders.side.trash')) ?>
        </span>
        <span class="side-count<?= $lifeCounts['trashed'] > 0 ? ' has' : '' ?>"><?= $lifeCounts['trashed'] > 0 ? (int) $lifeCounts['trashed'] : '–' ?></span>
    </a>
</aside>

<!-- ===== Widget statystyk ===== -->
<div class="card orders-stats">
    <a href="statistics.php" class="stats-widget-link" title="<?= htmlspecialchars(t('stats.widget_link')) ?>">
        <div class="stats-row">
            <span class="stats-label"><?= htmlspecialchars(t('orders.stats.today')) ?></span>
            <span class="stats-value"><?= (int) $statsToday ?></span>
        </div>
        <div class="stats-row">
            <span class="stats-label"><?= htmlspecialchars(t('orders.stats.last30')) ?></span>
            <span class="stats-value"><?= (int) $statsLast30 ?></span>
        </div>
        <div class="stats-row">
            <span class="stats-label"><?= htmlspecialchars(t('orders.stats.month')) ?></span>
            <span class="stats-value"><?= (int) $statsMonth ?></span>
        </div>
        <?php $chart = ordersSparkChart($dailyCounts, t('orders.stats.chart')); ?>
        <?php if ($chart !== ''): ?>
            <div class="stats-chart"><?= $chart ?></div>
        <?php endif; ?>
        <div class="stats-widget-cta">
            <?= htmlspecialchars(t('stats.widget_link')) ?>
            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
        </div>
    </a>
    <?php if (canEdit() && $view === 'active'): ?>
        <form method="post" class="stats-archive-form"
              onsubmit="return confirm('<?= htmlspecialchars(t('orders.stats.archive_confirm'), ENT_QUOTES) ?>');">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="auto_archive">
            <button type="submit" class="btn secondary stats-archive-btn"><?= htmlspecialchars(t('orders.stats.archive_now')) ?></button>
        </form>
    <?php endif; ?>
</div>
</div><!-- /orders-side-col -->

<!-- ===== Treść (filtry + lista) ===== -->
<div class="orders-main">

<!-- ===== Filtry ===== -->
<div class="card" id="ordersFilterBar"<?= $hasActiveFilters ? '' : ' hidden' ?>>
    <form method="get">
        <input type="hidden" name="q" id="ordersFilterFormQ" value="<?= htmlspecialchars($q) ?>">
        <?php if ($sortColumn !== null): /* filtrowanie nie gubi wybranego sortowania */ ?>
            <input type="hidden" name="sort" value="<?= htmlspecialchars($sortColumn) ?>">
            <input type="hidden" name="dir" value="<?= htmlspecialchars($sortDir) ?>">
        <?php endif; ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
            <div>
                <label style="font-size:12px;color:var(--ink-2);display:block"><?= htmlspecialchars(t('orders.status')) ?></label>
                <select name="status" style="width:100%">
                    <option value="">— <?= htmlspecialchars(t('common.all')) ?> —</option>
                    <?php foreach ($statusList as $s): ?>
                        <option value="<?= htmlspecialchars($s['status_key']) ?>" <?= $fStatus === $s['status_key'] ? 'selected' : '' ?>><?= htmlspecialchars($s['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <?php
                // Źródło zamówienia: najpierw cała platforma, pod nią konkretne konta.
                // Etykiety typów trzymamy zgodne z plakietką w kolumnie "Źródło"
                // (sourceBadge w _orders_table.php), żeby filtr i lista mówiły tym samym językiem.
                $sourceTypeLabels = ['woocommerce' => 'WooCommerce', 'allegro' => 'Allegro', 'wfirma' => 'wFirma'];
                $accountsByType = [];
                foreach ($sourceAccounts as $account) {
                    $accountsByType[(string) $account['type']][] = $account;
                }
                ?>
                <label style="font-size:12px;color:var(--ink-2);display:block">Źródło</label>
                <select name="source" style="width:100%">
                    <option value="">— <?= htmlspecialchars(t('common.all')) ?> —</option>
                    <?php foreach ($accountsByType as $sourceType => $accounts): ?>
                        <?php $typeLabel = $sourceTypeLabels[$sourceType] ?? ucfirst((string) $sourceType); ?>
                        <optgroup label="<?= htmlspecialchars($typeLabel) ?>">
                            <option value="type:<?= htmlspecialchars((string) $sourceType) ?>" <?= $fSource === 'type:' . $sourceType ? 'selected' : '' ?>>
                                Wszystkie: <?= htmlspecialchars($typeLabel) ?>
                            </option>
                            <?php foreach ($accounts as $account): ?>
                                <option value="acc:<?= (int) $account['id'] ?>" <?= $fSource === 'acc:' . $account['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $account['name']) ?><?= (int) $account['is_active'] === 0 ? ' (nieaktywne)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                    <option value="manual" <?= $fSource === 'manual' ? 'selected' : '' ?>>Dodane ręcznie w CRM</option>
                    <?php if ($hasOrdersWithoutSource): ?>
                        <option value="none" <?= $fSource === 'none' ? 'selected' : '' ?>>— bez przypisanego źródła —</option>
                    <?php endif; ?>
                </select>
            </div>
            <div>
                <label style="font-size:12px;color:var(--ink-2);display:block"><?= htmlspecialchars(t('orders.date_from')) ?></label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($fDateFrom) ?>" style="width:100%">
            </div>
            <div>
                <label style="font-size:12px;color:var(--ink-2);display:block"><?= htmlspecialchars(t('orders.date_to')) ?></label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($fDateTo) ?>" style="width:100%">
            </div>
            <div>
                <label style="font-size:12px;color:var(--ink-2);display:block"><?= htmlspecialchars(t('orders.total_min')) ?></label>
                <input type="number" step="0.01" name="total_min" value="<?= htmlspecialchars($fTotalMin) ?>" style="width:100%">
            </div>
            <div>
                <label style="font-size:12px;color:var(--ink-2);display:block"><?= htmlspecialchars(t('orders.total_max')) ?></label>
                <input type="number" step="0.01" name="total_max" value="<?= htmlspecialchars($fTotalMax) ?>" style="width:100%">
            </div>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:14px">
            <?php foreach ([
                'invoice_issued' => ['Faktura', $fInvoiceIssued, ['' => 'Wszystkie', '1' => 'Wystawiona', '0' => 'Niewystawiona']],
                'receipt_issued' => ['Paragon', $fReceiptIssued, ['' => 'Wszystkie', '1' => 'Wystawiony', '0' => 'Niewystawiony']],
                'unread' => ['Wiadomości', $fUnread ? '1' : '', ['' => 'Wszystkie', '1' => 'Z nieprzeczytanymi']],
                'need_invoice' => ['Żądanie faktury', $fNeedInvoice ? '1' : '', ['' => 'Wszystkie', '1' => 'Wymagana i niewystawiona']],
                'need_shipping' => ['Wysyłka', $fShipping, ['' => 'Wszystkie', '1' => 'Wymaga wysyłki']
                    + array_map(static fn(array $f): string => $f[0], \Pase\Services\OrderIndicators::TRACKING_FILTERS)],
                'unpaid' => ['Płatność', $fUnpaid ? '1' : '', ['' => 'Wszystkie', '1' => 'Nieopłacone / pobranie']],
                'need_receipt' => ['Paragon do wystawienia', $fNeedReceipt ? '1' : '', ['' => 'Wszystkie', '1' => 'Bez faktury i bez paragonu']],
            ] as $filterName => [$filterLabel, $filterValue, $filterOptions]): ?>
                <label style="display:flex;flex-direction:column;gap:6px;font-size:13px;color:var(--ink-2)">
                    <?= htmlspecialchars($filterLabel) ?>
                    <select name="<?= $filterName ?>">
                        <?php foreach ($filterOptions as $value => $label): ?>
                            <option value="<?= htmlspecialchars((string) $value) ?>" <?= (string) $value === $filterValue ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endforeach; ?>
        </div>
        <p style="margin-top:14px">
            <button class="btn" type="submit"><?= htmlspecialchars(t('common.filter')) ?></button>
            <a class="btn secondary" href="index.php"><?= htmlspecialchars(t('common.clear')) ?></a>
            <span id="ordersFoundText" style="color:var(--ink-2);font-size:13px;margin-left:10px"><?= htmlspecialchars(t('common.found', ['n' => $totalRows])) ?></span>
        </p>
    </form>
</div>

<?php if ($bulkFlash): ?><div class="flash ok"><?= htmlspecialchars($bulkFlash) ?></div><?php endif; ?>

<div id="ordersListRegion">
    <?php require __DIR__ . '/_orders_table.php'; ?>
</div>

</div><!-- /orders-main -->
</div><!-- /orders-layout -->

<dialog id="orderIconsDialog" aria-labelledby="orderIconsTitle">
    <div class="oi-head">
        <h2 id="orderIconsTitle">Opis ikon zamówienia</h2>
        <a class="btn" id="orderIconsOpen" href="#">Otwórz kartę zamówienia</a>
        <button type="button" class="oi-close" data-oi-close aria-label="Zamknij">×</button>
    </div>
    <div id="orderIconsBody"></div>
    <div id="orderIconsAll" class="oi-all" hidden></div>
    <div class="oi-foot">
        <button type="button" class="btn secondary" id="orderIconsAllToggle" aria-expanded="false">Pokaż wszystkie występujące ikony</button>
        <button type="button" class="btn secondary" data-oi-close>Zamknij</button>
    </div>
</dialog>
<script>
// Klik w ikonę statusu ($ / FV / PAR / ciężarówka) otwiera okienko z legendą ikon tego zamówienia
// oraz linkami do wFirma i śledzenia przesyłki (dane: order_icons.php). Ctrl/Cmd+klik działa jak zwykły link.
(function () {
    var ICONS = <?= json_encode(\Pase\Services\OrderIconDetails::ICON_PATHS, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
    var ICON_TEXT = <?= json_encode(\Pase\Services\OrderIconDetails::ICON_TEXT, JSON_HEX_TAG) ?>;
    var KIND_TITLES = { payment: 'Płatność', invoice: 'Faktura', receipt: 'Paragon', shipping: 'Wysyłka', note: 'Uwaga klienta' };
    var dialog = document.getElementById('orderIconsDialog');
    var body = document.getElementById('orderIconsBody');
    var allBox = document.getElementById('orderIconsAll');
    var allToggle = document.getElementById('orderIconsAllToggle');
    if (!dialog || !dialog.showModal) { return; }
    var requestNo = 0;

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (text !== undefined && text !== null) { e.textContent = text; }
        return e;
    }
    function icon(kind, state, svg) {
        var i = el('span', 'order-indicator ' + state + (ICON_TEXT[kind] ? ' has-text' : ''));
        i.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICONS[kind] || svg || '') + '</svg>';
        if (ICON_TEXT[kind]) { i.appendChild(el('span', 'ind-txt', ICON_TEXT[kind])); }
        return i;
    }
    function copyBtn(label, value) {
        var b = el('button', 'btn secondary', label);
        b.type = 'button';
        if (!value) { b.disabled = true; b.title = 'Brak danych do skopiowania'; return b; }
        b.addEventListener('click', function () {
            var done = function () { b.textContent = 'Skopiowano ✓'; setTimeout(function () { b.textContent = label; }, 1500); };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(value).then(done, function () { window.prompt('Skopiuj:', value); });
            } else {
                var t = el('textarea'); t.value = value; document.body.appendChild(t); t.select();
                try { document.execCommand('copy'); done(); } catch (e) { window.prompt('Skopiuj:', value); }
                t.remove();
            }
        });
        return b;
    }
    function link(text, href) {
        if (!href) { return el('strong', null, text); }
        var a = el('a', null, text + ' ↗');
        a.href = href; a.target = '_blank'; a.rel = 'noopener noreferrer';
        return a;
    }
    function notes(box, list) {
        (list || []).forEach(function (n) { box.appendChild(el('div', 'oi-note', n)); });
    }
    function fromNote(box, from, what) {
        if (from) { box.appendChild(el('div', 'oi-note', what + ' z zamówienia głównego ' + from.label + ' (scalone).')); }
    }
    function docItem(d) {
        var item = el('div', 'oi-item');
        var text = el('span', 'oi-text');
        text.appendChild(document.createTextNode(d.label + ' '));
        text.appendChild(link(d.display, d.url));
        item.appendChild(text);
        var c = el('span', 'oi-copy');
        c.appendChild(copyBtn('Kopiuj numer', d.number || ''));
        c.appendChild(copyBtn('Kopiuj link', d.url || ''));
        item.appendChild(c);
        var wrap = el('div');
        wrap.appendChild(item);
        notes(wrap, d.missing);
        return wrap;
    }
    function shipItem(s) {
        var wrap = el('div');
        var item = el('div', 'oi-item');
        var text = el('span', 'oi-text');
        text.appendChild(document.createTextNode('Przesyłka: '));
        text.appendChild(s.waybill ? link(s.waybill, s.url) : el('span', 'oi-muted', 'bez numeru'));
        if (s.courier) { text.appendChild(document.createTextNode(' (' + s.courier + ')')); }
        item.appendChild(text);
        var c = el('span', 'oi-copy');
        c.appendChild(copyBtn('Kopiuj numer', s.waybill || ''));
        c.appendChild(copyBtn('Kopiuj link', s.url || ''));
        item.appendChild(c);
        wrap.appendChild(item);
        var track = el('div', 'oi-track');
        var bar = el('span', 'oi-bar ' + s.status_class);
        var fill = el('span'); fill.style.width = Math.max(0, Math.min(100, s.progress)) + '%';
        bar.appendChild(fill);
        track.appendChild(bar);
        var st = el('span', null, s.status + (s.last_event && s.last_event !== s.status ? ' — ' + s.last_event : ''));
        track.appendChild(st);
        if (s.last_at) { track.appendChild(el('span', 'oi-muted', '(' + s.last_at + ')')); }
        wrap.appendChild(track);
        notes(wrap, s.missing);
        return wrap;
    }

    function render(rowIcons, data, error) {
        body.innerHTML = '';
        var docs = data ? data.documents : [];
        var ships = data ? data.shipments : [];
        var used = { invoice: false, receipt: false, shipping: false };
        function extras(kind, main) {
            if (!data) { return; }
            if (kind === 'invoice' || kind === 'receipt') {
                docs.filter(function (d) { return d.kind === kind; }).forEach(function (d) { main.appendChild(docItem(d)); });
                if (docs.some(function (d) { return d.kind === kind; })) { fromNote(main, data.documents_from, 'Dokument'); }
            } else if (kind === 'shipping') {
                ships.forEach(function (s) { main.appendChild(shipItem(s)); });
                if (ships.length) { fromNote(main, data.shipments_from, 'Przesyłka'); }
            }
            used[kind] = true;
        }
        rowIcons.forEach(function (ri) {
            var row = el('div', 'oi-row');
            row.appendChild(icon(ri.kind, ri.state, ri.svg));
            var main = el('div', 'oi-main');
            main.appendChild(el('div', 'oi-label', ri.label));
            extras(ri.kind, main);
            row.appendChild(main);
            body.appendChild(row);
        });
        // Dokumenty / przesyłki, których ikony w wierszu nie ma (np. faktura przy ukrytej ikonie).
        ['invoice', 'receipt', 'shipping'].forEach(function (kind) {
            if (used[kind] || !data) { return; }
            var has = kind === 'shipping' ? ships.length : docs.some(function (d) { return d.kind === kind; });
            if (!has) { return; }
            var row = el('div', 'oi-row');
            row.appendChild(icon(kind, 'muted'));
            var main = el('div', 'oi-main');
            main.appendChild(el('div', 'oi-label', KIND_TITLES[kind]));
            extras(kind, main);
            row.appendChild(main);
            body.appendChild(row);
        });
        // Wysłane / w drodze, a przesyłki w CRM brak (np. nadana poza CRM) - nie ma skąd wziąć numeru.
        if (data && !ships.length && rowIcons.some(function (r) { return r.kind === 'shipping' && r.state !== 'warn'; })) {
            body.appendChild(el('div', 'oi-note', 'Brak przesyłki zapisanej w CRM — nie ma numeru ani linku do śledzenia.'));
        }
        if (error) { body.appendChild(el('div', 'oi-note', error)); }
        if (!data && !error) { body.appendChild(el('div', 'oi-note', 'Wczytywanie szczegółów…')); }

        allBox.innerHTML = '';
        if (data && data.legend) {
            var lastKind = null;
            data.legend.forEach(function (l) {
                if (l.kind !== lastKind) { allBox.appendChild(el('h3', null, KIND_TITLES[l.kind] || l.kind)); lastKind = l.kind; }
                var row = el('div', 'oi-row');
                row.appendChild(icon(l.kind, l.state));
                row.appendChild(el('div', 'oi-label', l.label));
                allBox.appendChild(row);
            });
        }
    }

    function open(container) {
        var id = container.getAttribute('data-order-id');
        var rowIcons = [].map.call(container.querySelectorAll('.order-indicator'), function (a) {
            var state = ['ok', 'partial', 'transit', 'warn', 'bad', 'info', 'note', 'muted'].filter(function (s) { return a.classList.contains(s); })[0] || 'muted';
            // svg: ikona rozszerzenia (spoza ICONS) - przenosimy ją z listy do okienka.
            var svg = a.querySelector('svg');
            return { kind: a.getAttribute('data-kind'), state: state, label: a.getAttribute('data-legend') || a.getAttribute('title') || '', svg: svg ? svg.innerHTML : '' };
        });
        document.getElementById('orderIconsOpen').href = 'order_view.php?id=' + encodeURIComponent(id);
        render(rowIcons, null, null);
        allBox.hidden = true;
        allToggle.setAttribute('aria-expanded', 'false');
        allToggle.textContent = 'Pokaż wszystkie występujące ikony';
        if (!dialog.open) { dialog.showModal(); }
        var my = ++requestNo;
        fetch('order_icons.php?id=' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { if (!r.ok) { throw new Error(); } return r.json(); })
            .then(function (data) { if (my === requestNo) { render(rowIcons, data, null); } })
            .catch(function () { if (my === requestNo) { render(rowIcons, null, 'Nie udało się pobrać linków do dokumentów i przesyłek. Otwórz kartę zamówienia.'); } });
    }

    // Delegowane na document - działa też po podmianie tabeli przez wyszukiwanie na żywo.
    document.addEventListener('click', function (e) {
        var ind = e.target.closest('.order-indicators .order-indicator');
        if (!ind || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) { return; }
        e.preventDefault();
        open(ind.closest('.order-indicators'));
    });
    dialog.addEventListener('click', function (e) {
        if (e.target.closest('[data-oi-close]')) { dialog.close(); return; }
        // Klik w tło (poza ramką okienka) zamyka - klik w margines wewnątrz nie.
        var box = dialog.getBoundingClientRect();
        if (e.target === dialog && (e.clientX < box.left || e.clientX > box.right || e.clientY < box.top || e.clientY > box.bottom)) { dialog.close(); }
    });
    allToggle.addEventListener('click', function () {
        allBox.hidden = !allBox.hidden;
        allToggle.setAttribute('aria-expanded', allBox.hidden ? 'false' : 'true');
        allToggle.textContent = allBox.hidden ? 'Pokaż wszystkie występujące ikony' : 'Ukryj pozostałe ikony';
    });
})();
</script>

<script>
(function () {
    var filterToggle = document.getElementById('ordersFilterToggle');
    var filterBar = document.getElementById('ordersFilterBar');
    if (filterToggle && filterBar) {
        filterToggle.addEventListener('click', function () {
            var show = filterBar.hidden;
            filterBar.hidden = !show;
            filterToggle.setAttribute('aria-expanded', show ? 'true' : 'false');
        });
    }

    function refreshCount() {
        var n = document.querySelectorAll('.rowcheck:checked').length;
        var el = document.getElementById('selCount');
        if (el) el.textContent = n;
    }
    // Delegowane na document - działa też na wiersze podmienione przez wyszukiwanie na żywo.
    document.addEventListener('change', function (e) {
        if (e.target.classList && e.target.classList.contains('rowcheck')) refreshCount();
    });
    // Zamknij dropdown flagi po kliknięciu poza nim.
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.flag-menu')) {
            document.querySelectorAll('.flag-menu.open').forEach(function (m) { m.classList.remove('open'); });
        }
    });

    // Masowe akcje cyklu życia + akcje pojedynczego wiersza - delegowane na document
    // (nie na cache'owany element formularza), żeby działały też po podmianie tabeli
    // przez wyszukiwanie na żywo (nowe przyciski nie miałyby własnych listenerów).
    document.addEventListener('click', function (e) {
        var actSel = e.target.closest('.act-sel');
        if (actSel) {
            var form1 = actSel.closest('form');
            if (document.querySelectorAll('.rowcheck:checked').length === 0) {
                e.preventDefault();
                alert('Zaznacz przynajmniej jedno zamówienie.');
                return;
            }
            if (actSel.dataset.confirm && !confirm(actSel.dataset.confirm)) { e.preventDefault(); return; }
            form1.elements['action'].value = actSel.dataset.action;
            return;
        }
        var rowAct = e.target.closest('.row-act');
        if (rowAct) {
            var form2 = rowAct.closest('form');
            if (rowAct.dataset.confirm && !confirm(rowAct.dataset.confirm)) { e.preventDefault(); return; }
            document.querySelectorAll('.rowcheck:checked').forEach(function (c) { c.checked = false; });
            var hid = document.createElement('input');
            hid.type = 'hidden'; hid.name = 'ids[]'; hid.value = rowAct.dataset.id;
            form2.appendChild(hid);
            form2.elements['action'].value = rowAct.dataset.action;
        }
    });

    // ===== Wyszukiwanie na żywo (AJAX) =====
    var searchInput = document.getElementById('ordersSearchInput');
    var searchClear = document.getElementById('ordersSearchClear');
    var listRegion  = document.getElementById('ordersListRegion');
    var foundText   = document.getElementById('ordersFoundText');
    var filterFormQ = document.getElementById('ordersFilterFormQ');
    var debounceTimer = null;
    var latestRequestId = 0;

    function runLiveSearch(qValue) {
        if (!listRegion) { return; }
        var requestId = ++latestRequestId;
        var params = new URLSearchParams(window.location.search);
        params.set('q', qValue);
        params.delete('p'); // nowe wyszukiwanie zawsze zaczyna od strony 1

        fetch('orders_search.php?' + params.toString(), { headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (requestId !== latestRequestId) { return; } // spóźniona odpowiedź - ignoruj
                listRegion.innerHTML = data.html;
                if (foundText) { foundText.textContent = data.found; }
                if (filterFormQ) { filterFormQ.value = qValue; }
                var newUrl = window.location.pathname + '?' + params.toString();
                window.history.replaceState(null, '', newUrl);
            })
            .catch(function () { /* cichy fallback - wyszukiwarka nadal działa przez Enter/klik */ });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            if (searchClear) { searchClear.hidden = searchInput.value === ''; }
            window.clearTimeout(debounceTimer);
            var val = searchInput.value;
            debounceTimer = window.setTimeout(function () { runLiveSearch(val); }, 350);
        });
    }
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
