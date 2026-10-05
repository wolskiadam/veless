<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;

/**
 * Lokalizacje w magazynie (etap 1): słownik miejsc „Regał 1, półka B” z kodem „R1-B”
 * i jedna lokalizacja na produkt (products.location_id).
 *
 * Kod składa się z regału, półki i opcjonalnego poziomu - wpisywanie z ręki kończyło by się
 * „R1 B”, „r1/b” i „Regał 1 B” jako trzema różnymi miejscami. Etykieta na półkę ma QR
 * z treścią „CRMLOC:R1-B”, żeby skaner odróżnił półkę od produktu.
 */
final class WarehouseLocations
{
    public const QR_PREFIX = 'CRMLOC:';

    public function __construct(private readonly PDO $pdo) {}

    // ------------------------------------------------------------------ schemat

    public static function migrate(PDO $pdo): void
    {
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $autoId = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $pdo->exec("CREATE TABLE IF NOT EXISTS warehouse_locations (
            id {$autoId},
            code VARCHAR(40) NOT NULL UNIQUE,
            rack VARCHAR(20) NOT NULL,
            shelf VARCHAR(20) NOT NULL,
            level VARCHAR(20) NULL,
            note VARCHAR(190) NULL,
            sort INT NOT NULL DEFAULT 0,
            created_at DATETIME NULL
        )" . ($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
        if (!self::columnExists($pdo, 'products', 'location_id')) {
            $pdo->exec('ALTER TABLE products ADD COLUMN location_id INT NULL');
            if ($mysql) {
                $pdo->exec('ALTER TABLE products ADD KEY idx_location (location_id)');
            }
        }
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                foreach ($pdo->query("PRAGMA table_info({$table})") as $c) {
                    if ($c['name'] === $column) {
                        return true;
                    }
                }
                return false;
            }
            // Nie „SHOW COLUMNS … LIKE ?”: przy natywnych prepared statements (config/database.php)
            // MySQL odrzuca „?” w SHOW, wyjątek dawał tu false i ALTER dodawał kolumnę drugi raz (błąd 500).
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
            $stmt->execute([$table, $column]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\PDOException) {
            return false;
        }
    }

    // ------------------------------------------------------------------ kody

    /** Jedna część kodu: wielkie litery, bez spacji i znaków spoza A-Z0-9. */
    private static function part(string $s): string
    {
        $s = strtoupper(trim($s));
        $s = strtr($s, ['Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
                         'ą' => 'A', 'ć' => 'C', 'ę' => 'E', 'ł' => 'L', 'ń' => 'N', 'ó' => 'O', 'ś' => 'S', 'ź' => 'Z', 'ż' => 'Z']);
        return (string) preg_replace('/[^A-Z0-9]/', '', $s);
    }

    /** Kod z części: regał „1” → „R1”, półka „b” → „B”, poziom opcjonalny: „R1-B-3”. */
    public static function makeCode(string $rack, string $shelf, ?string $level = null): string
    {
        $r = self::part($rack);
        if ($r !== '' && ctype_digit($r)) {
            $r = 'R' . $r;
        }
        $parts = [$r, self::part($shelf)];
        $l = self::part((string) $level);
        if ($l !== '') {
            $parts[] = $l;
        }
        return implode('-', $parts);
    }

    /** Kod wpisany/zeskanowany dowolnie („r1 b”, „R1/B”, „CRMLOC:R1-B”) → „R1-B”. */
    public static function normalizeCode(string $code): string
    {
        $code = trim($code);
        if (stripos($code, self::QR_PREFIX) === 0) {
            $code = substr($code, strlen(self::QR_PREFIX));
        }
        $pieces = array_values(array_filter(array_map([self::class, 'part'], preg_split('/[\s\-\/\.,;:_]+/', $code) ?: []), 'strlen'));
        if ($pieces === []) {
            return '';
        }
        // „R1B” bez separatora zostaje jak jest; „1-B” → „R1-B”.
        if (ctype_digit($pieces[0])) {
            $pieces[0] = 'R' . $pieces[0];
        }
        return implode('-', $pieces);
    }

    /** Treść kodu QR z etykiety półki albo null, gdy to nie półka. */
    public static function codeFromQr(string $text): ?string
    {
        $text = trim($text);
        return stripos($text, self::QR_PREFIX) === 0 ? self::normalizeCode($text) : null;
    }

    public static function label(array $loc): string
    {
        $rack = (string) $loc['rack'];
        $label = 'Regał ' . (preg_match('/^R(\d+)$/i', $rack, $m) ? $m[1] : $rack) . ', półka ' . $loc['shelf'];
        if (!empty($loc['level'])) {
            $label .= ', poziom ' . $loc['level'];
        }
        return $label;
    }

    // ------------------------------------------------------------------ słownik

    /** @return list<array> lokalizacje z liczbą produktów, w kolejności chodzenia po magazynie */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT l.*, (SELECT COUNT(*) FROM products p WHERE p.location_id = l.id) AS products
            FROM warehouse_locations l ORDER BY l.sort, l.code')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['products'] = (int) $r['products'];
            $r['label'] = self::label($r);
        }
        return $rows;
    }

    public function count(): int
    {
        try {
            return (int) $this->pdo->query('SELECT COUNT(*) FROM warehouse_locations')->fetchColumn();
        } catch (\PDOException) {
            return 0;
        }
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM warehouse_locations WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ? $r + ['label' => self::label($r)] : null;
    }

    public function findByCode(string $code): ?array
    {
        $code = self::normalizeCode($code);
        if ($code === '') {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM warehouse_locations WHERE code = ?');
        $stmt->execute([$code]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ? $r + ['label' => self::label($r)] : null;
    }

    /** Nowa lokalizacja. Zwraca id. */
    public function create(string $rack, string $shelf, ?string $level = null, ?string $note = null, ?int $sort = null): int
    {
        [$code, $r, $s, $l] = $this->validate($rack, $shelf, $level);
        if ($this->findByCode($code) !== null) {
            throw new \RuntimeException("Lokalizacja {$code} już istnieje.");
        }
        $sort ??= (int) $this->pdo->query('SELECT COALESCE(MAX(sort), 0) FROM warehouse_locations')->fetchColumn() + 10;
        $this->pdo->prepare('INSERT INTO warehouse_locations (code, rack, shelf, level, note, sort, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$code, $r, $s, $l, self::cleanNote($note), $sort, gmdate('Y-m-d H:i:s')]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $rack, string $shelf, ?string $level, ?string $note, int $sort): void
    {
        [$code, $r, $s, $l] = $this->validate($rack, $shelf, $level);
        $other = $this->findByCode($code);
        if ($other !== null && (int) $other['id'] !== $id) {
            throw new \RuntimeException("Lokalizacja {$code} już istnieje.");
        }
        $this->pdo->prepare('UPDATE warehouse_locations SET code = ?, rack = ?, shelf = ?, level = ?, note = ?, sort = ? WHERE id = ?')
            ->execute([$code, $r, $s, $l, self::cleanNote($note), $sort, $id]);
    }

    /** @param array<int,int> $sorts id => kolejność */
    public function saveOrder(array $sorts): void
    {
        $stmt = $this->pdo->prepare('UPDATE warehouse_locations SET sort = ? WHERE id = ?');
        foreach ($sorts as $id => $sort) {
            $stmt->execute([(int) $sort, (int) $id]);
        }
    }

    /** Usuwa lokalizację; produkty z niej zostają „bez lokalizacji”. Zwraca liczbę odpiętych produktów. */
    public function delete(int $id): int
    {
        $unset = $this->pdo->prepare('UPDATE products SET location_id = NULL WHERE location_id = ?');
        $unset->execute([$id]);
        $this->pdo->prepare('DELETE FROM warehouse_locations WHERE id = ?')->execute([$id]);
        return $unset->rowCount();
    }

    /** @return array{0:string,1:string,2:string,3:?string} */
    private function validate(string $rack, string $shelf, ?string $level): array
    {
        $code = self::makeCode($rack, $shelf, $level);
        [$r, $s] = array_pad(explode('-', $code), 2, '');
        $l = self::part((string) $level);
        if ($r === '' || $s === '') {
            throw new \RuntimeException('Podaj regał i półkę (litery lub cyfry).');
        }
        if (strlen($code) > 40) {
            throw new \RuntimeException('Za długi kod lokalizacji.');
        }
        return [$code, $r, $s, $l !== '' ? $l : null];
    }

    private static function cleanNote(?string $note): ?string
    {
        $note = trim((string) $note);
        return $note !== '' ? mb_substr($note, 0, 190) : null;
    }

    // ------------------------------------------------------------------ produkty

    public function assign(int $productId, ?int $locationId): void
    {
        $this->pdo->prepare('UPDATE products SET location_id = ? WHERE id = ?')->execute([$locationId ?: null, $productId]);
    }

    /** @param list<int> $productIds */
    public function assignMany(array $productIds, ?int $locationId): int
    {
        $stmt = $this->pdo->prepare('UPDATE products SET location_id = ? WHERE id = ?');
        $n = 0;
        foreach (array_unique(array_map('intval', $productIds)) as $pid) {
            if ($pid > 0) {
                $stmt->execute([$locationId ?: null, $pid]);
                $n += $stmt->rowCount();
            }
        }
        return $n;
    }

    /**
     * Produkty o danym EAN albo SKU (skaner: kod z opakowania).
     * @return list<array{id:int,sku:string,name:string,location_id:?int}>
     */
    public function productsByCode(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            return [];
        }
        $variants = [$code];
        if (preg_match('/^\d{12}$/', $code)) {
            $variants[] = '0' . $code;            // UPC-A zapisany jako EAN-13
        } elseif (preg_match('/^0\d{12}$/', $code)) {
            $variants[] = substr($code, 1);
        }
        $in = implode(',', array_fill(0, count($variants), '?'));
        $stmt = $this->pdo->prepare("SELECT id, sku, name, location_id FROM products
            WHERE ean IN ({$in}) OR LOWER(sku) IN ({$in}) ORDER BY id");
        $stmt->execute([...$variants, ...array_map('strtolower', $variants)]);
        return array_map(static fn(array $r) => ['id' => (int) $r['id'], 'sku' => (string) $r['sku'], 'name' => (string) ($r['name'] ?? ''),
            'location_id' => $r['location_id'] !== null ? (int) $r['location_id'] : null], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Skaner telefonu: zeskanowany produkt dostaje lokalizację.
     * @return array{products:list<array>,location:array}
     */
    public function assignByCode(string $productCode, int $locationId): array
    {
        $loc = $this->find($locationId) ?? throw new \RuntimeException('Nie ma takiej lokalizacji.');
        $products = $this->productsByCode($productCode);
        if ($products === []) {
            throw new \RuntimeException('Nie znam produktu o kodzie „' . $productCode . '” (EAN ani SKU w magazynie).');
        }
        $this->assignMany(array_column($products, 'id'), $locationId);
        return ['products' => $products, 'location' => $loc];
    }

    /** @return array<int,array{code:string,label:string,sort:int}> product_id => lokalizacja */
    public function forProducts(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT p.id AS pid, l.* FROM products p JOIN warehouse_locations l ON l.id = p.location_id WHERE p.id IN ({$in})");
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['pid']] = ['code' => (string) $r['code'], 'label' => self::label($r), 'sort' => (int) $r['sort']];
        }
        return $out;
    }

    // ------------------------------------------------------------------ import CSV

    /**
     * Wiersze „SKU;lokalizacja” (albo EAN zamiast SKU; separator ; , lub tab; nagłówek pomijany).
     * Brakującą lokalizację o poprawnym kodzie („R1-B”, „R1-B-3”) zakłada sama.
     * @return array{assigned:int,created:list<string>,missing:list<string>,bad:list<string>}
     */
    public function importCsv(string $content): array
    {
        $res = ['assigned' => 0, 'created' => [], 'missing' => [], 'bad' => []];
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $n => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = array_map('trim', preg_split('/[;\t,]/', $line, 2) ?: []);
            [$productCode, $locCode] = array_pad($cols, 2, '');
            if ($n === 0 && preg_match('/sku|ean|kod|produkt/i', $productCode) && preg_match('/lok|miejsce|location|p[oó]łk/i', $locCode)) {
                continue;   // nagłówek
            }
            $norm = self::normalizeCode($locCode);
            $parts = $norm !== '' ? explode('-', $norm) : [];
            if ($productCode === '' || count($parts) < 2 || count($parts) > 3) {
                $res['bad'][] = trim($line);
                continue;
            }
            $loc = $this->findByCode($norm);
            if ($loc === null) {
                $id = $this->create($parts[0], $parts[1], $parts[2] ?? null);
                $res['created'][] = $norm;
            } else {
                $id = (int) $loc['id'];
            }
            $products = $this->productsByCode($productCode);
            if ($products === []) {
                $res['missing'][] = $productCode;
                continue;
            }
            $this->assignMany(array_column($products, 'id'), $id);
            $res['assigned'] += count($products);
        }
        return $res;
    }

    // ------------------------------------------------------------------ etykiety

    /**
     * Etykieta ZPL na półkę: QR (CRMLOC:kod) + duży kod + opis. Szerokość z ustawień agenta druku.
     */
    public static function zpl(array $loc, int $dpi = 203, float $labelMm = 100.0): string
    {
        $dots = static fn(float $mm) => (int) round($mm / 25.4 * $dpi);
        $w = $dots($labelMm);
        $h = $dots(min(60.0, max(30.0, $labelMm / 2)));
        $mag = max(3, min(10, (int) floor($h / 90)));
        $pad = $dots(3);
        $qrSize = 25 * $mag;              // QR wersji 2 (25 modułów) - kod ma kilkanaście znaków
        $textX = $pad * 2 + $qrSize + $pad;
        $big = max(40, min($dots(14), (int) (($w - $textX - $pad) / max(3, strlen((string) $loc['code'])) * 1.6)));
        $esc = static fn(string $s) => str_replace(['^', '~'], [' ', ' '], $s);
        return '^XA^CI28^PW' . $w . '^LL' . $h
            . '^FO' . $pad . ',' . $pad . '^BQN,2,' . $mag . '^FDQA,' . $esc(self::QR_PREFIX . $loc['code']) . '^FS'
            . '^FO' . $textX . ',' . ($pad * 2) . '^A0N,' . $big . ',' . $big . '^FD' . $esc((string) $loc['code']) . '^FS'
            . '^FO' . $textX . ',' . ($pad * 2 + $big + $pad) . '^A0N,' . $dots(4) . ',' . $dots(4) . '^FD' . $esc(self::label($loc)) . '^FS'
            . (!empty($loc['note']) ? '^FO' . $textX . ',' . ($pad * 3 + $big + $dots(5)) . '^A0N,' . $dots(3.2) . ',' . $dots(3.2) . '^FD' . $esc((string) $loc['note']) . '^FS' : '')
            . '^XZ';
    }
}
