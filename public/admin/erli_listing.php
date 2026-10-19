<?php
declare(strict_types=1);

/**
 * Marketplace → Erli → Wystawianie: produkty z magazynu wystawiane jako oferty ERLI.
 *  - oferta = nazwa, opis z szablonu (albo opis produktu), zdjęcia, cena z magazynu × mnożnik, stan, EAN, waga,
 *    kategoria i parametry wg Kategorii i parametrów, cennik wysyłki (Services\ErliOffers::buildOffer),
 *  - „Podgląd” pokazuje ofertę przed wysłaniem z brakami i ostrzeżeniami,
 *  - ponowne wystawienie produktu, który jest już w ERLI, aktualizuje ofertę (dane z magazynu nadpisują ERLI).
 */

use Pase\Services\Erli;
use Pase\Services\ErliOffers;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$PAGE_TITLE = 'Erli - wystawianie';
$PAGE_KEY   = 'erli';
require __DIR__ . '/_erli_page.php';
/** @var array $erliAcc @var array $erliAccounts @var ErliOffers $erliOffers */
$accId = (int) $erliAcc['id'];
const ERLI_PUBLISH_LIMIT = 50;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
    if (!canEdit()) {
        flash('Brak uprawnień do zmian.', 'err');
    } elseif ($ids === []) {
        flash('Zaznacz produkty do wystawienia.', 'err');
    } else {
        if (count($ids) > ERLI_PUBLISH_LIMIT) {
            flash('Naraz można wystawić ' . ERLI_PUBLISH_LIMIT . ' produktów - wysłano pierwsze ' . ERLI_PUBLISH_LIMIT . '.', 'err');
            $ids = array_slice($ids, 0, ERLI_PUBLISH_LIMIT);
        }
        try {
            $r = $erliOffers->publish($erliAcc, $ids);
            $parts = [];
            if ($r['listed']) { $parts[] = 'wystawiono: ' . $r['listed']; }
            if ($r['updated']) { $parts[] = 'zaktualizowano: ' . $r['updated']; }
            if ($r['failed']) { $parts[] = 'nie udało się: ' . $r['failed']; }
            flash('ERLI - ' . implode(', ', $parts) . '. ERLI publikuje oferty w ciągu kilku minut.', $r['failed'] && !$r['listed'] && !$r['updated'] ? 'err' : 'ok');
            foreach (array_slice($r['messages'], 0, 10) as $m) {
                flash($m, 'err');
            }
        } catch (\Throwable $e) {
            flash($e->getMessage(), 'err');
        }
    }
    redirectAfterPost();
}

$e = static fn($v): string => htmlspecialchars((string) $v);
$zl = static fn(?int $gr): string => $gr === null ? '—' : number_format($gr / 100, 2, ',', ' ') . ' zł';

// --- podgląd jednej oferty ---
$previewId = (int) ($_GET['preview'] ?? 0);
if ($previewId > 0) {
    $st = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $st->execute([$previewId]);
    $product = $st->fetch(PDO::FETCH_ASSOC);
    $offer = $product ? $erliOffers->buildOffer($erliAcc, $product) : null;
    require __DIR__ . '/header.php';
    $p = $offer['payload'] ?? [];
    $crumb = $p['externalCategories'][0]['breadcrumb'] ?? [];
    ?>
    <p style="margin:-6px 0 16px"><a class="btn secondary" href="erli_listing.php?acc=<?= $accId ?>">← Wystawianie</a></p>
    <?php if (!$offer): ?>
        <div class="card"><p>Nie ma takiego produktu.</p></div>
    <?php else: ?>
        <?php foreach ($offer['errors'] as $m): ?><div class="flash err"><?= $e($m) ?></div><?php endforeach; ?>
        <?php foreach ($offer['warnings'] as $m): ?><div class="flash" style="background:var(--warn-bg);color:var(--warn-ink)"><?= $e($m) ?></div><?php endforeach; ?>
        <div class="card">
            <div style="display:flex;gap:16px;flex-wrap:wrap">
                <?php if (!empty($p['images'][0]['url'])): ?><img src="<?= $e($p['images'][0]['url']) ?>" alt="" style="width:140px;height:140px;object-fit:cover;border-radius:8px;border:1px solid var(--line)"><?php endif; ?>
                <div style="flex:1;min-width:260px">
                    <h2 style="margin:0 0 6px;font-size:18px"><?= $e($p['name'] ?? '') ?></h2>
                    <table>
                        <tr><td>SKU (ID oferty w ERLI)</td><td><strong><?= $e($offer['external_id']) ?></strong></td></tr>
                        <tr><td>Cena</td><td><strong><?= $zl(isset($p['price']) ? (int) $p['price'] : null) ?></strong></td></tr>
                        <tr><td>Stan</td><td><?= (int) ($p['stock'] ?? 0) ?> szt.</td></tr>
                        <tr><td>Kategoria ERLI</td><td><?= $crumb ? $e(implode(' > ', array_column($crumb, 'name'))) : '<span style="color:var(--ink-3)">nie przypisano</span>' ?></td></tr>
                        <tr><td>Cennik wysyłki</td><td><?= $e(match ((string) ($p['deliveryPriceList'] ?? '')) { '' => 'domyślny sklepu w ERLI', '*' => 'Domyślny cennik ERLI (*)', default => $p['deliveryPriceList'] }) ?></td></tr>
                        <tr><td>Czas wysyłki</td><td><?= (int) ($p['dispatchTime']['period'] ?? 0) ?> dni rob.</td></tr>
                        <tr><td>VAT</td><td><?= $e(str_replace('TAX_', '', (string) ($p['taxRate'] ?? ''))) ?></td></tr>
                        <?php if (isset($p['ean'])): ?><tr><td>EAN</td><td><?= $e($p['ean']) ?></td></tr><?php endif; ?>
                        <?php if (isset($p['weight'])): ?><tr><td>Waga</td><td><?= (int) $p['weight'] ?> g</td></tr><?php endif; ?>
                        <tr><td>Zdjęcia</td><td><?= count($p['images'] ?? []) ?></td></tr>
                    </table>
                </div>
            </div>
        </div>
        <?php if (!empty($p['externalAttributes'])): ?>
            <div class="card"><strong>Parametry</strong>
                <table style="margin-top:8px">
                    <?php try { $defs = $crumb ? $erliOffers->attributes($erliAcc, (int) end($crumb)['id']) : []; } catch (\Throwable) { $defs = []; } ?>
                    <?php foreach ($p['externalAttributes'] as $a): $def = $defs[(int) $a['id']] ?? []; ?>
                        <tr><td><?= $e($a['name']) ?></td><td><?= $e(implode(', ', array_map(static fn($v) => (string) ($def['values'][(string) $v] ?? $v), $a['values']))) ?><?= isset($a['unit']) ? ' ' . $e($a['unit']) : '' ?></td></tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>
        <div class="card"><strong>Opis</strong>
            <div style="margin-top:8px;line-height:1.6">
                <?php foreach (($p['description']['sections'] ?? []) as $sec): foreach ($sec['items'] as $it): ?>
                    <?php if ($it['type'] === 'IMAGE'): ?><img src="<?= $e($it['url']) ?>" alt="" style="max-width:100%;border-radius:6px;margin:6px 0"><?php else: ?><div><?= $it['content'] /* już oczyszczony: h1, h2, p, b, ul, ol, li */ ?></div><?php endif; ?>
                <?php endforeach; endforeach; ?>
            </div>
        </div>
        <?php if (canEdit() && !$offer['errors']): ?>
            <form method="post" action="erli_listing.php?acc=<?= $accId ?>">
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                <input type="hidden" name="ids[]" value="<?= (int) $previewId ?>">
                <button class="btn" type="submit" style="background:#4295b8">Wystaw w ERLI</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
    <?php
    require __DIR__ . '/footer.php';
    exit;
}

// --- lista produktów ---
$views = ['' => 'Wszystkie', 'new' => 'Niewystawione', 'listed' => 'Wystawione', 'error' => 'Z błędem'];
$view = isset($views[$_GET['view'] ?? '']) ? (string) ($_GET['view'] ?? '') : '';
$q = trim((string) ($_GET['q'] ?? ''));
$cat = trim((string) ($_GET['cat'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

$where = ["p.sku IS NOT NULL AND p.sku <> ''"];
$params = [$accId];
if ($q !== '') {
    $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.ean LIKE ?)';
    array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
}
if ($cat !== '') {
    $where[] = 'p.categories LIKE ?';
    $params[] = '%' . json_encode($cat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '%';
}
if ($view === 'new') {
    $where[] = '(l.id IS NULL OR l.status = \'error\' AND l.listed_at IS NULL)';
} elseif ($view === 'listed') {
    $where[] = 'l.listed_at IS NOT NULL';
} elseif ($view === 'error') {
    $where[] = 'l.status = \'error\'';
}
$from = 'FROM products p LEFT JOIN erli_listings l ON l.product_id = p.id AND l.integration_id = ? WHERE ' . implode(' AND ', $where);
$cnt = $pdo->prepare('SELECT COUNT(*) ' . $from);
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$st = $pdo->prepare('SELECT p.id, p.sku, p.name, p.pase_price, p.pase_stock, p.categories, p.images, l.status AS l_status, l.error AS l_error,
        l.warnings AS l_warnings, l.listed_at, l.updated_at AS l_updated ' . $from . ' ORDER BY p.name LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$crmCats = array_keys((new \Pase\Repository\ProductRepository($pdo))->distinctCategories());
$map = $erliOffers->categoryMap();
$catPathCache = [];
$erliCatFor = static function (array $product) use ($map, $erliOffers, &$catPathCache): ?string {
    foreach (ErliOffers::productCategories($product) as $c) {
        $id = $map[$c]['erli_category_id'] ?? null;
        if ($id) {
            if (!isset($catPathCache[$id])) {
                $row = $erliOffers->category((int) $id);
                $catPathCache[$id] = $row ? (string) $row['name'] : '#' . $id;
            }
            return $catPathCache[$id];
        }
    }
    return null;
};
$link = static function (array $over) use ($view, $q, $cat, $accId): string {
    $p = array_filter(array_merge(['acc' => $accId, 'view' => $view, 'q' => $q, 'cat' => $cat], $over), static fn($v) => $v !== '' && $v !== null);
    return 'erli_listing.php?' . http_build_query($p);
};
$priceOn = ($erliAcc['config']['push_price'] ?? 'off') === 'on';
$mult = (string) ($erliAcc['config']['price_multiplier'] ?? '1.00');

require __DIR__ . '/header.php';
?>
<style>
    .el-tabs { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:12px; }
    .el-tabs a { padding:6px 12px; border-radius:999px; border:1px solid var(--line); text-decoration:none; color:var(--ink-2); font-size:13px; }
    .el-tabs a.active { background:var(--accent-soft); border-color:var(--accent); color:var(--ink); font-weight:600; }
    .el-muted { color:var(--ink-3); font-size:12px; }
    .el-num { font-family:var(--font-num); font-variant-numeric:tabular-nums; text-align:right; white-space:nowrap; }
    .el-bar { position:sticky; bottom:0; background:var(--surface); border-top:1px solid var(--line); padding:10px 0; display:flex; gap:10px; align-items:center; }
</style>
<?= erliAccountPicker($erliAccounts, $erliAcc, 'erli_listing.php') ?>

<div class="card" style="font-size:13px;color:var(--ink-2)">
    Cena w ERLI = cena w magazynie × <?= $e($mult) ?>. <?= $priceOn ? 'Zmiany cen w magazynie idą do ERLI same.' : 'Późniejsze zmiany cen nie idą do ERLI same - włącz „Ceny” w ustawieniach konta.' ?>
    Stany wystawionych ofert pilnuje synchronizacja co <?= Erli::RUN_EVERY_MIN ?> min.
</div>

<div class="card">
    <div class="el-tabs">
        <?php foreach ($views as $k => $label): ?><a href="<?= $e($link(['view' => $k, 'page' => null])) ?>" class="<?= $k === $view ? 'active' : '' ?>"><?= $e($label) ?></a><?php endforeach; ?>
    </div>
    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
        <input type="hidden" name="acc" value="<?= $accId ?>">
        <?php if ($view !== ''): ?><input type="hidden" name="view" value="<?= $e($view) ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= $e($q) ?>" placeholder="Nazwa, SKU albo EAN" style="min-width:240px">
        <select name="cat" onchange="this.form.submit()">
            <option value="">Wszystkie kategorie</option>
            <?php foreach ($crmCats as $c): ?><option value="<?= $e($c) ?>" <?= $cat === $c ? 'selected' : '' ?>><?= $e($c) ?></option><?php endforeach; ?>
        </select>
        <button class="btn secondary" type="submit">Szukaj</button>
        <span class="el-muted"><?= $total ?> produktów z SKU</span>
    </form>

    <?php if (!$rows): ?>
        <p class="el-muted" style="margin:0">Brak produktów dla tych filtrów.</p>
    <?php else: ?>
    <form method="post" action="<?= $e($link(['page' => $page])) ?>">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <div style="overflow-x:auto">
        <table>
            <tr>
                <th style="width:28px"><input type="checkbox" onclick="document.querySelectorAll('.el-ch').forEach(c=>c.checked=this.checked);elCount()"></th>
                <th>Produkt</th><th class="el-num">Cena w ERLI</th><th class="el-num">Stan</th><th>Kategoria ERLI</th><th>W ERLI</th><th></th>
            </tr>
            <?php foreach ($rows as $r):
                $price = Erli::erliPrice($erliAcc, $r['pase_price']);
                $ecat = $erliCatFor($r);
                $img = (json_decode((string) ($r['images'] ?? '[]'), true) ?: [])[0] ?? null;
                $img = is_array($img) ? ($img['src'] ?? $img['url'] ?? null) : $img; ?>
                <tr>
                    <td><input type="checkbox" class="el-ch" name="ids[]" value="<?= (int) $r['id'] ?>" onchange="elCount()"></td>
                    <td>
                        <div style="display:flex;gap:10px;align-items:center">
                            <?php if ($img): ?><img src="<?= $e($img) ?>" alt="" style="width:36px;height:36px;object-fit:cover;border-radius:6px;border:1px solid var(--line)"><?php endif; ?>
                            <div><?= $e($r['name']) ?><div class="el-muted">SKU <?= $e($r['sku']) ?></div></div>
                        </div>
                    </td>
                    <td class="el-num"><?= $zl($price) ?></td>
                    <td class="el-num"><?= $r['pase_stock'] !== null ? max(0, (int) $r['pase_stock']) : '—' ?></td>
                    <td><?= $ecat !== null ? $e($ecat) : '<span class="pill warn">brak</span>' ?></td>
                    <td>
                        <?php if ($r['listed_at'] !== null): ?>
                            <span class="pill ok">wystawiona</span><div class="el-muted"><?= $e(substr((string) ($r['l_updated'] ?? $r['listed_at']), 0, 16)) ?></div>
                        <?php elseif ($r['l_status'] === 'error'): ?>
                            <span class="pill bad">nie wystawiona</span>
                        <?php else: ?>
                            <span class="el-muted">—</span>
                        <?php endif; ?>
                        <?php if ($r['l_status'] === 'error' && $r['l_error']): ?><div class="el-muted" style="color:var(--danger-ink);max-width:320px"><?= $e(mb_substr((string) $r['l_error'], 0, 300)) ?></div><?php endif; ?>
                        <?php if ($r['l_warnings']): ?><div class="el-muted" style="color:var(--warn-ink);max-width:320px" title="<?= $e($r['l_warnings']) ?>">⚠ <?= count(explode("\n", (string) $r['l_warnings'])) ?> ostrzeżenia</div><?php endif; ?>
                    </td>
                    <td style="text-align:right"><a class="btn secondary" href="erli_listing.php?acc=<?= $accId ?>&preview=<?= (int) $r['id'] ?>">Podgląd</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php if (canEdit()): ?>
        <div class="el-bar">
            <button class="btn" type="submit" style="background:#4295b8" id="elBtn" disabled>Wystaw zaznaczone w ERLI</button>
            <span class="el-muted">Produkt, który już jest w ERLI, zostanie zaktualizowany danymi z magazynu. Naraz do <?= ERLI_PUBLISH_LIMIT ?>.</span>
        </div>
        <?php endif; ?>
    </form>
    <?php
    $pagerPage = $page; $pagerPages = $pages;
    $pagerLink = static fn(int $p): string => $link(['page' => $p]);
    require __DIR__ . '/_pager.php';
    ?>
    <?php endif; ?>
</div>
<script>
function elCount() {
    var n = document.querySelectorAll('.el-ch:checked').length, b = document.getElementById('elBtn');
    if (b) { b.disabled = n === 0; b.textContent = n ? 'Wystaw zaznaczone w ERLI (' + n + ')' : 'Wystaw zaznaczone w ERLI'; }
}
</script>

<?php require __DIR__ . '/footer.php'; ?>
