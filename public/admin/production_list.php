<?php
declare(strict_types=1);

/**
 * Lista produkcji do druku (A4): produkty z zaznaczonych zamówień zsumowane po produkcie i wariancie,
 * z kratką do odhaczenia, parametrami (zapach, knot, rozmiar...) i numerami zamówień.
 * Otwierana z listy zamówień: zaznaczone → „Lista produkcji” (?ids=1,2,3). Dane: Pase\Services\ProductionList.
 */

use Pase\Services\ProductionList;

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

$rawIds = $_GET['ids'] ?? [];
$ids = array_values(array_unique(array_filter(array_map('intval',
    is_array($rawIds) ? $rawIds : explode(',', (string) $rawIds)), static fn(int $i) => $i > 0)));
$ids = array_slice($ids, 0, 500);

$list = (new ProductionList($pdo))->build($ids);

// ?pdf=1 - ta sama lista jako plik PDF. Aplikacja na iPhonie (ekran początkowy) nie drukuje stron (window.print()
// nic nie robi), więc tam „Drukuj” udostępnia ten plik (navigator.share) - iOS pokazuje arkusz z „Drukuj”.
if (($_GET['pdf'] ?? '') === '1') {
    $pdfData = (new \Pase\Services\ProductionListPdf())->render($list, ($_GET['nr'] ?? '') !== '0');
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="lista-produkcji-' . date('Y-m-d') . '.pdf"');
    header('Content-Length: ' . strlen($pdfData));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $pdfData;
    return;
}
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

$pdfUrl = $list['rows'] !== [] ? 'production_list.php?' . http_build_query(['ids' => implode(',', $ids), 'pdf' => 1]) : '';

$nonce = base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; form-action 'none'; connect-src 'self'; frame-ancestors 'self'");
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
    .toolbar button.primary, .toolbar a.primary { background: #111; color: #fff; border-color: #111; }
    .toolbar .hint { font-size: 13px; color: #555; flex-basis: 100%; text-align: right; }
    .toolbar button:disabled { opacity: .5; }
    [hidden] { display: none !important; }
    .toolbar .sp { flex: 1; }
    .toolbar label { font-size: 13px; color: #555; display: inline-flex; align-items: center; gap: 6px; }
    header.top { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; border-bottom: 2px solid #111; padding-bottom: 6px; margin-bottom: 10px; }
    h1 { font-size: 20px; margin: 0; letter-spacing: -.01em; }
    .meta { font-size: 12px; color: #444; text-align: right; }
    h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .04em; margin: 18px 0 6px; color: #333; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #555; font-weight: 600; padding: 3px 5px; border-bottom: 1px solid #111; }
    td { padding: 4px 5px; border-bottom: 1px solid #d9d6cf; vertical-align: top; }
    tr { break-inside: avoid; page-break-inside: avoid; }
    thead { display: table-header-group; }
    .box { width: 13px; height: 13px; border: 1.3px solid #111; border-radius: 2px; margin-top: 2px; }
    td.chk, th.chk { width: 20px; padding-right: 0; }
    td.qty { width: 34px; font-size: 15px; line-height: 1.1; font-weight: 700; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    th.qty { text-align: right; }
    .name { font-weight: 600; font-size: 12.5px; line-height: 1.25; }
    .params { font-size: 11px; line-height: 1.3; color: #333; margin-top: 1px; }
    .params b { font-weight: 600; color: #111; }
    .sku { font-weight: 400; color: #777; font-size: 10px; margin-left: 6px; }
    td.cap { width: 58px; font-size: 12.5px; font-weight: 700; white-space: nowrap; }
    th.cap { width: 58px; }
    td.ord { width: 21%; font-size: 10px; line-height: 1.3; color: #444; }
    tfoot td { border-bottom: 0; font-weight: 700; }
    .small { font-size: 12px; color: #444; }
    .materials td, .notes td { font-size: 12px; padding: 3px 5px; }
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
    <span class="hint" id="printHint" hidden></span>
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
        <thead><tr><th class="chk"></th><th class="qty">Szt.</th><th>Produkt</th><th class="cap">Waga</th><th class="ord">Zamówienia</th></tr></thead>
        <tbody>
        <?php foreach ($list['rows'] as $r): ?>
            <?php
                // Jedna linia parametrów: wariant (knot, zapach), szczegóły z rozszerzeń (nr zapachu, receptura), SKU na końcu.
                $params = array_merge(
                    array_map(static fn(array $a) => $h($a[0]) . ': <b>' . $h($a[1]) . '</b>', $r['attrs']),
                    array_map(static fn(array $d) => $h($d['label']) . ': <b>' . $h($d['value']) . '</b>'
                        . ($d['total'] !== null ? ' (' . $h(ProductionList::amount($d['total'], $d['unit'])) . ')' : ''), $r['details'])
                );
            ?>
            <tr>
                <td class="chk"><div class="box"></div></td>
                <td class="qty"><?= (int) $r['qty'] ?></td>
                <td>
                    <div class="name"><?= $h($r['name']) ?></div>
                    <div class="params"><?= implode(' · ', $params) ?><?php if ($r['sku'] !== ''): ?><span class="sku"><?= $h($r['sku']) ?></span><?php endif; ?></div>
                </td>
                <td class="cap"><?= $r['weight'] !== '' ? $h($r['weight']) : '—' ?></td>
                <td class="ord"><?= implode(', ', array_map(static fn($label, $q) => $h($label) . ($q > 1 ? ' ×' . (int) $q : ''),
                    array_keys($r['orders']), $r['orders'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td></td><td class="qty"><?= (int) $list['total'] ?></td><td>Razem sztuk</td><td class="cap"></td><td class="ord"></td></tr></tfoot>
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
    var btn = document.getElementById('printBtn'), so = document.getElementById('showOrders'), hint = document.getElementById('printHint');
    var pdfUrl = <?= json_encode($pdfUrl) ?>;
    // iPhone/iPad, aplikacja dodana do ekranu początkowego: window.print() nic nie robi. Tam „Drukuj” udostępnia
    // plik PDF tej listy - iOS pokazuje arkusz z „Drukuj”. Plik pobieramy zawczasu: udostępnić trzeba od razu po kliknięciu.
    var ios = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var app = navigator.standalone === true || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
    var viaPdf = !!(pdfUrl && ios && app && navigator.share);
    var file = null, gen = 0;
    function say(t) { hint.textContent = t; hint.hidden = !t; }
    function loadPdf() {
        var my = ++gen;
        file = null; btn.disabled = true; say('Przygotowuję plik do druku…');
        fetch(pdfUrl + (so.checked ? '' : '&nr=0'), { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.blob(); })
            .then(function (b) {
                if (my !== gen) { return; }
                file = new File([b], 'lista-produkcji.pdf', { type: 'application/pdf' });
                btn.disabled = false; say('');
            })
            .catch(function () { if (my === gen) { btn.disabled = false; say('Nie udało się przygotować pliku. Odśwież stronę.'); } });
    }
    btn.addEventListener('click', function () {
        if (!viaPdf) { window.print(); return; }
        if (!file) { return; }
        var data = { files: [file], title: 'Lista produkcji' };
        if (navigator.canShare && !navigator.canShare(data)) { say('Ten iPhone nie udostępnia plików z aplikacji. Otwórz CRM w Safari i drukuj stamtąd.'); return; }
        navigator.share(data).catch(function (e) { if (!e || e.name !== 'AbortError') { say('Nie udało się otworzyć drukowania: ' + (e && e.message ? e.message : 'błąd')); } });
    });
    so.addEventListener('change', function () {
        document.body.classList.toggle('no-orders', !so.checked);
        if (viaPdf) { loadPdf(); }
    });
    if (viaPdf) { loadPdf(); }
</script>
</body>
</html>
