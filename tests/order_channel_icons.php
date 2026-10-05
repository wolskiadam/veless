<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Ikony na liście zamówień (Services\OrderChannelIcons): logo kanału z wtyczki integracji i przewoźnik w kolumnie „Dostawa”.
$root = dirname(__DIR__);
define('PASE_ROOT', $root);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Pase\\')) { require $root . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
    elseif (str_starts_with($class, 'PasePlugin\\')) {
        $parts = explode('\\', substr($class, 11));
        $parts[0] = strtolower($parts[0]);
        $file = $root . '/integrations/' . implode('/', $parts) . '.php';
        if (is_file($file)) { require $file; }
    }
});
use Pase\Services\OrderChannelIcons as I;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

check(I::carrierKey('Paczkomaty InPost 24/7') === 'inpost', 'InPost from Woo method');
check(I::carrierKey('Allegro Paczkomaty InPost') === 'inpost', 'Allegro InPost is InPost, not Allegro One');
check(I::carrierKey('Allegro One Box, DPD') === 'allegro_one', 'Allegro One wins over its partner carrier');
check(I::carrierKey('Allegro Kurier DPD pobranie') === 'dpd', 'DPD');
check(I::carrierKey('Kurier DHL') === 'dhl' && I::carrierKey('UPS Standard') === 'ups' && I::carrierKey('Pocztex Kurier48') === 'poczta', 'DHL, UPS, Pocztex');
check(I::carrierKey('ORLEN Paczka') === 'orlen' && I::carrierKey('Odbiór osobisty') === 'pickup', 'Orlen and pickup');
check(I::carrierKey('Kurier') === null && I::carrierKey('') === null && I::carrierKey(null) === null, 'unknown carrier');

$woo = ['shipping_lines' => [['method_title' => 'Kurier DPD']]];
check(I::delivery($woo)['key'] === 'dpd', 'carrier from shipping method');
check(I::delivery(['delivery' => ['method' => ['name' => 'Allegro Automat DHL BOX 24/7']]])['key'] === 'dhl', 'carrier from raw Allegro delivery');
check(I::delivery($woo, [['courier_code' => 'InPost', 'status' => 'created']])['key'] === 'inpost', 'sent shipment wins over chosen method');
check(I::delivery($woo, [['courier_code' => 'Wysyłam z Allegro', 'status' => 'created']])['key'] === 'dpd', 'unknown courier name falls back to method');
check(I::delivery($woo, [['courier_code' => 'UPS', 'status' => 'cancelled']])['key'] === 'dpd', 'cancelled shipment ignored');
$other = I::delivery(['shipping_lines' => [['method_title' => 'Transport własny']]]);
check($other['key'] === null && $other['name'] === 'Transport własny', 'unknown method keeps its name');
check(I::delivery([])['name'] === '', 'no delivery info');

$cell = I::deliveryCell(I::delivery($woo));
check(str_contains($cell, 'carrier-logo') && str_contains($cell, '<svg') && str_contains($cell, 'Kurier DPD'), 'carrier cell has logo and method in tooltip');
check(str_contains(I::deliveryCell($other), 'Transport własny') && str_contains(I::deliveryCell($other), 'carrier-other'), 'unknown carrier shows truck and name');
check(str_contains(I::deliveryCell(I::delivery([])), '—'), 'no delivery shows dash');
check(!str_contains(I::deliveryCell(I::delivery(['shipping_lines' => [['method_title' => '<b>x</b>']]])), '<b>'), 'method name escaped');

// Logo kanału pochodzi z manifestu wtyczki.
check(str_contains(I::sourceLogo('allegro'), '#ff5a00'), 'Allegro logo from its plugin');
check(str_contains(I::sourceLogo('woocommerce'), '#7f54b3'), 'WooCommerce logo from its plugin');
check(str_contains(I::sourceLogo('tiktokshop'), '<svg'), 'TikTok Shop logo from its plugin');
check(str_contains(I::sourceLogo('blpaczka'), '<text'), 'plugin without logo gets a letter fallback');
check(str_contains(I::sourceLogo('nope'), '<svg') && str_contains(I::sourceLogo(null), '<svg'), 'unknown channel gets neutral icon');
check(str_contains(I::sourceLogo('manual'), '#2f7a45'), 'manual orders have a core icon');

echo "\n{$checks} checks passed\n";
