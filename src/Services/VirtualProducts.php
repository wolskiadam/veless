<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;

/**
 * Produkty wirtualne (bez wysyłki: e-book, kurs, voucher, usługa).
 *
 * Produkt oznaczony w magazynie CRM (products.is_virtual = 1). Gdy WSZYSTKIE pozycje zamówienia to
 * produkty wirtualne, zamówienie dostaje przy imporcie / dodaniu znacznik „Zamówienie wirtualne —
 * bez wysyłki” (woo_orders.no_shipping = 1, patrz OrderIndicators).
 * Pozycje dopasowujemy po SKU. Pozycja spoza magazynu albo bez SKU = zwykły towar (wymaga wysyłki).
 * Nie nadpisujemy wyboru z karty zamówienia: znacznik ustawiamy tylko, gdy no_shipping jest puste
 * (NULL); ręczne odznaczenie zapisuje 0 i kolejne aktualizacje zamówienia go nie ruszą.
 */
final class VirtualProducts
{
    public function __construct(private readonly PDO $pdo) {}

    public static function migrate(PDO $pdo): void
    {
        if (!LowStock::columnExists($pdo, 'products', 'is_virtual')) {
            $pdo->exec('ALTER TABLE products ADD COLUMN is_virtual TINYINT(1) NOT NULL DEFAULT 0');
        }
    }

    public function set(int $productId, bool $virtual): void
    {
        $this->pdo->prepare('UPDATE products SET is_virtual = ? WHERE id = ?')->execute([$virtual ? 1 : 0, $productId]);
    }

    /**
     * Stare zamówienia: po oznaczeniu produktu jako wirtualny sprawdza otwarte zamówienia z tym SKU
     * (bez wyboru z karty, nie wysłane, nie anulowane) i oznacza te, w których wszystko jest wirtualne.
     * Pozycje z lokalnej edycji w CRM mają pierwszeństwo przed oryginałem ze sklepu.
     * @return int ile zamówień oznaczono
     */
    public function backfillForSku(string $sku): int
    {
        $sku = trim($sku);
        if ($sku === '') {
            return 0;
        }
        // Wstępne zawężenie po SKU (znaki specjalne jako %, bo w JSON mogą być zapisane inaczej);
        // o oznaczeniu i tak decyduje allVirtual() na pozycjach zamówienia.
        $needle = '%"' . preg_replace('/[^A-Za-z0-9 .-]+/', '%', $sku) . '"%';
        $st = $this->pdo->prepare("SELECT woo_order_id, payload, local_items FROM woo_orders
            WHERE no_shipping IS NULL AND COALESCE(pase_status, '') NOT IN ('cancelled', 'refunded', 'shipped')
              AND (payload LIKE ? OR local_items LIKE ?)");
        $st->execute([$needle, $needle]);
        $marked = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items = $row['local_items'] !== null ? json_decode((string) $row['local_items'], true)
                : (json_decode((string) $row['payload'], true)['line_items'] ?? null);
            if (is_array($items) && $this->applyToOrder((int) $row['woo_order_id'], $items)) {
                $marked++;
            }
        }
        return $marked;
    }

    /** Czy każda pozycja zamówienia (line_items w formacie Woo) to produkt wirtualny z magazynu CRM. */
    public function allVirtual(array $lineItems): bool
    {
        $skus = [];
        foreach ($lineItems as $item) {
            if (!is_array($item) || (int) ($item['quantity'] ?? 1) <= 0) {
                continue;
            }
            $sku = trim((string) ($item['sku'] ?? ''));
            if ($sku === '') {
                return false;
            }
            $skus[$sku] = true;
        }
        if ($skus === []) {
            return false;
        }
        $in = implode(',', array_fill(0, count($skus), '?'));
        $st = $this->pdo->prepare("SELECT COUNT(DISTINCT sku) FROM products WHERE is_virtual = 1 AND sku IN ($in)");
        $st->execute(array_keys($skus));
        return (int) $st->fetchColumn() === count($skus);
    }

    /**
     * Oznacza zamówienie jako wirtualne, jeśli wszystkie pozycje są wirtualne i nikt jeszcze nie wybrał
     * ręcznie. Błąd (np. brak kolumny przed migracją) nie może zatrzymać importu zamówienia.
     * @return bool true = zamówienie właśnie oznaczone
     */
    public function applyToOrder(int $wooOrderId, array $lineItems): bool
    {
        try {
            if (!$this->allVirtual($lineItems)) {
                return false;
            }
            $st = $this->pdo->prepare('UPDATE woo_orders SET no_shipping = 1 WHERE woo_order_id = ? AND no_shipping IS NULL');
            $st->execute([$wooOrderId]);
            if ($st->rowCount() !== 1) {
                return false;
            }
        } catch (\Throwable $e) {
            \Pase\Support\Logger::warn("Zamówienie #{$wooOrderId}: nie sprawdzono produktów wirtualnych: " . $e->getMessage());
            return false;
        }
        try {
            (new AuditTrail($this->pdo))->record($wooOrderId, 'order.no_shipping_changed', ['no_shipping' => null], ['no_shipping' => 1]);
        } catch (\Throwable $e) {
            // Historia zamówienia niedostępna - znacznik i tak zostaje.
        }
        return true;
    }
}
