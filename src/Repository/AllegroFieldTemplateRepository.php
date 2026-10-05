<?php
declare(strict_types=1);

namespace Pase\Repository;

use PDO;

/**
 * Szablony pól formularza „Oferta Allegro" (tabela allegro_field_templates): nazwane zestawy wartości
 * (dostawa, warunki, faktura i VAT, GPSR, opcjonalnie kategoria z parametrami), żeby każdej oferty
 * nie wypełniać od nowa. Jeden szablon może być domyślny - wypełnia nowe oferty.
 */
final class AllegroFieldTemplateRepository
{
    /**
     * Pola zapisywane w szablonie. Bez danych konkretnego produktu (tytuł, cena, ilość, sygnatura, EAN,
     * zdjęcia, załączniki, produkt z Katalogu) - te zawsze idą z magazynu.
     */
    public const FIELDS = [
        'shipping_rate', 'handling', 'province', 'city', 'post_code',
        'return_policy', 'implied_warranty', 'warranty',
        'invoice', 'tax_subject', 'tax_exemption', 'vat',
        'producer', 'person', 'safety_mode', 'safety_text',
        'publish_mode', 'message_mode', 'buyers',
        'stock_unit', 'duration', 'republish', 'delivery_info', 'additional_services', 'wholesale_price_list',
        'price_rule', 'promo_package', 'promo_department', 'ads',
    ];

    /** Pola kategorii - zapisywane tylko na życzenie (szablon dla konkretnej kategorii). */
    public const CATEGORY_FIELDS = ['category_id', 'params', 'params_custom'];

    public function __construct(private readonly PDO $pdo) {}

    /** Tworzy tabelę, gdy jej nie ma (MySQL na serwerze, SQLite w testach). Można wołać wielokrotnie. */
    public static function migrate(PDO $pdo): void
    {
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $autoId = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $pdo->exec("CREATE TABLE IF NOT EXISTS allegro_field_templates (
            id {$autoId},
            name VARCHAR(120) NOT NULL,
            data TEXT NOT NULL,
            is_default INT NOT NULL DEFAULT 0,
            updated_at DATETIME NULL
        )" . ($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
    }

    /**
     * Wartości szablonu z formularza: tylko pola szablonu (i kategorii, gdy $withCategory).
     * @param array<string,mixed> $form
     * @return array<string,mixed>
     */
    public static function fromForm(array $form, bool $withCategory): array
    {
        $out = [];
        foreach (array_merge(self::FIELDS, $withCategory ? self::CATEGORY_FIELDS : []) as $k) {
            if (array_key_exists($k, $form)) {
                $out[$k] = $form[$k];
            }
        }
        if ($withCategory && isset($out['params']) && is_array($out['params'])) {
            unset($out['params'][\Pase\Services\AllegroOfferPayload::EAN_PARAMETER_ID]);   // EAN jest z produktu
        }
        return $out;
    }

    /**
     * Nakłada szablon na formularz. Pola kategorii tylko razem (kategoria bez swoich parametrów nie ma sensu).
     * @param array<string,mixed> $form
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function apply(array $form, array $data): array
    {
        foreach (self::FIELDS as $k) {
            if (array_key_exists($k, $data)) {
                $form[$k] = $data[$k];
            }
        }
        if (($data['category_id'] ?? '') !== '') {
            $ean = $form['params'][\Pase\Services\AllegroOfferPayload::EAN_PARAMETER_ID] ?? null;
            $form['category_id'] = (string) $data['category_id'];
            $form['params'] = (array) ($data['params'] ?? []);
            $form['params_custom'] = (array) ($data['params_custom'] ?? []);
            if ($ean !== null) {
                $form['params'][\Pase\Services\AllegroOfferPayload::EAN_PARAMETER_ID] = $ean;
            }
        }
        return $form;
    }

    /** @return array<int,array{id:int,name:string,data:array<string,mixed>,is_default:bool}> */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT * FROM allegro_field_templates ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        return array_map([self::class, 'row'], $rows);
    }

    /** @return array{id:int,name:string,data:array<string,mixed>,is_default:bool}|null */
    public function find(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM allegro_field_templates WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::row($row) : null;
    }

    /** @return array{id:int,name:string,data:array<string,mixed>,is_default:bool}|null */
    public function findDefault(): ?array
    {
        $row = $this->pdo->query('SELECT * FROM allegro_field_templates WHERE is_default = 1 ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        return $row ? self::row($row) : null;
    }

    /**
     * Zapis: nowy szablon (id=null) albo nadpisanie. Szablon o tej samej nazwie jest nadpisywany. Zwraca id.
     * @param array<string,mixed> $data
     */
    public function save(?int $id, string $name, array $data, bool $isDefault): int
    {
        $name = mb_substr(trim($name), 0, 120);
        if ($id === null) {
            $st = $this->pdo->prepare('SELECT id FROM allegro_field_templates WHERE name = ?');
            $st->execute([$name]);
            $found = $st->fetchColumn();
            $id = $found !== false ? (int) $found : null;
        }
        if ($isDefault) {
            $this->pdo->exec('UPDATE allegro_field_templates SET is_default = 0');
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = date('Y-m-d H:i:s');
        if ($id === null) {
            $this->pdo->prepare('INSERT INTO allegro_field_templates (name, data, is_default, updated_at) VALUES (?, ?, ?, ?)')
                ->execute([$name, $json, $isDefault ? 1 : 0, $now]);
            return (int) $this->pdo->lastInsertId();
        }
        $this->pdo->prepare('UPDATE allegro_field_templates SET name = ?, data = ?, is_default = ?, updated_at = ? WHERE id = ?')
            ->execute([$name, $json, $isDefault ? 1 : 0, $now, $id]);
        return $id;
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM allegro_field_templates WHERE id = ?')->execute([$id]);
    }

    /** @param array<string,mixed> $r */
    private static function row(array $r): array
    {
        return ['id' => (int) $r['id'], 'name' => (string) $r['name'],
                'data' => json_decode((string) $r['data'], true) ?: [], 'is_default' => (bool) $r['is_default']];
    }
}
