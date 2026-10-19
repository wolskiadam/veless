<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Repository\AllegroDescTemplateRepository;
use Pase\Repository\SettingsRepository;

/**
 * ERLI: wystawianie ofert z magazynu CRM i wszystko, czego do tego trzeba (Marketplace → Erli).
 *
 *  - Szablony opisu (erli_desc_templates, ten sam format sekcji co Allegro: TEXT/IMAGE, tagi [nazwa], [opis]...),
 *    dobierane po kategorii produktu w magazynie.
 *  - Kategorie: drzewo kategorii ERLI pobrane do erli_categories; kategoria magazynu → kategoria ERLI (erli_category_map),
 *    przy mapowaniu można wskazać inny cennik wysyłki niż domyślny konta.
 *  - Parametry: lista parametrów kategorii ERLI (z wartościami słownikowymi) w erli_category_attrs; reguły w erli_attribute_rules
 *    mówią, skąd wziąć wartość: stała wartość albo pole/atrybut produktu w magazynie.
 *  - Cenniki wysyłek: tworzy się je w panelu ERLI; CRM pobiera listę (ustawienie ERLI_{konto}_PRICE_LISTS) i wybiera domyślny.
 *  - Wystawienie: POST /products/{SKU} (a gdy już jest - PATCH), wynik w erli_listings; produkt od razu trafia do
 *    erli_products, więc stany i ceny dalej pilnuje worker (Services\Erli::pushStock).
 *
 * externalId oferty w ERLI = SKU produktu w CRM - po tym ERLI i CRM łączą zamówienia i stany.
 */
final class ErliOffers
{
    /** Parametry o tych typach umiemy wypełnić. */
    public const ATTR_TYPES = ['dictionary', 'string', 'number', 'range'];
    /** Pola produktu, z których reguła może wziąć wartość parametru (poza atrybutami z WooCommerce). */
    public const PRODUCT_FIELDS = ['ean' => 'EAN', 'sku' => 'SKU', 'weight' => 'Waga (kg)', 'height' => 'Wysokość',
        'width' => 'Szerokość', 'length' => 'Długość', 'name' => 'Nazwa'];

    private SettingsRepository $settings;

    public function __construct(private readonly PDO $pdo, private readonly Erli $erli)
    {
        $this->settings = new SettingsRepository($pdo);
    }

    public static function migrate(PDO $pdo): void
    {
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $text = $sqlite ? 'TEXT' : 'MEDIUMTEXT';
        $pdo->exec('CREATE TABLE IF NOT EXISTS erli_desc_templates (
            id ' . $pk . ',
            name VARCHAR(160) NOT NULL,
            categories TEXT NULL,
            sections ' . $text . ' NOT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )' . $tail);
        $pdo->exec('CREATE TABLE IF NOT EXISTS erli_categories (
            id INT NOT NULL PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            leaf INT NOT NULL DEFAULT 0,
            path VARCHAR(1000) NULL,
            breadcrumb TEXT NULL
        )' . $tail);
        $pdo->exec('CREATE TABLE IF NOT EXISTS erli_category_map (
            crm_category VARCHAR(191) NOT NULL PRIMARY KEY,
            erli_category_id INT NULL,
            price_list VARCHAR(191) NULL
        )' . $tail);
        $pdo->exec('CREATE TABLE IF NOT EXISTS erli_category_attrs (
            erli_category_id INT NOT NULL PRIMARY KEY,
            attributes ' . $text . ' NULL,
            fetched_at DATETIME NULL
        )' . $tail);
        $pdo->exec('CREATE TABLE IF NOT EXISTS erli_attribute_rules (
            id ' . $pk . ',
            erli_category_id INT NOT NULL,
            attribute_id INT NOT NULL,
            mode VARCHAR(12) NOT NULL,
            value TEXT NULL,
            UNIQUE (erli_category_id, attribute_id)
        )' . $tail);
        $pdo->exec('CREATE TABLE IF NOT EXISTS erli_listings (
            id ' . $pk . ',
            integration_id INT NOT NULL,
            product_id INT NOT NULL,
            external_id VARCHAR(191) NOT NULL,
            status VARCHAR(12) NOT NULL,
            error TEXT NULL,
            warnings TEXT NULL,
            listed_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE (integration_id, product_id)
        )' . $tail);
    }

    public function templates(): AllegroDescTemplateRepository
    {
        return new AllegroDescTemplateRepository($this->pdo, 'erli_desc_templates');
    }

    private function nowSql(): string
    {
        return date('Y-m-d H:i:s');
    }

    // ---------------------------------------------------------------- cenniki wysyłek

    /** @return array<int,array<string,mixed>> zapamiętane cenniki konta (id, name, prices z detali, gdy były dostępne) */
    public function priceLists(int $accountId): array
    {
        $list = json_decode((string) $this->settings->get('ERLI_' . $accountId . '_PRICE_LISTS', '[]'), true);
        return is_array($list) ? $list : [];
    }

    /** Pobiera cenniki z ERLI (z metodami i cenami, jeśli ERLI je zwróci). @return int liczba cenników */
    public function refreshPriceLists(array $account): int
    {
        $client = $this->erli->client($account);
        $lists = $client->priceLists();
        try {
            $details = [];
            foreach ($client->priceListDetails() as $d) {
                $details[(int) ($d['id'] ?? 0)] = $d;
            }
            foreach ($lists as &$l) {
                $d = $details[(int) ($l['id'] ?? 0)] ?? null;
                if ($d !== null) {
                    $l['prices'] = $d['prices'] ?? [];
                }
            }
            unset($l);
        } catch (\Throwable) {
            // Szczegóły są tylko do podglądu - sama lista nazw wystarczy do wystawiania.
        }
        $id = (int) $account['id'];
        $this->settings->setMany([
            'ERLI_' . $id . '_PRICE_LISTS'    => json_encode($lists, JSON_UNESCAPED_UNICODE),
            'ERLI_' . $id . '_PRICE_LISTS_AT' => $this->nowSql(),
        ]);
        return count($lists);
    }

    public function defaultPriceList(int $accountId): string
    {
        return (string) $this->settings->get('ERLI_' . $accountId . '_PRICE_LIST', '');
    }

    public function setDefaultPriceList(int $accountId, string $name): void
    {
        $this->settings->setMany(['ERLI_' . $accountId . '_PRICE_LIST' => $name]);
    }

    // ---------------------------------------------------------------- kategorie

    /** Pobiera całe drzewo kategorii ERLI do erli_categories. @return int liczba kategorii */
    public function refreshCategories(array $account): int
    {
        $client = $this->erli->client($account);
        $find = $this->pdo->prepare('SELECT 1 FROM erli_categories WHERE id = ?');
        $ins = $this->pdo->prepare('INSERT INTO erli_categories (id, name, leaf, path, breadcrumb) VALUES (?, ?, ?, ?, ?)');
        $upd = $this->pdo->prepare('UPDATE erli_categories SET name = ?, leaf = ?, path = ?, breadcrumb = ? WHERE id = ?');
        $after = 0;
        $n = 0;
        for ($page = 0; $page < 500; $page++) {
            $list = $client->categories($after, 500);
            foreach ($list as $c) {
                $id = (int) $c['id'];
                $crumb = self::breadcrumb($c);
                $path = mb_substr(implode(' > ', array_column($crumb, 'name')), 0, 1000);
                $vals = [mb_substr((string) $c['name'], 0, 255), !empty($c['leaf']) ? 1 : 0, $path, json_encode($crumb, JSON_UNESCAPED_UNICODE)];
                $find->execute([$id]);
                if ($find->fetchColumn()) {
                    $upd->execute([...$vals, $id]);
                } else {
                    $ins->execute([$id, ...$vals]);
                }
                $after = max($after, $id);
                $n++;
            }
            if (count($list) < 500) {
                break;
            }
        }
        $this->settings->setMany(['ERLI_CATEGORIES_AT' => $this->nowSql()]);
        return $n;
    }

    /** Ścieżka kategorii od korzenia, zawsze zakończona samą kategorią. @return list<array{id:int,name:string}> */
    public static function breadcrumb(array $category): array
    {
        $out = [];
        foreach ((array) ($category['breadcrumb'] ?? []) as $b) {
            if (is_array($b) && isset($b['id'])) {
                $out[] = ['id' => (int) $b['id'], 'name' => (string) ($b['name'] ?? '')];
            }
        }
        if ($out === [] || end($out)['id'] !== (int) $category['id']) {
            $out[] = ['id' => (int) $category['id'], 'name' => (string) ($category['name'] ?? '')];
        }
        return $out;
    }

    public function categoryCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM erli_categories')->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public function category(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM erli_categories WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** Kategorie końcowe ERLI pasujące do tekstu (nazwa albo ścieżka). @return array<int,array<string,mixed>> */
    public function searchCategories(string $q, int $limit = 30): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $st = $this->pdo->prepare('SELECT id, name, path FROM erli_categories WHERE leaf = 1 AND (name LIKE ? OR path LIKE ?)
            ORDER BY CASE WHEN name LIKE ? THEN 0 ELSE 1 END, path LIMIT ' . max(1, min(100, $limit)));
        $st->execute(['%' . $q . '%', '%' . $q . '%', $q . '%']);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,array{crm_category:string,erli_category_id:?int,price_list:?string}> */
    public function categoryMap(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT * FROM erli_category_map')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['erli_category_id'] = $r['erli_category_id'] !== null ? (int) $r['erli_category_id'] : null;
            $out[(string) $r['crm_category']] = $r;
        }
        return $out;
    }

    public function setMapping(string $crmCategory, ?int $erliCategoryId, ?string $priceList): void
    {
        $this->pdo->prepare('DELETE FROM erli_category_map WHERE crm_category = ?')->execute([$crmCategory]);
        if ($erliCategoryId !== null || ($priceList ?? '') !== '') {
            $this->pdo->prepare('INSERT INTO erli_category_map (crm_category, erli_category_id, price_list) VALUES (?, ?, ?)')
                ->execute([$crmCategory, $erliCategoryId, ($priceList ?? '') !== '' ? $priceList : null]);
        }
    }

    // ---------------------------------------------------------------- parametry

    /**
     * Parametry kategorii ERLI z wartościami słownikowymi (values/valueIds), z pamięci albo z API.
     * @return array<int,array<string,mixed>> id => parametr
     */
    public function attributes(array $account, int $categoryId, bool $refresh = false): array
    {
        $st = $this->pdo->prepare('SELECT attributes FROM erli_category_attrs WHERE erli_category_id = ?');
        $st->execute([$categoryId]);
        $cached = $st->fetchColumn();
        if ($cached !== false && !$refresh) {
            $list = json_decode((string) $cached, true);
            if (is_array($list)) {
                return $list;
            }
        }
        $client = $this->erli->client($account);
        $out = [];
        foreach ($client->attributes($categoryId) as $a) {
            $out[(int) $a['id']] = [
                'id' => (int) $a['id'],
                'name' => (string) ($a['name'] ?? ''),
                'type' => (string) ($a['type'] ?? 'string'),
                'required' => !empty($a['required']),
                'maxValues' => (int) ($a['maxValues'] ?? 1),
                'unit' => (string) ($a['unit'] ?? ''),
                'values' => [],
            ];
        }
        foreach ($client->attributeValues($categoryId) as $v) {
            $aid = (int) $v['id'];
            if (!isset($out[$aid])) {
                continue;
            }
            $names = array_values((array) ($v['values'] ?? []));
            $ids = array_values((array) ($v['valueIds'] ?? []));
            foreach ($names as $i => $name) {
                $out[$aid]['values'][(string) ($ids[$i] ?? $name)] = (string) $name;
            }
        }
        $this->pdo->prepare('DELETE FROM erli_category_attrs WHERE erli_category_id = ?')->execute([$categoryId]);
        $this->pdo->prepare('INSERT INTO erli_category_attrs (erli_category_id, attributes, fetched_at) VALUES (?, ?, ?)')
            ->execute([$categoryId, json_encode($out, JSON_UNESCAPED_UNICODE), $this->nowSql()]);
        return $out;
    }

    /** @return array<int,array{mode:string,value:string}> attribute_id => reguła */
    public function rules(int $categoryId): array
    {
        $st = $this->pdo->prepare('SELECT attribute_id, mode, value FROM erli_attribute_rules WHERE erli_category_id = ?');
        $st->execute([$categoryId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['attribute_id']] = ['mode' => (string) $r['mode'], 'value' => (string) $r['value']];
        }
        return $out;
    }

    /**
     * Zapis reguł kategorii. mode: value (stała wartość albo id wartości słownikowej) / field (pole produktu: ean, weight...
     * albo „attr:Nazwa” - atrybut produktu z WooCommerce) / '' (bez reguły).
     * @param array<int,array{mode:string,value:string}> $rules
     */
    public function saveRules(int $categoryId, array $rules): void
    {
        $this->pdo->prepare('DELETE FROM erli_attribute_rules WHERE erli_category_id = ?')->execute([$categoryId]);
        $ins = $this->pdo->prepare('INSERT INTO erli_attribute_rules (erli_category_id, attribute_id, mode, value) VALUES (?, ?, ?, ?)');
        foreach ($rules as $attrId => $r) {
            $mode = (string) ($r['mode'] ?? '');
            $value = trim((string) ($r['value'] ?? ''));
            if (in_array($mode, ['value', 'field'], true) && $value !== '') {
                $ins->execute([$categoryId, (int) $attrId, $mode, $value]);
            }
        }
    }

    /** Nazwy atrybutów produktów z WooCommerce (do wyboru w regułach). @return list<string> */
    public function productAttributeNames(): array
    {
        $names = [];
        foreach ($this->pdo->query('SELECT attributes FROM products WHERE attributes IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $json) {
            foreach (self::productAttributes(['attributes' => $json]) as $name => $_) {
                $names[$name] = true;
            }
        }
        ksort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return array_keys($names);
    }

    /** Atrybuty produktu (WooCommerce: [{name, options[]}] albo {nazwa: wartość}). @return array<string,list<string>> */
    public static function productAttributes(array $product): array
    {
        $raw = json_decode((string) ($product['attributes'] ?? ''), true);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $k => $a) {
            if (is_array($a) && isset($a['name'])) {
                $vals = $a['options'] ?? ($a['option'] ?? ($a['value'] ?? []));
                $out[trim((string) $a['name'])] = array_values(array_filter(array_map('strval', (array) $vals), static fn($v) => trim($v) !== ''));
            } elseif (is_string($k) && (is_scalar($a) || is_array($a))) {
                $out[trim($k)] = array_values(array_map('strval', (array) $a));
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------- oferta

    /** Kategorie produktu w magazynie. @return list<string> */
    public static function productCategories(array $product): array
    {
        $cats = json_decode((string) ($product['categories'] ?? '[]'), true);
        return array_values(array_filter(array_map(static fn($c) => trim((string) $c), is_array($cats) ? $cats : []), static fn($c) => $c !== ''));
    }

    /** Mapowanie pierwszej kategorii produktu, która ma przypisaną kategorię ERLI. */
    public function mappingFor(array $product): ?array
    {
        $map = $this->categoryMap();
        foreach (self::productCategories($product) as $c) {
            if (isset($map[$c]) && $map[$c]['erli_category_id'] !== null) {
                return $map[$c];
            }
        }
        return null;
    }

    private static function absoluteUrl(string $url, string $base): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^https?://#i', $url)) {
            return $url;
        }
        return $base !== '' ? rtrim($base, '/') . '/' . ltrim($url, '/') : '';
    }

    /**
     * Treść oferty dla produktu (bez wysyłania). errors = nie da się wystawić; warnings = ERLI może odrzucić albo oferta będzie uboższa.
     * @return array{external_id:string,payload:array<string,mixed>,errors:list<string>,warnings:list<string>}
     */
    public function buildOffer(array $account, array $product): array
    {
        $errors = [];
        $warnings = [];
        $ext = trim((string) ($product['sku'] ?? ''));
        if ($ext === '') {
            $errors[] = 'Produkt nie ma SKU - po nim ERLI łączy ofertę z magazynem.';
        }
        $price = Erli::erliPrice($account, $product['pase_price'] ?? null);
        if ($price === null) {
            $errors[] = 'Produkt nie ma ceny w magazynie.';
        }
        $base = (string) ($this->settings->get('APP_BASE_URL', '') ?? '');
        $images = [];
        foreach ((array) (json_decode((string) ($product['images'] ?? '[]'), true) ?: []) as $img) {
            $url = self::absoluteUrl(is_array($img) ? (string) ($img['src'] ?? $img['url'] ?? '') : (string) $img, $base);
            if ($url !== '' && !in_array($url, array_column($images, 'url'), true)) {
                $images[] = ['url' => $url];
            }
        }
        if ($images === []) {
            $errors[] = 'Produkt nie ma zdjęć (ERLI wymaga co najmniej jednego).';
        }

        // Opis: szablon pasujący do kategorii produktu, a bez szablonu - opis produktu z magazynu.
        $sections = [];
        $tpl = $this->templates()->bestForCategories(self::productCategories($product));
        foreach ((array) ($tpl['sections'] ?? []) as $sec) {
            $items = [];
            foreach ((array) ($sec['items'] ?? []) as $it) {
                if (($it['type'] ?? '') === 'IMAGE') {
                    $url = self::absoluteUrl(AllegroOfferTags::resolve((string) ($it['url'] ?? ''), $product), $base);
                    if ($url !== '') {
                        $items[] = ['type' => 'IMAGE', 'url' => $url];
                    }
                } else {
                    $html = AllegroOfferPayload::sanitizeHtml(AllegroOfferTags::resolve((string) ($it['content'] ?? ''), $product));
                    if ($html !== '') {
                        $items[] = ['type' => 'TEXT', 'content' => $html];
                    }
                }
            }
            if ($items !== []) {
                $sections[] = ['items' => $items];
            }
        }
        if ($sections === []) {
            $html = AllegroOfferPayload::sanitizeHtml((string) ($product['description'] ?? ''));
            if ($html !== '') {
                $sections[] = ['items' => [['type' => 'TEXT', 'content' => $html]]];
            } else {
                $warnings[] = 'Brak opisu (ani szablonu dla kategorii, ani opisu produktu).';
            }
        }

        $days = max(0, (int) ($account['config']['dispatch_days'] ?? 1));
        $payload = [
            'name' => mb_substr(trim((string) ($product['name'] ?? '')), 0, 255),
            'sku' => $ext,
            'price' => $price ?? 0,
            'stock' => max(0, (int) ($product['pase_stock'] ?? 0)),
            'status' => 'active',
            'images' => $images,
            'dispatchTime' => ['unit' => 'day', 'period' => $days],
            'taxRate' => (string) ($account['config']['tax_rate'] ?? 'TAX_23'),
        ];
        if ($sections !== []) {
            $payload['description'] = ['sections' => $sections];
        }
        $ean = preg_replace('/\D/', '', (string) ($product['ean'] ?? ''));
        if ($ean !== '') {
            $payload['ean'] = $ean;
        }
        if (($product['weight'] ?? null) !== null && (float) $product['weight'] > 0) {
            $payload['weight'] = (int) round((float) $product['weight'] * 1000);   // magazyn w kg, ERLI w gramach
        }

        $mapping = $this->mappingFor($product);
        $priceList = trim((string) ($mapping['price_list'] ?? '')) ?: $this->defaultPriceList((int) $account['id']);
        if ($priceList !== '') {
            $payload['deliveryPriceList'] = $priceList;
        }
        if ($mapping === null) {
            $warnings[] = 'Kategoria produktu nie ma przypisanej kategorii ERLI (Kategorie i parametry) - ERLI dobierze ją samo albo odrzuci ofertę.';
        } else {
            $cat = $this->category((int) $mapping['erli_category_id']);
            $crumb = json_decode((string) ($cat['breadcrumb'] ?? ''), true);
            if (!is_array($crumb) || $crumb === []) {
                $crumb = [['id' => (int) $mapping['erli_category_id'], 'name' => (string) ($cat['name'] ?? '')]];
            }
            $payload['externalCategories'] = [['source' => 'marketplace', 'breadcrumb' => $crumb]];
            [$attrs, $attrWarnings] = $this->offerAttributes($account, (int) $mapping['erli_category_id'], $product);
            if ($attrs !== []) {
                $payload['externalAttributes'] = $attrs;
            }
            array_push($warnings, ...$attrWarnings);
        }
        return ['external_id' => $ext, 'payload' => $payload, 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Parametry oferty z reguł kategorii.
     * @return array{0:list<array<string,mixed>>,1:list<string>}
     */
    public function offerAttributes(array $account, int $categoryId, array $product): array
    {
        $rules = $this->rules($categoryId);
        try {
            $defs = $this->attributes($account, $categoryId);
        } catch (\Throwable) {
            $defs = [];
        }
        $productAttrs = self::productAttributes($product);
        $out = [];
        $warnings = [];
        foreach ($defs as $id => $def) {
            $rule = $rules[(int) $id] ?? null;
            $raw = [];
            if ($rule !== null && $rule['mode'] === 'value') {
                $raw = [$rule['value']];
            } elseif ($rule !== null && $rule['mode'] === 'field') {
                $f = $rule['value'];
                if (str_starts_with($f, 'attr:')) {
                    $raw = $productAttrs[substr($f, 5)] ?? [];
                } elseif (isset(self::PRODUCT_FIELDS[$f]) && ($product[$f] ?? null) !== null && (string) $product[$f] !== '') {
                    $raw = [(string) $product[$f]];
                }
            }
            $values = self::attributeValues($def, $raw);
            if ($values === []) {
                if (!empty($def['required'])) {
                    $warnings[] = 'Brak wymaganego parametru „' . $def['name'] . '”' . ($raw !== [] ? ' (wartość „' . implode(', ', $raw) . '” nie pasuje do listy ERLI)' : '') . '.';
                }
                continue;
            }
            $attr = ['id' => (int) $id, 'name' => (string) $def['name'], 'source' => 'marketplace', 'type' => (string) $def['type'], 'values' => $values];
            if (($def['unit'] ?? '') !== '') {
                $attr['unit'] = (string) $def['unit'];
            }
            $out[] = $attr;
        }
        return [$out, $warnings];
    }

    /**
     * Wartości parametru w formacie ERLI: słownik - id wartości (dopasowanie po id albo nazwie), liczba - liczby, tekst - teksty.
     * @param list<string> $raw
     * @return list<int|float|string>
     */
    public static function attributeValues(array $def, array $raw): array
    {
        $out = [];
        $type = (string) ($def['type'] ?? 'string');
        foreach ($raw as $v) {
            $v = trim((string) $v);
            if ($v === '') {
                continue;
            }
            if ($type === 'dictionary') {
                $dict = (array) ($def['values'] ?? []);
                $id = array_key_exists($v, $dict) ? $v : null;
                if ($id === null) {
                    foreach ($dict as $vid => $name) {
                        if (mb_strtolower(trim((string) $name)) === mb_strtolower($v)) {
                            $id = (string) $vid;
                            break;
                        }
                    }
                }
                if ($id !== null) {
                    $out[] = ctype_digit($id) ? (int) $id : $id;
                }
            } elseif ($type === 'number' || $type === 'range') {
                // Liczby z tekstu („180 ml” → 180, „10 – 20 cm” → 10, 20).
                preg_match_all('/\d+(?:[.,]\d+)?/', $v, $m);
                foreach ($m[0] as $num) {
                    $out[] = str_replace(',', '.', $num) + 0;
                }
            } else {
                $out[] = $v;
            }
        }
        $max = max(1, (int) ($def['maxValues'] ?? 1));
        return array_slice(array_values(array_unique($out, SORT_REGULAR)), 0, $type === 'range' ? 2 : $max);
    }

    /** @return array<int,array<string,mixed>> product_id => wpis z erli_listings dla konta */
    public function listings(int $accountId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM erli_listings WHERE integration_id = ?');
        $st->execute([$accountId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['product_id']] = $r;
        }
        return $out;
    }

    /**
     * Wystawia (albo aktualizuje, gdy oferta o tym SKU już jest w ERLI) wybrane produkty.
     * @param list<int> $productIds
     * @return array{listed:int,updated:int,failed:int,messages:list<string>}
     */
    public function publish(array $account, array $productIds): array
    {
        $client = $this->erli->client($account);
        $accId = (int) $account['id'];
        $res = ['listed' => 0, 'updated' => 0, 'failed' => 0, 'messages' => []];
        $get = $this->pdo->prepare('SELECT * FROM products WHERE id = ?');
        foreach (array_unique(array_map('intval', $productIds)) as $pid) {
            $get->execute([$pid]);
            $product = $get->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                continue;
            }
            $offer = $this->buildOffer($account, $product);
            $name = (string) $product['name'];
            if ($offer['errors'] !== []) {
                $this->saveListing($accId, $pid, $offer['external_id'] ?: ('#' . $pid), 'error', implode(' ', $offer['errors']), $offer['warnings']);
                $res['failed']++;
                $res['messages'][] = $name . ': ' . implode(' ', $offer['errors']);
                continue;
            }
            try {
                $created = $client->createProduct($offer['external_id'], $offer['payload']);
                if (!$created) {
                    $client->updateProduct($offer['external_id'], $offer['payload']);
                }
                $this->saveListing($accId, $pid, $offer['external_id'], 'listed', null, $offer['warnings']);
                $this->linkCatalog($accId, $offer['external_id'], $name, (int) $offer['payload']['stock'], (int) $offer['payload']['price']);
                $res[$created ? 'listed' : 'updated']++;
            } catch (\Throwable $e) {
                $this->saveListing($accId, $pid, $offer['external_id'], 'error', $e->getMessage(), $offer['warnings']);
                $res['failed']++;
                $res['messages'][] = $name . ': ' . $e->getMessage();
            }
        }
        return $res;
    }

    private function saveListing(int $accId, int $productId, string $ext, string $status, ?string $error, array $warnings): void
    {
        $now = $this->nowSql();
        $st = $this->pdo->prepare('SELECT id, listed_at FROM erli_listings WHERE integration_id = ? AND product_id = ?');
        $st->execute([$accId, $productId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $warn = $warnings !== [] ? implode("\n", $warnings) : null;
        if ($row) {
            $this->pdo->prepare('UPDATE erli_listings SET external_id = ?, status = ?, error = ?, warnings = ?, updated_at = ?,
                    listed_at = COALESCE(listed_at, ?) WHERE id = ?')
                ->execute([$ext, $status, $error, $warn, $now, $status === 'listed' ? $now : null, (int) $row['id']]);
        } else {
            $this->pdo->prepare('INSERT INTO erli_listings (integration_id, product_id, external_id, status, error, warnings, listed_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$accId, $productId, $ext, $status, $error, $warn, $status === 'listed' ? $now : null, $now]);
        }
    }

    /** Wystawiony produkt od razu w katalogu ERLI w CRM - stany i ceny pilnuje dalej worker. */
    private function linkCatalog(int $accId, string $ext, string $name, int $stock, int $price): void
    {
        $now = $this->nowSql();
        $st = $this->pdo->prepare('SELECT id FROM erli_products WHERE integration_id = ? AND external_id = ?');
        $st->execute([$accId, $ext]);
        $id = $st->fetchColumn();
        if ($id !== false) {
            $this->pdo->prepare('UPDATE erli_products SET match_sku = ?, product_name = ?, erli_stock = ?, pushed_qty = ?, erli_price = ?, pushed_price = ?,
                    pushed_at = ?, error = NULL, seen_at = ? WHERE id = ?')
                ->execute([$ext, mb_substr($name, 0, 255), $stock, $stock, $price, $price, $now, $now, (int) $id]);
        } else {
            $this->pdo->prepare('INSERT INTO erli_products (integration_id, external_id, match_sku, product_name, erli_stock, pushed_qty, erli_price, pushed_price, pushed_at, seen_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$accId, $ext, $ext, mb_substr($name, 0, 255), $stock, $stock, $price, $price, $now, $now]);
        }
    }
}
