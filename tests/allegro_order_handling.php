<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Czas wysyłki z oferty przy zamówieniu Allegro: zapisany przy pobraniu świeżego zamówienia,
// a dla starszych obecny czas z oferty z dopiskiem.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\AllegroOrderHandling as H;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
H::migrate($pdo);
H::migrate($pdo);
$pdo->exec('CREATE TABLE allegro_offer_details (offer_id VARCHAR(32) PRIMARY KEY, handling_time VARCHAR(16), shipping_rate_id VARCHAR(64), fetched_at DATETIME)');

$now = strtotime('2026-10-25 12:00:00 UTC');
$payload = ['boughtAt' => '2026-10-25T10:00:00.000Z', 'lineItems' => [
    ['offer' => ['id' => '111', 'name' => 'Świeca A']], ['offer' => ['id' => '222', 'name' => 'Świeca B']], ['offer' => ['id' => '111', 'name' => 'Świeca A']],
]];
$calls = [];
$offers = ['111' => 'PT24H', '222' => 'P2D'];
$fetch = function (string $id) use (&$calls, &$offers): ?array { $calls[] = $id; return ['delivery' => ['handlingTime' => $offers[$id]]]; };

H::record($pdo, 1, $payload, $fetch, $now);
check($calls === ['111', '222'], 'each offer fetched once');
$r = H::forOrder($pdo, 1, $payload);
check(count($r) === 2 && $r[0]['handling'] === '24 godziny' && $r[1]['handling'] === '2 dni' && !$r[0]['current'], 'saved handling per offer');
check($r[0]['name'] === 'Świeca A', 'offer name from line item');

// Zmiana w ofercie po zakupie nie zmienia zapisu.
$offers['111'] = 'P7D';
H::record($pdo, 1, $payload, $fetch, $now);
check(count($calls) === 2, 'second record does nothing');
check(H::forOrder($pdo, 1, $payload)[0]['handling'] === '24 godziny', 'value from purchase time kept');

// Stare zamówienie (import historyczny): nie zapisujemy, pokazujemy obecny czas z oferty.
$old = ['boughtAt' => '2026-09-01T10:00:00Z'] + $payload;
H::record($pdo, 2, $old, $fetch, $now);
check(count($calls) === 2, 'old order: no API calls');
check(H::forOrder($pdo, 2, $old) === [], 'old order without cached offer: nothing shown');
$pdo->exec("INSERT INTO allegro_offer_details VALUES ('111', 'P3D', NULL, '2026-10-25')");
$r = H::forOrder($pdo, 2, $old);
check(count($r) === 1 && $r[0]['handling'] === '3 dni' && $r[0]['current'], 'old order: current offer value marked as current');

// Oferta, której nie udało się pobrać.
$fail = fn(string $id): ?array => null;
H::record($pdo, 3, $payload, $fail, $now);
check(H::forOrder($pdo, 3, $payload)[0]['current'] === true, 'failed fetch falls back to current offer value');
check(H::forOrder($pdo, 4, []) === [], 'no line items');

echo "\n{$checks} checks passed\n";
