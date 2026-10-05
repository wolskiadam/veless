<?php
declare(strict_types=1);

namespace Pase\Handlers;

use Pase\Repository\ProductRepository;
use Pase\Services\WooCustomFields;
use Pase\Support\Logger;
use Pase\Support\Units;

/**
 * Import produktu z WooCommerce do magazynu PASE (tabela products).
 * Wywoływany przez Worker dla zadań typu 'woo.product.import'.
 *
 * Produkty BEZ SKU są pomijane - SKU to klucz mastera (bez niego nie da się
 * powiązać produktu między kanałami).
 */
final class WooProductImportHandler
{
    public function __construct(
        private readonly ProductRepository $repo,
        private readonly ?WooCustomFields $customFields = null,   // pola własne ze sklepu (meta_data)
    ) {}

    /**
     * Payload wariantu Woo gotowy do handle(): warianty nie mają własnej nazwy, kategorii
     * ani krótkiego opisu - dziedziczą po rodzicu; opis pełny wariantu, gdy pusty, też
     * bierzemy z rodzica. Atrybuty {name, option} -> {name, options:[...]}.
     */
    /**
     * Jednostki sklepu do przeliczenia wagi/wymiarów: ręczne ustawienie integracji
     * (weight_unit / dimension_unit) ma pierwszeństwo, potem odczyt z API sklepu.
     * @return array{0:string,1:string} [jednostka wagi, jednostka wymiarów]
     */
    public static function shopUnits(object $wooClient, array $config): array
    {
        $w = (string) ($config['weight_unit'] ?? '');
        $d = (string) ($config['dimension_unit'] ?? '');
        if (($w === '' || $w === 'auto' || $d === '' || $d === 'auto') && method_exists($wooClient, 'storeUnits')) {
            $u = $wooClient->storeUnits();
            if ($w === '' || $w === 'auto') { $w = $u['weight'] ?? 'kg'; }
            if ($d === '' || $d === 'auto') { $d = $u['dimension'] ?? 'cm'; }
        }
        return [$w ?: 'kg', $d ?: 'cm'];
    }

    public static function variationPayload(array $variation, array $parent, int $integrationId): array
    {
        $variation['name']              = $parent['name'] ?? null;
        $variation['short_description'] = $parent['short_description'] ?? null;
        $variation['description']       = trim(strip_tags((string) ($variation['description'] ?? ''))) !== ''
            ? $variation['description']
            : ($parent['description'] ?? null);
        $variation['categories']        = $parent['categories'] ?? [];
        $variation['images'] = !empty($variation['image']['src'])
            ? [$variation['image']]
            : ($parent['images'] ?? []);
        // Pola własne (meta_data): wariant dziedziczy pola rodzica, własne wartości wariantu wygrywają.
        $meta = [];
        foreach (array_merge($parent['meta_data'] ?? [], $variation['meta_data'] ?? []) as $m) {
            if (isset($m['key'])) {
                $meta[(string) $m['key']] = $m;
            }
        }
        $variation['meta_data'] = array_values($meta);
        $variation['attributes'] = array_map(
            static fn($a) => ['name' => $a['name'] ?? '', 'options' => isset($a['option']) && $a['option'] !== '' ? [$a['option']] : []],
            $variation['attributes'] ?? []
        );
        $variation['__integration_id'] = $integrationId;
        $variation['__woo_parent_id']  = (int) ($parent['id'] ?? 0);
        $variation['__is_variation']   = true;
        return $variation;
    }

    public function handle(array $payload): void
    {
        $sku = trim((string) ($payload['sku'] ?? ''));
        if ($sku === '') {
            Logger::warn('WooProductImport: produkt bez SKU - pomijam', ['id' => $payload['id'] ?? null]);
            return;
        }

        // Zdjęcia: tablica URL-i (Woo: images[].src).
        $images = [];
        foreach ($payload['images'] ?? [] as $img) {
            if (!empty($img['src'])) {
                $images[] = $img['src'];
            }
        }

        // Kategorie: nazwy (Woo: categories[].name).
        $categories = array_values(array_filter(array_map(
            static fn($c) => $c['name'] ?? null,
            $payload['categories'] ?? []
        )));

        // Atrybuty: [nazwa => [wartości]] (Woo: attributes[].name + options[]).
        $attributes = [];
        foreach ($payload['attributes'] ?? [] as $a) {
            if (!empty($a['name'])) {
                $attributes[$a['name']] = $a['options'] ?? [];
            }
        }

        // EAN/GTIN: różne miejsca zależnie od wtyczki. Sprawdzamy po kolei.
        $ean = $payload['global_unique_id'] ?? '';
        if ($ean === '') {
            foreach ($payload['meta_data'] ?? [] as $m) {
                $key = $m['key'] ?? '';
                if (in_array($key, ['_wpm_gtin_code', '_gtin', 'ean', '_ean', 'hwp_product_gtin'], true) && !empty($m['value'])) {
                    $ean = (string) $m['value'];
                    break;
                }
            }
        }

        $dim = $payload['dimensions'] ?? [];
        // Woo podaje wagę/wymiary w jednostkach sklepu (__weight_unit/__dim_unit dokłada import) -
        // w magazynie trzymamy kg i cm.
        $wUnit = $payload['__weight_unit'] ?? 'kg';
        $dUnit = $payload['__dim_unit'] ?? 'cm';
        $num = static fn($v) => ($v ?? '') !== '' ? (float) $v : null;

        $fields = [
            'name'             => $payload['name'] ?? null,
            'description'       => $payload['description'] ?? null,
            'short_description' => $payload['short_description'] ?? null,
            'images'            => $images,
            'categories'        => $categories,
            'attributes'        => $attributes,
            'ean'              => $ean !== '' ? $ean : null,
            'weight'           => Units::toKg($num($payload['weight'] ?? null), $wUnit),
            'length'           => Units::toCm($num($dim['length'] ?? null), $dUnit),
            'width'            => Units::toCm($num($dim['width'] ?? null), $dUnit),
            'height'           => Units::toCm($num($dim['height'] ?? null), $dUnit),
            'stock'            => isset($payload['stock_quantity']) && $payload['stock_quantity'] !== null
                                    ? (int) $payload['stock_quantity'] : null,
            'price'            => isset($payload['price']) && $payload['price'] !== ''
                                    ? (float) $payload['price'] : null,
            'integration_id'   => isset($payload['__integration_id']) ? (int) $payload['__integration_id'] : null,
            // Wariant Woo (patrz import_products.php): woo_product_id to WSPÓLNY id rodzica
            // (do grupowania w products.php), woo_variation_id to własny id wariantu.
            // Zwykły produkt: woo_product_id to jego własny id, wariantu brak.
            'woo_product_id'   => !empty($payload['__is_variation'])
                                    ? ((int) ($payload['__woo_parent_id'] ?? 0) ?: null)
                                    : ((int) ($payload['id'] ?? 0) ?: null),
            'woo_variation_id' => !empty($payload['__is_variation']) ? ((int) ($payload['id'] ?? 0) ?: null) : null,
        ];

        // Tryby „powiąż” (import_products.php): istniejący produkt tylko wiążemy ze sklepem,
        // bez nadpisywania jego danych. 'link_create' dodatkowo tworzy brakujące.
        $mode = (string) ($payload['__import_mode'] ?? '');
        if (in_array($mode, ['link', 'link_create'], true)) {
            $res = $this->repo->linkToShop($sku, (int) ($fields['integration_id'] ?? 0), $fields['woo_product_id'], $fields['woo_variation_id']);
            if ($res === 'linked') {
                Logger::info("WooProductImport: powiązano produkt SKU {$sku} ze sklepem #{$fields['integration_id']}");
                return;
            }
            if ($res === 'other_shop') {
                Logger::warn("WooProductImport: SKU {$sku} jest już powiązany z innym sklepem - pomijam powiązanie");
                return;
            }
            if ($mode === 'link') {
                return; // brak w magazynie - w trybie „tylko powiąż” nie tworzymy
            }
        }

        $isNew = $this->repo->upsert($sku, $fields, $payload);
        $this->customFields?->applyFromPayload($sku, $payload);
        Logger::info(
            'WooProductImport: ' . ($isNew ? 'dodano' : 'zaktualizowano') . " produkt SKU {$sku}",
            ['name' => $fields['name'], 'stock' => $fields['stock'], 'price' => $fields['price']]
        );
    }
}
