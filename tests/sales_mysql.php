<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Explicit opt-in. Only loopback server, random new DB; never loads .env or application config.
if (getenv('CRM_TEST_MYSQL') !== '1') {
    fwrite(STDERR, "NOT RUN: set CRM_TEST_MYSQL=1 and dedicated local test MySQL credentials.\n");
    exit(2);
}
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    }
});
use Pase\Support\Migrator;
use Pase\Repository\WooOrderRepository;
use Pase\Handlers\WooOrderImportHandler;
use Pase\Queue\Queue;

$checks = 0;
function check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    ++$checks; echo "OK: $label\n";
}
$port = (int) (getenv('CRM_TEST_MYSQL_PORT') ?: 3306);
if ($port < 1 || $port > 65535) { throw new RuntimeException('Invalid test port'); }
$pdo = new PDO('mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4',
    getenv('CRM_TEST_MYSQL_USER') ?: 'root', getenv('CRM_TEST_MYSQL_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$database = 'crm_test_' . bin2hex(random_bytes(12));
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
try {
    $pdo->exec("USE `$database`");
    (new Migrator($pdo))->migrate(); (new Migrator($pdo))->migrate();
    \Pase\Services\WarehouseLocations::migrate($pdo); \Pase\Services\WarehouseLocations::migrate($pdo);
    \Pase\Repository\AllegroFieldTemplateRepository::migrate($pdo); \Pase\Repository\AllegroFieldTemplateRepository::migrate($pdo);
    check((int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'products' AND column_name = 'location_id'")->fetchColumn() === 1,
        'Warehouse locations migration can run again (locations.php opens after the first visit)');
    \Pase\Services\LowStock::migrate($pdo); \Pase\Services\LowStock::migrate($pdo);
    $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('GS1_API_LOGIN', 'api-user'), ('GS1_API_PASSWORD', 'secret'), ('GS1_COMPANY_PREFIX', '590123450')");
    \Pase\Services\Gs1::migrate($pdo); \Pase\Services\Gs1::migrate($pdo);
    $gs1acc = (new \Pase\Repository\IntegrationAccountRepository($pdo))->activeByType('gs1');
    check(count($gs1acc) === 1 && $gs1acc[0]['config']['prefix'] === '590123450'
        && (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key LIKE 'GS1\\_%' AND setting_key <> 'GS1_LAST_SYNC'")->fetchColumn() === 0,
        'GS1: old settings moved once into the plugin account on MySQL');
    $pdo->exec("INSERT INTO products (sku, name, ean) VALUES ('GS1-1', 'Z kartą', '05901234500012'), ('GS1-2', 'Bez EAN', NULL)");
    $pdo->exec("INSERT INTO gs1_products (gtin, name, status, synced_at) VALUES ('5901234500012', 'Karta', 'ACT', NOW())");
    $gs1ov = (new \Pase\Services\Gs1($pdo))->overview();
    check(count($gs1ov['cards']) === 1 && $gs1ov['cards'][0]['product_sku'] === 'GS1-1' && in_array('GS1-2', array_column($gs1ov['without_ean'], 'sku'), true)
        && (new \Pase\Services\Gs1($pdo))->cardFor('5901234500012') !== null, 'GS1: table created once, EAN matching (also with leading zero) on MySQL');
    $pdo->exec("DELETE FROM products WHERE sku LIKE 'GS1-%'");
    \Pase\Services\OrderPayment::migrate($pdo); \Pase\Services\OrderPayment::migrate($pdo);
    $pdo->exec("INSERT INTO products (sku, name, pase_stock, min_stock) VALUES ('LOW-1', 'Niski', 2, NULL), ('LOW-2', 'Z minimum', 8, 10), ('LOW-3', 'Dość', 9, NULL)");
    $low = (new \Pase\Services\LowStock($pdo))->list();
    check($low['count'] === 2 && $low['rows'][0]['sku'] === 'LOW-1', 'Low stock: min_stock column added once, query with native prepares on MySQL');
    $pdo->exec("DELETE FROM products WHERE sku LIKE 'LOW-%'");
    // Stan faktyczny na natywnych prepared statements (dwa różne parametry ilości, porównanie dat).
    $pdo->exec("INSERT INTO products (sku, name, pase_stock) VALUES ('ACT-1', 'Faktyczny', 50)");
    $products = new \Pase\Repository\ProductRepository($pdo);
    $actId = (int) $pdo->query("SELECT id FROM products WHERE sku = 'ACT-1'")->fetchColumn();
    $products->setActualStock($actId, 6);
    \Pase\Services\ActualStock::setEnabled($pdo, true);
    $products->decrementStockBySku('ACT-1', 2);
    (new \Pase\Services\ActualStock($pdo))->applyWooOrder(1, 42, ['status' => 'processing', 'date_created_gmt' => gmdate('Y-m-d\TH:i:s', time() + 60),
        'line_items' => [['sku' => 'ACT-1', 'quantity' => 1]]]);
    $act = $pdo->query("SELECT pase_stock, actual_stock FROM products WHERE sku = 'ACT-1'")->fetch(PDO::FETCH_ASSOC);
    check((int) $act['pase_stock'] === 48 && (int) $act['actual_stock'] === 3 && array_column((new \Pase\Services\LowStock($pdo))->rows(), 'sku') === ['ACT-1'],
        'Actual stock on MySQL: Allegro and shop sales, low stock from actual');
    \Pase\Services\ActualStock::setEnabled($pdo, false);
    $pdo->exec("DELETE FROM products WHERE sku = 'ACT-1'");
    // TikTok Shop: tabele (kolacja jak reszta), zapis zamówienia z numerem z zakresu TikTok, stan i kolejka, JOIN SKU z products.
    \Pase\Services\TiktokShop::migrate($pdo); \Pase\Services\TiktokShop::migrate($pdo);
    $pdo->exec("INSERT INTO products (sku, name, pase_stock) VALUES ('TT-1', 'TikTok', 5)");
    $ttAcc = ['id' => (new \Pase\Repository\IntegrationAccountRepository($pdo))->create('tiktokshop', 'TikTok', ['app_key' => 'k', 'app_secret' => 's']), 'config' => []];
    $ttSvc = new \Pase\Services\TiktokShop($pdo, null, new \Pase\Queue\Queue($pdo));
    $ttOrder = ['id' => '576000000000000001', 'status' => 'AWAITING_SHIPMENT', 'create_time' => time(), 'update_time' => time(), 'payment' => ['currency' => 'PLN', 'total_amount' => '20.00'],
        'recipient_address' => ['name' => 'Anna Nowak'], 'line_items' => [['id' => 'L1', 'sku_id' => 'S1', 'seller_sku' => 'TT-1', 'product_name' => 'Świeca', 'sale_price' => '10.00'],
        ['id' => 'L2', 'sku_id' => 'S1', 'seller_sku' => 'TT-1', 'product_name' => 'Świeca', 'sale_price' => '10.00']]];
    $ttId = $ttSvc->importOrder($ttAcc, $ttOrder); $ttSvc->importOrder($ttAcc, $ttOrder);
    $pdo->exec("INSERT INTO tiktokshop_skus (integration_id, tt_product_id, tt_sku_id, seller_sku, warehouse_id) VALUES ({$ttAcc['id']}, 'P1', 'S1', 'TT-1', 'W1')");
    check($ttId === \Pase\Services\TiktokShop::ID_MIN && (int) $pdo->query("SELECT pase_stock FROM products WHERE sku = 'TT-1'")->fetchColumn() === 3
        && (int) $pdo->query("SELECT COUNT(*) FROM job_queue WHERE job_type = 'woo.stock.push'")->fetchColumn() === 1
        && $ttSvc->status(['id' => $ttAcc['id']])['skus_linked'] === 1 && (int) $pdo->query('SELECT pase_number FROM woo_orders WHERE woo_order_id = ' . $ttId)->fetchColumn() > 0,
        'TikTok Shop on MySQL: tables created once, order imported once with stock taken and shop push queued, SKU joined with products');
    $pdo->exec('DELETE FROM woo_orders WHERE woo_order_id = ' . $ttId);
    $pdo->exec("DELETE FROM products WHERE sku = 'TT-1'");
    $pdo->exec('DELETE FROM job_queue');
    $dash = new \Pase\Services\AllegroDashboard($pdo);
    $day = ['resultFor' => '2026-09-26', 'score' => 140, 'maxScore' => 400, 'grade' => 'GOOD', 'metrics' => [['code' => 'FAST_DISPATCH', 'name' => 'Szybka wysyłka', 'score' => 0, 'maxScore' => 160]]];
    $dash->saveQuality([$day]); $dash->saveQuality([['score' => 150] + $day]);
    $qh = $dash->qualityHistory();
    check(count($qh) === 1 && $qh[0]['score'] === 150 && $qh[0]['metrics'][0]['name'] === 'Szybka wysyłka', 'Allegro sales quality day upserted on MySQL');
    $repo = new WooOrderRepository($pdo);
    $import = new WooOrderImportHandler($repo);
    $payload = ['id' => 777, 'number' => 'TEST-777', 'status' => 'processing', 'total' => '49.90',
        'currency' => 'PLN', 'date_created_gmt' => '2026-01-01T12:00:00',
        'billing' => ['first_name' => 'Test', 'last_name' => 'Buyer', 'email' => 'test@example.invalid'],
        'line_items' => [['name' => 'Test', 'quantity' => 1, 'total' => '49.90']]];
    $import->handle($payload);
    $first = $repo->find(777);
    check($first !== null && (float) $first['total'] === 49.90, 'Woo import stores order');
    check((int) $first['pase_number'] > 0 && strlen($first['client_token']) === 48, 'CRM number and client token assigned');
    $repo->updateLocalContact(777, 'override@example.invalid', '123');
    $repo->updateLocalItems(777, [['name' => 'Local', 'quantity' => 2, 'price' => 5, 'total' => 10]]);
    $payload['total'] = '59.90'; $payload['status'] = 'completed';
    $import->handle($payload); $second = $repo->find(777);
    check((int) $pdo->query('SELECT COUNT(*) FROM woo_orders')->fetchColumn() === 1, 'Repeated import is idempotent');
    check($second['client_token'] === $first['client_token'] && $second['pase_number'] === $first['pase_number'], 'Reimport preserves token and number');
    check($second['pase_status'] === $first['pase_status'] && $second['status'] === 'completed', 'Channel status does not overwrite CRM status');
    check($second['local_billing_email'] === 'override@example.invalid' && $second['local_items'] !== null, 'Reimport preserves local edits');
    $cust = new \Pase\Services\Customers($pdo);
    $import->handle(['id' => 779, 'number' => 'TEST-779', 'status' => 'processing', 'total' => '10.00', 'currency' => 'PLN',
        'date_created_gmt' => '2026-02-01T12:00:00', 'billing' => ['first_name' => 'Test', 'last_name' => 'Buyer', 'email' => 'OVERRIDE@example.invalid']]);
    $sync = $cust->syncPending();
    check($sync['assigned'] === 2 && $sync['pending'] === 0 && !$sync['busy'], 'Customers: orders assigned on MySQL (lock, native prepares)');
    $cid = $cust->customerIdForOrder(777);
    check($cid !== null && $cust->customerIdForOrder(779) === $cid && $cust->find($cid)['orders_count'] === 2, 'Customers: same email (local correction) = one customer on MySQL');
    check($cust->list('override')['total'] === 1 && $cust->findByIdentifier('TEST-779') === $cid, 'Customers: search on MySQL');
    $cust->refreshOrder(777);
    check($cust->customerIdForOrder(777) === $cust->customerIdForOrder(779), 'Customers: refresh after contact edit on MySQL');
    $pdo->exec('DELETE FROM woo_orders WHERE woo_order_id = 779');
    $cust->syncPending();
    $cid = $cust->customerIdForOrder(777);
    check($cust->find($cid)['orders_count'] === 2 && count($cust->ordersOf($cid)) === 2, 'Customers: history kept on MySQL after an order is deleted');
    $cust->resetAll(); $cust->syncPending();
    check($cust->find($cust->customerIdForOrder(777))['orders_count'] === 2, 'Customers: recalculation from snapshots on MySQL');
    // Marża: migracja drugi raz, migawki, lokalna edycja (DATETIME vs tekst) i opłaty (upsert) na MySQL.
    \Pase\Services\OrderMargins::migrate($pdo);
    $pdo->exec("INSERT INTO products (sku, name) VALUES ('MARGIN-SKU', 'Marża test')");
    $margins = new \Pase\Services\OrderMargins($pdo, 23);
    $margins->syncPending();
    $pdo->exec("UPDATE woo_orders SET local_edited_at = '2026-09-26 10:00:00', local_items = '[{\"sku\":\"MARGIN-SKU\",\"quantity\":2,\"total\":24.6}]' WHERE woo_order_id = 777");
    check($margins->syncPending() === 1 && $margins->syncPending() === 0 && $margins->pendingCount() === 0, 'Margins: snapshot and local edit refresh settle on MySQL');
    $pid = (int) $pdo->query("SELECT id FROM products WHERE sku = 'MARGIN-SKU'")->fetchColumn();
    check($margins->setCosts([$pid => 5.0]) === 1 && (float) $pdo->query('SELECT unit_cost FROM margin_lines WHERE woo_order_id = 777')->fetchColumn() === 5.0, 'Margins: missing cost filled on MySQL');
    $fee = [['id' => 'e1', 'order' => ['id' => 'cf'], 'type' => ['id' => 'SUC', 'name' => 'Prowizja'], 'value' => ['amount' => '-1.23', 'currency' => 'PLN']]];
    $margins->saveAllegroFees($fee); $margins->saveAllegroFees($fee);
    check((int) $pdo->query('SELECT COUNT(*) FROM allegro_order_fees')->fetchColumn() === 1, 'Margins: Allegro fee upsert on MySQL');
    $mr = $margins->report('2026-01-01', '2026-12-31');
    check($mr['total']['orders'] === 1 && abs($mr['total']['margin'] - 10.0) < 0.01, 'Margins: report query on MySQL');
    $import->handle(['id' => 778, 'meta_data' => [['key' => 'pase_source_order', 'value' => 777]]]);
    check($repo->find(778) === null, 'Forwarded order not imported twice');
    $token = (new \Pase\Services\DocumentIssueGuard($pdo))->reserve(777, 'receipt', 1);
    $blocked = false;
    try { (new \Pase\Services\DocumentIssueGuard($pdo))->reserve(777, 'receipt', 1); } catch (RuntimeException) { $blocked = true; }
    check($blocked, 'MySQL durable reservation blocks duplicate');
    $snapshotDir = sys_get_temp_dir() . '/crm-mysql-backup-' . bin2hex(random_bytes(8));
    mkdir($snapshotDir, 0700);
    try {
        $meta = \Pase\Services\DatabaseSnapshot::export($pdo, $snapshotDir . '/database.jsonl');
        $counts = \Pase\Services\BackupRestoreCheck::mysql($snapshotDir . '/database.jsonl');
        check($counts === $meta['tables'], 'Full MySQL backup restored to isolated database with matching table counts');
    } finally {
        if (is_file($snapshotDir . '/database.jsonl')) { unlink($snapshotDir . '/database.jsonl'); }
        rmdir($snapshotDir);
    }
    $import->handle([]);
    check((int) $pdo->query('SELECT COUNT(*) FROM woo_orders')->fetchColumn() === 1, 'Invalid import ignored');
    $queue = new Queue($pdo);
    check($queue->enqueue('woo.order.import', $payload, 'test-order'), 'Queue accepts first webhook');
    check(!$queue->enqueue('woo.order.import', $payload, 'test-order'), 'Queue deduplicates webhook');
    $job = $queue->reserveNext();
    check($job !== null && $queue->reserveNext() === null, 'Reserved job cannot be reserved again');
    $queue->fail($job, 'test retry');
    check($queue->reserveNext() === null, 'Retry respects backoff');
    $pdo->exec("UPDATE job_queue SET available_at = UTC_TIMESTAMP() WHERE status = 'pending'");
    $job = $queue->reserveNext(); $queue->fail($job, 'test retry 2');
    $pdo->exec("UPDATE job_queue SET available_at = UTC_TIMESTAMP() WHERE status = 'pending'");
    $job = $queue->reserveNext(); $queue->fail($job, 'test terminal failure');
    check($pdo->query('SELECT status FROM job_queue')->fetchColumn() === 'failed', 'Retry limit produces failed job');
    check($queue->reviveFailed('test-order', $payload), 'Operator can revive failed job');
    $job = $queue->reserveNext(); $queue->complete((int) $job['id']);
    check($pdo->query('SELECT status FROM job_queue')->fetchColumn() === 'done', 'Successful processing completes job');
    $repo->setLifecycle(777, 'trashed');
    check($repo->emptyTrash() === 1, 'Trash removal on MySQL');
    check((int) $pdo->query('SELECT COUNT(*) FROM audit_events WHERE order_id = 777')->fetchColumn() === 5, 'Audit survives MySQL order deletion');
    echo "PASS: $checks MySQL sales checks\n";
} finally {
    // Exact DB created above, generated internally, never user-supplied.
    $pdo->exec("DROP DATABASE `$database`");
}
