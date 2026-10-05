<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Support\DescriptionSections;
use Pase\Support\Logger;
use PasePlugin\Allegro\AllegroClient;

/**
 * Operacje zbiorcze na ofertach Allegro (Zarządzanie ofertami → Operacje), jak w BaseLinkerze.
 *
 * Każda operacja dostaje listę ID ofert i parametry z formularza, a zwraca wynik per oferta.
 * Zmiany pojedynczych pól idą przez PATCH /sale/product-offers/{id} (Allegro stosuje je od razu),
 * zakończenie/aktywacja - jednym poleceniem publikacji dla wszystkich zaznaczonych ofert.
 */
final class AllegroOfferOperations
{
    /** Operacje: klucz => [etykieta, grupa]. Grupa: update (lewa kolumna menu) / other (prawa). */
    public const OPERATIONS = [
        'price'          => ['Zmień cenę', 'update'],
        'stock'          => ['Zmień ilość', 'update'],
        'title'          => ['Zmień tytuł', 'update'],
        'description'    => ['Zmień opis (z magazynu CRM)', 'update'],
        'shipping_rate'  => ['Zmień cennik wysyłek', 'update'],
        'handling_time'  => ['Zmień czas wysyłki', 'update'],
        'responsible_person' => ['Przypisz osobę odpowiedzialną (GPSR)', 'update'],
        'responsible_producer' => ['Przypisz producenta (GPSR)', 'update'],
        'safety_info'    => ['Ustaw informacje o bezpieczeństwie (GPSR)', 'update'],
        'duplicate'      => ['Wystaw ponownie (duplikuj)', 'list'],
        'end'            => ['Zakończ', 'other'],
        'activate'       => ['Wznów / aktywuj', 'other'],
        'link_products'  => ['Powiąż z produktami z magazynu', 'other'],
        'export'         => ['Eksportuj do pliku (CSV)', 'other'],
    ];

    /** Czas wysyłki (delivery.handlingTime) - wartości przyjmowane przez Allegro. */
    public const HANDLING_TIMES = [
        'PT0S'  => 'natychmiast',
        'PT24H' => '24 godziny',
        'P2D'   => '2 dni',
        'P3D'   => '3 dni',
        'P4D'   => '4 dni',
        'P5D'   => '5 dni',
        'P7D'   => '7 dni',
        'P10D'  => '10 dni',
        'P14D'  => '14 dni',
        'P21D'  => '21 dni',
        'P30D'  => '30 dni',
        'P60D'  => '60 dni',
    ];
    public const DEFAULT_HANDLING_SETTING = 'allegro_default_handling_time';

    public function __construct(private readonly PDO $pdo, private readonly AllegroClient $client) {}

    /**
     * @param string[] $ids
     * @param array<string,string> $p parametry operacji z formularza
     * @return array{ok:int,fail:int,errors:array<int,string>,info:string,applied:array<string,array<string,mixed>>}
     *         applied: nowe wartości zapisanych ofert (lista Allegro odświeża się z opóźnieniem - panel je nakłada)
     */
    public function run(string $op, array $ids, array $p): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn($i) => preg_replace('/\D/', '', (string) $i), $ids))));
        $res = ['ok' => 0, 'fail' => 0, 'errors' => [], 'info' => '', 'applied' => []];
        if ($ids === []) {
            $res['errors'][] = 'Nie zaznaczono żadnej oferty.';
            return $res;
        }

        if ($op === 'end' || $op === 'activate') {
            return $this->publication($ids, $op === 'end' ? 'END' : 'ACTIVATE');
        }

        $offers = in_array($op, ['price', 'stock', 'title', 'description', 'link_products'], true) ? $this->offersById($ids) : [];

        foreach ($ids as $id) {
            $o = $offers[$id] ?? null;
            try {
                $r = match ($op) {
                    'price'         => $this->price($id, $o, $p),
                    'stock'         => $this->stock($id, $o, $p),
                    'title'         => $this->title($id, $o, $p),
                    'description'   => $this->description($id, $o),
                    'shipping_rate' => $this->shippingRate($id, $p),
                    'handling_time' => isset(self::HANDLING_TIMES[$p['handling'] ?? ''])
                        ? $this->client->patchOffer($id, ['delivery' => ['handlingTime' => $p['handling']]]) + ['applied' => ['handling' => $p['handling']]]
                        : ['ok' => false, 'message' => 'Nie wybrano czasu wysyłki.'],
                    'responsible_person' => $this->gpsr($id, 'responsiblePerson', (string) ($p['person'] ?? ''), 'osoby odpowiedzialnej'),
                    'responsible_producer' => $this->gpsr($id, 'responsibleProducer', (string) ($p['producer'] ?? ''), 'producenta'),
                    'safety_info'   => $this->safetyInfo($id, $p),
                    'duplicate'     => $this->duplicate($id, !empty($p['activate'])),
                    'link_products' => $this->link($id, $o),
                    default         => ['ok' => false, 'message' => 'Nieznana operacja.'],
                };
            } catch (\Throwable $e) {
                $r = ['ok' => false, 'message' => $e->getMessage()];
            }
            if ($r['ok']) {
                $res['ok']++;
                if (!empty($r['applied'])) {
                    $res['applied'][$id] = $r['applied'];
                }
                if (!empty($r['message'])) {
                    $res['info'] .= ($res['info'] !== '' ? ' ' : '') . $r['message'];
                }
            } else {
                $res['fail']++;
                $res['errors'][] = "{$id}: {$r['message']}";
            }
        }
        Logger::info("Allegro operacja [{$op}]: {$res['ok']} OK, {$res['fail']} błędów");
        return $res;
    }

    /**
     * Wszystkie oferty w danym statusie (GET /sale/offers po 1000) - do filtrowania i sortowania w panelu,
     * bo API nie filtruje m.in. po ilości ani czasie wysyłki.
     * @return array{ok:bool,offers:array<int,array<string,mixed>>,message:string}
     */
    public function allOffers(string $status): array
    {
        $all = [];
        for ($offset = 0, $guard = 0; $guard < 50; $guard++) {
            $r = $this->client->listOffers(['publication.status' => $status, 'limit' => 1000, 'offset' => $offset]);
            if (!$r['ok']) {
                return ['ok' => $all !== [], 'offers' => $all, 'message' => $r['message']];
            }
            $all = array_merge($all, $r['offers']);
            $offset += 1000;
            if (count($r['offers']) < 1000 || $offset >= $r['total']) {
                break;
            }
        }
        return ['ok' => true, 'offers' => $all, 'message' => ''];
    }

    /** Czas wysyłki itp. z pamięci podręcznej. @return array<string,array{handling_time:?string,shipping_rate_id:?string,fetched_at:string}> */
    public function details(array $ids): array
    {
        $ids = array_values(array_filter(array_map('strval', $ids)));
        if ($ids === []) {
            return [];
        }
        try {
            $out = [];
            foreach (array_chunk($ids, 500) as $chunk) {
                $st = $this->pdo->prepare('SELECT * FROM allegro_offer_details WHERE offer_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
                $st->execute($chunk);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out[(string) $r['offer_id']] = $r;
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Pobiera z Allegro pełne dane ofert i zapisuje czas wysyłki. @return array<string,?string> id => handlingTime */
    public function fetchDetails(array $ids): array
    {
        $out = [];
        foreach (array_slice(array_values(array_unique(array_map('strval', $ids))), 0, 20) as $id) {
            $full = $this->client->productOffer($id);
            if ($full === null) {
                continue;
            }
            $h = (string) ($full['delivery']['handlingTime'] ?? '');
            $this->storeDetails($id, ['handling_time' => $h !== '' ? $h : null,
                                      'shipping_rate_id' => $full['delivery']['shippingRates']['id'] ?? null]);
            $out[$id] = $h !== '' ? $h : null;
        }
        return $out;
    }

    /**
     * Powody statusu ofert („Szczegóły statusu oferty" na Allegro): błędy i ostrzeżenia walidacji
     * z GET /sale/product-offers/{id}. Przy okazji zapisuje czas wysyłki (to samo zapytanie).
     * @return array<string,array<int,array{level:string,text:string,fix:?string}>> id => powody
     */
    public function fetchIssues(array $ids): array
    {
        $out = [];
        foreach (array_slice(array_values(array_unique(array_map('strval', $ids))), 0, 20) as $id) {
            $full = $this->client->productOffer($id);
            if ($full === null) {
                continue;
            }
            $h = (string) ($full['delivery']['handlingTime'] ?? '');
            $this->storeDetails($id, ['handling_time' => $h !== '' ? $h : null,
                                      'shipping_rate_id' => $full['delivery']['shippingRates']['id'] ?? null]);
            $out[$id] = self::offerIssues($full);
        }
        return $out;
    }

    /**
     * Czytelne powody z pola validation oferty. Błędy (errors) blokują wystawienie/aktywację, ostrzeżenia
     * (warnings) nie. Allegro zgłasza ten sam brak osobno dla każdego produktu w ofercie - tu bez powtórzeń.
     * fix: co w CRM pomaga to poprawić - producer / person (operacja GPSR), safety (dane na Allegro) albo null.
     * @param array<string,mixed> $offer odpowiedź GET /sale/product-offers/{id}
     * @return array<int,array{level:string,text:string,fix:?string}>
     */
    public static function offerIssues(array $offer): array
    {
        $out = $seen = [];
        foreach (['errors' => 'error', 'warnings' => 'warning'] as $key => $level) {
            foreach ((array) ($offer['validation'][$key] ?? []) as $err) {
                if (!is_array($err)) {
                    continue;
                }
                $text = trim((string) ($err['userMessage'] ?? ''));
                if ($text === '') {
                    $text = trim((string) ($err['message'] ?? ''));
                }
                if ($text === '') {
                    $text = (string) ($err['code'] ?? 'Nieznany błąd oferty');
                }
                $hay = strtolower(($err['path'] ?? '') . ' ' . ($err['code'] ?? '') . ' ' . ($err['message'] ?? ''));
                $fix = match (true) {
                    str_contains($hay, 'responsibleproducer') || str_contains($hay, 'responsible_producer') || str_contains($hay, 'producer') => 'producer',
                    str_contains($hay, 'responsibleperson') || str_contains($hay, 'responsible_person') || str_contains($hay, 'responsible person') => 'person',
                    str_contains($hay, 'safetyinformation') || str_contains($hay, 'safety_information') || str_contains($hay, 'safety') => 'safety',
                    default => null,
                };
                if (isset($seen[$level . '|' . $text])) {
                    continue;
                }
                $seen[$level . '|' . $text] = true;
                $out[] = ['level' => $level, 'text' => $text, 'fix' => $fix];
            }
        }
        return $out;
    }

    /** @param array<string,?string> $fields */
    public function storeDetails(string $id, array $fields): void
    {
        try {
            $cur = $this->details([$id])[$id] ?? [];
            $this->pdo->prepare('REPLACE INTO allegro_offer_details (offer_id, handling_time, shipping_rate_id, fetched_at) VALUES (?, ?, ?, NOW())')
                ->execute([$id, array_key_exists('handling_time', $fields) ? $fields['handling_time'] : ($cur['handling_time'] ?? null),
                           array_key_exists('shipping_rate_id', $fields) ? $fields['shipping_rate_id'] : ($cur['shipping_rate_id'] ?? null)]);
        } catch (\Throwable $e) {
            // brak tabeli (migracja) - pomijamy
        }
    }

    /**
     * Czas wysyłki w godzinach z zapisu ISO 8601, którego używa Allegro:
     * „PT48H" = 48 godzin, „P2D" = 2 dni, „PT0S" = natychmiast. Null gdy nieczytelny.
     */
    public static function handlingHours(?string $h): ?int
    {
        if ($h === null || !preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', strtoupper(trim($h)), $m) || $h === 'P') {
            return null;
        }
        return (int) ($m[1] ?? 0) * 24 + (int) ($m[2] ?? 0) + intdiv((int) ($m[3] ?? 0), 60);
    }

    /** Czytelny czas wysyłki: „P2D" / „PT48H" -> „2 dni", „PT24H" -> „24 godziny", „PT0S" -> „natychmiast". */
    public static function handlingLabel(?string $h): string
    {
        if ($h === null || $h === '') {
            return '';
        }
        if (isset(self::HANDLING_TIMES[$h])) {
            return self::HANDLING_TIMES[$h];
        }
        $hours = self::handlingHours($h);
        if ($hours === null) {
            return $h;
        }
        foreach (self::HANDLING_TIMES as $k => $label) {
            if (self::handlingHours($k) === $hours) {
                return $label;
            }
        }
        if ($hours === 0) {
            return 'natychmiast';
        }
        if ($hours % 24 === 0) {
            $d = intdiv($hours, 24);
            return $d . ' ' . ($d === 1 ? 'dzień' : 'dni');
        }
        return $hours . ' godz.';
    }

    /** Czy dwa zapisy oznaczają ten sam czas (np. „PT48H" i „P2D"). */
    public static function sameHandling(?string $a, ?string $b): bool
    {
        $ha = self::handlingHours($a);
        return $ha !== null && $ha === self::handlingHours($b);
    }

    /** @return array<string,array<string,mixed>> oferty z GET /sale/offers (cena, stan, nazwa, sygnatura) */
    public function offersById(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 20) as $chunk) {
            $q = implode('&', array_map(static fn($i) => 'offer.id=' . rawurlencode((string) $i), $chunk)) . '&limit=' . count($chunk);
            foreach ($this->client->listOffersRaw($q) as $o) {
                $out[(string) $o['id']] = $o;
            }
        }
        // Oferty, których lista nie zwróciła (np. inny status) - pełne dane pojedynczo.
        foreach ($ids as $id) {
            if (!isset($out[$id]) && ($full = $this->client->productOffer($id)) !== null) {
                $out[$id] = $full;
            }
        }
        return $out;
    }

    private function price(string $id, ?array $o, array $p): array
    {
        $cur = (float) ($o['sellingMode']['price']['amount'] ?? 0);
        $currency = (string) ($o['sellingMode']['price']['currency'] ?? 'PLN');
        $v = (float) str_replace(',', '.', (string) ($p['value'] ?? '0'));
        $new = match ($p['mode'] ?? 'set') {
            'inc_pct' => $cur * (1 + $v / 100),
            'dec_pct' => $cur * (1 - $v / 100),
            'inc_amt' => $cur + $v,
            'dec_amt' => $cur - $v,
            default   => $v,
        };
        if (($p['mode'] ?? 'set') !== 'set' && $o === null) {
            return ['ok' => false, 'message' => 'Nie udało się odczytać obecnej ceny.'];
        }
        if (!empty($p['end99'])) {
            $new = floor($new) + 0.99;
        }
        $new = round($new, 2);
        if ($new < 1) {
            return ['ok' => false, 'message' => 'Cena wyszłaby poniżej 1 zł (' . number_format($new, 2, ',', '') . ').'];
        }
        $r = $this->client->patchOffer($id, ['sellingMode' => ['price' => ['amount' => number_format($new, 2, '.', ''), 'currency' => $currency]]]);
        return $r + ['applied' => ['price' => number_format($new, 2, '.', '')]];
    }

    private function stock(string $id, ?array $o, array $p): array
    {
        $cur = (int) ($o['stock']['available'] ?? 0);
        $v = (int) ($p['value'] ?? 0);
        $mode = $p['mode'] ?? 'set';
        if ($mode !== 'set' && $o === null) {
            return ['ok' => false, 'message' => 'Nie udało się odczytać obecnej ilości.'];
        }
        $new = max(0, match ($mode) { 'inc' => $cur + $v, 'dec' => $cur - $v, default => $v });
        return $this->client->patchOffer($id, ['stock' => ['available' => $new]]) + ['applied' => ['stock' => $new]];
    }

    private function title(string $id, ?array $o, array $p): array
    {
        $cur = (string) ($o['name'] ?? '');
        $v = trim((string) ($p['value'] ?? ''));
        $new = match ($p['mode'] ?? 'set') {
            'prefix'  => trim($v . ' ' . $cur),
            'suffix'  => trim($cur . ' ' . $v),
            'replace' => ($p['find'] ?? '') !== '' ? str_replace((string) $p['find'], $v, $cur) : $cur,
            default   => $v,
        };
        $new = trim((string) preg_replace('/\s+/u', ' ', $new));
        if ($new === '' || ($o === null && ($p['mode'] ?? 'set') !== 'set')) {
            return ['ok' => false, 'message' => 'Pusty tytuł albo brak obecnego tytułu.'];
        }
        if (mb_strlen($new) > 75) {
            return ['ok' => false, 'message' => 'Tytuł ma ' . mb_strlen($new) . ' znaków (Allegro pozwala na 75).'];
        }
        if ($new === $cur) {
            return ['ok' => true, 'message' => ''];
        }
        return $this->client->patchOffer($id, ['name' => $new]) + ['applied' => ['name' => $new]];
    }

    /** Opis z produktu w magazynie CRM (po sygnaturze oferty albo powiązaniu), wg wybranych sekcji. */
    private function description(string $id, ?array $o): array
    {
        $sku = $this->skuFor($id, $o);
        if ($sku === null) {
            return ['ok' => false, 'message' => 'Brak powiązanego produktu w magazynie (sygnatura/powiązanie).'];
        }
        $st = $this->pdo->prepare('SELECT * FROM products WHERE sku = ? LIMIT 1');
        $st->execute([$sku]);
        $product = $st->fetch(PDO::FETCH_ASSOC);
        $html = $product ? DescriptionSections::allegroHtml($product, DescriptionSections::defaultExcluded($this->pdo)) : '';
        if (trim(strip_tags($html)) === '') {
            return ['ok' => false, 'message' => "Produkt {$sku} nie ma opisu w magazynie."];
        }
        return $this->client->patchOffer($id, ['description' => ['sections' => [['items' => [['type' => 'TEXT', 'content' => $html]]]]]]);
    }

    private function shippingRate(string $id, array $p): array
    {
        $rate = trim((string) ($p['rate'] ?? ''));
        if ($rate === '') {
            return ['ok' => false, 'message' => 'Nie wybrano cennika.'];
        }
        return $this->client->patchOffer($id, ['delivery' => ['shippingRates' => ['id' => $rate]]]) + ['applied' => ['rate' => $rate]];
    }

    /**
     * GPSR dla wszystkich produktów oferty: productSet[].responsiblePerson / responsibleProducer = {id}.
     * '__none' usuwa przypisanie. Pozostałe dane produktów zostają bez zmian.
     */
    private function gpsr(string $id, string $field, string $value, string $what): array
    {
        $value = trim($value);
        if ($value === '') {
            return ['ok' => false, 'message' => "Nie wybrano {$what}."];
        }
        $src = $this->client->productOffer($id);
        if ($src === null) {
            return ['ok' => false, 'message' => 'Nie udało się pobrać oferty.'];
        }
        $set = [];
        foreach ($src['productSet'] ?? [] as $item) {
            if (empty($item['product']['id'])) {
                continue;
            }
            $item['product'] = ['id' => (string) $item['product']['id']];
            if ($value === '__none') {
                unset($item[$field]);
            } else {
                $item[$field] = ['id' => $value];
            }
            $set[] = $item;
        }
        if ($set === []) {
            return ['ok' => false, 'message' => "Oferta nie jest powiązana z produktem w katalogu Allegro — dane {$what} przypisuje się do produktu w ofercie."];
        }
        return $this->client->patchOffer($id, ['productSet' => $set]);
    }

    /** Maks. długość opisu informacji o bezpieczeństwie (type TEXT) wg Allegro. */
    public const SAFETY_TEXT_MAX = 5000;

    /**
     * productSet[].safetyInformation z formularza: tekst (TEXT, bez HTML, 1-5000 znaków, nowe linie dozwolone)
     * albo deklaracja „produkt nie ma informacji o bezpieczeństwie". Null + komunikat, gdy dane są błędne.
     * @param array<string,mixed> $p
     * @return array{0:?array<string,string>,1:string}
     */
    public static function safetyPayload(array $p): array
    {
        if (($p['safety_mode'] ?? 'text') === 'none') {
            return [['type' => 'NO_SAFETY_INFORMATION'], ''];
        }
        $text = str_replace(["\r\n", "\r"], "\n", strip_tags((string) ($p['safety_text'] ?? '')));
        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
        if ($text === '') {
            return [null, 'Wpisz informacje o bezpieczeństwie.'];
        }
        if (mb_strlen($text) > self::SAFETY_TEXT_MAX) {
            return [null, 'Informacje o bezpieczeństwie mogą mieć maks. ' . self::SAFETY_TEXT_MAX . ' znaków (jest ' . mb_strlen($text) . ').'];
        }
        return [['type' => 'TEXT', 'description' => $text], ''];
    }

    /**
     * Obecne informacje o bezpieczeństwie oferty (z pierwszego produktu) - do podpowiedzi w oknie edycji.
     * @return array{ok:bool,type:string,text:string,message:string}
     */
    public function currentSafetyInfo(string $id): array
    {
        $src = $this->client->productOffer($id);
        if ($src === null) {
            return ['ok' => false, 'type' => '', 'text' => '', 'message' => 'Nie udało się pobrać oferty.'];
        }
        foreach ($src['productSet'] ?? [] as $item) {
            $si = $item['safetyInformation'] ?? null;
            if (is_array($si)) {
                return ['ok' => true, 'type' => (string) ($si['type'] ?? ''), 'text' => (string) ($si['description'] ?? ''), 'message' => ''];
            }
        }
        return ['ok' => true, 'type' => '', 'text' => '', 'message' => ''];
    }

    /** Informacje o bezpieczeństwie dla wszystkich produktów oferty. */
    private function safetyInfo(string $id, array $p): array
    {
        [$info, $err] = self::safetyPayload($p);
        if ($info === null) {
            return ['ok' => false, 'message' => $err];
        }
        $src = $this->client->productOffer($id);
        if ($src === null) {
            return ['ok' => false, 'message' => 'Nie udało się pobrać oferty.'];
        }
        $set = [];
        foreach ($src['productSet'] ?? [] as $item) {
            if (empty($item['product']['id'])) {
                continue;
            }
            $item['product'] = ['id' => (string) $item['product']['id']];
            $item['safetyInformation'] = $info;
            $set[] = $item;
        }
        if ($set === []) {
            return ['ok' => false, 'message' => 'Oferta nie jest powiązana z produktem w katalogu Allegro — informacje o bezpieczeństwie przypisuje się do produktu w ofercie.'];
        }
        return $this->client->patchOffer($id, ['productSet' => $set]);
    }

    /** Nowa oferta z kopią danych istniejącej (bez sprzedaży/historii). Domyślnie nieaktywna - do sprawdzenia. */
    private function duplicate(string $id, bool $activate): array
    {
        $src = $this->client->productOffer($id);
        if ($src === null) {
            return ['ok' => false, 'message' => 'Nie udało się pobrać oferty.'];
        }
        foreach (['id', 'publication', 'validation', 'warnings', 'createdAt', 'updatedAt', 'afterSalesServicesConditions'] as $k) {
            unset($src[$k]);
        }
        unset($src['stock']['sold']);
        $r = $this->client->createOffer($src);
        if (!$r['ok']) {
            return ['ok' => false, 'message' => $r['message']];
        }
        $msg = "Nowa oferta {$r['offer_id']}" . ($activate ? '' : ' (nieaktywna — sprawdź i aktywuj).');
        if ($activate && $r['offer_id']) {
            $pc = $this->client->publicationCommand([$r['offer_id']], 'ACTIVATE');
            $msg .= $pc['ok'] ? ' (aktywacja zlecona).' : ' (nie udało się aktywować: ' . $pc['message'] . ').';
        }
        return ['ok' => true, 'message' => $msg];
    }

    /** Powiązanie oferty z produktem magazynu po sygnaturze (external.id) albo EAN. */
    private function link(string $id, ?array $o): array
    {
        $sig = trim((string) ($o['external']['id'] ?? ''));
        $sku = null;
        if ($sig !== '') {
            $st = $this->pdo->prepare('SELECT sku FROM products WHERE sku = ? LIMIT 1');
            $st->execute([$sig]);
            $sku = $st->fetchColumn() ?: null;
        }
        if ($sku === null) {
            $full = $this->client->productOffer($id);
            $ean = null;
            foreach (($full['productSet'][0]['product']['parameters'] ?? []) as $par) {
                if (in_array((string) ($par['id'] ?? ''), ['225693'], true) || stripos((string) ($par['name'] ?? ''), 'EAN') !== false) {
                    $ean = (string) ($par['values'][0] ?? '');
                }
            }
            if ($ean) {
                $st = $this->pdo->prepare('SELECT sku FROM products WHERE ean = ? LIMIT 1');
                $st->execute([$ean]);
                $sku = $st->fetchColumn() ?: null;
            }
        }
        if ($sku === null) {
            return ['ok' => false, 'message' => 'Nie znaleziono produktu w magazynie (sygnatura ' . ($sig ?: '—') . ').'];
        }
        $this->pdo->prepare('INSERT INTO product_mappings (sku, allegro_offer_id) VALUES (?, ?)
                             ON DUPLICATE KEY UPDATE allegro_offer_id = VALUES(allegro_offer_id)')->execute([$sku, $id]);
        return ['ok' => true, 'message' => ''];
    }

    private function skuFor(string $id, ?array $o): ?string
    {
        $st = $this->pdo->prepare('SELECT sku FROM product_mappings WHERE allegro_offer_id = ? LIMIT 1');
        $st->execute([$id]);
        if (($sku = $st->fetchColumn()) !== false && $sku !== '') {
            return (string) $sku;
        }
        $sig = trim((string) ($o['external']['id'] ?? ''));
        if ($sig !== '') {
            $st = $this->pdo->prepare('SELECT sku FROM products WHERE sku = ? LIMIT 1');
            $st->execute([$sig]);
            if (($sku = $st->fetchColumn()) !== false) {
                return (string) $sku;
            }
        }
        return null;
    }

    /** Zakończenie / aktywacja: jedno polecenie, potem krótko czekamy na wynik per oferta. */
    private function publication(array $ids, string $action): array
    {
        $res = ['ok' => 0, 'fail' => 0, 'errors' => [], 'info' => '', 'applied' => []];
        $newStatus = $action === 'END' ? 'ENDED' : 'ACTIVE';
        foreach (array_chunk($ids, 1000) as $chunk) {
            $cmd = $this->client->publicationCommand($chunk, $action);
            if (!$cmd['ok']) {
                $res['fail'] += count($chunk);
                $res['errors'][] = $cmd['message'];
                continue;
            }
            $tasks = [];
            for ($i = 0; $i < 6; $i++) {
                usleep(700000);
                $tasks = $this->client->publicationCommandTasks((string) $cmd['command_id']);
                $pending = array_filter($tasks, static fn($t) => $t['status'] === 'NEW');
                if ($tasks !== [] && $pending === []) {
                    break;
                }
            }
            if ($tasks === []) {
                $res['ok'] += count($chunk);
                foreach ($chunk as $cid) {
                    $res['applied'][$cid] = ['status' => $newStatus];
                }
                $res['info'] = 'Allegro przyjęło polecenie — zmiana pojawi się w ciągu kilku minut.';
                continue;
            }
            foreach ($tasks as $t) {
                if (in_array($t['status'], ['SUCCESS', 'NEW'], true)) {
                    $res['ok']++;
                    $res['applied'][$t['offer']] = ['status' => $newStatus];
                } else {
                    $res['fail']++;
                    $res['errors'][] = $t['offer'] . ': ' . ($t['message'] ?: $t['status']);
                }
            }
        }
        return $res;
    }
}
