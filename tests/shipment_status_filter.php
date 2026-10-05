<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Filtr „Wysyłka" na liście zamówień: etapy śledzenia przesyłki (W drodze, W doręczeniu, Doręczona...).
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\OrderIndicators;
use Pase\Services\ShipmentTracking;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

// Każdy etap ze śledzenia trafia do dokładnie jednej grupy filtra.
foreach (array_keys(ShipmentTracking::STATUSES) as $stage) {
    $groups = array_filter(OrderIndicators::TRACKING_FILTERS, static fn(array $f): bool => in_array($stage, $f[1], true));
    check(count($groups) === 1, "tracking stage {$stage} belongs to one filter group");
}

$ship = static fn(?string $trk, string $status = 'created'): array => ['status' => $status, 'tracking_status' => $trk];

check(OrderIndicators::matchesTracking([$ship('in_transit')], 'in_transit'), 'in transit matches W drodze');
check(!OrderIndicators::matchesTracking([$ship('in_transit')], 'delivered'), 'in transit is not Doręczona');
check(OrderIndicators::matchesTracking([$ship('out_for_delivery')], 'out_for_delivery'), 'out for delivery matches W doręczeniu');
check(OrderIndicators::matchesTracking([$ship('notice_left')], 'pickup'), 'awizo matches Czeka w punkcie / awizo');
check(OrderIndicators::matchesTracking([$ship('returned')], 'problem'), 'return matches Problem / zwrot');
check(OrderIndicators::matchesTracking([$ship(null)], 'created'), 'shipment without tracking counts as Utworzona');
check(!OrderIndicators::matchesTracking([$ship('delivered', 'cancelled')], 'delivered'), 'cancelled shipment is ignored');
check(!OrderIndicators::matchesTracking([], 'created'), 'order without shipments matches no tracking stage');
check(!OrderIndicators::matchesTracking([$ship('delivered')], 'nonsense'), 'unknown filter matches nothing');
check(OrderIndicators::matchesTracking([$ship('delivered'), $ship('in_transit')], 'in_transit'), 'any active shipment on the stage matches');

echo "shipment_status_filter: {$checks} checks passed\n";
