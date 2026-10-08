<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Ręczne potwierdzenie wpłaty (Services\OrderPayment): zapis tylko w CRM, wskaźnik płatności,
// scalone zamówienia i powrót do danych ze sklepu - SQLite, bez sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\OrderIndicators;
use Pase\Services\OrderMerge;
use Pase\Services\OrderPayment;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY, pase_number INTEGER, order_number TEXT, status TEXT, pase_status TEXT,
    lifecycle TEXT NOT NULL DEFAULT 'active', currency TEXT, total REAL, date_created TEXT, payload TEXT, local_items TEXT NULL)");
Pase\Services\AuditTrail::migrate($pdo);
OrderMerge::migrate($pdo);
OrderPayment::migrate($pdo);
OrderPayment::migrate($pdo);   // drugi raz bez błędu
$cols = array_column($pdo->query('PRAGMA table_info(woo_orders)')->fetchAll(), 'name');
check(!array_diff(['paid_amount', 'paid_amount_total', 'paid_amount_at', 'paid_amount_by'], $cols), 'payment columns added once');

$unpaid = json_encode(['status' => 'on-hold', 'payment_method' => 'blik', 'date_paid' => null, 'total' => '34.98', 'line_items' => []]);
$ins = $pdo->prepare('INSERT INTO woo_orders (woo_order_id, pase_number, order_number, status, pase_status, currency, total, payload) VALUES (?,?,?,?,?,?,?,?)');
$ins->execute([1, 467, '44521', 'on-hold', 'new', 'PLN', 34.98, $unpaid]);
$ins->execute([2, 468, '44522', 'processing', 'new', 'PLN', 10.00, json_encode(['status' => 'processing', 'payment_method' => 'cod', 'date_paid' => null, 'total' => '10.00'])]);
$ins->execute([3, 469, '44523', 'processing', 'new', 'PLN', 20.00, json_encode(['status' => 'processing', 'payment_method' => 'blik', 'date_paid' => null, 'total' => '20.00'])]);

$row = static fn(int $id): array => $pdo->query("SELECT * FROM woo_orders WHERE woo_order_id = {$id}")->fetch();
$pay = static fn(int $id): array => OrderIndicators::forOrder($row($id), json_decode($row($id)['payload'], true), [])['payment'];
$svc = new OrderPayment($pdo);

check($pay(1) === ['state' => 'bad', 'label' => 'orders.indicator.payment_unpaid'], 'shop says unpaid before manual entry (red)');
check(OrderPayment::state($row(1)) === null, 'no manual entry yet');

$svc->set(1, 34.98, 'Adam');
check($pay(1) === ['state' => 'ok', 'label' => 'orders.indicator.payment_paid_manual'], 'full amount marks the order paid');
check((float) $row(1)['paid_amount_total'] === 34.98 && $row(1)['paid_amount_by'] === 'Adam' && $row(1)['paid_amount_at'] !== null, 'total, author and time stored');
check(json_decode($row(1)['payload'], true)['date_paid'] === null, 'shop payload is not changed');

$svc->set(1, 20.0, 'Adam');
check($pay(1) === ['state' => 'partial', 'label' => 'orders.indicator.payment_partial'], 'partial amount is green-orange');
check(OrderPayment::state($row(1), 20.0)['state'] === 'ok', 'state can be checked against the current total');

$svc->set(1, 0.0, 'Adam');
check($pay(1)['label'] === 'orders.indicator.payment_unpaid', '0.00 marks the order unpaid');
$svc->set(2, 0.0, 'Adam');
check($pay(2)['label'] === 'orders.indicator.payment_cod', '0.00 on cash on delivery stays "za pobraniem"');

check(OrderPayment::state(['paid_amount' => '0.00', 'paid_amount_total' => '0.00'])['state'] === 'ok', '0.00 paid on a 0.00 order = paid');
check(OrderPayment::coversTotal(0.0, 0.0) && !OrderPayment::coversTotal(0.0, 10.0) && OrderPayment::coversTotal(10.0, 10.0), 'coversTotal');

$svc->set(1, null, 'Adam');
check($row(1)['paid_amount'] === null && $row(1)['paid_amount_at'] === null, 'reset clears the manual entry');
check($pay(1)['label'] === 'orders.indicator.payment_unpaid', 'after reset the shop data decides again');

$failed = false;
try { $svc->set(1, -5.0, 'Adam'); } catch (RuntimeException) { $failed = true; }
check($failed && $row(1)['paid_amount'] === null, 'negative amount refused');

// Zwrot płatności w sklepie wygrywa z ręczną wpłatą.
$svc->set(3, 20.0, 'Adam');
$pdo->exec("UPDATE woo_orders SET payload = '" . json_encode(['status' => 'refunded', 'total' => '20.00']) . "' WHERE woo_order_id = 3");
check($pay(3)['label'] === 'orders.indicator.payment_refunded', 'refund in the shop still shows as refunded');
$pdo->exec("UPDATE woo_orders SET payload = '" . json_encode(['status' => 'processing', 'payment_method' => 'blik', 'date_paid' => null, 'total' => '20.00']) . "', paid_amount = NULL WHERE woo_order_id = 3");

// Scalone: wpłata na głównym obejmuje całą paczkę, na dołączonym jest zablokowana.
$pdo->exec('UPDATE woo_orders SET merged_into = 1 WHERE woo_order_id = 3');
check($svc->dueTotal($row(1)) === 54.98, 'due total of a merged main order includes absorbed orders');
$svc->set(1, 34.98, 'Adam');
check($pay(1)['label'] === 'orders.indicator.payment_partial', 'paying only the main part of a merged parcel is partial');
$svc->set(1, 54.98, 'Adam');
check($pay(1)['label'] === 'orders.indicator.payment_paid_manual', 'paying the whole parcel marks it paid');
$failed = false;
try { $svc->set(3, 20.0, 'Adam'); } catch (RuntimeException $e) { $failed = str_contains($e->getMessage(), 'głównym'); }
check($failed, 'absorbed order cannot get its own payment');

$audit = $pdo->query("SELECT before_json, after_json FROM audit_events WHERE order_id = 1 AND action = 'order.payment_set' ORDER BY id")->fetchAll();
check(count($audit) === 6 && json_decode($audit[0]['after_json'], true) === ['paid_amount' => '34.98'], 'every change is in the order history');

echo "\nAll {$checks} order payment checks passed.\n";
