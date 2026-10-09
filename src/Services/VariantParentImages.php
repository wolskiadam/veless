<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Repository\IntegrationAccountRepository;
use Pase\Support\Logger;

/**
 * Zdjęcia produktu głównego przy wariantach z WooCommerce.
 *
 * Wariant ma w sklepie zwykle jedno własne zdjęcie (products.images), z którego trudno poznać,
 * co to za produkt. Zdjęcia rodzica (główne + galeria) trzymamy osobno w products.parent_images
 * (tablica URL-i; [] = rodzic bez zdjęć, NULL = jeszcze nie pobrane), żeby nie zmieniać
 * products.images - z niego biorą zdjęcia oferty Allegro i nic tu nie wraca do sklepu ani na Allegro.
 *
 * Import produktów zapisuje je od razu. Warianty zaimportowane wcześniej uzupełniamy przy
 * otwarciu karty produktu lub zamówienia (backfill) - jedno GET /products/{rodzic} na rodzica,
 * wynik zapisany dla wszystkich jego wariantów.
 */
final class VariantParentImages
{
    /** @var null|\Closure(int):?object klient Woo dla id integracji (testy podstawiają własny) */
    private ?\Closure $clientFor;

    public function __construct(private readonly PDO $pdo, ?callable $clientFor = null)
    {
        $this->clientFor = $clientFor !== null ? \Closure::fromCallable($clientFor) : null;
    }

    public static function migrate(PDO $pdo): void
    {
        if (!LowStock::columnExists($pdo, 'products', 'parent_images')) {
            $pdo->exec('ALTER TABLE products ADD COLUMN parent_images ' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'TEXT' : 'JSON') . ' NULL');
        }
    }

    /** URL-e zdjęć z produktu Woo (images[].src), główne pierwsze. @return string[] */
    public static function fromWoo(array $product): array
    {
        $out = [];
        foreach ($product['images'] ?? [] as $img) {
            $src = is_array($img) ? ($img['src'] ?? null) : $img;
            if (is_string($src) && $src !== '') {
                $out[] = $src;
            }
        }
        return array_values(array_unique($out));
    }

    /** Zapisane zdjęcia rodzica; null = nie pobrane (lub produkt nie jest wariantem). @return ?string[] */
    public static function decode(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $v = json_decode($json, true);
        return is_array($v) ? array_values(array_filter($v, static fn($u) => is_string($u) && $u !== '')) : null;
    }

    /**
     * Galeria karty produktu: najpierw własne zdjęcia, potem zdjęcia rodzica, których jeszcze nie ma.
     * @param string[] $own
     * @param ?string[] $parent
     * @return array<int,array{src:string,parent:bool}>
     */
    public static function gallery(array $own, ?array $parent): array
    {
        $out = [];
        $seen = [];
        foreach ([[$own, false], [$parent ?? [], true]] as [$list, $isParent]) {
            foreach ($list as $src) {
                if (!is_string($src) || $src === '' || isset($seen[self::key($src)])) {
                    continue;
                }
                $seen[self::key($src)] = true;
                $out[] = ['src' => $src, 'parent' => $isParent];
            }
        }
        return $out;
    }

    /** Główne zdjęcie rodzica do miniatury zamówienia - tylko gdy różni się od zdjęcia wariantu. */
    public static function parentThumb(?string $thumb, ?array $parent): ?string
    {
        $first = $parent[0] ?? null;
        if (!is_string($first) || $first === '' || ($thumb !== null && self::key($thumb) === self::key($first))) {
            return null;
        }
        return $first;
    }

    /** Zapisuje zdjęcia rodzica dla wszystkich jego wariantów z danego sklepu. @param string[] $urls */
    public function store(int $integrationId, int $wooProductId, array $urls): void
    {
        self::migrate($this->pdo);
        $this->pdo->prepare('UPDATE products SET parent_images = ?
                WHERE source_integration_id = ? AND woo_product_id = ? AND woo_variation_id IS NOT NULL')
            ->execute([json_encode(array_values($urls), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $integrationId, $wooProductId]);
    }

    /**
     * Uzupełnia brakujące zdjęcia rodzica dla podanych wierszy products (warianty z parent_images = NULL).
     * Najwyżej $maxParents zapytań do sklepu na raz, żeby karta/zamówienie nie czekały długo.
     * Zwraca [id produktu => zdjęcia rodzica] dla uzupełnionych wierszy.
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,string[]>
     */
    public function backfill(array $rows, int $maxParents = 5): array
    {
        $todo = [];
        foreach ($rows as $r) {
            $int = (int) ($r['source_integration_id'] ?? 0);
            $woo = (int) ($r['woo_product_id'] ?? 0);
            if (empty($r['woo_variation_id']) || $int <= 0 || $woo <= 0 || self::decode($r['parent_images'] ?? null) !== null) {
                continue;
            }
            $todo["{$int}:{$woo}"][] = (int) ($r['id'] ?? 0);
        }
        $done = [];
        foreach (array_slice($todo, 0, $maxParents, true) as $key => $ids) {
            [$int, $woo] = array_map('intval', explode(':', $key));
            try {
                $client = $this->client($int);
                $parent = $client !== null && method_exists($client, 'fetchProduct') ? $client->fetchProduct($woo) : null;
                if (!is_array($parent)) {
                    continue;   // sklep nie odpowiedział - spróbujemy przy następnym otwarciu
                }
                $urls = self::fromWoo($parent);
                $this->store($int, $woo, $urls);
                foreach ($ids as $id) {
                    $done[$id] = $urls;
                }
            } catch (\Throwable $e) {
                Logger::warn("Zdjęcia produktu głównego: nie udało się pobrać produktu #{$woo} - " . $e->getMessage());
            }
        }
        return $done;
    }

    private function client(int $integrationId): ?object
    {
        if ($this->clientFor !== null) {
            return ($this->clientFor)($integrationId);
        }
        $acc = (new IntegrationAccountRepository($this->pdo))->find($integrationId);
        if ($acc === null || ($acc['type'] ?? '') !== 'woocommerce') {
            return null;
        }
        return \Pase\Plugin\IntegrationClient::for('woocommerce', $acc['config'] ?? []);
    }

    /** Ten sam plik niezależnie od parametrów zapytania i http/https. */
    private static function key(string $url): string
    {
        return preg_replace('~^https?:~i', '', strtok($url, '?') ?: $url) ?? $url;
    }
}
