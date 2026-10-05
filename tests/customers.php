<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Klienci: łączenie zamówień ze sklepów i Allegro w klienta, kanały, ręczne łączenie i cofanie.
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
$pdo->exec("INSERT INTO integration_accounts VALUES (1, 'woocommerce', 'Mój Sklep'), (2, 'woocommerce', 'Sklep hurt'), (3, 'allegro', 'Allegro')");
// Baza po PR #28: stara tabela customer_orders bez migawek -> migracja układa ją od nowa.
$pdo->exec('CREATE TABLE customer_orders (woo_order_id BIGINT NOT NULL PRIMARY KEY, customer_id BIGINT NOT NULL)');
$pdo->exec('INSERT INTO customer_orders VALUES (1, 1)');
C::migrate($pdo);
C::migrate($pdo);
check((int) $pdo->query('SELECT COUNT(*) FROM customer_orders')->fetchColumn() === 0, 'old customer_orders without snapshots replaced');

$n = 0;
function woo(PDO $pdo, int $id, int $shop, string $name, string $email, string $phone, float $total, string $date, string $status = 'shipped'): void {
    global $n;
    $payload = ['billing' => ['first_name' => $name, 'email' => $email, 'phone' => $phone]];
    $pdo->prepare('INSERT INTO woo_orders (woo_order_id, integration_id, pase_number, order_number, pase_status, currency, total, customer_name, customer_email, date_created, payload)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$id, $shop, ++$n, (string) $id, $status, 'PLN', $total, $name, $email, $date, json_encode($payload)]);
}
function allegro(PDO $pdo, int $id, string $buyerId, string $login, string $name, string $phone, float $total, string $date): void {
    global $n;
    $payload = ['id' => 'cf-' . $id, 'buyer' => ['id' => $buyerId, 'login' => $login, 'email' => $login . '+x' . $id . '@allegromail.pl', 'phoneNumber' => $phone],
        'delivery' => ['address' => ['phoneNumber' => '500 000 999']]];
    $pdo->prepare('INSERT INTO woo_orders (woo_order_id, integration_id, pase_number, order_number, pase_status, currency, total, customer_name, customer_email, date_created, payload)
        VALUES (?, 3, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([9000000000 + $id, ++$n, 'cf-' . $id, 'shipped', 'PLN', $total, $name, $payload['buyer']['email'], $date, json_encode($payload)]);
}

// --- Normalizacja ---
check(C::normalizeEmail(' Anna@Example.COM ') === 'anna@example.com', 'email lowercased and trimmed');
check(C::normalizeEmail('anna+x1@allegromail.pl') === null && C::normalizeEmail('abc@user.allegromail.pl') === null, 'masked Allegro email is not an identifier');
check(C::normalizePhone('+48 601-222-333') === '601222333' && C::normalizePhone('0048601222333') === '601222333', 'phone without country code');
check(C::normalizePhone('000000000') === null && C::normalizePhone('123456789') === null && C::normalizePhone('12345') === null, 'placeholder and short phones ignored');

// --- Dane ---
woo($pdo, 101, 1, 'Anna Kowalska', 'anna@example.com', '601 222 333', 100.00, '2026-01-10 10:00:00');
woo($pdo, 102, 2, 'Anna Kowalska', 'ANNA@example.com', '', 50.50, '2026-03-01 10:00:00');
allegro($pdo, 1, 'b-anna', 'ania_k', 'Anna Kowalska', '+48601222333', 30.00, '2026-05-01 10:00:00');
allegro($pdo, 2, 'b-anna', 'ania_k', 'Anna Kowalska', '', 20.00, '2026-06-01 10:00:00');
woo($pdo, 103, 1, 'Anna Kowalska', 'anna@example.com', '601222333', 999.00, '2026-06-05 10:00:00', 'cancelled');
// Mąż Anny: inny e-mail, ten sam telefon domowy -> NIE łączymy.
woo($pdo, 104, 1, 'Piotr Kowalski', 'piotr@example.com', '601222333', 70.00, '2026-02-01 10:00:00');
// Inne konto Allegro z tym samym telefonem -> NIE łączymy.
allegro($pdo, 3, 'b-other', 'inny_login', 'Ktoś Inny', '601222333', 15.00, '2026-04-01 10:00:00');
// Ta sama osoba, dwa e-maile -> osobno, do ręcznego połączenia.
woo($pdo, 105, 1, 'Jan Nowak', 'jan@work.pl', '', 40.00, '2026-02-02 10:00:00');
woo($pdo, 106, 1, 'Jan Nowak', 'jan.nowak@gmail.com', '', 60.00, '2026-02-03 10:00:00');
// Zamówienie w koszu.
woo($pdo, 107, 1, 'Ewa Kosz', 'ewa@example.com', '', 10.00, '2026-02-04 10:00:00');
$pdo->exec("UPDATE woo_orders SET lifecycle = 'trashed' WHERE woo_order_id = 107");

$svc = new C($pdo);
check($svc->pendingCount() === 10, 'all orders pending before first sync');
$r = $svc->syncPending();
check($r['assigned'] === 10 && $r['pending'] === 0 && !$r['busy'], 'sync assigns every order');
check($svc->syncPending()['assigned'] === 0, 'second sync has nothing to do');

$anna = $svc->customerIdForOrder(101);
$c = $svc->find($anna);
check($svc->customerIdForOrder(102) === $anna, 'same email in another shop = same customer');
check($svc->customerIdForOrder(9000000001) === $anna && $svc->customerIdForOrder(9000000002) === $anna, 'Allegro orders joined through the buyer phone and account');
check($c['orders_count'] === 4 && (int) $c['cancelled_count'] === 1, 'cancelled order not counted as a purchase');
check(abs((float) $c['spent_pln'] - 200.50) < 0.001 && $c['totals'] === ['PLN' => 200.5], 'spent sums non-cancelled orders');
check($c['first_order_at'] === '2026-01-10 10:00:00' && $c['last_order_at'] === '2026-06-01 10:00:00', 'first and last order');
$ch = $c['channels'];
check(count($ch) === 3 && $ch['int:1']['count'] === 1 && $ch['int:2']['count'] === 1 && $ch['int:3']['count'] === 2, 'per-channel counts (two shops + Allegro)');
check($ch['int:3']['label'] === 'Allegro' && $ch['int:1']['label'] === 'Mój Sklep', 'channel labels from integrations');
$kinds = array_column($c['keys'], 'label', 'kind');
check($kinds['email'] === 'anna@example.com' && $kinds['allegro'] === 'ania_k' && $kinds['phone'] === '601222333', 'identifiers shown on the card');
check(!in_array('500000999', array_column($c['keys'], 'label'), true), 'recipient phone from Allegro delivery is not used');

$piotr = $svc->customerIdForOrder(104);
check($piotr !== $anna, 'different emails with a shared phone stay separate');
check(in_array('601222333', array_column($svc->find($piotr)['keys'], 'label'), true), 'shared phone still shown on the other card');
check($svc->customerIdForOrder(9000000003) !== $anna, 'a different Allegro account with a shared phone stays separate');
$jan1 = $svc->customerIdForOrder(105);
$jan2 = $svc->customerIdForOrder(106);
check($jan1 !== $jan2, 'two emails of one person are not guessed');

// --- Lista ---
$list = $svc->list();
check($list['total'] === 5, 'list hides a customer with only trashed orders');
check((int) $list['rows'][0]['id'] === $anna, 'default sort: most recent buyer first');
check((int) $svc->list('', 'orders')['rows'][0]['id'] === $anna && (int) $svc->list('', 'spent')['rows'][0]['id'] === $anna, 'sort by orders and spent');
check($svc->list('', 'last', 2)['total'] === 1, 'returning-only filter');
check($svc->list('ania_k')['total'] === 1 && $svc->list('601 222 333')['total'] === 3 && $svc->list('jan.nowak')['total'] === 1, 'search by login, phone, email');
check((int) $svc->list('cf-2')['rows'][0]['id'] === $anna, 'search by order number');

// --- Ręczne łączenie ---
check($svc->findByIdentifier('JAN.NOWAK@gmail.com') === $jan2 && $svc->findByIdentifier('ania_k') === $anna && $svc->findByIdentifier('#105') === $jan1, 'find customer by email, login, order number');
$merged = $svc->mergeManually($jan1, $jan2, 'admin');
check($svc->customerIdForOrder(105) === $merged && $svc->customerIdForOrder(106) === $merged && $svc->find($merged)['orders_count'] === 2, 'manual merge joins the orders');
check(count($svc->mergesOf($merged)) === 1, 'merge listed on the card');

// Przeliczenie od zera zachowuje ręczne połączenie.
$svc->resetAll();
$svc->syncPending();
$j = $svc->customerIdForOrder(105);
check($j === $svc->customerIdForOrder(106) && $svc->find($j)['orders_count'] === 2, 'rebuild keeps the manual merge');
check($svc->find($svc->customerIdForOrder(101))['orders_count'] === 4, 'rebuild gives the same automatic grouping');

// Nowe zamówienie po połączeniu trafia do połączonego klienta.
woo($pdo, 108, 2, 'Jan Nowak', 'jan@work.pl', '', 25.00, '2026-07-01 10:00:00');
$svc->syncPending();
check($svc->customerIdForOrder(108) === $j && $svc->find($j)['orders_count'] === 3, 'new order joins the merged customer');

$mergeId = (int) $svc->mergesOf($j)[0]['id'];
$after = $svc->unmerge($mergeId);
check(count($after) === 2 && $svc->customerIdForOrder(105) !== $svc->customerIdForOrder(106), 'undo merge splits the customer again');
check($svc->customerIdForOrder(108) === $svc->customerIdForOrder(105), 'orders go back by their own email');

// --- Poprawka e-maila w zamówieniu ---
$pdo->exec("UPDATE woo_orders SET local_billing_email = 'anna@example.com' WHERE woo_order_id = 106");
$svc->refreshOrder(106);
check($svc->customerIdForOrder(106) === $svc->customerIdForOrder(101), 'corrected email moves the order to the right customer');
check($svc->find($svc->customerIdForOrder(101))['orders_count'] === 5, 'target customer recounted');

// Zamówienie bez żadnych danych kontaktowych = osobny klient bez kluczy.
woo($pdo, 109, 1, 'Gość', '', '', 5.00, '2026-07-02 10:00:00');
$g = $svc->customerIdForOrder(109);
check($g !== null && $svc->find($g)['keys'] === [] && $svc->find($g)['orders_count'] === 1, 'order without identifiers gets its own card');

// --- Historia przetrwa usunięcie zamówień (archiwum kasowane po roku) ---
$anna = $svc->customerIdForOrder(101);
$before = $svc->find($anna);
$pdo->exec('DELETE FROM woo_orders WHERE woo_order_id IN (101, 102, 9000000001)');
$svc->syncPending();
$after = $svc->find($anna);
check($after !== null && $after['orders_count'] === $before['orders_count'] && $after['spent_pln'] === $before['spent_pln'] && $after['channels'] === $before['channels'], 'card unchanged after orders are deleted from CRM');
$hist = $svc->ordersOf($anna);
$gone = array_values(array_filter($hist, static fn($o) => (int) $o['woo_order_id'] === 101));
check(count($gone) === 1 && (int) $gone[0]['in_crm'] === 0 && $gone[0]['order_number'] === '101' && $gone[0]['channel_label'] === 'Mój Sklep', 'deleted order kept in history with number and channel');
check($svc->list('101')['total'] >= 1 && $svc->findByIdentifier('anna@example.com') === $anna, 'deleted orders still searchable');
$svc->resetAll();
$svc->syncPending();
$annaNew = $svc->customerIdForOrder(9000000002);
check($svc->customerIdForOrder(101) === $annaNew && $svc->find($annaNew)['orders_count'] === $before['orders_count'], 'recalculation keeps deleted orders and their grouping');
check($svc->customerIdForOrder(106) === $annaNew, 'recalculation keeps the corrected email');

// --- Zmiana statusu i kwoty odświeża kartę ---
$pdo->exec("UPDATE woo_orders SET pase_status = 'cancelled', updated_at = '2026-09-02 00:00:00' WHERE woo_order_id = 9000000002");
$svc->syncPending();
check($svc->find($annaNew)['orders_count'] === $before['orders_count'] - 1, 'status change in CRM updates the card');

// --- Kosz: zamówienie wyrzucone i trwale usunięte przestaje się liczyć ---
$pdo->exec("UPDATE woo_orders SET lifecycle = 'trashed' WHERE woo_order_id = 106");
C::markPurged($pdo, 106);
$pdo->exec('DELETE FROM woo_orders WHERE woo_order_id = 106');
$svc->syncPending();
check($svc->find($annaNew)['orders_count'] === $before['orders_count'] - 2, 'order purged from trash no longer counted');

// --- Anonimizacja na prośbę ---
$svc->mergeManually($svc->customerIdForOrder(105), $annaNew, 'admin');
$anonId = $svc->customerIdForOrder(105);
$countBefore = $svc->find($anonId)['orders_count'];
$svc->anonymize($anonId);
$an = $svc->find($anonId);
check($an['name'] === null && $an['keys'] === [] && (int) $an['anonymized'] === 1 && $an['orders_count'] === $countBefore, 'anonymized: personal data gone, numbers kept');
check((int) $pdo->query('SELECT COUNT(*) FROM customer_merges')->fetchColumn() === 0, 'anonymized: manual merges with its identifiers removed');
check((int) $pdo->query("SELECT COUNT(*) FROM customer_orders WHERE customer_id = $anonId AND (customer_name IS NOT NULL OR order_keys <> '[]')")->fetchColumn() === 0, 'anonymized: snapshots without name and identifiers');
check($svc->list('anna')['total'] === 0 && $svc->findByIdentifier('anna@example.com') === null, 'anonymized customer not listed or searchable');
$pdo->exec("UPDATE woo_orders SET updated_at = '2026-09-03 00:00:00' WHERE woo_order_id = 105");
$svc->syncPending();
$svc->resetAll();
$svc->syncPending();
check($svc->customerIdForOrder(105) === $anonId && $svc->find($anonId)['name'] === null, 'anonymization survives order updates and recalculation');
woo($pdo, 110, 1, 'Anna Kowalska', 'anna@example.com', '', 15.00, '2026-09-10 10:00:00');
check($svc->customerIdForOrder(110) !== $anonId, 'a later order starts a new card');

echo "\nAll $checks customer checks passed.\n";
