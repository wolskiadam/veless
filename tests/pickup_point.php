<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Punkt odbioru z zamówienia (Allegro + różne wtyczki WooCommerce) - bez bazy i sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Support\PickupPoint;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }
$meta = static fn(array $kv): array => array_map(static fn($k, $v) => ['id' => 1, 'key' => $k, 'value' => $v], array_keys($kv), $kv);

// Allegro.
$p = PickupPoint::fromOrder(['delivery' => ['pickupPoint' => ['id' => 'KRA01M', 'name' => 'Paczkomat', 'address' => ['street' => 'Długa 1', 'zipCode' => '30-001', 'city' => 'Kraków']]]]);
check($p['id'] === 'KRA01M' && $p['line'] === 'Długa 1, 30-001 Kraków', 'Allegro pickupPoint');

// Znany klucz (WP Desk).
$p = PickupPoint::fromOrder(['meta_data' => $meta(['_paczkomat_id' => 'WAW22A'])]);
check($p['id'] === 'WAW22A', 'Known key _paczkomat_id');

// camelCase z osobnymi polami nazwy/adresu i przewoźnika (zamówienie #92377).
$p = PickupPoint::fromOrder(['shipping' => ['first_name' => '', 'address_1' => '', 'city' => ''], 'meta_data' => $meta([
    '_wc_order_attribution_source_type' => 'typein',
    '_furgonetkaPoint' => 'BYD117M',
    '_furgonetkaPointName' => 'SM Komunalni, Fałata 2, 85-309 Bydgoszcz',
    '_furgonetkaService' => 'inpost',
])]);
check($p !== null && $p['id'] === 'BYD117M', 'camelCase key _furgonetkaPoint');
check($p['name'] === 'SM Komunalni' && $p['street'] === 'Fałata 2' && $p['postcode'] === '85-309' && $p['city'] === 'Bydgoszcz', 'Name/address split from sibling meta');

// Tylko przewoźnik jako szczegół -> nazwa.
$p = PickupPoint::fromOrder(['meta_data' => $meta(['_myshipPointCode' => 'POZ08N', '_myshipService' => 'inpost'])]);
check($p['id'] === 'POZ08N' && $p['name'] === 'INPOST', 'Carrier used as name');

// Punkt wpisany tekstem w adres dostawy („Punkt odbioru: BYD117M (INPOST)").
$p = PickupPoint::fromOrder(['shipping' => ['first_name' => '', 'company' => 'Punkt odbioru: BYD117M (INPOST)', 'address_1' => 'SM Komunalni', 'address_2' => 'Fałata 2', 'postcode' => '85-309', 'city' => 'Bydgoszcz']]);
check($p['id'] === 'BYD117M' && $p['name'] === 'INPOST' && $p['street'] === 'SM Komunalni, Fałata 2' && $p['city'] === 'Bydgoszcz', 'Point from shipping address text');

// Tekst w meta metody wysyłki pod ludzką etykietą.
$p = PickupPoint::fromOrder(['shipping_lines' => [['method_title' => 'InPost Paczkomat', 'meta_data' => $meta(['Punkt odbioru' => 'BYD117M (INPOST)'])]]]);
check($p['id'] === 'BYD117M' && $p['name'] === 'INPOST', 'Shipping line meta label');

// Fałszywe trafienia: punkty lojalnościowe, sama nazwa metody, adres zwykły.
check(PickupPoint::fromOrder(['meta_data' => $meta(['_wc_points_earned' => '120', 'loyalty_point_balance' => '350'])]) === null, 'Loyalty points ignored');
check(PickupPoint::fromOrder(['shipping_lines' => [['method_title' => 'InPost Paczkomat (Bezpłatnie)']], 'shipping' => ['address_1' => 'Gołębia 2/3', 'city' => 'Bydgoszcz']]) === null, 'Plain method title / address ignored');
check(PickupPoint::fromOrder(['meta_data' => $meta(['_pickup_point_name' => 'Żabka, ul. Długa 15, 00-001 Warszawa'])]) === null, 'Detail-only key is not an ID');

// Apaczka (zamówienie #92377): JSON w apaczka_delivery_point - jako tekst i jako obiekt.
$apm = ['apm_access_point_id' => 'BYD117M', 'apm_supplier' => 'INPOST', 'apm_name' => 'SM Komunalni', 'apm_foreign_access_point_id' => 'BYD117M',
    'apm_street' => 'Fałata 2', 'apm_city' => 'Bydgoszcz', 'apm_postal_code' => '85-309'];
foreach ([json_encode($apm, JSON_UNESCAPED_UNICODE), $apm] as $val) {
    $p = PickupPoint::fromOrder(['meta_data' => $meta(['_billing_nip' => '', 'apaczka_delivery_point' => $val, 'is_vat_exempt' => 'no'])]);
    check($p !== null && $p['id'] === 'BYD117M' && $p['name'] === 'SM Komunalni (INPOST)' && $p['line'] === 'Fałata 2, 85-309 Bydgoszcz', 'Apaczka apaczka_delivery_point (' . gettype($val) . ')');
}

// Ręczna poprawka ma pierwszeństwo.
$p = PickupPoint::fromOrder(['meta_data' => $meta(['_furgonetkaPoint' => 'BYD117M'])], ['id' => 'GDA01M']);
check($p['id'] === 'GDA01M', 'Manual override wins');

echo "\n{$checks} checks passed\n";
