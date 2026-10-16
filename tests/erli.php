<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// ERLI (Services\Erli + wtyczka integrations/erli): klucz API w nagłówku Bearer, import zamówień (format Woo, numery,
// grosze, adres do faktury z NIP, punkt odbioru, stan zdejmowany raz i oddawany po anulowaniu), stany CRM do produktów
// ERLI (batch-update, błędy per produkt), numery przesyłek do ERLI - SQLite, atrapa API ERLI.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
    if (str_starts_with($class, 'PasePlugin\\')) {
        [$vendor, $rest] = explode('\\', substr($class, 11), 2);
        require dirname(__DIR__) . '/integrations/' . strtolower($vendor) . '/' . str_replace('\\', '/', $rest) . '.php';
    }
});
use Pase\Domain\OrderStatus;
use Pase\Services\Erli;
use Pase\Services\PayuPayments;
use Pase\Support\PickupPoint;
use Pase\Support\SecretStore;
use PasePlugin\Erli\ErliClient;
use PasePlugin\Erli\ErliPlugin;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE settings (setting_key VARCHAR(64) PRIMARY KEY, setting_value TEXT NULL)');
$pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type VARCHAR(48) NOT NULL, name VARCHAR(120) NOT NULL, is_active INT NOT NULL DEFAULT 1,
    config TEXT NULL, webhook_secret VARCHAR(255) NULL, access_token TEXT NULL, refresh_token TEXT NULL, expires_at DATETIME NULL)');
$pdo->exec("CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY, integration_id INTEGER, pase_number INTEGER UNIQUE, order_number TEXT, status TEXT, pase_status TEXT,
    currency TEXT, total REAL, customer_name TEXT, customer_email TEXT, date_created TEXT, client_token TEXT, payload TEXT, local_items TEXT NULL,
    lifecycle TEXT NOT NULL DEFAULT 'active', automations_done INT DEFAULT 0, no_shipping INT NULL)");
$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, pase_price REAL, pase_stock INTEGER, actual_stock INTEGER NULL)');
$pdo->exec('CREATE TABLE shipments (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, courier_code TEXT, waybill_no TEXT, status TEXT)');
Erli::migrate($pdo);
Erli::migrate($pdo);   // drugi raz bez błędu
$pdo->exec("INSERT INTO products (id, sku, name, pase_stock, actual_stock) VALUES (1, 'WOSK-1', 'Wosk sojowy 1 kg', 10, 6), (2, 'KNOT-5', 'Knoty', 3, NULL), (3, 'BEZ-STANU', 'Nowy', NULL, NULL)");
$pdo->exec("INSERT INTO woo_orders (woo_order_id, pase_number, order_number, status, payload) VALUES (55, 7, '4410', 'processing', '{}')");   // zamówienie ze sklepu
$pdo->exec("INSERT INTO integration_accounts (id, type, name, config) VALUES (4, 'erli', 'ERLI', '" . json_encode(['api_key' => 'klucz-erli', 'environment' => 'production', 'import_days' => '3']) . "')");

// --- manifest wtyczki ---
$m = (new ErliPlugin())->manifest();
check($m->type === 'erli' && $m->hasCapability('order_source') && $m->secretKeys() === ['api_key'], 'manifest: order source (filter), API key masked');
check(SecretStore::isSecretConfigKey('api_key'), 'API key encrypted in account config by SecretStore (name ends with _key)');
check(!(new ErliPlugin()) instanceof \Pase\Plugin\Contract\OrderSource, 'plugin does not take the Woo import path (would skip CRM stock)');
check((new ErliClient(['environment' => 'sandbox']))->baseUrl() === 'https://sandbox.erli.dev/svc/shop-api'
    && (new ErliClient([]))->baseUrl() === 'https://erli.pl/svc/shop-api', 'production by default, sandbox on request');

// --- atrapa ERLI ---
$now = 1_790_000_000;
$clock = static function () use (&$now): int { return $now; };
$iso = static fn(int $t): string => gmdate('Y-m-d\TH:i:s.000\Z', $t);
$calls = [];
$er = ['orders' => [], 'products' => [], 'stock' => [], 'stock_fail' => [], 'tracking' => [], 'status' => []];
$transport = static function (string $method, string $url, array $headers, ?string $body) use (&$calls, &$er): array {
    $calls[] = [$method, $url, $headers, $body];
    if (($headers['Authorization'] ?? '') !== 'Bearer klucz-erli') { return [401, '{"name":"Unauthorized","message":"Invalid token"}']; }
    $path = substr((string) parse_url($url, PHP_URL_PATH), strlen('/svc/shop-api'));
    $b = json_decode((string) $body, true);
    if ($method === 'GET' && $path === '/me') {
        return [200, json_encode(['id' => 77, 'name' => 'Klub Woskarzy', 'active' => true, 'company' => ['nip' => '1234567890', 'name' => 'KW'], 'externalMatchingPolicy' => 'enabled'])];
    }
    if ($method === 'POST' && $path === '/orders/_search') {
        if (($b['filter']['field'] ?? '') !== 'updated' || ($b['filter']['operator'] ?? '') !== '>=' || ($b['pagination']['sortField'] ?? '') !== 'updated') { return [400, '{"message":"bad search"}']; }
        $from = strtotime($b['filter']['value']);
        $list = array_values(array_filter($er['orders'], static fn($o) => strtotime($o['updated']) >= $from));
        usort($list, static fn($a, $c) => strcmp($a['updated'] . $a['id'], $c['updated'] . $c['id']));
        if (!empty($b['pagination']['after'])) {
            $list = array_values(array_filter($list, static fn($o) => strcmp($o['cursor'], $b['pagination']['after']) > 0));
        }
        return [200, json_encode(array_slice($list, 0, (int) $b['pagination']['limit']))];
    }
    if ($method === 'POST' && $path === '/products/_search') {
        $list = $er['products'];
        if (!empty($b['pagination']['after'])) {
            $list = array_values(array_filter($list, static fn($p) => strcmp((string) $p['externalId'], $b['pagination']['after']) > 0));
        }
        return [200, json_encode(array_slice($list, 0, (int) $b['pagination']['limit']))];
    }
    if ($method === 'PATCH' && $path === '/products/batch-update') {
        $out = [];
        foreach ($b as $it) {
            if (in_array($it['externalId'], $er['stock_fail'], true)) {
                $out[] = ['externalId' => $it['externalId'], 'status' => 409, 'error' => ['name' => 'Conflict', 'message' => 'frozen', 'polishMessage' => 'Stan zamrożony']];
                continue;
            }
            $er['stock'][] = [$it['externalId'], $it['stock']];
            $out[] = ['externalId' => $it['externalId'], 'status' => 200, 'result' => ['updatedFields' => ['stock']]];
        }
        return [200, json_encode($out)];
    }
    if ($method === 'PATCH' && preg_match('~^/orders/([\w-]+)/status$~', $path, $mm)) {
        $er['status'][] = [$mm[1], $b['status']];
        return [204, ''];
    }
    if ($method === 'PATCH' && preg_match('~^/orders/([\w-]+)$~', $path, $mm)) {
        $er['tracking'][] = [$mm[1], $b];
        return [202, ''];
    }
    return [404, '{"message":"not found"}'];
};
$factory = static function (array $account) use ($transport): ErliClient {
    return new ErliClient($account['config'], $transport);
};
// Kolejka (INSERT IGNORE) działa tylko na MySQL - tu bez niej.
$svc = new Erli($pdo, $factory, null, null, $clock);
$acc = static fn(): array => (new \Pase\Repository\IntegrationAccountRepository($pdo))->find(4);

// --- połączenie ---
check((new ErliPlugin())->withConfig([])->testConnection()['ok'] === false, 'no API key: test fails with a hint');
$client = $svc->client($acc());
check($client->shop()['name'] === 'Klub Woskarzy' && str_starts_with($calls[0][1], 'https://erli.pl/svc/shop-api/me'), 'shop info with Bearer key');
try { (new ErliClient(['api_key' => 'zly'], $transport))->shop(); $bad = ''; } catch (\Throwable $e) { $bad = $e->getMessage(); }
check(str_contains($bad, 'HTTP 401') && str_contains($bad, 'klucz API'), 'wrong key: readable error');

// --- zamówienia ---
$addr = ['firstName' => 'Anna', 'lastName' => 'Kowalska', 'address' => 'Lipowa 3/4', 'street' => 'Lipowa', 'buildingNumber' => '3', 'flatNumber' => '4',
    'zip' => '00-001', 'city' => 'Warszawa', 'country' => 'pl', 'phone' => '600100200'];
$item = static fn(int $id, string $ext, ?string $sku, int $qty, int $unit, string $name = 'Wosk sojowy 1 kg'): array =>
    ['id' => $id, 'externalId' => $ext, 'sku' => $sku, 'quantity' => $qty, 'unitPrice' => $unit, 'name' => $name, 'slug' => 'x', 'taxRate' => 'TAX_23'];
$er['orders'] = [
    ['id' => 'E-1', 'status' => 'purchased', 'sellerStatus' => 'readyToProcess', 'created' => $iso($now - 3600), 'updated' => $iso($now - 3000), 'purchasedAt' => $iso($now - 3500),
     'cursor' => 'c1', 'user' => ['email' => 'abc@buyer.erli.pl', 'deliveryAddress' => $addr,
        'invoiceAddress' => ['type' => 'company', 'companyName' => 'Świece sp. z o.o.', 'nip' => '5260250274', 'address' => 'Polna 1', 'zip' => '30-001', 'city' => 'Kraków', 'country' => 'pl']],
     'items' => [$item(1, '101', 'WOSK-1', 2, 3999), $item(2, '999', null, 1, 500, 'Gratis')],
     'delivery' => ['name' => 'InPost Paczkomaty 24/7', 'typeId' => 'inpostParcelLocker', 'price' => 999, 'cod' => false,
        'pickupPlace' => ['id' => 'pp-1', 'externalId' => 'WAW01M', 'name' => 'Paczkomat WAW01M', 'address' => 'Marszałkowska 1', 'zip' => '00-002', 'city' => 'Warszawa', 'provider' => 'inpost']],
     'comment' => 'Proszę szybko', 'totalPrice' => 9497, 'currency' => 'PLN', 'market' => 'pl'],
    ['id' => 'E-2', 'status' => 'pending', 'sellerStatus' => 'created', 'created' => $iso($now - 1000), 'updated' => $iso($now - 900), 'cursor' => 'c2',
     'user' => ['deliveryAddress' => ['firstName' => 'Jan', 'lastName' => 'Nowak'] + $addr],
     'items' => [$item(3, 'KNOT-5', '', 1, 1200, 'Knoty')],
     'delivery' => ['name' => 'Kurier DPD', 'typeId' => 'dpd', 'price' => 0, 'cod' => true], 'totalPrice' => 1200, 'currency' => 'PLN', 'market' => 'pl'],
];
check($svc->syncOrders($acc(), $client) === 2, 'two orders imported');
$rows = $pdo->query('SELECT * FROM woo_orders WHERE woo_order_id >= ' . Erli::ID_MIN . ' ORDER BY woo_order_id')->fetchAll();
check(count($rows) === 2 && (int) $rows[0]['woo_order_id'] === Erli::ID_MIN && (int) $rows[1]['woo_order_id'] === Erli::ID_MIN + 1
    && (int) $rows[0]['integration_id'] === 4 && $rows[0]['order_number'] === 'E-1', 'own id range, integration + ERLI order number');
check((int) $rows[0]['pase_number'] === 8 && (int) $rows[1]['pase_number'] === 9, 'CRM order numbers continue');
$p1 = json_decode($rows[0]['payload'], true);
check(count($p1['line_items']) === 2 && $p1['line_items'][0]['quantity'] === 2 && $p1['line_items'][0]['total'] === '79.98' && $p1['line_items'][0]['price'] === 39.99
    && $p1['line_items'][0]['product_id'] === 1 && $p1['line_items'][1]['product_id'] === 0 && $p1['line_items'][1]['sku'] === '999', 'grosze to PLN; SKU matched; unknown product kept without link');
check($p1['shipping']['first_name'] === 'Anna' && $p1['shipping']['address_1'] === 'Lipowa 3/4' && $p1['shipping']['city'] === 'Warszawa' && $p1['shipping']['country'] === 'PL'
    && $p1['shipping']['phone'] === '600100200', 'delivery address mapped to Woo shipping');
check($p1['billing']['company'] === 'Świece sp. z o.o.' && $p1['billing']['nip'] === '5260250274' && $p1['billing']['city'] === 'Kraków'
    && $p1['billing']['email'] === 'abc@buyer.erli.pl', 'company invoice address with NIP as billing');
check(\Pase\Services\InvoiceData::resolve($p1)['nip'] === '5260250274' && \Pase\Services\InvoiceData::resolve($p1)['wants'], 'invoice data sees the NIP');
check($p1['total'] === '94.97' && $p1['shipping_total'] === '9.99' && $p1['shipping_lines'][0]['method_title'] === 'InPost Paczkomaty 24/7' && $p1['date_paid'] !== null
    && $p1['customer_note'] === 'Proszę szybko' && $p1['payment_method_title'] === 'ERLI', 'totals, delivery, paid date, buyer note');
$pp = PickupPoint::fromOrder($p1);
check($pp !== null && $pp['id'] === 'WAW01M' && $pp['city'] === 'Warszawa', 'pickup point found by the core (InPost code)');
check($rows[0]['pase_status'] === OrderStatus::NEW && $rows[0]['customer_name'] === 'Anna Kowalska', 'CRM status new; customer name from delivery');
$p2 = json_decode($rows[1]['payload'], true);
check($p2['line_items'][0]['product_id'] === 2 && $p2['line_items'][0]['sku'] === 'KNOT-5' && $p2['payment_method'] === 'cod' && $p2['billing']['first_name'] === 'Jan',
    'no SKU: matched by ERLI externalId; COD; billing = delivery without invoice address');
$stock = static fn(int $id): array => $pdo->query("SELECT pase_stock, actual_stock FROM products WHERE id = {$id}")->fetch();
check($stock(1) === ['pase_stock' => 8, 'actual_stock' => 4] && (int) $stock(2)['pase_stock'] === 3, 'paid order takes stock (CRM + actual), unpaid does not');
check(OrderStatus::channelLabel($p1) === 'opłacone · do realizacji' && OrderStatus::channelLabel($p2) === 'oczekuje na płatność', 'ERLI status shown in Polish');
check(!PayuPayments::eligible(['woo_order_id' => Erli::ID_MIN]) && PayuPayments::eligible(['woo_order_id' => 55]), 'no PayU section on ERLI orders');

// powtórka - idempotentnie
$now += 60;
$svc->syncOrders($acc(), $client);
check((int) $pdo->query('SELECT COUNT(*) FROM woo_orders')->fetchColumn() === 3 && (int) $stock(1)['pase_stock'] === 8, 'second sync: no duplicate order, no second stock take');

// nieopłacone -> opłacone
$er['orders'][1]['status'] = 'purchased';
$er['orders'][1]['updated'] = $iso($now);
$svc->syncOrders($acc(), $client);
check((int) $stock(2)['pase_stock'] === 2, 'order paid later takes stock then');

// anulowane po opłaceniu -> stan wraca, status CRM „Anulowane” (tylko gdy był „Nowe”)
$pdo->exec("UPDATE woo_orders SET pase_status = 'processing' WHERE woo_order_id = " . (Erli::ID_MIN + 1));
$er['orders'][0]['status'] = $er['orders'][1]['status'] = 'cancelled';
$er['orders'][0]['updated'] = $er['orders'][1]['updated'] = $iso($now + 10);
$svc->syncOrders($acc(), $client);
$svc->syncOrders($acc(), $client);
check($stock(1) === ['pase_stock' => 10, 'actual_stock' => 6] && (int) $stock(2)['pase_stock'] === 3, 'cancel in ERLI returns stock once');
check($pdo->query('SELECT pase_status FROM woo_orders WHERE woo_order_id = ' . Erli::ID_MIN)->fetchColumn() === 'cancelled'
    && $pdo->query('SELECT pase_status FROM woo_orders WHERE woo_order_id = ' . (Erli::ID_MIN + 1))->fetchColumn() === 'processing', 'cancel changes CRM status only when still new');

// stronicowanie: więcej zamówień niż strona, kolejne strony po kursorze
for ($i = 0; $i < 130; $i++) {
    $er['orders'][] = ['id' => sprintf('P-%03d', $i), 'status' => 'pending', 'created' => $iso($now + 20), 'updated' => $iso($now + 20), 'cursor' => sprintf('p%03d', $i),
        'user' => ['deliveryAddress' => $addr], 'items' => [], 'delivery' => ['name' => 'DPD', 'price' => 0, 'cod' => false], 'totalPrice' => 0, 'currency' => 'PLN'];
}
check($svc->syncOrders($acc(), $client) === 132 && (int) $pdo->query("SELECT COUNT(*) FROM erli_orders WHERE erli_order_id LIKE 'P-%'")->fetchColumn() === 130,
    'orders beyond one page fetched with the cursor (plus the two at the boundary, again)');
check((int) $svc->state(4, 'ORDERS_FROM') === $now + 20, 'next sync starts from the last change seen');
$pdo->exec("DELETE FROM woo_orders WHERE woo_order_id IN (SELECT woo_order_id FROM erli_orders WHERE erli_order_id LIKE 'P-%')");
$pdo->exec("DELETE FROM erli_orders WHERE erli_order_id LIKE 'P-%'");
$er['orders'] = array_slice($er['orders'], 0, 2);

// --- stany ---
$er['products'] = [
    ['externalId' => '101', 'sku' => 'WOSK-1', 'name' => 'Wosk sojowy', 'stock' => 50],
    ['externalId' => 'KNOT-5', 'name' => 'Knoty', 'stock' => 1],
    ['externalId' => '103', 'sku' => 'BEZ-STANU', 'name' => 'Nowy', 'stock' => 7],
    ['externalId' => '104', 'sku' => 'NIE-MA-W-CRM', 'name' => 'Obcy', 'stock' => 2],
];
check($svc->pushStock($acc(), $client) === 2, 'stock pushed for the two products known in CRM with a stock');
check($er['stock'] === [['101', 10], ['KNOT-5', 3]], 'CRM stock sent by ERLI externalId (SKU, or externalId without SKU)');
check($svc->pushStock($acc(), $client) === 0, 'unchanged stock not sent again');
$pdo->exec('UPDATE products SET pase_stock = -2 WHERE id = 2');
$pdo->exec('UPDATE products SET pase_stock = 9 WHERE id = 1');
$er['stock_fail'] = ['KNOT-5'];
check($svc->pushStock($acc(), $client) === 1 && end($er['stock']) === ['101', 9], 'changed stock sent; rejected product does not stop others');
check(str_contains((string) $svc->status($acc())['stock_errors'][0]['error'], 'Stan zamrożony'), 'rejected product shown with ERLI message');
$er['stock_fail'] = [];
$svc->pushStock($acc(), $client);
check(end($er['stock']) === ['KNOT-5', 0] && $svc->status($acc())['stock_errors'] === [], 'negative CRM stock sent as 0, error cleared after success');
$acc4 = $acc(); $acc4['config']['push_stock'] = 'off';
$pdo->exec('UPDATE products SET pase_stock = 1 WHERE id = 1');
check($svc->pushStock($acc4, $client) === 0, 'stock push can be switched off');
$st = $svc->status($acc());
check($st['products'] === 4 && $st['products_linked'] === 3, 'status counts products and products matched to CRM');
$er['products'] = array_map(static fn(int $i): array => ['externalId' => sprintf('X%03d', $i), 'name' => 'P' . $i, 'stock' => 0], range(1, 150));
check($svc->refreshCatalog($acc(), $client, true) === 150 && $svc->status($acc())['products'] === 150, 'catalog paged by externalId; removed products dropped');

// --- numery przesyłek ---
$er['orders'][] = ['id' => 'E-3', 'status' => 'purchased', 'sellerStatus' => 'readyToProcess', 'created' => $iso($now), 'updated' => $iso($now + 30), 'cursor' => 'c3',
    'user' => ['deliveryAddress' => $addr], 'items' => [$item(5, '101', 'WOSK-1', 1, 3999)], 'delivery' => ['name' => 'Kurier', 'price' => 0, 'cod' => false], 'totalPrice' => 3999, 'currency' => 'PLN'];
$er['orders'][] = ['id' => 'E-4', 'status' => 'purchased', 'sellerStatus' => 'readyToProcess', 'created' => $iso($now), 'updated' => $iso($now + 30), 'cursor' => 'c4',
    'user' => ['deliveryAddress' => $addr], 'items' => [$item(6, '101', 'WOSK-1', 1, 3999)], 'delivery' => ['name' => 'Wysyłam z Erli', 'price' => 0, 'cod' => false],
    'deliveryTracking' => ['status' => 'readyToSend', 'vendor' => 'inpost', 'trackingNumber' => '6800999'], 'totalPrice' => 3999, 'currency' => 'PLN'];
$er['orders'][] = ['id' => 'E-5', 'status' => 'purchased', 'sellerStatus' => 'sent', 'created' => $iso($now), 'updated' => $iso($now + 30), 'cursor' => 'c5',
    'user' => ['deliveryAddress' => $addr], 'items' => [], 'delivery' => ['name' => 'Kurier', 'price' => 0, 'cod' => false], 'totalPrice' => 0, 'currency' => 'PLN'];
$svc->syncOrders($acc(), $client);
$wid = static fn(string $e): int => (int) $pdo->query("SELECT woo_order_id FROM erli_orders WHERE erli_order_id = '{$e}'")->fetchColumn();
check($svc->pushTracking($acc(), $client) === 0 && $er['tracking'] === [], 'no shipment yet - nothing sent');
$pdo->exec("INSERT INTO shipments (woo_order_id, courier_code, waybill_no, status) VALUES ({$wid('E-3')}, 'InPost Paczkomat (ręcznie)', '6800123', 'created'),
    ({$wid('E-4')}, 'Inne', '111', 'created'), ({$wid('E-5')}, 'DPD', '222', 'created')");
check($svc->pushTracking($acc(), $client) === 1, 'one number sent');
check($er['tracking'] === [['E-3', ['deliveryTracking' => ['status' => 'sent', 'vendor' => 'inpost', 'trackingNumber' => '6800123']]]]
    && $er['status'] === [['E-3', 'sent']] && $svc->orderLink($wid('E-3'))['tracking_state'] === 'sent', 'tracking number with InPost vendor, seller status set to sent');
check($svc->orderLink($wid('E-4'))['tracking_state'] === 'skipped', 'ERLI already has its own tracking number: not overwritten');
check($svc->orderLink($wid('E-5'))['tracking_state'] === null, 'order already sent in ERLI: nothing sent');
check($svc->pushTracking($acc(), $client) === 0 && count($er['tracking']) === 1, 'number sent only once');
check(Erli::vendorFor('Orlen Paczka (ręcznie)') === 'orlen' && Erli::vendorFor('Pocztex Kurier') === 'pocztaPolska' && Erli::vendorFor('DHL Parcel') === 'dhl'
    && Erli::vendorFor('Kurier lokalny') === 'other', 'carrier names mapped to ERLI vendors, unknown = other');

// błąd wysyłki numeru i ponowienie
$er['orders'][] = ['id' => 'E-6', 'status' => 'purchased', 'sellerStatus' => 'readyToProcess', 'created' => $iso($now), 'updated' => $iso($now + 40), 'cursor' => 'c6',
    'user' => ['deliveryAddress' => $addr], 'items' => [], 'delivery' => ['name' => 'Kurier', 'price' => 0, 'cod' => false], 'totalPrice' => 0, 'currency' => 'PLN'];
$svc->syncOrders($acc(), $client);
$pdo->exec("INSERT INTO shipments (woo_order_id, courier_code, waybill_no, status) VALUES ({$wid('E-6')}, 'GLS', '333', 'created')");
$pdo->exec("UPDATE integration_accounts SET config = '" . json_encode(['api_key' => 'zly']) . "' WHERE id = 4");
check((new Erli($pdo, $factory, null, null, $clock))->pushTracking($acc(), $svc->client($acc())) === 0
    && $svc->orderLink($wid('E-6'))['tracking_state'] === 'error' && str_contains((string) $svc->orderLink($wid('E-6'))['tracking_error'], '401'), 'rejected number recorded with the error');
$pdo->exec("UPDATE integration_accounts SET config = '" . json_encode(['api_key' => 'klucz-erli', 'import_days' => '3']) . "' WHERE id = 4");
$svc->retryTracking($wid('E-6'));
check($svc->pushTracking($acc(), $client) === 1 && end($er['tracking']) === ['E-6', ['deliveryTracking' => ['status' => 'sent', 'vendor' => 'gls', 'trackingNumber' => '333']]], 'retry sends it');

// --- cykl workera ---
$er['orders'][2]['updated'] = $iso($now + 50);
$r = $svc->run();
check($r['orders'] >= 1 && $svc->state(4, 'LAST_ERROR', '') === '' && $svc->state(4, 'LAST_RUN', '') !== '', 'worker run without errors');
$pdo->exec("UPDATE integration_accounts SET config = '" . json_encode(['api_key' => 'zly']) . "' WHERE id = 4");
$svc->run();
check(str_contains((string) $svc->state(4, 'LAST_ERROR', ''), 'HTTP 401'), 'API error recorded for the integration page');
$pdo->exec("UPDATE integration_accounts SET config = '{}' WHERE id = 4");
check($svc->run() === ['orders' => 0, 'stock' => 0, 'tracking' => 0], 'account without a key skipped');

echo "\n{$checks} checks passed\n";
