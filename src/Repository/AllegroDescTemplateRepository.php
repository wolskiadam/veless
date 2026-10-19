<?php
declare(strict_types=1);

namespace Pase\Repository;

use PDO;

/**
 * Nazwane szablony OPISU oferty Allegro (tabela allegro_desc_templates) - i ERLI (erli_desc_templates, ten sam format sekcji).
 *
 * sections to lista sekcji w formacie zgodnym z Allegro:
 *   [ { "items": [ {"type":"TEXT","content":"<p>..</p>"} | {"type":"IMAGE","url":"https://.."} ] }, ... ]
 * Reguła Allegro: w jednej sekcji albo teksty, albo jedna grafika - tego pilnuje UI.
 *
 * Szablon przypisujesz do kategorii (z magazynu). Pusta lista kategorii = szablon
 * domyślny (pasuje do każdego produktu, gdy nie ma trafienia po kategorii).
 */
final class AllegroDescTemplateRepository
{
    public const TABLES = ['allegro_desc_templates', 'erli_desc_templates'];

    private readonly string $table;

    public function __construct(private readonly PDO $pdo, string $table = 'allegro_desc_templates')
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new \InvalidArgumentException('Nieznana tabela szablonów opisu: ' . $table);
        }
        $this->table = $table;
    }

    /** @return array<int,array<string,mixed>> wszystkie szablony (z odkodowanym JSON) */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT * FROM ' . $this->table . ' ORDER BY name ASC')
            ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $this->decode($r);
        }
        return $rows;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->table . ' WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }
        $this->decode($r);
        return $r;
    }

    /**
     * Najlepszy szablon dla produktu o podanych kategoriach: najpierw szablon z
     * pasującą kategorią, w razie braku - pierwszy „domyślny" (bez kategorii).
     *
     * @param string[] $productCategories
     */
    public function bestForCategories(array $productCategories): ?array
    {
        $fallback = null;
        foreach ($this->all() as $t) {
            $cats = $t['categories'] ?? [];
            if ($cats === []) {
                $fallback ??= $t;
                continue;
            }
            if (array_intersect($cats, $productCategories) !== []) {
                return $t;
            }
        }
        return $fallback;
    }

    /**
     * @param string[] $categories
     * @param array<int,array<string,mixed>> $sections
     */
    public function create(string $name, array $categories, array $sections): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . $this->table . ' (name, categories, sections) VALUES (:n, :c, :s)'
        );
        $stmt->execute([
            ':n' => $name,
            ':c' => json_encode(array_values($categories), JSON_UNESCAPED_UNICODE),
            ':s' => json_encode(array_values($sections), JSON_UNESCAPED_UNICODE),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, array $categories, array $sections): void
    {
        $this->pdo->prepare(
            'UPDATE ' . $this->table . ' SET name = :n, categories = :c, sections = :s WHERE id = :id'
        )->execute([
            ':n' => $name,
            ':c' => json_encode(array_values($categories), JSON_UNESCAPED_UNICODE),
            ':s' => json_encode(array_values($sections), JSON_UNESCAPED_UNICODE),
            ':id' => $id,
        ]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM ' . $this->table . ' WHERE id = ?')->execute([$id]);
    }

    private function decode(array &$r): void
    {
        $r['categories'] = json_decode($r['categories'] ?? '[]', true) ?: [];
        $r['sections']   = json_decode($r['sections'] ?? '[]', true) ?: [];
    }
}
