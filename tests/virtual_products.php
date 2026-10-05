<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Produkty wirtualne: zamówienie z samymi takimi produktami dostaje „bez wysyłki” - SQLite, bez sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\ManualOrders;
use Pase\Services\VirtualProducts;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE settings (setting_key VARCHAR(64) PRIMARY KEY, setting_value TEXT NULL)');
$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, pase_price REAL, pase_stock INTEGER, actual_stock INTEGER NULL)');
$pdo->exec("CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY, integration_id INTEGER, pase_number INTEGER UNIQUE, order_number TEXT, status TEXT, pase_status TEXT,
    currency TEXT, total REAL, customer_name TEXT, customer_email TEXT, date_created TEXT, client_token TEXT, payload TEXT, local_items TEXT NULL, local_edited_at TEXT NULL,
    local_invoice TEXT NULL, local_billing_email TEXT NULL, local_billing_phone TEXT NULL, lifecycle TEXT NOT NULL DEFAULT 'active', automations_done INT DEFAULT 0, no_shipping INT NULL)");
$pdo->exec('CREATE TABLE audit_events (id INTEGER PRIMARY KEY, order_id INT, actor_id INT NULL, actor_name TEXT, action TEXT, before_json TEXT, after_json TEXT, created_at TEXT)');
$pdo->exec("INSERT INTO products (id, sku, name, pase_price, pase_stock) VALUES (1,'EBOOK','E-book',29,NULL),(2,'KURS','Kurs online',99,NULL),(3,'SW-1L','Świeca 1l',59,10)");

VirtualProducts::migrate($pdo);
VirtualProducts::migrate($pdo);   // drugi raz bez błędu
check(in_array('is_virtual', array_column($pdo->query('PRAGMA table_info(products)')->fetchAll(), 'name'), true), 'products.is_virtual added once');

$v = new VirtualProducts($pdo);
$v->set(1, true);
$v->set(2, true);
check((int) $pdo->query('SELECT is_virtual FROM products WHERE id = 1')->fetchColumn() === 1, 'product marked virtual');

check($v->allVirtual([['sku' => 'EBOOK', 'quantity' => 1], ['sku' => 'KURS', 'quantity' => 2]]), 'only virtual products -> virtual order');
check(!$v->allVirtual([['sku' => 'EBOOK', 'quantity' => 1], ['sku' => 'SW-1L', 'quantity' => 1]]), 'virtual + physical -> needs shipping');
check(!$v->allVirtual([['sku' => 'EBOOK', 'quantity' => 1], ['sku' => '', 'name' => 'Spoza magazynu', 'quantity' => 1]]), 'item without SKU -> needs shipping');
check(!$v->allVirtual([['sku' => 'NIEZNANY', 'quantity' => 1]]), 'item outside warehouse -> needs shipping');
check(!$v->allVirtual([]), 'no items -> not virtual');

$pdo->exec("INSERT INTO woo_orders (woo_order_id, payload) VALUES (10, '{}'), (11, '{}'), (12, '{}')");
$ns = static fn(int $id) => $pdo->query("SELECT no_shipping FROM woo_orders WHERE woo_order_id = $id")->fetchColumn();
check($v->applyToOrder(10, [['sku' => 'EBOOK', 'quantity' => 1]]) && (int) $ns(10) === 1, 'import of virtual-only order ticks "bez wysyłki"');
check((int) $pdo->query("SELECT COUNT(*) FROM audit_events WHERE order_id = 10 AND action = 'order.no_shipping_changed'")->fetchColumn() === 1, 'change in the order history');
check(!$v->applyToOrder(11, [['sku' => 'EBOOK', 'quantity' => 1], ['sku' => 'SW-1L', 'quantity' => 1]]) && $ns(11) === null, 'mixed order left untouched');
$pdo->exec('UPDATE woo_orders SET no_shipping = 0 WHERE woo_order_id = 12');   // odznaczone ręcznie w karcie
check(!$v->applyToOrder(12, [['sku' => 'KURS', 'quantity' => 1]]) && (int) $ns(12) === 0, 'manual choice on the order card is not overridden by a re-import');

// Stare zamówienia: produkt oznaczony jako wirtualny już po imporcie.
$pdo->exec("INSERT INTO products (id, sku, name) VALUES (4, 'VOUCHER/50', 'Bon 50 zł')");
$old = static fn(int $id, array $items, ?string $status = 'new', ?int $noShip = null, ?array $local = null) => $pdo->prepare('INSERT INTO woo_orders (woo_order_id, pase_status, no_shipping, payload, local_items) VALUES (?, ?, ?, ?, ?)')
    ->execute([$id, $status, $noShip, json_encode(['line_items' => $items]), $local === null ? null : json_encode($local, JSON_UNESCAPED_UNICODE)]);
$old(20, [['sku' => 'VOUCHER/50', 'quantity' => 1]]);                                   // oznaczy się
$old(21, [['sku' => 'VOUCHER/50', 'quantity' => 1], ['sku' => 'SW-1L', 'quantity' => 1]]);   // mieszane - nie
$old(22, [['sku' => 'VOUCHER/50', 'quantity' => 1]], 'shipped');                          // już wysłane - nie ruszamy
$old(23, [['sku' => 'VOUCHER/50', 'quantity' => 1]], 'new', 0);                            // odznaczone ręcznie - nie
$old(24, [['sku' => 'SW-1L', 'quantity' => 1]], 'new', null, [['sku' => 'VOUCHER/50', 'quantity' => 2], ['sku' => 'EBOOK', 'quantity' => 1]]);   // edycja w CRM - liczą się lokalne pozycje
$old(25, [['sku' => 'VOUCHER/500', 'quantity' => 1]]);                                  // inny SKU - nie
$v->set(4, true);
check($v->backfillForSku('VOUCHER/50') === 2 && (int) $ns(20) === 1 && (int) $ns(24) === 1, 'old unshipped virtual-only orders get marked (local item edits count)');
check($ns(21) === null && $ns(22) === null && (int) $ns(23) === 0 && $ns(25) === null, 'mixed, shipped, manually unticked and other-SKU orders left alone');
check($v->backfillForSku('VOUCHER/50') === 0 && $v->backfillForSku('') === 0, 'second run changes nothing');

// Zamówienie dodane ręcznie z samymi produktami wirtualnymi.
$svc = new ManualOrders($pdo);
$id = $svc->create($svc->validate(['billing_first_name' => 'Ola', 'item_sku' => ['KURS'], 'item_name' => ['Kurs online'], 'item_price' => ['99'], 'item_qty' => ['1']])['order'], 'Adam');
check((int) $ns($id) === 1, 'manual order with only virtual products is virtual');
$id2 = $svc->create($svc->validate(['billing_first_name' => 'Jan', 'item_sku' => ['KURS', 'SW-1L'], 'item_name' => ['Kurs online', 'Świeca'], 'item_price' => ['99', '59'], 'item_qty' => ['1', '1']])['order'], 'Adam');
check($ns($id2) === null, 'manual order with a physical product needs shipping');

echo "\nvirtual_products: {$checks} checks passed\n";
