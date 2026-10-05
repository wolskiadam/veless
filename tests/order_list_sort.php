<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Sortowanie listy zamówień po kliknięciu nagłówka kolumny (Services\OrderListSort).
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\OrderListSort as S;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

check(S::fromRequest([]) === [null, 'desc'], 'no sort param keeps default order');
check(S::fromRequest(['sort' => 'wo.total; DROP TABLE x']) === [null, 'desc'], 'unknown column ignored');
check(S::fromRequest(['sort' => 'client']) === ['client', 'asc'], 'text column starts ascending');
check(S::fromRequest(['sort' => 'amount', 'dir' => 'bogus']) === ['amount', 'desc'], 'invalid dir falls back to column default');
check(S::fromRequest(['sort' => ['x']]) === [null, 'desc'], 'array param ignored');
check(S::orderBy(null, 'desc') === 'wo.pase_number DESC', 'default ORDER BY unchanged');
check(S::nextDir('date', 'date', 'desc') === 'asc', 'same column toggles');
check(S::nextDir('date', 'client', 'asc') === 'desc', 'other column starts from its default');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY, integration_id INTEGER, pase_number INTEGER, order_number TEXT,
    pase_status TEXT, total REAL, customer_name TEXT, customer_email TEXT, date_created TEXT)');
$pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT)');
$pdo->exec('CREATE TABLE order_statuses (id INTEGER PRIMARY KEY, status_key TEXT, position INTEGER)');
$pdo->exec("INSERT INTO integration_accounts VALUES (1, 'woocommerce', 'Klub'), (2, 'allegro', 'Allegro')");
$pdo->exec("INSERT INTO order_statuses VALUES (1, 'new', 1), (2, 'shipped', 2)");
$pdo->exec("INSERT INTO woo_orders VALUES
    (1, 1, 1, '9999',  'shipped', 10.01, 'Zenon',  'z@x.pl', '2026-09-01 10:00:00'),
    (2, 2, 2, '10000', 'new',     250,   'Anna',   'a@x.pl', '2026-09-03 10:00:00'),
    (3, 1, 3, '500',   'shipped', 99.5,  'Monika', 'm@x.pl', '2026-09-02 10:00:00')");
$ids = static function (string $col, string $dir) use ($pdo): array {
    return array_map('intval', $pdo->query('SELECT wo.woo_order_id FROM woo_orders wo LEFT JOIN integration_accounts ia ON ia.id = wo.integration_id ORDER BY '
        . S::orderBy($col, $dir))->fetchAll(PDO::FETCH_COLUMN));
};
check($ids('shop', 'asc') === [3, 1, 2], 'shop number sorted numerically');
check($ids('source', 'asc') === [2, 3, 1], 'source by type, then newest');
check($ids('status', 'asc') === [2, 3, 1], 'status by panel position');
check($ids('client', 'asc') === [2, 3, 1], 'client name ascending');
check($ids('email', 'desc') === [1, 3, 2], 'email descending');
check($ids('amount', 'desc') === [2, 3, 1], 'amount numeric descending');
check($ids('date', 'asc') === [1, 3, 2], 'date ascending');

// Kolumna „Dostawa”: przewoźnik z przesyłki, a bez niej z metody dostawy w payloadzie (Services\OrderChannelIcons).
check(S::joinSql('amount', 'sqlite') === '' && S::joinSql('delivery', 'sqlite') !== '', 'delivery sort needs its own join');
$pdo->exec('ALTER TABLE woo_orders ADD COLUMN payload TEXT');
$pdo->exec('CREATE TABLE shipments (id INTEGER PRIMARY KEY, woo_order_id INTEGER, courier_code TEXT, status TEXT)');
$payloads = [
    1 => ['shipping_lines' => [['method_title' => 'Paczkomaty InPost 24/7']]],
    2 => ['delivery' => ['method' => ['name' => 'Allegro Kurier DPD']]],   // surowe Allegro bez shipping_lines
    3 => ['shipping_lines' => [['method_title' => 'Kurier']]],               // bez przewoźnika, ale nadane przez DHL
];
foreach ($payloads as $id => $p) {
    $pdo->prepare('UPDATE woo_orders SET payload = ? WHERE woo_order_id = ?')->execute([json_encode($p), $id]);
}
$pdo->exec("INSERT INTO woo_orders (woo_order_id, pase_number, payload) VALUES (4, 4, 'not json'), (5, 5, NULL)");
$pdo->exec("INSERT INTO shipments VALUES (1, 3, 'UPS', 'cancelled'), (2, 3, 'DHL Parcel', 'created')");
$deliveryIds = static function (string $dir) use ($pdo): array {
    return array_map('intval', $pdo->query('SELECT wo.woo_order_id FROM woo_orders wo LEFT JOIN integration_accounts ia ON ia.id = wo.integration_id '
        . S::joinSql('delivery', 'sqlite') . ' ORDER BY ' . S::orderBy('delivery', $dir))->fetchAll(PDO::FETCH_COLUMN));
};
// Klucze: dhl (3), dpd (2), inpost (1); bez dostawy (4 - zepsuty JSON, 5 - brak) na początku.
check($deliveryIds('asc') === [5, 4, 3, 2, 1], 'delivery sorted by carrier, broken payload does not fail');
check($deliveryIds('desc') === [1, 2, 3, 5, 4], 'delivery descending');

echo "\n{$checks} checks passed\n";
