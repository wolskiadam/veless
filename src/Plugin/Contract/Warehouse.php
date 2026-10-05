<?php
declare(strict_types=1);

namespace Pase\Plugin\Contract;

/**
 * Zdolność: MAGAZYN. Wtyczka potrafi pobierać produkty oraz przyjmować/wysyłać
 * stany i ceny (sync magazynu). CRM bywa źródłem prawdy - kierunek ustala rdzeń.
 */
interface Warehouse
{
    /**
     * Pobiera stronę produktów kanału (do importu do magazynu CRM).
     * @return array<int,array<string,mixed>> znormalizowane produkty (sku, name, stock, price, ...)
     */
    public function fetchProducts(int $page, int $perPage = 50): array;

    /**
     * Wypycha stan magazynowy do kanału. $externalId = id produktu w kanale.
     * $variationId = id konkretnego wariantu (gdy produkt jest wariantowy) - bez tego
     * wtyczka nadpisałaby stan samego produktu-rodzica, nie kupowanego wariantu.
     */
    public function pushStock(string $externalId, int $stock, ?string $variationId = null): bool;

    /** Wypycha cenę (regularną) do kanału. $externalId = id produktu w kanale, $variationId jak wyżej. */
    public function pushPrice(string $externalId, float $price, ?string $variationId = null): bool;

    /** Pobiera aktualny stan z kanału (gdy kanał jest masterem). Null = brak/nieobsługiwane. */
    public function fetchStock(string $externalId): ?int;
}
