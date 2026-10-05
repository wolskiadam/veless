<?php
declare(strict_types=1);

namespace Pase\Repository;

use PDO;

/**
 * Biblioteka gotowych plików do druku (etykiety produktowe, naklejki) - rzeczy
 * drukowane wielokrotnie i niezwiązane z konkretnym zamówieniem.
 *
 * Sam plik leży w storage/uploads, poza web-rootem (patrz Pase\Services\Attachments),
 * a serwuje go public/admin/print_asset_image.php po sprawdzeniu logowania.
 */
final class PrintAssetRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->pdo
            ->query('SELECT * FROM print_assets ORDER BY name')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM print_assets WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function create(string $name, string $filePath, string $fileName): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO print_assets (name, file_path, file_name) VALUES (:name, :path, :file)'
        );
        $stmt->execute([
            ':name' => $name,
            ':path' => $filePath,
            ':file' => $fileName,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function rename(int $id, string $name): void
    {
        $this->pdo->prepare('UPDATE print_assets SET name = ? WHERE id = ?')->execute([$name, $id]);
    }

    /**
     * Kasuje wpis i zwraca go, żeby wywołujący mógł usunąć plik z dysku.
     * Rozdzielone celowo: repozytorium nie rusza systemu plików.
     */
    public function delete(int $id): ?array
    {
        $row = $this->find($id);
        if ($row === null) {
            return null;
        }
        $this->pdo->prepare('DELETE FROM print_assets WHERE id = ?')->execute([$id]);
        return $row;
    }
}
