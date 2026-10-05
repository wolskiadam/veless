<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Alerty niskiego stanu: minimum per produkt, próg domyślny, filtr listy produktów, dzwoneczek - SQLite, bez sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Repository\ProductRepository;
use Pase\Services\LowStock;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE settings (setting_key VARCHAR(64) PRIMARY KEY, setting_value TEXT NULL)');
$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, pase_stock INT NULL, pase_price REAL NULL,
    source_integration_id INT NULL, woo_product_id INT NULL, categories TEXT NULL)');
$pdo->exec("INSERT INTO products (id, sku, name, pase_stock) VALUES
    (1,'GB-1L','Gingerbread 1l',3),(2,'MW-1L','Mulled Wine 1l',12),(3,'CC-1L','Clementine 1l',0),(4,'SLC-500','Lavender 500 ml',NULL),(5,'VAN-1L','Vanilla 1l',5)");
LowStock::migrate($pdo);
LowStock::migrate($pdo); // drugi raz bez błędu
check(in_array('min_stock', array_column($pdo->query('PRAGMA table_info(products)')->fetchAll(), 'name'), true), 'products.min_stock added once');

$low = new LowStock($pdo);
check($low->defaultThreshold() === 5, 'default threshold starts at 5 (the old fixed value on the product list)');
$l = $low->list();
check($l['count'] === 3 && array_column($l['rows'], 'sku') === ['CC-1L', 'GB-1L', 'VAN-1L'], 'at or below 5, lowest first; product without stock skipped');

$low->setMin(2, 15);   // Mulled Wine: własne minimum 15, stan 12 -> niski
$low->setMin(1, 2);    // Gingerbread: własne minimum 2, stan 3 -> OK
check(array_column($low->list()['rows'], 'sku') === ['CC-1L', 'VAN-1L', 'MW-1L'], 'own minimum overrides the default both ways');
$p = $pdo->query('SELECT * FROM products WHERE id = 2')->fetch();
check(LowStock::isLow($p, 5) && LowStock::minFor($p, 5) === 15 && !LowStock::isLow(['pase_stock' => null, 'min_stock' => 3], 5), 'row helpers for the list and product page');

$low->saveDefaultThreshold(null);
check($low->defaultThreshold() === null && array_column($low->list()['rows'], 'sku') === ['MW-1L'], 'default switched off: only products with their own minimum');
$low->saveDefaultThreshold(0);
check($low->defaultThreshold() === 0 && array_column($low->list()['rows'], 'sku') === ['CC-1L', 'MW-1L'], 'default 0 alerts only on sold-out products');
$low->saveDefaultThreshold(5);
$low->setMin(2, null);
check(array_column($low->list()['rows'], 'sku') === ['CC-1L', 'VAN-1L'], 'cleared minimum falls back to the default');

// Filtr „Niski stan” na liście produktów (stock_op=low, liczba = próg domyślny).
$repo = new ProductRepository($pdo);
$params = [];
$where = 'WHERE ' . ProductRepository::stockCondition('low', 5, $params);
check($repo->count($where, $params) === 2 && count($repo->paged(30, 0, '', null, null, 'low', 5)) === 2, 'product list filter "Niski stan" matches the bell');
check(count($repo->paged(30, 0, '', null, null, 'low', null)) === 0, 'filter with default off and no own minimums is empty');

// Dzwoneczek i ikona przy tytule.
$notif = new Pase\Services\Notifications($pdo);
$notif->savePrefs(1, ['low_stock' => '1']);   // pozostałe źródła potrzebują tabel spoza testu
$bell = $notif->collect(1, static fn() => null);
$g = array_column($bell['items'], null, 'key')['low_stock'] ?? null;
check($g !== null && $bell['total'] === 2 && $g['short'] === 'Niski stan' && $g['link'] === 'products.php?stock_op=low', 'bell group with link to the filtered list');
check(str_contains($g['details'][0]['text'], 'CC-1L') && str_contains($g['details'][0]['text'], 'stan 0') && $g['details'][0]['link'] === 'product_view.php?id=3', 'bell rows show SKU, stock and link to the product');
// „Odczytaj wszystkie”: obecne produkty znikają, nowy spadek wraca, uzupełniony i znów niski też wraca.
$notif->markRead(1, 'all', static fn() => null);
check(!isset(array_column($notif->collect(1, static fn() => null)['items'], null, 'key')['low_stock']), 'read all clears the low stock group');
$pdo->exec('UPDATE products SET pase_stock = 1 WHERE id = 1');   // Gingerbread: minimum 2, spada do 1
$g = array_column($notif->collect(1, static fn() => null)['items'], null, 'key')['low_stock'] ?? null;
check($g !== null && $g['count'] === 1 && str_contains($g['details'][0]['text'], 'GB-1L'), 'a product that drops after reading shows up again');
$notif->markRead(1, 'low_stock', static fn() => null);
$pdo->exec('UPDATE products SET pase_stock = 20 WHERE id = 3');
$notif->collect(1, static fn() => null);                          // CC-1L wypada z listy odczytanych
$pdo->exec('UPDATE products SET pase_stock = 0 WHERE id = 3');
$g = array_column($notif->collect(1, static fn() => null)['items'], null, 'key')['low_stock'] ?? null;
check($g !== null && $g['count'] === 1 && str_contains($g['details'][0]['text'], 'CC-1L'), 'restocked and sold out again shows up again');
check($notif->collect(2, static fn() => null)['total'] === 3, 'reading is per user');

// „Nie pilnuj stanu”: produkt nigdy nie jest niski; hurtem dla wyprzedanych bez własnego minimum.
$low->setMin(3, LowStock::OFF);
$params = [];
check(array_column($low->rows(), 'sku') === ['GB-1L', 'VAN-1L'] && !LowStock::isLow(['pase_stock' => 0, 'min_stock' => -1], 5)
    && $repo->count('WHERE ' . ProductRepository::stockCondition('untracked', null, $params), $params) === 1, 'untracked product never low, listed under "Nie pilnowane"');
$pdo->exec("INSERT INTO products (id, sku, name, pase_stock) VALUES (6, 'OLD-1', 'Wycofany', 0), (7, 'OLD-2', 'Wycofany z minimum', 0)");
$low->setMin(7, 3);
check($low->untrackSoldOut() === 1 && (int) $pdo->query('SELECT min_stock FROM products WHERE id = 6')->fetchColumn() === -1
    && (int) $pdo->query('SELECT min_stock FROM products WHERE id = 7')->fetchColumn() === 3, 'bulk untrack only sold-out products without own minimum');

$pdo->exec('UPDATE products SET pase_stock = 20 WHERE id IN (1, 3, 5, 7)');
check(!isset(array_column($notif->collect(1, static fn() => null)['items'], null, 'key')['low_stock']), 'restocked products leave the bell');
$notif->savePrefs(1, []);
check($notif->collect(1, static fn() => null)['total'] === 0, 'low stock group can be switched off');

echo "\n$checks checks passed\n";
