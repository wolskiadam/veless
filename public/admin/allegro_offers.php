<?php
declare(strict_types=1);

/**
 * Zarządzanie ofertami Allegro — lista ofert sprzedawcy (GET /sale/offers): filtr statusu,
 * wyszukiwanie po tytule, paginacja, szybka zmiana stanu oraz OPERACJE ZBIORCZE na zaznaczonych
 * ofertach (jak w BaseLinkerze): cena, ilość, tytuł, opis, cennik wysyłek, duplikowanie,
 * zakończenie/wznowienie, powiązanie z magazynem, eksport CSV. Logika: AllegroOfferOperations.
 */

use Pase\Services\AllegroOfferOperations;
use PasePlugin\Allegro\AllegroPlugin;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$config = require PASE_ROOT . '/config/config.php';
$client = AllegroPlugin::makeClient($pdo, $config['allegro'] ?? []);
$ops    = new AllegroOfferOperations($pdo, $client);

$status = preg_replace('/[^A-Z]/', '', strtoupper((string) ($_GET['status'] ?? 'ACTIVE'))) ?: 'ACTIVE';
$q      = trim((string) ($_GET['q'] ?? ''));
$page   = max(1, (int) ($_GET['p'] ?? 1));
$limit  = 50;
// Filtry (wszystkie opcjonalne) - lista pobierana w całości i filtrowana w panelu, bo API Allegro
// nie filtruje m.in. po ilości ani czasie wysyłki.
$f = [
    'price_min' => trim((string) ($_GET['price_min'] ?? '')), 'price_max' => trim((string) ($_GET['price_max'] ?? '')),
    'stock_min' => trim((string) ($_GET['stock_min'] ?? '')), 'stock_max' => trim((string) ($_GET['stock_max'] ?? '')),
    'rate'      => trim((string) ($_GET['rate'] ?? '')),      'handling'  => trim((string) ($_GET['handling'] ?? '')),
    'linked'    => in_array($_GET['linked'] ?? '', ['yes', 'no'], true) ? (string) $_GET['linked'] : '',
    'sold'      => in_array($_GET['sold'] ?? '', ['yes', 'no'], true) ? (string) $_GET['sold'] : '',
    'sort'      => in_array($_GET['sort'] ?? '', ['name', 'price', '-price', 'stock', '-stock', '-sold'], true) ? (string) $_GET['sort'] : '',
];
$filterQs = http_build_query(array_filter(['q' => $q] + $f, static fn($v) => $v !== ''));
$selfQs = 'allegro_offers.php?status=' . urlencode($status) . ($filterQs !== '' ? '&' . $filterQs : '') . '&p=' . $page;

// Czasy wysyłki dla ofert (AJAX z listy): z Allegro, zapis w pamięci podręcznej.
if (isset($_GET['details'])) {
    header('Content-Type: application/json');
    $ids = array_slice(array_values(array_filter(explode(',', (string) ($_GET['ids'] ?? '')), static fn($i) => ctype_digit($i))), 0, 10);
    $out = [];
    foreach ($ops->fetchDetails($ids) as $id => $h) {
        $out[$id] = ['raw' => $h, 'label' => $h !== null ? AllegroOfferOperations::handlingLabel($h) : '—'];
    }
    echo json_encode($out);
    exit;
}

// Powody statusu nieaktywnych ofert (AJAX z listy): błędy walidacji oferty z Allegro.
if (isset($_GET['issues'])) {
    header('Content-Type: application/json');
    $ids = array_slice(array_values(array_filter(explode(',', (string) ($_GET['ids'] ?? '')), static fn($i) => ctype_digit($i))), 0, 10);
    echo json_encode($ops->fetchIssues($ids));
    exit;
}

// Obecne informacje o bezpieczeństwie jednej oferty (AJAX z okna operacji) - do poprawienia w CRM.
if (isset($_GET['safety'])) {
    header('Content-Type: application/json');
    $sid = preg_replace('/\D/', '', (string) ($_GET['id'] ?? ''));
    echo json_encode($sid !== '' ? $ops->currentSafetyInfo($sid) : ['ok' => false, 'type' => '', 'text' => '', 'message' => 'Brak ID oferty.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEdit()) {
    csrfCheck();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'price_one') {
        // Szybka zmiana ceny jednej oferty (okienko po kliknięciu ceny) - odpowiedź JSON dla JS.
        header('Content-Type: application/json');
        $offerId = preg_replace('/\D/', '', (string) ($_POST['offer_id'] ?? ''));
        $price   = round((float) str_replace([',', ' '], ['.', ''], (string) ($_POST['price'] ?? '')), 2);
        $curr    = preg_replace('/[^A-Z]/', '', (string) ($_POST['currency'] ?? 'PLN')) ?: 'PLN';
        if ($offerId === '' || $price < 1) {
            echo json_encode(['ok' => false, 'message' => 'Podaj cenę (min. 1,00).']);
            exit;
        }
        $r = $client->patchOffer($offerId, ['sellingMode' => ['price' => ['amount' => number_format($price, 2, '.', ''), 'currency' => $curr]]]);
        if ($r['ok']) {
            $_SESSION['al_pending'][$offerId] = ['price' => number_format($price, 2, '.', ''), 'at' => time()] + ($_SESSION['al_pending'][$offerId] ?? []);
            $_SESSION['al_pending'][$offerId]['at'] = time();
        }
        echo json_encode(['ok' => $r['ok'], 'message' => $r['message'], 'price' => number_format($price, 2, ',', ' ') . ' ' . $curr, 'raw' => number_format($price, 2, '.', '')]);
        exit;
    }

    if ($action === 'stock_one') {
        // Szybka zmiana stanu jednej oferty (pole w wierszu).
        $offerId = trim((string) ($_POST['offer_id'] ?? ''));
        $qty     = (int) ($_POST['stock'] ?? 0);
        if ($offerId !== '') {
            if ($client->updateOfferQuantity($offerId, $qty)) {
                flash("Zaktualizowano stan oferty {$offerId} na {$qty} szt.");
                $_SESSION['al_pending'][$offerId] = ['stock' => $qty, 'at' => time()] + ($_SESSION['al_pending'][$offerId] ?? []);
            } else {
                flash("Nie udało się zaktualizować stanu oferty {$offerId}.", 'err');
            }
        }
        header('Location: ' . $selfQs, true, 303);
        exit;
    }

    if ($action === 'bulk') {
        $op  = (string) ($_POST['op'] ?? '');
        $ids = array_map('strval', (array) ($_POST['ids'] ?? []));
        if (!isset(AllegroOfferOperations::OPERATIONS[$op])) {
            flash('Nieznana operacja.', 'err');
        } elseif ($op === 'export') {
            // CSV zaznaczonych ofert (Excel: średnik + BOM).
            $rows = $ops->offersById($ids);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="oferty-allegro-' . date('Y-m-d') . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['ID oferty', 'Tytuł', 'Sygnatura (SKU)', 'Cena', 'Waluta', 'Ilość', 'Sprzedane', 'Status', 'Link'], ';');
            foreach ($ids as $id) {
                $o = $rows[$id] ?? null;
                if ($o === null) { continue; }
                fputcsv($out, [$id, $o['name'] ?? '', $o['external']['id'] ?? '', $o['sellingMode']['price']['amount'] ?? '',
                    $o['sellingMode']['price']['currency'] ?? '', $o['stock']['available'] ?? '', $o['stock']['sold'] ?? '',
                    $o['publication']['status'] ?? '', 'https://allegro.pl/oferta/' . $id], ';');
            }
            fclose($out);
            exit;
        } else {
            @set_time_limit(300);
            $params = array_map(static fn($v) => is_string($v) ? trim($v) : $v, (array) ($_POST['p'] ?? []));
            $r = $ops->run($op, $ids, $params);
            // Lista ofert Allegro (GET /sale/offers) pokazuje zmiany z opóźnieniem - zapamiętujemy nowe
            // wartości na kilka minut i nakładamy je na listę, dopóki Allegro jej nie odświeży.
            foreach ($r['applied'] as $aid => $vals) {
                if (isset($vals['handling'])) {
                    $ops->storeDetails((string) $aid, ['handling_time' => $vals['handling']]);
                }
                if (isset($vals['rate'])) {
                    $ops->storeDetails((string) $aid, ['shipping_rate_id' => $vals['rate']]);
                }
                $_SESSION['al_pending'][(string) $aid] = $vals + ['at' => time()] + ($_SESSION['al_pending'][(string) $aid] ?? []);
                $_SESSION['al_pending'][(string) $aid]['at'] = time();
            }
            $label = AllegroOfferOperations::OPERATIONS[$op][0];
            $msg = "{$label}: zmieniono {$r['ok']} z " . count($ids) . ' ofert.' . ($r['info'] !== '' ? ' ' . $r['info'] : '');
            flash($msg, $r['fail'] > 0 && $r['ok'] === 0 ? 'err' : 'ok');
            if ($r['errors']) {
                flash('Błędy (' . $r['fail'] . '): ' . implode(' · ', array_slice($r['errors'], 0, 15)) . (count($r['errors']) > 15 ? ' …' : ''), 'err');
            }
        }
        header('Location: ' . $selfQs, true, 303);
        exit;
    }
}

$result = $ops->allOffers($status);
$offers = $result['offers'];

// Nałożenie świeżo zapisanych zmian (max 10 min), dopóki lista Allegro ich nie pokaże.
$pending = [];
foreach ((array) ($_SESSION['al_pending'] ?? []) as $pid => $vals) {
    if (($vals['at'] ?? 0) < time() - 600) {
        unset($_SESSION['al_pending'][$pid]);
        continue;
    }
    $pending[(string) $pid] = $vals;
}
foreach ($offers as &$o) {
    $pid = (string) ($o['id'] ?? '');
    if (!isset($pending[$pid])) {
        continue;
    }
    $pv = $pending[$pid];
    $stale = false;
    if (isset($pv['price']) && (float) ($o['sellingMode']['price']['amount'] ?? 0) !== (float) $pv['price']) {
        $o['sellingMode']['price']['amount'] = $pv['price'];
        $stale = true;
    }
    if (isset($pv['stock']) && (int) ($o['stock']['available'] ?? -1) !== (int) $pv['stock']) {
        $o['stock']['available'] = $pv['stock'];
        $stale = true;
    }
    if (isset($pv['name']) && ($o['name'] ?? '') !== $pv['name']) {
        $o['name'] = $pv['name'];
        $stale = true;
    }
    if (isset($pv['status']) && ($o['publication']['status'] ?? '') !== $pv['status']) {
        $o['publication']['status'] = $pv['status'];
        $stale = true;
    }
    if (isset($pv['rate']) && ($o['delivery']['shippingRates']['id'] ?? '') !== $pv['rate']) {
        $o['delivery']['shippingRates']['id'] = $pv['rate'];
        $stale = true;
    }
    if ($stale) {
        $o['__pending'] = true;
    } else {
        unset($_SESSION['al_pending'][$pid]); // Allegro już pokazuje nowe dane
    }
}
unset($o);

// --- Czas wysyłki z pamięci podręcznej + powiązania z magazynem ---
$details = $ops->details(array_map(static fn($o) => (string) ($o['id'] ?? ''), $offers));
$linkedIds = $skus = [];
try {
    foreach ($pdo->query('SELECT allegro_offer_id FROM product_mappings WHERE allegro_offer_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $lid) {
        $linkedIds[(string) $lid] = true;
    }
    foreach ($pdo->query('SELECT sku FROM products')->fetchAll(PDO::FETCH_COLUMN) as $sk) {
        $skus[(string) $sk] = true;
    }
} catch (\Throwable $ex) {
    // brak tabel - bez informacji o powiązaniu
}
$missingHandling = [];
foreach ($offers as &$o) {
    $oid = (string) ($o['id'] ?? '');
    $o['__handling'] = $details[$oid]['handling_time'] ?? null;
    $o['__handling_known'] = isset($details[$oid]);
    $sigTmp = (string) ($o['external']['id'] ?? '');
    $o['__linked'] = isset($linkedIds[$oid]) || ($sigTmp !== '' && isset($skus[$sigTmp]));
    if (!$o['__handling_known']) {
        $missingHandling[] = $oid;
    }
}
unset($o);

// --- Filtry i sortowanie ---
$num = static fn(string $v): ?float => $v === '' ? null : (float) str_replace(',', '.', $v);
$qLow = mb_strtolower($q);
$offers = array_values(array_filter($offers, static function (array $o) use ($f, $num, $qLow): bool {
    $price = (float) ($o['sellingMode']['price']['amount'] ?? 0);
    $stock = (int) ($o['stock']['available'] ?? 0);
    $sold  = (int) ($o['stock']['sold'] ?? 0);
    $rate  = (string) ($o['delivery']['shippingRates']['id'] ?? '');
    if ($qLow !== '' && !str_contains(mb_strtolower(($o['name'] ?? '') . ' ' . ($o['id'] ?? '') . ' ' . ($o['external']['id'] ?? '')), $qLow)) { return false; }
    if (($v = $num($f['price_min'])) !== null && $price < $v) { return false; }
    if (($v = $num($f['price_max'])) !== null && $price > $v) { return false; }
    if (($v = $num($f['stock_min'])) !== null && $stock < $v) { return false; }
    if (($v = $num($f['stock_max'])) !== null && $stock > $v) { return false; }
    if ($f['rate'] === '__none' && $rate !== '') { return false; }
    if ($f['rate'] !== '' && $f['rate'] !== '__none' && $rate !== $f['rate']) { return false; }
    if ($f['handling'] === '__unknown' && $o['__handling_known']) { return false; }
    if ($f['handling'] !== '' && $f['handling'] !== '__unknown' && !AllegroOfferOperations::sameHandling($o['__handling'], $f['handling'])) { return false; }
    if ($f['linked'] === 'yes' && !$o['__linked']) { return false; }
    if ($f['linked'] === 'no' && $o['__linked']) { return false; }
    if ($f['sold'] === 'yes' && $sold < 1) { return false; }
    if ($f['sold'] === 'no' && $sold > 0) { return false; }
    return true;
}));
if ($f['sort'] !== '') {
    $sortKey = ltrim($f['sort'], '-');
    $key = static fn(array $o) => match ($sortKey) {
        'price' => (float) ($o['sellingMode']['price']['amount'] ?? 0),
        'stock' => (int) ($o['stock']['available'] ?? 0),
        'sold'  => (int) ($o['stock']['sold'] ?? 0),
        default => mb_strtolower((string) ($o['name'] ?? '')),
    };
    $dir = str_starts_with($f['sort'], '-') ? -1 : 1;
    usort($offers, static fn($a, $b) => $dir * ($key($a) <=> $key($b)));
}
$total  = count($offers);
$pages  = max(1, (int) ceil($total / $limit));
$page   = min($page, $pages);
$offers = array_slice($offers, ($page - 1) * $limit, $limit);
$rates  = $client->shippingRates();
// Osoby odpowiedzialne (GPSR) do operacji zbiorczej - pamiętane 10 min, żeby nie pytać Allegro przy każdym odświeżeniu.
$pc = $_SESSION['al_persons_cache'] ?? null;
if (!is_array($pc) || ($pc['t'] ?? 0) < time() - 600) {
    $pr = $client->responsiblePersons();
    $pc = ['t' => time(), 'list' => $pr['ok'] ? $pr['persons'] : []];
    if ($pr['ok']) {
        $_SESSION['al_persons_cache'] = $pc;
    }
}
$persons = $pc['list'];
$pc = $_SESSION['al_producers_cache'] ?? null;
if (!is_array($pc) || ($pc['t'] ?? 0) < time() - 600) {
    $pr = $client->responsibleProducers();
    $pc = ['t' => time(), 'list' => $pr['ok'] ? $pr['producers'] : []];
    if ($pr['ok']) {
        $_SESSION['al_producers_cache'] = $pc;
    }
}
$producers = $pc['list'];
$rateNames = [];
foreach ($rates as $r) {
    $rateNames[(string) ($r['id'] ?? '')] = (string) ($r['name'] ?? '');
}
$activeFilters = count(array_filter($f, static fn($v) => $v !== '')) - ($f['sort'] !== '' ? 1 : 0);

$e = static fn($v) => htmlspecialchars((string) $v);
$qsStatus = static fn(string $s): string => 'allegro_offers.php?status=' . urlencode($s) . ($filterQs !== '' ? '&' . $filterQs : '');

$statuses = [
    'ACTIVE'    => 'Aktywne',
    'INACTIVE'  => 'Nieaktywne',
    'ENDED'     => 'Zakończone',
    'ACTIVATING'=> 'Aktywowane',
];
$pubLabels = ['ACTIVE' => 'aktywna', 'INACTIVE' => 'nieaktywna', 'ENDED' => 'zakończona', 'ACTIVATING' => 'aktywowana'];

$PAGE_TITLE = 'Zarządzanie ofertami Allegro';
$PAGE_KEY   = 'allegro';
require __DIR__ . '/header.php';
?>

<?php if (!$result['ok']): ?><div class="flash err"><?= $e($result['message']) ?> <a href="allegro_connect.php">Połącz konto Allegro →</a></div><?php endif; ?>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <strong>🛒 Oferty Allegro</strong>
        <div class="al-tabs">
            <?php foreach ($statuses as $key => $label): ?>
                <a href="<?= $e($qsStatus($key)) ?>" class="al-tab <?= $status === $key ? 'on' : '' ?>"><?= $e($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="al-bar">
        <?php if (canEdit() && $offers !== []): ?>
        <div class="al-ops">
            <label class="al-selall" title="Zaznacz wszystkie na stronie"><input type="checkbox" id="selAll"> <span id="selCount">0</span>/<?= count($offers) ?></label>
            <button type="button" class="al-ops-btn" id="opsBtn" aria-expanded="false">OPERACJE ▾</button>
            <div class="al-ops-menu" id="opsMenu" hidden>
                <div>
                    <h4>Zaktualizuj trwające oferty</h4>
                    <?php foreach (AllegroOfferOperations::OPERATIONS as $k => [$lbl, $grp]): if ($grp !== 'update') continue; ?>
                        <button type="button" data-op="<?= $k ?>"><?= $e($lbl) ?></button>
                    <?php endforeach; ?>
                    <?= \Pase\Plugin\Hooks::render('allegro_offers.operations', ['csrf' => csrfToken()]) ?>
                </div>
                <div>
                    <h4>Wystaw</h4>
                    <?php foreach (AllegroOfferOperations::OPERATIONS as $k => [$lbl, $grp]): if ($grp !== 'list') continue; ?>
                        <button type="button" data-op="<?= $k ?>"><?= $e($lbl) ?></button>
                    <?php endforeach; ?>
                    <h4 style="margin-top:14px">Inne</h4>
                    <?php foreach (AllegroOfferOperations::OPERATIONS as $k => [$lbl, $grp]): if ($grp !== 'other') continue; ?>
                        <button type="button" data-op="<?= $k ?>"><?= $e($lbl) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <form method="get" class="al-search">
            <input type="hidden" name="status" value="<?= $e($status) ?>">
            <input name="q" value="<?= $e($q) ?>" placeholder="Szukaj: tytuł, ID, SKU…">
            <button class="btn secondary" type="button" onclick="var x=document.getElementById('alFilters');x.hidden=!x.hidden">⚙ Filtry<?= $activeFilters > 0 ? ' (' . $activeFilters . ')' : '' ?></button>
            <button class="btn secondary" type="submit">Szukaj</button>
            <div class="al-filters" id="alFilters" <?= $activeFilters > 0 ? '' : 'hidden' ?>>
                <div><label>Cena od – do (zł)</label><span class="al-range"><input name="price_min" value="<?= $e($f['price_min']) ?>" inputmode="decimal"> – <input name="price_max" value="<?= $e($f['price_max']) ?>" inputmode="decimal"></span></div>
                <div><label>Ilość od – do</label><span class="al-range"><input name="stock_min" value="<?= $e($f['stock_min']) ?>" inputmode="numeric"> – <input name="stock_max" value="<?= $e($f['stock_max']) ?>" inputmode="numeric"></span></div>
                <div><label>Cennik dostaw</label>
                    <select name="rate"><option value="">wszystkie</option><option value="__none" <?= $f['rate'] === '__none' ? 'selected' : '' ?>>bez cennika</option>
                        <?php foreach ($rates as $r): $rid = (string) ($r['id'] ?? ''); ?><option value="<?= $e($rid) ?>" <?= $f['rate'] === $rid ? 'selected' : '' ?>><?= $e($r['name'] ?? $rid) ?></option><?php endforeach; ?>
                    </select></div>
                <div><label>Czas wysyłki</label>
                    <select name="handling"><option value="">wszystkie</option>
                        <?php foreach (AllegroOfferOperations::HANDLING_TIMES as $hk => $hl): ?><option value="<?= $hk ?>" <?= $f['handling'] === $hk ? 'selected' : '' ?>><?= $e($hl) ?></option><?php endforeach; ?>
                        <option value="__unknown" <?= $f['handling'] === '__unknown' ? 'selected' : '' ?>>jeszcze nie pobrany</option>
                    </select></div>
                <div><label>Powiązanie z magazynem</label>
                    <select name="linked"><option value="">wszystkie</option><option value="yes" <?= $f['linked'] === 'yes' ? 'selected' : '' ?>>powiązane</option><option value="no" <?= $f['linked'] === 'no' ? 'selected' : '' ?>>niepowiązane</option></select></div>
                <div><label>Sprzedaż</label>
                    <select name="sold"><option value="">wszystkie</option><option value="yes" <?= $f['sold'] === 'yes' ? 'selected' : '' ?>>ze sprzedażą</option><option value="no" <?= $f['sold'] === 'no' ? 'selected' : '' ?>>bez sprzedaży</option></select></div>
                <div><label>Sortuj</label>
                    <select name="sort">
                        <?php foreach (['' => 'domyślnie', 'name' => 'tytuł A–Z', 'price' => 'cena rosnąco', '-price' => 'cena malejąco', 'stock' => 'ilość rosnąco', '-stock' => 'ilość malejąco', '-sold' => 'najlepiej sprzedające się'] as $sk => $sl): ?>
                            <option value="<?= $sk ?>" <?= $f['sort'] === $sk ? 'selected' : '' ?>><?= $e($sl) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="al-filters-actions">
                    <button class="btn" type="submit">Filtruj</button>
                    <?php if ($activeFilters > 0 || $q !== '' || $f['sort'] !== ''): ?><a href="<?= $e('allegro_offers.php?status=' . urlencode($status)) ?>">wyczyść</a><?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <p style="color:#888;font-size:12px;margin:6px 0 10px"><?= (int) $total ?> ofert(y)<?= ($activeFilters > 0 || $q !== '') ? ' pasujących do filtrów' : '' ?> · strona <?= (int) $page ?>/<?= (int) $pages ?>
        <?php if ($missingHandling !== []): ?>
            · <a href="#" id="loadAllHandling" data-ids="<?= $e(implode(',', $missingHandling)) ?>">pobierz czasy wysyłki (<?= count($missingHandling) ?> brak)</a>
            <span id="loadAllProgress"></span>
        <?php endif; ?></p>

    <?php if ($offers === []): ?>
        <p style="color:#888">Brak ofert<?= ($q !== '' || $activeFilters > 0) ? ' pasujących do filtrów' : ' w tym statusie' ?>.</p>
    <?php else: ?>
        <table class="al-table">
            <thead><tr>
                <?php if (canEdit()): ?><th style="width:28px"></th><?php endif; ?>
                <th></th><th>Tytuł</th><th>Cena</th><th>Stan</th><th>Sprzedane</th><th>Wysyłka</th><th>Status</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($offers as $o):
                $img = $o['primaryImage']['url'] ?? '';
                $price = $o['sellingMode']['price']['amount'] ?? '';
                $curr = $o['sellingMode']['price']['currency'] ?? 'PLN';
                $stock = $o['stock']['available'] ?? 0;
                $sold = $o['stock']['sold'] ?? 0;
                $pub = $o['publication']['status'] ?? '';
                $oid = (string) ($o['id'] ?? '');
                $sig = (string) ($o['external']['id'] ?? '');
            ?>
                <tr class="al-row">
                    <?php if (canEdit()): ?><td><input type="checkbox" class="al-sel" value="<?= $e($oid) ?>"></td><?php endif; ?>
                    <td><?php if ($img !== ''): ?><img src="<?= $e($img) ?>" class="al-thumb" alt=""><?php endif; ?></td>
                    <td>
                        <div style="font-weight:600"><?= $e($o['name'] ?? '(bez tytułu)') ?></div>
                        <div style="color:#999;font-size:11px">ID: <?= $e($oid) ?><?= $sig !== '' ? ' · SKU: ' . $e($sig) : '' ?>
                            <?php if (!empty($o['__pending'])): ?><span class="al-pending" title="Zmiana zapisana na Allegro. Lista ofert Allegro odświeża się z opóźnieniem — pokazujemy już nową wartość.">⟳ zapisano, Allegro odświeża listę</span><?php endif; ?></div>
                    </td>
                    <td style="white-space:nowrap">
                        <?php if (canEdit()): ?>
                            <span class="al-price-wrap">
                                <button type="button" class="al-price" data-offer="<?= $e($oid) ?>" data-price="<?= $e(number_format((float) $price, 2, '.', '')) ?>" data-curr="<?= $e($curr) ?>" title="Kliknij, aby zmienić cenę">⋮ <span><?= $e(number_format((float) $price, 2, ',', ' ')) ?> <?= $e($curr) ?></span></button>
                            </span>
                        <?php else: ?><?= $e(number_format((float) $price, 2, ',', ' ')) ?> <?= $e($curr) ?><?php endif; ?>
                    </td>
                    <td>
                        <?php if (canEdit()): ?>
                        <form method="post" style="display:flex;gap:4px;align-items:center">
                            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="stock_one">
                            <input type="hidden" name="offer_id" value="<?= $e($oid) ?>">
                            <input type="number" name="stock" value="<?= (int) $stock ?>" min="0" style="width:64px;padding:4px">
                            <button class="btn secondary" type="submit" style="padding:4px 8px" title="Zapisz stan">💾</button>
                        </form>
                        <?php else: ?><?= (int) $stock ?><?php endif; ?>
                    </td>
                    <td><?= (int) $sold ?></td>
                    <td class="al-ship">
                        <span class="al-handling" data-offer="<?= $e($oid) ?>" data-known="<?= $o['__handling_known'] ? '1' : '0' ?>"><?= $o['__handling_known']
                            ? $e(AllegroOfferOperations::handlingLabel($o['__handling']) ?: '—') : '<span class="al-muted-i">…</span>' ?></span>
                        <?php $rid = (string) ($o['delivery']['shippingRates']['id'] ?? ''); ?>
                        <div class="al-rate" title="Cennik dostaw"><?= $e($rid !== '' ? ($rateNames[$rid] ?? 'cennik') : 'bez cennika') ?></div>
                    </td>
                    <td><span class="al-badge al-<?= $e(strtolower($pub)) ?>"><?= $e($pubLabels[$pub] ?? $pub) ?></span>
                        <?php if ($pub !== 'ACTIVE'): ?><div class="al-issues" data-offer="<?= $e($oid) ?>"><span class="al-muted-i">sprawdzam powód…</span></div><?php endif; ?></td>
                    <td style="white-space:nowrap"><a class="btn secondary" href="https://allegro.pl/oferta/<?= $e($oid) ?>" target="_blank" rel="noopener" style="padding:4px 8px">Podgląd ↗</a>
                        <?php if (canEdit()): ?><a class="btn secondary" href="offer_allegro.php?offer=<?= $e(rawurlencode($oid)) ?>" style="padding:4px 8px" title="Zmień opis tej oferty">Aktualizuj</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        $pagerPage = (int) $page; $pagerPages = (int) $pages;
        $pagerLink = static fn(int $p): string => $qsStatus($status) . '&p=' . $p;
        require __DIR__ . '/_pager.php';
        ?>
    <?php endif; ?>
</div>

<?php if (canEdit()): ?>
<!-- Okno parametrów operacji -->
<div class="al-modal" id="opModal" hidden>
    <form method="post" class="al-modal-box" id="opForm">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="bulk">
        <input type="hidden" name="op" id="opKey">
        <h3 id="opTitle"></h3>
        <p class="al-muted" id="opCount"></p>

        <fieldset data-for="price">
            <label>Sposób</label>
            <select name="p[mode]">
                <option value="set">Ustaw cenę na</option>
                <option value="inc_pct">Podnieś o %</option>
                <option value="dec_pct">Obniż o %</option>
                <option value="inc_amt">Podnieś o kwotę (zł)</option>
                <option value="dec_amt">Obniż o kwotę (zł)</option>
            </select>
            <label>Wartość</label><input name="p[value]" type="number" step="0.01" min="0">
            <label class="al-inline"><input type="checkbox" name="p[end99]" value="1"> zaokrąglij do ,99</label>
        </fieldset>
        <fieldset data-for="stock">
            <label>Sposób</label>
            <select name="p[mode]"><option value="set">Ustaw ilość na</option><option value="inc">Zwiększ o</option><option value="dec">Zmniejsz o</option></select>
            <label>Ilość</label><input name="p[value]" type="number" step="1" min="0">
        </fieldset>
        <fieldset data-for="title">
            <label>Sposób</label>
            <select name="p[mode]" id="titleMode">
                <option value="set">Ustaw nowy tytuł</option>
                <option value="prefix">Dodaj tekst na początku</option>
                <option value="suffix">Dodaj tekst na końcu</option>
                <option value="replace">Zamień fragment</option>
            </select>
            <div id="titleFind" hidden><label>Szukany fragment</label><input name="p[find]"></div>
            <label>Tekst</label><input name="p[value]" maxlength="75">
            <p class="al-muted">Allegro pozwala na maks. 75 znaków — oferty z dłuższym tytułem zostaną pominięte.</p>
        </fieldset>
        <fieldset data-for="description">
            <p>Opis oferty zostanie <strong>zastąpiony</strong> opisem produktu z magazynu CRM (sekcje zaznaczone „Na Allegro").
               Produkt dobieramy po powiązaniu oferty albo sygnaturze (SKU). Obecny opis na Allegro — łącznie ze zdjęciami w opisie — zniknie.</p>
        </fieldset>
        <fieldset data-for="shipping_rate">
            <label>Cennik dostaw</label>
            <select name="p[rate]">
                <?php foreach ($rates as $r): ?><option value="<?= $e($r['id'] ?? '') ?>"><?= $e($r['name'] ?? $r['id'] ?? '') ?></option><?php endforeach; ?>
            </select>
            <?php if ($rates === []): ?><p class="al-muted">Nie udało się pobrać cenników z Allegro.</p><?php endif; ?>
        </fieldset>
        <fieldset data-for="handling_time">
            <label>Czas wysyłki (od zakupu do nadania)</label>
            <select name="p[handling]">
                <?php $defH = (string) ((new \Pase\Repository\SettingsRepository($pdo))->get(AllegroOfferOperations::DEFAULT_HANDLING_SETTING, 'PT24H') ?? 'PT24H'); ?>
                <?php foreach (AllegroOfferOperations::HANDLING_TIMES as $hk => $hl): ?>
                    <option value="<?= $hk ?>" <?= $hk === $defH ? 'selected' : '' ?>><?= $e($hl) ?></option>
                <?php endforeach; ?>
            </select>
        </fieldset>
        <fieldset data-for="responsible_person">
            <label>Osoba odpowiedzialna (GPSR)</label>
            <select name="p[person]">
                <?php foreach ($persons as $rp): ?><option value="<?= $e($rp['id'] ?? '') ?>"><?= $e(($rp['name'] ?? '') . ' — ' . ($rp['personalData']['name'] ?? '')) ?></option><?php endforeach; ?>
                <option value="__none">— usuń przypisanie —</option>
            </select>
            <p class="al-muted"><?= $persons === [] ? 'Nie masz jeszcze osób odpowiedzialnych. ' : '' ?><a href="allegro_persons.php">Zarządzaj osobami odpowiedzialnymi →</a></p>
        </fieldset>
        <fieldset data-for="responsible_producer">
            <label>Producent (GPSR)</label>
            <select name="p[producer]">
                <?php foreach ($producers as $rp): ?><option value="<?= $e($rp['id'] ?? '') ?>"><?= $e(($rp['name'] ?? '') . ' — ' . ($rp['producerData']['tradeName'] ?? '')) ?></option><?php endforeach; ?>
                <option value="__none">— usuń przypisanie —</option>
            </select>
            <p class="al-muted"><?= $producers === [] ? 'Nie masz jeszcze producentów. ' : '' ?><a href="allegro_producers.php">Zarządzaj producentami →</a></p>
        </fieldset>
        <fieldset data-for="safety_info">
            <label class="al-inline"><input type="radio" name="p[safety_mode]" value="text" checked> Opis tekstowy</label>
            <label class="al-inline"><input type="radio" name="p[safety_mode]" value="none"> Produkt nie ma informacji o bezpieczeństwie</label>
            <label>Informacje o bezpieczeństwie (bez HTML, maks. <?= AllegroOfferOperations::SAFETY_TEXT_MAX ?> znaków)</label>
            <textarea name="p[safety_text]" id="safetyText" rows="9" maxlength="<?= AllegroOfferOperations::SAFETY_TEXT_MAX ?>"></textarea>
            <p class="al-muted" id="safetyNote"></p>
            <div id="safetyBad" hidden><p class="al-bad"></p><button type="button" class="btn secondary" id="safetyFix">Zamień niedozwolone znaki</button></div>
            <p class="al-muted">Te same informacje trafią do wszystkich produktów w każdej zaznaczonej ofercie. Opis powinien być w językach rynków, na których jest oferta.</p>
        </fieldset>
        <fieldset data-for="duplicate">
            <p>Dla każdej zaznaczonej oferty powstanie <strong>nowa oferta</strong> z tymi samymi danymi (tytuł, kategoria, zdjęcia, opis, cena, ilość, dostawa).</p>
            <label class="al-inline"><input type="checkbox" name="p[activate]" value="1"> od razu aktywuj (bez tego nowe oferty zostaną nieaktywne do sprawdzenia)</label>
        </fieldset>
        <fieldset data-for="end"><p>Zaznaczone oferty zostaną <strong>zakończone</strong> na Allegro. Możesz je potem wznowić.</p></fieldset>
        <fieldset data-for="activate"><p>Zaznaczone oferty zostaną <strong>aktywowane</strong> (wznowione) na Allegro.</p></fieldset>
        <fieldset data-for="link_products"><p>Oferty zostaną powiązane z produktami magazynu CRM o tej samej sygnaturze (SKU), a gdy jej brak — po EAN.
            Dzięki temu zamówienia z tych ofert zdejmą stan z właściwego produktu.</p></fieldset>
        <fieldset data-for="export"><p>Pobierzesz plik CSV (Excel) z danymi zaznaczonych ofert.</p></fieldset>

        <div class="al-modal-actions">
            <button type="button" class="btn secondary" id="opCancel">Anuluj</button>
            <button type="submit" class="btn" id="opSubmit">Wykonaj</button>
        </div>
    </form>
</div>
<?php endif; ?>

<style>
    .al-tabs { display:flex; gap:4px; flex-wrap:wrap; }
    .al-tab { padding:6px 12px; border-radius:8px; text-decoration:none; color:#555; font-size:13px; background:#f1f3f5; }
    .al-tab.on { background:#ff5a00; color:#fff; font-weight:600; }
    .al-bar { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-top:14px; }
    .al-ops { position:relative; display:flex; align-items:stretch; border:1px solid #d9dde3; border-radius:8px; }
    .al-selall { display:flex; align-items:center; gap:6px; padding:6px 12px; font-size:13px; color:#555; cursor:pointer; }
    .al-ops-btn { background:#2a5db0; color:#fff; border:0; padding:8px 16px; font-weight:700; font-size:13px; letter-spacing:.02em; cursor:pointer; border-radius:0 7px 7px 0; }
    .al-ops-btn:disabled { background:#9fb3d6; cursor:not-allowed; }
    .al-ops-menu { position:absolute; top:calc(100% + 6px); left:0; z-index:50; background:#fff; border-radius:12px; box-shadow:0 12px 40px rgba(0,0,0,.18);
                   padding:18px 20px; display:grid; grid-template-columns:repeat(2, minmax(240px, 1fr)); gap:24px; min-width:560px; }
    .al-ops-menu[hidden] { display:none; }
    .al-ops-menu h4 { margin:0 0 8px; font-size:15px; }
    .al-ops-menu button { display:block; width:100%; text-align:left; background:none; border:0; padding:8px 10px; border-radius:7px; font-size:14px; color:#333; cursor:pointer; }
    .al-ops-menu button:hover { background:#f1f4f9; }
    .al-search { display:flex; gap:6px; align-items:center; }
    .al-search input { width:260px; }
    .al-table { width:100%; border-collapse:collapse; margin-top:6px; }
    .al-table th { text-align:left; font-size:11px; color:#888; text-transform:uppercase; padding:6px 8px; border-bottom:2px solid #eef0f3; }
    .al-table td { padding:8px; border-bottom:1px solid #f1f3f5; vertical-align:middle; font-size:14px; }
    .al-row.sel td { background:#eef4ff; }
    .al-thumb { width:44px; height:44px; object-fit:cover; border-radius:6px; }
    .al-pending { margin-left:6px; color:#1a73e8; font-size:11px; }
    .al-price-wrap { position:relative; display:inline-block; }
    .al-price { background:#fff; border:1px solid #d9dde3; border-radius:7px; padding:5px 10px; font-size:14px; cursor:pointer; color:#222; white-space:nowrap; }
    .al-price:hover, .al-price.open { border-color:#2a5db0; }
    .al-price.saving { opacity:.6; }
    .al-pop { position:absolute; bottom:calc(100% + 10px); left:50%; transform:translateX(-50%); z-index:60; background:#fff; border-radius:12px;
              box-shadow:0 10px 32px rgba(0,0,0,.18); width:300px; }
    .al-pop::after { content:''; position:absolute; top:100%; left:50%; margin-left:-9px; border:9px solid transparent; border-top-color:#fff; }
    .al-pop h5 { margin:0; padding:12px 16px; font-size:15px; font-weight:600; border-bottom:1px solid #eef0f3; }
    .al-pop form { display:flex; gap:8px; align-items:center; padding:12px 16px; }
    .al-pop input { flex:1; min-width:0; font-size:15px; padding:8px 10px; }
    .al-pop .ok { width:38px; height:38px; border-radius:50%; border:0; background:#2a5db0; color:#fff; font-size:18px; cursor:pointer; }
    .al-pop .no { width:38px; height:38px; border-radius:50%; border:1px solid #d9dde3; background:#fff; color:#555; font-size:16px; cursor:pointer; }
    .al-pop .err { color:#c5221f; font-size:12px; padding:0 16px 10px; }
    .al-search { flex:1 1 520px; flex-wrap:wrap; justify-content:flex-end; }
    .al-filters { flex-basis:100%; display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:10px 14px; background:#f8f9fb;
                  border:1px solid #eef0f3; border-radius:10px; padding:12px 14px; margin-top:8px; }
    .al-filters[hidden] { display:none; }
    .al-filters label { display:block; font-size:11px; color:#888; margin-bottom:3px; }
    .al-search .al-filters select, .al-search .al-filters input { width:100%; }
    .al-range { display:flex; align-items:center; gap:4px; } .al-range input { width:100%; min-width:0; }
    .al-filters-actions { display:flex; align-items:flex-end; gap:10px; font-size:13px; }
    .al-ship { font-size:13px; white-space:nowrap; }
    .al-rate { color:#999; font-size:11px; max-width:150px; overflow:hidden; text-overflow:ellipsis; }
    .al-muted-i { color:#bbb; }
    .al-badge { font-size:11px; font-weight:700; padding:2px 8px; border-radius:10px; background:#eee; color:#555; }
    .al-badge.al-active { background:#e6f4ea; color:#0a8537; }
    .al-badge.al-ended { background:#fce8e6; color:#c5221f; }
    .al-badge.al-inactive { background:#fef7e0; color:#b06000; }
    .al-issues { margin-top:6px; font-size:12px; line-height:1.35; max-width:260px; }
    .al-issues ul { list-style:none; margin:0; padding:0; display:grid; gap:4px; }
    .al-issues li { color:#8a4b00; }
    .al-issues li.warning { color:#777; }
    .al-issues li::before { content:'⚠ '; }
    .al-issues li.warning::before { content:'ℹ '; }
    .al-issues a { display:inline-block; color:#5a2ea6; }
    .al-issues .ok { color:#888; }
    .al-modal { position:fixed; inset:0; background:rgba(15,20,30,.45); z-index:100; display:flex; align-items:center; justify-content:center; padding:16px; }
    .al-modal[hidden] { display:none; }
    .al-modal-box { background:#fff; border-radius:14px; padding:22px 24px; width:100%; max-width:460px; box-shadow:0 20px 60px rgba(0,0,0,.25); }
    .al-modal-box h3 { margin:0 0 4px; }
    .al-modal-box fieldset { border:0; padding:0; margin:12px 0 0; display:grid; gap:6px; }
    .al-modal-box fieldset[hidden] { display:none; }
    .al-modal-box label { font-size:12px; color:#888; }
    .al-modal-box label.al-inline { font-size:13px; color:#333; display:flex; gap:6px; align-items:center; }
    .al-modal-box input:not([type=checkbox]):not([type=radio]), .al-modal-box select, .al-modal-box textarea { width:100%; }
    .al-modal-box textarea { font:inherit; font-size:13px; resize:vertical; }
    .al-bad { color:#c5221f; font-size:13px !important; margin-bottom:6px !important; }
    .al-modal-box p { font-size:14px; line-height:1.5; margin:0; }
    .al-muted { color:#888; font-size:12px !important; }
    .al-modal-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:18px; }
    @media (max-width:700px){ .al-ops-menu{ grid-template-columns:1fr; min-width:260px; } .al-search input{ width:160px; } }
</style>

<?php if (canEdit()): ?>
<script>
(function () {
    var sels = [].slice.call(document.querySelectorAll('.al-sel'));
    var all = document.getElementById('selAll');
    var cnt = document.getElementById('selCount');
    var btn = document.getElementById('opsBtn');
    var menu = document.getElementById('opsMenu');
    if (!btn) return;
    function checked() { return sels.filter(function (c) { return c.checked; }); }
    function refresh() {
        var n = checked().length;
        cnt.textContent = n;
        btn.disabled = n === 0;
        btn.title = n === 0 ? 'Zaznacz oferty' : '';
        all.checked = n > 0 && n === sels.length;
        all.indeterminate = n > 0 && n < sels.length;
        sels.forEach(function (c) { c.closest('tr').classList.toggle('sel', c.checked); });
    }
    sels.forEach(function (c) { c.addEventListener('change', refresh); });
    all.addEventListener('change', function () { sels.forEach(function (c) { c.checked = all.checked; }); refresh(); });
    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        menu.hidden = !menu.hidden;
        btn.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
    });
    document.addEventListener('click', function (e) { if (!e.target.closest('.al-ops')) menu.hidden = true; });

    var modal = document.getElementById('opModal');
    var form = document.getElementById('opForm');
    menu.querySelectorAll('[data-op]').forEach(function (b) {
        b.addEventListener('click', function () {
            menu.hidden = true;
            var op = b.dataset.op;
            document.getElementById('opKey').value = op;
            document.getElementById('opTitle').textContent = b.textContent;
            document.getElementById('opCount').textContent = 'Zaznaczone oferty: ' + checked().length;
            form.querySelectorAll('fieldset').forEach(function (f) {
                var on = f.dataset.for === op;
                f.hidden = !on;
                f.querySelectorAll('input,select,textarea').forEach(function (i) { i.disabled = !on; });
            });
            document.getElementById('opSubmit').textContent = op === 'export' ? 'Pobierz CSV' : 'Wykonaj';
            modal.hidden = false;
        });
    });
    document.getElementById('opCancel').addEventListener('click', function () { modal.hidden = true; });
    modal.addEventListener('click', function (e) { if (e.target === modal) modal.hidden = true; });
    var tm = document.getElementById('titleMode');
    if (tm) tm.addEventListener('change', function () { document.getElementById('titleFind').hidden = tm.value !== 'replace'; });
    form.addEventListener('submit', function (e) {
        form.querySelectorAll('input[name="ids[]"]').forEach(function (i) { i.remove(); });
        var ids = checked().map(function (c) { return c.value; });
        if (!ids.length) { e.preventDefault(); return; }
        var op = document.getElementById('opKey').value;
        if ((op === 'end' || op === 'description' || op === 'duplicate') &&
            !confirm('Na pewno wykonać operację „' + document.getElementById('opTitle').textContent + '" dla ' + ids.length + ' ofert?')) { e.preventDefault(); return; }
        ids.forEach(function (id) { var h = document.createElement('input'); h.type = 'hidden'; h.name = 'ids[]'; h.value = id; form.appendChild(h); });
        if (op !== 'export') {
            var s = document.getElementById('opSubmit'); s.disabled = true; s.textContent = 'Wykonuję…';
        } else { setTimeout(function () { modal.hidden = true; }, 300); }
    });
    refresh();
})();
</script>
<?php endif; ?>

<script>
// Zmiana ceny po kliknięciu (okienko jak w BaseLinkerze): Enter / ✓ zapisuje, Esc / ✕ zamyka.
(function () {
    var CSRF = <?= json_encode(csrfToken()) ?>;
    var open = null;
    function close() { if (open) { open.pop.remove(); open.btn.classList.remove('open'); open = null; } }
    document.querySelectorAll('.al-price').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (open && open.btn === btn) { close(); return; }
            close();
            var pop = document.createElement('div');
            pop.className = 'al-pop';
            pop.innerHTML = '<h5>Cena brutto</h5><form><input type="text" inputmode="decimal" autocomplete="off">'
                + '<button type="submit" class="ok" title="Zapisz">✓</button><button type="button" class="no" title="Anuluj">✕</button></form><div class="err" hidden></div>';
            var inp = pop.querySelector('input');
            inp.value = btn.dataset.price.replace('.', ',');
            btn.parentNode.appendChild(pop);
            btn.classList.add('open');
            open = { btn: btn, pop: pop };
            inp.focus(); inp.select();
            pop.addEventListener('click', function (ev) { ev.stopPropagation(); });
            pop.querySelector('.no').addEventListener('click', close);
            pop.querySelector('form').addEventListener('submit', function (ev) {
                ev.preventDefault();
                var err = pop.querySelector('.err');
                var val = inp.value.trim();
                if (val === '' || isNaN(parseFloat(val.replace(',', '.')))) { err.textContent = 'Podaj cenę.'; err.hidden = false; return; }
                pop.querySelector('.ok').disabled = true;
                btn.classList.add('saving');
                var body = new URLSearchParams({ csrf: CSRF, action: 'price_one', offer_id: btn.dataset.offer, price: val, currency: btn.dataset.curr });
                fetch('allegro_offers.php', { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        btn.classList.remove('saving');
                        if (d.ok) {
                            btn.querySelector('span').textContent = d.price;
                            btn.dataset.price = d.raw;
                            close();
                        } else {
                            err.textContent = d.message || 'Nie udało się zmienić ceny.'; err.hidden = false;
                            pop.querySelector('.ok').disabled = false;
                        }
                    })
                    .catch(function () { btn.classList.remove('saving'); err.textContent = 'Błąd połączenia.'; err.hidden = false; pop.querySelector('.ok').disabled = false; });
            });
        });
    });
    document.addEventListener('click', close);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();

// Czas wysyłki nie ma go na liście ofert Allegro - dociągamy go w tle (po 10 ofert) i zapamiętujemy.
(function () {
    function load(ids, done) {
        if (!ids.length) { done && done(); return; }
        var batch = ids.slice(0, 10), rest = ids.slice(10);
        fetch('allegro_offers.php?details=1&ids=' + batch.join(','), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                Object.keys(d).forEach(function (id) {
                    var el = document.querySelector('.al-handling[data-offer="' + id + '"]');
                    if (el) { el.textContent = d[id].label; el.dataset.known = '1'; }
                });
                if (done && done.progress) done.progress(batch.length);
                load(rest, done);
            })
            .catch(function () { done && done(); });
    }
    var visible = [].map.call(document.querySelectorAll('.al-handling[data-known="0"]'), function (el) { return el.dataset.offer; });
    load(visible);
    var all = document.getElementById('loadAllHandling');
    if (all) all.addEventListener('click', function (e) {
        e.preventDefault();
        var ids = all.dataset.ids.split(',').filter(Boolean), n = 0, prog = document.getElementById('loadAllProgress');
        all.style.pointerEvents = 'none';
        var done = function () { prog.textContent = ' — gotowe, odświeżam…'; setTimeout(function () { location.reload(); }, 600); };
        done.progress = function (k) { n += k; prog.textContent = ' — ' + n + '/' + ids.length; };
        load(ids, done);
    });
})();
</script>

<?php if (canEdit()): ?>
<script>
// Informacje o bezpieczeństwie: podpowiedź obecnego tekstu (jedna oferta) i zamiana znaków, których Allegro nie przyjmuje.
(function () {
    var ta = document.getElementById('safetyText');
    if (!ta) return;
    var note = document.getElementById('safetyNote'), bad = document.getElementById('safetyBad'), badChars = '';
    // Greckie litery (np. α-pinen, β-karoten) zapisujemy słownie - tak robią karty charakterystyki.
    var GREEK = { 'α': 'alfa', 'β': 'beta', 'γ': 'gamma', 'δ': 'delta', 'ε': 'epsilon', 'κ': 'kappa', 'λ': 'lambda',
                  'μ': 'mikro', 'µ': 'mikro', 'ω': 'omega', 'Α': 'Alfa', 'Β': 'Beta', 'Γ': 'Gamma', 'Δ': 'Delta', 'Ω': 'Omega' };
    function showBad() {
        var found = badChars.split('').filter(function (c, i, a) { return a.indexOf(c) === i && ta.value.indexOf(c) !== -1; });
        bad.hidden = !found.length;
        if (found.length) bad.querySelector('.al-bad').textContent = 'Allegro nie przyjmuje znaków: ' + found.join(' ') + ' (' + found.map(function (c) { return GREEK[c] ? c + ' → ' + GREEK[c] : c + ' → usunięty'; }).join(', ') + ')';
    }
    document.getElementById('safetyFix').addEventListener('click', function () {
        var v = ta.value;
        badChars.split('').forEach(function (c) { v = v.split(c).join(GREEK[c] || ''); });
        ta.value = v.replace(/[ \t]{2,}/g, ' ');
        showBad();
    });
    ta.addEventListener('input', showBad);
    document.querySelectorAll('#opsMenu [data-op="safety_info"]').forEach(function (b) {
        b.addEventListener('click', function () { badChars = ''; ta.value = ''; note.textContent = ''; bad.hidden = true; });
    });
    window.alSafetyPrefill = function (id, chars) {
        badChars = chars || '';
        note.textContent = 'Pobieram obecne informacje z oferty…';
        fetch('allegro_offers.php?safety=1&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) { note.textContent = d.message || 'Nie udało się pobrać obecnych informacji.'; return; }
                if (d.type === 'NO_SAFETY_INFORMATION') {
                    document.querySelector('input[name="p[safety_mode]"][value="none"]').checked = true;
                }
                ta.value = d.text || '';
                note.textContent = d.text ? 'Obecny tekst z oferty — popraw i zapisz.' : 'Oferta nie ma jeszcze opisu tekstowego.';
                showBad();
            })
            .catch(function () { note.textContent = 'Błąd połączenia.'; });
    };
})();
</script>
<?php endif; ?>

<script>
// Powód statusu (jak „Szczegóły statusu oferty" na Allegro): braki z walidacji oferty, z linkiem do poprawki.
(function () {
    var boxes = [].slice.call(document.querySelectorAll('.al-issues'));
    if (!boxes.length) return;
    var CAN_EDIT = <?= canEdit() ? 'true' : 'false' ?>;
    var FIX = {
        producer: { label: 'ustaw dane producenta', op: 'responsible_producer' },
        person:   { label: 'ustaw osobę odpowiedzialną', op: 'responsible_person' },
        safety:   { label: 'ustaw informacje o bezpieczeństwie', op: 'safety_info' }
    };
    function editUrl(id) { return 'https://allegro.pl/offer/' + encodeURIComponent(id) + '/edit'; }
    function fixLink(id, fix, text) {
        var a = document.createElement('a');
        var f = FIX[fix];
        if (f && f.op && CAN_EDIT) {
            // Operacja GPSR z menu OPERACJE dla tej jednej oferty.
            a.href = '#';
            a.textContent = f.label;
            a.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelectorAll('.al-sel').forEach(function (c) {
                    var on = c.value === id;
                    if (c.checked !== on) { c.checked = on; c.dispatchEvent(new Event('change')); }
                });
                var b = document.querySelector('#opsMenu [data-op="' + f.op + '"]');
                if (b) b.click();
                if (f.op === 'safety_info' && window.alSafetyPrefill) {
                    // „…niedozwolone znaki: [α]" - znaki do zamiany podane przez Allegro.
                    var m = /\[([^\]]+)\]/.exec(text || '');
                    window.alSafetyPrefill(id, m ? m[1].replace(/[,\s]/g, '') : '');
                }
            });
        } else {
            a.href = editUrl(id);
            a.target = '_blank';
            a.rel = 'noopener';
            a.textContent = (f ? f.label : 'popraw na Allegro') + ' ↗';
        }
        return a;
    }
    function render(box, issues) {
        box.textContent = '';
        if (!issues.length) {
            var s = document.createElement('span'); s.className = 'ok'; s.textContent = 'Allegro nie zgłasza braków';
            box.appendChild(s);
            return;
        }
        var ul = document.createElement('ul');
        issues.forEach(function (it) {
            var li = document.createElement('li');
            li.className = it.level;
            li.appendChild(document.createTextNode(it.text + ' '));
            if (it.level === 'error') li.appendChild(fixLink(box.dataset.offer, it.fix, it.text));
            ul.appendChild(li);
        });
        box.appendChild(ul);
    }
    function load(list) {
        if (!list.length) return;
        var batch = list.slice(0, 10), rest = list.slice(10);
        fetch('allegro_offers.php?issues=1&ids=' + batch.map(function (b) { return b.dataset.offer; }).join(','), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                batch.forEach(function (box) {
                    if (d[box.dataset.offer]) render(box, d[box.dataset.offer]);
                    else box.textContent = '';
                });
                load(rest);
            })
            .catch(function () { batch.forEach(function (box) { box.textContent = ''; }); });
    }
    load(boxes);
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
