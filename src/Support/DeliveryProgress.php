<?php
declare(strict_types=1);

namespace Pase\Support;

use Pase\Services\ShipmentTracking;

/**
 * Etap dostawy na stronie klienta (box „Status dostawy"): Przyjęte -> W realizacji -> Nadana -> Doręczona.
 *
 * Gdy przesyłka ma już etap ze śledzenia u przewoźnika, to on decyduje - status zamówienia
 * „Wysłane" znaczy tylko, że paczka wyszła, a nie że dotarła. Bez śledzenia zostaje
 * wyprowadzenie ze statusu zamówienia (dalej niż „Wysłane" = dotarło).
 */
final class DeliveryProgress
{
    /** Procent paska wg etapu ze śledzenia (przed doręczeniem paczka jest na etapie „Nadana"). */
    private const TRACKING_PERCENT = [
        'pending'          => 55,
        'in_transit'       => 65,
        'out_for_delivery' => 85,
        'ready_for_pickup' => 90,
        'notice_left'      => 90,
        'returned'         => 55,
        'issue'            => 55,
        'delivered'        => 100,
    ];

    private const STAGE_PERCENT = [0 => 5, 1 => 15, 2 => 55, 3 => 100];

    /**
     * @param string|null $trackingStatus etap ze śledzenia aktywnej przesyłki (ShipmentTracking::STATUSES) lub null
     * @return array{stage:int, percent:int, tracking:?string} tracking = etap ze śledzenia do pokazania zamiast etykiety etapu
     */
    public static function compute(bool $processing, bool $hasShipment, bool $pastShipped, ?string $trackingStatus): array
    {
        $stage = 0;                                              // Przyjęte
        if ($processing || $pastShipped) { $stage = 1; }         // W realizacji
        if ($hasShipment) { $stage = max($stage, 2); }           // Nadana

        $trk = (string) $trackingStatus;
        if ($hasShipment && ShipmentTracking::label($trk) !== '') {
            if ($trk === 'delivered') {
                return ['stage' => 3, 'percent' => 100, 'tracking' => null];
            }
            return ['stage' => 2, 'percent' => self::TRACKING_PERCENT[$trk] ?? 55, 'tracking' => $trk];
        }

        if ($pastShipped) { $stage = 3; }                        // Doręczona (bez danych ze śledzenia)
        return ['stage' => $stage, 'percent' => self::STAGE_PERCENT[$stage], 'tracking' => null];
    }
}
