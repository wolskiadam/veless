<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Automation\RuleEngine;
use Pase\Repository\AutomationRuleRepository;
use Pase\Repository\SettingsRepository;
use Pase\Repository\ShipmentRepository;
use Pase\Repository\WooOrderRepository;
use Pase\Support\Logger;
use Pase\Support\Runtime;
use PasePlugin\Allegro\AllegroClient;

/**
 * Śledzenie przesyłek - etap u przewoźnika (nadana, w drodze, w doręczeniu, doręczona...).
 *
 * Źródło danych: API śledzenia Allegro (GET /order/carriers/{carrierId}/tracking), które
 * obsługuje wielu przewoźników (InPost, DPD, DHL, GLS, UPS, FedEx, Poczta Polska, Orlen,
 * Allegro One...), nie tylko paczki z „Wysyłam z Allegro". Wystarczy połączone konto
 * Allegro i numer listu + rozpoznany przewoźnik. Allegro zna jednak tylko paczki związane
 * ze swoimi zamówieniami, dlatego InPost pytamy najpierw bezpośrednio (publiczne API śledzenia
 * InPost, bez klucza) - działa też dla paczek spoza Allegro (BLPaczka, „Inne", WooCommerce).
 * Wtyczka kurierska może mieć własne śledzenie (metody trackingCarrier() i trackWaybills(), np. ORLEN Paczka):
 * pytamy ją o paczki nadane przez jej konto i o paczki jej przewoźnika wpisane ręcznie, zanim zapytamy Allegro.
 *
 * Każda ZMIANA etapu:
 *   - zapisuje się w shipments (tracking_status / tracking_code / tracking_at / tracking_events),
 *   - uruchamia reguły automatyzacji ze zdarzeniem 'shipment.status'
 *     (warunek „Status przesyłki", np. = doręczona -> ustaw status „Zakończone").
 *
 * Sprawdzamy tylko przesyłki aktywne: nadane (status 'created'), z numerem listu,
 * jeszcze nie doręczone/zwrócone i nie starsze niż MAX_AGE_DAYS.
 */
final class ShipmentTracking
{
    /** Etapy (klucz zapisywany w bazie i używany w warunkach reguł) => [etykieta, klasa pill]. */
    public const STATUSES = [
        'pending'          => ['Czeka na nadanie',      'muted'],
        'in_transit'       => ['W drodze',              'info'],
        'out_for_delivery' => ['W doręczeniu',          'info'],
        'ready_for_pickup' => ['Czeka w punkcie odbioru', 'warn'],
        'notice_left'      => ['Awizo',                 'warn'],
        'delivered'        => ['Doręczona',             'ok'],
        'returned'         => ['Zwrot do nadawcy',      'bad'],
        'issue'            => ['Problem z doręczeniem', 'bad'],
    ];

    /** Kody Allegro -> nasze etapy. */
    private const CODE_MAP = [
        'PENDING'               => 'pending',
        'IN_TRANSIT'            => 'in_transit',
        'RELEASED_FOR_DELIVERY' => 'out_for_delivery',
        'AVAILABLE_FOR_PICKUP'  => 'ready_for_pickup',
        'NOTICE_LEFT'           => 'notice_left',
        'DELIVERED'             => 'delivered',
        'RETURNED'              => 'returned',
        'ISSUE'                 => 'issue',
    ];

    /** Etapy końcowe - po nich przestajemy pytać przewoźnika. */
    public const FINAL = ['delivered', 'returned'];

    /** Starszych przesyłek nie śledzimy (przewoźnicy i tak kasują historię). */
    private const MAX_AGE_DAYS = 45;

    /** Ile paczek InPost pytać bezpośrednio w jednym przebiegu (reszta przez Allegro / następnym razem). */
    private const INPOST_BATCH = 60;

    /** Ile przesyłek maksymalnie w jednym przebiegu harmonogramu. */
    private const BATCH_LIMIT = 300;

    /** Oznaczenie przesyłki, której przewoźnika nie rozpoznaliśmy. */
    public const NO_CARRIER = 'NO_CARRIER';

    /**
     * Słowa kluczowe w nazwie kuriera (courier_code) -> identyfikator przewoźnika w Allegro.
     * Kolejność ma znaczenie (pierwsze trafienie wygrywa).
     */
    private const KEYWORDS = [
        'allegro one' => 'ALLEGRO', 'one box' => 'ALLEGRO', 'one punkt' => 'ALLEGRO', 'one kurier' => 'ALLEGRO',
        'inpost' => 'INPOST', 'paczkomat' => 'INPOST',
        'dpd' => 'DPD',
        'dhl' => 'DHL',
        'gls' => 'GLS',
        'ups' => 'UPS',
        'fedex' => 'FEDEX',
        'pocztex' => 'POCZTA_POLSKA', 'poczta' => 'POCZTA_POLSKA', 'envelo' => 'POCZTA_POLSKA',
        'orlen' => 'ORLEN', 'ruch' => 'ORLEN',
        'geis' => 'GEIS', 'raben' => 'RABEN', 'schenker' => 'DB_SCHENKER',
    ];

    private const CARRIERS_CACHE_KEY = 'TRACKING_CARRIERS_CACHE';

    /** @var array<int,array{id:string,name:string}>|null */
    private ?array $carriers = null;

    /** @param AllegroClient $client (w testach atrapa z carriers() i carrierTracking()) */
    public function __construct(
        private readonly PDO $pdo,
        private readonly object $client,
        private readonly array $allegroConfig = [],
        /** @var \Closure(string):?array|null atrapa publicznego API InPost w testach (numer => JSON) */
        private readonly ?\Closure $inpostFetch = null,
        /** @var array<int,object>|null wtyczki z własnym śledzeniem (id konta => wtyczka); null = aktywne konta z bazy */
        private readonly ?array $trackers = null
    ) {}

    /** @var array<int,object>|null */
    private ?array $trackerList = null;

    public static function label(?string $status): string
    {
        return self::STATUSES[(string) $status][0] ?? '';
    }

    public static function pillClass(?string $status): string
    {
        return self::STATUSES[(string) $status][1] ?? 'muted';
    }

    /** Przebieg harmonogramu: sprawdza aktywne przesyłki. @return int liczba zmian etapu */
    public function run(): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM shipments
             WHERE status = 'created' AND waybill_no IS NOT NULL AND waybill_no <> ''
               AND (tracking_status IS NULL OR tracking_status NOT IN ('delivered','returned'))
               AND created_at >= ?
             ORDER BY tracking_checked_at IS NOT NULL, tracking_checked_at ASC, id DESC
             LIMIT " . self::BATCH_LIMIT
        );
        // Ile dni wstecz śledzić: Konfiguracja → Synchronizacja (TRACKING_MAX_AGE_DAYS), domyślnie MAX_AGE_DAYS.
        try {
            $days = TaskTimings::get(new \Pase\Repository\SettingsRepository($this->pdo), 'TRACKING_MAX_AGE_DAYS');
        } catch (\Throwable) {
            $days = self::MAX_AGE_DAYS;
        }
        $stmt->execute([date('Y-m-d H:i:s', strtotime('-' . $days . ' days'))]);
        return $this->track($stmt->fetchAll(PDO::FETCH_ASSOC))['changed'];
    }

    /**
     * Sprawdzenie „na żądanie" (przycisk na stronie zamówienia) - także przesyłek już doręczonych.
     * @return array{ok:bool,message:string,status:?string}
     */
    public function checkOne(int $shipmentId): array
    {
        $sh = (new ShipmentRepository($this->pdo))->find($shipmentId);
        if ($sh === null || trim((string) ($sh['waybill_no'] ?? '')) === '') {
            return ['ok' => false, 'message' => 'Przesyłka nie ma numeru listu — nie ma czego śledzić.', 'status' => null];
        }
        $r = $this->track([$sh], true);
        $fresh = (new ShipmentRepository($this->pdo))->find($shipmentId) ?? $sh;
        if ($r['errors'] !== []) {
            return ['ok' => false, 'message' => $r['errors'][0], 'status' => $fresh['tracking_status'] ?? null];
        }
        if (($fresh['tracking_code'] ?? '') === self::NO_CARRIER) {
            return ['ok' => false, 'status' => null,
                    'message' => 'Nie rozpoznano przewoźnika „' . ($sh['courier_code'] ?? '') . '" — śledzenie działa dla InPost, DPD, DHL, GLS, UPS, FedEx, Poczty Polskiej, Orlen i Allegro One.'];
        }
        $st = $fresh['tracking_status'] ?? null;
        return ['ok' => true, 'status' => $st,
                'message' => $st ? 'Status przesyłki: ' . self::label($st) . '.'
                    : 'Allegro nie ma informacji o tej przesyłce (sprawdzono przewoźnika: ' . ($r['tried'][$shipmentId] ?? '—') . ').'];
    }

    /**
     * @param array<int,array<string,mixed>> $rows wiersze shipments
     * @param bool $fallback gdy rozpoznany przewoźnik nic nie zwrócił, pytamy też inne pasujące id z listy Allegro
     *                       (np. Orlen Paczka bywa pod innym id, paczki z Allegro Delivery pod ALLEGRO) - tylko „na żądanie"
     * @return array{changed:int,errors:string[],tried:array<int,string>}
     */
    private function track(array $rows, bool $fallback = false): array
    {
        $repo    = new ShipmentRepository($this->pdo);
        $now     = date('Y-m-d H:i:s');
        $changed = 0;
        $errors  = [];
        $tried   = [];     // id przesyłki => sprawdzone id przewoźnika
        $noData  = [];     // przesyłki bez odpowiedzi (tylko $fallback)
        $failures = [];
        $inpostCalls = 0;  // InPost pytamy po jednej paczce - limit na przebieg

        // Wtyczki kurierskie z własnym śledzeniem (np. ORLEN Paczka): ich paczki pytamy najpierw u nich,
        // a to, czego nie znają, idzie dalej zwykłą drogą (InPost / Allegro).
        $rows = $this->trackOwn($rows, $now, $changed, $errors, $tried, $fallback);

        // Grupujemy po przewoźniku - jedno zapytanie obsługuje max 20 numerów jednego przewoźnika.
        $groups = [];
        foreach ($rows as $sh) {
            $carrier = $this->detectCarrier($sh);
            if ($carrier === null) {
                $repo->update((int) $sh['id'], ['tracking_code' => self::NO_CARRIER, 'tracking_checked_at' => $now]);
                continue;
            }
            // InPost: publiczne API śledzenia InPost (ShipX) zna każdą paczkę, także nadaną poza Allegro.
            if (strcasecmp($carrier, 'INPOST') === 0 && $inpostCalls++ < self::INPOST_BATCH
                && ($wb = $this->inpostTracking((string) $sh['waybill_no'])) !== null) {
                $tried[(int) $sh['id']] = 'InPost';
                $changed += $this->apply($sh, 'INPOST', $wb, $now) ? 1 : 0;
                continue;
            }
            $groups[$carrier][] = $sh;
        }

        foreach ($groups as $carrier => $list) {
            foreach (array_chunk($list, 20) as $chunk) {
                $byWaybill = [];
                foreach ($chunk as $sh) {
                    $byWaybill[self::normWaybill((string) $sh['waybill_no'])][] = $sh;
                }
                $res = $this->client->carrierTracking($carrier, array_map(static fn($s) => trim((string) $s['waybill_no']), $chunk));
                if (!$res['ok'] && $res['status'] === 0) {
                    // Brak połączonego konta Allegro - nie ma sensu pytać o kolejnych przewoźników.
                    return ['changed' => $changed, 'tried' => [],
                            'errors' => [$res['message'] . ' Śledzenie przesyłek korzysta z API Allegro — połącz konto w Integracjach.']];
                }
                foreach ($chunk as $sh) {
                    $tried[(int) $sh['id']] = $carrier;
                }
                if (!$res['ok']) {
                    if ($fallback) {
                        array_push($noData, ...$chunk);
                        $failures[] = "Śledzenie ({$carrier}): " . $res['message'];
                        continue;
                    }
                    $errors[] = "Śledzenie ({$carrier}): " . $res['message'];
                    Logger::warn("Śledzenie przesyłek ({$carrier}) nie powiodło się: " . $res['message']);
                    foreach ($chunk as $sh) {
                        $repo->update((int) $sh['id'], ['tracking_carrier' => $carrier, 'tracking_checked_at' => $now]);
                    }
                    continue;
                }
                $seen = [];
                foreach ($res['waybills'] as $wb) {
                    $key = self::normWaybill((string) ($wb['waybill'] ?? ''));
                    foreach ($byWaybill[$key] ?? [] as $sh) {
                        if (($wb['trackingDetails']['statuses'] ?? []) === [] && $fallback) {
                            continue;   // pusto - spróbujemy innego id przewoźnika
                        }
                        $seen[(int) $sh['id']] = true;
                        if ($this->apply($sh, $carrier, $wb, $now)) {
                            $changed++;
                        }
                    }
                }
                foreach ($chunk as $sh) {
                    if (!isset($seen[(int) $sh['id']])) {
                        if ($fallback) {
                            $noData[] = $sh;
                            continue;
                        }
                        $repo->update((int) $sh['id'], ['tracking_carrier' => $carrier, 'tracking_checked_at' => $now]);
                    }
                }
            }
        }

        // Na żądanie: pytamy pozostałe pasujące id przewoźnika, aż któreś zna przesyłkę.
        foreach ($noData as $sh) {
            $id = (int) $sh['id'];
            $first = $tried[$id];
            $found = false;
            foreach ($this->alternatives($first, $sh) as $alt) {
                $tried[$id] .= ', ' . $alt;
                $res = $this->client->carrierTracking($alt, [trim((string) $sh['waybill_no'])]);
                if (!$res['ok']) {
                    continue;
                }
                foreach ($res['waybills'] as $wb) {
                    if (self::normWaybill((string) ($wb['waybill'] ?? '')) === self::normWaybill((string) $sh['waybill_no'])
                        && ($wb['trackingDetails']['statuses'] ?? []) !== []) {
                        $changed += $this->apply($sh, $alt, $wb, $now) ? 1 : 0;
                        $found = true;
                        break 2;
                    }
                }
            }
            if (!$found) {
                $repo->update($id, ['tracking_carrier' => $first, 'tracking_checked_at' => $now]);
            }
        }
        if ($changed === 0 && $failures !== []) {
            $errors = $failures;   // żaden przewoźnik nie odpowiedział poprawnie - pokażmy błąd pierwszego
        }
        return ['changed' => $changed, 'errors' => $errors, 'tried' => $tried];
    }

    /**
     * Śledzenie przez wtyczki kurierskie z metodami trackingCarrier() i trackWaybills() (np. ORLEN Paczka).
     * Paczka trafia do wtyczki, gdy nadano ją przez jej konto albo gdy przewoźnik z nazwy kuriera to przewoźnik wtyczki
     * (np. paczka ORLEN Paczka wpisana ręcznie). Zwraca przesyłki, o których wtyczka nic nie wie.
     * @param array<int,array<string,mixed>> $rows
     * @param string[] $errors
     * @param array<int,string> $tried
     * @return array<int,array<string,mixed>>
     */
    private function trackOwn(array $rows, string $now, int &$changed, array &$errors, array &$tried, bool $onDemand = false): array
    {
        $trackers = $this->trackerPlugins();
        if ($trackers === []) {
            return $rows;
        }
        $byAccount = [];
        $rest = [];
        foreach ($rows as $sh) {
            $acc = $this->trackerFor($sh, $trackers);
            if ($acc === null) {
                $rest[] = $sh;
                continue;
            }
            $byAccount[$acc][] = $sh;
        }
        // Wtyczka, do której przewoźnik sam wysyła zmiany statusów (webhook, np. Uniwersalny Pusher ORLEN Paczka):
        // w harmonogramie pytamy ją tylko co PUSH_RECHECK_HOURS (zabezpieczenie na zgubione powiadomienie).
        $recheck = date('Y-m-d H:i:s', time() - self::PUSH_RECHECK_HOURS * 3600);
        foreach ($byAccount as $acc => $list) {
            $plugin = $trackers[$acc];
            if (!$onDemand && method_exists($plugin, 'pushActive') && $plugin->pushActive()) {
                $list = array_values(array_filter($list, static fn($s) => (string) ($s['tracking_checked_at'] ?? '') < $recheck));
                if ($list === []) {
                    continue;
                }
            }
            $carrier = (string) $plugin->trackingCarrier();
            try {
                $res = $plugin->trackWaybills(array_map(static fn($s) => trim((string) $s['waybill_no']), $list));
            } catch (\Throwable $e) {
                $res = ['ok' => false, 'message' => $e->getMessage(), 'waybills' => []];
            }
            $found = [];
            foreach ($res['waybills'] ?? [] as $wb => $statuses) {
                $found[self::normWaybill((string) $wb)] = $statuses;
            }
            foreach ($list as $sh) {
                $statuses = $found[self::normWaybill((string) $sh['waybill_no'])] ?? [];
                if ($statuses === []) {
                    if (empty($res['ok']) && (int) ($sh['integration_id'] ?? 0) === $acc) {
                        $errors[] = 'Śledzenie (' . $carrier . '): ' . ($res['message'] ?? 'błąd');
                        Logger::warn('Śledzenie przez wtyczkę nie powiodło się: ' . ($res['message'] ?? ''), ['account' => $acc]);
                    }
                    $rest[] = $sh;   // spróbujemy zwykłą drogą (Allegro)
                    continue;
                }
                $tried[(int) $sh['id']] = $carrier;
                $changed += $this->apply($sh, $carrier, ['waybill' => $sh['waybill_no'], 'trackingDetails' => ['statuses' => $statuses]], $now) ? 1 : 0;
            }
        }
        return $rest;
    }

    /** Id konta wtyczki, która śledzi tę przesyłkę (albo null). @param array<int,object> $trackers */
    private function trackerFor(array $sh, array $trackers): ?int
    {
        $acc = (int) ($sh['integration_id'] ?? 0);
        if ($acc > 0 && isset($trackers[$acc])) {
            return $acc;
        }
        $carrier = strtoupper((string) ($sh['tracking_carrier'] ?? ''));
        if ($carrier === '') {
            $name = mb_strtolower((string) ($sh['courier_code'] ?? ''));
            foreach (self::KEYWORDS as $kw => $guess) {
                if (preg_match('/(^|[^a-z])' . preg_quote($kw, '/') . '/u', $name)) {
                    $carrier = $guess;
                    break;
                }
            }
        }
        if ($carrier === '') {
            return null;
        }
        foreach ($trackers as $id => $p) {
            $own = strtoupper((string) $p->trackingCarrier());
            if ($carrier === $own || ($own === 'ORLEN' && in_array($carrier, ['PACZKA_W_RUCHU', 'ORLEN_PACZKA'], true))) {
                return $id;
            }
        }
        return null;
    }

    /** Aktywne konta wtyczek ze śledzeniem. @return array<int,object> */
    private function trackerPlugins(): array
    {
        if ($this->trackers !== null) {
            return $this->trackers;
        }
        if ($this->trackerList !== null) {
            return $this->trackerList;
        }
        $out = [];
        try {
            foreach ((new \Pase\Repository\IntegrationAccountRepository($this->pdo))->all() as $acc) {
                if (empty($acc['is_active'])) {
                    continue;
                }
                $p = \Pase\Plugin\PluginRegistry::forAccount((string) $acc['type'], $acc['config'] ?? []);
                if ($p !== null && method_exists($p, 'trackWaybills') && method_exists($p, 'trackingCarrier')) {
                    $out[(int) $acc['id']] = $p;
                }
            }
        } catch (\Throwable $e) {
            $out = [];
        }
        return $this->trackerList = $out;
    }

    /** Statusy InPost (ShipX) -> kody jak w Allegro (CODE_MAP) i opis dla historii. */
    private const INPOST_STATUSES = [
        'created' => ['PENDING', 'Przesyłka utworzona'], 'offers_prepared' => ['PENDING', 'Przesyłka utworzona'],
        'offer_selected' => ['PENDING', 'Przesyłka utworzona'], 'confirmed' => ['PENDING', 'Przygotowana przez nadawcę'],
        'dispatched_by_sender' => ['IN_TRANSIT', 'Paczka nadana w automacie'], 'dispatched_by_sender_to_pok' => ['IN_TRANSIT', 'Nadana w punkcie'],
        'collected_from_sender' => ['IN_TRANSIT', 'Odebrana od nadawcy'], 'taken_by_courier' => ['IN_TRANSIT', 'Odebrana od nadawcy'],
        'taken_by_courier_from_pok' => ['IN_TRANSIT', 'Odebrana z punktu nadania'],
        'adopted_at_source_branch' => ['IN_TRANSIT', 'Przyjęta w oddziale InPost'], 'sent_from_source_branch' => ['IN_TRANSIT', 'W trasie'],
        'adopted_at_sorting_center' => ['IN_TRANSIT', 'Przyjęta w sortowni'], 'sent_from_sorting_center' => ['IN_TRANSIT', 'Wysłana z sortowni'],
        'adopted_at_target_branch' => ['IN_TRANSIT', 'Przyjęta w oddziale docelowym'],
        'out_for_delivery' => ['RELEASED_FOR_DELIVERY', 'Wydana do doręczenia'],
        'out_for_delivery_to_address' => ['RELEASED_FOR_DELIVERY', 'Wydana do doręczenia'],
        'ready_to_pickup' => ['AVAILABLE_FOR_PICKUP', 'Czeka na odbiór w Paczkomacie'],
        'ready_to_pickup_from_pok' => ['AVAILABLE_FOR_PICKUP', 'Czeka na odbiór w punkcie'],
        'ready_to_pickup_from_branch' => ['AVAILABLE_FOR_PICKUP', 'Czeka na odbiór w oddziale'],
        'pickup_reminder_sent' => ['AVAILABLE_FOR_PICKUP', 'Przypomnienie o odbiorze'],
        'stack_in_box_machine' => ['AVAILABLE_FOR_PICKUP', 'Czeka w Paczkomacie tymczasowym'],
        'avizo' => ['NOTICE_LEFT', 'Awizo'],
        'delivered' => ['DELIVERED', 'Doręczona'],
        'returned_to_sender' => ['RETURNED', 'Zwrócona do nadawcy'],
        'pickup_time_expired' => ['ISSUE', 'Upłynął czas na odbiór'], 'rejected_by_receiver' => ['ISSUE', 'Odmowa przyjęcia'],
        'undelivered' => ['ISSUE', 'Nie doręczono'], 'missing' => ['ISSUE', 'Zaginiona'], 'canceled' => ['ISSUE', 'Anulowana'],
    ];

    /**
     * Historia paczki z publicznego API śledzenia InPost (GET api-shipx-pl.easypack24.net/v1/tracking/{numer}),
     * przerobiona na format odpowiedzi Allegro (dla apply()). Null, gdy InPost nie zna numeru albo API nie odpowiada.
     */
    private function inpostTracking(string $waybill): ?array
    {
        $waybill = trim($waybill);
        try {
            if ($this->inpostFetch !== null) {
                $data = ($this->inpostFetch)($waybill);
            } else {
                $res = \Pase\Support\Http::request('GET', 'https://api-shipx-pl.easypack24.net/v1/tracking/' . rawurlencode($waybill),
                    ['Accept' => 'application/json', 'User-Agent' => 'Veless/1.0'], null, 10);
                Logger::apiResponse('inpost', 'GET', '/v1/tracking', $res->status, $waybill);
                $data = $res->isSuccess() ? $res->json() : null;
            }
        } catch (\Throwable $e) {
            Logger::warn('Śledzenie InPost nie powiodło się: ' . $e->getMessage());
            return null;
        }
        $details = is_array($data) ? ($data['tracking_details'] ?? []) : [];
        if (!is_array($details) || $details === []) {
            return null;
        }
        $statuses = [];
        foreach ($details as $d) {
            $st = (string) ($d['status'] ?? '');
            if ($st === '') {
                continue;
            }
            [$code, $desc] = self::INPOST_STATUSES[$st]
                ?? (str_starts_with($st, 'undelivered') ? ['ISSUE', 'Nie doręczono']
                : (str_starts_with($st, 'return') ? ['RETURNED', 'Zwrot do nadawcy'] : ['IN_TRANSIT', str_replace('_', ' ', $st)]));
            $statuses[] = ['code' => $code, 'description' => $desc, 'occurredAt' => (string) ($d['datetime'] ?? '')];
        }
        return $statuses === [] ? null : ['waybill' => $waybill, 'trackingDetails' => ['statuses' => $statuses]];
    }

    /**
     * Inne id przewoźnika do sprawdzenia, gdy pierwsze nic nie zwróciło: z listy Allegro te,
     * których nazwa/id pasuje do nazwy kuriera (np. kilka wariantów Orlen), a na końcu ALLEGRO
     * (paczki nadane z Allegro Delivery / „Wysyłam z Allegro").
     * @return string[]
     */
    private function alternatives(string $tried, array $sh): array
    {
        $name = mb_strtolower((string) ($sh['courier_code'] ?? ''));
        $words = [];
        foreach (self::KEYWORDS as $kw => $guess) {
            if (preg_match('/(^|[^a-z])' . preg_quote($kw, '/') . '/u', $name)) {
                $words[] = $kw;
                $words[] = mb_strtolower($guess);
            }
        }
        if ($words !== [] && array_intersect($words, ['orlen', 'ruch']) !== []) {
            $words = array_merge($words, ['orlen', 'ruch', 'paczka_w_ruchu']);
        }
        $out = [];
        foreach ($this->carrierList() as $c) {
            $hay = mb_strtolower($c['id'] . ' ' . $c['name']);
            foreach (array_unique($words) as $w) {
                if ($w !== '' && str_contains($hay, $w)) {
                    $out[] = $c['id'];
                    break;
                }
            }
        }
        $out[] = 'ALLEGRO';
        return array_values(array_filter(array_unique($out), static fn($id) => strcasecmp($id, $tried) !== 0));
    }

    /** Zapisuje wynik dla jednej przesyłki. @return bool czy zmienił się etap */
    private function apply(array $sh, string $carrier, array $wb, string $now): bool
    {
        $statuses = $wb['trackingDetails']['statuses'] ?? [];
        usort($statuses, static fn($a, $b) => strcmp((string) ($a['occurredAt'] ?? ''), (string) ($b['occurredAt'] ?? '')));

        $events = array_map(static fn($s) => [
            'at'   => (string) ($s['occurredAt'] ?? ''),
            'code' => (string) ($s['code'] ?? ''),
            'desc' => (string) ($s['description'] ?? ''),
        ], array_slice($statuses, -30));

        $last    = $statuses === [] ? null : end($statuses);
        $code    = $last ? (string) ($last['code'] ?? '') : '';
        $status  = self::CODE_MAP[$code] ?? null;
        $at      = $last && !empty($last['occurredAt']) ? date('Y-m-d H:i:s', strtotime((string) $last['occurredAt'])) : null;

        $fields = ['tracking_carrier' => $carrier, 'tracking_checked_at' => $now];
        if ($events !== []) {
            $fields['tracking_events'] = $events;
        }
        $isChange = $status !== null && $status !== ($sh['tracking_status'] ?? null);
        if ($status !== null) {
            $fields += ['tracking_status' => $status, 'tracking_code' => $code, 'tracking_at' => $at];
        }
        (new ShipmentRepository($this->pdo))->update((int) $sh['id'], $fields);

        if ($isChange) {
            Logger::info('Przesyłka ' . $sh['waybill_no'] . ': ' . ($sh['tracking_status'] ?? '—') . ' -> ' . $status,
                ['order' => $sh['woo_order_id'], 'carrier' => $carrier]);
            if (!empty($sh['woo_order_id'])) {
                self::fire($this->pdo, 'shipment.status', (int) $sh['woo_order_id'], [
                    'shipment_status'   => $status,
                    'shipment_previous' => (string) ($sh['tracking_status'] ?? ''),
                    'shipment_carrier'  => $carrier,
                    'shipment_waybill'  => (string) $sh['waybill_no'],
                ], $this->allegroConfig);
            }
        }
        return $isChange;
    }

    /** Co ile godzin harmonogram sprawdza paczki przewoźnika, który sam przysyła statusy (webhook). */
    public const PUSH_RECHECK_HOURS = 6;

    /**
     * Status z powiadomienia przewoźnika (webhook, np. Uniwersalny Pusher ORLEN Paczka) dla paczki o numerze $waybill.
     * Dopisuje zdarzenie do historii przesyłki (to samo zdarzenie drugi raz niczego nie zmienia) i, gdy zmienia się
     * etap, uruchamia reguły 'shipment.status' - tak samo jak śledzenie z harmonogramu.
     * @param array{code:string,description?:string,occurredAt:string} $event kod jak w śledzeniu Allegro (DELIVERED...)
     * @return int ile przesyłek ma ten numer
     */
    public static function applyPush(PDO $pdo, string $carrier, string $waybill, array $event, ?array $allegroConfig = null): int
    {
        $w = self::normWaybill($waybill);
        if ($w === '' || !isset(self::CODE_MAP[(string) ($event['code'] ?? '')])) {
            return 0;
        }
        $stmt = $pdo->prepare("SELECT * FROM shipments WHERE status = 'created' AND UPPER(REPLACE(waybill_no, ' ', '')) = ?");
        $stmt->execute([$w]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return 0;
        }
        if ($allegroConfig === null) {
            try {
                $allegroConfig = Runtime::config('allegro');
            } catch (\Throwable) {
                $allegroConfig = [];
            }
        }
        $self = new self($pdo, new \stdClass(), $allegroConfig, null, []);
        $now = date('Y-m-d H:i:s');
        $new = ['code' => (string) $event['code'], 'description' => (string) ($event['description'] ?? ''), 'occurredAt' => (string) $event['occurredAt']];
        foreach ($rows as $sh) {
            $statuses = [];
            $dup = false;
            foreach (json_decode((string) ($sh['tracking_events'] ?? ''), true) ?: [] as $e) {
                if (($e['code'] ?? '') === self::MANUAL) {
                    continue;   // wpis „ustawiono ręcznie" nie jest statusem przewoźnika
                }
                $dup = $dup || (($e['at'] ?? '') === $new['occurredAt'] && ($e['code'] ?? '') === $new['code']);
                $statuses[] = ['code' => (string) ($e['code'] ?? ''), 'description' => (string) ($e['desc'] ?? ''), 'occurredAt' => (string) ($e['at'] ?? '')];
            }
            if (!$dup) {
                $statuses[] = $new;
            }
            $self->apply($sh, $carrier, ['waybill' => $sh['waybill_no'], 'trackingDetails' => ['statuses' => $statuses]], $now);
        }
        return count($rows);
    }

    /** Oznaczenie etapu ustawionego ręcznie (tracking_code). */
    public const MANUAL = 'MANUAL';

    /**
     * Ręczne ustawienie etapu - gdy przewoźnik/Allegro nie podaje danych (np. paczka spoza Allegro).
     * Zapisuje etap z wpisem w historii i uruchamia reguły 'shipment.status' jak zmiana od przewoźnika.
     * Późniejsze dane od przewoźnika normalnie go nadpiszą.
     */
    public static function setManual(PDO $pdo, int $shipmentId, string $status, string $user): bool
    {
        if (!isset(self::STATUSES[$status])) {
            return false;
        }
        $repo = new ShipmentRepository($pdo);
        $sh = $repo->find($shipmentId);
        if ($sh === null || ($sh['status'] ?? '') !== 'created') {
            return false;
        }
        $utc = gmdate('Y-m-d\\TH:i:s\\Z');
        $events = json_decode((string) ($sh['tracking_events'] ?? ''), true) ?: [];
        $events[] = ['at' => $utc, 'code' => self::MANUAL,
                     'desc' => self::label($status) . ' — ustawiono ręcznie' . ($user !== '' ? ' (' . $user . ')' : '')];
        $now = date('Y-m-d H:i:s');
        $repo->update($shipmentId, ['tracking_status' => $status, 'tracking_code' => self::MANUAL, 'tracking_at' => $now,
                                    'tracking_checked_at' => $now, 'tracking_events' => array_slice($events, -30)]);
        if ($status !== ($sh['tracking_status'] ?? null) && !empty($sh['woo_order_id'])) {
            self::fire($pdo, 'shipment.status', (int) $sh['woo_order_id'], [
                'shipment_status'   => $status,
                'shipment_previous' => (string) ($sh['tracking_status'] ?? ''),
                'shipment_carrier'  => (string) ($sh['tracking_carrier'] ?? $sh['courier_code'] ?? ''),
                'shipment_waybill'  => (string) $sh['waybill_no'],
            ]);
        }
        return true;
    }

    /**
     * Uruchamia reguły automatyzacji dla zdarzenia przesyłki ('shipment.created', 'shipment.status').
     * Błąd reguł nigdy nie przerywa nadawania/śledzenia.
     * @param array<string,mixed> $extra pola dołączane do payloadu zamówienia (np. shipment_status)
     */
    public static function fire(PDO $pdo, string $event, int $wooOrderId, array $extra = [], ?array $allegroConfig = null): void
    {
        try {
            $row = (new WooOrderRepository($pdo))->find($wooOrderId);
            if ($row === null) {
                return;
            }
            $payload = json_decode((string) ($row['payload'] ?? ''), true) ?: [];
            if ($allegroConfig === null) {
                try {
                    $allegroConfig = Runtime::config('allegro');
                } catch (\Throwable $e) {
                    $allegroConfig = [];
                }
            }
            (new RuleEngine($pdo, new AutomationRuleRepository($pdo), $allegroConfig))
                ->dispatch($event, $wooOrderId, $extra + $payload);
        } catch (\Throwable $e) {
            Logger::warn("Automatyzacja [{$event}] dla zamówienia {$wooOrderId} nie powiodła się: " . $e->getMessage());
        }
    }

    /** Strony śledzenia przewoźników (numer listu doklejany na końcu). */
    private const TRACKING_URLS = [
        'INPOST'        => 'https://inpost.pl/sledzenie-przesylek?number=',
        'DPD'           => 'https://tracktrace.dpd.com.pl/parcelDetails?typ=1&p1=',
        'DHL'           => 'https://www.dhl.com/pl-pl/home/sledzenie-przesylek.html?tracking-id=',
        'GLS'           => 'https://gls-group.com/PL/pl/sledzenie-paczek?match=',
        'UPS'           => 'https://www.ups.com/track?loc=pl_PL&tracknum=',
        'FEDEX'         => 'https://www.fedex.com/fedextrack/?trknbr=',
        'POCZTA_POLSKA' => 'https://emonitoring.poczta-polska.pl/?numer=',
        'ORLEN'         => 'https://www.orlenpaczka.pl/sledz-paczke/?numer=',
        'ALLEGRO'       => 'https://allegro.pl/allegrodelivery/sledzenie-paczki?numer=',
        'GEIS'          => 'https://www.geis.pl/pl/sledzenie-przesylki?packNumber=',
        'DB_SCHENKER'   => 'https://www.dbschenker.com/app/tracking-public/?refNumber=',
    ];

    /**
     * Link do śledzenia przesyłki na stronie przewoźnika (null, gdy przewoźnika nie znamy).
     * Działa bez zapytań do API: przewoźnik z ostatniego śledzenia albo z nazwy kuriera.
     */
    public static function trackingUrl(array $sh): ?string
    {
        $waybill = trim((string) ($sh['waybill_no'] ?? ''));
        if ($waybill === '') {
            return null;
        }
        $carrier = strtoupper((string) ($sh['tracking_carrier'] ?? ''));
        if ($carrier === '') {
            $raw = json_decode((string) ($sh['response_payload'] ?? ''), true);
            $carrier = is_array($raw) ? strtoupper((string) (self::findKey($raw, ['carrierId', 'carrier_id']) ?? '')) : '';
        }
        if ($carrier === '' || !isset(self::TRACKING_URLS[$carrier])) {
            $name = mb_strtolower((string) ($sh['courier_code'] ?? ''));
            foreach (self::KEYWORDS as $kw => $guess) {
                if (preg_match('/(^|[^a-z])' . preg_quote($kw, '/') . '/u', $name)) {
                    $carrier = $guess;
                    break;
                }
            }
        }
        if (!isset(self::TRACKING_URLS[$carrier]) && str_starts_with($carrier, 'ORLEN')) {
            $carrier = 'ORLEN';
        }
        return isset(self::TRACKING_URLS[$carrier]) ? self::TRACKING_URLS[$carrier] . rawurlencode($waybill) : null;
    }

    /** Identyfikator przewoźnika w Allegro albo null (nie rozpoznano). */
    public function detectCarrier(array $sh): ?string
    {
        // 0) Przewoźnik, pod którym Allegro już znalazło tę przesyłkę (np. po sprawdzeniu zapasowych id).
        if (!empty($sh['tracking_status']) && !empty($sh['tracking_carrier'])) {
            return (string) $sh['tracking_carrier'];
        }
        // 1) Wtyczka mogła zapisać carrierId w odpowiedzi przewoźnika („Wysyłam z Allegro").
        $raw = json_decode((string) ($sh['response_payload'] ?? ''), true);
        if (is_array($raw)) {
            $id = self::findKey($raw, ['carrierId', 'carrier_id']);
            if ($id !== null && $id !== '') {
                return strtoupper($id);
            }
        }
        // 2) Nazwa kuriera (BLPaczka: 'inpost', 'dpd'...; ręcznie: 'DPD (ręcznie)'; Allegro: 'InPost Paczkomaty').
        $name = mb_strtolower((string) ($sh['courier_code'] ?? '') . ' ' . (string) (is_array($raw) ? self::findKey($raw, ['carrier', 'courier', 'deliveryMethodName']) ?? '' : ''));
        if (trim($name) === '') {
            return null;
        }
        foreach (self::KEYWORDS as $kw => $guess) {
            if (preg_match('/(^|[^a-z])' . preg_quote($kw, '/') . '/u', $name)) {
                return $this->resolveCarrierId($guess, $kw);
            }
        }
        // 3) Dopasowanie do listy przewoźników z Allegro (nazwa albo id).
        foreach ($this->carrierList() as $c) {
            $n = mb_strtolower($c['name']);
            if ($n !== '' && ($n !== 'inny' && str_contains($name, $n) || str_contains($name, mb_strtolower($c['id'])))) {
                return $c['id'];
            }
        }
        return null;
    }

    /** Nasz domysł id sprawdzony z listą Allegro (np. Orlen może mieć inne id). */
    private function resolveCarrierId(string $guess, string $keyword): string
    {
        $list = $this->carrierList();
        if ($list === []) {
            return $guess;
        }
        foreach ($list as $c) {
            if (strcasecmp($c['id'], $guess) === 0) {
                return $c['id'];
            }
        }
        foreach ($list as $c) {
            if (str_contains(mb_strtolower($c['name'] . ' ' . $c['id']), $keyword)) {
                return $c['id'];
            }
        }
        return $guess;
    }

    /** Lista przewoźników Allegro, trzymana w settings przez tydzień. */
    private function carrierList(): array
    {
        if ($this->carriers !== null) {
            return $this->carriers;
        }
        $settings = new SettingsRepository($this->pdo);
        $cache = json_decode((string) ($settings->get(self::CARRIERS_CACHE_KEY, '') ?? ''), true);
        if (is_array($cache) && ($cache['at'] ?? 0) > time() - 7 * 86400 && !empty($cache['list'])) {
            return $this->carriers = $cache['list'];
        }
        try {
            $list = $this->client->carriers();
        } catch (\Throwable $e) {
            $list = [];
        }
        if ($list !== []) {
            $settings->setMany([self::CARRIERS_CACHE_KEY => json_encode(['at' => time(), 'list' => $list])]);
        }
        return $this->carriers = $list;
    }

    private static function findKey(array $data, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($data[$k]) && is_scalar($data[$k])) {
                return (string) $data[$k];
            }
        }
        foreach ($data as $v) {
            if (is_array($v) && ($f = self::findKey($v, $keys)) !== null) {
                return $f;
            }
        }
        return null;
    }

    private static function normWaybill(string $w): string
    {
        return strtoupper(preg_replace('/\s+/', '', $w) ?? $w);
    }
}
