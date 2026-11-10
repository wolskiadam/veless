<?php
declare(strict_types=1);

/**
 * Marketplace → InPost vonHalsky → Kategorie i parametry.
 *  - Kategoria w magazynie → kategoria vonHalsky (drzewo pobrane z InPost do vonhalsky_categories).
 *    Produkt bez przypisanej kategorii dostaje kategorię z podpowiedzi InPost po EAN (o ile InPost ją zna).
 *  - Parametry kategorii vonHalsky: reguła na parametr - stała wartość (przy słownikowych wybór z listy InPost) albo pole
 *    produktu (EAN, waga... albo atrybut z WooCommerce, dopasowywany do listy wartości po nazwie). Parametry bez reguły
 *    uzupełnia podpowiedź po EAN (Services\VonhalskyOffers::offerAttributes).
 */

use Pase\Services\VonhalskyOffers;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$PAGE_TITLE = 'InPost vonHalsky - kategorie i parametry';
$PAGE_KEY   = 'vonhalsky';
require __DIR__ . '/_vonhalsky_page.php';
/** @var array $vhAcc @var array $vhAccounts @var VonhalskyOffers $vhOffers */
$accId = (int) $vhAcc['id'];

$crmCats = (new \Pase\Repository\ProductRepository($pdo))->distinctCategories();
$paramsCat = trim((string) ($_GET['params'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = (string) ($_POST['action'] ?? '');
    $back = 'vonhalsky_categories.php?acc=' . $accId;
    if (!canEdit()) {
        flash('Brak uprawnień do zmian.', 'err');
    } elseif ($action === 'refresh_categories') {
        try {
            flash('Pobrano kategorie z InPost vonHalsky: ' . $vhOffers->refreshCategories($vhAcc) . '.');
        } catch (\Throwable $e) {
            flash($e->getMessage(), 'err');
        }
    } elseif ($action === 'map') {
        $crm = (string) ($_POST['crm_category'] ?? '');
        $cat = trim((string) ($_POST['vh_category_id'] ?? ''));
        if ($crm !== '' && isset($crmCats[$crm])) {
            $vhOffers->setMapping($crm, $cat !== '' && $cat !== '0' ? $cat : null);
            flash('Zapisano kategorię „' . $crm . '”.');
        }
    } elseif ($action === 'refresh_params' && $paramsCat !== '') {
        try {
            $vhOffers->attributes($vhAcc, $paramsCat, true);
            flash('Pobrano parametry kategorii z InPost.');
        } catch (\Throwable $e) {
            flash($e->getMessage(), 'err');
        }
        $back .= '&params=' . rawurlencode($paramsCat);
    } elseif ($action === 'rules' && $paramsCat !== '') {
        $rules = [];
        foreach ((array) ($_POST['mode'] ?? []) as $attrId => $mode) {
            $mode = (string) $mode;
            $value = $mode === 'field' ? (string) ($_POST['field'][$attrId] ?? '') : (string) ($_POST['value'][$attrId] ?? '');
            $rules[(string) $attrId] = ['mode' => $mode, 'value' => $value];
        }
        $vhOffers->saveRules($paramsCat, $rules);
        flash('Zapisano reguły parametrów.');
        $back .= '&params=' . rawurlencode($paramsCat);
    }
    redirectAfterPost($back);
}

$map = $vhOffers->categoryMap();
$catCount = $vhOffers->categoryCount();
$editCat = isset($_GET['map']) ? (string) $_GET['map'] : null;
$q = trim((string) ($_GET['q'] ?? ''));
$found = $editCat !== null ? $vhOffers->searchCategories($q !== '' ? $q : (string) $editCat) : [];
$e = static fn($v): string => htmlspecialchars((string) $v);

$attrs = [];
$rules = [];
$attrError = null;
if ($paramsCat !== '') {
    try {
        $attrs = $vhOffers->attributes($vhAcc, $paramsCat);
    } catch (\Throwable $ex) {
        $attrError = $ex->getMessage();
    }
    $rules = $vhOffers->rules($paramsCat);
}
$productFields = VonhalskyOffers::PRODUCT_FIELDS;
foreach ($vhOffers->productAttributeNames() as $n) {
    $productFields['attr:' . $n] = 'Atrybut: ' . $n;
}

require __DIR__ . '/header.php';
?>
<style>
    .vc-muted { color:var(--ink-3); font-size:12px; }
    .vc-path { font-size:13px; }
    .vc-rules td { vertical-align:top; }
    .vc-rules select, .vc-rules input[type=text] { max-width:260px; }
</style>
<?= vhAccountPicker($vhAccounts, $vhAcc) ?>

<?php if ($paramsCat !== ''): ?>
    <p style="margin:-6px 0 16px"><a class="btn secondary" href="vonhalsky_categories.php?acc=<?= $accId ?>">← Kategorie</a></p>
    <div class="card">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center">
            <div>
                <strong>Parametry kategorii vonHalsky</strong>
                <div class="vc-path"><?= $e($vhOffers->categoryPath($paramsCat)) ?></div>
            </div>
            <?php if (canEdit()): ?>
            <form method="post" action="vonhalsky_categories.php?acc=<?= $accId ?>&params=<?= $e(rawurlencode($paramsCat)) ?>" style="margin:0">
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="refresh_params">
                <button class="btn secondary" type="submit">↻ Odśwież parametry z InPost</button>
            </form>
            <?php endif; ?>
        </div>
        <p class="vc-muted" style="margin:8px 0 0">„Z produktu” bierze wartość z magazynu: przy parametrach z listą wartości dopasowanie jest po nazwie (np. atrybut „Kolor: Czarny” → „Czarny”). Parametry bez reguły uzupełnia podpowiedź InPost po EAN, gdy ją zna.</p>
    </div>
    <?php if ($attrError !== null): ?>
        <div class="flash err"><?= $e($attrError) ?></div>
    <?php elseif (!$attrs): ?>
        <div class="card"><p style="margin:0" class="vc-muted">Ta kategoria nie ma parametrów w vonHalsky.</p></div>
    <?php else: ?>
        <form method="post" action="vonhalsky_categories.php?acc=<?= $accId ?>&params=<?= $e(rawurlencode($paramsCat)) ?>" class="card vc-rules">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="rules">
            <div style="overflow-x:auto">
            <table>
                <tr><th>Parametr</th><th>Skąd wartość</th><th>Wartość</th></tr>
                <?php foreach ($attrs as $id => $a): $r = $rules[(string) $id] ?? ['mode' => '', 'value' => '']; $fid = $e($id); ?>
                    <tr>
                        <td>
                            <strong><?= $e($a['name']) ?></strong><?= !empty($a['required']) ? ' <span class="pill warn">wymagany</span>' : '' ?>
                            <div class="vc-muted"><?= $e(VonhalskyOffers::TYPE_LABELS[$a['type']] ?? $a['type']) ?><?= !empty($a['multi']) ? ' · kilka wartości' : '' ?><?= ($a['unit'] ?? '') !== '' ? ' · ' . $e($a['unit']) : '' ?></div>
                        </td>
                        <td>
                            <select name="mode[<?= $fid ?>]" aria-label="Skąd wartość: <?= $e($a['name']) ?>" onchange="var row=this.closest('tr');row.querySelector('.vc-v').style.display=this.value==='value'?'':'none';row.querySelector('.vc-f').style.display=this.value==='field'?'':'none'">
                                <option value="">— z podpowiedzi albo nie wysyłaj —</option>
                                <option value="value" <?= $r['mode'] === 'value' ? 'selected' : '' ?>>stała wartość</option>
                                <option value="field" <?= $r['mode'] === 'field' ? 'selected' : '' ?>>z produktu</option>
                            </select>
                        </td>
                        <td>
                            <span class="vc-v" style="<?= $r['mode'] === 'value' ? '' : 'display:none' ?>">
                                <?php if ($a['type'] === 'DICTIONARY' && !empty($a['values'])): ?>
                                    <select name="value[<?= $fid ?>]" aria-label="Wartość: <?= $e($a['name']) ?>">
                                        <option value="">— wybierz —</option>
                                        <?php foreach ($a['values'] as $vid => $vname): ?>
                                            <option value="<?= $e($vid) ?>" <?= $r['mode'] === 'value' && $r['value'] === (string) $vid ? 'selected' : '' ?>><?= $e($vname) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="text" name="value[<?= $fid ?>]" aria-label="Wartość: <?= $e($a['name']) ?>" value="<?= $r['mode'] === 'value' ? $e($r['value']) : '' ?>">
                                <?php endif; ?>
                            </span>
                            <span class="vc-f" style="<?= $r['mode'] === 'field' ? '' : 'display:none' ?>">
                                <select name="field[<?= $fid ?>]" aria-label="Pole produktu: <?= $e($a['name']) ?>">
                                    <?php foreach ($productFields as $fk => $fl): ?>
                                        <option value="<?= $e($fk) ?>" <?= $r['mode'] === 'field' && $r['value'] === $fk ? 'selected' : '' ?>><?= $e($fl) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
            </div>
            <?php if (canEdit()): ?><p style="margin:14px 0 0"><button class="btn" type="submit">Zapisz reguły</button></p><?php endif; ?>
        </form>
    <?php endif; ?>

<?php else: ?>
    <div class="card">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center">
            <p style="margin:0;font-size:13px;color:var(--ink-2);max-width:720px">
                Przypisz kategoriom z magazynu kategorie vonHalsky. Oferta dostaje kategorię i parametry według pierwszej kategorii produktu, która ma przypisanie;
                bez przypisania - kategorię z podpowiedzi InPost po EAN.
                <?= $catCount > 0 ? 'Kategorii vonHalsky w CRM: ' . $catCount . '.' : 'Najpierw pobierz kategorie z InPost.' ?>
            </p>
            <?php if (canEdit()): ?>
            <form method="post" action="vonhalsky_categories.php?acc=<?= $accId ?>" style="margin:0">
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="refresh_categories">
                <button class="btn secondary" type="submit">↻ Pobierz kategorie z InPost</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($editCat !== null && isset($crmCats[$editCat])): $cur = $map[$editCat] ?? null; ?>
        <div class="card">
            <strong>Kategoria w magazynie: <?= $e($editCat) ?></strong>
            <form method="get" style="display:flex;gap:8px;margin:10px 0;flex-wrap:wrap">
                <input type="hidden" name="acc" value="<?= $accId ?>">
                <input type="hidden" name="map" value="<?= $e($editCat) ?>">
                <input type="search" name="q" value="<?= $e($q !== '' ? $q : $editCat) ?>" placeholder="Szukaj kategorii vonHalsky" aria-label="Szukaj kategorii vonHalsky" style="min-width:260px">
                <button class="btn secondary" type="submit">Szukaj</button>
            </form>
            <form method="post" action="vonhalsky_categories.php?acc=<?= $accId ?>">
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="map">
                <input type="hidden" name="crm_category" value="<?= $e($editCat) ?>">
                <div style="max-height:320px;overflow:auto;border:1px solid var(--line);border-radius:8px;padding:8px">
                    <label style="display:block;padding:4px 0;font-size:13px"><input type="radio" name="vh_category_id" value="" <?= $cur === null ? 'checked' : '' ?>> bez kategorii (podpowiedź po EAN)</label>
                    <?php if ($cur !== null): ?>
                        <label style="display:block;padding:4px 0;font-size:13px"><input type="radio" name="vh_category_id" value="<?= $e($cur) ?>" checked> <?= $e($vhOffers->categoryPath($cur)) ?> <span class="pill ok">obecna</span></label>
                    <?php endif; ?>
                    <?php foreach ($found as $f): if ((string) $f['id'] === (string) $cur) { continue; } ?>
                        <label style="display:block;padding:4px 0;font-size:13px"><input type="radio" name="vh_category_id" value="<?= $e($f['id']) ?>"> <?= $e($f['path'] ?: $f['name']) ?></label>
                    <?php endforeach; ?>
                    <?php if (!$found): ?><p class="vc-muted" style="margin:6px 0">Brak kategorii dla tego wyszukiwania<?= $catCount === 0 ? ' - najpierw pobierz kategorie z InPost' : '' ?>.</p><?php endif; ?>
                </div>
                <p style="margin:12px 0 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <?php if (canEdit()): ?><button class="btn" type="submit">Zapisz</button><?php endif; ?>
                    <a class="btn secondary" href="vonhalsky_categories.php?acc=<?= $accId ?>">Anuluj</a>
                </p>
            </form>
        </div>
    <?php endif; ?>

    <div class="card">
        <?php if (!$crmCats): ?>
            <p class="vc-muted" style="margin:0">Produkty w magazynie nie mają kategorii.</p>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table>
            <tr><th>Kategoria w magazynie</th><th>Kategoria vonHalsky</th><th></th></tr>
            <?php foreach ($crmCats as $cat => $count): $m = $map[$cat] ?? null; ?>
                <tr>
                    <td><strong><?= $e($cat) ?></strong><div class="vc-muted"><?= (int) $count ?> produktów</div></td>
                    <td class="vc-path"><?= $m !== null ? $e($vhOffers->categoryPath($m)) : '<span class="vc-muted">nie przypisano (podpowiedź po EAN)</span>' ?></td>
                    <td style="text-align:right;white-space:nowrap">
                        <a class="btn secondary" href="vonhalsky_categories.php?acc=<?= $accId ?>&map=<?= rawurlencode((string) $cat) ?>">Zmień</a>
                        <?php if ($m !== null): ?><a class="btn secondary" href="vonhalsky_categories.php?acc=<?= $accId ?>&params=<?= rawurlencode($m) ?>">Parametry</a><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
