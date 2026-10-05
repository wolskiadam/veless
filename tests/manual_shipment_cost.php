<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Przesyłka wpisana ręcznie („Inne"): koszt z rozliczeń Allegro zamówienia (Services\CourierShipping)
// - tylko gdy to jedyna nadana paczka bez kosztu. SQLite + atrapa klienta Allegro, bez sieci.
define('PASE_ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require PASE_ROOT . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; return; }
    if (str_starts_with($class, 'PasePlugin\\')) {
        $parts = explode('\\', substr($class, 11));
        $parts[0] = strtolower($parts[0]);
        $f = PASE_ROOT . '/integrations/' . implode('/', $parts) . '.php';
        if (is_file($f)) { require $f; }
    }
});
use Pase\Services\CourierShipping;
use PasePlugin\Allegrowysylka\AllegroWysylkaPlugin;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT, is_active INTEGER, config TEXT, webhook_secret TEXT)');
$pdo->exec("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES (7, 'allegro_wysylka', 'Wysyłam z Allegro', 1, '{}')");
$pdo->exec('CREATE TABLE shipments (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, courier_code TEXT, waybill_no TEXT, bl_order_id TEXT,
    order_ref TEXT, price REAL, status TEXT, label_link TEXT, request_payload TEXT, response_payload TEXT, created_at TEXT)');

// Atrapa klienta „Wysyłam z Allegro": rozliczenia zamówienia z opłatą za dostawę Orlen Paczka.
$calls = [];
AllegroWysylkaPlugin::$clientFactory = static fn() => new class($calls) extends \PasePlugin\Allegrowysylka\WysylkaClient {
    public function __construct(private array &$calls) {}
    public function billingEntries(string $id): array
    {
        $this->calls[] = $id;
        return ['ok' => true, 'data' => ['billingEntries' => [
            ['type' => ['name' => 'Prowizja od sprzedaży'], 'value' => ['amount' => '-4.20', 'currency' => 'PLN']],
            ['type' => ['name' => 'Opłata za dostawę Orlen Paczka'], 'value' => ['amount' => '-8.99', 'currency' => 'PLN']],
        ]]];
    }
};

$allegroOrder = ['row' => ['woo_order_id' => 1, 'order_number' => 'a1b2-c3d4'], 'payload' => ['lineItems' => [['id' => 'x']]]];
$wooOrder     = ['row' => ['woo_order_id' => 2, 'order_number' => '44521'], 'payload' => ['line_items' => []]];
$now = date('Y-m-d H:i:s');
$add = static function (int $order, ?int $integration, ?float $price = null, string $status = 'created', ?string $at = null) use ($pdo, $now): int {
    $pdo->prepare('INSERT INTO shipments (woo_order_id, integration_id, courier_code, waybill_no, price, status, created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$order, $integration, 'Orlen Paczka', '2102711810140', $price, $status, $at ?? $now]);
    return (int) $pdo->lastInsertId();
};
$price = static fn(int $id) => $pdo->query("SELECT price FROM shipments WHERE id = {$id}")->fetchColumn();
$forOrder = static fn(int $o) => (new \Pase\Repository\ShipmentRepository($pdo))->forOrder($o);
$svc = new CourierShipping($pdo);

$id = $add(1, null);
check($svc->fillActualCosts($forOrder(1), $allegroOrder) === true, 'manual shipment on an Allegro order gets a cost');
check((float) $price($id) === 8.99, 'cost is the delivery fee from Allegro billing (commission ignored)');
$n = count($calls);
check($svc->fillActualCosts($forOrder(1), $allegroOrder) === false && count($calls) === $n, 'shipment with a cost is not asked again');

$pdo->exec('DELETE FROM shipments'); $calls = [];
$id = $add(2, null);
check($svc->fillActualCosts($forOrder(2), $wooOrder) === false && $price($id) === null && $calls === [], 'WooCommerce order: nothing to fetch');

$pdo->exec('DELETE FROM shipments'); $calls = [];
$a = $add(1, null); $b = $add(1, null);
check($svc->fillActualCosts($forOrder(1), $allegroOrder) === false && $price($a) === null && $price($b) === null, 'two manual parcels: order-wide fee is not guessed');

$pdo->exec('DELETE FROM shipments'); $calls = [];
$add(1, null, 12.5); $id = $add(1, null);
check($svc->fillActualCosts($forOrder(1), $allegroOrder) === false && $price($id) === null, 'another parcel already has a cost: fee is not counted twice');

$pdo->exec('DELETE FROM shipments'); $calls = [];
$add(1, null, null, 'cancelled'); $id = $add(1, null);
check($svc->fillActualCosts($forOrder(1), $allegroOrder) === true && (float) $price($id) === 8.99, 'cancelled parcels do not block the cost');

$pdo->exec('DELETE FROM shipments'); $calls = [];
$id = $add(1, null, null, 'created', date('Y-m-d H:i:s', strtotime('-20 days')));
check($svc->fillActualCosts($forOrder(1), $allegroOrder) === false && $calls === [], 'old manual parcels are not asked about');

echo "\n{$checks} checks passed\n";
