<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Dane do faktury: oryginał vs poprawka z CRM, walidacja NIP, pobieranie danych firmy (GUS BIR 1.1 i Biała lista VAT)
// na atrapie sieci, wskaźnik na liście, kontrahent w wFirma, historia zmian bez danych klienta. SQLite, bez sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Repository\SettingsRepository;
use Pase\Repository\WooOrderRepository;
use Pase\Services\AuditTrail;
use Pase\Services\CompanyLookup;
use Pase\Services\InvoiceData;
use Pase\Services\OrderIndicators;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }
function fails(callable $fn, string $contains = ''): bool {
    try { $fn(); return false; } catch (RuntimeException $e) { return $contains === '' || str_contains($e->getMessage(), $contains); }
}

// --- NIP ---
check(InvoiceData::validNip('1234563218') && !InvoiceData::validNip('1234563219') && !InvoiceData::validNip('123') && !InvoiceData::validNip('0000000000'), 'NIP checksum');
check(InvoiceData::normalizeNip('PL 123-456-32-18') === '1234563218' && InvoiceData::formatNip('1234563218') === '123-456-32-18', 'NIP normalised and formatted');

// --- oryginał ze sklepu ---
$woo = ['billing' => ['first_name' => 'Anna', 'last_name' => 'Testowa', 'company' => '', 'address_1' => 'Testowa 77', 'address_2' => '',
        'postcode' => '00-950', 'city' => 'Testowo', 'country' => 'PL', 'email' => 'l@example.invalid', 'phone' => '500'],
    'meta_data' => [['key' => '_billing_nip', 'value' => '123-456-32-18']]];
$d = InvoiceData::resolve($woo);
check($d['name'] === 'Anna Testowa' && $d['nip'] === '123-456-32-18' && $d['wants'] && $d['requested'] === null && !$d['edited'], 'Woo: billing and NIP from meta, NIP means invoice');
$wooNo = $woo; $wooNo['meta_data'][] = ['key' => '_billing_invoice', 'value' => 'nie'];
check(InvoiceData::resolve($wooNo)['wants'] === false && InvoiceData::requested($wooNo) === false, 'Woo: explicit "no invoice"');
$allegro = ['buyer' => ['firstName' => 'Jan', 'lastName' => 'Nowak', 'email' => 'j@example.invalid', 'address' => ['street' => 'Domowa 1', 'city' => 'Poznań', 'postCode' => '60-001', 'countryCode' => 'PL']],
    'invoice' => ['required' => true, 'address' => ['street' => 'Biurowa 5', 'city' => 'Poznań', 'zipCode' => '61-001', 'countryCode' => 'PL',
        'company' => ['name' => 'Nowak Sp. z o.o.', 'taxId' => '7811234567']]]];
$d = InvoiceData::resolve($allegro);
check($d['company'] === 'Nowak Sp. z o.o.' && $d['address_1'] === 'Biurowa 5' && $d['nip'] === '7811234567' && $d['wants'] === true, 'Allegro: invoice address and tax id');

// --- formularz ---
$f = InvoiceData::fromForm(['wants' => '1', 'name' => '  Anna   Testowa ', 'company' => 'Pracownia Anna', 'address_1' => 'ul. Testowa 77',
    'postcode' => '00-950', 'city' => 'Testowo', 'state' => 'wielkopolskie', 'country' => 'pl', 'nip' => '1234563218']);
$saved = json_decode($f['json'], true);
check($f['errors'] === [] && $saved['name'] === 'Anna Testowa' && $saved['nip'] === '123-456-32-18' && $saved['country'] === 'PL', 'Form cleaned, NIP formatted');
check(InvoiceData::fromForm(['wants' => '1', 'name' => 'X', 'nip' => '1234563219', 'country' => 'PL'])['errors'] !== [], 'Wrong NIP rejected');
check(InvoiceData::fromForm(['wants' => '1', 'name' => 'X', 'nip' => 'DE123456789', 'country' => 'DE'])['errors'] === [], 'Foreign VAT id not checked as Polish NIP');
check(InvoiceData::fromForm(['wants' => '1', 'country' => 'PL'])['errors'] !== [], 'Invoice needs a name or company');
$d = InvoiceData::resolve($woo, $f['json']);
check($d['edited'] && $d['company'] === 'Pracownia Anna' && $d['state'] === 'wielkopolskie' && $d['email'] === 'l@example.invalid', 'CRM correction wins, contact kept');
$b = InvoiceData::billing($woo, $f['json']);
check($b['company'] === 'Pracownia Anna' && $b['first_name'] === 'Anna Testowa' && $b['nip'] === '123-456-32-18', 'Billing shape for wFirma and prints');
$off = InvoiceData::fromForm(['name' => 'Anna Testowa', 'country' => 'PL'])['json'];
check(InvoiceData::requested($woo, $off) === false && InvoiceData::resolve($woo, $off)['wants'] === false, 'Unticked "wants invoice" overrides the NIP');
check(InvoiceData::changedFields(null, $f['json']) === ['wants', 'name', 'company', 'address_1', 'postcode', 'city', 'state', 'country', 'nip']
    && InvoiceData::changedFields($f['json'], $off) === ['wants', 'company', 'address_1', 'postcode', 'city', 'state', 'nip'], 'Changed field names');

// --- wskaźnik „faktura” na liście ---
$ind = static fn(array $payload, ?string $local) => OrderIndicators::forOrder(['local_invoice' => $local], $payload, [])['invoice']['label'];
check($ind($woo, null) === 'orders.indicator.invoice_nip' && $ind($woo, $f['json']) === 'orders.indicator.invoice_required'
    && $ind($woo, $off) === 'orders.indicator.invoice_not_requested' && $ind($allegro, null) === 'orders.indicator.invoice_required', 'Invoice indicator follows the correction and Allegro');

// --- wskaźnik „paragon”: każde zamówienie bez faktury musi mieć paragon ---
$rec = static function (array $payload, ?string $local, bool $inv = false, bool $receipt = false, array $order = []): string {
    $order += ['local_invoice' => $local, 'pase_status' => 'completed'];
    return OrderIndicators::receipt($order, $payload, OrderIndicators::forOrder($order, $payload, [])['invoice'], $inv, $receipt)['label'];
};
check($rec($woo, $off) === 'orders.indicator.receipt_missing' && $rec(['status' => 'completed'], null) === 'orders.indicator.receipt_missing', 'No invoice wanted and no receipt: receipt missing');
check($rec($woo, $off, false, true) === 'orders.indicator.receipt_issued' && $rec($woo, null) === 'orders.indicator.receipt_not_needed'
    && $rec($allegro, null) === 'orders.indicator.receipt_not_needed' && $rec($woo, $off, true) === 'orders.indicator.receipt_not_needed', 'Receipt issued / invoice wanted or issued');
check($rec(['status' => 'cancelled'], null) === 'orders.indicator.receipt_inactive' && $rec([], null, false, false, ['pase_status' => 'refunded']) === 'orders.indicator.receipt_inactive', 'Cancelled or refunded: receipt not needed');

// --- GUS BIR 1.1 i Biała lista na atrapie sieci ---
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
$settings = new SettingsRepository($pdo);
$gusDane = '<root><dane><Regon>301234567</Regon><Nip>1234563218</Nip><StatusNip /><Nazwa>ŚWIECE ANNA TESTOWA</Nazwa><Wojewodztwo>WIELKOPOLSKIE</Wojewodztwo>'
    . '<Powiat>testowy</Powiat><Gmina>Wieś Testowa</Gmina><Miejscowosc>Testowo</Miejscowosc><KodPocztowy>00950</KodPocztowy><Ulica>ul. Testowa</Ulica>'
    . '<NrNieruchomosci>77</NrNieruchomosci><NrLokalu>2</NrLokalu><Typ>F</Typ><SilosID>1</SilosID><DataZakonczeniaDzialalnosci /><MiejscowoscPoczty>Testowo</MiejscowoscPoczty></dane></root>';
$mtom = static fn(string $action, string $inner) => "--uuid:1\r\nContent-Type: application/xop+xml;charset=utf-8;type=\"application/soap+xml\"\r\n\r\n"
    . '<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"><s:Body><' . $action . 'Response xmlns="http://CIS/BIR/PUBL/2014/07"><'
    . $action . 'Result>' . htmlspecialchars($inner, ENT_XML1) . '</' . $action . 'Result></' . $action . 'Response></s:Body></s:Envelope>' . "\r\n--uuid:1--";
$calls = [];
$net = static function (string $method, string $url, array $headers, ?string $body) use (&$calls, $mtom, $gusDane): array {
    $calls[] = [$method, $url, $headers, $body];
    if (str_contains($url, 'stat.gov.pl')) {
        if (str_contains((string) $body, 'Zaloguj>')) { return [200, $mtom('Zaloguj', str_contains((string) $body, 'GOODKEY') ? 'sid123' : '')]; }
        if (str_contains((string) $body, 'DaneSzukajPodmioty>')) {
            return [200, $mtom('DaneSzukajPodmioty', str_contains((string) $body, '1234563218') ? $gusDane
                : '<root><dane><ErrorCode>4</ErrorCode><ErrorMessagePl>Nie znaleziono podmiotu</ErrorMessagePl></dane></root>')];
        }
        return [200, $mtom('Wyloguj', 'true')];
    }
    if (str_contains($url, '1234563218')) {
        return [200, json_encode(['result' => ['subject' => ['name' => 'ŚWIECE ANNA TESTOWA', 'nip' => '1234563218', 'regon' => '301234567',
            'statusVat' => 'Czynny', 'workingAddress' => 'UL. TESTOWA 77/2, 00-950 TESTOWO']]])];
    }
    return [200, json_encode(['result' => ['subject' => null]])];
};
$lookup = new CompanyLookup($settings, $net);
check(fails(fn() => $lookup->byNip('123'), 'poprawny NIP') && $calls === [], 'Invalid NIP stopped before any request');
$c = $lookup->byNip('123-456-32-18');
check($c['source'] === 'Biała lista VAT' && $c['company'] === 'ŚWIECE ANNA TESTOWA' && $c['address_1'] === 'ul. Testowa 77/2'
    && $c['postcode'] === '00-950' && $c['city'] === 'Testowo' && str_contains($calls[0][1], 'wl-api.mf.gov.pl/api/search/nip/1234563218?date='), 'Without GUS key: White List (MF) used, address split');
check(fails(fn() => $lookup->byNip('1234563218', 'gus'), 'Brak klucza'), 'GUS asked without a key: clear message');
check(fails(fn() => $lookup->byNip('7811234563')) , 'Unknown company on the White List reported');
$settings->setMany([CompanyLookup::SETTING_KEY => 'GOODKEY']);
$calls = [];
$c = $lookup->byNip('1234563218');
check($c['source'] === 'GUS' && $c['company'] === 'ŚWIECE ANNA TESTOWA' && $c['address_1'] === 'ul. Testowa 77/2' && $c['postcode'] === '00-950'
    && $c['state'] === 'wielkopolskie' && $c['regon'] === '301234567', 'GUS: company, street with flat, postcode, voivodeship');
check(count($calls) === 3 && ($calls[1][2]['sid'] ?? '') === 'sid123' && str_contains($calls[0][3], '<wsa:Action>http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/Zaloguj</wsa:Action>')
    && str_contains($calls[1][3], '<dat:Nip>1234563218</dat:Nip>') && str_contains($calls[2][3], 'Wyloguj'), 'GUS session: login, search with sid, logout');
$calls = [];
check(fails(fn() => $lookup->byNip('7811234563', 'gus'), 'Nie znaleziono'), 'GUS: not found (error 4)');
check(fails(fn() => $lookup->byNip('7811234563')) && count(array_filter($calls, fn($c) => str_contains($c[1], 'wl-api'))) === 1, 'GUS miss falls back to the White List');
$settings->setMany([CompanyLookup::SETTING_KEY => 'BADKEY']);
$calls = [];
$c = $lookup->byNip('1234563218');
check($c['source'] === 'Biała lista VAT', 'Rejected GUS key: White List still answers');
$village = CompanyLookup::parseGus(str_replace(['<Ulica>ul. Testowa</Ulica>', '<NrLokalu>2</NrLokalu>', '<MiejscowoscPoczty>Testowo</MiejscowoscPoczty>'],
    ['<Ulica></Ulica>', '<NrLokalu></NrLokalu>', '<MiejscowoscPoczty>Wieś Testowa</MiejscowoscPoczty>'], $gusDane), '1234563218');
check($village['address_1'] === 'Testowo 77' && $village['city'] === 'Wieś Testowa', 'Village without streets: place + number, postal town');
$down = new CompanyLookup($settings, static fn() => [0, '']);
check(fails(fn() => $down->byNip('1234563218'), 'Brak połączenia'), 'No network: readable error');
$limit = new CompanyLookup(new SettingsRepository((function () { $p = new PDO('sqlite::memory:'); $p->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)'); return $p; })()),
    static fn() => [429, '{"code":"WL-100","message":"limit"}']);
check(fails(fn() => $limit->byNip('1234563218'), 'limit'), 'White List daily limit explained');

// --- zapis w zamówieniu, historia zmian, wFirma ---
AuditTrail::migrate($pdo);
$pdo->exec('CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY, order_number TEXT, customer_name TEXT, customer_email TEXT, payload TEXT, pase_status TEXT,
    lifecycle TEXT, local_items TEXT, local_edited_at TEXT, local_billing_email TEXT, local_billing_phone TEXT, local_invoice TEXT)');
$woo['shipping'] = ['first_name' => '', 'address_1' => ''];
$pdo->prepare('INSERT INTO woo_orders (woo_order_id, order_number, customer_name, payload) VALUES (1, ?, ?, ?)')->execute(['1001', 'Anna Testowa', json_encode($woo)]);
$repo = new WooOrderRepository($pdo);
check($repo->updateLocalInvoice(1, $f['json']) === true && $repo->find(1)['local_invoice'] === $f['json'], 'Correction saved on the order');
$event = $pdo->query('SELECT * FROM audit_events ORDER BY id DESC LIMIT 1')->fetch();
check($event['action'] === 'order.invoice_data_changed' && json_decode($event['after_json'], true) === ['invoice_fields' => ['wants', 'name', 'company', 'address_1', 'postcode', 'city', 'state', 'country', 'nip']]
    && !str_contains($event['after_json'] . $event['before_json'], 'Testowa'), 'History lists changed fields without customer data');
check($repo->updateLocalInvoice(1, $f['json']) === false, 'Saving the same data twice records nothing');
$contractor = new ReflectionMethod(\Pase\Services\WfirmaDocumentService::class, 'contractor');
$svc = (new ReflectionClass(\Pase\Services\WfirmaDocumentService::class))->newInstanceWithoutConstructor();
$k = $contractor->invoke($svc, $woo, $repo->find(1));
check($k['name'] === 'Pracownia Anna' && $k['nip'] === '1234563218' && $k['contact_person'] === 'Anna Testowa' && $k['address_1'] === 'ul. Testowa 77', 'wFirma contractor from the correction');
$woo2 = $woo; $pdo->prepare('UPDATE woo_orders SET local_invoice = ? WHERE woo_order_id = 1')->execute([InvoiceData::fromForm(['wants' => 1, 'name' => 'Anna Testowa',
    'company' => 'Pracownia Anna', 'address_1' => 'Biurowa 1', 'postcode' => '60-001', 'city' => 'Poznań', 'country' => 'PL', 'nip' => '1234563218'])['json']]);
$k = $contractor->invoke($svc, $woo2, $repo->find(1));
check(($k['different_contact_address'] ?? '') === '1' && $k['contact_street'] === 'Testowa 77' && $k['contact_city'] === 'Testowo', 'Parcel address kept as contact address when invoice address changed');
$k = $contractor->invoke($svc, $woo, ['local_invoice' => null] + $repo->find(1));
check($k['name'] === 'Anna Testowa' && $k['nip'] === '1234563218' && !isset($k['different_contact_address']), 'Without correction: shop data as before');
$repo->updateLocalInvoice(1, null);
$event = $pdo->query('SELECT * FROM audit_events ORDER BY id DESC LIMIT 1')->fetch();
check($repo->find(1)['local_invoice'] === null && json_decode($event['after_json'], true) === ['invoice_fields' => ['restored']], 'Restore shop data, logged');
$ctx = \Pase\Services\PrintTemplateRenderer::contextForOrder(['local_items' => null, 'pase_number' => 1, 'local_invoice' => $f['json']] + $repo->find(1), $woo, 'Lumio');
check(str_contains($ctx['adres_faktury'], 'Pracownia Anna') && $ctx['nip'] === '123-456-32-18' && str_contains($ctx['adres_dostawy'], 'Testowa 77'), 'Print templates: invoice address and {{nip}}');

echo "\nPASS: {$checks} invoice data checks\n";
