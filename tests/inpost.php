<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Wtyczka InPost (ShipX): test połączenia, usługi i Paczkomat z zamówienia, nadanie Paczkomatu i kuriera (z pobraniem),
// nadanie asynchroniczne (pending + resolveShipment), odrzucona oferta, etykieta, anulowanie, punkty, zlecenie odbioru,
// komunikaty błędów. Atrapa API, bez sieci i bazy.
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
use Pase\Plugin\Contract\CourierAsync;
use Pase\Support\SecretStore;
use PasePlugin\Inpost\InpostClient;
use PasePlugin\Inpost\InpostPlugin;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

/** Atrapa: klucz „METODA ścieżka-bez-zapytania" => [kod, treść] albo fn(body, url) => [kod, treść]; kolejka = lista odpowiedzi po kolei. */
$calls = [];
$responses = [];
InpostClient::$transport = static function (string $method, string $url, array $headers, ?string $body) use (&$calls, &$responses): array {
    $path = (string) parse_url($url, PHP_URL_PATH);
    $calls[] = ['method' => $method, 'url' => $url, 'path' => $path, 'headers' => $headers, 'body' => $body !== null ? json_decode($body, true) : null];
    $key = $method . ' ' . $path;
    $r = $responses[$key] ?? null;
    if ($r === null) { throw new RuntimeException("Nieoczekiwane wywołanie {$key}"); }
    if (is_array($r) && isset($r['queue'])) { $r = count($r['queue']) > 1 ? array_shift($responses[$key]['queue']) : $r['queue'][0]; }
    return is_callable($r) ? $r($body, $url) : $r;
};
InpostPlugin::$sleep = static function (int $ms): void {};
$last = static function (string $method, string $pathPart) use (&$calls): ?array {
    for ($i = count($calls) - 1; $i >= 0; $i--) {
        if ($calls[$i]['method'] === $method && str_contains($calls[$i]['path'], $pathPart)) { return $calls[$i]; }
    }
    return null;
};
$json = static fn(array $a): string => json_encode($a);

$cfg = ['api_token' => 'TOKEN-123', 'organization_id' => '4321', 'env' => 'production'];
$plugin = (new InpostPlugin())->withConfig($cfg);
$mf = $plugin->manifest();
check($plugin instanceof CourierAsync && $mf->type === 'inpost' && $mf->hasCapability('courier'), 'Wtyczka kurierska (asynchroniczna) o typie inpost');
check(in_array('api_token', $mf->secretKeys(), true) && SecretStore::isSecretConfigKey('api_token'), 'Token jest sekretem i rdzeń szyfruje go w configu konta');
check(str_contains($mf->iconHtml(), '<svg') && (new DOMDocument())->loadXML($mf->logo), 'Logo InPost to poprawny SVG');
check(!SecretStore::isSecretConfigKey('organization_id'), 'ID organizacji nie jest sekretem');

// --- test połączenia ---
$responses['GET /v1/organizations/4321'] = [200, $json(['id' => 4321, 'name' => 'Sklep Testowy', 'services' => ['inpost_locker_standard', 'inpost_courier_standard', 'inpost_letter_allegro']])];
$t = $plugin->testConnection();
$c = end($calls);
check($t['ok'] && str_contains($t['message'], 'Sklep Testowy') && str_contains($t['message'], 'inpost_locker_standard'), 'Test połączenia: organizacja i usługi');
check($c['headers']['Authorization'] === 'Bearer TOKEN-123' && str_starts_with($c['url'], InpostClient::URL_PRODUCTION), 'Token w nagłówku Bearer, produkcja');
$responses['GET /v1/organizations/4321'] = [401, $json(['status' => 401, 'error' => 'token_invalid', 'description' => 'Token is missing or invalid.'])];
$t = (new InpostPlugin())->withConfig(['env' => 'sandbox'] + $cfg)->testConnection();
check(!$t['ok'] && str_contains($t['message'], 'Nieprawidłowy token') && str_starts_with(end($calls)['url'], InpostClient::URL_SANDBOX), 'Zły token: czytelny błąd, sandbox');
check(!(new InpostPlugin())->withConfig([])->testConnection()['ok'], 'Bez tokenu test nie łączy się');

// --- komunikaty błędów walidacji ---
$m = InpostClient::errorMessage(400, ['status' => 400, 'error' => 'validation_failed', 'description' => 'There are some validation errors. Check details object for more info.',
    'details' => ['receiver' => ['phone' => ['invalid'], 'email' => ['required']], 'custom_attributes' => [['target_point' => ['does_not_exist']]]]]);
check(str_contains($m, 'receiver.phone: niepoprawne') && str_contains($m, 'receiver.email: wymagane') && str_contains($m, 'custom_attributes.target_point: nie istnieje'), 'Błąd walidacji spłaszczony do pól');
check(str_contains(InpostClient::errorMessage(502, [], '<html>Bad gateway</html>'), 'HTTP 502'), 'Błąd bez JSON: kod HTTP');

// --- gabaryty i pomocnicze ---
check(InpostPlugin::sizeFor(['x' => 30, 'y' => 20, 'z' => 5]) === 'small', '30×20×5 cm -> gabaryt A');
check(InpostPlugin::sizeFor(['x' => 15, 'y' => 37, 'z' => 63]) === 'medium', 'Wymiary w dowolnej kolejności -> B');
check(InpostPlugin::sizeFor(['x' => 40, 'y' => 38, 'z' => 50]) === 'large', '40×38×50 -> C');
check(InpostPlugin::sizeFor(['x' => 70, 'y' => 40, 'z' => 40]) === null, 'Za duża na Paczkomat');
check(InpostPlugin::pointCode(' kra010 ') === 'KRA010' && InpostPlugin::pointCode('WAW123M') === 'WAW123M' && InpostPlugin::pointCode('POP-KRA12') === 'POP-KRA12', 'Nazwy Paczkomatów');
check(InpostPlugin::pointCode('WS-116263-C3-01') === '' && InpostPlugin::pointCode('Kraków') === '', 'Punkt ORLEN i miasto to nie Paczkomat');
check(InpostPlugin::phone('+48 600-100-200') === '600100200' && InpostPlugin::postcode('31209') === '31-209', 'Telefon i kod pocztowy');
check(InpostPlugin::nonStandard(['x' => 130, 'y' => 10, 'z' => 10]) && !InpostPlugin::nonStandard(['x' => 60, 'y' => 40, 'z' => 30]), 'Paczka niestandardowa u kuriera');

// --- zamówienia ---
$allegro = ['row' => ['woo_order_id' => 501, 'pase_number' => 'CRM-501'], 'payload' => [
    'buyer' => ['email' => 'jan@example.com', 'firstName' => 'Jan', 'lastName' => 'Kowalski', 'phoneNumber' => '+48 600 100 200'],
    'delivery' => ['address' => ['firstName' => 'Jan', 'lastName' => 'Kowalski', 'street' => 'Długa 5/3', 'city' => 'Kraków', 'zipCode' => '31-000', 'phoneNumber' => '600100200'],
                   'pickupPoint' => ['id' => 'KRA010', 'name' => 'Paczkomat KRA010', 'address' => ['street' => 'Floriańska 1', 'zipCode' => '31-019', 'city' => 'Kraków']]],
    'payment' => ['type' => 'ONLINE'],
]];
$woo = ['row' => ['woo_order_id' => 777, 'pase_number' => '777', 'total' => '123.45'], 'payload' => [
    'payment_method' => 'cod', 'total' => '123.45',
    'billing' => ['email' => 'anna@example.com', 'phone' => '500 200 300', 'first_name' => 'Anna', 'last_name' => 'Nowak'],
    'shipping' => ['first_name' => 'Anna', 'last_name' => 'Nowak', 'address_1' => 'ul. Polna 12', 'postcode' => '00001', 'city' => 'Warszawa', 'company' => ''],
]];

$sv = $plugin->listServices(['order' => $allegro]);
check($sv['default'] === 'locker_auto' && $sv['point']['value'] === 'KRA010' && str_contains($sv['point']['note'], 'z zamówienia'), 'Zamówienie z paczkomatem: Paczkomat i punkt z zamówienia');
check(count(array_filter($sv['services'], fn($s) => $s['group'] === 'courier')) === 4 && isset($sv['groups']['locker'], $sv['handover']['options']['dispatch_order']), 'Usługi w grupach Paczkomat / Kurier, sposoby nadania');
$sv = $plugin->listServices(['order' => $woo]);
check($sv['default'] === 'courier_standard' && $sv['point']['value'] === '', 'Zamówienie bez paczkomatu: kurier standard');
check(InpostPlugin::codAmount($woo) === 123.45 && InpostPlugin::codAmount($allegro) === null, 'Pobranie tylko dla zamówień za pobraniem');

// --- nadanie Paczkomatu: created -> confirmed po chwili ---
$created = ['id' => 9001, 'status' => 'created', 'tracking_number' => null, 'service' => 'inpost_locker_standard', 'offers' => []];
$confirmed = ['id' => 9001, 'status' => 'confirmed', 'tracking_number' => '620000111222333444555666', 'service' => 'inpost_locker_standard',
    'custom_attributes' => ['target_point' => 'KRA010', 'sending_method' => 'parcel_locker'], 'selected_offer' => ['rate' => 13.99, 'status' => 'bought']];
$responses['POST /v1/organizations/4321/shipments'] = [201, $json($created)];
$responses['GET /v1/shipments/9001'] = ['queue' => [[200, $json($created)], [200, $json($confirmed)]]];
$r = $plugin->createShipment(['order' => $allegro, 'parcel' => ['weight' => 1, 'x' => 30, 'y' => 20, 'z' => 5], 'service' => 'locker_auto', 'point' => '', 'handover' => 'parcel_locker']);
$sent = $last('POST', '/shipments')['body'];
check($r['ok'] && empty($r['pending']) && $r['waybill_no'] === '620000111222333444555666' && $r['external_id'] === '9001', 'Paczkomat nadany, numer po dopytaniu');
check($sent['service'] === 'inpost_locker_standard' && $sent['parcels'] === [['template' => 'small']]
    && $sent['custom_attributes'] === ['sending_method' => 'parcel_locker', 'target_point' => 'KRA010'], 'Treść: usługa, gabaryt A, Paczkomat z zamówienia, sposób nadania');
check($sent['receiver'] === ['first_name' => 'Jan', 'last_name' => 'Kowalski', 'email' => 'jan@example.com', 'phone' => '600100200'], 'Odbiorca Paczkomatu: imię, nazwisko, e-mail, telefon (bez adresu)');
check($sent['reference'] === 'Zamówienie CRM-501' && !isset($sent['sender']) && !isset($sent['cod']), 'Numer referencyjny z numeru zamówienia, nadawca z organizacji, bez pobrania');
check($r['price'] === 13.99 && $r['courier_label'] === 'InPost Paczkomat' && $r['raw']['carrierId'] === 'INPOST', 'Cena oferty, przewoźnik do śledzenia');

// Paczkomat wpisany ręcznie i ceny z ustawień przy kliencie z fakturą zbiorczą (rate = null).
$debit = $confirmed; $debit['selected_offer'] = ['rate' => null];
$responses['POST /v1/organizations/4321/shipments'] = [201, $json($debit)];
$p2 = (new InpostPlugin())->withConfig($cfg + ['price_medium' => '14,50', 'text_on_label' => 'Zam. {nr}']);
$r = $p2->createShipment(['order' => $allegro, 'parcel' => ['weight' => 2, 'x' => 30, 'y' => 30, 'z' => 15], 'service' => 'locker_auto', 'point' => 'waw123m']);
$sent = $last('POST', '/shipments')['body'];
check($r['ok'] && $sent['custom_attributes']['target_point'] === 'WAW123M' && $sent['parcels'][0]['template'] === 'medium' && $r['price'] === 14.5, 'Paczkomat z formularza, gabaryt B, cena z ustawień');
check($sent['reference'] === 'Zam. CRM-501', 'Tekst etykiety z ustawień');
check($p2->quote(['service' => 'locker_auto', 'parcel' => ['weight' => 1, 'x' => 30, 'y' => 30, 'z' => 15]])['price'] === 14.5, 'Wycena z ustawień');
check(!$plugin->quote(['service' => 'courier_standard', 'parcel' => ['weight' => 1]])['ok'], 'Bez ceny w ustawieniach brak wyceny');

// Walidacja przed wysłaniem.
$n = count($calls);
check(str_contains($plugin->createShipment(['order' => $woo, 'parcel' => ['weight' => 1, 'x' => 10, 'y' => 10, 'z' => 10], 'service' => 'locker_small', 'point' => ''])['message'], 'Podaj Paczkomat'), 'Paczkomat bez punktu: komunikat');
check(str_contains($plugin->createShipment(['order' => $allegro, 'parcel' => ['weight' => 30, 'x' => 10, 'y' => 10, 'z' => 10], 'service' => 'locker_auto'])['message'], '25 kg'), 'Paczkomat ponad 25 kg');
check(str_contains($plugin->createShipment(['order' => $allegro, 'parcel' => ['weight' => 1, 'x' => 70, 'y' => 50, 'z' => 50], 'service' => 'locker_auto'])['message'], 'gabarycie C'), 'Za duża paczka na Paczkomat');
$noPhone = $woo; $noPhone['payload']['billing']['phone'] = '';
check(str_contains($plugin->createShipment(['order' => $noPhone, 'parcel' => ['weight' => 1, 'x' => 10, 'y' => 10, 'z' => 10], 'service' => 'courier_standard'])['message'], 'telefonu'), 'Brak telefonu odbiorcy');
check(count($calls) === $n, 'Błędy walidacji nie wysyłają nic do InPost');

// --- kurier z pobraniem, nadawcą i zleceniem odbioru ---
$cour = ['id' => 9002, 'status' => 'confirmed', 'tracking_number' => '520000011122233344455566', 'service' => 'inpost_courier_standard',
    'custom_attributes' => ['sending_method' => 'dispatch_order'], 'selected_offer' => ['rate' => 21.5]];
$responses['POST /v1/organizations/4321/shipments'] = [201, $json($cour)];
$responses['POST /v1/organizations/4321/dispatch_orders'] = [201, $json(['id' => 555, 'status' => 'new'])];
$p3 = (new InpostPlugin())->withConfig($cfg + ['dispatch_auto' => '1', 'sender_company' => 'Sklep Testowy', 'sender_street' => 'Testowa', 'sender_building' => '1a',
    'sender_postcode' => '30001', 'sender_city' => 'Kraków', 'sender_phone' => '+48 444 555 666', 'sender_email' => 'sklep@example.com']);
$r = $p3->createShipment(['order' => $woo, 'parcel' => ['weight' => 3.2, 'x' => 40, 'y' => 30, 'z' => 20], 'service' => 'courier_standard', 'handover' => 'dispatch_order', 'point' => 'KRA010']);
$sent = $last('POST', '/shipments')['body'];
check($r['ok'] && $r['waybill_no'] === '520000011122233344455566' && $r['courier_label'] === 'Kurier InPost', 'Kurier nadany od razu');
check($sent['parcels'][0]['dimensions'] === ['length' => 400, 'width' => 300, 'height' => 200, 'unit' => 'mm'] && $sent['parcels'][0]['weight'] === ['amount' => 3.2, 'unit' => 'kg'], 'Wymiary w mm, waga w kg');
check($sent['receiver']['address'] === ['street' => 'Polna', 'building_number' => '12', 'city' => 'Warszawa', 'post_code' => '00-001', 'country_code' => 'PL']
    && !isset($sent['receiver']['company_name']), 'Adres odbiorcy rozbity na ulicę i numer, bez pustej firmy');
check(!isset($sent['custom_attributes']['target_point']) && $sent['custom_attributes']['sending_method'] === 'dispatch_order', 'Kurier ignoruje Paczkomat z formularza');
check($sent['cod'] === ['amount' => 123.45, 'currency' => 'PLN'] && $sent['insurance'] === ['amount' => 123.45, 'currency' => 'PLN'], 'Pobranie i ubezpieczenie na kwotę zamówienia');
check($sent['sender']['company_name'] === 'Sklep Testowy' && $sent['sender']['address']['post_code'] === '30-001' && $sent['sender']['phone'] === '444555666', 'Nadawca z ustawień');
$d = $last('POST', '/dispatch_orders')['body'];
check($d['shipments'] === ['9002'] && $d['name'] === 'Sklep Testowy' && $d['address']['street'] === 'Testowa' && str_contains($r['message'], 'zlecenie 555'), 'Zlecenie odbioru po nadaniu');
$n = count($calls);
$responses['POST /v1/organizations/4321/shipments'] = [201, $json($cour)];
$p4 = (new InpostPlugin())->withConfig(['cod' => '0'] + $cfg);
$r = $p4->createShipment(['order' => $woo, 'parcel' => ['weight' => 3, 'x' => 40, 'y' => 30, 'z' => 20], 'service' => 'courier_standard', 'handover' => 'dispatch_order']);
check($r['ok'] && count(array_filter(array_slice($calls, $n), fn($c) => str_contains($c['path'], 'dispatch'))) === 0, 'Bez ustawienia nie zamawia kuriera');
check(!isset($last('POST', '/shipments')['body']['cod']), 'Pobranie wyłączone w ustawieniach');

// --- nadanie asynchroniczne: pending, potem resolveShipment ---
$responses['POST /v1/organizations/4321/shipments'] = [201, $json($created)];
$responses['GET /v1/shipments/9001'] = [200, $json($created)];
$r = $plugin->createShipment(['order' => $allegro, 'parcel' => ['weight' => 1, 'x' => 30, 'y' => 20, 'z' => 5], 'service' => 'locker_small']);
check($r['ok'] && $r['pending'] === true && $r['external_id'] === '9001' && $r['waybill_no'] === null, 'Bez numeru po dopytaniu: przesyłka oczekuje');
$x = $plugin->resolveShipment('9001');
check(!$x['ok'] && $x['pending'], 'Dalej bez numeru: nadal oczekuje');
$responses['GET /v1/shipments/9001'] = [200, $json($confirmed)];
$x = $plugin->resolveShipment('9001');
check($x['ok'] && !$x['pending'] && $x['waybill_no'] === '620000111222333444555666' && $x['external_id'] === '9001' && $x['courier_label'] === 'InPost Paczkomat', 'Dokończenie: numer przesyłki');
check($plugin->shipmentCost('9001') === ['amount' => 13.99], 'Faktyczny koszt z oferty');
$responses['GET /v1/shipments/9001'] = [500, ''];
check($plugin->resolveShipment('9001')['pending'], 'Błąd API przy dokańczaniu: spróbujemy później');

// Oferta niedostępna = odrzucenie.
$bad = ['id' => 9003, 'status' => 'offers_prepared', 'tracking_number' => null, 'offers' => [['id' => 1, 'status' => 'unavailable',
    'unavailability_reasons' => [['key' => 'insufficient_funds', 'message' => 'Brak środków na koncie']]]]];
$responses['POST /v1/organizations/4321/shipments'] = [201, $json($bad)];
$responses['GET /v1/shipments/9003'] = [200, $json($bad)];
$r = $plugin->createShipment(['order' => $allegro, 'parcel' => ['weight' => 1, 'x' => 30, 'y' => 20, 'z' => 5], 'service' => 'locker_small']);
check(!$r['ok'] && str_contains($r['message'], 'Brak środków'), 'Niedostępna oferta: powód z InPost');
$x = $plugin->resolveShipment('9003');
check(!$x['ok'] && !$x['pending'] && str_contains($x['message'], 'Brak środków'), 'Dokańczanie: odrzucenie z powodem');

// Błąd walidacji przy nadaniu.
$responses['POST /v1/organizations/4321/shipments'] = [400, $json(['status' => 400, 'error' => 'validation_failed', 'description' => 'There are some validation errors.',
    'details' => ['custom_attributes' => [['target_point' => ['does_not_exist']]]]])];
$r = $plugin->createShipment(['order' => $allegro, 'parcel' => ['weight' => 1, 'x' => 30, 'y' => 20, 'z' => 5], 'service' => 'locker_small', 'point' => 'XXX999']);
check(!$r['ok'] && str_contains($r['message'], 'target_point: nie istnieje') && isset($r['raw']['request']), 'Odrzucone nadanie: pole z błędem');

// --- etykieta ---
$responses['GET /v1/shipments/9001/label'] = static fn(?string $b, string $url): array => [200, str_contains($url, 'format=Zpl') ? '^XA^FO50,50^XZ' : '%PDF-1.4 etykieta'];
$l = $plugin->getLabel('9001', 'A4');
check($l['ok'] && $l['mime'] === 'application/pdf' && $l['filename'] === 'etykieta-inpost-9001.pdf' && str_contains(end($calls)['url'], 'format=Pdf&type=A6'), 'Etykieta PDF 10×15 (domyślnie)');
(new InpostPlugin())->withConfig(['pdf_type' => 'normal'] + $cfg)->getLabel('9001', 'A4');
check(str_contains(end($calls)['url'], 'type=normal'), 'Etykieta na A4 z ustawień');
$l = $plugin->getLabel('9001', 'ZPL');
check($l['ok'] && $l['filename'] === 'etykieta-inpost-9001.zpl' && $l['mime'] === 'application/octet-stream' && str_contains(end($calls)['url'], 'format=Zpl'), 'Etykieta ZPL');
$responses['GET /v1/shipments/9001/label'] = [404, $json(['status' => 404, 'error' => 'resource_not_found', 'description' => 'Resource not found'])];
check(!$plugin->getLabel('9001')['ok'], 'Błąd etykiety');

// --- anulowanie ---
$responses['DELETE /v1/shipments/9001'] = [204, ''];
check($plugin->cancelShipment('9001')['ok'], 'Anulowanie przed opłaceniem');
$responses['DELETE /v1/shipments/9001'] = [400, $json(['status' => 400, 'error' => 'invalid_action', 'description' => 'Invalid action'])];
$x = $plugin->cancelShipment('9001');
check(!$x['ok'] && str_contains($x['message'], 'Managerze Paczek'), 'Opłaconej nie anulujemy przez API - podpowiedź');

// --- punkty ---
$responses['GET /v1/points'] = [200, $json(['items' => [
    ['name' => 'KRA010', 'location_description' => 'Przy sklepie Żabka', 'address_details' => ['street' => 'Floriańska', 'building_number' => '1', 'post_code' => '31-019', 'city' => 'Kraków']],
    ['name' => 'KRA011', 'address' => ['line1' => 'Długa 2', 'line2' => '31-000 Kraków']],
]])];
$s = $plugin->searchPoints('Kraków');
parse_str((string) parse_url(end($calls)['url'], PHP_URL_QUERY), $q);
check($s['ok'] && $s['points'][0]['code'] === 'KRA010' && str_contains($s['points'][0]['label'], 'Floriańska 1, 31-019 Kraków — Przy sklepie') && $q['city'] === 'Kraków' && $q['type'] === 'parcel_locker', 'Szukanie po mieście');
check($s['points'][1]['label'] === 'Długa 2, 31-000 Kraków', 'Adres punktu z line1/line2');
$plugin->searchPoints('31019');
parse_str((string) parse_url(end($calls)['url'], PHP_URL_QUERY), $q);
check(($q['post_code'] ?? '') === '31-019', 'Szukanie po kodzie pocztowym');
$plugin->searchPoints('kra010');
parse_str((string) parse_url(end($calls)['url'], PHP_URL_QUERY), $q);
check(($q['name'] ?? '') === 'KRA010', 'Szukanie po nazwie Paczkomatu');
$n = count($calls);
check($plugin->searchPoints('k')['points'] === [] && count($calls) === $n, 'Za krótkie zapytanie nie pyta API');

echo "\nAll {$checks} InPost checks passed.\n";
