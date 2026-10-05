<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Nieudana płatność (Services\PaymentFailure): czerwony wskaźnik i status Anulowane tylko w CRM - SQLite, bez sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Domain\OrderStatus;
use Pase\Services\AuditTrail;
use Pase\Services\OrderIndicators;
use Pase\Services\OrderMerge;
use Pase\Services\OrderPayment;
use Pase\Services\PaymentFailure;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY, pase_number INTEGER, order_number TEXT, status TEXT, pase_status TEXT,
    lifecycle TEXT NOT NULL DEFAULT 'active', currency TEXT, total REAL, date_created TEXT, payload TEXT, local_items TEXT NULL)");
AuditTrail::migrate($pdo);
OrderMerge::migrate($pdo);
OrderPayment::migrate($pdo);
OrderStatus::useMapLoader(null);

$ins = $pdo->prepare('INSERT INTO woo_orders (woo_order_id, order_number, status, pase_status, total, payload) VALUES (?,?,?,?,?,?)');
$order = static fn(string $status, ?string $paid = null): string =>
    json_encode(['status' => $status, 'payment_method' => 'payu', 'date_paid' => $paid, 'total' => '50.00', 'line_items' => []]);
$ins->execute([1, '100', 'failed', 'new', 50, $order('failed')]);
$ins->execute([2, '101', 'pending', 'new', 50, $order('pending')]);
$ins->execute([3, '102', 'failed', 'processing', 50, $order('failed')]);          // operator już zmienił status
$ins->execute([4, '103', 'failed', 'new', 50, $order('failed', '2026-09-29T10:00:00')]); // opłacone później
$ins->execute([5, '104', 'failed', 'new', 50, $order('failed')]);                 // część scalonej paczki
$ins->execute([6, '105', 'processing', 'new', 50, $order('processing')]);
$pdo->exec('UPDATE woo_orders SET merged_into = 6 WHERE woo_order_id = 5');

$row = static fn(int $id): array => $pdo->query("SELECT * FROM woo_orders WHERE woo_order_id = {$id}")->fetch();
$pay = static fn(int $id): array => OrderIndicators::forOrder($row($id), json_decode($row($id)['payload'], true), [])['payment'];

check($pay(1) === ['state' => 'bad', 'label' => 'orders.indicator.payment_failed'], 'failed payment is red');
check($pay(2) === ['state' => 'bad', 'label' => 'orders.indicator.payment_unpaid'], 'pending payment shows as unpaid (red)');
check($pay(4)['label'] === 'orders.indicator.payment_paid', 'paid later in the shop is not failed');

$svc = new PaymentFailure($pdo);
check($svc->apply(1) && $row(1)['pase_status'] === 'cancelled', 'failed payment cancels the order in CRM');
check(!$svc->apply(2) && $row(2)['pase_status'] === 'new', 'pending order untouched');
check(!$svc->apply(3) && $row(3)['pase_status'] === 'processing', 'status set by the operator is kept');
check(!$svc->apply(4) && $row(4)['pase_status'] === 'new', 'order paid later is not cancelled');
check(!$svc->apply(5) && $row(5)['pase_status'] === 'new', 'part of a merged parcel is not cancelled');
check(json_decode($row(1)['payload'], true)['status'] === 'failed' && $row(1)['status'] === 'failed', 'shop status is not changed');

$audit = $pdo->query("SELECT action, before_json, after_json FROM audit_events WHERE order_id = 1")->fetchAll();
check(count($audit) === 1 && $audit[0]['action'] === PaymentFailure::AUDIT_ACTION
    && json_decode($audit[0]['after_json'], true) === ['pase_status' => 'cancelled'], 'change is in the order history');

// Operator przywrócił „Nowe" - CRM nie anuluje drugi raz.
$pdo->exec("UPDATE woo_orders SET pase_status = 'new' WHERE woo_order_id = 1");
check(!$svc->apply(1) && $row(1)['pase_status'] === 'new', 'cancelled only once per order');

// Ręcznie potwierdzona pełna wpłata wygrywa z „failed" ze sklepu.
(new OrderPayment($pdo))->set(2, 50.0, 'Adam');
$pdo->exec("UPDATE woo_orders SET status = 'failed', payload = '" . $order('failed') . "' WHERE woo_order_id = 2");
check($pay(2)['label'] === 'orders.indicator.payment_paid_manual', 'manual full payment beats shop failure');
check(!$svc->apply(2) && $row(2)['pase_status'] === 'new', 'manually paid order is not cancelled');

// Jednorazowe sprawdzenie starszych zamówień.
$ins->execute([7, '106', 'failed', 'new', 50, $order('failed')]);
$ins->execute([8, '107', 'failed', 'new', 50, $order('failed')]);
$pdo->exec("UPDATE woo_orders SET lifecycle = 'archived' WHERE woo_order_id = 8");
check($svc->sweep() === 1 && $row(7)['pase_status'] === 'cancelled' && $row(8)['pase_status'] === 'new', 'sweep cancels older active failed orders only');

echo "\nAll {$checks} payment failure checks passed.\n";
