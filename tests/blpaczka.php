<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// BLPaczka: saldo skarbonki, forma płatności i podpowiedź przy braku środków - bez bazy i sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
require dirname(__DIR__) . '/integrations/blpaczka/BlpaczkaClient.php';
require dirname(__DIR__) . '/integrations/blpaczka/BlpaczkaPlugin.php';
use PasePlugin\Blpaczka\BlpaczkaClient;
use PasePlugin\Blpaczka\BlpaczkaPlugin;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

// Różne kształty odpowiedzi getBankSaldo.
check(BlpaczkaClient::parseSaldo(['success' => true, 'data' => '125.50']) === 125.5, 'Saldo jako data (string)');
check(BlpaczkaClient::parseSaldo(['success' => true, 'data' => ['saldo' => 7]]) === 7.0, 'Saldo w data.saldo');
check(BlpaczkaClient::parseSaldo(['success' => true, 'data' => ['Bank' => ['bank_saldo' => '1 234,56 zł']]]) === 1234.56, 'Saldo zagnieżdżone z przecinkiem');
check(BlpaczkaClient::parseSaldo(['success' => true, 'balance' => 0]) === 0.0, 'Saldo zero');
check(BlpaczkaClient::parseSaldo(['success' => true, 'data' => ['message' => 'x']]) === null, 'Brak kwoty -> null');

// Forma płatności: puste pole = skarbonka.
check(BlpaczkaPlugin::paymentCode([]) === 'bank', 'Brak ustawienia -> bank');
check(BlpaczkaPlugin::paymentCode(['payment' => '']) === 'bank', 'Puste ustawienie -> bank');
check(BlpaczkaPlugin::paymentCode(['payment' => 'pay_later']) === 'pay_later', 'Płatność odroczona');

// Podpowiedź do komunikatu z BLPaczki.
$err = 'Niewystarczająca kwota na skarbonce do opłacenia zamówienia [14.75].Nie wybrano formy płatności.';
$info = ['login' => 'a@b.pl', 'env' => 'production', 'payment' => 'bank', 'payment_label' => 'Skarbonka (prepaid)', 'balance' => 3.2];
check(BlpaczkaPlugin::isFundsError($err), 'Rozpoznaje brak środków');
check(!BlpaczkaPlugin::isFundsError('Nieprawidłowy kod pocztowy'), 'Inny błąd to nie brak środków');
$h = BlpaczkaPlugin::fundsHint($err, $info);
check(str_contains($h, '3,20 zł') && str_contains($h, 'a@b.pl') && str_contains($h, 'skarbonk') && !str_contains($h, 'Sandbox'), 'Podpowiedź z saldem, bez sandboxa');
$h = BlpaczkaPlugin::fundsHint($err, ['env' => 'sandbox', 'balance' => null] + $info);
check(str_contains($h, 'Sandbox') && !str_contains($h, 'zł.'), 'Podpowiedź o sandboxie, bez salda');
$h = BlpaczkaPlugin::fundsHint($err, ['payment' => 'pay_later', 'payment_label' => 'Płatność odroczona'] + $info);
check(str_contains($h, 'Płatność odroczona'), 'Podpowiedź przy innej formie płatności');
check(BlpaczkaPlugin::fundsHint('Nieprawidłowy kod pocztowy', $info) === null, 'Brak podpowiedzi dla innego błędu');

echo "\nAll $checks checks passed.\n";
