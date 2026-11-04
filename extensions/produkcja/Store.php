<?php
declare(strict_types=1);

namespace PaseExt\Produkcja;

use Pase\Repository\SettingsRepository;
use PDO;

/**
 * Dane wtyczki Produkcja: własne tabele ext_prod_* (składnia działa w MySQL i SQLite, identyfikatory to losowy tekst
 * jak w Paragonach, bo AUTO_INCREMENT pisze się w obu bazach inaczej).
 *
 * Stan materiału = suma tego, co zostało z jego dostaw (remaining). Partia zdejmuje z dostaw po kolei (najstarsza
 * pierwsza albo wybrana), inwentaryzacja dopisuje albo zdejmuje różnicę. Każda zmiana ma wpis w ext_prod_moves.
 */
final class Store
{
    public const SCHEMA = '1';
    public const CATEGORIES = ['wosk' => 'Wosk', 'zapach' => 'Zapach', 'knot' => 'Knot', 'pojemnik' => 'Pojemnik', 'barwnik' => 'Barwnik',
        'dodatek' => 'Dodatek', 'opakowanie' => 'Opakowanie', 'etykieta' => 'Etykieta', 'inne' => 'Inne'];
    public const UNITS = ['g' => 'g', 'ml' => 'ml', 'szt' => 'szt.'];
    public const RESULTS = ['ok' => 'Zaliczony', 'fix' => 'Do poprawy', 'fail' => 'Odrzucony', '' => 'W trakcie'];

    private static bool $ready = false;

    public function __construct(public readonly PDO $pdo)
    {
        if (!self::$ready) {
            $this->migrate();
            self::$ready = true;
        }
    }

    public static function resetForTests(): void
    {
        self::$ready = false;
    }

    private function migrate(): void
    {
        $settings = new SettingsRepository($this->pdo);
        if ($settings->get('EXT_PRODUKCJA_SCHEMA', '') === self::SCHEMA && $this->tableExists()) {
            return;
        }
        $t = [
            'ext_prod_materials' => 'id VARCHAR(16) NOT NULL PRIMARY KEY, name VARCHAR(200) NOT NULL, category VARCHAR(20) NOT NULL, code VARCHAR(8) NOT NULL,
                unit VARCHAR(8) NOT NULL, min_stock DECIMAL(14,3) NULL, supplier VARCHAR(200) NOT NULL, fill_g DECIMAL(10,2) NULL, notes TEXT NOT NULL,
                archived INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL',
            'ext_prod_deliveries' => 'id VARCHAR(16) NOT NULL PRIMARY KEY, material_id VARCHAR(16) NOT NULL, received_at VARCHAR(10) NOT NULL,
                supplier VARCHAR(200) NOT NULL, lot_no VARCHAR(100) NOT NULL, expires_at VARCHAR(10) NULL, qty DECIMAL(14,3) NOT NULL,
                remaining DECIMAL(14,3) NOT NULL, price_total DECIMAL(12,2) NULL, notes TEXT NOT NULL, created_by VARCHAR(100) NULL,
                created_at DATETIME NOT NULL, deleted_at DATETIME NULL',
            'ext_prod_products' => 'id VARCHAR(16) NOT NULL PRIMARY KEY, name VARCHAR(200) NOT NULL, sku VARCHAR(191) NOT NULL,
                shelf_life_months INT NOT NULL DEFAULT 12, price DECIMAL(12,2) NULL, vat INT NOT NULL DEFAULT 23, min_stock INT NULL,
                extra_cost DECIMAL(12,2) NULL, labor_minutes DECIMAL(8,2) NULL, notes TEXT NOT NULL, archived INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL',
            'ext_prod_recipe' => 'id VARCHAR(16) NOT NULL PRIMARY KEY, product_id VARCHAR(16) NOT NULL, material_id VARCHAR(16) NOT NULL,
                qty DECIMAL(14,3) NOT NULL, position INT NOT NULL DEFAULT 0',
            'ext_prod_batches' => 'id VARCHAR(16) NOT NULL PRIMARY KEY, code VARCHAR(60) NOT NULL, product_id VARCHAR(16) NULL, product_name VARCHAR(200) NOT NULL,
                produced_at VARCHAR(10) NOT NULL, qty INT NOT NULL, expires_at VARCHAR(10) NULL, person VARCHAR(100) NOT NULL, shift VARCHAR(100) NOT NULL,
                work_hours DECIMAL(8,2) NULL, cost_materials DECIMAL(12,2) NULL, cost_labor DECIMAL(12,2) NULL, lines_json TEXT NOT NULL,
                stock_json TEXT NOT NULL, is_draft INT NOT NULL DEFAULT 0, imported INT NOT NULL DEFAULT 0, notes TEXT NOT NULL,
                created_by VARCHAR(100) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME NULL',
            'ext_prod_dict' => 'id VARCHAR(16) NOT NULL PRIMARY KEY, kind VARCHAR(10) NOT NULL, name VARCHAR(100) NOT NULL, code VARCHAR(4) NOT NULL,
                rate DECIMAL(8,2) NULL',
            'ext_prod_moves' => 'id VARCHAR(16) NOT NULL PRIMARY KEY, material_id VARCHAR(16) NOT NULL, delivery_id VARCHAR(16) NULL,
                batch_id VARCHAR(16) NULL, kind VARCHAR(16) NOT NULL, qty DECIMAL(14,3) NOT NULL, note VARCHAR(255) NOT NULL,
                created_by VARCHAR(100) NULL, created_at DATETIME NOT NULL',
            'ext_prod_tests' => 'id VARCHAR(16) NOT NULL PRIMARY KEY, product_id VARCHAR(16) NULL, batch_id VARCHAR(16) NULL, tested_at VARCHAR(10) NOT NULL,
                wick VARCHAR(200) NOT NULL, result VARCHAR(8) NOT NULL, measurements TEXT NOT NULL, notes TEXT NOT NULL, photo VARCHAR(64) NOT NULL,
                photo_mime VARCHAR(40) NOT NULL, created_by VARCHAR(100) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL',
            'ext_prod_order_batches' => 'order_id INT NOT NULL, item_idx INT NOT NULL, sku VARCHAR(191) NOT NULL, item_name VARCHAR(255) NOT NULL,
                batch_code VARCHAR(60) NOT NULL, batch_id VARCHAR(16) NULL, qty INT NOT NULL, created_by VARCHAR(100) NULL,
                created_at DATETIME NOT NULL, PRIMARY KEY (order_id, item_idx)',
        ];
        foreach ($t as $name => $cols) {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$name} ({$cols})");
        }
        // Indeksy tylko przy pierwszym założeniu (MySQL nie zna CREATE INDEX IF NOT EXISTS).
        foreach (['ext_prod_deliveries (material_id)', 'ext_prod_recipe (product_id)', 'ext_prod_batches (code)', 'ext_prod_batches (product_id)',
            'ext_prod_moves (material_id)', 'ext_prod_order_batches (batch_code)'] as $i => $idx) {
            try {
                $this->pdo->exec('CREATE INDEX ext_prod_idx' . $i . ' ON ' . $idx);
            } catch (\PDOException) {
                // już jest
            }
        }
        $settings->setMany(['EXT_PRODUKCJA_SCHEMA' => self::SCHEMA]);
    }

    private function tableExists(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM ext_prod_order_batches WHERE 1 = 0');
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    public static function id(): string
    {
        return bin2hex(random_bytes(8));
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** Liczba z formularza: „1 234,5” → 1234.5; puste → null. */
    public static function num(mixed $v): ?float
    {
        $s = str_replace([' ', "\u{a0}", ','], ['', '', '.'], trim((string) $v));
        return $s === '' || !is_numeric($s) ? null : (float) $s;
    }

    public function one(string $sql, array $args = []): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function all(string $sql, array $args = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function run(string $sql, array $args = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->rowCount();
    }

    public function insert(string $table, array $row): void
    {
        $cols = array_keys($row);
        $this->run('INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($row));
    }

    public function update(string $table, string $id, array $row): void
    {
        $set = implode(', ', array_map(static fn(string $c): string => $c . ' = ?', array_keys($row)));
        $this->run('UPDATE ' . $table . ' SET ' . $set . ' WHERE id = ?', [...array_values($row), $id]);
    }

    public function tx(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn();
        }
        $this->pdo->beginTransaction();
        try {
            $r = $fn();
            $this->pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // ---------------------------------------------------------------- materiały

    public function material(string $id): ?array
    {
        return $this->one('SELECT * FROM ext_prod_materials WHERE id = ?', [$id]);
    }

    /**
     * Materiały ze stanem, ceną jednostkową i najbliższą datą ważności.
     * @return list<array<string,mixed>>
     */
    public function materials(bool $archived = false, string $q = '', string $cat = ''): array
    {
        $sql = 'SELECT m.*, COALESCE(SUM(CASE WHEN d.deleted_at IS NULL THEN d.remaining END), 0) AS stock,
                MIN(CASE WHEN d.deleted_at IS NULL AND d.remaining > 0 AND d.expires_at IS NOT NULL AND d.expires_at <> \'\' THEN d.expires_at END) AS expires
            FROM ext_prod_materials m LEFT JOIN ext_prod_deliveries d ON d.material_id = m.id
            WHERE m.archived = ?';
        $args = [$archived ? 1 : 0];
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], '', $q) . '%';
            $sql .= ' AND (m.name LIKE ? OR m.code LIKE ? OR m.supplier LIKE ?)';
            array_push($args, $like, $like, $like);
        }
        if ($cat !== '' && isset(self::CATEGORIES[$cat])) {
            $sql .= ' AND m.category = ?';
            $args[] = $cat;
        }
        $rows = $this->all($sql . ' GROUP BY m.id, m.name, m.category, m.code, m.unit, m.min_stock, m.supplier, m.fill_g, m.notes, m.archived,
            m.created_at, m.updated_at ORDER BY m.name', $args);
        $prices = $this->unitPrices();
        foreach ($rows as &$r) {
            $r['stock'] = (float) $r['stock'];
            $r['unit_price'] = $prices[$r['id']] ?? null;
            $r['low'] = $r['min_stock'] !== null && $r['min_stock'] !== '' && $r['stock'] <= (float) $r['min_stock'];
        }
        return $rows;
    }

    public function stock(string $materialId): float
    {
        $r = $this->one('SELECT COALESCE(SUM(remaining), 0) AS s FROM ext_prod_deliveries WHERE material_id = ? AND deleted_at IS NULL', [$materialId]);
        return (float) ($r['s'] ?? 0);
    }

    /**
     * Cena za jednostkę (g, ml, szt.) każdego materiału: średnia ważona z dostaw, które jeszcze leżą na półce,
     * a gdy wszystko zużyte - z ostatniej dostawy z ceną.
     * @return array<string,float>
     */
    public function unitPrices(): array
    {
        $out = [];
        $last = [];
        foreach ($this->all('SELECT material_id, qty, remaining, price_total, received_at FROM ext_prod_deliveries
            WHERE deleted_at IS NULL AND price_total IS NOT NULL AND qty > 0 ORDER BY received_at, created_at') as $d) {
            $unit = (float) $d['price_total'] / (float) $d['qty'];
            $last[$d['material_id']] = $unit;
            if ((float) $d['remaining'] > 0) {
                $out[$d['material_id']]['v'] = ($out[$d['material_id']]['v'] ?? 0) + $unit * (float) $d['remaining'];
                $out[$d['material_id']]['q'] = ($out[$d['material_id']]['q'] ?? 0) + (float) $d['remaining'];
            }
        }
        $prices = $last;
        foreach ($out as $id => $a) {
            $prices[$id] = $a['v'] / $a['q'];
        }
        return $prices;
    }

    public function saveMaterial(?string $id, array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Wpisz nazwę materiału.');
        }
        $cat = isset(self::CATEGORIES[(string) ($in['category'] ?? '')]) ? (string) $in['category'] : 'inne';
        $unit = isset(self::UNITS[(string) ($in['unit'] ?? '')]) ? (string) $in['unit'] : (in_array($cat, ['knot', 'pojemnik', 'opakowanie', 'etykieta'], true) ? 'szt' : 'g');
        $row = [
            'name' => mb_substr($name, 0, 200),
            'category' => $cat,
            'code' => strtoupper(mb_substr(trim((string) ($in['code'] ?? '')), 0, 8)),
            'unit' => $unit,
            'min_stock' => self::num($in['min_stock'] ?? ''),
            'supplier' => mb_substr(trim((string) ($in['supplier'] ?? '')), 0, 200),
            'fill_g' => self::num($in['fill_g'] ?? ''),
            'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 2000),
            'updated_at' => self::now(),
        ];
        $dupe = $this->one('SELECT id FROM ext_prod_materials WHERE LOWER(name) = LOWER(?) AND archived = 0' . ($id !== null ? ' AND id <> ?' : ''),
            $id !== null ? [$row['name'], $id] : [$row['name']]);
        if ($dupe !== null) {
            throw new \RuntimeException('Materiał „' . $row['name'] . '” już jest na liście.');
        }
        if ($id === null) {
            $id = self::id();
            $this->insert('ext_prod_materials', ['id' => $id, 'archived' => 0, 'created_at' => self::now()] + $row);
        } else {
            $this->update('ext_prod_materials', $id, $row);
        }
        return $this->material($id) ?? [];
    }

    // ---------------------------------------------------------------- dostawy

    public function delivery(string $id): ?array
    {
        return $this->one('SELECT * FROM ext_prod_deliveries WHERE id = ?', [$id]);
    }

    /** @return list<array<string,mixed>> */
    public function deliveries(string $materialId, bool $deleted = false): array
    {
        return $this->all('SELECT * FROM ext_prod_deliveries WHERE material_id = ? AND deleted_at IS ' . ($deleted ? 'NOT ' : '') . 'NULL
            ORDER BY received_at DESC, created_at DESC', [$materialId]);
    }

    public function addDelivery(string $materialId, array $in, string $user): array
    {
        $m = $this->material($materialId) ?? throw new \RuntimeException('Nie ma takiego materiału.');
        $qty = self::num($in['qty'] ?? '');
        if ($qty === null || $qty <= 0) {
            throw new \RuntimeException('Wpisz ilość z dostawy (w ' . self::UNITS[$m['unit']] . ').');
        }
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in['received_at'] ?? '')) ? (string) $in['received_at'] : date('Y-m-d');
        $exp = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in['expires_at'] ?? '')) ? (string) $in['expires_at'] : null;
        $id = self::id();
        $this->tx(function () use ($id, $materialId, $date, $exp, $qty, $in, $user, $m): void {
            $this->insert('ext_prod_deliveries', ['id' => $id, 'material_id' => $materialId, 'received_at' => $date,
                'supplier' => mb_substr(trim((string) ($in['supplier'] ?? $m['supplier'])), 0, 200),
                'lot_no' => mb_substr(trim((string) ($in['lot_no'] ?? '')), 0, 100), 'expires_at' => $exp, 'qty' => $qty, 'remaining' => $qty,
                'price_total' => self::num($in['price_total'] ?? ''), 'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 1000),
                'created_by' => $user, 'created_at' => self::now(), 'deleted_at' => null]);
            $this->move($materialId, $id, null, 'dostawa', $qty, trim((string) ($in['lot_no'] ?? '')) !== '' ? 'partia dostawcy ' . trim((string) $in['lot_no']) : '', $user);
        });
        return $this->delivery($id) ?? [];
    }

    /** Usunięcie dostawy do kosza: znika z niej to, co zostało (stan spada o resztę). */
    public function trashDelivery(string $id, string $user): void
    {
        $d = $this->delivery($id) ?? throw new \RuntimeException('Nie ma takiej dostawy.');
        $this->tx(function () use ($d, $user): void {
            $this->run('UPDATE ext_prod_deliveries SET deleted_at = ? WHERE id = ?', [self::now(), $d['id']]);
            if ((float) $d['remaining'] > 0) {
                $this->move($d['material_id'], $d['id'], null, 'usunięcie', -(float) $d['remaining'], 'dostawa do kosza', $user);
            }
        });
    }

    public function restoreDelivery(string $id, string $user): void
    {
        $d = $this->delivery($id) ?? throw new \RuntimeException('Nie ma takiej dostawy.');
        $this->tx(function () use ($d, $user): void {
            $this->run('UPDATE ext_prod_deliveries SET deleted_at = NULL WHERE id = ?', [$d['id']]);
            if ((float) $d['remaining'] > 0) {
                $this->move($d['material_id'], $d['id'], null, 'przywrócenie', (float) $d['remaining'], 'dostawa z kosza', $user);
            }
        });
    }

    /**
     * Zdjęcie ilości z dostaw materiału: najpierw wybrana dostawa, potem od najstarszej (FIFO).
     * @return array{taken:list<array{delivery_id:string,lot_no:string,qty:float,cost:?float}>,missing:float}
     */
    public function consume(string $materialId, float $need, ?string $preferred, ?string $batchId, string $kind, string $note, string $user): array
    {
        $rows = $this->all('SELECT * FROM ext_prod_deliveries WHERE material_id = ? AND deleted_at IS NULL AND remaining > 0
            ORDER BY received_at, created_at', [$materialId]);
        if ($preferred !== null && $preferred !== '') {
            usort($rows, static fn(array $a, array $b): int => ($b['id'] === $preferred) <=> ($a['id'] === $preferred));
        }
        $taken = [];
        foreach ($rows as $d) {
            if ($need <= 0.0000001) {
                break;
            }
            $take = min($need, (float) $d['remaining']);
            $this->run('UPDATE ext_prod_deliveries SET remaining = remaining - ? WHERE id = ?', [$take, $d['id']]);
            $this->move($materialId, $d['id'], $batchId, $kind, -$take, $note, $user);
            $unit = $d['price_total'] !== null && (float) $d['qty'] > 0 ? (float) $d['price_total'] / (float) $d['qty'] : null;
            $taken[] = ['delivery_id' => $d['id'], 'lot_no' => (string) $d['lot_no'], 'qty' => round($take, 3), 'cost' => $unit !== null ? round($take * $unit, 4) : null];
            $need -= $take;
        }
        return ['taken' => $taken, 'missing' => max(0.0, round($need, 3))];
    }

    /** Oddanie ilości do konkretnej dostawy (cofnięcie partii). */
    public function giveBack(string $materialId, string $deliveryId, float $qty, ?string $batchId, string $note, string $user): void
    {
        $this->run('UPDATE ext_prod_deliveries SET remaining = remaining + ? WHERE id = ?', [$qty, $deliveryId]);
        $this->move($materialId, $deliveryId, $batchId, 'zwrot', $qty, $note, $user);
    }

    public function move(string $materialId, ?string $deliveryId, ?string $batchId, string $kind, float $qty, string $note, string $user): void
    {
        $this->insert('ext_prod_moves', ['id' => self::id(), 'material_id' => $materialId, 'delivery_id' => $deliveryId, 'batch_id' => $batchId,
            'kind' => $kind, 'qty' => round($qty, 3), 'note' => mb_substr($note, 0, 255), 'created_by' => $user, 'created_at' => self::now()]);
    }

    /** @return list<array<string,mixed>> */
    public function moves(string $materialId, int $limit = 50): array
    {
        return $this->all('SELECT mv.*, b.code AS batch_code FROM ext_prod_moves mv LEFT JOIN ext_prod_batches b ON b.id = mv.batch_id
            WHERE mv.material_id = ? ORDER BY mv.created_at DESC, mv.id LIMIT ' . max(1, $limit), [$materialId]);
    }

    /** Inwentaryzacja: policzone ilości (id => liczba). Zwraca liczbę zmienionych materiałów. */
    public function inventory(array $counted, string $user): int
    {
        $n = 0;
        $this->tx(function () use ($counted, $user, &$n): void {
            foreach ($counted as $id => $v) {
                $val = self::num($v);
                $m = $this->material((string) $id);
                if ($val === null || $val < 0 || $m === null) {
                    continue;
                }
                $delta = round($val - $this->stock((string) $id), 3);
                if (abs($delta) < 0.0005) {
                    continue;
                }
                if ($delta < 0) {
                    $this->consume((string) $id, -$delta, null, null, 'inwentaryzacja', 'spis z natury', $user);
                } else {
                    $did = self::id();
                    $this->insert('ext_prod_deliveries', ['id' => $did, 'material_id' => $id, 'received_at' => date('Y-m-d'), 'supplier' => '',
                        'lot_no' => '', 'expires_at' => null, 'qty' => $delta, 'remaining' => $delta, 'price_total' => null,
                        'notes' => 'Nadwyżka z inwentaryzacji', 'created_by' => $user, 'created_at' => self::now(), 'deleted_at' => null]);
                    $this->move((string) $id, $did, null, 'inwentaryzacja', $delta, 'spis z natury', $user);
                }
                $n++;
            }
        });
        return $n;
    }

    /** Dostawy z datą ważności: przeterminowane i kończące się w ciągu $days dni (z czymś na stanie). */
    public function expiring(int $days = 30): array
    {
        return $this->all('SELECT d.*, m.name, m.unit FROM ext_prod_deliveries d JOIN ext_prod_materials m ON m.id = d.material_id
            WHERE d.deleted_at IS NULL AND d.remaining > 0 AND m.archived = 0 AND d.expires_at IS NOT NULL AND d.expires_at <> \'\' AND d.expires_at <= ?
            ORDER BY d.expires_at', [date('Y-m-d', strtotime('+' . $days . ' days'))]);
    }

    // ---------------------------------------------------------------- wyroby

    public function product(string $id): ?array
    {
        return $this->one('SELECT * FROM ext_prod_products WHERE id = ?', [$id]);
    }

    /** @return list<array<string,mixed>> */
    public function products(bool $archived = false, string $q = ''): array
    {
        $sql = 'SELECT * FROM ext_prod_products WHERE archived = ?';
        $args = [$archived ? 1 : 0];
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], '', $q) . '%';
            $sql .= ' AND (name LIKE ? OR sku LIKE ?)';
            array_push($args, $like, $like);
        }
        return $this->all($sql . ' ORDER BY name', $args);
    }

    /** @return list<array<string,mixed>> receptura z danymi materiału */
    public function recipe(string $productId): array
    {
        return $this->all('SELECT r.*, m.name, m.unit, m.category, m.code, m.archived FROM ext_prod_recipe r
            JOIN ext_prod_materials m ON m.id = r.material_id WHERE r.product_id = ? ORDER BY r.position, m.name', [$productId]);
    }

    public function saveProduct(?string $id, array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Wpisz nazwę wyrobu.');
        }
        $vat = (int) ($in['vat'] ?? 23);
        $row = [
            'name' => mb_substr($name, 0, 200),
            'sku' => mb_substr(trim((string) ($in['sku'] ?? '')), 0, 191),
            'shelf_life_months' => max(0, min(120, (int) ($in['shelf_life_months'] ?? 12))),
            'price' => self::num($in['price'] ?? ''),
            'vat' => in_array($vat, [0, 5, 8, 23], true) ? $vat : 23,
            'min_stock' => ($ms = self::num($in['min_stock'] ?? '')) === null ? null : max(0, (int) $ms),
            'extra_cost' => self::num($in['extra_cost'] ?? ''),
            'labor_minutes' => self::num($in['labor_minutes'] ?? ''),
            'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 2000),
            'updated_at' => self::now(),
        ];
        if ($row['sku'] !== '' && ($d = $this->one('SELECT name FROM ext_prod_products WHERE sku = ? AND archived = 0' . ($id !== null ? ' AND id <> ?' : ''),
            $id !== null ? [$row['sku'], $id] : [$row['sku']])) !== null) {
            throw new \RuntimeException('SKU ' . $row['sku'] . ' ma już wyrób „' . $d['name'] . '”.');
        }
        $lines = [];
        foreach ((array) ($in['recipe'] ?? []) as $i => $l) {
            $mid = (string) ($l['material_id'] ?? '');
            $q = self::num($l['qty'] ?? '');
            if ($mid !== '' && $q !== null && $q > 0 && $this->material($mid) !== null) {
                $lines[] = ['material_id' => $mid, 'qty' => $q, 'position' => (int) $i];
            }
        }
        return $this->tx(function () use ($id, $row, $lines): array {
            if ($id === null) {
                $id = self::id();
                $this->insert('ext_prod_products', ['id' => $id, 'archived' => 0, 'created_at' => self::now()] + $row);
            } else {
                $this->update('ext_prod_products', $id, $row);
            }
            $this->run('DELETE FROM ext_prod_recipe WHERE product_id = ?', [$id]);
            foreach ($lines as $l) {
                $this->insert('ext_prod_recipe', ['id' => self::id(), 'product_id' => $id] + $l);
            }
            return $this->product($id) ?? [];
        });
    }

    /**
     * Koszt jednej sztuki z receptury (ceny z dostaw) + inne koszty + robocizna.
     * @return array{materials:float,extra:float,labor:float,total:float,missing:list<string>}
     */
    public function unitCost(array $product, float $hourlyRate): array
    {
        $prices = $this->unitPrices();
        $mat = 0.0;
        $missing = [];
        foreach ($this->recipe((string) $product['id']) as $l) {
            if (!isset($prices[$l['material_id']])) {
                $missing[] = (string) $l['name'];
                continue;
            }
            $mat += (float) $l['qty'] * $prices[$l['material_id']];
        }
        $extra = (float) ($product['extra_cost'] ?? 0);
        $labor = (float) ($product['labor_minutes'] ?? 0) / 60 * $hourlyRate;
        return ['materials' => $mat, 'extra' => $extra, 'labor' => $labor, 'total' => $mat + $extra + $labor, 'missing' => $missing];
    }

    /** Ile sztuk wyrobu da się zrobić z tego, co jest na stanie (null = brak receptury). */
    public function canMake(string $productId, ?array $stocks = null): ?int
    {
        $recipe = $this->recipe($productId);
        if ($recipe === []) {
            return null;
        }
        $n = PHP_INT_MAX;
        foreach ($recipe as $l) {
            $have = $stocks[$l['material_id']] ?? $this->stock((string) $l['material_id']);
            $n = min($n, (int) floor($have / max(0.000001, (float) $l['qty']) + 1e-9));
        }
        return max(0, $n);
    }

    // ---------------------------------------------------------------- słowniki: osoby i zmiany

    /** @return list<array<string,mixed>> */
    public function dict(string $kind): array
    {
        return $this->all('SELECT * FROM ext_prod_dict WHERE kind = ? ORDER BY name', [$kind]);
    }

    public function saveDict(string $kind, array $names, array $codes, array $rates): void
    {
        $this->tx(function () use ($kind, $names, $codes, $rates): void {
            $this->run('DELETE FROM ext_prod_dict WHERE kind = ?', [$kind]);
            foreach ($names as $i => $n) {
                $n = trim((string) $n);
                if ($n === '') {
                    continue;
                }
                $this->insert('ext_prod_dict', ['id' => self::id(), 'kind' => $kind, 'name' => mb_substr($n, 0, 100),
                    'code' => strtoupper(mb_substr(trim((string) ($codes[$i] ?? '')), 0, 1)), 'rate' => self::num($rates[$i] ?? '')]);
            }
        });
    }

    // ---------------------------------------------------------------- partie

    public function batch(string $id): ?array
    {
        return $this->one('SELECT * FROM ext_prod_batches WHERE id = ?', [$id]);
    }

    public function batchByCode(string $code): ?array
    {
        return $this->one('SELECT * FROM ext_prod_batches WHERE UPPER(code) = UPPER(?) AND deleted_at IS NULL', [trim($code)]);
    }

    /** @return list<array<string,mixed>> */
    public function batches(array $f = [], int $limit = 200): array
    {
        $sql = 'SELECT * FROM ext_prod_batches WHERE deleted_at IS ' . (!empty($f['trash']) ? 'NOT ' : '') . 'NULL';
        $args = [];
        if (isset($f['draft'])) {
            $sql .= ' AND is_draft = ?';
            $args[] = $f['draft'] ? 1 : 0;
        }
        if (($f['q'] ?? '') !== '') {
            $like = '%' . str_replace(['%', '_'], '', (string) $f['q']) . '%';
            $sql .= ' AND (code LIKE ? OR product_name LIKE ? OR lines_json LIKE ? OR notes LIKE ?)';
            array_push($args, $like, $like, $like, $like);
        }
        if (($f['product'] ?? '') !== '') {
            $sql .= ' AND product_id = ?';
            $args[] = (string) $f['product'];
        }
        if (($f['from'] ?? '') !== '') {
            $sql .= ' AND produced_at >= ?';
            $args[] = (string) $f['from'];
        }
        if (($f['to'] ?? '') !== '') {
            $sql .= ' AND produced_at <= ?';
            $args[] = (string) $f['to'];
        }
        return $this->all($sql . ' ORDER BY produced_at DESC, created_at DESC LIMIT ' . max(1, $limit), $args);
    }

    /** @return list<string> */
    public function codesOn(string $date): array
    {
        return array_map('strval', array_column($this->all('SELECT code FROM ext_prod_batches WHERE deleted_at IS NULL'), 'code'));
    }

    public function countOnDay(string $date, ?string $except = null): int
    {
        $r = $this->one('SELECT COUNT(*) AS n FROM ext_prod_batches WHERE produced_at = ? AND deleted_at IS NULL AND is_draft = 0'
            . ($except !== null ? ' AND id <> ?' : ''), $except !== null ? [$date, $except] : [$date]);
        return (int) ($r['n'] ?? 0);
    }

    /** Kosz: trwałe usunięcie po 30 dniach. */
    public function purge(): void
    {
        $limit = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $this->run('DELETE FROM ext_prod_batches WHERE deleted_at IS NOT NULL AND deleted_at < ?', [$limit]);
        foreach ($this->all('SELECT id FROM ext_prod_deliveries WHERE deleted_at IS NOT NULL AND deleted_at < ?', [$limit]) as $d) {
            $this->run('DELETE FROM ext_prod_deliveries WHERE id = ?', [$d['id']]);
        }
    }

    // ---------------------------------------------------------------- testy spalania

    public function test(string $id): ?array
    {
        return $this->one('SELECT * FROM ext_prod_tests WHERE id = ?', [$id]);
    }

    /** @return list<array<string,mixed>> */
    public function tests(string $productId = ''): array
    {
        $sql = 'SELECT t.*, p.name AS product_name, b.code AS batch_code FROM ext_prod_tests t
            LEFT JOIN ext_prod_products p ON p.id = t.product_id LEFT JOIN ext_prod_batches b ON b.id = t.batch_id';
        $args = [];
        if ($productId !== '') {
            $sql .= ' WHERE t.product_id = ?';
            $args[] = $productId;
        }
        return $this->all($sql . ' ORDER BY t.tested_at DESC, t.created_at DESC LIMIT 300', $args);
    }

    // ---------------------------------------------------------------- zamówienia

    /** @return array<int,array<string,mixed>> idx pozycji => przypisana partia */
    public function orderBatches(int $orderId): array
    {
        $out = [];
        foreach ($this->all('SELECT * FROM ext_prod_order_batches WHERE order_id = ?', [$orderId]) as $r) {
            $out[(int) $r['item_idx']] = $r;
        }
        return $out;
    }

    public function setOrderBatch(int $orderId, int $idx, string $sku, string $name, int $qty, string $code, string $user): ?array
    {
        $code = strtoupper(trim($code));
        $this->run('DELETE FROM ext_prod_order_batches WHERE order_id = ? AND item_idx = ?', [$orderId, $idx]);
        if ($code === '') {
            return null;
        }
        $b = $this->batchByCode($code);
        $this->insert('ext_prod_order_batches', ['order_id' => $orderId, 'item_idx' => $idx, 'sku' => mb_substr($sku, 0, 191),
            'item_name' => mb_substr($name, 0, 255), 'batch_code' => mb_substr($code, 0, 60), 'batch_id' => $b['id'] ?? null,
            'qty' => max(1, $qty), 'created_by' => $user, 'created_at' => self::now()]);
        return $b;
    }

    /** Zamówienia, do których przypisano partię (ślad do skargi / GPSR). */
    public function ordersWithBatch(string $code): array
    {
        return $this->all('SELECT ob.*, o.pase_number, o.order_number, o.customer_name, o.date_created FROM ext_prod_order_batches ob
            LEFT JOIN woo_orders o ON o.woo_order_id = ob.order_id WHERE UPPER(ob.batch_code) = UPPER(?) ORDER BY ob.created_at DESC', [$code]);
    }

    /**
     * Ostatnie partie wyrobów po SKU (do wyboru przy pakowaniu).
     * @param list<string> $skus
     * @return array<string,list<array{code:string,date:string,qty:int}>> sku (małe litery) => partie od najnowszej
     */
    public function recentBatchesBySku(array $skus, int $per = 6): array
    {
        $skus = array_values(array_unique(array_filter(array_map(static fn($s) => mb_strtolower(trim((string) $s)), $skus))));
        if ($skus === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($skus), '?'));
        $out = [];
        foreach ($this->all("SELECT p.sku, b.code, b.produced_at, b.qty FROM ext_prod_batches b JOIN ext_prod_products p ON p.id = b.product_id
            WHERE LOWER(p.sku) IN ({$in}) AND b.deleted_at IS NULL AND b.is_draft = 0 ORDER BY b.produced_at DESC, b.created_at DESC", $skus) as $r) {
            $k = mb_strtolower((string) $r['sku']);
            if (count($out[$k] ?? []) < $per) {
                $out[$k][] = ['code' => (string) $r['code'], 'date' => (string) $r['produced_at'], 'qty' => (int) $r['qty']];
            }
        }
        return $out;
    }
}
