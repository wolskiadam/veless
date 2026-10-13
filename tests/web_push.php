<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Powiadomienia push (Services\WebPush): szyfrowanie RFC 8291, podpis VAPID, subskrypcje, „Nowe zamówienie” raz - SQLite, bez sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\WebPush;
use Pase\Support\SecretStore;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

SecretStore::useKeyFile(sys_get_temp_dir() . '/web-push-test-no-key-' . bin2hex(random_bytes(4)));
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE settings (setting_key VARCHAR(64) PRIMARY KEY, setting_value TEXT NULL)');
$pdo->exec('CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY, order_number TEXT, total REAL, currency TEXT, customer_name TEXT,
    date_created TEXT NULL, source_integration_id INT NULL)');
$pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT)');
\Pase\Automation\OrderEvents::migrate($pdo);

/** Urządzenie testowe: para kluczy P-256 + sekret auth, jak w przeglądarce. */
function device(): array
{
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    return ['key' => $k, 'p256dh' => WebPush::b64u(WebPush::rawPublic($k)), 'auth' => WebPush::b64u(random_bytes(16))];
}
/** Odszyfrowanie po stronie urządzenia (RFC 8291) - niezależnie od kodu szyfrującego. */
function decrypt(string $body, array $dev): string
{
    $salt = substr($body, 0, 16);
    $idlen = ord($body[20]);
    $asPublic = substr($body, 21, $idlen);
    $ct = substr($body, 21 + $idlen);
    $uaPublic = WebPush::b64uDecode($dev['p256dh']);
    $shared = openssl_pkey_derive(openssl_pkey_get_public(WebPush::pemFromRaw($asPublic)), $dev['key'], 32);
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, WebPush::b64uDecode($dev['auth']));
    $pt = openssl_decrypt(substr($ct, 0, -16), 'aes-128-gcm', hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt),
        OPENSSL_RAW_DATA, hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt), substr($ct, -16));
    if ($pt === false || substr($pt, -1) !== "\x02") { throw new RuntimeException('decrypt failed'); }
    return substr($pt, 0, -1);
}

// --- adresy usług push ---
check(WebPush::allowedEndpoint('https://web.push.apple.com/QGx1') && WebPush::allowedEndpoint('https://fcm.googleapis.com/fcm/send/abc')
    && WebPush::allowedEndpoint('https://updates.push.services.mozilla.com/wpush/v2/x'), 'Apple, Google and Mozilla push services are allowed');
check(!WebPush::allowedEndpoint('http://web.push.apple.com/x') && !WebPush::allowedEndpoint('https://evil.example/push.apple.com')
    && !WebPush::allowedEndpoint('https://push.apple.com.evil.example/x') && !WebPush::allowedEndpoint('https://localhost/x')
    && !WebPush::allowedEndpoint('https://web.push.apple.com:8443/x'), 'other hosts, http and ports are refused (no requests to arbitrary URLs)');

// --- klucze VAPID ---
$push = new WebPush($pdo);
$pub = $push->publicKey('https://sklep.example');
check(strlen(WebPush::b64uDecode($pub)) === 65 && WebPush::b64uDecode($pub)[0] === "\x04", 'VAPID public key is an uncompressed P-256 point');
check($push->publicKey() === $pub, 'keys are created once and reused');
check(str_contains((string) $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'WEBPUSH_VAPID_PRIVATE_KEY'")->fetchColumn(), 'PRIVATE KEY')
    && $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'WEBPUSH_SUBJECT'")->fetchColumn() === 'https://sklep.example',
    'private key and subject stored in settings');

// --- subskrypcje ---
$adam = device(); $phone2 = device(); $other = device();
try { $push->subscribe(1, 'https://evil.example/x', $adam['p256dh'], $adam['auth']); check(false, 'bad endpoint refused'); }
catch (InvalidArgumentException) { check(true, 'subscribe refuses an unknown push host'); }
try { $push->subscribe(1, 'https://web.push.apple.com/a', 'abc', $adam['auth']); check(false, 'bad key refused'); }
catch (InvalidArgumentException) { check(true, 'subscribe refuses malformed keys'); }
$push->subscribe(1, 'https://web.push.apple.com/adam', $adam['p256dh'], $adam['auth'], 'iPhone');
$push->subscribe(1, 'https://fcm.googleapis.com/fcm/send/adam2', $phone2['p256dh'], $phone2['auth']);
$push->subscribe(2, 'https://web.push.apple.com/ewa', $other['p256dh'], $other['auth']);
$push->subscribe(1, 'https://web.push.apple.com/adam', $adam['p256dh'], $adam['auth'], 'iPhone'); // ponowny zapis tego samego urządzenia
check((int) $pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn() === 3, 'same device saved again does not duplicate');

// --- wysyłka ---
$sent = [];
$devices = ['https://web.push.apple.com/adam' => $adam, 'https://fcm.googleapis.com/fcm/send/adam2' => $phone2, 'https://web.push.apple.com/ewa' => $other];
$status = [];
$push->withSender(function (string $url, string $body, array $headers) use (&$sent, &$status, $devices, $pub) {
    $sent[] = ['url' => $url, 'msg' => json_decode(decrypt($body, $devices[$url]), true), 'headers' => $headers];
    return ['status' => $status[$url] ?? 201];
});
check($push->send(['title' => 'Test', 'body' => 'zażółć'], 1) === 2 && count($sent) === 2
    && $sent[0]['msg'] === ['title' => 'Test', 'body' => 'zażółć'] && $sent[1]['msg'] === $sent[0]['msg'], 'test goes only to the user\'s devices and decrypts on the device');
$h = $sent[0]['headers'];
check($h['Content-Encoding'] === 'aes128gcm' && $h['TTL'] !== '' && str_ends_with($h['Authorization'], ', k=' . $pub), 'aes128gcm + VAPID headers');
[$jh, $jp, $js] = explode('.', substr($h['Authorization'], strlen('vapid t='), strpos($h['Authorization'], ',') - strlen('vapid t=')));
$claims = json_decode(WebPush::b64uDecode($jp), true);
$raw = WebPush::b64uDecode($js);
$int = static function (string $b): string { $b = ltrim($b, "\0"); if ($b === '' || ord($b[0]) > 0x7f) { $b = "\0" . $b; } return "\x02" . chr(strlen($b)) . $b; };
$der = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
$der = "\x30" . chr(strlen($der)) . $der;
check(openssl_verify($jh . '.' . $jp, $der, WebPush::pemFromRaw(WebPush::b64uDecode($pub)), OPENSSL_ALGO_SHA256) === 1
    && $claims['aud'] === 'https://' . parse_url($sent[0]['url'], PHP_URL_HOST) && $claims['sub'] === 'https://sklep.example' && $claims['exp'] > time(),
    'VAPID JWT is signed with the stored key, audience = push service origin');

$status['https://fcm.googleapis.com/fcm/send/adam2'] = 410;
$sent = [];
check($push->send(['title' => 'X'], 1) === 1 && (int) $pdo->query('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = 1')->fetchColumn() === 1,
    'expired subscription (410) is removed, the rest still delivered');
unset($status['https://fcm.googleapis.com/fcm/send/adam2']);

// --- nowe zamówienie ---
$pdo->exec("INSERT INTO integration_accounts (id, type, name) VALUES (3, 'allegro', 'Allegro Klub')");
$pdo->exec("INSERT INTO woo_orders VALUES (500, '1234', 129.9, 'PLN', 'Jan Kowalski', '" . gmdate('Y-m-d H:i:s') . "', 3)");
$pdo->exec("INSERT INTO woo_orders VALUES (501, '1235', 10, 'PLN', 'Stary', '" . gmdate('Y-m-d H:i:s', time() - 5 * 86400) . "', NULL)");
$payload = ['line_items' => [['name' => 'Wosk sojowy 1 kg', 'quantity' => 2], ['name' => 'Knot', 'quantity' => 1]]];
$sent = [];
check($push->notifyNewOrder(500, $payload) === 2 && count($sent) === 2, 'new order goes to every subscribed user');
check($sent[0]['msg'] === ['title' => 'Nowe zamówienie #1234', 'body' => "Allegro Klub · 129,90 zł · Jan Kowalski\n2× Wosk sojowy 1 kg, 1× Knot",
    'url' => 'order_view.php?id=500', 'tag' => 'order-500'], 'message: number, source, total, customer, items, link to the order');
$sent = [];
check($push->notifyNewOrder(500, $payload) === 0 && $sent === [], 'the same order never notifies twice');
check($push->notifyNewOrder(501, []) === 0 && $sent === [], 'old orders (history import) do not notify');

// --- zdarzenie „Pobrano zamówienie” w silniku reguł wysyła push ---
$pdo->exec("INSERT INTO woo_orders VALUES (502, 'R7', 0, 'PLN', 'Anna', '" . gmdate('Y-m-d H:i:s') . "', NULL)");
$pdo->exec('CREATE TABLE automation_rules (id INTEGER PRIMARY KEY, name TEXT, event TEXT, is_active INT, priority INT, conditions TEXT, actions TEXT, condition_match TEXT)');
$engine = new \Pase\Automation\RuleEngine($pdo, new \Pase\Repository\AutomationRuleRepository($pdo));
$sent = [];
// Silnik tworzy własny WebPush bez podstawionej wysyłki - adresy spoza listy usług push są pomijane, więc bez sieci; sprawdzamy znacznik „raz”.
$pdo->exec("UPDATE push_subscriptions SET endpoint = 'https://blocked.invalid/x'");
$engine->dispatch('order.imported', 502, ['created_via' => 'manual']);
check((int) $pdo->query("SELECT COUNT(*) FROM automation_once WHERE order_id = 502 AND event_key = 'push.new_order'")->fetchColumn() === 1,
    'rule engine "order.imported" triggers the push notification');

echo "web_push: {$checks} checks passed\n";
