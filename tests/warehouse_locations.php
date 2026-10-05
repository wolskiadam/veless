<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Lokalizacje w magazynie (regał/półka): kody, słownik, przypisanie produktów, import CSV, etykieta ZPL,
// plakietki i kolejność w asystencie pakowania, skaner telefonu - SQLite, bez sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\Packing;
use Pase\Services\PackingApi;
use Pase\Services\WarehouseLocations as W;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }
function throws(callable $fn): bool { try { $fn(); return false; } catch (RuntimeException) { return true; } }

// --- kody ---
check(W::makeCode('1', 'b') === 'R1-B' && W::makeCode('R2', 'a', '3') === 'R2-A-3' && W::makeCode(' 12 ', ' c ', '') === 'R12-C', 'Code from rack, shelf, level');
check(W::makeCode('Łąka', 'ś') === 'LAKA-S', 'Polish letters and spaces cleaned');
check(W::normalizeCode('r1 b') === 'R1-B' && W::normalizeCode('R1/B') === 'R1-B' && W::normalizeCode('1-b-3') === 'R1-B-3' && W::normalizeCode('CRMLOC:R1-B') === 'R1-B', 'Typed and scanned codes normalised');
check(W::codeFromQr('CRMLOC:R2-A') === 'R2-A' && W::codeFromQr('5901234567890') === null, 'Shelf QR told apart from product codes');
check(W::label(['rack' => 'R1', 'shelf' => 'B', 'level' => null]) === 'Regał 1, półka B' && W::label(['rack' => 'R1', 'shelf' => 'B', 'level' => '3']) === 'Regał 1, półka B, poziom 3', 'Readable label');

// --- baza ---
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, ean TEXT, images TEXT)');
$pdo->exec("INSERT INTO products (id, sku, name, ean) VALUES (1,'GB-1L','Gingerbread 1l','5901000000012'),(2,'MW-1L','Mulled Wine 1l','5901000000029'),
    (3,'CC-1L','Clementine 1l','012345678905'),(4,'SLC-500','Lavender 500 ml',NULL),(5,'GB-1L-V2','Gingerbread wariant','5901000000012')");
W::migrate($pdo);
W::migrate($pdo); // drugi raz bez błędu
$cols = array_column($pdo->query('PRAGMA table_info(products)')->fetchAll(PDO::FETCH_ASSOC), 'name');
check(in_array('location_id', $cols, true), 'products.location_id added');
$w = new W($pdo);

$a = $w->create('1', 'B', null, ' świece 1 l ');
$b = $w->create('1', 'A');
$c = $w->create('2', 'A', '3');
check(throws(fn() => $w->create('1', 'b')), 'Duplicate code refused');
check(throws(fn() => $w->create('', 'B')) && throws(fn() => $w->create('1', '  ')), 'Rack and shelf required');
$all = $w->all();
check(array_column($all, 'code') === ['R1-B', 'R1-A', 'R2-A-3'] && $all[0]['note'] === 'świece 1 l', 'New locations appended in walking order');
$w->saveOrder([$b => 5, $a => 10, $c => 20]);
check(array_column($w->all(), 'code') === ['R1-A', 'R1-B', 'R2-A-3'], 'Walking order saved');
$w->update($c, '2', 'A', '4', 'góra', 20);
check($w->find($c)['code'] === 'R2-A-4' && throws(fn() => $w->update($c, '1', 'a', '', '', 1)), 'Update, duplicate check on update');

// --- produkty ---
$r = $w->assignByCode('5901000000012', $a);
check(array_column($r['products'], 'id') === [1, 5] && $r['location']['code'] === 'R1-B', 'EAN assigns every product with that code');
$w->assignByCode('mw-1l', $b);
$w->assignByCode('0012345678905', $c);   // UPC zapisany jako EAN-13
check((int) $pdo->query('SELECT location_id FROM products WHERE id = 2')->fetchColumn() === $b
    && (int) $pdo->query('SELECT location_id FROM products WHERE id = 3')->fetchColumn() === $c, 'SKU (any case) and UPC/EAN-13 variants');
check(throws(fn() => $w->assignByCode('nieznany', $a)) && throws(fn() => $w->assignByCode('GB-1L', 999)), 'Unknown product or location refused');
$f = $w->forProducts([1, 2, 4]);
check($f[1]['code'] === 'R1-B' && $f[2]['code'] === 'R1-A' && !isset($f[4]), 'Locations for products');
check($w->all()[1]['products'] === 2, 'Product count per location');
check($w->delete($a) === 2 && $pdo->query('SELECT COUNT(*) FROM products WHERE location_id IS NULL')->fetchColumn() == 3, 'Deleting a location leaves products without one');

// --- import CSV ---
$res = $w->importCsv("\xEF\xBB\xBFSKU;Lokalizacja\nGB-1L;r1-b\nSLC-500\t3-c\n5901000000029,R1-A\nBRAK-SKU;R1-A\nzly wiersz\nX;R1\n");
check($res['assigned'] === 3 && $res['created'] === ['R1-B', 'R3-C'] && $res['missing'] === ['BRAK-SKU'] && count($res['bad']) === 2, 'CSV import: header, separators, new locations, missing products, bad rows');
$f = $w->forProducts([1, 2, 4, 5]); check(!isset($f[5]), 'SKU match is exact (variant untouched)');
check(($f[1]['code'] ?? '') === 'R1-B' && ($f[4]['code'] ?? '') === 'R3-C' && ($f[2]['code'] ?? '') === 'R1-A', 'Imported locations applied');

// --- etykieta ZPL ---
$zpl = W::zpl($w->findByCode('R1-B'), 203, 100);
check(str_starts_with($zpl, '^XA^CI28^PW799') && str_contains($zpl, '^BQN,2,') && str_contains($zpl, '^FDQA,CRMLOC:R1-B^FS') && str_contains($zpl, 'Regał 1, półka B'), 'Zebra label with QR, code and description');

// --- asystent pakowania ---
$pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
$pdo->exec('CREATE TABLE order_statuses (id INTEGER PRIMARY KEY, status_key TEXT, label TEXT, color TEXT, position INTEGER)');
$pdo->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT, role TEXT, password_hash TEXT, is_active INTEGER, session_version INTEGER, totp_secret TEXT)');
$pdo->exec("CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY, order_number TEXT, pase_number INTEGER, customer_name TEXT, payload TEXT, local_items TEXT, pase_status TEXT, lifecycle TEXT DEFAULT 'active', date_created TEXT)");
$pdo->prepare('INSERT INTO woo_orders (woo_order_id, order_number, customer_name, payload, pase_status) VALUES (1, ?, ?, ?, ?)')
    ->execute(['1001', 'Ala', json_encode(['line_items' => [
        ['name' => 'Lavender', 'quantity' => 1, 'sku' => 'SLC-500'],
        ['name' => 'Bez półki', 'quantity' => 1, 'sku' => 'NOPE'],
        ['name' => 'Gingerbread', 'quantity' => 2, 'sku' => 'gb-1l'],
        ['name' => 'Mulled', 'quantity' => 1, 'sku' => 'MW-1L'],
    ]]), 'new']);
Packing::migrate($pdo);
$w->saveOrder([$w->findByCode('R1-A')['id'] => 1, $w->findByCode('R1-B')['id'] => 2, $w->findByCode('R3-C')['id'] => 3]);
$o = (new Packing($pdo))->order(1);
check($o['hasLocations'] === true, 'Packing knows locations are in use');
check(array_column($o['items'], 'sku') === ['MW-1L', 'gb-1l', 'SLC-500', 'NOPE'], 'Items in walking order, items without a location last');
check($o['items'][0]['location']['code'] === 'R1-A' && $o['items'][3]['location'] === null && $o['items'][1]['idx'] === 2, 'Location badge data, original item index kept');
$api = static fn(array $ctx) => new PackingApi($pdo, $ctx + ['user_id' => 1, 'user_name' => 'Ola', 'can_edit' => true, 'is_admin' => false, 'panel' => false]);
[$code, $body] = $api([])->handle('loc_scan', ['code' => 'CRMLOC:r3-c']);
check($code === 200 && $body['location']['code'] === 'R3-C', 'Phone scans a shelf QR');
[$code] = $api([])->handle('loc_scan', ['code' => 'CRMLOC:R9-Z']);
check($code === 404, 'Unknown shelf reported');
[$code] = $api(['can_locations' => false])->handle('loc_assign', ['code' => 'MW-1L', 'location' => $body['location']['id']]);
check($code === 403, 'Assigning needs the locations permission');
[$code, $res] = $api(['can_locations' => true])->handle('loc_assign', ['code' => 'MW-1L', 'location' => $body['location']['id']]);
check($code === 200 && $res['products'][0]['sku'] === 'MW-1L' && $w->forProducts([2])[2]['code'] === 'R3-C', 'Phone assigns a scanned product to the shelf');
[$code] = $api(['can_locations' => true, 'can_edit' => false])->handle('loc_assign', ['code' => 'MW-1L', 'location' => 1]);
check($code === 403, 'View-only packing account cannot assign');

echo "\nPASS: {$checks} warehouse location checks\n";
