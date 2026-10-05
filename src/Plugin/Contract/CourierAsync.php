<?php
declare(strict_types=1);

namespace Pase\Plugin\Contract;

/**
 * Opcjonalne rozszerzenie kontraktu Courier dla przewoźników, którzy tworzą przesyłkę
 * ASYNCHRONICZNIE (np. „Wysyłam z Allegro": wysyłasz komendę, numer listu pojawia się
 * po chwili).
 *
 * createShipment() może wtedy zwrócić 'pending' => true i tymczasowe external_id.
 * Rdzeń zapisuje przesyłkę ze statusem 'pending' i woła resolveShipment() przy
 * kolejnym otwarciu zamówienia, aż przewoźnik poda wynik.
 */
interface CourierAsync extends Courier
{
    /**
     * @param string $pendingExternalId external_id zwrócone przez createShipment() z pending=true
     * @return array{ok:bool,pending:bool,message:string,external_id:?string,waybill_no:?string,courier_label?:?string,raw?:mixed}
     *         ok=false i pending=false => przewoźnik odrzucił przesyłkę (message = powód)
     */
    public function resolveShipment(string $pendingExternalId): array;
}
