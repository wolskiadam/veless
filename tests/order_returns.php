<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Zwroty i korekty: zwroty z Allegro, ręczny zwrot, przyjęcie na stan, korekta wFirma i zwrot pieniędzy
// na atrapach klientów API (bez sieci).
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\OrderReturns as R;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }
function throws(callable $fn, string $needle): bool {
    try { $fn(); } catch (Throwable $e) { if (!str_contains($e->getMessage(), $needle)) { echo '  got: ' . $e->getMessage() . "\n"; return false; } return true; }
    return false;
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY, integration_id INTEGER, pase_number INTEGER, order_number TEXT, currency TEXT,
    customer_name TEXT, payload TEXT, local_items TEXT NULL)');
$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, pase_stock INTEGER, actual_stock INTEGER NULL)');
$pdo->exec('CREATE TABLE order_documents (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, provider TEXT, document_type TEXT, remote_id TEXT)');
Pase\Services\AuditTrail::migrate($pdo);
R::migrate($pdo);
R::migrate($pdo);

// Zamówienie Allegro (checkout-form po AllegroOrderMapper) i zamówienie ze sklepu.
$allegroId = 9000000000 + 123;
$allegroPayload = [
    'id' => 'cf-1', 'payment' => ['id' => 'pay-1', 'paidAmount' => ['amount' => '93.00', 'currency' => 'PLN']],
    'delivery' => ['cost' => ['amount' => '12.99', 'currency' => 'PLN']],
    'line_items' => [
        ['id' => 'li-1', 'name' => 'Świeca sojowa Lawenda', 'sku' => 'SW-LAW', 'quantity' => 2, 'price' => 25.0, 'allegro_offer_id' => '111'],
        ['id' => 'li-2', 'name' => 'Wosk do kominka', 'sku' => 'WK-1', 'quantity' => 1, 'price' => 30.0, 'allegro_offer_id' => '222'],
    ],
];
$pdo->prepare('INSERT INTO woo_orders VALUES (?, 1, 10, ?, ?, ?, ?, NULL)')->execute([$allegroId, 'cf-1', 'PLN', 'Anna K', json_encode($allegroPayload)]);
$wooPayload = ['line_items' => [
    ['id' => 55, 'name' => 'Świeca w szkle', 'sku' => 'SW-SZ', 'quantity' => 3, 'total' => '97.56', 'total_tax' => '22.44'],
]];
$pdo->prepare('INSERT INTO woo_orders VALUES (?, 2, 11, ?, ?, ?, ?, NULL)')->execute([501, '501', 'PLN', 'Jan N', json_encode($wooPayload)]);
$pdo->exec("INSERT INTO products (id, sku, pase_stock, actual_stock) VALUES (1, 'SW-LAW', 5, 2), (2, 'WK-1', 0, NULL), (3, 'SW-SZ', 1, NULL)");

$returns = new R($pdo);

// --- Zwrot z Allegro ---
$cr = [
    'id' => 'ret-1', 'referenceNumber' => 'ZWROT-AB12', 'orderId' => 'cf-1', 'createdAt' => '2026-09-20T10:00:00.000Z', 'status' => 'IN_TRANSIT',
    'buyer' => ['login' => 'anna_k', 'email' => 'anna@example.com'],
    'items' => [['offerId' => '111', 'name' => 'Świeca sojowa Lawenda', 'quantity' => 1, 'price' => ['amount' => '25.00', 'currency' => 'PLN'],
        'reason' => ['type' => 'DAMAGED', 'userComment' => 'Pęknięte szkło']]],
    'parcels' => [['waybill' => 'AD123', 'carrierId' => 'ALLEGRO']],
    'refund' => ['bankAccount' => ['owner' => 'Anna K', 'accountNumber' => '12 3456']],
];
$rid = $returns->upsertAllegro($cr);
$ret = $returns->find($rid);
check($ret['woo_order_id'] === $allegroId && $ret['status'] === 'IN_TRANSIT' && $ret['buyer'] === 'anna_k <anna@example.com>', 'Allegro return matched to the CRM order');
check($ret['items'][0]['sku'] === 'SW-LAW' && $ret['items'][0]['line_item_id'] === 'li-1' && $ret['items'][0]['reason'] === 'DAMAGED', 'return item gets SKU and order line id');
check($ret['waybill'] === 'AD123' && $ret['bank_account']['owner'] === 'Anna K', 'parcel and buyer bank account kept');
$cr['status'] = 'DELIVERED';
check($returns->upsertAllegro($cr) === $rid && $returns->find($rid)['status'] === 'DELIVERED', 'next sync updates status, no duplicate');
check(count($returns->list()) === 1 && count($returns->forOrder($allegroId)) === 1, 'listed on the return list and the order');
check($returns->upsertAllegro(['id' => '']) === null, 'return without id ignored');
$orphan = $returns->upsertAllegro(['id' => 'ret-x', 'orderId' => 'unknown', 'items' => [['offerId' => '9', 'name' => 'X', 'quantity' => 1, 'price' => ['amount' => '5']]]]);
check($returns->find($orphan)['woo_order_id'] === null, 'return for an order outside CRM is kept, not linked');
$returns->linkOrder($orphan, 501);
check((int) $returns->find($orphan)['woo_order_id'] === 501, 'operator can link it to an order');
$returns->upsertAllegro(['id' => 'ret-x', 'orderId' => 'unknown', 'status' => 'DELIVERED', 'items' => [['offerId' => '9', 'name' => 'X', 'quantity' => 1, 'price' => ['amount' => '5']]]]);
check((int) $returns->find($orphan)['woo_order_id'] === 501, 'next sync keeps the manual link');

// Sync z atrapą klienta: stronicowanie i błąd.
$fakeAllegro = new class {
    public array $calls = [];
    public function customerReturns(array $q): array { $this->calls[] = $q; return ['ok' => true, 'items' => [['id' => 'ret-2', 'orderId' => 'cf-1', 'status' => 'CREATED', 'items' => []]], 'message' => '']; }
};
$r = $returns->syncAllegro($fakeAllegro);
check($r['ok'] && $r['count'] === 1 && count($fakeAllegro->calls) === 1 && isset($fakeAllegro->calls[0]['createdAt.gte']), 'sync fetches recent returns');
$failing = new class { public function customerReturns(array $q): array { return ['ok' => false, 'items' => [], 'message' => 'Forbidden']; } };
check($returns->syncAllegro($failing) === ['ok' => false, 'count' => 0, 'message' => 'Forbidden'], 'sync error reported');

// --- Ręczny zwrot do zamówienia ze sklepu ---
$lines = R::orderLines($pdo->query('SELECT * FROM woo_orders WHERE woo_order_id = 501')->fetch());
check($lines[0]['price'] === 40.0 && $lines[0]['line_item_id'] === '55', 'order line gross unit price from Woo net + tax');
check(throws(fn() => $returns->createManual(501, ['0' => 4], 'MISTAKE', ''), 'większa niż w zamówieniu'), 'cannot return more than ordered');
check(throws(fn() => $returns->createManual(501, [], 'MISTAKE', ''), 'co najmniej jednej'), 'empty return rejected');
$mid = $returns->createManual(501, ['0' => 2], 'MISTAKE', 'Paczka wróciła', 'RR123');
$m = $returns->find($mid);
check($m['source'] === 'manual' && $m['status'] === 'RECEIVED' && $m['items'][0]['quantity'] === 2 && R::itemsValue($m['items']) === 80.0, 'manual return saved with value');

// --- Krok 1: przyjęcie na stan ---
$r = $returns->restock($rid);
check($r['restocked'] === [['sku' => 'SW-LAW', 'quantity' => 1]] && (int) $pdo->query("SELECT pase_stock FROM products WHERE sku='SW-LAW'")->fetchColumn() === 6, 'restock adds returned units');
check((int) $pdo->query("SELECT actual_stock FROM products WHERE sku='SW-LAW'")->fetchColumn() === 3, 'restock also adds to the actual stock');
check(throws(fn() => $returns->restock($rid), 'już przyjęty'), 'restock only once');
check((int) $pdo->query("SELECT pase_stock FROM products WHERE sku='SW-LAW'")->fetchColumn() === 6, 'second click changes nothing');
$r = $returns->restock($mid, [0 => 1]);
check($r['restocked'][0]['quantity'] === 1 && (int) $pdo->query("SELECT pase_stock FROM products WHERE sku='SW-SZ'")->fetchColumn() === 2, 'restock only the good units');
check($pdo->query("SELECT actual_stock FROM products WHERE sku='SW-SZ'")->fetchColumn() === null, 'empty actual stock stays empty after restock');
$r = $returns->restock($orphan);
check($r['skipped'] === ['X'], 'item without SKU reported, not guessed');

// --- Krok 2: korekta w wFirma ---
$invoiceLines = [
    ['id' => '9001', 'name' => 'Świeca sojowa Lawenda', 'count' => 2.0, 'price' => 25.0, 'vat' => '23', 'unit' => 'szt.'],
    ['id' => '9002', 'name' => 'Wosk do kominka', 'count' => 1.0, 'price' => 30.0, 'vat' => '23', 'unit' => 'szt.'],
    ['id' => '9003', 'name' => 'Allegro One Box', 'count' => 1.0, 'price' => 12.99, 'vat' => '23', 'unit' => ''],
];
check(R::suggestCorrection($invoiceLines, $returns->find($rid)['items']) === [0 => 1.0, 1 => 0.0, 2 => 0.0], 'returned quantities suggested by product name');
$c = R::correctionPositions($invoiceLines, [0 => '1', 1 => 0, 2 => '0']);
check($c['amount'] === 25.0 && $c['positions'][0] === ['parent_id' => '9001', 'name' => 'Świeca sojowa Lawenda', 'count' => '1', 'price' => '25.00', 'vat' => '23', 'unit' => 'szt.'], 'correction lists the line after correction with parent_id');
check($c['positions'][1]['count'] === '1' && $c['positions'][2]['count'] === '1' && !isset($c['positions'][2]['unit']), 'unchanged lines stay as they were');
check(throws(fn() => R::correctionPositions($invoiceLines, [0 => 3]), 'między 0 a 2'), 'cannot correct more than invoiced');
check(throws(fn() => R::correctionPositions($invoiceLines, []), 'co najmniej jednej'), 'empty correction rejected');

$fakeWfirma = new class($invoiceLines) {
    public array $sent = [];
    public array $next = ['ok' => true, 'status' => 200, 'message' => 'Korekta wystawiona: FK 1/9/2026.', 'document_id' => '7777'];
    public ?Throwable $throw = null;
    public function __construct(private array $lines) {}
    public function getInvoice(string $id): array {
        return ['ok' => true, 'message' => '', 'invoice' => ['id' => $id, 'number' => 'FV 5/9/2026', 'type' => 'normal', 'contractor_id' => '44', 'currency' => 'PLN', 'lines' => $this->lines]];
    }
    public function issueCorrection(string $parent, string $contractor, array $positions, string $reason, string $currency): array {
        if ($this->throw) { throw $this->throw; }
        $this->sent[] = compact('parent', 'contractor', 'positions', 'reason', 'currency');
        return $this->next;
    }
};
check(throws(fn() => $returns->issueCorrection($rid, $fakeWfirma, [0 => 1], ''), 'nie ma faktury z wFirma'), 'no invoice in CRM: correction must be done in wFirma');
check($fakeWfirma->sent === [], 'nothing sent without an invoice');
$pdo->exec("INSERT INTO order_documents (woo_order_id, integration_id, provider, document_type, remote_id) VALUES ($allegroId, 3, 'wfirma', 'normal', '5555')");

$fakeWfirma->next = ['ok' => false, 'status' => 200, 'message' => 'invoicecontents: błędna ilość', 'document_id' => ''];
check(throws(fn() => $returns->issueCorrection($rid, $fakeWfirma, [0 => 1], 'Zwrot'), 'odrzuciła korektę'), 'validation error from wFirma shown');
check($returns->find($rid)['correction_state'] === null, 'rejected correction can be retried');

$fakeWfirma->next = ['ok' => false, 'status' => 0, 'message' => 'timeout', 'document_id' => ''];
check(throws(fn() => $returns->issueCorrection($rid, $fakeWfirma, [0 => 1], 'Zwrot'), 'ponowienie zablokowano'), 'no answer: result unknown');
check($returns->find($rid)['correction_state'] === R::STEP_UNCERTAIN, 'unknown result blocks retry');
$sentBefore = count($fakeWfirma->sent);
check(throws(fn() => $returns->issueCorrection($rid, $fakeWfirma, [0 => 1], 'Zwrot'), 'wymaga sprawdzenia') && count($fakeWfirma->sent) === $sentBefore, 'blocked retry sends nothing');
check(throws(fn() => $returns->confirmDone($rid, 'correction', 'abc'), 'numeryczne'), 'admin must give a numeric wFirma id');
$returns->confirmAbsent($rid, 'correction');
check($returns->find($rid)['correction_state'] === null, 'admin confirms no correction exists: unlocked');

$fakeWfirma->next = ['ok' => true, 'status' => 200, 'message' => 'Korekta wystawiona: FK 1/9/2026.', 'document_id' => '7777'];
$r = $returns->issueCorrection($rid, $fakeWfirma, [0 => 1, 1 => 0, 2 => 0], 'Uszkodzona');
$last = end($fakeWfirma->sent);
check($r['document_id'] === '7777' && $r['amount'] === 25.0, 'correction issued');
check($last['parent'] === '5555' && $last['contractor'] === '44' && $last['positions'][0]['count'] === '1' && str_contains($last['reason'], 'FV 5/9/2026 — Uszkodzona'), 'correction points at the invoice with the corrected quantities');
check($returns->find($rid)['correction_state'] === 'done' && $returns->find($rid)['correction_remote_id'] === '7777', 'correction id saved on the return');
check(throws(fn() => $returns->issueCorrection($rid, $fakeWfirma, [0 => 1], ''), 'już wystawiona'), 'second correction blocked');
$fakeWfirma->throw = new RuntimeException('connection reset');

// --- Krok 3: zwrot pieniędzy ---
$b = R::allegroRefundBody($allegroPayload, $returns->find($rid)['items'], [0 => 1], 12.99, 'Przepraszamy');
check($b['body'] === ['payment' => ['id' => 'pay-1'], 'reason' => 'REFUND', 'lineItems' => [['id' => 'li-1', 'type' => 'QUANTITY', 'quantity' => 1]],
    'delivery' => ['value' => ['amount' => '12.99', 'currency' => 'PLN']], 'sellerComment' => 'Przepraszamy'] && $b['amount'] === 37.99, 'Allegro refund body');
check(throws(fn() => R::allegroRefundBody($allegroPayload, $returns->find($rid)['items'], [0 => 1], 20, ''), 'kosztu dostawy'), 'delivery refund capped at delivery cost');
check(throws(fn() => R::allegroRefundBody(['payment' => []], [], [], 0, ''), 'ID płatności'), 'no payment id: refund in Allegro panel');
check(R::allegroRefundBody($allegroPayload, $returns->find($rid)['items'], [0 => 5], 0, '')['body']['lineItems'][0]['quantity'] === 1, 'refund quantity capped at returned quantity');

$w = R::wooRefundBody($m['items'], [0 => 2], 0, '', false);
check($w['body'] === ['amount' => '80.00', 'reason' => 'Zwrot towaru', 'api_refund' => false, 'restock_items' => false], 'Woo refund body: record only, no double restock');
check(R::wooRefundBody($m['items'], [0 => 1], 9.5, 'x', true)['body']['api_refund'] === true && R::wooRefundBody($m['items'], [0 => 1], 9.5, 'x', true)['amount'] === 49.5, 'Woo gateway refund with extra amount');

$fakeRefund = new class {
    public array $sent = [];
    public array $next = ['ok' => true, 'status' => 201, 'id' => 'rf-1', 'message' => ''];
    public function refundPayment(array $body): array { $this->sent[] = $body; return $this->next; }
    public function createRefund(int $id, array $body): array { $this->sent[] = [$id, $body]; return $this->next; }
};
check(throws(fn() => $returns->refund($rid, 'woo', ['client' => $fakeRefund, 'quantities' => [1]]), 'przez Allegro'), 'Allegro order is not refunded through the shop');
$fakeRefund->next = ['ok' => false, 'status' => 403, 'id' => '', 'message' => 'Brak uprawnień'];
check(throws(fn() => $returns->refund($rid, 'allegro', ['client' => $fakeRefund, 'quantities' => [1]]), 'odrzucił'), 'Allegro rejection shown');
check($returns->find($rid)['refund_state'] === null, 'rejected refund can be retried');
$fakeRefund->next = ['ok' => false, 'status' => 503, 'id' => '', 'message' => 'Service Unavailable'];
check(throws(fn() => $returns->refund($rid, 'allegro', ['client' => $fakeRefund, 'quantities' => [1]]), 'ponowienie zablokowano'), '5xx: result unknown');
$n = count($fakeRefund->sent);
check(throws(fn() => $returns->refund($rid, 'allegro', ['client' => $fakeRefund, 'quantities' => [1]]), 'wymaga sprawdzenia') && count($fakeRefund->sent) === $n, 'no second refund while unknown');
$returns->confirmDone($rid, 'refund', 'rf-found');
check($returns->find($rid)['refund_state'] === 'done' && $returns->find($rid)['refund_remote_id'] === 'rf-found', 'admin records the refund found in Allegro');

$fakeRefund->next = ['ok' => true, 'status' => 201, 'id' => '9', 'message' => ''];
$r = $returns->refund($mid, 'woo', ['client' => $fakeRefund, 'quantities' => [0 => 2], 'comment' => 'Zwrot', 'via_gateway' => false]);
check($r['amount'] === 80.0 && end($fakeRefund->sent)[0] === 501, 'Woo refund sent for the shop order');
check($returns->find($mid)['refund_method'] === 'woo' && (float) $returns->find($mid)['refund_amount'] === 80.0, 'refund amount and method saved');
check(throws(fn() => $returns->refund($mid, 'manual', ['amount' => '80']), 'już wykonano'), 'refund only once');

check(throws(fn() => $returns->refund($orphan, 'manual', ['amount' => '0']), 'kwotę'), 'manual refund needs an amount');
$r = $returns->refund($orphan, 'manual', ['amount' => '5,00', 'comment' => 'przelew 26.09']);
check($r['amount'] === 5.0 && str_contains((string) $returns->find($orphan)['refund_message'], 'przelew 26.09'), 'refund done outside CRM recorded');

// Filtry listy i historia zamówienia.
check(count($returns->list('open')) === 3 && count($returns->list('done')) === 1, 'open / finished filter');
check(count($returns->list('', 'Lawenda')) === 1, 'search by product');
$actions = $pdo->query('SELECT action FROM audit_events WHERE order_id = ' . $allegroId . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
check(in_array('return.created', $actions, true) && in_array('return.restocked', $actions, true) && in_array('return.correction_issued', $actions, true) && in_array('return.refunded', $actions, true), 'every step is in the order history');

echo "All $checks checks passed\n";
