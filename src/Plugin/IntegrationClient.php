<?php
declare(strict_types=1);

namespace Pase\Plugin;

/**
 * Mostek do Woo-specyficznego klienta wtyczki bez twardego importu klasy wtyczki
 * w rdzeniu. Wtyczki, które udostępniają metodę client() (jak WooCommerce), pozwalają
 * rdzeniowi sięgnąć po natywny klient API (importy, diagnostyka, createOrder).
 *
 * Zwracany obiekt jest „luźno typowany" (object/mixed) — rdzeń woła na nim metody
 * znane dla danego typu integracji (np. fetchOrders/updateProductStock dla Woo).
 */
final class IntegrationClient
{
    /**
     * Zwraca natywny klient wtyczki dla danej konfiguracji konta, albo null.
     * @param array<string,mixed> $config konfiguracja konta integracji
     */
    public static function for(string $type, array $config): ?object
    {
        $plugin = PluginRegistry::forAccount($type, $config);
        if ($plugin !== null && method_exists($plugin, 'client')) {
            $client = $plugin->client();
            return is_object($client) ? $client : null;
        }
        return null;
    }
}
