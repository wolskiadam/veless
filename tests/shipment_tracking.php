<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Śledzenie przesyłki „na żądanie" (Services\ShipmentTracking::checkOne): gdy rozpoznany przewoźnik
// nic nie zwraca, sprawdzamy inne pasujące id z listy Allegro (np. Orlen Paczka) i ALLEGRO.
// SQLite + atrapa klienta Allegro, bez sieci.
define('PASE_ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require PASE_ROOT . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\ShipmentTracking;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
$pdo->exec('CREATE TABLE shipments (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, courier_code TEXT, waybill_no TEXT, bl_order_id TEXT,
    order_ref TEXT, price REAL, status TEXT, label_link TEXT, request_payload TEXT, response_payload TEXT, created_at TEXT,
    tracking_status TEXT, tracking_code TEXT, tracking_carrier TEXT, tracking_at TEXT, tracking_checked_at TEXT, tracking_events TEXT)');

/** Atrapa: Allegro zna przesyłkę tylko pod jednym id przewoźnika. */
$client = new class {
    public string $knows = 'PACZKA_W_RUCHU';
    public array $asked = [];
    public array $failFor = [];
    public function carriers(): array
    {
        return [['id' => 'INPOST', 'name' => 'InPost'], ['id' => 'ORLEN', 'name' => 'ORLEN Paczka'],
                ['id' => 'PACZKA_W_RUCHU', 'name' => 'Paczka w Ruchu'], ['id' => 'ALLEGRO', 'name' => 'Allegro']];
    }
    public function carrierTracking(string $carrier, array $waybills): array
    {
        $this->asked[] = $carrier;
        if (in_array($carrier, $this->failFor, true)) {
            return ['ok' => false, 'status' => 404, 'waybills' => [], 'message' => 'Nie znaleziono przewoźnika.'];
        }
        $statuses = $carrier === $this->knows
            ? [['code' => 'IN_TRANSIT', 'occurredAt' => '2026-09-27T10:00:00Z', 'description' => 'Przesyłka w drodze']]
            : [];
        return ['ok' => true, 'status' => 200, 'message' => '',
                'waybills' => array_map(static fn($w) => ['waybill' => $w, 'trackingDetails' => ['statuses' => $statuses]], $waybills)];
    }
};
$add = static function (string $courier) use ($pdo): int {
    $pdo->prepare("INSERT INTO shipments (courier_code, waybill_no, status, created_at) VALUES (?, '2102711810140', 'created', ?)")
        ->execute([$courier, date('Y-m-d H:i:s')]);
    return (int) $pdo->lastInsertId();
};
$row = static fn(int $id) => $pdo->query("SELECT * FROM shipments WHERE id = {$id}")->fetch();
$trk = new ShipmentTracking($pdo, $client, [], static fn(string $w): ?array => null);   // InPost API nie zna tych numerów

$id = $add('Orlen Paczka');
$r = $trk->checkOne($id);
check($r['ok'] && $r['status'] === 'in_transit', 'Orlen Paczka found under another carrier id');
check($client->asked === ['ORLEN', 'PACZKA_W_RUCHU'], 'first the detected id, then the other Orlen id');
check($row($id)['tracking_carrier'] === 'PACZKA_W_RUCHU' && $row($id)['tracking_events'] !== null, 'working carrier and events stored');

$client->asked = [];
check($trk->detectCarrier($row($id)) === 'PACZKA_W_RUCHU', 'later checks reuse the carrier that worked');
$trk->run();
check($client->asked === ['PACZKA_W_RUCHU'] && $row($id)['tracking_carrier'] === 'PACZKA_W_RUCHU', 'scheduler asks only the working carrier');

$client->asked = []; $client->knows = 'ALLEGRO';
$id = $add('Orlen Paczka');
$r = $trk->checkOne($id);
check($r['status'] === 'in_transit' && end($client->asked) === 'ALLEGRO', 'parcel from Allegro Delivery found under ALLEGRO');

$client->asked = []; $client->knows = 'NONE';
$id = $add('Orlen Paczka');
$r = $trk->checkOne($id);
check($r['ok'] && $r['status'] === null && str_contains($r['message'], 'ORLEN, PACZKA_W_RUCHU, ALLEGRO'), 'no data anywhere: message names the carriers checked');
check($row($id)['tracking_carrier'] === 'ORLEN' && $row($id)['tracking_checked_at'] !== null, 'check time stored for the popover');

$client->asked = []; $client->knows = 'PACZKA_W_RUCHU'; $client->failFor = ['ORLEN'];
$id = $add('Orlen Paczka');
check($trk->checkOne($id)['status'] === 'in_transit', 'error on the first id does not stop the fallback');

$client->asked = []; $client->knows = 'NONE'; $client->failFor = ['ORLEN', 'PACZKA_W_RUCHU', 'ALLEGRO'];
$id = $add('Orlen Paczka');
$r = $trk->checkOne($id);
check(!$r['ok'] && str_contains($r['message'], 'Nie znaleziono przewoźnika'), 'all ids failing shows the Allegro error');

$client->asked = []; $client->failFor = []; $client->knows = 'INPOST';
$id = $add('InPost Paczkomat');
check($trk->checkOne($id)['status'] === 'in_transit' && $client->asked === ['INPOST'], 'recognised carrier with data: one question only');

// InPost: publiczne API InPost, bez pytania Allegro (paczka spoza Allegro).
$inpostAsked = [];
$inpost = static function (string $w) use (&$inpostAsked): ?array {
    $inpostAsked[] = $w;
    return $w === '620999680361685673392931' ? ['tracking_number' => $w, 'status' => 'delivered', 'tracking_details' => [
        ['status' => 'delivered', 'datetime' => '2026-09-26T14:02:00.000+02:00'],
        ['status' => 'ready_to_pickup', 'datetime' => '2026-09-25T09:10:00.000+02:00'],
        ['status' => 'confirmed', 'datetime' => '2026-09-24T18:00:00.000+02:00'],
    ]] : null;
};
$trk = new ShipmentTracking($pdo, $client, [], $inpost);
$client->asked = []; $client->knows = 'NONE';
$pdo->prepare("INSERT INTO shipments (courier_code, waybill_no, status, created_at) VALUES ('InPost', '620999680361685673392931', 'created', ?)")->execute([date('Y-m-d H:i:s')]);
$id = (int) $pdo->lastInsertId();
$r = $trk->checkOne($id);
check($r['ok'] && $r['status'] === 'delivered', 'InPost parcel outside Allegro: delivered from the InPost API');
check($client->asked === [] && $inpostAsked === ['620999680361685673392931'], 'Allegro is not asked when InPost answers');
$ev = json_decode((string) $row($id)['tracking_events'], true);
check(count($ev) === 3 && $ev[2]['desc'] === 'Doręczona' && $ev[0]['desc'] === 'Przygotowana przez nadawcę', 'InPost history stored oldest first with Polish descriptions');
check($row($id)['tracking_carrier'] === 'INPOST', 'carrier stored as INPOST');

$client->knows = 'INPOST'; $client->asked = [];
$id = $add('InPost Paczkomat');
check($trk->checkOne($id)['status'] === 'in_transit' && $client->asked === ['INPOST'], 'unknown to the InPost API: Allegro still asked');

// Ręczny status - gdy nikt nie zna przesyłki.
$id = $add('Orlen Paczka');
check(ShipmentTracking::setManual($pdo, $id, 'delivered', 'Adam'), 'manual status saved');
$r = $row($id);
$ev = json_decode((string) $r['tracking_events'], true);
check($r['tracking_status'] === 'delivered' && $r['tracking_code'] === ShipmentTracking::MANUAL
    && str_contains(end($ev)['desc'], 'Doręczona — ustawiono ręcznie (Adam)'), 'stored with a history entry naming who set it');
check(!ShipmentTracking::setManual($pdo, $id, 'bogus', 'Adam'), 'unknown status refused');
$pdo->exec("UPDATE shipments SET status = 'cancelled' WHERE id = {$id}");
check(!ShipmentTracking::setManual($pdo, $id, 'in_transit', 'Adam'), 'cancelled shipment cannot get a status');
$id = $add('Orlen Paczka');
ShipmentTracking::setManual($pdo, $id, 'in_transit', 'Adam');
$client->knows = 'NONE'; $client->failFor = [];
$trk->run();
check($row($id)['tracking_status'] === 'in_transit', 'scheduler with no carrier data keeps the manual status');

// Ikona wysyłki na liście zamówień: etap przesyłki (także ustawiony ręcznie) zmienia jej kolor.
$ship = static fn(array $shipments, string $pase = 'processing') => \Pase\Services\OrderIndicators::forOrder(
    ['pase_status' => $pase, 'status' => 'processing'], ['shipping_lines' => [['method_id' => 'flat_rate:1']]], $shipments)['shipping'];
check($ship([['status' => 'created', 'tracking_status' => null]])['state'] === 'info', 'created shipment without a stage stays blue');
check($ship([['status' => 'created', 'tracking_status' => 'in_transit']])['label'] === 'orders.indicator.shipping_in_transit', 'in transit label');
check($ship([['status' => 'created', 'tracking_status' => 'in_transit']])['state'] === 'transit', 'in transit is blue - green only once delivered');
check($ship([['status' => 'created', 'tracking_status' => 'delivered']]) === ['state' => 'ok', 'label' => 'orders.indicator.shipping_delivered'], 'delivered is green');
check($ship([['status' => 'created', 'tracking_status' => 'delivered'], ['status' => 'cancelled', 'tracking_status' => null]])['label'] === 'orders.indicator.shipping_delivered', 'cancelled parcels are ignored');
check($ship([['status' => 'created', 'tracking_status' => 'delivered'], ['status' => 'created', 'tracking_status' => null]])['label'] === 'orders.indicator.shipping_in_transit', 'not all parcels delivered yet');
check($ship([['status' => 'created', 'tracking_status' => 'returned']])['state'] === 'bad', 'returned parcel is red');
check($ship([['status' => 'created', 'tracking_status' => 'delivered']], 'completed')['state'] === 'ok', 'delivered green also on a completed order');

echo "\n{$checks} checks passed\n";
