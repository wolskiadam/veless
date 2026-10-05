<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;

/**
 * Ręczne potwierdzenie wpłaty („Zapłacono: X z Y PLN”, jak w BaseLinker).
 *
 * Kwota zapisuje się tylko w CRM (woo_orders.paid_amount) i od tej chwili ona decyduje
 * o statusie płatności w CRM zamiast danych ze sklepu (date_paid). Nic nie jest wysyłane
 * do WooCommerce ani Allegro. NULL = brak ręcznego wpisu, liczą się dane ze sklepu.
 * paid_amount_total = kwota zamówienia w chwili zapisu (dla scalonego - cała paczka),
 * żeby lista zamówień mogła policzyć wskaźnik bez przeliczania scalonych.
 */
final class OrderPayment
{
    public function __construct(private readonly PDO $pdo) {}

    public static function migrate(PDO $pdo): void
    {
        $columns = [
            'paid_amount'       => 'DECIMAL(12,2) NULL',
            'paid_amount_total' => 'DECIMAL(12,2) NULL',
            'paid_amount_at'    => 'DATETIME NULL',
            'paid_amount_by'    => 'VARCHAR(190) NULL',
        ];
        foreach ($columns as $name => $type) {
            if (!LowStock::columnExists($pdo, 'woo_orders', $name)) {
                $pdo->exec("ALTER TABLE woo_orders ADD COLUMN {$name} {$type}");
            }
        }
    }

    /** Kwota do zapłaty: zamówienie + dołączone do niego (scalone). */
    public function dueTotal(array $row): float
    {
        $total = OrderMerge::orderTotal($row);
        try {
            foreach ((new OrderMerge($this->pdo))->absorbed((int) $row['woo_order_id']) as $ab) {
                $total += OrderMerge::orderTotal($ab);
            }
        } catch (\Throwable) {
            // baza bez scalania
        }
        return round($total, 2);
    }

    /**
     * Zapisuje wpłatę; null = usuń ręczny wpis (wróć do danych ze sklepu).
     * Gdy zamówienie właśnie stało się opłacone, uruchamia reguły „Zamówienie zostało opłacone”
     * ($fireEvents = false np. przy zakładaniu zamówienia ręcznego - tam decyduje „Uruchom automatyzacje”).
     * @return float|null zapisana kwota
     */
    public function set(int $orderId, ?float $amount, string $actor, bool $fireEvents = true): ?float
    {
        $wasPaid = $fireEvents ? \Pase\Automation\OrderEvents::isPaidNow($this->pdo, $orderId) : true;
        $saved = $this->save($orderId, $amount, $actor);
        if ($fireEvents && !$this->pdo->inTransaction()) {
            \Pase\Automation\OrderEvents::paidTransition($this->pdo, $orderId, $wasPaid ?? false);
        }
        return $saved;
    }

    private function save(int $orderId, ?float $amount, string $actor): ?float
    {
        $audit = new AuditTrail($this->pdo);
        return $audit->atomic(function () use ($orderId, $amount, $actor, $audit): ?float {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $st = $this->pdo->prepare('SELECT * FROM woo_orders WHERE woo_order_id = ?' . $lock);
            $st->execute([$orderId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new \RuntimeException('Nie znaleziono zamówienia.');
            }
            if (!empty($row['merged_into'])) {
                throw new \RuntimeException('To zamówienie jest scalone - wpłatę zaznacz na zamówieniu głównym.');
            }
            if ($amount !== null) {
                if (!is_finite($amount) || $amount < 0 || $amount > 9999999) {
                    throw new \RuntimeException('Podaj poprawną kwotę wpłaty (0 lub więcej).');
                }
                $amount = round($amount, 2);
            }
            $before = $row['paid_amount'] !== null ? number_format((float) $row['paid_amount'], 2, '.', '') : null;
            $after = $amount !== null ? number_format($amount, 2, '.', '') : null;
            $this->pdo->prepare('UPDATE woo_orders SET paid_amount = ?, paid_amount_total = ?, paid_amount_at = ?, paid_amount_by = ? WHERE woo_order_id = ?')
                ->execute([
                    $after,
                    $amount !== null ? number_format($this->dueTotal($row), 2, '.', '') : null,
                    $amount !== null ? gmdate('Y-m-d H:i:s') : null,
                    $amount !== null ? mb_substr($actor, 0, 190) : null,
                    $orderId,
                ]);
            if ($before !== $after) {
                $audit->record($orderId, 'order.payment_set', ['paid_amount' => $before], ['paid_amount' => $after]);
            }
            return $amount;
        });
    }

    /**
     * Stan ręcznej wpłaty albo null, gdy jej nie wpisano.
     * @param float|null $total kwota do zapłaty; null = zapamiętana przy zapisie
     * @return array{state:string,label:string,amount:float,total:float}|null
     */
    public static function state(array $order, ?float $total = null): ?array
    {
        if (!array_key_exists('paid_amount', $order) || $order['paid_amount'] === null || $order['paid_amount'] === '') {
            return null;
        }
        $amount = (float) $order['paid_amount'];
        $total ??= (float) ($order['paid_amount_total'] ?? 0);
        if ($amount > 0 && $amount + 0.005 >= $total) {
            return ['state' => 'ok', 'label' => 'payment_paid_manual', 'amount' => $amount, 'total' => $total];
        }
        return ['state' => 'warn', 'label' => $amount > 0 ? 'payment_partial' : 'payment_unpaid', 'amount' => $amount, 'total' => $total];
    }
}
