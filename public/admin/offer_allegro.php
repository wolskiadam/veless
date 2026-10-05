<?php
declare(strict_types=1);

/**
 * Przygotowanie oferty Allegro dla produktu z magazynu.
 * Formularz ze wszystkimi polami, których Allegro wymaga przy nowej ofercie: kategoria (wyszukiwanie
 * i drzewo) z jej parametrami, cennik i czas wysyłki, lokalizacja, zwroty, reklamacje, gwarancja,
 * faktura i VAT, producent, osoba odpowiedzialna i informacje o bezpieczeństwie (GPSR), opis, zdjęcia.
 * Wstępnie wypełniony danymi produktu + PODGLĄD NA ŻYWO „jak na Allegro" (mockup karty oferty).
 * „Zapisz szablon" zapisuje formularz (offer_templates), „Wystaw" wysyła POST /sale/product-offers
 * (body i sprawdzenie pól: AllegroOfferPayload).
 */

use Pase\Repository\OfferTemplateRepository;
use Pase\Services\AllegroOfferPayload;
use Pase\Repository\AllegroFieldTemplateRepository;
use Pase\Repository\ProductRepository;
use Pase\Repository\SettingsRepository;
use PasePlugin\Allegro\AllegroPlugin;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$repo  = new ProductRepository($pdo);
$offers = new OfferTemplateRepository($pdo);
$settings = new SettingsRepository($pdo);

$productId = (int) ($_GET['product'] ?? 0);
$liveOfferId = preg_replace('/\D/', '', (string) ($_GET['offer'] ?? '')) ?? '';
// Wejście z „Zarządzania ofertami" (?offer=ID): produkt z magazynu po powiązaniu albo sygnaturze (SKU) oferty.
if ($productId === 0 && $liveOfferId !== '') {
    $sku = $repo->findSkuByAllegroOfferId($liveOfferId);
    if ($sku === null) {
        $config = require PASE_ROOT . '/config/config.php';
        $client = AllegroPlugin::makeClient($pdo, $config['allegro'] ?? []);
        $sku = trim((string) ($client->productOffer($liveOfferId)['external']['id'] ?? '')) ?: null;
    }
    $found = $sku !== null ? $repo->findBySku($sku) : null;
    if (isset($_GET['resolve'])) {
        // Produkt dla oferty jako JSON, bez przekierowania.
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($found === null ? ['ok' => false, 'message' => 'Oferta ' . $liveOfferId . ' nie jest powiązana z produktem w magazynie (brak sygnatury/SKU albo powiązania).']
            : ['ok' => true, 'product_id' => (int) $found['id'], 'name' => (string) $found['name']], JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($found === null) {
        $PAGE_TITLE = 'Oferta Allegro'; $PAGE_KEY = 'products';
        require __DIR__ . '/header.php';
        echo '<div class="flash err">Oferta ' . htmlspecialchars($liveOfferId) . ' nie jest powiązana z produktem w magazynie (brak sygnatury/SKU albo powiązania). '
            . 'Powiąż ją w Zarządzaniu ofertami → Operacje → „Powiąż z produktami z magazynu”.</div><p><a class="btn secondary" href="allegro_offers.php">← Oferty</a></p>';
        require __DIR__ . '/footer.php';
        return;
    }
    header('Location: offer_allegro.php?product=' . (int) $found['id'] . '&offer=' . $liveOfferId . '#s-desc', true, 303);
    return;
}
// Wejście z menu (bez produktu): lista produktów z magazynu do wybrania.
if ($productId === 0) {
    require __DIR__ . '/_offer_allegro_picker.php';
    return;
}
$p = $repo->find($productId);

if ($p === null) {
    $PAGE_TITLE = 'Oferta Allegro'; $PAGE_KEY = 'products';
    require __DIR__ . '/header.php';
    echo '<div class="flash err">Nie znaleziono produktu.</div><p><a class="btn secondary" href="offer_allegro.php">← Wybierz produkt</a></p>';
    require __DIR__ . '/footer.php';
    return;
}

$images     = json_decode($p['images'] ?? '[]', true) ?: [];
$categories = json_decode($p['categories'] ?? '[]', true) ?: [];
$attributes = json_decode($p['attributes'] ?? '{}', true) ?: [];

$flashOk = $flashErr = null;
$flashErrors = [];
$saved = $offers->find($productId, 'allegro');
$d = $saved['data'] ?? [];
$publishedOfferId = $saved['data']['offer_id'] ?? null;
// Komunikat po akcji z formularza (Post/Redirect/Get): odświeżenie strony nie wyśle formularza drugi raz.
$afterPost = $_SESSION['al_flash'][$productId] ?? null;
unset($_SESSION['al_flash'][$productId]);
if (is_array($afterPost)) {
    $flashOk = $afterPost['ok'] ?? null;
    $flashErr = $afterPost['err'] ?? null;
    $flashErrors = (array) ($afterPost['errors'] ?? []);
}

require __DIR__ . '/_offer_allegro_params.php';
$config ??= require PASE_ROOT . '/config/config.php';
$client ??= AllegroPlugin::makeClient($pdo, $config['allegro'] ?? []);

/**
 * Lista z Allegro pamiętana w sesji przez 10 min (cenniki, warunki, GPSR), żeby formularz nie pytał API przy każdym wejściu.
 * @param callable():array{ok:bool,items:array} $fetch
 * @return array{ok:bool,items:array,message:string}
 */
function alSessionList(string $key, callable $fetch): array
{
    $c = $_SESSION['al_form_cache'][$key] ?? null;
    if (is_array($c) && ($c['t'] ?? 0) >= time() - 600) {
        return ['ok' => true, 'items' => $c['items'], 'message' => ''];
    }
    $r = $fetch();
    if ($r['ok']) {
        $_SESSION['al_form_cache'][$key] = ['t' => time(), 'items' => $r['items']];
    }
    return $r + ['message' => ''];
}

/**
 * Zdjęcie główne w zalecanym przez Allegro rozmiarze 2560×2560: gotowe zostaje, inne CRM dopasowuje (białe tło, bez
 * przycinania) i wysyła na serwery zdjęć Allegro. Wynik pamiętany w ustawieniach, żeby nie wysyłać drugi raz.
 * @return array{url:string,changed:bool,note:string,warning:string}
 */
function alMainPhoto(PDO $pdo, $client, SettingsRepository $settings, string $url): array
{
    $out = ['url' => $url, 'changed' => false, 'note' => '', 'warning' => ''];
    $cache = (array) json_decode((string) ($settings->get(\Pase\Services\AllegroMainPhoto::CACHE_SETTING, '') ?? ''), true);
    if (in_array($url, array_column(array_filter($cache, 'is_array'), 'url'), true)) {
        return $out;                                    // to już jest gotowe zdjęcie 2560×2560
    }
    $hit = is_array($cache[$url] ?? null) ? $cache[$url] : null;
    if ($hit !== null && is_string($hit['url'] ?? null)) {
        return ['url' => $hit['url'], 'changed' => $hit['url'] !== $url, 'warning' => '',
            'note' => $hit['url'] !== $url ? 'Zdjęcie główne: ' . ($hit['from'] ?? '') . ' → 2560×2560 (białe tło, bez przycinania)' : ''];
    }
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (!\Pase\Services\AllegroMainPhoto::urlAllowed($url, $host !== '' && photoHostKnown($pdo, $host) ? [$host] : [])) {
        return ['warning' => 'Zdjęcie główne jest spoza sklepu i Allegro — CRM nie dopasował go do 2560×2560.'] + $out;
    }
    $res = \Pase\Support\Http::request('GET', $url, [], null, 30);
    $bytes = $res->status === 200 && strlen($res->body) <= 25_000_000 ? $res->body : '';
    $dim = $bytes !== '' ? \Pase\Services\AllegroMainPhoto::dimensions($bytes) : null;
    if ($dim === null) {
        return ['warning' => 'Nie udało się pobrać zdjęcia głównego — CRM nie dopasował go do 2560×2560.'] + $out;
    }
    $remember = static function (array $entry) use ($settings, $cache, $url): void {
        $cache[$url] = $entry;
        $settings->setMany([\Pase\Services\AllegroMainPhoto::CACHE_SETTING => json_encode(array_slice($cache, -300, null, true), JSON_UNESCAPED_SLASHES)]);
    };
    if (\Pase\Services\AllegroMainPhoto::isReady($dim[0], $dim[1])) {
        $remember(['url' => $url, 'from' => '2560×2560']);
        return $out;
    }
    $jpeg = \Pase\Services\AllegroMainPhoto::square($bytes);
    if ($jpeg === null) {
        return ['warning' => 'Nie udało się dopasować zdjęcia głównego do 2560×2560 (serwer nie odczytał zdjęcia).'] + $out;
    }
    $up = $client->uploadImage($jpeg, 'image/jpeg');
    if (!$up['ok']) {
        return ['warning' => 'Allegro nie przyjęło dopasowanego zdjęcia głównego: ' . $up['message']] + $out;
    }
    $from = $dim[0] . '×' . $dim[1];
    $remember(['url' => $up['url'], 'from' => $from]);
    return ['url' => $up['url'], 'changed' => true, 'warning' => '',
        'note' => 'Zdjęcie główne: ' . $from . ' → 2560×2560 (białe tło, bez przycinania)'
            . (max($dim) < \Pase\Services\AllegroMainPhoto::SMALL ? ' — zdjęcie jest małe, po powiększeniu może być mniej ostre' : '')];
}

/** Czy z tego hosta pochodzą zdjęcia jakiegoś produktu w magazynie (tylko takie CRM pobiera). */
function photoHostKnown(PDO $pdo, string $host): bool
{
    $stmt = $pdo->prepare('SELECT images FROM products WHERE images LIKE ? LIMIT 20');
    $stmt->execute(['%' . addcslashes($host, '%_\\') . '%']);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
        foreach ((array) json_decode((string) $json, true) as $u) {
            if (is_string($u) && strtolower((string) parse_url($u, PHP_URL_HOST)) === $host) {
                return true;
            }
        }
    }
    return false;
}

/** Ścieżka kategorii od korzenia: [[id,name],...] + czy liść. Pamiętana w sesji. @return array{path:array<int,array{id:string,name:string}>,leaf:bool}|null */
function alCategoryPath(\PasePlugin\Allegro\AllegroClient $client, string $id): ?array
{
    if ($id === '') {
        return null;
    }
    if (isset($_SESSION['al_cat_path'][$id])) {
        return $_SESSION['al_cat_path'][$id];
    }
    $path = [];
    $leaf = false;
    for ($cur = $id, $guard = 0; $cur !== '' && $guard < 10; $guard++) {
        $c = $client->category($cur);
        if ($c === null) {
            return null;
        }
        if ($cur === $id) {
            $leaf = !empty($c['leaf']);
        }
        array_unshift($path, ['id' => (string) $c['id'], 'name' => (string) ($c['name'] ?? '')]);
        $cur = (string) ($c['parent']['id'] ?? '');
    }
    return $_SESSION['al_cat_path'][$id] = ['path' => $path, 'leaf' => $leaf];
}

// ---- AJAX formularza: drzewo kategorii, wyszukiwanie, parametry wybranej kategorii ----
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ajax = (string) $_GET['ajax'];
    if ($ajax === 'children') {
        $parent = preg_replace('/\D/', '', (string) ($_GET['parent'] ?? '')) ?: null;
        echo json_encode(array_map(static fn($c) => ['id' => (string) $c['id'], 'name' => (string) ($c['name'] ?? ''), 'leaf' => !empty($c['leaf'])],
            $client->categories($parent)), JSON_UNESCAPED_UNICODE);
    } elseif ($ajax === 'match') {
        $out = [];
        foreach ($client->matchingCategories(mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100)) as $c) {
            $names = [];
            for ($n = $c; is_array($n); $n = $n['parent'] ?? null) {
                array_unshift($names, (string) ($n['name'] ?? ''));
            }
            $out[] = ['id' => (string) $c['id'], 'path' => implode(' › ', array_filter($names)), 'leaf' => !empty($c['leaf'])];
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    } elseif ($ajax === 'category') {
        $id = preg_replace('/\D/', '', (string) ($_GET['id'] ?? '')) ?? '';
        $info = alCategoryPath($client, $id);
        if ($info === null) {
            echo json_encode(['ok' => false, 'message' => 'Nie znaleziono kategorii ' . $id . ' na Allegro.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        $params = $info['leaf'] ? AllegroOfferPayload::withTariffCode($client->categoryParameters($id)) : [];
        $same = ($d['category_id'] ?? '') === $id;
        $taxForm = ['tax_subject' => $_GET['tax_subject'] ?? '', 'tax_exemption' => $_GET['tax_exemption'] ?? '', 'vat' => (array) ($_GET['vat'] ?? [])];
        echo json_encode(['ok' => true, 'path' => $info['path'], 'leaf' => $info['leaf'],
            'params' => $info['leaf'] ? renderOfferParams($params, $same ? ($d['params'] ?? null) : null, $attributes,
                (string) ($d['ean'] ?? $p['ean'] ?? ''), $same ? (array) ($d['params_custom'] ?? []) : []) : '',
            'tax' => renderTaxFields(AllegroOfferPayload::taxOptions($info['leaf'] ? $client->taxSettings($id) : []), $taxForm)], JSON_UNESCAPED_UNICODE);
    } elseif ($ajax === 'attach' && $_SERVER['REQUEST_METHOD'] === 'POST' && canEdit()) {
        // Załącznik oferty: plik z formularza -> Allegro (POST /sale/offer-attachments + upload).
        csrfCheck();
        $type = (string) ($_POST['type'] ?? '');
        $f = $_FILES['file'] ?? null;
        $ext = strtolower(pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION));
        $formats = AllegroOfferPayload::attachmentFormats($type);
        if (!isset(AllegroOfferPayload::ATTACHMENT_TYPES[$type])) {
            $r = ['ok' => false, 'message' => 'Wybierz rodzaj załącznika.'];
        } elseif (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            $r = ['ok' => false, 'message' => 'Nie udało się wczytać pliku (sprawdź rozmiar pliku).'];
        } elseif (!isset($formats[$ext])) {
            $r = ['ok' => false, 'message' => 'Ten rodzaj załącznika przyjmuje pliki: ' . strtoupper(implode(', ', array_keys($formats))) . '.'];
        } else {
            $name = mb_substr(basename((string) $f['name']), 0, 200);
            $up = $client->uploadOfferAttachment($type, $name, $formats[$ext], (string) file_get_contents((string) $f['tmp_name']));
            $r = $up['ok'] ? ['ok' => true, 'id' => $up['id'], 'type' => $type, 'name' => $name] : ['ok' => false, 'message' => $up['message']];
        }
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
    } elseif ($ajax === 'fee' && $_SERVER['REQUEST_METHOD'] === 'POST' && canEdit()) {
        // „Podsumowanie": szacunkowa prowizja i opłaty za ofertę w obecnym kształcie (POST /pricing/offer-fee-preview).
        csrfCheck();
        $feeCat = preg_replace('/\D/', '', (string) ($_POST['category_id'] ?? '')) ?? '';
        $feeInfo = $feeCat !== '' ? alCategoryPath($client, $feeCat) : null;
        $built = $feeInfo !== null && $feeInfo['leaf']
            ? AllegroOfferPayload::build($_POST, AllegroOfferPayload::withTariffCode($client->categoryParameters($feeCat)))['payload'] : null;
        $r = AllegroOfferPayload::feeOffer($_POST, $built);
        if ($r['error'] !== '') {
            echo json_encode(['ok' => false, 'message' => $r['error']], JSON_UNESCAPED_UNICODE);
            return;
        }
        $fee = $client->offerFeePreview($r['offer']);
        $rows = [];
        foreach (array_merge($fee['commissions'], $fee['quotes']) as $it) {
            $rows[] = ['name' => (string) ($it['name'] ?? $it['type'] ?? 'Opłata'),
                'amount' => number_format((float) ($it['fee']['amount'] ?? 0), 2, ',', ' ') . ' ' . (($it['fee']['currency'] ?? 'PLN') === 'PLN' ? 'zł' : (string) $it['fee']['currency'])];
        }
        echo json_encode(['ok' => $fee['ok'], 'message' => $fee['message'], 'rows' => $rows], JSON_UNESCAPED_UNICODE);
    } elseif ($ajax === 'products') {
        // Katalog Allegro: po EAN (same cyfry) albo po nazwie, w wybranej kategorii.
        $q = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 100));
        $gtin = (bool) preg_match('/^\d{8,14}$/', $q);
        $r = $q === '' ? ['ok' => true, 'products' => [], 'message' => '']
            : $client->searchProducts($q, $gtin ? null : (preg_replace('/\D/', '', (string) ($_GET['cat'] ?? '')) ?: null), $gtin);
        echo json_encode(['ok' => $r['ok'], 'message' => $r['message'], 'products' => array_map(static fn($pr) => [
            'id' => (string) ($pr['id'] ?? ''), 'name' => (string) ($pr['name'] ?? ''),
            'image' => (string) ($pr['images'][0]['url'] ?? ''), 'category' => (string) ($pr['category']['id'] ?? ''),
        ], array_slice($r['products'], 0, 20))], JSON_UNESCAPED_UNICODE);
    } elseif ($ajax === 'live_offers') {
        // Trwające oferty tego produktu: powiązanie, wystawiona z CRM, sygnatura (SKU) na Allegro.
        $sku = (string) ($p['sku'] ?? '');
        $ids = [];
        $st = $pdo->prepare('SELECT allegro_offer_id FROM product_mappings WHERE sku = ? AND allegro_offer_id IS NOT NULL');
        $st->execute([$sku]);
        foreach (array_merge($st->fetchAll(PDO::FETCH_COLUMN), [$publishedOfferId]) as $id) {
            if ((string) $id !== '') {
                $ids[(string) $id] = ['id' => (string) $id, 'name' => '', 'status' => ''];
            }
        }
        foreach ($sku !== '' ? $client->listOffersRaw('external.id=' . rawurlencode($sku) . '&limit=20') : [] as $o) {
            $ids[(string) $o['id']] = ['id' => (string) $o['id'], 'name' => (string) ($o['name'] ?? ''), 'status' => (string) ($o['publication']['status'] ?? '')];
        }
        echo json_encode(array_values($ids), JSON_UNESCAPED_UNICODE);
    } elseif ($ajax === 'live_desc') {
        // Obecny opis trwającej oferty -> edytor.
        $o = $client->productOffer(preg_replace('/\D/', '', (string) ($_GET['id'] ?? '')) ?? '');
        echo json_encode($o === null ? ['ok' => false, 'message' => 'Nie udało się pobrać oferty z Allegro.']
            : ['ok' => true, 'sections' => AllegroOfferPayload::descriptionSections(['description_sections' => (array) ($o['description']['sections'] ?? [])])], JSON_UNESCAPED_UNICODE);
    } elseif ($ajax === 'push_desc' && $_SERVER['REQUEST_METHOD'] === 'POST' && canEdit()) {
        // Opis z edytora -> trwająca oferta (PATCH description + images; zdjęcia z opisu muszą być w galerii).
        csrfCheck();
        $oid = preg_replace('/\D/', '', (string) ($_POST['offer_id'] ?? '')) ?? '';
        $new = AllegroOfferPayload::descriptionSections(['description_sections' => (string) ($_POST['description_sections'] ?? '[]')]);
        $o = $oid !== '' ? $client->productOffer($oid) : null;
        if ($new === []) {
            $r = ['ok' => false, 'message' => 'Opis jest pusty.'];
        } elseif ($o === null) {
            $r = ['ok' => false, 'message' => 'Nie udało się pobrać oferty ' . $oid . ' z Allegro.'];
        } else {
            $m = AllegroOfferPayload::mergeDescriptionImages(array_map(static fn($i) => is_array($i) ? (string) ($i['url'] ?? '') : (string) $i, (array) ($o['images'] ?? [])),
                (array) ($o['description']['sections'] ?? []), $new);
            $r = $m['error'] !== '' ? ['ok' => false, 'message' => $m['error']]
                : $client->patchOffer($oid, ['description' => ['sections' => $new], 'images' => $m['images']]);
            if ($r['ok']) {
                $r['message'] = 'Zmieniono opis oferty ' . $oid . ' na Allegro.';
            }
        }
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(400);
        echo '{}';
    }
    return;
}

/**
 * Sekcje szablonu opisu Allegro z podstawionymi tagami [nazwa], [cena]... (AllegroOfferTags::resolve) -
 * w formacie edytora blokowego (description.sections[].items[]).
 * @param array<string,mixed> $product
 * @return array<int,array{items:array<int,array<string,string>>}>
 */
function resolveDescSections(array $sections, array $product): array
{
    $out = [];
    foreach ($sections as $sec) {
        $items = [];
        foreach (array_slice((array) ($sec['items'] ?? []), 0, 2) as $item) {
            if (($item['type'] ?? '') === 'IMAGE' && ($item['url'] ?? '') !== '') {
                $items[] = ['type' => 'IMAGE', 'url' => (string) $item['url']];
            } elseif (($item['content'] ?? '') !== '') {
                $items[] = ['type' => 'TEXT', 'content' => AllegroOfferPayload::sanitizeHtml(
                    \Pase\Services\AllegroOfferTags::resolve((string) $item['content'], $product))];
            }
        }
        if ($items !== []) {
            $out[] = ['items' => $items];
        }
    }
    return $out;
}

$descTemplates = [];
foreach ((new \Pase\Repository\AllegroDescTemplateRepository($pdo))->all() as $t) {
    $descTemplates[] = ['id' => (int) $t['id'], 'name' => (string) $t['name'], 'sections' => resolveDescSections((array) ($t['sections'] ?? []), $p)];
}

// Opis startowy: zapisana oferta > szablon opisu pasujący do kategorii > opis produktu z magazynu.
$descFromTemplate = false;
if (isset($d['description_sections']) && is_array($d['description_sections'])) {
    $descSections = $d['description_sections'];
} elseif (isset($d['description'])) {
    $descSections = AllegroOfferPayload::descriptionSections(['description' => (string) $d['description']]);
} else {
    $tpl = (new \Pase\Repository\AllegroDescTemplateRepository($pdo))->bestForCategories($categories);
    $descSections = $tpl !== null ? resolveDescSections($tpl['sections'], $p) : [];
    $descFromTemplate = $descSections !== [];
    if ($descSections === []) {
        $descSections = AllegroOfferPayload::descriptionSections(['description' =>
            \Pase\Support\DescriptionSections::allegroHtml($p, \Pase\Support\DescriptionSections::defaultExcluded($pdo))]);
    }
}

$def = static fn(string $field): string => (string) ($settings->get(AllegroOfferPayload::DEFAULT_SETTINGS[$field], '') ?? '');

// Stan formularza: zapisana oferta ma priorytet, inaczej dane z magazynu (prefill) i domyślne z ostatniego wystawienia.
// Starsze szablony miały kategorię jako tekst - liczbowe ID z niej przejmujemy.
$legacyCat = preg_replace('/\D/', '', (string) ($d['category'] ?? '')) ?? '';
$form = [
    'title'    => $d['title']    ?? mb_substr((string) ($p['name'] ?? ''), 0, 75),
    'price'    => $d['price']    ?? ($p['pase_price'] !== null ? (string) $p['pase_price'] : ''),
    'stock'    => $d['stock']    ?? ($p['pase_stock'] !== null ? (string) $p['pase_stock'] : ''),
    'category_id' => $d['category_id'] ?? ($legacyCat === (string) ($d['category'] ?? '') ? $legacyCat : ''),
    'params'   => $d['params']   ?? null,
    'params_custom' => $d['params_custom'] ?? [],
    'catalog_product_id'   => $d['catalog_product_id'] ?? '',
    'catalog_product_name' => $d['catalog_product_name'] ?? '',
    'set_mode'             => ($d['set_mode'] ?? '') === 'set' ? 'set' : 'single',
    'set_quantity'         => (string) ($d['set_quantity'] ?? '2'),
    'ean'      => $d['ean']      ?? ($p['ean'] ?? ''),
    'signature'=> $d['signature']?? (string) ($p['sku'] ?? ''),
    'handling' => $d['handling'] ?? (string) ($settings->get(\Pase\Services\AllegroOfferOperations::DEFAULT_HANDLING_SETTING, 'PT24H') ?? 'PT24H'),
    'description_sections' => $descSections,
    'images'   => $d['images']   ?? $images,
    'safety_mode' => $d['safety_mode'] ?? 'text',
    'safety_text' => $d['safety_text'] ?? '',
    'publish_mode' => $d['publish_mode'] ?? 'draft',
    'message_mode' => ($d['message_mode'] ?? '') === 'HIDDEN' ? 'HIDDEN' : 'OPTIONAL',
    'buyers'       => ($d['buyers'] ?? '') === 'business' ? 'business' : 'all',
    'attachments'  => AllegroOfferPayload::attachments(['attachments' => $d['attachments'] ?? []]),
    'publish_date' => '',
    'publish_time' => '',
    'selling_format' => ($d['selling_format'] ?? '') === 'AUCTION' ? 'AUCTION' : 'BUY_NOW',
    'minimal_price'  => (string) ($d['minimal_price'] ?? ''),
    'stock_unit'     => isset(AllegroOfferPayload::STOCK_UNITS[$d['stock_unit'] ?? '']) ? $d['stock_unit'] : 'UNIT',
    'duration'       => (string) ($d['duration'] ?? ''),
    'republish'      => !empty($d['republish']),
    'preorder'       => !empty($d['preorder']),
    'shipment_date'  => (string) ($d['shipment_date'] ?? ''),
    'delivery_info'  => (string) ($d['delivery_info'] ?? ''),
    'additional_services'  => (string) ($d['additional_services'] ?? ''),
    'wholesale_price_list' => (string) ($d['wholesale_price_list'] ?? ''),
    'extra_products'   => AllegroOfferPayload::extraProducts(['extra_products' => $d['extra_products'] ?? []]),
    'markets'          => array_intersect_key((array) ($d['markets'] ?? []), AllegroOfferPayload::MARKETS),
    'price_rule'       => (string) ($d['price_rule'] ?? ''),
    'price_rule_min'   => (string) ($d['price_rule_min'] ?? ''),
    'price_rule_max'   => (string) ($d['price_rule_max'] ?? ''),
    'promo_package'    => (string) ($d['promo_package'] ?? ''),
    'promo_department' => !empty($d['promo_department']),
    'ads'              => !empty($d['ads']),
    'main_photo_2560'  => ($d['main_photo_2560'] ?? true) !== false,
];
foreach (array_keys(AllegroOfferPayload::DEFAULT_SETTINGS) as $k) {
    $form[$k] = $d[$k] ?? $def($k);
}
if ($form['invoice'] === '') {
    $form['invoice'] = 'VAT';
}
$form['vat'] = $d['vat'] ?? (isset($d['vat_rate']) && $d['vat_rate'] !== '' ? ['PL' => $d['vat_rate']]
    : (json_decode((string) ($settings->get(AllegroOfferPayload::DEFAULT_VAT_SETTING, '') ?? ''), true) ?: []));

// Szablony pól: domyślny wypełnia nową ofertę, ?template=ID nakłada wybrany na obecny formularz.
AllegroFieldTemplateRepository::migrate($pdo);
$fieldTemplates = new AllegroFieldTemplateRepository($pdo);
$appliedTemplate = null;
if (isset($_GET['template']) && ($t = $fieldTemplates->find((int) $_GET['template'])) !== null) {
    $appliedTemplate = $t;
} elseif ($saved === null && $_SERVER['REQUEST_METHOD'] !== 'POST' && $liveOfferId === '') {
    $appliedTemplate = $fieldTemplates->findDefault();
}
if ($appliedTemplate !== null) {
    $form = AllegroFieldTemplateRepository::apply($form, $appliedTemplate['data']);
    $flashOk = 'Zastosowano szablon „' . $appliedTemplate['name'] . '"' . ($appliedTemplate['is_default'] && !isset($_GET['template']) ? ' (domyślny)' : '')
        . '. Sprawdź pola i zapisz albo wystaw.';
}

// Trwająca oferta (?offer=ID z Zarządzania ofertami): formularz z jej obecnymi danymi z Allegro, zamiast „Wystaw" jest „Aktualizuj".
// Po nieudanej aktualizacji (&kept=1) zostają dane wpisane w formularzu.
$liveLoaded = false;
if ($liveOfferId !== '' && $_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_GET['kept']) && !isset($_GET['ajax'])) {
    $liveOffer = $client->productOffer($liveOfferId);
    if ($liveOffer === null) {
        $flashErr ??= 'Nie udało się pobrać oferty ' . $liveOfferId . ' z Allegro — formularz pokazuje dane zapisane w CRM. Sprawdź połączenie z kontem i odśwież stronę.';
    } else {
        $liveCat = preg_replace('/\D/', '', (string) ($liveOffer['category']['id'] ?? '')) ?? '';
        $form = array_merge($form, \Pase\Services\AllegroLiveOffer::formFromOffer($liveOffer, $liveCat !== '' ? $client->categoryParameters($liveCat) : []));
        $liveLoaded = true;
        $flashOk ??= 'Wczytano obecne dane oferty ' . $liveOfferId . ' z Allegro. Po zmianach kliknij „Aktualizuj ofertę na Allegro”.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEdit()) {
    csrfCheck();
    $formBase = $form;      // stan formularza przed danymi z POST (podstawa porównania trwającej oferty)
    foreach (['title', 'price', 'stock', 'signature', 'city', 'post_code', 'catalog_product_id', 'catalog_product_name', 'publish_date', 'publish_time', 'set_quantity'] as $k) {
        $form[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $form['set_mode'] = ($_POST['set_mode'] ?? '') === 'set' ? 'set' : 'single';
    foreach (['minimal_price', 'shipment_date', 'additional_services', 'wholesale_price_list', 'duration'] as $k) {
        $form[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $form['delivery_info']  = trim((string) ($_POST['delivery_info'] ?? ''));
    $form['selling_format'] = ($_POST['selling_format'] ?? '') === 'AUCTION' ? 'AUCTION' : 'BUY_NOW';
    $form['stock_unit'] = isset(AllegroOfferPayload::STOCK_UNITS[$_POST['stock_unit'] ?? '']) ? $_POST['stock_unit'] : 'UNIT';
    $form['republish']  = !empty($_POST['republish']);
    $form['extra_products'] = AllegroOfferPayload::extraProducts(['extra_products' => is_array($_POST['extra_products'] ?? null) ? $_POST['extra_products'] : []]);
    $form['markets'] = [];
    foreach (AllegroOfferPayload::MARKETS as $mk => $_) {
        $form['markets'][$mk] = trim((string) ($_POST['markets'][$mk] ?? ''));
    }
    foreach (['price_rule', 'price_rule_min', 'price_rule_max', 'promo_package'] as $k) {
        $form[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $form['promo_department'] = !empty($_POST['promo_department']);
    $form['ads'] = !empty($_POST['ads']);
    $form['main_photo_2560'] = !empty($_POST['main_photo_2560']);
    $form['preorder']   = !empty($_POST['preorder']);
    foreach (['shipping_rate', 'return_policy', 'implied_warranty', 'warranty', 'producer', 'person', 'tax_subject', 'tax_exemption'] as $k) {
        $form[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $form['category_id'] = preg_replace('/\D/', '', (string) ($_POST['category_id'] ?? '')) ?? '';
    $form['params']    = is_array($_POST['params'] ?? null) ? $_POST['params'] : [];
    $form['params_custom'] = array_filter(array_map(static fn($v) => is_scalar($v) ? trim((string) $v) : '', (array) ($_POST['params_custom'] ?? [])), static fn($v) => $v !== '');
    $form['vat'] = [];
    foreach (AllegroOfferPayload::VAT_COUNTRIES as $cc => $_) {
        $v = trim((string) ($_POST['vat'][$cc] ?? ''));
        if ($v !== '') {
            $form['vat'][$cc] = $v;
        }
    }
    // EAN oferty = parametr EAN (GTIN) z formularza parametrów.
    $eanIn = $form['params'][AllegroOfferPayload::EAN_PARAMETER_ID] ?? null;
    if (is_scalar($eanIn) && trim((string) $eanIn) !== '') {
        $form['ean'] = trim((string) $eanIn);
    }
    $form['invoice']   = isset(AllegroOfferPayload::INVOICES[$_POST['invoice'] ?? '']) ? $_POST['invoice'] : 'VAT';
    $form['province']  = isset(AllegroOfferPayload::PROVINCES[$_POST['province'] ?? '']) ? $_POST['province'] : '';
    $form['safety_mode'] = ($_POST['safety_mode'] ?? 'text') === 'none' ? 'none' : 'text';
    $form['safety_text'] = (string) ($_POST['safety_text'] ?? '');
    $form['message_mode'] = ($_POST['message_mode'] ?? '') === 'HIDDEN' ? 'HIDDEN' : 'OPTIONAL';
    $form['buyers'] = ($_POST['buyers'] ?? '') === 'business' ? 'business' : 'all';
    $form['attachments'] = AllegroOfferPayload::attachments(['attachments' => is_array($_POST['attachments'] ?? null) ? $_POST['attachments'] : []]);
    $form['publish_mode'] = in_array($_POST['publish_mode'] ?? '', ['draft', 'now', 'scheduled'], true) ? $_POST['publish_mode'] : 'draft';
    // Opis z edytora blokowego (JSON sekcji), oczyszczony do tagów, które przyjmuje Allegro.
    $form['description_sections'] = AllegroOfferPayload::descriptionSections(['description_sections' => (string) ($_POST['description_sections'] ?? '[]')]);
    $form['handling']  = isset(\Pase\Services\AllegroOfferOperations::HANDLING_TIMES[$_POST['handling'] ?? '']) ? $_POST['handling'] : 'PT24H';
    // Zdjęcia: kolejność z galerii (pierwsze = główne).
    $form['images']    = array_values(array_unique(array_filter((array) ($_POST['images'] ?? []),
        static fn($u) => is_string($u) && preg_match('#^https?://#i', trim($u)))));

    $action = $_POST['action'] ?? 'save';
    // Zdjęcie główne 2560×2560 (zalecenie Allegro) - przy wystawianiu i aktualizacji trwającej oferty.
    $mainPhoto = null;
    if (in_array($action, ['publish', 'live_diff', 'update_live'], true) && $form['main_photo_2560'] && is_string($form['images'][0] ?? null)) {
        $mainPhoto = alMainPhoto($pdo, $client, $settings, $form['images'][0]);
        $form['images'][0] = $mainPhoto['url'];
    }
    $store = $form;
    unset($store['publish_date'], $store['publish_time']);

    if ($action === 'save_template') {
        $offers->save($productId, 'allegro', $store, $saved['status'] ?? 'draft');
        $tplName = trim((string) ($_POST['template_name'] ?? ''));
        $tplId = (int) ($_POST['template_id'] ?? 0);
        if ($tplName === '' && $tplId > 0) {
            $tplName = (string) ($fieldTemplates->find($tplId)['name'] ?? '');
        }
        if ($tplName === '') {
            $flashErr = 'Podaj nazwę szablonu.';
        } else {
            $fieldTemplates->save($tplId > 0 ? $tplId : null, $tplName,
                AllegroFieldTemplateRepository::fromForm($form, !empty($_POST['template_category'])), !empty($_POST['template_default']));
            $flashOk = 'Zapisano szablon pól „' . $tplName . '". Wybierzesz go przy kolejnych ofertach.';
        }
    } elseif ($action === 'delete_template') {
        $offers->save($productId, 'allegro', $store, $saved['status'] ?? 'draft');
        $fieldTemplates->delete((int) ($_POST['template_id'] ?? 0));
        $flashOk = 'Usunięto szablon pól.';
    } elseif (($action === 'live_diff' || $action === 'update_live') && $liveOfferId !== '') {
        // Aktualizacja trwającej oferty: te same sprawdzenia co przy wystawianiu, potem PATCH (nigdy nowa oferta).
        // Pola zmienione przez operatora (przeglądarka porównuje formularz ze stanem po wczytaniu z Allegro);
        // wszystko inne bierzemy z oferty na Allegro, więc np. dostawa zostaje dokładnie taka, jaka jest.
        $liveChanged = json_decode((string) ($_POST['live_changed'] ?? ''), true);
        $liveChanged = is_array($liveChanged) ? array_values(array_filter($liveChanged, 'is_string')) : null;
        if ($liveChanged !== null && ($mainPhoto['changed'] ?? false)) {
            $liveChanged[] = 'images';
        }
        $current = $client->productOffer($liveOfferId);
        $liveCatId = preg_replace('/\D/', '', (string) ($current['category']['id'] ?? '')) ?? '';
        $catId = $liveChanged !== null && !in_array('category_id', $liveChanged, true) && $liveCatId !== '' ? $liveCatId : $form['category_id'];
        $catInfo = $catId !== '' ? alCategoryPath($client, $catId) : null;
        $catParams = $catInfo !== null && $catInfo['leaf'] ? AllegroOfferPayload::withTariffCode($client->categoryParameters($catId)) : [];
        $baseForm = $current !== null ? array_merge($formBase, \Pase\Services\AllegroLiveOffer::formFromOffer($current, $catParams)) : $form;
        $editForm = $current !== null ? \Pase\Services\AllegroLiveOffer::editedForm($baseForm, $form, $liveChanged) : $form;
        $built = AllegroOfferPayload::build($editForm, $catParams, $catInfo === null || $catInfo['leaf']);
        if ($catId !== '' && $catInfo === null) {
            $built['errors'][] = 'Nie udało się pobrać kategorii z Allegro — sprawdź połączenie z kontem.';
        }
        $cmp = ['patch' => [], 'changes' => []];
        if ($current === null) {
            $built['errors'][] = 'Nie udało się pobrać oferty ' . $liveOfferId . ' z Allegro.';
        } else {
            // Zdjęcia opisu dopisujemy do galerii jak przy „Wyślij ten opis do oferty" (stare grafiki opisu odpadają tylko ponad limit 16).
            $newPayload = $built['payload'];
            $merged = AllegroOfferPayload::mergeDescriptionImages((array) ($editForm['images'] ?? []), (array) ($current['description']['sections'] ?? []),
                (array) ($newPayload['description']['sections'] ?? []));
            $built['errors'] = array_values(array_filter($built['errors'], static fn($e) => !str_starts_with($e, 'Oferta może mieć maks.')));
            if ($merged['error'] !== '') {
                $built['errors'][] = $merged['error'];
            }
            $newPayload['images'] = $merged['images'];
            // Podstawa: ta sama oferta z Allegro przepuszczona przez formularz - różnice to tylko to, co zmieniono w formularzu.
            $basePayload = AllegroOfferPayload::build($baseForm, $catParams, true)['payload'];
            $cmp = \Pase\Services\AllegroLiveOffer::compare(\Pase\Services\AllegroLiveOffer::patchPayload($basePayload),
                \Pase\Services\AllegroLiveOffer::patchPayload($newPayload));
        }
        if ($action === 'live_diff') {
            header('Content-Type: application/json; charset=utf-8');
            $notes = array_values(array_filter([$mainPhoto['changed'] ?? false ? $mainPhoto['note'] : '', $mainPhoto['warning'] ?? '']));
            echo json_encode($built['errors'] !== [] ? ['ok' => false, 'errors' => $built['errors']]
                : ['ok' => true, 'changes' => array_merge($notes, $cmp['changes'])], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $patch = $cmp['patch'];
        $offers->save($productId, 'allegro', $store, $saved['status'] ?? 'draft');
        if ($built['errors'] !== []) {
            $flashErr = 'Oferta ' . $liveOfferId . ' nie została zmieniona. Uzupełnij pola wymagane przez Allegro:';
            $flashErrors = $built['errors'];
        } else {
            $res = $patch === [] ? ['ok' => true, 'message' => ''] : $client->patchOffer($liveOfferId, $patch);
            if ($res['ok']) {
                $liveUpdated = true;
                $flashOk = 'Zaktualizowano ofertę ' . $liveOfferId . ' na Allegro. Zmiany mogą być widoczne na Allegro po kilku minutach.'
                    . (($mainPhoto['changed'] ?? false) ? ' ' . $mainPhoto['note'] . '.' : '') . (($mainPhoto['warning'] ?? '') !== '' ? ' ' . $mainPhoto['warning'] : '');
            } else {
                $flashErr = 'Allegro nie przyjęło zmian oferty ' . $liveOfferId . ': ' . $res['message'];
            }
        }
    } elseif ($action === 'publish' && $liveOfferId === '') {
        $catInfo = $form['category_id'] !== '' ? alCategoryPath($client, $form['category_id']) : null;
        $catParams = $catInfo !== null && $catInfo['leaf'] ? AllegroOfferPayload::withTariffCode($client->categoryParameters($form['category_id'])) : [];
        $built = AllegroOfferPayload::build($form, $catParams, $catInfo === null || $catInfo['leaf']);
        if ($form['category_id'] !== '' && $catInfo === null) {
            $built['errors'][] = 'Nie udało się pobrać kategorii z Allegro — sprawdź połączenie z kontem.';
        } elseif ($catInfo !== null && $catInfo['leaf'] && $catParams === []) {
            $built['errors'][] = 'Nie udało się pobrać parametrów kategorii z Allegro — spróbuj ponownie.';
        }
        if ($built['errors'] !== []) {
            $offers->save($productId, 'allegro', $store, 'draft');
            $flashErr = 'Uzupełnij pola wymagane przez Allegro:';
            $flashErrors = $built['errors'];
        } else {
            $res = $client->createOffer($built['payload']);
            if ($res['ok']) {
                $store['offer_id'] = $res['offer_id'];
                $offers->save($productId, 'allegro', $store, 'published');
                $publishedOfferId = $res['offer_id'];
                // Użyte warunki, cennik, GPSR i lokalizacja stają się domyślnymi dla kolejnych ofert.
                $defaults = [];
                foreach (AllegroOfferPayload::DEFAULT_SETTINGS as $field => $key) {
                    $defaults[$key] = (string) $form[$field];
                }
                $defaults[AllegroOfferPayload::DEFAULT_VAT_SETTING] = json_encode($form['vat']);
                $settings->setMany($defaults);
                // Po utworzeniu oferty: automatyczne ceny i płatne wyróżnienie (osobne wywołania Allegro).
                $after = [];
                [$ruleBody] = AllegroOfferPayload::priceRule($form);
                if ($ruleBody !== null) {
                    $rr = $client->setOfferPriceRules($res['offer_id'], $ruleBody);
                    $after[] = $rr['ok'] ? 'Podpięto regułę automatycznych cen.' : 'Nie udało się podpiąć reguły cen: ' . $rr['message'];
                }
                if (($promo = AllegroOfferPayload::promotion($form)) !== null) {
                    $pr = $client->modifyOfferPromotion($res['offer_id'], $promo);
                    $after[] = $pr['ok'] ? 'Włączono wyróżnienie.' : 'Nie udało się włączyć wyróżnienia: ' . $pr['message'];
                }
                if ($form['ads']) {
                    $adsReminder = true;       // link do naszego panelu Ads pod komunikatem
                }
                $pub = $built['payload']['publication'];
                $flashOk = 'Wystawiono ofertę do Allegro' . match (true) {
                    isset($pub['startingAt']) => ' — pojawi się ' . $form['publish_date'] . ' o ' . $form['publish_time'],
                    $pub['status'] === 'ACTIVE' => ' — aktywacja w toku',
                    default => ' (nieaktywna — sprawdź i aktywuj)',
                } . '. ID: ' . $res['offer_id'] . ($after !== [] ? '. ' . implode(' ', $after) : '')
                    . (($mainPhoto['changed'] ?? false) ? '. ' . $mainPhoto['note'] : '') . (($mainPhoto['warning'] ?? '') !== '' ? '. ' . $mainPhoto['warning'] : '');
            } else {
                $offers->save($productId, 'allegro', $store, 'draft');
                $flashErr = 'Allegro nie przyjęło oferty: ' . $res['message'];
            }
        }
    } else {
        $offers->save($productId, 'allegro', $store, 'draft');
        $flashOk = 'Zapisano ofertę (szkic w CRM).';
    }
    // Stan formularza jest już zapisany - przekierowanie na GET, żeby „odśwież" nie wysyłał formularza (np. drugi raz oferty).
    $_SESSION['al_flash'][$productId] = ['ok' => $flashOk, 'err' => $flashErr, 'errors' => $flashErrors, 'ads' => !empty($adsReminder),
        'publish_date' => $form['publish_date'], 'publish_time' => $form['publish_time']];
    header('Location: offer_allegro.php?product=' . $productId . ($liveOfferId !== '' ? '&offer=' . $liveOfferId . (empty($liveUpdated) ? '&kept=1' : '') : ''), true, 303);
    exit;
}
if (is_array($afterPost) && ($afterPost['publish_date'] ?? '') !== '') {
    $form['publish_date'] = (string) $afterPost['publish_date'];
    $form['publish_time'] = (string) ($afterPost['publish_time'] ?? '');
}
if ($form['publish_date'] === '') {
    $form['publish_date'] = (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
    $form['publish_time'] = (new DateTimeImmutable('+1 hour', new DateTimeZone('Europe/Warsaw')))->format('H:00');
}

// Dane z Allegro do list wyboru.
$rates = alSessionList('rates', static function () use ($client) {
    $r = $client->shippingRates();
    return ['ok' => $r !== [], 'items' => $r];
});
$returnPolicies = alSessionList('return', static fn() => $client->afterSalesConditions('return-policies'));
$impliedWarranties = alSessionList('implied', static fn() => $client->afterSalesConditions('implied-warranties'));
$warranties = alSessionList('warranty', static fn() => $client->afterSalesConditions('warranties'));
$producers = alSessionList('producers', static function () use ($client) {
    $r = $client->responsibleProducers();
    return ['ok' => $r['ok'], 'items' => $r['producers'], 'message' => $r['message']];
});
$persons = alSessionList('persons', static function () use ($client) {
    $r = $client->responsiblePersons();
    return ['ok' => $r['ok'], 'items' => $r['persons'], 'message' => $r['message']];
});

$additionalServices = alSessionList('services', static fn() => $client->additionalServiceGroups());
$wholesaleLists = alSessionList('wholesale', static fn() => $client->wholesalePriceLists());
$promoPackages = alSessionList('promo', static fn() => $client->promotionPackages());
$priceRules = alSessionList('pricerules', static fn() => $client->priceAutomationRules());

$catInfo = $form['category_id'] !== '' ? alCategoryPath($client, (string) $form['category_id']) : null;
$catParams = $catInfo !== null && $catInfo['leaf'] ? AllegroOfferPayload::withTariffCode($client->categoryParameters((string) $form['category_id'])) : [];
$taxOptions = AllegroOfferPayload::taxOptions($catInfo !== null && $catInfo['leaf'] ? $client->taxSettings((string) $form['category_id']) : []);
$allTemplates = $fieldTemplates->all();
// Zdjęcia do wyboru: galeria produktu + te, które już są w ofercie (np. dodane z adresu).
$galleryPool = array_values(array_unique(array_merge($form['images'], $images)));

$e = static fn($v) => htmlspecialchars((string) $v);
$PAGE_TITLE = 'Oferta Allegro: ' . ($p['name'] ?: $p['sku']);
$PAGE_KEY   = 'products';
require __DIR__ . '/header.php';
/** Lista wyboru z danymi z Allegro (id => etykieta), z informacją, gdy lista jest pusta. */
$pick = static function (string $name, array $list, string $selected, callable $label, bool $optional, string $manage, string $manageLabel) use ($e): string {
    // Wymagane pole z jedną pozycją na koncie - od razu wybrana.
    if (!$optional && $selected === '' && count($list['items']) === 1) {
        $selected = (string) ($list['items'][0]['id'] ?? '');
    }
    $h = '<select name="' . $name . '"><option value="">' . ($optional ? '— brak —' : '— wybierz —') . '</option>';
    foreach ($list['items'] as $it) {
        $id = (string) ($it['id'] ?? '');
        $h .= '<option value="' . $e($id) . '"' . ($id === $selected ? ' selected' : '') . '>' . $e($label($it)) . '</option>';
    }
    $h .= '</select>';
    if ($list['items'] === []) {
        $h .= '<p class="hint">' . ($list['ok'] ? 'Brak pozycji na koncie Allegro. ' : 'Nie udało się pobrać z Allegro' . ($list['message'] !== '' ? ': ' . $e($list['message']) : '') . '. ') . '</p>';
    }
    if ($manage !== '') {
        $h .= '<p class="hint"><a href="' . $manage . '"' . (str_starts_with($manage, 'http') ? ' target="_blank" rel="noopener"' : '') . '>' . $manageLabel . '</a></p>';
    }
    return $h;
};
$byName = static fn($it) => (string) ($it['name'] ?? $it['id'] ?? '');
$req = '<span class="req">*</span>';
$opt = '<span class="opt">(opcjonalnie)</span>';
$sections = [
    's-title' => 'Tytuł', 's-category' => 'Kategoria', 's-product' => 'Produkty w ofercie', 's-photos' => 'Zdjęcia',
    's-desc' => 'Opis', 's-price' => 'Cena i ilość', 's-delivery' => 'Dostawa', 's-after' => 'Zwroty i reklamacje',
    's-invoice' => 'Faktura VAT', 's-gpsr' => 'Bezpieczeństwo (GPSR)', 's-notes' => 'Uwagi do zakupu', 's-attach' => 'Załączniki',
    's-promo' => 'Wyróżnienie i reklama', 's-publish' => 'Opcje wystawienia', 's-summary' => 'Podsumowanie',
];
?>

<p style="margin:-6px 0 12px"><a class="btn secondary" href="product_view.php?id=<?= (int)$productId ?>">← Powrót do produktu</a></p>
<?php if ($flashOk): ?><div class="flash ok"><?= $e($flashOk) ?></div><?php endif; ?>
<?php if (!empty($afterPost['ads'])): ?><div class="flash ok">📣 Reklama: wybierz tę ofertę w kampanii Allegro Ads. Koszty i budżet śledzisz w <a href="allegro_ads.php">panelu Allegro Ads w CRM</a>, a kampanię włączasz w <a href="https://ads.allegro.pl" target="_blank" rel="noopener">Allegro Ads na allegro.pl ↗</a>.</div><?php endif; ?>
<?php if ($flashErr): ?><div class="flash err"><?= $e($flashErr) ?><?php if ($flashErrors): ?><ul style="margin:6px 0 0 18px"><?php foreach ($flashErrors as $fe): ?><li><?= $e($fe) ?></li><?php endforeach; ?></ul><?php endif; ?></div><?php endif; ?>
<?php if ($publishedOfferId && !$flashOk): ?><div class="flash ok">✓ Oferta wystawiona — ID Allegro: <strong><?= $e($publishedOfferId) ?></strong>. <a href="allegro_offers.php">Zarządzaj ofertami →</a></div><?php endif; ?>

<form method="post" id="offerForm" class="ao">
<input type="hidden" name="csrf" value="<?= csrfToken() ?>">
<input type="hidden" name="description_sections" id="f_desc_json" value="">

<div class="ao-main">

    <!-- Szablony pól: wybór, zapis, domyślny -->
    <section class="ao-card ao-tpl">
        <div class="ao-tpl-row">
            <strong>📋 Szablon pól</strong>
            <select id="tplPick">
                <option value="">— wybierz szablon —</option>
                <?php foreach ($allTemplates as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= $appliedTemplate !== null && $appliedTemplate['id'] === $t['id'] ? 'selected' : '' ?>><?= $e($t['name']) ?><?= $t['is_default'] ? ' (domyślny)' : '' ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn secondary" id="tplApply">Zastosuj</button>
            <button type="button" class="btn secondary" id="tplSaveToggle">💾 Zapisz jako szablon…</button>
        </div>
        <p class="hint">Szablon wypełnia dostawę, warunki zwrotów i reklamacji, fakturę i VAT, producenta, informacje o bezpieczeństwie i opcje wystawienia
            (opcjonalnie także kategorię z parametrami). Tytuł, cena, ilość, EAN i zdjęcia zawsze idą z produktu.</p>
        <div id="tplSave" class="ao-tpl-save" hidden>
            <div class="og">
                <div><label class="ol">Nazwa szablonu</label><input name="template_name" placeholder="np. Świece — standard"></div>
                <div><label class="ol">albo nadpisz istniejący</label>
                    <select name="template_id"><option value="0">— nowy szablon —</option>
                        <?php foreach ($allTemplates as $t): ?><option value="<?= $t['id'] ?>"><?= $e($t['name']) ?></option><?php endforeach; ?>
                    </select></div>
            </div>
            <label class="inl"><input type="checkbox" name="template_category" value="1"> z kategorią i parametrami (bez EAN)</label>
            <label class="inl"><input type="checkbox" name="template_default" value="1"> domyślny — wypełnia każdą nową ofertę</label>
            <p style="display:flex;gap:8px;margin:8px 0 0">
                <button class="btn" type="submit" name="action" value="save_template">Zapisz szablon</button>
                <?php if ($allTemplates !== []): ?>
                    <button class="btn secondary" type="submit" name="action" value="delete_template" id="tplDelete"
                            onclick="return confirm('Usunąć szablon wybrany w polu „nadpisz istniejący”?')">Usuń wybrany</button>
                <?php endif; ?>
            </p>
        </div>
    </section>

    <section class="ao-card" id="s-title">
        <h2>Tytuł</h2>
        <label class="ol">Tytuł <?= $req ?></label>
        <input name="title" id="f_title" maxlength="75" value="<?= $e($form['title']) ?>">
        <p class="hint ao-split"><span id="titleWords"></span><span id="titleLen"></span></p>
        <label class="ol">Sygnatura <?= $opt ?></label>
        <input name="signature" maxlength="100" value="<?= $e($form['signature']) ?>">
        <p class="hint">Twój wewnętrzny identyfikator produktu, widoczny tylko dla Ciebie (domyślnie SKU z magazynu CRM).</p>
    </section>

    <section class="ao-card" id="s-category">
        <h2>Kategoria</h2>
        <input type="hidden" name="category_id" id="f_cat" value="<?= $e($form['category_id']) ?>">
        <div id="catPath" class="cat-path" data-path="<?= $e($catInfo !== null ? implode(' › ', array_column($catInfo['path'], 'name')) : '') ?>">
            <?php if ($catInfo !== null): ?>
                <div class="cat-chosen">
                    <div><span class="cat-ok">✓ Wybrana kategoria</span> <strong><?= $e(end($catInfo['path'])['name'] ?? '') ?></strong>
                        <span class="hint">nr <?= $e($form['category_id']) ?></span><br>
                        <?= $e(implode(' › ', array_column($catInfo['path'], 'name'))) ?>
                        <?php if (!$catInfo['leaf']): ?><span class="bad">— wybierz podkategorię</span><?php endif; ?></div>
                    <button type="button" class="cat-x" title="Usuń wybraną kategorię">✕ Usuń</button>
                </div>
            <?php elseif ($form['category_id'] !== ''): ?>
                <span class="bad">Nie udało się pobrać kategorii <?= $e($form['category_id']) ?> z Allegro.</span>
            <?php else: ?>
                <span class="bad">Nie wybrano kategorii</span>
            <?php endif; ?>
        </div>
        <div class="ao-row">
            <input id="catQuery" placeholder="Szukaj kategorii po nazwie produktu" value="<?= $e($form['title']) ?>">
            <button type="button" class="btn secondary" id="catSearch">🔎 Dopasuj</button>
            <button type="button" class="btn secondary" id="catBrowse">📂 Przeglądaj drzewo</button>
        </div>
        <?php if (!empty($categories)): ?><p class="hint">Kategoria w magazynie: <?= $e(implode(', ', array_map('strval', $categories))) ?></p><?php endif; ?>
        <div id="catResults"></div>
        <div id="catTree" hidden></div>
    </section>

    <section class="ao-card" id="s-product">
        <h2>Produkty w ofercie</h2>
        <div class="set-mode">
            <label class="set-tile"><input type="radio" name="set_mode" value="single" <?= $form['set_mode'] !== 'set' ? 'checked' : '' ?>>
                <strong>Pojedyncza sztuka</strong><span>Sprzedajesz tylko jedną sztukę produktu</span></label>
            <label class="set-tile"><input type="radio" name="set_mode" value="set" <?= $form['set_mode'] === 'set' ? 'checked' : '' ?>>
                <strong>Wiele sztuk jako zestaw</strong><span>Wystawiasz wiele sztuk produktu w jednej ofercie</span></label>
        </div>
        <div id="setQtyBox" class="set-qty" <?= $form['set_mode'] === 'set' ? '' : 'hidden' ?>>
            <label class="ol">Liczba sztuk produktu w zestawie <span class="req">*</span></label>
            <div class="unit-wrap" style="max-width:220px"><input type="number" name="set_quantity" id="f_set_qty" min="2" max="<?= AllegroOfferPayload::MAX_SET_QUANTITY ?>" value="<?= $e($form['set_quantity']) ?>"><span class="unit">szt.</span></div>
            <p class="hint">Kupujący za cenę oferty dostaje tyle sztuk produktu. „Liczba sztuk” w sekcji Cena to liczba takich zestawów.</p>
        </div>
        <div class="catalog-note">W wielu kategoriach Allegro wymaga, żeby oferta była połączona z Katalogiem produktów:
            wybierz produkt z Katalogu albo wypełnij parametry produktu, a Allegro utworzy go razem z ofertą.</div>
        <input type="hidden" name="catalog_product_id" id="f_catalog" value="<?= $e($form['catalog_product_id']) ?>">
        <input type="hidden" name="catalog_product_name" id="f_catalog_name" value="<?= $e($form['catalog_product_name']) ?>">
        <div id="catalogChosen" class="cat-path" <?= $form['catalog_product_id'] === '' ? 'hidden' : '' ?>>
            ✓ Produkt z Katalogu: <strong id="catalogName"><?= $e($form['catalog_product_name'] ?: $form['catalog_product_id']) ?></strong>
            <button type="button" class="btn secondary" id="catalogClear" style="margin-left:8px">Odłącz (nowy produkt)</button>
        </div>
        <div class="ao-row">
            <input id="catalogQuery" placeholder="EAN albo nazwa produktu" value="<?= $e($form['ean'] !== '' ? $form['ean'] : $form['title']) ?>">
            <button type="button" class="btn secondary" id="catalogSearch">🔎 Wybierz produkt z Katalogu</button>
        </div>
        <div id="catalogResults"></div>
        <div id="catParams">
            <?php if ($catInfo !== null && $catInfo['leaf']): ?>
                <?= $catParams !== [] ? renderOfferParams($catParams, $form['params'], $attributes, (string) $form['ean'], (array) $form['params_custom'])
                    : '<p class="bad">Nie udało się pobrać parametrów kategorii.</p>' ?>
            <?php else: ?>
                <p class="hint">Parametry pojawią się po wybraniu kategorii.</p>
            <?php endif; ?>
        </div>
        <h4 class="psub">Kolejne produkty w ofercie <?= $opt ?></h4>
        <p class="hint" style="margin-top:-4px">Kilka różnych produktów sprzedawanych razem (np. świeca + podstawka). Kolejne produkty wybierasz z Katalogu Allegro; dostają tego samego producenta i informacje o bezpieczeństwie co pierwszy.</p>
        <div id="xpList" class="att-list"></div>
        <div id="xpInputs"></div>
        <div class="ao-row">
            <input id="xpQuery" placeholder="EAN albo nazwa kolejnego produktu">
            <button type="button" class="btn secondary" id="xpSearch">➕ Dodaj kolejny produkt</button>
        </div>
        <div id="xpResults"></div>
    </section>

    <section class="ao-card" id="s-photos">
        <h2>Zdjęcia <?= $req ?></h2>
        <p class="hint" style="margin-top:-6px">Pierwsze zdjęcie jest główne. Kolejność zmienisz strzałkami. Maks. <?= AllegroOfferPayload::MAX_IMAGES ?> zdjęć (razem ze zdjęciami z opisu).</p>
        <label class="inline" style="display:flex;gap:8px;align-items:flex-start;margin:0 0 8px"><input type="checkbox" name="main_photo_2560" value="1"<?= $form['main_photo_2560'] ? ' checked' : '' ?> id="mainPhoto2560">
            <span><strong>Zdjęcie główne w 2560×2560 px</strong> (zalecenie Allegro)<br><span class="hint">Przy wystawianiu i aktualizacji CRM dopasuje pierwsze zdjęcie: dopełni białym tłem do kwadratu, bez przycinania, i wyśle je na Allegro.</span></span></label>
        <p class="hint" id="mainPhotoInfo" style="margin:0 0 8px"></p>
        <div id="photoList" class="ph-list"></div>
        <div id="photoInputs"></div>
        <div id="photoPoolBox">
            <h4 class="psub">Dodaj z galerii produktu</h4>
            <div id="photoPool" class="ph-pool"></div>
        </div>
        <div class="ao-row">
            <input id="photoUrl" placeholder="https://… adres zdjęcia">
            <button type="button" class="btn secondary" id="photoAdd">+ Dodaj z adresu</button>
        </div>
    </section>

    <section class="ao-card" id="s-desc">
        <h2>Opis <?= $req ?></h2>
        <?php if ($descFromTemplate): ?><p class="ok-note">✓ Wypełniono z szablonu opisu (wg kategorii). <a href="allegro_templates.php">Edytuj szablony opisu</a></p><?php endif; ?>
        <p class="hint" style="margin-top:-6px">Opis składa się z sekcji, jak na Allegro: tekst, zdjęcie albo zdjęcie z tekstem obok. W tekście: nagłówki, akapity, pogrubienie i listy.</p>
        <div class="live-box" id="liveBox">
            <strong>Trwająca oferta:</strong>
            <select id="liveOffer"><option value="">szukam ofert tego produktu…</option></select>
            <button type="button" class="btn secondary" id="liveLoad">Wczytaj jej obecny opis</button>
            <button type="button" class="btn secondary" id="livePush">⇪ Wyślij ten opis do oferty</button>
            <span class="hint" id="liveMsg">Zmienia tylko opis (i dopisuje zdjęcia opisu do galerii) w ofercie, która już trwa — reszta oferty zostaje.</span>
        </div>
        <div id="descEditor" class="de"></div>
        <div class="de-add">
            <span class="hint">Dodaj sekcję:</span>
            <button type="button" class="btn secondary" data-add="T">¶ Tekst</button>
            <button type="button" class="btn secondary" data-add="I">🖼 Zdjęcie</button>
            <button type="button" class="btn secondary" data-add="IT">🖼 ¶ Zdjęcie + tekst</button>
            <button type="button" class="btn secondary" data-add="TI">¶ 🖼 Tekst + zdjęcie</button>
            <button type="button" class="btn secondary" data-add="II">🖼 🖼 Dwa zdjęcia</button>
        </div>
        <?php if ($descTemplates !== []): ?>
            <div class="ao-row">
                <select id="descTplPick"><option value="">— wstaw szablon opisu —</option>
                    <?php foreach ($descTemplates as $i => $t): ?><option value="<?= $i ?>"><?= $e($t['name']) ?></option><?php endforeach; ?>
                </select>
                <button type="button" class="btn secondary" id="descTplApply">Zastąp opis szablonem</button>
            </div>
        <?php endif; ?>
    </section>

    <section class="ao-card" id="s-price">
        <h2>Cena i ilość</h2>
        <?php $auction = $form['selling_format'] === 'AUCTION'; ?>
        <div class="og">
            <div><label class="ol" id="priceLabel"><?= $auction ? 'Cena wywoławcza' : 'Cena „Kup teraz”' ?> <?= $req ?></label>
                <div class="unit-wrap"><input name="price" id="f_price" type="number" step="0.01" min="0" value="<?= $e($form['price']) ?>"><span class="unit">zł</span></div>
                <label class="inl" style="align-items:flex-start;margin-top:8px"><input type="checkbox" name="selling_format" value="AUCTION" id="f_auction" <?= $auction ? 'checked' : '' ?>>
                    <span><strong>licytacja</strong><br><span class="hint">Sprzedaj przedmiot temu, kto zaoferuje więcej.</span></span></label></div>
            <div id="minPriceBox" <?= $auction ? '' : 'hidden' ?>><label class="ol">Cena minimalna <?= $opt ?></label>
                <div class="unit-wrap"><input name="minimal_price" type="number" step="0.01" min="0" value="<?= $e($form['minimal_price']) ?>"><span class="unit">zł</span></div>
                <p class="hint">Poniżej tej ceny nie musisz sprzedać przedmiotu.</p></div>
        </div>
        <div class="og" style="margin-top:12px">
            <div><label class="ol">Liczba sztuk <?= $req ?></label>
                <div class="ao-row" style="margin-top:0;flex-wrap:nowrap"><input name="stock" id="f_stock" type="number" min="0" value="<?= $e($form['stock']) ?>">
                <select name="stock_unit" id="f_unit" style="max-width:140px">
                    <?php foreach (AllegroOfferPayload::STOCK_UNITS as $uk => $ul): ?><option value="<?= $uk ?>" <?= $uk === $form['stock_unit'] ? 'selected' : '' ?>><?= $ul ?></option><?php endforeach; ?>
                </select></div></div>
        </div>
        <h4 class="psub">Rynki dodatkowe <?= $opt ?></h4>
        <p class="hint" style="margin-top:-4px" id="marketsHint">Cena na zagranicznych serwisach Allegro. Puste pole — Allegro przeliczy cenę po aktualnym kursie (jeśli masz włączony ten rynek w cenniku dostawy).</p>
        <div class="og3" id="marketsBox" <?= $auction ? 'hidden' : '' ?>>
            <?php foreach (AllegroOfferPayload::MARKETS as $mk => [$mName, $mCur]): ?>
                <div><label class="ol"><?= $e($mName) ?> <span class="hint">(<?= $e($mk) ?>)</span></label>
                    <div class="unit-wrap"><input name="markets[<?= $mk ?>]" type="number" step="0.01" min="0" value="<?= $e($form['markets'][$mk] ?? '') ?>" placeholder="aktualny kurs"><span class="unit"><?= $mCur ?></span></div></div>
            <?php endforeach; ?>
        </div>
        <h4 class="psub">Automatyczne ceny <?= $opt ?></h4>
        <div class="og3">
            <div><label class="ol">Reguła cenowa</label>
                <?= $pick('price_rule', $priceRules, (string) $form['price_rule'], static fn($it) => (string) ($it['name'] ?? $it['type'] ?? $it['id'] ?? ''), true, 'https://help.allegro.com/sell/pl/c/automatyczne-zarzadzanie-cenami-oferty', 'Jak działają reguły cenowe →') ?></div>
            <div><label class="ol">Cena minimalna</label>
                <div class="unit-wrap"><input name="price_rule_min" type="number" step="0.01" min="0" value="<?= $e($form['price_rule_min']) ?>"><span class="unit">zł</span></div></div>
            <div><label class="ol">Cena maksymalna</label>
                <div class="unit-wrap"><input name="price_rule_max" type="number" step="0.01" min="0" value="<?= $e($form['price_rule_max']) ?>"><span class="unit">zł</span></div></div>
        </div>
        <p class="hint">Allegro samo zmienia cenę według reguły, ale nie poza tym zakresem. Regułę podpinamy zaraz po wystawieniu oferty.</p>
        <h4 class="psub">Wznawianie oferty</h4>
        <label class="inl" style="align-items:flex-start"><input type="checkbox" name="republish" value="1" <?= $form['republish'] ? 'checked' : '' ?>>
            <span>po zakończeniu wystaw ponownie ofertę <strong>z pełną liczbą sztuk</strong><br><span class="hint">Ofertę wznowimy ze stałą liczbą sztuk niezależnie od tego, ile przedmiotów sprzedasz.</span></span></label>
        <h4 class="psub">Czas trwania</h4>
        <select name="duration" id="f_duration" style="max-width:320px" data-value="<?= $e($form['duration']) ?>">
            <?php foreach ($auction ? AllegroOfferPayload::DURATIONS_AUCTION : AllegroOfferPayload::DURATIONS_BUY_NOW as $dk => $dl): ?>
                <option value="<?= $dk ?>" <?= $dk === $form['duration'] ? 'selected' : '' ?>><?= $dk === '' ? $e($dl) : 'na określony czas: ' . $e($dl) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="hint" id="durationHint"><?= $auction ? 'Licytacja zawsze trwa określony czas.' : '„Do wyczerpania przedmiotów” — oferta jest dostępna, dopóki nie sprzedasz wszystkich sztuk.' ?></p>
    </section>

    <section class="ao-card" id="s-delivery">
        <h2>Dostawa</h2>
        <div class="og">
            <div><label class="ol">Cennik dostawy <?= $req ?></label>
                <?= $pick('shipping_rate', $rates, (string) $form['shipping_rate'], $byName, false, 'allegro_shipping.php', 'Cenniki wysyłek →') ?></div>
            <div><label class="ol">Czas wysyłki <?= $req ?></label>
                <select name="handling">
                    <?php foreach (\Pase\Services\AllegroOfferOperations::HANDLING_TIMES as $hk => $hl): ?>
                        <option value="<?= $hk ?>" <?= $hk === $form['handling'] ? 'selected' : '' ?>><?= $e($hl) ?></option>
                    <?php endforeach; ?>
                </select></div>
        </div>
        <label class="inl" style="margin-top:10px"><input type="checkbox" name="preorder" value="1" id="f_preorder" <?= $form['preorder'] ? 'checked' : '' ?>> przedmiot jest w przedsprzedaży</label>
        <div id="preorderBox" class="og" <?= $form['preorder'] ? '' : 'hidden' ?>>
            <div><label class="ol">Data wysyłki <?= $req ?></label><input type="date" name="shipment_date" value="<?= $e($form['shipment_date']) ?>">
                <p class="hint">Kupujący zobaczy, że wyślesz przedmiot od tego dnia.</p></div>
        </div>
        <h4 class="psub">Dodatkowe informacje o dostawie <?= $opt ?></h4>
        <textarea name="delivery_info" rows="2" maxlength="<?= AllegroOfferPayload::DELIVERY_INFO_MAX ?>" placeholder="Np. „Wysyłamy w ekologicznym opakowaniu, odbiór osobisty po wcześniejszym kontakcie.”"><?= $e($form['delivery_info']) ?></textarea>
        <h4 class="psub">Lokalizacja <?= $opt ?></h4>
        <div class="og3">
            <div><label class="ol">Województwo</label>
                <select name="province"><option value="">— z konta Allegro —</option>
                    <?php foreach (AllegroOfferPayload::PROVINCES as $pk => $pl): ?><option value="<?= $pk ?>" <?= $pk === $form['province'] ? 'selected' : '' ?>><?= $e($pl) ?></option><?php endforeach; ?>
                </select></div>
            <div><label class="ol">Miejscowość</label><input name="city" value="<?= $e($form['city']) ?>"></div>
            <div><label class="ol">Kod pocztowy</label><input name="post_code" placeholder="00-000" value="<?= $e($form['post_code']) ?>"></div>
        </div>
    </section>

    <section class="ao-card" id="s-after">
        <h2>Zwroty, reklamacje, gwarancja</h2>
        <div class="og3">
            <div><label class="ol">Warunki zwrotów <?= $req ?></label>
                <?= $pick('return_policy', $returnPolicies, (string) $form['return_policy'], $byName, false, 'https://allegro.pl/moje-allegro/sprzedaz/ustawienia-sprzedazy/warunki-oferty', 'Warunki na Allegro →') ?></div>
            <div><label class="ol">Warunki reklamacji <?= $req ?></label>
                <?= $pick('implied_warranty', $impliedWarranties, (string) $form['implied_warranty'], $byName, false, '', '') ?></div>
            <div><label class="ol">Gwarancja <?= $opt ?></label>
                <?= $pick('warranty', $warranties, (string) $form['warranty'], $byName, true, '', '') ?></div>
        </div>
        <div class="og" style="margin-top:12px">
            <div><label class="ol">Usługi dodatkowe <?= $opt ?></label>
                <?= $pick('additional_services', $additionalServices, (string) $form['additional_services'], $byName, true, 'https://help.allegro.com/sell/pl/a/wniesienie-montaz-i-inne-uslugi-dodatkowe-w-ofertach-K6Vz8D3OOf1', 'Jak dodać usługi dodatkowe →') ?>
                <p class="hint">Np. wniesienie, montaż, grawer — grupy usług tworzysz na Allegro.</p></div>
        </div>
    </section>

    <section class="ao-card" id="s-invoice">
        <h2>Faktura VAT</h2>
        <div class="og">
            <div><label class="ol">Opcje faktury <?= $req ?></label>
                <select name="invoice" id="f_invoice">
                    <?php foreach (AllegroOfferPayload::INVOICES as $ik => $il): ?><option value="<?= $ik ?>" <?= $ik === $form['invoice'] ? 'selected' : '' ?>><?= $e($il) ?></option><?php endforeach; ?>
                </select></div>
        </div>
        <div id="taxBox"><?= renderTaxFields($taxOptions, $form) ?></div>
        <h4 class="psub">Rabat dla klientów biznesowych <?= $opt ?></h4>
        <div class="og"><div><label class="ol">Cennik hurtowy</label>
            <?= $pick('wholesale_price_list', $wholesaleLists, (string) $form['wholesale_price_list'], $byName, true, 'https://help.allegro.com/sell/pl/a/rabat-hurtowy-jak-go-utworzyc-i-dodac-do-oferty-eKWorAMxRil', 'Jak utworzyć rabat hurtowy →') ?>
            <p class="hint">Rabaty hurtowe są widoczne tylko dla kupujących na Allegro Business.</p></div></div>
    </section>

    <section class="ao-card" id="s-gpsr">
        <h2>Bezpieczeństwo produktu (GPSR)</h2>
        <div class="og">
            <div><label class="ol">Producent <?= $req ?></label>
                <?= $pick('producer', $producers, (string) $form['producer'], static fn($it) => ($it['name'] ?? '') . ' — ' . ($it['producerData']['tradeName'] ?? ''), false, 'allegro_producers.php', 'Producenci →') ?></div>
            <div><label class="ol">Osoba odpowiedzialna w UE <?= $opt ?></label>
                <?= $pick('person', $persons, (string) $form['person'], static fn($it) => ($it['name'] ?? '') . ' — ' . ($it['personalData']['name'] ?? ''), true, 'allegro_persons.php', 'Osoby odpowiedzialne →') ?>
                <p class="hint">Wymagana, gdy producent jest spoza UE.</p></div>
        </div>
        <label class="ol" style="margin-top:14px">Informacje o bezpieczeństwie <?= $req ?></label>
        <label class="inl"><input type="radio" name="safety_mode" value="text" <?= $form['safety_mode'] !== 'none' ? 'checked' : '' ?>> Opis tekstowy</label>
        <label class="inl"><input type="radio" name="safety_mode" value="none" <?= $form['safety_mode'] === 'none' ? 'checked' : '' ?>> Produkt nie ma informacji o bezpieczeństwie</label>
        <textarea name="safety_text" id="f_safety" rows="4" maxlength="<?= \Pase\Services\AllegroOfferOperations::SAFETY_TEXT_MAX ?>" placeholder="Ostrzeżenia, np. „Nie zostawiaj palącej się świecy bez nadzoru.” (bez HTML)"><?= $e($form['safety_text']) ?></textarea>
    </section>

    <section class="ao-card" id="s-notes">
        <h2>Uwagi do zakupu</h2>
        <p class="hint" style="margin-top:-6px">Jeśli potrzebujesz dodatkowych informacji od kupującego (np. dedykacja, wybór zapachu), zostaw pole „uwagi do zakupu” — kupujący zobaczy je przy finalizacji zamówienia.</p>
        <label class="inl"><input type="radio" name="message_mode" value="OPTIONAL" <?= $form['message_mode'] !== 'HIDDEN' ? 'checked' : '' ?>> Na wszelki wypadek zostawiam możliwość dodania uwag — <strong>opcjonalne pole „uwagi do zakupu”</strong></label>
        <label class="inl"><input type="radio" name="message_mode" value="HIDDEN" <?= $form['message_mode'] === 'HIDDEN' ? 'checked' : '' ?>> Nie potrzebuję żadnych informacji — <strong>brak pola „uwagi do zakupu”</strong></label>
    </section>

    <section class="ao-card" id="s-attach">
        <h2>Załączniki <?= $opt ?></h2>
        <p class="hint" style="margin-top:-6px">Pliki pokazywane pod opisem oferty, np. instrukcja obsługi albo karta produktu. PDF (etykieta energetyczna: JPG lub PNG).</p>
        <div id="attList" class="att-list"></div>
        <div id="attInputs"></div>
        <div class="ao-row">
            <select id="attType" style="max-width:280px">
                <?php foreach (AllegroOfferPayload::ATTACHMENT_TYPES as $ak => $al): ?><option value="<?= $ak ?>"><?= $e($al) ?></option><?php endforeach; ?>
            </select>
            <input type="file" id="attFile" accept=".pdf,.jpg,.jpeg,.png" style="flex:1;min-width:200px">
            <button type="button" class="btn secondary" id="attAdd">📎 Dodaj załącznik</button>
        </div>
        <p id="attMsg" class="hint"></p>
    </section>

    <section class="ao-card" id="s-promo">
        <h2>Wyróżnij swoją ofertę <?= $opt ?></h2>
        <p class="hint" style="margin-top:-6px">Płatne opcje. Włączamy je zaraz po wystawieniu oferty, a Allegro odnawia je automatycznie po każdym okresie rozliczeniowym. Ceny zobaczysz w cenniku Allegro.</p>
        <div class="og"><div><label class="ol">Plan wyróżnienia</label>
            <?= $pick('promo_package', $promoPackages, (string) $form['promo_package'], $byName, true, 'https://help.allegro.com/pl/sell/a/czym-sa-wyroznienia-czyli-opcje-promowania-nnkeyKZLlUY', 'Zasady wyróżnień →') ?></div></div>
        <label class="inl" style="align-items:flex-start;margin-top:10px"><input type="checkbox" name="promo_department" value="1" <?= $form['promo_department'] ? 'checked' : '' ?>>
            <span><strong>Promowanie na stronie kategorii</strong> (allegro.pl)<br><span class="hint">Oferta w galerii promowanych ofert na stronie działu, w którym ją wystawiasz.</span></span></label>
        <h4 class="psub">Reklama Allegro Ads</h4>
        <label class="inl" style="align-items:flex-start"><input type="checkbox" name="ads" value="1" <?= $form['ads'] ? 'checked' : '' ?>>
            <span><strong>włącz reklamę oferty</strong> na Allegro i w Google Ads<br><span class="hint">Allegro nie udostępnia publicznego API Allegro Ads, więc samą kampanię włączasz na allegro.pl — po wystawieniu przypomnimy o tym. Opłata tylko za kliknięcie.</span></span></label>
        <p class="hint"><a href="allegro_ads.php">Panel Allegro Ads w CRM →</a> (koszty, budżet, statystyki)
            · <a href="https://ads.allegro.pl" target="_blank" rel="noopener">Włącz kampanię na allegro.pl ↗</a></p>
    </section>

    <section class="ao-card" id="s-publish">
        <h2>Opcje wystawienia</h2>
        <h4 class="psub">Kto może kupić ofertę</h4>
        <label class="inl" style="align-items:flex-start"><input type="radio" name="buyers" value="all" <?= $form['buyers'] !== 'business' ? 'checked' : '' ?>>
            <span><strong>wszyscy klienci</strong><br><span class="hint">Zarówno użytkownicy zwykli, jak i firmowi mogą zobaczyć ofertę i dokonać zakupu.</span></span></label>
        <label class="inl" style="align-items:flex-start"><input type="radio" name="buyers" value="business" <?= $form['buyers'] === 'business' ? 'checked' : '' ?>>
            <span><strong>tylko klienci biznesowi (Allegro Business)</strong><br><span class="hint">Tylko użytkownicy firmowi mogą kupić, zwykli widzą ofertę, ale nie mogą jej kupić. Wymaga konta firmowego w programie Allegro Business — inaczej Allegro odrzuci ofertę.</span></span></label>
        <h4 class="psub">Oferta pojawi się na Allegro</h4>
        <label class="inl"><input type="radio" name="publish_mode" value="draft" <?= $form['publish_mode'] === 'draft' ? 'checked' : '' ?>> jako nieaktywna — sprawdzę i aktywuję sam</label>
        <label class="inl"><input type="radio" name="publish_mode" value="now" <?= $form['publish_mode'] === 'now' ? 'checked' : '' ?>> natychmiast</label>
        <label class="inl ao-row" style="margin-top:4px"><input type="radio" name="publish_mode" value="scheduled" <?= $form['publish_mode'] === 'scheduled' ? 'checked' : '' ?>>
            <input type="date" name="publish_date" value="<?= $e($form['publish_date']) ?>" style="max-width:180px">
            <input type="time" name="publish_time" value="<?= $e($form['publish_time']) ?>" style="max-width:120px"></label>
        <h4 class="psub">Dodanie produktu</h4>
        <p id="productMode" style="margin:0;font-size:14px"></p>
    </section>

    <section class="ao-card" id="s-summary">
        <h2>Podsumowanie</h2>
        <h4 class="psub" style="margin-top:0">Prowizje i opłaty</h4>
        <div id="feeRows"></div>
        <div class="ao-row"><button type="button" class="btn secondary" id="feeBtn">💰 Sprawdź prowizję</button></div>
        <p class="hint" id="feeMsg">Szacunek Allegro dla obecnej kategorii, ceny i czasu trwania. Pełna kwota opłat będzie widoczna po wystawieniu w Rozliczeniach z Allegro.</p>
    </section>
</div>

<!-- PRAWA: postęp sekcji, podgląd, przyciski -->
<aside class="ao-side">
    <div class="ao-card ao-sticky">
        <ol class="ao-nav" id="aoNav">
            <?php foreach ($sections as $sid => $sl): ?><li data-for="<?= $sid ?>"><a href="#<?= $sid ?>"><span class="dot"></span><?= $e($sl) ?></a></li><?php endforeach; ?>
        </ol>
        <div class="al-card" id="alPreview" title="Otwórz pełny podgląd">
            <div class="al-gallery"><img id="al_img" src="<?= $e($form['images'][0] ?? '') ?>" alt="" onerror="this.style.display='none'"></div>
            <div class="al-body">
                <div class="al-title" id="al_title"><?= $e($form['title']) ?></div>
                <div class="al-cond" id="al_cond"></div>
                <div class="al-price"><span id="al_price"><?= $e(number_format((float) ($form['price'] ?: 0), 2, ',', ' ')) ?></span> zł</div>
                <button class="al-buy" type="button" disabled>KUP TERAZ</button>
            </div>
        </div>
        <p class="ao-actions">
            <button class="btn secondary" type="submit" name="action" value="save">💾 Zapisz</button>
<?php if ($liveOfferId !== ''): ?>
            <?php if ($liveLoaded): ?><input type="hidden" name="live_changed" id="liveChanged" value=""><?php endif; ?>
            <button class="btn" type="submit" name="action" value="update_live" id="liveUpdate" style="background:#ff5a00">⟳ Aktualizuj ofertę na Allegro</button>
<?php else: ?>
            <button class="btn" type="submit" name="action" value="publish" style="background:#ff5a00"
                    onclick="return confirm('Wystawić ofertę do Allegro?')">🚀 Wystaw do Allegro</button>
<?php endif; ?>
        </p>
<?php if ($liveOfferId !== ''): ?>
        <p class="hint">Edytujesz trwającą ofertę <?= $e($liveOfferId) ?><?= $liveLoaded ? ' (dane wczytane z Allegro)' : '' ?>. „Aktualizuj” pokaże listę zmian i dopiero po potwierdzeniu zmieni ofertę — nie tworzy nowej. „Zapisz” zachowuje dane tylko w CRM.</p>
<?php else: ?>
        <p class="hint">„Zapisz” zachowuje ofertę w CRM. Przed wysłaniem CRM sprawdza pola wymagane przez Allegro.</p>
<?php endif; ?>
        <button type="button" class="btn secondary" id="previewBtn" style="width:100%">👁 Podgląd oferty na Allegro</button>
    </div>
</aside>
</form>

<dialog id="lightbox" class="lb"><div class="lb-in">
    <img id="lbImg" alt="">
    <button type="button" class="lb-prev" title="Poprzednie (←)">‹</button>
    <button type="button" class="lb-next" title="Następne (→)">›</button>
    <button type="button" class="lb-close" title="Zamknij (Esc)">✕</button>
    <span class="lb-count" id="lbCount"></span>
</div></dialog>

<!-- Pełny podgląd oferty w układzie strony Allegro; odświeża się na żywo przy zmianach w formularzu. -->
<dialog id="offerPreview" class="pv-dialog">
    <div class="pv-bar">
        <strong>Podgląd oferty na Allegro</strong>
        <span class="hint">Tak kupujący zobaczy ofertę. Edytuj formularz obok, podgląd odświeża się od razu.</span>
        <form method="dialog" style="margin-left:auto"><button class="btn">✕ Zamknij</button></form>
    </div>
    <div class="pv-page" id="pvPage"></div>
</dialog>

<style>
    .ao { display:grid; grid-template-columns:minmax(0,1fr) 320px; gap:18px; align-items:start; }
    .live-box { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:12px; padding:10px 12px; border:1px dashed #d1d5db; border-radius:10px; }
    .live-box select { width:auto !important; min-width:220px; max-width:100%; }
    .live-box .hint { flex-basis:100%; margin:0; } .live-box .hint.ok { color:#15803d; } .live-box .hint.bad { color:#b91c1c; }
    .ao-card { background:#fff; border-radius:12px; padding:22px 26px; margin-bottom:14px; box-shadow:0 1px 3px rgba(0,0,0,.06); scroll-margin-top:12px; }
    .ao-card h2 { font-size:20px; font-weight:600; margin:0 0 16px; color:#222; }
    .ao-card input:not([type=checkbox]):not([type=radio]), .ao-card select, .ao-card textarea { width:100%; box-sizing:border-box; min-height:40px; font-size:14px; }
    .ao-tpl { background:#f6f8ff; border:1px solid #e1e6ff; box-shadow:none; padding:14px 18px; }
    .ao-tpl-row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .ao-tpl-row select { max-width:300px; }
    .ao-tpl-save { margin-top:10px; padding-top:10px; border-top:1px solid #e1e6ff; }
    .fee-t { border-collapse:collapse; width:100%; max-width:520px; font-size:14px; }
    .fee-t td { padding:8px 0; border-bottom:1px solid #eee; }
    .fee-t td:last-child { text-align:right; font-weight:600; white-space:nowrap; }
    .ao-row { display:flex; gap:8px; margin-top:10px; flex-wrap:wrap; align-items:center; }
    .ao-row > input:not([type=radio]) { flex:1; min-width:200px; width:auto !important; }
    .ao-split { display:flex; justify-content:space-between; }
    .ol { font-size:13px; color:#555; display:block; margin:12px 0 5px; font-weight:500; }
    .og { display:grid; grid-template-columns:1fr 1fr; gap:0 16px; }
    .og3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:0 16px; }
    .req { color:#c5221f; }
    .opt { color:#888; font-weight:normal; }
    .hint { color:#777; font-size:12px; margin:4px 0 0; }
    .bad { color:#c5221f; font-size:13px; }
    .ok-note { color:#0a8537; font-size:13px; margin:-6px 0 8px; }
    .inl { display:flex; gap:8px; align-items:center; font-size:14px; margin:6px 0; }
    .cat-path { font-size:14px; padding:12px 14px; background:#f7f8fa; border-radius:8px; line-height:1.6; }
    .cat-chosen { display:flex; gap:12px; align-items:flex-start; justify-content:space-between; }
    .cat-ok { color:#0a8537; font-weight:600; }
    .cat-x { flex:none; border:1px solid #d6d9de; background:#fff; border-radius:8px; padding:6px 12px; cursor:pointer; font-size:13px; color:#c5221f; }
    .cat-x:hover { background:#fdecea; border-color:#f3b3ad; }
    .cat-item { display:flex; justify-content:space-between; gap:10px; align-items:center; width:100%; text-align:left; padding:9px 12px; margin-top:6px; border:1px solid #eef0f3; border-radius:8px; background:#fff; cursor:pointer; font-size:14px; }
    .cat-item:hover { background:#fff4ef; border-color:#ffd0bb; }
    .cat-pick { flex:none; font-size:12px; font-weight:600; color:#ff5a00; }
    #catTree select { margin-top:6px; }
    .att-list { display:flex; flex-direction:column; gap:6px; }
    .att { display:flex; gap:10px; align-items:center; padding:8px 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:14px; }
    .att .t { color:#666; font-size:12px; }
    .att button { margin-left:auto; border:0; background:none; cursor:pointer; color:#c5221f; font-size:13px; }
    .set-mode { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px; }
    .set-tile { position:relative; display:flex; flex-direction:column; gap:4px; padding:14px 16px 14px 44px; border:1px solid #d6d9de; border-radius:10px; cursor:pointer; font-size:14px; }
    .set-tile strong { font-size:16px; font-weight:600; }
    .set-tile span { color:#555; }
    .set-tile input { position:absolute; left:16px; top:18px; }
    .set-tile:has(input:checked) { border:2px solid #222; padding:13px 15px 13px 43px; }
    .set-qty { margin:-4px 0 14px; }
    .catalog-note { font-size:14px; padding:12px 14px; border-left:4px solid #e0b400; background:#fffbea; border-radius:8px; margin-bottom:10px; }
    .psub { font-size:15px; margin:20px 0 6px; color:#333; font-weight:600; }
    .pgrid { display:grid; grid-template-columns:1fr 1fr; gap:0 16px; }
    .prm-wide { grid-column:1 / -1; }
    .unit-wrap { position:relative; display:flex; align-items:center; margin-bottom:4px; flex:1; }
    .unit-wrap .unit { position:absolute; right:12px; color:#666; font-size:13px; pointer-events:none; }
    .unit-wrap input { padding-right:48px; }
    .chk-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(170px,1fr)); gap:8px 14px; font-size:14px; }
    .prm-filter { margin-bottom:4px; min-height:32px !important; font-size:12px !important; }
    .prm-custom { margin-top:4px; }
    .add-val { font-size:12px; padding:5px 12px; margin-top:4px; }
    /* Zdjęcia */
    .ph-list { display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:10px; }
    .ph { position:relative; border:2px solid #e5e7eb; border-radius:10px; overflow:hidden; background:#f7f7f7; }
    .ph:first-child { border-color:#ff5a00; }
    .ph img { width:100%; height:110px; object-fit:contain; display:block; cursor:zoom-in; }
    .ph .zoom { position:absolute; top:4px; right:6px; font-size:12px; background:rgba(255,255,255,.9); border-radius:8px; padding:1px 6px; opacity:0; transition:opacity .15s; pointer-events:none; }
    .ph:hover .zoom { opacity:1; }
    /* Powiększenie zdjęcia (lightbox) */
    .lb { border:0; padding:0; background:transparent; max-width:none; max-height:none; width:calc(100vw / var(--ui-zoom, 1)); height:calc(100vh / var(--ui-zoom, 1)); }
    .lb::backdrop { background:rgba(0,0,0,.92); }
    .lb-in { position:relative; width:100%; height:100%; display:flex; align-items:center; justify-content:center; }
    .lb img { width:calc(88vw / var(--ui-zoom, 1)); height:calc(86vh / var(--ui-zoom, 1)); object-fit:contain; }
    .lb button { position:absolute; border:0; background:rgba(255,255,255,.9); color:#222; border-radius:50%; width:44px; height:44px; font-size:20px; cursor:pointer; }
    .lb .lb-prev { left:20px; top:50%; } .lb .lb-next { right:20px; top:50%; } .lb .lb-close { right:20px; top:20px; }
    .lb .lb-count { position:absolute; bottom:18px; left:50%; transform:translateX(-50%); color:#fff; font-size:14px; }
    .pv-main img { cursor:zoom-in; }
    .ph .no { position:absolute; top:4px; left:6px; font-size:11px; background:rgba(0,0,0,.6); color:#fff; border-radius:8px; padding:1px 7px; }
    .ph .bar { display:flex; justify-content:space-between; background:#fff; border-top:1px solid #eee; }
    .ph .bar button { border:0; background:none; cursor:pointer; padding:4px 8px; font-size:13px; }
    .ph-pool { display:flex; gap:8px; flex-wrap:wrap; }
    .ph-pool img { width:64px; height:64px; object-fit:cover; border-radius:8px; border:2px dashed #ccc; cursor:pointer; }
    .ph-pool img:hover { border-color:#ff5a00; }
    /* Edytor blokowy opisu */
    .de-sec { border:1px solid #e5e7eb; border-radius:10px; margin-bottom:12px; background:#fff; }
    .de-head { display:flex; justify-content:space-between; align-items:center; padding:6px 10px; background:#fafbfc; border-bottom:1px solid #eee; border-radius:10px 10px 0 0; font-size:12px; color:#666; }
    .de-head button { border:0; background:none; cursor:pointer; font-size:14px; padding:2px 6px; }
    .de-body { display:grid; gap:12px; padding:12px; }
    .de-body.two { grid-template-columns:1fr 1fr; }
    .de-tools { display:flex; gap:4px; margin-bottom:6px; flex-wrap:wrap; }
    .de-tools button { border:1px solid #ddd; background:#fff; border-radius:6px; padding:3px 9px; cursor:pointer; font-size:12px; }
    .de-text { min-height:90px; border:1px solid #ddd; border-radius:8px; padding:10px 12px; font-size:14px; line-height:1.6; outline:none; }
    .de-text:focus { border-color:#ff5a00; }
    .de-text h1 { font-size:22px; margin:6px 0; } .de-text h2 { font-size:18px; margin:6px 0; } .de-text p { margin:6px 0; }
    .de-img { border:2px dashed #ccc; border-radius:8px; min-height:140px; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px; padding:8px; }
    .de-img img { max-width:100%; max-height:220px; object-fit:contain; }
    .de-pick { display:flex; gap:6px; flex-wrap:wrap; justify-content:center; }
    .de-pick img { width:48px; height:48px; object-fit:cover; border-radius:6px; cursor:pointer; border:2px solid transparent; }
    .de-pick img:hover { border-color:#ff5a00; }
    .de-add { display:flex; gap:6px; flex-wrap:wrap; align-items:center; margin-top:6px; }
    /* Panel boczny */
    .ao-sticky { position:sticky; top:12px; padding:16px; }
    .ao-nav { list-style:none; margin:0 0 12px; padding:0; counter-reset:n; }
    .ao-nav li a { display:flex; gap:8px; align-items:center; padding:5px 6px; border-radius:6px; text-decoration:none; color:#333; font-size:13px; }
    .ao-nav li a:hover { background:#f5f6f8; }
    .ao-nav .dot { width:10px; height:10px; border-radius:50%; background:#d0d4da; flex:none; }
    .ao-nav li.ok .dot { background:#0a8537; }
    .ao-nav li.todo .dot { background:#e0b400; }
    .ao-actions { display:flex; gap:8px; margin:12px 0 4px; }
    .ao-actions .btn { flex:1; }
    #alPreview { cursor:pointer; }
    /* Pełny podgląd w układzie strony oferty Allegro */
    /* Podgląd otwiera się obok formularza (bez blokowania), więc zmiany w polach widać od razu. */
    .pv-dialog[open] { position:fixed; inset:0 0 0 auto; width:min(1000px,calc(60vw / var(--ui-zoom, 1))); max-width:none; height:calc(100vh / var(--ui-zoom, 1)); max-height:none; margin:0; border:0; padding:0;
                       background:#f3f3f3; box-shadow:-6px 0 24px rgba(0,0,0,.18); overflow:auto; z-index:50; }
    body.pv-on .ao-main { max-width:calc(40vw / var(--ui-zoom, 1) - 40px); }
    .pv-bar { position:sticky; top:0; z-index:2; display:flex; gap:14px; align-items:center; flex-wrap:wrap; padding:10px 18px; background:#fff; border-bottom:1px solid #e5e5e5; }
    .pv-page { padding:16px 20px 30px; color:#222; font-family:"Open Sans",Arial,sans-serif; }
    .pv-status { background:#fff7e0; border:1px solid #f0d58a; border-radius:8px; padding:8px 12px; font-size:13px; margin-bottom:10px; }
    .pv-crumbs { font-size:12px; color:#666; margin:0 0 10px; }
    .pv-top { display:grid; grid-template-columns:minmax(0,1.25fr) minmax(0,1fr); gap:16px; }
    .pv-box { background:#fff; border-radius:6px; padding:18px; box-shadow:0 1px 2px rgba(0,0,0,.08); }
    .pv-main { height:420px; display:flex; align-items:center; justify-content:center; background:#fff; }
    .pv-main img { max-width:100%; max-height:420px; object-fit:contain; }
    .pv-thumbs { display:flex; gap:8px; flex-wrap:wrap; margin-top:12px; }
    .pv-thumbs img { width:64px; height:64px; object-fit:contain; border:2px solid #e5e5e5; border-radius:4px; cursor:pointer; background:#fff; }
    .pv-thumbs img.on { border-color:#ff5a00; }
    .pv-title { font-size:22px; font-weight:600; line-height:1.3; margin:0 0 6px; }
    .pv-meta { font-size:13px; color:#555; }
    .pv-meta b { color:#222; }
    .pv-price { font-size:34px; font-weight:700; margin:14px 0 2px; }
    .pv-price small { font-size:18px; }
    .pv-qty { font-size:13px; color:#555; margin-bottom:12px; }
    .pv-btn { display:block; width:100%; border:0; border-radius:4px; padding:12px; font-weight:700; font-size:14px; margin-top:8px; letter-spacing:.3px; }
    .pv-btn.buy { background:#ff5a00; color:#fff; } .pv-btn.cart { background:#fff; color:#ff5a00; border:1px solid #ff5a00; }
    .pv-rows { margin-top:16px; border-top:1px solid #eee; }
    .pv-row { display:flex; gap:10px; padding:10px 0; border-bottom:1px solid #eee; font-size:13px; }
    .pv-row .k { width:120px; flex:none; color:#666; }
    .pv-row .miss { color:#c5221f; }
    .pv-h { font-size:18px; font-weight:600; margin:0 0 12px; }
    .pv-params { display:grid; grid-template-columns:1fr 1fr; gap:0 30px; font-size:13px; }
    .pv-params div { display:flex; gap:8px; padding:7px 0; border-bottom:1px solid #f0f0f0; }
    .pv-params .k { width:45%; flex:none; color:#666; }
    .pv-desc { font-size:15px; line-height:1.6; }
    .pv-desc .pv-sec { display:grid; gap:20px; margin-bottom:24px; align-items:center; }
    .pv-desc .pv-sec.two { grid-template-columns:1fr 1fr; }
    .pv-desc img { max-width:100%; display:block; margin:0 auto; }
    .pv-desc h1 { font-size:24px; } .pv-desc h2 { font-size:19px; }
    .pv-safety { font-size:13px; white-space:pre-line; }
    @media (max-width:900px) { .pv-dialog[open] { width:100vw; } .pv-top, .pv-params, .pv-desc .pv-sec.two { grid-template-columns:1fr; } .pv-main { height:280px; } }
    /* Mockup karty oferty */
    .al-card { border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; }
    .al-gallery { background:#f7f7f7; height:160px; display:flex; align-items:center; justify-content:center; }
    .al-gallery img { max-width:100%; max-height:160px; object-fit:contain; }
    .al-body { padding:10px 12px; }
    .al-title { font-size:15px; font-weight:600; line-height:1.3; color:#222; }
    .al-cond { font-size:12px; color:#0a8537; margin-top:4px; }
    .al-price { font-size:22px; font-weight:800; color:#222; margin-top:6px; }
    .al-buy { width:100%; margin-top:8px; background:#ff5a00; color:#fff; border:0; border-radius:20px; padding:8px; font-weight:700; opacity:.85; }
    @media (max-width:1100px) { .ao { grid-template-columns:1fr; } .ao-sticky { position:static; } }
    @media (max-width:700px) { .set-mode, .og, .og3, .pgrid, .de-body.two { grid-template-columns:1fr; } .ao-card { padding:16px; } }
</style>
<script>
(function () {
    var form = document.getElementById('offerForm');
    var title = document.getElementById('f_title');
    var price = document.getElementById('f_price');
    var base  = 'offer_allegro.php?product=<?= (int) $productId ?>&ajax=';
    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }

    function fmtPrice(v) {
        var n = parseFloat(v || '0') || 0;
        return n.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }
    // „Stan" to parametr oferty (id 11323) - podgląd pokazuje wybraną wartość.
    function syncCond() {
        var s = document.querySelector('[data-param-id="11323"]');
        document.getElementById('al_cond').textContent = s && s.selectedIndex > 0 ? s.options[s.selectedIndex].text : '';
        updateNav();
    }
    function sync() {
        document.getElementById('al_title').textContent = title.value || '(tytuł oferty)';
        document.getElementById('al_price').textContent = fmtPrice(price.value);
    }
    [title, price].forEach(function (el) { el.addEventListener('input', sync); });
    document.getElementById('catParams').addEventListener('change', syncCond);

    // Przedmiot, zwolnienie i stawki VAT mają sens tylko przy fakturze VAT / VAT-marża.
    var inv = document.getElementById('f_invoice');
    function syncVat() { document.getElementById('taxBox').hidden = inv.value !== 'VAT' && inv.value !== 'VAT_MARGIN'; }
    inv.addEventListener('change', syncVat);
    syncVat();

    // ---- Licytacja / Kup teraz: etykieta ceny, cena minimalna i dostępne czasy trwania ----
    var DURATIONS = <?= json_encode(['BUY_NOW' => AllegroOfferPayload::DURATIONS_BUY_NOW, 'AUCTION' => AllegroOfferPayload::DURATIONS_AUCTION], JSON_UNESCAPED_UNICODE) ?>;
    var auctionBox = document.getElementById('f_auction'), durSel = document.getElementById('f_duration');
    function syncAuction() {
        var auc = auctionBox.checked, cur = durSel.value, list = DURATIONS[auc ? 'AUCTION' : 'BUY_NOW'];
        document.getElementById('priceLabel').firstChild.textContent = auc ? 'Cena wywoławcza ' : 'Cena „Kup teraz” ';
        document.getElementById('minPriceBox').hidden = !auc;
        document.getElementById('marketsBox').hidden = auc;
        document.getElementById('marketsHint').textContent = auc ? 'Rynki dodatkowe z własną ceną działają tylko w ofercie „Kup teraz”.'
            : 'Cena na zagranicznych serwisach Allegro. Puste pole — Allegro przeliczy cenę po aktualnym kursie (jeśli masz włączony ten rynek w cenniku dostawy).';
        durSel.innerHTML = Object.keys(list).map(function (k) {
            return '<option value="' + k + '">' + (k === '' ? esc(list[k]) : 'na określony czas: ' + esc(list[k])) + '</option>';
        }).join('');
        durSel.value = cur in list ? cur : (auc ? 'PT168H' : '');
        document.getElementById('durationHint').textContent = auc ? 'Licytacja zawsze trwa określony czas.'
            : '„Do wyczerpania przedmiotów” — oferta jest dostępna, dopóki nie sprzedasz wszystkich sztuk.';
    }
    auctionBox.addEventListener('change', syncAuction);
    var preBox = document.getElementById('f_preorder');
    preBox.addEventListener('change', function () { document.getElementById('preorderBox').hidden = !preBox.checked; });

    // ---- Podsumowanie: prowizja i opłaty wg Allegro ----
    document.getElementById('feeBtn').addEventListener('click', function () {
        var msg = document.getElementById('feeMsg'), box = document.getElementById('feeRows');
        var fd = new FormData(form);
        fd.delete('action');
        msg.className = 'hint'; msg.textContent = 'Pytam Allegro o opłaty…';
        fetch(base + 'fee', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.ok) { box.innerHTML = ''; msg.className = 'bad'; msg.textContent = d.message || 'Allegro nie podało opłat.'; return; }
            box.innerHTML = d.rows.length ? '<table class="fee-t">' + d.rows.map(function (r) {
                return '<tr><td>' + esc(r.name) + '</td><td>' + esc(r.amount) + '</td></tr>'; }).join('') + '</table>' : '<p class="hint">Allegro nie nalicza opłat dla tej oferty.</p>';
            msg.className = 'hint'; msg.textContent = 'Wycena szacunkowa Allegro dla obecnej konfiguracji oferty. Pełna kwota opłat będzie w Rozliczeniach z Allegro.';
        }).catch(function () { msg.className = 'bad'; msg.textContent = 'Błąd połączenia — spróbuj ponownie.'; });
    });

    // ---- Powiększanie zdjęć: podgląd na cały ekran, strzałki i klawiatura ----
    var lb = document.getElementById('lightbox'), lbList = [], lbAt = 0;
    function lbShow() {
        document.getElementById('lbImg').src = lbList[lbAt] || '';
        document.getElementById('lbCount').textContent = (lbAt + 1) + ' / ' + lbList.length;
        lb.querySelector('.lb-prev').hidden = lb.querySelector('.lb-next').hidden = lbList.length < 2;
    }
    function lbStep(d) { lbAt = (lbAt + d + lbList.length) % lbList.length; lbShow(); }
    function openLightbox(list, i) {
        if (!list.length) return;
        lbList = list.slice(); lbAt = i; lbShow();
        lb.showModal();
    }
    lb.querySelector('.lb-prev').onclick = function () { lbStep(-1); };
    lb.querySelector('.lb-next').onclick = function () { lbStep(1); };
    lb.querySelector('.lb-close').onclick = function () { lb.close(); };
    lb.addEventListener('click', function (ev) { if (ev.target === lb || ev.target.classList.contains('lb-in')) lb.close(); });
    lb.addEventListener('keydown', function (ev) {
        if (ev.key === 'ArrowLeft') lbStep(-1);
        if (ev.key === 'ArrowRight') lbStep(1);
    });

    // ---- Załączniki: plik trafia od razu do Allegro, w formularzu zostaje jego id ----
    var attachments = <?= json_encode($form['attachments'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    var ATT_TYPES = <?= json_encode(AllegroOfferPayload::ATTACHMENT_TYPES, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    function renderAtt() {
        var list = document.getElementById('attList'), inputs = document.getElementById('attInputs');
        list.innerHTML = attachments.length ? '' : '<p class="hint" style="margin:0">Brak załączników.</p>';
        inputs.innerHTML = '';
        attachments.forEach(function (a, i) {
            var row = document.createElement('div');
            row.className = 'att';
            row.innerHTML = '📄 <span>' + esc(a.name || a.id) + '</span><span class="t">' + esc(ATT_TYPES[a.type] || '') + '</span><button type="button">✕ Usuń</button>';
            row.querySelector('button').onclick = function () { attachments.splice(i, 1); renderAtt(); };
            list.appendChild(row);
            ['id', 'type', 'name'].forEach(function (k) {
                var h = document.createElement('input'); h.type = 'hidden'; h.name = 'attachments[' + i + '][' + k + ']'; h.value = a[k] || ''; inputs.appendChild(h);
            });
        });
        renderPreview();
    }
    document.getElementById('attAdd').addEventListener('click', function () {
        var file = document.getElementById('attFile').files[0], msg = document.getElementById('attMsg');
        if (!file) { msg.className = 'bad'; msg.textContent = 'Wybierz plik.'; return; }
        var fd = new FormData();
        fd.append('csrf', form.querySelector('[name=csrf]').value);
        fd.append('type', document.getElementById('attType').value);
        fd.append('file', file);
        msg.className = 'hint'; msg.textContent = 'Wysyłam plik do Allegro…';
        fetch(base + 'attach', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.ok) { msg.className = 'bad'; msg.textContent = d.message || 'Nie udało się dodać załącznika.'; return; }
            attachments.push({ id: d.id, type: d.type, name: d.name });
            document.getElementById('attFile').value = '';
            msg.className = 'hint'; msg.textContent = '✓ Dodano „' + d.name + '”.';
            renderAtt();
        }).catch(function () { msg.className = 'bad'; msg.textContent = 'Błąd połączenia — spróbuj ponownie.'; });
    });

    // ---- Kolejne produkty w ofercie (z Katalogu Allegro) ----
    var extraProducts = <?= json_encode($form['extra_products'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    function renderXp() {
        var list = document.getElementById('xpList'), inputs = document.getElementById('xpInputs');
        list.innerHTML = extraProducts.length ? '' : '<p class="hint" style="margin:0">Tylko jeden produkt w ofercie.</p>';
        inputs.innerHTML = '';
        extraProducts.forEach(function (x, i) {
            var row = document.createElement('div');
            row.className = 'att';
            row.innerHTML = '📦 <span>' + esc(x.name || x.id) + '</span><span class="t">sztuk: <input type="number" min="1" max="<?= AllegroOfferPayload::MAX_SET_QUANTITY ?>" value="' + (x.quantity || 1) + '" style="width:80px;min-height:30px"></span><button type="button">✕ Usuń</button>';
            row.querySelector('button').onclick = function () { extraProducts.splice(i, 1); renderXp(); };
            row.querySelector('input').oninput = function () { x.quantity = parseInt(this.value, 10) || 1; renderXp.sync(); };
            list.appendChild(row);
        });
        renderXp.sync();
        renderPreview();
    }
    renderXp.sync = function () {
        var inputs = document.getElementById('xpInputs');
        inputs.innerHTML = '';
        extraProducts.forEach(function (x, i) {
            ['id', 'name', 'quantity'].forEach(function (k) {
                var h = document.createElement('input'); h.type = 'hidden'; h.name = 'extra_products[' + i + '][' + k + ']'; h.value = x[k] || ''; inputs.appendChild(h);
            });
        });
    };
    document.getElementById('xpSearch').addEventListener('click', function () {
        var q = document.getElementById('xpQuery').value.trim(), out = document.getElementById('xpResults');
        if (!q) { out.innerHTML = '<p class="hint">Wpisz EAN albo nazwę produktu.</p>'; return; }
        out.innerHTML = '<p class="hint">Szukam w Katalogu Allegro…</p>';
        fetch(base + 'products&q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.ok) { out.innerHTML = '<p class="bad">' + esc(d.message || 'Błąd Allegro.') + '</p>'; return; }
            out.innerHTML = d.products.length ? '' : '<p class="hint">Nie ma takiego produktu w Katalogu Allegro.</p>';
            d.products.forEach(function (pr) {
                var b = document.createElement('button');
                b.type = 'button'; b.className = 'cat-item';
                b.innerHTML = (pr.image ? '<img src="' + esc(pr.image) + '" alt="" style="width:36px;height:36px;object-fit:contain;vertical-align:middle;margin-right:8px">' : '') + esc(pr.name) + ' <span class="hint">Dodaj</span>';
                b.addEventListener('click', function () {
                    if (!extraProducts.some(function (x) { return x.id === pr.id; })) extraProducts.push({ id: pr.id, name: pr.name, quantity: 1 });
                    out.innerHTML = ''; document.getElementById('xpQuery').value = '';
                    renderXp();
                });
                out.appendChild(b);
            });
        }).catch(function () { out.innerHTML = '<p class="bad">Błąd połączenia z Allegro.</p>'; });
    });

    // ---- Pojedyncza sztuka / wiele sztuk jako zestaw ----
    function syncSet() {
        var set = (form.querySelector('[name=set_mode]:checked') || {}).value === 'set';
        document.getElementById('setQtyBox').hidden = !set;
    }
    form.querySelectorAll('[name=set_mode]').forEach(function (r) { r.addEventListener('change', syncSet); });

    // ---- Szablony pól ----
    document.getElementById('tplApply').addEventListener('click', function () {
        var id = document.getElementById('tplPick').value;
        if (!id) return;
        if (!confirm('Zastosować szablon? Niezapisane zmiany w polach szablonu zostaną zastąpione.')) return;
        location.href = 'offer_allegro.php?product=<?= (int) $productId ?>&template=' + encodeURIComponent(id);
    });
    document.getElementById('tplSaveToggle').addEventListener('click', function () {
        var box = document.getElementById('tplSave'); box.hidden = !box.hidden;
    });

    // ---- Zdjęcia: kolejność, dodawanie z galerii i z adresu ----
    var photos = <?= json_encode(array_values($form['images']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    var pool = <?= json_encode($galleryPool, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    var MAX = <?= AllegroOfferPayload::MAX_IMAGES ?>;
    function renderPhotos() {
        var list = document.getElementById('photoList'), inputs = document.getElementById('photoInputs'), poolBox = document.getElementById('photoPool');
        list.innerHTML = photos.length ? '' : '<p class="bad">Dodaj co najmniej jedno zdjęcie.</p>';
        inputs.innerHTML = '';
        photos.forEach(function (u, i) {
            var d = document.createElement('div');
            d.className = 'ph';
            d.innerHTML = '<span class="no">' + (i === 0 ? 'główne' : i + 1) + '</span><span class="zoom">🔍</span><img src="' + esc(u) + '" alt="" title="Kliknij, aby powiększyć">'
                + '<div class="bar"><button type="button" data-mv="-1" title="W lewo">←</button><button type="button" data-rm title="Usuń">✕</button><button type="button" data-mv="1" title="W prawo">→</button></div>';
            d.querySelector('[data-rm]').onclick = function () { photos.splice(i, 1); renderPhotos(); };
            d.querySelector('img').onclick = function () { openLightbox(photos, i); };
            d.querySelectorAll('[data-mv]').forEach(function (b) {
                b.onclick = function () { var j = i + (+b.dataset.mv); if (j < 0 || j >= photos.length) return; var t = photos[i]; photos[i] = photos[j]; photos[j] = t; renderPhotos(); };
            });
            list.appendChild(d);
            var h = document.createElement('input'); h.type = 'hidden'; h.name = 'images[]'; h.value = u; inputs.appendChild(h);
        });
        var rest = pool.filter(function (u) { return photos.indexOf(u) === -1; });
        document.getElementById('photoPoolBox').hidden = !rest.length;
        poolBox.innerHTML = '';
        rest.forEach(function (u) {
            var im = document.createElement('img'); im.src = u; im.title = 'Dodaj do oferty';
            im.onclick = function () { if (photos.length < MAX) { photos.push(u); renderPhotos(); } };
            poolBox.appendChild(im);
        });
        var main = document.getElementById('al_img');
        if (photos[0]) { main.src = photos[0]; main.style.display = ''; }
        mainPhotoInfo();
        renderDesc();
    }
    // Wymiary zdjęcia głównego (zalecane przez Allegro 2560×2560).
    function mainPhotoInfo() {
        var box = document.getElementById('mainPhotoInfo'), auto = document.getElementById('mainPhoto2560').checked;
        if (!photos[0]) { box.textContent = ''; return; }
        var im = new Image(), u = photos[0];
        im.onload = function () {
            if (photos[0] !== u) return;
            var w = im.naturalWidth, h = im.naturalHeight;
            if (w === 2560 && h === 2560) { box.className = 'ok-note'; box.textContent = '✓ Zdjęcie główne ma 2560×2560 px.'; return; }
            box.className = 'hint';
            box.textContent = 'Zdjęcie główne: ' + w + '×' + h + ' px. ' + (auto ? 'Przy wysyłce CRM dopasuje je do 2560×2560 (białe tło, bez przycinania).' : 'Allegro zaleca 2560×2560 px.')
                + (Math.max(w, h) < 1200 ? ' Zdjęcie jest małe, po powiększeniu może być mniej ostre — lepiej dodać większe.' : '');
        };
        im.onerror = function () { if (photos[0] === u) box.textContent = ''; };
        im.src = u;
    }
    document.getElementById('mainPhoto2560').addEventListener('change', mainPhotoInfo);
    document.getElementById('photoAdd').addEventListener('click', function () {
        var inp = document.getElementById('photoUrl'), u = inp.value.trim();
        if (!/^https?:\/\//i.test(u)) { alert('Podaj adres zdjęcia zaczynający się od http:// lub https://'); return; }
        if (photos.indexOf(u) === -1 && photos.length < MAX) { photos.push(u); if (pool.indexOf(u) === -1) pool.push(u); }
        inp.value = ''; renderPhotos();
    });

    // ---- Edytor blokowy opisu: sekcje z 1-2 elementami (tekst / zdjęcie), jak na Allegro ----
    var sections = <?= json_encode(array_values($form['description_sections']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    var descTemplates = <?= json_encode($descTemplates, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    var editor = document.getElementById('descEditor');
    var LAYOUT = { T: 'Tekst', I: 'Zdjęcie', IT: 'Zdjęcie + tekst', TI: 'Tekst + zdjęcie', II: 'Dwa zdjęcia', TT: 'Dwa teksty' };
    function layoutOf(sec) { return sec.items.map(function (it) { return it.type === 'IMAGE' ? 'I' : 'T'; }).join(''); }
    function newSection(code) { return { items: code.split('').map(function (c) { return c === 'I' ? { type: 'IMAGE', url: '' } : { type: 'TEXT', content: '<p></p>' }; }) }; }
    function textItem(it) {
        var w = document.createElement('div');
        w.innerHTML = '<div class="de-tools">'
            + '<button type="button" data-cmd="formatBlock" data-arg="h1">Nagłówek 1</button><button type="button" data-cmd="formatBlock" data-arg="h2">Nagłówek 2</button>'
            + '<button type="button" data-cmd="formatBlock" data-arg="p">Akapit</button><button type="button" data-cmd="bold"><b>B</b></button>'
            + '<button type="button" data-cmd="insertUnorderedList">• Lista</button><button type="button" data-cmd="insertOrderedList">1. Lista</button></div>'
            + '<div class="de-text" contenteditable="true"></div>';
        var ed = w.querySelector('.de-text');
        ed.innerHTML = it.content || '<p></p>';
        ed.addEventListener('input', function () { it.content = ed.innerHTML; serialize(); });
        ed.addEventListener('paste', function (ev) {      // wklejanie jako czysty tekst - formatowanie przyciskami
            ev.preventDefault();
            document.execCommand('insertText', false, (ev.clipboardData || window.clipboardData).getData('text'));
        });
        w.querySelectorAll('[data-cmd]').forEach(function (b) {
            b.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
            b.addEventListener('click', function () {
                ed.focus();
                document.execCommand(b.dataset.cmd, false, b.dataset.arg ? '<' + b.dataset.arg + '>' : null);
                it.content = ed.innerHTML; serialize();
            });
        });
        return w;
    }
    function imageItem(it) {
        var w = document.createElement('div');
        w.className = 'de-img';
        function draw() {
            var opts = photos.concat(pool.filter(function (u) { return photos.indexOf(u) === -1; }));
            w.innerHTML = (it.url ? '<img src="' + esc(it.url) + '" alt="">' : '<span class="hint">Wybierz zdjęcie:</span>')
                + '<div class="de-pick">' + opts.map(function (u) { return '<img src="' + esc(u) + '" data-u="' + esc(u) + '" title="Użyj tego zdjęcia">'; }).join('') + '</div>'
                + '<button type="button" class="btn secondary" data-url style="font-size:12px;padding:3px 10px">z adresu…</button>';
            w.querySelectorAll('[data-u]').forEach(function (im) { im.onclick = function () { it.url = im.dataset.u; draw(); serialize(); }; });
            w.querySelector('[data-url]').onclick = function () {
                var u = prompt('Adres zdjęcia (https://…)', it.url || '');
                if (u && /^https?:\/\//i.test(u.trim())) { it.url = u.trim(); draw(); serialize(); }
            };
        }
        draw();
        return w;
    }
    function renderDesc() {
        editor.innerHTML = sections.length ? '' : '<p class="bad">Dodaj co najmniej jedną sekcję opisu.</p>';
        sections.forEach(function (sec, i) {
            var box = document.createElement('div');
            box.className = 'de-sec';
            box.innerHTML = '<div class="de-head"><span>Sekcja ' + (i + 1) + ' · ' + (LAYOUT[layoutOf(sec)] || '') + '</span><span>'
                + '<button type="button" data-mv="-1" title="W górę">↑</button><button type="button" data-mv="1" title="W dół">↓</button>'
                + '<button type="button" data-rm title="Usuń sekcję">🗑</button></span></div><div class="de-body' + (sec.items.length > 1 ? ' two' : '') + '"></div>';
            var body = box.querySelector('.de-body');
            sec.items.forEach(function (it) { body.appendChild(it.type === 'IMAGE' ? imageItem(it) : textItem(it)); });
            box.querySelector('[data-rm]').onclick = function () { if (confirm('Usunąć sekcję?')) { sections.splice(i, 1); renderDesc(); } };
            box.querySelectorAll('[data-mv]').forEach(function (b) {
                b.onclick = function () { var j = i + (+b.dataset.mv); if (j < 0 || j >= sections.length) return; var t = sections[i]; sections[i] = sections[j]; sections[j] = t; renderDesc(); };
            });
            editor.appendChild(box);
        });
        serialize();
    }
    function serialize() {
        document.getElementById('f_desc_json').value = JSON.stringify(sections);
        updateNav();
        renderPreview();
    }
    document.querySelectorAll('[data-add]').forEach(function (b) {
        b.addEventListener('click', function () { sections.push(newSection(b.dataset.add)); renderDesc(); });
    });
    // ---- Trwająca oferta: wczytanie jej opisu do edytora i wysyłka opisu z edytora (PATCH) ----
    (function () {
        var sel = document.getElementById('liveOffer'), msg = document.getElementById('liveMsg');
        var base = 'offer_allegro.php?product=<?= $productId ?>&ajax=';
        var wanted = <?= json_encode($liveOfferId) ?>;
        function say(t, cls) { msg.textContent = t; msg.className = 'hint ' + (cls || ''); }
        fetch(base + 'live_offers', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (list) {
            if (wanted && !list.some(function (o) { return o.id === wanted; })) list.unshift({ id: wanted, name: '', status: '' });
            sel.innerHTML = list.length ? list.map(function (o) {
                return '<option value="' + esc(o.id) + '"' + (o.id === wanted ? ' selected' : '') + '>' + esc(o.id + (o.name ? ' · ' + o.name : '') + (o.status && o.status !== 'ACTIVE' ? ' (' + o.status + ')' : '')) + '</option>';
            }).join('') : '<option value="">brak trwających ofert tego produktu</option>';
        }).catch(function () { sel.innerHTML = '<option value="">nie udało się pobrać ofert</option>'; });
        document.getElementById('liveLoad').onclick = function () {
            if (!sel.value) return;
            say('Pobieram opis z Allegro…');
            fetch(base + 'live_desc&id=' + encodeURIComponent(sel.value), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
                if (!j.ok) { say(j.message, 'bad'); return; }
                if (!confirm('Zastąpić opis w edytorze obecnym opisem oferty ' + sel.value + '?')) { say(''); return; }
                sections = j.sections; renderDesc(); say('Wczytano obecny opis oferty.', 'ok');
            }).catch(function () { say('Nie udało się pobrać opisu.', 'bad'); });
        };
        document.getElementById('livePush').onclick = function () {
            if (!sel.value) { say('Wybierz ofertę.', 'bad'); return; }
            if (!confirm('Zmienić opis trwającej oferty ' + sel.value + ' na Allegro na opis z edytora? Kupujący zobaczą go od razu.')) return;
            var b = this; b.disabled = true; say('Wysyłam opis na Allegro…');
            var fd = new FormData();
            fd.append('csrf', form.querySelector('[name=csrf]').value);
            fd.append('offer_id', sel.value);
            fd.append('description_sections', JSON.stringify(sections));
            fetch(base + 'push_desc', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); })
                .then(function (j) { say(j.message || (j.ok ? 'Gotowe.' : 'Allegro odrzuciło zmianę.'), j.ok ? 'ok' : 'bad'); })
                .catch(function () { say('Nie udało się wysłać opisu.', 'bad'); }).then(function () { b.disabled = false; });
        };
    })();
    var tplBtn = document.getElementById('descTplApply');
    if (tplBtn) tplBtn.addEventListener('click', function () {
        var t = descTemplates[document.getElementById('descTplPick').value];
        if (t && confirm('Zastąpić obecny opis szablonem „' + t.name + '”?')) { sections = JSON.parse(JSON.stringify(t.sections)); renderDesc(); }
    });
    // ---- Pełny podgląd oferty (układ strony Allegro) z danych formularza ----
    var pv = document.getElementById('offerPreview'), pvImg = 0;
    function optText(name) {
        var el = form.querySelector('[name="' + name + '"]');
        if (!el) return '';
        if (el.tagName === 'SELECT') return el.value ? el.options[el.selectedIndex].text : '';
        return el.value.trim();
    }
    // Parametry: nazwa z etykiety, wartość z pola (lista, pola wyboru, liczba z jednostką, zakres, własna wartość).
    function paramRows() {
        var rows = [];
        document.querySelectorAll('#catParams .prm').forEach(function (box) {
            if (box.hidden || box.closest('[hidden]')) return;
            var label = box.querySelector('label.ol');
            var name = label ? label.childNodes[0].textContent.trim() : '';
            var unit = box.querySelector('.unit') ? ' ' + box.querySelector('.unit').textContent : '';
            var vals = [], custom = box.querySelector('.prm-custom');
            var s = box.querySelector('select');
            if (s && s.value) vals.push(s.options[s.selectedIndex].text);
            box.querySelectorAll('input[type=checkbox]:checked').forEach(function (c) { vals.push(c.parentNode.textContent.trim()); });
            var from = box.querySelector('input[name$="[from]"]'), to = box.querySelector('input[name$="[to]"]');
            if (from) { if (from.value || to.value) vals.push((from.value || '…') + ' – ' + (to.value || '…') + unit); }
            else box.querySelectorAll('.unit-wrap input').forEach(function (i) { if (i.value.trim()) vals.push(i.value.trim() + unit); });
            if (custom && custom.value.trim()) vals = [custom.value.trim()];
            if (name && vals.length) rows.push([name, vals.join(', ')]);
        });
        return rows;
    }
    function descHtml() {
        return sections.map(function (sec) {
            return '<div class="pv-sec' + (sec.items.length > 1 ? ' two' : '') + '">' + sec.items.map(function (it) {
                return it.type === 'IMAGE' ? (it.url ? '<img src="' + esc(it.url) + '" alt="">' : '') : '<div>' + (it.content || '') + '</div>';
            }).join('') + '</div>';
        }).join('');
    }
    function publishText() {
        var mode = (form.querySelector('[name=publish_mode]:checked') || {}).value;
        if (mode === 'draft') return 'Oferta zostanie wystawiona jako nieaktywna — nie będzie widoczna, dopóki jej nie aktywujesz.';
        if (mode === 'scheduled') {
            var d = val('publish_date'), t = val('publish_time');
            if (!d || !t) return 'Oferta pojawi się w wybranym terminie — uzupełnij datę i godzinę.';
            var dt = new Date(d + 'T' + t);
            return 'Oferta pojawi się na Allegro ' + dt.toLocaleDateString('pl-PL', { day: 'numeric', month: 'long', year: 'numeric' }) + ', o ' + t + '.';
        }
        return '';
    }
    function row(k, v) { return '<div class="pv-row"><span class="k">' + esc(k) + '</span><span' + (v ? '>' + esc(v) : ' class="miss">nie wybrano') + '</span></div>'; }
    function renderPreview() {
        if (!pv.open) return;
        if (pvImg >= photos.length) pvImg = 0;
        var stock = parseInt(val('stock'), 10) || 0;
        var params = paramRows();
        var cond = params.filter(function (r) { return r[0] === 'Stan'; })[0];
        var handling = optText('handling');
        var safety = (form.querySelector('[name=safety_mode]:checked') || {}).value === 'none'
            ? 'Produkt nie ma informacji o bezpieczeństwie.' : val('safety_text');
        var catalog = document.getElementById('f_catalog').value !== '';
        var setQty = (form.querySelector('[name=set_mode]:checked') || {}).value === 'set' ? (parseInt(val('set_quantity'), 10) || 0) : 1;
        var status = publishText();
        var h = (status ? '<div class="pv-status">' + esc(status) + '</div>' : '')
            + '<p class="pv-crumbs">Allegro › ' + esc(document.getElementById('catPath').dataset.path || 'kategoria nie wybrana') + '</p>'
            + '<div class="pv-top"><div class="pv-box">'
            + '<div class="pv-main">' + (photos[pvImg] ? '<img src="' + esc(photos[pvImg]) + '" alt="">' : '<span class="hint">Brak zdjęć</span>') + '</div>'
            + '<div class="pv-thumbs">' + photos.map(function (u, i) { return '<img src="' + esc(u) + '" data-i="' + i + '"' + (i === pvImg ? ' class="on"' : '') + '>'; }).join('') + '</div>'
            + '</div><div class="pv-box">'
            + '<h1 class="pv-title">' + esc(title.value || '(tytuł oferty)') + '</h1>'
            + '<div class="pv-meta">' + (cond ? 'Stan: <b>' + esc(cond[1]) + '</b>' : '') + (catalog ? ' · produkt z Katalogu Allegro' : '')
                + (setQty > 1 ? '<br>Zestaw: <b>' + setQty + ' szt.</b> produktu w cenie oferty' : '') + '</div>'
            + (auctionBox.checked ? '<div class="pv-meta" style="margin-top:12px">Licytacja · cena wywoławcza</div>' : '')
            + '<div class="pv-price">' + fmtPrice(price.value).replace(/,(\d\d)$/, ',<small>$1</small>') + ' <small>zł</small></div>'
            + '<div class="pv-qty">' + (stock > 0 ? 'z ' + stock + (setQty > 1 ? ' zestawów' : ' ' + optText('stock_unit')) : '<span class="bad">brak sztuk</span>') + '</div>'
            + ((form.querySelector('[name=buyers]:checked') || {}).value === 'business' ? '<div class="pv-status" style="margin:0 0 8px">Tylko dla klientów Allegro Business</div>' : '')
            + '<button type="button" class="pv-btn cart" disabled>DODAJ DO KOSZYKA</button><button type="button" class="pv-btn buy" disabled>KUP TERAZ</button>'
            + '<div class="pv-rows">'
            + row('Dostawa', optText('shipping_rate') + (preBox.checked && val('shipment_date') ? ' · przedsprzedaż, wysyłka od ' + val('shipment_date') : (handling ? ' · wysyłka ' + handling : '')))
            + (val('delivery_info') ? row('Informacje o dostawie', val('delivery_info')) : '')
            + (optText('additional_services') ? row('Usługi dodatkowe', optText('additional_services')) : '')
            + (extraProducts.length ? row('W zestawie także', extraProducts.map(function (x) { return x.name + (x.quantity > 1 ? ' × ' + x.quantity : ''); }).join(', ')) : '')
            + row('Zwroty', optText('return_policy'))
            + row('Reklamacje', optText('implied_warranty'))
            + (optText('warranty') ? row('Gwarancja', optText('warranty')) : '')
            + row('Faktura', optText('invoice'))
            + (val('city') ? row('Wysyłka z', val('city') + (optText('province') ? ', ' + optText('province') : '')) : '')
            + '</div></div></div>'
            + '<div class="pv-box" style="margin-top:16px"><h2 class="pv-h">Parametry</h2>'
            + (params.length ? '<div class="pv-params">' + params.map(function (r) { return '<div><span class="k">' + esc(r[0]) + '</span><b>' + esc(r[1]) + '</b></div>'; }).join('') + '</div>'
                : '<p class="hint">Brak wypełnionych parametrów.</p>') + '</div>'
            + '<div class="pv-box" style="margin-top:16px"><h2 class="pv-h">Opis</h2><div class="pv-desc">' + (descHtml() || '<p class="hint">Brak opisu.</p>') + '</div></div>'
            + (attachments.length ? '<div class="pv-box" style="margin-top:16px"><h2 class="pv-h">Załączniki</h2>' + attachments.map(function (a) {
                return '<div class="pv-row"><span class="k">' + esc(ATT_TYPES[a.type] || 'Załącznik') + '</span><span>📄 ' + esc(a.name || a.id) + '</span></div>';
            }).join('') + '</div>' : '')
            + '<div class="pv-box" style="margin-top:16px"><h2 class="pv-h">Bezpieczeństwo produktu</h2>'
            + '<div class="pv-rows" style="margin-top:0;border-top:0">' + row('Producent', optText('producer')) + (optText('person') ? row('Osoba odpowiedzialna', optText('person')) : '') + '</div>'
            + '<p class="pv-safety">' + esc(safety || 'Brak informacji o bezpieczeństwie.') + '</p></div>';
        var page = document.getElementById('pvPage');
        page.innerHTML = h;
        page.querySelectorAll('.pv-thumbs img').forEach(function (im) { im.onclick = function () { pvImg = +im.dataset.i; renderPreview(); }; });
        var main = page.querySelector('.pv-main img');
        if (main) main.onclick = function () { openLightbox(photos, pvImg); };
    }
    function openPreview() {
        if (window.innerWidth < 900) pv.showModal(); else { pv.show(); document.body.classList.add('pv-on'); }
        renderPreview();
    }
    pv.addEventListener('close', function () { document.body.classList.remove('pv-on'); });
    document.getElementById('previewBtn').addEventListener('click', openPreview);
    document.getElementById('alPreview').addEventListener('click', openPreview);
    form.addEventListener('input', renderPreview);
    form.addEventListener('change', renderPreview);

    // ---- Postęp: co zostało do uzupełnienia w każdej sekcji ----
    function val(name) { var el = form.querySelector('[name="' + name + '"]'); return el ? el.value.trim() : ''; }
    function updateNav() {
        var words = title.value.trim().split(/\s+/).filter(Boolean).length;
        var paramsOk = true;
        document.querySelectorAll('#catParams .prm').forEach(function (box) {
            if (box.hidden || box.closest('[hidden]') || box.querySelector('.req').hidden) return;
            var filled = Array.prototype.some.call(box.querySelectorAll('input,select'), function (el) {
                return el.type === 'checkbox' ? el.checked : (el.value || '').trim() !== '' && !el.classList.contains('prm-filter');
            });
            if (!filled) paramsOk = false;
        });
        var hasText = sections.some(function (s) { return s.items.some(function (it) { return it.type === 'IMAGE' ? !!it.url : (it.content || '').replace(/<[^>]*>/g, '').trim() !== ''; }); });
        var mode = (form.querySelector('[name=publish_mode]:checked') || {}).value;
        var state = {
            's-title': words >= 3 && title.value.length <= 75,
            's-category': val('category_id') !== '' && !/podkategori|Nie wybrano|Nie udało/.test(document.getElementById('catPath').textContent),
            's-product': val('category_id') !== '' && paramsOk
                && ((form.querySelector('[name=set_mode]:checked') || {}).value !== 'set' || parseInt(val('set_quantity'), 10) >= 2),
            's-photos': photos.length > 0,
            's-desc': hasText,
            's-price': parseFloat(val('price').replace(',', '.')) > 0 && parseInt(val('stock'), 10) >= 1,
            's-delivery': val('shipping_rate') !== '' && val('handling') !== '' && (!preBox.checked || val('shipment_date') !== ''),
            's-after': val('return_policy') !== '' && val('implied_warranty') !== '',
            's-invoice': val('invoice') !== '',
            's-gpsr': val('producer') !== '' && ((form.querySelector('[name=safety_mode]:checked') || {}).value === 'none' || val('safety_text') !== ''),
            's-notes': true,
            's-attach': true,
            's-publish': mode !== 'scheduled' || (val('publish_date') !== '' && val('publish_time') !== ''),
            's-summary': true
        };
        document.querySelectorAll('#aoNav li').forEach(function (li) {
            li.className = state[li.dataset.for] ? 'ok' : 'todo';
        });
        var cat = document.getElementById('f_catalog').value !== '';
        document.getElementById('productMode').textContent = cat
            ? 'Oferta zostanie połączona z produktem z Katalogu Allegro: ' + (document.getElementById('f_catalog_name').value || '')
            : 'Przy wystawianiu oferty Allegro stworzy jednocześnie produkt z wypełnionych parametrów.';
    }
    form.addEventListener('input', updateNav);
    form.addEventListener('change', updateNav);
    form.addEventListener('submit', serialize);
    // Aktualizacja trwającej oferty: najpierw lista tego, co zmieni się na Allegro, dopiero po potwierdzeniu PATCH.
    var liveUpd = document.getElementById('liveUpdate'), liveOk = false;
    // Stan formularza tuż po wczytaniu oferty z Allegro (przed pierwszym kliknięciem/klawiszem): na serwer idą
    // nazwy pól, które operator zmienił - reszta oferty zostaje taka, jak jest na Allegro.
    var liveChanged = document.getElementById('liveChanged'), liveSnap = null;
    function formState() {
        serialize();
        var out = {};
        new FormData(form).forEach(function (v, k) {
            if (typeof v !== 'string' || k === 'action' || k === 'csrf' || k === 'live_changed') return;
            (out[k] = out[k] || []).push(v);
        });
        return out;
    }
    function takeSnap() { if (liveChanged && !liveSnap) liveSnap = formState(); }
    function markChanged() {
        if (!liveChanged || !liveSnap) return;
        var now = formState(), names = {};
        Object.keys(now).concat(Object.keys(liveSnap)).forEach(function (k) {
            if (JSON.stringify(now[k] || []) !== JSON.stringify(liveSnap[k] || [])) names[k.split('[')[0]] = 1;
        });
        liveChanged.value = JSON.stringify(Object.keys(names));
    }
    if (liveChanged) {
        ['pointerdown', 'keydown', 'focusin'].forEach(function (t) { document.addEventListener(t, takeSnap, true); });
        form.addEventListener('submit', markChanged);
    }
    if (liveUpd) liveUpd.addEventListener('click', function (ev) {
        if (liveOk) return;
        ev.preventDefault();
        serialize();
        markChanged();
        var fd = new FormData(form); fd.set('action', 'live_diff');
        liveUpd.disabled = true;
        fetch(location.href.split('#')[0], { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
            liveUpd.disabled = false;
            if (!j.ok) { alert('Oferta nie zostanie zmieniona. Uzupełnij pola wymagane przez Allegro:\n\n• ' + (j.errors || []).join('\n• ')); return; }
            if (!j.changes.length) { alert('Formularz niczym się nie różni od oferty na Allegro — nie ma czego aktualizować.'); return; }
            if (!confirm('Zmienić trwającą ofertę <?= $e($liveOfferId) ?> na Allegro? Kupujący zobaczą zmiany od razu.\n\nZmieni się:\n• ' + j.changes.join('\n• '))) return;
            liveOk = true; liveUpd.click();
        }).catch(function () { liveUpd.disabled = false; alert('Nie udało się sprawdzić zmian — spróbuj ponownie.'); });
    });

    // ---- Wybór kategorii: dopasowanie po nazwie albo drzewo, potem parametry kategorii ----
    var catInput = document.getElementById('f_cat'), pathBox = document.getElementById('catPath');
    var results = document.getElementById('catResults'), tree = document.getElementById('catTree');
    var paramsBox = document.getElementById('catParams'), taxBox = document.getElementById('taxBox');
    function getJson(q) { return fetch(base + q, { credentials: 'same-origin' }).then(function (r) { return r.json(); }); }

    function choose(id) {
        catInput.value = id;
        pathBox.innerHTML = '<span class="hint">Pobieram kategorię ' + esc(id) + '…</span>';
        paramsBox.innerHTML = '';
        var keep = new URLSearchParams();
        taxBox.querySelectorAll('select').forEach(function (s) { if (s.value) keep.append(s.name, s.value); });
        getJson('category&id=' + encodeURIComponent(id) + (keep.toString() ? '&' + keep.toString() : '')).then(function (d) {
            if (!d.ok) { pathBox.innerHTML = '<span class="bad">' + esc(d.message) + '</span>'; return; }
            pathBox.dataset.path = d.path.map(function (c) { return c.name; }).join(' › ');
            pathBox.innerHTML = '<div class="cat-chosen"><div><span class="cat-ok">✓ Wybrana kategoria</span> <strong>'
                + esc(d.path.length ? d.path[d.path.length - 1].name : id) + '</strong> <span class="hint">nr ' + esc(id) + '</span><br>'
                + esc(pathBox.dataset.path) + (d.leaf ? '' : ' <span class="bad">— wybierz podkategorię</span>')
                + '</div><button type="button" class="cat-x" title="Usuń wybraną kategorię">✕ Usuń</button></div>';
            paramsBox.innerHTML = d.params || '';
            taxBox.innerHTML = d.tax || '';
            refreshParams();
            syncCond();
        }).catch(function () { pathBox.innerHTML = '<span class="bad">Błąd połączenia z Allegro.</span>'; });
    }

    // Krzyżyk przy wybranej kategorii: usuwa kategorię razem z jej parametrami.
    pathBox.addEventListener('click', function (ev) {
        if (!ev.target.closest('.cat-x')) return;
        catInput.value = '';
        pathBox.dataset.path = '';
        pathBox.innerHTML = '<span class="bad">Nie wybrano kategorii</span>';
        paramsBox.innerHTML = '<p class="hint">Parametry pojawią się po wybraniu kategorii.</p>';
        updateNav();
        renderPreview();
    });

    document.getElementById('catSearch').addEventListener('click', function () {
        var q = document.getElementById('catQuery').value.trim();
        if (!q) return;
        tree.hidden = true;
        results.innerHTML = '<p class="hint">Szukam…</p>';
        getJson('match&q=' + encodeURIComponent(q)).then(function (list) {
            results.innerHTML = list.length ? '<p class="hint" style="margin-top:10px">Propozycje Allegro — kliknij, żeby wybrać kategorię:</p>'
                : '<p class="hint">Allegro nie dopasowało kategorii — użyj drzewa.</p>';
            list.forEach(function (c) {
                var b = document.createElement('button');
                b.type = 'button'; b.className = 'cat-item';
                b.innerHTML = '<span>' + esc(c.path) + '</span><span class="cat-pick">Wybierz</span>';
                b.addEventListener('click', function () { results.innerHTML = ''; choose(c.id); });
                results.appendChild(b);
            });
        }).catch(function () { results.innerHTML = '<p class="bad">Błąd połączenia z Allegro.</p>'; });
    });

    // Drzewo: kolejne listy podkategorii; wybór liścia ustawia kategorię.
    function level(parent, depth) {
        while (tree.children.length > depth) tree.removeChild(tree.lastChild);
        getJson('children' + (parent ? '&parent=' + encodeURIComponent(parent) : '')).then(function (list) {
            if (!list.length) return;
            var s = document.createElement('select');
            s.innerHTML = '<option value="">— wybierz —</option>' + list.map(function (c) {
                return '<option value="' + esc(c.id) + '" data-leaf="' + (c.leaf ? 1 : 0) + '">' + esc(c.name) + (c.leaf ? '' : ' ›') + '</option>';
            }).join('');
            s.addEventListener('change', function () {
                var o = s.options[s.selectedIndex];
                if (!s.value) return;
                if (o.dataset.leaf === '1') { while (tree.children.length > depth + 1) tree.removeChild(tree.lastChild); choose(s.value); }
                else level(s.value, depth + 1);
            });
            tree.appendChild(s);
        });
    }
    document.getElementById('catBrowse').addEventListener('click', function () {
        results.innerHTML = '';
        tree.hidden = false;
        if (!tree.children.length) level('', 0);
    });

    // ---- Parametry: warunki displayedIf/requiredIf, zależne słowniki, wyszukiwanie w długich listach, kolejne wartości ----
    function selected() {
        var out = {};
        paramsBox.querySelectorAll('.prm').forEach(function (box) {
            var ids = [];
            if (box.hidden) { out[box.dataset.pid] = ids; return; }     // ukryty parametr nie steruje innymi
            box.querySelectorAll('select').forEach(function (s) { if (s.value) ids.push(s.value); });
            box.querySelectorAll('input[type=checkbox]:checked').forEach(function (c) { ids.push(c.value); });
            out[box.dataset.pid] = ids;
        });
        return out;
    }
    function met(json, sel) {
        if (!json) return null;
        var c = JSON.parse(json);
        return (c.parameters || []).every(function (r) {
            var want = r.oneOfValueIds || [];
            return !want.length || want.some(function (v) { return (sel[r.id] || []).indexOf(String(v)) !== -1; });
        });
    }
    function refreshParams() {
        var sel = selected();
        paramsBox.querySelectorAll('.prm').forEach(function (box) {
            var shown = met(box.dataset.displayedIf, sel);
            box.hidden = shown === false;
            box.querySelectorAll('input,select').forEach(function (el) { el.disabled = box.hidden; });
            var req = box.dataset.required === '1' || met(box.dataset.requiredIf, sel) === true;
            box.querySelector('.req').hidden = !req;
            box.querySelector('.opt').hidden = req;
        });
        // Słowniki zależne od wartości innego parametru (jak na Allegro): lista ma pozycje dopiero po wyborze
        // parametru nadrzędnego i tylko te, które do niego pasują. Zamiast pustej listy - podpowiedź, co wybrać.
        paramsBox.querySelectorAll('[data-depends-on]').forEach(function (el) {
            var box = el.closest('.prm'), pbox = paramsBox.querySelector('.prm[data-pid="' + el.dataset.dependsOn + '"]');
            var parent = sel[el.dataset.dependsOn] || [], free = !pbox;          // brak parametru nadrzędnego w formularzu - pokaż wszystko
            var pname = pbox ? pbox.querySelector('.ol').firstChild.textContent.trim() : '';
            var visible = 0;
            el.querySelectorAll('option,label').forEach(function (o) {
                if (o.tagName === 'OPTION' && o.value === '') return;
                var ok = free || !o.dataset.depends || o.dataset.depends.split(',').some(function (v) { return parent.indexOf(v) !== -1; });
                o.hidden = !ok;
                if (ok) visible++;
                if (!ok && o.selected) o.selected = false;
                var cb = o.querySelector && o.querySelector('input'); if (!ok && cb) cb.checked = false;
            });
            var hint = box.querySelector('.prm-dep');
            if (!hint) { hint = document.createElement('p'); hint.className = 'hint prm-dep'; el.parentNode.insertBefore(hint, el.nextSibling); }
            var msg = '';
            if (!free && !parent.length) msg = 'Najpierw wybierz „' + pname + '” — od tej wartości zależy lista.';
            else if (!visible) msg = 'Dla wybranej wartości „' + pname + '” Allegro nie ma pozycji na tej liście — zostaw puste.';
            hint.textContent = msg;
            hint.hidden = msg === '';
            var off = box.hidden || msg !== '';
            (el.tagName === 'SELECT' ? [el] : el.querySelectorAll('input')).forEach(function (x) { x.disabled = off; });
        });
        var catalog = document.getElementById('f_catalog').value !== '';
        var pp = document.getElementById('productParams');
        if (pp) { pp.hidden = catalog; pp.querySelectorAll('input,select').forEach(function (el) { if (catalog) el.disabled = true; }); }
        updateNav();
    }
    paramsBox.addEventListener('change', refreshParams);
    paramsBox.addEventListener('input', function (ev) {
        if (!ev.target.classList.contains('prm-filter')) return;
        var q = ev.target.value.toLowerCase(), s = ev.target.nextElementSibling;
        Array.prototype.forEach.call(s.options, function (o, i) { if (i) o.hidden = q !== '' && o.text.toLowerCase().indexOf(q) === -1; });
    });
    paramsBox.addEventListener('click', function (ev) {
        if (!ev.target.classList.contains('add-val')) return;
        var multi = ev.target.previousElementSibling, rows = multi.querySelectorAll('.unit-wrap');
        if (rows.length >= +multi.dataset.max) return;
        var copy = rows[rows.length - 1].cloneNode(true);
        copy.querySelector('input').value = '';
        multi.appendChild(copy);
        ev.target.hidden = rows.length + 1 >= +multi.dataset.max;
    });
    refreshParams();

    // ---- Produkt z Katalogu Allegro ----
    var catalogIn = document.getElementById('f_catalog'), catalogOut = document.getElementById('catalogResults');
    function setCatalog(id, name) {
        catalogIn.value = id;
        document.getElementById('f_catalog_name').value = name;
        document.getElementById('catalogName').textContent = name || id;
        document.getElementById('catalogChosen').hidden = !id;
        catalogOut.innerHTML = '';
        refreshParams();
    }
    document.getElementById('catalogClear').addEventListener('click', function () { setCatalog('', ''); });
    document.getElementById('catalogSearch').addEventListener('click', function () {
        var eanField = paramsBox.querySelector('[data-param-id="225693"]');
        var q = document.getElementById('catalogQuery').value.trim() || (eanField ? eanField.value.trim() : '');
        if (!q) return;
        catalogOut.innerHTML = '<p class="hint">Szukam w Katalogu Allegro…</p>';
        getJson('products&q=' + encodeURIComponent(q) + '&cat=' + encodeURIComponent(catInput.value)).then(function (d) {
            if (!d.ok) { catalogOut.innerHTML = '<p class="bad">' + esc(d.message || 'Błąd Allegro.') + '</p>'; return; }
            catalogOut.innerHTML = d.products.length ? '' : '<p class="hint">Brak produktu w Katalogu — wypełnij parametry produktu, a Allegro go utworzy.</p>';
            d.products.forEach(function (pr) {
                var b = document.createElement('button');
                b.type = 'button'; b.className = 'cat-item';
                b.innerHTML = (pr.image ? '<img src="' + esc(pr.image) + '" alt="" style="width:36px;height:36px;object-fit:contain;vertical-align:middle;margin-right:8px">' : '')
                    + esc(pr.name) + (pr.category && catInput.value && pr.category !== catInput.value ? ' <span class="hint">(inna kategoria: ' + esc(pr.category) + ')</span>' : '');
                b.addEventListener('click', function () {
                    setCatalog(pr.id, pr.name);
                    if (pr.category && pr.category !== catInput.value) choose(pr.category);
                });
                catalogOut.appendChild(b);
            });
        }).catch(function () { catalogOut.innerHTML = '<p class="bad">Błąd połączenia z Allegro.</p>'; });
    });

    // Tytuł: 3-75 znaków, co najmniej 3 słowa (jak w Allegro).
    function syncTitle() {
        var words = title.value.trim().split(/\s+/).filter(Boolean).length;
        var w = document.getElementById('titleWords');
        w.textContent = words < 3 ? 'Tytuł musi mieć co najmniej 3 słowa.' : '';
        w.className = words < 3 ? 'bad' : '';
        document.getElementById('titleLen').textContent = title.value.length + ' / 75';
    }
    title.addEventListener('input', syncTitle);
    syncTitle();

    renderPhotos();
    renderAtt();
    renderXp();
    sync();
    syncCond();
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
