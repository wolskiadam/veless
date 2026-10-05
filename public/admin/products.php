<?php
declare(strict_types=1);

/**
 * Produkty - magazyn PASE. Lista z paginacją + ręczna edycja stanu/ceny PASE.
 * PASE jest masterem: pase_stock / pase_price to wartości zarządzane tutaj.
 */

use Pase\Queue\Queue;
use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\ProductRepository;

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

const PER_PAGE = 30;

$repo    = new ProductRepository($pdo);
$canEdit = canEdit();
// Lokalizacje w magazynie (Magazyn → Lokalizacje): kolumna, filtr i masowe przypisanie.
$locations = [];
try {
    \Pase\Services\WarehouseLocations::migrate($pdo);
    $locSvc = new \Pase\Services\WarehouseLocations($pdo);
    $locations = $locSvc->all();
} catch (\Throwable) {
    $locSvc = null;
}
$locById = array_column($locations, null, 'id');
$flashOk = null;
// Niski stan: minimum per produkt (products.min_stock) albo próg domyślny z ustawień.
\Pase\Services\LowStock::migrate($pdo);
$lowSvc = new \Pase\Services\LowStock($pdo);
$useActual = $lowSvc->usesActualStock();   // pole „Stan faktyczny” włączone w Konfiguracja → Synchronizacja

// Akcje POST.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    csrfCheck();
    $action = $_POST['action'] ?? '';
    if ($action === 'save_all') {
        // Masowy zapis: pola stock[ID] / price[ID] z całej strony.
        $stocks = $_POST['stock'] ?? [];
        $prices = $_POST['price'] ?? [];
        $items = [];
        foreach (array_keys($stocks + $prices) as $pid) {
            $items[(int) $pid] = [
                'stock' => ($stocks[$pid] ?? '') !== '' ? (int) $stocks[$pid] : null,
                'price' => ($prices[$pid] ?? '') !== '' ? (float) $prices[$pid] : null,
            ];
        }
        $n = $repo->setStockPriceMany($items);
        // Stan faktyczny: zapisujemy tylko zmienione (data wpisania ma znaczenie) i nic nie wypychamy.
        $actualChanged = 0;
        $actualOld = (array) ($_POST['actual_old'] ?? []);
        foreach ($useActual ? (array) ($_POST['actual'] ?? []) : [] as $pid => $v) {
            $v = trim((string) $v);
            if ($v !== (string) ($actualOld[$pid] ?? '')) {
                $repo->setActualStock((int) $pid, $v === '' ? null : (int) $v);
                $actualChanged++;
            }
        }
        // Lokalizacje: zapisujemy tylko zmienione (loc[ID] => id lokalizacji, '' = brak).
        $locChanged = 0;
        if ($locSvc !== null) {
            $old = $_POST['loc_old'] ?? [];
            foreach ((array) ($_POST['loc'] ?? []) as $pid => $lid) {
                if ((string) $lid !== (string) ($old[$pid] ?? '') && ($lid === '' || isset($locById[(int) $lid]))) {
                    $locSvc->assign((int) $pid, $lid === '' ? null : (int) $lid);
                    $locChanged++;
                }
            }
        }
        // Wypych stanu do sklepu po zmianie (handler sam sprawdzi, czy PASE master).
        // dedup_key z mikroczasem -> każda zmiana = nowe zadanie (nadpisze stan w Woo).
        $queue = new Queue($pdo);
        $batch = substr((string) microtime(true), -8);
        foreach (array_keys($items) as $pid) {
            $queue->enqueue('woo.stock.push', ['product_id' => (int) $pid], "woo.stock.push:{$pid}:{$batch}");
        }
        $flashOk = "Zapisano stany i ceny ({$n} produktów). Wypych do sklepu zakolejkowany."
            . ($locChanged ? " Zmieniono lokalizację: {$locChanged}." : '')
            . ($actualChanged ? " Zmieniono stan faktyczny: {$actualChanged} (tylko w CRM)." : '');
    } elseif ($action === 'delete') {
        $repo->delete((int) ($_POST['id'] ?? 0));
        $flashOk = 'Usunięto produkt.';
    } elseif ($action === 'bulk_delete') {
        $n = $repo->deleteMany($_POST['ids'] ?? []);
        $flashOk = "Usunięto {$n} produktów.";
    } elseif ($action === 'bulk_location' && $locSvc !== null) {
        $lid = (string) ($_POST['location_id'] ?? '');
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        if ($ids === [] || ($lid !== '' && !isset($locById[(int) $lid]))) {
            $flashOk = 'Zaznacz produkty i wybierz lokalizację.';
        } else {
            $n = $locSvc->assignMany($ids, $lid === '' ? null : (int) $lid);
            $flashOk = $lid === '' ? "Usunięto lokalizację z {$n} produktów." : "Lokalizacja {$locById[(int) $lid]['code']} ustawiona dla {$n} produktów.";
        }
    } elseif ($action === 'bulk_stock') {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) ($_POST['ids'] ?? [])),
            static fn(int $id): bool => $id > 0
        )));
        $mode = ($_POST['mode'] ?? 'set') === 'delta' ? 'delta' : 'set';
        $value = (int) ($_POST['value'] ?? 0);
        if ($ids === []) {
            $flashOk = 'Nie zaznaczono żadnych produktów.';
        } else {
            $n = $repo->bulkAdjustStock($ids, $mode, $value);
            $queue = new Queue($pdo);
            $batch = substr((string) microtime(true), -8);
            foreach ($ids as $pid) {
                $queue->enqueue('woo.stock.push', ['product_id' => $pid], "woo.stock.push:{$pid}:{$batch}");
            }
            $verb = $mode === 'delta' ? 'zmieniono o' : 'ustawiono na';
            $flashOk = "Stan {$verb} {$value} dla {$n} zaznaczonych produktów. Wypych do sklepu zakolejkowany.";
        }
    } elseif ($action === 'bulk_min') {
        // Minimalny stan zaznaczonych: liczba, puste = próg domyślny, „nie pilnuj” = bez alarmu.
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), static fn(int $id): bool => $id > 0)));
        $raw = trim((string) ($_POST['min'] ?? ''));
        $off = ($_POST['min_mode'] ?? '') === 'off';
        if ($ids === []) {
            $flashOk = 'Nie zaznaczono żadnych produktów.';
        } else {
            foreach ($ids as $pid) {
                $lowSvc->setMin($pid, $off ? \Pase\Services\LowStock::OFF : ($raw === '' ? null : (int) $raw));
            }
            $flashOk = $off ? 'Stan ' . count($ids) . ' zaznaczonych produktów nie jest już pilnowany.'
                : ($raw === '' ? 'Zaznaczone produkty (' . count($ids) . ') korzystają teraz z progu domyślnego.'
                               : 'Minimalny stan ' . max(0, (int) $raw) . ' ustawiony dla ' . count($ids) . ' produktów.');
        }
    } elseif ($action === 'untrack_zero') {
        $n = $lowSvc->untrackSoldOut();
        $flashOk = "Nie pilnujemy już stanu {$n} produktów ze stanem 0. Znajdziesz je pod filtrem „Nie pilnowane”.";
    } elseif ($action === 'low_default') {
        $raw = trim((string) ($_POST['low_default'] ?? ''));
        $lowSvc->saveDefaultThreshold($raw === '' ? null : (int) $raw);
        $flashOk = $raw === '' ? 'Próg domyślny wyłączony - alarm tylko dla produktów z własnym minimum.'
                               : 'Próg domyślny niskiego stanu: ' . max(0, (int) $raw) . '.';
    }
}
$lowDefault = $lowSvc->defaultThreshold();

$q         = trim($_GET['q'] ?? '');
$fCategory = trim($_GET['cat'] ?? '');
// Pusty string = brak filtra (wszystkie sklepy); "0" to prawidłowa, odrębna wartość
// (produkty bez przypisanego sklepu) - stąd rozróżnienie na surowy string, nie (int).
$fShopRaw = trim($_GET['shop'] ?? '');
$fShop    = $fShopRaw !== '' ? (int) $fShopRaw : null;
$page      = max(1, (int) ($_GET['p'] ?? 1));
// Filtr stanu magazynowego: operator (eq/lt/lte/gt/gte/none) + liczba, np. stan < 2.
$fStockOp  = array_key_exists($_GET['stock_op'] ?? '', ProductRepository::STOCK_OPS) ? $_GET['stock_op'] : '';
$fStockRaw = trim((string) ($_GET['stock'] ?? ''));
$fStockVal = preg_match('/^-?\d+$/', $fStockRaw) ? (int) $fStockRaw : null;
if ($fStockOp === 'untracked') {
    $fStockVal = null;
} elseif ($fStockOp === 'low') {
    $fStockVal = $lowDefault;   // „niski stan” = wg minimum produktu, liczba z adresu nie ma znaczenia
} elseif ($fStockOp !== 'none' && $fStockVal === null) {
    $fStockOp = '';   // operator bez liczby = brak filtra
}
$stockFilterOn = $fStockOp !== '';
// Filtr lokalizacji: '' = dowolna, 'none' = bez lokalizacji, liczba = konkretna półka.
$fLocRaw = trim((string) ($_GET['loc'] ?? ''));
$fLoc = $fLocRaw === 'none' ? 0 : (ctype_digit($fLocRaw) && isset($locById[(int) $fLocRaw]) ? (int) $fLocRaw : null);
$stockFilterOn = $stockFilterOn || $fLoc !== null;
$categories = $repo->distinctCategories();

// Sklepy do filtra - nazwa integracji + liczba przypisanych produktów. 0 = "bez sklepu"
// (produkty bez source_integration_id, np. sprzed tego pola albo dodane ręcznie).
$productCountsByShop = $repo->countByIntegration();
$shops = [];
// Adresy sklepów - plakietka kanału prowadzi prosto do produktu w WooCommerce.
$shopUrls = [];
foreach ((new IntegrationAccountRepository($pdo))->activeByType('woocommerce') as $acc) {
    $shops[(int) $acc['id']] = $acc['name'];
    $baseUrl = trim((string) ($acc['config']['base_url'] ?? ''));
    if ($baseUrl !== '') {
        $shopUrls[(int) $acc['id']] = rtrim($baseUrl, '/');
    }
}

$noTable = false;
try {
    // Dwa różne placeholdery na tę samą wartość - PDO z wyłączoną emulacją nie obsługuje
    // użycia tego samego nazwanego placeholdera dwa razy w jednym zapytaniu.
    $conditions = [];
    $params = [];
    if ($q !== '') {
        $conditions[] = '(sku LIKE :q1 OR name LIKE :q2)';
        $params[':q1'] = "%{$q}%";
        $params[':q2'] = "%{$q}%";
    }
    if ($fCategory !== '') {
        $conditions[] = 'categories LIKE :cat';
        $params[':cat'] = '%"' . str_replace(['%', '_'], ['\%', '\_'], $fCategory) . '"%';
    }
    if ($fShop !== null) {
        // 0 = "bez przypisanego sklepu" (kolumna jest wtedy NULL, nie liczbą 0).
        $conditions[] = $fShop === 0
            ? '(source_integration_id IS NULL OR source_integration_id = 0)'
            : 'source_integration_id = :shop';
        if ($fShop !== 0) {
            $params[':shop'] = $fShop;
        }
    }
    if (($stockSql = ProductRepository::stockCondition($fStockOp ?: null, $fStockVal, $params, $useActual)) !== null) {
        $conditions[] = $stockSql;
    }
    if ($fLoc !== null) {
        $conditions[] = $fLoc === 0 ? 'location_id IS NULL' : 'location_id = :loc';
        if ($fLoc !== 0) {
            $params[':loc'] = $fLoc;
        }
    }
    $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $total = $repo->count($where, $params);
    $pages = max(1, (int) ceil($total / PER_PAGE));
    $page  = min($page, $pages);
    $rows  = $repo->paged(PER_PAGE, ($page - 1) * PER_PAGE, $q, $fCategory !== '' ? $fCategory : null, $fShop,
                          $fStockOp ?: null, $fStockVal, $fLoc, $useActual);
} catch (\PDOException $e) {
    $noTable = true;
    $rows = []; $total = 0; $pages = 1;
}

$qs = $_GET;
$link = static function (int $p) use ($qs): string { $qs['p'] = $p; return 'products.php?' . http_build_query($qs); };

// Grupowanie wizualne wariantów Woo - po WSPÓLNYM woo_product_id (prawdziwy, dzielony
// identyfikator rodzica w WooCommerce - patrz import_products.php/WooProductImportHandler),
// NIE po nazwie. Zwykłe produkty mają każdy swój własny, unikalny woo_product_id, więc
// nigdy przypadkiem nie wpadną do wspólnej grupy.
$parentCounts = [];
foreach ($rows as $r) {
    $wpid = (int) ($r['woo_product_id'] ?? 0);
    if ($wpid > 0) {
        $parentCounts[$wpid] = ($parentCounts[$wpid] ?? 0) + 1;
    }
}

/** Etykieta konkretnego wariantu z zapisanych atrybutów Woo (np. "Knot: drewniany"). */
$variantLabel = static function (array $r): ?string {
    $attrs = json_decode((string) ($r['attributes'] ?? ''), true);
    if (!is_array($attrs) || $attrs === []) {
        return null;
    }
    $parts = [];
    foreach ($attrs as $attrName => $options) {
        $val = is_array($options) ? implode('/', $options) : (string) $options;
        if ($val !== '') {
            $parts[] = trim((string) $attrName) . ': ' . $val;
        }
    }
    return $parts !== [] ? implode(', ', $parts) : null;
};

// Kanały sprzedaży: powiązania z Allegro dla SKU widocznych NA TEJ stronie.
// Jedno zapytanie zamiast joina w repozytorium - lista jest stronicowana, więc
// pytamy o kilkadziesiąt SKU, a repozytorium zostaje nietknięte.
$allegroBySku = [];
try {
    $pageSkus = [];
    foreach ($rows as $r) {
        $sku = trim((string) ($r['sku'] ?? ''));
        if ($sku !== '') {
            $pageSkus[] = $sku;
        }
    }
    if ($pageSkus !== []) {
        $placeholders = implode(',', array_fill(0, count($pageSkus), '?'));
        $stmt = $pdo->prepare(
            "SELECT sku, allegro_offer_id FROM product_mappings
             WHERE allegro_offer_id IS NOT NULL AND allegro_offer_id <> '' AND sku IN ($placeholders)"
        );
        $stmt->execute($pageSkus);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $mapping) {
            $allegroBySku[(string) $mapping['sku']] = (string) $mapping['allegro_offer_id'];
        }
    }
} catch (\PDOException $e) {
    $allegroBySku = [];   // brak tabeli mapowań - plakietki Allegro po prostu się nie pokażą
}

/**
 * Plakietki kanałów dla jednego wiersza: sklep (skąd produkt przyszedł)
 * i Allegro (gdy SKU ma przypisaną ofertę). Zwraca gotowy HTML.
 */
$channelBadges = static function (array $r) use ($shops, $shopUrls, $allegroBySku): string {
    $badges = [];

    $shopId    = (int) ($r['source_integration_id'] ?? 0);
    $shopName  = $shops[$shopId] ?? null;
    $wooId     = (int) ($r['woo_product_id'] ?? 0);
    $baseUrl   = $shopUrls[$shopId] ?? null;

    if ($shopName !== null || $wooId > 0) {
        $label = $shopName ?? 'Sklep';   // konto usunięte, ale ID z Woo zostało

        if ($baseUrl !== null && $wooId > 0) {
            // Prowadzimy do edycji produktu w panelu WooCommerce - tak samo jak
            // "Otwórz w WooCommerce" przy zamówieniu. Warianty nie mają własnej
            // strony edycji, więc link idzie do produktu nadrzędnego.
            $variantNote = (int) ($r['woo_variation_id'] ?? 0) > 0 ? ' (produkt nadrzędny)' : '';
            $badges[] = '<a class="chan chan-shop chan-link" target="_blank" rel="noopener"'
                . ' href="' . htmlspecialchars($baseUrl . '/wp-admin/post.php?post=' . $wooId . '&action=edit', ENT_QUOTES)
                . '" title="Otwórz w WooCommerce' . $variantNote . ' ↗">'
                . htmlspecialchars($label) . '</a>';
        } else {
            $badges[] = '<span class="chan chan-shop">' . htmlspecialchars($label) . '</span>';
        }
    }

    $offerId = $allegroBySku[trim((string) ($r['sku'] ?? ''))] ?? null;
    if ($offerId !== null) {
        $badges[] = '<a class="chan chan-allegro" target="_blank" rel="noopener"'
            . ' href="https://allegro.pl/oferta/' . rawurlencode($offerId) . '"'
            . ' title="Oferta ' . htmlspecialchars($offerId) . ' — otwórz na Allegro">Allegro</a>';
    }

    return $badges === []
        ? '<span class="chan chan-none" title="Produkt nie jest powiązany z żadnym kanałem">—</span>'
        : implode(' ', $badges);
};

$PAGE_TITLE = 'Produkty (magazyn)';
$PAGE_KEY   = 'products';
require __DIR__ . '/header.php';
require_once __DIR__ . '/_loc_picker.php';
if ($locations !== []) { echo locPickerData($locations); }
?>

<style>
    /* Plakietki kanałów sprzedaży - zwarte, żeby zmieściły się obok siebie w wierszu. */
    .chan { display:inline-block; padding:2px 7px; border-radius:5px; font-size:11px; font-weight:600;
            line-height:1.6; white-space:nowrap; text-decoration:none; }
    .chan-shop { background:#e6f4ea; color:#137333; }
    .chan-link:hover { background:#d3ebda; text-decoration:underline; }
    .chan-allegro { background:#e8f0fe; color:#1a56c4; }
    .chan-allegro:hover { background:#d7e4fd; }
    .chan-none { background:transparent; color:#bbb; font-weight:400; }
</style>

<?php if ($flashOk): ?><div class="flash ok"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>
<?php if ($noTable): ?>
<div class="flash err">Tabela <code>products</code> jeszcze nie istnieje — powstanie automatycznie. Odśwież za chwilę.</div>
<?php endif; ?>

<div class="card">
    <form method="get" style="margin-bottom:14px">
        <input name="q" placeholder="Szukaj: SKU / nazwa" value="<?= htmlspecialchars($q) ?>" style="width:300px">
        <select name="cat" onchange="this.form.submit()">
            <option value="">— wszystkie kategorie —</option>
            <?php foreach ($categories as $catName => $catCount): ?>
                <option value="<?= htmlspecialchars($catName) ?>" <?= $fCategory === $catName ? 'selected' : '' ?>>
                    <?= htmlspecialchars($catName) ?> (<?= $catCount ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($shops !== []): ?>
        <select name="shop" onchange="this.form.submit()">
            <option value="">— wszystkie sklepy —</option>
            <?php foreach ($shops as $shopId => $shopName): ?>
                <option value="<?= $shopId ?>" <?= $fShop === $shopId ? 'selected' : '' ?>>
                    <?= htmlspecialchars($shopName) ?> (<?= $productCountsByShop[$shopId] ?? 0 ?>)
                </option>
            <?php endforeach; ?>
            <?php if (($productCountsByShop[0] ?? 0) > 0): ?>
                <option value="0" <?= $fShop === 0 ? 'selected' : '' ?>>— bez przypisanego sklepu — (<?= $productCountsByShop[0] ?>)</option>
            <?php endif; ?>
        </select>
        <?php endif; ?>
        <span class="stock-filter" title="Filtr stanu magazynowego (stan w CRM)">
            <label for="stock_op" style="font-size:13px;color:#666">Stan</label>
            <select name="stock_op" id="stock_op" style="width:auto;min-width:0" onchange="document.getElementById('stock_val').style.display = ['', 'none', 'low', 'untracked'].indexOf(this.value) !== -1 ? 'none' : ''">
                <option value="">— dowolny —</option>
                <?php foreach (ProductRepository::STOCK_OPS as $opKey => $opLabel): ?>
                    <option value="<?= $opKey ?>" <?= $fStockOp === $opKey ? 'selected' : '' ?>><?= htmlspecialchars($opLabel) ?></option>
                <?php endforeach; ?>
            </select>
            <input name="stock" id="stock_val" type="number" value="<?= htmlspecialchars($fStockVal !== null && $fStockOp !== 'low' ? (string) $fStockVal : '') ?>" placeholder="np. 2" style="width:70px<?= in_array($fStockOp, ['', 'none', 'low', 'untracked'], true) ? ';display:none' : '' ?>">
        </span>
        <?php if ($locations !== []): ?>
        <span class="stock-filter" title="Lokalizacja w magazynie (Magazyn → Lokalizacje)">
            <span style="font-size:13px;color:#666">📍</span>
            <?= locPicker('loc', $fLoc === null ? '' : ($fLoc === 0 ? 'none' : (string) $fLoc),
                ['' => '— każda lokalizacja —', 'none' => 'bez lokalizacji'], ['id' => 'loc', 'submit' => true, 'counts' => true]) ?>
        </span>
        <?php endif; ?>
        <button class="btn secondary" type="submit">Szukaj</button>
        <?php if ($q !== '' || $fCategory !== '' || $fShopRaw !== '' || $stockFilterOn): ?><a class="btn secondary" href="products.php">Wyczyść</a><?php endif; ?>
        <a class="btn" href="import_products.php" style="margin-left:10px">⬇ Importuj z WooCommerce</a>
        <span style="color:#888;font-size:13px;margin-left:10px"><?= ($q !== '' || $fCategory !== '' || $fShopRaw !== '' || $stockFilterOn) ? 'Znaleziono' : 'W magazynie' ?>: <strong><?= $total ?></strong></span>
        <?php
        // Szybkie filtry stanu - zachowują pozostałe filtry (szukaj/kategoria/sklep).
        $quick = [
            'Brak (0)'                         => ['stock_op' => 'lte', 'stock' => 0],
            'Mniej niż 2'                      => ['stock_op' => 'lt', 'stock' => 2],
            'Niski stan (≤ minimum)'           => ['stock_op' => 'low', 'stock' => ''],
            'Bez ustawionego stanu'            => ['stock_op' => 'none', 'stock' => ''],
            'Nie pilnowane'                    => ['stock_op' => 'untracked', 'stock' => ''],
        ];
        $baseQs = array_diff_key($_GET, ['stock_op' => 1, 'stock' => 1, 'p' => 1]);
        ?>
        <div class="stock-chips">
            <?php foreach ($quick as $qLabel => $qv):
                $active = $fStockOp === $qv['stock_op'] && (in_array($qv['stock_op'], ['none', 'low', 'untracked'], true) || $fStockVal === $qv['stock']); ?>
                <a class="stock-chip<?= $active ? ' on' : '' ?>" href="products.php?<?= htmlspecialchars(http_build_query($active ? $baseQs : $baseQs + $qv)) ?>"><?= htmlspecialchars($qLabel) ?><?= $active ? ' ✕' : '' ?></a>
            <?php endforeach; ?>
            <?php if ($canEdit): ?>
                <a class="stock-chip" href="#" onclick="var f=document.getElementById('lowDefaultForm');f.hidden=!f.hidden;return false"
                   title="Minimum dla produktów bez własnego minimum (własne ustawisz w podglądzie produktu albo zbiorczo dla zaznaczonych)">⚙ Próg domyślny: <?= $lowDefault === null ? 'wyłączony' : $lowDefault ?></a>
            <?php endif; ?>
        </div>
    </form>
    <?php if ($canEdit): ?>
    <form method="post" id="lowDefaultForm" hidden style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0 0">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="low_default">
        <label for="lowDefaultVal" style="font-size:13px;color:#666">Niski stan, gdy stan ≤</label>
        <input id="lowDefaultVal" name="low_default" type="number" min="0" value="<?= $lowDefault === null ? '' : $lowDefault ?>" placeholder="wyłączony" style="width:90px">
        <button class="btn secondary" type="submit">Zapisz</button>
        <span style="font-size:12px;color:#888">Dotyczy produktów bez własnego minimum. Puste = alarm tylko dla produktów z własnym minimum.</span>
    </form>
    <?php if ($fStockOp === 'low' && $total > 0): ?>
    <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0 0"
          onsubmit="return confirm('Przestać pilnować stanu wszystkich produktów ze stanem 0, które nie mają własnego minimum? Cofniesz to filtrem „Nie pilnowane” i akcją „Minimum zaznaczonych”.')">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="untrack_zero">
        <button class="btn secondary" type="submit">Nie pilnuj produktów ze stanem 0</button>
        <span style="font-size:12px;color:#888">Np. wycofane ze sprzedaży - znikną z alertu, dopóki nie ustawisz im minimum.</span>
    </form>
    <?php endif; ?>
    <?php endif; ?>
    <style>
    .stock-filter{display:inline-flex;align-items:center;gap:6px;margin-left:6px}
    .stock-chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}
    .stock-chip{font-size:12px;padding:4px 10px;border-radius:999px;border:1px solid #e5e2da;color:#555;text-decoration:none;background:#fff}
    .stock-chip:hover{background:#f5f3ee}
    .stock-chip.on{background:#8a6a3b;border-color:#8a6a3b;color:#fff}
    #lowDefaultForm[hidden]{display:none!important}
    </style>

    <?php if ($canEdit && $rows !== []): ?>
    <!-- Formularze (poza tabelą; pola w wierszach podpięte atrybutem form=). -->
    <form method="post" id="saveAllForm">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="save_all">
    </form>
    <form method="post" id="bulkDelForm" onsubmit="return document.querySelectorAll('.prod-check:checked').length ? confirm('Usunąć zaznaczone produkty z magazynu?') : (alert('Zaznacz produkty do usunięcia.'),false)">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="bulk_delete">
    </form>
    <form method="post" id="delOneForm" onsubmit="return confirm('Usunąć ten produkt z magazynu?')">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="delOneId" value="">
    </form>
    <form method="post" id="bulkStockForm" onsubmit="return prepareBulkStockSubmit()">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="bulk_stock">
        <span id="bulkStockIdsContainer"></span>
    </form>
    <form method="post" id="bulkMinForm" onsubmit="return prepareBulkIds('bulkMinIds', 'Ustawić minimalny stan dla')">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="bulk_min">
        <span id="bulkMinIds"></span>
    </form>
    <div style="display:flex;gap:8px;margin-bottom:10px;align-items:center;flex-wrap:wrap">
        <button class="btn" type="submit" form="saveAllForm">💾 Zapisz wszystkie zmiany</button>
        <button class="btn danger" type="submit" form="bulkDelForm">🗑 Usuń zaznaczone</button>
        <span style="display:inline-flex;gap:6px;align-items:center;border-left:1px solid #e5e7eb;padding-left:12px;margin-left:4px">
            <span style="font-size:13px;color:#888">Stan zaznaczonych:</span>
            <select name="mode" form="bulkStockForm" style="width:auto">
                <option value="set">Ustaw na</option>
                <option value="delta">Zmień o (+/-)</option>
            </select>
            <input name="value" form="bulkStockForm" type="number" required style="width:90px" placeholder="np. 20">
            <button class="btn secondary" type="submit" form="bulkStockForm">Zastosuj</button>
        </span>
        <span style="display:inline-flex;gap:6px;align-items:center;border-left:1px solid #e5e7eb;padding-left:12px;margin-left:4px"
              title="Alarm niskiego stanu, gdy stan spadnie do tej liczby. Puste = próg domyślny.">
            <span style="font-size:13px;color:#888">Minimum zaznaczonych:</span>
            <select name="min_mode" form="bulkMinForm" style="width:auto" onchange="document.getElementById('bulkMinVal').style.display = this.value === 'off' ? 'none' : ''">
                <option value="set">Ustaw</option>
                <option value="off">Nie pilnuj stanu</option>
            </select>
            <input name="min" id="bulkMinVal" form="bulkMinForm" type="number" min="0" style="width:95px" placeholder="<?= $lowDefault === null ? 'brak' : 'dom. ' . $lowDefault ?>">
            <button class="btn secondary" type="submit" form="bulkMinForm">Ustaw</button>
        </span>
        <?php if ($locations !== []): ?>
        <span style="display:inline-flex;gap:6px;align-items:center;border-left:1px solid #e5e7eb;padding-left:12px;margin-left:4px">
            <span style="font-size:13px;color:#888">📍 Lokalizacja zaznaczonych:</span>
            <?= locPicker('location_id', '', ['' => '— usuń lokalizację —'], ['form' => 'bulkLocForm', 'required' => true, 'id' => 'bulkLocValue']) ?>
            <button class="btn secondary" type="submit" form="bulkLocForm">Ustaw</button>
        </span>
        <?php endif; ?>
    </div>
    <form method="post" id="bulkLocForm" onsubmit="return locPickerChosen(document.getElementById('bulkLocValue'), 'Wybierz lokalizację dla zaznaczonych produktów.') &amp;&amp; prepareBulkIds('bulkLocIds', 'Ustawić lokalizację dla')">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="bulk_location">
        <span id="bulkLocIds"></span>
    </form>
    <?php endif; ?>

    <table>
        <tr>
            <?php if ($canEdit): ?><th style="width:28px"><input type="checkbox" onclick="document.querySelectorAll('.prod-check').forEach(c=>c.checked=this.checked)"></th><?php endif; ?>
            <th style="width:48px"></th>
            <th>SKU</th><th>Nazwa</th><th>Kanały</th><?php if ($locations !== []): ?><th>📍 Lokalizacja</th><?php endif; ?><th>Stan (CRM)</th><?php if ($useActual): ?><th title="Ile naprawdę masz na półce. Tylko w CRM - nie idzie do sklepu ani na Allegro.">Stan faktyczny</th><?php endif; ?><th>Cena (CRM)</th><th>Woo ID</th><th></th><?php if ($canEdit): ?><th></th><?php endif; ?>
        </tr>
        <?php if ($rows === [] && !$noTable): ?>
            <tr><td colspan="<?= $useActual ? 12 : 11 ?>" style="color:#888">Brak produktów. Kliknij „Importuj z WooCommerce”.</td></tr>
        <?php endif; ?>
        <?php $prevParent = null; foreach ($rows as $r): $pid = (int)$r['id'];
            $wpid = (int) ($r['woo_product_id'] ?? 0);
            $groupSize = $wpid > 0 ? ($parentCounts[$wpid] ?? 1) : 1;
            $isGrouped = $groupSize > 1;
            $isFirstOfGroup = $isGrouped && $wpid !== $prevParent;
            $prevParent = $wpid;
        ?>
            <?php if ($isFirstOfGroup): ?>
            <tr style="background:#f3ede0">
                <td colspan="<?= $useActual ? 12 : 11 ?>" style="font-weight:600;padding:10px 8px">
                    <?= htmlspecialchars($r['name'] ?? '—') ?> <span class="pill muted" style="font-size:11px;font-weight:400">×<?= $groupSize ?> warianty</span>
                </td>
            </tr>
            <?php endif; ?>
            <tr<?= $isGrouped ? ' style="background:#fdf8ef"' : '' ?>>
                <?php if ($canEdit): ?><td><input type="checkbox" class="prod-check" name="ids[]" value="<?= $pid ?>" form="bulkDelForm"></td><?php endif; ?>
                <td>
                    <?php
                        $thumbUrl = null;
                        $imgs = json_decode((string) ($r['images'] ?? ''), true);
                        if (is_array($imgs) && $imgs !== []) {
                            $thumbUrl = is_array($imgs[0]) ? ($imgs[0]['src'] ?? null) : $imgs[0];
                        }
                    ?>
                    <a href="product_view.php?id=<?= $pid ?>">
                        <?php if ($thumbUrl !== null): ?>
                            <img src="<?= htmlspecialchars($thumbUrl) ?>" alt="" loading="lazy" style="width:40px;height:40px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;display:block">
                        <?php else: ?>
                            <span style="display:flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:6px;background:#f1f3f4;color:#bbb;font-size:16px">—</span>
                        <?php endif; ?>
                    </a>
                </td>
                <td><a href="product_view.php?id=<?= $pid ?>" title="Podgląd produktu" style="color:inherit"><strong><?= htmlspecialchars($r['sku']) ?></strong></a></td>
                <td>
                    <?php if ($isGrouped):
                        $label = $variantLabel($r);
                    ?>
                        <span style="color:#888;padding-left:16px">↳ <?= $label !== null ? htmlspecialchars($label) : htmlspecialchars($r['name'] ?? '—') ?></span>
                    <?php else: ?>
                        <?= htmlspecialchars($r['name'] ?? '—') ?>
                    <?php endif; ?>
                    <?php if (!empty($r['is_virtual'])): ?><span class="pill muted" style="font-size:11px;font-weight:400" title="Produkt wirtualny - bez wysyłki">wirtualny</span><?php endif; ?>
                </td>
                <td style="white-space:nowrap"><?= $channelBadges($r) ?></td>
                <?php if ($locations !== []): $curLoc = isset($r['location_id']) ? (int) $r['location_id'] : 0; ?>
                <td style="white-space:nowrap">
                    <?php if ($canEdit): ?>
                        <input type="hidden" name="loc_old[<?= $pid ?>]" form="saveAllForm" value="<?= $curLoc ?: '' ?>">
                        <?= locPicker('loc[' . $pid . ']', $curLoc ? (string) $curLoc : '', ['' => '—'], ['form' => 'saveAllForm']) ?>
                    <?php else: ?>
                        <?= $curLoc && isset($locById[$curLoc]) ? '<span class="pill ok">📍 ' . htmlspecialchars($locById[$curLoc]['code']) . '</span>' : '<span style="color:#aaa">—</span>' ?>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
                <td>
                    <?php
                        $stockVal = $r['pase_stock'];
                        $minVal = \Pase\Services\LowStock::minFor($r, $lowDefault);
                        $effStock = \Pase\Services\LowStock::stockFor($r, $useActual);   // faktyczny, gdy włączony i wpisany
                        $stockWarn = $effStock !== null && $effStock <= 0
                            ? 'bad'
                            : (\Pase\Services\LowStock::isLow($r, $lowDefault, $useActual) ? 'warn' : null);
                    ?>
                    <?php if ($canEdit): ?>
                        <input name="stock[<?= $pid ?>]" form="saveAllForm" type="number" value="<?= htmlspecialchars((string)($stockVal ?? '')) ?>" style="width:80px<?= $stockWarn ? ';border-color:' . ($stockWarn === 'bad' ? '#c5221f' : '#b06000') : '' ?>">
                    <?php else: ?>
                        <?= htmlspecialchars((string)($stockVal ?? '—')) ?>
                    <?php endif; ?>
                    <?php if ($stockWarn === 'bad'): ?>
                        <span class="pill bad" style="margin-left:6px">Brak</span>
                    <?php elseif ($stockWarn === 'warn'): ?>
                        <span class="pill warn" style="margin-left:6px" title="Minimum: <?= (int) $minVal ?>">Niski</span>
                    <?php endif; ?>
                    <?php if (isset($r['min_stock'])): ?>
                        <div style="font-size:11px;color:#888;margin-top:2px"><?= (int) $r['min_stock'] === \Pase\Services\LowStock::OFF ? 'nie pilnowany' : 'min. ' . (int) $r['min_stock'] ?></div>
                    <?php endif; ?>
                </td>
                <?php if ($useActual): ?>
                <td>
                    <?php $actualVal = $r['actual_stock'] ?? null; ?>
                    <?php if ($canEdit): ?>
                        <input type="hidden" name="actual_old[<?= $pid ?>]" form="saveAllForm" value="<?= htmlspecialchars((string) ($actualVal ?? '')) ?>">
                        <input name="actual[<?= $pid ?>]" form="saveAllForm" type="number" min="0" value="<?= htmlspecialchars((string) ($actualVal ?? '')) ?>" placeholder="—" style="width:80px">
                    <?php else: ?>
                        <?= htmlspecialchars((string) ($actualVal ?? '—')) ?>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
                <td><?php if ($canEdit): ?><input name="price[<?= $pid ?>]" form="saveAllForm" type="number" step="0.01" value="<?= htmlspecialchars((string)($r['pase_price'] ?? '')) ?>" style="width:100px"> zł<?php else: ?><?= htmlspecialchars((string)($r['pase_price'] ?? '—')) ?> zł<?php endif; ?></td>
                <td style="font-size:12px;color:#888"><?= (int)($r['woo_product_id'] ?? 0) ?: '—' ?></td>
                <td><a class="btn secondary" href="product_view.php?id=<?= $pid ?>">Podgląd</a></td>
                <?php if ($canEdit): ?>
                <td style="text-align:right">
                    <button class="btn danger" type="button" onclick="document.getElementById('delOneId').value=<?= $pid ?>;document.getElementById('delOneForm').requestSubmit();">Usuń</button>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
    </table>

    <?php if (!$noTable) { $pagerPage = $page; $pagerPages = $pages; $pagerLink = $link; require __DIR__ . '/_pager.php'; } ?>
    <?php if ($canEdit && $rows !== []): ?>
        <div style="margin-top:14px"><button class="btn" type="submit" form="saveAllForm">💾 Zapisz wszystkie zmiany</button></div>
    <?php endif; ?>
    <p style="color:#888;font-size:12px;margin-top:10px;text-align:center"><?= PER_PAGE ?> na stronę.</p>
</div>

<?php if ($canEdit && $rows !== []): ?>
<script>
function prepareBulkIds(containerId, question) {
    var checked = Array.from(document.querySelectorAll('.prod-check:checked'));
    if (checked.length === 0) { alert('Zaznacz produkty.'); return false; }
    var container = document.getElementById(containerId);
    container.innerHTML = '';
    checked.forEach(function (c) { var h = document.createElement('input'); h.type = 'hidden'; h.name = 'ids[]'; h.value = c.value; container.appendChild(h); });
    return confirm(question + ' ' + checked.length + ' zaznaczonych produktów?');
}
function prepareBulkStockSubmit() {
    var checked = Array.from(document.querySelectorAll('.prod-check:checked'));
    if (checked.length === 0) {
        alert('Zaznacz produkty, którym chcesz zmienić stan.');
        return false;
    }
    var container = document.getElementById('bulkStockIdsContainer');
    container.innerHTML = '';
    checked.forEach(function (checkbox) {
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'ids[]';
        hidden.value = checkbox.value;
        container.appendChild(hidden);
    });
    return confirm('Zastosować zmianę stanu do ' + checked.length + ' zaznaczonych produktów?');
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
