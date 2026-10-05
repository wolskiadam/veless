<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Body POST /sale/product-offers z formularza „Oferta Allegro" i sprawdzenie pól wymaganych przez Allegro. Bez sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
define('PASE_ROOT', dirname(__DIR__));
require dirname(__DIR__) . '/public/admin/_offer_allegro_params.php';
use Pase\Services\AllegroOfferPayload;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

// Parametry kategorii jak z GET /sale/categories/{id}/parameters.
$params = [
    ['id' => '11323', 'name' => 'Stan', 'type' => 'dictionary', 'required' => true, 'options' => ['describesProduct' => false],
     'dictionary' => [['id' => '11323_1', 'value' => 'Nowy'], ['id' => '11323_2', 'value' => 'Używany']], 'restrictions' => ['multipleChoices' => false]],
    ['id' => '225693', 'name' => 'EAN (GTIN)', 'type' => 'string', 'required' => false, 'requiredForProduct' => false, 'options' => ['describesProduct' => true], 'restrictions' => []],
    ['id' => '4000', 'name' => 'Zapach', 'type' => 'dictionary', 'required' => false, 'requiredForProduct' => true, 'options' => ['describesProduct' => true],
     'dictionary' => [['id' => '4000_1', 'value' => 'Mięta'], ['id' => '4000_2', 'value' => 'Wanilia']], 'restrictions' => ['multipleChoices' => true]],
    ['id' => '5000', 'name' => 'Waga produktu', 'type' => 'float', 'unit' => 'g', 'required' => false, 'options' => ['describesProduct' => true], 'restrictions' => []],
    ['id' => '6000', 'name' => 'Czas palenia', 'type' => 'integer', 'required' => false, 'options' => ['describesProduct' => true], 'restrictions' => ['range' => true]],
];

$form = [
    'title' => 'After Eight Sojowa Świeca w Pudełku', 'price' => '39,99', 'stock' => '3', 'category_id' => '257931',
    'ean' => '5901234123457', 'signature' => 'SW-AE-01', 'handling' => 'P2D',
    'params' => ['11323' => '11323_1', '4000' => ['4000_1', '4000_2', 'bogus'], '5000' => '180,5', '6000' => ['from' => '30', 'to' => '40']],
    'images' => ['https://example.com/a.jpg', 'https://example.com/b.jpg'], 'description' => '<h2>Opis</h2><p>Świeca</p>',
    'shipping_rate' => 'rate-1', 'return_policy' => 'ret-1', 'implied_warranty' => 'imp-1', 'warranty' => '',
    'invoice' => 'VAT', 'vat_rate' => '23.00', 'producer' => 'prod-1', 'person' => '',
    'safety_mode' => 'text', 'safety_text' => 'Nie zostawiaj palącej się świecy bez nadzoru.',
    'province' => 'MAZOWIECKIE', 'city' => 'Warszawa', 'post_code' => '00-001',
];
['payload' => $p, 'errors' => $errs] = AllegroOfferPayload::build($form, $params);
check($errs === [], 'complete form has no errors');
check($p['productSet'][0]['product']['category'] === ['id' => '257931'] && $p['category'] === ['id' => '257931'], 'category on product and offer');
check($p['parameters'] === [['id' => '11323', 'valuesIds' => ['11323_1']]], 'condition (Stan) is an offer parameter');
$pp = $p['productSet'][0]['product']['parameters'];
check($pp[0] === ['id' => '225693', 'values' => ['5901234123457']], 'EAN from the EAN field goes into the EAN (GTIN) product parameter');
check($pp[1] === ['id' => '4000', 'valuesIds' => ['4000_1', '4000_2']], 'multiple choice dictionary keeps known values only');
check($pp[2] === ['id' => '5000', 'values' => ['180.5']], 'float with comma converted to dot');
check($pp[3] === ['id' => '6000', 'rangeValue' => ['from' => '30', 'to' => '40']], 'range parameter');
check($p['images'] === $form['images'] && $p['productSet'][0]['product']['images'] === $form['images'], 'images as plain URL strings');
check($p['sellingMode']['price'] === ['amount' => '39.99', 'currency' => 'PLN'], 'price with comma');
check($p['stock'] === ['available' => 3, 'unit' => 'UNIT'], 'stock');
check($p['delivery'] === ['shippingRates' => ['id' => 'rate-1'], 'handlingTime' => 'P2D'], 'delivery');
check($p['afterSalesServices'] === ['returnPolicy' => ['id' => 'ret-1'], 'impliedWarranty' => ['id' => 'imp-1']], 'after sales without optional warranty');
check($p['payments'] === ['invoice' => 'VAT'], 'invoice');
check($p['taxSettings'] === ['subject' => 'GOODS', 'rates' => [['rate' => '23.00', 'countryCode' => 'PL']]], 'VAT rate');
check($p['productSet'][0]['responsibleProducer'] === ['id' => 'prod-1'] && !isset($p['productSet'][0]['responsiblePerson']), 'GPSR producer, no person');
check($p['productSet'][0]['safetyInformation'] === ['type' => 'TEXT', 'description' => 'Nie zostawiaj palącej się świecy bez nadzoru.'], 'safety information');
check($p['location'] === ['countryCode' => 'PL', 'province' => 'MAZOWIECKIE', 'city' => 'Warszawa', 'postCode' => '00-001'], 'location');
check($p['external'] === ['id' => 'SW-AE-01'], 'signature is external.id, not EAN');
check($p['publication'] === ['status' => 'INACTIVE'], 'inactive unless asked to activate');
check(!isset($p['productSet'][0]['product']['id']), 'no product.id: Allegro matches catalog products itself');

$full = AllegroOfferPayload::build(['warranty' => 'war-1', 'person' => 'per-1', 'activate' => true, 'invoice' => 'VAT'] + $form, $params)['payload'];
check($full['afterSalesServices']['warranty'] === ['id' => 'war-1'] && $full['productSet'][0]['responsiblePerson'] === ['id' => 'per-1'], 'optional warranty and responsible person');
check($full['publication'] === ['status' => 'ACTIVE'], 'activate');
check(!isset(AllegroOfferPayload::build(['invoice' => 'NO_INVOICE'] + $form, $params)['payload']['taxSettings']), 'no VAT rate without VAT invoice');
check(AllegroOfferPayload::build(['province' => '', 'city' => '', 'post_code' => ''] + $form, $params)['payload']['location'] === ['countryCode' => 'PL'], 'empty location -> country only');
check(AllegroOfferPayload::build(['safety_mode' => 'none', 'safety_text' => ''] + $form, $params)['payload']['productSet'][0]['safetyInformation'] === ['type' => 'NO_SAFETY_INFORMATION'], 'no safety information declaration');

// Braki.
$errs = AllegroOfferPayload::build([], $params)['errors'];
foreach (['tytuł', 'kategorię', 'Cena', 'sztuk', 'zdjęcie', 'opis', '„Stan"', '„Zapach"', 'cennik', 'zwrotów', 'reklamacji', 'faktury', 'producenta', 'bezpieczeństwie'] as $needle) {
    check((bool) array_filter($errs, static fn($e) => str_contains($e, $needle)), "empty form reports: {$needle}");
}
check(!array_filter($errs, static fn($e) => str_contains($e, 'Waga')), 'optional parameter not reported');
check(in_array('Wybierz najniższą kategorię (liść) — w tej są jeszcze podkategorie.', AllegroOfferPayload::build($form, $params, false)['errors'], true), 'non-leaf category rejected');
check((bool) array_filter(AllegroOfferPayload::build(['title' => str_repeat('a', 76)] + $form, $params)['errors'], static fn($e) => str_contains($e, '75')), 'title over 75 characters');
check((bool) array_filter(AllegroOfferPayload::build(['post_code' => '00001'] + $form, $params)['errors'], static fn($e) => str_contains($e, '00-000')), 'bad post code');
check((bool) array_filter(AllegroOfferPayload::build(['params' => ['11323' => 'bogus']] + $form, $params)['errors'], static fn($e) => str_contains($e, '„Stan"')), 'unknown dictionary value counts as empty');
check(AllegroOfferPayload::build(['ean' => '', 'params' => ['225693' => '123'] + $form['params']] + $form, $params)['payload']['productSet'][0]['product']['parameters'][0]['values'] === ['123'], 'EAN parameter typed directly wins');

// Tytuł: co najmniej 3 słowa (jak w formularzu Allegro).
check(in_array('Tytuł musi mieć co najmniej 3 słowa.', AllegroOfferPayload::build(['title' => '5905080581898'] + $form, $params)['errors'], true), 'title with fewer than 3 words rejected');

// Produkt z Katalogu Allegro: product.id, bez parametrów produktu (bierze je Allegro), parametry oferty zostają.
['payload' => $cp, 'errors' => $cerr] = AllegroOfferPayload::build(['catalog_product_id' => 'uuid-1', 'params' => ['11323' => '11323_1']] + $form, $params);
check($cerr === [], 'catalog product: required product parameters not needed');
check($cp['productSet'][0]['product'] === ['id' => 'uuid-1'] && $cp['parameters'] === [['id' => '11323', 'valuesIds' => ['11323_1']]], 'catalog product sent by id, offer parameters kept');

// Wiele wartości, własna wartość słownika, warunki displayedIf / requiredIf.
$extra = [
    ['id' => '7000', 'name' => 'Kod taryfy celnej', 'type' => 'string', 'required' => false, 'options' => ['describesProduct' => true], 'restrictions' => ['allowedNumberOfValues' => 3]],
    ['id' => '8000', 'name' => 'Marka', 'type' => 'dictionary', 'required' => false, 'options' => ['describesProduct' => true, 'customValuesEnabled' => true, 'ambiguousValueId' => '8000_0'],
     'dictionary' => [['id' => '8000_0', 'value' => 'inna'], ['id' => '8000_1', 'value' => 'Yankee']], 'restrictions' => []],
    ['id' => '9000', 'name' => 'Rodzaj', 'type' => 'dictionary', 'required' => false, 'options' => ['describesProduct' => true],
     'dictionary' => [['id' => '9000_1', 'value' => 'Zapachowa'], ['id' => '9000_2', 'value' => 'Dekoracyjna']], 'restrictions' => []],
    ['id' => '9100', 'name' => 'Zapach świecy', 'type' => 'string', 'required' => false, 'options' => ['describesProduct' => true], 'restrictions' => [],
     'displayedIf' => ['parameters' => [['id' => '9000', 'oneOfValueIds' => ['9000_1']]]], 'requiredIf' => ['parameters' => [['id' => '9000', 'oneOfValueIds' => ['9000_1']]]]],
];
$all = array_merge($params, $extra);
$vals = $form['params'] + ['7000' => ['3406.10', ' ', '3406.90', '3406.10', 'x4'], '9000' => '9000_2', '9100' => 'mięta'];
$mb = AllegroOfferPayload::build(['params' => $vals, 'params_custom' => ['8000' => 'Lumio']] + $form, $all)['payload'];
$pp = $mb['productSet'][0]['product']['parameters'];
$byId = array_column($pp, null, 'id');
// „Kod taryfy celnej" (7000 tutaj, rozpoznany po nazwie) to parametr oferty, nie produktu.
check(array_column($mb['parameters'], null, 'id')['7000'] === ['id' => '7000', 'values' => ['3406.10', '3406.90', 'x4']] && !isset($byId['7000']), 'multi-value parameter: empty and duplicate values dropped, up to allowedNumberOfValues');
check($byId['8000'] === ['id' => '8000', 'valuesIds' => ['8000_0'], 'values' => ['Lumio']], 'custom dictionary value with ambiguous value id');
check(!isset($byId['9100']), 'parameter hidden by displayedIf is not sent');
$errs = AllegroOfferPayload::build(['params' => ['9000' => '9000_1'] + $form['params']] + $form, $all)['errors'];
check((bool) array_filter($errs, static fn($e) => str_contains($e, '„Zapach świecy"')), 'requiredIf makes parameter required when condition met');
check(!array_filter(AllegroOfferPayload::build(['params' => ['9000' => '9000_2'] + $form['params']] + $form, $all)['errors'], static fn($e) => str_contains($e, 'Zapach świecy')), 'requiredIf not met -> optional');
check(AllegroOfferPayload::parameterValue(['id' => '1', 'type' => 'integer'], '1 200') === ['id' => '1', 'values' => ['1200']], 'integer with space');

// VAT: przedmiot, zwolnienie, stawki dla krajów.
$tp = AllegroOfferPayload::build(['vat' => ['PL' => '23.00', 'CZ' => '21.00', 'XX' => '5.00'], 'tax_subject' => 'GOODS', 'tax_exemption' => 'EXCISE_GOODS'] + $form, $params)['payload'];
check($tp['taxSettings'] === ['subject' => 'GOODS', 'exemption' => 'EXCISE_GOODS', 'rates' => [['rate' => '21.00', 'countryCode' => 'CZ'], ['rate' => '23.00', 'countryCode' => 'PL']]], 'tax settings with rates per country');
$tax = AllegroOfferPayload::taxOptions([
    'subjects' => [['label' => 'Towar', 'value' => 'GOODS'], ['label' => 'Wybierz', 'value' => null]],
    'rates' => [['countryCode' => 'PL', 'values' => [['label' => '23%', 'value' => '23.00'], ['label' => 'Poza VAT / NP', 'value' => 'OUT_OF_SCOPE_OF_VAT']]],
                ['countryCode' => 'CZ', 'values' => [['label' => '21%', 'value' => '21.00']]]],
    'exemptions' => [['label' => 'Wyroby akcyzowe', 'value' => 'EXCISE_GOODS']],
]);
check($tax === ['subjects' => ['GOODS' => 'Towar'], 'exemptions' => ['EXCISE_GOODS' => 'Wyroby akcyzowe'],
    'rates' => ['PL' => ['23.00' => '23%', 'OUT_OF_SCOPE_OF_VAT' => 'Poza VAT / NP'], 'CZ' => ['21.00' => '21%']]], 'tax settings response parsed per country');
check(AllegroOfferPayload::taxOptions(['rates' => [['value' => '8.00', 'percentage' => '8', 'countryCode' => 'PL']]])['rates'] === ['PL' => ['8.00' => '8%']], 'flat tax rates list still parsed');
$th = renderTaxFields($tax, ['vat' => ['CZ' => '21.00'], 'tax_subject' => 'GOODS']);
foreach (['Czechy', 'Słowacja', 'Polska', 'Węgry', 'Litwa', 'Przedmiot oferty', 'Podstawa wyłączenia z VAT'] as $n) {
    check(str_contains($th, $n), "tax fields: {$n}");
}
check(str_contains($th, '<option value="21.00" selected>21%</option>') && str_contains($th, '<option value="GOODS" selected>Towar</option>'), 'tax fields keep selections');

// Formularz parametrów: wszystkie parametry kategorii, wg typu.
$html = renderOfferParams($all, null, ['Zapach' => 'Mięta', 'Waga produktu' => '180'], '5901234123457');
check(str_contains($html, '<option value="11323_1" selected>Nowy</option>'), 'Stan defaults to Nowy');
check(strpos($html, 'Parametry oferty') < strpos($html, 'data-pid="11323"') && strpos($html, 'data-pid="11323"') < strpos($html, 'Parametry produktu'), 'offer parameters (Stan) before product parameters');
check(str_contains($html, 'name="params[225693]"') && str_contains($html, 'value="5901234123457"'), 'EAN shown as parameter, prefilled from product');
check(str_contains($html, 'value="4000_1" checked> Mięta'), 'multi-choice dictionary as checkboxes, prefilled from attribute');
check(str_contains($html, 'name="params[5000]"') && str_contains($html, 'value="180"') && str_contains($html, '<span class="unit">g</span>'), 'number with unit suffix');
check(str_contains($html, 'params[6000][from]') && str_contains($html, 'params[6000][to]'), 'range rendered as from/to');
check(str_contains($html, 'name="params[7000][]"') && str_contains($html, 'data-max="3"') && str_contains($html, 'Dodaj kolejną wartość'), 'multi-value field with add button');
check(str_contains($html, 'name="params_custom[8000]"'), 'custom value input for dictionaries that allow it');
check(str_contains($html, 'data-displayed-if=') && str_contains($html, 'data-required-if='), 'conditions passed to the page script');
check(substr_count($html, '(opcjonalnie)') === count($all), 'every parameter has an optional marker (hidden when required)');
check(!str_contains($html, '<details'), 'optional parameters are not collapsed');
$long = ['id' => '1', 'name' => 'Kolor', 'type' => 'dictionary', 'dictionary' => array_map(static fn($i) => ['id' => "1_$i", 'value' => "v$i"], range(1, 40))];
check(str_contains(renderOfferParams([$long], null, []), 'prm-filter'), 'long dictionary gets a search box');
$dep = ['id' => '2', 'name' => 'Model', 'type' => 'dictionary', 'options' => ['dependsOnParameterId' => '1'], 'dictionary' => [['id' => '2_1', 'value' => 'A', 'dependsOnValueIds' => ['1_1']]]];
check(str_contains(renderOfferParams([$dep], null, []), 'data-depends-on="1"') && str_contains(renderOfferParams([$dep], null, []), 'data-depends="1_1"'), 'dependent dictionary values marked');
$html = renderOfferParams($params, ['11323' => '11323_2', '4000' => ['4000_2']], ['Zapach' => 'Mięta']);
check(str_contains($html, '<option value="11323_2" selected>') && str_contains($html, 'value="4000_2" checked') && !str_contains($html, 'value="4000_1" checked'), 'saved values win over prefill');
check(str_contains(renderOfferParams([['id' => '1', 'name' => '<b>x</b>', 'type' => 'string', 'required' => true]], null, []), '&lt;b&gt;x&lt;/b&gt;'), 'parameter names escaped');

// Opis blokowy: sanityzacja HTML do tagów Allegro, sekcje (1-2 elementy), zdjęcia z opisu w galerii.
check(AllegroOfferPayload::sanitizeHtml('<h3>T</h3><div>Ala <strong>ma</strong> <span>kota</span></div><script>alert(1)</script><p onclick="x">t</p>') === '<h2>T</h2><p>Ala <b>ma</b> kota</p><p>t</p>', 'description HTML reduced to Allegro tags');
check(AllegroOfferPayload::sanitizeHtml('<ul><li>a</li></ul>luźny') === '<ul><li>a</li></ul><p>luźny</p>', 'lists kept, loose text wrapped');
$sec = AllegroOfferPayload::descriptionSections(['description_sections' => json_encode([
    ['items' => [['type' => 'TEXT', 'content' => '<h1>A</h1>'], ['type' => 'IMAGE', 'url' => 'https://x/1.jpg'], ['type' => 'TEXT', 'content' => 'nadmiar']]],
    ['items' => [['type' => 'IMAGE', 'url' => 'javascript:alert(1)']]],
    ['items' => [['type' => 'TEXT', 'content' => '<p> </p>']]],
])]);
check(count($sec) === 1 && count($sec[0]['items']) === 2 && $sec[0]['items'][1]['url'] === 'https://x/1.jpg', 'sections: max 2 items, bad image and empty sections dropped');
check(AllegroOfferPayload::descriptionSections(['description' => '<p>Stary</p>']) === [['items' => [['type' => 'TEXT', 'content' => '<p>Stary</p>']]]], 'plain description becomes one text section');
$bf = $form; $bf['description_sections'] = [['items' => [['type' => 'IMAGE', 'url' => 'https://x/opis.jpg'], ['type' => 'TEXT', 'content' => '<p>x</p>']]]];
$bo = AllegroOfferPayload::build($bf, $params)['payload'];
check(in_array('https://x/opis.jpg', $bo['images'], true) && $bo['description']['sections'][0]['items'][0]['type'] === 'IMAGE', 'description images added to gallery');
$bf['description_sections'] = []; $bf['description'] = '';
check(in_array('Dodaj opis oferty.', AllegroOfferPayload::build($bf, $params)['errors'], true), 'empty description rejected');

// Opcje wystawienia: szkic, natychmiast, w terminie (czas polski -> UTC).
$now = new DateTimeImmutable('2026-09-26 12:00', new DateTimeZone('Europe/Warsaw'));
check(AllegroOfferPayload::publication(['publish_mode' => 'draft'], $now) === [['status' => 'INACTIVE'], ''], 'draft = INACTIVE');
check(AllegroOfferPayload::publication(['publish_mode' => 'now'], $now) === [['status' => 'ACTIVE'], ''], 'now = ACTIVE');
check(AllegroOfferPayload::publication(['publish_mode' => 'scheduled', 'publish_date' => '2026-12-01', 'publish_time' => '10:30'], $now)[0]
    === ['status' => 'ACTIVE', 'startingAt' => '2026-12-01T09:30:00Z'], 'scheduled start converted to UTC (winter)');
check(AllegroOfferPayload::publication(['publish_mode' => 'scheduled', 'publish_date' => '2026-10-01', 'publish_time' => '10:30'], $now)[0]['startingAt'] === '2026-10-01T08:30:00Z', 'scheduled start converted to UTC (summer)');
check(AllegroOfferPayload::publication(['publish_mode' => 'scheduled', 'publish_date' => '2026-09-01', 'publish_time' => '10:30'], $now)[1] === 'Data wystawienia musi być w przyszłości.', 'past date rejected');
check(AllegroOfferPayload::publication(['publish_mode' => 'scheduled'], $now)[1] === 'Podaj datę i godzinę wystawienia.', 'missing date rejected');

// Szablony pól: tylko pola szablonu, kategoria razem z parametrami (bez EAN), zapis w bazie.
use Pase\Repository\AllegroFieldTemplateRepository as Tpl;
$tf = ['title' => 'X', 'price' => '1', 'shipping_rate' => 'r1', 'invoice' => 'VAT', 'vat' => ['PL' => '23.00'], 'publish_mode' => 'now',
       'category_id' => '257931', 'params' => ['11323' => '11323_1', '225693' => '590'], 'params_custom' => []];
$d1 = Tpl::fromForm($tf, false);
check(!isset($d1['title'], $d1['price'], $d1['category_id']) && $d1['shipping_rate'] === 'r1' && $d1['vat'] === ['PL' => '23.00'], 'template keeps only template fields');
$d2 = Tpl::fromForm($tf, true);
check($d2['category_id'] === '257931' && !isset($d2['params']['225693']), 'template with category drops EAN');
$ap = Tpl::apply(['title' => 'Y', 'category_id' => '1', 'params' => ['225693' => '999']], $d2);
check($ap['title'] === 'Y' && $ap['category_id'] === '257931' && $ap['params'] === ['11323' => '11323_1', '225693' => '999'] && $ap['publish_mode'] === 'now', 'apply keeps product data and EAN');
check(Tpl::apply(['category_id' => '1', 'params' => ['a' => 1]], $d1)['category_id'] === '1', 'template without category keeps current category');
$db = new PDO('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
Tpl::migrate($db); Tpl::migrate($db);
$repo = new Tpl($db);
$a = $repo->save(null, 'Świece', $d1, true);
$b = $repo->save(null, 'Inne', $d2, false);
check($repo->findDefault()['id'] === $a && $repo->find($b)['data']['category_id'] === '257931', 'templates saved, default found');
check($repo->save(null, 'Świece', ['invoice' => 'NO_INVOICE'], false) === $a && count($repo->all()) === 2, 'same name overwrites');
$repo->save($b, 'Inne', $d2, true);
check($repo->findDefault()['id'] === $b && !$repo->find($a)['is_default'], 'only one default');
$repo->delete($b);
check($repo->find($b) === null && $repo->findDefault() === null, 'template deleted');

// Kod taryfy celnej: parametr oferty (także przy produkcie z Katalogu), dopisany gdy kategoria go nie zwraca.
$withT = AllegroOfferPayload::withTariffCode($params);
$tp = array_values(array_filter($withT, [AllegroOfferPayload::class, 'isTariffParameter']));
check(count($tp) === 1 && $tp[0]['id'] === '250792' && !AllegroOfferPayload::describesProduct($tp[0]) && AllegroOfferPayload::allowedValues($tp[0]) > 1, 'tariff code added as multi-value offer parameter');
$own = AllegroOfferPayload::withTariffCode([['id' => '250792', 'name' => 'Kod taryfy celnej', 'type' => 'string', 'options' => ['describesProduct' => true], 'restrictions' => ['allowedNumberOfValues' => 3]]]);
check(count($own) === 1 && !AllegroOfferPayload::describesProduct($own[0]) && $own[0]['restrictions']['maxLength'] === 14, 'category tariff parameter kept and treated as offer parameter');
check(AllegroOfferPayload::withTariffCode([]) === [], 'no parameters without a category');
$tf2 = $form; $tf2['catalog_product_id'] = 'cat-1'; $tf2['params']['250792'] = ['34060000', ''];
$tb = AllegroOfferPayload::build($tf2, $withT);
check($tb['errors'] === [] && in_array(['id' => '250792', 'values' => ['34060000']], $tb['payload']['parameters'], true), 'tariff code sent in offer parameters with a catalog product');
$tf2['params']['250792'] = ['3406 00'];
check(str_contains(implode(' ', AllegroOfferPayload::build($tf2, $withT)['errors']), 'Kod taryfy celnej'), 'malformed tariff code rejected');
check(str_contains(renderOfferParams($withT, null, []), 'Kod CN, min. 8 cyfr'), 'tariff code field has a hint');

// Pojedyncza sztuka / wiele sztuk jako zestaw (productSet[].quantity.value).
check(!isset(AllegroOfferPayload::build($form, $params)['payload']['productSet'][0]['quantity']), 'single unit sends no set quantity');
$sf = $form; $sf['set_mode'] = 'set'; $sf['set_quantity'] = '6';
$sb = AllegroOfferPayload::build($sf, $params);
check($sb['errors'] === [] && $sb['payload']['productSet'][0]['quantity'] === ['value' => 6], 'set of 6 units in productSet quantity');
$sf['set_quantity'] = '1';
check(in_array('W zestawie podaj liczbę sztuk produktu od 2 do 1000.', AllegroOfferPayload::build($sf, $params)['errors'], true), 'set needs at least 2 units');

// Uwagi do zakupu, kto może kupić, załączniki.
$op = AllegroOfferPayload::build($form, $params)['payload'];
check($op['messageToSellerSettings'] === ['mode' => 'OPTIONAL'] && $op['b2b'] === ['buyableOnlyByBusiness' => false] && !isset($op['attachments']), 'defaults: optional buyer notes, all buyers, no attachments');
$of = $form + ['message_mode' => 'HIDDEN', 'buyers' => 'business', 'attachments' => [
    ['id' => 'a0604bb9-7d1c-49f4-bdc7-8e0d87edd6e9', 'type' => 'USER_MANUAL', 'name' => 'Instrukcja.pdf'],
    ['id' => 'a0604bb9-7d1c-49f4-bdc7-8e0d87edd6e9', 'type' => 'USER_MANUAL', 'name' => 'dup.pdf'],
    ['id' => 'bad id!', 'type' => 'MANUAL', 'name' => 'x.pdf'],
]];
$op = AllegroOfferPayload::build($of, $params)['payload'];
check($op['messageToSellerSettings'] === ['mode' => 'HIDDEN'] && $op['b2b'] === ['buyableOnlyByBusiness' => true], 'hidden buyer notes and business-only buyers');
check($op['attachments'] === [['id' => 'a0604bb9-7d1c-49f4-bdc7-8e0d87edd6e9']], 'attachments: valid ids, no duplicates');
check(AllegroOfferPayload::attachmentFormats('ENERGY_LABEL') === ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'] && AllegroOfferPayload::attachmentFormats('USER_MANUAL') === ['pdf' => 'application/pdf'], 'attachment file formats per type');
$td = Tpl::fromForm($of, false);
check($td['message_mode'] === 'HIDDEN' && $td['buyers'] === 'business' && !isset($td['attachments']), 'templates keep buyer notes and buyers, not attachments');

// Licytacja, jednostka, czas trwania, wznawianie, przedsprzedaż, informacje o dostawie, usługi dodatkowe, cennik hurtowy.
$bp = AllegroOfferPayload::build($form, $params)['payload'];
check(!isset($bp['publication']['duration']) && !isset($bp['publication']['republish']) && !isset($bp['additionalServices']) && !isset($bp['discounts']), 'defaults: until sold out, no republish, no services, no wholesale list');
$xf = $form + ['stock_unit' => 'PAIR', 'duration' => 'PT240H', 'republish' => '1', 'delivery_info' => ' Odbiór <b>osobisty</b> możliwy. ',
    'additional_services' => 'grp-1', 'wholesale_price_list' => 'whl-1'];
$xb = AllegroOfferPayload::build($xf, $params);
check($xb['errors'] === [], 'extended form has no errors');
$xp = $xb['payload'];
check($xp['stock'] === ['available' => 3, 'unit' => 'PAIR'], 'stock unit: pairs');
check($xp['publication'] === ['status' => 'INACTIVE', 'duration' => 'PT240H', 'republish' => true], 'duration 10 days and republish');
check($xp['delivery']['additionalInfo'] === 'Odbiór osobisty możliwy.', 'additional delivery info without HTML');
check($xp['additionalServices'] === ['id' => 'grp-1'] && $xp['discounts'] === ['wholesalePriceList' => ['id' => 'whl-1']], 'additional services and wholesale price list');
check(!isset(AllegroOfferPayload::build(['stock_unit' => 'KG'] + $form, $params)['payload']['publication']['duration'])
    && AllegroOfferPayload::build(['stock_unit' => 'KG'] + $form, $params)['payload']['stock']['unit'] === 'UNIT', 'unknown unit falls back to pieces');
check(str_contains(implode(' ', AllegroOfferPayload::build(['delivery_info' => str_repeat('a', 651)] + $form, $params)['errors']), '650'), 'delivery info over 650 characters');
// Licytacja.
$af = ['selling_format' => 'AUCTION', 'duration' => 'PT72H', 'minimal_price' => '50'] + $form;
$ab = AllegroOfferPayload::build($af, $params);
check($ab['errors'] === [] && $ab['payload']['sellingMode'] === ['format' => 'AUCTION', 'startingPrice' => ['amount' => '39.99', 'currency' => 'PLN'], 'minimalPrice' => ['amount' => '50.00', 'currency' => 'PLN']]
    && $ab['payload']['publication']['duration'] === 'PT72H', 'auction with starting and minimal price, 3 days');
check(str_contains(implode(' ', AllegroOfferPayload::build(['duration' => ''] + $af, $params)['errors']), 'Licytacja trwa określony czas'), 'auction needs a duration');
check(str_contains(implode(' ', AllegroOfferPayload::build(['minimal_price' => '10'] + $af, $params)['errors']), 'Cena minimalna'), 'minimal price below starting price rejected');
check(!isset(AllegroOfferPayload::build(['duration' => 'PT24H'] + $form, $params)['payload']['publication']['duration']), 'auction-only duration ignored for buy now');
// Przedsprzedaż.
$now = new DateTimeImmutable('2026-09-26 12:00', new DateTimeZone('Europe/Warsaw'));
check(AllegroOfferPayload::shipmentDate(['preorder' => '1', 'shipment_date' => '2026-10-15'], $now) === ['2026-10-14T22:00:00Z', ''], 'preorder shipment date at Polish midnight in UTC');
check(AllegroOfferPayload::shipmentDate(['preorder' => '1', 'shipment_date' => '2026-09-26'], $now)[1] !== '', 'preorder date must be in the future');
check(AllegroOfferPayload::shipmentDate(['preorder' => '1', 'shipment_date' => ''], $now)[1] === 'Przedsprzedaż: podaj datę wysyłki.', 'preorder needs a date');
check(AllegroOfferPayload::shipmentDate(['preorder' => '', 'shipment_date' => '2026-10-15'], $now) === [null, ''], 'no preorder, no shipment date');
check(isset(AllegroOfferPayload::build(['preorder' => '1', 'shipment_date' => (new DateTimeImmutable('+20 days'))->format('Y-m-d')] + $form, $params)['payload']['delivery']['shipmentDate']), 'preorder date in the offer');
// Podgląd opłat.
$fo = AllegroOfferPayload::feeOffer($af + ['stock_unit' => 'SET']);
check($fo['error'] === '' && $fo['offer']['sellingMode']['format'] === 'AUCTION' && $fo['offer']['publication'] === ['duration' => 'PT72H']
    && $fo['offer']['stock'] === ['available' => 3, 'unit' => 'SET'] && $fo['offer']['delivery'] === ['shippingRates' => ['id' => 'rate-1']], 'fee preview offer');
check(AllegroOfferPayload::feeOffer(['price' => '10'])['error'] !== '', 'fee preview needs a category');
check(AllegroOfferPayload::feeOffer($af)['offer']['parameters'] === [], 'fee preview always sends parameters (Allegro cannot parse a missing list)');
$fb = AllegroOfferPayload::feeOffer($form, AllegroOfferPayload::build($form, $params)['payload'])['offer']['parameters'];
check($fb[0] === ['id' => '11323', 'valuesIds' => ['11323_1']] && in_array(['id' => '225693', 'values' => ['5901234123457']], $fb, true), 'fee preview parameters: offer and product parameters together');
$td = Tpl::fromForm($xf, false);
check($td['stock_unit'] === 'PAIR' && $td['duration'] === 'PT240H' && $td['republish'] === '1' && $td['additional_services'] === 'grp-1' && $td['wholesale_price_list'] === 'whl-1', 'templates keep unit, duration, republish, services, wholesale list');

// Kolejne produkty, rynki dodatkowe, automatyczne ceny, wyróżnienie.
$mf = ['person' => 'per-1', 'extra_products' => [
    ['id' => 'cat-prod-2', 'name' => 'Podstawka', 'quantity' => '2'],
    ['id' => 'cat-prod-2', 'name' => 'dup', 'quantity' => '1'],
    ['id' => 'bad id', 'name' => 'x'],
    ['id' => 'cat-prod-3', 'name' => 'Knot', 'quantity' => '0'],
], 'markets' => ['allegro-cz' => '249,90', 'allegro-sk' => '', 'allegro-hu' => '3990.4', 'allegro-xx' => '5']] + $form;
$mb = AllegroOfferPayload::build($mf, $params);
check($mb['errors'] === [], 'multi-product form has no errors');
$ps = $mb['payload']['productSet'];
check(count($ps) === 3 && $ps[1] === ['product' => ['id' => 'cat-prod-2'], 'responsibleProducer' => ['id' => 'prod-1'], 'quantity' => ['value' => 2],
    'responsiblePerson' => ['id' => 'per-1'], 'safetyInformation' => $ps[0]['safetyInformation']] && $ps[2]['product'] === ['id' => 'cat-prod-3'] && !isset($ps[2]['quantity']),
    'extra catalog products with quantity, GPSR copied, duplicates and bad ids dropped');
check($mb['payload']['additionalMarketplaces'] === ['allegro-cz' => ['sellingMode' => ['price' => ['amount' => '249.90', 'currency' => 'CZK']]],
    'allegro-hu' => ['sellingMode' => ['price' => ['amount' => '3990', 'currency' => 'HUF']]]], 'additional marketplaces: own prices, HUF without decimals, unknown market ignored');
check(str_contains(implode(' ', AllegroOfferPayload::build(['catalog_product_id' => 'cat-prod-2'] + $mf, $params)['errors']), 'jest już pierwszym produktem'), 'extra product equal to the main catalog product rejected');
check(!isset(AllegroOfferPayload::build($form, $params)['payload']['additionalMarketplaces']), 'no market prices, no additionalMarketplaces');
check(str_contains(implode(' ', AllegroOfferPayload::build(['selling_format' => 'AUCTION', 'duration' => 'PT72H'] + $mf, $params)['errors']), 'Kup teraz'), 'market prices rejected for auctions');
[$rb, $re] = AllegroOfferPayload::priceRule(['price_rule' => 'rule-1', 'price_rule_min' => '40', 'price_rule_max' => '55,5']);
check($re === '' && $rb === ['rules' => [['marketplace' => ['id' => 'allegro-pl'], 'rule' => ['id' => 'rule-1'], 'configuration' => ['priceRange' => ['type' => 'MARKETPLACE_CURRENCY',
    'minPrice' => ['amount' => '40.00', 'currency' => 'PLN'], 'maxPrice' => ['amount' => '55.50', 'currency' => 'PLN']]]]]], 'price automation rule body');
check(AllegroOfferPayload::priceRule([]) === [null, ''], 'no rule, nothing to attach');
check(AllegroOfferPayload::priceRule(['price_rule' => 'r', 'price_rule_min' => '60', 'price_rule_max' => '50'])[1] !== ''
    && str_contains(implode(' ', AllegroOfferPayload::build(['price_rule' => 'r'] + $form, $params)['errors']), 'Automatyczne ceny'), 'rule needs a valid price range');
check(AllegroOfferPayload::promotion([]) === null, 'no promotion by default');
check(AllegroOfferPayload::promotion(['promo_package' => 'promoPackage', 'promo_department' => '1']) === ['modifications' => [
    ['modificationType' => 'CHANGE', 'packageType' => 'BASE', 'packageId' => 'promoPackage'],
    ['modificationType' => 'CHANGE', 'packageType' => 'EXTRA', 'packageId' => 'departmentPage']]], 'promotion package and category page');
$td = Tpl::fromForm($mf + ['price_rule' => 'rule-1', 'promo_package' => 'promoPackage', 'promo_department' => true, 'ads' => true], false);
check($td['price_rule'] === 'rule-1' && $td['promo_package'] === 'promoPackage' && $td['promo_department'] === true && $td['ads'] === true
    && !isset($td['extra_products']) && !isset($td['markets']), 'templates keep rule and promotion, not products or market prices');

echo "All {$checks} checks passed\n";
