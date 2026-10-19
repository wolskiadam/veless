<?php
declare(strict_types=1);

/**
 * Marketplace → Erli → Kategorie i parametry.
 *  - Kategoria w magazynie → kategoria ERLI (drzewo pobrane z ERLI do erli_categories) i opcjonalnie własny cennik wysyłki.
 *  - Parametry kategorii ERLI: reguła na parametr - stała wartość (przy słownikowych wybór z listy ERLI) albo pole produktu
 *    (EAN, waga... albo atrybut z WooCommerce, dopasowywany do listy wartości ERLI po nazwie). Reguły wypełniają parametry
 *    przy wystawianiu (Services\ErliOffers::offerAttributes).
 */

use Pase\Services\ErliOffers;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$PAGE_TITLE = 'Erli - kategorie i parametry';
$PAGE_KEY   = 'erli';
require __DIR__ . '/_erli_page.php';
/** @var array $erliAcc @var array $erliAccounts @var ErliOffers $erliOffers */
$accId = (int) $erliAcc['id'];

$crmCats = (new \Pase\Repository\ProductRepository($pdo))->distinctCategories();
$paramsCat = (int) ($_GET['params'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = (string) ($_POST['action'] ?? '');
    $back = 'erli_categories.php?acc=' . $accId;
    if (!canEdit()) {
        flash('Brak uprawnień do zmian.', 'err');
    } elseif ($action === 'refresh_categories') {
        try {
            flash('Pobrano kategorie z ERLI: ' . $erliOffers->refreshCategories($erliAcc) . '.');
        } catch (\Throwable $e) {
            flash($e->getMessage(), 'err');
        }
    } elseif ($action === 'map') {
        $crm = (string) ($_POST['crm_category'] ?? '');
        $cat = (int) ($_POST['erli_category_id'] ?? 0);
        if ($crm !== '' && isset($crmCats[$crm])) {
            $erliOffers->setMapping($crm, $cat > 0 ? $cat : null, trim((string) ($_POST['price_list'] ?? '')));
            flash('Zapisano kategorię „' . $crm . '”.');
        }
    } elseif ($action === 'refresh_params' && $paramsCat > 0) {
        try {
            $erliOffers->attributes($erliAcc, $paramsCat, true);
            flash('Pobrano parametry kategorii z ERLI.');
        } catch (\Throwable $e) {
            flash($e->getMessage(), 'err');
        }
        $back .= '&params=' . $paramsCat;
    } elseif ($action === 'rules' && $paramsCat > 0) {
        $rules = [];
        foreach ((array) ($_POST['mode'] ?? []) as $attrId => $mode) {
            $mode = (string) $mode;
            $value = $mode === 'field' ? (string) ($_POST['field'][$attrId] ?? '') : (string) ($_POST['value'][$attrId] ?? '');
            $rules[(int) $attrId] = ['mode' => $mode, 'value' => $value];
        }
        $erliOffers->saveRules($paramsCat, $rules);
        flash('Zapisano reguły parametrów.');
        $back .= '&params=' . $paramsCat;
    }
    redirectAfterPost($back);
}

$map = $erliOffers->categoryMap();
$catCount = $erliOffers->categoryCount();
$lists = $erliOffers->priceLists($accId);
$editCat = isset($_GET['map']) ? (string) $_GET['map'] : null;
$q = trim((string) ($_GET['q'] ?? ''));
$found = $editCat !== null ? $erliOffers->searchCategories($q !== '' ? $q : (string) $editCat) : [];
$e = static fn($v): string => htmlspecialchars((string) $v);
$catPath = static function (?int $id) use ($erliOffers): string {
    if (!$id) {
        return '';
    }
    $c = $erliOffers->category($id);
    return $c ? (string) ($c['path'] ?: $c['name']) : '#' . $id;
};

$attrs = [];
$rules = [];
$attrError = null;
if ($paramsCat > 0) {
    try {
        $attrs = $erliOffers->attributes($erliAcc, $paramsCat);
    } catch (\Throwable $ex) {
        $attrError = $ex->getMessage();
    }
    $rules = $erliOffers->rules($paramsCat);
}
$productFields = ErliOffers::PRODUCT_FIELDS;
foreach ($erliOffers->productAttributeNames() as $n) {
    $productFields['attr:' . $n] = 'Atrybut: ' . $n;
}

require __DIR__ . '/header.php';
?>
<style>
    .ec-muted { color:var(--ink-3); font-size:12px; }
    .ec-path { font-size:13px; }
    .ec-rules td { vertical-align:top; }
    .ec-rules select, .ec-rules input[type=text] { max-width:260px; }
</style>
<?= erliAccountPicker($erliAccounts, $erliAcc, 'erli_categories.php') ?>

<?php if ($paramsCat > 0): ?>
    <p style="margin:-6px 0 16px"><a class="btn secondary" href="erli_categories.php?acc=<?= $accId ?>">← Kategorie</a></p>
    <div class="card">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center">
            <div>
                <strong>Parametry kategorii ERLI</strong>
                <div class="ec-path"><?= $e($catPath($paramsCat)) ?></div>
            </div>
            <?php if (canEdit()): ?>
            <form method="post" action="erli_categories.php?acc=<?= $accId ?>&params=<?= $paramsCat ?>" style="margin:0">
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="refresh_params">
                <button class="btn secondary" type="submit">↻ Odśwież parametry z ERLI</button>
            </form>
            <?php endif; ?>
        </div>
        <p class="ec-muted" style="margin:8px 0 0">„Z produktu” bierze wartość z magazynu: przy parametrach z listą wartości ERLI dopasowanie jest po nazwie (np. atrybut „Kolor: Czarny” → „Czarny”).</p>
    </div>
    <?php if ($attrError !== null): ?>
        <div class="flash err"><?= $e($attrError) ?></div>
    <?php elseif (!$attrs): ?>
        <div class="card"><p style="margin:0" class="ec-muted">Ta kategoria nie ma parametrów w ERLI.</p></div>
    <?php else: ?>
        <form method="post" action="erli_categories.php?acc=<?= $accId ?>&params=<?= $paramsCat ?>" class="card ec-rules">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="rules">
            <table>
                <tr><th>Parametr</th><th>Skąd wartość</th><th>Wartość</th></tr>
                <?php foreach ($attrs as $id => $a): $r = $rules[(int) $id] ?? ['mode' => '', 'value' => '']; ?>
                    <tr>
                        <td>
                            <strong><?= $e($a['name']) ?></strong><?= !empty($a['required']) ? ' <span class="pill warn">wymagany</span>' : '' ?>
                            <div class="ec-muted"><?= $e(['dictionary' => 'lista wartości', 'string' => 'tekst', 'number' => 'liczba', 'range' => 'zakres'][$a['type']] ?? $a['type']) ?><?= ($a['unit'] ?? '') !== '' ? ' · ' . $e($a['unit']) : '' ?></div>
                        </td>
                        <td>
                            <select name="mode[<?= (int) $id ?>]" onchange="var row=this.closest('tr');row.querySelector('.ec-v').style.display=this.value==='value'?'':'none';row.querySelector('.ec-f').style.display=this.value==='field'?'':'none'">
                                <option value="">— nie wysyłaj —</option>
                                <option value="value" <?= $r['mode'] === 'value' ? 'selected' : '' ?>>stała wartość</option>
                                <option value="field" <?= $r['mode'] === 'field' ? 'selected' : '' ?>>z produktu</option>
                            </select>
                        </td>
                        <td>
                            <span class="ec-v" style="<?= $r['mode'] === 'value' ? '' : 'display:none' ?>">
                                <?php if ($a['type'] === 'dictionary' && !empty($a['values'])): ?>
                                    <select name="value[<?= (int) $id ?>]">
                                        <option value="">— wybierz —</option>
                                        <?php foreach ($a['values'] as $vid => $vname): ?>
                                            <option value="<?= $e($vid) ?>" <?= $r['mode'] === 'value' && $r['value'] === (string) $vid ? 'selected' : '' ?>><?= $e($vname) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="text" name="value[<?= (int) $id ?>]" value="<?= $r['mode'] === 'value' ? $e($r['value']) : '' ?>" placeholder="<?= $a['type'] === 'range' ? 'np. 10-20' : '' ?>">
                                <?php endif; ?>
                            </span>
                            <span class="ec-f" style="<?= $r['mode'] === 'field' ? '' : 'display:none' ?>">
                                <select name="field[<?= (int) $id ?>]">
                                    <?php foreach ($productFields as $fk => $fl): ?>
                                        <option value="<?= $e($fk) ?>" <?= $r['mode'] === 'field' && $r['value'] === $fk ? 'selected' : '' ?>><?= $e($fl) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <?php if (canEdit()): ?><p style="margin:14px 0 0"><button class="btn" type="submit">Zapisz reguły</button></p><?php endif; ?>
        </form>
    <?php endif; ?>

<?php else: ?>
    <div class="card">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center">
            <p style="margin:0;font-size:13px;color:var(--ink-2);max-width:720px">
                Przypisz kategoriom z magazynu kategorie ERLI. Oferta dostaje kategorię i parametry według pierwszej kategorii produktu, która ma przypisanie.
                <?= $catCount > 0 ? 'Kategorii ERLI w CRM: ' . $catCount . '.' : 'Najpierw pobierz kategorie z ERLI.' ?>
            </p>
            <?php if (canEdit()): ?>
            <form method="post" action="erli_categories.php?acc=<?= $accId ?>" style="margin:0">
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="refresh_categories">
                <button class="btn secondary" type="submit">↻ Pobierz kategorie z ERLI</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($editCat !== null && isset($crmCats[$editCat])): $cur = $map[$editCat] ?? null; ?>
        <div class="card">
            <strong>Kategoria w magazynie: <?= $e($editCat) ?></strong>
            <form method="get" style="display:flex;gap:8px;margin:10px 0">
                <input type="hidden" name="acc" value="<?= $accId ?>">
                <input type="hidden" name="map" value="<?= $e($editCat) ?>">
                <input type="search" name="q" value="<?= $e($q !== '' ? $q : $editCat) ?>" placeholder="Szukaj kategorii ERLI" style="min-width:280px">
                <button class="btn secondary" type="submit">Szukaj</button>
            </form>
            <form method="post" action="erli_categories.php?acc=<?= $accId ?>">
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="map">
                <input type="hidden" name="crm_category" value="<?= $e($editCat) ?>">
                <div style="max-height:320px;overflow:auto;border:1px solid var(--line);border-radius:8px;padding:8px">
                    <label style="display:block;padding:4px 0;font-size:13px"><input type="radio" name="erli_category_id" value="0" <?= empty($cur['erli_category_id']) ? 'checked' : '' ?>> bez kategorii ERLI</label>
                    <?php if (!empty($cur['erli_category_id'])): ?>
                        <label style="display:block;padding:4px 0;font-size:13px"><input type="radio" name="erli_category_id" value="<?= (int) $cur['erli_category_id'] ?>" checked> <?= $e($catPath($cur['erli_category_id'])) ?> <span class="pill ok">obecna</span></label>
                    <?php endif; ?>
                    <?php foreach ($found as $f): if ((int) $f['id'] === (int) ($cur['erli_category_id'] ?? 0)) { continue; } ?>
                        <label style="display:block;padding:4px 0;font-size:13px"><input type="radio" name="erli_category_id" value="<?= (int) $f['id'] ?>"> <?= $e($f['path'] ?: $f['name']) ?></label>
                    <?php endforeach; ?>
                    <?php if (!$found): ?><p class="ec-muted" style="margin:6px 0">Brak kategorii ERLI dla tego wyszukiwania<?= $catCount === 0 ? ' - najpierw pobierz kategorie z ERLI' : '' ?>.</p><?php endif; ?>
                </div>
                <p style="margin:12px 0 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <label style="font-size:13px;color:var(--ink-2)">Cennik wysyłki:</label>
                    <select name="price_list">
                        <option value="">jak w Cennikach wysyłek (domyślny)</option>
                        <?php foreach ($lists as $l): ?><option value="<?= $e($l['name']) ?>" <?= ($cur['price_list'] ?? '') === (string) $l['name'] ? 'selected' : '' ?>><?= $e($l['name'] === '*' ? 'Domyślny cennik ERLI (*)' : $l['name']) ?></option><?php endforeach; ?>
                    </select>
                    <?php if (canEdit()): ?><button class="btn" type="submit">Zapisz</button><?php endif; ?>
                    <a class="btn secondary" href="erli_categories.php?acc=<?= $accId ?>">Anuluj</a>
                </p>
            </form>
        </div>
    <?php endif; ?>

    <div class="card">
        <?php if (!$crmCats): ?>
            <p class="ec-muted" style="margin:0">Produkty w magazynie nie mają kategorii.</p>
        <?php else: ?>
        <table>
            <tr><th>Kategoria w magazynie</th><th>Kategoria ERLI</th><th>Cennik wysyłki</th><th></th></tr>
            <?php foreach ($crmCats as $cat => $count): $m = $map[$cat] ?? null; ?>
                <tr>
                    <td><strong><?= $e($cat) ?></strong><div class="ec-muted"><?= (int) $count ?> produktów</div></td>
                    <td class="ec-path"><?= !empty($m['erli_category_id']) ? $e($catPath($m['erli_category_id'])) : '<span class="ec-muted">nie przypisano</span>' ?></td>
                    <td class="ec-path"><?= ($m['price_list'] ?? '') !== '' ? $e($m['price_list'] === '*' ? 'Domyślny cennik ERLI (*)' : $m['price_list']) : '<span class="ec-muted">domyślny</span>' ?></td>
                    <td style="text-align:right;white-space:nowrap">
                        <a class="btn secondary" href="erli_categories.php?acc=<?= $accId ?>&map=<?= rawurlencode($cat) ?>">Zmień</a>
                        <?php if (!empty($m['erli_category_id'])): ?><a class="btn secondary" href="erli_categories.php?acc=<?= $accId ?>&params=<?= (int) $m['erli_category_id'] ?>">Parametry</a><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
