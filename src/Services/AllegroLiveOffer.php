<?php
declare(strict_types=1);

namespace Pase\Services;

/**
 * Edycja trwającej oferty Allegro w formularzu wystawiania: dane oferty z GET /sale/product-offers/{id}
 * -> pola formularza, dane formularza -> PATCH (bez zmiany publikacji) i lista zmian do potwierdzenia.
 */
final class AllegroLiveOffer
{
    /**
     * Pola formularza (jak w offer_allegro.php) z pełnych danych oferty. Tylko to, co oferta ma - reszta
     * formularza zostaje bez zmian.
     * @param array<string,mixed> $o oferta z Allegro
     * @param array<int,array<string,mixed>> $categoryParams parametry kategorii (do własnych wartości słownika)
     * @return array<string,mixed>
     */
    public static function formFromOffer(array $o, array $categoryParams = []): array
    {
        $str = static fn($v): string => is_scalar($v) ? trim((string) $v) : '';
        $f = [];
        if (($v = $str($o['name'] ?? '')) !== '') {
            $f['title'] = $v;
        }
        if (($v = $str($o['category']['id'] ?? '')) !== '') {
            $f['category_id'] = preg_replace('/\D/', '', $v) ?? '';
        }
        $mode = (array) ($o['sellingMode'] ?? []);
        if (($mode['format'] ?? '') === 'AUCTION') {
            $f['selling_format'] = 'AUCTION';
            $f['price'] = $str($mode['startingPrice']['amount'] ?? '');
            $f['minimal_price'] = $str($mode['minimalPrice']['amount'] ?? '');
        } elseif (isset($mode['price']['amount'])) {
            $f['selling_format'] = 'BUY_NOW';
            $f['price'] = $str($mode['price']['amount']);
        }
        if (isset($o['stock']['available'])) {
            $f['stock'] = (string) (int) $o['stock']['available'];
            $unit = $str($o['stock']['unit'] ?? '');
            $f['stock_unit'] = isset(AllegroOfferPayload::STOCK_UNITS[$unit]) ? $unit : 'UNIT';
        }
        $images = self::urls((array) ($o['images'] ?? []));
        if ($images !== []) {
            $f['images'] = $images;
        }
        if (isset($o['description']['sections'])) {
            $f['description_sections'] = AllegroOfferPayload::descriptionSections(['description_sections' => (array) $o['description']['sections']]);
        }

        // Parametry oferty i pierwszego produktu (produkt oferty jest w Katalogu Allegro - wysyłamy go po id).
        $set = array_values(array_filter((array) ($o['productSet'] ?? []), 'is_array'));
        $first = $set[0] ?? [];
        $byId = [];
        foreach ($categoryParams as $cp) {
            $byId[(string) ($cp['id'] ?? '')] = $cp;
        }
        $params = $custom = [];
        foreach (array_merge((array) ($first['product']['parameters'] ?? []), (array) ($o['parameters'] ?? [])) as $par) {
            $pid = is_array($par) ? $str($par['id'] ?? '') : '';
            if ($pid === '') {
                continue;
            }
            if (isset($par['rangeValue']) && is_array($par['rangeValue'])) {
                $params[$pid] = ['from' => $str($par['rangeValue']['from'] ?? ''), 'to' => $str($par['rangeValue']['to'] ?? '')];
                continue;
            }
            $ids = array_values(array_filter(array_map($str, (array) ($par['valuesIds'] ?? [])), static fn($x) => $x !== ''));
            $vals = array_values(array_filter(array_map($str, (array) ($par['values'] ?? [])), static fn($x) => $x !== ''));
            $amb = $str($byId[$pid]['options']['ambiguousValueId'] ?? '');
            if ($ids !== [] && $amb !== '' && in_array($amb, $ids, true) && $vals !== []) {
                $custom[$pid] = $vals[0];              // własna wartość słownika (np. marka spoza listy)
                continue;
            }
            $list = $ids !== [] ? $ids : $vals;
            if ($list !== []) {
                $params[$pid] = count($list) === 1 ? $list[0] : $list;
            }
        }
        if ($params !== [] || $custom !== []) {
            $f['params'] = $params;
            $f['params_custom'] = $custom;
            $ean = $params[AllegroOfferPayload::EAN_PARAMETER_ID] ?? null;
            if (is_string($ean) && $ean !== '') {
                $f['ean'] = $ean;
            }
        }
        if (($pid = $str($first['product']['id'] ?? '')) !== '') {
            $f['catalog_product_id'] = $pid;
            $f['catalog_product_name'] = $str($first['product']['name'] ?? '') ?: ($f['title'] ?? '');
        }
        $qty = (int) ($first['quantity']['value'] ?? 1);
        $f['set_mode'] = $qty > 1 ? 'set' : 'single';
        $f['set_quantity'] = (string) max(2, $qty);
        $f['extra_products'] = [];
        foreach (array_slice($set, 1) as $x) {
            if (($xid = $str($x['product']['id'] ?? '')) !== '') {
                $f['extra_products'][] = ['id' => $xid, 'name' => $str($x['product']['name'] ?? ''), 'quantity' => max(1, (int) ($x['quantity']['value'] ?? 1))];
            }
        }
        if ($first !== []) {
            $f['producer'] = $str($first['responsibleProducer']['id'] ?? '');
            $f['person'] = $str($first['responsiblePerson']['id'] ?? '');
            $si = (array) ($first['safetyInformation'] ?? []);
            if (($si['type'] ?? '') === 'NO_SAFETY_INFORMATION') {
                $f['safety_mode'] = 'none';
            } elseif (($si['type'] ?? '') === 'TEXT') {
                $f['safety_mode'] = 'text';
                $f['safety_text'] = (string) ($si['description'] ?? '');
            }
        }

        if (isset($o['external']['id'])) {
            $f['signature'] = $str($o['external']['id']);
        }
        $del = (array) ($o['delivery'] ?? []);
        foreach (['shipping_rate' => $del['shippingRates']['id'] ?? null, 'handling' => $del['handlingTime'] ?? null] as $k => $v) {
            if ($str($v) !== '') {
                $f[$k] = $str($v);
            }
        }
        $f['delivery_info'] = $str($del['additionalInfo'] ?? '');
        $ship = $str($del['shipmentDate'] ?? '');
        $f['preorder'] = $ship !== '';
        $f['shipment_date'] = $ship !== '' ? substr($ship, 0, 10) : '';
        $after = (array) ($o['afterSalesServices'] ?? []);
        foreach (['return_policy' => 'returnPolicy', 'implied_warranty' => 'impliedWarranty', 'warranty' => 'warranty'] as $k => $src) {
            $f[$k] = $str($after[$src]['id'] ?? '');
        }
        if (isset(AllegroOfferPayload::INVOICES[$str($o['payments']['invoice'] ?? '')])) {
            $f['invoice'] = $str($o['payments']['invoice']);
        }
        $loc = (array) ($o['location'] ?? []);
        if ($loc !== []) {
            $prov = $str($loc['province'] ?? '');
            $f['province'] = isset(AllegroOfferPayload::PROVINCES[$prov]) ? $prov : '';
            $f['city'] = $str($loc['city'] ?? '');
            $f['post_code'] = $str($loc['postCode'] ?? '');
        }
        $pub = (array) ($o['publication'] ?? []);
        $f['duration'] = $str($pub['duration'] ?? '');
        $f['republish'] = !empty($pub['republish']);
        $f['message_mode'] = ($o['messageToSellerSettings']['mode'] ?? '') === 'HIDDEN' ? 'HIDDEN' : 'OPTIONAL';
        $f['buyers'] = !empty($o['b2b']['buyableOnlyByBusiness']) ? 'business' : 'all';
        $f['attachments'] = AllegroOfferPayload::attachments(['attachments' => array_map(
            static fn($a) => is_array($a) ? ['id' => $a['id'] ?? '', 'type' => $a['type'] ?? '', 'name' => $a['name'] ?? ($a['id'] ?? '')] : [],
            (array) ($o['attachments'] ?? []))]);
        $f['additional_services'] = $str($o['additionalServices']['id'] ?? '');
        $f['wholesale_price_list'] = $str($o['discounts']['wholesalePriceList']['id'] ?? '');
        $f['markets'] = [];
        foreach (AllegroOfferPayload::MARKETS as $mk => $_) {
            $f['markets'][$mk] = $str($o['additionalMarketplaces'][$mk]['sellingMode']['price']['amount'] ?? '');
        }
        $tax = (array) ($o['taxSettings'] ?? []);
        if ($tax !== []) {
            $f['tax_subject'] = $str($tax['subject'] ?? '');
            $f['tax_exemption'] = $str($tax['exemption'] ?? '');
            $f['vat'] = [];
            foreach ((array) ($tax['rates'] ?? []) as $r) {
                if (is_array($r) && isset(AllegroOfferPayload::VAT_COUNTRIES[$str($r['countryCode'] ?? '')])) {
                    $f['vat'][$str($r['countryCode'])] = $str($r['rate'] ?? '');
                }
            }
        }
        return $f;
    }

    /** Zbudowana oferta -> PATCH trwającej oferty: z publikacji tylko wznawianie (bez statusu, startu i czasu trwania), bez języka. */
    public static function patchPayload(array $payload): array
    {
        $payload['publication'] = ['republish' => !empty($payload['publication']['republish'])];
        unset($payload['language']);
        // Kod taryfy celnej należy do produktu (Allegro odrzuca go w parametrach oferty przy zmianie oferty,
        // „nie powinien być wskazany w sekcji offer"). Produkt wysyłamy po id, więc jego kod zostaje bez zmian.
        $payload['parameters'] = array_values(array_filter((array) ($payload['parameters'] ?? []),
            static fn($p) => (string) ($p['id'] ?? '') !== AllegroOfferPayload::TARIFF_PARAMETER_ID));
        return $payload;
    }

    /** Pola PATCH i ich nazwy na liście zmian. */
    public const LABELS = [
        'name' => 'Tytuł', 'sellingMode' => 'Cena', 'stock' => 'Liczba sztuk', 'category' => 'Kategoria', 'images' => 'Zdjęcia',
        'description' => 'Opis', 'parameters' => 'Parametry oferty', 'productSet' => 'Produkt i bezpieczeństwo (GPSR)',
        'delivery' => 'Dostawa', 'afterSalesServices' => 'Zwroty i reklamacje', 'payments' => 'Faktura', 'taxSettings' => 'Stawki VAT',
        'location' => 'Lokalizacja', 'external' => 'Sygnatura (SKU)', 'attachments' => 'Załączniki', 'additionalServices' => 'Usługi dodatkowe',
        'discounts' => 'Cennik hurtowy', 'additionalMarketplaces' => 'Rynki zagraniczne', 'messageToSellerSettings' => 'Uwagi do zakupu',
        'b2b' => 'Tylko dla firm', 'publication' => 'Wznawianie oferty',
    ];

    /** Pola formularza (nazwa w POST) => klucze stanu formularza, które z nich powstają (reszta ma tę samą nazwę). */
    private const POST_TO_FORM = ['params' => ['params', 'ean']];

    /**
     * Formularz do aktualizacji trwającej oferty: dane oferty z Allegro ($liveForm) i tylko te pola z formularza,
     * które operator faktycznie zmienił ($changedNames - nazwy pól z przeglądarki). Pole, którego nie ruszano,
     * zostaje takie jak na Allegro, nawet gdy formularz nie umie go pokazać 1:1 (np. cennik dostawy spoza listy).
     * $changedNames === null (formularz nie był wczytany z Allegro) - bierzemy cały formularz.
     */
    public static function editedForm(array $liveForm, array $form, ?array $changedNames): array
    {
        if ($changedNames === null) {
            return $form;
        }
        $out = $liveForm;
        foreach ($changedNames as $name) {
            foreach (self::POST_TO_FORM[(string) $name] ?? [(string) $name] as $key) {
                if (array_key_exists($key, $form)) {
                    $out[$key] = $form[$key];
                }
            }
        }
        return $out;
    }

    /**
     * Porównanie dwóch PATCH-y zbudowanych tą samą drogą: z oferty wczytanej z Allegro ($base) i z formularza ($new).
     * Wysyłamy tylko pola, które się różnią - reszta oferty zostaje na Allegro nietknięta.
     * @return array{patch:array<string,mixed>,changes:string[]}
     */
    public static function compare(array $base, array $new): array
    {
        $money = static fn($v): string => number_format((float) $v, 2, ',', ' ') . ' zł';
        $price = static fn(array $p): string => (string) ($p['sellingMode']['price']['amount'] ?? $p['sellingMode']['startingPrice']['amount'] ?? '');
        $patch = $changes = [];
        foreach ($new as $key => $value) {
            if (json_encode(self::ksorted($value)) === json_encode(self::ksorted($base[$key] ?? null))) {
                continue;
            }
            $patch[$key] = $value;
            $label = self::LABELS[$key] ?? $key;
            $changes[] = match ($key) {
                'name'        => $label . ': ' . ($base['name'] ?? '') . ' → ' . $value,
                'sellingMode' => $label . ': ' . $money($price($base)) . ' → ' . $money($price($new)),
                'stock'       => $label . ': ' . (int) ($base['stock']['available'] ?? 0) . ' → ' . (int) ($value['available'] ?? 0),
                'images'      => $label . ': ' . count((array) ($base['images'] ?? [])) . ' → ' . count((array) $value)
                    . (count((array) $value) === count((array) ($base['images'] ?? [])) ? ' (inne zdjęcia lub kolejność)' : '')
                    . (array_intersect(array_diff((array) $value, (array) ($base['images'] ?? [])), self::descImages($new)) !== []
                        ? ' (grafiki z opisu dopisane na końcu galerii — Allegro tego wymaga)' : ''),
                default       => $label,
            };
        }
        // Zdjęcia opisu muszą być w galerii: przy zmianie opisu wysyłamy też zdjęcia.
        if (isset($patch['description']) && !isset($patch['images']) && isset($new['images'])) {
            $patch['images'] = $new['images'];
        }
        return ['patch' => $patch, 'changes' => $changes];
    }

    /** @return string[] adresy zdjęć z sekcji opisu */
    public static function descImages(array $payload): array
    {
        $out = [];
        foreach ((array) ($payload['description']['sections'] ?? []) as $sec) {
            foreach ((array) ($sec['items'] ?? []) as $it) {
                if (($it['type'] ?? '') === 'IMAGE' && ($it['url'] ?? '') !== '') {
                    $out[] = (string) $it['url'];
                }
            }
        }
        return $out;
    }

    /** Klucze obiektów posortowane (listy zostają w kolejności), żeby kolejność pól nie dawała „zmian". */
    private static function ksorted(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v === null ? null : (is_bool($v) ? $v : str_replace("\r\n", "\n", (string) $v));
        }
        $v = array_map([self::class, 'ksorted'], $v);
        if (!array_is_list($v)) {
            ksort($v);
        }
        return $v;
    }

    /** @return string[] adresy zdjęć (Allegro zwraca je jako tekst albo {url}) */
    private static function urls(array $images): array
    {
        $out = [];
        foreach ($images as $i) {
            $u = is_array($i) ? (string) ($i['url'] ?? '') : (is_scalar($i) ? (string) $i : '');
            if ($u !== '' && !in_array($u, $out, true)) {
                $out[] = $u;
            }
        }
        return $out;
    }
}
