<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Domain\OrderStatus;
use Pase\Support\Logger;

/**
 * Nieudana płatność: sklep oznaczył zamówienie jako „failed" (np. PayU odrzuciło albo klient przerwał płatność),
 * a zamówienie nie jest opłacone. Wskaźnik płatności na liście jest wtedy czerwony, a status CRM przechodzi
 * na status zmapowany dla Woo „failed" (domyślnie Anulowane).
 *
 * Zmiana statusu jest tylko w CRM: nic nie idzie do sklepu/Allegro (sklep już wie, że płatność się nie udała)
 * i nie odpalają się reguły automatyzacji. Status zmieniamy tylko z „Nowe" (operator nic jeszcze nie ruszał)
 * i tylko raz na zamówienie - gdy ktoś potem przywróci status ręcznie, CRM go już nie cofnie.
 */
final class PaymentFailure
{
    public const AUDIT_ACTION = 'order.payment_failed_cancelled';

    public function __construct(private readonly PDO $pdo) {}

    /** Płatność nieudana i nic jej później nie zastąpiło (opłata w sklepie, ręczne potwierdzenie wpłaty w CRM). */
    public static function failed(array $order, array $payload): bool
    {
        if ((string) ($payload['status'] ?? $order['status'] ?? '') !== 'failed') {
            return false;
        }
        if (!empty($payload['date_paid']) || !empty($payload['date_paid_gmt'])) {
            return false;
        }
        return (OrderPayment::state($order)['state'] ?? null) !== 'ok';
    }

    /** Ustawia status anulowany, jeśli płatność zamówienia jest nieudana. Zwraca true, gdy zmieniono status. */
    public function apply(int $wooOrderId): bool
    {
        $st = $this->pdo->prepare('SELECT * FROM woo_orders WHERE woo_order_id = ?');
        $st->execute([$wooOrderId]);
        $order = $st->fetch(PDO::FETCH_ASSOC);
        if (!$order || ($order['lifecycle'] ?? 'active') !== 'active' || ($order['pase_status'] ?? '') !== OrderStatus::NEW) {
            return false;
        }
        if (!self::failed($order, json_decode((string) ($order['payload'] ?? ''), true) ?: [])) {
            return false;
        }
        $target = OrderStatus::fromWoo('failed');
        if ($target === OrderStatus::NEW) {
            return false;   // w Konfiguracja → Statusy zamówień „failed" zmapowano na Nowe - nic nie zmieniamy
        }
        // Scalone zamówienia idą w jednej paczce - nie ruszamy statusu części paczki.
        if (!empty($order['merged_into'])) {
            return false;
        }
        try {
            $m = $this->pdo->prepare('SELECT COUNT(*) FROM woo_orders WHERE merged_into = ?');
            $m->execute([$wooOrderId]);
            if ((int) $m->fetchColumn() > 0) {
                return false;
            }
        } catch (\PDOException) {
            // brak kolumny merged_into - scalanie nieużywane
        }
        $done = $this->pdo->prepare('SELECT COUNT(*) FROM audit_events WHERE order_id = ? AND action = ?');
        $done->execute([$wooOrderId, self::AUDIT_ACTION]);
        if ((int) $done->fetchColumn() > 0) {
            return false;   // raz już anulowaliśmy - operator przywrócił status ręcznie
        }
        $changed = (new AuditTrail($this->pdo))->changeOrder($wooOrderId, self::AUDIT_ACTION, ['pase_status' => $target]);
        if ($changed) {
            Logger::info("Płatność nieudana: zamówienie #{$wooOrderId} -> {$target} (tylko w CRM)");
        }
        return $changed;
    }

    /** Jednorazowo dla zamówień, które już wcześniej miały nieudaną płatność. Zwraca liczbę zmienionych. */
    public function sweep(): int
    {
        $ids = $this->pdo->prepare("SELECT woo_order_id FROM woo_orders WHERE status = 'failed' AND pase_status = ? AND lifecycle = 'active'");
        $ids->execute([OrderStatus::NEW]);
        $n = 0;
        foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
            try {
                $n += $this->apply((int) $id) ? 1 : 0;
            } catch (\Throwable $e) {
                Logger::warn("Płatność nieudana: zamówienie #{$id} nie zmienione: " . $e->getMessage());
            }
        }
        return $n;
    }
}
