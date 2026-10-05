<?php
declare(strict_types=1);

namespace Pase\Repository;

use PDO;

/**
 * Szablony ofert produktów na marketplace (tabela offer_templates).
 * Jeden szablon per (product_id, marketplace). data to JSON pól oferty
 * (tytuł, opis, cena, stan, zdjęcia, kategoria, parametry).
 */
final class OfferTemplateRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** Szablon dla produktu i marketplace (lub null). data zdekodowane do tablicy. */
    public function find(int $productId, string $marketplace): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM offer_templates WHERE product_id = ? AND marketplace = ? LIMIT 1'
        );
        $stmt->execute([$productId, $marketplace]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['data'] = json_decode($row['data'] ?? '{}', true) ?: [];
        return $row;
    }

    /** Wszystkie szablony danego produktu (do listy „gdzie wystawione"). @return array<int,array> */
    public function forProduct(int $productId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM offer_templates WHERE product_id = ?');
        $stmt->execute([$productId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['data'] = json_decode($r['data'] ?? '{}', true) ?: [];
        }
        return $rows;
    }

    /**
     * Zapis (upsert) szablonu oferty. Zwraca id.
     * @param array<string,mixed> $data pola oferty
     */
    public function save(int $productId, string $marketplace, array $data, string $status = 'draft'): int
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->pdo->prepare(
            'INSERT INTO offer_templates (product_id, marketplace, data, status)
             VALUES (:p, :m, :d, :s)
             ON DUPLICATE KEY UPDATE data = VALUES(data), status = VALUES(status)'
        );
        $stmt->execute([':p' => $productId, ':m' => $marketplace, ':d' => $json, ':s' => $status]);
        $id = (int) $this->pdo->lastInsertId();
        if ($id === 0) {
            // UPDATE - pobierz istniejące id.
            $f = $this->pdo->prepare('SELECT id FROM offer_templates WHERE product_id = ? AND marketplace = ?');
            $f->execute([$productId, $marketplace]);
            $id = (int) $f->fetchColumn();
        }
        return $id;
    }

    public function delete(int $productId, string $marketplace): void
    {
        $this->pdo->prepare('DELETE FROM offer_templates WHERE product_id = ? AND marketplace = ?')
            ->execute([$productId, $marketplace]);
    }
}
