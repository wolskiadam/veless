<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// ERLI - wystawianie ofert i ceny (Services\ErliOffers + Services\Erli::pushStock): treść oferty z magazynu (cena × mnożnik,
// zdjęcia, opis z szablonu z tagami, kategoria, parametry z reguł, cennik), wystawienie/aktualizacja, cenniki, kategorie,
// ceny w synchronizacji - SQLite, atrapa API ERLI.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
    if (str_starts_with($class, 'PasePlugin\\')) {
        [$vendor, $rest] = explode('\\', substr($class, 11), 2);
        require dirname(__DIR__) . '/integrations/' . strtolower($vendor) . '/' . str_replace('\\', '/', $rest) . '.php';
    }
});
use Pase\Services\Erli;
use Pase\Services\ErliOffers;
use PasePlugin\Erli\ErliClient;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE settings (setting_key VARCHAR(64) PRIMARY KEY, setting_value TEXT NULL)');
$pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type VARCHAR(48) NOT NULL, name VARCHAR(120) NOT NULL, is_active INT NOT NULL DEFAULT 1,
    config TEXT NULL, webhook_secret VARCHAR(255) NULL, access_token TEXT NULL, refresh_token TEXT NULL, expires_at DATETIME NULL)');
$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, pase_price REAL, pase_stock INTEGER, actual_stock INTEGER NULL, description TEXT,
    short_description TEXT, images TEXT, categories TEXT, attributes TEXT, ean TEXT, weight REAL, length REAL, width REAL, height REAL, woo_product_id INTEGER, allegro_sections TEXT)');
Erli::migrate($pdo);
Erli::migrate($pdo);   // drugi raz bez błędu (kolumny cen dodane raz)
check(in_array('pushed_price', array_column($pdo->query('PRAGMA table_info(erli_products)')->fetchAll(), 'name'), true), 'price columns added to erli_products');
$pdo->exec("INSERT INTO settings VALUES ('APP_BASE_URL', 'https://crm.example.pl')");
$pdo->exec("INSERT INTO integration_accounts (id, type, name, config) VALUES (4, 'erli', 'ERLI', '" . json_encode(['api_key' => 'k', 'price_multiplier' => '1,10',
    'dispatch_days' => '2', 'tax_rate' => 'TAX_8', 'push_price' => 'on']) . "')");
$pdo->exec("INSERT INTO products (id, sku, name, pase_price, pase_stock, description, images, categories, attributes, ean, weight) VALUES
    (1, 'SW-1', 'Świeca sojowa Lawenda', 39.99, 7, '<p>Opis <strong>świecy</strong><script>x</script></p>', '[\"/img/sw1.png\",\"https://cdn.example.pl/sw1b.jpg\"]',
     '[\"Świece\"]', '[{\"name\":\"Kolor\",\"options\":[\"Fioletowy\"]},{\"name\":\"Pojemność\",\"options\":[\"180 ml\"]}]', '5901234123457', 0.4),
    (2, '', 'Bez SKU', 10, 1, NULL, '[\"/a.png\"]', NULL, NULL, NULL, NULL),
    (3, 'WS-2', 'Wosk bez zdjęcia', NULL, 3, NULL, '[]', '[\"Woski\"]', NULL, NULL, NULL)");

$calls = [];
$er = ['products' => [], 'existing' => ['SW-OLD'], 'batch' => []];
$transport = static function (string $method, string $url, array $headers, ?string $body) use (&$calls, &$er): array {
    $path = substr((string) parse_url($url, PHP_URL_PATH), strlen('/svc/shop-api'));
    $b = json_decode((string) $body, true);
    $calls[] = [$method, $path, $b];
    if ($method === 'GET' && $path === '/delivery/priceLists') { return [200, json_encode([['id' => 1, 'name' => '*'], ['id' => 2, 'name' => 'gabaryty']])]; }
    if ($method === 'GET' && $path === '/delivery/priceListsDetails') {
        return [200, json_encode([['id' => 2, 'name' => 'gabaryty', 'prices' => [['deliveryMethod' => ['id' => 'inPost', 'deliveryTime' => ['unit' => 'days', 'minPeriod' => 1, 'maxPeriod' => 2]], 'basePrice' => 1299, 'nextItemPrice' => 0]], 'createdAt' => '2026-01-01T00:00:00Z']])];
    }
    if ($method === 'POST' && $path === '/dictionaries/categories/_search') {
        $all = [['id' => 10, 'name' => 'Dom', 'leaf' => false, 'breadcrumb' => [['id' => 10, 'name' => 'Dom']]],
                ['id' => 11, 'name' => 'Świece', 'leaf' => true, 'breadcrumb' => [['id' => 10, 'name' => 'Dom']]]];
        return [200, json_encode(array_values(array_filter($all, static fn($c) => $c['id'] > (int) ($b['after'] ?? 0))))];
    }
    if ($method === 'POST' && $path === '/dictionaries/attributes/_search' && ($b['categoryId'] ?? 0) === 11) {
        return [200, json_encode([['id' => 501, 'name' => 'Kolor', 'type' => 'dictionary', 'required' => true, 'maxValues' => 1],
            ['id' => 502, 'name' => 'Pojemność', 'type' => 'number', 'required' => false, 'maxValues' => 1, 'unit' => 'ml'],
            ['id' => 503, 'name' => 'Zapach', 'type' => 'string', 'required' => true, 'maxValues' => 1]])];
    }
    if ($method === 'POST' && $path === '/dictionaries/attributeValues/_search') {
        return [200, json_encode([['id' => 501, 'values' => ['Czarny', 'Fioletowy'], 'valueIds' => ['7', '8']]])];
    }
    if ($method === 'POST' && preg_match('~^/products/([^/]+)$~', $path, $m)) {
        if (in_array(rawurldecode($m[1]), $er['existing'], true)) { return [409, '{"message":"exists"}']; }
        $er['products'][rawurldecode($m[1])] = $b;
        return [202, ''];
    }
    if ($method === 'PATCH' && $path === '/products/batch-update') {
        $er['batch'][] = $b;
        return [200, json_encode(array_map(static fn($it) => ['externalId' => $it['externalId'], 'status' => 200], $b))];
    }
    if ($method === 'PATCH' && preg_match('~^/products/([^/]+)$~', $path, $m)) {
        $er['products'][rawurldecode($m[1])] = ['patched' => $b];
        return [202, '{"updatedFields":["name"]}'];
    }
    return [404, '{"message":"not found"}'];
};
$factory = static fn(array $account): ErliClient => new ErliClient($account['config'], $transport);
$erli = new Erli($pdo, $factory);
$offers = new ErliOffers($pdo, $erli);
$acc = static fn(): array => (new \Pase\Repository\IntegrationAccountRepository($pdo))->find(4);

// --- cena ---
check(Erli::erliPrice($acc(), '39.99') === 4399 && Erli::erliPrice($acc(), null) === null && Erli::erliPrice(['config' => []], 12.5) === 1250,
    'ERLI price = warehouse price × multiplier (comma accepted), in grosze; no price = null');

// --- cenniki i kategorie ---
check($offers->refreshPriceLists($acc()) === 2 && $offers->priceLists(4)[1]['prices'][0]['basePrice'] === 1299, 'price lists fetched with their delivery prices');
$offers->setDefaultPriceList(4, 'gabaryty');
check($offers->refreshCategories($acc()) === 2 && $offers->category(11)['path'] === 'Dom > Świece', 'category tree stored with its path (category itself appended)');
check(array_column($offers->searchCategories('Świe'), 'id') === [11], 'category search finds leaf categories only');

// --- oferta ---
$offers->templates()->create('Świece', ['Świece'], [['items' => [['type' => 'TEXT', 'content' => '<h2>[nazwa]</h2>[opis_caly]']]], ['items' => [['type' => 'IMAGE', 'url' => '/img/baner.png']]]]);
$p1 = $pdo->query('SELECT * FROM products WHERE id = 1')->fetch();
$o = $offers->buildOffer($acc(), $p1);
check($o['errors'] === [] && $o['external_id'] === 'SW-1', 'product with SKU, price and photos can be listed');
check($o['payload']['price'] === 4399 && $o['payload']['stock'] === 7 && $o['payload']['taxRate'] === 'TAX_8' && $o['payload']['dispatchTime'] === ['unit' => 'day', 'period' => 2]
    && $o['payload']['weight'] === 400 && $o['payload']['ean'] === '5901234123457', 'price, stock, VAT, dispatch time, weight in grams, EAN');
check($o['payload']['images'] === [['url' => 'https://crm.example.pl/img/sw1.png'], ['url' => 'https://cdn.example.pl/sw1b.jpg']], 'relative photo paths made absolute');
$txt = $o['payload']['description']['sections'][0]['items'][0]['content'];
check(str_contains($txt, '<h2>Świeca sojowa Lawenda</h2>') && str_contains($txt, '<b>świecy</b>') && !str_contains($txt, 'script')
    && $o['payload']['description']['sections'][1]['items'][0]['url'] === 'https://crm.example.pl/img/baner.png', 'description from the category template: tags filled, HTML cleaned');
check(!isset($o['payload']['externalCategories']) && str_contains(implode(' ', $o['warnings']), 'kategorii ERLI') && $o['payload']['deliveryPriceList'] === 'gabaryty',
    'no category mapping: warning; default price list used');

$offers->setMapping('Świece', 11, 'wysylka-swiece');
$offers->saveRules(11, [501 => ['mode' => 'field', 'value' => 'attr:Kolor'], 502 => ['mode' => 'field', 'value' => 'attr:Pojemność'], 503 => ['mode' => 'value', 'value' => '']]);
$o = $offers->buildOffer($acc(), $p1);
check($o['payload']['externalCategories'] === [['source' => 'marketplace', 'breadcrumb' => [['id' => 10, 'name' => 'Dom'], ['id' => 11, 'name' => 'Świece']]]]
    && $o['payload']['deliveryPriceList'] === 'wysylka-swiece', 'mapped category with its breadcrumb and its own price list');
$attrs = array_column($o['payload']['externalAttributes'], null, 'id');
check($attrs[501]['values'] === [8] && $attrs[501]['type'] === 'dictionary' && $attrs[502]['values'] === [180] && $attrs[502]['unit'] === 'ml',
    'parameters from rules: product attribute matched to the ERLI value id by name, number parsed from "180 ml"');
check(str_contains(implode(' ', $o['warnings']), '„Zapach”'), 'missing required parameter reported as a warning');
check(ErliOffers::attributeValues(['type' => 'dictionary', 'values' => ['7' => 'Czarny']], ['Niebieski']) === []
    && ErliOffers::attributeValues(['type' => 'range', 'maxValues' => 1], ['10 – 20']) === [10, 20], 'unknown dictionary value dropped; range parsed to two numbers');

$o2 = $offers->buildOffer($acc(), $pdo->query('SELECT * FROM products WHERE id = 2')->fetch());
$o3 = $offers->buildOffer($acc(), $pdo->query('SELECT * FROM products WHERE id = 3')->fetch());
check(count($o2['errors']) === 1 && str_contains($o2['errors'][0], 'SKU') && count($o3['errors']) === 2, 'no SKU / no price / no photos block listing');

// --- wystawienie ---
$r = $offers->publish($acc(), [1, 3]);
check($r['listed'] === 1 && $r['failed'] === 1 && isset($er['products']['SW-1']) && $er['products']['SW-1']['name'] === 'Świeca sojowa Lawenda', 'listed in ERLI; product with errors not sent');
$l = $offers->listings(4);
check($l[1]['status'] === 'listed' && $l[1]['listed_at'] !== null && $l[3]['status'] === 'error' && str_contains((string) $l[3]['error'], 'ceny'), 'listing result stored per product');
$cat = $pdo->query("SELECT * FROM erli_products WHERE external_id = 'SW-1'")->fetch();
check((int) $cat['pushed_qty'] === 7 && (int) $cat['pushed_price'] === 4399 && $cat['match_sku'] === 'SW-1', 'listed product joins the ERLI catalog in CRM (stock and price sync continue)');
$pdo->exec("UPDATE products SET sku = 'SW-OLD' WHERE id = 1");
$r = $offers->publish($acc(), [1]);
check($r['updated'] === 1 && isset($er['products']['SW-OLD']['patched']['price']), 'product already in ERLI: offer updated (PATCH) instead');
$pdo->exec("UPDATE products SET sku = 'SW-1' WHERE id = 1");

// --- ceny w synchronizacji ---
$pdo->exec("UPDATE products SET pase_price = 45, pase_stock = 7 WHERE id = 1");
$pdo->exec("INSERT INTO settings VALUES ('ERLI_4_CATALOG_AT', '" . time() . "')");
check($erli->pushStock($acc(), $erli->client($acc())) === 1 && end($er['batch']) === [['externalId' => 'SW-1', 'price' => 4950]], 'changed price sent alone (stock unchanged)');
check($erli->pushStock($acc(), $erli->client($acc())) === 0, 'nothing sent when price and stock already match');
$pdo->exec("UPDATE integration_accounts SET config = '" . json_encode(['api_key' => 'k']) . "' WHERE id = 4");
$pdo->exec("UPDATE products SET pase_price = 50, pase_stock = 2 WHERE id = 1");
check($erli->pushStock($acc(), $erli->client($acc())) === 1 && end($er['batch']) === [['externalId' => 'SW-1', 'stock' => 2]], 'price sync off by default: only stock');

echo "\n{$checks} checks passed\n";
