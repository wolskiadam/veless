<?php
declare(strict_types=1);

/**
 * Marketplace → InPost vonHalsky → Wystawianie: produkty z magazynu wystawiane jako oferty vonHalsky.
 *  - oferta = nazwa, opis, marka, zdjęcia, cena z magazynu × mnożnik, stan, EAN, wymiary, kategoria i parametry wg
 *    Kategorii i parametrów (bez przypisania - podpowiedź InPost po EAN), GPSR i zasady z ustawień konta
 *    (Services\VonhalskyOffers::buildOffer),
 *  - „Podgląd” pokazuje ofertę przed wysłaniem z brakami i ostrzeżeniami,
 *  - produkt, który ma już ofertę (wystawioną z CRM, połączoną albo z tym samym SKU), dostaje aktualizację tej oferty.
 * InPost sprawdza ofertę po wysłaniu - status (opublikowana / odrzucona z powodem) przychodzi z listą ofert.
 */

use Pase\Services\Vonhalsky;
use Pase\Services\VonhalskyOffers;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$PAGE_TITLE = 'InPost vonHalsky - wystawianie';
$PAGE_KEY   = 'vonhalsky';
require __DIR__ . '/_vonhalsky_page.php';
/** @var array $vhAcc @var array $vhAccounts @var Vonhalsky $vh @var VonhalskyOffers $vhOffers */
$accId = (int) $vhAcc['id'];
const VH_PUBLISH_LIMIT = 30;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
    if (!canEdit()) {
        flash('Brak uprawnień do zmian.', 'err');
    } elseif ($ids === []) {
        flash('Zaznacz produkty do wystawienia.', 'err');
    } else {
        if (count($ids) > VH_PUBLISH_LIMIT) {
            flash('Naraz można wystawić ' . VH_PUBLISH_LIMIT . ' produktów - wysłano pierwsze ' . VH_PUBLISH_LIMIT . '.', 'err');
            $ids = array_slice($ids, 0, VH_PUBLISH_LIMIT);
        }
        try {
            $r = $vhOffers->publish($vhAcc, $ids);
            $parts = [];
            if ($r['listed']) { $parts[] = 'wystawiono: ' . $r['listed']; }
            if ($r['updated']) { $parts[] = 'zaktualizowano: ' . $r['updated']; }
            if ($r['failed']) { $parts[] = 'nie udało się: ' . $r['failed']; }
            flash('InPost vonHalsky - ' . implode(', ', $parts) . '. InPost sprawdza oferty i publikuje je w ciągu kilku minut.', $r['failed'] && !$r['listed'] && !$r['updated'] ? 'err' : 'ok');
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
$zl = static fn(?float $v): string => $v === null ? '—' : number_format($v, 2, ',', ' ') . ' zł';

// --- podgląd jednej oferty ---
$previewId = (int) ($_GET['preview'] ?? 0);
if ($previewId > 0) {
    $st = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $st->execute([$previewId]);
    $product = $st->fetch(PDO::FETCH_ASSOC);
    $offer = $product ? $vhOffers->buildOffer($vhAcc, $product) : null;
    $existing = $product ? $vhOffers->existingOfferId($accId, $product) : null;
    require __DIR__ . '/header.php';
    $p = $offer['payload'] ?? [];
    $pi = $p['product'] ?? [];
    ?>
    <p style="margin:-6px 0 16px"><a class="btn secondary" href="vonhalsky_listing.php?acc=<?= $accId ?>">← Wystawianie</a></p>
    <?php if (!$offer): ?>
        <div class="card"><p>Nie ma takiego produktu.</p></div>
    <?php else: ?>
        <?php foreach ($offer['errors'] as $m): ?><div class="flash err"><?= $e($m) ?></div><?php endforeach; ?>
        <?php foreach ($offer['warnings'] as $m): ?><div class="flash" style="background:var(--warn-bg);color:var(--warn-ink)"><?= $e($m) ?></div><?php endforeach; ?>
        <div class="card">
            <div style="display:flex;gap:16px;flex-wrap:wrap">
                <?php if (!empty($p['images'][0]['fileUrl'])): ?><img src="<?= $e($p['images'][0]['fileUrl']) ?>" alt="" style="width:140px;height:140px;object-fit:cover;border-radius:8px;border:1px solid var(--line)"><?php endif; ?>
                <div style="flex:1;min-width:260px">
                    <h2 style="margin:0 0 6px;font-size:18px"><?= $e($pi['name'] ?? '') ?></h2>
                    <table>
                        <tr><td>SKU (identyfikator zewnętrzny)</td><td><strong><?= $e($offer['external_id']) ?></strong></td></tr>
                        <tr><td>W vonHalsky</td><td><?= $existing !== null ? 'ma już ofertę - zostanie zaktualizowana <span style="color:var(--ink-3);font-size:12px">(' . $e($existing) . ')</span>' : 'nowa oferta' ?></td></tr>
                        <tr><td>Cena brutto</td><td><strong><?= $zl(isset($p['price']['grossPrice']['amount']) ? (float) $p['price']['grossPrice']['amount'] : null) ?></strong> · VAT <?= $e($p['price']['taxRateInfo'] ?? '') ?></td></tr>
                        <tr><td>Stan</td><td><?= (int) ($p['stock']['quantity'] ?? 0) ?> szt.</td></tr>
                        <tr><td>Marka</td><td><?= ($pi['brand'] ?? '') !== '' ? $e($pi['brand']) : '<span style="color:var(--ink-3)">brak</span>' ?></td></tr>
                        <tr><td>Kategoria vonHalsky</td><td><?= $offer['category_id'] !== null ? $e($vhOffers->categoryPath($offer['category_id'])) . ($offer['category_source'] === 'hint' ? ' <span class="pill muted">z podpowiedzi po EAN</span>' : '') : '<span style="color:var(--ink-3)">nie przypisano</span>' ?></td></tr>
                        <tr><td>Czas wysyłki</td><td><?= (int) ($p['shippingTime']['daysToShip'] ?? 0) ?> dni</td></tr>
                        <?php if (isset($pi['ean'])): ?><tr><td>EAN</td><td><?= $e($pi['ean']) ?></td></tr><?php endif; ?>
                        <?php if (isset($pi['dimension'])): $d = $pi['dimension']; ?><tr><td>Wymiary</td><td><?= (int) $d['length'] ?> × <?= (int) $d['width'] ?> × <?= (int) $d['height'] ?> mm, <?= (int) $d['weight'] ?> g</td></tr><?php endif; ?>
                        <tr><td>GPSR</td><td><?= !empty($p['gpsr']['doesNotRequireGpsrInfo']) ? 'produkt sprzed 13.12.2024' : (isset($p['gpsr']['manufacturer']) ? $e($p['gpsr']['manufacturer']['name'] . ' · ' . $p['gpsr']['manufacturer']['email']) : '<span style="color:var(--ink-3)">brak</span>') ?></td></tr>
                        <tr><td>Zdjęcia</td><td><?= count($p['images'] ?? []) ?></td></tr>
                    </table>
                </div>
            </div>
        </div>
        <?php if (!empty($pi['attributes'])): ?>
            <div class="card"><strong>Parametry</strong>
                <table style="margin-top:8px">
                    <?php try { $defs = $offer['category_id'] !== null ? $vhOffers->attributes($vhAcc, $offer['category_id']) : []; } catch (\Throwable) { $defs = []; } ?>
                    <?php foreach ($pi['attributes'] as $a): ?>
                        <tr><td><?= $e($defs[$a['id']]['name'] ?? $a['id']) ?></td><td><?= $e(implode(', ', $a['values'])) ?><?= ($defs[$a['id']]['unit'] ?? '') !== '' ? ' ' . $e($defs[$a['id']]['unit']) : '' ?></td></tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>
        <div class="card"><strong>Opis</strong>
            <div style="margin-top:8px;line-height:1.6"><?= $pi['description'] ?? '' /* już oczyszczony: h1, h2, p, b, ul, ol, li */ ?></div>
        </div>
        <?php if (canEdit() && !$offer['errors']): ?>
            <form method="post" action="vonhalsky_listing.php?acc=<?= $accId ?>">
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                <input type="hidden" name="ids[]" value="<?= (int) $previewId ?>">
                <button class="btn" type="submit" style="background:#ffcd00;color:#1d1d1b"><?= $existing !== null ? 'Zaktualizuj ofertę w vonHalsky' : 'Wystaw w vonHalsky' ?></button>
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
$params = [$accId, $accId];
if ($q !== '') {
    $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.ean LIKE ?)';
    array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
}
if ($cat !== '') {
    $where[] = 'p.categories LIKE ?';
    $params[] = '%' . json_encode($cat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '%';
}
if ($view === 'new') {
    $where[] = '(l.id IS NULL OR l.offer_id IS NULL)';
} elseif ($view === 'listed') {
    $where[] = 'l.offer_id IS NOT NULL';
} elseif ($view === 'error') {
    $where[] = "(l.status = 'error' OR o.offer_status = 'REJECTED')";
}
$from = 'FROM products p
    LEFT JOIN vonhalsky_listings l ON l.product_id = p.id AND l.integration_id = ?
    LEFT JOIN vonhalsky_offers o ON o.offer_id = l.offer_id AND o.integration_id = ?
    WHERE ' . implode(' AND ', $where);
$cnt = $pdo->prepare('SELECT COUNT(*) ' . $from);
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$st = $pdo->prepare('SELECT p.id, p.sku, p.name, p.pase_price, p.pase_stock, p.categories, p.images, p.ean, l.status AS l_status, l.error AS l_error,
        l.warnings AS l_warnings, l.offer_id, l.listed_at, l.updated_at AS l_updated, o.offer_status, o.problems ' . $from
    . ' ORDER BY p.name LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$crmCats = array_keys((new \Pase\Repository\ProductRepository($pdo))->distinctCategories());
$map = $vhOffers->categoryMap();
$pathCache = [];
$vhCatFor = static function (array $product) use ($map, $vhOffers, &$pathCache): ?string {
    foreach (\Pase\Services\ErliOffers::productCategories($product) as $c) {
        if (isset($map[$c])) {
            return $pathCache[$map[$c]] ??= ($vhOffers->categoryPath($map[$c]) ?: $map[$c]);
        }
    }
    return null;
};
$link = static function (array $over) use ($view, $q, $cat, $accId): string {
    $p = array_filter(array_merge(['acc' => $accId, 'view' => $view, 'q' => $q, 'cat' => $cat], $over), static fn($v) => $v !== '' && $v !== null);
    return 'vonhalsky_listing.php?' . http_build_query($p);
};
$priceOn = ($vhAcc['config']['push_price'] ?? 'off') === 'on';
$mult = (string) ($vhAcc['config']['price_multiplier'] ?? '1.00');

require __DIR__ . '/header.php';
?>
<style>
    .vl-tabs { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:12px; }
    .vl-tabs a { padding:6px 12px; border-radius:999px; border:1px solid var(--line); text-decoration:none; color:var(--ink-2); font-size:13px; }
    .vl-tabs a.active { background:var(--accent-soft); border-color:var(--accent); color:var(--ink); font-weight:600; }
    .vl-muted { color:var(--ink-3); font-size:12px; }
    .vl-num { font-family:var(--font-num); font-variant-numeric:tabular-nums; text-align:right; white-space:nowrap; }
    .vl-bar { position:sticky; bottom:0; background:var(--surface); border-top:1px solid var(--line); padding:10px 0; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
</style>
<?= vhAccountPicker($vhAccounts, $vhAcc) ?>
<?= vhConnectionNotice($vh, $vhAcc) ?>

<div class="card" style="font-size:13px;color:var(--ink-2)">
    Cena w vonHalsky = cena w magazynie × <?= $e($mult) ?>. <?= $priceOn ? 'Zmiany cen w magazynie idą do vonHalsky same.' : 'Późniejsze zmiany cen nie idą do vonHalsky same - włącz „Ceny” w ustawieniach konta.' ?>
    Kategorię dobiera przypisanie w <a href="vonhalsky_categories.php?acc=<?= $accId ?>">Kategoriach i parametrach</a>, a bez niego podpowiedź InPost po EAN.
    Markę, GPSR, VAT i czas wysyłki ustawiasz w <?= canOpenPage('integration_edit.php') ? '<a href="integration_edit.php?id=' . $accId . '">ustawieniach konta</a>' : 'ustawieniach konta' ?>.
</div>

<div class="card">
    <div class="vl-tabs">
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
        <span class="vl-muted"><?= $total ?> produktów z SKU</span>
    </form>

    <?php if (!$rows): ?>
        <p class="vl-muted" style="margin:0">Brak produktów dla tych filtrów.</p>
    <?php else: ?>
    <form method="post" action="<?= $e($link(['page' => $page])) ?>">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <div style="overflow-x:auto">
        <table>
            <tr>
                <th style="width:28px"><input type="checkbox" aria-label="Zaznacz wszystkie" onclick="document.querySelectorAll('.vl-ch').forEach(c=>c.checked=this.checked);vlCount()"></th>
                <th>Produkt</th><th class="vl-num">Cena w vonHalsky</th><th class="vl-num">Stan</th><th>Kategoria vonHalsky</th><th>W vonHalsky</th><th></th>
            </tr>
            <?php foreach ($rows as $r):
                $price = Vonhalsky::offerPrice($vhAcc, $r['pase_price']);
                $vcat = $vhCatFor($r);
                $img = (json_decode((string) ($r['images'] ?? '[]'), true) ?: [])[0] ?? null;
                $img = is_array($img) ? ($img['src'] ?? $img['url'] ?? null) : $img;
                $ost = (string) ($r['offer_status'] ?? ''); ?>
                <tr>
                    <td><input type="checkbox" class="vl-ch" name="ids[]" value="<?= (int) $r['id'] ?>" onchange="vlCount()" aria-label="Zaznacz <?= $e($r['name']) ?>"></td>
                    <td>
                        <div style="display:flex;gap:10px;align-items:center">
                            <?php if ($img): ?><img src="<?= $e($img) ?>" alt="" style="width:36px;height:36px;object-fit:cover;border-radius:6px;border:1px solid var(--line)"><?php endif; ?>
                            <div><?= $e($r['name']) ?><div class="vl-muted">SKU <?= $e($r['sku']) ?><?= $r['ean'] ? ' · EAN ' . $e($r['ean']) : '' ?></div></div>
                        </div>
                    </td>
                    <td class="vl-num"><?= $zl($price) ?></td>
                    <td class="vl-num"><?= $r['pase_stock'] !== null ? max(0, (int) $r['pase_stock']) : '—' ?></td>
                    <td><?= $vcat !== null ? $e($vcat) : ($r['ean'] ? '<span class="vl-muted">z podpowiedzi po EAN</span>' : '<span class="pill warn">brak</span>') ?></td>
                    <td>
                        <?php if ($r['offer_id'] !== null && $ost === 'REJECTED'): ?>
                            <span class="pill bad">odrzucona</span>
                        <?php elseif ($r['offer_id'] !== null && $ost === 'PUBLISHED'): ?>
                            <span class="pill ok">opublikowana</span>
                        <?php elseif ($r['offer_id'] !== null): ?>
                            <span class="pill muted"><?= $e(VonhalskyOffers::OFFER_STATUS_LABELS[$ost] ?? 'wysłana') ?></span>
                        <?php elseif ($r['l_status'] === 'error'): ?>
                            <span class="pill bad">nie wystawiona</span>
                        <?php else: ?>
                            <span class="vl-muted">—</span>
                        <?php endif; ?>
                        <?php if ($r['offer_id'] !== null): ?><div class="vl-muted"><?= $e(substr((string) ($r['l_updated'] ?? $r['listed_at']), 0, 16)) ?></div><?php endif; ?>
                        <?php if ($r['l_status'] === 'error' && $r['l_error']): ?><div class="vl-muted" style="color:var(--danger-ink);max-width:320px"><?= $e(mb_substr((string) $r['l_error'], 0, 300)) ?></div><?php endif; ?>
                        <?php if ($ost === 'REJECTED' && $r['problems']): ?><div class="vl-muted" style="color:var(--danger-ink);max-width:320px"><?= $e(mb_substr((string) $r['problems'], 0, 300)) ?></div><?php endif; ?>
                        <?php if ($r['l_warnings']): ?><div class="vl-muted" style="color:var(--warn-ink);max-width:320px" title="<?= $e($r['l_warnings']) ?>">⚠ <?= count(explode("\n", (string) $r['l_warnings'])) ?> ostrzeżenia</div><?php endif; ?>
                    </td>
                    <td style="text-align:right"><a class="btn secondary" href="vonhalsky_listing.php?acc=<?= $accId ?>&preview=<?= (int) $r['id'] ?>">Podgląd</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php if (canEdit()): ?>
        <div class="vl-bar">
            <button class="btn" type="submit" style="background:#ffcd00;color:#1d1d1b" id="vlBtn" disabled>Wystaw zaznaczone w vonHalsky</button>
            <span class="vl-muted">Produkt, który ma już ofertę, dostanie aktualizację danymi z magazynu. Naraz do <?= VH_PUBLISH_LIMIT ?>.</span>
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
function vlCount() {
    var n = document.querySelectorAll('.vl-ch:checked').length, b = document.getElementById('vlBtn');
    if (b) { b.disabled = n === 0; b.textContent = n ? 'Wystaw zaznaczone w vonHalsky (' + n + ')' : 'Wystaw zaznaczone w vonHalsky'; }
}
</script>

<?php require __DIR__ . '/footer.php'; ?>
