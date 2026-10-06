<?php
declare(strict_types=1);

/**
 * Statystyki zamówień: wykres dzienny (ilość + wartość) w wybranym zakresie,
 * z filtrami źródła/statusu/waluty i podsumowaniem okresu. Wzorowane na
 * widoku statystyk Baselinkera.
 */

use Pase\Repository\OrderStatusRepository;

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

$statusRepo = new OrderStatusRepository($pdo);
$statusList = $statusRepo->all();

// --- Zakres dat: preset (7/30/90/365 dni, bieżący miesiąc) + możliwość ręcznej edycji dat ---
$rangeOptions = ['7' => 'stats.range.7', '30' => 'stats.range.30', '90' => 'stats.range.90', '365' => 'stats.range.365', 'month' => 'stats.range.month'];
$range = array_key_exists($_GET['range'] ?? '', $rangeOptions) ? $_GET['range'] : '30';

if ($range === 'month') {
    $dateFromCalc = date('Y-m-01');
    $dateToCalc   = date('Y-m-d');
} else {
    $days         = (int) $range;
    $dateToCalc   = date('Y-m-d');
    $dateFromCalc = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
}

$isValidDate = static fn(string $v): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
$dateFrom = $isValidDate($_GET['date_from'] ?? '') ? $_GET['date_from'] : $dateFromCalc;
$dateTo   = $isValidDate($_GET['date_to'] ?? '') ? $_GET['date_to'] : $dateToCalc;
if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}
// Zabezpieczenie przed ręcznie wpisanym absurdalnie długim zakresem (generowanie osi dzień po dniu).
if ((strtotime($dateTo) - strtotime($dateFrom)) > 400 * 86400) {
    $dateFrom = date('Y-m-d', strtotime($dateTo . ' -400 days'));
}

$fSource   = trim($_GET['source'] ?? '');
$fStatus   = trim($_GET['status'] ?? '');
$fCurrency = trim($_GET['currency'] ?? '');

// --- Opcje filtrów: źródła zamówień i waluty faktycznie występujące w danych.
// Uwaga: integration_accounts zawiera też integracje, które NIE są źródłem zamówień
// (np. Blpaczka - kurier, wFirma - księgowość) - dlatego bierzemy typy tylko z tych
// integracji, które są faktycznie podpięte pod jakieś zamówienie w woo_orders.
$sourceLabels = ['woocommerce' => 'WooCommerce', 'allegro' => 'Allegro'];
$sourceTypes  = [];
try {
    $sourceTypes = $pdo->query(
        'SELECT DISTINCT ia.type FROM woo_orders wo
         JOIN integration_accounts ia ON ia.id = wo.integration_id
         ORDER BY ia.type'
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (\PDOException $e) {
    $sourceTypes = [];
}

$currencies = [];
try {
    $currencies = $pdo->query("SELECT DISTINCT currency FROM woo_orders WHERE currency IS NOT NULL AND currency <> '' ORDER BY currency")->fetchAll(PDO::FETCH_COLUMN);
} catch (\PDOException $e) {
    $currencies = [];
}
if ($fCurrency === '' && !isset($_GET['range'])) {
    // Pierwsze wejście na stronę (brak jakichkolwiek filtrów w URL) - domyślnie PLN, jeśli występuje.
    if (in_array('PLN', $currencies, true)) {
        $fCurrency = 'PLN';
    } elseif (count($currencies) === 1) {
        $fCurrency = $currencies[0];
    }
}

// --- Budowa zapytania ---
$where  = ["wo.lifecycle <> 'trashed'", 'wo.date_created >= :from', 'wo.date_created <= :to'];
$params = [':from' => $dateFrom . ' 00:00:00', ':to' => $dateTo . ' 23:59:59'];
if ($fSource !== '') {
    $where[] = 'ia.type = :source';
    $params[':source'] = $fSource;
}
if ($fStatus !== '') {
    $where[] = 'wo.pase_status = :status';
    $params[':status'] = $fStatus;
}
if ($fCurrency !== '') {
    $where[] = 'wo.currency = :currency';
    $params[':currency'] = $fCurrency;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$rows    = [];
$noTable = false;
try {
    $stmt = $pdo->prepare(
        "SELECT wo.date_created, wo.total, wo.payload
         FROM woo_orders wo
         LEFT JOIN integration_accounts ia ON ia.id = wo.integration_id
         {$whereSql}"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    $noTable = true;
}

// --- Agregacja dzienna + podsumowanie okresu ---
$daily = [];
$cursor = new \DateTimeImmutable($dateFrom);
$end    = new \DateTimeImmutable($dateTo);
while ($cursor <= $end) {
    $daily[$cursor->format('Y-m-d')] = ['count' => 0, 'value' => 0.0];
    $cursor = $cursor->modify('+1 day');
}

$totalCount = 0;
$totalValue = 0.0;
$totalShipping = 0.0;
foreach ($rows as $r) {
    $day = substr((string) $r['date_created'], 0, 10);
    $val = (float) ($r['total'] ?? 0);
    if (isset($daily[$day])) {
        $daily[$day]['count']++;
        $daily[$day]['value'] += $val;
    }
    $totalCount++;
    $totalValue += $val;
    $payload = json_decode($r['payload'] ?? '{}', true);
    $totalShipping += is_array($payload) ? (float) ($payload['shipping_total'] ?? 0) : 0.0;
}
$totalProducts = $totalValue - $totalShipping;
$daysInRange = count($daily);
$currencyLabel = $fCurrency !== '' ? $fCurrency : 'zł';

/** Zaokrągla w górę do "ładnej" liczby (1/2/5 * 10^n) - do skalowania osi wykresu. */
function niceCeil(float $v): float
{
    if ($v <= 0) {
        return 1.0;
    }
    $mag = 10 ** floor(log10($v));
    $n = $v / $mag;
    $nice = $n <= 1 ? 1 : ($n <= 2 ? 2 : ($n <= 5 ? 5 : 10));
    return $nice * $mag;
}

$plMonths = [1 => 'sty', 2 => 'lut', 3 => 'mar', 4 => 'kwi', 5 => 'maj', 6 => 'cze', 7 => 'lip', 8 => 'sie', 9 => 'wrz', 10 => 'paź', 11 => 'lis', 12 => 'gru'];

/** Renderuje wykres liniowy (dwie osie: ilość + wartość) jako inline SVG. */
function renderOrdersStatsChart(array $daily, array $plMonths, string $countLabel, string $valueLabel, string $currency): string
{
    $days = array_keys($daily);
    $n = count($days);
    if ($n === 0) {
        return '';
    }
    $counts = array_column($daily, 'count');
    $values = array_column($daily, 'value');
    $maxCount = niceCeil((float) max($counts));
    $maxValue = niceCeil((float) max($values));

    $w = 880; $h = 300;
    $mL = 40; $mR = 58; $mT = 36; $mB = 30;
    $plotW = $w - $mL - $mR;
    $plotH = $h - $mT - $mB;
    $baseY = $mT + $plotH;

    $x = static fn(int $i) => $n <= 1 ? $mL + $plotW / 2 : $mL + ($i / ($n - 1)) * $plotW;
    $yC = static fn(float $v) => $baseY - ($v / $maxCount) * $plotH;
    $yV = static fn(float $v) => $baseY - ($v / $maxValue) * $plotH;

    // Siatka pozioma (5 poziomów) + etykiety obu osi.
    $grid = '';
    for ($s = 0; $s <= 4; $s++) {
        $frac = $s / 4;
        $y = $baseY - $frac * $plotH;
        $grid .= '<line x1="' . $mL . '" y1="' . round($y, 1) . '" x2="' . ($mL + $plotW) . '" y2="' . round($y, 1) . '" class="stc-grid"/>';
        $grid .= '<text x="' . ($mL - 8) . '" y="' . round($y + 4, 1) . '" class="stc-axis" text-anchor="end">' . (int) round($frac * $maxCount) . '</text>';
        $vLabel = $frac * $maxValue >= 1000 ? round($frac * $maxValue / 1000, 1) . 'k' : (int) round($frac * $maxValue);
        $grid .= '<text x="' . ($mL + $plotW + 8) . '" y="' . round($y + 4, 1) . '" class="stc-axis">' . $vLabel . '</text>';
    }

    // Etykiety osi X - maks. ok. 7, zawsze pierwsza i ostatnia.
    $xLabels = '';
    $step = max(1, (int) ceil($n / 7));
    for ($i = 0; $i < $n; $i++) {
        if ($i % $step !== 0 && $i !== $n - 1) {
            continue;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $days[$i]);
        $lbl = $d ? ($d->format('j') . ' ' . $plMonths[(int) $d->format('n')]) : $days[$i];
        $xLabels .= '<text x="' . round($x($i), 1) . '" y="' . ($h - 8) . '" class="stc-axis" text-anchor="middle">' . htmlspecialchars($lbl) . '</text>';
    }

    // Linie + punkty + obszar pod linią ilości.
    $countPts = []; $valuePts = []; $areaPts = []; $markers = '';
    for ($i = 0; $i < $n; $i++) {
        $xi = round($x($i), 1);
        $ycC = round($yC((float) $counts[$i]), 1);
        $ycV = round($yV((float) $values[$i]), 1);
        $countPts[] = "$xi,$ycC";
        $valuePts[] = "$xi,$ycV";
        $areaPts[] = "$xi,$ycC";
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $days[$i]);
        $dLbl = $d ? ($d->format('d.m')) : $days[$i];
        $markers .= '<circle cx="' . $xi . '" cy="' . $ycC . '" r="2.6" class="stc-dot stc-dot-count"><title>' . htmlspecialchars($dLbl) . ': ' . $counts[$i] . '</title></circle>';
        $markers .= '<circle cx="' . $xi . '" cy="' . $ycV . '" r="2.6" class="stc-dot stc-dot-value"><title>' . htmlspecialchars($dLbl) . ': ' . number_format((float) $values[$i], 2, ',', ' ') . ' ' . htmlspecialchars($currency) . '</title></circle>';
    }
    $areaPath = 'M' . $mL . ',' . $baseY . ' L' . implode(' L', $areaPts) . ' L' . round($x($n - 1), 1) . ',' . $baseY . ' Z';

    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" height="' . $h . '" role="img" aria-label="' . htmlspecialchars($countLabel . ' / ' . $valueLabel) . '">';
    $svg .= '<style>.stc-grid{stroke:var(--line);stroke-width:1}.stc-axis{font:11px var(--font-num,monospace);fill:var(--ink-2)}.stc-dot-count{fill:var(--accent)}.stc-dot-value{fill:#3a6ea8}</style>';
    $svg .= $grid;
    $svg .= '<path d="' . $areaPath . '" fill="var(--accent)" fill-opacity="0.10" stroke="none"/>';
    $svg .= '<polyline points="' . implode(' ', $countPts) . '" fill="none" stroke="var(--accent)" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"/>';
    $svg .= '<polyline points="' . implode(' ', $valuePts) . '" fill="none" stroke="#3a6ea8" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"/>';
    $svg .= $markers;
    $svg .= $xLabels;
    $svg .= '</svg>';
    return $svg;
}

// --- Marża (koszt zakupu zapisany na pozycjach zamówień) - tylko z uprawnieniem do kosztów zakupu.
$margin = null;
$marginOtherCurrency = $fCurrency !== '' && $fCurrency !== 'PLN';
if (!$noTable && canViewPage('product_costs')) {
    try {
        $marginSvc = new \Pase\Services\OrderMargins($pdo);
        $marginSvc->syncPending(2000);
        $margin = $marginSvc->report($dateFrom, $dateTo, $fSource, $fStatus);
    } catch (\Throwable $e) {
        \Pase\Support\Logger::warn('Statystyki: marża niedostępna - ' . $e->getMessage());
        $margin = null;
    }
}

$chart = renderOrdersStatsChart($daily, $plMonths, t('stats.legend.count'), t('stats.legend.value'), $currencyLabel);

$PAGE_TITLE = t('stats.title');
$PAGE_KEY   = 'statistics';
require __DIR__ . '/header.php';
?>

<div class="card stats-filters">
    <form method="get">
        <div class="stats-fgrid">
            <div class="stats-field">
                <label><?= htmlspecialchars(t('stats.range')) ?></label>
                <select name="range" onchange="this.form.elements['date_from'].value='';this.form.elements['date_to'].value='';this.form.submit()">
                    <?php foreach ($rangeOptions as $key => $labelKey): $key = (string) $key; ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= $range === $key ? 'selected' : '' ?>><?= htmlspecialchars(t($labelKey)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="stats-field">
                <label><?= htmlspecialchars(t('orders.date_from')) ?></label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="stats-field">
                <label><?= htmlspecialchars(t('orders.date_to')) ?></label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <div class="stats-field">
                <label><?= htmlspecialchars(t('stats.source')) ?></label>
                <select name="source">
                    <option value="">— <?= htmlspecialchars(t('common.all')) ?> —</option>
                    <?php foreach ($sourceTypes as $type): ?>
                        <option value="<?= htmlspecialchars($type) ?>" <?= $fSource === $type ? 'selected' : '' ?>><?= htmlspecialchars($sourceLabels[$type] ?? ucfirst($type)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="stats-field">
                <label><?= htmlspecialchars(t('stats.status')) ?></label>
                <select name="status">
                    <option value="">— <?= htmlspecialchars(t('common.all')) ?> —</option>
                    <?php foreach ($statusList as $s): ?>
                        <option value="<?= htmlspecialchars($s['status_key']) ?>" <?= $fStatus === $s['status_key'] ? 'selected' : '' ?>><?= htmlspecialchars($s['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="stats-field">
                <label><?= htmlspecialchars(t('stats.currency')) ?></label>
                <select name="currency">
                    <option value="">— <?= htmlspecialchars(t('common.all')) ?> —</option>
                    <?php foreach ($currencies as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>" <?= $fCurrency === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <p style="margin-top:14px">
            <button class="btn" type="submit"><?= htmlspecialchars(t('stats.show')) ?></button>
            <a class="btn secondary" href="statistics.php"><?= htmlspecialchars(t('common.clear')) ?></a>
        </p>
    </form>
</div>

<?php if ($noTable): ?>
    <div class="flash err">Tabela <code>woo_orders</code> jeszcze nie istnieje.</div>
<?php elseif ($chart === ''): ?>
    <div class="card"><p style="color:var(--ink-2)"><?= htmlspecialchars(t('stats.no_data')) ?></p></div>
<?php else: ?>
    <div class="card">
        <div class="stats-chart-legend">
            <span><i class="dot" style="background:var(--accent)"></i><?= htmlspecialchars(t('stats.legend.count')) ?></span>
            <span><i class="dot" style="background:#3a6ea8"></i><?= htmlspecialchars(t('stats.legend.value')) ?> (<?= htmlspecialchars($currencyLabel) ?>)</span>
        </div>
        <?= $chart ?>
    </div>
<?php endif; ?>

<div class="stats-summary-grid">
    <div class="card stats-tile">
        <div class="stats-tile-num"><?= (int) $daysInRange ?></div>
        <div class="stats-tile-lbl"><?= htmlspecialchars(t('stats.days')) ?></div>
    </div>
    <div class="card stats-tile">
        <div class="stats-tile-num"><?= (int) $totalCount ?></div>
        <div class="stats-tile-lbl"><?= htmlspecialchars(t('stats.orders_in_period')) ?></div>
    </div>
    <div class="card stats-summary">
        <h3><?= htmlspecialchars(t('stats.summary')) ?></h3>
        <div class="stats-summary-row"><span><?= htmlspecialchars(t('stats.total_value')) ?></span><b><?= number_format($totalValue, 2, ',', ' ') ?> <?= htmlspecialchars($currencyLabel) ?></b></div>
        <div class="stats-summary-row"><span><?= htmlspecialchars(t('stats.products_value')) ?></span><b><?= number_format($totalProducts, 2, ',', ' ') ?> <?= htmlspecialchars($currencyLabel) ?></b></div>
        <div class="stats-summary-row"><span><?= htmlspecialchars(t('stats.shipping_cost')) ?></span><b><?= number_format($totalShipping, 2, ',', ' ') ?> <?= htmlspecialchars($currencyLabel) ?></b></div>
    </div>
</div>

<?php if ($margin !== null):
    $mt = $margin['total'];
    $money = static fn(float $v): string => number_format($v, 2, ',', ' ') . ' zł';
    $pct = static fn(?float $v): string => $v === null ? '—' : number_format($v, 1, ',', ' ') . '%';
    $tone = static fn(?float $v): string => $v === null ? '' : ($v < 0 ? ' mg-neg' : '');
    $plDate = static function (string $key) use ($plMonths, $margin): string {
        if ($margin['period_format'] === 'Y-m') {
            [$y, $m] = explode('-', $key);
            return $plMonths[(int) $m] . ' ' . $y;
        }
        return date('d.m', strtotime($key));
    };
?>
<?php $nt = \Pase\Services\CompanySettings::netSuffix($pdo); ?>
<h2 class="mg-h">Marża <span><?= $nt !== '' ? 'netto, ' : '' ?>zamówienia w PLN, bez anulowanych i zwróconych<?= $marginOtherCurrency ? ' — filtr waluty ' . htmlspecialchars($fCurrency) . ' nie dotyczy marży' : '' ?></span></h2>
<?php if ($mt['orders'] === 0): ?>
    <div class="card"><p style="color:var(--ink-2)">Brak zamówień w PLN w tym zakresie.</p></div>
<?php else: ?>
<div class="mg-tiles">
    <div class="card mg-tile"><div class="mg-lbl">Sprzedaż produktów<?= $nt ?></div><div class="mg-num"><?= $money($mt['revenue']) ?></div><div class="mg-sub"><?= $mt['orders'] ?> zamówień, <?= $mt['qty'] ?> szt.</div></div>
    <div class="card mg-tile"><div class="mg-lbl">Koszt towaru</div><div class="mg-num"><?= $money($mt['cost']) ?></div><div class="mg-sub">z pozycji z kosztem zakupu</div></div>
    <div class="card mg-tile"><div class="mg-lbl">Marża na towarze</div><div class="mg-num<?= $tone($mt['margin']) ?>"><?= $money($mt['margin']) ?></div><div class="mg-sub"><?= $pct($mt['margin_pct']) ?> sprzedaży z kosztem</div></div>
    <div class="card mg-tile"><div class="mg-lbl">Po opłatach Allegro</div><div class="mg-num<?= $tone($mt['margin_after_fees']) ?>"><?= $money($mt['margin_after_fees']) ?></div><div class="mg-sub"><?= $pct($mt['margin_after_fees_pct']) ?> · opłaty <?= $money($mt['fees']) ?></div></div>
</div>
<?php if ($mt['coverage_pct'] !== null && $mt['coverage_pct'] < 100): ?>
    <div class="flash <?= $mt['coverage_pct'] < 80 ? 'err' : 'ok' ?> mg-cover">
        Koszt zakupu znamy dla <b><?= $pct($mt['coverage_pct']) ?></b> sprzedaży<?= $nt ?>. Pozycje bez kosztu
        (<?= $money($mt['revenue'] - $mt['revenue_costed']) ?>) nie wchodzą do marży —
        <?php if (canOpenPage('product_costs.php')): ?><a href="product_costs.php?only=missing">uzupełnij koszty zakupu</a><?php else: ?>poproś o uzupełnienie kosztów zakupu<?php endif; ?>.
    </div>
<?php endif; ?>

<div class="mg-grid">
    <div class="card">
        <h3 class="mg-t">Kanały sprzedaży</h3>
        <div class="mg-scroll">
        <table class="mg-table">
            <tr><th>Kanał</th><th>Sprzedaż<?= $nt ?></th><th>Koszt</th><th>Marża</th><th>%</th><th>Opłaty</th><th>Po opłatach</th></tr>
            <?php foreach ($margin['channels'] as $c): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $c['label']) ?> <span class="mg-dim"><?= $c['orders'] ?> zam.</span></td>
                    <td><?= $money($c['revenue']) ?></td>
                    <td><?= $money($c['cost']) ?></td>
                    <td class="<?= $tone($c['margin']) ?>"><?= $money($c['margin']) ?></td>
                    <td><?= $pct($c['margin_pct']) ?></td>
                    <td><?= $c['fees'] > 0 ? $money($c['fees']) : '—' ?></td>
                    <td class="<?= $tone($c['margin_after_fees']) ?>"><?= $money($c['margin_after_fees']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
    </div>
    <div class="card">
        <h3 class="mg-t"><?= $margin['period_format'] === 'Y-m' ? 'Miesiące' : 'Dni' ?></h3>
        <div class="mg-scroll">
        <table class="mg-table">
            <tr><th>Okres</th><th>Sprzedaż<?= $nt ?></th><th>Marża</th><th>%</th><th>Po opłatach</th></tr>
            <?php foreach (array_reverse($margin['periods'], true) as $key => $pr): ?>
                <tr>
                    <td><?= htmlspecialchars($plDate((string) $key)) ?></td>
                    <td><?= $money($pr['revenue']) ?></td>
                    <td class="<?= $tone($pr['margin']) ?>"><?= $money($pr['margin']) ?></td>
                    <td><?= $pct($pr['margin_pct']) ?></td>
                    <td class="<?= $tone($pr['margin_after_fees']) ?>"><?= $money($pr['margin_after_fees']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
    </div>
</div>

<div class="card">
    <h3 class="mg-t">Produkty <span class="mg-dim">od największej sprzedaży<?= count($margin['products']) > 100 ? ', pierwsze 100 z ' . count($margin['products']) : '' ?></span></h3>
    <div class="mg-scroll mg-tall">
    <table class="mg-table">
        <tr><th>Produkt</th><th>Szt.</th><th>Sprzedaż<?= $nt ?></th><th>Koszt</th><th>Marża</th><th>%</th></tr>
        <?php foreach (array_slice($margin['products'], 0, 100) as $pr):
            $label = trim((string) ($pr['name'] ?? '')) ?: (string) ($pr['sku'] ?? '—'); ?>
            <tr>
                <td>
                    <?php if ($pr['product_id']): ?><a href="product_view.php?id=<?= (int) $pr['product_id'] ?>"><?= htmlspecialchars($label) ?></a><?php else: ?><?= htmlspecialchars($label) ?><?php endif; ?>
                    <?php if ($pr['sku']): ?><span class="mg-dim"><?= htmlspecialchars((string) $pr['sku']) ?></span><?php endif; ?>
                    <?php if ($pr['missing'] > 0): ?><span class="pill warn" title="<?= $pr['product_id'] ? 'Produkt nie ma kosztu zakupu' : 'Pozycja nie jest powiązana z produktem w magazynie' ?>"><?= $pr['missing'] === $pr['qty'] ? 'brak kosztu' : $pr['missing'] . ' szt. bez kosztu' ?></span><?php endif; ?>
                </td>
                <td><?= $pr['qty'] ?></td>
                <td><?= $money($pr['revenue']) ?></td>
                <td><?= $pr['revenue_costed'] > 0 ? $money($pr['cost']) : '—' ?></td>
                <td class="<?= $tone($pr['margin']) ?>"><?= $pr['revenue_costed'] > 0 ? $money($pr['margin']) : '—' ?></td>
                <td><?= $pct($pr['margin_pct']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php if (!empty($margin['vat_payer'])): ?>
    <p class="mg-dim" style="margin-top:10px">Przychód bez dostawy i bez VAT (ceny bez podatku w zamówieniu dzielimy przez <?= (int) $margin['vat'] ?>%). Koszt zakupu jest zapisywany na pozycjach w chwili przyjścia zamówienia.
        Opłaty Allegro to prowizja i promowanie przypisane do zamówienia w rozliczeniach Allegro (netto, bez opłat za dostawę); w „po opłatach” liczymy tylko część przypadającą na pozycje z kosztem.</p>
    <?php else: ?>
    <p class="mg-dim" style="margin-top:10px">Firma bez VAT (Konfiguracja → Firma): przychód to kwota zapłacona przez klienta bez dostawy, koszt zakupu i opłaty Allegro to kwoty faktycznie zapłacone. Koszt zakupu jest zapisywany na pozycjach w chwili przyjścia zamówienia.
        Opłaty Allegro to prowizja i promowanie przypisane do zamówienia w rozliczeniach Allegro (bez opłat za dostawę); w „po opłatach” liczymy tylko część przypadającą na pozycje z kosztem.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<style>
    .mg-h { margin:26px 0 12px; font-size:18px; }
    .mg-h span { font-size:12.5px; font-weight:400; color:var(--ink-2); margin-left:8px; }
    .mg-tiles { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:14px; }
    .mg-tile { padding:16px; }
    .mg-lbl { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:var(--ink-2); font-weight:600; }
    .mg-num { font-family:var(--font-num); font-variant-numeric:tabular-nums; font-size:24px; font-weight:600; margin:6px 0 2px; }
    .mg-sub, .mg-dim { color:var(--ink-2); font-size:12px; }
    .mg-dim { margin-left:4px; }
    .mg-neg { color:#c5221f; }
    .mg-cover { margin-top:14px; }
    .mg-grid { display:grid; grid-template-columns:minmax(0,1.3fr) minmax(0,1fr); gap:18px; align-items:start; }
    @media (max-width:980px) { .mg-grid { grid-template-columns:minmax(0,1fr); } }
    .mg-t { margin:0 0 10px; font-size:13px; text-transform:uppercase; letter-spacing:.05em; color:var(--ink-2); }
    .mg-table td:not(:first-child), .mg-table th:not(:first-child) { text-align:right; font-family:var(--font-num); font-variant-numeric:tabular-nums; white-space:nowrap; }
    .mg-scroll { max-height:360px; overflow:auto; }
    .mg-scroll.mg-tall { max-height:560px; }
</style>
<?php endif; ?>

<?php
// Kafelki rozszerzeń (filtr statistics.cards): ['title' => ..., 'html' => ...] albo 'render' => fn(array $ctx, PDO $pdo): string.
// $ctx to bieżące filtry strony, żeby kafelek liczył ten sam zakres co reszta statystyk.
$statsCtx = ['from' => $dateFrom, 'to' => $dateTo, 'source' => $fSource, 'status' => $fStatus, 'currency' => $fCurrency];
$extCards = \Pase\Plugin\Hooks::applyFilters('statistics.cards', [], $statsCtx, $pdo);
$extCardsHtml = [];
foreach ($extCards as $i => $card) {
    if (!is_array($card)) { continue; }
    $html = isset($card['render']) && is_callable($card['render'])
        ? \Pase\Plugin\Hooks::safe('statistics.cards:' . $i, $card['render'], $statsCtx, $pdo) : ($card['html'] ?? null);
    if (is_string($html) && $html !== '') {
        $extCardsHtml[] = '<div class="card"><h3 class="mg-t">' . htmlspecialchars((string) ($card['title'] ?? '')) . '</h3>' . $html . '</div>';
    }
}
?>
<?php if ($extCardsHtml): ?>
<div class="stats-ext-grid"><?= implode('', $extCardsHtml) ?></div>
<style>
    .stats-ext-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:18px; margin-top:18px; }
    .stats-ext-grid .mg-t { margin:0 0 10px; font-size:13px; text-transform:uppercase; letter-spacing:.05em; color:var(--ink-2); }
</style>
<?php endif; ?>

<style>
    .stats-fgrid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; }
    .stats-field label { display:block; font-size:11px; color:var(--ink-2); margin-bottom:5px; font-weight:600; text-transform:uppercase; letter-spacing:.03em; }
    .stats-field select, .stats-field input { width:100%; }
    .stats-chart-legend { display:flex; gap:22px; margin-bottom:10px; font-size:13px; color:var(--ink-2); }
    .stats-chart-legend .dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:7px; vertical-align:middle; }
    .stats-summary-grid { display:grid; grid-template-columns:160px 200px minmax(240px,1fr); gap:18px; align-items:start; }
    .stats-tile { text-align:center; padding:20px 14px; }
    .stats-tile-num { font-family:var(--font-num); font-variant-numeric:tabular-nums; font-size:32px; font-weight:600; color:var(--ink); }
    .stats-tile-lbl { color:var(--ink-2); font-size:12.5px; margin-top:4px; }
    .stats-summary h3 { margin:0 0 10px; font-size:13px; text-transform:uppercase; letter-spacing:.05em; color:var(--ink-2); }
    .stats-summary-row { display:flex; align-items:center; justify-content:space-between; padding:7px 0; border-bottom:1px solid var(--line); font-size:14px; }
    .stats-summary-row:last-child { border-bottom:0; }
    .stats-summary-row b { font-family:var(--font-num); font-variant-numeric:tabular-nums; font-weight:600; }
    @media (max-width:820px) { .stats-summary-grid { grid-template-columns:1fr; } }
</style>

<?php require __DIR__ . '/footer.php'; ?>
