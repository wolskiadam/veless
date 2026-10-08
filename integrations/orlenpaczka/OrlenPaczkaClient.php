<?php
declare(strict_types=1);

namespace PasePlugin\Orlenpaczka;

use Pase\Support\Http;
use Pase\Support\Logger;

/**
 * Klient API ORLEN Paczka (WebService SOAP, dokumentacja API_ORLENPaczka v1.26).
 *
 * SOAP składamy ręcznie (bez rozszerzenia php-soap, którego często brakuje na hostingu):
 * koperta SOAP 1.1, element operacji w przestrzeni nazw z WSDL. Odpowiedzi czytamy po nazwach
 * elementów (bez przestrzeni nazw), bo część metod zwraca DataSet (.NET diffgram), a część
 * zwykłe obiekty - pola biznesowe nazywają się tak samo.
 *
 * Autoryzacja: PartnerID + PartnerKey w treści każdego wywołania (dane z umowy z ORLEN Paczka;
 * PartnerKey to to samo hasło co w WebTrucker). Środowisko testowe ma osobne dane dostępowe.
 *
 * Każda metoda zwraca ['ok' => bool, 'message' => string, ...dane]; nie rzuca wyjątków.
 */
final class OrlenPaczkaClient
{
    public const URL_PRODUCTION = 'https://api.orlenpaczka.pl/WebServicePwRProd/WebServicePwR.asmx';
    public const URL_TEST       = 'https://apitest.orlenpaczka.pl/WebServicePwR/WebServicePwR.asmx';

    /** Przestrzeń nazw operacji z WSDL (dokumentacja: nie zastępować adresem hosta). */
    public const NS = 'https://91.242.220.103/WebServicePwR';

    /** Kody <Err> oznaczające zapis (0) i zapis ze zmienionym kodem punktu (006/007/008). */
    private const OK_CODES = [0, 6, 7, 8];

    /**
     * Atrapa transportu w testach: fn(string $operation, string $xml, string $url): string (treść odpowiedzi SOAP).
     * @var (callable(string,string,string):string)|null
     */
    public static $transport = null;

    public function __construct(
        private readonly string $partnerId,
        private readonly string $partnerKey,
        private readonly bool $test = false
    ) {}

    public function url(): string
    {
        return $this->test ? self::URL_TEST : self::URL_PRODUCTION;
    }

    // ================= Administracja =================

    /** Dane konta: typ płatności (POSTPAID/PREPAID), dane firmy. */
    public function partnerStatus(): array
    {
        $r = $this->call('GivePartnerStatus', $this->auth());
        if (!$r['ok']) {
            return $r;
        }
        return $this->checked($r, self::firstRow($r['doc'], 'Err'));
    }

    // ================= Punkty odbioru =================

    /**
     * Wszystkie punkty odbioru (APM, PPP, PKN, PPK) - GiveMeAllLocationWithAllDataWithZipCode.
     * Odpowiedź jest duża (kilkanaście tysięcy punktów), więc czytamy ją strumieniowo i zostawiamy
     * tylko pola potrzebne do wyboru punktu.
     * @return array{ok:bool,message:string,points?:array<int,array<string,string>>}
     */
    public function allPoints(): array
    {
        $r = $this->call('GiveMeAllLocationWithAllDataWithZipCode', [], 120, false);
        if (!$r['ok']) {
            return $r;
        }
        $points = self::parsePoints($r['body']);
        return $points === []
            ? ['ok' => false, 'message' => 'ORLEN Paczka nie zwróciła listy punktów.']
            : ['ok' => true, 'message' => '', 'points' => $points];
    }

    // ================= Awizacja i etykiety =================

    /**
     * Awizacja paczki z etykietą (GenerateLabelBusinessPackListTwo - metoda zalecana).
     * @param array<string,string> $pack pola <BusinessPack> (DestinationCode, BoxSize, odbiorca, nadawca...)
     * @param string $format PDF | PDF10 | EPL | ZPL | ZPL_300DPI
     * @return array{ok:bool,message:string,pack_code?:string,destination?:string,price?:?float,paid?:?bool,warning?:string,label?:?string,row?:array}
     */
    public function createPack(array $pack, string $format): array
    {
        $r = $this->call('GenerateLabelBusinessPackListTwo', $this->auth() + [
            'AutoDestinationChange' => '',
            'Format'                => $format,
            'BusinessPackList'      => ['BusinessPack' => $pack],
        ], 60);
        if (!$r['ok']) {
            return $r;
        }
        $row = self::firstRow($r['doc'], 'PackCode_RUCH') ?? self::firstRow($r['doc'], 'Err');
        $checked = $this->checked($r, $row);
        if (!$checked['ok']) {
            return $checked;
        }
        $code = trim((string) ($row['PackCode_RUCH'] ?? ''));
        if ($code === '') {
            return ['ok' => false, 'message' => 'ORLEN Paczka nie zwróciła numeru paczki.', 'row' => $row];
        }
        $err = (int) ($row['Err'] ?? 0);
        $label = self::text($r['doc'], 'LabelData');
        return [
            'ok'          => true,
            'message'     => '',
            'pack_code'   => $code,
            'destination' => trim((string) ($row['DestinationCode'] ?? '')),
            'price'       => isset($row['PackPrice']) && is_numeric(trim($row['PackPrice'])) ? round((float) $row['PackPrice'] / 100, 2) : null,
            'paid'        => isset($row['PackPaid']) ? strtolower(trim($row['PackPaid'])) === 'true' : null,
            'warning'     => $err !== 0 ? self::errText($row) : '',
            'label'       => $label !== null && $label !== '' ? (base64_decode($label, true) ?: null) : null,
            'row'         => $row,
        ];
    }

    /**
     * Ponowne pobranie etykiet (LabelPrintDuplicateListTwo, maks. 50 paczek).
     * @param string[] $packCodes
     * @return array{ok:bool,message:string,content?:string}
     */
    public function labels(array $packCodes, string $format): array
    {
        $r = $this->call('LabelPrintDuplicateListTwo', $this->auth() + [
            'Format'       => $format,
            'PackCodeList' => ['string' => array_values($packCodes)],
        ], 60);
        if (!$r['ok']) {
            return $r;
        }
        foreach (self::rows($r['doc'], 'Err') as $row) {
            if (!in_array(self::errCode($row), self::OK_CODES, true)) {
                return ['ok' => false, 'message' => 'ORLEN Paczka: ' . self::errText($row)];
            }
        }
        $data = self::text($r['doc'], 'LabelData');
        $content = $data !== null ? base64_decode($data, true) : false;
        if ($content === false || $content === '') {
            return ['ok' => false, 'message' => 'ORLEN Paczka nie zwróciła etykiety.'];
        }
        return ['ok' => true, 'message' => '', 'content' => $content];
    }

    /** Anulowanie zaawizowanej paczki (PutCustomerPackCanceled). */
    public function cancel(string $packCode): array
    {
        $r = $this->call('PutCustomerPackCanceled', ['PackCode' => $packCode] + $this->auth());
        if (!$r['ok']) {
            return $r;
        }
        return $this->checked($r, self::firstRow($r['doc'], 'Err'));
    }

    // ================= Śledzenie =================

    /**
     * Pełna historia statusów wielu paczek (GiveMePackStatusFullHistoryList, maks. 1000).
     * @param string[] $packCodes
     * @return array{ok:bool,message:string,events?:array<string,array<int,array<string,string>>>} numer paczki => zdarzenia
     */
    public function history(array $packCodes): array
    {
        $r = $this->call('GiveMePackStatusFullHistoryList', ['PackCodes' => ['string' => array_values($packCodes)]] + $this->auth(), 30);
        if (!$r['ok']) {
            return $r;
        }
        $events = [];
        foreach (self::rows($r['doc'], 'Trans') as $row) {
            $code = trim((string) ($row['PackCode'] ?? ''));
            if ($code !== '') {
                $events[$code][] = $row;
            }
        }
        if ($events === [] && ($err = self::firstRow($r['doc'], 'Err')) !== null && !in_array(self::errCode($err), self::OK_CODES, true)) {
            return ['ok' => false, 'message' => 'ORLEN Paczka: ' . self::errText($err)];
        }
        return ['ok' => true, 'message' => '', 'events' => $events];
    }

    // ================= Uniwersalny Pusher (webhook statusów) =================

    /**
     * Rejestracja adresu, na który ORLEN Paczka wysyła zmiany statusów paczek (WebhookRegister).
     * Autoryzacja OAuth2StaticToken: ORLEN dołącza do każdego powiadomienia nagłówek „Authorization: Bearer <token>".
     * Po rejestracji ORLEN wysyła powiadomienie testowe dla paczki 9999999999999.
     * @return array{ok:bool,message:string,pusher?:array<string,string>}
     */
    public function webhookRegister(string $notificationUrl, string $token): array
    {
        return $this->webhook('WebhookRegister', [
            'AuthorizationType' => 'OAuth2StaticToken',
            'UserName'          => '',
            'Password'          => $token,
            'LoginUrl'          => '',
            'NotificationUrl'   => $notificationUrl,
        ]);
    }

    /** Aktualna konfiguracja Pushera (WebhookStatus): Status Active / Unregistered, NotificationUrl... */
    public function webhookStatus(): array
    {
        return $this->webhook('WebhookStatus', []);
    }

    /** Wyłączenie Pushera (WebhookUnregister) - ORLEN czyści też kolejkę niedoręczonych powiadomień. */
    public function webhookUnregister(): array
    {
        return $this->webhook('WebhookUnregister', []);
    }

    private function webhook(string $operation, array $params): array
    {
        $r = $this->call($operation, $this->auth() + $params);
        if (!$r['ok']) {
            return $r;
        }
        $checked = $this->checked($r, self::firstRow($r['doc'], 'Err'));
        if (!$checked['ok']) {
            return $checked;
        }
        $data = self::firstRow($r['doc'], 'NotificationUrl') ?? self::firstRow($r['doc'], 'Annotation') ?? [];
        unset($data['Password']);   // token nie wraca do panelu ani do logów
        return ['ok' => true, 'message' => '', 'pusher' => $data];
    }

    // ================= Podjazd kuriera =================

    /** Dostępne przedziały podjazdu kuriera dla kodu pocztowego (GetAvailablePickups). */
    public function availablePickups(array $address, string $readyDate): array
    {
        $r = $this->call('GetAvailablePickups', $this->auth() + [
            'PostCode'      => $address['PostCode'] ?? '',
            'City'          => $address['City'] ?? '',
            'Street'        => $address['Street'] ?? '',
            'BuildingNo'    => $address['BuildingNo'] ?? '',
            'Email'         => $address['Email'] ?? '',
            'PartnerName'   => $address['PartnerName'] ?? '',
            'PersonName'    => $address['PersonName'] ?? '',
            'PersonSurname' => $address['PersonSurname'] ?? '',
            'Telephone'     => $address['Telephone'] ?? '',
            'ReadyDate'     => $readyDate,
        ]);
        if (!$r['ok']) {
            return $r;
        }
        $checked = $this->checked($r, self::firstRow($r['doc'], 'Err'));
        if (!$checked['ok']) {
            return $checked;
        }
        return ['ok' => true, 'message' => '', 'days' => self::rows($r['doc'], 'MinReadyDate')];
    }

    /**
     * Zamówienie podjazdu kuriera (CallPickupNew).
     * @param string[] $packCodes
     * @return array{ok:bool,message:string,pickup_no?:string}
     */
    public function callPickup(array $packCodes, string $readyDate, string $pickupDate, array $address): array
    {
        $r = $this->call('CallPickupNew', $this->auth() + [
            'PackList'       => ['string' => array_values($packCodes)],
            'ReadyDate'      => $readyDate,
            'PickupDate'     => $pickupDate,
            'PostCode'       => $address['PostCode'] ?? '',
            'City'           => $address['City'] ?? '',
            'Street'         => $address['Street'] ?? '',
            'BuildingNo'     => $address['BuildingNo'] ?? '',
            'Email'          => $address['Email'] ?? '',
            'PartnerName'    => $address['PartnerName'] ?? '',
            'PersonName'     => $address['PersonName'] ?? '',
            'PersonSurname'  => $address['PersonSurname'] ?? '',
            'Telephone'      => $address['Telephone'] ?? '',
            'ExternalNumber' => $address['ExternalNumber'] ?? '',
        ]);
        if (!$r['ok']) {
            return $r;
        }
        $row = self::firstRow($r['doc'], 'Err');
        $checked = $this->checked($r, $row);
        if (!$checked['ok']) {
            return $checked;
        }
        return ['ok' => true, 'message' => '', 'pickup_no' => trim((string) ($row['Data'] ?? ''))];
    }

    // ================= SOAP =================

    /** @return array<string,string> */
    private function auth(): array
    {
        return ['PartnerID' => $this->partnerId, 'PartnerKey' => $this->partnerKey];
    }

    /**
     * Wywołanie operacji. Zwraca ['ok', 'message', 'doc' => DOMDocument, 'body' => string].
     * $parse = false: bez budowania DOM (duże odpowiedzi czytamy strumieniowo).
     */
    private function call(string $operation, array $params, int $timeout = 30, bool $parse = true): array
    {
        $xml = self::envelope($operation, $params);
        try {
            if (is_callable(self::$transport)) {
                $body = (string) (self::$transport)($operation, $xml, $this->url());
                $status = 200;
            } else {
                $res = Http::request('POST', $this->url(), [
                    'Content-Type' => 'text/xml; charset=utf-8',
                    'SOAPAction'   => '"' . self::NS . '/' . $operation . '"',
                ], $xml, $timeout);
                $status = $res->status;
                $body = $res->body;
                Logger::apiResponse('orlenpaczka', 'POST', '/' . $operation, $status);
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Brak połączenia z ORLEN Paczka: ' . $e->getMessage(), 'transport' => true];
        }
        if ($status === 0) {
            return ['ok' => false, 'message' => 'Brak odpowiedzi z ORLEN Paczka (' . $body . ').', 'transport' => true];
        }
        if (!$parse && $status >= 200 && $status < 300) {
            return ['ok' => true, 'message' => '', 'body' => $body];
        }

        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $body !== '' && $doc->loadXML($body, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            return ['ok' => false, 'message' => "ORLEN Paczka odpowiedziała niepoprawnie (HTTP {$status})."];
        }
        foreach ($doc->getElementsByTagNameNS('*', 'Fault') as $faultEl) {
            $fault = '';
            foreach (['faultstring', 'Text'] as $n) {
                foreach ($faultEl->getElementsByTagNameNS('*', $n) as $t) {
                    $fault = $fault !== '' ? $fault : trim($t->textContent);
                }
            }
            Logger::warn('ORLEN Paczka: błąd SOAP', ['operation' => $operation, 'fault' => $fault]);
            return ['ok' => false, 'message' => 'ORLEN Paczka: ' . ($fault !== '' ? $fault : "błąd SOAP (HTTP {$status})")];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'message' => "ORLEN Paczka odpowiedziała błędem HTTP {$status}."];
        }
        return ['ok' => true, 'message' => '', 'doc' => $doc, 'body' => $body];
    }

    /** Wynik z kodem <Err>: 0 i 006-008 to zapis, inne kody to błąd z opisem. */
    private function checked(array $r, ?array $row): array
    {
        if ($row === null) {
            return ['ok' => false, 'message' => 'ORLEN Paczka zwróciła pustą odpowiedź.'];
        }
        if (!in_array(self::errCode($row), self::OK_CODES, true)) {
            return ['ok' => false, 'message' => 'ORLEN Paczka: ' . self::errText($row), 'row' => $row];
        }
        return ['ok' => true, 'message' => '', 'row' => $row];
    }

    /** Koperta SOAP 1.1 z parametrami operacji (tablice zagnieżdżone; lista = powtórzony element). */
    public static function envelope(string $operation, array $params): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soap:Body><' . $operation . ' xmlns="' . self::NS . '">'
            . self::elements($params)
            . '</' . $operation . '></soap:Body></soap:Envelope>';
    }

    private static function elements(array $params): string
    {
        $out = '';
        foreach ($params as $name => $value) {
            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $item) {
                    $out .= self::elements([$name => $item]);
                }
                continue;
            }
            $inner = is_array($value)
                ? self::elements($value)
                : htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $out .= "<{$name}>{$inner}</{$name}>";
        }
        return $out;
    }

    // ================= Czytanie odpowiedzi =================

    /** Tekst pierwszego elementu o danej nazwie (bez przestrzeni nazw). */
    public static function text(\DOMDocument $doc, string $name): ?string
    {
        foreach ($doc->getElementsByTagNameNS('*', $name) as $el) {
            return trim($el->textContent);
        }
        return null;
    }

    /**
     * Wiersze: rodzice elementów $key, jako mapa nazwa pola => tekst (tylko pola bez dzieci).
     * Działa i dla zwykłych obiektów, i dla DataSet (diffgram), bo schemat XSD ma inne nazwy elementów.
     * @return array<int,array<string,string>>
     */
    public static function rows(\DOMDocument $doc, string $key): array
    {
        $rows = [];
        foreach ($doc->getElementsByTagNameNS('*', $key) as $el) {
            $parent = $el->parentNode;
            if (!$parent instanceof \DOMElement) {
                continue;
            }
            $row = [];
            foreach ($parent->childNodes as $child) {
                if ($child instanceof \DOMElement && !self::hasElementChildren($child)) {
                    $row[$child->localName] ??= trim($child->textContent);
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }

    public static function firstRow(\DOMDocument $doc, string $key): ?array
    {
        return self::rows($doc, $key)[0] ?? null;
    }

    private static function hasElementChildren(\DOMElement $el): bool
    {
        foreach ($el->childNodes as $c) {
            if ($c instanceof \DOMElement) {
                return true;
            }
        }
        return false;
    }

    /** <Err> jako liczba („000" i „0" to to samo - dokumentacja każe nie porównywać tekstu). */
    private static function errCode(array $row): int
    {
        $e = trim((string) ($row['Err'] ?? '0'));
        return $e === '' ? 0 : (int) $e;
    }

    private static function errText(array $row): string
    {
        $des = trim((string) ($row['ErrDes'] ?? ''));
        $code = trim((string) ($row['Err'] ?? ''));
        return ($des !== '' ? $des : 'błąd') . ($code !== '' ? " (kod {$code})" : '');
    }

    /**
     * Punkty z odpowiedzi GiveMeAllLocationWithAllDataWithZipCode, czytane strumieniowo:
     * wiersz = rodzic elementu <DestinationCode>.
     * @return array<int,array<string,string>>
     */
    public static function parsePoints(string $xml): array
    {
        // 1) Nazwa elementu wiersza (np. LocationWithAllData2) - rodzic pierwszego <DestinationCode>.
        $r = new \XMLReader();
        if (!$r->XML($xml, null, LIBXML_NONET)) {
            return [];
        }
        $stack = [];
        $rowName = null;
        $prev = libxml_use_internal_errors(true);
        while (@$r->read()) {
            if ($r->nodeType === \XMLReader::ELEMENT) {
                if ($r->localName === 'DestinationCode' && $stack !== []) {
                    $rowName = end($stack);
                    break;
                }
                if (!$r->isEmptyElement) {
                    $stack[] = $r->localName;
                }
            } elseif ($r->nodeType === \XMLReader::END_ELEMENT) {
                array_pop($stack);
            }
        }
        $r->close();
        if ($rowName === null) {
            libxml_use_internal_errors($prev);
            return [];
        }

        // 2) Każdy wiersz rozwijamy osobno (pamięć rośnie tylko o wynik).
        $fields = ['DestinationCode' => 'code', 'PSD' => 'psd', 'PointType' => 'type', 'StreetName' => 'street',
            'BuildingNumber' => 'building', 'City' => 'city', 'Zipcode' => 'zip', 'Province' => 'province',
            'OpeningHours' => 'hours', 'Location' => 'location', 'Available' => 'available',
            'Latitude' => 'lat', 'Longitude' => 'lng'];
        $points = [];
        $r = new \XMLReader();
        $r->XML($xml, null, LIBXML_NONET);
        while (@$r->read()) {
            if ($r->nodeType !== \XMLReader::ELEMENT || $r->localName !== $rowName) {
                continue;
            }
            $node = $r->expand();
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $p = [];
            foreach ($node->childNodes as $c) {
                if ($c instanceof \DOMElement && isset($fields[$c->localName])) {
                    $p[$fields[$c->localName]] = trim(preg_replace('/\s+/u', ' ', $c->textContent) ?? '');
                }
            }
            if (($p['code'] ?? '') !== '') {
                $points[] = $p;
            }
        }
        $r->close();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $points;
    }
}
