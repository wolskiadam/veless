<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Box „Status dostawy" na stronie klienta: status zamówienia „Wysłane" nie może pokazywać
// „Doręczona", gdy śledzenie u przewoźnika mówi, że paczka jest jeszcze w drodze.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Support\DeliveryProgress;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

// Zamówienie „Wysłane", InPost: w drodze.
$p = DeliveryProgress::compute(true, true, true, 'in_transit');
check($p['stage'] === 2 && $p['percent'] < 100 && $p['tracking'] === 'in_transit', 'shipped order + in_transit tracking is not delivered');

$p = DeliveryProgress::compute(true, true, true, 'ready_for_pickup');
check($p['stage'] === 2 && $p['tracking'] === 'ready_for_pickup', 'waiting in locker is not delivered');

$p = DeliveryProgress::compute(true, true, true, 'delivered');
check($p['stage'] === 3 && $p['percent'] === 100 && $p['tracking'] === null, 'delivered tracking = Doręczona 100%');

// Śledzenie potwierdza doręczenie, nawet gdy status zamówienia jeszcze „W realizacji".
$p = DeliveryProgress::compute(true, true, false, 'delivered');
check($p['stage'] === 3, 'delivered tracking wins over processing order status');

// Bez danych ze śledzenia zostaje dotychczasowe zachowanie.
$p = DeliveryProgress::compute(true, true, true, null);
check($p['stage'] === 3 && $p['percent'] === 100, 'no tracking: shipped order status = Doręczona');
$p = DeliveryProgress::compute(true, true, false, '');
check($p['stage'] === 2 && $p['percent'] === 55 && $p['tracking'] === null, 'no tracking: shipment = Nadana');
$p = DeliveryProgress::compute(true, false, false, null);
check($p['stage'] === 1, 'processing, no shipment = W realizacji');
$p = DeliveryProgress::compute(false, false, false, null);
check($p['stage'] === 0 && $p['percent'] === 5, 'new order = Przyjęte');

// Nieznany etap śledzenia jest ignorowany.
$p = DeliveryProgress::compute(true, true, true, 'bogus');
check($p['stage'] === 3, 'unknown tracking status falls back to order status');

echo "\n$checks checks passed\n";
