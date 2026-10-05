<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Automatyzacje: warunki (opłacone, wirtualne, faktura...), akcja „Wystaw fakturę lub paragon”,
// zdarzenie „Zamówienie zostało opłacone” - SQLite, wtyczka wFirma podstawiona, bez sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Automation\OrderEvents;
use Pase\Automation\RuleEngine;
use Pase\Plugin\Contract\Invoicing;
use Pase\Repository\AutomationRuleRepository;
use Pase\Services\OrderPayment;
use Pase\Services\WfirmaDocumentService;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY, integration_id INTEGER, pase_number INTEGER, order_number TEXT, status TEXT, pase_status TEXT,
    currency TEXT DEFAULT 'PLN', total REAL, customer_name TEXT, customer_email TEXT, date_created TEXT, client_token TEXT, payload TEXT, local_items TEXT NULL,
    local_edited_at TEXT NULL, local_invoice TEXT NULL, local_billing_email TEXT NULL, local_billing_phone TEXT NULL, lifecycle TEXT NOT NULL DEFAULT 'active',
    automations_done INT DEFAULT 0, no_shipping INT NULL, merged_into INT NULL,
    paid_amount REAL NULL, paid_amount_total REAL NULL, paid_amount_at TEXT NULL, paid_amount_by TEXT NULL);
    CREATE TABLE order_statuses (status_key TEXT, label TEXT);
    INSERT INTO order_statuses VALUES ('new', 'Nowe'), ('done', 'Zrealizowane'), ('paid_ok', 'Opłacone');
    CREATE TABLE automation_rules (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, event TEXT, is_active INTEGER, priority INTEGER,
        conditions TEXT, actions TEXT, action_type TEXT NULL, action_params TEXT NULL);
    CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT, is_active INTEGER, config TEXT);
    INSERT INTO integration_accounts VALUES (1, 'wfirma', 'wFirma', 1, '{}');
    CREATE TABLE order_documents (id INTEGER PRIMARY KEY AUTOINCREMENT, woo_order_id INTEGER, integration_id INTEGER,
        provider TEXT, document_type TEXT, remote_id TEXT, status TEXT, message TEXT, UNIQUE(woo_order_id, provider, document_type));
    CREATE TABLE shipments (id INTEGER PRIMARY KEY, woo_order_id INTEGER, status TEXT, tracking_status TEXT NULL, waybill_no TEXT NULL, courier_code TEXT NULL);");
Pase\Services\AuditTrail::migrate($pdo);
Pase\Services\DocumentIssueGuard::migrate($pdo);
OrderEvents::migrate($pdo);
OrderEvents::migrate($pdo);   // drugi raz bez błędu
AutomationRuleRepository::migrate($pdo);
AutomationRuleRepository::migrate($pdo);

$fake = new class implements Invoicing {
    public array $calls = [];
    public function issueDocument(array $contractor, array $positions, string $type = 'normal', array $options = []): array {
        $this->calls[] = $type;
        return ['ok' => true, 'message' => 'test', 'document_id' => 'DOC-' . count($this->calls)];
    }
};
$docs = static fn() => new WfirmaDocumentService($pdo, static fn(array $c): Invoicing => $fake);
$engine = new RuleEngine($pdo, new AutomationRuleRepository($pdo), [], $docs);
$rules = new AutomationRuleRepository($pdo);

$order = static function (int $id, array $over = [], array $row = []) use ($pdo): void {
    $payload = array_replace_recursive([
        'status' => 'processing', 'currency' => 'PLN', 'total' => '49.00', 'payment_method' => 'payu', 'payment_method_title' => 'PayU',
        'date_paid' => '2026-09-29 10:00:00',
        'billing' => ['first_name' => 'Ola', 'last_name' => 'Nowak', 'email' => 'ola@example.invalid', 'country' => 'PL',
                      'address_1' => 'Testowa 1', 'city' => 'Test', 'postcode' => '00-001'],
        'line_items' => [['sku' => 'EBOOK', 'name' => 'E-book o świecach', 'quantity' => 1, 'total' => '49.00', 'total_tax' => '0']],
    ], $over);
    $pdo->prepare('INSERT INTO woo_orders (woo_order_id, integration_id, order_number, status, pase_status, total, payload, no_shipping) VALUES (?, 0, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, (string) $id, $payload['status'], $row['pase_status'] ?? 'new', (float) $payload['total'], json_encode($payload, JSON_UNESCAPED_UNICODE),
                   array_key_exists('no_shipping', $row) ? $row['no_shipping'] : 1]);
};
$fire = static fn(string $event, int $id) => $engine->dispatch($event, $id, json_decode((string) $pdo->query("SELECT payload FROM woo_orders WHERE woo_order_id = $id")->fetchColumn(), true));
$doc = static fn(int $id): array => $pdo->query("SELECT document_type FROM order_documents WHERE woo_order_id = $id ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$status = static fn(int $id): string => (string) $pdo->query("SELECT pase_status FROM woo_orders WHERE woo_order_id = $id")->fetchColumn();

// Przykład z panelu: Pobrano zamówienie + opłacone + wirtualne -> faktura lub paragon + status Zrealizowane.
$rules->save(null, 'Wirtualne opłacone', 'order.imported', true, 10,
    [['field' => 'payment_status', 'op' => 'eq', 'value' => 'paid'], ['field' => 'is_virtual', 'op' => 'eq', 'value' => '1']],
    [['type' => 'issue_sales_document', 'params' => ['receipt_type' => 'receipt']], ['type' => 'set_status', 'params' => ['status' => 'done']]]);

$order(1);                                                                                  // opłacone, wirtualne, bez faktury
$order(2, ['meta_data' => [['key' => '_billing_invoice', 'value' => '1']], 'billing' => ['company' => 'Firma', 'nip' => '2222222222']]);   // chce fakturę
$order(3, ['date_paid' => null]);                                                           // nieopłacone
$order(4, [], ['no_shipping' => 0]);                                                        // wymaga wysyłki
$order(5, [], ['no_shipping' => null]);                                                     // bez znacznika, pozycja niewirtualna
$order(6, ['line_items' => [['sku' => 'KURS', 'name' => 'Kurs', 'quantity' => 1, 'total' => '49', 'virtual' => true]]], ['no_shipping' => null]);   // Woo: virtual

check($fire('order.imported', 1) === 1 && $doc(1) === ['receipt'] && $status(1) === 'done', 'paid virtual order: receipt issued and status set to Zrealizowane');
check($fire('order.imported', 2) === 1 && $doc(2) === ['normal'] && $status(2) === 'done', 'customer asking for an invoice gets a VAT invoice instead of a receipt');
check($fire('order.imported', 3) === 0 && $doc(3) === [] && $status(3) === 'new', 'unpaid order untouched');
check($fire('order.imported', 4) === 0 && $doc(4) === [], 'order that needs shipping untouched');
check($fire('order.imported', 5) === 0, 'order without the virtual flag and with physical items untouched');
check($fire('order.imported', 6) === 1 && $doc(6) === ['receipt'], 'Woo order with only virtual items counts as virtual');

$calls = count($fake->calls);
$pdo->exec("UPDATE woo_orders SET pase_status = 'new' WHERE woo_order_id = 1");
$fire('order.imported', 1);
check(count($fake->calls) === $calls && $doc(1) === ['receipt'], 'second run never issues a second document');

// Ręczne akcje „Wystaw fakturę” / „Stwórz paragon” też nie dublują dokumentu sprzedaży.
$rules->save(null, 'Faktura', 'status.changed', true, 10, [['field' => 'pase_status', 'op' => 'eq', 'value' => 'paid_ok']],
    [['type' => 'issue_invoice', 'params' => ['doc_type' => 'normal']], ['type' => 'create_receipt', 'params' => ['doc_type' => 'receipt_fiscal']]]);
$pdo->exec("UPDATE woo_orders SET pase_status = 'paid_ok' WHERE woo_order_id = 1");
$fire('status.changed', 1);
check(count($fake->calls) === $calls && $doc(1) === ['receipt'], 'invoice action skips an order that already has a receipt (and other receipt type too)');

// Rezerwacja w toku (niepewny wynik) też blokuje.
$order(7);
$pdo->exec("INSERT INTO document_issue_operations (order_id, document_type, integration_id, attempt_token, state, created_at, updated_at)
    VALUES (7, 'receipt', 1, 'x', 'uncertain', '', '')");
check($fire('order.imported', 7) === 1 && $doc(7) === [] && count($fake->calls) === $calls, 'uncertain earlier attempt blocks automatic issuing');

// Warunki opisowe.
$order(8, ['total' => '103.00', 'payment_method' => 'cod', 'payment_method_title' => 'Płatność przy odbiorze (pobranie)', 'date_paid' => null,
    'shipping' => ['country' => 'pl'], 'shipping_lines' => [['method_title' => 'Paczkomat InPost', 'method_id' => 'flat_rate']],
    'line_items' => [['sku' => 'SW-1', 'name' => 'Świeca sojowa', 'quantity' => 2, 'total' => '98'], ['sku' => 'SW-2', 'name' => 'Knot', 'quantity' => 1, 'total' => '5']]],
    ['no_shipping' => 0]);
$match = static function (array $conds) use ($pdo, $rules, $fire): bool {
    $pdo->exec('UPDATE automation_rules SET is_active = 0');
    $rules->save(null, 't', 'shipment.created', true, 1, $conds, [['type' => 'set_virtual', 'params' => ['value' => '0']]]);
    return $fire('shipment.created', 8) === 1;
};
check($match([['field' => 'payment_status', 'op' => 'eq', 'value' => 'cod']]), 'payment status: cash on delivery');
check($match([['field' => 'payment_method', 'op' => 'contains', 'value' => 'POBRANIE']]), 'payment method contains (case-insensitive)');
check($match([['field' => 'shipping_method', 'op' => 'contains', 'value' => 'paczkomat']]), 'shipping method contains');
check($match([['field' => 'country', 'op' => 'eq', 'value' => 'PL']]), 'delivery country');
check($match([['field' => 'items_qty', 'op' => 'gte', 'value' => '3']]) && !$match([['field' => 'items_qty', 'op' => 'gt', 'value' => '3']]), 'number of items');
check($match([['field' => 'product_name', 'op' => 'ncontains', 'value' => 'e-book']]), 'product name does not contain');
check($match([['field' => 'document', 'op' => 'eq', 'value' => 'none']]) && !$match([['field' => 'document', 'op' => 'eq', 'value' => 'receipt']]), 'no sales document yet');
check($match([['field' => 'wants_invoice', 'op' => 'eq', 'value' => '0']]) && $match([['field' => 'has_shipment', 'op' => 'eq', 'value' => '0']]), 'invoice not wanted, no shipment');
$pdo->exec("INSERT INTO shipments (woo_order_id, status) VALUES (8, 'created')");
check($match([['field' => 'has_shipment', 'op' => 'eq', 'value' => '1']]), 'shipment created');
check($match([['field' => 'sku', 'op' => 'contains', 'value' => 'SW-2']]), 'SKU condition still works');

// Akcje „Oznacz jako wirtualne” i „Oznacz jako opłacone”.
$pdo->exec('UPDATE automation_rules SET is_active = 0');
$rules->save(null, 'COD doręczone', 'shipment.status', true, 1, [['field' => 'payment_status', 'op' => 'eq', 'value' => 'cod']],
    [['type' => 'mark_paid', 'params' => []], ['type' => 'set_virtual', 'params' => ['value' => '1']]]);
$fire('shipment.status', 8);
$row8 = $pdo->query('SELECT * FROM woo_orders WHERE woo_order_id = 8')->fetch();
check((float) $row8['paid_amount'] === 103.0 && OrderEvents::paymentState($row8, json_decode($row8['payload'], true)) === 'paid', 'mark as paid records the full amount');
check((int) $row8['no_shipping'] === 1, 'set virtual flag');

// Zdarzenie „Zamówienie zostało opłacone”: raz, tylko przy przejściu nieopłacone -> opłacone.
$rules->save(null, 'Opłacone', 'order.paid', true, 1, [['field' => 'is_virtual', 'op' => 'eq', 'value' => '1']],
    [['type' => 'set_status', 'params' => ['status' => 'paid_ok']]]);
$order(9, ['date_paid' => null, 'total' => '20.00']);
check(OrderEvents::isPaidNow($pdo, 9) === false && !OrderEvents::paidTransition($pdo, 9, false, $engine), 'unpaid order does not fire the paid event');
(new OrderPayment($pdo))->set(9, 10.0, 'test');
check($status(9) === 'new', 'partial payment does not fire the paid event');
(new OrderPayment($pdo))->set(9, 20.0, 'test');
check($status(9) === 'paid_ok', 'full payment entered in the CRM fires the paid event');
$pdo->exec("UPDATE woo_orders SET pase_status = 'new' WHERE woo_order_id = 9");
(new OrderPayment($pdo))->set(9, null, 'test');
(new OrderPayment($pdo))->set(9, 20.0, 'test');
check($status(9) === 'new', 'paid event fires only once per order');
$order(10);
check(!OrderEvents::paidTransition($pdo, 10, true, $engine) && $status(10) === 'new', 'order already paid before the update does not fire (old orders)');
check(OrderEvents::paidTransition($pdo, 10, null, $engine) && $status(10) === 'paid_ok', 'new order imported as paid fires the paid event');
$order(11, ['date_paid' => null, 'total' => '15.00']);
(new OrderPayment($pdo))->set(11, 15.0, 'test', false);
check($status(11) === 'new', 'events can be turned off (manual order form decides)');

// Zdarzenie po wystawieniu dokumentu (manualnie z karty zamówienia też).
$rules->save(null, 'Po paragonie', 'receipt.issued', true, 1, [], [['type' => 'set_status', 'params' => ['status' => 'done']]]);
$order(12, [], ['pase_status' => 'new']);
$docs()->issue(12, 'receipt');
check($status(12) === 'done', 'issuing a receipt fires "Wystawiono paragon" rules');

// „Wszystkie warunki” (i) albo „wystarczy jeden” (lub).
$pdo->exec('UPDATE automation_rules SET is_active = 0');
$order(13, ['date_paid' => null], ['no_shipping' => 1]);   // nieopłacone, wirtualne
$either = [['field' => 'payment_status', 'op' => 'eq', 'value' => 'paid'], ['field' => 'is_virtual', 'op' => 'eq', 'value' => '1']];
$allId = $rules->save(null, 'wszystkie', 'shipment.created', true, 1, $either, [['type' => 'set_virtual', 'params' => ['value' => '1']]]);
check($fire('shipment.created', 13) === 0, 'all conditions: one of two is not enough');
$rules->save($allId, 'dowolny', 'shipment.created', true, 1, $either, [['type' => 'set_virtual', 'params' => ['value' => '1']]], 'any');
check($rules->find($allId)['condition_match'] === 'any' && $fire('shipment.created', 13) === 1, 'any condition: one of two is enough');
$rules->save($allId, 'dowolny', 'shipment.created', true, 1,
    [['field' => 'payment_status', 'op' => 'eq', 'value' => 'refunded'], ['field' => 'is_virtual', 'op' => 'eq', 'value' => '0']],
    [['type' => 'set_virtual', 'params' => ['value' => '1']]], 'any');
check($fire('shipment.created', 13) === 0, 'any condition: none matching -> rule skipped');
check($rules->find($rules->duplicate($allId))['condition_match'] === 'any', 'duplicate keeps the any/all choice');
$pdo->exec('UPDATE automation_rules SET is_active = 0');
// Parametr zdarzenia („Ustawiono status: X”) jest wymagany także przy „dowolny”.
$rules->save(null, 'status', 'status.changed', true, 1,
    [['field' => 'pase_status', 'op' => 'eq', 'value' => 'done'], ['field' => 'is_virtual', 'op' => 'eq', 'value' => '1'], ['field' => 'payment_status', 'op' => 'eq', 'value' => 'paid']],
    [['type' => 'set_virtual', 'params' => ['value' => '1']]], 'any');
$pdo->exec("UPDATE woo_orders SET pase_status = 'new' WHERE woo_order_id = 13");
check($fire('status.changed', 13) === 0, 'any condition: the event status is still required');
$pdo->exec("UPDATE woo_orders SET pase_status = 'done' WHERE woo_order_id = 13");
check($fire('status.changed', 13) === 1, 'any condition: event status + one condition matches');

// Reguła po ludzku (lista w panelu).
$text = new Pase\Automation\RuleText(['status' => ['done' => 'Zrealizowane', 'ready' => 'Do wysłania'], 'email' => ['tpl1' => 'Realizujemy Twoje zamówienie']]);
$r = ['event' => 'order.imported', 'condition_match' => 'all', 'conditions' => $either,
      'actions' => [['type' => 'send_email', 'params' => ['template' => 'tpl1']], ['type' => 'issue_sales_document', 'params' => ['receipt_type' => 'receipt']],
                    ['type' => 'set_status', 'params' => ['status' => 'done']]]];
check($text->when($r) === 'Gdy pobrano zamówienie' && $text->joinLabel($r) === 'i spełnione są wszystkie warunki', 'sentence start');
check($text->conditions($r) === ['zamówienie jest opłacone', 'zamówienie jest wirtualne (bez wysyłki)'], 'conditions as plain words');
check($text->actions($r) === ['Wyślij klientowi e-mail „Realizujemy Twoje zamówienie”',
    'Wystaw fakturę VAT, jeśli klient jej chce, a jeśli nie – paragon niefiskalny', 'Zmień status na „Zrealizowane”'], 'actions as plain words');
$st = ['event' => 'status.changed', 'condition_match' => 'any', 'conditions' => [['field' => 'pase_status', 'op' => 'eq', 'value' => 'ready'],
    ['field' => 'total', 'op' => 'gte', 'value' => '100'], ['field' => 'payment_method', 'op' => 'contains', 'value' => 'pobranie']], 'actions' => []];
check($text->when($st) === 'Gdy zamówienie dostanie status „Do wysłania”' && $text->joinLabel($st) === 'i spełniony jest co najmniej jeden warunek'
    && $text->conditions($st) === ['suma zamówienia co najmniej 100', 'metoda płatności zawiera „pobranie”'], 'event status in the sentence, not in the list');
check($text->condition(['field' => 'wants_invoice', 'op' => 'ne', 'value' => '1']) === 'klient nie chce faktury'
    && $text->condition(['field' => 'document', 'op' => 'eq', 'value' => 'none']) === 'nie ma jeszcze faktury ani paragonu', 'yes/no and document conditions');
check($text->action(['type' => 'set_status', 'params' => ['status' => '']]) === 'Zmień status na … (nie wybrano statusu)', 'missing parameter is visible');

// Kolejność z listy (przeciągnij i upuść).
$ids = array_map('intval', array_column($rules->all(), 'id'));
$want = array_merge([end($ids)], array_slice($ids, 0, 2));
$rules->reorder($want);
$after = array_map('intval', array_column($rules->all(), 'id'));
check(array_slice($after, 0, 3) === $want && count($after) === count($ids), 'reorder puts dragged rules first and keeps the rest');
check((int) $rules->find($want[0])['priority'] === 10 && $rules->nextPriority() === count($ids) * 10 + 10, 'priorities 10, 20... and new rule goes last');

echo "\nautomation_actions: {$checks} checks passed\n";
