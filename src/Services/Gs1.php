<?php
declare(strict_types=1);

namespace Pase\Services;

use Pase\Plugin\IntegrationClient;
use Pase\Plugin\PluginRegistry;
use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\SettingsRepository;
use PDO;

/**
 * Integracja z GS1 Polska (MojeGS1, https://mojegs1.pl/api/v2/index.html).
 *
 *  - Odczyt (automatyczny): karty produktów z MojeGS1 (GTIN, nazwa, marka, status...) trafiają do tabeli gs1_products
 *    i łączą się z produktami CRM po EAN (products.ean). Nic w GS1 się przy tym nie zmienia.
 *  - Zapis (tylko po kliknięciu): nadanie nowego numeru GTIN = utworzenie karty produktu w MojeGS1 (PUT /products/{gtin}).
 *    Numery GS1 są płatne i limitowane, więc CRM tylko proponuje kolejny wolny numer z prefiksu firmy,
 *    sprawdza w GS1, że jest wolny, i wysyła kartę dopiero po potwierdzeniu formularza.
 *
 * Połączenie z API (klient, login, hasło, prefiks) daje wtyczka integrations/gs1 - konto w Konfiguracja → Integracje.
 * Rdzeń nie importuje klas wtyczki: klienta bierze przez IntegrationClient::for('gs1', config).
 * Bez aktywnej wtyczki strona GS1 i przycisk na karcie produktu są ukryte.
 */
final class Gs1
{
    public const TYPE = 'gs1';
    public const S_LAST_SYNC = 'GS1_LAST_SYNC';
    /** Dawne ustawienia (przed wtyczką) - przenoszone raz do konta integracji. */
    private const LEGACY = ['GS1_API_LOGIN' => 'login', 'GS1_API_PASSWORD' => 'password', 'GS1_COMPANY_PREFIX' => 'prefix', 'GS1_PUSH_EAN_WOO' => 'push_woo'];
    /** Automatyczne odświeżenie listy z GS1 przy wejściu na stronę, gdy ostatnie starsze niż tyle godzin. */
    public const AUTO_SYNC_HOURS = 12;
    /** Kod GPC „tymczasowy” w MojeGS1 - kategoria do uzupełnienia. */
    public const GPC_TEMPORARY = 99999999;
    private const PAGE_LIMIT = 100;
    private const MAX_PAGES = 55;   // 60 zapytań/min - zostawiamy zapas na sprawdzenie numeru i zapis karty

    /** Jednostki zawartości netto akceptowane w formularzu (kody jak w MojeGS1). */
    public const UNITS = ['g' => 'g', 'kg' => 'kg', 'ml' => 'ml', 'l' => 'l', 'szt' => 'szt.', 'cm' => 'cm', 'm' => 'm'];

    /** @var callable(array):?object config konta => klient wtyczki (Gs1Client) */
    private $clientFactory;
    /** @var array<string,mixed>|null */
    private ?array $account = null;

    /** @param ?callable(array):?object $clientFactory w testach: atrapa klienta; domyślnie klient z wtyczki */
    public function __construct(private readonly PDO $pdo, ?callable $clientFactory = null)
    {
        $this->clientFactory = $clientFactory ?? static fn(array $config): ?object => IntegrationClient::for(self::TYPE, $config);
    }

    /** Czy wtyczka GS1 jest zainstalowana i włączona (inaczej strona i przyciski GS1 są ukryte). */
    public static function available(): bool
    {
        return PluginRegistry::get(self::TYPE) !== null;
    }

    public static function migrate(PDO $pdo): void
    {
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $pdo->exec('CREATE TABLE IF NOT EXISTS gs1_products (
            gtin VARCHAR(14) NOT NULL PRIMARY KEY,
            name VARCHAR(400) NULL,
            brand VARCHAR(200) NULL,
            sub_brand VARCHAR(200) NULL,
            common_name VARCHAR(150) NULL,
            variant VARCHAR(70) NULL,
            internal_symbol VARCHAR(255) NULL,
            status VARCHAR(16) NULL,
            gpc_code BIGINT NULL,
            net_content DECIMAL(14,3) NULL,
            net_content_unit VARCHAR(16) NULL,
            image VARCHAR(1000) NULL,
            website VARCHAR(1000) NULL,
            suggestions TEXT NULL,
            last_modified VARCHAR(40) NULL,
            payload ' . ($sqlite ? 'TEXT' : 'MEDIUMTEXT') . ' NULL,
            synced_at DATETIME NULL
        )' . ($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'));
        self::moveLegacySettings($pdo);
    }

    /**
     * Dane z dawnych ustawień GS1 (login, hasło, prefiks) trafiają raz do konta wtyczki GS1, a ustawienia są usuwane.
     * Gdy konto GS1 już jest, dawne ustawienia tylko znikają.
     */
    private static function moveLegacySettings(PDO $pdo): void
    {
        $keys = array_keys(self::LEGACY);
        $st = $pdo->prepare('SELECT setting_key, setting_value FROM settings WHERE setting_key IN (?,?,?,?)');
        $st->execute($keys);
        $old = $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        foreach ($old as $k => $v) { $old[$k] = \Pase\Support\SecretStore::openSetting((string) $k, $v, false); }
        if ($old === []) {
            return;
        }
        $accounts = new IntegrationAccountRepository($pdo);
        if (trim((string) ($old['GS1_API_LOGIN'] ?? '')) !== '' && $accounts->activeByType(self::TYPE) === []) {
            $config = [];
            foreach (self::LEGACY as $key => $field) {
                $config[$field] = (string) ($old[$key] ?? ($field === 'push_woo' ? '1' : ''));
            }
            $accounts->create(self::TYPE, 'MojeGS1', $config);
        }
        $pdo->prepare('DELETE FROM settings WHERE setting_key IN (?,?,?,?)')->execute($keys);
    }

    private function settings(): SettingsRepository
    {
        return new SettingsRepository($this->pdo);
    }

    /** Aktywne konto wtyczki GS1 (Konfiguracja → Integracje) albo null. */
    public function account(): ?array
    {
        return $this->account ??= (new IntegrationAccountRepository($this->pdo))->firstActive(self::TYPE);
    }

    /** @return array<string,mixed> */
    private function config(): array
    {
        return (array) ($this->account()['config'] ?? []);
    }

    /** Klient API z wtyczki (Gs1Client) albo null, gdy wtyczki nie ma. */
    private function client(): ?object
    {
        return ($this->clientFactory)($this->config());
    }

    public function configured(): bool
    {
        $client = $this->client();
        return $client !== null && $client->configured();
    }

    /** Wyszukiwarka GS1 (Verified by GS1): dane dowolnego numeru GTIN, także obcego - kto go nadał i co opisuje. */
    public const VERIFY_URL = 'https://www.gs1.org/services/verified-by-gs1/results?gtin=';

    /**
     * Link do karty numeru w systemie GS1. Z adresem karty w ustawieniach wtyczki (np. https://mojegs1.pl/...{gtin}...)
     * otwiera kartę w MojeGS1, bez niego - wyszukiwarkę Verified by GS1 dla tego numeru.
     */
    public function cardUrl(string $gtin): string
    {
        $gtin = self::normalize($gtin);
        $tpl = trim((string) ($this->config()['card_url'] ?? ''));
        return str_contains($tpl, '{gtin}') && preg_match('~^https://~i', $tpl)
            ? str_replace('{gtin}', rawurlencode($gtin), $tpl)
            : self::VERIFY_URL . rawurlencode($gtin);
    }

    public function prefix(): string
    {
        return preg_replace('/\D/', '', (string) ($this->config()['prefix'] ?? '')) ?? '';
    }

    public function pushToWoo(): bool
    {
        return (string) ($this->config()['push_woo'] ?? '1') === '1';
    }

    public function lastSync(): ?string
    {
        return $this->settings()->get(self::S_LAST_SYNC);
    }

    public function syncDue(): bool
    {
        $last = $this->lastSync();
        return $last === null || strtotime($last) < time() - self::AUTO_SYNC_HOURS * 3600;
    }

    // ------------------------------------------------------------------ API (przez wtyczkę)

    private function api(): object
    {
        $client = $this->client();
        if ($client === null) {
            throw new \RuntimeException('Wtyczka GS1 jest wyłączona albo usunięta (Konfiguracja → Wtyczki).');
        }
        if (!$client->configured()) {
            throw new \RuntimeException('Brak danych dostępu do MojeGS1. Wpisz je w Konfiguracja → Integracje → GS1.');
        }
        return $client;
    }

    /**
     * Pobiera wszystkie karty z MojeGS1 do gs1_products (nowe i zmienione nadpisuje, usunięte w GS1 kasuje)
     * i zapisuje czas synchronizacji. Tylko odczyt po stronie GS1.
     * @return array{total:int,linked:int,truncated:bool}
     */
    public function sync(): array
    {
        self::migrate($this->pdo);
        $api = $this->api();
        $all = [];
        $truncated = false;
        for ($page = 1; ; $page++) {
            if ($page > self::MAX_PAGES) {
                $truncated = true;
                break;
            }
            [$st, $data] = $api->listProducts($page, self::PAGE_LIMIT);
            if ($st !== 200) {
                throw new \RuntimeException($api::errorMessage($st, $data));
            }
            $rows = is_array($data['data'] ?? null) ? $data['data'] : [];
            $new = 0;
            foreach ($rows as $row) {
                $gtin = self::normalize((string) ($row['id'] ?? ''));
                if ($gtin !== '' && !isset($all[$gtin])) {
                    $all[$gtin] = $row;
                    $new++;
                }
            }
            $next = (string) ($data['links']['next'] ?? '');
            $self = (string) ($data['links']['self'] ?? '');
            // Koniec: pusta strona, strona bez nowych numerów, brak "next", albo "next" wskazuje tę samą stronę (API podaje prev=first itp.).
            if ($rows === [] || $new === 0 || $next === '' || $next === $self) {
                break;
            }
        }

        $now = date('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $del = $this->pdo->prepare('DELETE FROM gs1_products WHERE gtin = ?');
            $ins = $this->pdo->prepare('INSERT INTO gs1_products (gtin, name, brand, sub_brand, common_name, variant, internal_symbol,
                status, gpc_code, net_content, net_content_unit, image, website, suggestions, last_modified, payload, synced_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($all as $gtin => $row) {
                $del->execute([(string) $gtin]);
                $ins->execute(self::rowValues((string) $gtin, $row, $now));
            }
            if (!$truncated) {
                // Karty, których GS1 już nie zwraca (usunięte), znikają z CRM.
                $this->pdo->prepare('DELETE FROM gs1_products WHERE synced_at IS NULL OR synced_at < ?')->execute([$now]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->settings()->setMany([self::S_LAST_SYNC => $now]);
        $linked = (int) $this->pdo->query('SELECT COUNT(DISTINCT g.gtin) FROM gs1_products g JOIN products p ON ' . $this->joinOn())->fetchColumn();
        return ['total' => count($all), 'linked' => $linked, 'truncated' => $truncated];
    }

    /** @return list<mixed> */
    private static function rowValues(string $gtin, array $row, string $now): array
    {
        $a = is_array($row['attributes'] ?? null) ? $row['attributes'] : [];
        $str = static fn(string $k, int $max): ?string => isset($a[$k]) && is_scalar($a[$k]) && trim((string) $a[$k]) !== ''
            ? mb_substr(trim((string) $a[$k]), 0, $max) : null;
        $sugg = array_values(array_filter(array_map('strval', (array) ($a['qualityDetails']['suggestions'] ?? []))));
        return [
            $gtin, $str('name', 400), $str('brandName', 200), $str('subBrandName', 200), $str('commonName', 150), $str('variant', 70),
            $str('internalSymbol', 255), $str('status', 16),
            isset($a['gpcCode']) && is_numeric($a['gpcCode']) ? (int) $a['gpcCode'] : null,
            isset($a['netContent']) && is_numeric($a['netContent']) ? (float) $a['netContent'] : null,
            $str('netContentUnit', 16), $str('productImage', 1000), $str('productWebsite', 1000),
            $sugg ? json_encode($sugg, JSON_UNESCAPED_UNICODE) : null,
            $str('lastModificationDate', 40),
            json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now,
        ];
    }

    /**
     * Warunek złączenia karty GS1 z produktem CRM po EAN. W products.ean bywa GTIN-13 albo z zerem z przodu (GTIN-14),
     * więc porównujemy obie postaci; spacje i myślniki z Woo usuwamy w PHP (normalize) przy zapisie, tu tylko TRIM.
     */
    private function joinOn(): string
    {
        $zero = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "'0' || g.gtin" : "CONCAT('0', g.gtin)";
        return "(TRIM(p.ean) = g.gtin OR TRIM(p.ean) = $zero)";
    }

    /** GTIN do porównań: same cyfry, bez zer wiodących ponad 13 cyfr (GTIN-14 z zerem = GTIN-13). */
    public static function normalize(string $code): string
    {
        $d = preg_replace('/\D/', '', $code) ?? '';
        while (strlen($d) > 13 && $d[0] === '0') {
            $d = substr($d, 1);
        }
        return $d;
    }

    /** Cyfra kontrolna GTIN (mod 10, wagi 3/1 od prawej) dla numeru BEZ cyfry kontrolnej. */
    public static function checkDigit(string $digitsWithoutCheck): int
    {
        $sum = 0;
        $rev = strrev($digitsWithoutCheck);
        for ($i = 0, $n = strlen($rev); $i < $n; $i++) {
            $sum += (int) $rev[$i] * ($i % 2 === 0 ? 3 : 1);
        }
        return (10 - $sum % 10) % 10;
    }

    public static function validGtin(string $code): bool
    {
        $d = preg_replace('/\D/', '', $code) ?? '';
        if (!in_array(strlen($d), [8, 12, 13, 14], true) || $d !== $code) {
            return false;
        }
        return self::checkDigit(substr($d, 0, -1)) === (int) substr($d, -1);
    }

    /**
     * Kolejny wolny GTIN-13 z prefiksu firmy: najwyższy znany numer z tym prefiksem (w GS1 albo w CRM) + 1.
     * Nie wypełniamy "dziur" - numer mógł być kiedyś użyty i wycofany, a GS1 nie pozwala go szybko użyć ponownie.
     */
    public function nextFreeGtin(): ?string
    {
        $prefix = $this->prefix();
        if ($prefix === '' || strlen($prefix) >= 12) {
            return null;
        }
        $refLen = 12 - strlen($prefix);
        $max = -1;
        $codes = $this->pdo->query('SELECT gtin FROM gs1_products')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($this->pdo->query("SELECT ean FROM products WHERE ean IS NOT NULL AND ean <> ''")->fetchAll(PDO::FETCH_COLUMN) as $e) {
            $codes[] = self::normalize((string) $e);
        }
        foreach ($codes as $c) {
            $c = (string) $c;
            if (strlen($c) === 13 && str_starts_with($c, $prefix)) {
                $max = max($max, (int) substr($c, strlen($prefix), $refLen));
            }
        }
        $next = $max + 1;
        if ($next >= 10 ** $refLen) {
            return null;   // pula numerów wyczerpana
        }
        $body = $prefix . str_pad((string) $next, $refLen, '0', STR_PAD_LEFT);
        return $body . self::checkDigit($body);
    }

    /**
     * Prefiks firmy zgadnięty z kart w GS1: wspólny początek wszystkich numerów (co najmniej dwie karty).
     * Tylko podpowiedź w ustawieniach - prawdziwy prefiks jest w MojeGS1.
     */
    public function guessPrefix(): ?string
    {
        $codes = array_values(array_filter(
            $this->pdo->query('SELECT gtin FROM gs1_products')->fetchAll(PDO::FETCH_COLUMN),
            static fn($c): bool => strlen((string) $c) === 13 && str_starts_with((string) $c, '59')
        ));
        if (count($codes) < 2) {
            return null;
        }
        $p = (string) $codes[0];
        foreach ($codes as $c) {
            $i = 0;
            while ($i < strlen($p) && $p[$i] === ((string) $c)[$i]) {
                $i++;
            }
            $p = substr($p, 0, $i);
        }
        $p = substr($p, 0, 10);
        return strlen($p) >= 7 ? $p : null;
    }

    // ------------------------------------------------------------------ widoki

    /** Karta GS1 dla EAN produktu (z lokalnej kopii, bez sieci). */
    public function cardFor(?string $ean): ?array
    {
        $g = self::normalize((string) $ean);
        if ($g === '') {
            return null;
        }
        self::migrate($this->pdo);
        $st = $this->pdo->prepare('SELECT * FROM gs1_products WHERE gtin = ?');
        $st->execute([$g]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Zestawienie: karty GS1 z produktem CRM (lub bez), produkty CRM bez EAN i produkty z EAN spoza GS1.
     * @return array{cards:list<array>,without_ean:list<array>,foreign:list<array>}
     */
    public function overview(): array
    {
        self::migrate($this->pdo);
        $cards = $this->pdo->query('SELECT g.*, (SELECT p.id FROM products p WHERE ' . $this->joinOn() . ' ORDER BY p.id LIMIT 1) AS product_id,
                (SELECT p.sku FROM products p WHERE ' . $this->joinOn() . ' ORDER BY p.id LIMIT 1) AS product_sku,
                (SELECT p.name FROM products p WHERE ' . $this->joinOn() . ' ORDER BY p.id LIMIT 1) AS product_name
            FROM gs1_products g ORDER BY g.name, g.gtin')->fetchAll(PDO::FETCH_ASSOC);
        // Karta bez produktu w CRM: podpowiedź po symbolu wewnętrznym = SKU.
        $skuStmt = $this->pdo->prepare("SELECT id, sku, name, ean FROM products WHERE sku = ? LIMIT 1");
        foreach ($cards as &$c) {
            $c['sku_match'] = null;
            if ($c['product_id'] === null && ($c['internal_symbol'] ?? '') !== '') {
                $skuStmt->execute([$c['internal_symbol']]);
                $c['sku_match'] = $skuStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }
        unset($c);
        $withoutEan = $this->pdo->query("SELECT id, sku, name FROM products WHERE ean IS NULL OR TRIM(ean) = '' ORDER BY name, sku")
            ->fetchAll(PDO::FETCH_ASSOC);
        $known = array_fill_keys(array_map('strval', array_column($cards, 'gtin')), true);
        $foreign = [];
        foreach ($this->pdo->query("SELECT id, sku, name, ean FROM products WHERE ean IS NOT NULL AND TRIM(ean) <> '' ORDER BY name, sku")
                     ->fetchAll(PDO::FETCH_ASSOC) as $p) {
            if (!isset($known[self::normalize((string) $p['ean'])])) {
                $foreign[] = $p;
            }
        }
        return ['cards' => $cards, 'without_ean' => $withoutEan, 'foreign' => $foreign];
    }

    /**
     * Wyszukiwarka na stronie GS1: każde słowo musi wystąpić w GTIN/EAN, nazwie, SKU albo symbolu (bez wielkości liter).
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function search(array $rows, string $query): array
    {
        $words = preg_split('/\s+/u', mb_strtolower(trim($query))) ?: [];
        $words = array_values(array_filter($words, static fn($w) => $w !== ''));
        if ($words === []) {
            return $rows;
        }
        $fields = ['gtin', 'ean', 'name', 'common_name', 'brand', 'sku', 'internal_symbol', 'product_sku', 'product_name'];
        return array_values(array_filter($rows, static function (array $r) use ($words, $fields): bool {
            $hay = '';
            foreach ($fields as $f) {
                $hay .= ' ' . mb_strtolower((string) ($r[$f] ?? ''));
            }
            $hay .= ' ' . preg_replace('/\D/', '', (string) ($r['ean'] ?? ''));
            foreach ($words as $w) {
                if (!str_contains($hay, $w)) {
                    return false;
                }
            }
            return true;
        }));
    }

    /** Domyślne wartości formularza nadania GTIN: z produktu CRM i z ostatnio zmienianej karty w GS1 (marka, GPC, jednostka). */
    public function formDefaults(array $product): array
    {
        self::migrate($this->pdo);
        $last = $this->pdo->query('SELECT * FROM gs1_products ORDER BY last_modified DESC, gtin DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
        // Kod GPC: najczęstszy prawdziwy wśród Twoich kart (99999999 to tymczasowy „do uzupełnienia”, nie podpowiadamy go).
        $gpc = $this->pdo->query('SELECT gpc_code FROM gs1_products WHERE gpc_code IS NOT NULL AND gpc_code <> ' . self::GPC_TEMPORARY . '
            GROUP BY gpc_code ORDER BY COUNT(*) DESC, MAX(last_modified) DESC LIMIT 1')->fetchColumn();
        $payload = json_decode((string) ($product['payload'] ?? ''), true);
        $desc = trim(html_entity_decode(strip_tags((string) ($product['short_description'] ?: $product['description'] ?? '')), ENT_QUOTES | ENT_HTML5));
        $desc = preg_replace('/\s+/u', ' ', $desc) ?? '';
        return [
            'gtin' => $this->nextFreeGtin() ?? '',
            'brandName' => (string) ($last['brand'] ?? ''),
            'subBrandName' => (string) ($last['sub_brand'] ?? ''),
            'commonName' => (string) ($product['name'] ?? ''),
            'variant' => '',
            'gpcCode' => $gpc !== false ? (string) $gpc : '',
            'netContent' => '',
            'netContentUnit' => (string) ($last['net_content_unit'] ?? 'g'),
            'description' => mb_substr($desc, 0, 4000),
            'productWebsite' => is_array($payload) ? (string) ($payload['permalink'] ?? '') : '',
            'internalSymbol' => (string) ($product['sku'] ?? ''),
            'targetMarket' => 'PL',
        ];
    }

    // ------------------------------------------------------------------ zapis (tylko po kliknięciu)

    /**
     * Tworzy kartę w MojeGS1 dla produktu CRM i wpisuje GTIN jako EAN produktu (opcjonalnie też w WooCommerce).
     * Najpierw sprawdza w GS1, że numer jest wolny - GET 404. Zajętego numeru nie nadpisujemy.
     * @param array<string,string> $f pola formularza
     * @param ?callable(array $product, string $gtin):?string $pushWoo zwraca komunikat błędu albo null
     * @return array{gtin:string,woo:?string,suggestions:list<string>}
     */
    public function assign(int $productId, array $f, ?callable $pushWoo = null): array
    {
        self::migrate($this->pdo);
        $st = $this->pdo->prepare('SELECT * FROM products WHERE id = ?');
        $st->execute([$productId]);
        $product = $st->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            throw new \RuntimeException('Nie ma takiego produktu.');
        }
        if (self::normalize((string) ($product['ean'] ?? '')) !== '') {
            throw new \RuntimeException('Produkt ma już EAN ' . $product['ean'] . '. Nowego numeru nie nadajemy.');
        }
        $gtin = preg_replace('/\D/', '', (string) ($f['gtin'] ?? '')) ?? '';
        if (strlen($gtin) !== 13 || !self::validGtin($gtin)) {
            throw new \RuntimeException('Numer GTIN musi mieć 13 cyfr z poprawną cyfrą kontrolną.');
        }
        $prefix = $this->prefix();
        if ($prefix !== '' && !str_starts_with($gtin, $prefix)) {
            throw new \RuntimeException('Numer ' . $gtin . ' nie zaczyna się od prefiksu firmy ' . $prefix . '.');
        }
        $taken = $this->pdo->prepare("SELECT sku FROM products WHERE TRIM(ean) = ? OR TRIM(ean) = ? LIMIT 1");
        $taken->execute([$gtin, '0' . $gtin]);
        if ($sku = $taken->fetchColumn()) {
            throw new \RuntimeException('Numer ' . $gtin . ' ma już produkt ' . $sku . ' w CRM.');
        }
        $attrs = self::attributes($f);

        $api = $this->api();
        [$st1, $d1] = $api->getProduct($gtin);
        if ($st1 === 200) {
            throw new \RuntimeException('Numer ' . $gtin . ' jest już zajęty w MojeGS1 („' . ($d1['data']['attributes']['name'] ?? '') . '”). Odśwież listę z GS1 i wybierz inny.');
        }
        if ($st1 !== 404) {
            throw new \RuntimeException($api::errorMessage($st1, $d1));
        }
        [$st2, $d2] = $api->putProduct($gtin, $attrs);
        if ($st2 < 200 || $st2 >= 300) {
            throw new \RuntimeException($api::errorMessage($st2, $d2));
        }
        $suggestions = array_values(array_map('strval', (array) ($d2['qualityDetails']['suggestions'] ?? [])));

        $this->pdo->prepare('UPDATE products SET ean = ? WHERE id = ?')->execute([$gtin, $productId]);
        $now = date('Y-m-d H:i:s');
        $this->pdo->prepare('DELETE FROM gs1_products WHERE gtin = ?')->execute([$gtin]);
        $this->pdo->prepare('INSERT INTO gs1_products (gtin, name, brand, sub_brand, common_name, variant, internal_symbol,
                status, gpc_code, net_content, net_content_unit, image, website, suggestions, last_modified, payload, synced_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute(self::rowValues($gtin, ['id' => $gtin, 'attributes' => $attrs + ['qualityDetails' => ['suggestions' => $suggestions],
                'lastModificationDate' => gmdate('Y-m-d\TH:i:s\Z')]], $now));

        $wooMsg = null;
        if ($pushWoo !== null) {
            $product['ean'] = $gtin;
            $wooMsg = $pushWoo($product, $gtin);
        }
        return ['gtin' => $gtin, 'woo' => $wooMsg, 'suggestions' => $suggestions];
    }

    /**
     * Wpisuje GTIN jako EAN istniejącego produktu CRM (np. karta z GS1 z symbolem = SKU, a w CRM bez EAN).
     * @param ?callable(array $product, string $gtin):?string $pushWoo
     * @return ?string komunikat błędu Woo albo null
     */
    public function linkEan(int $productId, string $gtin, ?callable $pushWoo = null): ?string
    {
        $gtin = self::normalize($gtin);
        $card = $this->cardFor($gtin);
        if ($card === null) {
            throw new \RuntimeException('Nie ma karty ' . $gtin . ' w pobranej liście z GS1.');
        }
        $st = $this->pdo->prepare('SELECT * FROM products WHERE id = ?');
        $st->execute([$productId]);
        $product = $st->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            throw new \RuntimeException('Nie ma takiego produktu.');
        }
        if (self::normalize((string) ($product['ean'] ?? '')) !== '') {
            throw new \RuntimeException('Produkt ' . $product['sku'] . ' ma już EAN ' . $product['ean'] . '.');
        }
        $taken = $this->pdo->prepare("SELECT sku FROM products WHERE TRIM(ean) = ? OR TRIM(ean) = ? LIMIT 1");
        $taken->execute([$gtin, '0' . $gtin]);
        if ($sku = $taken->fetchColumn()) {
            throw new \RuntimeException('Numer ' . $gtin . ' ma już produkt ' . $sku . '.');
        }
        $this->pdo->prepare('UPDATE products SET ean = ? WHERE id = ?')->execute([$gtin, $productId]);
        $product['ean'] = $gtin;
        return $pushWoo !== null ? $pushWoo($product, $gtin) : null;
    }

    /**
     * Wysyłka EAN do sklepu WooCommerce, z którego pochodzi produkt (pole global_unique_id).
     * Bez tego kolejny import z Woo i tak nie skasuje numeru (COALESCE w ProductRepository::upsert),
     * ale sklep i Allegro (przez Woo) by go nie znały.
     * @return callable(array,string):?string
     */
    public static function wooPusher(PDO $pdo): callable
    {
        return static function (array $product, string $gtin) use ($pdo): ?string {
            $wooId = (int) ($product['woo_product_id'] ?? 0);
            $integ = (int) ($product['source_integration_id'] ?? 0);
            if ($wooId <= 0 || $integ <= 0) {
                return 'produkt nie jest powiązany ze sklepem WooCommerce';
            }
            $acc = (new \Pase\Repository\IntegrationAccountRepository($pdo))->find($integ);
            if ($acc === null) {
                return 'brak integracji sklepu';
            }
            $client = IntegrationClient::for('woocommerce', $acc['config'] ?? []);
            if ($client === null) {
                return 'wtyczka WooCommerce jest wyłączona';
            }
            $var = (int) ($product['woo_variation_id'] ?? 0);
            return $client->updateGlobalUniqueId($wooId, $var > 0 ? $var : null, $gtin);
        };
    }

    /** Atrybuty karty dla PUT /products/{gtin} z pól formularza + walidacja zgodna z ograniczeniami MojeGS1. */
    public static function attributes(array $f): array
    {
        $t = static fn(string $k): string => trim((string) ($f[$k] ?? ''));
        $errors = [];
        if (mb_strlen($t('brandName')) < 2) { $errors[] = 'Marka: co najmniej 2 znaki.'; }
        if ($t('subBrandName') !== '' && mb_strlen($t('subBrandName')) < 2) { $errors[] = 'Podmarka: co najmniej 2 znaki albo puste.'; }
        if ($t('commonName') === '' || mb_strlen($t('commonName')) > 150) { $errors[] = 'Nazwa zwyczajowa: wymagana, do 150 znaków.'; }
        if ($t('variant') !== '' && (mb_strlen($t('variant')) < 2 || mb_strlen($t('variant')) > 70)) { $errors[] = 'Wariant: 2–70 znaków albo puste.'; }
        if (!preg_match('/^\d{8}$/', $t('gpcCode'))) { $errors[] = 'Kod GPC: 8 cyfr (kategoria GS1, np. z innej Twojej karty w MojeGS1).'; }
        elseif ((int) $t('gpcCode') === self::GPC_TEMPORARY) { $errors[] = 'Kod GPC 99999999 to kategoria tymczasowa - wybierz właściwą (wyszukiwarka: https://gpc-browser.gs1.org).'; }
        $net = str_replace(',', '.', $t('netContent'));
        if (!is_numeric($net) || (float) $net <= 0) { $errors[] = 'Zawartość netto: liczba większa od zera.'; }
        if (!isset(self::UNITS[$t('netContentUnit')])) { $errors[] = 'Jednostka zawartości: wybierz z listy.'; }
        if ($t('description') !== '' && (mb_strlen($t('description')) < 20 || mb_strlen($t('description')) > 4000)) { $errors[] = 'Opis: 20–4000 znaków albo puste.'; }
        if ($t('productWebsite') !== '' && !preg_match('~^https?://~i', $t('productWebsite'))) { $errors[] = 'Strona produktu: adres zaczynający się od http:// lub https://.'; }
        if ($t('internalSymbol') !== '' && (mb_strlen($t('internalSymbol')) < 2 || mb_strlen($t('internalSymbol')) > 255)) { $errors[] = 'Symbol wewnętrzny: 2–255 znaków.'; }
        if ($errors) {
            throw new \RuntimeException(implode(' ', $errors));
        }
        $markets = array_values(array_filter(array_map(static fn($m) => strtoupper(trim($m)), explode(',', $t('targetMarket') ?: 'PL'))));
        $attrs = [
            'brandName' => $t('brandName'),
            'subBrandName' => $t('subBrandName') !== '' ? $t('subBrandName') : null,
            'commonName' => $t('commonName'),
            'variant' => $t('variant') !== '' ? $t('variant') : null,
            'gpcCode' => (int) $t('gpcCode'),
            'netContent' => (float) $net,
            'netContentUnit' => $t('netContentUnit'),
            'description' => $t('description') !== '' ? $t('description') : null,
            'descriptionLanguage' => 'pl',
            'productWebsite' => $t('productWebsite') !== '' ? $t('productWebsite') : null,
            'internalSymbol' => $t('internalSymbol') !== '' ? $t('internalSymbol') : null,
            'targetMarket' => $markets ?: ['PL'],
            'status' => 'ACT',
        ];
        return array_filter($attrs, static fn($v) => $v !== null);
    }
}
