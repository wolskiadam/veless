<?php
declare(strict_types=1);

namespace Pase\Services;

/**
 * Tagi/placeholdery dostępne w szablonach opisu Allegro (jak „Tagi Base.com").
 *
 * Edytor szablonu pokazuje listę tagów do kliknięcia; przy przygotowaniu oferty
 * tagi [tag] są zamieniane na dane konkretnego produktu (resolve()).
 *
 * Jedno źródło prawdy dla obu stron (edytor + render), żeby lista się nie rozjechała.
 */
final class AllegroOfferTags
{
    /** @return array<string,string> tag (bez nawiasów) => etykieta/opis do UI */
    public static function catalog(): array
    {
        return [
            'nazwa'       => 'Nazwa produktu',
            'opis'        => 'Opis pełny — tylko sekcje włączone na Allegro',
            'opis_caly'   => 'Opis pełny — cały, jak w sklepie',
            'opis_krotki' => 'Opis krótki',
            'cena'        => 'Cena (CRM)',
            'sku'         => 'SKU',
            'ean'         => 'EAN / GTIN',
            'kategoria'   => 'Kategoria',
            'waga'        => 'Waga (kg)',
            'wysokosc'    => 'Wysokość (cm)',
            'szerokosc'   => 'Szerokość (cm)',
            'dlugosc'     => 'Długość (cm)',
            'id_produktu' => 'ID produktu (Woo)',
        ];
    }

    /**
     * Zamienia tagi [tag] w treści na wartości z rekordu produktu (tabela products).
     * Nieznane tagi zostawia bez zmian (nie psuje tekstu).
     *
     * @param array<string,mixed> $product wiersz produktu
     */
    public static function resolve(string $text, array $product): string
    {
        $cats = json_decode((string) ($product['categories'] ?? '[]'), true) ?: [];

        $map = [
            'nazwa'       => (string) ($product['name'] ?? ''),
            'opis'        => \Pase\Support\DescriptionSections::allegroHtml($product, \Pase\Support\DescriptionSections::defaultExcluded()),
            'opis_caly'   => (string) ($product['description'] ?? ''),
            'opis_krotki' => (string) ($product['short_description'] ?? ''),
            'cena'        => $product['pase_price'] !== null ? (string) $product['pase_price'] : '',
            'sku'         => (string) ($product['sku'] ?? ''),
            'ean'         => (string) ($product['ean'] ?? ''),
            'kategoria'   => is_array($cats) ? (string) ($cats[0] ?? '') : '',
            'waga'        => $product['weight'] !== null ? \Pase\Support\Units::num($product['weight'], 3) : '',
            'wysokosc'    => $product['height'] !== null ? (string) $product['height'] : '',
            'szerokosc'   => $product['width'] !== null ? (string) $product['width'] : '',
            'dlugosc'     => $product['length'] !== null ? (string) $product['length'] : '',
            'id_produktu' => $product['woo_product_id'] !== null ? (string) $product['woo_product_id'] : '',
        ];

        return preg_replace_callback('/\[([a-z0-9_]+)\]/i', static function ($m) use ($map) {
            $key = strtolower($m[1]);
            return array_key_exists($key, $map) ? $map[$key] : $m[0];
        }, $text) ?? $text;
    }
}
