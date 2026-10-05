<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Nazwa przesyłki na stronie klienta: bez ceny (dana wewnętrzna), bez „(ręcznie)", po ludzku.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Support\CarrierLabel;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$cases = [
    'paczkomaty'                          => 'Paczkomat InPost',
    'paczkomaty (14.75 PLN)'              => 'Paczkomat InPost',
    'Paczkomaty InPost - 12,99 zł'        => 'Paczkomat InPost',
    'Allegro Paczkomaty InPost'           => 'Paczkomat InPost',
    'inpost_locker_standard'              => 'Paczkomat InPost',
    'inpost_courier'                      => 'Kurier InPost',
    'dpd'                                 => 'DPD',
    'kurier-dhl'                          => 'Kurier DHL',
    'courier'                             => 'Kurier',
    'Kurier DPD (ręcznie)'                => 'Kurier DPD',
    'Kurier DPD: 18.50 PLN'               => 'Kurier DPD',
    'Poczta Polska (PLN 9.99)'            => 'Poczta Polska',
    'odbiór osobisty'                     => 'Odbiór osobisty',
    ''                                    => '—',
    '(14.75 PLN)'                         => '—',
];
foreach ($cases as $in => $want) {
    $got = CarrierLabel::forClient($in);
    check($got === $want, "'$in' -> '$want' (got '$got')");
    check(!preg_match('/\d+[.,]\d\d|pln|zł/iu', $got), "no price in '$got'");
}

// Strona klienta nie wypisuje już ceny przesyłki.
$page = file_get_contents(dirname(__DIR__) . '/public/order.php');
check(!str_contains($page, "\$ship['price']"), 'public order page does not print shipment price');

echo "\n$checks checks passed\n";
