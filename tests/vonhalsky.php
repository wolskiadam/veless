<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// InPost vonHalsky (Services\Vonhalsky + wtyczka integrations/vonhalsky): OAuth2 z PKCE (strona zgody, wymiana kodu,
// wybór organizacji, odnawianie tokenu), import zamówień (format Woo, numery, łączenie linii tej samej oferty, paczkomat,
// faktura z NIP, stan zdejmowany raz i oddawany po odrzuceniu), automatyczne i ręczne przyjmowanie, stany i ceny do ofert
// (batch, błędy per oferta) - SQLite, atrapa API InPost.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
    if (str_starts_with($class, 'PasePlugin\\')) {
        [$vendor, $rest] = explode('\\', substr($class, 11), 2);
        require dirname(__DIR__) . '/integrations/' . strtolower($vendor) . '/' . str_replace('\\', '/', $rest) . '.php';
    }
});
use Pase\Domain\OrderStatus;
use Pase\Services\PayuPayments;
use Pase\Services\Vonhalsky;
use Pase\Support\PickupPoint;
use Pase\Support\SecretStore;
use PasePlugin\Vonhalsky\VonhalskyClient;
use PasePlugin\Vonhalsky\VonhalskyPlugin;

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
Vonhalsky::migrate($pdo);
Vonhalsky::migrate($pdo);   // drugi raz bez błędu
$pdo->exec("INSERT INTO settings VALUES ('APP_BASE_URL', 'https://crm.example.pl/public/')");
$pdo->exec("INSERT INTO products (id, sku, name, pase_price, pase_stock, actual_stock) VALUES (1, 'WOSK-1', 'Wosk sojowy 1 kg', 40, 10, 6), (2, 'KNOT-5', 'Knoty', 12.5, 3, NULL), (3, 'BEZ-STANU', 'Nowy', NULL, NULL, NULL)");
$pdo->exec("INSERT INTO woo_orders (woo_order_id, pase_number, order_number, status, payload) VALUES (55, 7, '4410', 'processing', '{}')");
$pdo->exec("INSERT INTO integration_accounts (id, type, name, config) VALUES (4, 'vonhalsky', 'vonHalsky', '"
    . json_encode(['client_id' => 'cid', 'client_secret' => 'csecret', 'environment' => 'production', 'import_days' => '3', 'auto_accept' => 'off']) . "')");

// --- manifest wtyczki ---
$m = (new VonhalskyPlugin())->manifest();
check($m->type === 'vonhalsky' && $m->hasCapability('order_source') && $m->secretKeys() === ['client_secret'], 'manifest: order source, client secret masked');
check(SecretStore::isSecretConfigKey('client_secret'), 'client secret encrypted in account config by SecretStore (name ends with _secret)');
check(!(new VonhalskyPlugin()) instanceof \Pase\Plugin\Contract\OrderSource, 'plugin does not take the Woo import path (would skip CRM stock)');
check((new VonhalskyClient(['environment' => 'stage']))->baseUrl() === 'https://stage-api.inpost-group.com/inpsa'
    && (new VonhalskyClient([]))->baseUrl() === 'https://api.inpost-group.com/inpsa', 'production by default, stage on request');
check((new VonhalskyPlugin())->withConfig([])->testConnection()['ok'] === false, 'no client id: test fails with a hint');

// --- atrapa InPost ---
$now = 1_790_000_000;
$clock = static function () use (&$now): int { return $now; };
$iso = static fn(int $t): string => gmdate('Y-m-d\TH:i:s\Z', $t);
$calls = [];
$vh = ['orders' => [], 'offers' => [], 'stocks' => [], 'prices' => [], 'stock_fail' => [], 'accepted' => [], 'refused' => [], 'tokens' => 0, 'accept_fail' => false];
$transport = static function (string $method, string $url, array $headers, ?string $body) use (&$calls, &$vh): array {
    $calls[] = [$method, $url, $headers, $body];
    $u = parse_url($url);
    if (($u['host'] ?? '') === 'account.inpost-group.com' && $u['path'] === '/oauth2/token') {
        if (($headers['Authorization'] ?? '') !== 'Basic ' . base64_encode('cid:csecret')) { return [401, '{"error":"invalid_client"}']; }
        parse_str((string) $body, $f);
        if ($f['grant_type'] === 'authorization_code') {
            if ($f['code'] !== 'KOD' || $f['redirect_uri'] !== 'https://crm.example.pl/public/admin/vonhalsky_connect.php' || strlen($f['code_verifier']) < 43) {
                return [400, '{"error":"invalid_grant","error_description":"bad code"}'];
            }
        } elseif ($f['grant_type'] !== 'refresh_token' || !str_starts_with($f['refresh_token'], 'RT')) {
            return [400, '{"error":"invalid_grant","error_description":"Token is not active"}'];
        }
        $vh['tokens']++;
        return [200, json_encode(['access_token' => 'AT' . $vh['tokens'], 'refresh_token' => 'RT' . $vh['tokens'], 'expires_in' => 540, 'token_type' => 'Bearer'])];
    }
    if (!preg_match('/^Bearer AT\d+$/', $headers['Authorization'] ?? '')) { return [401, '{"errorCode":"UNAUTHORIZED","errorMessage":"Invalid token"}']; }
    if (($u['host'] ?? '') !== 'api.inpost-group.com') { return [404, '']; }
    $path = substr($u['path'], strlen('/inpsa'));
    parse_str($u['query'] ?? '', $q);
    $b = json_decode((string) $body, true);
    if ($method === 'GET' && $path === '/v1/organizations') {
        return [200, json_encode([['id' => 'ORG-1', 'name' => 'Klub Woskarzy', 'type' => 'SHOP', 'status' => 'ACTIVE'], ['id' => 'ORG-2', 'name' => 'Drugi sklep']])];
    }
    if ($method === 'GET' && $path === '/v1/organizations/ORG-1/orders') {
        if (($q['sort'] ?? '') !== 'updatedAt' || empty($q['updatedAtGte']) || (int) $q['limit'] > 30) { return [400, '{"errorMessage":"bad query"}']; }
        $from = strtotime($q['updatedAtGte']);
        $list = array_values(array_filter($vh['orders'], static fn($o) => strtotime($o['updatedAt']) >= $from));
        usort($list, static fn($a, $c) => strcmp($a['updatedAt'] . $a['id'], $c['updatedAt'] . $c['id']));
        return [200, json_encode(['page' => ['limit' => (int) $q['limit'], 'offset' => (int) $q['offset'], 'total' => count($list)],
            'data' => array_slice($list, (int) $q['offset'], (int) $q['limit'])])];
    }
    if ($method === 'POST' && preg_match('~^/v1/organizations/ORG-1/orders/([\w-]+)/(accept|refuse)$~', $path, $mm)) {
        if ($vh['accept_fail']) { return [409, '{"errorCode":"CONFLICT","errorMessage":"Order cannot be accepted","details":[{"field":"status","detail":"is CANCELED"}]}']; }
        $vh[$mm[2] === 'accept' ? 'accepted' : 'refused'][] = $mm[1];
        return [202, json_encode(['commandId' => 'cmd-' . $mm[1], 'status' => 'PENDING'])];
    }
    if ($method === 'GET' && $path === '/v1/organizations/ORG-1/offers') {
        return [200, json_encode(['page' => ['total' => count($vh['offers'])],
            'data' => array_map(static fn($o) => ['metadata' => [], 'offer' => $o], array_slice($vh['offers'], (int) $q['offset'], (int) $q['limit']))])];
    }
    if ($method === 'PATCH' && $path === '/v1/organizations/ORG-1/offers/stocks') {
        $out = [];
        foreach ($b as $it) {
            $fail = in_array($it['offerId'], $vh['stock_fail'], true);
            if (!$fail) { $vh['stocks'][] = [$it['offerId'], $it['stock']]; }
            $out[] = ['commandId' => 'c-' . $it['offerId'], 'offerId' => $it['offerId'], 'status' => $fail ? 'FAILURE' : 'PENDING'];
        }
        return [202, json_encode($out)];
    }
    if ($method === 'PATCH' && $path === '/v1/organizations/ORG-1/offers/prices') {
        foreach ($b as $it) { $vh['prices'][] = [$it['offerId'], $it['price']]; }
        return [202, json_encode(array_map(static fn($it) => ['offerId' => $it['offerId'], 'status' => 'PENDING'], $b))];
    }
    return [404, '{"errorMessage":"not found"}'];
};
$factory = static function (array $account, ?string $token) use ($transport): VonhalskyClient {
    return new VonhalskyClient($account['config'], $token, $transport);
};
$svc = new Vonhalsky($pdo, $factory, null, null, $clock);
$repo = new \Pase\Repository\IntegrationAccountRepository($pdo);
$acc = static fn(): array => $repo->find(4);

// --- połączenie (OAuth2 + PKCE) ---
try { $svc->client($acc()); $err = ''; } catch (\Throwable $e) { $err = $e->getMessage(); }
check(str_contains($err, 'Połącz z InPost vonHalsky'), 'not connected: readable hint');
$url = $svc->connectUrl($acc());
parse_str((string) parse_url($url, PHP_URL_QUERY), $aq);
check(str_starts_with($url, 'https://account.inpost-group.com/oauth2/authorize?') && $aq['client_id'] === 'cid' && $aq['response_type'] === 'code'
    && $aq['code_challenge_method'] === 'S256' && strlen($aq['code_challenge']) === 43 && str_contains($aq['scope'], 'api:orders:write')
    && $aq['redirect_uri'] === 'https://crm.example.pl/public/admin/vonhalsky_connect.php' && str_starts_with($aq['state'], '4.'), 'consent URL with PKCE challenge, scopes and redirect URI');
$verifier = explode('|', (string) $svc->state(4, 'OAUTH_STATE'))[2];
check(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=') === $aq['code_challenge'], 'challenge = S256 of the saved verifier');
$bad = $svc->handleCallback('KOD', '4.zly-state');
check(!$bad['ok'] && $bad['account_id'] === 4 && str_contains($bad['message'], 'nie pasuje'), 'forged state rejected');
$url = $svc->connectUrl($acc());
parse_str((string) parse_url($url, PHP_URL_QUERY), $aq);
$res = $svc->handleCallback('KOD', $aq['state']);
check($res['ok'] && str_contains($res['message'], 'Klub Woskarzy') && str_contains($res['message'], '2 organizacje'), 'code exchanged, first organization chosen, others named');
$a = $acc();
check($a['access_token'] === 'AT1' && $a['refresh_token'] === 'RT1' && $svc->organizationId($a) === 'ORG-1' && strtotime($a['expires_at']) === $now + 540, 'tokens + expiry saved, organization remembered');
check(!$svc->handleCallback('KOD', $aq['state'])['ok'], 'state is single use');
check($svc->test($acc())['ok'], 'test lists organizations');

// odnowienie tokenu, gdy wygasa
$now += 600;
$client = $svc->client($acc());
check($acc()['access_token'] === 'AT2' && $acc()['refresh_token'] === 'RT2', 'expired access token refreshed (rotated refresh token saved)');
$calls = [];
$svc->client($acc());
check($calls === [], 'fresh token reused without refresh');

// --- zamówienia ---
$addr = ['street' => 'Lipowa', 'building' => '3', 'flat' => '4', 'postCode' => '00-001', 'city' => 'Warszawa', 'countryCode' => 'PL'];
$line = static fn(string $offer, ?string $sku, float $price, string $name = 'Wosk sojowy 1 kg', ?string $ext = null): array =>
    ['offer' => ['offerId' => $offer, 'externalId' => $ext, 'product' => ['productId' => 'p-' . $offer, 'name' => $name, 'sku' => $sku, 'ean' => '5901234123457'],
        'finalPrice' => ['amount' => $price, 'currency' => 'PLN'], 'basePrice' => ['amount' => $price, 'currency' => 'PLN']]];
$vh['orders'] = [
    ['id' => 'VH-1', 'organizationId' => 'ORG-1', 'status' => 'CREATED', 'createdAt' => $iso($now - 3600), 'updatedAt' => $iso($now - 3000),
     'customer' => ['email' => 'anna@example.com', 'firstName' => 'Anna', 'lastName' => 'Kowalska', 'phoneNumber' => '+48600100200', 'address' => $addr],
     'invoice' => ['email' => 'anna@example.com', 'legalForm' => 'COMPANY', 'companyName' => 'Świece sp. z o.o.', 'taxIdPrefix' => 'PL', 'taxId' => '5260250274',
        'address' => ['street' => 'Polna', 'building' => '1', 'postCode' => '30-001', 'city' => 'Kraków', 'countryCode' => 'PL']],
     'delivery' => ['deliveryType' => 'APM', 'deliveryPoint' => 'WAW01M', 'name' => 'Anna Maria Kowalska', 'phoneNumber' => '+48600100200',
        'price' => ['amount' => 9.99, 'currency' => 'PLN'], 'parcels' => []],
     'orderLines' => [$line('of-1', 'WOSK-1', 39.99), $line('of-1', 'WOSK-1', 39.99), $line('of-9', null, 5, 'Gratis', 'X-999')],
     'finalPrice' => ['amount' => 94.97, 'currency' => 'PLN'], 'basePrice' => ['amount' => 84.98, 'currency' => 'PLN'],
     'paymentDetails' => ['selectedPaymentType' => 'BLIK_CODE', 'payments' => [['paymentId' => 'pay1', 'paymentType' => 'BLIK_CODE', 'paymentDate' => $iso($now - 3500)]]],
     'comment' => 'Proszę szybko'],
    ['id' => 'VH-2', 'organizationId' => 'ORG-1', 'status' => 'ACCEPTED', 'createdAt' => $iso($now - 1000), 'updatedAt' => $iso($now - 900),
     'customer' => ['email' => 'jan@example.com', 'firstName' => 'Jan', 'lastName' => 'Nowak', 'phoneNumber' => '+48500100200', 'address' => $addr],
     'delivery' => ['deliveryType' => 'COURIER', 'address' => $addr, 'price' => ['amount' => 0, 'currency' => 'PLN'], 'courierNote' => 'Domofon 12',
        'parcels' => [['trackingNumber' => '6200000000001', 'createdAt' => $iso($now - 800), 'status' => 'SENT']]],
     'orderLines' => [$line('of-2', null, 12.5, 'Knoty', 'KNOT-5')],
     'finalPrice' => ['amount' => 12.5, 'currency' => 'PLN'], 'paymentDetails' => ['selectedPaymentType' => 'CASH_ON_DELIVERY', 'payments' => []]],
];
$client = $svc->client($acc());
check($svc->syncOrders($acc(), $client) === 2, 'two orders imported');
$q = $calls[count($calls) - 1][1];
check(str_contains($q, '/v1/organizations/ORG-1/orders?updatedAtGte=') && str_contains($q, 'sort=updatedAt') && str_contains($q, 'limit=30')
    && $calls[count($calls) - 1][2]['Accept-Language'] === 'pl', 'orders query: organization, updatedAtGte, sort, page of 30, Polish messages');
$rows = $pdo->query('SELECT * FROM woo_orders WHERE woo_order_id >= ' . Vonhalsky::ID_MIN . ' ORDER BY woo_order_id')->fetchAll();
check(count($rows) === 2 && (int) $rows[0]['woo_order_id'] === Vonhalsky::ID_MIN && (int) $rows[0]['integration_id'] === 4 && $rows[0]['order_number'] === 'VH-1'
    && (int) $rows[0]['pase_number'] === 8 && (int) $rows[1]['pase_number'] === 9, 'own id range, integration, order number, CRM numbers continue');
check(Vonhalsky::isVonhalsky(Vonhalsky::ID_MIN) && !Vonhalsky::isVonhalsky(\Pase\Services\Erli::ID_MIN) && !Vonhalsky::isVonhalsky(\Pase\Services\TiktokShop::ID_MIN), 'id range does not overlap ERLI/TikTok');
$p1 = json_decode($rows[0]['payload'], true);
check(count($p1['line_items']) === 2 && $p1['line_items'][0]['quantity'] === 2 && $p1['line_items'][0]['total'] === '79.98' && $p1['line_items'][0]['price'] === 39.99
    && $p1['line_items'][0]['product_id'] === 1 && $p1['line_items'][1]['product_id'] === 0 && $p1['line_items'][1]['sku'] === 'X-999', 'lines of the same offer merged; SKU matched; unknown product kept');
check($p1['shipping']['first_name'] === 'Anna Maria' && $p1['shipping']['last_name'] === 'Kowalska' && $p1['shipping']['phone'] === '+48600100200', 'recipient name split from delivery');
check($p1['billing']['company'] === 'Świece sp. z o.o.' && $p1['billing']['nip'] === '5260250274' && $p1['billing']['city'] === 'Kraków' && $p1['billing']['email'] === 'anna@example.com',
    'company invoice with NIP as billing');
check($p1['total'] === '94.97' && $p1['shipping_total'] === '9.99' && $p1['shipping_lines'][0]['method_title'] === 'InPost Paczkomat 24/7' && $p1['date_paid'] !== null
    && $p1['customer_note'] === 'Proszę szybko' && $p1['payment_method'] === 'vonhalsky', 'totals, delivery, paid date, note');
$pp = PickupPoint::fromOrder($p1);
check($pp !== null && $pp['id'] === 'WAW01M', 'parcel locker found by the core');
check(\Pase\Services\OrderChannelIcons::carrierKey(\Pase\Services\OrderChannelIcons::deliveryMethod($p1)) === 'inpost', 'delivery column shows InPost');
$p2 = json_decode($rows[1]['payload'], true);
check($p2['line_items'][0]['product_id'] === 2 && $p2['payment_method'] === 'cod' && $p2['shipping']['address_1'] === 'Lipowa 3/4' && $p2['shipping']['first_name'] === 'Jan'
    && $p2['shipping_lines'][0]['method_title'] === 'InPost Kurier' && str_contains($p2['customer_note'], 'Domofon 12'), 'courier: address, COD, matched by externalId, courier note');
check(Vonhalsky::parcels($p2) === [['number' => '6200000000001', 'status' => 'w drodze']] && Vonhalsky::parcels($p1) === [], 'InPost parcels listed for the order page');
$stock = static fn(int $id): array => $pdo->query("SELECT pase_stock, actual_stock FROM products WHERE id = {$id}")->fetch();
check($stock(1) === ['pase_stock' => 8, 'actual_stock' => 4] && (int) $stock(2)['pase_stock'] === 2, 'created and accepted orders take stock (CRM + actual)');
check(OrderStatus::channelLabel($p1) === 'czeka na przyjęcie' && OrderStatus::channelLabel($p2) === 'przyjęte', 'vonHalsky status shown in Polish');
check($rows[0]['pase_status'] === OrderStatus::NEW && $rows[0]['customer_name'] === 'Anna Maria Kowalska', 'CRM status new');
check(!PayuPayments::eligible(['woo_order_id' => Vonhalsky::ID_MIN]) && PayuPayments::eligible(['woo_order_id' => 55]), 'no PayU section on vonHalsky orders');

$now += 60;
$svc->syncOrders($acc(), $client);
check((int) $pdo->query('SELECT COUNT(*) FROM woo_orders')->fetchColumn() === 3 && (int) $stock(1)['pase_stock'] === 8, 'second sync: no duplicate, no second stock take');

// --- przyjmowanie ---
check($svc->acceptPending($acc(), $client) === 0 && $vh['accepted'] === [], 'auto accept off: nothing accepted');
$cfg = $acc()['config'];
$repo->updateConfig(4, ['auto_accept' => 'on'] + $cfg);
$vh['accept_fail'] = true;
check($svc->acceptPending($acc(), $client) === 0, 'accept refused by InPost: not counted');
$l = $svc->orderLink(Vonhalsky::ID_MIN);
check($l['accept_state'] === 'error' && str_contains($l['accept_error'], 'is CANCELED') && (int) $l['accept_attempts'] === 1, 'accept error saved with InPost detail');
$vh['accept_fail'] = false;
check($svc->acceptPending($acc(), $client) === 1 && $vh['accepted'] === ['VH-1'] && $svc->orderLink(Vonhalsky::ID_MIN)['vh_status'] === 'ACCEPTED', 'auto accept retries and accepts');
check($svc->acceptPending($acc(), $client) === 0 && count($vh['accepted']) === 1, 'accepted once');

// ręczne odrzucenie nowego zamówienia: stan wraca, status CRM „Anulowane”
$vh['orders'][] = ['id' => 'VH-3', 'status' => 'CREATED', 'createdAt' => $iso($now), 'updatedAt' => $iso($now), 'customer' => ['email' => 'x@example.com', 'firstName' => 'Ewa', 'lastName' => 'Lis', 'phoneNumber' => '+48', 'address' => $addr],
    'delivery' => ['deliveryType' => 'APM', 'deliveryPoint' => 'KRA010', 'parcels' => []], 'orderLines' => [$line('of-1', 'WOSK-1', 39.99)], 'finalPrice' => ['amount' => 39.99, 'currency' => 'PLN'],
    'paymentDetails' => ['payments' => []]];
$repo->updateConfig(4, ['auto_accept' => 'off'] + $cfg);
$svc->syncOrders($acc(), $client);
$id3 = Vonhalsky::ID_MIN + 2;
check((int) $stock(1)['pase_stock'] === 7, 'third order takes stock');
check(!$svc->decideOrder(Vonhalsky::ID_MIN, true)['ok'], 'already accepted order cannot be decided again');
$r = $svc->decideOrder($id3, false);
check($r['ok'] && $vh['refused'] === ['VH-3'] && (int) $stock(1)['pase_stock'] === 8
    && $pdo->query("SELECT pase_status FROM woo_orders WHERE woo_order_id = {$id3}")->fetchColumn() === OrderStatus::CANCELLED, 'manual refuse: InPost told, stock back, CRM cancelled');
$now += 60;
$svc->syncOrders($acc(), $client);   // InPost jeszcze pokazuje CREATED (polecenie asynchroniczne)
check((int) $stock(1)['pase_stock'] === 8 && $svc->acceptPending($acc(), $client) === 0, 'stale CREATED after refuse: no second stock take, no accept');

// anulowanie w InPost po przyjęciu -> stan wraca, status CRM „Anulowane” tylko, gdy był „Nowe”
$pdo->exec("UPDATE woo_orders SET pase_status = 'processing' WHERE woo_order_id = " . (Vonhalsky::ID_MIN + 1));
$vh['orders'][0]['status'] = 'CANCELED';
$vh['orders'][1]['status'] = 'CANCELED';
$vh['orders'][0]['updatedAt'] = $vh['orders'][1]['updatedAt'] = $iso($now + 10);
$svc->syncOrders($acc(), $client);
$svc->syncOrders($acc(), $client);
check($stock(1) === ['pase_stock' => 10, 'actual_stock' => 6] && (int) $stock(2)['pase_stock'] === 3, 'cancel in InPost returns stock once');
check($pdo->query('SELECT pase_status FROM woo_orders WHERE woo_order_id = ' . Vonhalsky::ID_MIN)->fetchColumn() === 'cancelled'
    && $pdo->query('SELECT pase_status FROM woo_orders WHERE woo_order_id = ' . (Vonhalsky::ID_MIN + 1))->fetchColumn() === 'processing', 'cancel changes CRM status only when still new');

// stronicowanie po 30
for ($i = 0; $i < 65; $i++) {
    $vh['orders'][] = ['id' => sprintf('P-%03d', $i), 'status' => 'ACCEPTED', 'createdAt' => $iso($now + 20), 'updatedAt' => $iso($now + 20),
        'customer' => ['email' => 'p@example.com', 'firstName' => 'P', 'lastName' => 'Q', 'phoneNumber' => '+48', 'address' => $addr],
        'delivery' => ['deliveryType' => 'COURIER', 'address' => $addr, 'parcels' => []], 'orderLines' => [], 'finalPrice' => ['amount' => 0, 'currency' => 'PLN'], 'paymentDetails' => []];
}
check($svc->syncOrders($acc(), $client) === 67 && (int) $pdo->query("SELECT COUNT(*) FROM vonhalsky_orders WHERE vh_order_id LIKE 'P-%'")->fetchColumn() === 65,
    'orders beyond one page fetched with offset (plus the two at the boundary, again)');
check((int) $svc->state(4, 'ORDERS_FROM') === $now + 20, 'next sync starts from the last change seen');

// --- stany i ceny ---
$vh['offers'] = [
    ['id' => 'of-1', 'status' => 'PUBLISHED', 'externalId' => 'e1', 'product' => ['name' => 'Wosk', 'sku' => 'WOSK-1'], 'stock' => ['quantity' => 50, 'unit' => 'UNIT'], 'price' => ['grossPrice' => ['amount' => 39.99, 'currency' => 'PLN']]],
    ['id' => 'of-2', 'status' => 'SOLDOUT', 'externalId' => 'KNOT-5', 'product' => ['name' => 'Knoty'], 'stock' => ['quantity' => 0, 'unit' => 'SET'], 'price' => ['grossPrice' => ['amount' => 12, 'currency' => 'PLN']]],
    ['id' => 'of-3', 'status' => 'PUBLISHED', 'product' => ['name' => 'Nowy', 'sku' => 'BEZ-STANU'], 'stock' => ['quantity' => 7, 'unit' => 'UNIT']],
    ['id' => 'of-4', 'status' => 'PUBLISHED', 'product' => ['name' => 'Obcy', 'sku' => 'NIE-MA-W-CRM'], 'stock' => ['quantity' => 2, 'unit' => 'UNIT']],
    ['id' => 'of-5', 'status' => 'CLOSED', 'product' => ['name' => 'Zamknięta', 'sku' => 'WOSK-1'], 'stock' => ['quantity' => 1, 'unit' => 'UNIT']],
];
check($svc->pushStock($acc(), $client) === 2, 'stock pushed to the two open offers known in CRM with a stock');
check($vh['stocks'] === [['of-1', ['quantity' => 10, 'unit' => 'UNIT']], ['of-2', ['quantity' => 3, 'unit' => 'SET']]], 'stock with the offer unit; matched by SKU and by externalId; closed offer skipped');
check($vh['prices'] === [], 'prices off by default');
check($svc->pushStock($acc(), $client) === 0, 'unchanged stock not sent again');
$pdo->exec("UPDATE products SET pase_stock = 4 WHERE id = 1");
$vh['stock_fail'] = ['of-1'];
$repo->updateConfig(4, ['push_price' => 'on', 'price_multiplier' => '1,10'] + $acc()['config']);
$n = $svc->pushStock($acc(), $client);
$err = $pdo->query("SELECT error FROM vonhalsky_offers WHERE offer_id = 'of-1'")->fetchColumn();
check($n === 1 && str_contains((string) $err, 'Stan:') && $vh['prices'] === [['of-1', ['amount' => 44, 'currency' => 'PLN']], ['of-2', ['amount' => 13.75, 'currency' => 'PLN']]],
    'price × multiplier sent; stock rejected for one offer kept as its error');
$vh['stock_fail'] = [];
check($svc->pushStock($acc(), $client) === 1 && end($vh['stocks']) === ['of-1', ['quantity' => 4, 'unit' => 'UNIT']] && count($vh['prices']) === 2
    && $pdo->query("SELECT error FROM vonhalsky_offers WHERE offer_id = 'of-1'")->fetchColumn() === null, 'rejected stock retried; accepted price not resent');
$st = $svc->status($acc());
check($st['connected'] && $st['offers'] === 5 && $st['offers_linked'] === 4 && $st['org_name'] === 'Klub Woskarzy', 'status for the integration page');

// --- przebieg workera ---
$now += 3600;
$out = $svc->run();
check((string) $svc->state(4, 'LAST_ERROR') === '' && $svc->state(4, 'LAST_RUN') !== '' && isset($out['orders'], $out['accepted'], $out['stock']), 'worker run: no error');
$repo->saveTokens(4, '', 'zly', new DateTimeImmutable('@' . ($now - 10)));
$svc->run();
check(str_contains((string) $svc->state(4, 'LAST_ERROR'), 'połącz konto jeszcze raz'), 'dead refresh token: readable error on the account');

echo "\n{$checks} checks passed\n";
