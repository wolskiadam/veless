<?php
declare(strict_types=1);

namespace PasePlugin\Woocommerce;

use Pase\Domain\OrderStatus;
use Pase\Plugin\AbstractPlugin;
use Pase\Plugin\Capability;
use Pase\Plugin\Contract\OrderSource;
use Pase\Plugin\Contract\Warehouse;
use Pase\Plugin\PluginManifest;

/**
 * Wtyczka WooCommerce — SAMOWYSTARCZALNA. Logika API mieszka w tej wtyczce
 * (WoocommerceClient). Zdolności: źródło zamówień + magazyn.
 *
 * Poza znormalizowanym kontraktem (OrderSource/Warehouse) wtyczka udostępnia
 * client() — dla Woo-specyficznego kodu rdzenia (importy stronami z filtrem statusu,
 * tryby diagnostyczne, tworzenie zamówień z Allegro). Dzięki temu cała logika REST
 * API jest w katalogu wtyczki, a usunięcie katalogu realnie usuwa integrację.
 */
final class WoocommercePlugin extends AbstractPlugin implements OrderSource, Warehouse
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            type: 'woocommerce',
            name: 'WooCommerce',
            version: '1.0.0',
            author: 'Veless',
            capabilities: [Capability::ORDER_SOURCE, Capability::WAREHOUSE],
            fields: [
                ['key' => 'base_url', 'label' => 'Adres sklepu (URL)', 'type' => 'url', 'required' => true,
                 'help' => 'Pełny adres z https://, np. https://twojsklep.pl'],
                ['key' => 'consumer_key', 'label' => 'Consumer key', 'type' => 'text', 'required' => true,
                 'help' => 'WooCommerce → Ustawienia → Zaawansowane → REST API.'],
                ['key' => 'consumer_secret', 'label' => 'Consumer secret', 'type' => 'password', 'required' => true, 'secret' => true],
                ['key' => 'sync_status', 'label' => 'Synchronizuj status zamówień do sklepu', 'type' => 'checkbox', 'default' => ''],
                ['key' => 'stock_master', 'label' => 'Źródło prawdy dla stanów', 'type' => 'select', 'default' => 'pase',
                 'options' => ['pase' => 'CRM (wypycha stany do sklepu)', 'woo' => 'WooCommerce']],
            ],
            color: '#7f54b3',
            icon: '🛒',
            // Logo kanału na liście zamówień (Services\OrderChannelIcons).
            logo: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect width="24" height="24" rx="6" fill="#7f54b3"/><text x="12" y="15" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="8.5" font-weight="700" fill="#fff">woo</text></svg>',
            multiple: true,
            description: 'Pobieranie zamówień, import produktów, sync stanów/cen (Woo lub CRM jako master).',
            category: Capability::CAT_SHOP
        );
    }

    /** Klient API tej wtyczki (logika REST). Dla Woo-specyficznego kodu rdzenia. */
    public function client(): WoocommerceClient
    {
        return new WoocommerceClient($this->config);
    }

    public function testConnection(): array
    {
        $r = $this->client()->testConnection();
        return ['ok' => (bool) ($r['ok'] ?? false), 'message' => (string) ($r['message'] ?? '')];
    }

    // ===== OrderSource =====

    /** Surowe zamówienia Woo (natywny format payloadu PASE). Pusta tablica = koniec. */
    public function fetchOrders(int $page, int $perPage = 50, ?string $after = null): array
    {
        return $this->client()->fetchOrders($page, $perPage, 'any', $after)['orders'] ?? [];
    }

    /** Zamówienia zmienione po $sinceGmt (tryb lokalny bez webhooków, patrz Scheduler::pullModifiedOrders). */
    public function fetchOrdersModifiedSince(int $page, int $perPage, string $sinceGmt): array
    {
        return $this->client()->fetchOrdersModifiedSince($page, $perPage, $sinceGmt);
    }

    public function pushOrderStatus(string $externalOrderId, string $paseStatus): bool
    {
        $wooStatus = OrderStatus::toWoo($paseStatus);
        if ($wooStatus === null) {
            return false;
        }
        return $this->client()->updateOrderStatus((int) $externalOrderId, $wooStatus);
    }

    // ===== Warehouse =====

    public function fetchProducts(int $page, int $perPage = 50): array
    {
        return $this->client()->fetchProducts($page, $perPage)['products'] ?? [];
    }

    public function pushStock(string $externalId, int $stock, ?string $variationId = null): bool
    {
        return $variationId !== null
            ? $this->client()->updateVariationStock((int) $externalId, (int) $variationId, $stock)
            : $this->client()->updateProductStock((int) $externalId, $stock);
    }

    public function pushPrice(string $externalId, float $price, ?string $variationId = null): bool
    {
        return $variationId !== null
            ? $this->client()->updateVariationPrice((int) $externalId, (int) $variationId, $price)
            : $this->client()->updateProductPrice((int) $externalId, $price);
    }

    public function fetchStock(string $externalId): ?int
    {
        return $this->client()->getStock((int) $externalId);
    }
}
