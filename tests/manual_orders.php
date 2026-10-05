<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Zamówienia dodane ręcznie (Services\ManualOrders) i linki do płatności PayU (PayuPayments::createLink):
// walidacja formularza, zapis w formacie Woo, numery, magazyn, wpłata, link PayU bez podwójnego tworzenia,
// powiadomienie PayU (tylko odświeżenie z PayU), wpłata po opłaceniu linku - SQLite, atrapa API PayU.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
    if (str_starts_with($class, 'PasePlugin\\')) {
        [$vendor, $rest] = explode('\\', substr($class, 11), 2);
        require dirname(__DIR__) . '/integrations/' . strtolower($vendor) . '/' . str_replace('\\', '/', $rest) . '.php';
    }
});
use Pase\Services\InvoiceData;
use Pase\Services\ManualOrders;
use Pase\Services\OrderPayment;
use Pase\Services\PayuPayments;
use Pase\Services\PayuPayouts;
use PasePlugin\Payu\PayuClient;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE settings (setting_key VARCHAR(64) PRIMARY KEY, setting_value TEXT NULL)');
$pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type VARCHAR(48) NOT NULL, name VARCHAR(120) NOT NULL, is_active INT NOT NULL DEFAULT 1,
    config TEXT NULL, webhook_secret VARCHAR(255) NULL, access_token TEXT NULL, refresh_token TEXT NULL, token_expires_at DATETIME NULL)');
$pdo->exec("CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY, integration_id INTEGER, pase_number INTEGER UNIQUE, order_number TEXT, status TEXT, pase_status TEXT,
    currency TEXT, total REAL, customer_name TEXT, customer_email TEXT, date_created TEXT, client_token TEXT, payload TEXT, local_items TEXT NULL,
    local_invoice TEXT NULL, local_billing_email TEXT NULL, local_billing_phone TEXT NULL, lifecycle TEXT NOT NULL DEFAULT 'active', automations_done INT DEFAULT 0, no_shipping INT NULL)");
$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, pase_price REAL, pase_stock INTEGER, actual_stock INTEGER NULL)');
\Pase\Services\AuditTrail::migrate($pdo);
\Pase\Services\OrderMerge::migrate($pdo);
OrderPayment::migrate($pdo);
PayuPayouts::migrate($pdo);
PayuPayments::migrate($pdo);
PayuPayments::migrate($pdo);   // drugi raz bez błędu
$pdo->exec("INSERT INTO products (id, sku, name, pase_price, pase_stock, actual_stock) VALUES (1, 'WOSK-1', 'Wosk sojowy 1 kg', 39.99, 10, 4), (2, 'KNOT-5', 'Knoty 5 szt.', 9.50, 1, NULL)");
$pdo->exec("INSERT INTO woo_orders (woo_order_id, pase_number, order_number, status, payload) VALUES (55, 7, '4410', 'processing', '{}')");   // zamówienie ze sklepu

$svc = new ManualOrders($pdo);
$row = static fn(int $id): array => $pdo->query("SELECT * FROM woo_orders WHERE woo_order_id = {$id}")->fetch();

// --- walidacja ---
$bad = $svc->validate(['billing_email' => 'zly-mail', 'item_sku' => ['NIE-MA'], 'item_name' => [''], 'item_price' => [''], 'item_qty' => ['1']]);
$msg = implode(' | ', $bad['errors']);
check(str_contains($msg, 'e-mail') && str_contains($msg, 'imię i nazwisko') && str_contains($msg, 'NIE-MA'), 'bad e-mail, missing name and unknown SKU are reported');
check(in_array('Dodaj co najmniej jedną pozycję.', $svc->validate(['billing_first_name' => 'Ola', 'item_name' => ['', '']])['errors'], true), 'order without items refused');
check(str_contains(implode(' ', $svc->validate(['billing_first_name' => 'Ola', 'item_name' => ['Świeca'], 'item_price' => ['abc'], 'item_qty' => ['1']])['errors']), 'podaj cenę'), 'bad price refused');
check(str_contains(implode(' ', $svc->validate(['billing_first_name' => 'Ola', 'item_name' => ['Świeca'], 'item_price' => ['5'], 'item_qty' => ['1'], 'wants_invoice' => '1', 'billing_nip' => '1234567890'])['errors']), 'sumę kontrolną'), 'wrong NIP refused');

// --- zapis ---
$post = [
    'billing_first_name' => 'Anna', 'billing_last_name' => 'Kowalska', 'billing_company' => 'Świece Anna', 'billing_email' => 'anna@example.com', 'billing_phone' => '600 100 200',
    'billing_address_1' => 'Lipowa 3', 'billing_postcode' => '00-001', 'billing_city' => 'Warszawa', 'billing_country' => 'pl',
    'wants_invoice' => '1', 'billing_nip' => '526-104-08-28',
    'ship_different' => '1', 'shipping_first_name' => 'Jan', 'shipping_last_name' => 'Kowalski', 'shipping_address_1' => 'Polna 1', 'shipping_postcode' => '30-001', 'shipping_city' => 'Kraków',
    'item_sku' => ['WOSK-1', '', ''], 'item_name' => ['', 'Grawer na słoiku', ''], 'item_price' => ['39,99', '15', ''], 'item_qty' => ['3', '1', '1'],
    'shipping_method' => 'InPost Paczkomat', 'shipping_cost' => '12.99', 'payment_method' => 'payu_link', 'customer_note' => 'Zadzwonić przed wysyłką', 'pase_status' => 'new',
];
$ok = $svc->validate($post);
check($ok['errors'] === [], 'valid form passes: ' . implode(' ', $ok['errors']));
check(count($ok['order']['items']) === 2 && $ok['order']['items'][0]['name'] === 'Wosk sojowy 1 kg' && $ok['order']['items'][1]['sku'] === null, 'SKU fills the name from the warehouse, free item has no SKU, empty row skipped');
check($ok['order']['total'] === 147.96, 'total = 3 x 39.99 + 15 + 12.99 shipping');
$id = $svc->create($ok['order'], 'Adam');
$r = $row($id);
$p = json_decode($r['payload'], true);
check($id === ManualOrders::ID_MIN && ManualOrders::isManual($id) && !ManualOrders::isManual(55) && !ManualOrders::isManual(9000000001), 'id in the manual range');
check($r['integration_id'] === null && $r['order_number'] === 'R1' && (int) $r['pase_number'] === 8 && $r['pase_status'] === 'new', 'CRM-only order, number R1 and next CRM number');
check((float) $r['total'] === 147.96 && $r['customer_name'] === 'Anna Kowalska' && $r['customer_email'] === 'anna@example.com' && strlen((string) $r['client_token']) === 48, 'list columns and client token filled');
check($p['line_items'][0]['total'] === '119.97' && $p['shipping_lines'][0]['total'] === '12.99' && $p['payment_method'] === 'payu_link' && $p['date_paid'] === null, 'payload in Woo format, unpaid');
check($p['billing']['country'] === 'PL' && $p['shipping']['city'] === 'Kraków' && $p['customer_note'] === 'Zadzwonić przed wysyłką' && $p['crm_created_by'] === 'Adam', 'addresses, note and author stored');
$inv = InvoiceData::resolve($p, $r['local_invoice']);
check($inv['wants'] && $inv['nip'] === '526-104-08-28' && $inv['company'] === 'Świece Anna', 'invoice data with NIP for wFirma');
$st = $pdo->query('SELECT id, pase_stock, actual_stock FROM products ORDER BY id')->fetchAll();
check((int) $st[0]['pase_stock'] === 7 && (int) $st[0]['actual_stock'] === 1 && (int) $st[1]['pase_stock'] === 1, 'warehouse item lowers CRM stock (and counted actual stock), free item does not');
check((int) $pdo->query("SELECT COUNT(*) FROM audit_events WHERE order_id = {$id} AND action = 'order.created_manually'")->fetchColumn() === 1, 'creation in order history');
check(OrderPayment::state($r) === null, 'no payment recorded yet');

// Drugie zamówienie: sprzedaż więcej niż na stanie, zapłacone gotówką.
$two = $svc->validate(['billing_first_name' => 'Ola', 'item_sku' => ['KNOT-5'], 'item_price' => ['9.50'], 'item_qty' => ['3'], 'payment_method' => 'cash', 'paid' => '1']);
$id2 = $svc->create($two['order'], 'Adam');
check($id2 === $id + 1 && $row($id2)['order_number'] === 'R2', 'next manual number');
check((int) $pdo->query('SELECT pase_stock FROM products WHERE id = 2')->fetchColumn() === 0, 'stock does not go below zero');
check(OrderPayment::state($row($id2))['state'] === 'ok' && (float) $row($id2)['paid_amount'] === 28.5, '"already paid" records the full payment');
check(\Pase\Services\Customers::channelOf($row($id2))[0] === 'manual', 'customer card shows the manual channel');
$ship = static fn(int $i): string => \Pase\Services\OrderIndicators::forOrder($row($i), json_decode($row($i)['payload'], true), [])['shipping']['label'];
check($ship($id) === 'orders.indicator.shipping_needed' && $ship($id2) === 'orders.indicator.shipping_unknown', 'order with delivery needs shipping');
$id3 = $svc->create($svc->validate(['billing_first_name' => 'Ola', 'item_name' => ['Kurs online'], 'item_price' => ['99'], 'item_qty' => ['1'], 'shipping_method' => 'Kurier', 'no_shipping' => '1'])['order'], 'Adam');
check((int) $row($id3)['no_shipping'] === 1 && $ship($id3) === 'orders.indicator.shipping_not_needed', 'virtual order: no shipping icon');
$pdo->exec("UPDATE woo_orders SET no_shipping = 1 WHERE woo_order_id = 55");
check(\Pase\Services\OrderIndicators::forOrder($row(55), ['shipping_lines' => [['method_id' => 'flat_rate']], 'line_items' => [['name' => 'x']]], [])['shipping']['label'] === 'orders.indicator.shipping_not_needed', 'shop order marked virtual in CRM: no shipping icon');
check(\Pase\Services\OrderIndicators::forOrder($row(55), ['shipping_lines' => [['method_id' => 'flat_rate']]], [['status' => 'created']])['shipping']['label'] === 'orders.indicator.shipping_created', 'an existing shipment still shows');

// --- link do płatności PayU ---
$pdo->prepare("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES (3, 'payu', 'Sklep A', 1, ?)")
    ->execute([json_encode(['client_id' => '4455', 'client_secret' => 'x', 'shop_id' => 'SHOPAAAA'])]);
$PAYU = 'LNKF5FFDRJ140731GUEST000P01';
$created = [];
$payStatus = 'NEW';
$createReply = null;
$transport = static function (string $method, string $url, array $headers, ?string $body) use (&$created, &$payStatus, &$createReply, $PAYU): array {
    $path = (string) parse_url($url, PHP_URL_PATH);
    if ($path === '/pl/standard/user/oauth/authorize') {
        return [200, '{"access_token":"tok"}'];
    }
    if ($method === 'POST' && $path === '/api/v2_1/orders') {
        $created[] = json_decode((string) $body, true);
        return $createReply ?? [302, json_encode(['status' => ['statusCode' => 'SUCCESS'], 'redirectUri' => 'https://secure.payu.com/pay/?orderId=' . $PAYU . '&token=abc', 'orderId' => $PAYU, 'extOrderId' => $created[count($created) - 1]['extOrderId']])];
    }
    if ($method === 'GET' && $path === '/api/v2_1/orders/' . $PAYU) {
        return [200, json_encode(['orders' => [['orderId' => $PAYU, 'extOrderId' => 'x', 'currencyCode' => 'PLN', 'totalAmount' => '14796', 'status' => $payStatus, 'description' => 'Zamówienie #8',
            'buyer' => ['email' => 'anna@example.com']]], 'status' => ['statusCode' => 'SUCCESS']])];
    }
    if ($method === 'GET' && str_starts_with($path, '/api/v2_1/orders/')) {
        return [200, '{"refunds":[],"transactions":[]}'];
    }
    return [404, '{}'];
};
$payu = new PayuPayments($pdo, new PayuPayouts($pdo, static fn(array $c): object => new PayuClient($c, $transport)));
$ext = PayuPayments::newLinkExtId($id);
$opts = ['notifyUrl' => 'https://crm.example.pl/public/webhook_payu.php', 'continueUrl' => 'http://niebezpieczny', 'customerIp' => '203.0.113.5',
    'buyer' => ['email' => 'anna@example.com', 'phone' => '600 100 200', 'firstName' => 'Anna', 'lastName' => '']];
$failed = null;
try { $payu->createLink($id, 3, 14796, 'Zamówienie #8', 7, 'zly', 'Adam', $opts); } catch (RuntimeException $e) { $failed = $e->getMessage(); }
check($failed !== null && $created === [], 'link without a valid form token is refused before PayU');

$link = $payu->createLink($id, 3, 14796, 'Zamówienie #8', 7, $ext, 'Adam', $opts);
$req = $created[0];
check(count($created) === 1 && $req['merchantPosId'] === '4455' && $req['totalAmount'] === '14796' && $req['currencyCode'] === 'PLN' && $req['extOrderId'] === $ext, 'PayU order: POS id, amount in grosze, our extOrderId');
check($req['validityTime'] === '604800' && $req['customerIp'] === '203.0.113.5' && $req['products'][0]['unitPrice'] === '14796', 'validity 7 days, customer IP, one product line');
check($req['notifyUrl'] === 'https://crm.example.pl/public/webhook_payu.php' && !isset($req['continueUrl']), 'https notify URL sent, non-https continue URL skipped');
check($req['buyer'] === ['language' => 'pl', 'email' => 'anna@example.com', 'phone' => '600 100 200', 'firstName' => 'Anna'], 'buyer data without empty fields');
check($link['status'] === 'CREATED' && str_starts_with((string) $link['link'], 'https://secure.payu.com/pay/') && $link['payu_order_id'] === $PAYU, 'link stored');
$pays = $payu->payments($id);
check(count($pays) === 1 && $pays[0]['payu_order_id'] === $PAYU && $pays[0]['status'] === 'NEW' && $pays[0]['linked_by'] === 'link: Adam', 'payment attached to the order right away');
$again = $payu->createLink($id, 3, 14796, 'Zamówienie #8', 7, $ext, 'Adam', $opts);
check(count($created) === 1 && (int) $again['id'] === (int) $link['id'], 'resubmitting the same form returns the same link, nothing new in PayU');
check(count($payu->links($id)) === 1 && $payu->links($id)[0]['payment_status'] === 'NEW', 'links list with payment status');

// Powiadomienie: tylko numer z treści, stan czytany z PayU.
$payStatus = 'COMPLETED';
check($payu->notification('{"order":{"orderId":"INNYNUMER1234567890","status":"COMPLETED"}}') === false, 'notification for an unknown payment ignored');
check($payu->notification('nie json') === false, 'malformed notification ignored');
$pdo->exec("UPDATE payu_order_payments SET fetched_at = '2026-01-01 00:00:00'");
check($payu->notification(json_encode(['order' => ['orderId' => $PAYU, 'status' => 'COMPLETED', 'totalAmount' => '1']])) === true, 'notification for our link refreshes it from PayU');
check($payu->payments($id)[0]['status'] === 'COMPLETED' && (int) $payu->payments($id)[0]['amount'] === 14796, 'status and amount come from PayU, not from the notification body');
check((float) $row($id)['paid_amount'] === 147.96 && OrderPayment::state($row($id))['state'] === 'ok', 'paid link records the payment on the order');
$pdo->exec("UPDATE payu_order_payments SET fetched_at = '2026-01-01 00:00:00'");
$payu->notification(json_encode(['order' => ['orderId' => $PAYU]]));
check((float) $row($id)['paid_amount'] === 147.96, 'second notification does not add the payment twice');
check($payu->notification(json_encode(['order' => ['orderId' => $PAYU]])) === false, 'repeated notification right after a refresh is skipped');

// Błąd PayU: link nie powstaje, zapis błędu; kolejna próba wymaga nowego formularza.
$createReply = [400, json_encode(['status' => ['statusCode' => 'ERROR_VALUE_INVALID', 'codeLiteral' => 'ERROR_VALUE_INVALID', 'statusDesc' => 'Invalid field: customerIp']])];
$ext2 = PayuPayments::newLinkExtId($id2);
$err = null;
try { $payu->createLink($id2, 3, 2850, 'Zamówienie #9', 3, $ext2, 'Adam', []); } catch (RuntimeException $e) { $err = $e->getMessage(); }
check($err !== null && str_contains($err, 'PayU nie utworzyło linku') && str_contains($err, 'customerIp'), 'PayU error shown: ' . $err);
check($payu->links($id2)[0]['status'] === 'ERROR' && $payu->payments($id2) === [], 'failed link stored as error, no payment attached');
check($created[1]['customerIp'] === '127.0.0.1' && !isset($created[1]['buyer']) && !isset($created[1]['notifyUrl']), 'defaults without options');
$err = null;
try { $payu->createLink($id2, 3, 2850, 'Zamówienie #9', 3, $ext2, 'Adam', []); } catch (RuntimeException $e) { $err = $e->getMessage(); }
check($err !== null && count($created) === 2, 'failed form token is not sent again');
$createReply = [503, ''];
$err = null;
try { $payu->createLink($id2, 3, 2850, 'Zamówienie #9', 3, PayuPayments::newLinkExtId($id2), 'Adam', []); } catch (RuntimeException $e) { $err = $e->getMessage(); }
check($err !== null && str_starts_with($err, 'Nie wiadomo') && $payu->links($id2)[0]['status'] === 'UNKNOWN', 'no answer from PayU = unknown, check in PayU panel');

// Cron: odświeża tylko otwarte, niewygasłe linki.
$createReply = null;
$payStatus = 'NEW';
$pdo->exec("DELETE FROM payu_order_payments");
$pdo->exec("DELETE FROM payu_payment_links");
$payu->createLink($id2, 3, 2850, 'Zamówienie #9', 1, PayuPayments::newLinkExtId($id2), 'Adam', []);
check($payu->refreshOpenLinks() === 1, 'cron refreshes an open link');
$payStatus = 'CANCELED';
$payu->refreshOpenLinks();
check($payu->refreshOpenLinks() === 0 && $payu->links($id2)[0]['payment_status'] === 'CANCELED', 'cancelled / expired link no longer refreshed');
check((float) $row($id2)['paid_amount'] === 28.5, 'cancelled link does not change the payment');

echo "\n{$checks} checks passed\n";
