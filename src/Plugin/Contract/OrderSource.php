<?php
declare(strict_types=1);

namespace Pase\Plugin\Contract;

/**
 * Zdolność: ŹRÓDŁO ZAMÓWIEŃ. Wtyczka potrafi pobierać zamówienia do CRM.
 * Zamówienie zwracamy w znormalizowanej formie (NormalizedOrder), żeby rdzeń nie
 * musiał znać formatu konkretnego kanału.
 */
interface OrderSource
{
    /**
     * Pobiera stronę zamówień. Zwraca listę znormalizowanych zamówień (tablice).
     * Pusta tablica = koniec stron.
     *
     * @param int         $page    numer strony (1-based)
     * @param int         $perPage rozmiar strony
     * @param string|null $after   ISO data - tylko nowsze (opcjonalnie)
     * @return array<int,array<string,mixed>> znormalizowane zamówienia (patrz NormalizedOrder)
     */
    public function fetchOrders(int $page, int $perPage = 50, ?string $after = null): array;

    /**
     * Aktualizuje status zamówienia w kanale źródłowym (sync z CRM), jeśli wspierane.
     * @param string $externalOrderId id zamówienia w kanale
     * @param string $crmStatus      status CRM (wtyczka mapuje na własny)
     */
    public function pushOrderStatus(string $externalOrderId, string $crmStatus): bool;
}
