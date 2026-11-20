<?php
declare(strict_types=1);

namespace Pase\Repository;

use PDO;

/**
 * Dostęp do tabeli products - MASTER produktów (centralny magazyn PASE).
 * Upsert po SKU: produkt z danym SKU = jeden rekord, niezależnie od kanału.
 */
final class ProductRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Zapisuje/aktualizuje produkt po SKU. Zwraca true, jeśli nowy.
     *
     * Stany i ceny PASE (pase_stock/pase_price) ustawiamy z Woo TYLKO przy
     * pierwszym imporcie (COALESCE) - później to PASE jest źródłem prawdy i nie
     * nadpisujemy ich danymi ze sklepu. Kierunek synchronizacji konfigurujemy
     * osobno (w kolejnym etapie).
     */
    public function upsert(string $sku, array $fields, array $payload): bool
    {
        $sql = 'INSERT INTO products
                    (sku, name, description, short_description, images, categories, attributes,
                     ean, weight, length, width, height,
                     pase_stock, pase_price, source_integration_id,
                     woo_product_id, woo_variation_id, payload)
                VALUES
                    (:sku, :name, :desc, :sdesc, :images, :cats, :attrs,
                     :ean, :weight, :len, :wid, :hei,
                     :stock, :price, :int, :wp, :wv, :payload)
                ON DUPLICATE KEY UPDATE
                    -- dane opisowe z Woo aktualizujemy zawsze (to nie nasze stany/ceny)
                    name              = VALUES(name),
                    description       = VALUES(description),
                    short_description = VALUES(short_description),
                    images            = VALUES(images),
                    categories        = VALUES(categories),
                    attributes        = VALUES(attributes),
                    -- EAN z Woo; pusty w sklepie nie kasuje numeru nadanego w CRM (np. z GS1)
                    ean               = COALESCE(VALUES(ean), ean),
                    weight            = VALUES(weight),
                    length            = VALUES(length),
                    width             = VALUES(width),
                    height            = VALUES(height),
                    -- stany/ceny CRM ustawiamy tylko gdy jeszcze puste (pierwszy import)
                    pase_stock            = COALESCE(pase_stock, VALUES(pase_stock)),
                    pase_price            = COALESCE(pase_price, VALUES(pase_price)),
                    source_integration_id = COALESCE(source_integration_id, VALUES(source_integration_id)),
                    -- ID produktu w Woo nadpisujemy tylko dla sklepu, do którego produkt należy
                    -- (source_integration_id jest ustawiane WYŻEJ, więc tu ma już wartość docelową).
                    -- Wcześniej import tego samego SKU z drugiego sklepu podmieniał ID z pierwszego,
                    -- a wypych stanów szedł do pierwszego sklepu z cudzym ID produktu.
                    woo_product_id        = IF(source_integration_id <=> VALUES(source_integration_id) OR VALUES(source_integration_id) IS NULL,
                                               VALUES(woo_product_id), woo_product_id),
                    woo_variation_id      = IF(source_integration_id <=> VALUES(source_integration_id) OR VALUES(source_integration_id) IS NULL,
                                               VALUES(woo_variation_id), woo_variation_id),
                    payload               = VALUES(payload)';

        $j = static fn($v) => $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':sku'     => $sku,
            ':name'    => $fields['name'] ?? null,
            ':desc'    => $fields['description'] ?? null,
            ':sdesc'   => $fields['short_description'] ?? null,
            ':images'  => $j($fields['images'] ?? null),
            ':cats'    => $j($fields['categories'] ?? null),
            ':attrs'   => $j($fields['attributes'] ?? null),
            ':ean'     => $fields['ean'] ?? null,
            ':weight'  => $fields['weight'] ?? null,
            ':len'     => $fields['length'] ?? null,
            ':wid'     => $fields['width'] ?? null,
            ':hei'     => $fields['height'] ?? null,
            ':stock'   => $fields['stock'] ?? null,
            ':price'   => $fields['price'] ?? null,
            ':int'     => $fields['integration_id'] ?? null,
            ':wp'      => $fields['woo_product_id'] ?? null,
            ':wv'      => $fields['woo_variation_id'] ?? null,
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        return $stmt->rowCount() === 1;
    }

    /** Zdjęcia produktu głównego wariantu (patrz VariantParentImages). @param string[] $urls */
    public function setParentImages(string $sku, array $urls): void
    {
        \Pase\Services\VariantParentImages::migrate($this->pdo);
        $this->pdo->prepare('UPDATE products SET parent_images = ? WHERE sku = ?')
            ->execute([json_encode(array_values($urls), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $sku]);
    }

    public function findBySku(string $sku): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE sku = ? LIMIT 1');
        $stmt->execute([$sku]);
        return $stmt->fetch() ?: null;
    }

    /** SKU produktu powiązanego z ofertą Allegro (zakładka Powiązania / product_mappings). */
    public function findSkuByAllegroOfferId(string $offerId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT sku FROM product_mappings WHERE allegro_offer_id = ? LIMIT 1');
        $stmt->execute([$offerId]);
        $sku = $stmt->fetchColumn();
        return $sku === false ? null : (string) $sku;
    }

    /**
     * Wiele SKU naraz (np. pozycje jednego zamówienia z Allegro - patrz NewAllegroOrderHandler).
     * @param string[] $skus
     * @return array<string,array<string,mixed>> klucz = sku
     */
    public function findManyBySku(array $skus): array
    {
        if ($skus === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($skus), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM products WHERE sku IN ({$placeholders})");
        $stmt->execute(array_values($skus));

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['sku']] = $row;
        }
        return $result;
    }

    /**
     * Odejmuje sprzedaną ilość od stanu PASE (nie schodzi poniżej zera) i od stanu faktycznego,
     * jeśli jest wpisany (pusty stan faktyczny zostaje pusty).
     * @return array<string,mixed>|null zaktualizowany wiersz, albo null gdy SKU nieznane
     */
    public function decrementStockBySku(string $sku, int $qty): ?array
    {
        $stmt = $this->pdo->prepare(
            'UPDATE products SET pase_stock = GREATEST(0, COALESCE(pase_stock, 0) - :qty),
                    actual_stock = CASE WHEN actual_stock IS NULL THEN NULL ELSE GREATEST(0, actual_stock - :qty2) END
             WHERE sku = :sku'
        );
        $stmt->execute([':qty' => $qty, ':qty2' => $qty, ':sku' => $sku]);
        return $this->findBySku($sku);
    }

    /** Ręczna zmiana nazwy produktu w magazynie. */
    public function setName(int $id, string $name): void
    {
        $this->pdo->prepare('UPDATE products SET name = :n WHERE id = :id')->execute([':n' => $name, ':id' => $id]);
    }

    /** Ręczna zmiana stanu CRM jednego produktu (cena bez zmian). Wypych do sklepu kolejkuje wywołujący. */
    public function setStock(int $id, int $stock): void
    {
        $this->pdo->prepare('UPDATE products SET pase_stock = :s WHERE id = :id')
            ->execute([':s' => max(0, $stock), ':id' => $id]);
    }

    /** Ręczne ustawienie stanu faktycznego (null = nie wpisany). Nie wysyła niczego do sklepów. */
    public function setActualStock(int $id, ?int $stock): void
    {
        // Chwila wpisania: sprzedaże w sklepie złożone wcześniej są już w tej liczbie (ActualStock).
        $this->pdo->prepare('UPDATE products SET actual_stock = :s, actual_stock_set_at = :t WHERE id = :id')
            ->execute([':s' => $stock === null ? null : max(0, $stock), ':t' => $stock === null ? null : gmdate('Y-m-d H:i:s'), ':id' => $id]);
    }

    /**
     * Zbiorcza zmiana stanu wielu produktów naraz (panel: zaznacz + zastosuj).
     * 'set' - ustawia dokładnie podaną wartość wszystkim zaznaczonym.
     * 'delta' - dolicza/odejmuje podaną wartość do obecnego stanu każdego (nie schodzi poniżej zera).
     * @param int[] $ids
     */
    public function bulkAdjustStock(array $ids, string $mode, int $value): int
    {
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = $mode === 'delta'
            ? "UPDATE products SET pase_stock = GREATEST(0, COALESCE(pase_stock, 0) + ?) WHERE id IN ({$placeholders})"
            : "UPDATE products SET pase_stock = GREATEST(0, ?) WHERE id IN ({$placeholders})";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$value], $ids));
        return $stmt->rowCount();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function count(string $where = '', array $params = []): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM products {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * ID produktów nadających się do wypchnięcia do sklepu (mają woo_product_id i stan PASE).
     * Używane przez cykliczny sync stanów/cen. @return int[]
     */
    public function pushableIds(int $limit = 1000): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM products
             WHERE woo_product_id IS NOT NULL AND woo_product_id > 0
             ORDER BY id ASC LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Lista z paginacją, opcjonalnym wyszukiwaniem po SKU/nazwie, filtrem kategorii i sklepu. */
    /**
     * Powiązanie istniejącego produktu (po SKU) ze sklepem - bez zmiany jego danych.
     * Ustawia ID produktu/wariantu w Woo tylko, gdy produkt nie jest jeszcze powiązany
     * z INNYM sklepem (jeden produkt = jeden sklep źródłowy).
     * @return string 'linked' | 'missing' (brak SKU) | 'other_shop' (powiązany z innym sklepem)
     */
    public function linkToShop(string $sku, int $integrationId, ?int $wooProductId, ?int $wooVariationId): string
    {
        $row = $this->findBySku($sku);
        if ($row === null) {
            return 'missing';
        }
        $current = (int) ($row['source_integration_id'] ?? 0);
        if ($current !== 0 && $current !== $integrationId) {
            return 'other_shop';
        }
        $this->pdo->prepare(
            'UPDATE products SET source_integration_id = :int, woo_product_id = :wp, woo_variation_id = :wv WHERE sku = :sku'
        )->execute([':int' => $integrationId, ':wp' => $wooProductId, ':wv' => $wooVariationId, ':sku' => $sku]);
        return 'linked';
    }

    /** Operatory filtra stanu magazynowego (panel Produkty). */
    public const STOCK_OPS = [
        'eq'   => '=',
        'lt'   => '<',
        'lte'  => '≤',
        'gt'   => '>',
        'gte'  => '≥',
        'none' => 'brak stanu',
        'low'  => 'niski (≤ minimum)',
        'untracked' => 'nie pilnowany',
    ];

    /**
     * Warunek SQL filtra stanu (pase_stock). Zwraca null, gdy filtr nieaktywny.
     * 'none' = produkty bez ustawionego stanu (NULL). Porównania pomijają produkty bez stanu.
     * 'low' = stan ≤ minimum produktu (albo progu domyślnego przekazanego w $value).
     */
    public static function stockCondition(?string $op, ?int $value, array &$params, bool $useActual = false): ?string
    {
        if ($op === 'none') {
            return 'pase_stock IS NULL';
        }
        if ($op === 'low') {
            // Niski stan: $value = próg domyślny (null = tylko produkty z własnym minimum).
            return \Pase\Services\LowStock::condition($value, $params, $useActual);
        }
        if ($op === 'untracked') {
            return 'min_stock = ' . \Pase\Services\LowStock::OFF;   // „nie pilnuj stanu”
        }
        $sqlOp = ['eq' => '=', 'lt' => '<', 'lte' => '<=', 'gt' => '>', 'gte' => '>='][$op ?? ''] ?? null;
        if ($sqlOp === null || $value === null) {
            return null;
        }
        $params[':stock'] = $value;
        return "pase_stock IS NOT NULL AND pase_stock {$sqlOp} :stock";
    }

    /**
     * Kolejność listy produktów: najnowsze na górze, ale warianty jednego produktu Woo
     * (wspólny woo_product_id w tym samym sklepie, własny woo_variation_id) zawsze razem,
     * tuż pod sobą. Grupa stoi w miejscu swojego najnowszego wariantu, a w środku warianty
     * idą od najnowszego. Produkt główny Woo zapisany jako wiersz (bez woo_variation_id, ten sam
     * woo_product_id co warianty) stoi w grupie na samej górze, nad wariantami. Bez tego wariant dodany później (inne id) lądował osobno, a między
     * warianty wchodziły zwykłe produkty o podobnej nazwie - lista pokazywała grupę dwa razy.
     */
    public const GROUPED_ORDER = 'COALESCE((SELECT MAX(g.id) FROM products g
            WHERE products.woo_product_id > 0 AND g.woo_variation_id > 0 AND g.woo_product_id = products.woo_product_id
              AND COALESCE(g.source_integration_id, 0) = COALESCE(products.source_integration_id, 0)), products.id) DESC,
        CASE WHEN products.woo_variation_id > 0 THEN 1 ELSE 0 END,
        products.id DESC';

    /**
     * Klucz grupy wariantów dla listy produktów ('' = nie wariant, pokazywany osobno).
     * Tylko prawdziwe warianty (woo_variation_id) - rodzic produktu wariantowego, jeśli
     * kiedyś trafił do bazy, ma ten sam woo_product_id, ale wariantem nie jest.
     */
    public static function variantGroupKey(array $row): string
    {
        $wpid = (int) ($row['woo_product_id'] ?? 0);
        return $wpid > 0 && (int) ($row['woo_variation_id'] ?? 0) > 0
            ? (int) ($row['source_integration_id'] ?? 0) . ':' . $wpid : '';
    }

    /**
     * Które z wierszy (nie-wariantów) to rodzice produktów wariantowych Woo zapisani jako
     * zwykły produkt - mają warianty o tym samym woo_product_id w tym samym sklepie.
     * Zwraca [id produktu => true].
     */
    public function variableParentIds(array $rows): array
    {
        $cand = [];
        foreach ($rows as $r) {
            $wpid = (int) ($r['woo_product_id'] ?? 0);
            if ($wpid > 0 && (int) ($r['woo_variation_id'] ?? 0) <= 0) {
                $cand[(int) ($r['source_integration_id'] ?? 0) . ':' . $wpid][] = (int) $r['id'];
            }
        }
        if ($cand === []) {
            return [];
        }
        $wpids = array_values(array_unique(array_map(static fn(string $k): int => (int) explode(':', $k)[1], array_keys($cand))));
        $stmt = $this->pdo->query('SELECT DISTINCT COALESCE(source_integration_id, 0) AS sid, woo_product_id FROM products
            WHERE woo_variation_id > 0 AND woo_product_id IN (' . implode(',', $wpids) . ')');
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
            foreach ($cand[(int) $g['sid'] . ':' . (int) $g['woo_product_id']] ?? [] as $id) {
                $out[$id] = true;
            }
        }
        return $out;
    }

    public function paged(int $limit, int $offset, string $q = '', ?string $category = null, ?int $integrationId = null,
                          ?string $stockOp = null, ?int $stockValue = null, ?int $locationId = null, bool $useActual = false,
                          array $orIds = []): array
    {
        $orIds = self::cleanIds($orIds);
        $conditions = [];
        $params = [];
        if ($locationId !== null) {
            // 0 = produkty bez lokalizacji w magazynie (Magazyn → Lokalizacje).
            $conditions[] = $locationId === 0 ? 'location_id IS NULL' : 'location_id = :loc';
            if ($locationId !== 0) {
                $params[':loc'] = $locationId;
            }
        }
        if (($stockSql = self::stockCondition($stockOp, $stockValue, $params, $useActual)) !== null) {
            $conditions[] = $stockSql;
        }
        if ($q !== '') {
            // Dwa różne placeholdery na tę samą wartość - PDO z wyłączoną emulacją
            // (PDO::ATTR_EMULATE_PREPARES=false, patrz config/database.php) nie obsługuje
            // użycia tego samego nazwanego placeholdera dwa razy w jednym zapytaniu.
            // $orIds: produkty, które pasują do szukanego tekstu według rozszerzeń (filtr products.search).
            $conditions[] = '(sku LIKE :q1 OR name LIKE :q2' . ($orIds !== [] ? ' OR id IN (' . implode(',', $orIds) . ')' : '') . ')';
            $params[':q1'] = "%{$q}%";
            $params[':q2'] = "%{$q}%";
        }
        if ($category !== null && $category !== '') {
            // categories to JSON-owa tablica nazw (patrz WooProductImportHandler) - szukamy
            // dokładnej nazwy jako elementu tablicy, nie dowolnego podciągu tekstu.
            $conditions[] = 'categories LIKE :cat';
            $params[':cat'] = '%"' . str_replace(['%', '_'], ['\%', '\_'], $category) . '"%';
        }
        if ($integrationId !== null) {
            // 0 = "bez przypisanego sklepu" (kolumna jest wtedy NULL, nie liczbą 0).
            $conditions[] = $integrationId === 0
                ? '(source_integration_id IS NULL OR source_integration_id = 0)'
                : 'source_integration_id = :intid';
            if ($integrationId !== 0) {
                $params[':intid'] = $integrationId;
            }
        }
        $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $stmt = $this->pdo->prepare("SELECT * FROM products {$where} ORDER BY " . self::GROUPED_ORDER . " LIMIT :lim OFFSET :off");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Dodatnie liczby całkowite bez powtórzeń (ID z rozszerzeń wstawiane wprost do IN (...)). @return int[] */
    public static function cleanIds(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }
        return array_slice(array_values(array_unique(array_filter(array_map('intval', array_filter($ids, 'is_numeric')), static fn(int $i) => $i > 0))), 0, 5000);
    }

    /** Ręczna zmiana stanu/ceny PASE (panel) - PASE jako źródło prawdy. */
    public function setStockPrice(int $id, ?int $stock, ?float $price): void
    {
        $this->pdo->prepare('UPDATE products SET pase_stock = :s, pase_price = :p WHERE id = :id')
            ->execute([':s' => $stock, ':p' => $price, ':id' => $id]);
    }

    /**
     * Masowy zapis stanów/cen PASE. $items = [id => ['stock'=>?int, 'price'=>?float]].
     * @return int liczba zaktualizowanych wierszy
     */
    public function setStockPriceMany(array $items): int
    {
        $stmt = $this->pdo->prepare('UPDATE products SET pase_stock = :s, pase_price = :p WHERE id = :id');
        $n = 0;
        foreach ($items as $id => $v) {
            $stmt->execute([':s' => $v['stock'], ':p' => $v['price'], ':id' => (int) $id]);
            $n++;
        }
        return $n;
    }

    /**
     * Unikalne kategorie produktów (z kolumny categories JSON) + liczba produktów.
     * @return array<string,int>  nazwa kategorii => liczba produktów (posortowane wg nazwy)
     */
    public function distinctCategories(): array
    {
        try {
            $rows = $this->pdo->query("SELECT categories FROM products WHERE categories IS NOT NULL")
                ->fetchAll(PDO::FETCH_COLUMN);
        } catch (\PDOException $e) {
            return [];
        }
        $counts = [];
        foreach ($rows as $json) {
            $cats = json_decode((string) $json, true);
            if (!is_array($cats)) {
                continue;
            }
            foreach ($cats as $c) {
                $c = trim((string) $c);
                if ($c !== '') {
                    $counts[$c] = ($counts[$c] ?? 0) + 1;
                }
            }
        }
        ksort($counts, SORT_NATURAL | SORT_FLAG_CASE);
        return $counts;
    }

    /**
     * Liczba produktów per sklep (source_integration_id) - do filtra sklepu w panelu.
     * Klucz 0 = produkty bez przypisanego sklepu (import sprzed tego pola / ręcznie dodane).
     * @return array<int,int> integration_id => liczba produktów
     */
    public function countByIntegration(): array
    {
        try {
            $rows = $this->pdo->query(
                'SELECT COALESCE(source_integration_id, 0) AS iid, COUNT(*) AS n FROM products GROUP BY iid'
            )->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (\PDOException $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $iid => $n) {
            $out[(int) $iid] = (int) $n;
        }
        return $out;
    }

    /** Zapis wartości pól własnych produktu (JSON {klucz: wartość}). */
    public function setCustomFields(int $id, array $values): void
    {
        $this->pdo->prepare('UPDATE products SET custom_fields = :v WHERE id = :id')
            ->execute([':v' => json_encode($values, JSON_UNESCAPED_UNICODE), ':id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
    }

    /** Usuwa wiele produktów naraz. @param int[] $ids @return int liczba usuniętych */
    public function deleteMany(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn($i) => $i > 0));
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM products WHERE id IN ($in)");
        $stmt->execute($ids);
        return $stmt->rowCount();
    }
}
