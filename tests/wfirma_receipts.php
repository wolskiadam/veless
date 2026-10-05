<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Paragony i faktury wystawione w wFirma poza CRM (BaseLinker): dopasowanie do zamówień i powiązanie, na atrapie klienta wFirma.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\WfirmaReceiptMatcher as M;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$receipt = static fn(string $id, string $date, float $total, string $buyer = '', string $text = '', string $type = 'receipt_fiscal_normal'): array =>
    ['id' => $id, 'number' => 'PAR ' . $id, 'type' => $type, 'date' => $date, 'total' => $total, 'buyer' => $buyer, 'text' => $text];
$order = static fn(int $id, string $number, float $total, string $date, string $name, array $payload = []): array =>
    ['woo_order_id' => $id, 'order_number' => $number, 'total' => $total, 'date_created' => $date . ' 12:00:00', 'customer_name' => $name, 'payload' => json_encode($payload)];

$orders = [
    $order(1, '92368', 29.90, '2026-09-20', 'Małgorzata Przykładowa'),
    $order(2, '92369', 29.90, '2026-09-20', 'Jan Kowalski'),
    $order(3, '92370', 55.00, '2026-09-21', 'Anna Nowak'),
    $order(4, '92371', 81.50, '2026-09-22', 'Piotr Zieliński', ['meta_data' => [['key' => '_baselinker_order_id', 'value' => '771234567']]]),
    $order(5, '92372', 12.00, '2026-09-01', 'Ewa Lis'),
];

$r = M::match([$receipt('10', '2026-09-21', 29.90, 'Malgorzata Przykladowa')], $orders);
check($r['rows'][0]['level'] === 'sure' && $r['rows'][0]['candidates'][0]['woo_order_id'] === 1, 'Amount + date + buyer name (without Polish letters) gives a sure match');

$r = M::match([$receipt('11', '2026-09-21', 29.90, 'Klient detaliczny')], $orders);
check($r['rows'][0]['level'] === 'ambiguous' && count($r['rows'][0]['candidates']) === 2, 'Same amount on two orders, no name: operator chooses');

$r = M::match([$receipt('12', '2026-09-23', 55.00, '')], $orders);
check($r['rows'][0]['level'] === 'probable' && $r['rows'][0]['candidates'][0]['woo_order_id'] === 3, 'Single order with amount and date: probable, not sure');

$r = M::match([$receipt('13', '2026-09-23', 81.50, '', 'Zamówienie nr 771234567')], $orders);
check($r['rows'][0]['level'] === 'sure' && str_contains($r['rows'][0]['reason'], 'numer'), 'BaseLinker order id from order meta found in the description');

$r = M::match([$receipt('14', '2026-09-23', 99.00, '', 'Zamówienie 92370')], $orders);
check($r['rows'][0]['level'] === 'ambiguous', 'Order number matches but amount differs: not sure');

$r = M::match([$receipt('15', '2026-09-21', 29.90, '', 'Zamówienie 923689')], $orders);
check($r['rows'][0]['level'] === 'ambiguous' && !str_contains($r['rows'][0]['reason'], 'numer'), 'Number inside a longer number does not count');

$r = M::match([$receipt('16', '2026-11-30', 12.00, 'Ewa Lis')], $orders);
check($r['rows'] === [] && count($r['unmatched']) === 1, 'Receipt long after the order is not matched');

$r = M::match([$receipt('17', '2026-09-21', 29.90, 'Małgorzata Przykładowa'), $receipt('18', '2026-09-22', 29.90, 'Małgorzata Przykładowa')], $orders);
check($r['rows'][0]['level'] === 'ambiguous' && $r['rows'][1]['level'] === 'ambiguous', 'Two receipts pointing at one order: neither is sure');
check(!isset($r['rows'][0]['candidates'][0]['payload']), 'Candidates do not carry the raw payload');

// Faktury: firma na fakturze nie ma imienia i nazwiska kupującego - potwierdza NIP z zamówienia.
$firm = [
    $order(21, '92380', 246.00, '2026-09-20', 'Jan Kowalski', ['billing' => ['company' => 'Kowalski Sp. z o.o.', 'nip' => 'PL 333-333-33-33']]),
    $order(22, '92381', 246.00, '2026-09-20', 'Adam Nowak'),
];
$invoice = ['id' => '30', 'number' => 'FV 5/09/2026', 'type' => 'normal', 'date' => '2026-09-21', 'total' => 246.00,
    'buyer' => 'Kowalski Sp. z o.o.', 'nip' => '3333333333', 'text' => ''];
$r = M::match([$invoice], $firm);
check($r['rows'][0]['level'] === 'sure' && $r['rows'][0]['candidates'][0]['woo_order_id'] === 21 && str_contains($r['rows'][0]['reason'], 'NIP'), 'Invoice: amount + date + buyer NIP gives a sure match');
check(!isset($r['rows'][0]['candidates'][0]['_nip']), 'Candidates do not carry the internal NIP');
$r = M::match([['nip' => '1234563218'] + $invoice], $firm);
check($r['rows'][0]['level'] === 'ambiguous', 'Invoice with another NIP: operator chooses');
check(M::isInvoice('normal') && M::isInvoice('margin') && !M::isInvoice('receipt_fiscal_normal'), 'Invoice vs receipt by wFirma type');

// --- podgląd i powiązanie na bazie + atrapa wFirma ---
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT, is_active INTEGER, config TEXT, webhook_secret TEXT)');
$pdo->exec("INSERT INTO integration_accounts VALUES (7, 'wfirma', 'wFirma', 1, '{}', NULL)");
$pdo->exec('CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY, order_number TEXT, total REAL, date_created TEXT, customer_name TEXT, payload TEXT)');
$pdo->exec('CREATE TABLE order_documents (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, provider TEXT, document_type TEXT,
    remote_id TEXT, status TEXT, message TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE (woo_order_id, provider, document_type))');
Pase\Services\AuditTrail::migrate($pdo);
$ins = $pdo->prepare('INSERT INTO woo_orders VALUES (?, ?, ?, ?, ?, ?)');
foreach ($orders as $o) { $ins->execute(array_values($o)); }
// Zamówienie 3 ma już fakturę z CRM - paragon do niego nie pasuje.
$pdo->exec("INSERT INTO order_documents (woo_order_id, integration_id, provider, document_type, remote_id, status) VALUES (3, 7, 'wfirma', 'normal', '500', 'issued')");

$fake = new class {
    public array $calls = [];
    public function findSaleDocuments(string $from, string $to): array
    {
        $this->calls[] = [$from, $to];
        return ['ok' => true, 'message' => '', 'truncated' => false, 'receipts' => [
            ['id' => '900', 'number' => 'PAR 1/09/2026', 'type' => 'receipt_fiscal_normal', 'date' => '2026-09-21', 'total' => 29.90, 'buyer' => 'Małgorzata Przykładowa', 'text' => ''],
            ['id' => '901', 'number' => 'PAR 2/09/2026', 'type' => 'receipt_normal', 'date' => '2026-09-23', 'total' => 81.50, 'buyer' => '', 'text' => 'BL 771234567'],
            ['id' => '902', 'number' => 'PAR 3/09/2026', 'type' => 'receipt_normal', 'date' => '2026-09-23', 'total' => 55.00, 'buyer' => 'Anna Nowak', 'text' => ''],
            ['id' => '500', 'number' => 'FV 1/09/2026', 'type' => 'receipt_normal', 'date' => '2026-09-21', 'total' => 55.00, 'buyer' => '', 'text' => ''],
            ['id' => '903', 'number' => 'FV 7/09/2026', 'type' => 'normal', 'date' => '2026-09-02', 'total' => 12.00, 'buyer' => 'Lis Ewa Firma', 'nip' => '', 'text' => 'Zamówienie 92372'],
        ]];
    }
};
$m = new M($pdo, static fn(array $cfg): object => $fake);
$p = $m->preview('2026-09-01', '2026-09-30');
$levels = array_column(array_map(static fn(array $r): array => ['id' => $r['receipt']['id'], 'l' => $r['level']], $p['rows']), 'l', 'id');
check($levels == ['900' => 'sure', '901' => 'sure', '903' => 'sure'] && count($p['unmatched']) === 1 && $p['already'] === 1, 'Preview: invoiced order skipped, already linked document skipped, invoice matched by number');
check($fake->calls[0] === ['2026-09-01', '2026-09-30'], 'wFirma asked for the chosen period');

$res = $m->link('2026-09-01', '2026-09-30', ['900' => 1, '901' => 2]);
check($res['linked'] === 1 && $res['skipped'] === ['PAR 2/09/2026'], 'Only an order from the candidate list can be linked');
$doc = $pdo->query("SELECT * FROM order_documents WHERE remote_id = '900'")->fetch();
check((int) $doc['woo_order_id'] === 1 && $doc['document_type'] === 'receipt_fiscal' && $doc['status'] === 'issued', 'Fiscal receipt linked as issued');
$res = $m->link('2026-09-01', '2026-09-30', ['901' => 4]);
check($res['linked'] === 1 && $pdo->query("SELECT document_type FROM order_documents WHERE remote_id = '901'")->fetchColumn() === 'receipt', 'Non-fiscal receipt linked');
$res = $m->link('2026-09-01', '2026-09-30', ['903' => 5]);
$doc = $pdo->query("SELECT * FROM order_documents WHERE remote_id = '903'")->fetch();
check($res['linked'] === 1 && (int) $doc['woo_order_id'] === 5 && $doc['document_type'] === 'normal' && str_starts_with((string) $doc['message'], 'Faktura FV 7/09/2026'), 'Invoice linked as an invoice (normal)');
$p = $m->preview('2026-09-01', '2026-09-30');
check($p['rows'] === [] && $p['already'] === 4, 'Linked documents disappear from the preview');
check((int) $pdo->query("SELECT COUNT(*) FROM audit_events WHERE action = 'document.linked'")->fetchColumn() === 3, 'Linking is recorded in the history');

check(M::range('2026-09-30', '2026-01-01') === ['2026-01-01', '2026-09-30'] && M::range('2024-01-01', '2026-09-30') === ['2025-09-29', '2026-09-30'], 'Range sorted and capped');
check(M::range('x', '2026-09-30') === ['2026-08-31', '2026-09-30'], 'Default range: last 30 days');

echo "\nPASS: {$checks} wFirma sync checks\n";
