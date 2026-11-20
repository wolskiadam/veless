<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Lista produktów: warianty jednego produktu Woo razem, produkty bez rodzica osobno - SQLite, bez sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Repository\ProductRepository;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, pase_stock INT NULL, pase_price REAL NULL,
    source_integration_id INT NULL, woo_product_id INT NULL, categories TEXT NULL, location_id INT NULL)');
// Jak na zrzucie „hot wine”: wariant AW15 dodany dawno (niskie id), dwa nowsze warianty tego samego
// rodzica 11538 i trzy zwykłe produkty Hot Wine o id pomiędzy.
$pdo->exec("INSERT INTO products (id, sku, name, source_integration_id, woo_product_id) VALUES
    (10,'AW15LBP1W4VM','Grzane Wino Świeca Sojowa',1,11538),
    (20,'AW61Y4F3F89C','Hot Wine',1,23230),
    (21,'AWBRTIPH9UFW','Hot Wine',1,23238),
    (22,'AW9BAFYA8384','Hot Wine',1,23242),
    (30,'NSOS61HW100B','Grzane Wino Świeca Sojowa',1,11538),
    (31,'NSOS61HW100A','Grzane Wino Świeca Sojowa',1,11538),
    (40,'OTHER-SHOP','Grzane Wino',2,11538),
    (50,'MANUAL','Hot Wine ręczny',NULL,NULL)");

$repo = new ProductRepository($pdo);
$skus = array_column($repo->paged(30, 0), 'sku');
check($skus === ['MANUAL', 'OTHER-SHOP', 'NSOS61HW100A', 'NSOS61HW100B', 'AW15LBP1W4VM', 'AW9BAFYA8384', 'AWBRTIPH9UFW', 'AW61Y4F3F89C'],
    'all three variants of 11538 together, newest group first; same Woo id in another shop is a separate product');

// Wariant „Grzane Wino” pasuje do „hot wine” przez rozszerzenie (zapach #8 Hot Wine) - $orIds.
$skus = array_column($repo->paged(30, 0, 'hot wine', null, null, null, null, null, false, [10, 30, 31]), 'sku');
check($skus === ['MANUAL', 'NSOS61HW100A', 'NSOS61HW100B', 'AW15LBP1W4VM', 'AW9BAFYA8384', 'AWBRTIPH9UFW', 'AW61Y4F3F89C'],
    'search keeps the variants together too');

$keys = array_map([ProductRepository::class, 'variantGroupKey'], $repo->paged(30, 0));
check($keys[0] === '' && $keys[1] === '2:11538' && $keys[2] === '1:11538' && $keys[4] === '1:11538' && $keys[5] === '1:23242',
    'group key includes the shop; product without Woo id has none');

echo "\n{$checks} checks passed\n";
