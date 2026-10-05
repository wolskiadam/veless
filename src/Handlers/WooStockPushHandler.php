<?php
declare(strict_types=1);

namespace Pase\Handlers;

use Pase\Plugin\Contract\Warehouse;
use Pase\Plugin\PluginRegistry;
use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\ProductRepository;
use Pase\Support\Logger;

/**
 * Wypych stanu i/lub ceny PASE -> WooCommerce.
 * Wywoływany przez Worker dla zadań 'woo.stock.push'.
 *
 * Payload: ['product_id' => <id w products>, 'what' => 'stock'|'price'|'both'].
 *   - brak 'what' => 'stock' (zgodność wstecz).
 * Bezpieczeństwo: wysyłamy TYLKO gdy integracja ma stock_master = 'pase'.
 */
final class WooStockPushHandler
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly IntegrationAccountRepository $integrations
    ) {}

    public function handle(array $payload): void
    {
        $productId = (int) ($payload['product_id'] ?? 0);
        if ($productId <= 0) {
            return;
        }

        $what = in_array($payload['what'] ?? 'stock', ['stock', 'price', 'both'], true)
            ? ($payload['what'] ?? 'stock') : 'stock';

        $p = $this->products->find($productId);
        if ($p === null) {
            Logger::warn("StockPush: brak produktu #{$productId}");
            return;
        }

        $wooId = (int) ($p['woo_product_id'] ?? 0);
        if ($wooId <= 0) {
            Logger::info("StockPush: produkt SKU {$p['sku']} bez woo_product_id - pomijam");
            return;
        }
        // Wariant Woo (patrz import_products.php): woo_product_id to WSPÓLNY id rodzica
        // (do grupowania w products.php), więc bez woo_variation_id trafialibyśmy stanem
        // w sam produkt-rodzic, a nie w konkretny wariant kupowany przez klienta.
        $wooVariationId = (int) ($p['woo_variation_id'] ?? 0) ?: null;

        // Integracja sklepu, do której należy produkt.
        $integrationId = (int) ($p['source_integration_id'] ?? 0);
        $integration = $integrationId ? $this->integrations->find($integrationId) : null;
        if ($integration === null) {
            Logger::warn("StockPush: brak integracji dla produktu {$p['sku']}");
            return;
        }

        // Ochrona: wypych tylko gdy PASE jest masterem stanów dla tej integracji.
        if (($integration['config']['stock_master'] ?? 'pase') !== 'pase') {
            Logger::info("StockPush: integracja '{$integration['name']}' nie ma CRM jako mastera - pomijam");
            return;
        }

        // Wypych przez wtyczkę z magazynem (Warehouse).
        $plugin = PluginRegistry::forAccount($integration['type'], $integration['config'] ?? []);
        if (!$plugin instanceof Warehouse) {
            Logger::warn("StockPush: integracja '{$integration['name']}' nie wspiera magazynu (Warehouse) - pomijam");
            return;
        }

        $failed = [];

        // Stan.
        if ($what === 'stock' || $what === 'both') {
            $stock = $p['pase_stock'];
            if ($stock !== null) {
                if ($plugin->pushStock((string) $wooId, (int) $stock, $wooVariationId !== null ? (string) $wooVariationId : null)) {
                    Logger::info("StockPush: SKU {$p['sku']} -> Woo #{$wooId} stan={$stock}");
                } else {
                    $failed[] = 'stan';
                }
            }
        }

        // Cena.
        if ($what === 'price' || $what === 'both') {
            $price = $p['pase_price'];
            if ($price !== null) {
                if ($plugin->pushPrice((string) $wooId, (float) $price, $wooVariationId !== null ? (string) $wooVariationId : null)) {
                    Logger::info("StockPush: SKU {$p['sku']} -> Woo #{$wooId} cena={$price}");
                } else {
                    $failed[] = 'cena';
                }
            }
        }

        if ($failed !== []) {
            // Błąd API -> wyjątek, Worker ponowi z backoffem.
            throw new \RuntimeException(
                'StockPush: nie udało się wypchnąć (' . implode(', ', $failed) . ") dla SKU {$p['sku']} (Woo #{$wooId})"
            );
        }
    }
}
