<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Edycja trwającej oferty Allegro: oferta -> formularz -> PATCH bez zmian = brak różnic; zmiany wypisane do potwierdzenia. Bez sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
define('PASE_ROOT', dirname(__DIR__));
use Pase\Services\AllegroLiveOffer;
use Pase\Services\AllegroOfferPayload;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$offer = [
    'id' => '17000000001', 'name' => 'Wytworna Duża Świeca Zapachowa Whisky i Jazz',
    'category' => ['id' => '257383'],
    'productSet' => [[
        'product' => ['id' => 'b1a2-prod', 'parameters' => [['id' => '225693', 'name' => 'EAN (GTIN)', 'values' => ['5901234123457']],
            ['id' => '248811', 'name' => 'Marka', 'valuesIds' => ['248811_999'], 'values' => ['Lumio']]]],
        'responsibleProducer' => ['id' => 'prod-1'],
        'safetyInformation' => ['type' => 'TEXT', 'description' => 'Nie zostawiaj płonącej świecy bez nadzoru.'],
    ]],
    'parameters' => [['id' => '11323', 'name' => 'Stan', 'valuesIds' => ['11323_1'], 'values' => ['Nowy']]],
    'images' => ['https://a.allegroimg.com/original/1.jpg', 'https://a.allegroimg.com/original/2.jpg'],
    'description' => ['sections' => [['items' => [['type' => 'TEXT', 'content' => '<h2>Whisky i Jazz</h2><p>Ciepły zapach.</p>'],
        ['type' => 'IMAGE', 'url' => 'https://a.allegroimg.com/original/2.jpg']]]]],
    'sellingMode' => ['format' => 'BUY_NOW', 'price' => ['amount' => '49.99', 'currency' => 'PLN']],
    'stock' => ['available' => 10, 'unit' => 'UNIT'],
    'delivery' => ['shippingRates' => ['id' => 'rate-1'], 'handlingTime' => 'PT48H'],
    'afterSalesServices' => ['returnPolicy' => ['id' => 'ret-1'], 'impliedWarranty' => ['id' => 'imp-1']],
    'payments' => ['invoice' => 'VAT'],
    'taxSettings' => ['subject' => 'GOODS', 'rates' => [['rate' => '23.00', 'countryCode' => 'PL']]],
    'location' => ['countryCode' => 'PL', 'province' => 'WIELKOPOLSKIE', 'city' => 'Poznań', 'postCode' => '60-001'],
    'publication' => ['status' => 'ACTIVE', 'republish' => false],
    'external' => ['id' => 'NOS85WJ510A'],
    'messageToSellerSettings' => ['mode' => 'OPTIONAL'],
    'b2b' => ['buyableOnlyByBusiness' => false],
    'language' => 'pl-PL',
];
$catParams = [
    ['id' => '11323', 'name' => 'Stan', 'type' => 'dictionary', 'required' => true, 'dictionary' => [['id' => '11323_1', 'value' => 'Nowy']], 'options' => ['describesProduct' => false]],
    ['id' => '248811', 'name' => 'Marka', 'type' => 'dictionary', 'dictionary' => [['id' => '248811_1', 'value' => 'Yankee']],
        'options' => ['describesProduct' => true, 'customValuesEnabled' => true, 'ambiguousValueId' => '248811_999']],
    ['id' => '225693', 'name' => 'EAN (GTIN)', 'type' => 'string', 'options' => ['describesProduct' => true]],
];

$f = AllegroLiveOffer::formFromOffer($offer, $catParams);
check($f['title'] === $offer['name'] && $f['category_id'] === '257383' && $f['price'] === '49.99' && $f['stock'] === '10', 'title, category, price, stock from offer');
check($f['params']['11323'] === '11323_1' && $f['params_custom']['248811'] === 'Lumio' && $f['ean'] === '5901234123457', 'offer + product parameters (custom brand, EAN)');
check($f['catalog_product_id'] === 'b1a2-prod', 'offer product sent by its catalog id');
check($f['images'] === $offer['images'] && count($f['description_sections']) === 1, 'photos and description');
check($f['shipping_rate'] === 'rate-1' && $f['handling'] === 'PT48H' && $f['return_policy'] === 'ret-1' && $f['implied_warranty'] === 'imp-1', 'delivery and after-sales');
check($f['producer'] === 'prod-1' && $f['safety_mode'] === 'text' && str_contains($f['safety_text'], 'bez nadzoru'), 'GPSR');
check($f['vat'] === ['PL' => '23.00'] && $f['signature'] === 'NOS85WJ510A' && $f['city'] === 'Poznań', 'VAT, SKU, location');

$form = $f + ['publish_mode' => 'draft', 'selling_format' => 'BUY_NOW'];
$built = AllegroOfferPayload::build($form, $catParams);
check($built['errors'] === [], 'loaded offer passes the listing checks: ' . implode(' | ', $built['errors']));
$base = AllegroLiveOffer::patchPayload($built['payload']);
check(!isset($base['language']) && $base['publication'] === ['republish' => false], 'PATCH never changes status or start date');
check($base['productSet'][0]['product'] === ['id' => 'b1a2-prod'], 'product kept by id');
$same = AllegroLiveOffer::compare($base, AllegroLiveOffer::patchPayload(AllegroOfferPayload::build($form, $catParams)['payload']));
check($same === ['patch' => [], 'changes' => []], 'unchanged form -> nothing sent');

// Kod taryfy celnej: Allegro odrzuca go w parametrach oferty przy zmianie („nie powinien być wskazany w sekcji offer").
$tariff = AllegroOfferPayload::withTariffCode($catParams);
$form2 = $form; $form2['params'][AllegroOfferPayload::TARIFF_PARAMETER_ID] = ['34060000'];
$b2 = AllegroOfferPayload::build($form2, $tariff);
check(in_array(AllegroOfferPayload::TARIFF_PARAMETER_ID, array_column($b2['payload']['parameters'], 'id'), true), 'new listing still sends the tariff code');
$p2 = AllegroLiveOffer::patchPayload($b2['payload']);
check(!in_array(AllegroOfferPayload::TARIFF_PARAMETER_ID, array_column($p2['parameters'], 'id'), true) && in_array('11323', array_column($p2['parameters'], 'id'), true),
    'offer update leaves the tariff code out of offer parameters');

// Zmiana tylko opisu (z nowymi grafikami) - wysyłamy tylko opis i zdjęcia, reszta oferty bez zmian.
$form3 = $form;
$form3['description_sections'] = [['items' => [['type' => 'IMAGE', 'url' => 'https://a.allegroimg.com/original/aw1.jpg']]],
    ['items' => [['type' => 'TEXT', 'content' => '<p>Nowy tekst.</p>']]]];
$form3['safety_text'] = str_replace("\n", "\r\n", $form3['safety_text']);        // przeglądarka wysyła \r\n
$new = AllegroOfferPayload::build($form3, $catParams)['payload'];
$merged = AllegroOfferPayload::mergeDescriptionImages($form3['images'], $offer['description']['sections'], $new['description']['sections']);
$new['images'] = $merged['images'];
$c = AllegroLiveOffer::compare($base, AllegroLiveOffer::patchPayload($new));
check(array_keys($c['patch']) === ['images', 'description'], 'description edit sends only description + photos: ' . implode(',', array_keys($c['patch'])));
check($c['patch']['images'] === array_merge($offer['images'], ['https://a.allegroimg.com/original/aw1.jpg']), 'new description graphic appended after the gallery');
check($c['changes'][0] === 'Zdjęcia: 2 → 3 (grafiki z opisu dopisane na końcu galerii — Allegro tego wymaga)' && $c['changes'][1] === 'Opis', 'change list: ' . implode(' | ', $c['changes']));

$form['price'] = '59.99';
$form['title'] = 'Wytworna Duża Świeca Zapachowa Whisky i Jazz 510 g';
$c = AllegroLiveOffer::compare($base, AllegroLiveOffer::patchPayload(AllegroOfferPayload::build($form, $catParams)['payload']));
check($c['changes'] === ['Tytuł: Wytworna Duża Świeca Zapachowa Whisky i Jazz → Wytworna Duża Świeca Zapachowa Whisky i Jazz 510 g', 'Cena: 49,99 zł → 59,99 zł'],
    'changes listed: ' . implode(' | ', $c['changes']));
check(array_keys($c['patch']) === ['name', 'sellingMode'] && $c['patch']['sellingMode']['price']['amount'] === '59.99', 'only title and price sent');

// Pola, których operator nie ruszał, zostają jak na Allegro - nawet gdy formularz pokazał je inaczej
// (np. cennik dostawy spoza listy albo inny czas wysyłki): liczy się tylko to, co zmieniono w przeglądarce.
$liveForm = $form;
$liveForm['shipping_rate'] = 'rate-live'; $liveForm['handling'] = 'PT48H'; $liveForm['delivery_info'] = 'Wysyłka w 24h.';
$browser = $liveForm; $browser['shipping_rate'] = ''; $browser['handling'] = 'PT24H'; $browser['delivery_info'] = '';
$browser['description_sections'] = [['items' => [['type' => 'TEXT', 'content' => '<p>Nowy opis.</p>']]]];
$browser['params']['11323'] = ['zmienione'];
$edited = AllegroLiveOffer::editedForm($liveForm, $browser, ['description_sections']);
check($edited['shipping_rate'] === 'rate-live' && $edited['handling'] === 'PT48H' && $edited['delivery_info'] === 'Wysyłka w 24h.', 'untouched delivery stays as on Allegro');
check($edited['description_sections'] === $browser['description_sections'] && $edited['params'] === $liveForm['params'], 'only the edited description taken from the form');
$c = AllegroLiveOffer::compare(AllegroLiveOffer::patchPayload(AllegroOfferPayload::build($liveForm, $catParams)['payload']),
    AllegroLiveOffer::patchPayload(AllegroOfferPayload::build($edited, $catParams)['payload']));
check(array_keys($c['patch']) === ['description', 'images'] || array_keys($c['patch']) === ['images', 'description'], 'patch = description (+images): ' . implode(',', array_keys($c['patch'])));
$e2 = AllegroLiveOffer::editedForm($liveForm, $browser, ['params', 'handling']);
check($e2['params'] === $browser['params'] && $e2['handling'] === 'PT24H' && $e2['shipping_rate'] === 'rate-live', 'edited fields (params -> params+ean, handling) taken from the form');
check(AllegroLiveOffer::editedForm($liveForm, $browser, null) === $browser, 'form not loaded from Allegro: whole form used');

echo "\n{$checks} checks passed\n";
