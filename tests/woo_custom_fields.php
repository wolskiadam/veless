<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Własne pola ze sklepu WooCommerce (meta_data -> products.custom_fields) - SQLite, bez sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Handlers\WooProductImportHandler;
use Pase\Repository\ProductFieldRepository;
use Pase\Services\WooCustomFields;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE product_fields (id INTEGER PRIMARY KEY AUTOINCREMENT, field_key TEXT UNIQUE, label TEXT, type TEXT DEFAULT "text",
    options TEXT, categories TEXT, position INT DEFAULT 100)');
$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, sku TEXT UNIQUE, name TEXT, custom_fields TEXT NULL, payload TEXT NULL, source_integration_id INT NULL)');
WooCustomFields::migrate($pdo);
WooCustomFields::migrate($pdo);
check(in_array('woo_meta_key', array_column($pdo->query('PRAGMA table_info(product_fields)')->fetchAll(), 'name'), true), 'product_fields.woo_meta_key added once');

$meta = [
    ['id' => 1, 'key' => 'sklad', 'value' => "Wosk sojowy\nKnot bawełniany"],
    ['id' => 2, 'key' => '_sklad', 'value' => 'field_64a1b2c3'],          // odnośnik ACF - pomijamy
    ['id' => 3, 'key' => 'czas_palenia', 'value' => '40 h'],
    ['id' => 4, 'key' => 'nuty', 'value' => ['wanilia', 'cynamon']],
    ['id' => 5, 'key' => 'pusty', 'value' => ''],
    ['id' => 6, 'key' => '_wpm_gtin_code', 'value' => '5901234123457'],
];
$vals = WooCustomFields::metaValues($meta);
check($vals === ['sklad' => "Wosk sojowy\nKnot bawełniany", 'czas_palenia' => '40 h', 'nuty' => 'wanilia, cynamon', '_wpm_gtin_code' => '5901234123457'],
    'meta_data -> text values; ACF reference and empty skipped, list joined');
check(WooCustomFields::text(['a' => ['b' => 1]]) === '{"a":{"b":1}}', 'nested value kept as JSON');
check(WooCustomFields::labelFromKey('czas_palenia') === 'Czas palenia', 'label from key');

$fields = new ProductFieldRepository($pdo);
$cf = new WooCustomFields($pdo);
$pdo->prepare('INSERT INTO products (sku, name, custom_fields, payload) VALUES (?,?,?,?)')
    ->execute(['GB-1L', 'Gingerbread', json_encode(['kolor' => 'brąz']), json_encode(['meta_data' => $meta])]);
$pdo->prepare('INSERT INTO products (sku, name, payload) VALUES (?,?,?)')
    ->execute(['MW-1L', 'Mulled Wine', json_encode(['meta_data' => [['key' => 'sklad', 'value' => 'Parafina']]])]);

$d = array_column($cf->discover(), null, 'key');
check($d['sklad']['count'] === 2 && !$d['sklad']['technical'] && $d['_wpm_gtin_code']['technical'] && !isset($d['_sklad']), 'discover counts keys across products, flags technical ones');
check(array_key_last($d) === '_wpm_gtin_code', 'technical keys listed last');

$fid = $fields->create('Skład', 'text', [], [], 1);
$fields->setWooKey($fid, 'sklad');
$fields->create('Kolor', 'text', [], [], 2);   // pole ręczne
check($cf->mapping() === ['sklad' => 'sklad'] && $cf->backfill() === 2, 'backfill fills mapped field from stored payloads');
$gb = json_decode($pdo->query("SELECT custom_fields FROM products WHERE sku='GB-1L'")->fetchColumn(), true);
check($gb === ['kolor' => 'brąz', 'sklad' => "Wosk sojowy\nKnot bawełniany"], 'manual value kept, shop value added');
check(($cf->discover()[0]['field'] ?? null) === 'sklad', 'discover shows which CRM field a key feeds');

// Import z Woo (handler woła applyFromPayload po upsert) aktualizuje pole; pusty w sklepie czyści wartość ze sklepu.
$cf->applyFromPayload('MW-1L', ['sku' => 'MW-1L', 'meta_data' => [['key' => 'sklad', 'value' => 'Wosk rzepakowy']]]);
check(json_decode($pdo->query("SELECT custom_fields FROM products WHERE sku='MW-1L'")->fetchColumn(), true) === ['sklad' => 'Wosk rzepakowy'], 'import updates shop field');
$cf->applyFromPayload('MW-1L', ['sku' => 'MW-1L', 'meta_data' => []]);
check($pdo->query("SELECT custom_fields FROM products WHERE sku='MW-1L'")->fetchColumn() === null, 'field removed in shop is cleared in CRM');
check(str_contains((string) file_get_contents(dirname(__DIR__) . '/src/Handlers/WooProductImportHandler.php'), '$this->customFields?->applyFromPayload($sku, $payload)'), 'product import applies shop fields');

// Wariant dziedziczy pola rodzica, własne wartości wariantu wygrywają.
$v = WooProductImportHandler::variationPayload(
    ['id' => 11, 'meta_data' => [['key' => 'czas_palenia', 'value' => '20 h']]],
    ['id' => 10, 'name' => 'Świeca', 'meta_data' => [['key' => 'sklad', 'value' => 'Soja'], ['key' => 'czas_palenia', 'value' => '40 h']]],
    1
);
check(WooCustomFields::metaValues($v['meta_data']) === ['sklad' => 'Soja', 'czas_palenia' => '20 h'], 'variation inherits parent fields, own value wins');

// Pole „Zdjęcie”: ID z biblioteki mediów -> link (raz ze sklepu, potem z pamięci woo_media).
$calls = [];
$img = new WooCustomFields($pdo, static function (int $int, int $media) use (&$calls): ?string {
    $calls[] = "$int:$media";
    return $media === 404 ? null : "https://sklep.test/wp-content/uploads/obraz-{$media}.jpg";
});
check($img->imageUrl('1234', 1) === 'https://sklep.test/wp-content/uploads/obraz-1234.jpg' && $img->imageUrl('1234', 1) === 'https://sklep.test/wp-content/uploads/obraz-1234.jpg' && $calls === ['1:1234'],
    'attachment ID resolved once, then from cache');
check($img->imageUrl('404', 1) === '404' && $img->imageUrl('https://x.test/a.png', 1) === 'https://x.test/a.png', 'unresolvable ID kept, ready URL kept');
check($img->imageUrl('{"id":55,"url":"https://x.test/b.webp"}', 1) === 'https://x.test/b.webp' && $img->imageUrl('{"ID":56}', 1) === 'https://sklep.test/wp-content/uploads/obraz-56.jpg', 'ACF image object: url or id');
check((new WooCustomFields($pdo))->imageUrl('1234', 1) === 'https://sklep.test/wp-content/uploads/obraz-1234.jpg' && (new WooCustomFields($pdo))->imageUrl('999', 1) === '999', 'without shop access only cached links are used');
$budget = new WooCustomFields($pdo, static fn() => 'https://x.test/c.jpg');
$budget->lookupBudget = 1;
check($budget->imageUrl('700', 1) === 'https://x.test/c.jpg' && $budget->imageUrl('701', 1) === '701', 'lookup budget stops shop calls from the admin page');
check(WooCustomFields::looksLikeImage('1234', '_shop_side_image') && WooCustomFields::looksLikeImage('https://a.pl/x.JPG') && !WooCustomFields::looksLikeImage('40 h', 'zdjecie')
    && !WooCustomFields::looksLikeImage('61', '_yoast_wpseo_seo_title_score') && !WooCustomFields::looksLikeImage('30', '_woo_prdd_lite_maximum_number_days'), 'image-like detection: ID only in image-named fields');

$pdo->exec("UPDATE products SET source_integration_id = 1, payload = '" . json_encode(['meta_data' => [['key' => '_obok_opisu', 'value' => '321']]]) . "' WHERE sku = 'MW-1L'");
$fid2 = $fields->create('Zdjęcie obok opisu', 'image', [], [], 3);
$fields->setWooKey($fid2, '_obok_opisu');
$img->backfill();
check(json_decode($pdo->query("SELECT custom_fields FROM products WHERE sku='MW-1L'")->fetchColumn(), true)['zdjecie_obok_opisu'] === 'https://sklep.test/wp-content/uploads/obraz-321.jpg', 'backfill turns image field into a link');
$img->applyFromPayload('MW-1L', ['__integration_id' => 1, 'meta_data' => [['key' => '_obok_opisu', 'value' => '322']]]);
check(json_decode($pdo->query("SELECT custom_fields FROM products WHERE sku='MW-1L'")->fetchColumn(), true)['zdjecie_obok_opisu'] === 'https://sklep.test/wp-content/uploads/obraz-322.jpg', 'import resolves image from the product shop');

echo "\nAll {$checks} checks passed.\n";
