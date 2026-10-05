<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Asystent pakowania przez HTTP: prawdziwe auth.php, packing.php, packing_api.php i strony telefonu (public/pack),
// baza SQLite w katalogu tymczasowym. Parowanie telefonu kodem QR, ciasteczko telefonu, CSRF, uprawnienia.
// Gdy jest Playwright (NODE_PATH), dodatkowo klika ekran w przeglądarce (komputer + telefon) - tests/packing_ui.js.
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Pase\\')) { require $root . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Support\PagePermissions;

$checks = 0;
function ok(bool $condition, string $name): void { global $checks; if (!$condition) { throw new RuntimeException('FAIL: ' . $name); } ++$checks; echo "OK: {$name}\n"; }
function client(): CurlHandle {
    $h = curl_init();
    curl_setopt_array($h, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10, CURLOPT_PROXY => '', CURLOPT_HEADER => true]);
    return $h;
}
function request(CurlHandle $h, string $path, ?array $data = null, array $headers = []): array {
    global $base;
    curl_setopt($h, CURLOPT_URL, $base . $path);
    curl_setopt($h, CURLOPT_POST, $data !== null);
    if ($data !== null) { curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($data)); }
    curl_setopt($h, CURLOPT_HTTPHEADER, $headers);
    $res = curl_exec($h);
    if ($res === false) { throw new RuntimeException(curl_error($h)); }
    $len = curl_getinfo($h, CURLINFO_HEADER_SIZE);
    $body = substr($res, $len);
    return ['status' => curl_getinfo($h, CURLINFO_RESPONSE_CODE), 'headers' => substr($res, 0, $len), 'body' => $body,
            'json' => json_decode($body, true)];
}
function login(string $user): CurlHandle {
    $h = client();
    $page = request($h, 'admin/login.php');
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $page['body'], $m);
    $r = request($h, 'admin/login.php', ['csrf' => $m[1] ?? '', 'username' => $user, 'password' => 'Only-Test-Password-42']);
    if (!in_array($r['status'], [302, 303], true)) { throw new RuntimeException('Login failed for ' . $user); }
    return $h;
}
function csrfOf(CurlHandle $h): string {
    $page = request($h, 'admin/packing.php?ids=1');
    preg_match('/"csrf":"([a-f0-9]+)"/', $page['body'], $m);
    return $m[1] ?? '';
}

$temp = sys_get_temp_dir() . '/crm-packing-http-' . bin2hex(random_bytes(6));
foreach (['public/admin/assets', 'public/pack/assets', 'config', 'storage/security', 'lang'] as $d) { mkdir("$temp/$d", 0700, true); }
$process = null;
try {
    file_put_contents("$temp/.env", "TOTP_REQUIRE_ADMIN=0\n");
    foreach (['auth.php', 'login.php', 'packing.php', 'packing_api.php', 'packing_photo.php'] as $f) { copy("$root/public/admin/$f", "$temp/public/admin/$f"); }
    copy("$root/public/admin/assets/qrcodegen.js", "$temp/public/admin/assets/qrcodegen.js");
    foreach (glob("$root/public/pack/*.php") as $f) { copy($f, "$temp/public/pack/" . basename($f)); }
    foreach (glob("$root/public/pack/assets/*") as $f) { copy($f, "$temp/public/pack/assets/" . basename($f)); }
    foreach (glob("$root/lang/*.php") as $f) { copy($f, "$temp/lang/" . basename($f)); }
    file_put_contents("$temp/public/admin/bootstrap_admin.php", '<?php \Pase\Support\PagePermissions::migrate($pdo);');

    // Konfiguracja jak config/config.php, ale z SQLite i kodem z repozytorium.
    $boot = '<?php if (!defined("PASE_ROOT")) { define("PASE_ROOT", ' . var_export($temp, true) . ');'
        . 'spl_autoload_register(static function($c) { if (str_starts_with($c, "Pase\\\\")) require ' . var_export("$root/src/", true)
        . ' . str_replace("\\\\", "/", substr($c, 5)) . ".php"; });'
        . '\Pase\Support\Env::load(PASE_ROOT . "/.env");'
        . 'if (!function_exists("t")) { function t($k, $v = []) { return \Pase\Support\I18n::t($k, $v); } } }'
        . 'return ["db" => [], "allegro" => []];';
    file_put_contents("$temp/config/config.php", $boot);
    file_put_contents("$temp/config/database.php", '<?php return static fn($db) => new PDO("sqlite:" . PASE_ROOT . "/test.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);');
    file_put_contents("$temp/public/admin/db_admin.php", '<?php $config = require dirname(__DIR__, 2) . "/config/config.php"; $pdo = (require PASE_ROOT . "/config/database.php")($config["db"]);');

    $pdo = new PDO("sqlite:$temp/test.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT, role TEXT, password_hash TEXT, is_active INTEGER, session_version INTEGER, totp_secret TEXT, totp_last_step INTEGER DEFAULT -1, totp_recovery_hashes TEXT, last_login TEXT, totp_required INTEGER NOT NULL DEFAULT 0, email TEXT)');
    $ins = $pdo->prepare('INSERT INTO admin_users (id,username,display_name,role,password_hash,is_active,session_version) VALUES (?,?,?,?,?,1,1)');
    foreach ([1 => ['boss', 'Adam', 'admin'], 2 => ['ola', 'Ola', 'editor'], 3 => ['vi', 'Wiktor', 'viewer']] as $id => [$u, $n, $role]) {
        $ins->execute([$id, $u, $n, $role, password_hash('Only-Test-Password-42', PASSWORD_DEFAULT)]);
    }
    PagePermissions::migrate($pdo);
    \Pase\Services\AuditTrail::migrate($pdo);
    $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
    $pdo->exec('CREATE TABLE order_statuses (id INTEGER PRIMARY KEY, status_key TEXT, label TEXT, color TEXT, position INTEGER)');
    $pdo->exec("INSERT INTO order_statuses (status_key,label,color,position) VALUES ('new','Nowe','#8a8f98',1),('processing','W realizacji','#c28a2b',2),('packed','Spakowane','#2f8f5b',3),('hold','Wstrzymane','#b8412c',4)");
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, ean TEXT, images TEXT, length REAL, width REAL, height REAL, weight REAL, woo_product_id INTEGER, woo_variation_id INTEGER)');
    $svgImg = static fn(string $c) => 'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="#f4efe6"/><rect x="3" y="2" width="4" height="7" rx="1" fill="' . $c . '"/><rect x="4.6" y="1" width=".8" height="1.4" fill="#333"/></svg>');
    $pins = $pdo->prepare('INSERT INTO products (id,sku,ean,images) VALUES (?,?,?,?)');
    foreach ([[1, 'GB-1L', '5901000000012', '#b5651d'], [2, 'MW-1L', '5901000000029', '#7b2d3a'], [3, 'CC-1L', '5901000000036', '#e8964a'],
              [4, 'SLC-500', '5901000000043', '#9c88c9'], [5, 'ZB-1L', '5901000000050', '#6a8f5a']] as [$pid, $sku, $ean, $c]) {
        $pins->execute([$pid, $sku, $ean, json_encode([$svgImg($c)])]);
    }
    // wymiary i waga do „Gabaryt paczki” (świeca 1 l: 10 × 10 × 12 cm, 0,9 kg)
    $pdo->exec("UPDATE products SET length = 10, width = 10, height = 12, weight = 0.9");
    $pdo->exec("UPDATE products SET length = 8, width = 8, height = 10, weight = 0.5 WHERE sku = 'SLC-500'");
    $pdo->exec("CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY, order_number TEXT, pase_number INTEGER, customer_name TEXT, source_type TEXT, payload TEXT, local_items TEXT, pase_status TEXT, integration_id INTEGER DEFAULT 0, lifecycle TEXT NOT NULL DEFAULT 'active', date_created TEXT)");
    $line = static fn(string $name, int $q, string $sku, int $pid, string $zapach, string $ilosc) => ['name' => $name, 'quantity' => $q, 'sku' => $sku, 'product_id' => $pid,
        'meta_data' => [['key' => 'pa_zapach', 'display_key' => 'Zapach', 'display_value' => $zapach], ['key' => 'pa_ilosc', 'display_key' => 'Ilość', 'display_value' => $ilosc]]];
    $o1 = ['line_items' => [
        $line('GINGERBREAD 1l', 1, 'GB-1L', 1, 'GINGERBREAD', '1 l'), $line('MULLED WINE 1l', 2, 'MW-1L', 2, 'MULLED WINE', '1 l'),
        $line('CLEMENTINE CUPCAKE 1l', 1, 'CC-1L', 3, 'CLEMENTINE CUPCAKE', '1 l'), $line('Secret Lavender Cream 500 ml', 1, 'SLC-500', 4, 'Secret Lavender Cream', '500 ml'),
        $line('Z’aara Bergamot & White Tea 1l', 1, 'ZB-1L', 5, 'Z’aara Bergamot & White Tea', '1 l'),
        ['name' => 'Pumpkin Woods 1l', 'quantity' => 1, 'sku' => 'PW-1L'],
    ], 'shipping_lines' => [['method_title' => 'InPost Paczkomat (Bezpłatnie)']], 'customer_note' => 'Proszę zapakować na prezent 🎁'];
    $o2 = ['line_items' => [$line('GINGERBREAD 1l', 2, 'GB-1L', 1, 'GINGERBREAD', '1 l')], 'shipping_lines' => [['method_title' => 'Kurier DPD']]];
    $o3 = ['line_items' => [['name' => 'Świeca sojowa 500 ml', 'quantity' => 1, 'sku' => 'SS-500']], 'delivery' => ['method' => ['name' => 'Allegro One Box']]];
    $oi = $pdo->prepare('INSERT INTO woo_orders (woo_order_id,order_number,pase_number,customer_name,source_type,payload,pase_status) VALUES (?,?,?,?,?,?,?)');
    $oi->execute([1, '73937001', 1201, 'Paula Testowa', 'woocommerce', json_encode($o1), 'processing']);
    $oi->execute([2, '73937002', 1202, 'Marek Zieliński', 'woocommerce', json_encode($o2), 'processing']);
    $oi->execute([3, 'b7e1c2d4-11aa-22bb', 1203, 'Anna Kowalska', 'allegro', json_encode($o3), 'new']);
    $oi->execute([4, '73937004', 1204, 'Tomasz Wiśniewski', 'woocommerce', json_encode($o2), 'new']);
    $oi->execute([5, '73937005', 1205, 'Karolina Lewandowska', 'woocommerce', json_encode($o1), 'processing']);

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'upload_max_filesize=20M', '-d', 'post_max_size=20M', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes);
    fclose($pipes[0]);
    for ($i = 0; $i < 100; ++$i) { $p = @stream_socket_client('tcp://' . $address, $e1, $e2, 0.1); if ($p) { fclose($p); break; } usleep(10000); }

    // --- panel ---
    $boss = login('boss');
    $page = request($boss, 'admin/packing.php?ids=1,2');
    ok($page['status'] === 200 && str_contains($page['body'], 'window.PACK_CFG') && str_contains($page['body'], '"ids":[1,2]'), 'Packing screen opens with selected orders');
    $csrf = csrfOf($boss);
    $r = request($boss, 'admin/packing_api.php?action=order&id=1');
    ok($r['json']['ok'] && count($r['json']['order']['items']) === 6 && $r['json']['order']['items'][1]['qty'] === 2, 'API returns order items');
    ok(request($boss, 'admin/packing_api.php', ['action' => 'set', 'id' => 1, 'idx' => 0, 'count' => 1])['status'] === 419, 'Write without CSRF token refused');
    ok(request($boss, 'admin/packing_api.php?action=set&id=1&idx=0&count=1')['status'] === 405, 'Write by GET refused');
    $r = request($boss, 'admin/packing_api.php', ['action' => 'set', 'id' => 1, 'idx' => 0, 'count' => 1], ['X-CSRF-Token: ' . $csrf]);
    ok($r['json']['ok'] && $r['json']['order']['items'][0]['packed'] === 1, 'Panel packs an item');
    ok(request(client(), 'admin/packing_api.php?action=order&id=1')['status'] === 302, 'Not logged in: redirected to login');

    // --- parowanie telefonu kodem QR ---
    $r = request($boss, 'admin/packing_api.php', ['action' => 'pair_code', 'id' => 2], ['X-CSRF-Token: ' . $csrf]);
    ok($r['json']['ok'] && str_contains($r['json']['url'], '/pack/pair.php?c='), 'QR link created');
    $pairPath = substr($r['json']['url'], strpos($r['json']['url'], 'pack/pair.php'));
    $phone = client();
    $r = request($phone, 'pack/index.php');
    ok(str_contains($r['body'], 'nie jest jeszcze sparowany'), 'Unpaired phone sees pairing instructions');
    ok(request($phone, 'pack/api.php?action=queue')['status'] === 401, 'Unpaired phone has no API access');
    $r = request($phone, $pairPath, null, ['User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)']);
    ok($r['status'] === 303 && str_contains($r['headers'], 'Location: index.php?order=2') && preg_match('/Set-Cookie: CRM_PACK=[a-f0-9]{64};.*HttpOnly/i', $r['headers']), 'Scanning QR pairs phone and opens the order');
    ok(request(client(), $pairPath)['status'] === 410, 'QR code cannot be reused on another phone');
    $r = request($phone, 'pack/index.php?order=2');
    ok($r['status'] === 200 && str_contains($r['body'], '"mode":"phone"') && str_contains($r['body'], '"openId":2'), 'Phone packing screen');
    $r = request($phone, 'pack/api.php?action=order&id=2');
    ok($r['json']['ok'] && $r['json']['order']['number'] === '73937002', 'Phone reads the order');
    ok(request($phone, 'pack/api.php', ['action' => 'set', 'id' => 2, 'idx' => 0, 'count' => 2])['status'] === 405, 'Phone write without app header refused');
    $r = request($phone, 'pack/api.php', ['action' => 'set', 'id' => 2, 'idx' => 0, 'count' => 2], ['X-Requested-With: pack']);
    ok($r['json']['ok'] && $r['json']['order']['items'][0]['packed'] === 2, 'Phone packs items');
    ok(request($phone, 'pack/api.php', ['action' => 'pair_code'], ['X-Requested-With: pack'])['status'] === 403, 'Phone cannot create QR codes');
    $r = request($phone, 'pack/api.php?action=browse');
    ok($r['json']['ok'] && $r['json']['total'] === 5, 'Phone lists orders to pack');

    // --- lokalizacje w magazynie: plakietki w zamówieniu, skaner półek na telefonie ---
    \Pase\Services\WarehouseLocations::migrate($pdo);
    $wl = new \Pase\Services\WarehouseLocations($pdo);
    $locA = $wl->create('1', 'A'); $wl->create('1', 'B');
    $wl->assign(2, $locA);
    $r = request($phone, 'pack/api.php?action=order&id=1');
    ok($r['json']['order']['hasLocations'] && $r['json']['order']['items'][0]['sku'] === 'MW-1L' && $r['json']['order']['items'][0]['location']['code'] === 'R1-A', 'Order items carry shelf and start with located ones');
    $r = request($phone, 'pack/api.php?action=loc_scan&code=' . urlencode('CRMLOC:R1-B'));
    ok($r['json']['ok'] && $r['json']['location']['label'] === 'Regał 1, półka B', 'Phone reads a shelf QR');
    ok(request($phone, 'pack/api.php', ['action' => 'loc_assign', 'code' => 'CC-1L', 'location' => $r['json']['location']['id']])['status'] === 405, 'Shelf assignment needs the app header');
    $r = request($phone, 'pack/api.php', ['action' => 'loc_assign', 'code' => '5901000000036', 'location' => $r['json']['location']['id']], ['X-Requested-With: pack']);
    ok($r['json']['ok'] && $wl->forProducts([3])[3]['code'] === 'R1-B', 'Phone assigns a product to the shelf');
    $wl->assign(3, null);
    ok(str_contains(request($phone, 'pack/index.php')['body'], 'pkBrowseLoc'), 'Shelf scanner offered to an account with locations access');
    $r = request($boss, 'admin/packing_api.php', ['action' => 'loc_assign', 'code' => 'CC-1L', 'location' => $locA], ['X-CSRF-Token: ' . $csrf]);
    ok($r['json']['ok'] ?? false, 'Panel can assign too (session + CSRF)');
    $wl->assign(3, null);
    $wl->assign(2, null);   // przeglądarka zaczyna bez lokalizacji (kolejność jak w zamówieniu); półkę nada skaner na telefonie

    // --- „Na telefon” + „Spakowano” ze zmianą statusu ---
    request($boss, 'admin/packing_api.php', ['action' => 'settings_save', 'done_status' => 'packed', 'problem_status' => 'hold', 'require_all' => '1'], ['X-CSRF-Token: ' . $csrf]);
    $r = request($boss, 'admin/packing_api.php', ['action' => 'queue_add', 'ids' => [1, 3]], ['X-CSRF-Token: ' . $csrf]);
    ok($r['json']['added'] === 2 && $r['json']['devices'] === 1, 'Orders sent to the paired phone');
    $r = request($phone, 'pack/api.php?action=queue');
    ok(array_column($r['json']['queue'], 'id') === [1, 3], 'Phone sees the queue');
    $r = request($phone, 'pack/api.php', ['action' => 'packed', 'id' => 2], ['X-Requested-With: pack']);
    ok($r['json']['ok'] && $r['json']['order']['state'] === 'packed', 'Phone marks order packed');
    ok($pdo->query('SELECT pase_status FROM woo_orders WHERE woo_order_id = 2')->fetchColumn() === 'packed', 'Order status changed as configured');
    $audit = $pdo->query("SELECT actor_id, actor_name FROM audit_events WHERE order_id = 2 ORDER BY id DESC")->fetch(PDO::FETCH_ASSOC);
    ok((int) $audit['actor_id'] === 1 && $audit['actor_name'] === 'Adam (telefon)', 'Change history names the account and the phone');
    $r = request($phone, 'pack/api.php', ['action' => 'packed', 'id' => 3], ['X-Requested-With: pack']);
    ok($r['status'] === 409, 'Incomplete order needs confirmation');

    // --- uprawnienia ---
    $perms = array_fill_keys(array_keys(PagePermissions::registry()), 'hidden');
    PagePermissions::save($pdo, 3, array_merge($perms, ['orders' => 'view', 'packing' => 'view']));
    $vi = login('vi');
    $r = request($vi, 'admin/packing.php?ids=1');
    ok($r['status'] === 200 && str_contains($r['body'], '"canEdit":false'), 'View-only account sees packing read-only');
    ok(request($vi, 'admin/packing_api.php', ['action' => 'set', 'id' => 1, 'idx' => 0, 'count' => 1, 'csrf' => csrfOf($vi)])['status'] === 403, 'View-only account cannot pack');
    ok(request($vi, 'admin/packing_api.php', ['action' => 'loc_assign', 'code' => 'CC-1L', 'location' => 1], ['X-CSRF-Token: ' . csrfOf($vi)])['status'] === 403, 'Account without locations permission cannot assign shelves');
    ok(!str_contains(request($vi, 'admin/packing.php?ids=1')['body'], 'id="pkBrowseLoc"'), 'Shelf scanner hidden without locations permission');
    PagePermissions::save($pdo, 3, array_merge($perms, ['orders' => 'view']));
    ok(request($vi, 'admin/packing.php')['status'] === 403, 'Account without packing permission is blocked');

    // --- odłączenie ---
    $dev = request($boss, 'admin/packing_api.php?action=devices')['json']['devices'][0];
    ok($dev['name'] === 'iPhone', 'Device listed with its name');
    request($boss, 'admin/packing_api.php', ['action' => 'revoke_device', 'device' => $dev['id']], ['X-CSRF-Token: ' . $csrf]);
    ok(request($phone, 'pack/api.php?action=queue')['status'] === 401, 'Disconnected phone loses access');
    ok(str_contains(request($phone, 'pack/index.php')['body'], 'nie jest jeszcze sparowany'), 'Disconnected phone asks to pair again');

    // --- przeglądarka (opcjonalnie) ---
    $node = trim((string) shell_exec('command -v node'));
    $hasPw = $node !== '' && trim((string) shell_exec('NODE_PATH=' . escapeshellarg((string) getenv('NODE_PATH')) . ' ' . escapeshellarg($node)
        . ' -e "require(\'playwright\');console.log(1)" 2>/dev/null')) === '1';
    if ($hasPw) {
        $pdo->exec('DELETE FROM packing_orders'); $pdo->exec('DELETE FROM packing_queue');
        $pdo->exec("UPDATE woo_orders SET pase_status = 'processing' WHERE woo_order_id IN (1, 2)");
        $env = 'BASE=' . escapeshellarg($base) . ' SHOTS=' . escapeshellarg((string) (getenv('SHOTS') ?: "$temp/shots"))
            . ' NODE_PATH=' . escapeshellarg((string) getenv('NODE_PATH'));
        passthru($env . ' ' . escapeshellarg($node) . ' ' . escapeshellarg("$root/tests/packing_ui.js"), $rc);
        ok($rc === 0, 'Browser run (desktop + phone)');
        ok($pdo->query('SELECT pase_status FROM woo_orders WHERE woo_order_id = 1')->fetchColumn() === 'packed', 'Browser: order packed and status set');
        ok((int) $pdo->query("SELECT COUNT(*) FROM packing_devices WHERE revoked_at IS NULL")->fetchColumn() === 1, 'Browser: phone paired by QR link');
    } else {
        echo "SKIP: browser part (no Playwright)\n";
    }
} finally {
    if ($process) { proc_terminate($process); }
    if (getenv('KEEP') !== '1') { exec('rm -rf ' . escapeshellarg($temp)); } else { echo "Kept: $temp\n"; }
}
echo "\nPASS: {$checks} packing HTTP checks\n";
