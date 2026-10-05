<?php
declare(strict_types=1);

namespace Pase\Handlers;

use Pase\Repository\ProductMappingRepository;
use Pase\Support\Logger;

/**
 * Moduł 3: synchronizacja stanu magazynowego WooCommerce -> Allegro.
 * Wywoływany przez Worker dla zadań typu 'woo.stock.sync'.
 *
 * Przepływ:
 *   1. Z payloadu webhooka Woo wyciągamy SKU + nowy stan.
 *   2. Z product_mappings pobieramy allegro_offer_id po SKU.
 *   3. klient Allegro (wtyczka) -> updateOfferQuantity (PATCH).
 *
 * Rate-limit Allegro jest obsługiwany na poziomie Workera (odstęp między
 * żądaniami) oraz kolejki (available_at). Tu trzymamy tylko logikę.
 */
final class StockSyncHandler
{
    /**
     * @param object $allegro klient Allegro z wtyczki (udostępnia updateOfferQuantity()).
     */
    public function __construct(
        private readonly ProductMappingRepository $mappings,
        private readonly object $allegro
    ) {}

    public function handle(array $payload): void
    {
        $sku   = $payload['sku'] ?? null;
        $stock = $payload['stock_quantity'] ?? null;

        if ($sku === null || $sku === '') {
            // Produkt bez SKU nie ma jak być zmapowany - nie retry'ujemy.
            Logger::warn('StockSync: webhook bez SKU - pomijam');
            return;
        }
        if ($stock === null) {
            Logger::warn("StockSync: produkt {$sku} bez stock_quantity (zarządzanie stanem wyłączone?) - pomijam");
            return;
        }

        $mapping = $this->mappings->findBySku((string) $sku);
        if ($mapping === null || empty($mapping['allegro_offer_id'])) {
            Logger::warn("StockSync: brak allegro_offer_id dla SKU {$sku} - pomijam");
            return;
        }

        $ok = $this->allegro->updateOfferQuantity(
            (string) $mapping['allegro_offer_id'],
            (int) $stock
        );

        if (!$ok) {
            // Błąd API Allegro = retry przez wyjątek (Worker zastosuje backoff).
            throw new \RuntimeException("Allegro: nie udało się zaktualizować stanu oferty dla SKU {$sku}");
        }

        Logger::info("StockSync: SKU {$sku} -> Allegro offer {$mapping['allegro_offer_id']} = {$stock} szt.");
    }
}
