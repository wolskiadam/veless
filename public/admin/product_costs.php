<?php
declare(strict_types=1);

/**
 * Magazyn → Koszty zakupu: koszt netto każdego produktu (do marży w Statystykach).
 * Edycja w tabeli, import i eksport CSV (SKU;koszt), lista sprzedanych produktów bez kosztu.
 * Koszt trafia na pozycje zamówień w chwili ich przyjścia — patrz Services\OrderMargins.
 */

use Pase\Services\OrderMargins;

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

const COSTS_PER_PAGE = 50;

// Koszty są poufne: także bez szczegółowych uprawnień konta rola „podgląd” ich nie widzi (jak w Statystykach i w karcie produktu).
if (!canViewPage('product_costs')) {
    pageAccessDenied('Brak uprawnień', 'Koszty zakupu widzi administrator i edytor. Jeśli potrzebujesz dostępu, poproś administratora.');
}

$svc = new OrderMargins($pdo);
$canEdit = canEdit();

if (($_GET['export'] ?? '') !== '') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="koszty-zakupu-' . date('Y-m-d') . '.csv"');
    echo $svc->exportCsv($_GET['export'] === 'missing');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    csrfCheck();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save') {
        $costs = []; $bad = [];
        $old = (array) ($_POST['old'] ?? []);
        foreach ((array) ($_POST['cost'] ?? []) as $pid => $raw) {
            if ((string) $raw === (string) ($old[$pid] ?? '')) {
                continue;
            }
            $c = OrderMargins::parseCost($raw);
            if ($c === false) {
                $bad[] = (string) $raw;
                continue;
            }
            $costs[(int) $pid] = $c;
        }
        $n = $svc->setCosts($costs);
        flash("Zapisano koszt zakupu: {$n} produktów." . ($bad ? ' Pominięte (zła kwota): ' . implode(', ', array_slice($bad, 0, 10)) . '.' : ''), $bad ? 'err' : 'ok');
    } elseif ($action === 'import') {
        $content = (string) ($_POST['csv'] ?? '');
        if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            $content .= "\n" . (string) file_get_contents($_FILES['csv_file']['tmp_name']);
        }
        $r = $svc->importCsv($content);
        $msg = "Zaktualizowano koszt {$r['updated']} produktów" . ($r['unchanged'] ? ", bez zmian: {$r['unchanged']}" : '') . '.';
        if ($r['unknown']) { $msg .= ' Nie znaleziono SKU: ' . implode(', ', array_slice($r['unknown'], 0, 15)) . (count($r['unknown']) > 15 ? '…' : '') . '.'; }
        if ($r['invalid']) { $msg .= ' Zła kwota: ' . implode('; ', array_slice($r['invalid'], 0, 5)) . (count($r['invalid']) > 5 ? '…' : '') . '.'; }
        flash($msg, $r['unknown'] || $r['invalid'] ? 'err' : 'ok');
    } elseif ($action === 'rebuild') {
        $n = $svc->rebuildAll();
        flash("Przeliczono marżę {$n} zamówień z bieżącymi kosztami zakupu.");
    }
    redirectAfterPost();
}

$svc->syncPending(1000);

$q = trim((string) ($_GET['q'] ?? ''));
$only = ($_GET['only'] ?? '') === 'missing' ? 'missing' : '';
$page = max(1, (int) ($_GET['p'] ?? 1));
$where = []; $args = [];
if ($q !== '') {
    $where[] = '(sku LIKE ? OR name LIKE ?)';
    $args[] = "%{$q}%";
    $args[] = "%{$q}%";
}
if ($only === 'missing') {
    $where[] = 'purchase_cost IS NULL';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$cnt = $pdo->prepare("SELECT COUNT(*) FROM products $whereSql");
$cnt->execute($args);
$total = (int) $cnt->fetchColumn();
$pages = max(1, (int) ceil($total / COSTS_PER_PAGE));
$page = min($page, $pages);
$stmt = $pdo->prepare("SELECT id, sku, name, pase_price, purchase_cost, purchase_cost_at FROM products $whereSql ORDER BY name, sku LIMIT "
    . COSTS_PER_PAGE . ' OFFSET ' . (($page - 1) * COSTS_PER_PAGE));
$stmt->execute($args);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cov = $svc->coverage();
$since = date('Y-m-d', strtotime('-90 days'));
$missingSold = $svc->soldWithoutCost($since . ' 00:00:00', 15);
$vatNote = $svc->defaultVat();
$vatPayer = $svc->vatPayer();
$nt = $vatPayer ? ' netto' : '';

$qs = $_GET;
$link = static function (int $p) use ($qs): string { $qs['p'] = $p; return 'product_costs.php?' . http_build_query($qs); };
$fmt = static fn(float $v): string => number_format($v, 2, ',', ' ');

$PAGE_TITLE = 'Koszty zakupu';
$PAGE_KEY   = 'product_costs';
require __DIR__ . '/header.php';
?>
<style>
    .pc-grid { display:grid; grid-template-columns:minmax(0,1.5fr) minmax(0,1fr); gap:18px; align-items:start; }
    @media (max-width: 980px) { .pc-grid { grid-template-columns:1fr; } }
    .pc-cost { width:110px; text-align:right; font-family:var(--font-num); }
    .pc-num { font-family:var(--font-num); font-variant-numeric:tabular-nums; text-align:right; white-space:nowrap; }
    .pc-bar { height:8px; background:var(--line); border-radius:99px; overflow:hidden; margin:8px 0 4px; }
    .pc-bar i { display:block; height:100%; background:var(--accent); }
    .pc-muted { color:var(--ink-2); font-size:12.5px; }
</style>

<div class="pc-grid">
<div class="card">
    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
        <input name="q" placeholder="Szukaj: SKU / nazwa" value="<?= htmlspecialchars($q) ?>" style="width:260px">
        <select name="only" onchange="this.form.submit()">
            <option value="">Wszystkie produkty</option>
            <option value="missing" <?= $only === 'missing' ? 'selected' : '' ?>>Tylko bez kosztu</option>
        </select>
        <button class="btn secondary" type="submit">Szukaj</button>
        <span class="pc-muted">Znaleziono: <b><?= $total ?></b></span>
    </form>

    <?php if ($canEdit && $rows !== []): ?>
    <form method="post" id="costForm">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="save">
    </form>
    <?php endif; ?>
    <table>
        <tr><th>SKU</th><th>Nazwa</th><th class="pc-num">Cena sprzedaży</th><th class="pc-num">Koszt zakupu<?= $nt ?></th><th class="pc-num" title="Orientacyjnie: cena z CRM<?= $vatPayer ? ' bez VAT' : '' ?> minus koszt">Marża</th></tr>
        <?php if ($rows === []): ?>
            <tr><td colspan="5" class="pc-muted">Brak produktów.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r):
            $pid = (int) $r['id'];
            $cost = $r['purchase_cost'] === null ? null : (float) $r['purchase_cost'];
            $price = $r['pase_price'] === null ? null : (float) $r['pase_price'];
            // Marża orientacyjna: cena z CRM (brutto) na netto domyślną stawką VAT.
            $net = $price !== null ? $price / (1 + $vatNote / 100) : null;
            $markup = $cost !== null && $net !== null && $net > 0 ? ($net - $cost) / $net * 100 : null;
            $costStr = $cost === null ? '' : number_format($cost, 2, ',', '');
        ?>
            <tr>
                <td><a href="product_view.php?id=<?= $pid ?>"><strong><?= htmlspecialchars((string) $r['sku']) ?></strong></a></td>
                <td><?= htmlspecialchars((string) ($r['name'] ?? '—')) ?></td>
                <td class="pc-num"><?= $price !== null ? $fmt($price) . ' zł' : '—' ?></td>
                <td class="pc-num">
                    <?php if ($canEdit): ?>
                        <input type="hidden" name="old[<?= $pid ?>]" form="costForm" value="<?= htmlspecialchars($costStr) ?>">
                        <input class="pc-cost" name="cost[<?= $pid ?>]" form="costForm" inputmode="decimal" value="<?= htmlspecialchars($costStr) ?>" placeholder="brak"> zł
                    <?php else: ?>
                        <?= $cost !== null ? $fmt($cost) . ' zł' : '<span class="pill warn">brak</span>' ?>
                    <?php endif; ?>
                </td>
                <td class="pc-num"><?= $markup !== null ? number_format($markup, 0, ',', ' ') . '%' : '<span class="pc-muted">—</span>' ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php $pagerPage = $page; $pagerPages = $pages; $pagerLink = $link; require __DIR__ . '/_pager.php'; ?>
    <?php if ($canEdit && $rows !== []): ?>
        <p style="margin-top:12px"><button class="btn" type="submit" form="costForm">💾 Zapisz koszty</button>
        <span class="pc-muted">Puste pole = brak kosztu (produkt nie wchodzi do marży). Marża w tabeli liczona od ceny z CRM<?= $vatPayer ? ' bez ' . (int) $vatNote . '% VAT' : '' ?>.</span></p>
    <?php endif; ?>
</div>

<div>
    <div class="card">
        <strong>Uzupełnienie kosztów</strong>
        <?php $pct = $cov['total'] > 0 ? $cov['with_cost'] / $cov['total'] * 100 : 0; ?>
        <div class="pc-bar"><i style="width:<?= round($pct, 1) ?>%"></i></div>
        <div class="pc-muted"><?= $cov['with_cost'] ?> z <?= $cov['total'] ?> produktów ma koszt zakupu (<?= number_format($pct, 0) ?>%).</div>
        <?php if ($missingSold !== []): ?>
            <p style="margin:14px 0 6px"><strong>Sprzedane bez kosztu (90 dni)</strong><br><span class="pc-muted">Tych pozycji Statystyki nie liczą do marży. Od największej sprzedaży<?= $nt ?>.</span></p>
            <table>
                <?php foreach ($missingSold as $m): ?>
                    <tr>
                        <td><?php if ($m['product_id']): ?><a href="product_view.php?id=<?= (int) $m['product_id'] ?>"><?= htmlspecialchars((string) ($m['sku'] ?: $m['name'])) ?></a><?php else: ?><?= htmlspecialchars((string) ($m['sku'] ?: $m['name'])) ?> <span class="pill muted" title="Pozycja zamówienia nie jest powiązana z produktem w magazynie">spoza magazynu</span><?php endif; ?></td>
                        <td class="pc-num"><?= $m['qty'] ?> szt.</td>
                        <td class="pc-num"><?= $fmt($m['revenue']) ?> zł</td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="pc-muted" style="margin-top:12px">Wszystko, co sprzedało się w ostatnich 90 dniach, ma koszt zakupu.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <strong>Import i eksport CSV</strong>
        <p class="pc-muted">Kolumny: <code>SKU;koszt</code> (średnik, przecinek albo tabulator; nagłówek opcjonalny, np. taki jak w eksporcie). Pusty koszt w pliku nie kasuje kosztu w CRM.</p>
        <p><a class="btn secondary" href="product_costs.php?export=all">⬇ Eksport wszystkich</a>
           <a class="btn secondary" href="product_costs.php?export=missing">⬇ Tylko bez kosztu</a></p>
        <?php if ($canEdit): ?>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="import">
            <textarea name="csv" rows="5" style="width:100%;font-family:var(--font-num)" placeholder="SWIECA-01;12,50&#10;SWIECA-02;8,90"></textarea>
            <p style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><input type="file" name="csv_file" accept=".csv,.txt,text/csv"> <button class="btn" type="submit">Importuj</button></p>
        </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <strong>Jak liczymy marżę</strong>
        <ul class="pc-muted" style="padding-left:18px;margin:8px 0 0">
            <li>Koszt z chwili przyjścia zamówienia zostaje na jego pozycjach — zmiana kosztu dziś nie zmienia marży z przeszłości.</li>
            <li>Pozycja bez kosztu dostaje go, gdy go wpiszesz, i dopiero wtedy wchodzi do marży.</li>
            <?php if ($vatPayer): ?>
            <li>Przychód liczymy netto (bez dostawy); ceny bez VAT w zamówieniu dzielimy przez <?= (int) $vatNote ?>% (stawka domyślna z wFirma).</li>
            <li>Od zamówień z Allegro odejmujemy prowizję i opłaty za promowanie przypisane do zamówienia (netto).</li>
            <?php else: ?>
            <li>Firma bez VAT (Konfiguracja → Firma): przychód to kwota zapłacona przez klienta (bez dostawy), a koszt zakupu wpisujesz taki, jaki faktycznie płacisz.</li>
            <li>Od zamówień z Allegro odejmujemy prowizję i opłaty za promowanie przypisane do zamówienia (kwoty z faktury Allegro).</li>
            <?php endif; ?>
        </ul>
        <?php if ($canEdit): ?>
        <form method="post" style="margin-top:12px" onsubmit="return confirm('Przeliczyć marżę WSZYSTKICH zamówień z bieżącymi kosztami? Koszty z chwili zamówienia zostaną nadpisane.')">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="rebuild">
            <button class="btn secondary" type="submit">Przelicz wszystkie zamówienia bieżącymi kosztami</button>
        </form>
        <?php endif; ?>
    </div>
</div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
