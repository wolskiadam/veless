<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Deliberately no application bootstrap, .env, network, production database or real plugin discovery.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    }
});
use Pase\Services\AuditTrail;
use Pase\Services\OrderStatusService;
use Pase\Services\WfirmaDocumentService;
use Pase\Repository\WooOrderRepository;
use Pase\Repository\OrderDocumentRepository;
use Pase\Repository\OrderStatusRepository;
use Pase\Repository\IntegrationAccountRepository;
use Pase\Plugin\Contract\Invoicing;

$checks = 0;
function check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    ++$checks; echo "OK: $label\n";
}
function rejects(callable $fn, string $label): void {
    try { $fn(); } catch (Throwable $e) { check(true, $label); return; }
    check(false, $label);
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY, pase_status TEXT, lifecycle TEXT DEFAULT 'active',
    payload TEXT, local_items TEXT, local_edited_at TEXT, local_billing_email TEXT, local_billing_phone TEXT,
    integration_id INTEGER, order_number TEXT, currency TEXT DEFAULT 'PLN');
    CREATE TABLE order_statuses (status_key TEXT);
    INSERT INTO order_statuses VALUES ('new'), ('done');
    CREATE TABLE automation_rules (id INTEGER, is_active INTEGER, event TEXT, priority INTEGER);
    CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, is_active INTEGER, config TEXT);
    INSERT INTO integration_accounts VALUES (1, 'wfirma', 1, '{}');
    CREATE TABLE order_documents (id INTEGER PRIMARY KEY AUTOINCREMENT, woo_order_id INTEGER, integration_id INTEGER,
        provider TEXT, document_type TEXT, remote_id TEXT, status TEXT, message TEXT,
        UNIQUE(woo_order_id, provider, document_type));");
AuditTrail::migrate($pdo); AuditTrail::migrate($pdo);
\Pase\Services\DocumentIssueGuard::migrate($pdo);
$guard = new \Pase\Services\DocumentIssueGuard($pdo);
$resolveAbsent = static function (int $id, string $type) use ($pdo, $guard): void {
    $s = $pdo->prepare('SELECT attempt_token FROM document_issue_operations WHERE order_id = ? AND document_type = ?');
    $s->execute([$id, $type]); $guard->reconcile($id, $type, (string) $s->fetchColumn(), null);
};
$_SESSION = ['pase_user_id' => 7, 'pase_username' => 'Operator <script>'];
$payload = [
    'billing' => ['first_name' => 'Jan', 'last_name' => 'Testowy', 'email' => 'test@example.invalid',
        'phone' => '123', 'address_1' => 'Testowa 1', 'city' => 'Test', 'postcode' => '00-001', 'country' => 'PL'],
    'line_items' => [['product_id' => 12, 'sku' => 'TEST', 'name' => 'Produkt', 'quantity' => 2, 'total' => '100', 'total_tax' => '23']],
    'shipping_lines' => [['method_title' => 'Dostawa', 'total' => '10', 'total_tax' => '2.30']],
    'fee_lines' => [['name' => 'Opłata', 'total' => '5', 'total_tax' => '0']],
    'currency' => 'PLN', 'date_paid' => '2026-09-24', 'total' => '140.30', 'payment_method' => 'bacs',
];
$insert = $pdo->prepare("INSERT INTO woo_orders (woo_order_id, pase_status, payload, integration_id, order_number) VALUES (?, 'new', ?, 0, ?)");
foreach ([1, 2, 3, 4] as $id) { $insert->execute([$id, json_encode($payload), (string) $id]); }
$orders = new WooOrderRepository($pdo);
$documents = new OrderDocumentRepository($pdo);
$audit = new AuditTrail($pdo);
$count = static fn(): int => (int) $pdo->query('SELECT COUNT(*) FROM audit_events')->fetchColumn();
$last = static fn(): array => $pdo->query('SELECT * FROM audit_events ORDER BY id DESC LIMIT 1')->fetch();
$status = new OrderStatusService($pdo, new IntegrationAccountRepository($pdo), new OrderStatusRepository($pdo));
$status->change(1, 'done');
check($orders->find(1)['pase_status'] === 'done', 'Status updated');
check(json_decode($last()['before_json'], true)['pase_status'] === 'new', 'Previous status captured');
check((int) $last()['actor_id'] === 7, 'Operator recorded');
$n = $count(); $status->change(1, 'done'); $status->change(1, 'unknown');
check($count() === $n, 'No duplicate/no invalid status events');
rejects(fn() => $status->change(999, 'done'), 'Missing order does not report successful status change');
$orders->updateLocalItems(1, [['product_id' => 12, 'sku' => 'TEST', 'name' => 'Changed', 'quantity' => 3, 'price' => 7, 'total' => 21, 'token' => 'DO-NOT-LOG']]);
check(json_decode($orders->find(1)['local_items'], true)[0]['quantity'] === 3, 'Local items saved');
check(!str_contains($last()['after_json'], 'DO-NOT-LOG'), 'Item metadata secrets excluded');
check($orders->find(1)['payload'] === json_encode($payload), 'Original imported payload unchanged');
$orders->clearLocalItems(1);
check($orders->find(1)['local_items'] === null, 'Restore original items');
$orders->updateLocalContact(1, 'new@example.invalid', '987');
check($orders->find(1)['local_billing_email'] === 'new@example.invalid', 'Contact override saved');
check(!str_contains($last()['after_json'], '@') && !str_contains($last()['after_json'], '987'), 'Contact values not copied into history');
check(json_decode($last()['after_json'], true)['contact_fields'] === ['email', 'phone'], 'Changed contact fields identified');
$orders->updateLocalContact(1, '', '');
check($orders->find(1)['local_billing_email'] === null, 'Clear contact override');
check($orders->setLifecycleMany([1, 2, 2, 999, -1], 'archived') === 2, 'Bulk lifecycle handles duplicate/missing IDs');
check($orders->setLifecycleMany([1], 'invalid') === 0, 'Invalid lifecycle rejected');
$orders->setLifecycle(2, 'trashed');
check($orders->purgeMany([1, 2]) === 1, 'Only trashed order can be permanently removed');
check($orders->find(2) === null && $last()['action'] === 'order.deleted', 'Deletion history survives order deletion');
$pdo->beginTransaction(); $orders->setLifecycle(1, 'active'); $pdo->rollBack();
check($orders->find(1)['lifecycle'] === 'archived', 'Outer rollback rolls back mutation and history');
$n = $count();
$pdo->exec("CREATE TRIGGER reject_audit BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'test failure'); END;");
rejects(fn() => $orders->setLifecycle(1, 'active'), 'Audit failure rejects local mutation');
check($orders->find(1)['lifecycle'] === 'archived' && $count() === $n, 'Audit failure leaves no partial changes');
$pdo->exec('DROP TRIGGER reject_audit');
$fake = new class implements Invoicing {
    public array $calls = [];
    public ?Closure $onCall = null;
    public array $result = ['ok' => true, 'message' => 'test', 'document_id' => 'TEST-1'];
    public function issueDocument(array $contractor, array $positions, string $type = 'normal', array $options = []): array {
        $this->calls[] = compact('contractor', 'positions', 'type', 'options');
        if ($this->onCall) { ($this->onCall)(); }
        return $this->result;
    }
};
$service = new WfirmaDocumentService($pdo, static fn(array $config): Invoicing => $fake);
$result = $service->issue(1, 'receipt');
check($result['document_id'] === 'TEST-1', 'Receipt workflow saves external result');
check($fake->calls[0]['contractor']['name'] === 'Jan Testowy', 'Contractor full name mapped');
check($fake->calls[0]['contractor']['city'] === 'Test', 'Contractor address mapped');
check($fake->calls[0]['positions'][0]['price'] === '61.50', 'Gross item unit price mapped');
check($fake->calls[0]['positions'][1]['price'] === '12.30', 'Shipping included');
check($fake->calls[0]['positions'][2]['price'] === '5.00', 'Fees included');
check($fake->calls[0]['options']['currency'] === 'PLN', 'Currency mapped');
check($fake->calls[0]['options']['alreadypaid_initial'] === '140.30', 'Paid amount mapped for original order');
check($last()['action'] === 'document.linked', 'Document history recorded');
rejects(fn() => $service->issue(1, 'receipt'), 'Repeated document type rejected');
check(count($fake->calls) === 1, 'Duplicate rejected before external API');
rejects(fn() => $service->issue(1, 'invalid'), 'Invalid document type rejected');
rejects(fn() => $service->issue(999, 'normal'), 'Document for missing order rejected');
$doc = $documents->findForOrderAndType(1, 'wfirma', 'receipt');
check(!$documents->unlinkReceipt((int) $doc['id'], 3), 'Cannot unlink another order document');
check($documents->unlinkReceipt((int) $doc['id'], 1), 'Receipt unlink succeeds');
check($last()['action'] === 'document.unlinked' && str_contains($last()['before_json'], 'TEST-1'), 'Unlink history retains external reference');
$service->issue(1, 'receipt');
check(count($fake->calls) === 2, 'Explicit unlink permits reissue');
// Pozycje poprawione w CRM (nazwa, ilość) idą na dokument; VAT ze sklepu zostaje, opłacone = kwota ze sklepu.
$doc = $documents->findForOrderAndType(1, 'wfirma', 'receipt');
$documents->unlinkReceipt((int) $doc['id'], 1);
$orders->updateLocalItems(1, [
    ['product_id' => 12, 'sku' => 'TEST', 'name' => 'Nazwa na paragon', 'quantity' => 2, 'price' => 50, 'total' => 100, 'total_tax' => 23],
    ['product_id' => 12, 'sku' => 'TEST', 'name' => 'Druga pozycja', 'quantity' => 1, 'price' => 1, 'total' => 1, 'total_tax' => 0.23],
]);
$service->issue(1, 'receipt');
$edited = $fake->calls[2];
check($edited['positions'][0]['name'] === 'Nazwa na paragon' && $edited['positions'][1]['name'] === 'Druga pozycja', 'Edited names on receipt');
check($edited['positions'][0]['price'] === '61.50' && $edited['positions'][0]['vat'] === 23, 'Edited line keeps shop VAT');
check($edited['options']['alreadypaid_initial'] === '140.30', 'Edited paid order still marked paid (shop amount)');
$doc = $documents->findForOrderAndType(1, 'wfirma', 'receipt');
$documents->unlinkReceipt((int) $doc['id'], 1);
$orders->updateLocalItems(1, [['product_id' => 12, 'sku' => 'TEST', 'name' => 'Mniej', 'quantity' => 1, 'price' => 50, 'total' => 50, 'total_tax' => 11.5]]);
$service->issue(1, 'receipt');
check($fake->calls[3]['options']['alreadypaid_initial'] === '78.80', 'Paid capped at edited document total');
$doc = $documents->findForOrderAndType(1, 'wfirma', 'receipt');
$documents->unlinkReceipt((int) $doc['id'], 1);
// Starsza edycja bez total_tax: VAT dokładany z oryginalnej pozycji tego samego produktu.
$orders->updateLocalItems(1, [['product_id' => 12, 'sku' => 'TEST', 'name' => 'Stara edycja', 'quantity' => 2, 'price' => 50, 'total' => 100]]);
$service->issue(1, 'receipt');
check($fake->calls[4]['positions'][0]['price'] === '61.50' && $fake->calls[4]['positions'][0]['name'] === 'Stara edycja', 'Older edit without VAT gets it from the shop line');
$orders->clearLocalItems(1);
$fake->result = ['ok' => false, 'message' => 'Controlled rejection'];
rejects(fn() => $service->issue(3, 'normal'), 'Provider rejection propagated');
check($documents->forOrder(3) === [], 'Rejected document not marked issued');
$calls = count($fake->calls);
rejects(fn() => $service->issue(3, 'normal'), 'Rejected request blocked until operator reconciliation');
check(count($fake->calls) === $calls, 'Uncertain retry does not call provider');
$resolveAbsent(3, 'normal');
$fake->result = ['ok' => true, 'message' => 'Missing ID'];
rejects(fn() => $service->issue(3, 'normal'), 'Missing external document ID rejected');
check($documents->forOrder(3) === [], 'Unknown external result not marked issued');
$resolveAbsent(3, 'normal');
$fake->result = ['ok' => true, 'message' => '', 'document_id' => 'TEST-INVOICE'];
$service->issue(3, 'normal');
$doc = $documents->findForOrderAndType(3, 'wfirma', 'normal');
check(!$documents->unlinkReceipt((int) $doc['id'], 3), 'Invoice cannot be removed using receipt action');
$orders->updateLocalItems(4, []);
$calls = count($fake->calls);
// Remove shipping/fees too: an order with only shipping still contains billable positions.
$pdo->exec("UPDATE woo_orders SET payload = '{}' WHERE woo_order_id = 4");
rejects(fn() => $service->issue(4, 'proforma'), 'Empty order rejected');
check(count($fake->calls) === $calls, 'Empty order rejected before external API');
$_SESSION = [];
$orders->setLifecycle(4, 'archived');
check($last()['actor_id'] === null && $last()['actor_name'] === 'System / automatyzacja', 'System operations identified');
$allegro = \Pase\Support\AllegroOrderMapper::withWooFields([
    'buyer' => ['firstName' => 'Anna', 'lastName' => 'Test', 'email' => 'anna@example.invalid'],
    'lineItems' => [['offer' => ['name' => 'Allegro product', 'external' => ['id' => 'SKU-A']], 'quantity' => 2, 'price' => ['amount' => '25.00']]],
    'delivery' => ['cost' => ['amount' => '9.00']],
    'summary' => ['totalToPay' => ['amount' => '59.00', 'currency' => 'PLN']],
]);
check($allegro['line_items'][0]['total'] === '50.00' && $allegro['total'] === '59.00', 'Allegro prices and delivery normalized');
check(!isset($allegro['line_items'][0]['total_tax']), 'Allegro gross price is not taxed twice');
$pdo->prepare('UPDATE woo_orders SET payload = ?, local_items = NULL WHERE woo_order_id = 4')->execute([json_encode($allegro)]);
$service->issue(4, 'proforma');
$call = end($fake->calls);
check($call['contractor']['name'] === 'Anna Test', 'Allegro buyer mapped to contractor');
check($call['positions'][0]['price'] === '25.00' && $call['positions'][1]['price'] === '9.00', 'Allegro document uses gross product and delivery values');
$fake->onCall = static function () use ($pdo): void {
    $pdo->exec("CREATE TRIGGER reject_audit BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'test failure'); END;");
};
try {
    $service->issue(4, 'normal');
    check(false, 'Post-API local failure reported');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'Nie ponawiaj') && str_contains($e->getMessage(), 'TEST-INVOICE'), 'Post-API local failure warns against duplicate issuance');
}
$pdo->exec('DROP TRIGGER reject_audit');
$fake->onCall = null;
$calls = count($fake->calls);
rejects(fn() => $service->issue(4, 'normal'), 'Post-API database failure blocks another issue');
check(count($fake->calls) === $calls, 'No remote duplicate after database failure');
$token = $pdo->query("SELECT attempt_token FROM document_issue_operations WHERE order_id=4 AND document_type='normal'")->fetchColumn();
rejects(fn() => $guard->reconcile(4, 'normal', $token, null), 'Cannot clear operation with known document ID');
// Test overlapping requests while the fake provider is still executing.
$fake->onCall = static function () use ($service): void {
    rejects(fn() => $service->issue(4, 'receipt_fiscal'), 'Concurrent second request blocked before first provider call returns');
};
$service->issue(4, 'receipt_fiscal');
$fake->onCall = null;
$pendingToken = $guard->reserve(3, 'proforma', 1);
rejects(fn() => $guard->reserve(3, 'proforma', 1), 'Process-crash pending reservation survives retry');
rejects(fn() => $guard->reconcile(3, 'proforma', $pendingToken, null), 'Pending reservation cannot be cleared');
rejects(fn() => $guard->reconcile(3, 'proforma', 'old-token', '1234'), 'Stale reconciliation rejected');
$guard->reconcile(3, 'proforma', $pendingToken, '1234');
check($documents->findForOrderAndType(3, 'wfirma', 'proforma')['remote_id'] === '1234', 'Operator can recover an existing remote document');
$audit->record(4, 'test', ['password' => 'secret'], ['access_key' => 'secret']);
check(!str_contains($last()['before_json'] . $last()['after_json'], 'secret'), 'Sensitive fields excluded by allowlist');
echo "PASS: $checks sales workflow checks (SQLite and fake invoicing; no external calls)\n";
