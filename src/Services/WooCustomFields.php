<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;

/**
 * Własne pola produktu pobierane ze sklepu WooCommerce.
 *
 * Woo REST API oddaje dodatkowe pola produktu (ACF, JetEngine, „Własne pola” WordPressa,
 * pola wtyczek) w tablicy meta_data [{key, value}]. Pole CRM (product_fields) może mieć
 * przypisany klucz z Woo (woo_meta_key) - wtedy przy każdym imporcie/odświeżeniu produktu
 * wartość trafia do products.custom_fields pod kluczem pola CRM. Do sklepu nic nie wysyłamy.
 *
 * Pole typu „image” (Zdjęcie): wtyczki pól zapisują zwykle samo ID załącznika - zamieniamy je
 * na link do pliku (WordPress /wp/v2/media), zapamiętany w woo_media, żeby nie pytać sklepu
 * przy każdym odświeżeniu.
 */
final class WooCustomFields
{
    /** @param null|callable(int $integrationId, int $mediaId): ?string $mediaUrl */
    public function __construct(private readonly PDO $pdo, private $mediaUrl = null) {}

    /** Ile zdjęć wolno jeszcze dociągnąć ze sklepu (null = bez limitu; limit przy uzupełnianiu z panelu). */
    public ?int $lookupBudget = null;

    /** Z pobieraniem linków do zdjęć ze sklepu, z którego przyszedł produkt. */
    public static function withShopMedia(PDO $pdo): self
    {
        $clients = [];
        return new self($pdo, static function (int $integrationId, int $mediaId) use ($pdo, &$clients): ?string {
            if (!array_key_exists($integrationId, $clients)) {
                $acc = (new \Pase\Repository\IntegrationAccountRepository($pdo))->find($integrationId);
                $clients[$integrationId] = ($acc['type'] ?? '') === 'woocommerce'
                    ? \Pase\Plugin\IntegrationClient::for('woocommerce', $acc['config'] ?? []) : null;
            }
            $c = $clients[$integrationId];
            return $c !== null && method_exists($c, 'mediaUrl') ? $c->mediaUrl($mediaId) : null;
        });
    }

    public static function migrate(PDO $pdo): void
    {
        if (!self::columnExists($pdo, 'product_fields', 'woo_meta_key')) {
            $pdo->exec('ALTER TABLE product_fields ADD COLUMN woo_meta_key VARCHAR(191) NULL');
        }
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        if (!$sqlite) {
            // Typ pola był ENUM bez 'image' - poszerzamy do tekstu (nowe typy bez kolejnych migracji).
            $t = $pdo->query("SELECT DATA_TYPE FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_fields' AND COLUMN_NAME = 'type'")->fetchColumn();
            if ($t === 'enum') {
                $pdo->exec("ALTER TABLE product_fields MODIFY `type` VARCHAR(16) NOT NULL DEFAULT 'text'");
            }
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS woo_media (
            integration_id INT NOT NULL, media_id BIGINT NOT NULL, url VARCHAR(1024) NOT NULL,
            PRIMARY KEY (integration_id, media_id))');
    }

    /**
     * meta_data z Woo -> [klucz => wartość tekstowa]. Pomija puste wartości i techniczne
     * odnośniki ACF („_nazwa” => „field_abc123”), które nie niosą treści.
     * @return array<string,string>
     */
    public static function metaValues(array $metaData): array
    {
        $out = [];
        foreach ($metaData as $m) {
            $key = is_array($m) ? (string) ($m['key'] ?? '') : '';
            if ($key === '' || !array_key_exists('value', $m)) {
                continue;
            }
            $val = self::text($m['value']);
            if ($val === '' || (str_starts_with($key, '_') && preg_match('/^field_[a-z0-9]+$/i', $val))) {
                continue;
            }
            $out[$key] = $val;
        }
        return $out;
    }

    /** Wartość meta jako tekst: lista prostych wartości -> „a, b”, reszta -> JSON. */
    public static function text(mixed $v): string
    {
        if ($v === null || is_bool($v)) {
            return $v ? '1' : '';
        }
        if (is_scalar($v)) {
            return trim((string) $v);
        }
        if (is_array($v) && array_is_list($v) && array_filter($v, static fn($x) => !is_scalar($x) && $x !== null) === []) {
            return implode(', ', array_filter(array_map(static fn($x) => trim((string) $x), $v), static fn($x) => $x !== ''));
        }
        return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string,string> [klucz Woo => klucz pola CRM] */
    public function mapping(): array
    {
        return array_map(static fn($f) => $f['field'], $this->fields());
    }

    /** @return array<string,array{field:string,type:string}> [klucz Woo => pole CRM i jego typ] */
    public function fields(): array
    {
        try {
            $rows = $this->pdo->query("SELECT woo_meta_key, field_key, type FROM product_fields
                WHERE woo_meta_key IS NOT NULL AND woo_meta_key <> ''")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];   // brak kolumny przed migracją
        }
        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['woo_meta_key']] = ['field' => (string) $r['field_key'], 'type' => (string) $r['type']];
        }
        return $map;
    }

    /**
     * Wartość pola „Zdjęcie” -> link. Wtyczki zapisują ID załącznika („1234”), czasem link
     * albo obiekt {url, ...}. Gdy linku nie da się ustalić, zostaje ID (strona pokaże „zdjęcie #1234”).
     */
    public function imageUrl(string $value, int $integrationId): string
    {
        if (str_starts_with($value, '{')) {
            $o = json_decode($value, true);
            $value = (string) ($o['url'] ?? $o['source_url'] ?? $o['id'] ?? $o['ID'] ?? $value);
        }
        if (!ctype_digit($value) || $integrationId <= 0) {
            return $value;
        }
        $mediaId = (int) $value;
        $stmt = $this->pdo->prepare('SELECT url FROM woo_media WHERE integration_id = ? AND media_id = ?');
        try {
            $stmt->execute([$integrationId, $mediaId]);
            $cached = $stmt->fetchColumn();
        } catch (\PDOException $e) {
            $cached = false;
        }
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        if ($this->mediaUrl === null || $this->lookupBudget === 0) {
            return $value;   // resztę uzupełni cykliczne odświeżenie produktów
        }
        if ($this->lookupBudget !== null) {
            $this->lookupBudget--;
        }
        $url = ($this->mediaUrl)($integrationId, $mediaId);
        if ($url === null) {
            return $value;
        }
        try {
            $this->pdo->prepare('INSERT INTO woo_media (integration_id, media_id, url) VALUES (?, ?, ?)')
                ->execute([$integrationId, $mediaId, $url]);
        } catch (\PDOException $e) {
            // równoległy zapis tego samego zdjęcia - bez znaczenia
        }
        return $url;
    }

    /**
     * Przepisuje wartości pól ze sklepu do produktu. Pole puste w sklepie czyści wartość w CRM;
     * pola wpisywane ręcznie (bez klucza Woo) zostają nietknięte.
     * @return int ile pól ze sklepu ma wartość
     */
    public function applyToProduct(int $productId, array $metaData, ?array $map = null, int $integrationId = 0): int
    {
        $map ??= $this->fields();
        if ($map === []) {
            return 0;
        }
        $stmt = $this->pdo->prepare('SELECT custom_fields FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $cur = $stmt->fetchColumn();
        if ($cur === false) {
            return 0;
        }
        $vals = json_decode((string) ($cur ?? ''), true) ?: [];
        $meta = self::metaValues($metaData);
        $filled = 0;
        foreach ($map as $wooKey => $f) {
            $fieldKey = $f['field'];
            if (isset($meta[$wooKey])) {
                $vals[$fieldKey] = $f['type'] === 'image' ? $this->imageUrl($meta[$wooKey], $integrationId) : $meta[$wooKey];
                $filled++;
            } else {
                unset($vals[$fieldKey]);
            }
        }
        $new = $vals === [] ? null : json_encode($vals, JSON_UNESCAPED_UNICODE);
        if ($new !== $cur) {
            $this->pdo->prepare('UPDATE products SET custom_fields = ? WHERE id = ?')->execute([$new, $productId]);
        }
        return $filled;
    }

    /** Po imporcie produktu z Woo (payload = odpowiedź API + pola __*). */
    public function applyFromPayload(string $sku, array $payload): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM products WHERE sku = ?');
        $stmt->execute([$sku]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            $this->applyToProduct((int) $id, (array) ($payload['meta_data'] ?? []), null, (int) ($payload['__integration_id'] ?? 0));
        }
    }

    /**
     * Uzupełnia pola ze sklepu we wszystkich produktach z ostatnio pobranych danych
     * (products.payload) - bez odpytywania sklepu. Wołane po dodaniu/zmianie powiązania pola.
     * @return int ile produktów ma teraz jakąś wartość ze sklepu
     */
    public function backfill(): int
    {
        $map = $this->fields();
        if ($map === []) {
            return 0;
        }
        $this->lookupBudget ??= 40;   // strona panelu nie może wisieć na setkach zapytań o zdjęcia
        $n = 0;
        foreach ($this->pdo->query("SELECT id, payload, source_integration_id FROM products WHERE payload IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $payload = json_decode((string) $r['payload'], true);
            $intId = (int) ($r['source_integration_id'] ?? 0) ?: (int) ($payload['__integration_id'] ?? 0);
            if (is_array($payload) && $this->applyToProduct((int) $r['id'], (array) ($payload['meta_data'] ?? []), $map, $intId) > 0) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Pola znalezione w danych pobranych ze sklepu (products.payload.meta_data), najczęstsze
     * najpierw. technical = klucz od „_” (zwykle dane wtyczek, nie opis produktu).
     * @return array<int,array{key:string,count:int,sample:string,sample_sku:string,technical:bool,field:?string}>
     */
    public function discover(): array
    {
        $map = $this->mapping();
        $found = [];
        foreach ($this->pdo->query("SELECT sku, payload FROM products WHERE payload IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $payload = json_decode((string) $r['payload'], true);
            if (!is_array($payload)) {
                continue;
            }
            foreach (self::metaValues((array) ($payload['meta_data'] ?? [])) as $k => $v) {
                if (!isset($found[$k])) {
                    $found[$k] = ['key' => $k, 'count' => 0, 'sample' => $v, 'sample_sku' => (string) $r['sku'],
                        'technical' => str_starts_with($k, '_'), 'field' => $map[$k] ?? null];
                }
                $found[$k]['count']++;
            }
        }
        $out = array_values($found);
        usort($out, static fn($a, $b) => [$a['technical'], -$a['count'], $a['key']] <=> [$b['technical'], -$b['count'], $b['key']]);
        return $out;
    }

    /**
     * Czy pole wygląda na zdjęcie: link do pliku graficznego albo liczba (ID z biblioteki mediów)
     * w polu, którego nazwa mówi o obrazku - same liczby to zwykle oceny SEO, dni dostawy itp.
     */
    public static function looksLikeImage(string $sample, string $key = ''): bool
    {
        if (preg_match('~^https?://\S+\.(jpe?g|png|webp|gif|avif)(\?\S*)?$~i', $sample)) {
            return true;
        }
        return ctype_digit($sample) && strlen($sample) <= 10
            && (bool) preg_match('/img|image|photo|foto|zdj|obraz|thumb|picture|pic|media|galler|galeria|logo|icon|ikon|attach|banner|grafik/i', $key);
    }

    /** Czytelna nazwa z klucza: „sklad_surowcowy” -> „Sklad surowcowy”. */
    public static function labelFromKey(string $key): string
    {
        $s = trim(preg_replace('/[_\-]+/', ' ', $key) ?? $key);
        return $s === '' ? $key : mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    private static function columnExists(PDO $pdo, string $table, string $col): bool
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return in_array($col, array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $col]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
