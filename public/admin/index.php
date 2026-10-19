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
// Kropka na przycisku panelu: lista zawężona czymś innym niż wyszukiwarka (ta jest widoczna na górze).
$drawerFiltersOn = $fStatus !== '' || $fSource !== '' || $fDateFrom !== '' || $fDateTo !== '' || $fTotalMin !== '' || $fTotalMax !== ''
    || $fUnread || $fIndicators || $fInvoiceIssued !== '' || $fReceiptIssued !== '';
// Telefon: „Nowe zamówienie” to sam „+”, a filtry, statusy, akcje na zaznaczonych i sortowanie są w panelu
// wysuwanym z boku (przycisk #ordersDrawerOpen, panel #ordersDrawer niżej) - na górze zostaje wyszukiwarka i lista.
$PAGE_TITLE_ACTIONS = (canEdit() && $view === 'active' ? '<a class="btn new-order-btn" href="order_new.php" style="margin-right:8px" aria-label="Nowe zamówienie" title="Nowe zamówienie">'
        . '<span aria-hidden="true">+</span><span class="new-order-label"> Nowe zamówienie</span></a>' : '')
    . '<button type="button" id="ordersFilterToggle" class="filters-toggle" aria-expanded="'
    . ($hasActiveFilters ? 'true' : 'false') . '" aria-controls="ordersFilterBar">'
    . '<span class="filters-toggle-icon"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16l-6 7v5l-4 2v-7L4 5z"/></svg></span>'
    . '<span class="filters-toggle-label">' . htmlspecialchars(t('orders.filters_toggle')) . '</span>'
    . '<svg class="filters-toggle-chevron" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>'
    . '</button>'
    . '<button type="button" id="ordersDrawerOpen" class="orders-drawer-open" aria-expanded="false" aria-controls="ordersDrawer" aria-label="Filtry, statusy i akcje" title="Filtry, statusy i akcje">'
    . '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/></svg>'
    . '<span class="orders-drawer-badge" id="ordersDrawerBadge" hidden></span>'
    . ($drawerFiltersOn ? '<span class="orders-drawer-dot" title="Włączone filtry"></span>' : '')
    . '</button>';
// „Pobierz teraz”: nowe zamówienia ze sklepów i z Allegro od razu, bez czekania na cykliczne pobieranie (orders_sync_now.php).
if (canEdit() && $view === 'active') {
    $PAGE_TITLE_SIDE = '<form method="post" action="orders_sync_now.php" class="sync-now-form" id="ordersSyncNow">'
        . '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrfToken()) . '">'
        . '<input type="hidden" name="back" value="' . htmlspecialchars(basename((string) ($_SERVER['REQUEST_URI'] ?? 'index.php'))) . '">'
        . '<button type="submit" class="filters-toggle sync-now" title="Pobierz nowe zamówienia ze sklepów i z Allegro teraz, bez czekania na automatyczne pobieranie" aria-label="Pobierz zamówienia teraz">'
        . '<span class="filters-toggle-icon"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><polyline points="21 3 21 9 15 9"/></svg></span>'
        . '<span class="sync-now-label">Pobierz teraz</span>'
        . '</button></form>';
}
// Style listy w <head> (patrz header.php) - bez tego ikony przez chwilę rysowały się w pełnym rozmiarze.
$PAGE_HEAD = <<<'CSS'
<style>
    .orders-perpage { display:flex; align-items:center; justify-content:center; gap:8px; margin-top:12px; font-size:12px; color:var(--ink-2); }
    .orders-perpage-select { padding:5px 8px; font-size:13px; font-family:var(--font-num); cursor:pointer; }
    .orders-layout { display:grid; grid-template-columns:230px minmax(0,1fr); gap:18px; align-items:start; }
    .orders-main { min-width:0; }
    .orders-table-scroll { width:100%; overflow-x:auto; }
    .orders-mobile-bar { display:none; }
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
    /* Telefon: każde zamówienie to osobna karta ze wszystkimi danymi - bez przewijania tabeli w bok.
       Komórki mają data-col (_orders_table.php); kolumny rozszerzeń zajmują całą szerokość karty. */
    @media (max-width:820px) {
        .orders-list-card { background:transparent; border:0; box-shadow:none; padding:0; }
        .orders-table-scroll { overflow:visible; }
        .orders-table, .orders-table tbody { display:block; }
        .orders-table tr.orders-head { display:none; }
        .orders-mobile-bar { display:flex; align-items:center; justify-content:space-between; gap:10px; margin:2px 0 12px; }
        .orders-mobile-selall { display:inline-flex; align-items:center; gap:8px; padding-left:15px; font-size:13px; color:var(--ink-2); cursor:pointer; }
        .orders-mobile-selall input { margin:0; width:20px; height:20px; accent-color:var(--accent); }
        .orders-mobile-sort { display:inline-flex; align-items:center; gap:6px; margin-left:auto; font-size:13px; color:var(--ink-2); }
        .orders-mobile-sort select { padding:6px 8px; font-size:13px; font-weight:600; max-width:170px; }
        .orders-table tr:not(.orders-head) { display:grid; grid-template-columns:auto minmax(0,1fr) auto; align-items:center;
            grid-template-areas:"chk num badge" "client client client" "email email email" "source source delivery" "ind ind date" "amount amount act";
            margin-bottom:12px; padding:14px; background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); }
        .orders-table tr:not(.orders-head):hover { background:var(--surface); }
        .orders-table td { grid-column:1 / -1; min-width:0; margin-top:10px; padding:0; border:0; }
        .orders-table td:first-child:not([data-col]):not([colspan]) { grid-area:chk; margin:0; padding-right:12px; }
        .orders-table td:first-child:not([data-col]):not([colspan]) input { display:block; margin:0; width:20px; height:20px; accent-color:var(--accent); }
        .orders-table td[data-col="number"] { grid-area:num; margin:0; font-size:13px !important; }
        .orders-table td[data-col="number"] strong { font-size:16px; color:var(--ink); }
        .orders-table td[data-col="status"] { display:contents; }
        .orders-table td[data-col="status"] > span:first-child { grid-area:badge; justify-self:end; font-size:13px !important; padding:4px 12px !important; }
        .orders-table td[data-col="status"] .order-indicators { grid-area:ind; flex-wrap:wrap; margin-top:10px; }
        .orders-table td[data-col="client"] { grid-area:client; margin-top:12px; font-size:16px; font-weight:600; }
        .orders-table td[data-col="email"] { grid-area:email; margin:0; font-size:13px !important; color:var(--ink-2); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .orders-table td[data-col="source"] { grid-area:source; }
        .orders-table td[data-col="delivery"] { grid-area:delivery; justify-self:end; }
        .orders-table td[data-col="date"] { grid-area:date; justify-self:end; align-self:end; color:var(--ink-2); }
        .orders-table td[data-col="amount"], .orders-table td[data-col="actions"] { align-self:stretch; display:flex; align-items:center;
            margin-top:12px; padding-top:12px; border-top:1px solid var(--line); }
        .orders-table td[data-col="amount"] { grid-area:amount; font-size:20px; }
        .orders-table td[data-col="actions"] { grid-area:act; justify-content:flex-end; gap:4px; }
        .orders-table .row-unread { background:#fff8e6; box-shadow:inset 3px 0 0 #f59e0b, var(--shadow); }
        .orders-table .row-unread:hover { background:#fff8e6; }
        .orders-table .row-unread td:first-child { box-shadow:none; }
        .orders-table .row-merged td[data-col="status"] > * { opacity:.55; }
    }

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
    .side-nav-toggle { display:none; }
    /* Telefon: lista zamówień zaraz pod wyszukiwarką, statusy zwinięte, statystyki na samym końcu. */
    @media (max-width:820px) {
        .orders-layout { display:flex; flex-direction:column; align-items:stretch; gap:14px; }
        .orders-side-col { display:contents; }
        .orders-side { order:0; }
        .orders-main { order:1; }
        .orders-stats { order:2; }
        .side-nav-toggle { display:flex; align-items:center; justify-content:space-between; gap:8px; width:100%;
            margin-top:8px; padding:9px 11px; border:1px solid var(--line); border-radius:8px; background:var(--surface);
            color:var(--ink); font:inherit; font-size:14px; font-weight:600; cursor:pointer; text-align:left; }
        .side-nav-toggle:focus-visible { outline:2px solid var(--accent); outline-offset:2px; }
        .side-nav-toggle-prefix { color:var(--ink-2); font-weight:400; }
        .side-nav-toggle-end { display:inline-flex; align-items:center; gap:8px; flex-shrink:0; }
        .side-nav-toggle .side-count.has { background:var(--accent); }
        .side-nav-toggle[aria-expanded="true"] .filters-toggle-chevron { transform:rotate(180deg); }
        .orders-side:not(.nav-open) .side-nav { display:none; }
    }
    .actionbar { display:flex; align-items:center; gap:10px; margin-bottom:12px; padding:8px 12px; background:var(--surface-2); border:1px solid var(--line); border-radius:var(--radius-sm); flex-wrap:wrap; }
    .actionbar .btn { padding:7px 12px; font-size:13px; }
    .actionbar-count { font-size:13px; color:var(--ink-2); margin-left:auto; }
    /* Telefon: licznik zaznaczonych na górze, „Zmień status” na całą szerokość, pozostałe przyciski
       równo po dwa w rzędzie (nieparzysty ostatni rozciąga się na cały rząd) - także przyciski rozszerzeń. */
    @media (max-width:820px) {
        .actionbar { gap:8px; padding:10px; }
        .actionbar-count { order:-1; flex:1 0 100%; margin-left:0; }
        .actionbar .flag-menu { flex:1 0 100%; }
        .actionbar .flag-btn { width:100%; justify-content:center; min-height:40px; }
        .actionbar .flag-dropdown { right:0; }
        .actionbar > .btn { flex:1 1 calc(50% - 4px); min-height:40px; justify-content:center; text-align:center; }
    }
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
    /* „Pobierz teraz” - okrągła ikona tuż przy tytule „Zamówienia”; w trakcie pobierania się kręci. */
    .sync-now-form { display:inline-flex; margin:0 auto 0 -2px; }
    .sync-now { padding:6px; }
    .sync-now .filters-toggle-icon { border:0; }
    .sync-now .filters-toggle-icon svg { width:17px; height:17px; }
    .sync-now-label { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; }
    .sync-now[disabled] { cursor:progress; opacity:.75; }
    .sync-now[disabled] .filters-toggle-icon svg { animation:sync-now-spin .9s linear infinite; }
    @keyframes sync-now-spin { to { transform:rotate(360deg); } }
    /* Panel wysuwany z boku (tylko telefon): filtry, statusy, akcje na zaznaczonych, sortowanie.
       Na komputerze te elementy zostają na swoich miejscach, a przycisk i panel są ukryte. */
    .orders-drawer-open, .orders-drawer, .orders-drawer-backdrop { display:none; }
    @media (max-width:820px) {
        .page-title-row .new-order-btn { display:inline-flex; align-items:center; justify-content:center; width:44px; height:44px;
            padding:0; margin:0 !important; border-radius:50%; font-size:26px; font-weight:500; line-height:1; flex:none; }
        .new-order-label { display:none; }
        .orders-drawer-open { display:inline-flex; align-items:center; justify-content:center; position:relative; width:44px; height:44px; flex:none;
            border:1px solid var(--line); border-radius:50%; background:var(--surface); color:var(--ink); cursor:pointer; }
        .orders-drawer-open:focus-visible { outline:2px solid var(--accent); outline-offset:2px; }
        .orders-drawer-badge { position:absolute; top:-4px; right:-4px; min-width:20px; height:20px; padding:0 5px; box-sizing:border-box;
            border-radius:999px; background:var(--accent); color:#fff; font-size:11px; font-weight:700; line-height:20px; text-align:center; }
        .orders-drawer-badge[hidden] { display:none; }
        .orders-drawer-dot { position:absolute; top:2px; right:2px; width:10px; height:10px; border-radius:50%; background:var(--accent); border:2px solid var(--surface); }
        .orders-drawer-badge:not([hidden]) + .orders-drawer-dot { display:none; }
        .sync-now-form { order:0; margin:0 auto 0 -2px; }
        #ordersFilterToggle { display:none; }

        .orders-side { padding:0; border:0; box-shadow:none; background:transparent; }
        .orders-side .side-search { padding:11px 14px; background:var(--surface); box-shadow:var(--shadow); }
        .orders-side .side-search-input { font-size:16px; }
        .orders-side .side-search-icon { width:18px; height:18px; }
        .side-nav-toggle { display:none !important; }
        .orders-list-card .orders-mobile-bar { display:none; }

        .orders-drawer-backdrop { display:block; position:fixed; inset:0; z-index:1100; background:rgba(30,25,15,.45);
            opacity:0; pointer-events:none; transition:opacity .2s ease; }
        .orders-drawer-backdrop.open { opacity:1; pointer-events:auto; }
        .orders-drawer { display:flex; flex-direction:column; position:fixed; top:0; right:0; bottom:0; z-index:1101; width:min(380px, 90vw);
            background:var(--bg, #f6f4ef); box-shadow:-12px 0 40px rgba(20,18,12,.22); transform:translateX(105%); visibility:hidden;
            transition:transform .22s ease, visibility 0s linear .22s; }
        .orders-drawer.open { transform:none; visibility:visible; transition:transform .22s ease; }
        .orders-drawer-head { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:14px 16px;
            border-bottom:1px solid var(--line); background:var(--surface); }
        .orders-drawer-head strong { font-size:17px; }
        .orders-drawer-close { width:40px; height:40px; border:0; border-radius:50%; background:var(--surface-2); color:var(--ink-2); font-size:24px; line-height:1; cursor:pointer; }
        .orders-drawer-body { flex:1; overflow-y:auto; overscroll-behavior:contain; -webkit-overflow-scrolling:touch; padding:14px 14px 28px; }
        .orders-drawer-section { margin-bottom:18px; }
        .orders-drawer-section[hidden] { display:none; }
        .orders-drawer-section h3 { margin:0 0 8px; font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:var(--ink-2); }
        .orders-drawer .orders-mobile-selall { display:flex; padding:0 2px 10px; font-size:14px; color:var(--ink); }
        .orders-drawer .actionbar { margin:0; }
        .orders-drawer .flag-dropdown { position:static; margin-top:6px; box-shadow:none; border:1px solid var(--line); }
        .orders-drawer .orders-mobile-sort { display:flex; width:100%; margin:0; }
        .orders-drawer .orders-mobile-sort > span { display:none; }
        .orders-drawer .orders-mobile-sort select { flex:1; max-width:none; padding:10px; font-size:15px; }
        .orders-drawer .side-nav { background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); padding:6px; }
        .orders-drawer .side-nav > .side-sep:first-child { display:none; }
        .orders-drawer #ordersFilterToggle { display:flex; width:100%; justify-content:flex-start; }
        .orders-drawer #ordersFilterToggle .filters-toggle-chevron { margin-left:auto; }
        .orders-drawer #ordersFilterBar { margin:10px 0 0; }
        body.orders-drawer-lock { overflow:hidden; }
    }
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
// Telefon: statusy są zwinięte pod przyciskiem z aktualnie wybraną pozycją.
$currentSide = ['label' => t('orders.side.all'), 'color' => null, 'count' => $view === 'active' ? (int) $ordersTotal : 0];
if ($view === 'archived') {
    $currentSide = ['label' => t('orders.side.archive'), 'color' => null, 'count' => (int) $lifeCounts['archived']];
} elseif ($view === 'trashed') {
    $currentSide = ['label' => t('orders.side.trash'), 'color' => null, 'count' => (int) $lifeCounts['trashed']];
} elseif ($fStatus !== '') {
    foreach ($statusList as $s) {
        if ($s['status_key'] === $fStatus) {
            $currentSide = ['label' => $s['label'], 'color' => $s['color'] ?? '#888', 'count' => (int) ($statusCounts[$fStatus] ?? 0)];
            break;
        }
    }
}
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
    <button type="button" class="side-nav-toggle" id="ordersSideNavToggle" aria-expanded="false" aria-controls="ordersSideNav">
        <span class="side-label">
            <?php if ($currentSide['color'] !== null): ?>
                <span class="side-dot" style="background:<?= htmlspecialchars($currentSide['color']) ?>"></span>
            <?php endif; ?>
            <span class="side-nav-toggle-prefix"><?= htmlspecialchars(t('orders.side.status')) ?>:</span>
            <?= htmlspecialchars($currentSide['label']) ?>
        </span>
        <span class="side-nav-toggle-end">
            <?php if ($currentSide['count'] > 0): ?><span class="side-count has"><?= $currentSide['count'] ?></span><?php endif; ?>
            <svg class="filters-toggle-chevron" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
        </span>
    </button>
    <div class="side-nav" id="ordersSideNav">
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
    </div><!-- /side-nav -->
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

<?php // Telefon: skrypt niżej przenosi tu statusy, filtry, akcje na zaznaczonych i sortowanie (komputer - bez zmian). ?>
<div class="orders-drawer-backdrop" id="ordersDrawerBackdrop" data-no-pull-refresh></div>
<aside class="orders-drawer" id="ordersDrawer" aria-label="Filtry, statusy i akcje" aria-hidden="true" data-no-pull-refresh>
    <div class="orders-drawer-head">
        <strong><?= htmlspecialchars($PAGE_TITLE) ?></strong>
        <button type="button" class="orders-drawer-close" id="ordersDrawerClose" aria-label="Zamknij">×</button>
    </div>
    <div class="orders-drawer-body">
        <section class="orders-drawer-section" data-drawer-slot="selection"><h3>Zaznaczone</h3></section>
        <section class="orders-drawer-section" data-drawer-slot="sort"><h3><?= htmlspecialchars(t('orders.sort.label')) ?></h3></section>
        <section class="orders-drawer-section" data-drawer-slot="filters"><h3>Filtry</h3></section>
        <section class="orders-drawer-section" data-drawer-slot="status"><h3><?= htmlspecialchars(t('orders.side.status')) ?></h3></section>
    </div>
</aside>

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
    var sideNavToggle = document.getElementById('ordersSideNavToggle');
    if (sideNavToggle) {
        sideNavToggle.addEventListener('click', function () {
            var side = sideNavToggle.closest('.orders-side');
            var open = side.classList.toggle('nav-open');
            sideNavToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    // „Pobierz teraz”: pobieranie trwa kilka sekund - kręcąca się ikona, żeby nikt nie klikał drugi raz.
    var syncNow = document.getElementById('ordersSyncNow');
    if (syncNow) {
        syncNow.addEventListener('submit', function () {
            var b = syncNow.querySelector('button');
            b.disabled = true;
            b.querySelector('.sync-now-label').textContent = 'Pobieram…';
        });
    }

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
        var badge = document.getElementById('ordersDrawerBadge');
        if (badge) { badge.textContent = n; badge.hidden = n === 0; }
    }

    // ===== Panel wysuwany z boku (telefon) =====
    // Do 820 px statusy, filtry, pasek akcji, „Zaznacz wszystkie” i sortowanie są przenoszone do panelu, a na górze
    // zostaje wyszukiwarka i lista. Szerzej wracają na swoje miejsca (komentarz-znacznik w miejscu, skąd je wzięto).
    // Pasek akcji jest poza #bulkForm - jego pola dostają atrybut form, więc dalej wysyłają ten sam formularz.
    var drawer = document.getElementById('ordersDrawer');
    var drawerBackdrop = document.getElementById('ordersDrawerBackdrop');
    var drawerOpenBtn = document.getElementById('ordersDrawerOpen');
    var phone = window.matchMedia('(max-width:820px)');
    var drawerPieces = {
        selection: ['#ordersListRegion .orders-mobile-selall', '#ordersListRegion .actionbar'],
        sort: ['#ordersListRegion .orders-mobile-sort'],
        filters: ['#ordersFilterToggle', '#ordersFilterBar'],
        status: ['#ordersSideNav']
    };
    var moved = [];
    function slot(name) { return drawer.querySelector('[data-drawer-slot="' + name + '"]'); }
    function mountDrawer() {
        Object.keys(drawerPieces).forEach(function (name) {
            var box = slot(name);
            drawerPieces[name].forEach(function (sel) {
                var node = document.querySelector(sel);
                if (!node) { return; }
                var mark = document.createComment('orders-drawer');
                node.parentNode.insertBefore(mark, node);
                node.__drawerHome = mark;
                box.appendChild(node);
                moved.push(node);
                if (node.classList.contains('actionbar')) {
                    node.querySelectorAll('button, input, select, textarea').forEach(function (f) {
                        if (!f.hasAttribute('form')) { f.setAttribute('form', 'bulkForm'); f.setAttribute('data-drawer-form', ''); }
                    });
                }
            });
            box.hidden = box.children.length < 2;   // sam nagłówek - np. podgląd bez akcji
        });
    }
    function unmountDrawer() {
        moved.forEach(function (node) {
            var mark = node.__drawerHome;
            if (mark && mark.isConnected) {
                mark.parentNode.replaceChild(node, mark);
                node.querySelectorAll('[data-drawer-form]').forEach(function (f) { f.removeAttribute('form'); f.removeAttribute('data-drawer-form'); });
            } else {
                node.remove();   // stara lista po wyszukiwaniu na żywo - jej miejsca już nie ma
            }
        });
        moved = [];
    }
    function setDrawer(open) {
        if (!drawer) { return; }
        drawer.classList.toggle('open', open);
        drawerBackdrop.classList.toggle('open', open);
        drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
        document.body.classList.toggle('orders-drawer-lock', open);
        if (drawerOpenBtn) { drawerOpenBtn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    }
    function placeDrawer() {
        if (!drawer) { return; }
        unmountDrawer();
        if (phone.matches) { mountDrawer(); } else { setDrawer(false); }
    }
    if (drawer) {
        placeDrawer();
        if (phone.addEventListener) { phone.addEventListener('change', placeDrawer); } else { phone.addListener(placeDrawer); }
        drawerOpenBtn && drawerOpenBtn.addEventListener('click', function () { setDrawer(true); });
        drawerBackdrop.addEventListener('click', function () { setDrawer(false); });
        document.getElementById('ordersDrawerClose').addEventListener('click', function () { setDrawer(false); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && drawer.classList.contains('open')) { setDrawer(false); } });
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
            var form1 = actSel.form || actSel.closest('form');
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
                placeDrawer();   // nowy pasek akcji i sortowanie trafiają do panelu (telefon)
                refreshCount();
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
