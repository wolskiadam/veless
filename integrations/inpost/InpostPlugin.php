<?php
declare(strict_types=1);

namespace PasePlugin\Inpost;

use Pase\Plugin\AbstractPlugin;
use Pase\Plugin\Capability;
use Pase\Plugin\Contract\CourierAsync;
use Pase\Plugin\PluginManifest;
use Pase\Support\Logger;
use Pase\Support\PickupPoint;

/**
 * Wtyczka InPost (kurier) - nadawanie Paczkomatów i przesyłek kurierskich InPost na WŁASNEJ UMOWIE z InPost,
 * bezpośrednio przez API ShipX (bez pośrednika typu BLPaczka).
 *
 *  - nadanie: POST /v1/organizations/{id}/shipments w trybie uproszczonym (usługa w treści). ShipX kupuje ofertę
 *    asynchronicznie, więc po nadaniu chwilę dopytujemy o status; gdy numer nie przyjdzie od razu, przesyłka
 *    zostaje „pending" i rdzeń dokończy ją przez resolveShipment() (CourierAsync),
 *  - etykieta: GET /v1/shipments/{id}/label (PDF A6 / A4, ZPL, EPL), anulowanie: DELETE /v1/shipments/{id}
 *    (tylko przed opłaceniem - potem przesyłkę anuluje się w Managerze Paczek),
 *  - Paczkomat: z zamówienia (Allegro / WooCommerce) albo z wyszukiwarki punktów (GET /v1/points),
 *  - zlecenie odbioru przez kuriera (opcjonalnie): POST /v1/organizations/{id}/dispatch_orders,
 *  - pobranie: przy zamówieniach za pobraniem (Woo „cod", Allegro CASH_ON_DELIVERY) - kwota zamówienia + ubezpieczenie,
 *  - śledzenie: robi rdzeń (Services\ShipmentTracking, publiczne API śledzenia InPost) - w odpowiedzi zapisujemy
 *    carrierId INPOST.
 *
 * Wejście createShipment() (tablica od rdzenia, patrz public/admin/courier_shipment.php):
 *   order        => ['row' => wiersz woo_orders, 'payload' => zamówienie]
 *   parcel       => ['weight' => kg, 'x' => cm, 'y' => cm, 'z' => cm]
 *   service      => klucz z SERVICES (np. 'locker_auto', 'locker_small', 'courier_standard')
 *   point        => Paczkomat docelowy (np. „KRA010", „WAW123M")
 *   label_format => 'PDF' | 'ZPL', text_on_label, handover (sposób nadania z HANDOVER)
 */
final class InpostPlugin extends AbstractPlugin implements CourierAsync
{
    /** Usługi do wyboru: klucz => [usługa ShipX, gabaryt (null = wymiary paczki), etykieta, grupa]. */
    public const SERVICES = [
        'locker_auto'          => ['inpost_locker_standard', 'auto', 'Paczkomat — gabaryt dobrany z wymiarów', 'locker'],
        'locker_small'         => ['inpost_locker_standard', 'small', 'Paczkomat — gabaryt A (8 × 38 × 64 cm)', 'locker'],
        'locker_medium'        => ['inpost_locker_standard', 'medium', 'Paczkomat — gabaryt B (19 × 38 × 64 cm)', 'locker'],
        'locker_large'         => ['inpost_locker_standard', 'large', 'Paczkomat — gabaryt C (41 × 38 × 64 cm)', 'locker'],
        'courier_standard'     => ['inpost_courier_standard', null, 'Kurier InPost — standard', 'courier'],
        'courier_express_1000' => ['inpost_courier_express_1000', null, 'Kurier InPost — ekspres do 10:00', 'courier'],
        'courier_express_1200' => ['inpost_courier_express_1200', null, 'Kurier InPost — ekspres do 12:00', 'courier'],
        'courier_express_1700' => ['inpost_courier_express_1700', null, 'Kurier InPost — ekspres do 17:00', 'courier'],
    ];

    /** Gabaryty Paczkomatu: wymiary w cm (malejąco), maks. 25 kg (dokumentacja „Rozmiary i usługi"). */
    public const SIZES = [
        'small'  => ['dims' => [64, 38, 8],  'name' => 'A'],
        'medium' => ['dims' => [64, 38, 19], 'name' => 'B'],
        'large'  => ['dims' => [64, 41, 38], 'name' => 'C'],
    ];
    public const LOCKER_MAX_WEIGHT = 25.0;
    public const COURIER_MAX_WEIGHT = 50.0;

    /** Sposób nadania (custom_attributes.sending_method). */
    public const HANDOVER = [
        'parcel_locker'  => 'Nadam w Paczkomacie',
        'pop'            => 'Nadam w PaczkoPunkcie',
        'dispatch_order' => 'Odbierze kurier InPost (zlecenie odbioru)',
    ];

    /** Przewoźnik w śledzeniu (jak id przewoźnika w Allegro) - patrz Services\ShipmentTracking. */
    public const CARRIER = 'INPOST';

    /** Statusy ShipX przed opłaceniem przesyłki (bez numeru, można anulować). */
    private const UNPAID = ['created', 'offers_prepared', 'offer_selected'];

    /** Ile razy po nadaniu dopytać o numer przesyłki, zanim zostawimy ją jako „pending". */
    public const POLL_TRIES = 6;

    /** Pauza między pytaniami (testy podmieniają na pustą funkcję). @var (callable(int):void)|null */
    public static $sleep = null;

    public const LOGO = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect width="24" height="24" rx="5" fill="#ffcd00"/>'
        . '<text x="12" y="15.2" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="7.2" font-weight="700" fill="#2b2b2b">InPost</text></svg>';

    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            type: 'inpost',
            name: 'InPost',
            version: '1.0.0',
            author: 'Veless',
            capabilities: [Capability::COURIER],
            fields: [
                ['key' => 'api_token', 'label' => 'Token API ShipX', 'type' => 'password', 'required' => true, 'secret' => true,
                 'help' => 'Manager Paczek → Moje konto → API → API ShipX → „Pokaż token”. Zapisywany zaszyfrowany.'],
                ['key' => 'organization_id', 'label' => 'ID organizacji', 'type' => 'text', 'required' => true,
                 'help' => 'Manager Paczek → Moje konto → API (albo „Ustawienia organizacji”).'],
                ['key' => 'env', 'label' => 'Środowisko', 'type' => 'select', 'default' => 'production',
                 'options' => ['production' => 'Produkcyjne', 'sandbox' => 'Testowe (sandbox-manager.paczkomaty.pl)'],
                 'help' => 'Sandbox ma osobne konto, token i ID organizacji.'],
                ['key' => 'default_service', 'label' => 'Domyślna usługa', 'type' => 'select', 'default' => 'locker_auto',
                 'options' => array_map(static fn(array $s): string => $s[2], self::SERVICES),
                 'help' => 'Wstępnie zaznaczona przy nadawaniu. Zamówienie z paczkomatem dostaje Paczkomat, bez punktu — kuriera standard.'],
                ['key' => 'handover', 'label' => 'Domyślny sposób nadania', 'type' => 'select', 'default' => 'parcel_locker', 'options' => self::HANDOVER],
                ['key' => 'dispatch_auto', 'label' => 'Zlecenie odbioru przy „Odbierze kurier InPost”', 'type' => 'select', 'default' => '0',
                 'options' => ['0' => 'Tworzę sam w Managerze Paczek (np. jedno na paczki z całego dnia)', '1' => 'Zamów kuriera od razu po nadaniu paczki']],
                ['key' => 'sender_company', 'label' => 'Nadawca: nazwa firmy', 'type' => 'text',
                 'help' => 'Dane nadawcy są opcjonalne — puste = dane organizacji z Managera Paczek. Adres jest potrzebny do zlecenia odbioru.'],
                ['key' => 'sender_first_name', 'label' => 'Nadawca: imię', 'type' => 'text'],
                ['key' => 'sender_last_name', 'label' => 'Nadawca: nazwisko', 'type' => 'text'],
                ['key' => 'sender_street', 'label' => 'Nadawca: ulica', 'type' => 'text'],
                ['key' => 'sender_building', 'label' => 'Nadawca: numer budynku (i lokalu)', 'type' => 'text'],
                ['key' => 'sender_postcode', 'label' => 'Nadawca: kod pocztowy', 'type' => 'text', 'help' => 'Format 00-000.'],
                ['key' => 'sender_city', 'label' => 'Nadawca: miejscowość', 'type' => 'text'],
                ['key' => 'sender_phone', 'label' => 'Nadawca: telefon', 'type' => 'text'],
                ['key' => 'sender_email', 'label' => 'Nadawca: e-mail', 'type' => 'text'],
                ['key' => 'cod', 'label' => 'Pobranie przy zamówieniach za pobraniem', 'type' => 'select', 'default' => '1',
                 'options' => ['1' => 'Tak — pobranie na kwotę zamówienia', '0' => 'Nie'],
                 'help' => 'Kwota pobrania = wartość zamówienia; przesyłka dostaje ubezpieczenie na tę samą kwotę (wymóg InPost).'],
                ['key' => 'label_format', 'label' => 'Domyślny format etykiety', 'type' => 'select', 'default' => 'PDF',
                 'options' => ['PDF' => 'PDF', 'ZPL' => 'ZPL (Zebra)']],
                ['key' => 'pdf_type', 'label' => 'Etykieta PDF', 'type' => 'select', 'default' => 'A6',
                 'options' => ['A6' => 'Etykieta 10 × 15 cm (A6)', 'normal' => 'Strona A4']],
                ['key' => 'text_on_label', 'label' => 'Tekst na etykiecie (numer referencyjny)', 'type' => 'text',
                 'help' => 'Pole „reference”, 3–100 znaków. {nr} = numer zamówienia w CRM. Puste = „Zamówienie {nr}”.'],
                ['key' => 'price_small', 'label' => 'Cena Paczkomatu A (zł brutto)', 'type' => 'text',
                 'help' => 'Cena z Twojej umowy — do wyceny przed nadaniem i kosztu przesyłki, gdy InPost nie poda ceny (klienci z fakturą zbiorczą).'],
                ['key' => 'price_medium', 'label' => 'Cena Paczkomatu B (zł brutto)', 'type' => 'text'],
                ['key' => 'price_large', 'label' => 'Cena Paczkomatu C (zł brutto)', 'type' => 'text'],
                ['key' => 'price_courier', 'label' => 'Cena kuriera standard (zł brutto)', 'type' => 'text'],
            ],
            color: '#ffcd00',
            icon: '📦',
            logo: self::LOGO,
            multiple: true,
            description: 'Paczkomaty i kurier InPost na własnej umowie, bezpośrednio przez API ShipX: etykiety PDF/ZPL, wybór Paczkomatu, pobranie i zlecenie odbioru.',
            category: Capability::CAT_COURIER
        );
    }

    public function client(): InpostClient
    {
        return new InpostClient(
            trim((string) $this->cfg('api_token', '')),
            trim((string) $this->cfg('organization_id', '')),
            $this->cfg('env', 'production') === 'sandbox'
        );
    }

    private function configured(): bool
    {
        return trim((string) $this->cfg('api_token', '')) !== '' && trim((string) $this->cfg('organization_id', '')) !== '';
    }

    public function testConnection(): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'message' => 'Uzupełnij token API ShipX i ID organizacji.'];
        }
        $r = $this->client()->organization();
        if (!$r['ok']) {
            return ['ok' => false, 'message' => $r['message']];
        }
        $org = $r['organization'];
        $name = trim((string) ($org['name'] ?? ''));
        $services = array_map('strval', is_array($org['services'] ?? null) ? $org['services'] : []);
        $msg = 'Połączono z InPost ShipX' . ($this->cfg('env') === 'sandbox' ? ' (sandbox)' : '') . '.'
            . ($name !== '' ? " Organizacja: {$name}." : '');
        if ($services !== []) {
            $ours = array_values(array_intersect(array_unique(array_column(self::SERVICES, 0)), $services));
            $msg .= $ours !== [] ? ' Dostępne usługi: ' . implode(', ', $ours) . '.' : ' Organizacja nie ma usług Paczkomat ani Kurier InPost.';
        }
        return ['ok' => true, 'message' => $msg];
    }

    // ================= Courier =================

    /** @param array<string,mixed> $parcel zawiera 'order' => ['row', 'payload'] */
    public function listServices(array $parcel): array
    {
        $services = [];
        foreach (self::SERVICES as $id => [, , $label, $group]) {
            $services[] = ['id' => $id, 'label' => $label, 'group' => $group];
        }
        $point = $this->orderPoint($parcel['order'] ?? []);
        $default = (string) $this->cfg('default_service', 'locker_auto');
        if (!isset(self::SERVICES[$default])) {
            $default = 'locker_auto';
        }
        // Zamówienie bez paczkomatu, a domyślnie Paczkomat -> kurier; z paczkomatem, a domyślnie kurier -> Paczkomat.
        if ($point['code'] === '' && self::SERVICES[$default][3] === 'locker') {
            $default = 'courier_standard';
        } elseif ($point['code'] !== '' && self::SERVICES[$default][3] === 'courier') {
            $default = 'locker_auto';
        }
        $handover = (string) $this->cfg('handover', 'parcel_locker');
        return [
            'ok'       => true,
            'message'  => $this->configured() ? '' : 'Uzupełnij w ustawieniach integracji token API ShipX i ID organizacji.',
            'default'  => $default,
            'services' => $services,
            'groups'   => ['locker' => 'Paczkomat InPost', 'courier' => 'Kurier InPost'],
            'handover' => ['default' => isset(self::HANDOVER[$handover]) ? $handover : 'parcel_locker', 'options' => self::HANDOVER],
            'point'    => [
                'label'       => 'Paczkomat odbiorcy (tylko usługa Paczkomat)',
                'value'       => $point['code'],
                'note'        => $point['note'],
                'placeholder' => 'np. KRA010, WAW123M albo miasto / kod pocztowy',
                'required'    => false,
                'search'      => true,
            ],
        ];
    }

    public function listPaymentOptions(): array
    {
        return []; // rozliczenie wg umowy z InPost
    }

    /** Wycena: cena z ustawień (InPost nie ma osobnego cennika w trybie uproszczonym). */
    public function quote(array $params): array
    {
        $svc = $this->resolveService((string) ($params['service'] ?? ''), $params['parcel'] ?? []);
        if (!$svc['ok']) {
            return ['ok' => false, 'price' => null, 'message' => $svc['message']];
        }
        $price = $this->ownPrice($svc);
        return $price === null
            ? ['ok' => false, 'price' => null, 'message' => 'Brak ceny w ustawieniach integracji InPost.']
            : ['ok' => true, 'price' => $price, 'message' => 'cena z ustawień'];
    }

    /** Podpowiedź do formularza: nadawca z ustawień. */
    public function proposal(array $order): array
    {
        $name = trim((string) $this->cfg('sender_company', '')) ?: trim($this->cfg('sender_first_name', '') . ' ' . $this->cfg('sender_last_name', ''));
        $sender = trim($name . ', ' . (string) $this->cfg('sender_city', ''), ', ');
        return ['ok' => true, 'message' => '', 'package' => [], 'sender' => $sender !== '' ? $sender : 'dane organizacji z Managera Paczek'];
    }

    public function createShipment(array $shipment): array
    {
        $fail = static fn(string $m, $raw = null) => ['ok' => false, 'message' => $m, 'waybill_no' => null,
            'external_id' => null, 'price' => null, 'label_link' => null, 'raw' => $raw];

        if (!$this->configured()) {
            return $fail('Uzupełnij w ustawieniach integracji InPost token API ShipX i ID organizacji.');
        }
        $order = $shipment['order'] ?? [];
        $row = $order['row'] ?? [];
        $nr = (string) ($row['pase_number'] ?? $row['woo_order_id'] ?? '');

        $svc = $this->resolveService((string) ($shipment['service'] ?? ''), $shipment['parcel'] ?? []);
        if (!$svc['ok']) {
            return $fail($svc['message']);
        }
        $locker = $svc['group'] === 'locker';

        $receiver = self::receiver($order);
        if (($receiver['phone'] ?? '') === '') {
            return $fail('Zamówienie nie ma telefonu odbiorcy — InPost go wymaga. Uzupełnij telefon w zamówieniu.');
        }
        $handover = (string) ($shipment['handover'] ?? '');
        if (!isset(self::HANDOVER[$handover])) {
            $handover = (string) $this->cfg('handover', 'parcel_locker');
        }
        $attrs = ['sending_method' => isset(self::HANDOVER[$handover]) ? $handover : 'parcel_locker'];

        if ($locker) {
            $raw = trim((string) ($shipment['point'] ?? ''));
            $point = $raw !== '' ? self::pointCode($raw) : $this->orderPoint($order)['code'];
            if ($point === '') {
                return $fail('Podaj Paczkomat odbiorcy (np. KRA010) albo wybierz usługę kurierską.');
            }
            if (($receiver['email'] ?? '') === '') {
                return $fail('Zamówienie nie ma e-maila odbiorcy — InPost wymaga go przy Paczkomacie.');
            }
            $attrs['target_point'] = $point;
            $parcels = [['template' => $svc['size']]];
            $receiver = array_intersect_key($receiver, array_flip(['first_name', 'last_name', 'company_name', 'email', 'phone']));
        } else {
            if (empty($receiver['address']['street']) || empty($receiver['address']['city']) || empty($receiver['address']['post_code'])) {
                return $fail('Zamówienie nie ma pełnego adresu dostawy (ulica, kod, miasto) — kurier InPost go wymaga.');
            }
            if (($receiver['first_name'] ?? '') === '' && ($receiver['company_name'] ?? '') === '') {
                return $fail('Zamówienie nie ma imienia i nazwiska ani firmy odbiorcy.');
            }
            $p = $shipment['parcel'] ?? [];
            $parcels = [[
                'dimensions' => ['length' => self::mm($p['x'] ?? 0), 'width' => self::mm($p['y'] ?? 0), 'height' => self::mm($p['z'] ?? 0), 'unit' => 'mm'],
                'weight'     => ['amount' => round((float) ($p['weight'] ?? 0), 2), 'unit' => 'kg'],
                'is_non_standard' => self::nonStandard($p),
            ]];
        }

        $text = trim((string) ($shipment['text_on_label'] ?? ''));
        if ($text === '') {
            $tpl = trim((string) $this->cfg('text_on_label', '')) ?: 'Zamówienie {nr}';
            $text = str_replace('{nr}', $nr, $tpl);
        }
        $body = [
            'receiver'          => self::clean($receiver),
            'parcels'           => $parcels,
            'service'           => $svc['service'],
            'custom_attributes' => $attrs,
        ];
        if (mb_strlen($text) >= 3) {
            $body['reference'] = mb_substr($text, 0, 100);
        }
        if (($sender = $this->sender()) !== null) {
            $body['sender'] = $sender;
        }
        $cod = (string) $this->cfg('cod', '1') === '1' ? self::codAmount($order) : null;
        if ($cod !== null) {
            $body['cod'] = ['amount' => $cod, 'currency' => 'PLN'];
            $body['insurance'] = ['amount' => $cod, 'currency' => 'PLN'];
        }

        $c = $this->client();
        $r = $c->createShipment($body);
        if (!$r['ok']) {
            Logger::warn('InPost: nadanie odrzucone', ['order' => $nr, 'message' => $r['message']]);
            $msg = $r['message'] . (!empty($r['transport'])
                ? ' Wynik nadania jest nieznany — zanim nadasz ponownie, sprawdź w Managerze Paczek, czy przesyłka nie powstała.' : '');
            return $fail('InPost odrzucił przesyłkę: ' . $msg, ['request' => $body, 'response' => $r['json'] ?? null]);
        }
        $sh = $r['shipment'];
        $id = (string) ($sh['id'] ?? '');
        if ($id === '') {
            return $fail('InPost nie zwrócił numeru przesyłki.', ['request' => $body, 'response' => $sh]);
        }

        // ShipX kupuje ofertę asynchronicznie - chwilę czekamy na numer przesyłki.
        $state = self::state($sh);
        for ($i = 0; $state['pending'] && $i < self::POLL_TRIES; $i++) {
            (self::$sleep ?? static fn(int $ms) => usleep($ms * 1000))($i < 2 ? 700 : 1500);
            $g = $c->shipment($id);
            if ($g['ok']) {
                $sh = $g['shipment'];
                $state = self::state($sh);
            }
        }
        if (!$state['pending'] && !$state['ok']) {
            return $fail('InPost nie przyjął przesyłki: ' . $state['message'], ['request' => $body, 'response' => $sh]);
        }

        $price = self::rate($sh) ?? $this->ownPrice($svc);
        $what = $locker ? 'Paczkomat ' . $attrs['target_point'] . ', gabaryt ' . self::SIZES[$svc['size']]['name'] : self::SERVICES[$svc['key']][2];
        $raw = ['carrierId' => self::CARRIER, 'shipment_id' => $id, 'service' => $svc['service'], 'sending_method' => $attrs['sending_method'],
                'target_point' => $attrs['target_point'] ?? null, 'template' => $locker ? $svc['size'] : null, 'cod' => $cod,
                'status' => $sh['status'] ?? null];
        $base = ['ok' => true, 'external_id' => $id, 'courier_label' => $locker ? 'InPost Paczkomat' : 'Kurier InPost',
                 'price' => $price, 'label_link' => null];

        if ($state['pending']) {
            return $base + ['pending' => true, 'waybill_no' => null, 'raw' => $raw,
                'message' => "Przesyłka InPost utworzona ({$what}), czekamy na numer od InPost — pojawi się po odświeżeniu zamówienia."];
        }
        $msg = "Nadano przez InPost, nr {$state['tracking']} ({$what})" . ($cod !== null ? ', pobranie ' . number_format($cod, 2, ',', ' ') . ' zł' : '') . '.';
        if ($attrs['sending_method'] === 'dispatch_order') {
            $d = $this->autoDispatch($id, $nr);
            if ($d !== null) {
                $msg .= ' ' . $d['message'];
                $raw['dispatch_order'] = $d;
            }
        }
        return $base + ['waybill_no' => $state['tracking'], 'message' => $msg, 'raw' => $raw];
    }

    /** Dokończenie przesyłki „pending": numer przesyłki po zakupie oferty. */
    public function resolveShipment(string $pendingExternalId): array
    {
        $id = trim($pendingExternalId);
        $r = $this->client()->shipment($id);
        if (!$r['ok']) {
            // Błąd sieci / chwilowy - spróbujemy przy następnym otwarciu zamówienia.
            return ['ok' => false, 'pending' => true, 'message' => $r['message'], 'external_id' => $id, 'waybill_no' => null];
        }
        $sh = $r['shipment'];
        $state = self::state($sh);
        if ($state['pending']) {
            return ['ok' => false, 'pending' => true, 'message' => 'InPost jeszcze przygotowuje przesyłkę.', 'external_id' => $id, 'waybill_no' => null];
        }
        if (!$state['ok']) {
            return ['ok' => false, 'pending' => false, 'message' => 'InPost nie przyjął przesyłki: ' . $state['message'], 'external_id' => $id,
                    'waybill_no' => null, 'raw' => $sh];
        }
        $locker = str_starts_with((string) ($sh['service'] ?? ''), 'inpost_locker');
        $raw = ['carrierId' => self::CARRIER, 'shipment_id' => $id, 'service' => $sh['service'] ?? null, 'status' => $sh['status'] ?? null,
                'target_point' => $sh['custom_attributes']['target_point'] ?? null, 'sending_method' => $sh['custom_attributes']['sending_method'] ?? null];
        if (($sh['custom_attributes']['sending_method'] ?? '') === 'dispatch_order') {
            $d = $this->autoDispatch($id, (string) ($sh['reference'] ?? ''));
            if ($d !== null) {
                $raw['dispatch_order'] = $d;
            }
        }
        return ['ok' => true, 'pending' => false, 'message' => 'Nadano przez InPost, nr ' . $state['tracking'] . '.', 'external_id' => $id,
                'waybill_no' => $state['tracking'], 'courier_label' => $locker ? 'InPost Paczkomat' : 'Kurier InPost', 'raw' => $raw];
    }

    /**
     * Faktyczny koszt (rdzeń: CourierShipping::fillActualCosts) - cena wybranej oferty. Klienci z fakturą zbiorczą
     * dostają tu null (InPost nie podaje ceny), wtedy zostaje cena z ustawień.
     * @return array{amount:float}|null
     */
    public function shipmentCost(string $externalId, array $order = []): ?array
    {
        $r = $this->client()->shipment(trim($externalId));
        $rate = $r['ok'] ? self::rate($r['shipment']) : null;
        return $rate !== null ? ['amount' => $rate] : null;
    }

    /**
     * @param string $format A4 (PDF wg ustawień) | LBL (PDF 10×15) | ZPL | EPL
     */
    public function getLabel(string $externalId, string $format = 'A4'): array
    {
        $id = trim($externalId);
        if ($id === '') {
            return ['ok' => false, 'content' => null, 'filename' => null, 'mime' => null, 'message' => 'Brak numeru przesyłki InPost.'];
        }
        [$api, $type] = match ($format) {
            'ZPL'   => ['Zpl', 'normal'],
            'EPL'   => ['Epl', 'normal'],
            'LBL'   => ['Pdf', 'A6'],
            default => ['Pdf', $this->cfg('pdf_type', 'A6') === 'normal' ? 'normal' : 'A6'],
        };
        $r = $this->client()->label($id, $api, $type);
        if (!$r['ok']) {
            return ['ok' => false, 'content' => null, 'filename' => null, 'mime' => null, 'message' => 'InPost nie wydał etykiety: ' . $r['message']];
        }
        $content = (string) $r['content'];
        $name = 'etykieta-inpost-' . preg_replace('/[^A-Za-z0-9_-]/', '', $id);
        $isPdf = str_starts_with($content, '%PDF');
        $isZip = str_starts_with($content, "PK\x03\x04");
        return [
            'ok'       => true,
            'content'  => $content,
            'filename' => $name . ($isPdf ? '.pdf' : ($isZip ? '.zip' : ($api === 'Epl' ? '.epl' : ($api === 'Zpl' ? '.zpl' : '.pdf')))),
            'mime'     => $isPdf || ($api === 'Pdf' && !$isZip) ? 'application/pdf' : ($isZip ? 'application/zip' : 'application/octet-stream'),
            'message'  => '',
        ];
    }

    public function cancelShipment(string $externalId): array
    {
        $id = trim($externalId);
        if ($id === '') {
            return ['ok' => false, 'message' => 'Brak numeru przesyłki InPost.'];
        }
        $r = $this->client()->cancel($id);
        if ($r['ok']) {
            return ['ok' => true, 'message' => 'Przesyłka anulowana w InPost.'];
        }
        if (($r['json']['error'] ?? '') === 'invalid_action') {
            return ['ok' => false, 'message' => 'InPost nie pozwala anulować przez API opłaconej przesyłki — anuluj ją w Managerze Paczek (nienadana przesyłka nie zostanie rozliczona).'];
        }
        return ['ok' => false, 'message' => 'InPost nie anulował przesyłki: ' . $r['message']];
    }

    // ================= Zlecenie odbioru =================

    /** Zlecenie odbioru dla jednej przesyłki, gdy włączone w ustawieniach. Null = nie zamawiamy. */
    private function autoDispatch(string $shipmentId, string $nr): ?array
    {
        if ((string) $this->cfg('dispatch_auto', '') !== '1') {
            return null;
        }
        $s = $this->sender();
        if ($s === null || empty($s['address']['street']) || empty($s['address']['city']) || empty($s['address']['post_code']) || ($s['phone'] ?? '') === '') {
            return ['ok' => false, 'message' => 'Nie zamówiono kuriera: uzupełnij w ustawieniach integracji adres i telefon nadawcy.'];
        }
        $name = trim(($s['company_name'] ?? '') !== '' ? $s['company_name'] : ($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
        $body = ['shipments' => [$shipmentId], 'name' => $name !== '' ? $name : 'Nadawca', 'phone' => $s['phone'],
                 'address' => $s['address'], 'comment' => mb_substr($nr !== '' ? 'Zamówienie ' . $nr : '', 0, 100)];
        if (($s['email'] ?? '') !== '') {
            $body['email'] = $s['email'];
        }
        $r = $this->client()->dispatchOrder(self::clean($body));
        if (!$r['ok']) {
            return ['ok' => false, 'message' => 'Nie zamówiono kuriera: ' . $r['message'] . ' Zamów odbiór w Managerze Paczek.'];
        }
        $do = $r['dispatch_order'];
        return ['ok' => true, 'id' => (string) ($do['id'] ?? ''), 'message' => 'Zamówiono odbiór przez kuriera InPost'
            . (!empty($do['id']) ? ' (zlecenie ' . $do['id'] . ')' : '') . '.'];
    }

    // ================= Punkty =================

    /**
     * Wyszukiwarka Paczkomatów: po nazwie punktu (KRA010), kodzie pocztowym albo mieście.
     * @return array{ok:bool,message:string,points:array<int,array{code:string,label:string}>}
     */
    public function searchPoints(string $query, int $limit = 15): array
    {
        $q = trim($query);
        if (mb_strlen($q) < 2) {
            return ['ok' => true, 'message' => '', 'points' => []];
        }
        $base = ['per_page' => $limit, 'type' => 'parcel_locker'];
        if (preg_match('/^\d{2}-?\d{3}$/', $q)) {
            $query = $base + ['post_code' => substr(preg_replace('/\D/', '', $q), 0, 2) . '-' . substr(preg_replace('/\D/', '', $q), 2)];
        } elseif (self::pointCode($q) !== '' && preg_match('/\d/', $q)) {
            $query = ['name' => self::pointCode($q), 'per_page' => $limit];
        } else {
            $query = $base + ['city' => $q];
        }
        $r = $this->client()->points($query);
        if (!$r['ok']) {
            return ['ok' => false, 'message' => 'Nie udało się pobrać Paczkomatów: ' . $r['message'], 'points' => []];
        }
        $out = [];
        foreach ($r['points'] as $p) {
            if (!is_array($p) || empty($p['name'])) {
                continue;
            }
            $out[] = ['code' => (string) $p['name'], 'label' => self::pointLabel($p)];
            if (count($out) >= $limit) {
                break;
            }
        }
        return ['ok' => true, 'message' => $out === [] ? 'Nie znaleziono Paczkomatu.' : '', 'points' => $out];
    }

    /** Opis punktu do listy wyboru. */
    public static function pointLabel(array $p): string
    {
        $a = is_array($p['address_details'] ?? null) ? $p['address_details'] : [];
        $addr = trim(trim(($a['street'] ?? '') . ' ' . ($a['building_number'] ?? '')) . ', ' . trim(($a['post_code'] ?? '') . ' ' . ($a['city'] ?? '')), ' ,');
        if ($addr === '' && is_array($p['address'] ?? null)) {
            $addr = trim(($p['address']['line1'] ?? '') . ', ' . ($p['address']['line2'] ?? ''), ' ,');
        }
        $desc = trim((string) ($p['location_description'] ?? ''));
        return $addr . ($desc !== '' ? ' — ' . mb_substr($desc, 0, 60) : '');
    }

    /** Nazwa Paczkomatu / PaczkoPunktu („KRA010", „WAW123M", „POP-KRA12") albo pusty tekst. */
    public static function pointCode(string $raw): string
    {
        $s = strtoupper(preg_replace('/\s+/', '', $raw) ?? '');
        return preg_match('/^(?:POP-)?[A-Z]{3}\d{1,4}[A-Z]{0,4}$/', $s) ? $s : '';
    }

    /**
     * Paczkomat z zamówienia (Allegro: delivery.pickupPoint, WooCommerce: meta wtyczki wysyłkowej,
     * albo punkt poprawiony ręcznie w CRM).
     * @return array{code:string,note:string}
     */
    private function orderPoint(array $order): array
    {
        $row = $order['row'] ?? [];
        $pp = PickupPoint::fromOrder($order['payload'] ?? [], json_decode((string) ($row['local_pickup_point'] ?? ''), true) ?: null);
        if ($pp === null) {
            return ['code' => '', 'note' => 'Zamówienie nie ma paczkomatu — przy usłudze Paczkomat wpisz go albo wyszukaj po mieście.'];
        }
        $code = self::pointCode($pp['id']);
        if ($code === '') {
            return ['code' => '', 'note' => 'Punkt z zamówienia (' . $pp['id'] . ') to nie Paczkomat InPost — wybierz Paczkomat albo kuriera.'];
        }
        $label = trim($pp['name'] . ' ' . $pp['line']);
        return ['code' => $code, 'note' => 'Paczkomat z zamówienia' . ($label !== '' ? ': ' . $label : '') . '.'];
    }

    // ================= Dane przesyłki =================

    /**
     * Usługa i gabaryt: wybrane albo dobrane z wymiarów.
     * @return array{ok:bool,message:string,key?:string,service?:string,size?:?string,group?:string}
     */
    private function resolveService(string $key, array $parcel): array
    {
        if (!isset(self::SERVICES[$key])) {
            $key = (string) $this->cfg('default_service', 'locker_auto');
            $key = isset(self::SERVICES[$key]) ? $key : 'locker_auto';
        }
        [$service, $size, , $group] = self::SERVICES[$key];
        $weight = (float) ($parcel['weight'] ?? 0);
        if ($group === 'courier') {
            if ($weight > self::COURIER_MAX_WEIGHT) {
                return ['ok' => false, 'message' => 'Paczka waży ponad 50 kg — kurier InPost przyjmuje do 50 kg.'];
            }
            return ['ok' => true, 'message' => '', 'key' => $key, 'service' => $service, 'size' => null, 'group' => $group];
        }
        if ($weight > self::LOCKER_MAX_WEIGHT) {
            return ['ok' => false, 'message' => 'Paczka waży ponad 25 kg — Paczkomat przyjmuje do 25 kg. Wybierz kuriera.'];
        }
        if ($size === 'auto') {
            $size = self::sizeFor($parcel);
            if ($size === null) {
                return ['ok' => false, 'message' => 'Paczka nie mieści się w gabarycie C Paczkomatu (maks. 41 × 38 × 64 cm). Wybierz kuriera.'];
            }
        }
        return ['ok' => true, 'message' => '', 'key' => $key, 'service' => $service, 'size' => $size, 'group' => $group];
    }

    /** Najmniejszy gabaryt Paczkomatu dla wymiarów (cm). Bez wymiarów - B (najczęstszy). */
    public static function sizeFor(array $parcel): ?string
    {
        $dims = [(float) ($parcel['x'] ?? 0), (float) ($parcel['y'] ?? 0), (float) ($parcel['z'] ?? 0)];
        if (min($dims) <= 0) {
            return 'medium';
        }
        rsort($dims);
        foreach (self::SIZES as $k => $s) {
            if ($dims[0] <= $s['dims'][0] && $dims[1] <= $s['dims'][1] && $dims[2] <= $s['dims'][2]) {
                return $k;
            }
        }
        return null;
    }

    /** Paczka niestandardowa u kuriera: bok ponad 120 cm albo suma wymiarów ponad 220 cm. */
    public static function nonStandard(array $p): bool
    {
        $d = [(float) ($p['x'] ?? 0), (float) ($p['y'] ?? 0), (float) ($p['z'] ?? 0)];
        return max($d) > 120 || array_sum($d) > 220;
    }

    private static function mm(mixed $cm): int
    {
        return (int) round((float) $cm * 10);
    }

    /** Cena z ustawień dla usługi. */
    private function ownPrice(array $svc): ?float
    {
        $key = ($svc['group'] ?? '') === 'locker' ? 'price_' . $svc['size'] : 'price_courier';
        $v = trim(str_replace([',', 'zł', ' '], ['.', '', ''], (string) $this->cfg($key, '')));
        return $v !== '' && is_numeric($v) && (float) $v > 0 ? round((float) $v, 2) : null;
    }

    /** Cena wybranej oferty (brutto) albo null (klient z fakturą zbiorczą / brak oferty). */
    public static function rate(array $sh): ?float
    {
        $rate = $sh['selected_offer']['rate'] ?? null;
        return is_numeric($rate) && (float) $rate > 0 ? round((float) $rate, 2) : null;
    }

    /**
     * Stan przesyłki ShipX: pending (oferta jeszcze nie kupiona / brak numeru), ok (jest numer) albo błąd
     * (anulowana albo wszystkie oferty niedostępne).
     * @return array{pending:bool,ok:bool,tracking:string,message:string}
     */
    public static function state(array $sh): array
    {
        $status = (string) ($sh['status'] ?? '');
        $tracking = trim((string) ($sh['tracking_number'] ?? ''));
        if ($status === 'canceled' || $status === 'cancelled') {
            return ['pending' => false, 'ok' => false, 'tracking' => '', 'message' => 'przesyłka anulowana.'];
        }
        if (in_array($status, self::UNPAID, true)) {
            $offers = is_array($sh['offers'] ?? null) ? $sh['offers'] : [];
            $reasons = [];
            $live = false;
            foreach ($offers as $o) {
                $st = (string) ($o['status'] ?? '');
                if (in_array($st, ['unavailable', 'rejected', 'expired'], true)) {
                    foreach ((array) ($o['unavailability_reasons'] ?? []) as $why) {
                        $reasons[] = is_array($why) ? (string) ($why['message'] ?? $why['description'] ?? $why['key'] ?? json_encode($why)) : (string) $why;
                    }
                } else {
                    $live = true;
                }
            }
            if ($offers !== [] && !$live) {
                return ['pending' => false, 'ok' => false, 'tracking' => '',
                        'message' => $reasons !== [] ? implode('; ', array_unique($reasons)) . '.' : 'brak dostępnej oferty dla tej usługi.'];
            }
            return ['pending' => true, 'ok' => false, 'tracking' => '', 'message' => ''];
        }
        if ($tracking === '') {
            return ['pending' => true, 'ok' => false, 'tracking' => '', 'message' => ''];
        }
        return ['pending' => false, 'ok' => true, 'tracking' => $tracking, 'message' => ''];
    }

    /** Kwota pobrania dla zamówienia za pobraniem (Woo / ręczne „cod", Allegro CASH_ON_DELIVERY), inaczej null. */
    public static function codAmount(array $order): ?float
    {
        $payload = $order['payload'] ?? [];
        $row = $order['row'] ?? [];
        $isCod = ($payload['payment_method'] ?? '') === 'cod' || ($payload['payment']['type'] ?? '') === 'CASH_ON_DELIVERY';
        if (!$isCod) {
            return null;
        }
        $amount = $payload['summary']['totalToPay']['amount'] ?? ($payload['total'] ?? ($row['total'] ?? null));
        return is_numeric($amount) && (float) $amount > 0 ? round((float) $amount, 2) : null;
    }

    /** Nadawca z ustawień albo null (ShipX weźmie dane organizacji). */
    private function sender(): ?array
    {
        $company = trim((string) $this->cfg('sender_company', ''));
        $first = trim((string) $this->cfg('sender_first_name', ''));
        $last = trim((string) $this->cfg('sender_last_name', ''));
        $street = trim((string) $this->cfg('sender_street', ''));
        if ($company === '' && $first === '' && $street === '') {
            return null;
        }
        return self::clean([
            'company_name' => $company, 'first_name' => $first, 'last_name' => $last,
            'email' => trim((string) $this->cfg('sender_email', '')), 'phone' => self::phone((string) $this->cfg('sender_phone', '')),
            'address' => [
                'street' => $street, 'building_number' => trim((string) $this->cfg('sender_building', '')),
                'city' => trim((string) $this->cfg('sender_city', '')), 'post_code' => self::postcode((string) $this->cfg('sender_postcode', '')),
                'country_code' => 'PL',
            ],
        ]);
    }

    /**
     * Odbiorca z zamówienia: WooCommerce (shipping, a brakujące z billing) albo Allegro (delivery.address + buyer).
     * E-mail i telefon poprawione ręcznie w CRM mają pierwszeństwo.
     * @return array<string,mixed> obiekt receiver ShipX
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
                'country' => $da['countryCode'] ?? 'PL',
            ];
            $b = ['email' => $by['email'] ?? '', 'phone' => $by['phoneNumber'] ?? ''];
        }
        $pick = static fn(string $k): string => trim((string) (($s[$k] ?? '') !== '' ? $s[$k] : ($b[$k] ?? '')));
        $email = trim((string) ($row['local_billing_email'] ?? '')) ?: $pick('email');
        $phone = trim((string) ($row['local_billing_phone'] ?? '')) ?: $pick('phone');
        [$street, $building, $flat] = self::splitStreet(trim($pick('address_1') . ' ' . $pick('address_2')));
        return [
            'company_name' => mb_substr($pick('company'), 0, 100),
            'first_name'   => mb_substr($pick('first_name'), 0, 50),
            'last_name'    => mb_substr($pick('last_name'), 0, 50),
            'email'        => $email,
            'phone'        => self::phone($phone),
            'address'      => [
                'street'          => $street,
                'building_number' => $building . ($flat !== '' ? '/' . $flat : ''),
                'city'            => $pick('city'),
                'post_code'       => self::postcode($pick('postcode')),
                'country_code'    => strtoupper($pick('country') ?: 'PL'),
            ],
        ];
    }

    /** „ul. Długa 5/3" -> [„Długa", „5", „3"]; bez numeru - cała linia jako ulica. @return array{0:string,1:string,2:string} */
    public static function splitStreet(string $line): array
    {
        $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');
        $line = (string) preg_replace('/^(ul\.|ulica|al\.|aleja|os\.|pl\.)\s*/iu', '', $line);
        if (preg_match('/^(.*?\D)\s+(\d+[A-Za-z]?)\s*(?:(?:\/|m\.?|lok\.?)\s*(\w+))?$/u', $line, $m)) {
            return [trim($m[1], ' ,'), $m[2], $m[3] ?? ''];
        }
        return [$line, '', ''];
    }

    /** Telefon: 9 cyfr bez +48 (format ShipX dla Polski). */
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
        return $d;
    }

    /** Kod pocztowy w formacie 00-000. */
    public static function postcode(string $raw): string
    {
        $d = preg_replace('/\D+/', '', $raw) ?? '';
        return strlen($d) === 5 ? substr($d, 0, 2) . '-' . substr($d, 2) : trim($raw);
    }

    /** Usuwa puste pola (ShipX odrzuca np. pusty company_name jako „too_short"). */
    private static function clean(array $a): array
    {
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $a[$k] = self::clean($v);
                if ($a[$k] === [] && !array_is_list($v)) {
                    unset($a[$k]);
                }
            } elseif ($v === '' || $v === null) {
                unset($a[$k]);
            }
        }
        return $a;
    }
}
