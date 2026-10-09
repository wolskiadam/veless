<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Zdjęcia produktu głównego przy wariantach (Services\VariantParentImages) - SQLite, bez sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Handlers\WooProductImportHandler;
use Pase\Repository\ProductRepository;
use Pase\Services\OrderItemThumbnails;
use Pase\Services\VariantParentImages as V;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT UNIQUE, name TEXT, images TEXT, source_integration_id INT, woo_product_id INT, woo_variation_id INT)');
V::migrate($pdo);
V::migrate($pdo);
check(in_array('parent_images', array_column($pdo->query('PRAGMA table_info(products)')->fetchAll(), 'name'), true), 'products.parent_images added once');

$parent = ['id' => 10, 'images' => [['src' => 'https://s.test/glowne.jpg'], ['src' => 'https://s.test/braz.jpg'], ['src' => ''], ['src' => 'https://s.test/galeria.jpg']]];
$v = WooProductImportHandler::variationPayload(['id' => 11, 'image' => ['src' => 'https://s.test/braz.jpg']], $parent, 1);
check($v['images'] === [['src' => 'https://s.test/braz.jpg']] && $v['__parent_images'] === ['https://s.test/glowne.jpg', 'https://s.test/braz.jpg', 'https://s.test/galeria.jpg'],
    'variation keeps its own photo, parent photos carried separately');

$g = V::gallery(['https://s.test/braz.jpg?v=2'], $v['__parent_images']);
check(array_column($g, 'src') === ['https://s.test/braz.jpg?v=2', 'https://s.test/glowne.jpg', 'https://s.test/galeria.jpg'] && array_column($g, 'parent') === [false, true, true],
    'gallery: own first, parent after, duplicate skipped');
check(V::gallery(['a.jpg'], null) === [['src' => 'a.jpg', 'parent' => false]], 'no parent photos: own only');
check(V::parentThumb('https://s.test/braz.jpg', ['https://s.test/glowne.jpg']) === 'https://s.test/glowne.jpg'
    && V::parentThumb('http://s.test/glowne.jpg', ['https://s.test/glowne.jpg']) === null && V::parentThumb(null, []) === null, 'order badge only when parent main photo differs');

$ins = $pdo->prepare('INSERT INTO products (id, sku, name, images, source_integration_id, woo_product_id, woo_variation_id) VALUES (?,?,?,?,?,?,?)');
$ins->execute([1, 'GB-BRAZ', 'Gingerbread', '["https://s.test/braz.jpg"]', 1, 10, 11]);
$ins->execute([2, 'GB-BIAL', 'Gingerbread', '["https://s.test/bial.jpg"]', 1, 10, 12]);
$ins->execute([3, 'INNY-SKLEP', 'Gingerbread', '["https://s.test/x.jpg"]', 2, 10, 13]);
$ins->execute([4, 'ZWYKLY', 'Świeca', '["https://s.test/zwykly.jpg"]', 1, 20, null]);

(new ProductRepository($pdo))->setParentImages('GB-BRAZ', $v['__parent_images']);
check(V::decode($pdo->query('SELECT parent_images FROM products WHERE id = 1')->fetchColumn()) === $v['__parent_images'], 'import stores parent photos for the variant');
check($pdo->query('SELECT images FROM products WHERE id = 1')->fetchColumn() === '["https://s.test/braz.jpg"]', 'variant own photos (used for Allegro) unchanged');

// Uzupełnianie: jedno pobranie rodzica na sklep, zapis dla wszystkich jego wariantów z tego sklepu.
$calls = [];
$fake = new class($calls) { public function __construct(public array &$calls) {}
    public function fetchProduct(int $id): ?array { $this->calls[] = $id; return ['id' => $id, 'images' => [['src' => "https://s.test/rodzic-$id.jpg"]]]; } };
$svc = new V($pdo, static fn(int $int) => $int === 1 ? $fake : null);
$rows = $pdo->query('SELECT * FROM products WHERE id IN (2, 3, 4)')->fetchAll();
$done = $svc->backfill($rows);
check($calls === [10] && array_keys($done) === [2], 'backfill: one shop call per parent, unknown shop and plain product skipped');
check(V::decode($pdo->query('SELECT parent_images FROM products WHERE id = 1')->fetchColumn()) === ['https://s.test/rodzic-10.jpg']
    && $pdo->query('SELECT parent_images FROM products WHERE id = 3')->fetchColumn() === null
    && $pdo->query('SELECT parent_images FROM products WHERE id = 4')->fetchColumn() === null, 'stored for sibling variants of the same shop only');
$svc->backfill($pdo->query('SELECT * FROM products WHERE id = 2')->fetchAll());
check($calls === [10], 'already stored: no second shop call');
$down = new V($pdo, static fn() => new class { public function fetchProduct(int $id): ?array { return null; } });
$pdo->exec('UPDATE products SET parent_images = NULL WHERE id = 3');
check($down->backfill($pdo->query('SELECT * FROM products WHERE id = 3')->fetchAll()) === [] && $pdo->query('SELECT parent_images FROM products WHERE id = 3')->fetchColumn() === null,
    'shop down: nothing stored, retried next time');

// Miniatury zamówienia: zdjęcie wariantu zostaje, obok główne zdjęcie produktu głównego.
$items = [['sku' => 'GB-BRAZ', 'image' => 'https://s.test/braz.jpg', 'product_id' => 999], ['sku' => 'ZWYKLY'], ['sku' => 'BRAK']];
$t = new OrderItemThumbnails($pdo);
check($t->forItems($items) === ['https://s.test/braz.jpg', 'https://s.test/zwykly.jpg', null] && $t->parentThumbs === [0 => 'https://s.test/rodzic-10.jpg'],
    'order thumbnails: variant photo kept, parent photo added, plain product untouched');
$pdo->exec("UPDATE products SET parent_images = NULL, images = '[]' WHERE id = 3");
$t2 = new OrderItemThumbnails($pdo, null, $svc);
$t2->forItems([['sku' => 'INNY-SKLEP']]);
check($t2->parentThumbs === [], 'variant from shop without client: no badge, no error');
$t2->forItems([['sku' => 'GB-BIAL']]);
check($t2->parentThumbs === [0 => 'https://s.test/rodzic-10.jpg'], 'parent badges reset between calls');

echo "\nAll {$checks} checks passed.\n";
