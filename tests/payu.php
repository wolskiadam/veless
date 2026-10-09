<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// PayU (Payouts API): token OAuth, saldo sklepu, wypłata tylko z kwotą <= dostępne, bez podwójnego zlecenia, status - SQLite, atrapa API.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
    if (str_starts_with($class, 'PasePlugin\\')) {
        [$vendor, $rest] = explode('\\', substr($class, 11), 2);
        require dirname(__DIR__) . '/integrations/' . strtolower($vendor) . '/' . str_replace('\\', '/', $rest) . '.php';
    }
});
use Pase\Services\PayuPayouts;
use PasePlugin\Payu\PayuClient;
use PasePlugin\Payu\PayuPlugin;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE settings (setting_key VARCHAR(64) PRIMARY KEY, setting_value TEXT NULL)');
$pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type VARCHAR(48) NOT NULL, name VARCHAR(120) NOT NULL, is_active INT NOT NULL DEFAULT 1,
    config TEXT NULL, webhook_secret VARCHAR(255) NULL, access_token TEXT NULL, refresh_token TEXT NULL, token_expires_at DATETIME NULL)');
PayuPayouts::migrate($pdo);
PayuPayouts::migrate($pdo); // drugi raz bez błędu
$old = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$old->exec("CREATE TABLE payu_payouts (id INTEGER PRIMARY KEY AUTOINCREMENT, ext_payout_id VARCHAR(64) NOT NULL UNIQUE, payout_id VARCHAR(64) NULL, shop_id VARCHAR(32) NULL,
    amount INT NOT NULL, currency VARCHAR(3) NOT NULL DEFAULT 'PLN', description VARCHAR(255) NULL, status VARCHAR(20) NOT NULL, error VARCHAR(500) NULL,
    sandbox TINYINT NOT NULL DEFAULT 0, created_by VARCHAR(120) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL)");
PayuPayouts::migrate($old);
check(\Pase\Services\LowStock::columnExists($old, 'payu_payouts', 'integration_id'), 'table from the first version gets integration_id');

// Manifest wtyczki.
$m = (new PayuPlugin())->manifest();
check($m->type === 'payu' && array_column($m->fields, 'key') === ['client_id', 'client_secret', 'second_key', 'shop_id', 'environment']
    && array_column(array_filter($m->fields, static fn($f) => !empty($f['secret'])), 'key') === ['client_secret', 'second_key'], 'plugin manifest: keys, secrets masked');

// Atrapa PayU.
$calls = [];
$available = 220839;
$available2 = 5000;    // drugi sklep (inne klucze punktu płatności)
$payouts = [];
$payoutReply = null;   // wymuszona odpowiedź POST /payouts
$transport = static function (string $method, string $url, array $headers, ?string $body) use (&$calls, &$available, &$available2, &$payouts, &$payoutReply): array {
    $calls[] = [$method, $url, $headers, $body];
    $path = (string) parse_url($url, PHP_URL_PATH);
    if ($path === '/pl/standard/user/oauth/authorize') {
        parse_str((string) $body, $f);
        $keys = ['300746' => ['sekret', 'tok-1'], '999001' => ['sekret2', 'tok-2']];
        return ($f['grant_type'] ?? '') === 'client_credentials' && ($keys[$f['client_id'] ?? ''][0] ?? null) === ($f['client_secret'] ?? '')
            ? [200, json_encode(['access_token' => $keys[$f['client_id']][1], 'token_type' => 'bearer', 'expires_in' => 43199])]
            : [401, '{"error":"invalid_client"}'];
    }
    // Token należy do punktu płatności jednego sklepu - PayU nie pokaże ani nie wypłaci cudzego.
    $tokShop = ['Bearer tok-1' => 'SHOP1234', 'Bearer tok-2' => 'Sh0p2XYZ'][$headers['Authorization'] ?? ''] ?? null;
    if ($tokShop === null) {
        return [401, '{"status":{"statusCode":"UNAUTHORIZED_REQUEST"}}'];
    }
    if ($method === 'GET' && $path === '/api/v2_1/shops/Sh0p2XYZ' && $tokShop === 'Sh0p2XYZ') {
        return [200, json_encode(['shopId' => 'Sh0p2XYZ', 'name' => 'Sklep 2', 'currencyCode' => 'PLN', 'balance' => ['currencyCode' => 'PLN', 'total' => (string) $available2, 'available' => (string) $available2]])];
    }
    if ($method === 'GET' && $path === '/api/v2_1/shops/SHOP1234' && $tokShop === 'SHOP1234') {
        return [200, json_encode(['shopId' => 'SHOP1234', 'name' => 'Mój Sklep', 'currencyCode' => 'PLN',
            'balance' => ['currencyCode' => 'PLN', 'total' => (string) ($available + 5000), 'available' => (string) $available]])];
    }
    if ($method === 'GET' && str_starts_with($path, '/api/v2_1/shops/')) {
        return [404, '{"status":{"statusCode":"DATA_NOT_FOUND","code":"8354","codeLiteral":"INCORRECT_MERCHANT_POS"}}'];
    }
    if ($method === 'POST' && $path === '/api/v2_1/payouts') {
        if ($payoutReply !== null) { return $payoutReply; }
        $req = json_decode((string) $body, true);
        $ext = $req['payout']['extPayoutId'];
        if ($req['shopId'] !== $tokShop) { return [404, '{"status":{"statusCode":"DATA_NOT_FOUND","code":"8354","codeLiteral":"INCORRECT_MERCHANT_POS"}}']; }
        if ($tokShop === 'Sh0p2XYZ') {
            if ($req['payout']['amount'] > $available2) { return [403, '{"status":{"codeLiteral":"NOT_ENOUGH_FUNDS"}}']; }
            $available2 -= $req['payout']['amount'];
            $payouts[$ext] = ['payoutId' => 'two-' . $ext, 'status' => 'PENDING', 'amount' => $req['payout']['amount']];
            return [201, json_encode(['payout' => ['payoutId' => 'two-' . $ext, 'status' => 'PENDING'], 'status' => ['statusCode' => 'SUCCESS']])];
        }
        if (isset($payouts[$ext])) { return [403, '{"status":{"statusCode":"BUSINESS_ERROR","code":"8356","codeLiteral":"PAYOUT_ALREADY_EXISTS"}}']; }
        if ($req['payout']['amount'] > $available) { return [403, '{"status":{"statusCode":"BUSINESS_ERROR","code":"8352","codeLiteral":"NOT_ENOUGH_FUNDS"}}']; }
        $id = md5($ext);
        $payouts[$ext] = ['payoutId' => $id, 'status' => 'PENDING', 'amount' => $req['payout']['amount']];
        $available -= $req['payout']['amount'];
        return [201, json_encode(['payout' => ['payoutId' => $id, 'extPayoutId' => $ext, 'status' => 'PENDING'], 'status' => ['statusCode' => 'SUCCESS']])];
    }
    if ($method === 'GET' && str_starts_with($path, '/api/v2_1/payouts/')) {
        foreach ($payouts as $ext => $p) {
            if ($p['payoutId'] === basename($path)) {
                return [200, json_encode(['payout' => ['payoutId' => $p['payoutId'], 'extPayoutId' => $ext, 'amount' => $p['amount'], 'status' => 'REALIZED'], 'status' => ['statusCode' => 'SUCCESS']])];
            }
        }
        return [404, '{"status":{"statusCode":"DATA_NOT_FOUND","codeLiteral":"INCORRECT_PAYOUT"}}'];
    }
    return [404, '{}'];
};
$cfg = ['client_id' => '300746', 'client_secret' => 'sekret', 'shop_id' => 'SHOP1234', 'environment' => 'sandbox'];
// Konta PayU w integration_accounts (każdy sklep osobno); klient dostaje config swojego konta.
$make = static function (array ...$configs) use ($pdo, $transport): PayuPayouts {
    $pdo->exec("DELETE FROM integration_accounts");
    $ins = $pdo->prepare("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES (?, 'payu', ?, 1, ?)");
    foreach ($configs as $i => $c) { $ins->execute([$i + 1, $c['name'] ?? 'Sklep ' . ($i + 1), json_encode($c)]); }
    return new PayuPayouts($pdo, static fn(array $c): object => new PayuClient($c, $transport));
};

// Bez konfiguracji nic nie idzie do PayU.
$payu = $make(['environment' => 'production']);
check(!$payu->configured(1), 'not configured without keys');
try { $payu->balance(1); check(false, 'balance without keys'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'Konfiguracja → Integracje → PayU'), 'clear message where to enter keys'); }
check($calls === [], 'no calls without keys');

// Test połączenia i saldo.
$client = new PayuClient($cfg, $transport);
$t = $client->test();
check($t['ok'] && str_contains($t['message'], '2 208,39 PLN') && str_contains($t['message'], 'sandbox'), 'connection test shows available balance');
check(str_starts_with($calls[0][1], 'https://secure.snd.payu.com/pl/standard/user/oauth/authorize') && $calls[0][2]['Content-Type'] === 'application/x-www-form-urlencoded', 'sandbox OAuth endpoint, form-encoded');
check($calls[1][0] === 'GET' && $calls[1][3] === null, 'GET shop sends no body');
$bad = (new PayuClient(['client_secret' => 'zle'] + $cfg, $transport))->test();
check(!$bad['ok'] && str_contains($bad['message'], 'client_secret'), 'wrong secret: readable error');
$bad = (new PayuClient(['shop_id' => 'XXXX'] + $cfg, $transport))->test();
check(!$bad['ok'] && str_contains($bad['message'], 'shopId'), 'wrong shop id: readable error');
check((new PayuClient(['environment' => 'production'] + $cfg))->baseUrl() === 'https://secure.payu.com', 'production base URL');

$payu = $make($cfg + ['name' => 'Mój Sklep PayU']);
$b = $payu->balance(1);
check($b['available'] === 220839 && $b['total'] === 225839 && $b['currency'] === 'PLN' && $b['name'] === 'Mój Sklep', 'balance in grosze');
check($payu->lastBalance(1)['available'] === 220839, 'last balance remembered');

// Kwoty.
check(PayuPayouts::parseAmount('1 234,56') === 123456 && PayuPayouts::parseAmount('1500') === 150000 && PayuPayouts::parseAmount('0.1') === 10
    && PayuPayouts::parseAmount('12,345') === null && PayuPayouts::parseAmount('-5') === null && PayuPayouts::parseAmount('abc') === null, 'amount parsing');
check(PayuPayouts::money(220839) === '2 208,39 PLN', 'money format');
try { $payu->validateAmount('3000', 220839); check(false, 'too much'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'większa niż dostępne'), 'amount above available refused before confirmation'); }
try { $payu->validateAmount('0', 220839); check(false, 'zero'); } catch (RuntimeException) { check(true, 'zero refused'); }
check($payu->validateAmount('1000,50', 220839) === 100050, 'valid amount');

// Zlecenie wypłaty.
$calls = [];
$ext = PayuPayouts::newExtId();
$row = $payu->order(1, 100050, 'Wypłata z CRM', $ext, 'Adam');
$post = array_values(array_filter($calls, static fn($c) => $c[0] === 'POST' && str_ends_with($c[1], '/api/v2_1/payouts')));
$sent = json_decode((string) $post[0][3], true);
check(count($post) === 1 && $sent === ['shopId' => 'SHOP1234', 'payout' => ['extPayoutId' => $ext, 'amount' => 100050, 'description' => 'Wypłata z CRM']], 'payout request matches PayU schema (amount in grosze)');
$seq = array_map(static fn($c) => $c[0] . ' ' . parse_url($c[1], PHP_URL_PATH), $calls);
check(array_search('GET /api/v2_1/shops/SHOP1234', $seq, true) < array_search('POST /api/v2_1/payouts', $seq, true), 'fresh balance read before sending');
check($row['status'] === 'PENDING' && $row['payout_id'] === md5($ext) && (int) $row['amount'] === 100050 && $row['created_by'] === 'Adam' && (int) $row['sandbox'] === 1, 'payout saved with PayU id');

// Drugi raz ten sam identyfikator (odświeżenie strony / podwójne kliknięcie): nic nie idzie do PayU.
$calls = [];
try { $payu->order(1, 100050, 'Wypłata z CRM', $ext, 'Adam'); check(false, 'double'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'już zlecona'), 'double submit refused'); }
check($calls === [], 'double submit sends nothing');

// Saldo spadło między potwierdzeniem a wysłaniem.
$calls = [];
try { $payu->order(1, 150000, '', PayuPayouts::newExtId(), 'Adam'); check(false, 'over'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'Nic nie wysłano'), 'amount above fresh balance refused'); }
check(!in_array('POST /api/v2_1/payouts', array_map(static fn($c) => $c[0] . ' ' . parse_url($c[1], PHP_URL_PATH), $calls), true), 'no payout POST when balance too low');
try { $payu->order(1, 100, '', 'zly-id', 'Adam'); check(false, 'bad ext'); } catch (RuntimeException) { check(true, 'forged ext id refused'); }

// PayU odrzuca / nie odpowiada.
$payoutReply = [403, '{"status":{"statusCode":"BUSINESS_ERROR","code":"8364","codeLiteral":"PAYOUT_TOKEN_INSUFFICIENT_AUTHENTICATION_LEVEL"}}'];
$ext2 = PayuPayouts::newExtId();
try { $payu->order(1, 1000, '', $ext2, 'Adam'); check(false, 'rejected'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'nie przyjęło') && str_contains($e->getMessage(), 'uprawnień do wypłat'), 'rejection explained'); }
check($payu->find($ext2)['status'] === 'ERROR', 'rejected payout stored as ERROR');
$payoutReply = [0, ''];
$ext3 = PayuPayouts::newExtId();
try { $payu->order(1, 1000, '', $ext3, 'Adam'); check(false, 'timeout'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'Sprawdź w panelu PayU'), 'no answer: tells to check PayU panel'); }
check($payu->find($ext3)['status'] === 'UNKNOWN', 'no answer stored as UNKNOWN, not ERROR');
$payoutReply = null;

// Status.
$payu->refreshOpen();
check($payu->find($ext)['status'] === 'REALIZED', 'open payouts refreshed from PayU');
check(count($payu->history()) === 3 && $payu->history()[0]['ext_payout_id'] === $ext3, 'history newest first');
// Lista wypłat: okres (domyślnie bieżący miesiąc) + strony; wypłaty w toku zawsze widoczne.
$now = new DateTimeImmutable('2026-10-09 20:00:00');
$pdo->exec("UPDATE payu_payouts SET created_at = '2026-10-08 10:00:00'");
$ins = $pdo->prepare("INSERT INTO payu_payouts (ext_payout_id, amount, status, created_at) VALUES (?, 100, ?, ?)");
foreach ([['old-sep', 'REALIZED', '2026-09-15 10:00:00'], ['old-aug', 'REALIZED', '2026-08-01 10:00:00'], ['old-pending', 'PENDING', '2026-07-01 10:00:00'], ['early-oct', 'REALIZED', '2026-10-02 10:00:00']] as $r) { $ins->execute($r); }
$ids = static fn(array $h): array => array_column($h['rows'], 'ext_payout_id');
$m = $payu->historyPage('month', 1, 20, $now);
check($m['total'] === 5 && in_array('old-pending', $ids($m), true) && !in_array('old-sep', $ids($m), true), 'month: this month + payouts still pending, older hidden');
$w = $payu->historyPage('week', 1, 20, $now);
check($w['total'] === 4 && !in_array('early-oct', $ids($w), true), 'week: from Monday of this week (+ pending)');
$pm = $payu->historyPage('prev_month', 1, 20, $now);
check($ids($pm) === ['old-pending', 'old-sep', $ext3], 'previous month only (+ pending and unknown-status payouts)');
check($payu->historyPage('all', 1, 20, $now)['total'] === 7 && $payu->historyPage('bogus', 1, 20, $now)['total'] === 5, 'all shows everything, unknown period falls back to month');
$p1 = $payu->historyPage('all', 1, 3, $now); $p3 = $payu->historyPage('all', 3, 3, $now);
check($p1['pages'] === 3 && count($p1['rows']) === 3 && count($p3['rows']) === 1 && $payu->historyPage('all', 99, 3, $now)['page'] === 3, 'pagination');
$pdo->exec("DELETE FROM payu_payouts WHERE ext_payout_id IN ('old-sep','old-aug','old-pending','early-oct')");
check(PayuPayouts::statusLabel('REALIZED') === 'zrealizowana' && PayuPayouts::statusLabel('NEW_ONE') === 'NEW_ONE', 'status labels');

// Kilka sklepów: osobne konta, osobne klucze, wypłata idzie kluczami sklepu, z którego wypłacamy.
$cfg2 = ['name' => 'Sklep 2', 'client_id' => '999001', 'client_secret' => 'sekret2', 'shop_id' => 'Sh0p2XYZ', 'environment' => 'sandbox'];
$payu = $make($cfg + ['name' => 'Mój Sklep PayU'], $cfg2);
check(array_column($payu->accounts(), 'name') === ['Mój Sklep PayU', 'Sklep 2'], 'two PayU shops listed');
check($payu->balance(1)['available'] === $available && $payu->balance(2)['available'] === 5000 && $payu->lastBalance(2)['name'] === 'Sklep 2', 'balance per shop, remembered per shop');
try { $payu->order(2, 6000, '', PayuPayouts::newExtId(), 'Adam'); check(false, 'over shop2'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), '50,00 PLN'), 'limit is the balance of the chosen shop, not the other'); }
$calls = [];
$row = $payu->order(2, 4000, 'Z drugiego', $ext4 = PayuPayouts::newExtId(), 'Adam');
$post = array_values(array_filter($calls, static fn($c) => $c[0] === 'POST' && str_ends_with($c[1], '/api/v2_1/payouts')));
check($row['status'] === 'PENDING' && (int) $row['integration_id'] === 2 && $row['shop_id'] === 'Sh0p2XYZ' && $post[0][2]['Authorization'] === 'Bearer tok-2'
    && $payu->shopLabel($row) === 'Sklep 2', 'payout from the second shop with its own keys');
$payu->refreshOpen();
check($payu->find($ext4)['status'] === 'REALIZED', 'status of the second shop payout read with its keys');
$pdo->exec("UPDATE payu_payouts SET integration_id = NULL, status = 'PENDING' WHERE ext_payout_id = " . $pdo->quote($ext));
$payu->refreshOpen();
check($payu->find($ext)['status'] === 'REALIZED', 'older payout without shop id refreshed with the shop matching its shopId');
try { $payu->balance(99); check(false, 'unknown account'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'Nie ma takiego sklepu'), 'unknown shop refused'); }

echo "\n$checks checks passed\n";
