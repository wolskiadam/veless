<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Asystent pakowania: postęp, „Spakowano” ze zmianą statusu, kolejka na telefon, parowanie telefonu - SQLite, bez sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Repository\SettingsRepository;
use Pase\Services\Packing;
use Pase\Services\PackingApi;
use Pase\Support\AdminSession;

define('PASE_ROOT', sys_get_temp_dir() . '/crm-packing-' . bin2hex(random_bytes(4)));
$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
$pdo->exec('CREATE TABLE order_statuses (id INTEGER PRIMARY KEY, status_key TEXT, label TEXT, color TEXT, position INTEGER)');
$pdo->exec("INSERT INTO order_statuses (status_key,label,color,position) VALUES ('new','Nowe','#888',1),('packed','Spakowane','#2a7',2),('hold','Wstrzymane','#c33',3)");
$pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, ean TEXT, images TEXT)');
$pdo->exec("INSERT INTO products (id,sku,ean,images) VALUES (7,'GB-1L','5901234567890','[\"https://img/gb.jpg\"]')");
$pdo->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT, role TEXT, password_hash TEXT, is_active INTEGER, session_version INTEGER, totp_secret TEXT)');
$pdo->exec("INSERT INTO admin_users VALUES (1,'adam','Adam','admin','h1',1,1,NULL),(2,'ola','Ola','editor','h2',1,1,NULL)");
$pdo->exec("CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY, order_number TEXT, pase_number INTEGER, customer_name TEXT, source_type TEXT, payload TEXT, local_items TEXT, pase_status TEXT, lifecycle TEXT NOT NULL DEFAULT 'active', date_created TEXT)");
$woo = ['line_items' => [
    ['name' => 'GINGERBREAD 1l', 'quantity' => 2, 'sku' => 'GB-1L', 'product_id' => 7,
     'meta_data' => [['key' => '_reduced_stock', 'value' => '2'], ['key' => 'pa_zapach', 'display_key' => 'Zapach', 'display_value' => 'GINGERBREAD']]],
    ['name' => 'MULLED WINE 1l', 'quantity' => 1, 'sku' => 'MW-1L'],
    ['name' => 'Gratis (0 szt.)', 'quantity' => 0, 'sku' => 'X'],
], 'shipping_lines' => [['method_title' => 'InPost Paczkomat']], 'customer_note' => 'Proszę dobrze zapakować'];
$allegro = ['line_items' => [['name' => 'Świeca 500 ml', 'quantity' => 1, 'image' => 'https://a/1.jpg']],
    'delivery' => ['method' => ['name' => 'Allegro One Box']], 'messageToSeller' => 'Na prezent'];
$ins = $pdo->prepare('INSERT INTO woo_orders (woo_order_id, order_number, pase_number, customer_name, source_type, payload, local_items, pase_status) VALUES (?,?,?,?,?,?,?,?)');
$ins->execute([1, '73937001', 101, 'Paula Testowa', 'woocommerce', json_encode($woo), null, 'new']);
$ins->execute([2, 'a1b2c3d4-5555-6666', 102, 'Jan Nowak', 'allegro', json_encode($allegro), null, 'new']);

Packing::migrate($pdo);
Packing::migrate($pdo); // drugi raz bez błędu
$p = new Packing($pdo);
$settings = new SettingsRepository($pdo);

// --- dane zamówienia ---
$o = $p->order(1);
check(count($o['items']) === 2 && $o['items'][0]['qty'] === 2, 'Order items without zero-quantity lines');
check($o['items'][0]['ean'] === '5901234567890' && $o['items'][0]['image'] === 'https://img/gb.jpg', 'EAN and photo from warehouse product');
check($o['items'][0]['attrs'] === 'Zapach: GINGERBREAD', 'Variant attributes shown, technical meta hidden');
check($o['shipping'] === 'InPost Paczkomat' && $o['note'] === 'Proszę dobrze zapakować' && $o['status']['label'] === 'Nowe', 'Delivery, note and status');
$a = $p->order(2);
check($a['number'] === 'a1b2c3d4' && $a['shipping'] === 'Allegro One Box' && $a['note'] === 'Na prezent', 'Allegro order: short number, delivery, buyer message');
check($p->order(999) === null, 'Missing order');

// --- postęp ---
$o = $p->setPacked(1, 0, 5, 'Adam');
check($o['items'][0]['packed'] === 2, 'Count clamped to ordered quantity');
$o = $p->setPacked(1, 0, -3, 'Adam');
check($o['items'][0]['packed'] === 0, 'Count never below zero');
$p->setPacked(1, 0, 1, 'Adam');
check(!Packing::complete($p->order(1)), 'Incomplete order');
try { $p->setPacked(1, 2, 1, 'Adam'); check(false, 'Zero-quantity line rejected'); } catch (RuntimeException) { check(true, 'Zero-quantity line rejected'); }
$p->setPacked(1, 0, 2, 'Ola');
$p->setPacked(1, 1, 1, 'Ola');
check(Packing::complete($p->order(1)), 'Complete when every piece packed');

// --- „Spakowano” i status ---
$calls = [];
$changer = function (int $id, string $status) use (&$calls, $pdo): string {
    $calls[] = [$id, $status];
    $pdo->prepare('UPDATE woo_orders SET pase_status = ? WHERE woo_order_id = ?')->execute([$status, $id]);
    return 'Zmieniono w CRM';
};
[$o, $msg] = $p->markPacked(1, 'Ola', $settings, $changer);
check($o['state'] === 'packed' && $o['packedBy'] === 'Ola' && $msg === null && $calls === [], 'Packed without status change when none configured');
$settings->setMany([Packing::SETTING_DONE_STATUS => 'packed', Packing::SETTING_PROBLEM_STATUS => 'hold']);
$p->setPacked(1, 1, 1, 'Ola'); // bez zmian ilości, ale wraca do pakowania
[$o, $msg] = $p->markPacked(1, 'Ola', $settings, $changer);
check($calls === [[1, 'packed']] && $msg === 'Zmieniono w CRM' && $o['status']['key'] === 'packed', 'Configured status applied on packed');
[$o, $msg] = $p->markPacked(1, 'Ola', $settings, $changer);
check(count($calls) === 1, 'Status not re-sent when already set');
try { $p->markPacked(2, 'Ola', $settings, $changer); check(false, 'Incomplete order refused'); } catch (DomainException) { check(true, 'Incomplete order refused when everything is required'); }
[$o] = $p->markPacked(2, 'Ola', $settings, $changer, true);
check($o['state'] === 'packed', 'Packed anyway after confirmation');
$settings->setMany([Packing::SETTING_REQUIRE_ALL => '0']);
$p->reset(2, 'Ola');
[$o] = $p->markPacked(2, 'Ola', $settings, $changer);
check($o['state'] === 'packed', 'Setting allows packing incomplete orders');
$o = $p->setPacked(1, 0, 1, 'Ola');
check($o['state'] === 'open' && $o['packedAt'] === null, 'Changing a count reopens a packed order');
[$o, $msg] = $p->markProblem(1, '  Brak towaru ', 'Ola', $settings, $changer);
check($o['state'] === 'problem' && $o['problem'] === 'Brak towaru' && end($calls) === [1, 'hold'], 'Not packed: reason and configured status');
$o = $p->reset(1, 'Ola');
check(array_sum(array_column($o['items'], 'packed')) === 0 && $o['state'] === 'open', 'Reset clears progress');

// --- zdjęcia ---
@mkdir(Packing::photoDir(), 0777, true);
$fname = '1-0123456789abcdef.jpg';
file_put_contents(Packing::photoDir() . '/' . $fname, 'JPEG');
$o = $p->attachPhoto(1, $fname, 'Ola');
check(count($o['photos']) === 1 && $p->photoPath(1, 0) !== null && $p->photoPath(1, 1) === null, 'Photo attached and served by number');
$pdo->prepare('UPDATE packing_orders SET photos = ? WHERE woo_order_id = 1')->execute([json_encode([['file' => '../../.env']])]);
check($p->photoPath(1, 0) === null, 'Photo path traversal rejected');
$fname2 = '1-fedcba9876543210.jpg';
file_put_contents(Packing::photoDir() . '/' . $fname, 'JPEG');
file_put_contents(Packing::photoDir() . '/' . $fname2, 'JPEG2');
$pdo->prepare('UPDATE packing_orders SET photos = ? WHERE woo_order_id = 1')->execute([json_encode([['file' => $fname], ['file' => $fname2]])]);
$o = $p->deletePhoto(1, 0, 'Ola');
check(count($o['photos']) === 1 && !is_file(Packing::photoDir() . '/' . $fname) && file_get_contents($p->photoPath(1, 0)) === 'JPEG2', 'Photo deleted from list and disk, next one moves up');
try { $p->deletePhoto(1, 5, 'Ola'); check(false, 'Missing photo'); } catch (RuntimeException) { check(true, 'Deleting a missing photo reports an error'); }
[$code] = (new PackingApi($pdo, ['user_id' => 2, 'user_name' => 'Ola', 'can_edit' => false, 'is_admin' => false, 'panel' => true]))->handle('photo_delete', ['id' => 1, 'n' => 0]);
check($code === 403 && $p->photoPath(1, 0) !== null, 'View-only account cannot delete photos');
@unlink(Packing::photoDir() . '/' . $fname2);

// --- lista „Do spakowania” ---
$ins->execute([4, '73937009', 104, 'Ewa Wysłana', 'woocommerce', json_encode($woo), null, 'shipped']);
$ins->execute([5, '73937010', 105, 'Olek Stary', 'woocommerce', json_encode($woo), null, 'new']);
$ins->execute([6, '73937011', 106, 'Zosia Archiwum', 'woocommerce', json_encode($woo), null, 'new']);
$pdo->exec("UPDATE woo_orders SET lifecycle = 'archived' WHERE woo_order_id = 6");
$pdo->exec("UPDATE woo_orders SET date_created = '2026-09-01 10:00:00' WHERE woo_order_id = 5");
$pdo->exec("UPDATE woo_orders SET date_created = '2026-09-02 10:00:00' WHERE woo_order_id IN (1, 2)");
$pdo->exec("UPDATE woo_orders SET pase_status = 'new' WHERE woo_order_id IN (1, 2)");
$settings->setMany([Packing::SETTING_BROWSE_STATUSES => '']);
$st = $p->browseStatuses($settings);
check(in_array('new', $st, true) && in_array('hold', $st, true) && !in_array('packed', $st, true), 'Default: every status except shipped and the packed status');
$b = $p->browse($st);
check(array_column($b['orders'], 'id') === [5, 1, 2] && $b['total'] === 3, 'Oldest first; shipped and archived orders left out');
check($b['orders'][1]['lines'] === 2 && $b['orders'][1]['total'] === 3 && $b['orders'][1]['first'] === 'GINGERBREAD 1l', 'Item count and first product');
check($b['orders'][2]['number'] === 'a1b2c3d4', 'Short Allegro number in the list');
check(array_column($p->browse($st, 'Nowak')['orders'], 'id') === [2] && array_column($p->browse($st, '#105')['orders'], 'id') === [5], 'Search by customer and CRM number');
check(count($p->browse($st, '', 1)['orders']) === 1 && $p->browse($st, '', 1, 1)['orders'][0]['id'] === 1, 'Paging');
$settings->setMany([Packing::SETTING_BROWSE_STATUSES => 'hold,nope']);
check($p->browseStatuses($settings) === ['hold'] && $p->browse(['hold'])['total'] === 0, 'Chosen statuses only, unknown ignored');
$settings->setMany([Packing::SETTING_BROWSE_STATUSES => '']);
check($p->browse([])['total'] === 0, 'No statuses, no orders');
$pdo->exec("UPDATE woo_orders SET pase_status = 'packed' WHERE woo_order_id IN (1, 2)");

// --- kolejka na telefon ---
check($p->queueAdd(1, [1, 2, 2, 999]) === 2, 'Queue adds existing orders once');
check($p->queueAdd(1, [1]) === 0 && count($p->queue(1)) === 2 && $p->queue(2) === [], 'Queue per account, no duplicates');
$p->queueRemove(1, 1);
check(array_column($p->queue(1), 'id') === [2], 'Remove from queue');

// --- parowanie telefonu ---
$now = 1_800_000_000;
$code = $p->createPairCode(1, 2, $now);
$pair = $p->pair($code, 'iPhone', $now + 10);
check($pair['user_id'] === 1 && $pair['order_id'] === 2 && strlen($pair['token']) === 64, 'QR code pairs a phone and carries the order');
$row = $pdo->query('SELECT * FROM packing_devices')->fetch(PDO::FETCH_ASSOC);
check($row['token_hash'] === hash('sha256', $pair['token']) && !str_contains(json_encode($row), $pair['token']), 'Only token hash stored');
try { $p->pair($code, 'iPhone', $now + 20); check(false, 'Code single-use'); } catch (RuntimeException) { check(true, 'QR code is single-use'); }
$late = $p->createPairCode(1, null, $now);
try { $p->pair($late, 'X', $now + Packing::PAIR_TTL + 1); check(false, 'Expired'); } catch (RuntimeException) { check(true, 'Expired QR code rejected'); }
check($p->deviceByToken($pair['token'], $now + 100)['user']['id'] === 1, 'Phone recognised by token');
check($p->deviceByToken(str_repeat('a', 64), $now) === null && $p->deviceByToken('x', $now) === null, 'Unknown token rejected');
check($p->deviceByToken($pair['token'], $now + Packing::DEVICE_DAYS * 86400 + 200) === null, 'Long unused phone must pair again');
$pdo->exec("UPDATE admin_users SET password_hash = 'changed' WHERE id = 1");
check($p->deviceByToken($pair['token'], $now + 200) === null, 'Password change disconnects phones');
$pdo->exec("UPDATE admin_users SET password_hash = 'h1' WHERE id = 1");
check($p->deviceByToken($pair['token'], $now + 200) !== null, 'Back when account fingerprint matches');
check(count($p->devices(1)) === 1 && $p->devices(2) === [], 'Device list per account');
check(!$p->revokeDevice(2, (int) $row['id']), 'Other account cannot disconnect my phone');
check($p->revokeDevice(1, (int) $row['id']) && $p->deviceByToken($pair['token'], $now + 300) === null, 'Disconnected phone loses access');
$pdo->exec('UPDATE admin_users SET is_active = 0 WHERE id = 2');
try { $p->pair($p->createPairCode(2), 'X'); check(false, 'Inactive'); } catch (RuntimeException) { check(true, 'Inactive account cannot pair'); }
$pdo->exec('UPDATE admin_users SET is_active = 1 WHERE id = 2');

// --- API: uprawnienia i akcje ---
$ctx = ['user_id' => 2, 'user_name' => 'Ola', 'can_edit' => false, 'is_admin' => false, 'panel' => true];
[$code, $body] = (new PackingApi($pdo, $ctx))->handle('set', ['id' => 1, 'idx' => 0, 'count' => 1]);
check($code === 403, 'View-only account cannot pack');
[$code, $body] = (new PackingApi($pdo, $ctx))->handle('order', ['id' => 1]);
check($code === 200 && $body['order']['id'] === 1, 'View-only account can look');
$phone = ['user_id' => 2, 'user_name' => 'Ola (telefon)', 'can_edit' => true, 'is_admin' => false, 'panel' => false];
[$code] = (new PackingApi($pdo, $phone))->handle('pair_code', []);
check($code === 403, 'Phone cannot create pairing codes');
[$code] = (new PackingApi($pdo, $phone))->handle('settings', []);
check($code === 403, 'Phone cannot read settings');
[$code, $body] = (new PackingApi($pdo, $phone))->handle('set', ['id' => 1, 'idx' => 1, 'count' => 1]);
check($code === 200 && $body['order']['items'][1]['packed'] === 1, 'Phone packs items');
[$code, $body] = (new PackingApi($pdo, $phone))->handle('find', ['q' => '73937001']);
check($code === 200 && $body['id'] === 1, 'Find by shop order number');
[, $body] = (new PackingApi($pdo, $phone))->handle('find', ['q' => 'a1b2c3d4']);
check(($body['id'] ?? null) === 2, 'Find Allegro order by short number');
[, $body] = (new PackingApi($pdo, $phone))->handle('find', ['q' => '#101']);
check(($body['id'] ?? null) === 1, 'Find by CRM number');
$editor = ['user_id' => 2, 'user_name' => 'Ola', 'can_edit' => true, 'is_admin' => false, 'panel' => true];
[$code] = (new PackingApi($pdo, $editor))->handle('settings_save', ['done_status' => '']);
check($code === 403, 'Only admin changes packing settings');
$admin = ['user_id' => 1, 'user_name' => 'Adam', 'can_edit' => true, 'is_admin' => true, 'panel' => true];
[$code] = (new PackingApi($pdo, $admin))->handle('settings_save', ['done_status' => 'nope']);
check($code === 422, 'Unknown status rejected');
[$code, $body] = (new PackingApi($pdo, $admin))->handle('settings_save', ['done_status' => 'packed', 'problem_status' => '', 'require_all' => '1']);
check($code === 200 && $body['settings']['done_status'] === 'packed' && $body['settings']['require_all'] === true, 'Admin saves packing settings');
[$code, $body] = (new PackingApi($pdo, $admin, null, 'https://crm.test/public/'))->handle('pair_code', ['id' => 1]);
check($code === 200 && str_starts_with($body['url'], 'https://crm.test/public/pack/pair.php?c='), 'Pair link points to phone page');
[$code, $body] = (new PackingApi($pdo, $admin))->handle('packed', ['id' => 2]);
check($code === 409 && !empty($body['incomplete']), 'Incomplete packing reported to the screen');
[$code, $body] = (new PackingApi($pdo, $admin))->handle('queue_add', ['ids' => [1, 2]]);
check($code === 200 && $body['added'] === 1 && count($body['queue']) === 2, 'Send to phone');
[$code, $body] = (new PackingApi($pdo, $phone))->handle('browse', ['status' => 'new']);
check($code === 200 && array_column($body['orders'], 'id') === [5] && in_array('new', array_column($body['statuses'], 'key'), true), 'Phone browses orders to pack by status');
[$code, $body] = (new PackingApi($pdo, $admin))->handle('settings_save', ['done_status' => 'packed', 'browse_statuses' => ['new', 'hold'], 'require_all' => '1']);
check($body['settings']['browse_statuses'] === ['new', 'hold'], 'Admin chooses statuses for the list');
[$code] = (new PackingApi($pdo, $admin))->handle('settings_save', ['browse_statuses' => ['zly']]);
check($code === 422, 'Unknown list status rejected');
[$code] = (new PackingApi($pdo, $admin))->handle('nope', []);
check($code === 400, 'Unknown action');

// --- gabaryt paczki: sugestia kartonu z wymiarów i wagi produktów ---
use Pase\Services\PackingCartons;
$cartons = PackingCartons::defaults();
$item = static fn(string $n, int $q, ?array $d, ?float $w) => ['name' => $n, 'qty' => $q, 'dims' => $d, 'weight' => $w];
$r = PackingCartons::suggest([$item('Świeca', 2, [10, 10, 7], 0.5)], $cartons);
check($r['suggested'] === 'Gabaryt A' && $r['weight'] === 1.0 && $r['message'] === null, 'Small order -> smallest carton (A), total weight');
$r = PackingCartons::suggest([$item('Słoik', 1, [12, 12, 15], 1.2)], $cartons);
check($r['suggested'] === 'Gabaryt B' && $r['options'][0]['fits'] === false, 'Item taller than A in every rotation -> B');
$r = PackingCartons::suggest([$item('Płaski', 1, [64, 7, 38], 0.3)], $cartons);
check($r['suggested'] === 'Gabaryt A', 'Rotation: 64x7x38 lies flat in A (8x38x64)');
// 4 × 20x20x7 = 11 200 cm³ to mniej niż objętość A (19 456), ale w 8 cm wysokości leżą tylko 3 (1 × 3 na 38 × 64)
$r = PackingCartons::suggest([$item('Kafel', 3, [20, 20, 7], 0.1)], $cartons);
check($r['suggested'] === 'Gabaryt A', 'Three 20x20 tiles lie side by side in A');
$r = PackingCartons::suggest([$item('Kafel', 4, [20, 20, 7], 0.1)], $cartons);
check($r['suggested'] === 'Gabaryt B', 'Fourth tile needs a second layer -> B (volume alone would say A)');
$r = PackingCartons::suggest([$item('Wosk 5 kg', 6, [15, 15, 15], 5.0)], $cartons);
check($r['suggested'] === null && $r['options'][2]['why'] !== null && str_contains($r['options'][2]['why'], 'za ciężko'), 'Too heavy for every carton -> no suggestion');
$r = PackingCartons::suggest([$item('Duży', 1, [70, 10, 10], 1)], $cartons);
check($r['suggested'] === null && str_contains((string) $r['message'], 'Duży'), 'Item bigger than every carton named');
$r = PackingCartons::suggest([$item('Bez wymiarów', 1, null, null)], $cartons);
check($r['suggested'] === null && $r['options'][0]['fits'] === null && $r['missingDims'] === ['Bez wymiarów'] && $r['message'] !== null, 'No dimensions -> clear note, no guess');
$r = PackingCartons::suggest([$item('A', 1, [5, 5, 5], null), $item('B', 1, null, 0.2)], $cartons);
check($r['suggested'] === 'Gabaryt A' && $r['missingDims'] === ['B'] && $r['missingWeight'] === ['A'], 'Partial data: suggestion from known items + list of gaps');
check(PackingCartons::fits(array_fill(0, 8, [19, 19, 32]), [38, 38, 64]) && !PackingCartons::fits(array_fill(0, 9, [19, 19, 32]), [38, 38, 64]), 'Box packing: 8 blocks fill the carton exactly, 9th does not');
// gramy zapisane jako kg (sklep w gramach): 240 „kg” świecy 6 × 6 × 6 cm liczymy jako 240 g
$r = PackingCartons::suggest([$item('Palo Santo', 1, [6.15, 6.15, 6.27], 240.0), $item('Sandał', 1, null, null)], $cartons);
check($r['suggested'] === 'Gabaryt A' && $r['weight'] === 0.24 && count($r['suspectWeight']) === 1 && $r['missingDims'] === ['Sandał'] && $r['message'] === null,
    'Implausible 240 kg candle counted as 240 g, product without data only noted');
check(\Pase\Support\Units::implausibleWeight(240, 6.15, 6.15, 6.27) && !\Pase\Support\Units::implausibleWeight(0.24, 6.15, 6.15, 6.27)
    && !\Pase\Support\Units::implausibleWeight(20, 30, 20, 15) && !\Pase\Support\Units::implausibleWeight(240, null, 6, 6), 'Implausible weight detection');
try { PackingCartons::normalize([['name' => '', 'dims' => [1, 2, 3]]]); check(false, 'empty name'); } catch (RuntimeException) { check(true, 'Carton without name rejected'); }
try { PackingCartons::normalize([['name' => 'X', 'dims' => [1, 0, 3]]]); check(false, 'zero'); } catch (RuntimeException) { check(true, 'Carton with zero dimension rejected'); }
$n = PackingCartons::normalize([['name' => 'Duży', 'dims' => ['40', '30,5', '20'], 'max_kg' => ''], ['name' => 'Mały', 'dims' => [10, 10, 10], 'max_kg' => '5']]);
check($n[0]['name'] === 'Mały' && $n[1]['dims'] === [40.0, 30.5, 20.0] && $n[1]['max_kg'] === null && $n[0]['max_kg'] === 5.0, 'Cartons sorted by size, comma decimals, empty kg = no limit');

// w zamówieniu: wymiary z magazynu (po SKU, a w Woo po ID produktu w sklepie)
foreach (['length', 'width', 'height', 'weight', 'woo_product_id', 'woo_variation_id'] as $col) {
    $pdo->exec("ALTER TABLE products ADD COLUMN $col REAL NULL");
}
$pdo->exec("UPDATE products SET length = 10, width = 10, height = 7, weight = 0.6 WHERE sku = 'GB-1L'");
$pdo->exec("INSERT INTO products (id, sku, length, width, height, weight, woo_product_id) VALUES (8, 'INNE', 30, 20, 15, 1.5, 555)");
$pdo->exec("UPDATE woo_orders SET local_items = " . $pdo->quote(json_encode([
    ['name' => 'GINGERBREAD 1l', 'quantity' => 2, 'sku' => 'GB-1L'],
    ['name' => 'Bez SKU', 'quantity' => 1, 'sku' => '', 'product_id' => 555],
])) . " WHERE woo_order_id = 1");
$o = $p->order(1);
check($o['carton']['suggested'] === 'Gabaryt B' && abs($o['carton']['weight'] - 2.7) < 1e-9 && $o['carton']['chosen'] === null, 'Order suggestion from warehouse dimensions (SKU + Woo product id)');
[$code, $body] = (new PackingApi($pdo, $phone))->handle('carton', ['id' => 1, 'carton' => 'Gabaryt C']);
check($code === 200 && $body['order']['carton']['chosen'] === 'Gabaryt C' && $body['order']['carton']['suggested'] === 'Gabaryt B', 'Packer picks another carton, suggestion stays');
[$code] = (new PackingApi($pdo, $phone))->handle('carton', ['id' => 1, 'carton' => 'Nie ma']);
check($code === 422, 'Unknown carton rejected');
[$code] = (new PackingApi($pdo, $ctx))->handle('carton', ['id' => 1, 'carton' => 'Gabaryt A']);
check($code === 403, 'View-only account cannot pick a carton');
[, $body] = (new PackingApi($pdo, $phone))->handle('carton', ['id' => 1, 'carton' => '']);
check($body['order']['carton']['chosen'] === null, 'Back to suggestion');
[$code, $body] = (new PackingApi($pdo, $admin))->handle('settings_save', ['done_status' => 'packed', 'cartons' => json_encode([['name' => 'Karton M', 'dims' => [30, 25, 20], 'max_kg' => 10]])]);
check($code === 200 && array_column($body['settings']['cartons'], 'name') === ['Karton M'] && $p->order(1)['carton']['suggested'] === 'Karton M', 'Admin replaces the carton list');
[$code, $body] = (new PackingApi($pdo, $admin))->handle('settings_save', ['done_status' => 'packed', 'cartons' => json_encode([['name' => 'Zły', 'dims' => [0, 1, 1]]])]);
check($code === 422 && str_contains($body['error'], 'Zły') && array_column(PackingCartons::load($settings), 'name') === ['Karton M'], 'Invalid carton list rejected, old list kept');
(new PackingApi($pdo, $admin))->handle('settings_save', ['done_status' => 'packed', 'cartons' => '[]']);
check($p->order(1)['carton'] === null, 'Empty carton list hides the section');

exec('rm -rf ' . escapeshellarg(PASE_ROOT));
echo "\nPASS: {$checks} packing checks\n";
