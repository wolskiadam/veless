<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// TikTok Shop (Services\TiktokShop + wtyczka integrations/tiktokshop): podpis żądań, OAuth z state, odnawianie tokenu,
// import zamówień (format Woo, numery, stan zdejmowany raz i oddawany po anulowaniu), stany CRM do SKU TikTok,
// numery przesyłek do TikTok - SQLite, atrapa API TikTok sprawdzająca podpis każdego żądania.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
    if (str_starts_with($class, 'PasePlugin\\')) {
        [$vendor, $rest] = explode('\\', substr($class, 11), 2);
        require dirname(__DIR__) . '/integrations/' . strtolower($vendor) . '/' . str_replace('\\', '/', $rest) . '.php';
    }
});
use Pase\Domain\OrderStatus;
use Pase\Services\PayuPayments;
use Pase\Services\TiktokShop;
use PasePlugin\Tiktokshop\TiktokShopClient;
use PasePlugin\Tiktokshop\TiktokShopPlugin;

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
TiktokShop::migrate($pdo);
TiktokShop::migrate($pdo);   // drugi raz bez błędu
$pdo->exec("INSERT INTO products (id, sku, name, pase_stock, actual_stock) VALUES (1, 'WOSK-1', 'Wosk sojowy 1 kg', 10, 6), (2, 'KNOT-5', 'Knoty', 3, NULL), (3, 'BEZ-STANU', 'Nowy', NULL, NULL)");
$pdo->exec("INSERT INTO woo_orders (woo_order_id, pase_number, order_number, status, payload) VALUES (55, 7, '4410', 'processing', '{}')");   // zamówienie ze sklepu
$pdo->exec("INSERT INTO integration_accounts (id, type, name, config) VALUES (4, 'tiktokshop', 'TikTok PL', '" . json_encode(['app_key' => 'appk', 'app_secret' => 'sekret', 'service_id' => '7301', 'region' => 'global', 'import_days' => '3']) . "')");

// --- manifest wtyczki ---
$m = (new TiktokShopPlugin())->manifest();
check($m->type === 'tiktokshop' && $m->hasCapability('order_source') && $m->secretKeys() === ['app_secret'], 'manifest: order source (filter), secret masked');
check(!(new TiktokShopPlugin()) instanceof \Pase\Plugin\Contract\OrderSource, 'plugin does not take the Woo import path (would skip CRM stock)');

// --- podpis: przykład policzony ręcznie wg dokumentacji ---
$expected = hash_hmac('sha256', 'sekret' . '/order/202309/orders/search' . 'app_key' . 'appk' . 'page_size' . '50' . 'shop_cipher' . 'CIPH' . 'timestamp' . '1700000000' . '{"a":1}' . 'sekret', 'sekret');
check(TiktokShopClient::sign('/order/202309/orders/search', ['timestamp' => '1700000000', 'shop_cipher' => 'CIPH', 'app_key' => 'appk', 'page_size' => 50,
    'sign' => 'x', 'access_token' => 'y'], '{"a":1}', 'sekret') === $expected, 'signature: sorted params without sign/access_token, path first, body last, wrapped in secret');

// --- atrapa TikTok ---
$now = 1_790_000_000;
$clock = static function () use (&$now): int { return $now; };
$calls = [];
$tt = [
    'token' => 'tok-1', 'refresh' => 'ref-1', 'orders' => [], 'products' => [], 'inventory' => [], 'shipped' => [],
    'providers' => [['id' => 'P-DPD', 'name' => 'DPD Polska'], ['id' => 'P-INPOST', 'name' => 'InPost']],
    'inventory_fail' => [],
];
$transport = static function (string $method, string $url, array $headers, ?string $body) use (&$calls, &$tt, &$now): array {
    $calls[] = [$method, $url, $headers, $body];
    $u = parse_url($url);
    parse_str($u['query'] ?? '', $q);
    $path = $u['path'];
    if (str_starts_with($url, TiktokShopClient::AUTH_URL)) {
        if ($q['app_key'] !== 'appk' || $q['app_secret'] !== 'sekret') { return [200, '{"code":36004004,"message":"invalid app"}']; }
        if ($path === '/api/v2/token/get' && ($q['auth_code'] ?? '') === 'CODE-OK' && $q['grant_type'] === 'authorized_code') {
            return [200, json_encode(['code' => 0, 'data' => ['access_token' => 'tok-1', 'access_token_expire_in' => $now + 7 * 86400, 'refresh_token' => 'ref-1',
                'refresh_token_expire_in' => $now + 60 * 86400, 'seller_name' => 'Mój Sklep']])];
        }
        if ($path === '/api/v2/token/refresh' && ($q['refresh_token'] ?? '') === $tt['refresh'] && $q['grant_type'] === 'refresh_token') {
            $tt['token'] = 'tok-2'; $tt['refresh'] = 'ref-2';
            return [200, json_encode(['code' => 0, 'data' => ['access_token' => 'tok-2', 'access_token_expire_in' => $now + 7 * 86400, 'refresh_token' => 'ref-2']])];
        }
        return [200, '{"code":36004005,"message":"invalid auth code"}'];
    }
    // open-api: podpis, token, sklep
    $sign = $q['sign'] ?? '';
    if (!hash_equals(TiktokShopClient::sign($path, $q, (string) $body, 'sekret'), $sign)) { return [200, '{"code":106001,"message":"Invalid signature"}']; }
    if (($headers['x-tts-access-token'] ?? '') !== $tt['token']) { return [401, '{"code":105002,"message":"Expired credentials"}']; }
    if ($path === '/authorization/202309/shops') {
        return [200, json_encode(['code' => 0, 'data' => ['shops' => [['id' => '7494', 'name' => 'Mój Sklep', 'region' => 'PL', 'cipher' => 'CIPH-PL']]]])];
    }
    if (($q['shop_cipher'] ?? '') !== 'CIPH-PL') { return [200, '{"code":106011,"message":"shop_cipher invalid"}']; }
    $b = json_decode((string) $body, true);
    if ($method === 'POST' && $path === '/order/202309/orders/search') {
        $list = array_values(array_filter($tt['orders'], static fn($o) => $o['update_time'] >= $b['update_time_ge']));
        return [200, json_encode(['code' => 0, 'data' => ['orders' => $list, 'next_page_token' => '', 'total_count' => count($list)]])];
    }
    if ($method === 'POST' && $path === '/product/202309/products/search') {
        return [200, json_encode(['code' => 0, 'data' => ['products' => $tt['products'], 'next_page_token' => '']])];
    }
    if ($method === 'POST' && preg_match('~^/product/202309/products/(\w+)/inventory/update$~', $path, $mm)) {
        $sku = $b['skus'][0];
        if (in_array($sku['id'], $tt['inventory_fail'], true)) { return [200, '{"code":12052700,"message":"sku is frozen","request_id":"R1"}']; }
        $tt['inventory'][] = [$mm[1], $sku['id'], $sku['inventory'][0]['warehouse_id'] ?? '', $sku['inventory'][0]['quantity']];
        return [200, '{"code":0,"data":{}}'];
    }
    if ($method === 'GET' && preg_match('~^/logistics/202309/delivery_options/(\w+)/shipping_providers$~', $path)) {
        return [200, json_encode(['code' => 0, 'data' => ['shipping_providers' => $tt['providers']]])];
    }
    if ($method === 'POST' && preg_match('~^/fulfillment/202309/orders/(\w+)/packages$~', $path, $mm)) {
        $tt['shipped'][] = [$mm[1], $b];
        return [200, '{"code":0,"data":{"package_id":"PKG1"}}'];
    }
    return [404, '{"code":404,"message":"not found"}'];
};
$factory = static function (array $account, ?string $token, ?string $cipher) use ($transport, $clock): TiktokShopClient {
    return new TiktokShopClient($account['config'], $token, $cipher, $transport, $clock);
};
// Kolejka (INSERT IGNORE) działa tylko na MySQL - tu bez niej; wysłanie stanu do sklepu sprawdza tests/sales_mysql.php.
$svc = new TiktokShop($pdo, $factory, null, null, $clock);
$acc = static fn(): array => (new \Pase\Repository\IntegrationAccountRepository($pdo))->find(4);

// --- OAuth ---
$url = $svc->connectUrl($acc());
parse_str((string) parse_url($url, PHP_URL_QUERY), $aq);
check(str_starts_with($url, 'https://services.tiktokshop.com/open/authorize?') && $aq['service_id'] === '7301' && str_starts_with($aq['state'], '4.'), 'connect URL: consent page with service id and account-bound state');
check(!$svc->handleCallback('CODE-OK', '4.podrobiony')['ok'] && $acc()['access_token'] === null, 'forged state refused, no token saved');
$url = $svc->connectUrl($acc());
parse_str((string) parse_url($url, PHP_URL_QUERY), $aq);
$now += 7200;
check(!$svc->handleCallback('CODE-OK', $aq['state'])['ok'], 'state older than an hour refused');
$now -= 7200;
$url = $svc->connectUrl($acc());
parse_str((string) parse_url($url, PHP_URL_QUERY), $aq);
$res = $svc->handleCallback('CODE-OK', $aq['state']);
check($res['ok'] && $res['account_id'] === 4 && str_contains($res['message'], 'Mój Sklep'), 'callback: token + shop saved');
check($acc()['access_token'] === 'tok-1' && $acc()['refresh_token'] === 'ref-1' && $svc->state(4, 'SHOP_CIPHER') === 'CIPH-PL', 'token in integration_accounts, cipher in settings');
check(!$svc->handleCallback('CODE-OK', $aq['state'])['ok'], 'state is single-use');
$st = $svc->status($acc());
check($st['connected'] && str_contains($st['shop'], 'Mój Sklep'), 'status: connected');
check($svc->test($acc())['ok'], 'connection test lists shops');

// --- zamówienia ---
$addr = ['name' => 'Anna Kowalska', 'phone_number' => '(+48)600100200', 'postal_code' => '00-001', 'region_code' => 'PL',
    'address_line1' => 'Lipowa 3', 'address_line2' => 'm. 4', 'district_info' => [['address_level_name' => 'Country', 'address_name' => 'Poland'], ['address_level_name' => 'City', 'address_name' => 'Warszawa']]];
$li = static fn(string $id, string $skuId, string $sellerSku, string $price, string $name = 'Wosk sojowy', string $status = 'AWAITING_SHIPMENT'): array =>
    ['id' => $id, 'sku_id' => $skuId, 'product_id' => 'PR1', 'seller_sku' => $sellerSku, 'product_name' => $name, 'sku_name' => '1 kg', 'sale_price' => $price, 'display_status' => $status];
$tt['orders'] = [
    ['id' => '576000000000000001', 'status' => 'AWAITING_SHIPMENT', 'create_time' => $now - 3600, 'update_time' => $now - 3000, 'paid_time' => $now - 3500,
     'buyer_email' => 'v1@scs.tiktokw.eu', 'buyer_message' => 'Proszę szybko', 'recipient_address' => $addr, 'delivery_option_id' => 'DO1', 'delivery_option_name' => 'Standard',
     'shipping_type' => 'SELLER', 'payment_method_name' => 'Karta',
     'payment' => ['currency' => 'PLN', 'total_amount' => '94.97', 'shipping_fee' => '9.99', 'sub_total' => '84.98'],
     'line_items' => [$li('L1', 'S1', 'WOSK-1', '39.99'), $li('L2', 'S1', 'WOSK-1', '39.99'), $li('L3', 'S9', 'OBCY', '5.00', 'Gratis')]],
    ['id' => '576000000000000002', 'status' => 'UNPAID', 'create_time' => $now - 1000, 'update_time' => $now - 900,
     'recipient_address' => $addr + ['first_name' => 'Jan', 'last_name' => 'Nowak'], 'payment' => ['currency' => 'PLN', 'total_amount' => '12.00', 'shipping_fee' => '0'],
     'line_items' => [$li('L9', 'S2', 'KNOT-5', '12.00', 'Knoty', 'UNPAID')]],
];
$client = $svc->client($acc());
check($svc->syncOrders($acc(), $client) === 2, 'two orders imported');
$rows = $pdo->query('SELECT * FROM woo_orders WHERE woo_order_id >= ' . TiktokShop::ID_MIN . ' ORDER BY woo_order_id')->fetchAll();
check(count($rows) === 2 && (int) $rows[0]['woo_order_id'] === TiktokShop::ID_MIN && (int) $rows[1]['woo_order_id'] === TiktokShop::ID_MIN + 1
    && (int) $rows[0]['integration_id'] === 4 && $rows[0]['order_number'] === '576000000000000001', 'own id range, integration + TikTok order number');
check((int) $rows[0]['pase_number'] === 8 && (int) $rows[1]['pase_number'] === 9, 'CRM order numbers continue');
$p1 = json_decode($rows[0]['payload'], true);
check(count($p1['line_items']) === 2 && $p1['line_items'][0]['quantity'] === 2 && $p1['line_items'][0]['total'] === '79.98' && $p1['line_items'][0]['product_id'] === 1
    && $p1['line_items'][0]['name'] === 'Wosk sojowy — 1 kg' && $p1['line_items'][1]['product_id'] === 0, 'per-unit TikTok lines grouped by SKU; unknown SKU kept without product');
check($p1['billing']['first_name'] === 'Anna' && $p1['billing']['last_name'] === 'Kowalska' && $p1['billing']['city'] === 'Warszawa' && $p1['billing']['address_1'] === 'Lipowa 3'
    && $p1['billing']['address_2'] === 'm. 4' && $p1['billing']['country'] === 'PL' && $p1['billing']['email'] === 'v1@scs.tiktokw.eu', 'address mapped to Woo billing/shipping');
check($p1['total'] === '94.97' && $p1['shipping_lines'][0]['total'] === '9.99' && $p1['date_paid'] !== null && $p1['customer_note'] === 'Proszę szybko'
    && $p1['payment_method_title'] === 'TikTok Shop — Karta', 'totals, shipping, paid date, buyer note');
check($rows[0]['pase_status'] === OrderStatus::NEW && $rows[1]['pase_status'] === OrderStatus::NEW && $rows[0]['customer_name'] === 'Anna Kowalska', 'CRM status new; customer name');
$stock = static fn(int $id): array => $pdo->query("SELECT pase_stock, actual_stock FROM products WHERE id = {$id}")->fetch();
check($stock(1) === ['pase_stock' => 8, 'actual_stock' => 4] && (int) $stock(2)['pase_stock'] === 3, 'paid order takes stock (CRM + actual), unpaid does not');
check(OrderStatus::channelLabel($p1) === 'czeka na wysyłkę', 'TikTok status shown in Polish');
check(!PayuPayments::eligible(['woo_order_id' => TiktokShop::ID_MIN]) && PayuPayments::eligible(['woo_order_id' => 55]), 'no PayU section on TikTok orders');

// powtórka - idempotentnie
$now += 60;
$svc->syncOrders($acc(), $client);
check((int) $pdo->query('SELECT COUNT(*) FROM woo_orders')->fetchColumn() === 3 && (int) $stock(1)['pase_stock'] === 8, 'second sync: no duplicate order, no second stock take');

// nieopłacone -> opłacone
$tt['orders'][1]['status'] = 'AWAITING_SHIPMENT';
$tt['orders'][1]['update_time'] = $now;
$svc->syncOrders($acc(), $client);
check((int) $stock(2)['pase_stock'] === 2, 'order paid later takes stock then');

// anulowane po opłaceniu -> stan wraca, status CRM „Anulowane” (tylko gdy był „Nowe”)
$pdo->exec("UPDATE woo_orders SET pase_status = 'processing' WHERE woo_order_id = " . (TiktokShop::ID_MIN + 1));
$tt['orders'][0]['status'] = 'CANCELLED';
$tt['orders'][1]['status'] = 'CANCELLED';
$tt['orders'][0]['update_time'] = $tt['orders'][1]['update_time'] = $now + 10;
$svc->syncOrders($acc(), $client);
$svc->syncOrders($acc(), $client);
check($stock(1) === ['pase_stock' => 10, 'actual_stock' => 6] && (int) $stock(2)['pase_stock'] === 3, 'cancel in TikTok returns stock once');
check($pdo->query('SELECT pase_status FROM woo_orders WHERE woo_order_id = ' . TiktokShop::ID_MIN)->fetchColumn() === 'cancelled'
    && $pdo->query('SELECT pase_status FROM woo_orders WHERE woo_order_id = ' . (TiktokShop::ID_MIN + 1))->fetchColumn() === 'processing', 'cancel changes CRM status only when still new');

// --- stany ---
$tt['products'] = [
    ['id' => 'PR1', 'title' => 'Wosk sojowy', 'skus' => [['id' => 'S1', 'seller_sku' => 'WOSK-1', 'inventory' => [['warehouse_id' => 'WH1', 'quantity' => 50]]]]],
    ['id' => 'PR2', 'title' => 'Knoty', 'skus' => [['id' => 'S2', 'seller_sku' => 'KNOT-5', 'inventory' => [['warehouse_id' => 'WH1', 'quantity' => 1]]],
                                                  ['id' => 'S3', 'seller_sku' => 'BEZ-STANU', 'inventory' => [['warehouse_id' => 'WH1', 'quantity' => 7]]],
                                                  ['id' => 'S4', 'seller_sku' => 'NIE-MA-W-CRM', 'inventory' => [['warehouse_id' => 'WH1', 'quantity' => 2]]]]],
];
check($svc->pushStock($acc(), $client) === 2, 'stock pushed for the two SKUs known in CRM with a stock');
check($tt['inventory'] === [['PR1', 'S1', 'WH1', 10], ['PR2', 'S2', 'WH1', 3]], 'CRM stock sent to the right product, SKU and warehouse');
check($svc->pushStock($acc(), $client) === 0, 'unchanged stock not sent again');
$pdo->exec('UPDATE products SET pase_stock = -2 WHERE id = 2');
$pdo->exec('UPDATE products SET pase_stock = 9 WHERE id = 1');
$tt['inventory_fail'] = ['S2'];
check($svc->pushStock($acc(), $client) === 1 && end($tt['inventory']) === ['PR1', 'S1', 'WH1', 9], 'changed stock sent; failure does not stop others');
check(str_contains((string) $svc->status($acc())['sku_errors'][0]['error'], 'sku is frozen'), 'rejected SKU shown with TikTok message');
$tt['inventory_fail'] = [];
$svc->pushStock($acc(), $client);
check(end($tt['inventory']) === ['PR2', 'S2', 'WH1', 0] && $svc->status($acc())['sku_errors'] === [], 'negative CRM stock sent as 0, error cleared after success');
$acc4 = $acc(); $acc4['config']['push_stock'] = 'off';
$pdo->exec('UPDATE products SET pase_stock = 1 WHERE id = 1');
check($svc->pushStock($acc4, $client) === 0, 'stock push can be switched off');
$st = $svc->status($acc());
check($st['skus'] === 4 && $st['skus_linked'] === 3, 'status counts SKUs and SKUs matched to CRM');

// --- numery przesyłek ---
$tt['orders'][] = ['id' => '576000000000000003', 'status' => 'AWAITING_SHIPMENT', 'create_time' => $now, 'update_time' => $now + 20, 'paid_time' => $now,
    'recipient_address' => $addr, 'delivery_option_id' => 'DO1', 'shipping_type' => 'SELLER', 'payment' => ['currency' => 'PLN', 'total_amount' => '39.99'],
    'line_items' => [$li('L20', 'S1', 'WOSK-1', '39.99')]];
$tt['orders'][] = ['id' => '576000000000000004', 'status' => 'AWAITING_SHIPMENT', 'create_time' => $now, 'update_time' => $now + 20, 'paid_time' => $now,
    'recipient_address' => $addr, 'delivery_option_id' => 'DO2', 'shipping_type' => 'TIKTOK', 'payment' => ['currency' => 'PLN', 'total_amount' => '39.99'],
    'line_items' => [$li('L30', 'S1', 'WOSK-1', '39.99')]];
$svc->syncOrders($acc(), $client);
$o3 = (int) $pdo->query("SELECT woo_order_id FROM tiktokshop_orders WHERE tt_order_id = '576000000000000003'")->fetchColumn();
$o4 = (int) $pdo->query("SELECT woo_order_id FROM tiktokshop_orders WHERE tt_order_id = '576000000000000004'")->fetchColumn();
check($svc->pushTracking($acc(), $client) === 0 && $tt['shipped'] === [], 'no shipment yet - nothing sent');
$pdo->exec("INSERT INTO shipments (woo_order_id, courier_code, waybill_no, status) VALUES ({$o3}, 'Orlen Paczka (ręcznie)', '111', 'created'), ({$o4}, 'InPost', '222', 'created')");
check($svc->pushTracking($acc(), $client) === 0 && $svc->orderLink($o3)['tracking_state'] === 'error'
    && str_contains((string) $svc->orderLink($o3)['tracking_error'], 'Orlen'), 'unknown carrier: error with the carriers TikTok offers');
check($svc->orderLink($o4)['tracking_state'] === 'skipped', 'TikTok-shipped order: number not sent');
$pdo->exec("UPDATE shipments SET courier_code = 'InPost Paczkomat (ręcznie)', waybill_no = '6800123' WHERE woo_order_id = {$o3}");
check($svc->pushTracking($acc(), $client) === 1, 'retry after error sends the corrected number');
check($tt['shipped'] === [['576000000000000003', ['tracking_number' => '6800123', 'shipping_provider_id' => 'P-INPOST', 'order_line_item_ids' => ['L20']]]]
    && $svc->orderLink($o3)['tracking_state'] === 'sent', 'marked shipped in TikTok with InPost and all line items');
check($svc->pushTracking($acc(), $client) === 0 && count($tt['shipped']) === 1, 'number sent only once');
check(TiktokShop::matchProvider('Kurier', [['id' => 'X', 'name' => 'Only One']])['id'] === 'X' && TiktokShop::matchProvider('', $tt['providers']) === null,
    'single carrier always matches; empty courier never guesses');

// --- token: odnowienie dobę przed wygaśnięciem ---
$now += 6 * 86400 + 3600;
$svc->client($acc());
check($acc()['access_token'] === 'tok-2' && $acc()['refresh_token'] === 'ref-2', 'token refreshed before expiry');
$tt['orders'][2]['update_time'] = $now;
$r = $svc->run();
check($r['orders'] >= 1 && $svc->state(4, 'LAST_ERROR', '') === '' && $svc->state(4, 'LAST_RUN', '') !== '', 'worker run with the new token, no errors');
$pdo->exec("UPDATE integration_accounts SET access_token = 'zly' WHERE id = 4");
$svc->run();
check(str_contains((string) $svc->state(4, 'LAST_ERROR', ''), 'kod 105002'), 'API error recorded for the integration page');

echo "\n{$checks} checks passed\n";
