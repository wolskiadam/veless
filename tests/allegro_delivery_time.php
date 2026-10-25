<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Terminy z zamówienia Allegro (delivery.time): „Wyślij do” i „Przewidywany czas dostawy” jak w panelu sprzedawcy.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Support\AllegroDeliveryTime as T;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$payload = ['delivery' => ['time' => [
    'from' => '2026-10-27T07:00:00Z', 'to' => '2026-10-27T20:00:00Z',
    'dispatch' => ['from' => '2026-10-25T18:00:00Z', 'to' => '2026-10-26T22:59:59.999Z'],
]], 'fulfillment' => ['status' => 'NEW']];
$t = T::fromPayload($payload);
check($t !== null && $t['dispatch_to'] !== null, 'dispatch deadline parsed');
check(T::formatDateTime($t['dispatch_to']) === 'pon., 26 paź 2026, 23:59', 'deadline in Polish time like Allegro panel: ' . T::formatDateTime($t['dispatch_to']));
check(T::formatDelivery($t) === 'wt., 27 paź 2026', 'expected delivery one day: ' . T::formatDelivery($t));
check($t['guaranteed'] === false, 'not guaranteed');
check(T::formatShort($t['dispatch_to']) === '26.10 23:59', 'short format for list');

// Zakres dni dostawy i gwarancja.
$g = T::fromPayload(['delivery' => ['time' => ['from' => '2026-10-27T07:00:00Z', 'to' => '2026-10-29T20:00:00Z',
    'guaranteed' => ['from' => '2026-10-28T07:00:00Z', 'to' => '2026-10-28T20:00:00Z']]]]);
check($g['guaranteed'] && T::formatDelivery($g) === 'śr., 28 paź 2026', 'guaranteed window wins');
$r = T::fromPayload(['delivery' => ['time' => ['from' => '2026-10-27T07:00:00Z', 'to' => '2026-10-29T20:00:00Z']]]);
check(T::formatDelivery($r) === 'wt., 27 paź 2026 – czw., 29 paź 2026', 'range');
check($r['dispatch_to'] === null, 'no dispatch = null deadline');

// Bez danych.
check(T::fromPayload([]) === null, 'no delivery.time');
check(T::fromPayload(['delivery' => ['time' => ['dispatch' => null]]]) === null, 'empty delivery.time');
check(T::fromPayload(['delivery' => ['time' => ['from' => 'bogus']]]) === null, 'garbage date ignored');

// Pilność.
$tz = new DateTimeZone('Europe/Warsaw');
$d = $t['dispatch_to'];
check(T::urgency($d, new DateTimeImmutable('2026-10-26 10:00', $tz)) === 'today', 'today');
check(T::urgency($d, new DateTimeImmutable('2026-10-25 19:45', $tz)) === 'soon', 'tomorrow');
check(T::urgency($d, new DateTimeImmutable('2026-10-23 10:00', $tz)) === 'ok', 'later');
check(T::urgency($d, new DateTimeImmutable('2026-10-27 00:01', $tz)) === 'overdue', 'overdue');

// Czy termin jeszcze obowiązuje.
check(T::awaitingDispatch($payload, []), 'new order awaits dispatch');
check(!T::awaitingDispatch(['fulfillment' => ['status' => 'SENT']] + $payload, []), 'sent in Allegro');
check(!T::awaitingDispatch($payload, [['status' => 'created']]), 'shipment created');
check(T::awaitingDispatch($payload, [['status' => 'cancelled']]), 'cancelled shipment does not count');

echo "\n{$checks} checks passed\n";
