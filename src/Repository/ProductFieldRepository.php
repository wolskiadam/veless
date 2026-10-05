<?php
declare(strict_types=1);

namespace Pase\Repository;

use PDO;

/**
 * Definicje WŁASNYCH pól produktu (tabela product_fields).
 * Wartości tych pól trzymane są per produkt w products.custom_fields (JSON).
 */
final class ProductFieldRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int,array<string,mixed>> wszystkie pola wg pozycji (z odkodowanymi options) */
    public function all(): array
    {
        try {
            $rows = $this->pdo->query('SELECT * FROM product_fields ORDER BY position ASC, id ASC')
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }
        foreach ($rows as &$r) {
            $r['options']    = json_decode($r['options'] ?? '[]', true) ?: [];
            $r['categories'] = json_decode($r['categories'] ?? '[]', true) ?: [];
        }
        return $rows;
    }

    /**
     * Pola pasujące do produktu o podanych kategoriach.
     * Pole bez przypisanych kategorii = globalne (zawsze widoczne).
     * Pole z kategoriami = widoczne, jeśli produkt należy do którejś z nich.
     *
     * @param string[] $productCategories
     */
    public function forCategories(array $productCategories): array
    {
        $out = [];
        foreach ($this->all() as $f) {
            $fieldCats = $f['categories'] ?? [];
            if ($fieldCats === [] || array_intersect($fieldCats, $productCategories) !== []) {
                $out[] = $f;
            }
        }
        return $out;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_fields WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }
        $r['options']    = json_decode($r['options'] ?? '[]', true) ?: [];
        $r['categories'] = json_decode($r['categories'] ?? '[]', true) ?: [];
        return $r;
    }

    public function keyExists(string $key, int $exceptId = 0): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM product_fields WHERE field_key = ? AND id <> ?');
        $stmt->execute([$key, $exceptId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Tworzy pole. Klucz generowany ze sluga etykiety (unikalny).
     * @param string[] $options opcje dla typu 'select'
     */
    /** @param string[] $categories puste = pole globalne (wszystkie kategorie) */
    public function create(string $label, string $type, array $options, array $categories, int $position): int
    {
        $key = $this->uniqueKey($this->slug($label));
        $stmt = $this->pdo->prepare(
            'INSERT INTO product_fields (field_key, label, type, options, categories, position)
             VALUES (:k, :l, :t, :o, :c, :p)'
        );
        $stmt->execute([
            ':k' => $key, ':l' => $label, ':t' => $type,
            ':o' => json_encode(array_values($options), JSON_UNESCAPED_UNICODE),
            ':c' => json_encode(array_values($categories), JSON_UNESCAPED_UNICODE),
            ':p' => $position,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $label, string $type, array $options, array $categories, int $position): void
    {
        $this->pdo->prepare(
            'UPDATE product_fields SET label = :l, type = :t, options = :o, categories = :c, position = :p WHERE id = :id'
        )->execute([
            ':l' => $label, ':t' => $type,
            ':o' => json_encode(array_values($options), JSON_UNESCAPED_UNICODE),
            ':c' => json_encode(array_values($categories), JSON_UNESCAPED_UNICODE),
            ':p' => $position, ':id' => $id,
        ]);
    }

    /** Klucz pola w WooCommerce (meta_data), z którego CRM pobiera wartość; pusty = pole wpisywane ręcznie. */
    public function setWooKey(int $id, ?string $wooKey): void
    {
        $wooKey = trim((string) $wooKey);
        $this->pdo->prepare('UPDATE product_fields SET woo_meta_key = ? WHERE id = ?')
            ->execute([$wooKey === '' ? null : mb_substr($wooKey, 0, 191), $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM product_fields WHERE id = ?')->execute([$id]);
    }

    private function uniqueKey(string $base): string
    {
        $key = $base;
        $n = 2;
        while ($this->keyExists($key)) {
            $key = $base . '_' . $n++;
        }
        return $key;
    }

    private function slug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = strtr($s, ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ż'=>'z','ź'=>'z']);
        $s = preg_replace('/[^a-z0-9]+/', '_', $s);
        $s = trim((string) $s, '_');
        return $s !== '' ? $s : ('pole_' . substr(md5((string) microtime(true)), 0, 6));
    }
}
