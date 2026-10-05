<?php
declare(strict_types=1);

namespace Pase\Services;

/**
 * Body POST /sale/product-offers z formularza „Oferta Allegro" + sprawdzenie pól, których Allegro wymaga.
 *
 * Oferta z nowym produktem (spoza katalogu Allegro) potrzebuje: nazwy, kategorii-liścia, wymaganych
 * parametrów kategorii (produktu i oferty, np. „Stan"), zdjęć, ceny, ilości, cennika dostaw i czasu wysyłki,
 * warunków zwrotów i reklamacji (konto firmowe), faktury, producenta i informacji o bezpieczeństwie (GPSR).
 * Allegro rozpoznaje produkt z katalogu samo (po EAN/nazwie) - product.id nie podajemy.
 */
final class AllegroOfferPayload
{
    /** payments.invoice - wartości przyjmowane przez Allegro. */
    public const INVOICES = [
        'VAT'         => 'Faktura VAT',
        'VAT_MARGIN'  => 'Faktura VAT-marża',
        'WITHOUT_VAT' => 'Faktura bez VAT',
        'NO_INVOICE'  => 'Nie wystawiam faktury',
    ];

    /** location.province - województwa w zapisie Allegro. */
    public const PROVINCES = [
        'DOLNOSLASKIE' => 'dolnośląskie', 'KUJAWSKO_POMORSKIE' => 'kujawsko-pomorskie', 'LUBELSKIE' => 'lubelskie',
        'LUBUSKIE' => 'lubuskie', 'LODZKIE' => 'łódzkie', 'MALOPOLSKIE' => 'małopolskie', 'MAZOWIECKIE' => 'mazowieckie',
        'OPOLSKIE' => 'opolskie', 'PODKARPACKIE' => 'podkarpackie', 'PODLASKIE' => 'podlaskie', 'POMORSKIE' => 'pomorskie',
        'SLASKIE' => 'śląskie', 'SWIETOKRZYSKIE' => 'świętokrzyskie', 'WARMINSKO_MAZURSKIE' => 'warmińsko-mazurskie',
        'WIELKOPOLSKIE' => 'wielkopolskie', 'ZACHODNIOPOMORSKIE' => 'zachodniopomorskie',
    ];

    /** Parametr „EAN (GTIN)" - wypełniany z pola EAN formularza, gdy kategoria go ma. */
    public const EAN_PARAMETER_ID = '225693';

    /** Ustawienia z domyślnymi wartościami formularza (zapamiętywane po udanym wystawieniu). */
    public const DEFAULT_SETTINGS = [
        'shipping_rate'    => 'allegro_default_shipping_rate',
        'return_policy'    => 'allegro_default_return_policy',
        'implied_warranty' => 'allegro_default_implied_warranty',
        'warranty'         => 'allegro_default_warranty',
        'invoice'          => 'allegro_default_invoice',
        'tax_subject'      => 'allegro_default_tax_subject',
        'tax_exemption'    => 'allegro_default_tax_exemption',
        'producer'         => 'allegro_default_producer',
        'person'           => 'allegro_default_person',
        'province'         => 'allegro_default_province',
        'city'             => 'allegro_default_city',
        'post_code'        => 'allegro_default_post_code',
    ];

    /** Stawki VAT dla krajów (JSON kraj => stawka) - domyślne z ostatniego wystawienia. */
    public const DEFAULT_VAT_SETTING = 'allegro_default_vat';

    /**
     * „Kod taryfy celnej" (kod CN, min. 8 cyfr, z rozszerzeniem TARIC 10 lub 12 znaków) - parametr oferty
     * o id 250792 (Allegro czyta go z parametrów oferty, np. przy eksporcie).
     */
    public const TARIFF_PARAMETER_ID = '250792';
    public const TARIFF_MAX_LENGTH = 14;

    /** „Uwagi do zakupu" (messageToSellerSettings.mode): pole opcjonalne dla kupującego albo brak pola. */
    public const MESSAGE_MODES = ['OPTIONAL' => 'opcjonalne pole „uwagi do zakupu"', 'HIDDEN' => 'brak pola „uwagi do zakupu"'];

    /** stock.unit - jednostka liczby sztuk. */
    public const STOCK_UNITS = ['UNIT' => 'sztuk', 'PAIR' => 'par', 'SET' => 'kompletów'];

    /** publication.duration dla „Kup teraz" ('' = do wyczerpania przedmiotów). */
    public const DURATIONS_BUY_NOW = ['' => 'do wyczerpania przedmiotów', 'PT72H' => '3 dni', 'PT120H' => '5 dni',
        'PT168H' => '7 dni', 'PT240H' => '10 dni', 'PT480H' => '20 dni', 'PT720H' => '30 dni'];

    /** publication.duration dla licytacji (zawsze na określony czas). */
    public const DURATIONS_AUCTION = ['PT24H' => '1 dzień', 'PT72H' => '3 dni', 'PT120H' => '5 dni', 'PT168H' => '7 dni', 'PT240H' => '10 dni'];

    /** delivery.additionalInfo - „Dodatkowe informacje o dostawie". */
    public const DELIVERY_INFO_MAX = 650;

    /** Rodzaje załączników oferty (POST /sale/offer-attachments). Etykieta energetyczna to obraz, reszta PDF. */
    public const ATTACHMENT_TYPES = [
        'USER_MANUAL'               => 'Instrukcja obsługi',
        'MANUAL'                    => 'Poradnik',
        'INSTALLATION_INSTRUCTIONS' => 'Instrukcja montażu',
        'PRODUCT_INFORMATION_SHEET' => 'Karta produktu',
        'SPECIAL_OFFER_RULES'       => 'Regulamin promocji',
        'COMPETITION_RULES'         => 'Regulamin konkursu',
        'GAME_INSTRUCTIONS'         => 'Instrukcja gry',
        'BOOK_EXCERPT'              => 'Fragment książki',
        'ENERGY_LABEL'              => 'Etykieta energetyczna',
        'SOFTWARE_DATA_PROCESSING'  => 'Przetwarzanie danych (oprogramowanie)',
        'HARDWARE_DATA_PROCESSING'  => 'Przetwarzanie danych (urządzenie)',
    ];

    /**
     * Dozwolone pliki dla rodzaju załącznika: rozszerzenie => Content-Type.
     * @return array<string,string>
     */
    public static function attachmentFormats(string $type): array
    {
        return $type === 'ENERGY_LABEL'
            ? ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png']
            : ['pdf' => 'application/pdf'];
    }

    /**
     * Załączniki z formularza (id z Allegro, rodzaj, nazwa pliku) - poprawne i bez powtórzeń.
     * @return array<int,array{id:string,type:string,name:string}>
     */
    public static function attachments(array $form): array
    {
        $out = [];
        foreach ((array) ($form['attachments'] ?? []) as $a) {
            $id = is_array($a) ? trim((string) ($a['id'] ?? '')) : '';
            if ($id === '' || !preg_match('/^[A-Za-z0-9-]{1,64}$/', $id) || isset($out[$id])) {
                continue;
            }
            $type = (string) ($a['type'] ?? '');
            $out[$id] = ['id' => $id, 'type' => isset(self::ATTACHMENT_TYPES[$type]) ? $type : '',
                         'name' => mb_substr(trim((string) ($a['name'] ?? '')), 0, 200)];
        }
        return array_values($out);
    }

    /** Rynki dodatkowe Allegro (additionalMarketplaces): id => [kraj, waluta]. */
    public const MARKETS = ['allegro-cz' => ['Czechy', 'CZK'], 'allegro-sk' => ['Słowacja', 'EUR'], 'allegro-hu' => ['Węgry', 'HUF']];

    /** Najwięcej dodatkowych produktów w jednej ofercie (productSet ma razem maks. 30 pozycji). */
    public const MAX_EXTRA_PRODUCTS = 29;

    /** Wyróżnienie (pakiet podstawowy), gdy Allegro nie poda listy pakietów. */
    public const PROMO_EXTRA_DEPARTMENT = 'departmentPage';

    /**
     * Kolejne produkty w ofercie („Dodaj kolejny produkt"): produkty z Katalogu Allegro z liczbą sztuk.
     * @param array<string,mixed> $form
     * @return array<int,array{id:string,name:string,quantity:int}>
     */
    public static function extraProducts(array $form): array
    {
        $out = [];
        foreach ((array) ($form['extra_products'] ?? []) as $r) {
            $id = is_array($r) ? trim((string) ($r['id'] ?? '')) : '';
            if ($id === '' || !preg_match('/^[A-Za-z0-9-]{1,64}$/', $id) || isset($out[$id])) {
                continue;
            }
            $out[$id] = ['id' => $id, 'name' => mb_substr(trim((string) ($r['name'] ?? '')), 0, 200),
                         'quantity' => max(1, min(self::MAX_SET_QUANTITY, (int) ($r['quantity'] ?? 1)))];
        }
        return array_slice(array_values($out), 0, self::MAX_EXTRA_PRODUCTS);
    }

    /**
     * Ceny na rynkach dodatkowych (kraj => cena w walucie rynku). Pusta cena = rynek bez własnej ceny.
     * @param array<string,mixed> $form
     * @return array<string,array{sellingMode:array{price:array{amount:string,currency:string}}}>
     */
    public static function marketPrices(array $form): array
    {
        $out = [];
        foreach (self::MARKETS as $id => [$_, $cur]) {
            $v = (float) str_replace([',', ' '], ['.', ''], trim((string) ($form['markets'][$id] ?? '')));
            if ($v > 0) {
                $out[$id] = ['sellingMode' => ['price' => ['amount' => number_format($v, $cur === 'HUF' ? 0 : 2, '.', ''), 'currency' => $cur]]];
            }
        }
        return $out;
    }

    /**
     * Automatyczne ceny: body PUT /sale/price-automation/offers/{id}/rules (reguła z zakresem cen na allegro.pl).
     * @param array<string,mixed> $form
     * @return array{0:?array<string,mixed>,1:string} [body albo null, błąd]
     */
    public static function priceRule(array $form): array
    {
        $rule = trim((string) ($form['price_rule'] ?? ''));
        if ($rule === '') {
            return [null, ''];
        }
        $num = static fn(string $k): float => (float) str_replace(',', '.', trim((string) ($form[$k] ?? '')));
        $min = $num('price_rule_min');
        $max = $num('price_rule_max');
        if ($min <= 0 || $max <= 0) {
            return [null, 'Automatyczne ceny: podaj cenę minimalną i maksymalną.'];
        }
        if ($min > $max) {
            return [null, 'Automatyczne ceny: cena minimalna jest wyższa od maksymalnej.'];
        }
        $money = static fn(float $v): array => ['amount' => number_format($v, 2, '.', ''), 'currency' => 'PLN'];
        return [['rules' => [['marketplace' => ['id' => 'allegro-pl'], 'rule' => ['id' => $rule],
            'configuration' => ['priceRange' => ['type' => 'MARKETPLACE_CURRENCY', 'minPrice' => $money($min), 'maxPrice' => $money($max)]]]]], ''];
    }

    /**
     * Wyróżnienie i promowanie na stronie kategorii: body POST /sale/offers/{id}/promo-options-modification.
     * @param array<string,mixed> $form
     * @return array<string,mixed>|null null = bez płatnych opcji
     */
    public static function promotion(array $form): ?array
    {
        $mods = [];
        $base = trim((string) ($form['promo_package'] ?? ''));
        if ($base !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $base)) {
            $mods[] = ['modificationType' => 'CHANGE', 'packageType' => 'BASE', 'packageId' => $base];
        }
        if (!empty($form['promo_department']) && $form['promo_department'] !== '0') {
            $mods[] = ['modificationType' => 'CHANGE', 'packageType' => 'EXTRA', 'packageId' => self::PROMO_EXTRA_DEPARTMENT];
        }
        return $mods === [] ? null : ['modifications' => $mods];
    }

    /** Najwięcej sztuk produktu w zestawie („Wiele sztuk jako zestaw", productSet[].quantity.value). */
    public const MAX_SET_QUANTITY = 1000;

    /**
     * Parametry kategorii z „Kodem taryfy celnej" jako parametrem oferty: zawsze widocznym (także przy
     * produkcie z Katalogu) i wysyłanym w parameters oferty. Gdy kategoria go nie zwraca - dopisujemy.
     * @param array<int,array<string,mixed>> $params
     * @return array<int,array<string,mixed>>
     */
    public static function withTariffCode(array $params): array
    {
        foreach ($params as $i => $param) {
            if (self::isTariffParameter($param)) {
                $params[$i]['options']['describesProduct'] = false;
                $params[$i]['restrictions']['maxLength'] ??= self::TARIFF_MAX_LENGTH;
                return $params;
            }
        }
        if ($params === []) {
            return $params;          // bez kategorii (albo błąd Allegro) nie ma formularza parametrów
        }
        $params[] = [
            'id' => self::TARIFF_PARAMETER_ID, 'name' => 'Kod taryfy celnej', 'type' => 'string', 'required' => false,
            'options' => ['describesProduct' => false],
            'restrictions' => ['maxLength' => self::TARIFF_MAX_LENGTH, 'allowedNumberOfValues' => 5],
        ];
        return $params;
    }

    public static function isTariffParameter(array $param): bool
    {
        return (string) ($param['id'] ?? '') === self::TARIFF_PARAMETER_ID
            || mb_stripos((string) ($param['name'] ?? ''), 'taryfy celnej') !== false;
    }

    /**
     * Ile sztuk produktu jest w ofercie: 1 („Pojedyncza sztuka") albo liczba z „Wiele sztuk jako zestaw".
     * @return array{0:int,1:string} [liczba, błąd]
     */
    public static function setQuantity(array $form): array
    {
        if (($form['set_mode'] ?? 'single') !== 'set') {
            return [1, ''];
        }
        $raw = trim((string) ($form['set_quantity'] ?? ''));
        $n = ctype_digit($raw) ? (int) $raw : 0;
        if ($n < 2 || $n > self::MAX_SET_QUANTITY) {
            return [0, 'W zestawie podaj liczbę sztuk produktu od 2 do ' . self::MAX_SET_QUANTITY . '.'];
        }
        return [$n, ''];
    }

    /** Czy parametr opisuje produkt (productSet[].product.parameters), a nie ofertę (parameters, np. „Stan"). */
    public static function describesProduct(array $param): bool
    {
        return !empty($param['options']['describesProduct']) && !self::isTariffParameter($param);
    }

    /** Czy parametr trzeba wypełnić przy nowym produkcie (bez warunku requiredIf). */
    public static function isRequired(array $param): bool
    {
        return !empty($param['required']) || !empty($param['requiredForProduct']);
    }

    public static function isEanParameter(array $param): bool
    {
        return (string) ($param['id'] ?? '') === self::EAN_PARAMETER_ID;
    }

    /** Ile wartości parametr przyjmuje (np. „Kod taryfy celnej" kilka pól), domyślnie 1. */
    public static function allowedValues(array $param): int
    {
        return max(1, (int) ($param['restrictions']['allowedNumberOfValues'] ?? 1));
    }

    /**
     * Warunek displayedIf / requiredIf z API: {parameters:[{id, oneOfValueIds:[...]}]} - spełniony, gdy każdy
     * wymieniony parametr ma wybraną jedną z podanych wartości.
     * @param array<string,array<int,string>> $selected id parametru => wybrane id wartości słownikowych
     */
    public static function conditionMet(mixed $cond, array $selected): bool
    {
        $rules = is_array($cond) ? (array) ($cond['parameters'] ?? []) : [];
        foreach ($rules as $r) {
            $want = array_map('strval', (array) ($r['oneOfValueIds'] ?? []));
            if ($want !== [] && array_intersect($want, $selected[(string) ($r['id'] ?? '')] ?? []) === []) {
                return false;
            }
        }
        return true;
    }

    /** Czy parametr jest widoczny (displayedIf) przy wybranych wartościach. */
    public static function isDisplayed(array $param, array $selected): bool
    {
        return !isset($param['displayedIf']) || self::conditionMet($param['displayedIf'], $selected);
    }

    /** Czy parametr trzeba wypełnić przy wybranych wartościach: zawsze wymagany albo spełniony requiredIf. */
    public static function isRequiredNow(array $param, array $selected): bool
    {
        return self::isRequired($param) || (isset($param['requiredIf']) && ($param['requiredIf']['parameters'] ?? []) !== []
            && self::conditionMet($param['requiredIf'], $selected));
    }

    /**
     * Wybrane wartości słownikowe z formularza (do warunków displayedIf/requiredIf i zależnych słowników).
     * @param array<string,mixed> $values params z formularza
     * @return array<string,array<int,string>>
     */
    public static function selectedValues(array $values): array
    {
        $out = [];
        foreach ($values as $id => $v) {
            $ids = array_values(array_filter(array_map('strval', is_array($v) ? array_filter($v, 'is_scalar') : [$v]), static fn($x) => $x !== ''));
            $out[(string) $id] = $ids;
        }
        return $out;
    }

    /**
     * Wartość parametru z formularza w zapisie API albo null, gdy pusta.
     * Formularz: słownik -> id wartości (lista przy wielokrotnym wyborze), zakres -> [from,to],
     * tekst/liczba -> wartość albo lista wartości (allowedNumberOfValues > 1).
     * $custom: własna wartość słownika (customValuesEnabled), np. marka spoza listy.
     * @return array<string,mixed>|null
     */
    public static function parameterValue(array $param, mixed $input, string $custom = ''): ?array
    {
        $id = (string) ($param['id'] ?? '');
        $type = (string) ($param['type'] ?? 'string');
        $restr = (array) ($param['restrictions'] ?? []);
        $num = static fn($v) => in_array($type, ['float', 'integer'], true) ? str_replace([',', ' '], ['.', ''], trim((string) $v)) : trim((string) $v);

        if (!empty($restr['range'])) {
            $from = $num(is_array($input) ? ($input['from'] ?? '') : '');
            $to   = $num(is_array($input) ? ($input['to'] ?? '') : '');
            return $from === '' && $to === '' ? null : ['id' => $id, 'rangeValue' => ['from' => $from, 'to' => $to]];
        }
        if ($type === 'dictionary') {
            $known = [];
            foreach ((array) ($param['dictionary'] ?? []) as $d) {
                $known[(string) ($d['id'] ?? '')] = true;
            }
            $ids = array_values(array_unique(array_filter(array_map('strval', is_array($input) ? array_filter($input, 'is_scalar') : [$input]),
                static fn($v) => $v !== '' && isset($known[$v]))));
            if (empty($restr['multipleChoices'])) {
                $ids = array_slice($ids, 0, 1);
            }
            $custom = trim($custom);
            if ($custom !== '' && !empty($param['options']['customValuesEnabled'])) {
                $amb = (string) ($param['options']['ambiguousValueId'] ?? '');
                return ['id' => $id, 'valuesIds' => $amb !== '' ? [$amb] : [], 'values' => [$custom]];
            }
            return $ids === [] ? null : ['id' => $id, 'valuesIds' => $ids];
        }
        $vals = array_values(array_filter(array_map($num, is_array($input) ? array_filter($input, 'is_scalar') : [(string) $input]),
            static fn($v) => $v !== ''));
        $vals = array_slice(array_values(array_unique($vals)), 0, self::allowedValues($param));
        return $vals === [] ? null : ['id' => $id, 'values' => $vals];
    }

    /**
     * Stawki VAT, przedmiot i zwolnienia z GET /sale/tax-settings w postaci do formularza.
     * Obsługuje odpowiedź z krajami (rates:[{countryCode, values:[{value,label}]}]) i płaską listę stawek.
     * @param array<string,mixed> $tax
     * @return array{subjects:array<string,string>,exemptions:array<string,string>,rates:array<string,array<string,string>>}
     */
    public static function taxOptions(array $tax): array
    {
        $out = ['subjects' => [], 'exemptions' => [], 'rates' => []];
        foreach (['subjects', 'exemptions'] as $k) {
            foreach ((array) ($tax[$k] ?? []) as $o) {
                $v = is_array($o) ? (string) ($o['value'] ?? '') : '';
                if ($v !== '') {
                    $out[$k][$v] = (string) ($o['label'] ?? $o['description'] ?? $v);
                }
            }
        }
        foreach ((array) ($tax['rates'] ?? []) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $cc = strtoupper((string) ($r['countryCode'] ?? 'PL'));
            $list = isset($r['values']) ? (array) $r['values'] : [$r];
            foreach ($list as $o) {
                $v = is_array($o) ? (string) ($o['value'] ?? $o['rate'] ?? '') : '';
                if ($v !== '') {
                    $out['rates'][$cc][$v] = (string) ($o['label'] ?? (($o['percentage'] ?? $v) . '%'));
                }
            }
        }
        return $out;
    }

    /** Kraje stawek VAT w kolejności jak w formularzu Allegro. */
    public const VAT_COUNTRIES = ['CZ' => 'Czechy', 'SK' => 'Słowacja', 'PL' => 'Polska', 'HU' => 'Węgry', 'LT' => 'Litwa'];

    /** Maks. liczba zdjęć w ofercie Allegro. */
    public const MAX_IMAGES = 16;

    /** Tagi dozwolone przez Allegro w tekście opisu. */
    private const DESC_TAGS = ['h1', 'h2', 'p', 'ul', 'ol', 'li', 'b'];

    /**
     * HTML opisu w zakresie, który przyjmuje Allegro (h1, h2, p, ul, ol, li, b): strong -> b, h3-h6 -> h2,
     * div -> p, luźny tekst w akapicie, reszta tagów i atrybutów usuwana (zostaje ich tekst).
     */
    public static function sanitizeHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="r">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('r') ?? $doc->documentElement;
        $inline = static function (\DOMNode $n) use (&$inline): string {
            $out = '';
            foreach ($n->childNodes as $c) {
                if ($c instanceof \DOMText) {
                    $out .= htmlspecialchars($c->textContent, ENT_NOQUOTES);
                } elseif ($c instanceof \DOMElement) {
                    $t = strtolower($c->tagName);
                    $in = $inline($c);
                    $out .= in_array($t, ['b', 'strong'], true) ? ($in !== '' ? '<b>' . $in . '</b>' : '') : ($t === 'br' ? ' ' : $in);
                }
            }
            return $out;
        };
        $out = '';
        $loose = '';
        $flush = static function () use (&$loose, &$out): void {
            if (trim(html_entity_decode(strip_tags($loose))) !== '') {
                $out .= '<p>' . trim($loose) . '</p>';
            }
            $loose = '';
        };
        $block = static function (\DOMNode $parent) use (&$block, &$out, &$loose, $flush, $inline): void {
            foreach ($parent->childNodes as $c) {
                $t = $c instanceof \DOMElement ? strtolower($c->tagName) : '';
                if ($c instanceof \DOMText || in_array($t, ['b', 'strong', 'i', 'em', 'u', 'span', 'a', 'br', 'small', 'sup', 'sub'], true)) {
                    $loose .= $c instanceof \DOMText ? htmlspecialchars($c->textContent, ENT_NOQUOTES)
                        : (in_array($t, ['b', 'strong'], true) ? '<b>' . $inline($c) . '</b>' : ($t === 'br' ? ' ' : $inline($c)));
                    continue;
                }
                if (!$c instanceof \DOMElement || in_array($t, ['script', 'style', 'noscript', 'template', 'img', 'iframe'], true)) {
                    continue;
                }
                $flush();
                if (in_array($t, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p'], true)) {
                    $tag = $t === 'h1' || $t === 'p' ? $t : ($t === 'h2' ? 'h2' : (str_starts_with($t, 'h') ? 'h2' : 'p'));
                    $in = trim($inline($c));
                    if ($in !== '') {
                        $out .= "<{$tag}>{$in}</{$tag}>";
                    }
                } elseif ($t === 'ul' || $t === 'ol') {
                    $items = '';
                    foreach ($c->childNodes as $li) {
                        if ($li instanceof \DOMElement && strtolower($li->tagName) === 'li' && trim($inline($li)) !== '') {
                            $items .= '<li>' . trim($inline($li)) . '</li>';
                        }
                    }
                    if ($items !== '') {
                        $out .= "<{$t}>{$items}</{$t}>";
                    }
                } else {
                    $block($c);            // div, section, table... - zawartość jako kolejne bloki
                    $flush();
                }
            }
        };
        $block($root);
        $flush();
        return $out;
    }

    /**
     * Zdjęcia trwającej oferty po zmianie opisu (PATCH description + images): Allegro wymaga, żeby
     * każde zdjęcie z opisu było też w galerii. Galeria zostaje, nowe zdjęcia opisu dochodzą na końcu;
     * gdy brak miejsca (limit MAX_IMAGES), wypadają od końca zdjęcia używane tylko w starym opisie.
     *
     * @param string[] $current zdjęcia oferty teraz
     * @param array<int,array<string,mixed>> $oldSections obecny opis oferty
     * @param array<int,array<string,mixed>> $newSections nowy opis
     * @return array{images:string[],error:string}
     */
    public static function mergeDescriptionImages(array $current, array $oldSections, array $newSections): array
    {
        $urls = static function (array $sections): array {
            $out = [];
            foreach ($sections as $sec) {
                foreach ((array) ($sec['items'] ?? []) as $it) {
                    if (($it['type'] ?? '') === 'IMAGE' && is_string($it['url'] ?? null) && $it['url'] !== '') {
                        $out[] = $it['url'];
                    }
                }
            }
            return array_values(array_unique($out));
        };
        $images = array_values(array_unique(array_filter($current, static fn($u) => is_string($u) && $u !== '')));
        $new = $urls($newSections);
        foreach ($new as $u) {
            if (!in_array($u, $images, true)) {
                $images[] = $u;
            }
        }
        $oldOnly = array_diff($urls($oldSections), $new);
        for ($i = count($images) - 1; count($images) > self::MAX_IMAGES && $i > 0; --$i) {
            if (in_array($images[$i], $oldOnly, true)) {
                array_splice($images, $i, 1);
            }
        }
        if (count($images) > self::MAX_IMAGES) {
            return ['images' => $images, 'error' => 'Oferta miałaby ' . count($images) . ' zdjęć (Allegro pozwala na ' . self::MAX_IMAGES
                . ', razem ze zdjęciami z opisu). Usuń część sekcji ze zdjęciami albo zdjęć z galerii oferty.'];
        }
        return ['images' => $images, 'error' => ''];
    }

    /**
     * Sekcje opisu (description.sections) z edytora blokowego: każda ma 1-2 elementy TEXT / IMAGE.
     * Bez sekcji (starsze szablony) - opis HTML jako jedna sekcja tekstowa.
     * @param array<string,mixed> $form
     * @return array<int,array{items:array<int,array<string,string>>}>
     */
    public static function descriptionSections(array $form): array
    {
        $raw = $form['description_sections'] ?? null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            $raw = [['items' => [['type' => 'TEXT', 'content' => (string) ($form['description'] ?? '')]]]];
        }
        $out = [];
        foreach ($raw as $sec) {
            $items = [];
            foreach (array_slice((array) ($sec['items'] ?? []), 0, 2) as $it) {
                if (($it['type'] ?? '') === 'IMAGE') {
                    $url = trim((string) ($it['url'] ?? ''));
                    if (preg_match('#^https?://#i', $url)) {
                        $items[] = ['type' => 'IMAGE', 'url' => $url];
                    }
                } else {
                    $html = self::sanitizeHtml((string) ($it['content'] ?? ''));
                    if ($html !== '') {
                        $items[] = ['type' => 'TEXT', 'content' => $html];
                    }
                }
            }
            if ($items !== []) {
                $out[] = ['items' => $items];
            }
        }
        return $out;
    }

    /**
     * publication: szkic (INACTIVE), od razu (ACTIVE) albo w wybranym dniu i godzinie (ACTIVE + startingAt w UTC).
     * @param array<string,mixed> $form publish_mode: draft|now|scheduled, publish_date (RRRR-MM-DD), publish_time (GG:MM)
     * @return array{0:array<string,string>,1:string}
     */
    public static function publication(array $form, ?\DateTimeImmutable $now = null): array
    {
        $mode = (string) ($form['publish_mode'] ?? (!empty($form['activate']) ? 'now' : 'draft'));
        if ($mode === 'now') {
            return [['status' => 'ACTIVE'], ''];
        }
        if ($mode !== 'scheduled') {
            return [['status' => 'INACTIVE'], ''];
        }
        $tz = new \DateTimeZone('Europe/Warsaw');
        $when = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', trim((string) ($form['publish_date'] ?? '')) . ' ' . trim((string) ($form['publish_time'] ?? '')), $tz);
        if ($when === false) {
            return [['status' => 'INACTIVE'], 'Podaj datę i godzinę wystawienia.'];
        }
        if ($when <= ($now ?? new \DateTimeImmutable('now', $tz))) {
            return [['status' => 'INACTIVE'], 'Data wystawienia musi być w przyszłości.'];
        }
        return [['status' => 'ACTIVE', 'startingAt' => $when->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')], ''];
    }

    /**
     * Oferta do podglądu opłat (POST /pricing/offer-fee-preview): kategoria, sposób sprzedaży, cena, ilość i czas trwania.
     * Podgląd opłat przyjmuje ofertę w starym modelu (/sale/offers): „parameters" jest wymagane (także puste) i zawiera
     * parametry oferty i produktu razem - bierzemy je z body zbudowanego przez build(), gdy jest.
     * @param array<string,mixed> $form
     * @param array<string,mixed>|null $built payload z build() dla tego formularza (dla parametrów)
     * @return array{offer:array<string,mixed>,error:string}
     */
    public static function feeOffer(array $form, ?array $built = null): array
    {
        $s = static fn(string $k): string => trim((string) ($form[$k] ?? ''));
        $categoryId = preg_replace('/\D/', '', $s('category_id')) ?? '';
        $price = (float) str_replace(',', '.', $s('price'));
        if ($categoryId === '' || $price <= 0) {
            return ['offer' => [], 'error' => 'Wybierz kategorię i podaj cenę, żeby sprawdzić prowizję.'];
        }
        $auction = $s('selling_format') === 'AUCTION';
        $duration = $s('duration');
        $durations = $auction ? self::DURATIONS_AUCTION : self::DURATIONS_BUY_NOW;
        if (!isset($durations[$duration])) {
            $duration = $auction ? 'PT168H' : '';
        }
        $money = ['amount' => number_format($price, 2, '.', ''), 'currency' => 'PLN'];
        $offer = [
            'name'        => $s('title'),
            'category'    => ['id' => $categoryId],
            'sellingMode' => $auction ? ['format' => 'AUCTION', 'startingPrice' => $money] : ['format' => 'BUY_NOW', 'price' => $money],
            'stock'       => ['available' => max(1, (int) $s('stock')), 'unit' => isset(self::STOCK_UNITS[$s('stock_unit')]) ? $s('stock_unit') : 'UNIT'],
            'publication' => ['duration' => $duration !== '' ? $duration : null],
            'parameters'  => array_values(array_merge((array) ($built['parameters'] ?? []),
                (array) ($built['productSet'][0]['product']['parameters'] ?? []))),
        ];
        if ($s('shipping_rate') !== '') {
            $offer['delivery'] = ['shippingRates' => ['id' => $s('shipping_rate')]];
        }
        return ['offer' => $offer, 'error' => ''];
    }

    /**
     * Przedsprzedaż: delivery.shipmentDate (początek dnia wysyłki czasu polskiego, w UTC).
     * @return array{0:?string,1:string} [data albo null, błąd]
     */
    public static function shipmentDate(array $form, ?\DateTimeImmutable $now = null): array
    {
        if (empty($form['preorder']) || $form['preorder'] === '0') {
            return [null, ''];
        }
        $tz = new \DateTimeZone('Europe/Warsaw');
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', trim((string) ($form['shipment_date'] ?? '')), $tz);
        if ($day === false) {
            return [null, 'Przedsprzedaż: podaj datę wysyłki.'];
        }
        if ($day <= ($now ?? new \DateTimeImmutable('now', $tz))) {
            return [null, 'Przedsprzedaż: data wysyłki musi być w przyszłości.'];
        }
        return [$day->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), ''];
    }

    /**
     * Sprawdzenie pól i body oferty.
     * @param array<string,mixed> $form stan formularza (jak zapisywany w offer_templates)
     * @param array<int,array<string,mixed>> $categoryParams GET /sale/categories/{id}/parameters
     * @return array{payload:array<string,mixed>,errors:array<int,string>}
     */
    public static function build(array $form, array $categoryParams, bool $leafCategory = true): array
    {
        $errors = [];
        $s = static fn(string $k): string => trim((string) ($form[$k] ?? ''));

        $title = $s('title');
        if ($title === '') {
            $errors[] = 'Podaj tytuł oferty.';
        } elseif (mb_strlen($title) > 75) {
            $errors[] = 'Tytuł ma ' . mb_strlen($title) . ' znaków (Allegro pozwala na 75).';
        } elseif (count(preg_split('/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: []) < 3) {
            $errors[] = 'Tytuł musi mieć co najmniej 3 słowa.';
        }

        $categoryId = preg_replace('/\D/', '', $s('category_id')) ?? '';
        if ($categoryId === '') {
            $errors[] = 'Wybierz kategorię Allegro.';
        } elseif (!$leafCategory) {
            $errors[] = 'Wybierz najniższą kategorię (liść) — w tej są jeszcze podkategorie.';
        }

        $price = (float) str_replace(',', '.', $s('price'));
        if ($price <= 0) {
            $errors[] = 'Cena musi być większa od zera.';
        }
        $stock = (int) $s('stock');
        if ($stock < 1) {
            $errors[] = 'Liczba sztuk musi wynosić co najmniej 1.';
        }

        $images = array_values(array_unique(array_filter(array_map('strval', (array) ($form['images'] ?? [])), static fn($u) => $u !== '')));
        if ($images === []) {
            $errors[] = 'Dodaj co najmniej jedno zdjęcie.';
        }
        // Opis z edytora blokowego. Zdjęcia z opisu muszą być też w galerii oferty - dopisujemy je na końcu.
        $sections = self::descriptionSections($form);
        if ($sections === []) {
            $errors[] = 'Dodaj opis oferty.';
        }
        foreach ($sections as $sec) {
            foreach ($sec['items'] as $it) {
                if ($it['type'] === 'IMAGE' && !in_array($it['url'], $images, true)) {
                    $images[] = $it['url'];
                }
            }
        }
        if (count($images) > self::MAX_IMAGES) {
            $errors[] = 'Oferta może mieć maks. ' . self::MAX_IMAGES . ' zdjęć (razem ze zdjęciami z opisu) — jest ' . count($images) . '.';
        }
        [$setQty, $setErr] = self::setQuantity($form);
        if ($setErr !== '') {
            $errors[] = $setErr;
        }
        foreach ((array) ($form['params'][self::TARIFF_PARAMETER_ID] ?? []) as $code) {
            $code = trim((string) $code);
            if ($code !== '' && !preg_match('/^[0-9A-Z]{8,' . self::TARIFF_MAX_LENGTH . '}$/', $code)) {
                $errors[] = 'Kod taryfy celnej „' . $code . '" - wpisz min. 8 cyfr kodu CN (bez spacji i kropek, maks. ' . self::TARIFF_MAX_LENGTH . ' znaków).';
            }
        }
        [$publication, $pubErr] = self::publication($form);
        if ($pubErr !== '') {
            $errors[] = $pubErr;
        }
        $auction = $s('selling_format') === 'AUCTION';
        $duration = $s('duration');
        if ($auction) {
            if (!isset(self::DURATIONS_AUCTION[$duration])) {
                $errors[] = 'Licytacja trwa określony czas — wybierz czas trwania (1–10 dni).';
                $duration = 'PT168H';
            }
        } elseif (!isset(self::DURATIONS_BUY_NOW[$duration])) {
            $duration = '';
        }
        if ($duration !== '') {
            $publication['duration'] = $duration;          // bez czasu trwania = do wyczerpania przedmiotów
        }
        if (!empty($form['republish']) && $form['republish'] !== '0') {
            $publication['republish'] = true;              // wznawianie z pełną liczbą sztuk
        }
        $minimal = $s('minimal_price') !== '' ? (float) str_replace(',', '.', $s('minimal_price')) : 0.0;
        if ($auction && $minimal > 0 && $minimal < $price) {
            $errors[] = 'Cena minimalna nie może być niższa od ceny wywoławczej.';
        }
        [$shipmentDate, $shipErr] = self::shipmentDate($form);
        if ($shipErr !== '') {
            $errors[] = $shipErr;
        }
        $deliveryInfo = trim(strip_tags($s('delivery_info')));
        if (mb_strlen($deliveryInfo) > self::DELIVERY_INFO_MAX) {
            $errors[] = 'Dodatkowe informacje o dostawie mogą mieć maks. ' . self::DELIVERY_INFO_MAX . ' znaków.';
        }

        // Parametry kategorii: produktu i oferty (np. „Stan"). Wymagane (także warunkowo - requiredIf) muszą mieć
        // wartość; ukryte przez displayedIf pomijamy. Przy produkcie z katalogu Allegro parametry produktu bierze
        // z katalogu, więc sprawdzamy i wysyłamy tylko parametry oferty.
        $catalogId = $s('catalog_product_id');
        $productParams = $offerParams = [];
        $values = (array) ($form['params'] ?? []);
        $customs = (array) ($form['params_custom'] ?? []);
        $selected = self::selectedValues($values);
        $ean = preg_replace('/\s+/', '', $s('ean')) ?? '';
        foreach ($categoryParams as $param) {
            $pid = (string) ($param['id'] ?? '');
            $forProduct = self::describesProduct($param);
            if (($catalogId !== '' && $forProduct) || !self::isDisplayed($param, $selected)) {
                continue;
            }
            $input = $values[$pid] ?? null;
            if (self::isEanParameter($param) && ($input === null || $input === '' || $input === [''])) {
                $input = $ean;
            }
            $val = self::parameterValue($param, $input, is_scalar($customs[$pid] ?? null) ? (string) $customs[$pid] : '');
            if ($val === null) {
                if (self::isRequiredNow($param, $selected)) {
                    $errors[] = 'Uzupełnij wymagany parametr „' . ($param['name'] ?? $pid) . '".';
                }
                continue;
            }
            if ($forProduct) {
                $productParams[] = $val;
            } else {
                $offerParams[] = $val;
            }
        }

        if ($s('shipping_rate') === '') {
            $errors[] = 'Wybierz cennik dostawy.';
        }
        if ($s('return_policy') === '') {
            $errors[] = 'Wybierz warunki zwrotów.';
        }
        if ($s('implied_warranty') === '') {
            $errors[] = 'Wybierz warunki reklamacji.';
        }
        if (!isset(self::INVOICES[$s('invoice')])) {
            $errors[] = 'Wybierz rodzaj faktury.';
        }
        if ($s('producer') === '') {
            $errors[] = 'Wybierz producenta (GPSR).';
        }
        [$safety, $safetyErr] = AllegroOfferOperations::safetyPayload([
            'safety_mode' => $form['safety_mode'] ?? 'text',
            'safety_text' => $form['safety_text'] ?? '',
        ]);
        if ($safety === null) {
            $errors[] = $safetyErr;
        }
        $province = $s('province');
        if ($province !== '' && !isset(self::PROVINCES[$province])) {
            $errors[] = 'Nieznane województwo.';
        }
        $postCode = $s('post_code');
        if ($postCode !== '' && !preg_match('/^\d{2}-\d{3}$/', $postCode)) {
            $errors[] = 'Kod pocztowy wpisz w formacie 00-000.';
        }

        $product = $catalogId !== '' ? ['id' => $catalogId] : [
            'name'       => $title,
            'category'   => ['id' => $categoryId],
            'parameters' => $productParams,
            'images'     => $images,
        ];
        $set = ['product' => $product, 'responsibleProducer' => ['id' => $s('producer')]];
        if ($setQty > 1) {
            $set['quantity'] = ['value' => $setQty];     // „Wiele sztuk jako zestaw"
        }
        if ($s('person') !== '') {
            $set['responsiblePerson'] = ['id' => $s('person')];
        }
        if ($safety !== null) {
            $set['safetyInformation'] = $safety;
        }

        $after = [
            'returnPolicy'    => ['id' => $s('return_policy')],
            'impliedWarranty' => ['id' => $s('implied_warranty')],
        ];
        if ($s('warranty') !== '') {
            $after['warranty'] = ['id' => $s('warranty')];
        }

        $messageMode = isset(self::MESSAGE_MODES[$s('message_mode')]) ? $s('message_mode') : 'OPTIONAL';
        $attachments = array_map(static fn($a) => ['id' => $a['id']], self::attachments($form));

        $delivery = ['shippingRates' => ['id' => $s('shipping_rate')], 'handlingTime' => $s('handling') !== '' ? $s('handling') : 'PT24H'];
        if ($shipmentDate !== null) {
            $delivery['shipmentDate'] = $shipmentDate;      // przedsprzedaż: data, od której wysyłamy
        }
        if ($deliveryInfo !== '') {
            $delivery['additionalInfo'] = $deliveryInfo;
        }
        // Kolejne produkty z Katalogu: ten sam producent i informacje o bezpieczeństwie co pierwszy produkt.
        $productSet = [$set];
        foreach (self::extraProducts($form) as $xp) {
            if ($xp['id'] === $catalogId) {
                $errors[] = 'Produkt „' . ($xp['name'] ?: $xp['id']) . '" jest już pierwszym produktem oferty — więcej sztuk ustawisz w „Wiele sztuk jako zestaw”.';
                continue;
            }
            $extra = ['product' => ['id' => $xp['id']], 'responsibleProducer' => $set['responsibleProducer']];
            if ($xp['quantity'] > 1) {
                $extra['quantity'] = ['value' => $xp['quantity']];
            }
            foreach (['responsiblePerson', 'safetyInformation'] as $k) {
                if (isset($set[$k])) {
                    $extra[$k] = $set[$k];
                }
            }
            $productSet[] = $extra;
        }
        $markets = self::marketPrices($form);
        if ($markets !== [] && $auction) {
            $errors[] = 'Ceny na rynkach dodatkowych działają tylko w ofercie „Kup teraz”, nie w licytacji.';
        }
        [, $ruleErr] = self::priceRule($form);
        if ($ruleErr !== '') {
            $errors[] = $ruleErr;
        }
        $money = static fn(float $v): array => ['amount' => number_format($v, 2, '.', ''), 'currency' => 'PLN'];
        $sellingMode = $auction
            ? array_filter(['format' => 'AUCTION', 'startingPrice' => $money($price), 'minimalPrice' => $minimal > 0 ? $money($minimal) : null])
            : ['format' => 'BUY_NOW', 'price' => $money($price)];
        $unit = isset(self::STOCK_UNITS[$s('stock_unit')]) ? $s('stock_unit') : 'UNIT';

        $payload = [
            'name'        => $title,
            'productSet'  => $productSet,
            'category'    => ['id' => $categoryId],
            'parameters'  => $offerParams,
            'images'      => $images,
            'description' => ['sections' => $sections],
            'sellingMode' => $sellingMode,
            'stock'       => ['available' => max(0, $stock), 'unit' => $unit],
            'delivery'    => $delivery,
            'afterSalesServices' => $after,
            'payments'    => ['invoice' => $s('invoice')],
            'location'    => array_filter(['countryCode' => 'PL', 'province' => $province, 'city' => $s('city'), 'postCode' => $postCode],
                static fn($v) => $v !== ''),
            'language'    => 'pl-PL',
            'publication' => $publication,
            'messageToSellerSettings' => ['mode' => $messageMode],
            'b2b'         => ['buyableOnlyByBusiness' => $s('buyers') === 'business'],
        ];
        if ($attachments !== []) {
            $payload['attachments'] = $attachments;
        }
        if ($markets !== [] && !$auction) {
            $payload['additionalMarketplaces'] = $markets;
        }
        if ($s('additional_services') !== '') {
            $payload['additionalServices'] = ['id' => $s('additional_services')];
        }
        if ($s('wholesale_price_list') !== '') {
            $payload['discounts'] = ['wholesalePriceList' => ['id' => $s('wholesale_price_list')]];
        }
        if ($s('signature') !== '') {
            $payload['external'] = ['id' => $s('signature')];
        }
        // VAT (taxSettings): przedmiot, podstawa zwolnienia i stawki dla krajów - przy fakturze VAT / VAT-marża.
        $rates = [];
        $vat = (array) ($form['vat'] ?? []);
        if ($vat === [] && $s('vat_rate') !== '') {
            $vat = ['PL' => $s('vat_rate')];          // szablony sprzed stawek dla wielu krajów
        }
        foreach (self::VAT_COUNTRIES as $cc => $_) {
            $r = trim((string) ($vat[$cc] ?? ''));
            if ($r !== '') {
                $rates[] = ['rate' => $r, 'countryCode' => $cc];
            }
        }
        if (in_array($s('invoice'), ['VAT', 'VAT_MARGIN'], true) && ($rates !== [] || $s('tax_subject') !== '' || $s('tax_exemption') !== '')) {
            $payload['taxSettings'] = array_filter([
                'subject'   => $s('tax_subject') !== '' ? $s('tax_subject') : 'GOODS',
                'exemption' => $s('tax_exemption'),
                'rates'     => $rates,
            ], static fn($v) => $v !== '' && $v !== []);
        }

        return ['payload' => $payload, 'errors' => array_values(array_unique($errors))];
    }
}
