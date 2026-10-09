<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Support\Logger;
use PasePlugin\Allegro\AllegroClient;

/**
 * Miniatury pozycji zamówienia.
 *
 * Kolejność źródeł:
 *   1. 'image' zapisane już w pozycji (np. zdjęcie oferty Allegro pobrane wcześniej),
 *   2. zdjęcie produktu z magazynu CRM (po product_id, potem po SKU),
 *   3. główne zdjęcie oferty Allegro (GET /sale/offers?offer.id=...) - jedno zapytanie
 *      na wszystkie brakujące oferty zamówienia.
 * Zdjęcia z Allegro wołający może zapisać w payloadzie (fetchedFromAllegro), żeby nie
 * pytać API przy każdym otwarciu zamówienia.
 * Wariant z magazynu dostaje dodatkowo główne zdjęcie produktu głównego (parentThumbs),
 * gdy różni się od zdjęcia wariantu - inaczej warianty np. świec wyglądają identycznie.
 */
final class OrderItemThumbnails
{
    /** @var array<int,string> indeks pozycji => URL zdjęcia pobranego właśnie z Allegro */
    public array $fetchedFromAllegro = [];

    /** @var array<int,string> indeks pozycji => URL głównego zdjęcia produktu głównego (warianty) */
    public array $parentThumbs = [];

    /**
     * @param ?VariantParentImages $parents gdy podane, brakujące zdjęcia rodzica dociągamy ze sklepu
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?AllegroClient $allegro = null,
        private readonly ?VariantParentImages $parents = null
    ) {}

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,?string> indeks pozycji => URL miniatury (null = brak)
     */
    public function forItems(array $items): array
    {
        $thumbs = [];
        $missingOffers = [];
        $this->parentThumbs = [];

        $cols  = 'images';
        $withParents = LowStock::columnExists($this->pdo, 'products', 'parent_images');
        if ($withParents) {
            $cols = 'id, images, parent_images, source_integration_id, woo_product_id, woo_variation_id';
        }
        $byId  = $this->pdo->prepare("SELECT {$cols} FROM products WHERE id = ? LIMIT 1");
        $bySku = $this->pdo->prepare("SELECT {$cols} FROM products WHERE sku = ? LIMIT 1");
        /** @var array<int,array<string,mixed>> $rows indeks pozycji => wiersz products wariantu (po SKU) */
        $rows = [];

        foreach ($items as $i => $it) {
            $url = is_string($it['image'] ?? null) && $it['image'] !== '' ? $it['image'] : null;

            // Wiersz po SKU (pewny także dla WooCommerce, gdzie product_id to id ze sklepu, nie z CRM).
            $skuRow = null;
            if (!empty($it['sku'])) {
                $bySku->execute([(string) $it['sku']]);
                $skuRow = $bySku->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if ($url === null && !empty($it['product_id'])) {
                $byId->execute([(int) $it['product_id']]);
                $url = self::firstImage(($byId->fetch(PDO::FETCH_ASSOC) ?: [])['images'] ?? null);
            }
            if ($url === null && $skuRow !== null) {
                $url = self::firstImage($skuRow['images'] ?? null);
            }
            if ($withParents && $skuRow !== null && !empty($skuRow['woo_variation_id'])) {
                $rows[$i] = $skuRow;
            }
            if ($url === null && !empty($it['allegro_offer_id'])) {
                $missingOffers[$i] = (string) $it['allegro_offer_id'];
            }
            $thumbs[$i] = $url;
        }

        if ($missingOffers !== [] && $this->allegro !== null) {
            $fromAllegro = $this->allegroImages(array_values(array_unique($missingOffers)));
            foreach ($missingOffers as $i => $offerId) {
                if (isset($fromAllegro[$offerId])) {
                    $thumbs[$i] = $fromAllegro[$offerId];
                    $this->fetchedFromAllegro[$i] = $fromAllegro[$offerId];
                }
            }
        }

        if ($rows !== []) {
            $filled = [];
            if ($this->parents !== null) {
                try {
                    $filled = $this->parents->backfill(array_values($rows), 3);
                } catch (\Throwable $e) {
                    Logger::warn('Miniatury: zdjęcia produktu głównego - ' . $e->getMessage());
                }
            }
            foreach ($rows as $i => $row) {
                $parent = $filled[(int) $row['id']] ?? VariantParentImages::decode($row['parent_images'] ?? null);
                $pt = VariantParentImages::parentThumb($thumbs[$i] ?? null, $parent);
                if ($pt !== null) {
                    $this->parentThumbs[$i] = $pt;
                }
            }
        }

        return $thumbs;
    }

    /** @return array<string,string> offerId => URL głównego zdjęcia */
    private function allegroImages(array $offerIds): array
    {
        $out = [];
        try {
            $filters = ['limit' => min(1000, count($offerIds))];
            // /sale/offers przyjmuje offer.id wielokrotnie: ?offer.id=1&offer.id=2
            $query = http_build_query($filters) . '&' . implode('&', array_map(
                static fn($id) => 'offer.id=' . rawurlencode($id),
                $offerIds
            ));
            $res = $this->allegro->listOffersRaw($query);
            foreach ($res as $offer) {
                $url = $offer['primaryImage']['url'] ?? null;
                if (is_string($url) && $url !== '') {
                    $out[(string) $offer['id']] = $url;
                }
            }
        } catch (\Throwable $e) {
            Logger::warn('Miniatury: nie udało się pobrać zdjęć ofert Allegro - ' . $e->getMessage());
        }
        return $out;
    }

    private static function firstImage(mixed $json): ?string
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $imgs = json_decode($json, true);
        if (!is_array($imgs) || $imgs === []) {
            return null;
        }
        $first = is_array($imgs[0] ?? null) ? ($imgs[0]['src'] ?? null) : ($imgs[0] ?? null);
        return is_string($first) && $first !== '' ? $first : null;
    }
}
