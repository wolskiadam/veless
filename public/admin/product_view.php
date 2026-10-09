<?php
declare(strict_types=1);

/**
 * Podgląd produktu w magazynie PASE - pełne dane do wystawiania ofert
 * (tytuł, opisy, galeria, kategorie, atrybuty, EAN, wymiary, stan/cena).
 */

use Pase\Repository\ProductFieldRepository;
use Pase\Repository\ProductRepository;

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

$repo = new ProductRepository($pdo);
$id = (int) ($_GET['id'] ?? 0);

$flashOk = null;
// Lokalizacja w magazynie (Magazyn → Lokalizacje).
$locations = [];
try {
    \Pase\Services\WarehouseLocations::migrate($pdo);
    $locSvc = new \Pase\Services\WarehouseLocations($pdo);
    $locations = $locSvc->all();
} catch (\Throwable) {
    $locSvc = null;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEdit() && ($_POST['action'] ?? '') === 'save_location' && $locSvc !== null) {
    csrfCheck();
    $lid = (string) ($_POST['location_id'] ?? '');
    $locSvc->assign($id, $lid === '' ? null : (int) $lid);
    $flashOk = $lid === '' ? 'Usunięto lokalizację produktu.' : 'Zapisano lokalizację: ' . ($locSvc->find((int) $lid)['code'] ?? '') . '.';
}
// Nazwa produktu. Produkt ze sklepu WooCommerce dostaje nową nazwę także w sklepie - inaczej kolejny
// import ze sklepu przywróciłby starą. Wariant bierze nazwę z produktu głównego, więc zmienia się tylko w CRM.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEdit() && ($_POST['action'] ?? '') === 'save_name') {
    csrfCheck();
    $name = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')) ?? '');
    $cur = $repo->find($id);
    if ($cur === null) {
        $flashErr = 'Nie znaleziono produktu.';
    } elseif ($name === '' || mb_strlen($name) > 255) {
        $flashErr = 'Nazwa produktu: od 1 do 255 znaków.';
    } elseif ($name === (string) ($cur['name'] ?? '')) {
        $flashOk = 'Nazwa bez zmian.';
    } else {
        $wooId = (int) ($cur['woo_product_id'] ?? 0);
        $intId = (int) ($cur['source_integration_id'] ?? 0);
        $acc = $wooId > 0 && $intId > 0 ? (new \Pase\Repository\IntegrationAccountRepository($pdo))->find($intId) : null;
        $linked = $acc !== null && ($acc['type'] ?? '') === 'woocommerce';
        if ($linked && (int) ($cur['woo_variation_id'] ?? 0) > 0) {
            $repo->setName($id, $name);
            $flashOk = 'Zapisano nazwę w CRM. To wariant produktu ze sklepu - jego nazwa pochodzi z produktu głównego, więc sklep jej nie zmieni, a kolejny import ze sklepu przywróci starą nazwę.';
        } elseif ($linked) {
            $client = \Pase\Plugin\IntegrationClient::for('woocommerce', $acc['config'] ?? []);
            $err = $client === null ? 'wtyczka WooCommerce jest wyłączona' : $client->updateProductName($wooId, $name);
            if ($err === null) {
                $repo->setName($id, $name);
                $flashOk = "Zapisano nazwę w CRM i w sklepie „{$acc['name']}”.";
            } else {
                $flashErr = "Nie zmieniono nazwy - sklep „{$acc['name']}” odrzucił zmianę ({$err}). Gdyby zapisać ją tylko w CRM, kolejny import ze sklepu przywróciłby starą.";
            }
        } else {
            $repo->setName($id, $name);
            $flashOk = 'Zapisano nazwę produktu.';
        }
    }
}
// Koszt zakupu netto (do marży w Statystykach) - osobne uprawnienie „Koszty zakupu i marża”.
$canSeeCost = canViewPage('product_costs');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEditPage('product_costs') && ($_POST['action'] ?? '') === 'save_cost') {
    csrfCheck();
    $c = \Pase\Services\OrderMargins::parseCost($_POST['purchase_cost'] ?? '');
    if ($c === false) {
        $flashErr = 'Zła kwota kosztu zakupu.';
    } else {
        (new \Pase\Services\OrderMargins($pdo))->setCosts([$id => $c]);
        $flashOk = $c === null ? 'Usunięto koszt zakupu.' : 'Zapisano koszt zakupu: ' . number_format($c, 2, ',', ' ') . ' zł' . \Pase\Services\CompanySettings::netSuffix($pdo) . '.';
    }
}
// Minimalny stan (alert niskiego stanu); puste = próg domyślny z listy produktów.
\Pase\Services\LowStock::migrate($pdo);
try { \Pase\Services\WooCustomFields::migrate($pdo); } catch (\PDOException $e) { /* brak tabeli product_fields - pola własne i tak puste */ }
$lowSvc = new \Pase\Services\LowStock($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEdit() && ($_POST['action'] ?? '') === 'save_min_stock') {
    csrfCheck();
    $raw = trim((string) ($_POST['min_stock'] ?? ''));
    $off = !empty($_POST['untracked']);
    $lowSvc->setMin($id, $off ? \Pase\Services\LowStock::OFF : ($raw === '' ? null : (int) $raw));
    $flashOk = $off ? 'Stan tego produktu nie jest pilnowany (brak alertu niskiego stanu).'
        : ($raw === '' ? 'Produkt korzysta z progu domyślnego niskiego stanu.' : 'Zapisano minimalny stan: ' . max(0, (int) $raw) . '.');
}
// Produkt wirtualny (bez wysyłki): zamówienie z samymi takimi produktami dostaje „Zamówienie wirtualne — bez wysyłki”.
try { \Pase\Services\VirtualProducts::migrate($pdo); } catch (\PDOException) { /* kolumna dojdzie przy migracji */ }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEdit() && ($_POST['action'] ?? '') === 'save_virtual') {
    csrfCheck();
    $virtual = !empty($_POST['is_virtual']);
    $virtualSvc = new \Pase\Services\VirtualProducts($pdo);
    $virtualSvc->set($id, $virtual);
    // Stare, jeszcze niewysłane zamówienia z tym produktem też sprawdzamy (wybór z karty zamówienia zostaje).
    $marked = $virtual ? $virtualSvc->backfillForSku((string) ($repo->find($id)['sku'] ?? '')) : 0;
    $flashOk = $virtual ? 'Oznaczono jako produkt wirtualny - zamówienia z samymi takimi produktami nie wymagają wysyłki.'
            . ($marked > 0 ? ' Oznaczono też ' . $marked . ' niewysłanych zamówień jako wirtualne.' : '')
        : 'Produkt znów wymaga wysyłki.';
}
$useActual = $lowSvc->usesActualStock();   // pole „Stan faktyczny” włączone w Konfiguracja → Synchronizacja
if ($useActual && $_SERVER['REQUEST_METHOD'] === 'POST' && canEdit() && ($_POST['action'] ?? '') === 'save_actual_stock') {
    csrfCheck();
    $raw = trim((string) ($_POST['actual_stock'] ?? ''));
    $repo->setActualStock($id, $raw === '' ? null : (int) $raw);
    $flashOk = $raw === '' ? 'Wyczyszczono stan faktyczny.' : 'Zapisano stan faktyczny: ' . max(0, (int) $raw) . ' (tylko w CRM, nie idzie do sklepu ani na Allegro).';
}
$lowDefault = $lowSvc->defaultThreshold();
// Zapis wartości pól własnych.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEdit() && ($_POST['action'] ?? '') === 'save_custom') {
    csrfCheck();
    // Scalamy z zapisanymi wartościami (pola innych kategorii zostają); pola pobierane ze sklepu
    // zmienia tylko import z WooCommerce.
    $vals = json_decode((string) ($repo->find($id)['custom_fields'] ?? ''), true) ?: [];
    $fromShop = array_flip((new \Pase\Services\WooCustomFields($pdo))->mapping());
    foreach (($_POST['cf'] ?? []) as $k => $v) {
        $k = preg_replace('/[^a-z0-9_]/', '', (string) $k);
        if ($k !== '' && !isset($fromShop[$k])) {
            $vals[$k] = is_string($v) ? trim($v) : $v;
        }
    }
    $repo->setCustomFields($id, $vals);
    $flashOk = 'Zapisano własne pola.';
}

// Odświeżenie jednego produktu prosto ze sklepu WooCommerce (bez kolejki) + diagnoza opisów.
$flashErr ??= null;

// Wybór sekcji opisu na Allegro (per produkt; opcjonalnie jako domyślny dla wszystkich).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEdit() && ($_POST['action'] ?? '') === 'save_sections') {
    csrfCheck();
    $cur = $repo->find($id);
    $choice = [];
    foreach (\Pase\Support\DescriptionSections::split((string) ($cur['description'] ?? '')) as $sec) {
        $choice[$sec['key']] = isset($_POST['sec'][$sec['key']]);
    }
    $pdo->prepare('UPDATE products SET allegro_sections = ? WHERE id = ?')
        ->execute([json_encode($choice, JSON_UNESCAPED_UNICODE), $id]);
    $flashOk = 'Zapisano wybór sekcji opisu na Allegro.';
    if (!empty($_POST['as_default'])) {
        $excluded = array_keys(array_filter($choice, static fn($on) => !$on));
        (new \Pase\Repository\SettingsRepository($pdo))->setMany([
            \Pase\Support\DescriptionSections::SETTING_DEFAULT_EXCLUDED => json_encode($excluded, JSON_UNESCAPED_UNICODE),
        ]);
        $flashOk .= ' Ustawiono też jako domyślny dla produktów bez własnego wyboru.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEdit() && ($_POST['action'] ?? '') === 'refresh_from_shop') {
    csrfCheck();
    try {
        $cur = $repo->find($id);
        $intId = (int) ($cur['source_integration_id'] ?? 0);
        $acc = $intId ? (new \Pase\Repository\IntegrationAccountRepository($pdo))->find($intId) : null;
        $wooId = (int) ($cur['woo_product_id'] ?? 0);
        $varId = (int) ($cur['woo_variation_id'] ?? 0);
        if ($cur === null || $acc === null || ($acc['type'] ?? '') !== 'woocommerce' || $wooId <= 0) {
            throw new \RuntimeException('Ten produkt nie jest powiązany ze sklepem WooCommerce — nie ma skąd go pobrać.');
        }
        $woo = \Pase\Plugin\IntegrationClient::for('woocommerce', $acc['config'] ?? []);
        $parent = $woo?->fetchProduct($wooId);
        if (!$parent) {
            throw new \RuntimeException("Sklep „{$acc['name']}” nie zwrócił produktu #{$wooId} (usunięty albo brak dostępu API).");
        }
        if ($varId > 0) {
            $var = $woo->fetchVariation($wooId, $varId);
            if (!$var) {
                throw new \RuntimeException("Sklep nie zwrócił wariantu #{$varId} produktu #{$wooId}.");
            }
            $payload = \Pase\Handlers\WooProductImportHandler::variationPayload($var, $parent, $intId);
        } else {
            $payload = $parent + ['__integration_id' => $intId];
        }
        $payload['__import_mode'] = 'all';
        [$payload['__weight_unit'], $payload['__dim_unit']] = \Pase\Handlers\WooProductImportHandler::shopUnits($woo, $acc['config'] ?? []);
        (new \Pase\Handlers\WooProductImportHandler($repo, \Pase\Services\WooCustomFields::withShopMedia($pdo)))->handle($payload);

        $len = static fn($v) => mb_strlen(trim(strip_tags((string) $v)));
        $dl = $len($payload['description'] ?? '');
        $sl = $len($payload['short_description'] ?? '');
        $flashOk = "Pobrano produkt ze sklepu „{$acc['name']}”. Opis pełny: " . ($dl ? "{$dl} znaków" : 'PUSTY')
            . ', opis krótki: ' . ($sl ? "{$sl} znaków" : 'PUSTY') . '.';
        if ($dl === 0 && $sl === 0) {
            $flashErr = 'WooCommerce zwraca dla tego produktu puste pola „Opis” i „Krótki opis”'
                . ($varId > 0 ? ' (to wariant — sprawdziliśmy też produkt główny)' : '')
                . '. Jeśli w sklepie opis jest widoczny, najpewniej pochodzi z kreatora strony (np. Elementor / Divi) albo z innej wtyczki, a nie z pól opisu produktu.';
        }
    } catch (\Throwable $e) {
        $flashErr = $e->getMessage();
    }
}

$p = $repo->find($id);

if ($p === null) {
    $PAGE_TITLE = 'Produkt'; $PAGE_KEY = 'products';
    require __DIR__ . '/header.php';
    echo '<div class="flash err">Nie znaleziono produktu.</div><p><a class="btn secondary" href="products.php">← Powrót</a></p>';
    require __DIR__ . '/footer.php';
    return;
}

$images     = json_decode($p['images'] ?? '[]', true) ?: [];
// Wariant: obok własnego zdjęcia także zdjęcia produktu głównego (tylko podgląd w CRM).
$parentImages = null;
if (!empty($p['woo_variation_id'])) {
    $parentImages = \Pase\Services\VariantParentImages::decode($p['parent_images'] ?? null);
    if ($parentImages === null) {
        try {
            $parentImages = (new \Pase\Services\VariantParentImages($pdo))->backfill([$p], 1)[(int) $p['id']] ?? null;
        } catch (\Throwable $e) {
            $parentImages = null;
        }
    }
}
$gallery    = \Pase\Services\VariantParentImages::gallery(\Pase\Services\VariantParentImages::fromWoo(['images' => $images]), $parentImages);
$ownGallery    = array_values(array_filter($gallery, static fn($g) => !$g['parent']));
$parentGallery = array_values(array_filter($gallery, static fn($g) => $g['parent']));
$categories = json_decode($p['categories'] ?? '[]', true) ?: [];
$attributes = json_decode($p['attributes'] ?? '{}', true) ?: [];

// Własne pola: tylko pasujące do kategorii produktu (+ pola globalne bez kategorii).
$customDefs = (new ProductFieldRepository($pdo))->forCategories($categories);
$customVals = json_decode($p['custom_fields'] ?? '{}', true) ?: [];
// Wszystkie dodatkowe pola, które sklep zwrócił dla produktu (meta_data z ostatniego pobrania).
$shopMeta = \Pase\Services\WooCustomFields::metaValues((array) ((json_decode((string) ($p['payload'] ?? ''), true) ?: [])['meta_data'] ?? []));

$PAGE_TITLE = 'Produkt: ' . ($p['name'] ?: $p['sku']);
$PAGE_KEY   = 'products';
require __DIR__ . '/header.php';
?>

<p style="margin:-6px 0 16px"><a class="btn secondary" href="products.php">← Powrót do listy</a></p>

<?php if ($flashOk): ?><div class="flash ok"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="flash err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

<!-- ===== Zakładki ===== -->
<?php
// Po zapisie własnych pól wracamy na ich zakładkę.
$activeTab = ($flashOk !== null && ($_POST['action'] ?? '') === 'save_custom') ? 'custom' : 'details';
?>
<?php
// Gdzie produkt jest już wystawiony (do badge'a na zakładce Marketplace).
$offerCount = 0;
try {
    $offerCount = count((new \Pase\Repository\OfferTemplateRepository($pdo))->forProduct((int) $p['id']));
} catch (\Throwable $e) { $offerCount = 0; }
?>
<div class="pv-tabs">
    <button type="button" class="pv-tab<?= $activeTab === 'details' ? ' active' : '' ?>" data-tab="details">Szczegóły</button>
    <button type="button" class="pv-tab<?= $activeTab === 'custom' ? ' active' : '' ?>" data-tab="custom">
        Własne pola<?= $customDefs !== [] ? ' <span class="pv-badge">' . count($customDefs) . '</span>' : '' ?>
    </button>
    <button type="button" class="pv-tab" data-tab="marketplace">
        Marketplace<?= $offerCount > 0 ? ' <span class="pv-badge">' . $offerCount . '</span>' : '' ?>
    </button>
</div>

<!-- ===== TAB: Własne pola ===== -->
<div class="pv-pane" data-pane="custom" style="<?= $activeTab === 'custom' ? '' : 'display:none' ?>">
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center">
        <strong>Własne pola</strong>
        <a href="product_fields.php" style="font-size:12px;color:#1a73e8">⚙ Zarządzaj definicjami pól</a>
    </div>
    <?php if ($customDefs === []): ?>
        <p style="color:#888;font-size:13px;margin-top:8px">Brak pól własnych dla kategorii tego produktu. Dodaj/przypisz je w <a href="product_fields.php">Pola produktów</a>.</p>
    <?php else: ?>
        <form method="post" style="margin-top:10px">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save_custom">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
                <?php foreach ($customDefs as $def): $k = $def['field_key']; $val = $customVals[$k] ?? ''; $shop = ($def['woo_meta_key'] ?? '') !== ''; ?>
                    <div>
                        <label style="font-size:12px;color:#888;display:block"><?= htmlspecialchars($def['label']) ?><?php if ($shop): ?> <span title="Pobierane ze sklepu WooCommerce (klucz <?= htmlspecialchars($def['woo_meta_key']) ?>) przy każdym odświeżeniu produktu. Zmieniasz je w sklepie.">🛒 ze sklepu</span><?php endif; ?></label>
                        <?php if ($shop && $def['type'] === 'image'): ?>
                            <?php if (preg_match('~^https?://~i', (string) $val)): ?>
                                <div style="display:flex;gap:10px;align-items:flex-start">
                                    <a href="<?= htmlspecialchars((string) $val) ?>" target="_blank" rel="noopener"><img src="<?= htmlspecialchars((string) $val) ?>" alt="" style="width:96px;height:96px;object-fit:cover;border-radius:8px;border:1px solid #e5e7eb"></a>
                                    <div style="flex:1;min-width:0">
                                        <input value="<?= htmlspecialchars((string) $val) ?>" style="width:100%" readonly onclick="this.select()">
                                        <a href="<?= htmlspecialchars((string) $val) ?>" target="_blank" rel="noopener" style="font-size:12px">Otwórz zdjęcie ↗</a>
                                    </div>
                                </div>
                            <?php elseif ((string) $val !== ''): ?>
                                <input value="zdjęcie #<?= htmlspecialchars((string) $val) ?> — link pojawi się po „Pobierz ponownie ze sklepu”" style="width:100%" readonly>
                            <?php else: ?>
                                <input value="" style="width:100%" readonly placeholder="— brak w sklepie —">
                            <?php endif; ?>
                        <?php elseif ($shop): ?>
                            <?php if (mb_strlen((string) $val) > 80 || str_contains((string) $val, "\n")): ?>
                                <textarea rows="5" style="width:100%" readonly><?= htmlspecialchars((string) $val) ?></textarea>
                            <?php else: ?>
                                <input value="<?= htmlspecialchars((string) $val) ?>" style="width:100%" readonly placeholder="— brak w sklepie —">
                            <?php endif; ?>
                        <?php elseif ($def['type'] === 'select'): ?>
                            <select name="cf[<?= htmlspecialchars($k) ?>]" style="width:100%" <?= canEdit() ? '' : 'disabled' ?>>
                                <option value="">—</option>
                                <?php foreach ($def['options'] as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= (string)$val === (string)$opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($def['type'] === 'number'): ?>
                            <input name="cf[<?= htmlspecialchars($k) ?>]" type="number" step="any" value="<?= htmlspecialchars((string)$val) ?>" style="width:100%" <?= canEdit() ? '' : 'readonly' ?>>
                        <?php elseif (mb_strlen((string) $val) > 80 || str_contains((string) $val, "\n")): ?>
                            <textarea name="cf[<?= htmlspecialchars($k) ?>]" rows="5" style="width:100%" <?= canEdit() ? '' : 'readonly' ?>><?= htmlspecialchars((string)$val) ?></textarea>
                        <?php else: ?>
                            <input name="cf[<?= htmlspecialchars($k) ?>]" value="<?= htmlspecialchars((string)$val) ?>" style="width:100%" <?= canEdit() ? '' : 'readonly' ?>>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (canEdit()): ?><p style="margin-top:12px"><button class="btn" type="submit">Zapisz własne pola</button></p><?php endif; ?>
        </form>
    <?php endif; ?>
    <?php if ($shopMeta !== []): ?>
        <details style="margin-top:14px">
            <summary style="cursor:pointer;font-size:13px">Wszystkie dodatkowe pola z WooCommerce dla tego produktu (<?= count($shopMeta) ?>)</summary>
            <p style="font-size:12px;color:#888;margin:6px 0">Z ostatniego pobrania ze sklepu. Żeby któreś pokazywać wyżej, dodaj je w <a href="product_fields.php">Pola produktów</a> → „Pola znalezione w sklepie”.</p>
            <table>
                <tr><th>Klucz</th><th>Wartość</th></tr>
                <?php foreach ($shopMeta as $mk => $mv): ?>
                    <tr><td style="white-space:nowrap"><code><?= htmlspecialchars((string) $mk) ?></code></td>
                        <td style="font-size:12px;overflow-wrap:anywhere"><?= htmlspecialchars(mb_strimwidth(strip_tags($mv), 0, 300, '…')) ?></td></tr>
                <?php endforeach; ?>
            </table>
        </details>
    <?php endif; ?>
</div>
</div><!-- /pane custom -->

<!-- ===== TAB: Marketplace ===== -->
<div class="pv-pane" data-pane="marketplace" style="display:none">
<div class="card">
    <strong>Wystaw produkt na marketplace</strong>
    <p style="color:#888;font-size:13px;margin:6px 0 14px">
        Wybierz kanał sprzedaży. Dane oferty (tytuł, opis, cena, zdjęcia) zostaną wstępnie wypełnione
        z magazynu — przed wystawieniem możesz je dopracować i zobaczyć podgląd.
    </p>
    <?php
    // Kanały: Allegro aktywny, reszta jako placeholdery (wkrótce).
    $channels = [
        ['key' => 'allegro', 'name' => 'Allegro', 'icon' => '🛒', 'color' => '#ff5a00', 'ready' => true],
        ['key' => 'ebay',    'name' => 'eBay',    'icon' => '🏷️', 'color' => '#0064d2', 'ready' => false],
        ['key' => 'amazon',  'name' => 'Amazon',  'icon' => '📦', 'color' => '#ff9900', 'ready' => false],
    ];
    $existingOffers = [];
    try {
        foreach ((new \Pase\Repository\OfferTemplateRepository($pdo))->forProduct((int) $p['id']) as $o) {
            $existingOffers[$o['marketplace']] = $o;
        }
    } catch (\Throwable $e) { $existingOffers = []; }
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px">
        <?php foreach ($channels as $ch): $has = isset($existingOffers[$ch['key']]); ?>
            <div style="border:1px solid #e5e7eb;border-radius:10px;padding:14px;display:flex;flex-direction:column;gap:8px">
                <div style="font-size:15px;font-weight:600"><?= $ch['icon'] ?> <?= htmlspecialchars($ch['name']) ?></div>
                <?php if ($ch['ready']): ?>
                    <?php if ($has): ?>
                        <span class="pill ok" style="align-self:flex-start">szablon zapisany</span>
                    <?php endif; ?>
                    <a class="btn" href="offer_<?= $ch['key'] ?>.php?product=<?= (int)$p['id'] ?>"
                       style="background:<?= $ch['color'] ?>;margin-top:auto">
                       <?= $has ? 'Edytuj ofertę' : 'Przygotuj ofertę' ?>
                    </a>
                <?php else: ?>
                    <span class="pill muted" style="align-self:flex-start">wkrótce</span>
                    <button class="btn secondary" type="button" disabled style="margin-top:auto;opacity:.6">Niedostępne</button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
</div><!-- /pane marketplace -->

<!-- ===== TAB: Szczegóły ===== -->
<div class="pv-pane" data-pane="details" style="<?= $activeTab === 'details' ? '' : 'display:none' ?>">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;align-items:start">

    <!-- Lewa: dane podstawowe + opisy -->
    <div class="card">
        <strong>Dane podstawowe</strong>
        <table style="margin-top:10px">
            <tr><th>SKU</th><td><strong><?= htmlspecialchars($p['sku']) ?></strong></td></tr>
            <tr><th>Nazwa</th><td>
                <?php if (canEdit()): ?>
                    <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="save_name">
                        <input name="name" type="text" maxlength="255" required value="<?= htmlspecialchars((string) ($p['name'] ?? '')) ?>" style="flex:1;min-width:220px">
                        <button class="btn secondary" type="submit" style="padding:6px 12px">Zapisz</button>
                        <?php if ((int) ($p['woo_product_id'] ?? 0) > 0): ?>
                            <span style="font-size:12px;color:#888"><?= (int) ($p['woo_variation_id'] ?? 0) > 0
                                ? 'Wariant ze sklepu: nazwa zmieni się tylko w CRM, kolejny import ze sklepu przywróci nazwę produktu głównego.'
                                : 'Produkt ze sklepu: nowa nazwa trafi też do WooCommerce.' ?></span>
                        <?php endif; ?>
                    </form>
                <?php else: ?>
                    <?= htmlspecialchars($p['name'] ?? '—') ?>
                <?php endif; ?>
            </td></tr>
            <tr><th>EAN / GTIN</th><td><?= htmlspecialchars($p['ean'] ?? '—') ?>
                <?php // GS1 (MojeGS1): karta z lokalnej kopii, bez zapytania do GS1 przy każdym wejściu.
                if (canOpenPage('gs1.php') && \Pase\Services\Gs1::available()):
                    $gs1 = new \Pase\Services\Gs1($pdo);
                    if ($gs1->configured()):
                        $gs1Card = $gs1->cardFor($p['ean'] ?? null); ?>
                    <?php if ($gs1Card !== null): ?>
                        <div style="font-size:12.5px;color:var(--ink-2)"><span class="pill ok">GS1 ✓</span> <?= htmlspecialchars($gs1Card['name'] ?: $gs1Card['common_name'] ?: '') ?> <a href="<?= htmlspecialchars($gs1->cardUrl((string) $gs1Card['gtin'])) ?>" style="color:inherit" target="_blank" rel="noopener noreferrer">otwórz w GS1 ↗</a><?= strtoupper((string) $gs1Card['status']) === 'ACT' ? '' : ' · status ' . htmlspecialchars((string) $gs1Card['status']) ?></div>
                    <?php elseif (trim((string) ($p['ean'] ?? '')) !== ''): ?>
                        <div style="font-size:12.5px;color:var(--ink-2)">Nie ma tego numeru wśród Twoich kart w GS1 (<a href="gs1.php?tab=foreign">GS1</a>).</div>
                    <?php elseif (canEditPage('gs1')): ?>
                        <div><a class="btn secondary" style="padding:3px 10px" href="gs1.php?assign=<?= (int) $p['id'] ?>">Nadaj GTIN z GS1</a></div>
                    <?php endif; ?>
                <?php endif; endif; ?>
            </td></tr>
            <tr><th>📍 Lokalizacja</th><td>
                <?php $curLoc = isset($p['location_id']) ? (int) $p['location_id'] : 0; $curLocRow = null;
                    foreach ($locations as $l) { if ($l['id'] === $curLoc) { $curLocRow = $l; } } ?>
                <?php if (canEdit() && $locations !== []): ?>
                    <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="save_location">
                        <?php require_once __DIR__ . '/_loc_picker.php'; echo locPickerData($locations); ?>
                        <?= locPicker('location_id', $curLoc ? (string) $curLoc : '', ['' => '— brak —'], ['wide' => true]) ?>
                        <button class="btn secondary" type="submit" style="padding:6px 12px">Zapisz</button>
                    </form>
                <?php elseif ($curLocRow !== null): ?>
                    <span class="pill ok">📍 <?= htmlspecialchars($curLocRow['code']) ?></span> <span style="color:#888"><?= htmlspecialchars($curLocRow['label']) ?></span>
                <?php else: ?>
                    <span style="color:#888">—<?= $locations === [] && canOpenPage('locations.php') ? ' (najpierw dodaj lokalizacje: <a href="locations.php">Magazyn → Lokalizacje</a>)' : '' ?></span>
                <?php endif; ?>
            </td></tr>
            <tr><th>Stan (CRM)</th><td><?= htmlspecialchars((string)($p['pase_stock'] ?? '—')) ?>
                <?php if (\Pase\Services\LowStock::isLow($p, $lowDefault, $useActual)): $effStock = (int) \Pase\Services\LowStock::stockFor($p, $useActual); ?><span class="pill <?= $effStock <= 0 ? 'bad' : 'warn' ?>" style="margin-left:6px"><?= $effStock <= 0 ? 'Brak' : 'Niski stan' ?><?= $useActual && ($p['actual_stock'] ?? null) !== null ? ' (faktyczny)' : '' ?></span><?php endif; ?>
            </td></tr>
            <?php if ($useActual): ?>
            <tr><th>Stan faktyczny</th><td>
                <?php $actual = $p['actual_stock'] ?? null; ?>
                <?php if (canEdit()): ?>
                    <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="save_actual_stock">
                        <input name="actual_stock" type="number" min="0" value="<?= $actual ?? '' ?>" placeholder="nie wpisany" style="width:120px">
                        <button class="btn secondary" type="submit" style="padding:6px 12px">Zapisz</button>
                        <span style="font-size:12px;color:#888">Ile naprawdę masz na półce. Tylko w CRM: nie idzie do sklepu ani na Allegro. Schodzi przy każdej sprzedaży, wraca przy przyjęciu zwrotu.</span>
                    </form>
                <?php else: ?>
                    <?= $actual === null ? '—' : (int) $actual ?>
                <?php endif; ?>
            </td></tr>
            <?php endif; ?>
            <tr><th>Minimalny stan</th><td>
                <?php $ownMin = isset($p['min_stock']) ? (int) $p['min_stock'] : null;
                    $untracked = $ownMin === \Pase\Services\LowStock::OFF; if ($untracked) { $ownMin = null; } ?>
                <?php if (canEdit()): ?>
                    <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="save_min_stock">
                        <input name="min_stock" type="number" min="0" value="<?= $ownMin ?? '' ?>" placeholder="<?= $lowDefault === null ? 'brak' : 'domyślnie ' . $lowDefault ?>" style="width:120px">
                        <label style="font-size:13px;display:inline-flex;gap:4px;align-items:center"><input type="checkbox" name="untracked" value="1" <?= $untracked ? 'checked' : '' ?>> nie pilnuj stanu</label>
                        <button class="btn secondary" type="submit" style="padding:6px 12px">Zapisz</button>
                        <span style="font-size:12px;color:#888">Alarm w dzwoneczku, gdy stan spadnie do tej liczby. Puste = próg domyślny<?= $lowDefault === null ? ' (wyłączony)' : ' (' . $lowDefault . ')' ?>.</span>
                    </form>
                <?php else: ?>
                    <?= $untracked ? 'nie pilnowany' : ($ownMin ?? ($lowDefault === null ? '—' : $lowDefault . ' <span style="color:#888">(domyślny)</span>')) ?>
                <?php endif; ?>
            </td></tr>
            <tr><th>Produkt wirtualny</th><td>
                <?php $isVirtual = !empty($p['is_virtual']); ?>
                <?php if (canEdit()): ?>
                    <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="save_virtual">
                        <label style="font-size:13px;display:inline-flex;gap:4px;align-items:center;cursor:pointer"><input type="checkbox" name="is_virtual" value="1" <?= $isVirtual ? 'checked' : '' ?> onchange="this.form.submit()"> wirtualny — bez wysyłki</label>
                        <span style="font-size:12px;color:#888">Np. e-book, kurs, voucher. Gdy zamówienie ma tylko takie produkty, w karcie zamówienia zaznaczy się „Wirtualne” (bez wysyłki).</span>
                    </form>
                <?php else: ?>
                    <?= $isVirtual ? '<span class="pill muted">wirtualny — bez wysyłki</span>' : 'nie' ?>
                <?php endif; ?>
            </td></tr>
            <tr><th>Cena (CRM)</th><td><?= htmlspecialchars((string)($p['pase_price'] ?? '—')) ?> zł</td></tr>
            <?php if ($canSeeCost): $pc = $p['purchase_cost'] ?? null; ?>
            <tr><th>Koszt zakupu<?= \Pase\Services\CompanySettings::netSuffix($pdo) ?></th><td>
                <?php if (canEditPage('product_costs')): ?>
                    <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="save_cost">
                        <input name="purchase_cost" inputmode="decimal" value="<?= $pc === null ? '' : htmlspecialchars(number_format((float) $pc, 2, ',', '')) ?>" placeholder="brak" style="width:110px"> zł
                        <button class="btn secondary" type="submit" style="padding:6px 12px">Zapisz</button>
                        <?php if ($pc === null): ?><span class="pill warn">brak — produkt nie wchodzi do marży</span><?php endif; ?>
                    </form>
                <?php else: ?>
                    <?= $pc === null ? '<span class="pill warn">brak</span>' : htmlspecialchars(number_format((float) $pc, 2, ',', ' ')) . ' zł' ?>
                <?php endif; ?>
            </td></tr>
            <?php endif; ?>
            <tr><th>Waga</th><td><?= htmlspecialchars(\Pase\Support\Units::formatWeight($p['weight'])) ?>
                <?php if (\Pase\Support\Units::implausibleWeight($p['weight'] ?? null, $p['length'] ?? null, $p['width'] ?? null, $p['height'] ?? null)): ?>
                    <span class="pill warn" title="Przy tych wymiarach taka waga jest niemożliwa. Najczęściej sklep podaje wagę w gramach, a CRM zapisał ją jako kg.">⚠ to raczej <?= htmlspecialchars(\Pase\Support\Units::num($p['weight'], 3)) ?> g — kliknij „Pobierz ponownie ze sklepu” (niżej)</span>
                <?php endif; ?></td></tr>
            <tr><th>Wymiary (dł×szer×wys)</th><td>
                <?= htmlspecialchars(\Pase\Support\Units::num($p['length'])) ?> × <?= htmlspecialchars(\Pase\Support\Units::num($p['width'])) ?> × <?= htmlspecialchars(\Pase\Support\Units::num($p['height'])) ?> cm
            </td></tr>
            <tr><th>Woo product ID</th><td><?= (int)($p['woo_product_id'] ?? 0) ?: '—' ?><?php if (!empty($p['woo_variation_id'])): ?> <span style="color:#888">(wariant #<?= (int) $p['woo_variation_id'] ?>)</span><?php endif; ?></td></tr>
            <?php
            // Kanały sprzedaży - gdzie ten produkt jest wystawiony.
            // Allegro znamy z product_mappings (uzupełnia je strona Powiązania).
            $allegroOffer = null;
            try {
                $ms = $pdo->prepare('SELECT allegro_offer_id FROM product_mappings WHERE sku = ? LIMIT 1');
                $ms->execute([$p['sku']]);
                $found = (string) ($ms->fetchColumn() ?: '');
                $allegroOffer = $found !== '' ? $found : null;
            } catch (\PDOException $e) {
                $allegroOffer = null;
            }
            ?>
            <tr><th>Kanały sprzedaży</th><td>
                <?php if ((int) ($p['woo_product_id'] ?? 0) > 0): ?>
                    <span style="display:inline-block;padding:2px 7px;border-radius:5px;font-size:11px;font-weight:600;background:#e6f4ea;color:#137333">Sklep</span>
                <?php endif; ?>
                <?php if ($allegroOffer !== null): ?>
                    <a target="_blank" rel="noopener" href="https://allegro.pl/oferta/<?= rawurlencode($allegroOffer) ?>"
                       style="display:inline-block;padding:2px 7px;border-radius:5px;font-size:11px;font-weight:600;background:#e8f0fe;color:#1a56c4;text-decoration:none"
                       title="Oferta <?= htmlspecialchars($allegroOffer) ?>">Allegro</a>
                <?php endif; ?>
                <?php if ((int) ($p['woo_product_id'] ?? 0) <= 0 && $allegroOffer === null): ?>
                    <span style="color:#888">brak powiązań —
                        <a href="allegro_links.php">pobierz z Allegro</a> albo
                        <a href="mappings.php">dodaj ręcznie</a>
                    </span>
                <?php endif; ?>
            </td></tr>
        </table>

        <?php if (!empty($categories)): ?>
            <p style="margin:14px 0 4px;font-weight:600">Kategorie</p>
            <?php foreach ($categories as $c): ?>
                <span class="pill muted" style="margin:2px"><?= htmlspecialchars($c) ?></span>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($attributes)): ?>
            <p style="margin:14px 0 4px;font-weight:600">Atrybuty</p>
            <table>
                <?php foreach ($attributes as $name => $vals): ?>
                    <tr><th><?= htmlspecialchars((string)$name) ?></th><td><?= htmlspecialchars(implode(', ', (array)$vals)) ?></td></tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

    <!-- Prawa: zdjęcia -->
    <div class="card">
        <strong>Zdjęcia (<?= count($gallery) ?>)</strong>
        <?php if (empty($gallery)): ?>
            <p style="color:#888;font-size:13px;margin-top:8px">Brak zdjęć.</p>
        <?php else: ?>
            <?php foreach ([[$ownGallery, !empty($p['woo_variation_id']) ? 'Wariant' : null], [$parentGallery, 'Z produktu głównego']] as [$group, $groupLabel]): ?>
                <?php if ($group === []) { continue; } ?>
                <?php if ($groupLabel !== null && $parentGallery !== []): ?>
                    <p style="margin:12px 0 0;font-size:12px;color:#888;font-weight:600"><?= htmlspecialchars($groupLabel) ?> (<?= count($group) ?>)</p>
                <?php endif; ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:8px;margin-top:<?= $groupLabel !== null && $parentGallery !== [] ? '6' : '10' ?>px">
                    <?php foreach ($group as $img): ?>
                        <img src="<?= htmlspecialchars($img['src']) ?>" alt="" loading="lazy" onclick="pvOpenLightbox(this.src)"
                             <?= $img['parent'] ? 'title="Zdjęcie produktu głównego"' : '' ?>
                             style="width:100%;height:110px;object-fit:cover;border-radius:8px;border:1px solid #e5e7eb;cursor:zoom-in">
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($parentGallery !== []): ?>
                <p style="color:#888;font-size:12px;margin:8px 0 0">Zdjęcia produktu głównego są tylko podglądem w CRM — nie trafiają do wariantu w sklepie ani do oferty Allegro.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Opisy (pełna szerokość) -->
<?php $noDesc = trim(strip_tags((string) ($p['short_description'] ?? ''))) === '' && trim(strip_tags((string) ($p['description'] ?? ''))) === ''; ?>
<?php if (canEdit() && !empty($p['woo_product_id'])): ?>
<form method="post" class="card" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;<?= $noDesc ? 'border:1px solid #f0d9b5;background:#fffaf2' : '' ?>">
    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="refresh_from_shop">
    <span style="font-size:13px;color:#555;flex:1;min-width:240px">
        <?= $noDesc ? 'Brak opisów w magazynie. Pobierz ten produkt ponownie ze sklepu — zobaczysz też, co sklep faktycznie zwraca.'
                    : 'Dane produktu (nazwa, opisy, zdjęcia, kategorie, atrybuty) można odświeżyć ze sklepu. Stan i cena w CRM się nie zmienią.' ?>
    </span>
    <button class="btn <?= $noDesc ? '' : 'secondary' ?>" type="submit">🔄 Pobierz ponownie ze sklepu</button>
</form>
<?php endif; ?>
<div class="card">
    <strong>Opis krótki</strong>
    <div style="margin-top:8px;color:#333"><?= $p['short_description'] !== null && $p['short_description'] !== '' ? $p['short_description'] : '<span style="color:#888">—</span>' ?></div>
</div>
<?php
$descSections  = \Pase\Support\DescriptionSections::split((string) ($p['description'] ?? ''));
$secChoice     = json_decode((string) ($p['allegro_sections'] ?? ''), true);
$secChoice     = is_array($secChoice) ? $secChoice : [];
$secDefaultOff = \Pase\Support\DescriptionSections::defaultExcluded($pdo);
?>
<?php if ($descSections === []): ?>
<div class="card">
    <strong>Opis pełny</strong>
    <div style="margin-top:8px;color:#888">—</div>
</div>
<?php else: ?>
<form method="post" class="card desc-sections">
    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="save_sections">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
        <strong>Opis pełny<?= count($descSections) > 1 ? ' — sekcje (' . count($descSections) . ')' : '' ?></strong>
        <span style="font-size:12px;color:#888">Odznacz sekcje, których nie chcesz w opisie oferty Allegro.</span>
    </div>
    <?php foreach ($descSections as $sec):
        $on = \Pase\Support\DescriptionSections::includedOnAllegro($sec['key'], $secChoice, $secDefaultOff);
        $fromDefault = !array_key_exists($sec['key'], $secChoice); ?>
        <details class="ds-item<?= $on ? '' : ' off' ?>" open>
            <summary>
                <span class="ds-title"><?= htmlspecialchars($sec['title']) ?></span>
                <label class="ds-toggle" onclick="event.stopPropagation()">
                    <input type="checkbox" name="sec[<?= htmlspecialchars($sec['key']) ?>]" value="1" <?= $on ? 'checked' : '' ?>
                           <?= canEdit() ? '' : 'disabled' ?> onchange="this.closest('.ds-item').classList.toggle('off', !this.checked)">
                    Na Allegro<?= $fromDefault ? ' <span class="ds-def" title="Ustawienie domyślne — produkt nie ma własnego wyboru">(domyślnie)</span>' : '' ?>
                </label>
            </summary>
            <div class="ds-body"><?= $sec['html'] ?></div>
        </details>
    <?php endforeach; ?>
    <?php if (canEdit()): ?>
        <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-top:12px">
            <button class="btn" type="submit">Zapisz wybór sekcji</button>
            <label style="font-size:13px;color:#555;cursor:pointer"><input type="checkbox" name="as_default" value="1">
                Ustaw jako domyślny dla wszystkich produktów bez własnego wyboru</label>
        </div>
    <?php endif; ?>
</form>
<style>
.desc-sections .ds-item{border:1px solid #eee;border-radius:10px;margin-top:10px;overflow:hidden}
.desc-sections .ds-item summary{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 14px;background:#faf9f6;cursor:pointer;list-style:none}
.desc-sections .ds-item summary::-webkit-details-marker{display:none}
.desc-sections .ds-title{font-weight:700}
.desc-sections .ds-title::before{content:'▸ ';color:#999}
.desc-sections details[open] .ds-title::before{content:'▾ '}
.desc-sections .ds-toggle{font-size:13px;color:#2c2a26;display:flex;align-items:center;gap:6px;cursor:pointer;white-space:nowrap}
.desc-sections .ds-def{color:#999;font-size:11px}
.desc-sections .ds-body{padding:12px 14px;color:#333;line-height:1.6}
.desc-sections .ds-item.off .ds-body{opacity:.45}
.desc-sections .ds-item.off .ds-title{text-decoration:line-through;color:#999}
</style>
<?php endif; ?>
</div><!-- /pane details -->

<!-- Lightbox do zdjęć produktu -->
<div id="pvLightbox" onclick="pvCloseLightbox()"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:1000;cursor:zoom-out;
            align-items:center;justify-content:center">
    <span onclick="pvCloseLightbox()"
          style="position:absolute;top:18px;right:26px;color:#fff;font-size:32px;line-height:1;cursor:pointer">&times;</span>
    <img id="pvLightboxImg" src="" alt="" style="max-width:calc(90vw / var(--ui-zoom, 1));max-height:calc(90vh / var(--ui-zoom, 1));object-fit:contain;border-radius:6px">
</div>

<style>
.pv-tabs { display:flex; gap:4px; border-bottom:2px solid #e5e7eb; margin-bottom:18px; }
.pv-tab { background:transparent; border:none; border-bottom:2px solid transparent; margin-bottom:-2px;
    padding:10px 18px; font-size:14px; font-weight:600; color:#888; cursor:pointer; font-family:inherit; }
.pv-tab:hover { color:#2c3e50; }
.pv-tab.active { color:#1a73e8; border-bottom-color:#1a73e8; }
.pv-badge { display:inline-block; min-width:18px; padding:0 5px; margin-left:4px; font-size:11px;
    line-height:18px; text-align:center; background:#e8f0fe; color:#1a73e8; border-radius:9px; }
</style>
<script>
document.querySelectorAll('.pv-tab').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var tab = btn.dataset.tab;
        document.querySelectorAll('.pv-tab').forEach(function (b) { b.classList.toggle('active', b === btn); });
        document.querySelectorAll('.pv-pane').forEach(function (p) {
            p.style.display = (p.dataset.pane === tab) ? '' : 'none';
        });
    });
});

function pvOpenLightbox(src) {
    document.getElementById('pvLightboxImg').src = src;
    document.getElementById('pvLightbox').style.display = 'flex';
}
function pvCloseLightbox() {
    document.getElementById('pvLightbox').style.display = 'none';
    document.getElementById('pvLightboxImg').src = '';
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { pvCloseLightbox(); }
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
