<?php
declare(strict_types=1);

namespace Pase\Repository;

use PDO;

/**
 * Dostęp do tabeli `product_mappings` - mostek SKU <-> ID na platformach.
 */
final class ProductMappingRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findBySku(string $sku): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_mappings WHERE sku = ? LIMIT 1');
        $stmt->execute([$sku]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Mapowanie wielu SKU naraz (Moduł 2: pozycje zamówienia z Allegro).
     *
     * @param string[] $skus
     * @return array<string,array<string,mixed>>  klucz = sku
     */
    public function findManyBySku(array $skus): array
    {
        if ($skus === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($skus), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM product_mappings WHERE sku IN ($placeholders)");
        $stmt->execute(array_values($skus));

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['sku']] = $row;
        }
        return $result;
    }

    public function findByWooProductId(int $wooProductId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_mappings WHERE woo_product_id = ? LIMIT 1');
        $stmt->execute([$wooProductId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
