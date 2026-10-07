<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;

/**
 * Planowana data nadania zamówienia (woo_orders.planned_ship_date), ustawiana ręcznie na karcie
 * zamówienia. Klient widzi ją na swojej stronie zamówienia („Twoje zamówienie zostanie nadane …”),
 * dopóki paczka nie zostanie nadana. Tylko w CRM - nic nie idzie do sklepu ani Allegro.
 */
final class PlannedShipDate
{
    public function __construct(private readonly PDO $pdo) {}

    public static function migrate(PDO $pdo): void
    {
        if (!LowStock::columnExists($pdo, 'woo_orders', 'planned_ship_date')) {
            $pdo->exec('ALTER TABLE woo_orders ADD COLUMN planned_ship_date DATE NULL');
        }
    }

    /** Data z formularza (RRRR-MM-DD z pola daty albo DD.MM.RRRR) -> RRRR-MM-DD; '' = brak daty. */
    public static function parse(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2})[.\-\/](\d{1,2})[.\-\/](\d{4})$/', $raw, $m)) {
            $raw = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new \InvalidArgumentException('Podaj datę nadania, np. 09.10.2026.');
        }
        return $raw;
    }

    /** Zapisuje datę (null = usuń). Zwraca true, gdy coś się zmieniło. */
    public function set(int $wooOrderId, ?string $date): bool
    {
        self::migrate($this->pdo);
        $st = $this->pdo->prepare('SELECT planned_ship_date FROM woo_orders WHERE woo_order_id = ?');
        $st->execute([$wooOrderId]);
        $before = $st->fetchColumn();
        if ($before === false) {
            throw new \RuntimeException('Nie znaleziono zamówienia.');
        }
        $before = $before === null || $before === '' ? null : substr((string) $before, 0, 10);
        if ($before === $date) {
            return false;
        }
        $this->pdo->prepare('UPDATE woo_orders SET planned_ship_date = ? WHERE woo_order_id = ?')->execute([$date, $wooOrderId]);
        (new AuditTrail($this->pdo))->record($wooOrderId, 'order.planned_ship_date_changed',
            ['planned_ship_date' => $before], ['planned_ship_date' => $date]);
        return true;
    }

    /**
     * Data do pokazania klientowi (RRRR-MM-DD) albo null. Ukryta, gdy paczka jest już nadana,
     * zamówienie wysłane / anulowane / zwrócone albo data minęła (nieaktualna obietnica).
     */
    public static function forClient(array $order, bool $hasShipment, bool $pastShipped, string $today): ?string
    {
        $date = substr(trim((string) ($order['planned_ship_date'] ?? '')), 0, 10);
        if ($date === '' || $hasShipment || $pastShipped) {
            return null;
        }
        if (in_array((string) ($order['pase_status'] ?? ''), ['shipped', 'cancelled', 'refunded', 'failed'], true)) {
            return null;
        }
        return $date >= $today ? $date : null;
    }
}
