<?php
declare(strict_types=1);

namespace PaseExt\Zapachy;

use Pase\Repository\SettingsRepository;
use PDO;

/**
 * Dane wtyczki Zapachy: własne tabele ext_zap_* (składnia działa w MySQL i SQLite, identyfikatory to losowy tekst
 * jak w Produkcji).
 *
 *  - ext_zap_fragrances: zapach (olejek) z numerem miejsca w magazynie (storage_no, np. 18), nazwą i innymi nazwami,
 *    producentem i jego kodem, nutami głowy, serca i bazy, opisem; opcjonalnie powiązany z materiałem wtyczki Produkcja
 *    (material_id), z którego bierzemy stan magazynowy olejku.
 *  - ext_zap_notes: słownik nut z kategorią (cytrusowa, kwiatowa, słodka...), jak „Menadżer Nut” w Aroma Helper.
 *  - ext_zap_links: zapach przypisany do produktu w Magazynie (products.id). Produkt może mieć kilka zapachów (zestaw).
 *
 * Gdy produkt nie ma przypisanego zapachu, zapach pozycji zamówienia rozpoznajemy po wariancie („Zapach: Sweet Caramel”)
 * albo po nazwie produktu - patrz resolve().
 */
final class Store
{
    public const SCHEMA = '1';
    /** Klucze wariantu/atrybutu, które oznaczają zapach. */
    private const ATTR_KEYS = ['zapach', 'aromat', 'olejek', 'scent', 'fragrance', 'nuta'];

    private static bool $ready = false;
    /** @var list<array<string,mixed>>|null wszystkie zapachy (lista jest krótka - trzymamy ją przez żądanie) */
    private static ?array $cache = null;

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
        self::$cache = null;
    }

    private function migrate(): void
    {
        $settings = new SettingsRepository($this->pdo);
        if ($settings->get('EXT_ZAPACHY_SCHEMA', '') === self::SCHEMA && $this->tableExists()) {
            return;
        }
        $t = [
            'ext_zap_fragrances' => 'id VARCHAR(16) NOT NULL PRIMARY KEY, storage_no VARCHAR(20) NOT NULL, name VARCHAR(200) NOT NULL,
                aliases TEXT NOT NULL, producer VARCHAR(200) NOT NULL, producer_code VARCHAR(60) NOT NULL, head_notes TEXT NOT NULL,
                heart_notes TEXT NOT NULL, base_notes TEXT NOT NULL, description TEXT NOT NULL, comments TEXT NOT NULL,
                price_per_g DECIMAL(10,4) NULL, max_concentration DECIMAL(5,2) NULL, ufi VARCHAR(255) NOT NULL,
                material_id VARCHAR(16) NULL, source_id VARCHAR(60) NOT NULL, archived INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL',
            'ext_zap_notes' => 'id VARCHAR(16) NOT NULL PRIMARY KEY, name VARCHAR(100) NOT NULL, category VARCHAR(100) NOT NULL',
            'ext_zap_links' => 'product_id INT NOT NULL, fragrance_id VARCHAR(16) NOT NULL, created_by VARCHAR(100) NULL,
                created_at DATETIME NOT NULL, PRIMARY KEY (product_id, fragrance_id)',
        ];
        foreach ($t as $name => $cols) {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$name} ({$cols})");
        }
        foreach (['ext_zap_links (fragrance_id)', 'ext_zap_fragrances (storage_no)'] as $i => $idx) {
            try {
                $this->pdo->exec('CREATE INDEX ext_zap_idx' . $i . ' ON ' . $idx);
            } catch (\PDOException) {
                // już jest
            }
        }
        $settings->setMany(['EXT_ZAPACHY_SCHEMA' => self::SCHEMA]);
    }

    private function tableExists(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM ext_zap_links WHERE 1 = 0');
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

    private function insert(string $table, array $row): void
    {
        $cols = array_keys($row);
        $this->run('INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($row));
    }

    private function update(string $table, string $id, array $row): void
    {
        $set = implode(', ', array_map(static fn(string $c): string => $c . ' = ?', array_keys($row)));
        $this->run('UPDATE ' . $table . ' SET ' . $set . ' WHERE id = ?', [...array_values($row), $id]);
    }

    // ---------------------------------------------------------------- tekst

    /** Porównywanie nazw: małe litery, bez polskich znaków i interpunkcji, pojedyncze spacje. */
    public static function norm(string $s): string
    {
        $s = mb_strtolower(trim(html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8')));
        $s = strtr($s, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n', '&' => ' and ']);
        $s = preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    /** „wanilia, karmel; tonka” → ['wanilia', 'karmel', 'tonka'] (małe litery, bez powtórzeń). @return list<string> */
    public static function notes(string $text): array
    {
        $out = [];
        foreach (preg_split('/[,;\n]+/u', $text) ?: [] as $n) {
            $n = mb_strtolower(trim($n, " \t\r\n.-"));
            if ($n !== '' && !in_array($n, $out, true)) {
                $out[] = $n;
            }
        }
        return $out;
    }

    /** Wszystkie nazwy zapachu: główna i inne (np. nazwa handlowa producenta). @return list<string> */
    public static function names(array $f): array
    {
        $out = [trim((string) $f['name'])];
        foreach (preg_split('/[,;\n]+/u', (string) ($f['aliases'] ?? '')) ?: [] as $a) {
            $a = trim($a);
            if ($a !== '' && !in_array($a, $out, true)) {
                $out[] = $a;
            }
        }
        return array_values(array_filter($out, static fn(string $s) => $s !== ''));
    }

    /** „#18 Sweet Caramel” */
    public static function label(array $f): string
    {
        $no = trim((string) $f['storage_no']);
        return ($no !== '' ? '#' . $no . ' ' : '') . $f['name'];
    }

    // ---------------------------------------------------------------- zapachy

    public function fragrance(string $id): ?array
    {
        return $this->one('SELECT * FROM ext_zap_fragrances WHERE id = ?', [$id]);
    }

    /** Wszystkie aktywne zapachy, posortowane po numerze (naturalnie: 2, 10, 18). @return list<array<string,mixed>> */
    public function allFragrances(): array
    {
        if (self::$cache === null) {
            $rows = $this->all('SELECT * FROM ext_zap_fragrances WHERE archived = 0');
            usort($rows, static fn(array $a, array $b) => self::sortKey($a) <=> self::sortKey($b));
            self::$cache = $rows;
        }
        return self::$cache;
    }

    private static function sortKey(array $f): array
    {
        $no = trim((string) $f['storage_no']);
        return [$no === '' ? 1 : 0, is_numeric($no) ? (float) $no : PHP_FLOAT_MAX, $no, mb_strtolower((string) $f['name'])];
    }

    /**
     * Szukanie jak w Aroma Helper: nazwa, inne nazwy, numer, producent i jego kod, nuty. Sama liczba („18”) to numer miejsca
     * dokładnie - inaczej „1” znalazłoby też 10-19.
     * @return list<array<string,mixed>>
     */
    public function search(string $q = '', string $note = '', string $category = '', bool $archived = false): array
    {
        $rows = $archived ? $this->all('SELECT * FROM ext_zap_fragrances WHERE archived = 1') : $this->allFragrances();
        if ($archived) {
            usort($rows, static fn(array $a, array $b) => self::sortKey($a) <=> self::sortKey($b));
        }
        $q = trim($q);
        $noteN = mb_strtolower(trim($note));
        $catNotes = null;
        if (trim($category) !== '') {
            $catNotes = array_map(static fn(array $n) => mb_strtolower((string) $n['name']),
                $this->all('SELECT name FROM ext_zap_notes WHERE LOWER(category) = LOWER(?)', [trim($category)]));
        }
        return array_values(array_filter($rows, function (array $f) use ($q, $noteN, $catNotes): bool {
            $notes = self::fragranceNotes($f);
            if ($noteN !== '' && !in_array($noteN, $notes, true)) {
                return false;
            }
            if ($catNotes !== null && array_intersect($notes, $catNotes) === []) {
                return false;
            }
            return $q === '' || self::matches($f, $q);
        }));
    }

    /** Czy zapach pasuje do szukanego tekstu. */
    public static function matches(array $f, string $q): bool
    {
        $q = trim(ltrim(trim($q), '#'));
        if ($q === '') {
            return true;
        }
        if (preg_match('/^\d+[a-z]?$/i', $q)) {
            return mb_strtolower(trim((string) $f['storage_no'])) === mb_strtolower($q) || mb_strtolower(trim((string) $f['producer_code'])) === mb_strtolower($q);
        }
        $hay = self::norm(implode(' ', [$f['name'], $f['aliases'], $f['storage_no'], $f['producer'], $f['producer_code'],
            $f['head_notes'], $f['heart_notes'], $f['base_notes']]));
        foreach (explode(' ', self::norm($q)) as $word) {
            if ($word !== '' && !str_contains($hay, $word)) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> nuty zapachu (małe litery), głowa + serce + baza */
    public static function fragranceNotes(array $f): array
    {
        return array_values(array_unique(array_merge(self::notes((string) $f['head_notes']), self::notes((string) $f['heart_notes']), self::notes((string) $f['base_notes']))));
    }

    /** Zapis z formularza. @throws \RuntimeException */
    public function saveFragrance(?string $id, array $in): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($in['name'] ?? '')) ?? '');
        if ($name === '' || mb_strlen($name) > 200) {
            throw new \RuntimeException('Podaj nazwę zapachu (do 200 znaków).');
        }
        $no = trim(ltrim(trim((string) ($in['storage_no'] ?? '')), '#'));
        if (mb_strlen($no) > 20) {
            throw new \RuntimeException('Numer miejsca może mieć najwyżej 20 znaków.');
        }
        if ($no !== '') {
            $taken = $this->one('SELECT id, name FROM ext_zap_fragrances WHERE LOWER(storage_no) = LOWER(?) AND archived = 0 AND id <> ?', [$no, (string) $id]);
            if ($taken !== null) {
                throw new \RuntimeException('Numer ' . $no . ' ma już zapach „' . $taken['name'] . '”. Każde miejsce w magazynie ma jeden numer.');
            }
        }
        $notes = static fn(string $k): string => implode(', ', self::notes((string) ($in[$k] ?? '')));
        $row = [
            'storage_no' => $no,
            'name' => $name,
            'aliases' => implode(', ', array_slice(self::names(['name' => '', 'aliases' => (string) ($in['aliases'] ?? '')]), 0, 30)),
            'producer' => mb_substr(trim((string) ($in['producer'] ?? '')), 0, 200),
            'producer_code' => mb_substr(trim((string) ($in['producer_code'] ?? '')), 0, 60),
            'head_notes' => $notes('head_notes'),
            'heart_notes' => $notes('heart_notes'),
            'base_notes' => $notes('base_notes'),
            'description' => trim((string) ($in['description'] ?? '')),
            'comments' => trim((string) ($in['comments'] ?? '')),
            'price_per_g' => self::num($in['price_per_g'] ?? ''),
            'max_concentration' => self::num($in['max_concentration'] ?? ''),
            'ufi' => mb_substr(trim((string) ($in['ufi'] ?? '')), 0, 255),
            'updated_at' => self::now(),
        ];
        if (array_key_exists('material_id', $in)) {
            $mid = trim((string) $in['material_id']);
            $row['material_id'] = $mid !== '' && preg_match('/^[a-f0-9]{1,16}$/', $mid) ? $mid : null;
        }
        if (array_key_exists('source_id', $in)) {
            $row['source_id'] = mb_substr((string) $in['source_id'], 0, 60);
        }
        if ($id === null) {
            $id = self::id();
            $this->insert('ext_zap_fragrances', $row + ['id' => $id, 'material_id' => null, 'source_id' => '', 'archived' => 0, 'created_at' => self::now()]);
        } else {
            $this->update('ext_zap_fragrances', $id, $row);
        }
        self::$cache = null;
        return $this->fragrance($id) ?? [];
    }

    public function archive(string $id, bool $archived): void
    {
        $this->update('ext_zap_fragrances', $id, ['archived' => $archived ? 1 : 0, 'updated_at' => self::now()]);
        self::$cache = null;
    }

    public function deleteFragrance(string $id): void
    {
        $this->run('DELETE FROM ext_zap_links WHERE fragrance_id = ?', [$id]);
        $this->run('DELETE FROM ext_zap_fragrances WHERE id = ?', [$id]);
        self::$cache = null;
    }

    /** Najczęstsze nuty we wszystkich zapachach. @return array<string,int> nuta => liczba zapachów */
    public function topNotes(): array
    {
        $count = [];
        foreach ($this->allFragrances() as $f) {
            foreach (self::fragranceNotes($f) as $n) {
                $count[$n] = ($count[$n] ?? 0) + 1;
            }
        }
        arsort($count);
        return $count;
    }

    // ---------------------------------------------------------------- nuty (słownik)

    /** @return list<array<string,mixed>> */
    public function noteDict(string $q = ''): array
    {
        $rows = $this->all('SELECT * FROM ext_zap_notes ORDER BY name');
        if (trim($q) === '') {
            return $rows;
        }
        $q = mb_strtolower(trim($q));
        return array_values(array_filter($rows, static fn(array $n) => str_contains(mb_strtolower($n['name'] . ' ' . $n['category']), $q)));
    }

    /** @return array<string,string> nuta (małe litery) => kategoria */
    public function noteCategories(): array
    {
        $out = [];
        foreach ($this->all('SELECT name, category FROM ext_zap_notes') as $n) {
            $out[mb_strtolower((string) $n['name'])] = (string) $n['category'];
        }
        return $out;
    }

    /** @return list<string> */
    public function categories(): array
    {
        return array_values(array_filter(array_map(static fn(array $r) => (string) $r['category'],
            $this->all("SELECT DISTINCT category FROM ext_zap_notes WHERE category <> '' ORDER BY category")), static fn(string $c) => $c !== ''));
    }

    /** Dodaje albo zmienia kategorię nuty. @return bool true = nowa */
    public function saveNote(string $name, string $category): bool
    {
        $name = mb_strtolower(trim($name));
        $category = mb_strtolower(trim($category));
        if ($name === '' || mb_strlen($name) > 100 || mb_strlen($category) > 100) {
            throw new \RuntimeException('Nuta: nazwa od 1 do 100 znaków.');
        }
        $old = $this->one('SELECT id FROM ext_zap_notes WHERE LOWER(name) = ?', [$name]);
        if ($old !== null) {
            $this->update('ext_zap_notes', (string) $old['id'], ['category' => $category]);
            return false;
        }
        $this->insert('ext_zap_notes', ['id' => self::id(), 'name' => $name, 'category' => $category]);
        return true;
    }

    public function deleteNote(string $id): void
    {
        $this->run('DELETE FROM ext_zap_notes WHERE id = ?', [$id]);
    }

    /**
     * Masowe dodawanie jak w Aroma Helper: wiersz „nuta, kategoria”. Istniejące nuty dostają nową kategorię tylko
     * wtedy, gdy jej nie miały.
     * @return array{added:int,skipped:int,errors:list<string>}
     */
    public function bulkNotes(string $text): array
    {
        $res = ['added' => 0, 'skipped' => 0, 'errors' => []];
        $known = $this->noteCategories();
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', preg_split('/[,;\t]/u', $line, 2) ?: []);
            if (($parts[0] ?? '') === '') {
                $res['errors'][] = 'Pominięto: ' . $line;
                continue;
            }
            $name = mb_strtolower($parts[0]);
            if (isset($known[$name]) && ($known[$name] !== '' || ($parts[1] ?? '') === '')) {
                $res['skipped']++;
                continue;
            }
            try {
                $this->saveNote($name, (string) ($parts[1] ?? ''));
                $known[$name] = (string) ($parts[1] ?? '');
                $res['added']++;
            } catch (\RuntimeException $e) {
                $res['errors'][] = $line . ': ' . $e->getMessage();
            }
        }
        return $res;
    }

    // ---------------------------------------------------------------- produkty

    /** Zapachy przypisane do produktów. @param int[] $productIds @return array<int,list<array<string,mixed>>> */
    public function forProducts(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn(int $i) => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $byId = [];
        foreach ($this->allFragrances() as $f) {
            $byId[$f['id']] = $f;
        }
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach ($this->all("SELECT product_id, fragrance_id FROM ext_zap_links WHERE product_id IN ({$in})", $chunk) as $l) {
                if (isset($byId[$l['fragrance_id']])) {
                    $out[(int) $l['product_id']][] = $byId[$l['fragrance_id']];
                }
            }
        }
        foreach ($out as &$list) {
            usort($list, static fn(array $a, array $b) => self::sortKey($a) <=> self::sortKey($b));
        }
        return $out;
    }

    /** Produkty z tym zapachem. @return list<array<string,mixed>> */
    public function productsOf(string $fragranceId): array
    {
        try {
            return $this->all('SELECT p.id, p.sku, p.name FROM ext_zap_links l JOIN products p ON p.id = l.product_id WHERE l.fragrance_id = ? ORDER BY p.name, p.sku', [$fragranceId]);
        } catch (\PDOException) {
            return [];
        }
    }

    /** @return array<string,int> fragrance_id => liczba produktów */
    public function productCounts(): array
    {
        $out = [];
        foreach ($this->all('SELECT fragrance_id, COUNT(*) AS n FROM ext_zap_links GROUP BY fragrance_id') as $r) {
            $out[(string) $r['fragrance_id']] = (int) $r['n'];
        }
        return $out;
    }

    /** Ustawia zapachy produktu (zastępuje poprzednie). @param string[] $fragranceIds */
    public function setProductFragrances(int $productId, array $fragranceIds, string $user): void
    {
        $valid = array_column($this->allFragrances(), 'id');
        $ids = array_values(array_unique(array_intersect(array_map('strval', $fragranceIds), $valid)));
        $this->run('DELETE FROM ext_zap_links WHERE product_id = ?', [$productId]);
        foreach ($ids as $fid) {
            $this->insert('ext_zap_links', ['product_id' => $productId, 'fragrance_id' => $fid, 'created_by' => $user, 'created_at' => self::now()]);
        }
    }

    /** Dokłada zapach do produktów (bez ruszania innych zapachów). @return int ile nowych powiązań */
    public function link(string $fragranceId, array $productIds, string $user): int
    {
        if ($this->fragrance($fragranceId) === null) {
            return 0;
        }
        $n = 0;
        foreach (array_unique(array_map('intval', $productIds)) as $pid) {
            if ($pid <= 0 || $this->one('SELECT 1 AS x FROM ext_zap_links WHERE product_id = ? AND fragrance_id = ?', [$pid, $fragranceId]) !== null) {
                continue;
            }
            $this->insert('ext_zap_links', ['product_id' => $pid, 'fragrance_id' => $fragranceId, 'created_by' => $user, 'created_at' => self::now()]);
            $n++;
        }
        return $n;
    }

    public function unlink(string $fragranceId, int $productId): void
    {
        $this->run('DELETE FROM ext_zap_links WHERE product_id = ? AND fragrance_id = ?', [$productId, $fragranceId]);
    }

    /** ID produktów, których zapach pasuje do szukanego tekstu (szukajka w Magazynie). @return int[] */
    public function productIdsMatching(string $q): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $fids = array_column(array_filter($this->allFragrances(), static fn(array $f) => self::matches($f, $q)), 'id');
        if ($fids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($fids), '?'));
        return array_map('intval', array_column($this->all("SELECT DISTINCT product_id FROM ext_zap_links WHERE fragrance_id IN ({$in})", $fids), 'product_id'));
    }

    // ---------------------------------------------------------------- rozpoznawanie zapachu

    /**
     * Zapach pozycji (zamówienia, listy produkcji): najpierw przypisany do produktu o tym SKU, potem wariant
     * („Zapach: Sweet Caramel” albo numer), potem olejek z receptury wyrobu Produkcji o tym SKU, na końcu nazwa
     * produktu (tylko jedno, najdłuższe dopasowanie).
     *
     * @param list<array{0:string,1:string}> $attrs
     * @return list<array<string,mixed>> zapachy z kluczem 'how' = product|variant|recipe|name
     */
    public function resolve(string $sku, array $attrs, string $name, ?int $productId = null): array
    {
        $pid = $productId;
        if (($pid === null || $pid <= 0) && trim($sku) !== '') {
            $pid = $this->productIdsBySku([$sku])[mb_strtolower(trim($sku))] ?? null;
        }
        if ($pid !== null && $pid > 0) {
            $linked = $this->forProducts([$pid])[$pid] ?? [];
            if ($linked !== []) {
                return array_map(static fn(array $f) => $f + ['how' => 'product'], $linked);
            }
        }
        foreach ($attrs as [$k, $v]) {
            if (!self::isScentKey((string) $k)) {
                continue;
            }
            $f = $this->matchText((string) $v, true);
            if ($f !== null) {
                return [$f + ['how' => 'variant']];
            }
        }
        $fromRecipe = $this->fromProductionRecipe($sku);
        if ($fromRecipe !== []) {
            return $fromRecipe;
        }
        $f = $this->matchText($name, false);
        return $f !== null ? [$f + ['how' => 'name']] : [];
    }

    public static function isScentKey(string $key): bool
    {
        $k = self::norm($key);
        foreach (self::ATTR_KEYS as $w) {
            if (str_contains($k, $w)) {
                return true;
            }
        }
        return false;
    }

    /** „Zapach: Sweet Caramel | Knot: drewniany” → [[Zapach, Sweet Caramel], [Knot, drewniany]] */
    public static function parseAttrs(string $attrs): array
    {
        $out = [];
        foreach (preg_split('/\s*\|\s*/u', $attrs) ?: [] as $part) {
            if (str_contains($part, ':')) {
                [$k, $v] = array_map('trim', explode(':', $part, 2));
                if ($k !== '' && $v !== '') {
                    $out[] = [$k, $v];
                }
            }
        }
        return $out;
    }

    /**
     * Zapach z tekstu. $exact (wartość wariantu): cała wartość to nazwa albo numer zapachu, albo zawiera nazwę.
     * Z nazwy produktu bierzemy najdłuższą pasującą nazwę zapachu; dwa różne zapachy o tej samej długości = nie wiadomo.
     */
    public function matchText(string $text, bool $exact): ?array
    {
        $t = self::norm($text);
        if ($t === '') {
            return null;
        }
        if ($exact) {
            $no = ltrim(trim($text), '#');
            foreach ($this->allFragrances() as $f) {
                if ((trim((string) $f['storage_no']) !== '' && mb_strtolower(trim((string) $f['storage_no'])) === mb_strtolower($no))) {
                    return $f;
                }
                foreach (self::names($f) as $n) {
                    if (self::norm($n) === $t) {
                        return $f;
                    }
                }
            }
        }
        $best = null;
        $bestLen = 0;
        $tie = false;
        foreach ($this->allFragrances() as $f) {
            foreach (self::names($f) as $n) {
                $nn = self::norm($n);
                if (mb_strlen($nn) < 4 || !str_contains(' ' . $t . ' ', ' ' . $nn . ' ')) {
                    continue;
                }
                $len = mb_strlen($nn);
                if ($len > $bestLen) {
                    [$best, $bestLen, $tie] = [$f, $len, false];
                } elseif ($len === $bestLen && $best !== null && $best['id'] !== $f['id']) {
                    $tie = true;
                }
            }
        }
        return $tie ? null : $best;
    }

    /** @param string[] $skus @return array<string,int> sku małymi literami => products.id */
    public function productIdsBySku(array $skus): array
    {
        $skus = array_values(array_unique(array_filter(array_map(static fn($s) => mb_strtolower(trim((string) $s)), $skus))));
        if ($skus === []) {
            return [];
        }
        $out = [];
        try {
            foreach (array_chunk($skus, 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                foreach ($this->all("SELECT id, sku FROM products WHERE LOWER(sku) IN ({$in})", $chunk) as $r) {
                    $out[mb_strtolower((string) $r['sku'])] ??= (int) $r['id'];
                }
            }
        } catch (\PDOException) {
        }
        return $out;
    }

    /**
     * Propozycje przypisania dla produktów bez zapachu: z atrybutu „Zapach” produktu albo z nazwy.
     * @return list<array{product:array,fragrance:array,how:string}>
     */
    public function suggestions(int $limit = 500): array
    {
        try {
            $rows = $this->all('SELECT p.id, p.sku, p.name, p.attributes FROM products p WHERE NOT EXISTS (SELECT 1 FROM ext_zap_links l WHERE l.product_id = p.id) ORDER BY p.name, p.sku');
        } catch (\PDOException) {
            return [];
        }
        $out = [];
        foreach ($rows as $p) {
            $found = $this->resolve('', self::productAttrs($p), (string) $p['name'], -1);
            if ($found !== []) {
                $out[] = ['product' => $p, 'fragrance' => $found[0], 'how' => $found[0]['how']];
                if (count($out) >= $limit) {
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Atrybuty produktu z jedną wartością (wariant „Zapach: Wanilia”); atrybut z kilkoma wartościami to wybór w sklepie,
     * nie zapach tego produktu. Oba zapisy Woo: [{name, options}] i {nazwa: [wartości]}.
     * @return list<array{0:string,1:string}>
     */
    public static function productAttrs(array $p): array
    {
        $raw = json_decode((string) ($p['attributes'] ?? ''), true);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $k => $a) {
            if (is_array($a) && isset($a['name'])) {
                [$name, $vals] = [(string) $a['name'], (array) ($a['options'] ?? ($a['option'] ?? ($a['value'] ?? [])))];
            } elseif (is_string($k)) {
                [$name, $vals] = [$k, (array) $a];
            } else {
                continue;
            }
            $vals = array_values(array_filter(array_map(static fn($v) => is_scalar($v) ? trim((string) $v) : '', $vals), static fn(string $v) => $v !== ''));
            if (trim($name) !== '' && count($vals) === 1) {
                $out[] = [trim($name), $vals[0]];
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------- wtyczka Produkcja (olejki w magazynie)

    /** Czy jest wtyczka Produkcja (jej tabele). */
    public function hasProduction(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM ext_prod_materials WHERE 1 = 0');
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * Materiały Produkcji z kategorii Zapach ze stanem (g/ml).
     * @return array<string,array{id:string,name:string,code:string,unit:string,stock:float,min_stock:?float}>
     */
    public function productionScents(): array
    {
        if (!$this->hasProduction()) {
            return [];
        }
        $out = [];
        try {
            $rows = $this->all("SELECT m.id, m.name, m.code, m.unit, m.min_stock, COALESCE(SUM(CASE WHEN d.deleted_at IS NULL THEN d.remaining END), 0) AS stock
                FROM ext_prod_materials m LEFT JOIN ext_prod_deliveries d ON d.material_id = m.id
                WHERE m.category = 'zapach' AND m.archived = 0 GROUP BY m.id, m.name, m.code, m.unit, m.min_stock ORDER BY m.name");
        } catch (\PDOException) {
            return [];
        }
        foreach ($rows as $r) {
            $out[(string) $r['id']] = ['id' => (string) $r['id'], 'name' => (string) $r['name'], 'code' => (string) $r['code'], 'unit' => (string) $r['unit'],
                'stock' => (float) $r['stock'], 'min_stock' => $r['min_stock'] !== null ? (float) $r['min_stock'] : null];
        }
        return $out;
    }

    /**
     * Łączy zapachy bez materiału z olejkami Produkcji o tej samej nazwie (albo nazwie zawierającej nazwę zapachu,
     * np. „Olejek Sweet Caramel”). Jeden olejek - jeden zapach. @return int ile połączono
     */
    public function linkProductionByName(): int
    {
        $mats = $this->productionScents();
        if ($mats === []) {
            return 0;
        }
        $used = array_flip(array_filter(array_column($this->allFragrances(), 'material_id')));
        $n = 0;
        foreach ($this->allFragrances() as $f) {
            if (!empty($f['material_id'])) {
                continue;
            }
            $hit = null;
            foreach ($mats as $m) {
                if (isset($used[$m['id']])) {
                    continue;
                }
                $mn = self::norm($m['name']);
                foreach (self::names($f) as $name) {
                    $nn = self::norm($name);
                    if ($nn !== '' && ($mn === $nn || (mb_strlen($nn) >= 4 && str_contains(' ' . $mn . ' ', ' ' . $nn . ' ')))) {
                        $hit = $m;
                        break 2;
                    }
                }
            }
            if ($hit !== null) {
                $this->update('ext_zap_fragrances', (string) $f['id'], ['material_id' => $hit['id'], 'updated_at' => self::now()]);
                $used[$hit['id']] = true;
                $n++;
            }
        }
        self::$cache = null;
        return $n;
    }

    /** Zapach powiązany z materiałem Produkcji. */
    public function byMaterial(string $materialId): ?array
    {
        foreach ($this->allFragrances() as $f) {
            if ((string) $f['material_id'] === $materialId && $materialId !== '') {
                return $f;
            }
        }
        return null;
    }

    /** Zapachy z receptury wyrobu Produkcji o danym SKU (olejki powiązane z zapachami). @return list<array<string,mixed>> */
    public function fromProductionRecipe(string $sku): array
    {
        if (trim($sku) === '' || !$this->hasProduction()) {
            return [];
        }
        try {
            $rows = $this->all("SELECT r.material_id FROM ext_prod_products w JOIN ext_prod_recipe r ON r.product_id = w.id
                JOIN ext_prod_materials m ON m.id = r.material_id WHERE LOWER(w.sku) = LOWER(?) AND w.archived = 0 AND m.category = 'zapach' ORDER BY r.qty DESC", [trim($sku)]);
        } catch (\PDOException) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $f = $this->byMaterial((string) $r['material_id']);
            if ($f !== null) {
                $out[$f['id']] = $f + ['how' => 'recipe'];
            }
        }
        return array_values($out);
    }
}
