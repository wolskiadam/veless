<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Nazwisko klienta zamówienia Allegro: kupujący, a gdy pusty - adres dostawy / nazwa firmy.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Support\AllegroOrderMapper as M;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$del = ['delivery' => ['address' => ['firstName' => 'Filip', 'lastName' => 'Król', 'street' => 'Biegusa 29']]];
check(M::customerName(['buyer' => ['firstName' => 'Jan', 'lastName' => 'Nowak']] + $del) === 'Jan Nowak', 'buyer name first');
check(M::customerName(['buyer' => ['firstName' => null, 'lastName' => '', 'email' => 'x@allegromail.pl']] + $del) === 'Filip Król', 'empty buyer -> delivery address');
check(M::customerName(['buyer' => ['email' => 'x@allegromail.pl']] + $del) === 'Filip Król', 'missing buyer name -> delivery address');
check(M::customerName(['buyer' => ['companyName' => 'Firma Sp. z o.o.']]) === 'Firma Sp. z o.o.', 'company buyer');
check(M::customerName(['invoice' => ['address' => ['company' => ['name' => 'ACME']]]]) === 'ACME', 'invoice company');
check(M::customerName([]) === '', 'nothing known');

echo "\n{$checks} checks passed\n";
