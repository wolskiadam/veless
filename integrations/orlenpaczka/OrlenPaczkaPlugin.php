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
 *  - śledzenie: GiveMePackStatusFullHistoryList - rdzeń (Services\ShipmentTracking) woła trackWaybills(),
 *  - Uniwersalny Pusher: ORLEN wysyła zmiany statusów na public/webhook_plugin.php (handleWebhook()),
 *    włączany przyciskiem w Integracjach (adminActions() / adminAction(): WebhookRegister / Status / Unregister).
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

    /** Logo ORLEN Paczka (kwadrat 80 × 80) - ikona wtyczki w panelu i logo przewoźnika na liście zamówień. */
    public const LOGO = '<svg viewBox="0 0 80 80" aria-hidden="true"><path d="M63.56,79.8 L4.11,69.32 C1.4,68.84 -0.4,66.26 0.08,63.56 L10.56,4.11 C11.04,1.41 13.61,-0.4 16.32,0.08 L75.77,10.56 C78.47,11.04 80.28,13.62 79.8,16.32 L69.32,75.77 C68.84,78.47 66.26,80.28 63.56,79.8" fill="#db3830"/><path d="M61.8,49.73 C61.8,50.54 62.43,50.93 63.39,50.93 C64.16,50.93 64.79,50.66 65.1,50.44 L65.1,48.81 C64.5,48.63 63.99,48.53 63.37,48.53 C62.3,48.53 61.8,49.08 61.8,49.73 Z M67.41,46.62 L67.41,51.05 C67.41,51.45 67.3,51.67 66.98,51.89 C66.49,52.22 65.25,52.85 63.25,52.85 C60.68,52.85 59.52,51.45 59.52,49.79 C59.52,48.28 60.56,46.91 63.05,46.91 C63.84,46.91 64.48,47.01 65.1,47.19 L65.1,46.54 C65.1,45.66 64.67,45.17 63.45,45.17 C62.89,45.17 62.25,45.32 61.78,45.49 C61.53,45.56 61.33,45.6 61.15,45.6 C60.57,45.6 60.1,45.12 60.1,44.58 C60.1,44.22 60.24,43.77 60.89,43.5 C61.38,43.29 62.34,43.02 63.45,43.02 C66.02,43.02 67.41,44.13 67.41,46.62 Z M52.58,44.27 L52.58,46.75 L52.76,46.75 C53.14,46.75 53.29,46.69 53.64,46.34 L56.28,43.61 C56.6,43.25 56.93,43.13 57.26,43.13 C57.93,43.13 58.47,43.68 58.47,44.34 C58.47,44.65 58.38,44.94 58.04,45.27 L55.37,47.82 L58.1,50.64 C58.39,50.95 58.5,51.24 58.5,51.56 C58.5,52.22 57.96,52.76 57.3,52.76 C56.97,52.76 56.63,52.63 56.38,52.34 L53.69,49.44 C53.36,49.08 53.19,49.05 52.84,49.05 L52.58,49.05 L52.58,51.52 C52.58,52.21 52.06,52.77 51.37,52.77 C50.72,52.77 50.16,52.19 50.16,51.52 L50.16,44.27 C50.16,43.57 50.68,43.02 51.37,43.02 C52.03,43.02 52.58,43.57 52.58,44.27 Z M48.52,44.52 C48.52,44.83 48.41,45.18 48.15,45.49 L43.94,50.42 L47.29,50.42 C47.97,50.42 48.52,50.87 48.52,51.56 C48.52,52.21 47.97,52.68 47.29,52.68 L42.31,52.68 C41.43,52.68 40.87,52.12 40.87,51.36 C40.87,51.05 40.98,50.69 41.24,50.39 L45.45,45.43 L42.31,45.43 C41.64,45.43 41.08,45.01 41.08,44.33 C41.08,43.67 41.62,43.19 42.31,43.19 L47.08,43.19 C47.96,43.19 48.52,43.75 48.52,44.52 Z M39.87,45.7 C39.87,46.35 39.34,46.87 38.7,46.87 C38.32,46.87 37.93,46.64 37.74,46.33 C37.39,45.63 36.77,45.33 36,45.33 C34.91,45.33 34.24,45.97 34.13,47.02 C34.09,47.5 34.1,48.33 34.14,48.8 C34.25,49.89 34.93,50.54 36,50.54 C36.77,50.54 37.39,50.24 37.74,49.54 C37.93,49.23 38.32,49 38.7,49 C39.34,49 39.87,49.52 39.87,50.17 C39.87,51.45 38.22,52.85 35.93,52.85 C33.66,52.85 31.92,51.26 31.74,49.12 C31.68,48.48 31.68,47.43 31.74,46.66 C31.88,44.55 33.62,43.02 35.93,43.02 C38.22,43.02 39.87,44.42 39.87,45.7 Z M24.46,49.73 C24.46,50.54 25.09,50.93 26.05,50.93 C26.82,50.93 27.45,50.66 27.76,50.44 L27.76,48.81 C27.16,48.63 26.66,48.53 26.03,48.53 C24.96,48.53 24.46,49.08 24.46,49.73 Z M30.07,46.62 L30.07,51.05 C30.07,51.45 29.96,51.67 29.65,51.89 C29.15,52.22 27.91,52.85 25.91,52.85 C23.34,52.85 22.18,51.45 22.18,49.79 C22.18,48.28 23.22,46.91 25.71,46.91 C26.5,46.91 27.14,47.01 27.76,47.19 L27.76,46.54 C27.76,45.66 27.33,45.17 26.11,45.17 C25.55,45.17 24.92,45.32 24.44,45.49 C24.19,45.56 24,45.6 23.81,45.6 C23.23,45.6 22.77,45.12 22.77,44.58 C22.77,44.22 22.9,43.77 23.55,43.5 C24.04,43.29 25,43.02 26.11,43.02 C28.68,43.02 30.07,44.13 30.07,46.62 Z M14.89,45.85 L14.89,50.06 C15.18,50.25 15.9,50.5 16.64,50.5 C17.73,50.5 18.33,49.85 18.4,48.8 C18.45,48.16 18.45,47.59 18.4,47.05 C18.32,46.04 17.7,45.33 16.56,45.33 C15.97,45.33 15.22,45.6 14.89,45.85 Z M20.8,46.62 C20.87,47.35 20.86,48.44 20.8,49.17 C20.63,51.29 19.21,52.81 16.89,52.81 C16.19,52.81 15.4,52.61 14.89,52.38 L14.89,54.33 C14.89,55.02 14.34,55.57 13.68,55.57 C12.98,55.57 12.47,55.02 12.47,54.33 L12.47,44.77 C12.47,44.5 12.54,44.32 12.77,44.16 C13.35,43.75 14.76,43.02 16.58,43.02 C18.93,43.02 20.61,44.5 20.8,46.62 Z M31.31,31.3 C31.31,30.49 30.82,30.01 29.85,30.01 L29.21,30.01 L29.21,32.6 L29.85,32.6 C30.82,32.6 31.31,32.12 31.31,31.3 Z M18.04,26.78 C21.43,26.78 24.19,29.69 24.19,33.09 C24.19,36.49 21.43,39.24 18.04,39.24 C14.47,39.24 11.72,36.49 11.72,33.09 C11.72,29.69 14.47,26.78 18.04,26.78 Z M25.48,27.1 L30.34,27.1 C33.41,27.1 35.03,28.56 35.03,31.15 C35.03,32.76 34.06,33.9 32.77,34.06 L35.84,38.91 L31.15,38.91 L29.21,35.03 L29.21,38.91 L25.48,38.91 L25.48,27.1 Z M36.97,27.1 L41.19,27.1 L41.19,35.67 L44.91,35.67 L44.91,38.91 L36.97,38.91 L36.97,27.1 Z M46.21,27.1 L54.46,27.1 L54.46,30.01 L49.92,30.01 L49.92,31.47 L52.35,31.47 L52.35,34.22 L49.92,34.22 L49.92,35.67 L54.94,35.67 L54.94,38.91 L46.21,38.91 L46.21,27.1 Z M56.24,27.1 L59.8,27.1 L63.85,33.25 C63.85,32.44 63.69,31.79 63.69,31.3 C63.69,31.15 63.69,31.15 63.69,30.98 L63.69,27.1 L67.41,27.1 L67.41,38.91 L63.85,38.91 L59.8,32.76 C59.96,33.41 59.96,34.06 59.96,34.54 L59.96,38.91 L56.24,38.91 L56.24,27.1 Z M18.04,35.52 C19.33,35.52 20.46,34.38 20.46,33.09 C20.46,31.63 19.33,30.66 18.04,30.66 C16.58,30.66 15.44,31.63 15.44,33.09 C15.44,34.38 16.58,35.52 18.04,35.52 L18.04,35.52 Z" fill="#fffffe"/></svg>';

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
                ['key' => 'price_s', 'label' => 'Cena gabarytu S (zł brutto)', 'type' => 'text',
                 'help' => 'Cena brutto z Twojej umowy, czyli kwota, którą płacisz za paczkę — do wyceny i kosztu przesyłki na zamówieniu. Puste = cena orientacyjna ORLEN Paczka (9,99 / 10,99 / 12,99 zł brutto).'],
                ['key' => 'price_m', 'label' => 'Cena gabarytu M (zł brutto)', 'type' => 'text'],
                ['key' => 'price_l', 'label' => 'Cena gabarytu L (zł brutto)', 'type' => 'text'],
            ],
            color: '#d7141a',
            icon: '📦',
            logo: self::LOGO,
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

    // ================= Uniwersalny Pusher (webhook statusów) =================

    /** Numer paczki w powiadomieniu testowym, które ORLEN wysyła po rejestracji Pushera. */
    public const PUSH_TEST_PACK = '9999999999999';

    /** Maks. długość NotificationUrl w WebhookRegister. */
    public const PUSH_URL_MAX = 100;

    /** Nazwy statusów ORLEN Paczka (dokumentacja API, rozdz. 7.3) - powiadomienie Pushera podaje sam numer. */
    public const STATUS_NAMES = [
        100 => 'W sortowni regionalnej', 110 => 'W transporcie do sortowni centralnej', 120 => 'W transporcie do punktu odbioru',
        193 => 'Przekierowanie do automatu paczkowego', 195 => 'Przekierowanie do punktu', 200 => 'Zaawizowana do PwR',
        201 => 'Anulowane awizo', 210 => 'Nadana w kiosku', 230 => 'W transporcie do ekspedycji z kiosku',
        240 => 'W transporcie do ekspedycji u kuriera', 241 => 'W transporcie po magazynowaniu', 300 => 'W sortowni centralnej',
        400 => 'W sortowni centralnej', 401 => 'W sortowni centralnej', 450 => 'W transporcie do ekspedycji z sortowni centralnej',
        610 => 'Wydana kurierowi do doręczenia', 620 => 'Gotowa do odbioru', 653 => 'W ekspedycji',
        660 => 'W transporcie do kiosku u kuriera', 665 => 'Przekazana do kiosku', 670 => 'Przekazana do punktu',
        677 => 'Do nieczynnego kiosku', 679 => 'Niewydana kierowcy', 680 => 'W transporcie do kiosku', 681 => 'Do magazynowania',
        685 => 'W transporcie do kiosku (DP)', 690 => 'W kiosku', 691 => 'Magazynowanie w punkcie', 695 => 'W kiosku – SMS wysłany',
        696 => 'Magazynowanie w punkcie – SMS wysłany', 700 => 'W ekspedycji do sortowni centralnej',
        708 => 'Magazynowanie w punkcie – nieodebrana', 709 => 'Powrót – nieodebrana w terminie', 710 => 'Utracona/Poszukiwana',
        711 => 'Przesyłka w weryfikacji', 712 => 'Zatrzymana', 714 => 'Nieczynny punkt', 729 => 'Powrót – niepoprawny kiosk',
        739 => 'Nie przekazano do kiosku', 749 => 'Reklamacja', 750 => 'Zwrot do nadawcy', 780 => 'Brak możliwości doręczenia do punktu',
        790 => 'Zwrot do ekspedycji', 800 => 'Zwrot do sortowni', 888 => 'Archiwizacja', 900 => 'Zwrot do nadawcy',
        999 => 'Zniszczona – zagubiona', 1000 => 'Odebrana przez klienta', 1100 => 'Odebrana', 1200 => 'Odebrana – zwrot',
        1220 => 'Zwrot do nadawcy', 2000 => 'Likwidacja',
    ];

    /** Czy ORLEN Paczka sama przysyła zmiany statusów (Pusher włączony w Integracjach). */
    public function pushActive(): bool
    {
        return (string) $this->cfg('pusher_active', '') === '1' && (string) $this->cfg('pusher_token', '') !== '';
    }

    /**
     * Powiadomienie Uniwersalnego Pushera (rdzeń: public/webhook_plugin.php?a=<id konta>).
     * Treść: {"PackCode":1234567890123,"PackCodePrev":null,"Status":"200","Updated":"2025-06-26T01:02:03.225694","Operator":"ORLEN-PACZKA"}.
     * HTTP 200 = przyjęte; inny kod = ORLEN ponowi powiadomienie za 5 minut (dokumentacja Pushera, rozdz. 5.3).
     * @param array{method:string,headers:array<string,string>,query:array<string,mixed>,body:string,account_id:int} $request
     * @return array{status:int,body:string}
     */
    public function handleWebhook(array $request, \PDO $pdo): array
    {
        if (strtoupper((string) ($request['method'] ?? '')) !== 'POST') {
            return ['status' => 405, 'body' => 'POST only'];
        }
        $token = (string) $this->cfg('pusher_token', '');
        if ($token === '' || !hash_equals($token, self::requestToken($request))) {
            Logger::warn('ORLEN Paczka Pusher: powiadomienie bez poprawnego tokenu - odrzucone', ['account' => $request['account_id'] ?? 0]);
            return ['status' => 401, 'body' => 'unauthorized'];
        }
        $data = json_decode((string) ($request['body'] ?? ''), true, 8, JSON_BIGINT_AS_STRING);
        if (!is_array($data) || !isset($data['PackCode'], $data['Status'])) {
            Logger::warn('ORLEN Paczka Pusher: nieczytelne powiadomienie', ['body' => mb_substr((string) ($request['body'] ?? ''), 0, 300)]);
            return ['status' => 400, 'body' => 'bad payload'];
        }
        $pack = self::packCode($data['PackCode']);
        $prev = self::packCode($data['PackCodePrev'] ?? null);
        [$trans, $attr] = self::splitStatus((string) $data['Status']);
        $updated = (string) ($data['Updated'] ?? '');
        Logger::info('ORLEN Paczka Pusher: ' . $pack . ' status ' . $data['Status'], ['prev' => $prev, 'updated' => $updated]);

        $matched = 0;
        if ($pack !== self::PUSH_TEST_PACK) {
            $code = self::statusCode($trans, $attr);
            if ($code !== null) {
                $event = ['code' => $code, 'description' => self::statusName($trans, $attr), 'occurredAt' => self::eventTime($updated)];
                $matched = \Pase\Services\ShipmentTracking::applyPush($pdo, self::CARRIER, $pack, $event);
                // Zwrot operacyjny (POWROT) może mieć nowy numer - wtedy dopasowujemy po numerze pierwotnej paczki.
                if ($matched === 0 && $prev !== '' && in_array($attr, ['POWROT', '2_POWROT'], true)) {
                    $matched = \Pase\Services\ShipmentTracking::applyPush($pdo, self::CARRIER, $prev, $event);
                }
            }
        }
        $this->rememberPush($pdo, (int) ($request['account_id'] ?? 0), [
            'at' => date('c'), 'pack' => $pack, 'status' => (string) $data['Status'], 'matched' => $matched,
        ]);
        return ['status' => 200, 'body' => 'ok'];
    }

    /** Token z nagłówka Authorization (Bearer albo hasło Basic) albo z parametru k w adresie. */
    public static function requestToken(array $request): string
    {
        $auth = '';
        foreach ($request['headers'] ?? [] as $name => $value) {
            if (strtolower((string) $name) === 'authorization') {
                $auth = trim((string) $value);
            }
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
            return $m[1];
        }
        if (preg_match('/^Basic\s+(\S+)$/i', $auth, $m)) {
            $pair = (string) base64_decode($m[1], true);
            return str_contains($pair, ':') ? substr($pair, strpos($pair, ':') + 1) : '';
        }
        $k = $request['query']['k'] ?? '';
        return is_string($k) ? $k : '';
    }

    /** „1000", „100_POWROT", „100_2_POWROT", „100_ZWROT" -> [1000, ''], [100, 'POWROT']... */
    public static function splitStatus(string $status): array
    {
        $status = strtoupper(trim($status));
        if (!preg_match('/^(\d+)(?:_(.+))?$/', $status, $m)) {
            return [0, ''];
        }
        return [(int) $m[1], (string) ($m[2] ?? '')];
    }

    private static function statusName(int $trans, string $attr): string
    {
        $name = self::STATUS_NAMES[$trans] ?? ('Status ' . $trans);
        return match ($attr) {
            'POWROT', '2_POWROT' => $name . ' (powrót do nadawcy)',
            'ZWROT'              => $name . ' (zwrot konsumencki)',
            default              => $name,
        };
    }

    /** Numer paczki z JSON (liczba albo tekst; null = brak). */
    private static function packCode(mixed $v): string
    {
        if ($v === null || is_array($v) || is_bool($v)) {
            return '';
        }
        if (is_float($v)) {
            return number_format($v, 0, '', '');
        }
        return preg_replace('/\D/', '', (string) $v) ?? '';
    }

    private function rememberPush(\PDO $pdo, int $accountId, array $info): void
    {
        if ($accountId <= 0) {
            return;
        }
        try {
            (new \Pase\Repository\SettingsRepository($pdo))->setMany(['ORLENPACZKA_PUSH_LAST_' . $accountId => json_encode($info, JSON_UNESCAPED_UNICODE)]);
        } catch (\Throwable $e) {
            Logger::warn('ORLEN Paczka Pusher: nie zapisano znacznika ostatniego powiadomienia: ' . $e->getMessage());
        }
    }

    /** Ostatnie powiadomienie Pushera dla konta (do panelu integracji). */
    public static function lastPush(\PDO $pdo, int $accountId): ?array
    {
        try {
            $raw = (new \Pase\Repository\SettingsRepository($pdo))->get('ORLENPACZKA_PUSH_LAST_' . $accountId);
        } catch (\Throwable) {
            return null;
        }
        $v = $raw !== null ? json_decode($raw, true) : null;
        return is_array($v) ? $v : null;
    }

    /**
     * Adres powiadomień: {APP_BASE_URL}/webhook_plugin.php?a=<id konta>, z tokenem w parametrze k, jeśli zmieści się
     * w limicie 100 znaków (token idzie też w nagłówku Authorization; parametr ratuje hostingi, które ucinają ten nagłówek).
     */
    public static function pusherUrl(string $baseUrl, int $accountId, string $token): string
    {
        $url = rtrim(trim($baseUrl), '/') . '/webhook_plugin.php?a=' . $accountId;
        $withToken = $url . '&k=' . $token;
        return strlen($withToken) <= self::PUSH_URL_MAX ? $withToken : $url;
    }

    // ----- Panel integracji (rdzeń: public/admin/integration_edit.php) -----

    /** Przyciski pod formularzem integracji: akcja => etykieta. */
    public function adminActions(): array
    {
        return $this->pushActive()
            ? ['pusher_status' => 'Sprawdź Pusher w ORLEN Paczka', 'pusher_on' => 'Zarejestruj Pusher ponownie', 'pusher_off' => 'Wyłącz Pusher']
            : ['pusher_on' => '⚡ Włącz Uniwersalny Pusher'];
    }

    /**
     * Opis stanu Pushera nad przyciskami. @param array{account_id:int,base_url:string,pdo:\PDO,local:bool} $ctx
     * @return string[] akapity (tekst)
     */
    public function adminInfo(array $ctx): array
    {
        $lines = ['Uniwersalny Pusher: ORLEN Paczka sama wysyła do CRM każdą zmianę statusu paczki, więc etap przesyłki zmienia się od razu, '
            . 'a CRM nie musi co chwilę pytać o statusy (sprawdza je już tylko co ' . \Pase\Services\ShipmentTracking::PUSH_RECHECK_HOURS . ' godz. na wszelki wypadek).'];
        if (!$this->pushActive()) {
            $lines[] = 'Stan: wyłączony. Zmiany statusów CRM pobiera z harmonogramu „Śledzenie przesyłek”.';
            return $lines;
        }
        $lines[] = 'Stan: włączony. Adres powiadomień: ' . self::maskToken((string) $this->cfg('pusher_url', '')) . '.';
        $last = self::lastPush($ctx['pdo'], (int) $ctx['account_id']);
        if ($last === null) {
            $lines[] = 'Nie przyszło jeszcze żadne powiadomienie od ORLEN Paczka.';
        } else {
            $when = date('Y-m-d H:i', strtotime((string) $last['at']) ?: time());
            $lines[] = 'Ostatnie powiadomienie: ' . $when . ' — '
                . (($last['pack'] ?? '') === self::PUSH_TEST_PACK ? 'testowe (paczka 9999999999999).'
                    : 'paczka ' . $last['pack'] . ', status ' . $last['status'] . ((int) ($last['matched'] ?? 0) > 0 ? '.' : ' (nie ma jej w CRM).'));
        }
        return $lines;
    }

    /**
     * Akcja z panelu integracji. $ctx['save'] zapisuje config konta (fn(array $config): void).
     * @param array{account_id:int,base_url:string,pdo:\PDO,local:bool,save:callable} $ctx
     * @return array{ok:bool,message:string}
     */
    public function adminAction(string $action, array $ctx): array
    {
        if (trim((string) $this->cfg('partner_id', '')) === '' || trim((string) $this->cfg('partner_key', '')) === '') {
            return ['ok' => false, 'message' => 'Najpierw wpisz i zapisz PartnerID i PartnerKey.'];
        }
        $config = $this->config;
        switch ($action) {
            case 'pusher_on':
                $base = trim((string) ($ctx['base_url'] ?? ''));
                if (!empty($ctx['local'])) {
                    return ['ok' => false, 'message' => 'CRM działa w trybie lokalnym (na tym komputerze) — ORLEN Paczka nie ma jak wysłać do niego powiadomień. Statusy pobiera harmonogram.'];
                }
                if (!preg_match('#^https?://#i', $base) || str_contains($base, 'twojsklep.pl')) {
                    return ['ok' => false, 'message' => 'Ustaw adres CRM (APP_BASE_URL) w Konfiguracji — na niego ORLEN Paczka wyśle powiadomienia.'];
                }
                $token = (string) ($config['pusher_token'] ?? '');
                if (!preg_match('/^[a-f0-9]{24}$/', $token)) {
                    $token = bin2hex(random_bytes(12));
                }
                $url = self::pusherUrl($base, (int) $ctx['account_id'], $token);
                if (strlen($url) > self::PUSH_URL_MAX) {
                    return ['ok' => false, 'message' => 'Adres powiadomień jest dłuższy niż 100 znaków, a tyle przyjmuje ORLEN Paczka: ' . $url];
                }
                // Token zapisujemy PRZED rejestracją: ORLEN od razu wysyła powiadomienie testowe i musi przejść autoryzację.
                $config['pusher_token'] = $token;
                ($ctx['save'])($config);
                $r = $this->client()->webhookRegister($url, $token);
                if (!$r['ok']) {
                    $config['pusher_active'] = '';
                    ($ctx['save'])($config);
                    return ['ok' => false, 'message' => 'ORLEN Paczka nie zarejestrowała Pushera: ' . $r['message']];
                }
                $status = (string) ($r['pusher']['Status'] ?? '');
                $config['pusher_active'] = '1';
                $config['pusher_url'] = $url;
                ($ctx['save'])($config);
                return ['ok' => true, 'message' => 'Pusher włączony' . ($status !== '' ? ' (status w ORLEN Paczka: ' . $status . ')' : '')
                    . '. ORLEN Paczka wyśle teraz powiadomienie testowe — odśwież stronę, by zobaczyć, czy dotarło.'
                    . (str_starts_with(strtolower($url), 'http://') ? ' Uwaga: adres CRM nie używa HTTPS, ORLEN zaleca HTTPS.' : '')];

            case 'pusher_status':
                $r = $this->client()->webhookStatus();
                if (!$r['ok']) {
                    return ['ok' => false, 'message' => 'Nie udało się sprawdzić Pushera: ' . $r['message']];
                }
                $p = $r['pusher'];
                $status = (string) ($p['Status'] ?? '');
                if ($status !== '' && strcasecmp($status, 'Active') !== 0 && ($config['pusher_active'] ?? '') === '1') {
                    $config['pusher_active'] = '';
                    ($ctx['save'])($config);
                }
                return ['ok' => strcasecmp($status, 'Active') === 0,
                        'message' => 'Pusher w ORLEN Paczka: ' . ($status !== '' ? $status : 'brak danych')
                            . (($p['Annotation'] ?? '') !== '' ? ' — ' . $p['Annotation'] : '')
                            . (($p['NotificationUrl'] ?? '') !== '' ? '. Adres: ' . self::maskToken((string) $p['NotificationUrl']) : '') . '.'];

            case 'pusher_off':
                $r = $this->client()->webhookUnregister();
                if (!$r['ok']) {
                    return ['ok' => false, 'message' => 'ORLEN Paczka nie wyłączyła Pushera: ' . $r['message']];
                }
                $config['pusher_active'] = '';
                ($ctx['save'])($config);
                return ['ok' => true, 'message' => 'Pusher wyłączony. Statusy paczek znów pobiera harmonogram „Śledzenie przesyłek”.'];
        }
        return ['ok' => false, 'message' => 'Nieznana akcja.'];
    }

    /** Adres bez tokenu (do wyświetlenia w panelu). */
    private static function maskToken(string $url): string
    {
        return preg_replace('/([?&]k=)[^&]+/', '$1•••', $url) ?? $url;
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
