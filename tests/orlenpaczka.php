<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Wtyczka ORLEN Paczka: koperta SOAP, odczyt odpowiedzi (zwykłej i DataSet), nadanie z punktem i gabarytem,
// etykieta, anulowanie, podjazd kuriera, lista punktów i śledzenie. Atrapa API, bez sieci i bazy.
define('PASE_ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require PASE_ROOT . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; return; }
    if (str_starts_with($class, 'PasePlugin\\')) {
        $parts = explode('\\', substr($class, 11));
        $parts[0] = strtolower($parts[0]);
        $f = PASE_ROOT . '/integrations/' . implode('/', $parts) . '.php';
        if (is_file($f)) { require $f; }
    }
});
use Pase\Plugin\Contract\Courier;
use PasePlugin\Orlenpaczka\OrlenPaczkaClient;
use PasePlugin\Orlenpaczka\OrlenPaczkaPlugin;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

function soap(string $op, string $inner): string
{
    return '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
        . "<{$op}Response xmlns=\"https://91.242.220.103/WebServicePwR\">{$inner}</{$op}Response></soap:Body></soap:Envelope>";
}
/** DataSet .NET (schemat + diffgram), tak jak zwracają starsze metody ASMX. */
function dataset(string $op, string $rows, string $after = ''): string
{
    return soap($op, "<{$op}Result><xs:schema xmlns:xs=\"http://www.w3.org/2001/XMLSchema\" id=\"NewDataSet\"><xs:element name=\"NewDataSet\"><xs:complexType>"
        . '<xs:sequence><xs:element name="Err" type="xs:string"/><xs:element name="PackCode_RUCH" type="xs:string"/></xs:sequence></xs:complexType></xs:element></xs:schema>'
        . '<diffgr:diffgram xmlns:msdata="urn:schemas-microsoft-com:xml-msdata" xmlns:diffgr="urn:schemas-microsoft-com:xml-diffgram-v1"><NewDataSet xmlns="">'
        . $rows . "</NewDataSet></diffgr:diffgram></{$op}Result>{$after}");
}

$calls = [];
$responses = [];
OrlenPaczkaClient::$transport = static function (string $op, string $xml, string $url) use (&$calls, &$responses): string {
    $calls[] = ['op' => $op, 'xml' => $xml, 'url' => $url];
    $r = $responses[$op] ?? null;
    if ($r === null) { throw new RuntimeException("Nieoczekiwane wywołanie {$op}"); }
    return is_callable($r) ? $r($xml) : $r;
};
$tmpPoints = sys_get_temp_dir() . '/orlen-points-' . bin2hex(random_bytes(4)) . '.json';
OrlenPaczkaPlugin::$pointsCacheFile = $tmpPoints;

$cfg = ['partner_id' => 'PARTNER01', 'partner_key' => 'Tajne<&>Haslo', 'env' => 'production',
    'sender_company' => 'Sklep Testowy', 'sender_street' => 'Testowa', 'sender_building' => '1a', 'sender_postcode' => '00001',
    'sender_city' => 'Warszawa', 'sender_phone' => '+48 444 555 666', 'sender_email' => 'sklep@example.com', 'text_on_label' => 'Zam. {nr}'];
$plugin = (new OrlenPaczkaPlugin())->withConfig($cfg);
check($plugin instanceof Courier && $plugin->manifest()->type === 'orlenpaczka', 'Wtyczka kurierska o typie orlenpaczka');
check(in_array('partner_key', $plugin->manifest()->secretKeys(), true), 'PartnerKey jest sekretem');
$icon = $plugin->manifest()->iconHtml('20px');
check(str_starts_with($icon, '<span class="plugin-logo" style="width:20px;height:20px') && str_contains($icon, '<svg width="100%" height="100%" viewBox="0 0 80 80"')
    && (new DOMDocument())->loadXML($icon), 'Ikona: logo ORLEN Paczka (poprawny SVG) zamiast emoji');
check((new \Pase\Plugin\PluginManifest(type: 'x', name: 'X', icon: '<b>'))->iconHtml() === '&lt;b&gt;', 'Bez logo: emoji, escapowane');

// --- koperta SOAP ---
$env = OrlenPaczkaClient::envelope('LabelPrintDuplicateListTwo', ['PartnerID' => 'A', 'PartnerKey' => 'x<y', 'PackCodeList' => ['string' => ['1', '2']]]);
check(str_contains($env, '<LabelPrintDuplicateListTwo xmlns="https://91.242.220.103/WebServicePwR">'), 'Operacja w przestrzeni nazw z WSDL');
check(str_contains($env, '<PackCodeList><string>1</string><string>2</string></PackCodeList>'), 'Lista jako powtórzone elementy <string>');
check(str_contains($env, '<PartnerKey>x&lt;y</PartnerKey>') && (new DOMDocument())->loadXML($env), 'Znaki specjalne są escapowane, XML poprawny');

// --- test połączenia ---
$responses['GivePartnerStatus'] = soap('GivePartnerStatus', '<GivePartnerStatusResult><Err>000</Err><ErrDes>saved</ErrDes><PaymentType>POSTPAID</PaymentType><CompanyName>Sklep Testowy</CompanyName></GivePartnerStatusResult>');
$t = $plugin->testConnection();
check($t['ok'] && str_contains($t['message'], 'Sklep Testowy') && str_contains($t['message'], 'POSTPAID'), 'Test połączenia pokazuje konto i rozliczenie');
check(end($calls)['url'] === OrlenPaczkaClient::URL_PRODUCTION, 'Produkcja: api.orlenpaczka.pl');
$responses['GivePartnerStatus'] = soap('GivePartnerStatus', '<GivePartnerStatusResult><Err>2</Err><ErrDes>Błędny PartnerID lub PartnerKey</ErrDes></GivePartnerStatusResult>');
$t = (new OrlenPaczkaPlugin())->withConfig(['env' => 'test'] + $cfg)->testConnection();
check(!$t['ok'] && str_contains($t['message'], 'Błędny PartnerID') && end($calls)['url'] === OrlenPaczkaClient::URL_TEST, 'Błąd logowania czytelny, środowisko testowe');
$responses['GivePartnerStatus'] = '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault><faultcode>soap:Server</faultcode><faultstring>Server was unable to process request.</faultstring></soap:Fault></soap:Body></soap:Envelope>';
check(str_contains($plugin->testConnection()['message'], 'unable to process'), 'SOAP Fault jako komunikat');
check(!(new OrlenPaczkaPlugin())->withConfig([])->testConnection()['ok'], 'Bez danych logowania test nie łączy się');

// --- gabaryty ---
check(OrlenPaczkaPlugin::sizeFor(['x' => 30, 'y' => 20, 'z' => 5]) === 'S', '30×20×5 cm -> S');
check(OrlenPaczkaPlugin::sizeFor(['x' => 15, 'y' => 37, 'z' => 59]) === 'M', 'Wymiary w dowolnej kolejności -> M');
check(OrlenPaczkaPlugin::sizeFor(['x' => 40, 'y' => 38, 'z' => 60]) === 'L', '40×38×60 -> L');
check(OrlenPaczkaPlugin::sizeFor(['x' => 45, 'y' => 40, 'z' => 60]) === null, 'Za duża paczka -> brak gabarytu');
check(OrlenPaczkaPlugin::sizeFor([]) === 'M', 'Bez wymiarów -> M (domyślny gabaryt ORLEN)');

// --- punkty: kody ---
check(OrlenPaczkaPlugin::pointCode('ws-116263-c3-01') === 'WS-116263-C3-01', 'Pełny kod punktu bez zmian (wielkie litery)');
check(OrlenPaczkaPlugin::pointCode('116263') === 'XX-116263-00-00', 'Sam numer punktu -> kod uniwersalny');
check(OrlenPaczkaPlugin::pointCode('Punkt 116263 Warszawa', [['code' => 'WA-116263-K1-02', 'psd' => '116263']]) === 'WA-116263-K1-02', 'Numer punktu -> pełny kod z listy');
check(OrlenPaczkaPlugin::pointCode('KRA01M') === '', 'Kod paczkomatu InPost to nie punkt ORLEN');

// --- dane odbiorcy ---
check(OrlenPaczkaPlugin::splitStreet('ul. Długa 5/3') === ['Długa', '5', '3'], 'Ulica z numerem i lokalem');
check(OrlenPaczkaPlugin::splitStreet('Aleje Jerozolimskie 123A m. 7') === ['Aleje Jerozolimskie', '123A', '7'], 'Numer z literą i „m.”');
check(OrlenPaczkaPlugin::phone('+48 501-202-303') === '501202303' && OrlenPaczkaPlugin::phone('0048501202303') === '501202303', 'Telefon do 9 cyfr');
check(OrlenPaczkaPlugin::postcode('00950') === '00-950', 'Kod pocztowy 00-000');
$alg = OrlenPaczkaPlugin::receiver(['row' => [], 'payload' => ['buyer' => ['email' => 'k@allegromail.pl', 'phoneNumber' => '+48600100200'],
    'delivery' => ['address' => ['firstName' => 'Jan', 'lastName' => 'Nowak', 'street' => 'Polna 2', 'zipCode' => '30-001', 'city' => 'Kraków']]]]);
check($alg['FirstName'] === 'Jan' && $alg['PhoneNumber'] === '600100200' && $alg['EMail'] === 'k@allegromail.pl' && $alg['StreetName'] === 'Polna', 'Odbiorca z zamówienia Allegro');

// --- formularz nadania ---
$woo = ['row' => ['woo_order_id' => 501, 'pase_number' => 1234, 'local_billing_phone' => '+48 777 888 999'], 'payload' => [
    'billing' => ['first_name' => 'Anna', 'last_name' => 'Kowalska', 'email' => 'anna@example.com', 'phone' => '111222333', 'address_1' => 'Lipowa 1', 'postcode' => '00-001', 'city' => 'Warszawa'],
    'shipping' => ['first_name' => 'Anna', 'last_name' => 'Kowalska', 'address_1' => 'Lipowa 1', 'postcode' => '00-001', 'city' => 'Warszawa'],
    'meta_data' => [['key' => '_orlen_point', 'value' => '116263']],
]];
$sv = $plugin->listServices(['order' => $woo]);
check($sv['ok'] && array_column($sv['services'], 'id') === ['auto', 'S', 'M', 'L'] && $sv['default'] === 'auto', 'Usługi = gabaryty, domyślnie dobór z wymiarów');
check($sv['point']['value'] === 'XX-116263-00-00' && $sv['point']['required'] && $sv['point']['search'], 'Punkt z zamówienia WooCommerce w formularzu');
check(isset($sv['handover']['options']['courier']) && $sv['handover']['default'] === 'point', 'Sposób nadania: punkt albo kurier');
$noPoint = $plugin->listServices(['order' => ['row' => [], 'payload' => ['meta_data' => [['key' => '_paczkomat_id', 'value' => 'KRA01M']]]]]);
check($noPoint['point']['value'] === '' && str_contains($noPoint['point']['note'], 'KRA01M'), 'Paczkomat InPost w zamówieniu - prośba o punkt ORLEN');
check(str_contains((new OrlenPaczkaPlugin())->withConfig(['partner_id' => 'a'])->listServices(['order' => $woo])['message'], 'dane nadawcy'), 'Brak danych nadawcy - ostrzeżenie w formularzu');

// --- wycena ---
$q = $plugin->quote(['service' => 'auto', 'parcel' => ['weight' => 1, 'x' => 30, 'y' => 20, 'z' => 5]]);
check($q['ok'] && $q['price'] === 9.99 && str_contains($q['message'], 'orientacyjna'), 'Wycena S = 9,99 zł (orientacyjnie)');
$q = (new OrlenPaczkaPlugin())->withConfig(['price_m' => '8,50'] + $cfg)->quote(['service' => 'M', 'parcel' => []]);
check($q['ok'] && $q['price'] === 8.5 && str_contains($q['message'], 'z ustawień'), 'Cena gabarytu z umowy w ustawieniach');
check(!$plugin->quote(['service' => 'S', 'parcel' => ['weight' => 25]])['ok'], 'Ponad 20 kg - bez wyceny');

// --- nadanie ---
$label = base64_encode('%PDF-1.4 etykieta');
$responses['GenerateLabelBusinessPackListTwo'] = dataset('GenerateLabelBusinessPackListTwo',
    '<GenerateLabelBusinessPackListTwo diffgr:id="1"><Err>006</Err><ErrDes>Zapisano, ale zmieniono DestinationCode</ErrDes><PackCode_RUCH>2100012345678</PackCode_RUCH>'
    . '<DestinationCode>WA-116263-K1-02</DestinationCode><DestinationId>116263</DestinationId><PackPrice>999</PackPrice><PackPaid>true</PackPaid>'
    . '<NameCL>WARSZAWA</NameCL><OriginDestinationCode>XX-116263-00-00</OriginDestinationCode></GenerateLabelBusinessPackListTwo>',
    "<LabelData>{$label}</LabelData>");
$calls = [];
$r = $plugin->createShipment(['order' => $woo, 'parcel' => ['weight' => 1.2, 'x' => 30, 'y' => 20, 'z' => 5], 'service' => 'auto',
    'label_format' => 'PDF', 'text_on_label' => '', 'handover' => 'point', 'point' => '']);
check($r['ok'] && $r['waybill_no'] === '2100012345678' && $r['external_id'] === '2100012345678', 'Nadanie zwraca numer paczki (kod 006 = sukces)');
check($r['price'] === 9.99 && $r['courier_label'] === 'ORLEN Paczka' && $r['raw']['carrierId'] === 'ORLEN', 'Koszt, nazwa i przewoźnik do śledzenia');
check(str_contains($r['message'], 'WA-116263-K1-02') && str_contains($r['message'], 'gabaryt S'), 'Komunikat o zmienionym punkcie i gabarycie');
$x = new DOMDocument(); $x->loadXML($calls[0]['xml']);
$val = static fn(string $n) => $x->getElementsByTagNameNS('*', $n)->item(0)?->textContent;
check($val('DestinationCode') === 'XX-116263-00-00' && $val('BoxSize') === 'S' && $val('Format') === 'PDF10', 'Awizacja: punkt, gabaryt, etykieta 10×15');
check($val('PartnerID') === 'PARTNER01' && $val('PartnerKey') === 'Tajne<&>Haslo', 'Dane logowania w wywołaniu');
check($val('PhoneNumber') === '777888999' && $val('FirstName') === 'Anna' && $val('StreetName') === 'Lipowa' && $val('BuildingNumber') === '1', 'Odbiorca: telefon poprawiony w CRM, ulica i numer osobno');
check($val('SenderCompanyName') === 'Sklep Testowy' && $val('SenderPostCode') === '00-001' && $val('SenderPhoneNumber') === '444555666', 'Nadawca z ustawień');
check($val('SenderOrders') === 'Zam. 1234' && $val('TransferDescription') === 'Zamówienie 1234' && $val('PrintType') === '1', 'Tekst na etykiecie i numer zamówienia');
check($x->getElementsByTagNameNS('*', 'Insurance')->length === 0 && $x->getElementsByTagNameNS('*', 'CashOnDelivery')->length === 0, 'Bez wycofanych pól COD i ubezpieczenia');

$calls = [];
$r = $plugin->createShipment(['order' => $woo, 'parcel' => ['weight' => 1, 'x' => 30, 'y' => 30, 'z' => 30], 'service' => 'L',
    'label_format' => 'ZPL', 'point' => 'WS-999999-C3-01']);
$x->loadXML($calls[0]['xml']);
check($r['ok'] && $val('DestinationCode') === 'WS-999999-C3-01' && $val('BoxSize') === 'L' && $val('Format') === 'ZPL', 'Punkt i gabaryt wybrane ręcznie, etykieta ZPL');

$responses['GenerateLabelBusinessPackListTwo'] = soap('GenerateLabelBusinessPackListTwo', '<GenerateLabelBusinessPackListTwoResult><Err>104</Err><ErrDes>Brak DestinationCode</ErrDes></GenerateLabelBusinessPackListTwoResult>');
$r = $plugin->createShipment(['order' => $woo, 'parcel' => ['weight' => 1], 'service' => 'M', 'point' => '116263']);
check(!$r['ok'] && str_contains($r['message'], 'Brak DestinationCode (kod 104)'), 'Błąd ORLEN Paczka z kodem');
$calls = [];
$r = $plugin->createShipment(['order' => ['row' => [], 'payload' => ['billing' => ['first_name' => 'A', 'last_name' => 'B']]], 'parcel' => [], 'service' => 'M', 'point' => '116263']);
check(!$r['ok'] && str_contains($r['message'], 'telefonu') && $calls === [], 'Bez telefonu odbiorcy nie wysyłamy');
$r = $plugin->createShipment(['order' => ['row' => [], 'payload' => []], 'parcel' => [], 'service' => 'M', 'point' => '']);
check(!$r['ok'] && str_contains($r['message'], 'punkt odbioru') && $calls === [], 'Bez punktu nie wysyłamy');
$r = (new OrlenPaczkaPlugin())->withConfig(['partner_id' => 'a'])->createShipment(['order' => $woo, 'parcel' => [], 'service' => 'M']);
check(!$r['ok'] && str_contains($r['message'], 'dane nadawcy') && $calls === [], 'Bez danych nadawcy nie wysyłamy');
$responses['GenerateLabelBusinessPackListTwo'] = static fn() => throw new RuntimeException('timeout');
$r = $plugin->createShipment(['order' => $woo, 'parcel' => ['weight' => 1], 'service' => 'M', 'point' => '116263']);
check(!$r['ok'] && str_contains($r['message'], 'nieznany'), 'Brak odpowiedzi: ostrzeżenie przed zdublowaniem paczki');

// --- podjazd kuriera ---
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$responses['GenerateLabelBusinessPackListTwo'] = soap('GenerateLabelBusinessPackListTwo', '<GenerateLabelBusinessPackListTwoResult><Err>0</Err><ErrDes>saved</ErrDes><PackCode_RUCH>2100099999999</PackCode_RUCH><PackPrice>1099</PackPrice><PackPaid>true</PackPaid></GenerateLabelBusinessPackListTwoResult><LabelData>' . $label . '</LabelData>');
$responses['GetAvailablePickups'] = soap('GetAvailablePickups', "<GetAvailablePickupsResult><Err>0</Err><ErrDes>Success</ErrDes><Data>"
    . "<AvailablePickupDay><Date>{$tomorrow}</Date><MinReadyDate>{$tomorrow}T08:00:00+02:00</MinReadyDate><MaxPickupDate>{$tomorrow}T16:00:00+02:00</MaxPickupDate><MinimumInterval>120</MinimumInterval></AvailablePickupDay>"
    . "</Data></GetAvailablePickupsResult>");
$responses['CallPickupNew'] = soap('CallPickupNew', '<CallPickupNewResult><Err>0</Err><ErrDes>Success</ErrDes><Data>14681063</Data></CallPickupNewResult>');
$calls = [];
$r = $plugin->createShipment(['order' => $woo, 'parcel' => ['weight' => 1, 'x' => 20, 'y' => 30, 'z' => 15], 'service' => 'auto',
    'point' => '116263', 'handover' => 'courier', 'pickup_date' => $tomorrow]);
check($r['ok'] && $r['price'] === 10.99 && str_contains($r['message'], "Kurier zamówiony na {$tomorrow} 08:00–16:00 (zlecenie nr 14681063)"), 'Podjazd kuriera zamówiony po nadaniu');
check(array_column($calls, 'op') === ['GenerateLabelBusinessPackListTwo', 'GetAvailablePickups', 'CallPickupNew'], 'Kolejność: nadanie, przedziały, zamówienie kuriera');
$x->loadXML($calls[2]['xml']);
check($val('ReadyDate') === "{$tomorrow}T08:00:00" && $val('PickupDate') === "{$tomorrow}T16:00:00" && $val('string') === '2100099999999'
    && $val('PostCode') === '00-001' && $val('PartnerName') === 'Sklep Testowy', 'Zlecenie podjazdu: przedział, paczka i adres nadawcy');
$responses['CallPickupNew'] = soap('CallPickupNew', '<CallPickupNewResult><Err>301</Err><ErrDes>Brak dostępnych kurierów</ErrDes></CallPickupNewResult>');
$r = $plugin->createShipment(['order' => $woo, 'parcel' => ['weight' => 1], 'service' => 'M', 'point' => '116263', 'handover' => 'courier', 'pickup_date' => $tomorrow]);
check($r['ok'] && str_contains($r['message'], 'Nie zamówiono kuriera: ORLEN Paczka: Brak dostępnych kurierów'), 'Nieudany podjazd nie cofa nadania - komunikat');

// --- etykieta i anulowanie ---
$responses['LabelPrintDuplicateListTwo'] = soap('LabelPrintDuplicateListTwo', '<LabelPrintDuplicateListTwoResult><LabelPrintDuplicateListTwo><PackCode>2100012345678</PackCode><Err>0</Err><ErrDes>saved</ErrDes></LabelPrintDuplicateListTwo></LabelPrintDuplicateListTwoResult><LabelData>' . base64_encode('^XA^XZ') . '</LabelData>');
$calls = [];
$l = $plugin->getLabel('2100012345678', 'ZPL');
check($l['ok'] && $l['content'] === '^XA^XZ' && str_ends_with($l['filename'], '.zpl'), 'Etykieta ZPL');
$x->loadXML($calls[0]['xml']);
check($val('Format') === 'ZPL' && $val('string') === '2100012345678', 'Duplikat etykiety dla numeru paczki');
$plugin->getLabel('2100012345678', 'A4'); $x->loadXML(end($calls)['xml']);
check($val('Format') === 'PDF10', 'Etykieta PDF wg ustawień (10×15)');
$plugin->getLabel('2100012345678', 'LBL'); $x->loadXML(end($calls)['xml']);
$a4 = (new OrlenPaczkaPlugin())->withConfig(['pdf_format' => 'PDF'] + $cfg); $a4->getLabel('2100012345678', 'A4'); $y = new DOMDocument(); $y->loadXML(end($calls)['xml']);
check($val('Format') === 'PDF10' && $y->getElementsByTagNameNS('*', 'Format')->item(0)->textContent === 'PDF', 'LBL -> PDF10, A4 z ustawieniem PDF -> PDF');
$responses['LabelPrintDuplicateListTwo'] = soap('LabelPrintDuplicateListTwo', '<LabelPrintDuplicateListTwoResult><LabelPrintDuplicateListTwo><PackCode>1</PackCode><Err>106</Err><ErrDes>Brak paczki</ErrDes></LabelPrintDuplicateListTwo></LabelPrintDuplicateListTwoResult>');
check(!$plugin->getLabel('1')['ok'], 'Błąd etykiety zgłoszony');
$responses['PutCustomerPackCanceled'] = soap('PutCustomerPackCanceled', '<PutCustomerPackCanceledResult><Err>000</Err><ErrDes>saved</ErrDes><PackCode>2100012345678</PackCode></PutCustomerPackCanceledResult>');
check($plugin->cancelShipment('2100012345678')['ok'], 'Anulowanie paczki');
$responses['PutCustomerPackCanceled'] = soap('PutCustomerPackCanceled', '<PutCustomerPackCanceledResult><Err>210</Err><ErrDes>Paczka nadana - nie można anulować</ErrDes></PutCustomerPackCanceledResult>');
$c = $plugin->cancelShipment('2100012345678');
check(!$c['ok'] && str_contains($c['message'], 'nie można anulować'), 'Nadanej paczki nie anulujemy - komunikat ORLEN');

// --- lista punktów i wyszukiwarka ---
$pt = static fn(string $code, string $psd, string $type, string $street, string $city, string $zip, string $avail = 'T') =>
    "<LocationWithAllData2><DestinationCode>{$code}</DestinationCode><StreetName>{$street}</StreetName><BuildingNumber>6</BuildingNumber><City>{$city}</City>"
    . "<Zipcode>{$zip}</Zipcode><Province>x</Province><OpeningHours>Pn-Pt 8-20</OpeningHours><Location>Automat przy sklepie</Location><PSD>{$psd}</PSD>"
    . "<Available>{$avail}</Available><PointType>{$type}</PointType><NearestPoints><NearestPoint><Name>Inny 1</Name><Distance>10</Distance></NearestPoint></NearestPoints></LocationWithAllData2>";
$responses['GiveMeAllLocationWithAllDataWithZipCode'] = soap('GiveMeAllLocationWithAllDataWithZipCode', '<GiveMeAllLocationWithAllDataWithZipCodeResult>'
    . $pt('KL-895926-J2-55', '895926', 'APM', 'CHROBREGO', 'Jędrzejów', '28-300')
    . $pt('WA-116263-K1-02', '116263', 'PKN', 'MARSZAŁKOWSKA', 'Warszawa', '00-950')
    . $pt('WA-222222-K1-02', '222222', 'PPP', 'POLNA', 'Warszawa', '00-951', 'N')
    . '</GiveMeAllLocationWithAllDataWithZipCodeResult>');
$calls = [];
$s = $plugin->searchPoints('warszawa');
check($s['ok'] && count($s['points']) === 1 && $s['points'][0]['code'] === 'WA-116263-K1-02' && str_contains($s['points'][0]['label'], 'stacja ORLEN'), 'Wyszukiwanie po mieście (bez punktów niedostępnych)');
check(count($calls) === 1 && is_file($tmpPoints), 'Lista punktów pobrana raz i zapisana');
$s = $plugin->searchPoints('895926');
check($s['points'][0]['code'] === 'KL-895926-J2-55' && count($calls) === 1, 'Po numerze punktu, z zapisanej listy (bez ponownego pobierania)');
check($plugin->searchPoints('28-300 chrobrego')['points'][0]['code'] === 'KL-895926-J2-55', 'Po kodzie pocztowym i ulicy');
check($plugin->searchPoints('nieistniejace')['points'] === [], 'Brak wyników');
$sv = $plugin->listServices(['order' => $woo]);
check($sv['point']['value'] === 'WA-116263-K1-02' && str_contains($sv['point']['note'], 'MARSZAŁKOWSKA'), 'Punkt z zamówienia uzupełniony pełnym kodem i adresem z listy');
touch($tmpPoints, time() - 2 * 86400);
$responses['GiveMeAllLocationWithAllDataWithZipCode'] = static fn() => throw new RuntimeException('down');
check($plugin->searchPoints('warszawa')['ok'], 'Nieaktualna lista zostaje, gdy API nie odpowiada');

// --- śledzenie ---
check(OrlenPaczkaPlugin::statusCode(200) === 'PENDING' && OrlenPaczkaPlugin::statusCode(240) === 'IN_TRANSIT', 'Statusy: zaawizowana, w drodze');
check(OrlenPaczkaPlugin::statusCode(680) === 'RELEASED_FOR_DELIVERY' && OrlenPaczkaPlugin::statusCode(695) === 'AVAILABLE_FOR_PICKUP', 'W transporcie do punktu, czeka w punkcie');
check(OrlenPaczkaPlugin::statusCode(1000) === 'DELIVERED' && OrlenPaczkaPlugin::statusCode(709) === 'ISSUE', 'Odebrana, nieodebrana w terminie');
check(OrlenPaczkaPlugin::statusCode(690, 'POWROT') === 'ISSUE' && OrlenPaczkaPlugin::statusCode(1000, '2_POWROT') === 'RETURNED', 'Atrybut powrotu: w drodze do nadawcy, odebrana przez nadawcę');
check(OrlenPaczkaPlugin::statusCode(888) === null, 'Archiwizacja pomijana');
$responses['GiveMePackStatusFullHistoryList'] = soap('GiveMePackStatusFullHistoryList', '<GiveMePackStatusFullHistoryListResult>'
    . '<PackStatus><PackCode>2100012345678</PackCode><Trans>200</Trans><Trans_Des>Zaawizowana do PwR</Trans_Des><Data>2026-10-07T13:18:49.9237746Z</Data><Attribute /></PackStatus>'
    . '<PackStatus><PackCode>2100012345678</PackCode><Trans>690</Trans><Trans_Des>W kiosku</Trans_Des><Data>2026-10-08T09:45:23.1436745Z</Data><Attribute /></PackStatus>'
    . '<PackStatus><PackCode>2100012345678</PackCode><Trans>888</Trans><Trans_Des>Archiwizacja</Trans_Des><Data>2026-10-08T10:00:00Z</Data><Attribute /></PackStatus>'
    . '</GiveMePackStatusFullHistoryListResult>');
$calls = [];
$tr = $plugin->trackWaybills(['2100012345678', '2100000000000']);
check($tr['ok'] && array_map('strval', array_keys($tr['waybills'])) === ['2100012345678'] && count($tr['waybills']['2100012345678']) === 2, 'Historia paczki bez archiwizacji, nieznane numery pominięte');
check($tr['waybills']['2100012345678'][1] === ['code' => 'AVAILABLE_FOR_PICKUP', 'description' => 'W kiosku', 'occurredAt' => '2026-10-08T09:45:23+02:00'], 'Zdarzenie: kod, opis i czas polski bez „Z”');
$x->loadXML($calls[0]['xml']);
check($x->getElementsByTagNameNS('*', 'string')->length === 2, 'Jedno zapytanie dla wielu paczek');

// --- Uniwersalny Pusher: rejestracja, status, wyłączenie ---
$hook = static fn(string $op, string $status, string $err = '0', string $des = '') => soap($op, "<{$op}Result><Err>{$err}</Err><ErrDes>{$des}</ErrDes><Data><PartnerID>PARTNER01</PartnerID>"
    . "<Status>{$status}</Status><Annotation>Webhook {$status}</Annotation><AuthorizationType>OAuth2StaticToken</AuthorizationType><UserName /><Password>sekret-token</Password>"
    . "<NotificationUrl>https://crm.example.com/webhook_plugin.php?a=7&amp;k=abc</NotificationUrl></Data></{$op}Result>");
$responses['WebhookRegister'] = $hook('WebhookRegister', 'Active');
$calls = [];
$r = $plugin->client()->webhookRegister('https://crm.example.com/webhook_plugin.php?a=7', 'tok123');
check($r['ok'] && $r['pusher']['Status'] === 'Active' && !isset($r['pusher']['Password']), 'WebhookRegister: status z odpowiedzi, token nie wraca');
$x = new DOMDocument();
$x->loadXML($calls[0]['xml']);
$val = static fn(string $n) => $x->getElementsByTagNameNS('*', $n)->item(0)?->textContent;
check($val('AuthorizationType') === 'OAuth2StaticToken' && $val('Password') === 'tok123' && $val('NotificationUrl') === 'https://crm.example.com/webhook_plugin.php?a=7'
    && $val('PartnerID') === 'PARTNER01', 'WebhookRegister: token statyczny (Bearer) i adres powiadomień');
$responses['WebhookRegister'] = $hook('WebhookRegister', '', '12', 'Endpoint returned 401');
check(!$plugin->client()->webhookRegister('https://x', 't')['ok'], 'WebhookRegister: błąd z ErrDes');
$responses['WebhookStatus'] = $hook('WebhookStatus', 'Active');
$responses['WebhookUnregister'] = $hook('WebhookUnregister', 'Unregistered');
check($plugin->client()->webhookStatus()['pusher']['NotificationUrl'] === 'https://crm.example.com/webhook_plugin.php?a=7&k=abc', 'WebhookStatus: aktualny adres');
check($plugin->client()->webhookUnregister()['pusher']['Status'] === 'Unregistered', 'WebhookUnregister');

// --- Pusher: adres, token, status ---
check(OrlenPaczkaPlugin::pusherUrl('https://klubwoskarzy.pl/crm/', 12, str_repeat('a', 24)) === 'https://klubwoskarzy.pl/crm/webhook_plugin.php?a=12&k=' . str_repeat('a', 24), 'Adres z tokenem w parametrze k');
$longBase = 'https://' . str_repeat('x', 50) . '.pl/crm/public';
check(OrlenPaczkaPlugin::pusherUrl($longBase, 12, str_repeat('a', 24)) === $longBase . '/webhook_plugin.php?a=12', 'Za długi adres: bez parametru k (token tylko w nagłówku)');
check(OrlenPaczkaPlugin::requestToken(['headers' => ['Authorization' => 'Bearer abc']]) === 'abc', 'Token z nagłówka Bearer');
check(OrlenPaczkaPlugin::requestToken(['headers' => ['authorization' => 'Basic ' . base64_encode('user:p:ss')]]) === 'p:ss', 'Token jako hasło Basic');
check(OrlenPaczkaPlugin::requestToken(['headers' => [], 'query' => ['k' => 'zz']]) === 'zz' && OrlenPaczkaPlugin::requestToken([]) === '', 'Token z parametru k, brak tokenu');
check(OrlenPaczkaPlugin::splitStatus('200') === [200, ''] && OrlenPaczkaPlugin::splitStatus('100_2_POWROT') === [100, '2_POWROT']
    && OrlenPaczkaPlugin::splitStatus('1200_zwrot') === [1200, 'ZWROT'] && OrlenPaczkaPlugin::splitStatus('x') === [0, ''], 'Status z atrybutem');

// --- Pusher: włączanie z panelu integracji ---
$saved = [];
$ctx = ['account_id' => 7, 'base_url' => 'https://crm.example.com', 'pdo' => null, 'local' => false,
        'save' => static function (array $c) use (&$saved): void { $saved[] = $c; }];
$seenToken = null;
$responses['WebhookRegister'] = static function (string $xml) use (&$saved, &$seenToken, $hook): string {
    $seenToken = end($saved)['pusher_token'] ?? null;   // co było zapisane w chwili rejestracji
    return $hook('WebhookRegister', 'Active');
};
$calls = [];
$r = $plugin->adminAction('pusher_on', $ctx);
$cfgOn = end($saved);
check($r['ok'] && $cfgOn['pusher_active'] === '1' && preg_match('/^[a-f0-9]{24}$/', $cfgOn['pusher_token']) === 1, 'Włącz Pusher: token wygenerowany, Pusher aktywny');
check($seenToken === $cfgOn['pusher_token'], 'Token zapisany przed rejestracją (powiadomienie testowe przechodzi autoryzację)');
check($cfgOn['pusher_url'] === 'https://crm.example.com/webhook_plugin.php?a=7&k=' . $cfgOn['pusher_token'] && str_contains($calls[0]['xml'], 'webhook_plugin.php?a=7&amp;k='), 'Adres powiadomień z id konta');
$on = $plugin->withConfig($cfgOn);
check($on->pushActive() && array_keys($on->adminActions()) === ['pusher_status', 'pusher_on', 'pusher_off'] && !$plugin->pushActive(), 'Przyciski zależą od stanu Pushera');
$saved = [];
$on->adminAction('pusher_on', $ctx);
check(end($saved)['pusher_token'] === $cfgOn['pusher_token'], 'Ponowna rejestracja zostawia ten sam token');
$responses['WebhookRegister'] = $hook('WebhookRegister', '', '12', 'Endpoint returned 401');
$saved = [];
$r = $plugin->adminAction('pusher_on', $ctx);
check(!$r['ok'] && str_contains($r['message'], '401') && end($saved)['pusher_active'] === '', 'Odrzucona rejestracja: Pusher nieaktywny, błąd ORLEN w komunikacie');
check(!$plugin->adminAction('pusher_on', ['local' => true] + $ctx)['ok'], 'Tryb lokalny: nie da się włączyć');
check(!$plugin->adminAction('pusher_on', ['base_url' => 'https://twojsklep.pl/pase/public'] + $ctx)['ok'], 'Bez adresu CRM: nie da się włączyć');
check(!(new OrlenPaczkaPlugin())->withConfig([])->adminAction('pusher_on', $ctx)['ok'], 'Bez PartnerID: nie da się włączyć');
$responses['WebhookStatus'] = $hook('WebhookStatus', 'Unregistered');
$saved = [];
$r = $on->adminAction('pusher_status', $ctx);
check(!$r['ok'] && str_contains($r['message'], 'Unregistered') && end($saved)['pusher_active'] === '' && str_contains($r['message'], 'k=•••'), 'Status: wyrejestrowany w ORLEN = wyłączony w CRM, token zamaskowany');
$saved = [];
check($on->adminAction('pusher_off', $ctx)['ok'] && end($saved)['pusher_active'] === '', 'Wyłącz Pusher');

// --- Pusher: powiadomienie ---
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
$db->exec('CREATE TABLE shipments (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, courier_code TEXT, waybill_no TEXT, bl_order_id TEXT,
    order_ref TEXT, price REAL, status TEXT, label_link TEXT, request_payload TEXT, response_payload TEXT, created_at TEXT, updated_at TEXT,
    tracking_status TEXT, tracking_code TEXT, tracking_carrier TEXT, tracking_at TEXT, tracking_checked_at TEXT, tracking_events TEXT)');
$db->exec("INSERT INTO shipments (id, integration_id, courier_code, waybill_no, status) VALUES (1, 7, 'ORLEN Paczka', '2100012345678', 'created')");
$tok = $cfgOn['pusher_token'];
$req = static fn(array $body, array $headers = []) => ['method' => 'POST', 'headers' => $headers + ['authorization' => 'Bearer ' . $tok], 'query' => [],
    'body' => json_encode($body), 'account_id' => 7];
$ev = static fn(string $status, string $updated, int $pack = 2100012345678, ?int $prev = null) => ['PackCode' => $pack, 'PackCodePrev' => $prev, 'Status' => $status, 'Updated' => $updated, 'Operator' => 'ORLEN-PACZKA'];
$sh = static fn() => $db->query('SELECT * FROM shipments WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
check($on->handleWebhook($req($ev('200', '2000-01-01T00:00:00', 9999999999999)), $db)['status'] === 200 && OrlenPaczkaPlugin::lastPush($db, 7)['pack'] === '9999999999999', 'Powiadomienie testowe 9999999999999: 200 i zapamiętane');
check($sh()['tracking_status'] === null, 'Powiadomienie testowe niczego nie zmienia');
check($on->handleWebhook($req($ev('200', '2026-10-08T10:00:00'), ['authorization' => 'Bearer zly']), $db)['status'] === 401, 'Zły token: 401');
check($plugin->handleWebhook($req($ev('200', '2026-10-08T10:00:00')), $db)['status'] === 401, 'Pusher bez tokenu w konfiguracji: 401');
check($on->handleWebhook(['method' => 'GET'] + $req([]), $db)['status'] === 405, 'GET: 405');
check($on->handleWebhook(['body' => 'nie json'] + $req([]), $db)['status'] === 400, 'Nieczytelna treść: 400');
$r = $on->handleWebhook($req($ev('690', '2026-10-08T09:45:23.225694')), $db);
$row = $sh();
$evs = json_decode((string) $row['tracking_events'], true);
check($r['status'] === 200 && $row['tracking_status'] === 'ready_for_pickup' && $row['tracking_carrier'] === 'ORLEN', 'Status 690: paczka czeka w punkcie');
check($evs === [['at' => '2026-10-08T09:45:23+02:00', 'code' => 'AVAILABLE_FOR_PICKUP', 'desc' => 'W kiosku']], 'Zdarzenie z nazwą statusu i czasem polskim');
$on->handleWebhook($req($ev('690', '2026-10-08T09:45:23.225694')), $db);
check(count(json_decode((string) $sh()['tracking_events'], true)) === 1, 'Ponowione powiadomienie zapisane raz');
$on->handleWebhook($req($ev('690_POWROT', '2026-10-15T08:00:00', 2100099999999, 2100012345678)), $db);
$evs = json_decode((string) $sh()['tracking_events'], true);
check($sh()['tracking_status'] === 'issue' && str_contains(end($evs)['desc'], 'powrót do nadawcy'), 'Powrót pod nowym numerem: dopasowany po numerze pierwotnym');
$on->handleWebhook($req($ev('1200_ZWROT', '2026-10-16T08:00:00', 2100088888888, 2100012345678)), $db);
check($sh()['tracking_status'] === 'issue', 'Zwrot konsumencki (osobna paczka) nie zmienia etapu wysyłki');
$r = $on->handleWebhook($req($ev('1000', '2026-10-17T08:00:00', 2100077777777)), $db);
check($r['status'] === 200 && OrlenPaczkaPlugin::lastPush($db, 7)['matched'] === 0, 'Nieznana paczka: 200, bez zmian');
$r = $on->handleWebhook(['headers' => [], 'query' => ['k' => $tok]] + $req($ev('1000', '2026-10-16T12:00:00')), $db);
check($r['status'] === 200 && $sh()['tracking_status'] === 'delivered', 'Token w parametrze k (serwer uciął nagłówek): odebrana');

@unlink($tmpPoints);
echo "\nAll $checks checks passed.\n";
