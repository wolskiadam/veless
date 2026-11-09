<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Support\TrackingText;

/**
 * Statystyki odbiorów paczek przez kurierów (Statystyki → Odbiory kurierów).
 *
 * Z historii śledzenia zapisanej w shipments.tracking_events wyciągamy chwilę, w której kurier zabrał paczkę
 * (z automatu, punktu albo od nadawcy) i liczymy, o której godzinie zwykle to robi - osobno dla każdej usługi
 * (metoda dostawy z zamówienia, np. „Allegro One Box, One Kurier", „Paczkomaty InPost") i dnia tygodnia.
 * Dzięki temu wiadomo, do której wrzucić paczkę do automatu, żeby kurier zabrał ją jeszcze tego samego dnia.
 *
 * Odbiór rozpoznajemy po opisie zdarzenia (przetłumaczonym jak w panelu - Allegro One opisuje po angielsku):
 *   - dokładnie: „Kurier odebrał przesyłkę z automatu", „Odebrana od nadawcy", „Odebrana przez kuriera"...,
 *     „W transporcie do ekspedycji u kuriera" (ORLEN) - każda wzmianka o kurierze poza doręczeniem/awizo,
 *   - w przybliżeniu (gdy przewoźnik nie podaje odbioru): pierwsze zdarzenie „w drodze" po wrzuceniu paczki,
 *     które nie jest przyjęciem w sortowni/oddziale. „Nadana", „W kiosku" to wrzucenie przez nadawcę, nie odbiór.
 * Wrzucenie paczki do automatu/punktu przez nadawcę zapamiętujemy osobno - pokazuje, czy kurier zabrał ją tego samego dnia.
 */
final class CourierPickupStats
{
    public const TZ = 'Europe/Warsaw';

    /** Dni tygodnia (ISO 1 = poniedziałek). */
    public const DAYS = [1 => 'Pn', 2 => 'Wt', 3 => 'Śr', 4 => 'Cz', 5 => 'Pt', 6 => 'So', 7 => 'Nd'];

    /** Poniżej tylu odbiorów godziny dnia/usługi pokazujemy jako orientacyjne. */
    public const FEW = 5;

    /** Sposób nadania (z historii śledzenia): paczka wrzucona przez nadawcę do automatu/punktu albo odebrana przez kuriera od nadawcy. */
    public const DROP = 'drop';
    public const DOOR = 'door';
    public const HANDOVER = [self::DROP => 'nadane w automacie / punkcie', self::DOOR => 'kurier odebrał od nadawcy'];

    /** Ile przesyłek maksymalnie analizujemy na raz (najnowsze). */
    private const LIMIT = 5000;

    /** Opisy (małe litery, po polsku) oznaczające, że kurier zabrał paczkę. */
    private const PICKUP = [
        'kurier odebrał', 'odebrana przez kuriera', 'odebrana od nadawcy', 'odebrana z punktu nadania',
        'odebrana z automatu', 'odebrana z paczkomatu', 'przekazana przewoźnikowi', 'przekazana kurierowi',
        'odbiór od nadawcy', 'odebrano od nadawcy', 'odebrana z punktu',
    ];

    /** Kody zdarzeń oznaczające odbiór od nadawcy (spoza CODE_MAP). */
    private const PICKUP_CODES = ['PICKED_UP_FROM_SENDER', 'COLLECTED_FROM_SENDER', 'TAKEN_BY_COURIER'];

    /**
     * Wrzucenie paczki przez nadawcę (to jeszcze nie kurier): automat, punkt, kiosk ORLEN („W kiosku”),
     * a także samo „nadana” / „sent” - przewoźnicy (ORLEN, Allegro One) piszą tak, gdy paczka trafi do automatu.
     */
    private const DROPOFF = ['nadan', 'w kiosku', 'w automacie', 'w paczkomacie', 'w punkcie nadania', 'umieszczona w',
                             'przyjęta w punkcie'];

    /** Wzmianka o kurierze, która NIE jest odbiorem od nadawcy (doręczenie, awizo, zwrot). */
    private const COURIER_NOT_PICKUP = ['doręcz', 'wydana', 'awiz', 'nie zast', 'zwrot', 'zwróc', 'odbiorc'];

    /** Dalsze etapy - nie mogą udawać odbioru, gdy szukamy go w przybliżeniu. */
    private const LATER = ['sortowni', 'oddzia', 'magazyn', 'terminal', 'docelow', 'doręcz', 'czeka na odbiór', 'odbiorc', 'awizo', 'zwrot', 'zwrócon'];

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Chwila odbioru przez kuriera w historii jednej przesyłki.
     * @param array<int,array{at?:string,code?:string,desc?:string}> $events shipments.tracking_events
     * @return array{at:\DateTimeImmutable,exact:bool,dropoff:?\DateTimeImmutable}|null
     */
    public static function pickupOf(array $events): ?array
    {
        $tz = new \DateTimeZone(self::TZ);
        $list = [];
        foreach ($events as $e) {
            if (!is_array($e) || ($e['code'] ?? '') === ShipmentTracking::MANUAL || trim((string) ($e['at'] ?? '')) === '') {
                continue;
            }
            try {
                $at = (new \DateTimeImmutable((string) $e['at']))->setTimezone($tz);
            } catch (\Throwable) {
                continue;
            }
            $desc = trim((string) ($e['desc'] ?? ''));
            $pl = mb_strtolower(TrackingText::translate($desc) ?? $desc);
            $list[] = ['at' => $at, 'code' => strtoupper((string) ($e['code'] ?? '')), 'desc' => $desc, 'pl' => $pl];
        }
        usort($list, static fn($a, $b) => $a['at'] <=> $b['at']);

        $dropoff = null;
        $guess = null;
        foreach ($list as $e) {
            $has = static fn(array $words): bool => array_filter($words, static fn($w) => str_contains($e['pl'], $w)) !== [];
            // „W transporcie do ekspedycji u kuriera” (ORLEN), „odebrana przez kuriera”, „Kurier odebrał…”.
            $courier = str_contains($e['pl'], 'kurier') && !$has(self::COURIER_NOT_PICKUP);
            if (in_array($e['code'], self::PICKUP_CODES, true) || $has(self::PICKUP) || $courier) {
                $fromBox = $has(['z automatu', 'z paczkomatu', 'z punktu', 'z kiosku', 'z one box']);
                return ['at' => $e['at'], 'exact' => true, 'dropoff' => $dropoff,
                        'handover' => $dropoff !== null || $fromBox ? self::DROP : self::DOOR];
            }
            if ($has(self::DROPOFF) && !str_contains($e['pl'], 'nienadan') && !str_contains($e['pl'], 'nie nadan')) {
                $dropoff ??= $e['at'];
                continue;
            }
            if ($guess !== null) {
                continue;
            }
            $stage = ShipmentTracking::stageCode($e['code'], $e['desc']);
            if ($stage === 'IN_TRANSIT' && !$has(self::LATER)) {
                $guess = $e['at'];
            } elseif ($stage !== 'PENDING' && $stage !== '') {
                // Paczka jest już dalej (sortownia, doręczenie) - odbioru w historii nie ma, nie zgadujemy.
                $guess = false;
            }
        }
        return $guess instanceof \DateTimeImmutable
            ? ['at' => $guess, 'exact' => false, 'dropoff' => $dropoff, 'handover' => $dropoff !== null ? self::DROP : self::DOOR] : null;
    }

    /**
     * Raport dla przesyłek nadanych od $since (Y-m-d).
     * @return list<array<string,mixed>> usługi od najczęściej używanej
     */
    public function report(string $since): array
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $json = static fn(string $path): string => $mysql
            ? "JSON_UNQUOTE(JSON_EXTRACT(o.payload, '$path'))" : "json_extract(o.payload, '$path')";
        $stmt = $this->pdo->prepare(
            "SELECT s.courier_code, s.tracking_carrier, s.tracking_events,
                    {$json('$.delivery.method.name')} AS allegro_method,
                    {$json('$.shipping_lines[0].method_title')} AS shop_method
             FROM shipments s
             LEFT JOIN woo_orders o ON o.woo_order_id = s.woo_order_id
             WHERE s.tracking_events IS NOT NULL AND s.waybill_no IS NOT NULL AND s.waybill_no <> ''
               AND s.created_at >= ?
             ORDER BY s.id DESC
             LIMIT " . self::LIMIT
        );
        $stmt->execute([$since . ' 00:00:00']);

        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $events = json_decode((string) $r['tracking_events'], true);
            $p = is_array($events) ? self::pickupOf($events) : null;
            if ($p === null) {
                continue;
            }
            $raw = self::serviceName($r);
            $company = self::company($raw, (string) ($r['courier_code'] ?? ''), (string) ($r['tracking_carrier'] ?? ''));
            $key = mb_strtolower($company) . '|' . $p['handover'];
            $groups[$key] ??= ['name' => $company, 'handover' => $p['handover'], 'variants' => [], 'pickups' => []];
            $groups[$key]['variants'][$raw] = ($groups[$key]['variants'][$raw] ?? 0) + 1;
            $groups[$key]['pickups'][] = $p;
        }

        $out = [];
        foreach ($groups as $g) {
            arsort($g['variants']);
            $out[] = ['name' => $g['name'], 'handover' => $g['handover'], 'variants' => array_keys($g['variants'])] + self::summarize($g['pickups']);
        }
        // Najpierw paczki wrzucane do automatów (o nie chodzi przy „wrzuć do”), potem odbiory od nadawcy.
        usort($out, static fn($a, $b) => ($a['handover'] === self::DROP ? 0 : 1) <=> ($b['handover'] === self::DROP ? 0 : 1)
            ?: $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * Firma przewożąca paczkę - bez względu na to, jak nazwał metodę sklep czy Allegro („Allegro Paczkomaty InPost”,
     * „InPost Kurier”, „Paczkomat” -> InPost). Najpierw przewoźnik ze śledzenia, potem nazwa metody i kuriera.
     * Rodzaj dostawy do klienta (paczkomat, kurier) nie ma znaczenia - grupujemy po sposobie nadania (handover).
     * Nierozpoznana firma = nazwa metody.
     */
    public static function company(string $name, string $courier = '', string $carrier = ''): string
    {
        $byCarrier = [
            'INPOST' => 'InPost', 'DPD' => 'DPD', 'DHL' => 'DHL', 'GLS' => 'GLS', 'UPS' => 'UPS', 'FEDEX' => 'FedEx',
            'ORLEN' => 'ORLEN Paczka', 'PACZKA_W_RUCHU' => 'ORLEN Paczka', 'POCZTA_POLSKA' => 'Poczta Polska',
        ];
        $c = strtoupper(trim($carrier));
        if (isset($byCarrier[$c])) {
            return $byCarrier[$c];
        }
        $all = mb_strtolower($name . ' ' . $courier . ' ' . $carrier);
        // Krótkie skróty (DPD, UPS, GLS...) tylko jako osobne słowa - „zakupu” to nie UPS.
        $has = static fn(string ...$w): bool => array_filter($w, static fn($x) => mb_strlen($x) > 3
            ? str_contains($all, $x) : preg_match('/(?<![\\p{L}\\d])' . preg_quote($x, '/') . '(?![\\p{L}\\d])/u', $all) === 1) !== [];
        return match (true) {
            $has('allegro one', 'one box', 'one punkt', 'one kurier') => 'Allegro One',
            $has('inpost', 'paczkomat') => 'InPost',
            $has('orlen', 'ruch', 'paczka_w_ruchu') => 'ORLEN Paczka',
            $has('dpd') => 'DPD',
            $has('dhl') => 'DHL',
            $has('gls') => 'GLS',
            $has('ups') => 'UPS',
            $has('fedex') => 'FedEx',
            $has('pocztex', 'poczta', 'envelo', 'poczta_polska') => 'Poczta Polska',
            default => $name,
        };
    }

    /** Usługa: metoda dostawy z zamówienia, a bez niej nazwa kuriera z przesyłki. */
    public static function serviceName(array $r): string
    {
        foreach (['allegro_method', 'shop_method', 'courier_code', 'tracking_carrier'] as $k) {
            $v = trim((string) ($r[$k] ?? ''));
            if ($v !== '' && $v !== 'null' && $v !== ShipmentTracking::NO_CARRIER) {
                return $v;
            }
        }
        return 'Bez nazwy';
    }

    /**
     * Godziny odbiorów jednej usługi: rozkład godzinowy (ogółem i w dni tygodnia), typowe okno, „wrzuć do".
     * @param list<array{at:\DateTimeImmutable,exact:bool,dropoff:?\DateTimeImmutable}> $pickups
     * @return array<string,mixed>
     */
    public static function summarize(array $pickups): array
    {
        $minutes = [];
        $byDay = array_fill_keys(array_keys(self::DAYS), []);
        $hours = array_fill(0, 24, 0);
        $grid = [];
        $exact = 0;
        $sameDay = 0;
        $nextDay = 0;
        $latestSameDay = null;
        $last = null;
        foreach ($pickups as $p) {
            $at = $p['at'];
            $m = (int) $at->format('G') * 60 + (int) $at->format('i');
            $d = (int) $at->format('N');
            $h = (int) $at->format('G');
            $minutes[] = $m;
            $byDay[$d][] = $m;
            $hours[$h]++;
            $grid[$d][$h] = ($grid[$d][$h] ?? 0) + 1;
            $exact += $p['exact'] ? 1 : 0;
            $last = $last === null || $at > $last ? $at : $last;
            if ($p['dropoff'] instanceof \DateTimeImmutable) {
                $drop = $p['dropoff'];
                if ($drop->format('Y-m-d') === $at->format('Y-m-d')) {
                    $sameDay++;
                    $dm = (int) $drop->format('G') * 60 + (int) $drop->format('i');
                    $latestSameDay = max($latestSameDay ?? 0, $dm);
                } else {
                    $nextDay++;
                }
            }
        }

        // Nagłówek liczymy z dni roboczych - sobotni kurier bywa wcześniej i zaniżałby „wrzuć do" na cały tydzień.
        $weekdays = array_merge($byDay[1], $byDay[2], $byDay[3], $byDay[4], $byDay[5]);
        $headline = $weekdays !== [] ? $weekdays : $minutes;
        $days = [];
        foreach ($byDay as $d => $list) {
            if ($list !== []) {
                $days[$d] = self::window($list);
            }
        }
        return [
            'count'   => count($minutes),
            'scope'   => $weekdays !== [] ? 'Pn–Pt' : '',
            'exact'   => $exact,
            'last'    => $last,
            'hours'   => $hours,
            'grid'    => $grid,
            'days'    => $days,
            'dropoff' => ['same_day' => $sameDay, 'next_day' => $nextDay, 'latest_same_day' => $latestSameDay],
        ] + self::window($headline);
    }

    /**
     * Typowe okno odbioru z listy minut doby: od 20. do 80. percentyla (zaokrąglone do 5 min),
     * mediana i „wrzuć do" = 10. percentyl zaokrąglony w dół do kwadransa (9 na 10 odbiorów było później).
     * @param int[] $minutes
     * @return array{n:int,from:int,to:int,median:int,drop_by:int}
     */
    public static function window(array $minutes): array
    {
        sort($minutes);
        $n = count($minutes);
        $q = static function (float $p) use ($minutes, $n): float {
            if ($n === 1) {
                return (float) $minutes[0];
            }
            $pos = ($n - 1) * $p;
            $lo = (int) floor($pos);
            $hi = min($n - 1, $lo + 1);
            return $minutes[$lo] + ($minutes[$hi] - $minutes[$lo]) * ($pos - $lo);
        };
        return [
            'n'       => $n,
            'from'    => (int) (floor($q(0.2) / 5) * 5),
            'to'      => (int) (ceil($q(0.8) / 5) * 5),
            'median'  => (int) round($q(0.5)),
            'drop_by' => (int) (floor($q(0.1) / 15) * 15),
        ];
    }

    /** Minuty doby -> „11:05". */
    public static function hm(int $minutes): string
    {
        $minutes = max(0, min(24 * 60 - 1, $minutes));
        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
