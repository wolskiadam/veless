<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Stan faktyczny (products.actual_stock): tylko w CRM, schodzi przy sprzedaży, wraca przy anulowaniu - SQLite, bez sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Repository\ProductRepository;
use Pase\Services\ActualStock;
use Pase\Services\LowStock;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('GREATEST', 'max', 2);   // MySQL
$pdo->exec('CREATE TABLE settings (setting_key VARCHAR(64) PRIMARY KEY, setting_value TEXT NULL)');
$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, pase_stock INT NULL, pase_price REAL NULL,
    source_integration_id INT NULL, woo_product_id INT NULL, categories TEXT NULL)');
$pdo->exec("INSERT INTO products (id, sku, name, pase_stock) VALUES (1,'GB-1L','Gingerbread 1l',50),(2,'MW-1L','Mulled Wine 1l',40),(3,'CC-1L','Clementine 1l',30)");
LowStock::migrate($pdo);
LowStock::migrate($pdo);   // drugi raz bez błędu
$cols = array_column($pdo->query('PRAGMA table_info(products)')->fetchAll(), 'name');
check(in_array('actual_stock', $cols, true) && in_array('actual_stock_set_at', $cols, true), 'columns added once');

$repo = new ProductRepository($pdo);
$stock = static fn(int $id): array => $pdo->query("SELECT pase_stock, actual_stock FROM products WHERE id = {$id}")->fetch();
check($stock(1)['actual_stock'] === null, 'actual stock starts empty (not counted yet)');
$repo->setActualStock(1, 4);
$repo->setActualStock(2, 10);
check((int) $stock(1)['actual_stock'] === 4 && (int) $stock(1)['pase_stock'] === 50, 'setting actual stock leaves the shown stock alone');

// Sprzedaż na Allegro: oba stany schodzą, pusty faktyczny zostaje pusty.
$repo->decrementStockBySku('GB-1L', 3);
$repo->decrementStockBySku('CC-1L', 2);
check((int) $stock(1)['pase_stock'] === 47 && (int) $stock(1)['actual_stock'] === 1, 'Allegro sale lowers both stocks');
check((int) $stock(3)['pase_stock'] === 28 && $stock(3)['actual_stock'] === null, 'Allegro sale leaves an empty actual stock empty');
$repo->decrementStockBySku('GB-1L', 5);
check((int) $stock(1)['actual_stock'] === 0, 'actual stock does not go below zero');

// Sprzedaż w sklepie Woo: tylko stan faktyczny, raz na zamówienie, anulowanie oddaje.
$svc = new ActualStock($pdo);
$now = gmdate('Y-m-d\TH:i:s', time() + 60);
$order = ['status' => 'processing', 'date_created_gmt' => $now, 'line_items' => [
    ['sku' => 'MW-1L', 'quantity' => 3], ['sku' => 'CC-1L', 'quantity' => 1], ['sku' => '', 'quantity' => 1], ['sku' => 'UNKNOWN', 'quantity' => 1]]];
check($svc->applyWooOrder(7, 1001, $order) === 1, 'shop order: only products with actual stock counted');
check((int) $stock(2)['actual_stock'] === 7 && (int) $stock(2)['pase_stock'] === 40, 'shop order lowers actual stock only');
check($svc->applyWooOrder(7, 1001, ['status' => 'completed'] + $order) === 0 && (int) $stock(2)['actual_stock'] === 7, 'status update of the same order does not subtract again');
check($svc->applyWooOrder(8, 1001, $order) === 1 && (int) $stock(2)['actual_stock'] === 4, 'same order number in another shop is a different order');
check($svc->applyWooOrder(7, 1001, ['status' => 'cancelled'] + $order) === 1 && (int) $stock(2)['actual_stock'] === 7, 'cancelled order gives the units back');
check($svc->applyWooOrder(7, 1001, ['status' => 'cancelled'] + $order) === 0 && (int) $stock(2)['actual_stock'] === 7, 'second cancel changes nothing');
check($svc->applyWooOrder(7, 1001, ['status' => 'processing'] + $order) === 0 && (int) $stock(2)['actual_stock'] === 7, 'reopened order is not subtracted a second time');
check($svc->applyWooOrder(7, 1002, ['status' => 'cancelled'] + $order) === 0 && (int) $stock(2)['actual_stock'] === 7, 'order first seen as cancelled takes nothing');

// Zamówienia sprzed wpisania stanu faktycznego (np. import historii) są już w tej liczbie.
$old = ['status' => 'completed', 'date_created_gmt' => '2020-01-01T10:00:00', 'line_items' => [['sku' => 'MW-1L', 'quantity' => 2]]];
check($svc->applyWooOrder(7, 500, $old) === 0 && (int) $stock(2)['actual_stock'] === 7, 'order placed before the stock was counted is skipped');

// Pole wyłączone (domyślnie): alerty liczą tylko ze stanu w CRM, zapisane liczby zostają.
$low = new LowStock($pdo);
check(!ActualStock::enabled($pdo) && !$low->usesActualStock(), 'actual stock field is off by default');
check($low->rows() === [] && !LowStock::isLow(['pase_stock' => 50, 'actual_stock' => 2, 'min_stock' => null], 5), 'off: low stock ignores actual stock');
$params = [];
check($repo->count('WHERE ' . ProductRepository::stockCondition('low', 5, $params, $low->usesActualStock()), $params) === 0, 'off: product list filter ignores actual stock');

// Włączone: alerty liczą ze stanu faktycznego, gdy jest wpisany.
ActualStock::setEnabled($pdo, true);
check(ActualStock::enabled($pdo) && $low->usesActualStock(), 'setting switches the field on');
$rows = array_column($low->rows(), 'sku');
check($rows === ['GB-1L'], 'on: low stock uses actual stock (GB 0 real vs 42 shown; MW 7; CC shown 28)');
$params = [];
check(count($repo->paged(30, 0, '', null, null, 'low', 5, null, true)) === 1, 'on: product list filter matches the bell');
check(LowStock::isLow(['pase_stock' => 50, 'actual_stock' => 2, 'min_stock' => null], 5, true) && !LowStock::isLow(['pase_stock' => 2, 'actual_stock' => 9, 'min_stock' => null], 5, true), 'row helper prefers actual stock');
check(LowStock::isLow(['pase_stock' => 2, 'actual_stock' => null, 'min_stock' => null], 5, true), 'row helper falls back to the shown stock');
ActualStock::setEnabled($pdo, false);
check($low->rows() === [] && (int) $stock(1)['actual_stock'] === 0, 'switched off again: ignored, stored numbers kept');
ActualStock::setEnabled($pdo, true);

$repo->setActualStock(1, null);
check($stock(1)['actual_stock'] === null && $low->rows() === [], 'clearing actual stock returns to the shown stock');

echo "\nactual_stock: {$checks} checks passed\n";
