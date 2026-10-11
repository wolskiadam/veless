<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Plugin\IntegrationClient;
use Pase\Queue\Queue;
use Pase\Repository\IntegrationAccountRepository;
use Pase\Support\Logger;

/**
 * Ponowne pobranie zamówienia ze sklepu WooCommerce do kolejki importu (woo.order.import), gdy CRM
 * ma nieaktualny stan - np. pobrał zamówienie jako „wstrzymane”, a sklep po opłaceniu przestawił je
 * na „w trakcie realizacji”. Tylko odczyt ze sklepu; zapis robi zwykły import (jak webhook order.updated).
 */
final class ShopOrderRefresh
{
    /** Najwyżej raz na tyle sekund na zamówienie (w ramach sesji), żeby nie pytać sklepu przy każdym odświeżeniu strony. */
    private const THROTTLE_SEC = 120;

    /**
     * @param array<string,mixed> $row wiersz woo_orders
     * @param array<string,mixed> $payload zapisany payload zamówienia
     * @return bool true = zakolejkowano nowy stan zamówienia
     */
    public static function request(PDO $pdo, array $row, array $payload): bool
    {
        $wooId = (int) ($row['woo_order_id'] ?? 0);
        $shopId = (int) ($payload['id'] ?? 0);
        if ($wooId <= 0 || $shopId <= 0 || empty($row['integration_id']) || !empty($row['merged_into'])) {
            return false;
        }
        $throttleKey = 'shop_order_refresh_' . $wooId;
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (time() - (int) ($_SESSION[$throttleKey] ?? 0) < self::THROTTLE_SEC) {
                return false;
            }
            $_SESSION[$throttleKey] = time();
        }
        try {
            $acc = (new IntegrationAccountRepository($pdo))->find((int) $row['integration_id']);
            if ($acc === null || ($acc['type'] ?? '') !== 'woocommerce' || (int) ($acc['is_active'] ?? 0) !== 1) {
                return false;
            }
            $fresh = IntegrationClient::for('woocommerce', $acc['config'] ?? [])?->fetchOrder($shopId);
            if (!is_array($fresh) || (int) ($fresh['id'] ?? 0) !== $shopId) {
                return false;
            }
            $status = (string) ($fresh['status'] ?? 'unknown');
            if ($status === (string) ($payload['status'] ?? '') && ($fresh['date_paid'] ?? null) == ($payload['date_paid'] ?? null)) {
                return false;   // sklep wciąż ma to samo - nie ma czego importować
            }
            $fresh['__integration_id'] = (int) $acc['id'];
            return (new Queue($pdo))->enqueue('woo.order.import', $fresh, "woo.order.import:{$acc['id']}:{$shopId}:{$status}");
        } catch (\Throwable $e) {
            Logger::warn("Odświeżenie zamówienia #{$wooId} ze sklepu nie powiodło się: " . $e->getMessage());
            return false;
        }
    }
}
