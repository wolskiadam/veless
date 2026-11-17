<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Zamówienie ze sklepu „wstrzymane”, a w CRM opłacone (PayU przypisane w CRM) - od razu do realizacji
// i „Pobrano zamówienie” (reguły + push), raz; nieopłacone dalej czeka - SQLite, bez sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Automation\OrderEvents;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

\Pase\Support\SecretStore::useKeyFile(sys_get_temp_dir() . '/awaiting-test-no-key-' . bin2hex(random_bytes(4)));
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE settings (setting_key VARCHAR(64) PRIMARY KEY, setting_value TEXT NULL)');
$pdo->exec('CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY, integration_id INT NULL, order_number TEXT, status TEXT, pase_status TEXT,
    total REAL, currency TEXT, customer_name TEXT, date_created TEXT, payload TEXT, automations_done INT NOT NULL DEFAULT 0)');
$pdo->exec('CREATE TABLE automation_rules (id INTEGER PRIMARY KEY, name TEXT, event TEXT, is_active INT, priority INT, conditions TEXT, actions TEXT, condition_match TEXT)');
OrderEvents::migrate($pdo);
\Pase\Services\AuditTrail::migrate($pdo);
\Pase\Services\PayuPayments::migrate($pdo);

$payload = static fn(int $id): string => json_encode(['id' => $id, 'status' => 'on-hold', 'payment_method' => 'payu', 'total' => '120.00',
    'date_paid' => null, 'line_items' => [['name' => 'Świeca', 'quantity' => 1]]]);
$ins = $pdo->prepare("INSERT INTO woo_orders (woo_order_id, order_number, status, pase_status, total, currency, date_created, payload) VALUES (?, ?, 'on-hold', 'new', 120, 'PLN', ?, ?)");
$ins->execute([45964, '45964', gmdate('Y-m-d H:i:s'), $payload(45964)]);
$ins->execute([45965, '45965', gmdate('Y-m-d H:i:s'), $payload(45965)]);
$pay = $pdo->prepare("INSERT INTO payu_order_payments (woo_order_id, payu_order_id, integration_id, status, amount, currency, linked_by, created_at) VALUES (?, ?, 1, ?, 12000, 'PLN', 'auto', ?)");
$pay->execute([45964, 'PAYUPAID000000000001', 'COMPLETED', gmdate('Y-m-d H:i:s')]);
$pay->execute([45965, 'PAYUWAIT000000000002', 'PENDING', gmdate('Y-m-d H:i:s')]);

check(OrderEvents::isPaidNow($pdo, 45964) === true && OrderEvents::isPaidNow($pdo, 45965) === false, 'paid in PayU (assigned in CRM) counts as paid, like the list icon');

$engine = new \Pase\Automation\RuleEngine($pdo, new \Pase\Repository\AutomationRuleRepository($pdo));
check(OrderEvents::releaseAwaitingPayment($pdo, 45964, $engine), 'on-hold in the shop but paid in CRM: released');
$row = $pdo->query('SELECT pase_status, automations_done FROM woo_orders WHERE woo_order_id = 45964')->fetch();
check($row['pase_status'] === 'processing' && (int) $row['automations_done'] === 1, 'goes straight to processing, rules marked done');
$hist = $pdo->query("SELECT action FROM audit_events WHERE order_id = 45964 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
check(in_array('order.status_changed', $hist, true) && in_array('push.new_order', $hist, true), 'status change and push entry in the order history');
check(!OrderEvents::releaseAwaitingPayment($pdo, 45964, $engine), 'released only once');

check(!OrderEvents::releaseAwaitingPayment($pdo, 45965, $engine)
    && $pdo->query('SELECT pase_status FROM woo_orders WHERE woo_order_id = 45965')->fetchColumn() === 'new', 'unpaid PayU payment: still waiting');

// Status zmieniony wcześniej ręcznie (jak #45964 na produkcji) - zostaje, ale reguły i push idą.
$ins->execute([45966, '45966', gmdate('Y-m-d H:i:s'), $payload(45966)]);
$pay->execute([45966, 'PAYUPAID000000000003', 'COMPLETED', gmdate('Y-m-d H:i:s')]);
$pdo->exec("UPDATE woo_orders SET pase_status = 'on_hold_custom' WHERE woo_order_id = 45966");
check(OrderEvents::releaseAwaitingPayment($pdo, 45966, $engine)
    && $pdo->query('SELECT pase_status FROM woo_orders WHERE woo_order_id = 45966')->fetchColumn() === 'on_hold_custom',
    'status set by hand is kept, rules and push still run');

echo "awaiting_payment_release: {$checks} checks passed\n";
