<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;

/**
 * Produkt z magazynu CRM dla pozycji zamówienia (marże, link „do produktu" na stronie zamówienia).
 *
 * Allegro (po AllegroOrderMapper) i zamówienia ręczne mają product_id = id w CRM;
 * WooCommerce ma product_id/variation_id ze sklepu, więc najpierw SKU, potem ID wariantu i produktu.
 */
final class OrderItemProducts
{
    /** @return array<string,mixed>|null wiersz produktu (id jako int) */
    public static function match(PDO $pdo, array $li, bool $crmIds): ?array
    {
        $q = static function (string $where, array $args) use ($pdo): ?array {
            $s = $pdo->prepare("SELECT * FROM products WHERE $where LIMIT 1");
            $s->execute($args);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            return $r ? ['id' => (int) $r['id']] + $r : null;
        };
        $pid = (int) ($li['product_id'] ?? 0);
        if ($crmIds && $pid > 0 && ($r = $q('id = ?', [$pid]))) {
            return $r;
        }
        $sku = trim((string) ($li['sku'] ?? ''));
        if ($sku !== '' && ($r = $q('sku = ?', [$sku]))) {
            return $r;
        }
        if ($crmIds) {
            return null;
        }
        $vid = (int) ($li['variation_id'] ?? 0);
        if ($vid > 0) {
            return $q('woo_variation_id = ?', [$vid]);
        }
        if ($pid > 0) {
            return $q('woo_product_id = ? AND (woo_variation_id IS NULL OR woo_variation_id = 0)', [$pid]);
        }
        return null;
    }

    /**
     * Id produktów w magazynie dla pozycji (indeks pozycji => id; brak klucza = brak produktu).
     * @param array<int|string,array<string,mixed>> $items
     * @return array<int|string,int>
     */
    public static function ids(PDO $pdo, array $items, bool $crmIds): array
    {
        $out = [];
        foreach ($items as $i => $li) {
            if (is_array($li) && ($li['in_warehouse'] ?? true) !== false && ($p = self::match($pdo, $li, $crmIds)) !== null) {
                $out[$i] = $p['id'];
            }
        }
        return $out;
    }
}
