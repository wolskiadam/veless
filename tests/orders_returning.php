<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Lista zamówień: oznaczenie „Powracający” przy kliencie, który ma wcześniejsze zamówienia (Customers::returningFor).
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\Customers as C;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY, integration_id INTEGER, pase_number INTEGER, order_number TEXT, status TEXT,
    pase_status TEXT, currency TEXT, total REAL, customer_name TEXT, customer_email TEXT, date_created TEXT, lifecycle TEXT DEFAULT \'active\',
    local_billing_email TEXT NULL, local_billing_phone TEXT NULL, payload TEXT, updated_at TEXT DEFAULT \'2026-09-01 00:00:00\')');
$pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT)');
$pdo->exec("INSERT INTO integration_accounts VALUES (1, 'woocommerce', 'Sklep'), (3, 'allegro', 'Allegro')");
C::migrate($pdo);

$n = 0;
function woo(PDO $pdo, int $id, string $name, string $email, string $phone, string $date, string $status = 'completed'): void {
    global $n;
    $payload = ['billing' => ['first_name' => $name, 'email' => $email, 'phone' => $phone]];
    $pdo->prepare('INSERT INTO woo_orders (woo_order_id, integration_id, pase_number, order_number, pase_status, currency, total, customer_name, customer_email, date_created, payload)
        VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$id, ++$n, (string) $id, $status, 'PLN', 29.90, $name, $email, $date, json_encode($payload)]);
}
function allegro(PDO $pdo, int $id, string $buyerId, string $login, string $name, string $phone, string $date): void {
    global $n;
    $payload = ['id' => 'cf-' . $id, 'buyer' => ['id' => $buyerId, 'login' => $login, 'email' => 'u' . $id . '+abc@allegromail.pl', 'phoneNumber' => $phone]];
    $pdo->prepare('INSERT INTO woo_orders (woo_order_id, integration_id, pase_number, order_number, pase_status, currency, total, customer_name, customer_email, date_created, payload)
        VALUES (?, 3, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([9000000000 + $id, ++$n, 'cf-' . $id, 'completed', 'PLN', 59.98, $name, $payload['buyer']['email'], $date, json_encode($payload)]);
}

woo($pdo, 1, 'Anna Kowalska', 'anna@example.com', '601222333', '2026-09-10 10:00:00');
woo($pdo, 2, 'Anna Kowalska', 'ANNA@example.com', '', '2026-10-01 10:00:00');
woo($pdo, 3, 'Anna Kowalska', 'anna@example.com', '', '2026-11-05 12:00:00');
// Ten sam dzień: dokupiony produkt - to nie powrót.
woo($pdo, 10, 'Ola Trofimiuk', 'ola@example.com', '', '2026-11-06 17:30:27');
woo($pdo, 11, 'Ola Trofimiuk', 'ola@example.com', '', '2026-11-06 17:34:31');
// Wcześniejsze anulowane zamówienie się nie liczy.
woo($pdo, 20, 'Wiola Nowak', 'wiola@example.com', '', '2026-10-01 10:00:00', 'cancelled');
woo($pdo, 21, 'Wiola Nowak', 'wiola@example.com', '', '2026-11-05 10:00:00');
// Allegro: maskowane maile różne, to samo konto -> powracający; inne konto -> nie.
allegro($pdo, 1, 'b-robert', 'r-mazurek1979', 'Robert Mazurkiewicz', '', '2026-08-01 10:00:00');
allegro($pdo, 2, 'b-robert', 'r-mazurek1979', 'Robert Mazurkiewicz', '', '2026-11-07 06:40:04');
allegro($pdo, 3, 'b-ktos', 'ktos', 'Ktoś Inny', '', '2026-11-07 07:00:00');
// Allegro -> sklep po telefonie kupującego (ta sama osoba).
allegro($pdo, 4, 'b-marta', 'marta_g', 'Marta Giza', '+48 700 800 900', '2026-07-01 10:00:00');
woo($pdo, 30, 'Marta Giza', 'martagiza@onet.pl', '700800900', '2026-11-04 16:53:27');

$svc = new C($pdo);
$all = array_map('intval', $pdo->query('SELECT woo_order_id FROM woo_orders')->fetchAll(PDO::FETCH_COLUMN));
// Bez wcześniejszego przeliczania klientów: returningFor przypisuje zamówienia z listy, ale nie znane wcześniej starsze.
$page = [3, 11, 10, 21, 9000000002, 9000000003, 30];
$r = $svc->returningFor($page);
check(!isset($r[3]) && !isset($r[9000000002]), 'older orders not yet known to Customers: no badge yet (no false positive)');
check((int) $pdo->query('SELECT COUNT(*) FROM customer_orders WHERE customer_id IS NOT NULL')->fetchColumn() === count($page), 'orders from the page assigned on the fly');

// Po synchronizacji (Scheduler co minutę) wszystko jest znane.
$svc->syncPending();
$r = $svc->returningFor($all);
check(($r[3]['count'] ?? 0) === 2 && $r[3]['last'] === '2026-10-01 10:00:00', 'third order: 2 earlier orders, last date');
check(($r[2]['count'] ?? 0) === 1, 'second order: returning (email case-insensitive)');
check(!isset($r[1]), 'first order: not returning');
check(!isset($r[10]) && !isset($r[11]), 'two orders the same day: not returning');
check(!isset($r[21]), 'earlier cancelled order does not count');
check(($r[9000000002]['count'] ?? 0) === 1, 'Allegro: same buyer account despite different masked emails');
check(!isset($r[9000000003]), 'Allegro: other account not matched by masked email');
check(($r[30]['count'] ?? 0) === 1, 'shop order matched with earlier Allegro order by buyer phone');

// Nowe zamówienie z listy dostaje oznaczenie od razu, bez wchodzenia na stronę Klienci.
woo($pdo, 4, 'Anna Kowalska', 'anna@example.com', '', '2026-11-07 09:00:00');
$r = $svc->returningFor([4, 3]);
check(($r[4]['count'] ?? 0) === 3, 'new order assigned on the fly and marked returning');

// Zamówienie w koszu nie liczy się jako wcześniejsze.
$pdo->exec("UPDATE woo_orders SET lifecycle = 'trashed', updated_at = '2026-09-02 00:00:00' WHERE woo_order_id = 9000000001");
$svc->syncPending();
check(!isset($svc->returningFor([9000000002])[9000000002]), 'trashed earlier order does not count');
check($svc->returningFor([]) === [] && $svc->returningFor([999]) === [], 'empty / unknown ids');

echo "\nAll $checks returning-customer checks passed.\n";
