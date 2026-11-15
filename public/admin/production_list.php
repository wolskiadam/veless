<?php
declare(strict_types=1);

/**
 * Lista produkcji do druku (A4): produkty z zaznaczonych zamówień zsumowane po produkcie i wariancie,
 * z kratką do odhaczenia, parametrami (zapach, knot, rozmiar...) i numerami zamówień.
 * Otwierana z listy zamówień: zaznaczone → „Lista produkcji” (?ids=1,2,3). Dane: Pase\Services\ProductionList.
 */

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

use Pase\Services\ProductionList;

$rawIds = $_GET['ids'] ?? [];
$ids = array_values(array_unique(array_filter(array_map('intval',
    is_array($rawIds) ? $rawIds : explode(',', (string) $rawIds)), static fn(int $i) => $i > 0)));
$ids = array_slice($ids, 0, 500);

$list = (new ProductionList($pdo))->build($ids);
$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$plural = static function (int $n, string $one, string $few, string $many): string {
    if ($n === 1) { return $one; }
    return ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14)) ? $few : $many;
};
$nOrders = count($list['orders']);
$notes = array_values(array_filter($list['orders'], static fn(array $o) => $o['note'] !== ''));

$back = 'index.php';
$ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
if ($ref !== '' && parse_url($ref, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? '')
    && preg_match('#/admin/index\.php#', (string) parse_url($ref, PHP_URL_PATH))) {
    $back = $ref;
}

$nonce = base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lista produkcji · <?= $h(date('d.m.Y')) ?></title>
<style>
    @page { size: A4; margin: 12mm 12mm 14mm; }
    * { box-sizing: border-box; }
    body { margin: 0; background: #f2f1ee; color: #111; font: 13px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; }
    .sheet { max-width: 210mm; margin: 0 auto 24px; background: #fff; padding: 12mm; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
    .toolbar { max-width: 210mm; margin: 0 auto; display: flex; gap: 8px; align-items: center; padding: 12px 16px; }
    .toolbar a, .toolbar button { font: inherit; font-size: 14px; padding: 8px 14px; border-radius: 8px; border: 1px solid #d6d3cc; background: #fff; color: #111; text-decoration: none; cursor: pointer; }
    .toolbar button.primary { background: #111; color: #fff; border-color: #111; }
    .toolbar .sp { flex: 1; }
    .toolbar label { font-size: 13px; color: #555; display: inline-flex; align-items: center; gap: 6px; }
    header.top { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; border-bottom: 2px solid #111; padding-bottom: 6px; margin-bottom: 10px; }
    h1 { font-size: 20px; margin: 0; letter-spacing: -.01em; }
    .meta { font-size: 12px; color: #444; text-align: right; }
    h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .04em; margin: 18px 0 6px; color: #333; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #555; font-weight: 600; padding: 4px 6px; border-bottom: 1px solid #111; }
    td { padding: 7px 6px; border-bottom: 1px solid #cfccc5; vertical-align: top; }
    tr { break-inside: avoid; page-break-inside: avoid; }
    thead { display: table-header-group; }
    .box { width: 16px; height: 16px; border: 1.5px solid #111; border-radius: 2px; margin-top: 2px; }
    td.chk, th.chk { width: 26px; }
    td.qty { width: 54px; font-size: 18px; font-weight: 700; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    th.qty { text-align: right; }
    .name { font-weight: 600; font-size: 14px; }
    .sku { font-weight: 400; color: #666; font-size: 11px; margin-left: 6px; }
    .attrs { margin-top: 2px; }
    .attrs b, .details b { font-weight: 600; }
    .details { margin-top: 2px; font-size: 12px; color: #333; }
    td.ord { width: 30%; font-size: 11px; color: #444; }
    tfoot td { border-bottom: 0; font-weight: 700; }
    .small { font-size: 12px; color: #444; }
    .notes td { font-size: 12px; }
    .empty { padding: 40px 0; text-align: center; color: #555; }
    body.no-orders td.ord, body.no-orders th.ord { display: none; }
    @media (max-width: 640px) {
        .sheet { padding: 16px; margin: 0 0 16px; }
        td.ord { display: none; } th.ord { display: none; }
        .toolbar { flex-wrap: wrap; }
    }
    @media print {
        body { background: #fff; }
        .toolbar { display: none; }
        .sheet { box-shadow: none; padding: 0; margin: 0; max-width: none; }
    }
</style>
</head>
<body>
<div class="toolbar">
    <a href="<?= $h($back) ?>">← Wróć</a>
    <span class="sp"></span>
    <label><input type="checkbox" id="showOrders" checked> Numery zamówień</label>
    <button type="button" class="primary" id="printBtn">Drukuj</button>
</div>
<main class="sheet">
    <header class="top">
        <h1>Lista produkcji</h1>
        <div class="meta">
            <?= $h(date('d.m.Y H:i')) ?><br>
            <?= $nOrders ?> <?= $plural($nOrders, 'zamówienie', 'zamówienia', 'zamówień') ?> ·
            <?= (int) $list['total'] ?> szt. · <?= count($list['rows']) ?> <?= $plural(count($list['rows']), 'pozycja', 'pozycje', 'pozycji') ?>
        </div>
    </header>

    <?php if ($list['rows'] === []): ?>
        <p class="empty">Brak produktów do zrobienia. Zaznacz zamówienia na liście i wybierz „Lista produkcji”.</p>
    <?php else: ?>
    <table class="items">
        <thead><tr><th class="chk"></th><th class="qty">Szt.</th><th>Produkt</th><th class="ord">Zamówienia</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $r): ?>
            <tr>
                <td class="chk"><div class="box"></div></td>
                <td class="qty"><?= (int) $r['qty'] ?></td>
                <td>
                    <div class="name"><?= $h($r['name']) ?><?php if ($r['sku'] !== ''): ?><span class="sku"><?= $h($r['sku']) ?></span><?php endif; ?></div>
                    <?php if ($r['attrs'] !== []): ?>
                        <div class="attrs"><?= implode(' · ', array_map(static fn(array $a) => $h($a[0]) . ': <b>' . $h($a[1]) . '</b>', $r['attrs'])) ?></div>
                    <?php endif; ?>
                    <?php if ($r['details'] !== []): ?>
                        <div class="details"><?= implode(' · ', array_map(static fn(array $d) => $h($d['label']) . ': <b>' . $h($d['value']) . '</b>'
                            . ($d['total'] !== null ? ' (' . $h(ProductionList::amount($d['total'], $d['unit'])) . ')' : ''), $r['details'])) ?></div>
                    <?php endif; ?>
                </td>
                <td class="ord"><?= implode(', ', array_map(static fn($label, $q) => $h($label) . ($q > 1 ? ' ×' . (int) $q : ''),
                    array_keys($r['orders']), $r['orders'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td></td><td class="qty"><?= (int) $list['total'] ?></td><td>Razem sztuk</td><td class="ord"></td></tr></tfoot>
    </table>

    <?php if ($list['materials'] !== []): ?>
        <h2>Materiały razem</h2>
        <table class="materials">
            <tbody>
            <?php foreach ($list['materials'] as $m): ?>
                <tr>
                    <td class="chk"><div class="box"></div></td>
                    <td><?= $h($m['label']) ?>: <b><?= $h($m['value']) ?></b></td>
                    <td style="text-align:right;white-space:nowrap;font-weight:600"><?= $h(ProductionList::amount($m['total'], $m['unit'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($notes !== []): ?>
        <h2>Uwagi klientów</h2>
        <table class="notes">
            <tbody>
            <?php foreach ($notes as $o): ?>
                <tr><td style="width:90px;white-space:nowrap"><b><?= $h($o['label']) ?></b></td><td style="width:30%"><?= $h($o['customer']) ?></td><td><?= nl2br($h($o['note'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2>Zamówienia na liście</h2>
    <p class="small"><?= implode(', ', array_map(static fn(array $o) => '<b>' . $h($o['label']) . '</b> ' . $h($o['customer']), $list['orders'])) ?></p>
    <?php endif; ?>
</main>
<script nonce="<?= $nonce ?>">
    document.getElementById('printBtn').addEventListener('click', function () { window.print(); });
    var so = document.getElementById('showOrders');
    so.addEventListener('change', function () { document.body.classList.toggle('no-orders', !so.checked); });
</script>
</body>
</html>
