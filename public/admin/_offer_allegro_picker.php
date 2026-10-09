<?php
declare(strict_types=1);

/**
 * Allegro → Wystawianie bez wybranego produktu: lista produktów z magazynu do wystawienia.
 * Wyszukiwarka, filtry (stan, status na Allegro, kategoria), paginacja; „Wystaw" otwiera
 * formularz oferty (offer_allegro.php?product=ID). Dołączane z offer_allegro.php.
 */

/** @var PDO $pdo */

$e    = static fn($v) => htmlspecialchars((string) $v);
$q    = trim((string) ($_GET['q'] ?? ''));
$cat  = trim((string) ($_GET['cat'] ?? ''));
$st   = (string) ($_GET['st'] ?? '');       // '' | none | draft | listed
$inStock = !empty($_GET['instock']);
$per  = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));

// Status na Allegro: wystawiona (ID oferty z powiązania SKU albo z zapisanego szablonu), szkic, brak.
$offerIdSql = "COALESCE(NULLIF(pm.allegro_offer_id, ''), NULLIF(ot.external_id, ''),
               NULLIF(JSON_UNQUOTE(JSON_EXTRACT(ot.data, '$.offer_id')), 'null'))";
$where  = [];
$params = [];
if ($q !== '') {
    $where[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.ean LIKE ?)';
    array_push($params, "%{$q}%", "%{$q}%", "%{$q}%");
}
if ($cat !== '') {
    $where[] = 'p.categories LIKE ?';
    $params[] = '%"' . str_replace(['%', '_'], ['\%', '\_'], $cat) . '"%';
}
if ($inStock) {
    $where[] = 'COALESCE(p.pase_stock, 0) > 0';
}
if ($st === 'listed') {
    $where[] = "{$offerIdSql} IS NOT NULL";
} elseif ($st === 'draft') {
    $where[] = "{$offerIdSql} IS NULL AND ot.id IS NOT NULL";
} elseif ($st === 'none') {
    $where[] = "{$offerIdSql} IS NULL AND ot.id IS NULL";
}
$from = "FROM products p
         LEFT JOIN product_mappings pm ON pm.sku = p.sku
         LEFT JOIN offer_templates ot ON ot.product_id = p.id AND ot.marketplace = 'allegro'"
      . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

$cnt = $pdo->prepare("SELECT COUNT(*) {$from}");
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();
$pages = max(1, (int) ceil($total / $per));
$page  = min($page, $pages);

$stmt = $pdo->prepare("SELECT p.id, p.sku, p.name, p.ean, p.images, p.pase_stock, p.pase_price,
                              ot.id AS tpl_id, ot.updated_at AS tpl_at, {$offerIdSql} AS offer_id
                       {$from} ORDER BY p.name ASC LIMIT {$per} OFFSET " . (($page - 1) * $per));
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categories = (new \Pase\Repository\ProductRepository($pdo))->distinctCategories();
$link = static function (int $p) use ($q, $cat, $st, $inStock): string {
    return 'offer_allegro.php?' . http_build_query(array_filter(['q' => $q, 'cat' => $cat, 'st' => $st, 'instock' => $inStock ? 1 : null, 'page' => $p > 1 ? $p : null]));
};

$PAGE_TITLE = 'Wystawianie na Allegro';
$PAGE_KEY   = 'allegro';
require __DIR__ . '/header.php';
?>

<div class="card">
    <p style="color:#888;font-size:13px;margin:0 0 12px">Wybierz produkt z magazynu, żeby przygotować i wystawić ofertę. Formularz wypełni się danymi produktu (tytuł, opis, zdjęcia, cena, stan).</p>
    <form method="get" class="oa-filters">
        <input type="search" name="q" value="<?= $e($q) ?>" placeholder="Szukaj: nazwa, SKU lub EAN" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other" style="flex:1;min-width:220px">
        <select name="st">
            <option value="">Wszystkie</option>
            <option value="none" <?= $st === 'none' ? 'selected' : '' ?>>Niewystawione</option>
            <option value="draft" <?= $st === 'draft' ? 'selected' : '' ?>>Szkice</option>
            <option value="listed" <?= $st === 'listed' ? 'selected' : '' ?>>Wystawione</option>
        </select>
        <?php if ($categories): ?>
        <select name="cat">
            <option value="">Wszystkie kategorie</option>
            <?php foreach ($categories as $c => $n): $c = (string) $c; ?>
                <option value="<?= $e($c) ?>" <?= $cat === $c ? 'selected' : '' ?>><?= $e($c) ?> (<?= (int) $n ?>)</option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <label class="oa-chk"><input type="checkbox" name="instock" value="1" <?= $inStock ? 'checked' : '' ?>> tylko na stanie</label>
        <button class="btn" type="submit">Filtruj</button>
        <?php if ($q !== '' || $cat !== '' || $st !== '' || $inStock): ?><a class="btn secondary" href="offer_allegro.php">Wyczyść</a><?php endif; ?>
    </form>

    <p style="color:#888;font-size:13px;margin:12px 0 6px">Produktów: <?= $total ?></p>

    <?php if ($rows === []): ?>
        <p style="color:#888">Brak produktów spełniających kryteria.<?php if ($total === 0 && $q === '' && $cat === '' && $st === '' && !$inStock): ?> Najpierw dodaj lub zaimportuj produkty w <a href="products.php">Magazynie</a>.<?php endif; ?></p>
    <?php else: ?>
    <table class="oa-table">
        <tr><th style="width:52px"></th><th>Produkt</th><th>SKU / EAN</th><th style="text-align:right">Stan</th><th style="text-align:right">Cena</th><th>Allegro</th><th></th></tr>
        <?php foreach ($rows as $r):
            $imgs  = json_decode((string) ($r['images'] ?? ''), true);
            $thumb = is_array($imgs) && isset($imgs[0]) ? (is_array($imgs[0]) ? ($imgs[0]['src'] ?? null) : $imgs[0]) : null;
            $oid   = $r['offer_id'] !== null && $r['offer_id'] !== '' ? (string) $r['offer_id'] : null;
        ?>
            <tr>
                <td><?php if ($thumb): ?><img src="<?= $e($thumb) ?>" alt="" loading="lazy" class="oa-thumb"><?php else: ?><span class="oa-thumb oa-noimg"></span><?php endif; ?></td>
                <td><a class="oa-name" href="product_view.php?id=<?= (int) $r['id'] ?>"><?= $e($r['name']) ?></a></td>
                <td style="font-size:13px"><?= $e($r['sku']) ?><?php if (!empty($r['ean'])): ?><div class="oa-sub"><?= $e($r['ean']) ?></div><?php endif; ?></td>
                <td style="text-align:right"><?= $r['pase_stock'] !== null ? (int) $r['pase_stock'] : '—' ?></td>
                <td style="text-align:right;white-space:nowrap"><?= $r['pase_price'] !== null ? number_format((float) $r['pase_price'], 2, ',', ' ') . ' zł' : '—' ?></td>
                <td>
                    <?php if ($oid !== null): ?>
                        <span class="oa-badge ok">wystawiona</span>
                        <div class="oa-sub"><a href="https://allegro.pl/oferta/<?= $e($oid) ?>" target="_blank" rel="noopener"><?= $e($oid) ?> ↗</a></div>
                    <?php elseif ($r['tpl_id'] !== null): ?>
                        <span class="oa-badge draft">szkic</span>
                    <?php else: ?>
                        <span class="oa-sub">—</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right">
                    <a class="btn <?= $oid !== null || $r['tpl_id'] !== null ? 'secondary' : '' ?>" style="padding:5px 12px;font-size:13px;white-space:nowrap"
                       href="offer_allegro.php?product=<?= (int) $r['id'] ?>"><?= $oid !== null ? 'Otwórz' : ($r['tpl_id'] !== null ? 'Dokończ' : 'Wystaw') ?></a>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php $pagerPage = $page; $pagerPages = $pages; $pagerLink = $link; require __DIR__ . '/_pager.php'; ?>
    <?php endif; ?>
</div>

<style>
    .oa-filters { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .oa-chk { display:flex; align-items:center; gap:6px; font-size:14px; color:#555; }
    .oa-thumb { width:40px; height:40px; object-fit:cover; border-radius:6px; border:1px solid #e5e7eb; display:block; }
    .oa-noimg { background:#f1f3f5; }
    .oa-sub { color:#999; font-size:12px; }
    .oa-name { color:inherit; text-decoration:none; font-weight:500; }
    .oa-name:hover { text-decoration:underline; }
    .oa-badge { display:inline-block; font-size:12px; padding:2px 8px; border-radius:10px; }
    .oa-badge.ok { background:#e6f4ea; color:#1e7e34; }
    .oa-badge.draft { background:#fef7e0; color:#b06000; }
</style>

<?php require __DIR__ . '/footer.php'; ?>
