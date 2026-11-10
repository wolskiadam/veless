<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Repository\SettingsRepository;

/**
 * InPost vonHalsky: wystawianie ofert z magazynu CRM i ręczne łączenie ofert z produktami (Marketplace → InPost vonHalsky).
 *
 *  - Kategorie: drzewo kategorii vonHalsky (id to UUID) w vonhalsky_categories; kategoria magazynu → kategoria vonHalsky
 *    (vonhalsky_category_map). Bez przypisania bierzemy kategorię z podpowiedzi InPost po EAN (/offers/hint).
 *  - Parametry: definicje parametrów kategorii (z listą wartości słownikowych) w vonhalsky_category_attrs; reguły
 *    w vonhalsky_attribute_rules mówią, skąd wziąć wartość (stała wartość albo pole/atrybut produktu). Brakujące
 *    parametry uzupełnia podpowiedź po EAN.
 *  - GPSR, marka, VAT, czas wysyłki, zasady zwrotów i reklamacji: z ustawień konta (wtyczka integrations/vonhalsky).
 *  - Wystawienie: POST /offers (nowa) albo PATCH /offers/{id}, gdy produkt ma już ofertę (wcześniej wystawioną z CRM,
 *    połączoną ręcznie albo z tym samym SKU). InPost sprawdza ofertę asynchronicznie - status (opublikowana / odrzucona
 *    z powodami) przychodzi z listą ofert przy kolejnej synchronizacji. Oferta od razu trafia do vonhalsky_offers,
 *    więc stany i ceny pilnuje dalej worker (Services\Vonhalsky::pushStock).
 *  - Ręczne łączenie: oferta bez SKU albo z innym SKU niż w CRM dostaje linked_product_id - po nim idą stany, ceny
 *    i dopasowanie pozycji zamówień.
 *
 * externalId oferty = SKU produktu w CRM.
 */
final class VonhalskyOffers
{
    /** Pola produktu, z których reguła może wziąć wartość parametru (poza atrybutami z WooCommerce). */
    public const PRODUCT_FIELDS = ['ean' => 'EAN', 'sku' => 'SKU', 'weight' => 'Waga (kg)', 'height' => 'Wysokość',
        'width' => 'Szerokość', 'length' => 'Długość', 'name' => 'Nazwa'];
    /** Atrybuty produktu, w których szukamy marki (pierwszy pasujący). */
    public const BRAND_ATTRIBUTES = ['marka', 'producent', 'brand', 'manufacturer'];
    public const TYPE_LABELS = ['TEXT_VALUE' => 'tekst', 'LONG_TEXT_VALUE' => 'długi tekst', 'DICTIONARY' => 'lista wartości',
        'NUMERIC' => 'liczba', 'NUMERIC_FLOAT' => 'liczba', 'DATE' => 'data', 'URL' => 'adres www'];
    public const OFFER_STATUS_LABELS = ['PENDING' => 'sprawdzana przez InPost', 'PUBLISHED' => 'opublikowana', 'REJECTED' => 'odrzucona',
        'CLOSED' => 'zamknięta', 'SOLDOUT' => 'wyprzedana'];
    private const HINT_TTL = 7 * 86400;
    private const CATEGORY_DEPTH = 10;

    private SettingsRepository $settings;

    public function __construct(private readonly PDO $pdo, private readonly Vonhalsky $vh)
    {
        $this->settings = new SettingsRepository($pdo);
    }

    public static function migrate(PDO $pdo): void
    {
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $text = $sqlite ? 'TEXT' : 'MEDIUMTEXT';
        $pdo->exec('CREATE TABLE IF NOT EXISTS vonhalsky_categories (
            id VARCHAR(64) NOT NULL PRIMARY KEY,
            parent_id VARCHAR(64) NULL,
            name VARCHAR(255) NOT NULL,
            leaf INT NOT NULL DEFAULT 0,
            gpsr_free INT NOT NULL DEFAULT 0,
            path VARCHAR(1000) NULL
        )' . $tail);
        $pdo->exec('CREATE TABLE IF NOT EXISTS vonhalsky_category_map (
            crm_category VARCHAR(191) NOT NULL PRIMARY KEY,
            vh_category_id VARCHAR(64) NULL
        )' . $tail);
        $pdo->exec('CREATE TABLE IF NOT EXISTS vonhalsky_category_attrs (
            vh_category_id VARCHAR(64) NOT NULL PRIMARY KEY,
            attributes ' . $text . ' NULL,
            fetched_at DATETIME NULL
        )' . $tail);
        $pdo->exec('CREATE TABLE IF NOT EXISTS vonhalsky_attribute_rules (
            id ' . $pk . ',
            vh_category_id VARCHAR(64) NOT NULL,
            attribute_id VARCHAR(64) NOT NULL,
            mode VARCHAR(12) NOT NULL,
            value TEXT NULL,
            UNIQUE (vh_category_id, attribute_id)
        )' . $tail);
        $pdo->exec('CREATE TABLE IF NOT EXISTS vonhalsky_listings (
            id ' . $pk . ',
            integration_id INT NOT NULL,
            product_id INT NOT NULL,
            offer_id VARCHAR(64) NULL,
            external_id VARCHAR(191) NOT NULL,
            status VARCHAR(12) NOT NULL,
            error TEXT NULL,
            warnings TEXT NULL,
            listed_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE (integration_id, product_id)
        )' . $tail);
        $pdo->exec('CREATE TABLE IF NOT EXISTS vonhalsky_hints (
            ean VARCHAR(32) NOT NULL PRIMARY KEY,
            data ' . $text . ' NULL,
            fetched_at DATETIME NULL
        )' . $tail);
    }

    private function nowSql(): string
    {
        return date('Y-m-d H:i:s');
    }

    // ---------------------------------------------------------------- kategorie

    /** Pobiera drzewo kategorii vonHalsky do vonhalsky_categories. @return int liczba kategorii */
    public function refreshCategories(array $account): int
    {
        $client = $this->vh->client($account);
        $rows = [];
        $walk = function (array $nodes, ?string $parent, array $crumb) use (&$walk, &$rows, $client): void {
            foreach ($nodes as $c) {
                $id = trim((string) ($c['id'] ?? ''));
                if ($id === '' || isset($rows[$id])) {
                    continue;
                }
                $name = trim((string) ($c['name'] ?? ''));
                $path = [...$crumb, $name];
                $leaf = !empty($c['leaf']);
                $rows[$id] = [$id, $parent, mb_substr($name, 0, 255), $leaf ? 1 : 0, !empty($c['doesNotRequireGpsrInfo']) ? 1 : 0, mb_substr(implode(' > ', $path), 0, 1000)];
                $children = is_array($c['children'] ?? null) ? $c['children'] : [];
                if (!$leaf && $children === [] && count($path) % self::CATEGORY_DEPTH === 0) {
                    // Dalsze poziomy poza zasięgiem depth - dociągamy poddrzewo.
                    $children = $client->categories(self::CATEGORY_DEPTH, $id);
                    if (count($children) === 1 && (string) ($children[0]['id'] ?? '') === $id) {
                        $children = is_array($children[0]['children'] ?? null) ? $children[0]['children'] : [];
                    }
                }
                $walk($children, $id, $path);
            }
        };
        $walk($client->categories(self::CATEGORY_DEPTH), null, []);
        if ($rows === []) {
            throw new \RuntimeException('InPost nie zwrócił kategorii.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM vonhalsky_categories');
            $ins = $this->pdo->prepare('INSERT INTO vonhalsky_categories (id, parent_id, name, leaf, gpsr_free, path) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($rows as $r) {
                $ins->execute($r);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->settings->setMany(['VONHALSKY_CATEGORIES_AT' => $this->nowSql()]);
        return count($rows);
    }

    public function categoryCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM vonhalsky_categories')->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public function category(string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM vonhalsky_categories WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function categoryPath(?string $id): string
    {
        if ($id === null || $id === '') {
            return '';
        }
        $c = $this->category($id);
        return $c ? (string) ($c['path'] ?: $c['name']) : $id;
    }

    /** Kategorie końcowe pasujące do tekstu (nazwa albo ścieżka). @return array<int,array<string,mixed>> */
    public function searchCategories(string $q, int $limit = 30): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $st = $this->pdo->prepare('SELECT id, name, path FROM vonhalsky_categories WHERE leaf = 1 AND (name LIKE ? OR path LIKE ?)
            ORDER BY CASE WHEN name LIKE ? THEN 0 ELSE 1 END, path LIMIT ' . max(1, min(100, $limit)));
        $st->execute(['%' . $q . '%', '%' . $q . '%', $q . '%']);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,string> kategoria magazynu => id kategorii vonHalsky */
    public function categoryMap(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT crm_category, vh_category_id FROM vonhalsky_category_map WHERE vh_category_id IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['crm_category']] = (string) $r['vh_category_id'];
        }
        return $out;
    }

    public function setMapping(string $crmCategory, ?string $vhCategoryId): void
    {
        $this->pdo->prepare('DELETE FROM vonhalsky_category_map WHERE crm_category = ?')->execute([$crmCategory]);
        if ($vhCategoryId !== null && $vhCategoryId !== '') {
            $this->pdo->prepare('INSERT INTO vonhalsky_category_map (crm_category, vh_category_id) VALUES (?, ?)')->execute([$crmCategory, $vhCategoryId]);
        }
    }

    /** Kategoria vonHalsky z pierwszej kategorii produktu, która ma przypisanie. */
    public function mappedCategory(array $product): ?string
    {
        $map = $this->categoryMap();
        foreach (ErliOffers::productCategories($product) as $c) {
            if (isset($map[$c])) {
                return $map[$c];
            }
        }
        return null;
    }

    // ---------------------------------------------------------------- parametry

    /**
     * Parametry kategorii z pamięci albo z API.
     * @return array<string,array{id:string,name:string,type:string,lang:string,required:bool,multi:bool,unit:string,values:array<string,string>}>
     */
    public function attributes(array $account, string $categoryId, bool $refresh = false): array
    {
        $st = $this->pdo->prepare('SELECT attributes FROM vonhalsky_category_attrs WHERE vh_category_id = ?');
        $st->execute([$categoryId]);
        $cached = $st->fetchColumn();
        if ($cached !== false && !$refresh) {
            $list = json_decode((string) $cached, true);
            if (is_array($list)) {
                return $list;
            }
        }
        $out = [];
        foreach ($this->vh->client($account)->categoryAttributes($categoryId) as $a) {
            $id = trim((string) ($a['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $expected = strtoupper((string) ($a['expectedValue'] ?? 'NULL_OR_ONE'));
            $values = [];
            foreach ((array) ($a['dictionary']['options'] ?? []) as $opt) {
                if (is_array($opt) && ($opt['active'] ?? true) !== false && isset($opt['id'])) {
                    $values[(string) $opt['id']] = (string) ($opt['value'] ?? '');
                }
            }
            $out[$id] = [
                'id'       => $id,
                'name'     => (string) ($a['name'] ?? ''),
                'type'     => strtoupper((string) ($a['type'] ?? 'TEXT_VALUE')),
                'lang'     => (string) ($a['lang'] ?? ''),
                'required' => in_array($expected, ['ONE', 'AT_LEAST_ONE'], true),
                'multi'    => in_array($expected, ['AT_LEAST_ONE', 'ANY'], true),
                'unit'     => (string) ($a['unitOfMeasureDetails']['symbol'] ?? ''),
                'values'   => $values,
            ];
        }
        $this->pdo->prepare('DELETE FROM vonhalsky_category_attrs WHERE vh_category_id = ?')->execute([$categoryId]);
        $this->pdo->prepare('INSERT INTO vonhalsky_category_attrs (vh_category_id, attributes, fetched_at) VALUES (?, ?, ?)')
            ->execute([$categoryId, json_encode($out, JSON_UNESCAPED_UNICODE), $this->nowSql()]);
        return $out;
    }

    /** @return array<string,array{mode:string,value:string}> attribute_id => reguła */
    public function rules(string $categoryId): array
    {
        $st = $this->pdo->prepare('SELECT attribute_id, mode, value FROM vonhalsky_attribute_rules WHERE vh_category_id = ?');
        $st->execute([$categoryId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['attribute_id']] = ['mode' => (string) $r['mode'], 'value' => (string) $r['value']];
        }
        return $out;
    }

    /**
     * mode: value (stała wartość albo id wartości słownikowej) / field (pole produktu albo „attr:Nazwa”) / '' (bez reguły).
     * @param array<string,array{mode:string,value:string}> $rules
     */
    public function saveRules(string $categoryId, array $rules): void
    {
        $this->pdo->prepare('DELETE FROM vonhalsky_attribute_rules WHERE vh_category_id = ?')->execute([$categoryId]);
        $ins = $this->pdo->prepare('INSERT INTO vonhalsky_attribute_rules (vh_category_id, attribute_id, mode, value) VALUES (?, ?, ?, ?)');
        foreach ($rules as $attrId => $r) {
            $mode = (string) ($r['mode'] ?? '');
            $value = trim((string) ($r['value'] ?? ''));
            if (in_array($mode, ['value', 'field'], true) && $value !== '') {
                $ins->execute([$categoryId, (string) $attrId, $mode, $value]);
            }
        }
    }

    /**
     * Wartości parametru w formacie vonHalsky (zawsze teksty): słownik - wartość z listy (dopasowanie po id albo nazwie),
     * liczba - liczby z tekstu, reszta - tekst.
     * @param list<string> $raw
     * @return list<string>
     */
    public static function attributeValues(array $def, array $raw): array
    {
        $out = [];
        $type = (string) ($def['type'] ?? 'TEXT_VALUE');
        foreach ($raw as $v) {
            $v = trim((string) $v);
            if ($v === '') {
                continue;
            }
            if ($type === 'DICTIONARY') {
                $dict = (array) ($def['values'] ?? []);
                if (array_key_exists($v, $dict)) {
                    $out[] = (string) $dict[$v];
                    continue;
                }
                foreach ($dict as $name) {
                    if (mb_strtolower(trim((string) $name)) === mb_strtolower($v)) {
                        $out[] = (string) $name;
                        break;
                    }
                }
            } elseif ($type === 'NUMERIC' || $type === 'NUMERIC_FLOAT') {
                if (preg_match('/-?\d+(?:[.,]\d+)?/', $v, $m)) {
                    $num = str_replace(',', '.', $m[0]);
                    $out[] = $type === 'NUMERIC' ? (string) (int) round((float) $num) : (string) ((float) $num);
                }
            } else {
                $out[] = mb_substr($v, 0, 1024);
            }
        }
        $out = array_values(array_unique($out));
        return empty($def['multi']) ? array_slice($out, 0, 1) : $out;
    }

    /** Nazwy atrybutów produktów z WooCommerce (do wyboru w regułach). @return list<string> */
    public function productAttributeNames(): array
    {
        $names = [];
        foreach ($this->pdo->query('SELECT attributes FROM products WHERE attributes IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $json) {
            foreach (ErliOffers::productAttributes(['attributes' => $json]) as $name => $_) {
                $names[$name] = true;
            }
        }
        ksort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return array_map('strval', array_keys($names));
    }

    // ---------------------------------------------------------------- podpowiedź po EAN

    /** Pierwsza podpowiedź InPost dla EAN (z pamięci 7 dni). @return array{product?:array,gpsr?:array}|null */
    public function hint(array $account, string $ean, bool $refresh = false): ?array
    {
        $ean = preg_replace('/\D/', '', $ean) ?? '';
        if ($ean === '') {
            return null;
        }
        $st = $this->pdo->prepare('SELECT data, fetched_at FROM vonhalsky_hints WHERE ean = ?');
        $st->execute([$ean]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && !$refresh && time() - (int) strtotime((string) $row['fetched_at']) < self::HINT_TTL) {
            $data = json_decode((string) $row['data'], true);
            return is_array($data) && $data !== [] ? $data : null;
        }
        try {
            $hints = $this->vh->client($account)->hints($this->vh->organizationId($account), $ean);
        } catch (\Throwable) {
            return null;   // podpowiedź jest dodatkiem - bez niej wystawiamy z danych CRM
        }
        $first = $hints[0] ?? [];
        $this->pdo->prepare('DELETE FROM vonhalsky_hints WHERE ean = ?')->execute([$ean]);
        $this->pdo->prepare('INSERT INTO vonhalsky_hints (ean, data, fetched_at) VALUES (?, ?, ?)')
            ->execute([$ean, json_encode($first, JSON_UNESCAPED_UNICODE), $this->nowSql()]);
        return $first !== [] ? $first : null;
    }

    // ---------------------------------------------------------------- oferta

    private static function absoluteUrl(string $url, string $base): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^https?://#i', $url)) {
            return $url;
        }
        return $base !== '' ? rtrim($base, '/') . '/' . ltrim($url, '/') : '';
    }

    /** Marka: atrybut produktu (Marka/Producent/Brand), potem podpowiedź InPost, potem ustawienie konta. */
    public static function brandFor(array $account, array $product, ?array $hint = null): string
    {
        foreach (ErliOffers::productAttributes($product) as $name => $vals) {
            if (in_array(mb_strtolower($name), self::BRAND_ATTRIBUTES, true) && trim((string) ($vals[0] ?? '')) !== '') {
                return trim((string) $vals[0]);
            }
        }
        $hb = trim((string) ($hint['product']['brand'] ?? ''));
        return $hb !== '' ? $hb : trim((string) ($account['config']['brand'] ?? ''));
    }

    /** Dane GPSR z ustawień konta. @return array{0:?array,1:list<string>} [gpsr albo null, błędy] */
    public static function gpsrFor(array $account, bool $categoryFree): array
    {
        $cfg = $account['config'] ?? [];
        if (($cfg['gpsr_mode'] ?? 'manufacturer') === 'exempt') {
            return [['doesNotRequireGpsrInfo' => true], []];
        }
        $name = trim((string) ($cfg['gpsr_name'] ?? ''));
        $email = trim((string) ($cfg['gpsr_email'] ?? ''));
        if ($name === '' || $email === '') {
            return $categoryFree ? [null, []] : [null, ['Brak danych producenta (GPSR): wpisz nazwę i e-mail producenta w ustawieniach konta vonHalsky.']];
        }
        $m = ['name' => mb_substr($name, 0, 255), 'email' => $email];
        $phone = preg_replace('/[^\d+]/', '', (string) ($cfg['gpsr_phone'] ?? '')) ?? '';
        if ($phone !== '') {
            $m['phone'] = str_starts_with($phone, '+') ? $phone : '+48' . ltrim($phone, '0');
        }
        $country = strtoupper(trim((string) ($cfg['gpsr_country'] ?? 'PL')));
        if (preg_match('/^[A-Z]{2}$/', $country)) {
            $m['countryCode'] = $country;
        }
        if (trim((string) ($cfg['gpsr_address'] ?? '')) !== '') {
            $m['unstructuredAddress'] = mb_substr(trim((string) $cfg['gpsr_address']), 0, 500);
        }
        $person = array_map('trim', explode(';', (string) ($cfg['gpsr_person'] ?? '')));
        if (($person[0] ?? '') !== '') {
            $m['responsiblePersonDetails'] = array_filter([
                'name' => $person[0],
                'email' => $person[1] ?? '',
                'unstructuredAddress' => $person[2] ?? '',
            ], static fn($v) => $v !== '');
        }
        $gpsr = ['manufacturer' => $m];
        $safety = trim((string) ($cfg['gpsr_safety'] ?? ''));
        if ($safety !== '') {
            $gpsr['safetyInformation'] = mb_substr($safety, 0, 4000);
        }
        return [$gpsr, []];
    }

    /**
     * Treść oferty dla produktu (bez wysyłania). errors = nie da się wystawić; warnings = InPost może odrzucić albo oferta uboższa.
     * @return array{external_id:string,category_id:?string,category_source:string,payload:array<string,mixed>,errors:list<string>,warnings:list<string>}
     */
    public function buildOffer(array $account, array $product): array
    {
        $errors = [];
        $warnings = [];
        $cfg = $account['config'] ?? [];
        $ext = trim((string) ($product['sku'] ?? ''));
        if ($ext === '') {
            $errors[] = 'Produkt nie ma SKU - po nim CRM łączy ofertę z magazynem.';
        }
        $price = Vonhalsky::offerPrice($account, $product['pase_price'] ?? null);
        if ($price === null) {
            $errors[] = 'Produkt nie ma ceny w magazynie.';
        }
        $ean = preg_replace('/\D/', '', (string) ($product['ean'] ?? '')) ?? '';
        $hint = $ean !== '' ? $this->hint($account, $ean) : null;

        // Kategoria: przypisanie z Kategorii i parametrów, bez niego - z podpowiedzi InPost po EAN.
        $categoryId = $this->mappedCategory($product);
        $source = 'mapping';
        if ($categoryId === null && trim((string) ($hint['product']['categoryId'] ?? '')) !== '') {
            $categoryId = trim((string) $hint['product']['categoryId']);
            $source = 'hint';
            $warnings[] = 'Kategoria z podpowiedzi InPost po EAN (' . ($this->categoryPath($categoryId) ?: $categoryId) . ') - możesz przypisać własną w Kategoriach i parametrach.';
        }
        if ($categoryId === null) {
            $source = '';
            $errors[] = 'Kategoria produktu nie ma przypisanej kategorii vonHalsky (Kategorie i parametry), a InPost nie podpowiedział jej po EAN.';
        }

        $brand = self::brandFor($account, $product, $hint);
        if ($brand === '') {
            $errors[] = 'Brak marki: dodaj produktowi atrybut „Marka” albo wpisz markę w ustawieniach konta vonHalsky.';
        }
        $desc = AllegroOfferPayload::sanitizeHtml((string) ($product['description'] ?? ''));
        if ($desc === '') {
            $desc = AllegroOfferPayload::sanitizeHtml((string) ($product['short_description'] ?? ''));
        }
        if ($desc === '') {
            $errors[] = 'Produkt nie ma opisu (vonHalsky go wymaga).';
        }

        $base = (string) ($this->settings->get('APP_BASE_URL', '') ?? '');
        $images = [];
        foreach ((array) (json_decode((string) ($product['images'] ?? '[]'), true) ?: []) as $img) {
            $url = self::absoluteUrl(is_array($img) ? (string) ($img['src'] ?? $img['url'] ?? '') : (string) $img, $base);
            if ($url !== '' && !in_array($url, array_column($images, 'fileUrl'), true)) {
                $file = basename((string) parse_url($url, PHP_URL_PATH)) ?: 'zdjecie-' . (count($images) + 1) . '.jpg';
                $images[] = ['fileName' => mb_substr($file, 0, 255), 'fileUrl' => $url, 'priority' => count($images) + 1];
            }
        }
        if ($images === []) {
            $errors[] = 'Produkt nie ma zdjęć.';
        }

        $productInfo = [
            'name'        => mb_substr(trim((string) ($product['name'] ?? '')), 0, 255),
            'description' => $desc,
            'brand'       => mb_substr($brand, 0, 255),
            'sku'         => $ext,
        ];
        if ($categoryId !== null) {
            $productInfo['categoryId'] = $categoryId;
            [$attrs, $attrWarnings] = $this->offerAttributes($account, $categoryId, $product, $source === 'hint' || $this->sameHintCategory($hint, $categoryId) ? $hint : null);
            if ($attrs !== []) {
                $productInfo['attributes'] = $attrs;
            }
            array_push($warnings, ...$attrWarnings);
        }
        if ($ean !== '') {
            $productInfo['ean'] = $ean;
        }
        // Wymiary: InPost chce wszystkie cztery albo żadnego (magazyn: cm i kg, InPost: mm i g).
        $dims = ['width' => $product['width'] ?? null, 'height' => $product['height'] ?? null, 'length' => $product['length'] ?? null];
        if (($product['weight'] ?? null) !== null && (float) $product['weight'] > 0 && min(array_map(static fn($v) => (float) $v, $dims)) > 0) {
            $productInfo['dimension'] = array_map(static fn($v) => (int) round((float) $v * 10), $dims) + ['weight' => (int) round((float) $product['weight'] * 1000)];
        }

        $cat = $categoryId !== null ? $this->category($categoryId) : null;
        [$gpsr, $gpsrErrors] = self::gpsrFor($account, !empty($cat['gpsr_free']));
        array_push($errors, ...$gpsrErrors);

        $tax = (string) ($cfg['tax_rate'] ?? '23%');
        $payload = [
            'externalId' => $ext,
            'product'    => $productInfo,
            'stock'      => ['quantity' => max(0, (int) ($product['pase_stock'] ?? 0)), 'unit' => 'UNIT'],
            'price'      => ['grossPrice' => ['amount' => $price ?? 0.0, 'currency' => 'PLN'], 'taxRateInfo' => $tax !== '' ? $tax : '23%'],
            'shippingTime' => ['daysToShip' => max(0, (int) ($cfg['shipping_days'] ?? 1))],
            'images'     => $images,
        ];
        if ($gpsr !== null) {
            $payload['gpsr'] = $gpsr;
        }
        $postSale = array_filter([
            'returnPolicy'    => trim((string) ($cfg['return_policy'] ?? '')) !== '' ? ['description' => trim((string) $cfg['return_policy'])] : null,
            'complaintPolicy' => trim((string) ($cfg['complaint_policy'] ?? '')) !== '' ? ['description' => trim((string) $cfg['complaint_policy'])] : null,
        ]);
        if ($postSale !== []) {
            $payload['postSale'] = $postSale;
        }
        return ['external_id' => $ext, 'category_id' => $categoryId, 'category_source' => $source, 'payload' => $payload, 'errors' => $errors, 'warnings' => $warnings];
    }

    private function sameHintCategory(?array $hint, string $categoryId): bool
    {
        return $hint !== null && (string) ($hint['product']['categoryId'] ?? '') === $categoryId;
    }

    /**
     * Parametry oferty: reguły kategorii, a gdy reguły nie dają wartości - podpowiedź InPost (ta sama kategoria).
     * @return array{0:list<array{id:string,lang:string,values:list<string>}>,1:list<string>}
     */
    public function offerAttributes(array $account, string $categoryId, array $product, ?array $hint = null): array
    {
        $rules = $this->rules($categoryId);
        try {
            $defs = $this->attributes($account, $categoryId);
        } catch (\Throwable) {
            $defs = [];
        }
        $hinted = [];
        foreach ((array) ($hint['product']['attributes'] ?? []) as $ha) {
            if (is_array($ha) && isset($ha['id']) && !empty($ha['values'])) {
                $hinted[(string) $ha['id']] = $ha;
            }
        }
        $productAttrs = ErliOffers::productAttributes($product);
        $out = [];
        $warnings = [];
        foreach ($defs as $id => $def) {
            $rule = $rules[(string) $id] ?? null;
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
            $lang = (string) ($def['lang'] ?? '') ?: 'pl';
            if ($values === [] && isset($hinted[(string) $id])) {
                $values = array_values(array_map('strval', (array) $hinted[(string) $id]['values']));
                $lang = (string) ($hinted[(string) $id]['lang'] ?? '') ?: $lang;
            }
            if ($values === []) {
                if (!empty($def['required'])) {
                    $warnings[] = 'Brak wymaganego parametru „' . $def['name'] . '”' . ($raw !== [] ? ' (wartość „' . implode(', ', $raw) . '” nie pasuje do listy InPost)' : '') . '.';
                }
                continue;
            }
            $out[] = ['id' => (string) $id, 'lang' => $lang, 'values' => $values];
        }
        // Parametry z podpowiedzi, których nie ma w pobranych definicjach (np. definicje jeszcze niepobrane).
        if ($defs === []) {
            foreach ($hinted as $hid => $ha) {
                $out[] = ['id' => (string) $hid, 'lang' => (string) ($ha['lang'] ?? '') ?: 'pl', 'values' => array_values(array_map('strval', (array) $ha['values']))];
            }
        }
        return [$out, $warnings];
    }

    // ---------------------------------------------------------------- wystawianie

    /** @return array<int,array<string,mixed>> product_id => wpis z vonhalsky_listings dla konta */
    public function listings(int $accountId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM vonhalsky_listings WHERE integration_id = ?');
        $st->execute([$accountId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['product_id']] = $r;
        }
        return $out;
    }

    /** Oferta, którą produkt już ma: wystawiona z CRM, połączona ręcznie albo z tym samym SKU. */
    public function existingOfferId(int $accountId, array $product): ?string
    {
        $st = $this->pdo->prepare('SELECT offer_id FROM vonhalsky_listings WHERE integration_id = ? AND product_id = ? AND offer_id IS NOT NULL');
        $st->execute([$accountId, (int) $product['id']]);
        $id = $st->fetchColumn();
        if ($id !== false && $id !== '') {
            return (string) $id;
        }
        $st = $this->pdo->prepare("SELECT offer_id FROM vonhalsky_offers WHERE integration_id = ? AND (linked_product_id = ? OR (linked_product_id IS NULL AND match_sku = ?))
            AND (offer_status IS NULL OR offer_status <> 'REJECTED') ORDER BY id LIMIT 1");
        $st->execute([$accountId, (int) $product['id'], trim((string) ($product['sku'] ?? '')) ?: "\0"]);
        $id = $st->fetchColumn();
        return $id !== false && $id !== '' ? (string) $id : null;
    }

    /**
     * Wystawia (albo aktualizuje, gdy produkt ma już ofertę) wybrane produkty.
     * @param list<int> $productIds
     * @return array{listed:int,updated:int,failed:int,messages:list<string>}
     */
    public function publish(array $account, array $productIds): array
    {
        $client = $this->vh->client($account);
        $org = $this->vh->organizationId($account);
        if ($org === '') {
            throw new \RuntimeException('Nie wybrano organizacji (sklepu) - połącz konto jeszcze raz.');
        }
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
                $this->saveListing($accId, $pid, null, $offer['external_id'] ?: ('#' . $pid), 'error', implode(' ', $offer['errors']), $offer['warnings']);
                $res['failed']++;
                $res['messages'][] = $name . ': ' . implode(' ', $offer['errors']);
                continue;
            }
            $existing = $this->existingOfferId($accId, $product);
            try {
                if ($existing !== null) {
                    $patch = $offer['payload'];
                    unset($patch['externalId']);   // raz nadany identyfikator zewnętrzny jest stały
                    $client->patchOffer($org, $existing, $patch);
                    $offerId = $existing;
                } else {
                    $created = $client->createOffer($org, $offer['payload']);
                    $offerId = trim((string) ($created['offerId'] ?? ''));
                    if ($offerId === '') {
                        throw new \RuntimeException('InPost nie zwrócił identyfikatora oferty.');
                    }
                }
                $this->saveListing($accId, $pid, $offerId, $offer['external_id'], 'sent', null, $offer['warnings']);
                $this->linkCatalog($accId, $offerId, (int) $pid, $offer['external_id'], $name, $offer['payload'], $existing === null);
                $res[$existing === null ? 'listed' : 'updated']++;
            } catch (\Throwable $e) {
                $this->saveListing($accId, $pid, $existing, $offer['external_id'], 'error', $e->getMessage(), $offer['warnings']);
                $res['failed']++;
                $res['messages'][] = $name . ': ' . $e->getMessage();
            }
        }
        return $res;
    }

    private function saveListing(int $accId, int $productId, ?string $offerId, string $ext, string $status, ?string $error, array $warnings): void
    {
        $now = $this->nowSql();
        $st = $this->pdo->prepare('SELECT id FROM vonhalsky_listings WHERE integration_id = ? AND product_id = ?');
        $st->execute([$accId, $productId]);
        $rowId = $st->fetchColumn();
        $warn = $warnings !== [] ? implode("\n", $warnings) : null;
        if ($rowId !== false) {
            $this->pdo->prepare('UPDATE vonhalsky_listings SET offer_id = COALESCE(?, offer_id), external_id = ?, status = ?, error = ?, warnings = ?, updated_at = ?,
                    listed_at = COALESCE(listed_at, ?) WHERE id = ?')
                ->execute([$offerId, $ext, $status, $error, $warn, $now, $status === 'sent' ? $now : null, (int) $rowId]);
        } else {
            $this->pdo->prepare('INSERT INTO vonhalsky_listings (integration_id, product_id, offer_id, external_id, status, error, warnings, listed_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$accId, $productId, $offerId, $ext, $status, $error, $warn, $status === 'sent' ? $now : null, $now]);
        }
    }

    /** Wystawiona oferta od razu w vonhalsky_offers, połączona z produktem - stany i ceny pilnuje dalej worker. */
    private function linkCatalog(int $accId, string $offerId, int $productId, string $sku, string $name, array $payload, bool $new): void
    {
        $now = $this->nowSql();
        $qty = (int) $payload['stock']['quantity'];
        $price = (float) $payload['price']['grossPrice']['amount'];
        $st = $this->pdo->prepare('SELECT id FROM vonhalsky_offers WHERE integration_id = ? AND offer_id = ?');
        $st->execute([$accId, $offerId]);
        $id = $st->fetchColumn();
        if ($id !== false) {
            $this->pdo->prepare('UPDATE vonhalsky_offers SET linked_product_id = ?, product_name = ?, vh_stock = ?, pushed_qty = ?, vh_price = ?, pushed_price = ?,
                    pushed_at = ?, error = NULL, seen_at = ? WHERE id = ?')
                ->execute([$productId, mb_substr($name, 0, 255), $qty, $qty, $price, $price, $now, $now, (int) $id]);
        } else {
            $this->pdo->prepare("INSERT INTO vonhalsky_offers (integration_id, offer_id, match_sku, linked_product_id, product_name, offer_status, vh_stock, stock_unit,
                    vh_price, currency, pushed_qty, pushed_price, pushed_at, seen_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'UNIT', ?, 'PLN', ?, ?, ?, ?)")
                ->execute([$accId, $offerId, $sku, $productId, mb_substr($name, 0, 255), $new ? 'PENDING' : null, $qty, $price, $qty, $price, $now, $now]);
        }
    }

    // ---------------------------------------------------------------- oferty: zamknięcie, wznowienie, łączenie

    /** Zamknięcie (true) albo wznowienie (false) oferty. @return array{ok:bool,message:string} */
    public function setClosed(array $account, string $offerId, bool $close): array
    {
        try {
            $client = $this->vh->client($account);
            $org = $this->vh->organizationId($account);
            $close ? $client->closeOffer($org, $offerId) : $client->reopenOffer($org, $offerId);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        $this->pdo->prepare('UPDATE vonhalsky_offers SET offer_status = ? WHERE integration_id = ? AND offer_id = ?')
            ->execute([$close ? 'CLOSED' : 'PENDING', (int) $account['id'], $offerId]);
        return ['ok' => true, 'message' => $close ? 'Oferta zamknięta w InPost vonHalsky.' : 'Oferta wznowiona - InPost ją sprawdzi i opublikuje.'];
    }

    /** Produkt magazynu po SKU albo EAN (do ręcznego łączenia). @return array<string,mixed>|null */
    public function findProduct(string $term): ?array
    {
        $term = trim($term);
        if ($term === '') {
            return null;
        }
        $st = $this->pdo->prepare('SELECT id, sku, name, pase_stock FROM products WHERE sku = ? ORDER BY id LIMIT 1');
        $st->execute([$term]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p && preg_match('/^\d{8,14}$/', $term)) {
            $st = $this->pdo->prepare('SELECT id, sku, name, pase_stock FROM products WHERE ean = ? ORDER BY id LIMIT 1');
            $st->execute([$term]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
        }
        return $p ?: null;
    }

    /**
     * Ręczne połączenie oferty z produktem (null = rozłącz, wraca dopasowanie po SKU). Stan wyśle najbliższa synchronizacja.
     * @return array{ok:bool,message:string}
     */
    public function linkOffer(int $accountId, string $offerId, ?string $productTerm): array
    {
        $st = $this->pdo->prepare('SELECT id, product_name FROM vonhalsky_offers WHERE integration_id = ? AND offer_id = ?');
        $st->execute([$accountId, $offerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'message' => 'Nie ma takiej oferty - odśwież listę ofert.'];
        }
        if ($productTerm === null) {
            $this->pdo->prepare('UPDATE vonhalsky_offers SET linked_product_id = NULL, pushed_qty = NULL, pushed_price = NULL, error = NULL WHERE id = ?')->execute([(int) $row['id']]);
            return ['ok' => true, 'message' => 'Rozłączono ofertę „' . $row['product_name'] . '” - wraca dopasowanie po SKU.'];
        }
        $product = $this->findProduct($productTerm);
        if ($product === null) {
            return ['ok' => false, 'message' => 'Nie ma w magazynie produktu o SKU albo EAN „' . $productTerm . '”.'];
        }
        // pushed_* = NULL - najbliższa synchronizacja wyśle stan (i cenę) połączonego produktu.
        $this->pdo->prepare('UPDATE vonhalsky_offers SET linked_product_id = ?, pushed_qty = NULL, pushed_price = NULL, error = NULL WHERE id = ?')
            ->execute([(int) $product['id'], (int) $row['id']]);
        return ['ok' => true, 'message' => 'Oferta „' . $row['product_name'] . '” połączona z „' . $product['name'] . '” (SKU ' . $product['sku'] . '). Stan pójdzie przy najbliższej synchronizacji.'];
    }
}
