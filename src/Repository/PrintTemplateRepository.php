<?php
declare(strict_types=1);

namespace Pase\Repository;

use PDO;

/** Dostęp do tabeli `print_templates` - własne szablony wydruku (A4/A5/ZPL). */
final class PrintTemplateRepository
{
    private const FORMATS = ['A4', 'A5', 'ZPL'];

    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int,array<string,mixed>> wszystkie szablony, posortowane do wyświetlenia w panelu */
    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM print_templates ORDER BY position, id')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array<string,mixed>> tylko aktywne, do dropdownu "Drukuj/Eksportuj" w zamówieniu */
    public function active(): array
    {
        return $this->pdo->query("SELECT * FROM print_templates WHERE is_active = 1 ORDER BY position, id")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM print_templates WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function create(string $name, string $format, string $body, bool $isActive): int
    {
        $format = in_array($format, self::FORMATS, true) ? $format : 'A4';
        $stmt = $this->pdo->prepare(
            'INSERT INTO print_templates (name, format, body, is_active) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, $format, $body, $isActive ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, string $format, string $body, bool $isActive): void
    {
        $format = in_array($format, self::FORMATS, true) ? $format : 'A4';
        $this->pdo->prepare(
            'UPDATE print_templates SET name = ?, format = ?, body = ?, is_active = ? WHERE id = ?'
        )->execute([$name, $format, $body, $isActive ? 1 : 0, $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM print_templates WHERE id = ?')->execute([$id]);
    }

    /** Zapisuje obrazek (logo/grafikę) dołączony do szablonu - wstawiany placeholderem {{obrazek}}. */
    public function setImage(int $id, string $path, string $name): void
    {
        $this->pdo->prepare('UPDATE print_templates SET image_path = ?, image_name = ? WHERE id = ?')
            ->execute([$path, $name, $id]);
    }

    public function clearImage(int $id): void
    {
        $this->pdo->prepare('UPDATE print_templates SET image_path = NULL, image_name = NULL WHERE id = ?')
            ->execute([$id]);
    }
}
