<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Szybki podgląd zamówienia na liście zamówień przez HTTP: oko w wierszu, order_preview.php zwraca produkty
// (miniatura, wariant, ilość), dostawę i uwagę klienta, z pozycjami zamówień scalonych. Prawdziwe strony panelu,
// baza SQLite. Gdy jest Playwright (NODE_PATH), sprawdza okienko w przeglądarce - tests/order_preview_ui.js.
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
function csrfOf(string $body): string {
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $m);
    return $m[1] ?? '';
}

$temp = sys_get_temp_dir() . '/crm-order-preview-http-' . bin2hex(random_bytes(6));
foreach (['public/admin/assets', 'public/pack/assets', 'config', 'storage/security', 'lang', 'public/img'] as $d) { mkdir("$temp/$d", 0700, true); }
$process = null;
try {
    file_put_contents("$temp/.env", "TOTP_REQUIRE_ADMIN=0\n");
    foreach (glob("$root/public/admin/*.php") as $f) { if (!in_array(basename($f), ['bootstrap_admin.php', 'db_admin.php'], true)) { copy($f, "$temp/public/admin/" . basename($f)); } }
    copy("$root/public/admin/assets/loc-picker.js", "$temp/public/admin/assets/loc-picker.js");
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
    $pdo->exec("INSERT INTO order_statuses (status_key,label,color,position) VALUES ('new','Nowe','#8a8f98',1),('processing','W realizacji','#c28a2b',2),('shipped','Wysłane','#2f8f5b',3)");
    $pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT, is_active INTEGER, config TEXT)');
    $pdo->exec("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES (1, 'woocommerce', 'Mój Sklep', 1, '{}')");
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, pase_stock INTEGER, pase_price REAL, images TEXT, ean TEXT)');
    $pdo->exec("INSERT INTO products (id, sku, name, pase_stock, pase_price) VALUES (1, 'GB-1L', 'GINGERBREAD 1l', 5, 49.9), (2, 'MW-1L', 'MULLED WINE 1l', 5, 39.9)");
    $pdo->exec("CREATE TABLE woo_orders (id INTEGER PRIMARY KEY, woo_order_id INTEGER UNIQUE, integration_id INTEGER, pase_number INTEGER, order_number TEXT, status TEXT,
        pase_status TEXT, currency TEXT, total REAL, customer_name TEXT, customer_email TEXT, date_created TEXT, imported_at TEXT, updated_at TEXT,
        lifecycle TEXT DEFAULT 'active', payload TEXT, local_items TEXT NULL, local_edited_at TEXT NULL, local_billing_email TEXT NULL,
        local_billing_phone TEXT NULL, local_invoice TEXT NULL, local_pickup_point TEXT NULL, forwarded_to TEXT NULL, client_token TEXT NULL,
        automations_done INTEGER DEFAULT 0, internal_status TEXT NULL)");
    $pdo->exec('CREATE TABLE shipments (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, courier_code TEXT, waybill_no TEXT, bl_order_id TEXT,
        order_ref TEXT, price REAL, status TEXT, label_link TEXT, request_payload TEXT, response_payload TEXT, created_at TEXT,
        tracking_status TEXT, tracking_code TEXT, tracking_carrier TEXT, tracking_at TEXT, tracking_checked_at TEXT, tracking_events TEXT)');
    $pdo->exec("CREATE TABLE order_messages (id INTEGER PRIMARY KEY, woo_order_id INTEGER, sender TEXT, author_name TEXT, body TEXT,
        attachment_path TEXT, attachment_name TEXT, is_read INTEGER DEFAULT 0, created_at TEXT)");
    $pdo->exec("CREATE TABLE order_documents (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, provider TEXT, document_type TEXT,
        remote_id TEXT, status TEXT DEFAULT 'issued', message TEXT, created_at TEXT, updated_at TEXT)");
    foreach ([\Pase\Services\OrderReturns::class, \Pase\Services\AllegroFeedback::class, \Pase\Services\Customers::class,
        \Pase\Services\DocumentIssueGuard::class, \Pase\Services\OrderMerge::class, \Pase\Services\OrderPayment::class] as $svc) { $svc::migrate($pdo); }
    // Zdjęcia produktów (małe PNG serwowane przez serwer testowy).
    $img = static function (string $file, array $rgb) use ($temp): void {
        $im = imagecreatetruecolor(120, 120);
        imagefill($im, 0, 0, imagecolorallocate($im, 246, 242, 234));
        imagefilledrectangle($im, 30, 34, 90, 104, imagecolorallocate($im, ...$rgb));
        imagefilledrectangle($im, 34, 26, 86, 36, imagecolorallocate($im, 60, 52, 44));
        imagefilledrectangle($im, 38, 56, 82, 80, imagecolorallocate($im, 250, 248, 242));
        imagepng($im, "$temp/public/img/$file");
    };
    $img('gb.png', [176, 106, 52]); $img('mw.png', [128, 30, 46]); $img('lav.png', [150, 128, 196]);
    $pdo->exec("UPDATE products SET images = '[\"/img/gb.png\"]' WHERE id = 1");
    $pdo->exec("UPDATE products SET images = '[\"/img/mw.png\"]' WHERE id = 2");
    $pdo->exec("INSERT INTO products (id, sku, name, pase_stock, pase_price, images) VALUES (3, 'LAV-180', 'Świeca sojowa w szkle 180 ml', 5, 29.9, '[\"/img/lav.png\"]')");

    $order = static fn(array $items, float $total, string $note = '', array $extra = []): string => json_encode($extra + [
        'status' => 'processing', 'payment_method' => 'blik', 'payment_method_title' => 'Blik', 'date_paid' => '2026-11-16 10:00:00', 'total' => (string) $total,
        'billing' => ['first_name' => 'Marta', 'last_name' => 'Wylon', 'email' => 'marta@example.com', 'address_1' => 'Lipowa 1', 'postcode' => '00-001', 'city' => 'Warszawa'],
        'shipping' => ['first_name' => 'Marta', 'last_name' => 'Wylon', 'address_1' => 'Lipowa 1', 'postcode' => '00-001', 'city' => 'Warszawa'],
        'line_items' => $items, 'shipping_lines' => [['method_title' => 'Kurier DPD', 'total' => '15.00']], 'customer_note' => $note]);
    $oins = $pdo->prepare('INSERT INTO woo_orders (woo_order_id, integration_id, pase_number, order_number, status, pase_status, currency, total, customer_name, customer_email, date_created, imported_at, payload)
        VALUES (?,1,?,?,?,?,?,?,?,?,?,?,?)');
    $oins->execute([601, 21, '45960', 'processing', 'processing', 'PLN', 143.6, 'Marta Wylon', 'marta@example.com', '2026-11-16 16:27:00', '2026-11-16 16:28:00',
        $order([
            ['product_id' => 3, 'sku' => 'LAV-180', 'name' => 'Świeca sojowa w szkle 180 ml', 'quantity' => 2, 'price' => 29.9, 'total' => '59.80',
             'meta_data' => [['key' => 'pa_zapach', 'display_key' => 'Zapach', 'display_value' => '<p>Lawenda &amp; wanilia</p>'], ['key' => '_reduced_stock', 'value' => '2']]],
            ['product_id' => 1, 'sku' => 'GB-1L', 'name' => 'GINGERBREAD 1l', 'quantity' => 1, 'price' => 49.9, 'total' => '49.90'],
            ['sku' => 'GIFT', 'name' => 'Pakowanie na prezent', 'quantity' => 1, 'price' => 5, 'total' => '5.00'],
        ], 143.6, "Proszę o paczkę bez ulotek <b>\nDziękuję", ['shipping_lines' => [['method_title' => 'Paczkomat InPost', 'total' => '13.99']],
            'meta_data' => [['key' => '_paczkomat_id', 'value' => 'WAW01M']]])]);
    $oins->execute([602, 22, '45959', 'processing', 'processing', 'PLN', 54.9, 'Anna Nowak', 'anna@example.com', '2026-11-16 12:00:00', '2026-11-16 12:01:00',
        $order([['product_id' => 2, 'sku' => 'MW-1L', 'name' => 'MULLED WINE 1l', 'quantity' => 1, 'price' => 39.9, 'total' => '39.90']], 54.9)]);
    // 603 jest dołączone (scalone) do 602 - jego pozycje są w podglądzie 602.
    $oins->execute([603, 23, '45961', 'processing', 'processing', 'PLN', 29.9, 'Anna Nowak', 'anna@example.com', '2026-11-16 12:30:00', '2026-11-16 12:31:00',
        $order([['product_id' => 3, 'sku' => 'LAV-180', 'name' => 'Świeca sojowa w szkle 180 ml', 'quantity' => 1, 'price' => 29.9, 'total' => '29.90']], 29.9)]);
    $pdo->exec('UPDATE woo_orders SET merged_into = 602 WHERE woo_order_id = 603');
    // Zamówienie z lokalnie poprawionymi pozycjami - podgląd pokazuje wersję z CRM.
    $oins->execute([604, 24, '45962', 'processing', 'processing', 'PLN', 99.8, 'Jan Lokalny', 'jan@example.com', '2026-11-15 09:00:00', '2026-11-15 09:01:00',
        $order([['product_id' => 2, 'sku' => 'MW-1L', 'name' => 'MULLED WINE 1l', 'quantity' => 1, 'price' => 39.9, 'total' => '39.90']], 99.8)]);
    $pdo->exec("UPDATE woo_orders SET local_items = '" . json_encode([['product_id' => 1, 'sku' => 'GB-1L', 'name' => 'GINGERBREAD 1l', 'quantity' => 2, 'price' => 49.9, 'total' => '99.80']]) . "' WHERE woo_order_id = 604");

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes);
    fclose($pipes[0]);
    for ($i = 0; $i < 100; ++$i) { $p = @stream_socket_client('tcp://' . $address, $e1, $e2, 0.1); if ($p) { fclose($p); break; } usleep(10000); }

    $boss = login('boss');
    $list = request($boss, 'admin/index.php');
    ok($list['status'] === 200 && str_contains($list['body'], 'id="peekPop"') && str_contains($list['body'], 'class="btn secondary order-peek" data-peek="601"')
        && str_contains($list['body'], 'data-peek-hover="601" href="order_view.php?id=601"'), 'List has the quick preview eye and the "Podgląd" link still opens the order');

    $r = request($boss, 'admin/order_preview.php?id=601');
    $j = $r['json'];
    if (getenv('DEBUG')) { echo $r['body'], "\n"; }
    ok($r['status'] === 200 && $j['label'] === '45960' && $j['customer'] === 'Marta Wylon' && $j['order_url'] === 'order_view.php?id=601', 'Preview header data');
    ok(count($j['items']) === 3 && $j['items'][0]['name'] === 'Świeca sojowa w szkle 180 ml' && $j['items'][0]['qty'] === 2
        && $j['items'][0]['thumb'] === '/img/lav.png' && $j['items'][1]['thumb'] === '/img/gb.png' && $j['items'][2]['thumb'] === null, 'Items with quantity and thumbnails');
    ok($j['items'][0]['attrs'] === [['label' => 'Zapach', 'value' => 'Lawenda & wanilia']] && $j['items'][1]['attrs'] === [], 'Variant from Woo meta_data without technical fields');
    ok($j['qty'] === 4 && $j['total'] === "143,60\u{00a0}PLN" && $j['items'][0]['total'] === "59,80\u{00a0}PLN", 'Totals');
    ok($j['delivery']['name'] === 'InPost' && str_contains($j['delivery']['method'], 'Paczkomat InPost') && str_contains($j['delivery']['point'], 'WAW01M'), 'Delivery with pickup point');
    ok($j['note'] === "Proszę o paczkę bez ulotek <b>\nDziękuję", 'Customer note as plain text (escaped by the browser)');

    $j = request($boss, 'admin/order_preview.php?id=602')['json'];
    ok(count($j['items']) === 2 && $j['items'][0]['merged_from'] === null && $j['items'][1]['merged_from'] === '45961' && $j['qty'] === 2, 'Merged order items listed with their source order');
    ok($j['delivery']['address'] === 'Lipowa 1, 00-001 Warszawa' && $j['delivery']['point'] === '', 'Courier delivery shows the address');
    $j = request($boss, 'admin/order_preview.php?id=604')['json'];
    ok(count($j['items']) === 1 && $j['items'][0]['name'] === 'GINGERBREAD 1l' && $j['items'][0]['qty'] === 2, 'Locally edited items win over the shop copy');

    ok(request($boss, 'admin/order_preview.php?id=999')['status'] === 404, 'Unknown order: 404');
    ok(request(client(), 'admin/order_preview.php?id=601')['status'] === 302, 'Not logged in: redirected to login');
    ok(request(login('vi'), 'admin/order_preview.php?id=601')['status'] === 200, 'Read-only user can preview');

    $node = trim((string) shell_exec('command -v node'));
    $hasPw = $node !== '' && trim((string) shell_exec('NODE_PATH=' . escapeshellarg((string) getenv('NODE_PATH')) . ' ' . escapeshellarg($node)
        . ' -e "require.resolve(\'playwright\'); console.log(1)" 2>/dev/null')) === '1';
    if ($hasPw) {
        passthru('BASE=' . escapeshellarg($base) . ' NODE_PATH=' . escapeshellarg((string) getenv('NODE_PATH')) . ' ' . escapeshellarg($node) . ' ' . escapeshellarg("$root/tests/order_preview_ui.js"), $rc);
        ok($rc === 0, 'Browser checks passed');
    } else {
        echo "SKIP: browser checks (no Playwright; set NODE_PATH)\n";
    }
    $warnings = preg_grep('/PHP (Warning|Fatal|Parse|Deprecated)/', file("$temp/server.log") ?: []);
    ok($warnings === [], 'No PHP warnings in the server log: ' . implode('', $warnings ?: []));
    echo "PASS: {$checks} order preview checks\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (getenv('KEEP_TEMP')) { echo "temp: $temp\n"; } else { exec('rm -rf ' . escapeshellarg($temp)); }
}
