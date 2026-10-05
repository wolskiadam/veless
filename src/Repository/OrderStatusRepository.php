<?php
declare(strict_types=1);

namespace Pase\Repository;

use PDO;

/**
 * Statusy zamówień (tabela order_statuses): 5 systemowych + własne usera.
 * Systemowe (is_system=1) są nieusuwalne i mają stały status_key (mapowanie z Woo
 * w OrderStatus zależy od tych kluczy); user może im zmienić kolor/nazwę.
 */
final class OrderStatusRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int,array<string,mixed>> wszystkie statusy wg pozycji */
    public function all(): array
    {
        try {
            return $this->pdo->query(
                'SELECT * FROM order_statuses ORDER BY position ASC, id ASC'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }
    }

    /** Mapa status_key => wiersz (do szybkiego renderu badge). */
    public function map(): array
    {
        $out = [];
        foreach ($this->all() as $r) {
            $out[$r['status_key']] = $r;
        }
        return $out;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM order_statuses WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function keyExists(string $key): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM order_statuses WHERE status_key = ?');
        $stmt->execute([$key]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** Dodaje własny status. Klucz generowany ze slug etykiety jeśli pusty. */
    public function create(string $label, string $color, int $position): int
    {
        $key = $this->slug($label);
        // Unikalność klucza.
        $base = $key;
        $n = 2;
        while ($this->keyExists($key)) {
            $key = $base . '-' . $n++;
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO order_statuses (status_key, label, color, position, is_system)
             VALUES (:k, :l, :c, :p, 0)"
        );
        $stmt->execute([':k' => $key, ':l' => $label, ':c' => $color, ':p' => $position]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Edycja: label, color, position. Klucz i is_system bez zmian. */
    public function update(int $id, string $label, string $color, int $position): void
    {
        $this->pdo->prepare(
            'UPDATE order_statuses SET label = :l, color = :c, position = :p WHERE id = :id'
        )->execute([':l' => $label, ':c' => $color, ':p' => $position, ':id' => $id]);
    }

    /** Usuwa status - tylko gdy NIE jest systemowy. Zwraca false jeśli odmowa. */
    public function delete(int $id): bool
    {
        $row = $this->find($id);
        if ($row === null || (int) $row['is_system'] === 1) {
            return false;
        }
        $this->pdo->prepare('DELETE FROM order_statuses WHERE id = ?')->execute([$id]);
        return true;
    }

    private function slug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = strtr($s, [
            'ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ż'=>'z','ź'=>'z',
        ]);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim((string) $s, '-');
        return $s !== '' ? $s : ('status-' . substr(md5((string) microtime(true)), 0, 6));
    }
}
