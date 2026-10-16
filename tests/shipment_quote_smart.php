<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Wycena przed nadaniem z historii (CourierShipping::lastActualCost) nie miesza zamówień Allegro Smart
// i bez Smart; Allegro bez Smart bez cennika i historii - kwota zapłacona przez kupującego. SQLite, bez sieci.
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
$pdo->exec('CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY, payload TEXT)');
$pdo->exec('CREATE TABLE shipments (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, price REAL, status TEXT, request_payload TEXT, created_at TEXT)');

$label = 'Umowa Allegro — Allegro Paczkomaty InPost';
$smartPayload = ['delivery' => ['smart' => true, 'cost' => ['amount' => '0.00']]];
$plainPayload = ['delivery' => ['smart' => false, 'cost' => ['amount' => '10.95']]];
$addOrder = static fn(int $id, array $p) => $pdo->prepare('INSERT INTO woo_orders VALUES (?, ?)')->execute([$id, json_encode($p)]);
$addShipment = static fn(int $order, float $price, string $at) => $pdo->prepare(
    "INSERT INTO shipments (woo_order_id, integration_id, price, status, request_payload, created_at) VALUES (?, 7, ?, 'created', ?, ?)")
    ->execute([$order, $price, json_encode(['service_label' => $label, 'handover' => 'point']), $at]);

$svc = new CourierShipping($pdo);
$plain = ['row' => ['woo_order_id' => 9], 'payload' => $plainPayload];
$smart = ['row' => ['woo_order_id' => 8], 'payload' => $smartPayload];

// Tylko przesyłka z zamówienia Smart (Allegro dopłaca, sprzedawca zapłacił 5,19 zł).
$addOrder(1, $smartPayload);
$addShipment(1, 5.19, '2026-10-05 10:00:00');
check($svc->lastActualCost(7, $label, 'point', $plain) === null, 'koszt z zamówienia Smart nie jest wyceną zamówienia bez Smart');
check($svc->lastActualCost(7, $label, 'point', $smart) === ['price' => 5.19, 'date' => '2026-10-05'], 'koszt z Smart wycenia kolejne zamówienie Smart');

// Starsza przesyłka bez Smart wygrywa dla zamówienia bez Smart, mimo że nowsza jest Smart.
$addOrder(2, $plainPayload);
$addShipment(2, 10.95, '2026-10-01 10:00:00');
$pdo->exec('UPDATE shipments SET id = 0 WHERE woo_order_id = 2');
check($svc->lastActualCost(7, $label, 'point', $plain) === ['price' => 10.95, 'date' => '2026-10-01'], 'zamówienie bez Smart bierze koszt z zamówienia bez Smart');
check($svc->lastActualCost(7, $label, 'courier', $plain) === null, 'inny sposób nadania - bez dopasowania');

// Wtyczka: kwota kupującego tylko przy umowie Allegro i bez Smart.
$plugin = new AllegroWysylkaPlugin();
check($plugin->fallbackQuote(['service' => 'allegro', 'order' => $plain]) === ['price' => 10.95, 'message' => 'tyle kupujący zapłacił za dostawę (zamówienie bez Smart)'], 'bez Smart: kwota zapłacona przez kupującego');
check($plugin->fallbackQuote(['service' => 'allegro', 'order' => $smart]) === null, 'Smart: bez podpowiedzi z kwoty kupującego');
check($plugin->fallbackQuote(['service' => 'abc|cred', 'order' => $plain]) === null, 'własna umowa: bez podpowiedzi z kwoty kupującego');

echo "Wszystkie testy przeszły ($checks).\n";
