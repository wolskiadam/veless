<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Lista produktów: warianty jednego produktu Woo razem, produkty bez rodzica osobno - SQLite, bez sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Repository\ProductRepository;
use Pase\Handlers\WooProductImportHandler;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, pase_stock INT NULL, pase_price REAL NULL,
    source_integration_id INT NULL, woo_product_id INT NULL, woo_variation_id INT NULL, categories TEXT NULL, location_id INT NULL)');
// Jak na zrzucie „hot wine”: dwa warianty rodzica 11538 (knot drewniany/bawełniany), wcześniej dodane
// trzy zwykłe produkty Hot Wine, a jeszcze wcześniej sam rodzic 11538 z własnym SKU (AW15...) zapisany
// przez stare odświeżanie katalogu - bez woo_variation_id.
$pdo->exec("INSERT INTO products (id, sku, name, source_integration_id, woo_product_id, woo_variation_id) VALUES
    (10,'AW15LBP1W4VM','Grzane Wino Świeca Sojowa',1,11538,NULL),
    (20,'AW61Y4F3F89C','Hot Wine',1,23230,NULL),
    (21,'AWBRTIPH9UFW','Hot Wine',1,23238,NULL),
    (22,'AW9BAFYA8384','Hot Wine',1,23242,NULL),
    (30,'NSOS61HW100B','Grzane Wino Świeca Sojowa',1,11538,11540),
    (31,'NSOS61HW100A','Grzane Wino Świeca Sojowa',1,11538,11539),
    (35,'V-OLD','Lawenda',1,500,502),
    (40,'OTHER-SHOP','Grzane Wino',2,11538,11541),
    (41,'V-NEW','Lawenda',1,500,501),
    (50,'MANUAL','Hot Wine ręczny',NULL,NULL,NULL)");

$repo = new ProductRepository($pdo);
$rows = $repo->paged(30, 0);
check(array_column($rows, 'sku') === ['MANUAL', 'V-NEW', 'V-OLD', 'OTHER-SHOP', 'AW15LBP1W4VM', 'NSOS61HW100A', 'NSOS61HW100B', 'AW9BAFYA8384', 'AWBRTIPH9UFW', 'AW61Y4F3F89C'],
    'variants of one product together (newest group first), Woo main product on top of its group; plain products separate; same Woo id in another shop separate');

$keys = array_combine(array_column($rows, 'sku'), array_map([ProductRepository::class, 'variantGroupKey'], $rows));
check($keys['NSOS61HW100A'] === '1:11538' && $keys['NSOS61HW100B'] === '1:11538' && $keys['OTHER-SHOP'] === '2:11538',
    'group key = shop + parent id for real variants');
check($keys['AW15LBP1W4VM'] === '' && $keys['AW9BAFYA8384'] === '' && $keys['MANUAL'] === '', 'parent row and plain products are not variants');

$parents = $repo->variableParentIds($rows);
check($parents === [10 => true], 'only the Woo parent with variants is flagged');

// Wariant „Grzane Wino” pasuje do „hot wine” przez rozszerzenie (zapach #8 Hot Wine) - $orIds.
$skus = array_column($repo->paged(30, 0, 'hot wine', null, null, null, null, null, false, [10, 30, 31]), 'sku');
check($skus === ['MANUAL', 'AW15LBP1W4VM', 'NSOS61HW100A', 'NSOS61HW100B', 'AW9BAFYA8384', 'AWBRTIPH9UFW', 'AW61Y4F3F89C'],
    'search: main product and its two variants together, the rest separate');

check(WooProductImportHandler::isVariableParent(['type' => 'variable', 'sku' => 'AW15LBP1W4VM'])
    && !WooProductImportHandler::isVariableParent(['type' => 'variable', '__is_variation' => true])
    && !WooProductImportHandler::isVariableParent(['type' => 'simple']), 'catalog refresh recognises a variable parent and skips it');

echo "\n{$checks} checks passed\n";
