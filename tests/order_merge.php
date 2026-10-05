<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Scalanie zamówień (Services\OrderMerge): sprawdzenie, scalenie, pozycje w paczce, statusy, pakowanie,
// wydruk i odłączenie - SQLite, bez sieci. Magazyn i dokumenty nie mogą się zmienić.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\OrderStatusRepository;
use Pase\Services\OrderMerge;
use Pase\Services\OrderStatusService;
use Pase\Services\Packing;
use Pase\Services\PrintTemplateRenderer;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }
function throws(callable $fn, string $needle): bool {
    try { $fn(); } catch (Throwable $e) { if (!str_contains($e->getMessage(), $needle)) { echo '  got: ' . $e->getMessage() . "\n"; return false; } return true; }
    return false;
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY, integration_id INTEGER NULL, pase_number INTEGER, order_number TEXT, status TEXT,
    pase_status TEXT, lifecycle TEXT NOT NULL DEFAULT 'active', currency TEXT, total REAL, customer_name TEXT, customer_email TEXT,
    date_created TEXT, payload TEXT, local_items TEXT NULL, local_billing_email TEXT NULL, local_billing_phone TEXT NULL,
    local_invoice TEXT NULL, local_pickup_point TEXT NULL, local_edited_at TEXT NULL)");
$pdo->exec('CREATE TABLE shipments (id INTEGER PRIMARY KEY, woo_order_id INTEGER, status TEXT)');
$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, ean TEXT, images TEXT, pase_stock INTEGER, actual_stock INTEGER NULL)');
$pdo->exec("INSERT INTO products (id, sku, pase_stock, actual_stock) VALUES (1,'GB-1L',10,4),(2,'MW-1L',8,NULL),(3,'CC-1L',5,5)");
$pdo->exec('CREATE TABLE order_statuses (id INTEGER PRIMARY KEY, status_key TEXT, label TEXT, color TEXT, position INTEGER)');
$pdo->exec("INSERT INTO order_statuses (status_key,label,color,position) VALUES ('new','Nowe','#888',1),('processing','W realizacji','#27a',2),('shipped','Wysłane','#2a7',3),('cancelled','Anulowane','#c33',4)");
$pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT, is_active INTEGER, config TEXT)');
$pdo->exec('CREATE TABLE automation_rules (id INTEGER, is_active INTEGER, event TEXT, priority INTEGER)');
Pase\Services\AuditTrail::migrate($pdo);
OrderMerge::migrate($pdo);
OrderMerge::migrate($pdo);   // drugi raz bez błędu
check(in_array('merged_into', array_column($pdo->query('PRAGMA table_info(woo_orders)')->fetchAll(), 'name'), true), 'merged_into column added once');

$woo = static fn(array $items, string $email, string $street, float $total, array $extra = []): string => json_encode($extra + [
    'status' => 'processing', 'date_paid' => '2026-09-20T10:00:00', 'total' => (string) $total,
    'billing' => ['first_name' => 'Anna', 'last_name' => 'Kowalska', 'email' => $email, 'address_1' => $street, 'postcode' => '00-001'],
    'shipping' => ['address_1' => $street, 'postcode' => '00-001'],
    'line_items' => $items, 'shipping_lines' => [['method_title' => 'Kurier', 'total' => '15.00']],
]);
$ins = $pdo->prepare('INSERT INTO woo_orders (woo_order_id, pase_number, order_number, pase_status, currency, total, customer_name, customer_email, date_created, payload)
    VALUES (?,?,?,?,?,?,?,?,?,?)');
$ins->execute([101, 1, '5001', 'new', 'PLN', 65.0, 'Anna Kowalska', 'anna@example.com', '2026-09-20 10:00:00',
    $woo([['name' => 'Gingerbread 1l', 'sku' => 'GB-1L', 'product_id' => 1, 'quantity' => 2, 'price' => 25.0, 'total' => '50.00']], 'anna@example.com', 'Lipowa 1', 65.0)]);
$ins->execute([102, 2, '5002', 'processing', 'PLN', 45.0, 'Anna Kowalska', 'ANNA@example.com', '2026-09-21 09:00:00',
    $woo([['name' => 'Mulled Wine 1l', 'sku' => 'MW-1L', 'product_id' => 2, 'quantity' => 1, 'price' => 30.0, 'total' => '30.00']], 'ANNA@example.com', 'Lipowa 1', 45.0)]);
$ins->execute([103, 3, '5003', 'new', 'PLN', 20.0, 'Jan Nowak', 'jan@example.com', '2026-09-21 11:00:00',
    $woo([['name' => 'Clementine 1l', 'sku' => 'CC-1L', 'product_id' => 3, 'quantity' => 1, 'price' => 20.0, 'total' => '20.00']], 'jan@example.com', 'Polna 9', 20.0)]);
$ins->execute([104, 4, '5004', 'shipped', 'PLN', 10.0, 'Anna Kowalska', 'anna@example.com', '2026-09-19 10:00:00',
    $woo([['name' => 'Knot', 'sku' => 'KN', 'quantity' => 1, 'price' => 10.0, 'total' => '10.00']], 'anna@example.com', 'Lipowa 1', 10.0)]);
$ins->execute([105, 5, '5005', 'new', 'PLN', 12.0, 'Anna Kowalska', 'anna@example.com', '2026-09-22 10:00:00',
    $woo([['name' => 'Wosk', 'sku' => 'WK', 'quantity' => 1, 'price' => 12.0, 'total' => '12.00']], 'anna@example.com', 'Lipowa 1', 12.0)]);
$pdo->exec("INSERT INTO shipments (woo_order_id, status) VALUES (105, 'created')");
$stockBefore = $pdo->query('SELECT id, pase_stock, actual_stock FROM products ORDER BY id')->fetchAll();

$merge = new OrderMerge($pdo);

// --- Sprawdzenie ---
$c = $merge->check(101, [104]);
check($c['errors'] !== [] && str_contains(implode(' ', $c['errors']), 'wysłane'), 'shipped order cannot be merged');
$c = $merge->check(101, [105]);
check(str_contains(implode(' ', $c['errors']), 'przesyłkę'), 'order with a shipment cannot be merged');
$c = $merge->check(101, [102]);
check($c['errors'] === [] && $c['warnings'] === [], 'same customer (email case ignored), same address: no warnings');
$c = $merge->check(101, [103]);
check($c['errors'] === [] && count($c['warnings']) === 2, 'other customer and other address give warnings');
check(throws(fn() => $merge->merge(101, [103], false), 'Potwierdź'), 'warnings must be confirmed');
check((int) $pdo->query('SELECT COUNT(*) FROM woo_orders WHERE merged_into IS NOT NULL')->fetchColumn() === 0, 'refused merge changes nothing');
$pdo->exec("UPDATE woo_orders SET lifecycle = 'archived' WHERE woo_order_id = 103");
check(throws(fn() => $merge->merge(101, [103], true), 'archiwum'), 'archived order cannot be merged');
$pdo->exec("UPDATE woo_orders SET lifecycle = 'active' WHERE woo_order_id = 103");

// --- Scalenie ---
check($merge->merge(101, [102], false) === 1, 'merge 102 into 101');
check($merge->merge(101, [102], false) === 0, 'merging again does nothing');
check((int) $pdo->query('SELECT merged_into FROM woo_orders WHERE woo_order_id = 102')->fetchColumn() === 101, 'absorbed order points at main order');
check((int) $pdo->query('SELECT COUNT(*) FROM woo_orders')->fetchColumn() === 5, 'no order deleted');
check($pdo->query('SELECT local_items FROM woo_orders WHERE woo_order_id = 101')->fetchColumn() === null, 'main order items not rewritten');
check($pdo->query('SELECT id, pase_stock, actual_stock FROM products ORDER BY id')->fetchAll() === $stockBefore, 'stock and actual stock unchanged');
check(throws(fn() => $merge->merge(102, [103], true), 'już dołączone'), 'absorbed order cannot become a main order');
check(throws(fn() => $merge->merge(103, [102], true), 'już dołączone do innego'), 'absorbed order cannot be merged elsewhere');

$extra = $merge->extraItems(101);
check(count($extra) === 1 && $extra[0]['sku'] === 'MW-1L' && $extra[0]['merged_from'] === 102 && $extra[0]['merged_label'] === '#2', 'extra items come from the absorbed order');
$info = $merge->listInfo([101, 102, 103]);
check(($info[101]['absorbed'] ?? 0) === 1 && abs(($info[101]['group_total'] ?? 0) - 110.0) < 0.001, 'list: main order shows +1 and parcel total 65 + 45');
check(($info[102]['into']['id'] ?? 0) === 101 && $info[102]['into']['label'] === '#1', 'list: absorbed order links to main');
check(!isset($info[103]), 'list: unrelated order has no merge info');

// Lokalna edycja dołączonego (np. usunięta pozycja) widać w paczce głównego.
$pdo->exec("UPDATE woo_orders SET local_items = '" . json_encode([['name' => 'Mulled Wine 1l', 'sku' => 'MW-1L', 'quantity' => 3, 'price' => 30.0, 'total' => 90.0]]) . "' WHERE woo_order_id = 102");
check($merge->extraItems(101)[0]['quantity'] === 3 && abs($merge->listInfo([101])[101]['group_total'] - 155.0) < 0.001, 'local edit of absorbed order is used');

// --- Status: główne -> dołączone (bez anulowania) ---
$statusSvc = new OrderStatusService($pdo, new IntegrationAccountRepository($pdo), new OrderStatusRepository($pdo));
$msg = $statusSvc->change(101, 'shipped');
check($pdo->query('SELECT pase_status FROM woo_orders WHERE woo_order_id = 102')->fetchColumn() === 'shipped' && str_contains($msg, 'scalone'), 'shipped status goes to absorbed order');
$pdo->exec("UPDATE woo_orders SET pase_status = 'processing' WHERE woo_order_id IN (101, 102)");
$statusSvc->change(101, 'cancelled');
check($pdo->query('SELECT pase_status FROM woo_orders WHERE woo_order_id = 102')->fetchColumn() === 'processing', 'cancelling main order does not cancel absorbed order');
$pdo->exec("UPDATE woo_orders SET pase_status = 'processing' WHERE woo_order_id = 101");
$statusSvc->change(102, 'new');
check($pdo->query('SELECT pase_status FROM woo_orders WHERE woo_order_id = 101')->fetchColumn() === 'processing', 'status of absorbed order does not touch main order');

// --- Pakowanie ---
$pdo->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT, role TEXT, password_hash TEXT, is_active INTEGER, session_version INTEGER, totp_secret TEXT)');
Packing::migrate($pdo);
$packing = new Packing($pdo);
$main = $packing->order(101);
check(array_column($main['items'], 'sku') === ['GB-1L', 'MW-1L'] && str_contains($main['note'], '#2'), 'packing main order lists absorbed products');
check(str_contains($main['items'][1]['attrs'], 'z zamówienia #2'), 'packing shows which order a product came from');
$packing->setPacked(101, 1, 3, 'adam');
check($packing->order(101)['items'][1]['packed'] === 3, 'absorbed product can be packed in main order');
$abs = $packing->order(102);
check($abs['items'] === [] && str_contains($abs['note'], '#1'), 'absorbed order has nothing to pack and points to main');
$browse = $packing->browse(['new', 'processing']);
check(!in_array(102, array_column($browse['orders'], 'id'), true), 'absorbed order hidden from packing list');
$b101 = array_values(array_filter($browse['orders'], static fn($o) => $o['id'] === 101))[0] ?? null;
check($b101 !== null && $b101['total'] === 5, 'packing list counts absorbed units (2 + 3)');

// --- Wydruk ---
$row = $pdo->query('SELECT * FROM woo_orders WHERE woo_order_id = 101')->fetch();
$ctx = PrintTemplateRenderer::contextForOrder($row, json_decode($row['payload'], true), 'Sklep', $merge->absorbed(101));
check(str_contains($ctx['produkty_lista'], 'Mulled Wine') && $ctx['liczba_pozycji'] === '2' && $ctx['suma'] === '155,00' && $ctx['scalone_zamowienia'] === '#2', 'print includes absorbed products and parcel total');

// --- Odłączenie ---
check($merge->unmerge(102) === 101, 'unmerge returns main order');
check($merge->unmerge(102) === null, 'second unmerge does nothing');
check($merge->extraItems(101) === [] && $merge->listInfo([101, 102]) === [], 'after unmerge orders are separate again');
check(count($packing->order(102)['items']) === 1, 'unmerged order can be packed on its own again');
$hist = $pdo->query("SELECT action FROM audit_events WHERE order_id = 102 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
check(in_array('order.merged', $hist, true) && in_array('order.unmerged', $hist, true), 'merge and unmerge are in order history');

// --- Scalenie łańcuchowe: dołączane zamówienie miało już swoje dołączone ---
$merge->merge(102, [101], false);
$merge->merge(103, [102], true);
check((int) $pdo->query('SELECT merged_into FROM woo_orders WHERE woo_order_id = 101')->fetchColumn() === 103, 'orders absorbed earlier move to the new main order');
check($pdo->query('SELECT id, pase_stock, actual_stock FROM products ORDER BY id')->fetchAll() === $stockBefore, 'stock still unchanged');

// --- Jeden dokument na paczkę: paragon z wFirma (BaseLinker) i wystawianie z CRM ---
$pdo->exec("UPDATE woo_orders SET merged_into = NULL, merged_at = NULL");
$pdo->exec("CREATE TABLE order_documents (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, provider TEXT, document_type TEXT,
    remote_id TEXT, status TEXT DEFAULT 'issued', message TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE (woo_order_id, provider, document_type))");
$pdo->exec("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES (7, 'wfirma', 'wFirma', 1, '{}')");
Pase\Services\DocumentIssueGuard::migrate($pdo);
$pdo->exec("UPDATE woo_orders SET local_items = NULL, pase_status = 'processing', lifecycle = 'active'");
$pdo->exec("DELETE FROM shipments");
$merge->merge(101, [102], false);
$wf = new class {
    public function findSaleDocuments(string $from, string $to): array
    {
        return ['ok' => true, 'message' => '', 'truncated' => false, 'receipts' => [
            ['id' => '172', 'number' => 'PAR 172/2026', 'type' => 'receipt_normal', 'date' => '2026-09-24', 'total' => 110.00, 'buyer' => 'Anna Kowalska', 'text' => ''],
        ]];
    }
};
$matcher = new Pase\Services\WfirmaReceiptMatcher($pdo, static fn(array $cfg): object => $wf);
$pv = $matcher->preview('2026-09-01', '2026-09-30');
check(count($pv['rows']) === 1 && $pv['rows'][0]['level'] === 'sure' && (int) $pv['rows'][0]['candidates'][0]['woo_order_id'] === 101
    && $pv['rows'][0]['candidates'][0]['merged_numbers'] === ['5002'], 'receipt with the combined amount matches the main order');
$ids = array_map(static fn(array $r): int => (int) $r['candidates'][0]['woo_order_id'], $pv['rows']);
check(!in_array(102, $ids, true), 'absorbed order is not a separate receipt candidate');
check($matcher->link('2026-09-01', '2026-09-30', ['172' => 101])['linked'] === 1, 'receipt linked to the main order');

$fakeInv = new class implements Pase\Plugin\Contract\Invoicing {
    public array $calls = [];
    public function issueDocument(array $contractor, array $positions, string $type = 'normal', array $options = []): array {
        $this->calls[] = compact('positions', 'options');
        return ['ok' => true, 'message' => 'ok', 'document_id' => 'FV-' . count($this->calls)];
    }
};
$docs = new Pase\Services\WfirmaDocumentService($pdo, static fn(array $cfg) => $fakeInv);
check(throws(fn() => $docs->issue(102, 'normal'), 'zamówieniu głównym'), 'absorbed order cannot get its own document');
$docs->issue(101, 'normal');
$names = array_column($fakeInv->calls[0]['positions'], 'name');
check(in_array('Gingerbread 1l', $names, true) && in_array('Mulled Wine 1l', $names, true) && count(array_keys($names, 'Kurier')) === 2,
    'invoice for the main order covers both orders with both deliveries');
check($fakeInv->calls[0]['options']['alreadypaid_initial'] === '110.00' && str_contains($fakeInv->calls[0]['options']['description'], '+ #5002'), 'invoice paid amount and description cover the parcel');

// Dołączone z własnym dokumentem sprzed scalenia nie trafia drugi raz na dokument głównego.
$merge->unmerge(102);
$pdo->exec("INSERT INTO order_documents (woo_order_id, integration_id, provider, document_type, remote_id) VALUES (103, 7, 'wfirma', 'receipt', 'R-103')");
$merge->merge(102, [103], true);
$docs->issue(102, 'normal');
check(!in_array('Clementine 1l', array_column($fakeInv->calls[1]['positions'], 'name'), true), 'absorbed order with its own receipt is left off the main invoice');

echo "\nWszystkie testy scalania zamówień przeszły ({$checks}).\n";
