<?php
declare(strict_types=1);

namespace PasePlugin\Allegrowysylka;

use Pase\Plugin\AbstractPlugin;
use Pase\Plugin\Capability;
use Pase\Plugin\Contract\CourierAsync;
use Pase\Plugin\PluginManifest;
use Pase\Support\Logger;
use Pase\Support\Runtime;
use PasePlugin\Allegro\AllegroPlugin;

/**
 * Wtyczka „Wysyłam z Allegro" (kurier) — nadawanie paczek dla zamówień z Allegro
 * przez Shipment Management API, na UMOWIE ALLEGRO albo na WŁASNEJ UMOWIE z przewoźnikiem
 * podpiętej w Allegro (usługi z owner = CLIENT i credentialsId).
 *
 * Wymaga połączonej wtyczki `allegro` (OAuth) - z niej bierze token i środowisko.
 * Numer przesyłki Allegro samo dopina do zamówienia (kupujący widzi go w Allegro).
 *
 * Wejście createShipment() (tablica od rdzenia, patrz public/admin/courier_shipment.php):
 *   order         => ['row' => wiersz woo_orders, 'payload' => zamówienie z Allegro]
 *   parcel        => ['weight' => kg, 'x' => cm, 'y' => cm, 'z' => cm]
 *   service       => 'allegro' (umowa Allegro, metoda kupującego) albo '<deliveryMethodId>|<credentialsId>' (własna umowa)
 *   label_format  => 'PDF' | 'ZPL'   (Allegro nie pozwala go zmienić po nadaniu)
 *   text_on_label => opcjonalny tekst
 *   handover      => 'point' (nadam w paczkomacie / PaczkoPunkcie / punkcie) | 'courier' (podjazd kuriera)
 *   pickup_date   => YYYY-MM-DD - dzień odbioru, gdy handover = courier
 *
 * Wycena: API Allegro nie zwraca ceny przed nadaniem. quote() liczy ją z cennika w ustawieniach
 * wtyczki; faktyczny koszt po nadaniu daje shipmentCost() z rozliczeń Allegro (billing-entries).
 */
final class AllegroWysylkaPlugin extends AbstractPlugin implements CourierAsync
{
    /** Ile sekund czekamy na wynik komendy w jednym żądaniu (resztę dokańcza resolveShipment). */
    private const POLL_SECONDS = 12;

    /** Odstęp między zapytaniami o status komendy (mikrosekundy); testy mogą go skrócić. */
    public static int $pollIntervalUs = 1_000_000;

    /** Sposoby przekazania paczki przewoźnikowi. */
    public const HANDOVER = [
        'point'   => 'Nadam w Paczkomacie / PaczkoPunkcie / punkcie przewoźnika',
        'courier' => 'Nadam z podjazdem kuriera (zamówię odbiór)',
    ];

    /** Fabryka klienta - testy mogą ją podmienić. @var (callable():WysylkaClient)|null */
    public static $clientFactory = null;

    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            type: 'allegro_wysylka',
            name: 'Wysyłam z Allegro',
            version: '1.0.0',
            author: 'Veless',
            capabilities: [Capability::COURIER],
            fields: [
                ['key' => 'default_contract', 'label' => 'Domyślna umowa przy nadawaniu', 'type' => 'select', 'default' => 'allegro',
                 'options' => ['allegro' => 'Umowa Allegro', 'own' => 'Własna umowa z przewoźnikiem (podpięta w Allegro)'],
                 'help' => 'Wstępnie zaznaczona przy nadawaniu - można zmienić przy każdej paczce.'],
                ['key' => 'handover', 'label' => 'Domyślny sposób nadania', 'type' => 'select', 'default' => 'point',
                 'options' => self::HANDOVER,
                 'help' => 'Jak przekazujesz paczkę przewoźnikowi. Wstępnie zaznaczony przy nadawaniu - można zmienić przy każdej paczce.'],
                ['key' => 'label_format', 'label' => 'Domyślny format etykiety', 'type' => 'select', 'default' => 'PDF',
                 'options' => ['PDF' => 'PDF', 'ZPL' => 'ZPL (Zebra)'],
                 'help' => 'Allegro ustala format przy nadaniu - później można zmienić tylko rozmiar strony (A4/A6).'],
                ['key' => 'text_on_label', 'label' => 'Tekst na etykiecie', 'type' => 'text',
                 'help' => 'Opcjonalnie. {nr} = numer zamówienia w CRM, np. „Zamówienie {nr}”.'],
                ['key' => 'price_list', 'label' => 'Cennik do wyceny (przed nadaniem)', 'type' => 'textarea',
                 'help' => 'Allegro nie podaje ceny przez API, więc wycena bierze się z tego cennika. Jedna linia = '
                    . 'fragment nazwy metody ; sposób (punkt / kurier / *) ; cena, np. „Paczkomaty InPost ; punkt ; 3.19”. '
                    . 'Bez dopasowania pokażemy ostatni faktyczny koszt tą metodą.'],
            ],
            color: '#ff5a00',
            icon: '📮',
            multiple: false,
            description: 'Nadawanie paczek z zamówień Allegro na umowie Allegro lub własnej umowie z przewoźnikiem. Wymaga połączonego konta Allegro.',
            category: Capability::CAT_COURIER
        );
    }

    public function client(): WysylkaClient
    {
        if (is_callable(self::$clientFactory)) {
            return (self::$clientFactory)();
        }
        return new WysylkaClient(AllegroPlugin::makeClient(Runtime::pdo(), Runtime::config('allegro')));
    }

    public function testConnection(): array
    {
        $r = $this->client()->deliveryServices();
        if (!$r['ok']) {
            return ['ok' => false, 'message' => $r['message']];
        }
        $services = $r['data']['services'] ?? [];
        $own = count(array_filter($services, static fn($s) => ($s['owner'] ?? '') === 'CLIENT'));
        return ['ok' => true, 'message' => 'Połączono z „Wysyłam z Allegro”. Usług: ' . count($services)
            . ($own > 0 ? ", w tym na własnej umowie: {$own}." : ' (brak własnych umów podpiętych w Allegro).')];
    }

    /** Rdzeń pokazuje tę wtyczkę tylko przy zamówieniach z Allegro (patrz Pase\Services\CourierShipping). */
    public function supportsOrder(array $order): bool
    {
        return isset($order['payload']['lineItems']) && !empty($order['row']['order_number']);
    }

    // ================= Courier =================

    /**
     * Umowy/usługi do wyboru przy nadaniu.
     * @param array<string,mixed> $parcel może zawierać 'order' => ['payload' => ...] (metoda dostawy kupującego)
     * @return array{ok:bool,message:string,default:string,services:array<int,array{id:string,label:string,group:string}>}
     */
    public function listServices(array $parcel): array
    {
        $method = $parcel['order']['payload']['delivery']['method']['name'] ?? null;
        $services = [[
            'id'    => 'allegro',
            'label' => 'Umowa Allegro' . ($method ? " — {$method}" : ''),
            'group' => 'allegro',
        ]];

        $r = $this->client()->deliveryServices();
        $message = $r['ok'] ? '' : $r['message'];
        foreach ($r['data']['services'] ?? [] as $s) {
            if (($s['owner'] ?? '') !== 'CLIENT' || empty($s['id']['deliveryMethodId']) || empty($s['id']['credentialsId'])) {
                continue;
            }
            $services[] = [
                'id'    => $s['id']['deliveryMethodId'] . '|' . $s['id']['credentialsId'],
                'label' => trim(($s['name'] ?? 'Usługa') . (!empty($s['carrierId']) ? " ({$s['carrierId']})" : '')),
                'group' => 'own',
            ];
        }

        // Przewoźnik usługi (do podpowiedzi sposobu nadania): umowa Allegro = metoda kupującego.
        $orderMethodId = $parcel['order']['payload']['delivery']['method']['id'] ?? null;
        foreach ($services as &$sv) {
            $dm = $sv['id'] === 'allegro' ? $orderMethodId : explode('|', $sv['id'])[0];
            $sv['carrier'] = self::carrierOf($r['data']['services'] ?? [], $dm);
        }
        unset($sv);

        $default = 'allegro';
        if ($this->cfg('default_contract') === 'own') {
            foreach ($services as $s) {
                if ($s['group'] === 'own') { $default = $s['id']; break; }
            }
        }
        $handoverDefault = (string) $this->cfg('handover', 'point');
        return ['ok' => true, 'message' => $message, 'default' => $default, 'services' => $services,
                'handover' => ['default' => isset(self::HANDOVER[$handoverDefault]) ? $handoverDefault : 'point',
                               'options' => self::HANDOVER]];
    }

    public function listPaymentOptions(): array
    {
        return []; // koszt nalicza Allegro na koncie sprzedawcy
    }

    /**
     * Wycena z cennika w ustawieniach (Allegro nie udostępnia ceny przez API).
     * @param array $params order, service, service_label, handover
     */
    public function quote(array $params): array
    {
        $method = (string) ($params['service_label'] ?? '');
        if ($method === '' || ($params['service'] ?? 'allegro') === 'allegro') {
            $method = trim($method . ' ' . ($params['order']['payload']['delivery']['method']['name'] ?? ''));
        }
        $handover = (string) ($params['handover'] ?? $this->cfg('handover', 'point'));
        $price = self::priceFromList((string) $this->cfg('price_list', ''), $method, $handover);
        return $price === null
            ? ['ok' => false, 'price' => null, 'message' => 'Brak tej metody w cenniku wtyczki.']
            : ['ok' => true, 'price' => $price, 'message' => 'wg cennika w ustawieniach'];
    }

    /**
     * Faktyczny koszt przesyłki z rozliczeń Allegro (billing-entries zamówienia).
     * Opłaty pojawiają się z opóźnieniem - null, dopóki ich nie ma.
     * @return array{amount:float,currency:string}|null
     */
    public function shipmentCost(string $externalId, array $order): ?array
    {
        if (str_starts_with($externalId, 'cmd:')) {
            return null;
        }
        return $this->orderShipmentCost($order);
    }

    /**
     * Opłaty za wysyłkę naliczone przez Allegro dla całego zamówienia (billing-entries) -
     * także gdy paczkę nadano poza CRM (np. na allegro.pl) i wpisano jej numer ręcznie („Inne").
     * @return array{amount:float,currency:string}|null
     */
    public function orderShipmentCost(array $order): ?array
    {
        $checkoutFormId = (string) ($order['row']['order_number'] ?? '');
        if ($checkoutFormId === '' || !$this->supportsOrder($order)) {
            return null;
        }
        $r = $this->client()->billingEntries($checkoutFormId);
        if (!$r['ok']) {
            return null;
        }
        $sum = 0.0;
        $currency = 'PLN';
        foreach ($r['data']['billingEntries'] ?? [] as $e) {
            $name = mb_strtolower((string) ($e['type']['name'] ?? ''));
            $amount = (float) ($e['value']['amount'] ?? 0);
            if ($amount < 0 && preg_match('/wysyłam z allegro|przesył|dostaw|etykiet|nadani/u', $name)) {
                $sum += -$amount;
                $currency = (string) ($e['value']['currency'] ?? $currency);
            }
        }
        return $sum > 0 ? ['amount' => round($sum, 2), 'currency' => $currency] : null;
    }

    /** Sugestia paczki z Allegro (do wypełnienia formularza). */
    public function proposal(array $order): array
    {
        $p = $this->buildInput($order);
        if (!$p['ok']) {
            return $p;
        }
        $pkg = $p['input']['packages'][0] ?? [];
        $val = static fn(string $k) => isset($pkg[$k]['value']) ? (float) $pkg[$k]['value'] : null;
        return [
            'ok'      => true,
            'message' => $p['message'],
            'package' => ['weight' => $val('weight'), 'x' => $val('length'), 'y' => $val('width'), 'z' => $val('height')],
            'sender'  => trim(($p['input']['sender']['name'] ?? '') . ', ' . ($p['input']['sender']['city'] ?? ''), ', '),
            'point'   => $p['input']['receiver']['point'] ?? null,
        ];
    }

    public function createShipment(array $shipment): array
    {
        $fail = static fn(string $m, $raw = null) => ['ok' => false, 'pending' => false, 'message' => $m, 'waybill_no' => null,
            'external_id' => null, 'price' => null, 'label_link' => null, 'raw' => $raw];

        $built = $this->buildInput($shipment['order'] ?? []);
        if (!$built['ok']) {
            return $fail($built['message']);
        }
        $input = $built['input'];

        // Umowa: Allegro (metoda kupującego z propozycji) albo własna umowa (credentialsId).
        $service = (string) ($shipment['service'] ?? 'allegro');
        if ($service !== '' && $service !== 'allegro' && str_contains($service, '|')) {
            [$dm, $cred] = explode('|', $service, 2);
            $input['deliveryMethodId'] = $dm;
            $input['credentialsId'] = $cred;
        }

        $row = $shipment['order']['row'] ?? [];
        $nr = (string) ($row['pase_number'] ?? $row['woo_order_id'] ?? '');
        $parcel = $shipment['parcel'] ?? [];
        $text = trim((string) ($shipment['text_on_label'] ?? ''));
        if ($text === '' && ($tpl = trim((string) $this->cfg('text_on_label', ''))) !== '') {
            $text = str_replace('{nr}', $nr, $tpl);
        }
        $base = $input['packages'][0] ?? [];

        $fmt = trim((string) ($shipment['label_format'] ?? ''));
        if ($fmt === '') {
            $fmt = (string) $this->cfg('label_format', 'PDF');
        }
        $input['labelFormat'] = strtoupper($fmt) === 'ZPL' ? 'ZPL' : 'PDF';
        $input['referenceNumber'] = mb_substr($nr, 0, 30);
        $input['packages'] = [[
            'type'        => $base['type'] ?? 'PACKAGE',
            'length'      => ['value' => (float) ($parcel['x'] ?? 0), 'unit' => 'CENTIMETER'],
            'width'       => ['value' => (float) ($parcel['y'] ?? 0), 'unit' => 'CENTIMETER'],
            'height'      => ['value' => (float) ($parcel['z'] ?? 0), 'unit' => 'CENTIMETER'],
            'weight'      => ['value' => (float) ($parcel['weight'] ?? 0), 'unit' => 'KILOGRAMS'],
            'textOnLabel' => $text !== '' ? mb_substr($text, 0, 100) : ($base['textOnLabel'] ?? null),
        ]];
        // Sposób nadania.
        $handover = (string) ($shipment['handover'] ?? '');
        if (!isset(self::HANDOVER[$handover])) {
            $handover = (string) $this->cfg('handover', 'point');
        }
        $carrier = self::carrierOf($this->client()->deliveryServices()['data']['services'] ?? [], $input['deliveryMethodId'] ?? null);
        $applied = self::applyHandover($input, $built['options'] ?? [], $carrier, $handover);
        if (!$applied['ok']) {
            return $fail($applied['message']);
        }
        $input = self::withoutNulls($applied['input']);

        $cmd = $this->client()->createCommand($input);
        if (!$cmd['ok']) {
            return $fail('Allegro odrzuciło przesyłkę: ' . $cmd['message'], ['input' => $input, 'response' => $cmd['data']]);
        }

        // Podjazd kuriera: po utworzeniu przesyłki zamawiamy odbiór (patrz resolveShipment).
        $pendingId = 'cmd:' . $cmd['command_id'];
        if ($handover === 'courier') {
            $date = (string) ($shipment['pickup_date'] ?? '');
            $pendingId .= ';pickup=' . (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d', strtotime('+1 weekday')));
        }
        $deadline = microtime(true) + self::POLL_SECONDS;
        do {
            usleep(self::$pollIntervalUs);
            $res = $this->resolveShipment($pendingId);
        } while ($res['pending'] && microtime(true) < $deadline);

        return [
            'ok'            => $res['ok'],
            'pending'       => $res['pending'],
            'message'       => $res['message'],
            'waybill_no'    => $res['waybill_no'],
            'external_id'   => $res['pending'] ? $pendingId : $res['external_id'],
            'courier_label' => $res['courier_label'] ?? 'Allegro',
            'price'         => null,
            'label_link'    => null,
            'raw'           => ['input' => $input, 'response' => $res['raw'] ?? null],
        ];
    }

    public function resolveShipment(string $pendingExternalId): array
    {
        $ref = str_starts_with($pendingExternalId, 'cmd:') ? substr($pendingExternalId, 4) : $pendingExternalId;
        [$commandId, $opts] = array_pad(explode(';', $ref, 2), 2, '');
        parse_str(str_replace(';', '&', $opts), $opt);
        $c = $this->client();

        $st = $c->createCommandStatus($commandId);
        if (!$st['ok']) {
            // Chwilowy błąd API - spróbujemy później.
            return ['ok' => true, 'pending' => true, 'message' => 'Nie udało się sprawdzić statusu w Allegro: ' . $st['message'],
                    'external_id' => null, 'waybill_no' => null];
        }
        $status = (string) ($st['data']['status'] ?? '');

        if ($status === 'ERROR') {
            $msg = WysylkaClient::messages($st['data']['errors'] ?? []) ?: 'Allegro nie utworzyło przesyłki.';
            Logger::warn('Wysyłam z Allegro: nadanie odrzucone', ['command' => $commandId, 'errors' => $st['data']['errors'] ?? []]);
            return ['ok' => false, 'pending' => false, 'message' => 'Allegro odrzuciło przesyłkę: ' . $msg,
                    'external_id' => null, 'waybill_no' => null, 'raw' => $st['data']];
        }
        if ($status !== 'SUCCESS' || empty($st['data']['shipmentId'])) {
            return ['ok' => true, 'pending' => true, 'message' => 'Allegro jeszcze tworzy przesyłkę — numer listu pojawi się po odświeżeniu zamówienia.',
                    'external_id' => null, 'waybill_no' => null];
        }

        $shipmentId = (string) $st['data']['shipmentId'];
        $det = $c->shipment($shipmentId);
        $pkg = $det['data']['packages'][0] ?? [];
        $waybill = $pkg['waybill'] ?? null;
        $carrier = $det['data']['carrier'] ?? ($pkg['transportingInfo'][0]['carrierId'] ?? null);

        $pickupMsg = '';
        if (!empty($opt['pickup'])) {
            $pickupMsg = ' ' . $this->orderPickup($shipmentId, $det['data']['sender'] ?? [], (string) $opt['pickup']);
        }

        return [
            'ok'            => true,
            'pending'       => false,
            'message'       => 'Nadano przez Allegro' . ($waybill ? ", nr listu {$waybill}" : '') . '.' . $pickupMsg,
            'external_id'   => $shipmentId,
            'waybill_no'    => $waybill,
            'courier_label' => 'Allegro' . ($carrier ? " · {$carrier}" : '') . (!empty($det['data']['credentialsId']) ? ' (własna umowa)' : ''),
            'raw'           => $det['data'] ?: $st['data'],
        ];
    }

    /**
     * @param string $format A4 | LBL (A6) | ZPL | EPL - dla Allegro liczy się tylko rozmiar strony;
     *                       typ pliku (PDF/ZPL) ustalono przy nadaniu.
     */
    public function getLabel(string $externalId, string $format = 'A4'): array
    {
        if ($externalId === '' || str_starts_with($externalId, 'cmd:')) {
            return ['ok' => false, 'content' => null, 'filename' => null, 'mime' => null, 'message' => 'Przesyłka Allegro nie jest jeszcze gotowa.'];
        }
        $c = $this->client();
        $det = $c->shipment($externalId);
        $isZpl = ($det['data']['labelFormat'] ?? 'PDF') === 'ZPL';
        $r = $c->label([$externalId], $format === 'A4' ? 'A4' : 'A6');
        if (!$r['ok']) {
            return ['ok' => false, 'content' => null, 'filename' => null, 'mime' => null, 'message' => $r['message']];
        }
        $name = 'etykieta-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) (($det['data']['packages'][0]['waybill'] ?? '') ?: $externalId));
        return [
            'ok'       => true,
            'content'  => $r['content'],
            'filename' => $name . ($isZpl ? '.zpl' : '.pdf'),
            'mime'     => $isZpl ? 'application/octet-stream' : 'application/pdf',
            'message'  => '',
        ];
    }

    public function cancelShipment(string $externalId): array
    {
        if ($externalId === '' || str_starts_with($externalId, 'cmd:')) {
            return ['ok' => false, 'message' => 'Przesyłka Allegro nie jest jeszcze utworzona — nie ma czego anulować.'];
        }
        $c = $this->client();
        $cmd = $c->cancelCommand($externalId);
        if (!$cmd['ok']) {
            return ['ok' => false, 'message' => 'Allegro odrzuciło anulowanie: ' . $cmd['message']];
        }
        $deadline = microtime(true) + 8;
        do {
            usleep(self::$pollIntervalUs);
            $st = $c->cancelCommandStatus($cmd['command_id']);
            $status = (string) ($st['data']['status'] ?? '');
            if ($status === 'SUCCESS') {
                return ['ok' => true, 'message' => 'Przesyłka anulowana w Allegro.'];
            }
            if ($status === 'ERROR') {
                return ['ok' => false, 'message' => 'Allegro nie anulowało przesyłki: '
                    . (WysylkaClient::messages($st['data']['errors'] ?? []) ?: 'nieznany błąd')];
            }
        } while (microtime(true) < $deadline);

        return ['ok' => false, 'message' => 'Allegro nie potwierdziło anulowania w czasie — sprawdź przesyłkę w Allegro.'];
    }

    // ================= pomocnicze =================

    /**
     * Dane do nadania: propozycja Allegro (nadawca z konta, odbiorca z punktem, paczka),
     * a gdy jej brak - odbiorca złożony z zamówienia.
     * @return array{ok:bool,message:string,input:array<string,mixed>}
     */
    private function buildInput(array $order): array
    {
        $row = $order['row'] ?? [];
        $payload = $order['payload'] ?? [];
        $checkoutFormId = (string) ($row['order_number'] ?? ($payload['id'] ?? ''));
        if (!isset($payload['lineItems']) || $checkoutFormId === '') {
            return ['ok' => false, 'message' => '„Wysyłam z Allegro” działa tylko dla zamówień z Allegro.', 'input' => []];
        }

        $r = $this->client()->deliveryProposal($checkoutFormId);
        if ($r['ok'] && !empty($r['data']['suggestedInput'])) {
            $input = $r['data']['suggestedInput'];
            if (empty($input['deliveryMethodId']) && !empty($payload['delivery']['method']['id'])) {
                $input['deliveryMethodId'] = $payload['delivery']['method']['id'];
            }
            return ['ok' => true, 'message' => '', 'input' => $input, 'options' => $r['data']['deliveryOptions'] ?? []];
        }
        if (in_array($r['status'], [0, 401, 403], true)) {
            return ['ok' => false, 'message' => $r['message'], 'input' => []];
        }

        Logger::warn("Wysyłam z Allegro: brak propozycji dla {$checkoutFormId} ({$r['status']}) - składam dane z zamówienia");
        $addr  = $payload['delivery']['address'] ?? [];
        $buyer = $payload['buyer'] ?? [];
        return ['ok' => true, 'message' => 'Allegro nie zwróciło propozycji — dane odbiorcy wzięte z zamówienia.', 'input' => [
            'deliveryMethodId' => $payload['delivery']['method']['id'] ?? null,
            'receiver' => [
                'name'        => trim(($addr['firstName'] ?? '') . ' ' . ($addr['lastName'] ?? '')),
                'company'     => $addr['companyName'] ?? null,
                'street'      => $addr['street'] ?? '',
                'postalCode'  => $addr['zipCode'] ?? '',
                'city'        => $addr['city'] ?? '',
                'countryCode' => $addr['countryCode'] ?? 'PL',
                'email'       => $buyer['email'] ?? '',
                'phone'       => $addr['phoneNumber'] ?? ($buyer['phoneNumber'] ?? ''),
                'point'       => $payload['delivery']['pickupPoint']['id'] ?? null,
            ],
            'packages' => [['type' => 'PACKAGE']],
        ]];
    }

    /** Zamawia odbiór przez kuriera. Zwraca komunikat dla użytkownika (sukces lub powód porażki). */
    private function orderPickup(string $shipmentId, array $sender, string $date): string
    {
        $address = array_filter([
            'name'        => $sender['name'] ?? null,
            'company'     => $sender['company'] ?? null,
            'street'      => $sender['street'] ?? null,
            'postalCode'  => $sender['postalCode'] ?? null,
            'city'        => $sender['city'] ?? null,
            'countryCode' => $sender['countryCode'] ?? 'PL',
            'email'       => $sender['email'] ?? null,
            'phone'       => $sender['phone'] ?? null,
        ], static fn($v) => $v !== null && $v !== '');

        $c = $this->client();
        $prop = $c->pickupProposals([$shipmentId], $address, $date);
        if (!$prop['ok']) {
            return 'Nie zamówiono kuriera: ' . $prop['message'] . ' Zamów odbiór w Allegro.';
        }
        $times = self::findPickupTimes($prop['data']);
        $time = null;
        foreach ($times as $t) {
            if (($t['date'] ?? '') === $date) { $time = $t; break; }
        }
        $time ??= $times[0] ?? ['date' => $date];   // InPost przyjmuje samą datę
        $time = array_filter($time, static fn($v) => $v !== null && $v !== '');

        $cmd = $c->createPickupCommand([$shipmentId], $time, $address);
        if (!$cmd['ok']) {
            return 'Nie zamówiono kuriera: ' . $cmd['message'] . ' Zamów odbiór w Allegro.';
        }
        $deadline = microtime(true) + 6;
        do {
            usleep(self::$pollIntervalUs);
            $st = $c->pickupCommandStatus($cmd['command_id']);
            $status = (string) ($st['data']['status'] ?? '');
            if ($status === 'SUCCESS') {
                $when = ($time['date'] ?? $date) . (!empty($time['minTime']) ? " {$time['minTime']}–" . ($time['maxTime'] ?? '') : '');
                return "Kurier zamówiony na {$when}.";
            }
            if ($status === 'ERROR') {
                return 'Nie zamówiono kuriera: ' . (WysylkaClient::messages($st['data']['errors'] ?? []) ?: 'błąd Allegro') . ' Zamów odbiór w Allegro.';
            }
        } while (microtime(true) < $deadline);
        return 'Zamówienie kuriera jest w toku — sprawdź w Allegro, czy zostało potwierdzone.';
    }

    /** Pierwsza lista pickupTimes w odpowiedzi (struktura bywa zagnieżdżona per przesyłka). */
    private static function findPickupTimes(array $data): array
    {
        if (isset($data['pickupTimes']) && is_array($data['pickupTimes'])) {
            return array_values(array_filter($data['pickupTimes'], 'is_array'));
        }
        foreach ($data as $v) {
            if (is_array($v) && ($found = self::findPickupTimes($v)) !== []) {
                return $found;
            }
        }
        return [];
    }

    /** carrierId usługi o danym deliveryMethodId (z delivery-services). */
    private static function carrierOf(array $services, ?string $deliveryMethodId): ?string
    {
        foreach ($services as $s) {
            if ($deliveryMethodId !== null && ($s['id']['deliveryMethodId'] ?? null) === $deliveryMethodId) {
                return isset($s['carrierId']) ? strtoupper((string) $s['carrierId']) : null;
            }
        }
        return null;
    }

    /**
     * Ustawia sposób nadania w danych przesyłki.
     *   INPOST        : usługa "sendingAtPoint" = nadanie w punkcie; bez niej = podjazd kuriera.
     *   POCZTA POLSKA : "sendAtApm" (nadanie w Automacie Pocztex), gdy dostępna.
     *   inni          : nadanie w punkcie tylko, gdy Allegro podało punkt nadawcy; kurier = domyślne.
     * @return array{ok:bool,message:string,input:array}
     */
    public static function applyHandover(array $input, array $deliveryOptions, ?string $carrier, string $handover): array
    {
        $available = [];
        $props = [];
        foreach ($deliveryOptions as $o) {
            foreach ($o['additionalServices'] ?? [] as $a) {
                $available[] = is_array($a) ? (string) ($a['id'] ?? '') : (string) $a;
            }
            foreach ($o['additionalProperties'] ?? [] as $a) {
                $props[] = is_array($a) ? (string) ($a['id'] ?? '') : (string) $a;
            }
        }
        $services = array_values(array_filter(
            array_map(static fn($a) => is_array($a) ? (string) ($a['id'] ?? '') : (string) $a, $input['additionalServices'] ?? []),
            static fn($a) => $a !== '' && !in_array($a, ['sendingAtPoint', 'sendAtApm'], true)
        ));
        $extra = is_array($input['additionalProperties'] ?? null) ? $input['additionalProperties'] : [];
        unset($extra['inpost#sendingMethod']);

        if ($carrier === 'INPOST') {
            // InPost: nadanie w Paczkomacie / PaczkoPunkcie = usługa "sendingAtPoint",
            // brak tej usługi = podjazd kuriera. (Właściwość "inpost#sendingMethod" Allegro wycofało -
            // odrzuca ją błędem „nie jest już obsługiwany”, dlatego jej nie wysyłamy.)
            if ($handover === 'point') {
                $services[] = 'sendingAtPoint';
            } else {
                unset($input['sender']['point']); // kurier odbiera z adresu nadawcy
            }
        } elseif ($handover === 'point') {
            if (in_array('sendAtApm', $available, true)) {
                $services[] = 'sendAtApm';
            } elseif (empty($input['sender']['point'])) {
                return ['ok' => false, 'input' => $input, 'message' => 'Ten przewoźnik' . ($carrier ? " ({$carrier})" : '')
                    . ' nie ma nadania w punkcie przez API — wybierz „podjazd kuriera”.'];
            }
        } else {
            unset($input['sender']['point']); // kurier odbiera z adresu nadawcy
        }

        $input['additionalServices'] = array_values(array_unique($services));
        $input['additionalProperties'] = $extra;
        if ($input['additionalServices'] === []) { unset($input['additionalServices']); }
        if ($input['additionalProperties'] === []) { unset($input['additionalProperties']); }
        return ['ok' => true, 'message' => '', 'input' => $input];
    }

    /**
     * Cena z cennika: linie „fragment nazwy ; punkt|kurier|* ; cena”. Najdłuższy pasujący fragment wygrywa.
     */
    public static function priceFromList(string $list, string $method, string $handover): ?float
    {
        $method = mb_strtolower($method);
        $want = $handover === 'courier' ? 'kurier' : 'punkt';
        $best = null;
        $bestLen = -1;
        foreach (preg_split('/\R/u', $list) ?: [] as $line) {
            $parts = array_map('trim', explode(';', $line));
            if (count($parts) < 3 || $parts[0] === '') {
                continue;
            }
            [$frag, $mode, $price] = $parts;
            // sposób: „punkt/paczkomat/point” -> punkt, „kurier/courier” -> kurier, „*” lub puste -> dowolny
            $mode = mb_strtolower($mode);
            $modeKey = ($mode === '' || $mode === '*') ? '*'
                : ((str_starts_with($mode, 'k') || str_starts_with($mode, 'c')) ? 'kurier' : 'punkt');
            if ($modeKey !== '*' && $modeKey !== $want) {
                continue;
            }
            if (!str_contains($method, mb_strtolower($frag))) {
                continue;
            }
            $value = (float) str_replace([',', ' zł', 'zł'], ['.', '', ''], $price);
            $score = mb_strlen($frag) + ($modeKey === '*' ? 0 : 1000);
            if ($value > 0 && $score > $bestLen) {
                $best = $value;
                $bestLen = $score;
            }
        }
        return $best;
    }

    private static function withoutNulls(array $a): array
    {
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $a[$k] = self::withoutNulls($v);
            } elseif ($v === null) {
                unset($a[$k]);
            }
        }
        return $a;
    }
}
