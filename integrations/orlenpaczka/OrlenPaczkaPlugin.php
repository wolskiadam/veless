<?php
declare(strict_types=1);

namespace PasePlugin\Orlenpaczka;

use Pase\Plugin\AbstractPlugin;
use Pase\Plugin\Capability;
use Pase\Plugin\Contract\Courier;
use Pase\Plugin\PluginManifest;
use Pase\Support\Logger;
use Pase\Support\PickupPoint;

/**
 * Wtyczka ORLEN Paczka (kurier) - nadawanie paczek do punktów i automatów ORLEN Paczka
 * na WŁASNEJ UMOWIE z ORLEN Paczka, przez API ORLEN Paczka (SOAP).
 *
 *  - nadanie z etykietą: GenerateLabelBusinessPackListTwo (metoda zalecana w dokumentacji),
 *  - etykieta ponownie: LabelPrintDuplicateListTwo, anulowanie: PutCustomerPackCanceled,
 *  - punkt odbioru: z zamówienia (WooCommerce / Allegro) albo wybrany z listy punktów ORLEN Paczka
 *    (lista pobierana raz dziennie i trzymana w storage/cache),
 *  - podjazd kuriera (opcjonalnie): GetAvailablePickups + CallPickupNew,
 *  - śledzenie: GiveMePackStatusFullHistoryList - rdzeń (Services\ShipmentTracking) woła trackWaybills().
 *
 * Paczki z umowy Allegro (Allegro SMART) nadaje się przez „Wysyłam z Allegro" - metody Allegro
 * w API ORLEN Paczka są zastrzeżone dla Allegro (dokumentacja, rozdz. 2.14).
 *
 * Wejście createShipment() (tablica od rdzenia, patrz public/admin/courier_shipment.php):
 *   order        => ['row' => wiersz woo_orders, 'payload' => zamówienie]
 *   parcel       => ['weight' => kg, 'x' => cm, 'y' => cm, 'z' => cm]
 *   service      => 'auto' | 'S' | 'M' | 'L' (gabaryt)
 *   point        => kod punktu odbioru (pełny „WS-116263-C3-01", uniwersalny „XX-116263-00-00" albo sam numer PSD)
 *   label_format => 'PDF' | 'ZPL', text_on_label, handover ('point' | 'courier'), pickup_date (YYYY-MM-DD)
 */
final class OrlenPaczkaPlugin extends AbstractPlugin implements Courier
{
    /** Gabaryty ORLEN Paczka: wymiary w cm (posortowane malejąco) i maks. waga (dokumentacja 2.5.4). */
    public const SIZES = [
        'S' => ['dims' => [60, 38, 8],  'label' => 'Gabaryt S (do 8 × 38 × 60 cm, 20 kg)'],
        'M' => ['dims' => [60, 38, 19], 'label' => 'Gabaryt M (do 19 × 38 × 60 cm, 20 kg)'],
        'L' => ['dims' => [60, 41, 38], 'label' => 'Gabaryt L (do 41 × 38 × 60 cm, 20 kg)'],
    ];
    public const MAX_WEIGHT = 20.0;

    /** Orientacyjne ceny ORLEN Paczka (pole PackPrice, wspólne dla wszystkich klientów) w zł. */
    public const STANDARD_PRICES = ['S' => 9.99, 'M' => 10.99, 'L' => 12.99];

    public const HANDOVER = [
        'point'   => 'Nadam w punkcie lub automacie ORLEN Paczka',
        'courier' => 'Zamówię podjazd kuriera ORLEN Paczka',
    ];

    /** Przewoźnik w śledzeniu (jak id przewoźnika w Allegro) - patrz Services\ShipmentTracking. */
    public const CARRIER = 'ORLEN';

    /** Ścieżka pliku z listą punktów (testy mogą ją podmienić). */
    public static ?string $pointsCacheFile = null;

    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            type: 'orlenpaczka',
            name: 'ORLEN Paczka',
            version: '1.0.0',
            author: 'Veless',
            capabilities: [Capability::COURIER],
            fields: [
                ['key' => 'partner_id', 'label' => 'PartnerID (login API)', 'type' => 'text', 'required' => true,
                 'help' => 'Dane dostępowe przychodzą e-mailem po aktywacji umowy z ORLEN Paczka. Środowisko testowe ma inne dane — prosi się o nie opiekuna handlowego.'],
                ['key' => 'partner_key', 'label' => 'PartnerKey (hasło API)', 'type' => 'password', 'required' => true, 'secret' => true,
                 'help' => 'To samo hasło co w WebTrucker — zmiana hasła w WebTrucker zmienia też PartnerKey.'],
                ['key' => 'env', 'label' => 'Środowisko', 'type' => 'select', 'default' => 'production',
                 'options' => ['production' => 'Produkcyjne', 'test' => 'Testowe (apitest.orlenpaczka.pl)'],
                 'help' => 'Paczek ze środowiska testowego nie widać na stronie śledzenia ORLEN Paczka.'],
                ['key' => 'sender_company', 'label' => 'Nadawca: nazwa firmy', 'type' => 'text',
                 'help' => 'Firma albo imię i nazwisko nadawcy — jedno z dwóch jest wymagane.'],
                ['key' => 'sender_first_name', 'label' => 'Nadawca: imię', 'type' => 'text'],
                ['key' => 'sender_last_name', 'label' => 'Nadawca: nazwisko', 'type' => 'text'],
                ['key' => 'sender_street', 'label' => 'Nadawca: ulica', 'type' => 'text', 'required' => true],
                ['key' => 'sender_building', 'label' => 'Nadawca: numer budynku', 'type' => 'text', 'required' => true],
                ['key' => 'sender_flat', 'label' => 'Nadawca: numer lokalu', 'type' => 'text'],
                ['key' => 'sender_postcode', 'label' => 'Nadawca: kod pocztowy', 'type' => 'text', 'required' => true, 'help' => 'Format 00-000.'],
                ['key' => 'sender_city', 'label' => 'Nadawca: miejscowość', 'type' => 'text', 'required' => true],
                ['key' => 'sender_phone', 'label' => 'Nadawca: telefon', 'type' => 'text', 'required' => true],
                ['key' => 'sender_email', 'label' => 'Nadawca: e-mail', 'type' => 'text', 'required' => true],
                ['key' => 'box_size', 'label' => 'Domyślny gabaryt', 'type' => 'select', 'default' => 'auto',
                 'options' => ['auto' => 'Dobierz z wymiarów paczki', 'S' => 'S', 'M' => 'M', 'L' => 'L'],
                 'help' => 'Wstępnie zaznaczony przy nadawaniu — można zmienić przy każdej paczce.'],
                ['key' => 'handover', 'label' => 'Domyślny sposób nadania', 'type' => 'select', 'default' => 'point', 'options' => self::HANDOVER,
                 'help' => 'Przy podjeździe kuriera po nadaniu zamówimy odbiór na wybrany dzień (pierwszy wolny przedział ORLEN Paczka).'],
                ['key' => 'label_format', 'label' => 'Domyślny format etykiety', 'type' => 'select', 'default' => 'PDF',
                 'options' => ['PDF' => 'PDF', 'ZPL' => 'ZPL (Zebra)']],
                ['key' => 'pdf_format', 'label' => 'Etykieta PDF', 'type' => 'select', 'default' => 'PDF10',
                 'options' => ['PDF10' => 'Etykieta 10 × 15 cm (PDF10)', 'PDF' => 'Strona A4 (PDF)']],
                ['key' => 'zpl_format', 'label' => 'Etykieta ZPL', 'type' => 'select', 'default' => 'ZPL',
                 'options' => ['ZPL' => 'ZPL 203 dpi', 'ZPL_300DPI' => 'ZPL 300 dpi']],
                ['key' => 'print_type', 'label' => 'Dane na etykiecie', 'type' => 'select', 'default' => '1',
                 'options' => ['1' => 'Pełny adres', '2' => 'Adres anonimowy', '3' => 'Tylko nazwa odbiorcy i nadawcy']],
                ['key' => 'text_on_label', 'label' => 'Tekst na etykiecie', 'type' => 'text',
                 'help' => 'Opcjonalnie, do 30 znaków (pole SenderOrders). {nr} = numer zamówienia w CRM, np. „Zamówienie {nr}”.'],
                ['key' => 'price_s', 'label' => 'Cena gabarytu S (zł)', 'type' => 'text',
                 'help' => 'Cena z Twojej umowy — do wyceny i kosztu przesyłki. Puste = cena orientacyjna ORLEN Paczka (9,99 / 10,99 / 12,99 zł).'],
                ['key' => 'price_m', 'label' => 'Cena gabarytu M (zł)', 'type' => 'text'],
                ['key' => 'price_l', 'label' => 'Cena gabarytu L (zł)', 'type' => 'text'],
            ],
            color: '#d7141a',
            icon: '📦',
            multiple: true,
            description: 'Nadawanie paczek do punktów i automatów ORLEN Paczka na własnej umowie: etykiety PDF/ZPL, wybór punktu, podjazd kuriera i śledzenie.',
            category: Capability::CAT_COURIER
        );
    }

    public function client(): OrlenPaczkaClient
    {
        return new OrlenPaczkaClient(
            trim((string) $this->cfg('partner_id', '')),
            trim((string) $this->cfg('partner_key', '')),
            $this->cfg('env', 'production') === 'test'
        );
    }

    public function testConnection(): array
    {
        if (trim((string) $this->cfg('partner_id', '')) === '' || trim((string) $this->cfg('partner_key', '')) === '') {
            return ['ok' => false, 'message' => 'Uzupełnij PartnerID i PartnerKey.'];
        }
        $r = $this->client()->partnerStatus();
        if (!$r['ok']) {
            return ['ok' => false, 'message' => $r['message']];
        }
        $row = $r['row'];
        $who = trim((string) ($row['CompanyName'] ?? '')) ?: trim(($row['FirstName'] ?? '') . ' ' . ($row['LastName'] ?? ''));
        $pay = strtoupper(trim((string) ($row['PaymentType'] ?? '')));
        $msg = 'Połączono z ORLEN Paczka' . ($this->cfg('env') === 'test' ? ' (środowisko testowe)' : '') . '.'
            . ($who !== '' ? " Konto: {$who}." : '') . ($pay !== '' ? " Rozliczenie: {$pay}." : '');
        if ($pay === 'PREPAID') {
            $msg .= ' Umowa ma status PREPAID — ORLEN Paczka nie przyjmie nadań, dopóki umowa handlowa nie zostanie podpisana.';
        }
        $missing = $this->missingSender();
        if ($missing !== []) {
            $msg .= ' Uzupełnij dane nadawcy: ' . implode(', ', $missing) . '.';
        }
        return ['ok' => true, 'message' => $msg];
    }

    // ================= Courier =================

    /**
     * Gabaryty do wyboru + punkt odbioru z zamówienia + sposób nadania.
     * @param array<string,mixed> $parcel zawiera 'order' => ['row', 'payload']
     */
    public function listServices(array $parcel): array
    {
        $services = [['id' => 'auto', 'label' => 'Dobierz gabaryt z wymiarów paczki', 'group' => 'size']];
        foreach (self::SIZES as $k => $s) {
            $services[] = ['id' => $k, 'label' => $s['label'], 'group' => 'size'];
        }
        $default = (string) $this->cfg('box_size', 'auto');
        $handover = (string) $this->cfg('handover', 'point');

        $point = $this->orderPoint($parcel['order'] ?? []);
        $missing = $this->missingSender();
        return [
            'ok'       => true,
            'message'  => $missing !== [] ? 'Uzupełnij w ustawieniach integracji dane nadawcy: ' . implode(', ', $missing) . '.' : '',
            'default'  => isset(self::SIZES[$default]) ? $default : 'auto',
            'services' => $services,
            'groups'   => ['size' => 'Gabaryt ORLEN Paczka'],
            'handover' => ['default' => isset(self::HANDOVER[$handover]) ? $handover : 'point', 'options' => self::HANDOVER],
            'point'    => [
                'label'       => 'Punkt odbioru ORLEN Paczka',
                'value'       => $point['code'],
                'note'        => $point['note'],
                'placeholder' => 'np. WS-116263-C3-01, numer 116263 albo miasto / kod pocztowy',
                'required'    => true,
                'search'      => true,
            ],
        ];
    }

    public function listPaymentOptions(): array
    {
        return []; // rozliczenie wg umowy z ORLEN Paczka
    }

    /** Wycena: cena gabarytu z ustawień albo orientacyjna cena ORLEN Paczka. */
    public function quote(array $params): array
    {
        $size = $this->resolveSize((string) ($params['service'] ?? ''), $params['parcel'] ?? []);
        if (!$size['ok']) {
            return ['ok' => false, 'price' => null, 'message' => $size['message']];
        }
        [$price, $own] = $this->sizePrice($size['size']);
        return ['ok' => true, 'price' => $price,
                'message' => 'gabaryt ' . $size['size'] . ', ' . ($own ? 'cena z ustawień' : 'cena orientacyjna ORLEN Paczka')];
    }

    /** Podpowiedź do formularza: nadawca z ustawień. */
    public function proposal(array $order): array
    {
        $name = trim((string) $this->cfg('sender_company', '')) ?: trim($this->cfg('sender_first_name', '') . ' ' . $this->cfg('sender_last_name', ''));
        return ['ok' => true, 'message' => '', 'package' => [],
                'sender' => trim($name . ', ' . (string) $this->cfg('sender_city', ''), ', ')];
    }

    public function createShipment(array $shipment): array
    {
        $fail = static fn(string $m, $raw = null) => ['ok' => false, 'message' => $m, 'waybill_no' => null,
            'external_id' => null, 'price' => null, 'label_link' => null, 'raw' => $raw];

        $missing = $this->missingSender();
        if ($missing !== []) {
            return $fail('Uzupełnij w ustawieniach integracji ORLEN Paczka dane nadawcy: ' . implode(', ', $missing) . '.');
        }
        $order = $shipment['order'] ?? [];
        $row = $order['row'] ?? [];
        $nr = (string) ($row['pase_number'] ?? $row['woo_order_id'] ?? '');

        $size = $this->resolveSize((string) ($shipment['service'] ?? ''), $shipment['parcel'] ?? []);
        if (!$size['ok']) {
            return $fail($size['message']);
        }

        $rawPoint = trim((string) ($shipment['point'] ?? ''));
        $point = $rawPoint !== '' ? self::pointCode($rawPoint, $this->cachedPoints()) : $this->orderPoint($order)['code'];
        if ($point === '') {
            return $fail('Podaj punkt odbioru ORLEN Paczka (kod punktu albo jego numer).');
        }

        $receiver = self::receiver($order);
        if ($receiver['PhoneNumber'] === '') {
            return $fail('Zamówienie nie ma telefonu odbiorcy — ORLEN Paczka go wymaga (SMS z kodem odbioru). Uzupełnij telefon w zamówieniu.');
        }
        if ($receiver['FirstName'] === '' && $receiver['CompanyName'] === '') {
            return $fail('Zamówienie nie ma imienia i nazwiska ani firmy odbiorcy.');
        }

        $text = trim((string) ($shipment['text_on_label'] ?? ''));
        if ($text === '' && ($tpl = trim((string) $this->cfg('text_on_label', ''))) !== '') {
            $text = str_replace('{nr}', $nr, $tpl);
        }

        $pack = ['DestinationCode' => $point, 'AlternativeDestinationCode' => '', 'BoxSize' => $size['size']]
            + $receiver
            + $this->sender()
            + [
                'SenderOrders'        => self::cut($text, 30),
                'TransferDescription' => self::cut($nr !== '' ? 'Zamówienie ' . $nr : '', 70),
                'PrintAdress'         => '1',
                'PrintType'           => in_array((string) $this->cfg('print_type', '1'), ['1', '2', '3'], true) ? (string) $this->cfg('print_type', '1') : '1',
            ];

        $isZpl = strtoupper((string) ($shipment['label_format'] ?? $this->cfg('label_format', 'PDF'))) === 'ZPL';
        $format = $isZpl ? $this->zplFormat() : $this->pdfFormat();

        $r = $this->client()->createPack($pack, $format);
        if (!$r['ok']) {
            Logger::warn('ORLEN Paczka: nadanie odrzucone', ['order' => $nr, 'message' => $r['message']]);
            $msg = $r['message'];
            if (!empty($r['transport'])) {
                $msg .= ' Wynik nadania jest nieznany — zanim nadasz ponownie, sprawdź, czy paczka nie powstała (żeby nie zdublować nadania).';
            }
            return $fail('ORLEN Paczka odrzuciła przesyłkę: ' . $msg, ['request' => $pack, 'response' => $r['row'] ?? null]);
        }

        $code = $r['pack_code'];
        [$ownPrice, $own] = $this->sizePrice($size['size']);
        $price = $own ? $ownPrice : ($r['price'] ?? $ownPrice);

        $msg = "Nadano przez ORLEN Paczka, nr paczki {$code} (gabaryt {$size['size']}).";
        if ($r['warning'] !== '') {
            $msg .= ' Uwaga: ' . $r['warning'] . ($r['destination'] !== '' ? ' — punkt: ' . $r['destination'] . '.' : '');
        }
        if ($r['paid'] === false) {
            $msg .= ' ORLEN Paczka zgłasza umowę PREPAID — paczka może nie zostać przyjęta do nadania.';
        }

        $pickup = null;
        $handover = (string) ($shipment['handover'] ?? '');
        if (!isset(self::HANDOVER[$handover])) {
            $handover = (string) $this->cfg('handover', 'point');
        }
        if ($handover === 'courier') {
            $pickup = $this->orderPickup($code, (string) ($shipment['pickup_date'] ?? ''), $nr);
            $msg .= ' ' . $pickup['message'];
        }

        return [
            'ok'            => true,
            'message'       => $msg,
            'waybill_no'    => $code,
            'external_id'   => $code,
            'courier_label' => 'ORLEN Paczka',
            'price'         => $price,
            'label_link'    => null,
            'raw'           => [
                'carrierId'   => self::CARRIER,
                'pack_code'   => $code,
                'destination' => $r['destination'] ?: $point,
                'box_size'    => $size['size'],
                'pack_price'  => $r['price'],
                'label'       => $format,
                'pickup'      => $pickup,
                'response'    => $r['row'],
            ],
        ];
    }

    /**
     * @param string $format A4 (PDF na stronie A4) | LBL (PDF 10×15) | ZPL | EPL
     */
    public function getLabel(string $externalId, string $format = 'A4'): array
    {
        $code = trim($externalId);
        if ($code === '') {
            return ['ok' => false, 'content' => null, 'filename' => null, 'mime' => null, 'message' => 'Brak numeru paczki ORLEN Paczka.'];
        }
        $api = match ($format) {
            'ZPL'   => $this->zplFormat(),
            'EPL'   => 'EPL',
            'LBL'   => 'PDF10',
            default => $this->pdfFormat(),
        };
        $r = $this->client()->labels([$code], $api);
        if (!$r['ok']) {
            return ['ok' => false, 'content' => null, 'filename' => null, 'mime' => null, 'message' => $r['message']];
        }
        $raw = in_array($api, ['ZPL', 'ZPL_300DPI', 'EPL'], true);
        $name = 'etykieta-orlen-' . preg_replace('/[^A-Za-z0-9_-]/', '', $code);
        return [
            'ok'       => true,
            'content'  => $r['content'],
            'filename' => $name . ($raw ? ($api === 'EPL' ? '.epl' : '.zpl') : '.pdf'),
            'mime'     => $raw ? 'application/octet-stream' : 'application/pdf',
            'message'  => '',
        ];
    }

    public function cancelShipment(string $externalId): array
    {
        $code = trim($externalId);
        if ($code === '') {
            return ['ok' => false, 'message' => 'Brak numeru paczki ORLEN Paczka.'];
        }
        $r = $this->client()->cancel($code);
        return $r['ok']
            ? ['ok' => true, 'message' => "Paczka {$code} anulowana w ORLEN Paczka."]
            : ['ok' => false, 'message' => 'ORLEN Paczka nie anulowała paczki: ' . $r['message']];
    }

    // ================= Śledzenie (woła Services\ShipmentTracking) =================

    public function trackingCarrier(): string
    {
        return self::CARRIER;
    }

    /**
     * Historia statusów paczek w formacie śledzenia Allegro (kody PENDING / IN_TRANSIT / ... / DELIVERED),
     * żeby rdzeń zapisał je tak samo jak dane z Allegro i InPost.
     * @param string[] $waybills
     * @return array{ok:bool,message:string,waybills:array<string,array<int,array{code:string,description:string,occurredAt:string}>>}
     */
    public function trackWaybills(array $waybills): array
    {
        $codes = array_values(array_unique(array_filter(array_map(static fn($w) => preg_replace('/\s+/', '', (string) $w), $waybills))));
        $out = [];
        foreach (array_chunk($codes, 1000) as $chunk) {
            $r = $this->client()->history($chunk);
            if (!$r['ok']) {
                return ['ok' => false, 'message' => $r['message'], 'waybills' => $out];
            }
            foreach ($r['events'] as $code => $events) {
                $statuses = [];
                foreach ($events as $e) {
                    $mapped = self::statusCode((int) ($e['Trans'] ?? 0), (string) ($e['Attribute'] ?? ''));
                    if ($mapped === null) {
                        continue;
                    }
                    $desc = trim((string) ($e['Trans_Des'] ?? ''));
                    $attr = strtoupper(trim((string) ($e['Attribute'] ?? '')));
                    if (in_array($attr, ['POWROT', '2_POWROT'], true)) {
                        $desc .= ' (powrót do nadawcy)';
                    }
                    $statuses[] = ['code' => $mapped, 'description' => $desc, 'occurredAt' => self::eventTime((string) ($e['Data'] ?? ''))];
                }
                if ($statuses !== []) {
                    $out[(string) $code] = $statuses;
                }
            }
        }
        return ['ok' => true, 'message' => '', 'waybills' => $out];
    }

    /**
     * Kod statusu ORLEN Paczka (<Trans>, rozdz. 7.3) -> kod jak w śledzeniu Allegro. Null = pomijamy (archiwizacja).
     * Atrybut POWROT / 2_POWROT = paczka wraca do nadawcy (nieodebrana).
     */
    public static function statusCode(int $trans, string $attribute = ''): ?string
    {
        $attr = strtoupper(trim($attribute));
        if ($trans === 888 || $trans === 0) {
            return null;
        }
        if (in_array($attr, ['POWROT', '2_POWROT'], true)) {
            return in_array($trans, [1000, 1100, 1220], true) ? 'RETURNED' : 'ISSUE';
        }
        return match (true) {
            $trans === 200                                              => 'PENDING',
            $trans === 1000                                             => 'DELIVERED',
            in_array($trans, [1100, 1200, 1220], true)                  => 'RETURNED',
            in_array($trans, [620, 690, 691, 695, 696], true)           => 'AVAILABLE_FOR_PICKUP',
            in_array($trans, [610, 660, 665, 670, 680, 685], true)      => 'RELEASED_FOR_DELIVERY',
            in_array($trans, [201, 677, 679, 700, 708, 709, 710, 711, 712, 714, 729, 739, 749, 750, 780, 790, 800, 900, 999, 2000], true) => 'ISSUE',
            default                                                     => 'IN_TRANSIT',
        };
    }

    /**
     * Czas statusu: ORLEN podaje czas polski z „Z" na końcu (do zignorowania) i ułamkiem sekund.
     * Zwracamy go z przesunięciem strefy (np. „2026-10-08T09:45:23+02:00"), bo CRM liczy czas w UTC.
     */
    private static function eventTime(string $data): string
    {
        $d = preg_replace('/\s+/', '', $data) ?? '';
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})/', $d, $m)) {
            return $d;
        }
        try {
            return (new \DateTimeImmutable($m[1] . ' ' . $m[2], self::tz()))->format(DATE_ATOM);
        } catch (\Throwable) {
            return $m[1] . 'T' . $m[2];
        }
    }

    /** Strefa czasowa API ORLEN Paczka (czas polski). */
    private static function tz(): \DateTimeZone
    {
        return new \DateTimeZone('Europe/Warsaw');
    }

    /** Teraz w czasie polskim, w podanym formacie. */
    private static function plNow(string $format): string
    {
        return (new \DateTimeImmutable('now', self::tz()))->format($format);
    }

    /** Znacznik czasu dla czasu polskiego „YYYY-MM-DDThh:mm:ss". */
    private static function plTs(string $local): int
    {
        try {
            return (new \DateTimeImmutable(str_replace('T', ' ', $local), self::tz()))->getTimestamp();
        } catch (\Throwable) {
            return 0;
        }
    }

    // ================= Punkty odbioru =================

    /**
     * Wyszukiwanie punktu po kodzie, numerze, mieście, kodzie pocztowym albo ulicy (lista punktów z dnia).
     * @return array{ok:bool,message:string,points:array<int,array{code:string,label:string}>}
     */
    public function searchPoints(string $query, int $limit = 15): array
    {
        $q = mb_strtolower(trim($query));
        if (mb_strlen($q) < 2) {
            return ['ok' => true, 'message' => '', 'points' => []];
        }
        $list = $this->points();
        if (!$list['ok']) {
            return ['ok' => false, 'message' => $list['message'], 'points' => []];
        }
        $words = preg_split('/[\s,]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $hits = [];
        foreach ($list['points'] as $p) {
            if (($p['available'] ?? 'T') === 'N') {
                continue;
            }
            $hay = mb_strtolower(implode(' ', [$p['code'] ?? '', $p['psd'] ?? '', $p['zip'] ?? '', $p['city'] ?? '', $p['street'] ?? '', $p['building'] ?? '', $p['location'] ?? '']));
            foreach ($words as $w) {
                if (!str_contains($hay, $w)) {
                    continue 2;
                }
            }
            // Dokładny kod albo numer punktu na początek.
            $exact = in_array($q, [mb_strtolower($p['code'] ?? ''), $p['psd'] ?? ''], true) ? 0 : 1;
            $hits[] = [$exact, $p];
            if (count($hits) > 400) {
                break;
            }
        }
        usort($hits, static fn($a, $b) => $a[0] <=> $b[0] ?: strcmp(($a[1]['city'] ?? '') . ($a[1]['street'] ?? ''), ($b[1]['city'] ?? '') . ($b[1]['street'] ?? '')));
        $out = [];
        foreach (array_slice($hits, 0, $limit) as [, $p]) {
            $out[] = ['code' => $p['code'], 'label' => self::pointLabel($p)];
        }
        return ['ok' => true, 'message' => $out === [] ? 'Nie znaleziono punktu.' : '', 'points' => $out];
    }

    /** Opis punktu do listy wyboru. */
    public static function pointLabel(array $p): string
    {
        $types = ['APM' => 'automat', 'PPP' => 'punkt', 'PKN' => 'stacja ORLEN', 'PPK' => 'punkt partnera'];
        $addr = trim(trim(($p['street'] ?? '') . ' ' . ($p['building'] ?? '')) . ', ' . trim(($p['zip'] ?? '') . ' ' . ($p['city'] ?? '')), ' ,');
        $type = $types[strtoupper((string) ($p['type'] ?? ''))] ?? '';
        return $addr . ($type !== '' ? " ({$type})" : '') . (!empty($p['location']) ? ' — ' . mb_substr((string) $p['location'], 0, 60) : '');
    }

    /**
     * Lista punktów: z pliku w storage/cache, odświeżana raz dziennie po 6:00 (zalecenie ORLEN Paczka).
     * @return array{ok:bool,message:string,points:array<int,array<string,string>>}
     */
    public function points(): array
    {
        $file = self::pointsFile();
        $cached = $this->cachedPoints();
        if ($cached !== [] && !self::pointsStale($file)) {
            return ['ok' => true, 'message' => '', 'points' => $cached];
        }
        $r = $this->client()->allPoints();
        if (!$r['ok']) {
            // Stara lista jest lepsza niż żadna.
            return $cached !== [] ? ['ok' => true, 'message' => '', 'points' => $cached]
                : ['ok' => false, 'message' => 'Nie udało się pobrać listy punktów: ' . $r['message'], 'points' => []];
        }
        if ($file !== null) {
            if (!is_dir(dirname($file))) {
                @mkdir(dirname($file), 0700, true);
            }
            @file_put_contents($file, json_encode($r['points'], JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        return ['ok' => true, 'message' => '', 'points' => $r['points']];
    }

    /** Lista punktów z pliku (bez łączenia z API). @return array<int,array<string,string>> */
    private function cachedPoints(): array
    {
        $file = self::pointsFile();
        if ($file === null || !is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private static function pointsFile(): ?string
    {
        if (self::$pointsCacheFile !== null) {
            return self::$pointsCacheFile;
        }
        return defined('PASE_ROOT') ? PASE_ROOT . '/storage/cache/orlenpaczka-points.json' : null;
    }

    /** Lista jest nieaktualna, gdy pobrano ją przed dzisiejszą 6:00 (a jest już po 6:00) albo ponad dobę temu. */
    private static function pointsStale(?string $file): bool
    {
        if ($file === null || !is_file($file)) {
            return true;
        }
        $mtime = (int) filemtime($file);
        $sixToday = (new \DateTimeImmutable('today 06:00', self::tz()))->getTimestamp();
        return $mtime < time() - 86400 || (time() >= $sixToday && $mtime < $sixToday);
    }

    /**
     * Kod punktu do awizacji. Przyjmuje pełny kod („WS-116263-C3-01"), uniwersalny („XX-116263-00-00")
     * albo sam 6-cyfrowy numer punktu (PSD) - wtedy szukamy pełnego kodu na liście, a bez niej
     * podajemy kod uniwersalny (ORLEN Paczka sama go dopasuje, komunikat 006).
     * @param array<int,array<string,string>> $points
     */
    public static function pointCode(string $raw, array $points = []): string
    {
        $s = strtoupper(preg_replace('/\s+/', '', $raw) ?? '');
        if (preg_match('/^[A-Z]{2}-\d{6}-[A-Z0-9]{2}-[A-Z0-9]{2}$/', $s)) {
            return $s;
        }
        if (!preg_match('/(?<!\d)(\d{6})(?!\d)/', $s, $m)) {
            return '';
        }
        foreach ($points as $p) {
            if (($p['psd'] ?? '') === $m[1] || str_contains((string) ($p['code'] ?? ''), '-' . $m[1] . '-')) {
                return (string) $p['code'];
            }
        }
        return 'XX-' . $m[1] . '-00-00';
    }

    /**
     * Punkt z zamówienia (Allegro: delivery.pickupPoint, WooCommerce: meta wtyczki wysyłkowej,
     * albo punkt poprawiony ręcznie w CRM).
     * @return array{code:string,note:string}
     */
    private function orderPoint(array $order): array
    {
        $row = $order['row'] ?? [];
        $pp = PickupPoint::fromOrder($order['payload'] ?? [], json_decode((string) ($row['local_pickup_point'] ?? ''), true) ?: null);
        if ($pp === null) {
            return ['code' => '', 'note' => 'Zamówienie nie ma punktu odbioru — wpisz kod punktu albo wyszukaj go po mieście.'];
        }
        $points = $this->cachedPoints();
        $code = self::pointCode($pp['id'], $points);
        if ($code === '') {
            return ['code' => '', 'note' => 'Punkt z zamówienia (' . $pp['id'] . ') to nie punkt ORLEN Paczka — wybierz punkt ORLEN Paczka.'];
        }
        $label = '';
        foreach ($points as $p) {
            if (($p['code'] ?? '') === $code) {
                $label = self::pointLabel($p);
                break;
            }
        }
        $label = $label !== '' ? $label : trim($pp['name'] . ' ' . $pp['line']);
        return ['code' => $code, 'note' => 'Punkt z zamówienia' . ($label !== '' ? ': ' . $label : '') . '.'];
    }

    // ================= Podjazd kuriera =================

    /** @return array{ok:bool,message:string,pickup_no?:string,from?:string,to?:string} */
    private function orderPickup(string $packCode, string $date, string $nr): array
    {
        $today = self::plNow('Y-m-d');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $date >= $today ? $date : (new \DateTimeImmutable('+1 weekday', self::tz()))->format('Y-m-d');
        $addr = $this->pickupAddress($nr);
        $c = $this->client();
        $av = $c->availablePickups($addr, $date . 'T' . ($date === $today ? self::plNow('H:i:s') : '08:00:00'));
        if (!$av['ok']) {
            return ['ok' => false, 'message' => 'Nie zamówiono kuriera: ' . $av['message'] . ' Zamów podjazd w ORLEN Paczka.'];
        }
        $slot = null;
        foreach ($av['days'] as $d) {
            $from = self::localTime((string) ($d['MinReadyDate'] ?? ''));
            $to = self::localTime((string) ($d['MaxPickupDate'] ?? ''));
            if ($from === '' || $to === '' || substr($from, 0, 10) < $date) {
                continue;
            }
            // Zamówienie trzeba złożyć co najmniej MinimumInterval minut przed końcem przedziału.
            $interval = (int) ($d['MinimumInterval'] ?? 0);
            if (self::plTs($to) - $interval * 60 <= time()) {
                continue;
            }
            $slot = ['from' => $from, 'to' => $to];
            break;
        }
        if ($slot === null) {
            return ['ok' => false, 'message' => "Nie zamówiono kuriera: brak wolnego przedziału od {$date}. Zamów podjazd w ORLEN Paczka."];
        }
        $r = $c->callPickup([$packCode], $slot['from'], $slot['to'], $addr);
        if (!$r['ok']) {
            return ['ok' => false, 'message' => 'Nie zamówiono kuriera: ' . $r['message'] . ' Zamów podjazd w ORLEN Paczka.'];
        }
        $when = substr($slot['from'], 0, 10) . ' ' . substr($slot['from'], 11, 5) . '–' . substr($slot['to'], 11, 5);
        return ['ok' => true, 'pickup_no' => $r['pickup_no'], 'from' => $slot['from'], 'to' => $slot['to'],
                'message' => "Kurier zamówiony na {$when}" . ($r['pickup_no'] !== '' ? " (zlecenie nr {$r['pickup_no']})" : '') . '.'];
    }

    /** „2024-08-29T08:00:00+02:00" -> „2024-08-29T08:00:00" (czas polski, jak oczekuje CallPickupNew). */
    private static function localTime(string $v): string
    {
        $v = preg_replace('/\s+/', '', $v) ?? '';
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $v, $m) ? $m[0] : '';
    }

    /** @return array<string,string> */
    private function pickupAddress(string $nr): array
    {
        $company = trim((string) $this->cfg('sender_company', ''));
        $first = trim((string) $this->cfg('sender_first_name', ''));
        $last = trim((string) $this->cfg('sender_last_name', ''));
        $flat = trim((string) $this->cfg('sender_flat', ''));
        return [
            'PostCode'       => self::postcode((string) $this->cfg('sender_postcode', '')),
            'City'           => self::cut((string) $this->cfg('sender_city', ''), 30),
            'Street'         => self::cut((string) $this->cfg('sender_street', ''), 30),
            'BuildingNo'     => self::cut(trim((string) $this->cfg('sender_building', '')) . ($flat !== '' ? '/' . $flat : ''), 10),
            'Email'          => self::cut((string) $this->cfg('sender_email', ''), 60),
            'PartnerName'    => self::cut($company !== '' ? $company : trim($first . ' ' . $last), 30),
            'PersonName'     => self::cut($first, 30),
            'PersonSurname'  => self::cut($last, 30),
            'Telephone'      => self::phone((string) $this->cfg('sender_phone', '')),
            'ExternalNumber' => self::cut($nr, 30),
        ];
    }

    // ================= Dane paczki =================

    /**
     * Gabaryt: wybrany albo dobrany z wymiarów (najmniejszy, w który paczka się mieści).
     * @return array{ok:bool,message:string,size:string}
     */
    private function resolveSize(string $service, array $parcel): array
    {
        $weight = (float) ($parcel['weight'] ?? 0);
        if ($weight > self::MAX_WEIGHT) {
            return ['ok' => false, 'size' => '', 'message' => 'Paczka waży ponad 20 kg — ORLEN Paczka przyjmuje do 20 kg.'];
        }
        $service = strtoupper(trim($service));
        if ($service === '' || $service === 'AUTO') {
            $cfg = strtoupper((string) $this->cfg('box_size', 'auto'));
            $service = $service === '' && isset(self::SIZES[$cfg]) ? $cfg : 'AUTO';
        }
        if (isset(self::SIZES[$service])) {
            return ['ok' => true, 'size' => $service, 'message' => ''];
        }
        $size = self::sizeFor($parcel);
        return $size === null
            ? ['ok' => false, 'size' => '', 'message' => 'Paczka nie mieści się w gabarycie L ORLEN Paczka (maks. 41 × 38 × 60 cm).']
            : ['ok' => true, 'size' => $size, 'message' => ''];
    }

    /** Najmniejszy gabaryt dla wymiarów (cm). Bez wymiarów - M (domyślny gabaryt ORLEN Paczka). */
    public static function sizeFor(array $parcel): ?string
    {
        $dims = [(float) ($parcel['x'] ?? 0), (float) ($parcel['y'] ?? 0), (float) ($parcel['z'] ?? 0)];
        if (min($dims) <= 0) {
            return 'M';
        }
        rsort($dims);
        foreach (self::SIZES as $k => $s) {
            if ($dims[0] <= $s['dims'][0] && $dims[1] <= $s['dims'][1] && $dims[2] <= $s['dims'][2]) {
                return $k;
            }
        }
        return null;
    }

    /** @return array{0:float,1:bool} cena i czy z ustawień */
    private function sizePrice(string $size): array
    {
        $v = trim(str_replace([',', 'zł', ' '], ['.', '', ''], (string) $this->cfg('price_' . strtolower($size), '')));
        if ($v !== '' && is_numeric($v) && (float) $v > 0) {
            return [round((float) $v, 2), true];
        }
        return [self::STANDARD_PRICES[$size] ?? self::STANDARD_PRICES['M'], false];
    }

    private function pdfFormat(): string
    {
        return $this->cfg('pdf_format', 'PDF10') === 'PDF' ? 'PDF' : 'PDF10';
    }

    private function zplFormat(): string
    {
        return $this->cfg('zpl_format', 'ZPL') === 'ZPL_300DPI' ? 'ZPL_300DPI' : 'ZPL';
    }

    /** Brakujące pola nadawcy (nazwy dla użytkownika). @return string[] */
    private function missingSender(): array
    {
        $missing = [];
        if (trim((string) $this->cfg('sender_company', '')) === ''
            && (trim((string) $this->cfg('sender_first_name', '')) === '' || trim((string) $this->cfg('sender_last_name', '')) === '')) {
            $missing[] = 'firma albo imię i nazwisko';
        }
        foreach (['sender_street' => 'ulica', 'sender_building' => 'numer budynku', 'sender_postcode' => 'kod pocztowy',
                  'sender_city' => 'miejscowość', 'sender_phone' => 'telefon', 'sender_email' => 'e-mail'] as $k => $label) {
            if (trim((string) $this->cfg($k, '')) === '') {
                $missing[] = $label;
            }
        }
        return $missing;
    }

    /** @return array<string,string> pola nadawcy <BusinessPack> */
    private function sender(): array
    {
        return [
            'SenderEMail'          => self::cut((string) $this->cfg('sender_email', ''), 60),
            'SenderFirstName'      => self::cut((string) $this->cfg('sender_first_name', ''), 30),
            'SenderLastName'       => self::cut((string) $this->cfg('sender_last_name', ''), 30),
            'SenderCompanyName'    => self::cut((string) $this->cfg('sender_company', ''), 70),
            'SenderStreetName'     => self::cut((string) $this->cfg('sender_street', ''), 30),
            'SenderBuildingNumber' => self::cut((string) $this->cfg('sender_building', ''), 10),
            'SenderFlatNumber'     => self::cut((string) $this->cfg('sender_flat', ''), 10),
            'SenderCity'           => self::cut((string) $this->cfg('sender_city', ''), 30),
            'SenderPostCode'       => self::postcode((string) $this->cfg('sender_postcode', '')),
            'SenderPhoneNumber'    => self::phone((string) $this->cfg('sender_phone', '')),
        ];
    }

    /**
     * Odbiorca z zamówienia: WooCommerce (shipping, a brakujące z billing) albo Allegro (delivery.address + buyer).
     * E-mail i telefon poprawione ręcznie w CRM mają pierwszeństwo.
     * @return array<string,string> pola odbiorcy <BusinessPack>
     */
    public static function receiver(array $order): array
    {
        $row = $order['row'] ?? [];
        $payload = $order['payload'] ?? [];
        $b = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
        $s = is_array($payload['shipping'] ?? null) ? $payload['shipping'] : [];
        if ((isset($payload['buyer']) || isset($payload['delivery'])) && empty($s['address_1']) && empty($b['address_1'])) {
            $da = $payload['delivery']['address'] ?? [];
            $by = $payload['buyer'] ?? [];
            $s = [
                'first_name' => $da['firstName'] ?? ($by['firstName'] ?? ''), 'last_name' => $da['lastName'] ?? ($by['lastName'] ?? ''),
                'company' => $da['companyName'] ?? '', 'address_1' => $da['street'] ?? '', 'postcode' => $da['zipCode'] ?? '',
                'city' => $da['city'] ?? '', 'phone' => $da['phoneNumber'] ?? '', 'email' => $by['email'] ?? '',
            ];
            $b = ['email' => $by['email'] ?? '', 'phone' => $by['phoneNumber'] ?? ''];
        }
        $pick = static fn(string $k): string => trim((string) (($s[$k] ?? '') !== '' ? $s[$k] : ($b[$k] ?? '')));
        $email = trim((string) ($row['local_billing_email'] ?? '')) ?: $pick('email');
        $phone = trim((string) ($row['local_billing_phone'] ?? '')) ?: $pick('phone');
        [$street, $building, $flat] = self::splitStreet(trim($pick('address_1') . ' ' . $pick('address_2')));
        return [
            'EMail'          => self::cut($email, 60),
            'FirstName'      => self::cut($pick('first_name'), 30),
            'LastName'       => self::cut($pick('last_name'), 30),
            'CompanyName'    => self::cut($pick('company'), 70),
            'StreetName'     => self::cut($street, 30),
            'BuildingNumber' => self::cut($building, 10),
            'FlatNumber'     => self::cut($flat, 10),
            'City'           => self::cut($pick('city'), 30),
            'PostCode'       => self::postcode($pick('postcode')),
            'PhoneNumber'    => self::phone($phone),
        ];
    }

    /** „ul. Długa 5/3" -> [„Długa", „5", „3"]; bez numeru - cała linia jako ulica. @return array{0:string,1:string,2:string} */
    public static function splitStreet(string $line): array
    {
        $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');
        $line = (string) preg_replace('/^(ul\.|ulica|al\.|aleja|os\.|pl\.)\s*/iu', '', $line);
        if (preg_match('/^(.*?\D)\s+(\d+[A-Za-z]?)\s*(?:(?:\/|m\.?|lok\.?)\s*(\w+))?$/u', $line, $m)) {
            return [trim($m[1], " ,"), $m[2], $m[3] ?? ''];
        }
        return [$line, '', ''];
    }

    /** Telefon: 9 cyfr bez +48 (format przyjmowany przez API). Inny kraj - z prefiksem. */
    public static function phone(string $raw): string
    {
        $d = preg_replace('/\D+/', '', $raw) ?? '';
        if (str_starts_with($d, '0048')) {
            $d = substr($d, 4);
        } elseif (strlen($d) === 11 && str_starts_with($d, '48')) {
            $d = substr($d, 2);
        } elseif (strlen($d) === 10 && str_starts_with($d, '0')) {
            $d = substr($d, 1);
        }
        return strlen($d) === 9 ? $d : (strlen($d) > 9 ? '+' . substr($d, 0, 11) : $d);
    }

    /** Kod pocztowy w formacie 00-000. */
    public static function postcode(string $raw): string
    {
        $d = preg_replace('/\D+/', '', $raw) ?? '';
        return strlen($d) === 5 ? substr($d, 0, 2) . '-' . substr($d, 2) : self::cut(trim($raw), 6);
    }

    private static function cut(string $v, int $len): string
    {
        return mb_substr(trim($v), 0, $len);
    }
}
