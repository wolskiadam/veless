<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Płatności PayU przy zamówieniach: przypisanie po numerze ze sklepu (kilka sklepów PayU), dane transakcji,
// zwrot przez PayU z limitem i bez podwójnego zlecenia, zwrot z modułu Zwroty - SQLite, atrapa API.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
    if (str_starts_with($class, 'PasePlugin\\')) {
        [$vendor, $rest] = explode('\\', substr($class, 11), 2);
        require dirname(__DIR__) . '/integrations/' . strtolower($vendor) . '/' . str_replace('\\', '/', $rest) . '.php';
    }
});
use Pase\Services\OrderReturns;
use Pase\Services\PayuPayments;
use Pase\Services\PayuPayouts;
use PasePlugin\Payu\PayuClient;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE settings (setting_key VARCHAR(64) PRIMARY KEY, setting_value TEXT NULL)');
$pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type VARCHAR(48) NOT NULL, name VARCHAR(120) NOT NULL, is_active INT NOT NULL DEFAULT 1,
    config TEXT NULL, webhook_secret VARCHAR(255) NULL, access_token TEXT NULL, refresh_token TEXT NULL, token_expires_at DATETIME NULL)');
$pdo->exec('CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY, integration_id INTEGER, pase_number INTEGER, order_number TEXT, currency TEXT,
    customer_name TEXT, payload TEXT, local_items TEXT NULL)');
$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, pase_stock INTEGER, actual_stock INTEGER NULL)');
$pdo->exec('CREATE TABLE order_documents (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, provider TEXT, document_type TEXT, remote_id TEXT)');
\Pase\Services\AuditTrail::migrate($pdo);
OrderReturns::migrate($pdo);
PayuPayouts::migrate($pdo);
PayuPayments::migrate($pdo);
PayuPayments::migrate($pdo); // drugi raz bez błędu

$ins = $pdo->prepare("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES (?, 'payu', ?, 1, ?)");
$ins->execute([1, 'Sklep A', json_encode(['client_id' => '111', 'client_secret' => 'a', 'shop_id' => 'SHOPAAAA'])]);
$ins->execute([2, 'Sklep B', json_encode(['client_id' => '222', 'client_secret' => 'b', 'shop_id' => 'SHOPBBBB'])]);

// Atrapa PayU: płatność ORDERB... istnieje tylko w sklepie B (token tok-222).
$ORD = 'WZGF5FFDRJ140731GUEST000P01';
$calls = [];
$refunds = [];
$refundReply = null;
$orderStatus = 'COMPLETED';
$transport = static function (string $method, string $url, array $headers, ?string $body) use (&$calls, &$refunds, &$refundReply, &$orderStatus, $ORD): array {
    $calls[] = [$method, $url, $headers, $body];
    $path = (string) parse_url($url, PHP_URL_PATH);
    if ($path === '/pl/standard/user/oauth/authorize') {
        parse_str((string) $body, $f);
        return [200, json_encode(['access_token' => 'tok-' . $f['client_id']])];
    }
    $tok = $headers['Authorization'] ?? '';
    if (str_starts_with($path, '/api/v2_1/orders/' . $ORD) && $tok !== 'Bearer tok-222') {
        return [404, '{"status":{"statusCode":"DATA_NOT_FOUND"}}'];
    }
    if ($method === 'GET' && $path === '/api/v2_1/orders/' . $ORD) {
        return [200, json_encode(['orders' => [['orderId' => $ORD, 'extOrderId' => '501', 'orderCreateDate' => '2026-09-20T10:00:00.000+02:00', 'currencyCode' => 'PLN',
            'totalAmount' => '12000', 'status' => $orderStatus, 'payMethod' => ['type' => 'PBL'], 'buyer' => ['email' => 'jan@example.com', 'firstName' => 'Jan', 'lastName' => 'Nowak']]], 'status' => ['statusCode' => 'SUCCESS']])];
    }
    if ($method === 'GET' && $path === '/api/v2_1/orders/' . $ORD . '/transactions') {
        return [200, json_encode(['transactions' => [['payMethod' => ['value' => 'c'], 'card' => ['cardData' => ['cardNumberMasked' => '543402******4014', 'cardScheme' => 'MC']]]]])];
    }
    if ($method === 'GET' && $path === '/api/v2_1/orders/' . $ORD . '/refunds') {
        return [200, json_encode(['refunds' => array_values($refunds)])];
    }
    if ($method === 'POST' && $path === '/api/v2_1/orders/' . $ORD . '/refunds') {
        if ($refundReply !== null) { return $refundReply; }
        $r = json_decode((string) $body, true)['refund'];
        $id = (string) (5000000100 + count($refunds));
        $refunds[$id] = ['refundId' => $id, 'extRefundId' => $r['extRefundId'], 'amount' => (int) $r['amount'], 'currencyCode' => 'PLN', 'description' => $r['description'], 'status' => 'PENDING'];
        return [200, json_encode(['orderId' => $ORD, 'refund' => $refunds[$id], 'status' => ['statusCode' => 'SUCCESS']])];
    }
    if (str_starts_with($path, '/api/v2_1/orders/')) {
        return [404, '{"status":{"statusCode":"DATA_NOT_FOUND"}}'];
    }
    return [404, '{}'];
};
$payouts = new PayuPayouts($pdo, static fn(array $c): object => new PayuClient($c, $transport));
$svc = new PayuPayments($pdo, $payouts);

// Numer płatności PayU z zamówienia WooCommerce.
check(PayuPayments::candidateId(['payment_method' => 'payulistbanks', 'transaction_id' => $ORD]) === $ORD, 'PayU order id from transaction_id');
check(PayuPayments::candidateId(['payment_method' => 'bacs', 'transaction_id' => $ORD]) === null, 'transaction_id of another gateway ignored');
check(PayuPayments::candidateId(['payment_method' => 'payucreditcard', 'transaction_id' => '', 'meta_data' => [['key' => '_payu_order_id', 'value' => $ORD], ['key' => '_payu_order_status', 'value' => 'COMPLETEDXXXXXXXXXX']]]) === $ORD, 'PayU order id from plugin meta');
check(PayuPayments::eligible(['woo_order_id' => 501]) && !PayuPayments::eligible(['woo_order_id' => 9000000123]), 'Allegro orders excluded');

$payload = ['payment_method' => 'payulistbanks', 'payment_method_title' => 'PayU', 'transaction_id' => $ORD,
    'line_items' => [['id' => 55, 'name' => 'Świeca w szkle', 'sku' => 'SW-SZ', 'quantity' => 3, 'total' => '97.56', 'total_tax' => '22.44']]];
$pdo->prepare('INSERT INTO woo_orders VALUES (?, 2, 11, ?, ?, ?, ?, NULL)')->execute([501, '501', 'PLN', 'Jan N', json_encode($payload)]);
$pdo->prepare('INSERT INTO woo_orders VALUES (?, 2, 12, ?, ?, ?, ?, NULL)')->execute([502, '502', 'PLN', 'Ola', json_encode(['payment_method' => 'bacs'])]);
$order = $pdo->query('SELECT * FROM woo_orders WHERE woo_order_id = 501')->fetch();

// Automatyczne przypisanie przy otwarciu zamówienia: sklep A nie zna płatności, sklep B zna.
check($svc->sync($order) === null, 'sync without errors');
$pays = $svc->payments(501);
check(count($pays) === 1 && $pays[0]['payu_order_id'] === $ORD && (int) $pays[0]['integration_id'] === 2 && $pays[0]['linked_by'] === 'auto', 'payment linked automatically, found in the second PayU shop');
check($pays[0]['status'] === 'COMPLETED' && (int) $pays[0]['amount'] === 12000 && $pays[0]['pay_method'] === 'karta MC 543402******4014'
    && $pays[0]['buyer'] === 'Jan Nowak jan@example.com', 'transaction data stored (status, amount, card, buyer)');
check(!in_array('POST', array_column($calls, 0), true) || count(array_filter($calls, static fn($c) => $c[0] === 'POST' && !str_contains($c[1], 'oauth'))) === 0, 'linking only reads PayU');
$calls = [];
$svc->sync($order);
check(count(array_filter($calls, static fn($c) => !str_contains($c[1], 'oauth'))) === 0, 'completed payment not re-read on every open');
$pdo->exec("UPDATE payu_order_payments SET data = '{\"orderId\":\"x\"}' WHERE woo_order_id = 501");
$calls = [];
$svc->sync($order);
check(count(array_filter($calls, static fn($c) => str_contains($c[1], '/api/v2_1/orders/'))) > 0
    && isset(json_decode((string) $pdo->query('SELECT data FROM payu_order_payments WHERE woo_order_id = 501')->fetchColumn(), true)['order']), 'older saved payment re-read once for full details');
$calls = [];
$svc->sync($order);
check(count(array_filter($calls, static fn($c) => !str_contains($c[1], 'oauth'))) === 0, 'full details not re-read again');
$det = $svc->details($svc->payments(501)[0], 'Sklep testowy');
check(isset($det['Dane wpłaty']['Numer zamówienia w PayU'], $det['Przebieg operacji']['Status płatności']) && ($det['Dane wpłaty']['Sklep'] ?? '') === 'Sklep testowy', 'details grouped like the PayU panel');
check($svc->sync($pdo->query('SELECT * FROM woo_orders WHERE woo_order_id = 502')->fetch()) === null && $svc->payments(502) === [], 'order without PayU number untouched');

// Ręczne przypisanie.
try { $svc->link(502, 'abc', 'Adam'); check(false, 'bad id'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'nie wygląda'), 'invalid PayU number refused'); }
try { $svc->link(502, $ORD, 'Adam'); check(false, 'taken'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'innego zamówienia'), 'payment already linked to another order refused'); }
try { $svc->link(502, 'AAAAAAAAAAAAAAAAAAAAAAAAAA', 'Adam'); check(false, 'unknown'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'Żaden z Twoich sklepów PayU'), 'unknown payment: clear message'); }

// Zwrot.
$p = $svc->payments(501)[0];
check($svc->refundable($p) === 12000, 'refundable = paid amount');
$calls = [];
$ext = PayuPayments::newExtId();
$r = $svc->refund((int) $p['id'], 3000, 'Zwrot części', $ext, 'Adam');
$post = array_values(array_filter($calls, static fn($c) => $c[0] === 'POST' && str_ends_with($c[1], '/refunds')));
check(count($post) === 1 && json_decode((string) $post[0][3], true) === ['refund' => ['description' => 'Zwrot części', 'amount' => '3000', 'extRefundId' => $ext]]
    && $post[0][2]['Authorization'] === 'Bearer tok-222', 'refund request matches PayU schema, sent with the shop that owns the payment');
check($r['status'] === 'PENDING' && $r['refund_id'] === '5000000100' && (int) $r['amount'] === 3000 && $r['created_by'] === 'Adam', 'refund stored');
check($svc->refundable($svc->payment((int) $p['id'])) === 9000, 'refundable lowered by the refund');
$calls = [];
try { $svc->refund((int) $p['id'], 3000, '', $ext, 'Adam'); check(false, 'double'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'już zlecony'), 'double refund refused'); }
check($calls === [], 'double refund sends nothing');
try { $svc->refund((int) $p['id'], 9001, '', PayuPayments::newExtId(), 'Adam'); check(false, 'over'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), '90,00 PLN'), 'refund above refundable refused'); }

// Zwrot zrobiony w panelu PayU pojawia się w CRM; status z PayU.
$refunds['5000000100']['status'] = 'FINALIZED';
$refunds['5000000999'] = ['refundId' => '5000000999', 'extRefundId' => 'panel-1', 'amount' => 1000, 'currencyCode' => 'PLN', 'description' => 'z panelu', 'status' => 'FINALIZED'];
$svc->refresh((int) $p['id']);
$list = $svc->refunds(501);
check(count($list) === 2 && $svc->findRefund($ext)['status'] === 'FINALIZED' && in_array('panel PayU', array_column($list, 'created_by'), true), 'refund statuses synced, refund from PayU panel added');
check($svc->refundable($svc->payment((int) $p['id'])) === 8000, 'panel refund counted');

// Odrzucenie i brak odpowiedzi.
$refundReply = [400, '{"status":{"statusCode":"ERROR_VALUE_INVALID","codeLiteral":"OPENAPI_REFUND_AMOUNT_TOO_HIGH"}}'];
$e2 = PayuPayments::newExtId();
try { $svc->refund((int) $p['id'], 100, '', $e2, 'Adam'); check(false, 'rejected'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'nie przyjęło zwrotu'), 'rejected refund explained'); }
check($svc->findRefund($e2)['status'] === 'ERROR' && $svc->refundable($svc->payment((int) $p['id'])) === 8000, 'rejected refund not counted');
$refundReply = [0, ''];
$e3 = PayuPayments::newExtId();
try { $svc->refund((int) $p['id'], 100, '', $e3, 'Adam'); check(false, 'unknown'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'Sprawdź w panelu PayU'), 'no answer: check PayU'); }
check($svc->findRefund($e3)['status'] === 'UNKNOWN' && $svc->refundable($svc->payment((int) $p['id'])) === 7900, 'unknown refund counted (blocks refunding the same money twice)');
$refundReply = null;

// Płatność nieopłacona - nie ma czego zwracać.
$orderStatus = 'CANCELED';
try { $svc->refund((int) $p['id'], 100, '', PayuPayments::newExtId(), 'Adam'); check(false, 'canceled'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'nie jest opłacona'), 'no refund from an unpaid payment'); }
$orderStatus = 'COMPLETED';
$svc->refresh((int) $p['id']);

// Moduł Zwroty: sposób „Przez PayU”.
$returns = new OrderReturns($pdo);
$rid = $returns->createManual(501, ['0' => 1], 'MISTAKE', 'Nie pasuje', '');
$res = $returns->refund($rid, 'payu', ['client' => $svc, 'quantities' => [0 => 1], 'comment' => 'Zwrot towaru', 'user' => 'Adam']);
$ret = $returns->find($rid);
$last = $svc->refunds(501)[0];
check($ret['refund_state'] === 'done' && $ret['refund_method'] === 'payu' && abs((float) $ret['refund_amount'] - 40.0) < 0.001 && (int) $last['amount'] === 4000
    && (int) $last['return_id'] === $rid && $ret['refund_remote_id'] === $last['refund_id'], 'return refunded through PayU, linked both ways');
$rid2 = $returns->createManual(501, ['0' => 2], 'MISTAKE', 'Reszta', '');
$res = null;
try { $returns->refund($rid2, 'payu', ['client' => $svc, 'quantities' => [0 => 2], 'delivery' => '0', 'user' => 'Adam']); check(false, 'over refundable'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'odrzucił') && $returns->find($rid2)['refund_state'] === null, 'return above refundable rejected and unlocked (nothing sent)'); }

// Hurtowe dopasowanie.
$pdo->exec('DELETE FROM payu_order_payments');
$m = $svc->matchRecent();
check($m['linked'] === 1 && $m['checked'] === 1 && count($svc->payments(501)) === 1, 'bulk matching links PayU orders');
check($svc->matchRecent()['checked'] === 0, 'already linked orders skipped');

echo "\n$checks checks passed\n";
