<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Lista produkcji przez HTTP: zaznaczone zamówienia → production_list.php. Sumowanie po produkcie i wariancie (zapach z Woo),
// parametry z produktu w magazynie (atrybut, pole własne), receptura wyrobu z rozszerzenia Produkcja (knot, zapach z ilością),
// zamówienia scalone liczone raz, przycisk na liście zamówień, uprawnienia. Baza SQLite.
// Z SHOTS=katalog zapisuje zrzuty (Playwright przez NODE_PATH): tests/production_list_ui.js.
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Pase\\')) { require $root . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
    if (str_starts_with($class, 'PaseExt\\Produkcja\\')) { require $root . '/extensions/produkcja/' . substr($class, 18) . '.php'; }
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
function request(CurlHandle $h, string $path, ?array $data = null): array {
    global $base;
    curl_setopt($h, CURLOPT_URL, $base . $path);
    curl_setopt($h, CURLOPT_POST, $data !== null);
    if ($data !== null) { curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($data)); }
    $res = curl_exec($h);
    if ($res === false) { throw new RuntimeException(curl_error($h)); }
    $len = curl_getinfo($h, CURLINFO_HEADER_SIZE);
    return ['status' => curl_getinfo($h, CURLINFO_RESPONSE_CODE), 'headers' => substr($res, 0, $len), 'body' => substr($res, $len)];
}
function login(string $user): CurlHandle {
    $h = client();
    $page = request($h, 'admin/login.php');
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $page['body'], $m);
    $r = request($h, 'admin/login.php', ['csrf' => $m[1] ?? '', 'username' => $user, 'password' => 'Only-Test-Password-42']);
    if (!in_array($r['status'], [302, 303], true)) { throw new RuntimeException('Login failed for ' . $user); }
    return $h;
}
/** Tekst wiersza tabeli produktów, w którym jest $needle (bez znaczników, spacje zwinięte). */
function rowWith(string $html, string $needle): string {
    preg_match_all('#<tr>(.*?)</tr>#s', $html, $m);
    foreach ($m[1] as $tr) {
        $txt = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $tr)), ENT_QUOTES, 'UTF-8')));
        if (str_contains($txt, $needle)) { return $txt; }
    }
    return '';
}

/** Tekst z PDF (Pase\Support\Pdf: nieskompresowane „(…) Tj”, polskie litery w kodowaniu z /Differences). */
function pdfText(string $pdf): string {
    $map = [];
    foreach ((new ReflectionClass(\Pase\Support\Pdf::class))->getConstant('EXTRA') as $ch => [$code]) { $map[chr($code)] = $ch; }
    preg_match_all('/\((.*?)(?<!\\\\)\) Tj/s', $pdf, $m);
    return implode("\n", array_map(static fn($t) => strtr(stripcslashes($t), $map), $m[1]));
}

$temp = sys_get_temp_dir() . '/crm-prodlist-http-' . bin2hex(random_bytes(6));
foreach (['public/admin/assets', 'config', 'storage/security', 'lang', 'extensions'] as $d) { mkdir("$temp/$d", 0700, true); }
$process = null;
try {
    file_put_contents("$temp/.env", "TOTP_REQUIRE_ADMIN=0\nTOTP_KEY_FILE=$temp/storage/security/totp.key\n");
    file_put_contents("$temp/storage/security/totp.key", base64_encode(random_bytes(32)));
    foreach (glob("$root/public/admin/*.php") as $f) { if (!in_array(basename($f), ['bootstrap_admin.php', 'db_admin.php'], true)) { copy($f, "$temp/public/admin/" . basename($f)); } }
    foreach (glob("$root/public/admin/assets/*") as $f) { if (is_file($f)) { copy($f, "$temp/public/admin/assets/" . basename($f)); } }
    foreach (glob("$root/lang/*.php") as $f) { copy($f, "$temp/lang/" . basename($f)); }
    exec('cp -r ' . escapeshellarg("$root/extensions/produkcja") . ' ' . escapeshellarg("$temp/extensions/produkcja"));
    file_put_contents("$temp/public/admin/bootstrap_admin.php", '<?php \Pase\Support\PagePermissions::migrate($pdo);');
    $boot = '<?php if (!defined("PASE_ROOT")) { define("PASE_ROOT", ' . var_export($temp, true) . ');'
        . 'spl_autoload_register(static function($c) { if (str_starts_with($c, "Pase\\\\")) require ' . var_export("$root/src/", true)
        . ' . str_replace("\\\\", "/", substr($c, 5)) . ".php"; });'
        . '\Pase\Support\Env::load(PASE_ROOT . "/.env");'
        . 'if (!function_exists("t")) { function t($k, $v = []) { return \Pase\Support\I18n::t($k, $v); } } }'
        . 'return ["db" => [], "allegro" => []];';
    file_put_contents("$temp/config/config.php", $boot);
    file_put_contents("$temp/config/database.php", '<?php return static function ($db) { $pdo = new PDO("sqlite:" . PASE_ROOT . "/test.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); \\Pase\\Support\\Runtime::setPdo($pdo); return $pdo; };');
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
    $pdo->exec('CREATE TABLE order_statuses (id INTEGER PRIMARY KEY, status_key TEXT, label TEXT, color TEXT, position INTEGER, is_system INTEGER DEFAULT 0)');
    $pdo->exec("INSERT INTO order_statuses (status_key,label,color,position) VALUES ('new','Nowe','#8a8f98',1),('processing','W realizacji','#c28a2b',2)");
    $pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT, is_active INTEGER, config TEXT)');
    $pdo->exec("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES (1, 'woocommerce', 'Mój Sklep', 1, '{\"base_url\":\"https://sklep.example\"}')");
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, pase_stock INTEGER, pase_price REAL, images TEXT, ean TEXT, attributes TEXT, custom_fields TEXT, actual_stock INTEGER NULL,
        weight REAL NULL, woo_product_id INTEGER NULL, woo_variation_id INTEGER NULL)');
    $pdo->exec('CREATE TABLE product_fields (id INTEGER PRIMARY KEY, field_key TEXT, label TEXT, type TEXT, options TEXT, categories TEXT, position INTEGER DEFAULT 100)');
    $pdo->exec("INSERT INTO product_fields (field_key, label, type, position) VALUES ('czas_palenia', 'Czas palenia', 'text', 1), ('opis_dlugi', 'Opis', 'image', 2)");
    $pins = $pdo->prepare('INSERT INTO products (sku, name, attributes, custom_fields) VALUES (?,?,?,?)');
    $pins->execute(['SOJ-180', 'Świeca sojowa 180 ml', json_encode([['name' => 'Zapach', 'options' => ['Wanilia', 'Cynamon', 'Las']],
        ['name' => 'Aroma head', 'options' => ['Zielone liście, kardamon']], ['name' => 'Nuta serca', 'options' => ['Jaśmin']]]),
        json_encode(['czas_palenia' => '40 h', 'opis_dlugi' => 'https://x'])]);
    $pins->execute(['GB-1L', 'GINGERBREAD 1l', json_encode([['name' => 'Knot', 'options' => ['bawełniany']], ['name' => 'Waga netto', 'options' => ['900 g']]]), null]);
    $pins->execute(['PIER', 'Świeca Piernik Drewniany Knot', null, null]);
    // Waga z karty produktu (kg): SOJ-180 ma też wagę w Produkcji (wygrywa), reszta po ID ze sklepu (pozycje bez SKU / SKU spoza magazynu).
    $pdo->exec("UPDATE products SET weight = 0.3 WHERE sku = 'SOJ-180'");
    $pdo->exec("INSERT INTO products (sku, name, weight, woo_product_id, woo_variation_id) VALUES ('', 'Wosk do kominka', 0.08, 555, NULL),
        ('SW-240', 'Świeca 240', 0.24, 11534, 18096), ('SW-INNY', 'Świeca inny wariant', 0.5, 11534, 18097)");
    $pdo->exec("ALTER TABLE products ADD COLUMN short_description TEXT");
    $pdo->exec("ALTER TABLE products ADD COLUMN description TEXT");
    $pdo->exec("UPDATE products SET description = '<p>Świeca sojowa.</p><ul><li><strong>Pojemność:</strong> 100&nbsp;ml</li></ul>' WHERE sku = 'PIER'");
    $pdo->exec("CREATE TABLE woo_orders (id INTEGER PRIMARY KEY, woo_order_id INTEGER UNIQUE, integration_id INTEGER, pase_number INTEGER, order_number TEXT, status TEXT,
        pase_status TEXT, currency TEXT, total REAL, customer_name TEXT, customer_email TEXT, date_created TEXT, imported_at TEXT, updated_at TEXT,
        lifecycle TEXT DEFAULT 'active', payload TEXT, local_items TEXT NULL, local_edited_at TEXT NULL, local_billing_email TEXT NULL,
        local_billing_phone TEXT NULL, local_invoice TEXT NULL, local_pickup_point TEXT NULL, forwarded_to TEXT NULL, client_token TEXT NULL,
        automations_done INTEGER DEFAULT 0, internal_status TEXT NULL, source_type TEXT NULL)");
    $pdo->exec('CREATE TABLE shipments (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, courier_code TEXT, waybill_no TEXT, bl_order_id TEXT,
        order_ref TEXT, price REAL, status TEXT, label_link TEXT, request_payload TEXT, response_payload TEXT, created_at TEXT,
        tracking_status TEXT, tracking_code TEXT, tracking_carrier TEXT, tracking_at TEXT, tracking_checked_at TEXT, tracking_events TEXT)');
    $pdo->exec("CREATE TABLE order_documents (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, provider TEXT, document_type TEXT,
        remote_id TEXT, status TEXT DEFAULT 'issued', message TEXT, created_at TEXT, updated_at TEXT)");
    foreach ([\Pase\Services\OrderMerge::class, \Pase\Services\Packing::class] as $svc) { $svc::migrate($pdo); }

    // Wyrób w Produkcji z recepturą (SKU jak produkt w sklepie).
    $store = new \PaseExt\Produkcja\Store($pdo);
    $knot = $store->saveMaterial(null, ['name' => 'Knot drewniany 13 mm', 'category' => 'knot']);
    $wosk = $store->saveMaterial(null, ['name' => 'Wosk sojowy Golden 464', 'category' => 'wosk', 'unit' => 'g']);
    $wan = $store->saveMaterial(null, ['name' => 'Olejek Wanilia', 'category' => 'zapach', 'unit' => 'g']);
    $sloik = $store->saveMaterial(null, ['name' => 'Słoik 180 ml bursztyn', 'category' => 'pojemnik']);
    $etyk = $store->saveMaterial(null, ['name' => 'Etykieta okrągła', 'category' => 'etykieta']);
    $store->saveProduct(null, ['name' => 'Świeca sojowa 180 ml', 'sku' => 'SOJ-180', 'weight_g' => '200', 'recipe' => [
        ['material_id' => $wosk['id'], 'qty' => 150], ['material_id' => $wan['id'], 'qty' => 12],
        ['material_id' => $knot['id'], 'qty' => 1], ['material_id' => $sloik['id'], 'qty' => 1], ['material_id' => $etyk['id'], 'qty' => 1]]]);

    $woo = static fn(string $name, int $q, string $sku, array $meta = []) => ['name' => $name, 'quantity' => $q, 'sku' => $sku,
        'meta_data' => array_map(static fn($k, $v) => ['key' => 'pa_' . $k, 'display_key' => $k, 'display_value' => $v], array_keys($meta), $meta)];
    $orders = [
        // id, numer CRM, klient, pozycje, uwaga
        [101, 1201, 'Paula Testowa', [$woo('Świeca sojowa 180 ml', 2, 'SOJ-180', ['Zapach' => 'Wanilia']), $woo('GINGERBREAD 1l', 1, 'GB-1L')], 'Proszę zapakować na prezent'],
        [102, 1202, 'Marek Zieliński', [$woo('Świeca sojowa 180 ml', 1, 'SOJ-180', ['Zapach' => 'Wanilia']), $woo('Świeca sojowa 180 ml', 1, 'SOJ-180', ['Zapach' => 'Cynamon'])], ''],
        [103, 1203, 'Anna Kowalska', [$woo('GINGERBREAD 1l', 2, 'GB-1L'), ['name' => 'Wosk do kominka', 'quantity' => 3, 'sku' => '', 'product_id' => 555], ['name' => 'Zapałki', 'quantity' => 1, 'sku' => ''],
            $woo('Świeca kokosowa z drewnianym knotem', 2, 'KOK', ['Zapach' => 'Wanilia'])], ''],
        [104, 1204, 'Anna Kowalska', [$woo('GINGERBREAD 1l', 5, 'GB-1L')], ''],   // dołączone do 103
        [105, 1205, 'Tomasz Nowak', [$woo('GINGERBREAD 1l', 9, 'GB-1L')], ''],   // nie zaznaczone
    ];
    $oi = $pdo->prepare("INSERT INTO woo_orders (woo_order_id, integration_id, pase_number, order_number, status, pase_status, customer_name, date_created, payload)
        VALUES (?,1,?,?,'processing','processing',?,?,?)");
    foreach ($orders as $i => [$id, $no, $cust, $items, $note]) {
        $oi->execute([$id, $no, (string) (73900 + $id), $cust, '2026-11-1' . $i . ' 10:00:00', json_encode(['line_items' => $items, 'customer_note' => $note])]);
    }
    $pdo->exec('UPDATE woo_orders SET merged_into = 103 WHERE woo_order_id = 104');
    $oi->execute([107, 1207, '74007', 'Ewa Opis', '2026-11-16 09:00:00', json_encode(['line_items' => [$woo('Świeca Piernik Drewniany Knot 120 g', 1, 'PIER', ['knot' => 'drewniany']),
        ['name' => 'Świeca z wariantem', 'quantity' => 1, 'sku' => 'STARE-SKU', 'product_id' => 11534, 'variation_id' => 18096]]])]);

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes);
    fclose($pipes[0]);
    for ($i = 0; $i < 100; ++$i) { $p = @stream_socket_client('tcp://' . $address, $e1, $e2, 0.1); if ($p) { fclose($p); break; } usleep(10000); }

    $boss = login('boss');
    $list = request($boss, 'admin/index.php');
    ok($list['status'] === 200 && str_contains($list['body'], "production_list.php?ids='") && str_contains($list['body'], 'Lista produkcji'), 'Orders list offers "Lista produkcji" for selected orders');

    $r = request($boss, 'admin/production_list.php?ids=101,102,103&grp=0');
    $b = $r['body'];
    ok($r['status'] === 200 && str_contains($b, '<h1>Lista produkcji</h1>') && str_contains($b, '@page { size: A4'), 'Print page opens (A4)');
    ok(str_contains($r['headers'], "script-src 'nonce-"), 'Print page has a strict CSP');
    $van = rowWith($b, 'Świeca sojowa 180 ml Zapach: Wanilia');
    ok(str_starts_with($van, '3 Świeca sojowa 180 ml Zapach: Wanilia') && str_ends_with($van, 'SOJ-180 200 g 74001 ×2, 74002'), 'Same product and scent summed across orders, weight column from Produkcja, order numbers: ' . $van);
    ok(!str_contains($van, 'Waga:') && str_contains($van, 'Czas palenia: 40 h') && !str_contains($van, 'Las') && !str_contains($van, 'https://x'),
        'Weight in its own column, not among parameters; short custom fields from the product');
    ok(!str_contains($b, 'Aroma head') && !str_contains($b, 'Zielone liście') && !str_contains($b, 'Nuta serca'), 'Scent notes left out');
    ok(str_contains(rowWith($b, 'GINGERBREAD'), 'Knot: bawełniany GB-1L 900 g') && !str_contains(rowWith($b, 'GINGERBREAD'), 'Waga netto'), 'Weight from the attribute (Waga netto): ' . rowWith($b, 'GINGERBREAD'));
    ok(str_contains(rowWith($b, 'Wosk do kominka'), 'Wosk do kominka 80 g'), 'Weight from the product card, found by shop product ID (no SKU): ' . rowWith($b, 'Wosk do kominka'));
    ok(str_contains(rowWith($b, 'Zapałki'), 'Zapałki —'), 'No weight: dash');
    ok(str_contains(rowWith(request($boss, 'admin/production_list.php?ids=107')['body'], 'Piernik'), 'knot: drewniany PIER 120 g'), 'Weight from the name (… 120 g)');
    ok(str_contains(rowWith(request($boss, 'admin/production_list.php?ids=107')['body'], 'Świeca z wariantem'), 'STARE-SKU 240 g'), 'Weight from the product card of the variation (shop variation ID)');
    ok(str_contains($van, 'Knot: Knot drewniany 13 mm (3 szt.)') && str_contains($van, 'Zapach: Olejek Wanilia (36 g)') && str_contains($van, 'Wosk: Wosk sojowy Golden 464 (450 g)')
        && str_contains($van, 'Pojemnik: Słoik 180 ml bursztyn (3 szt.)') && !str_contains($van, 'Etykieta'), 'Recipe from Produkcja: wick, scent, wax, jar multiplied by pieces (labels skipped)');
    $cyn = rowWith($b, 'Zapach: Cynamon');
    ok(str_starts_with($cyn, '1 Świeca sojowa 180 ml') && str_contains($cyn, 'Knot: Knot drewniany 13 mm (1 szt.)') && !str_contains($cyn, 'Olejek Wanilia'),
        'Other scent is a separate line; recipe scent that contradicts the variant is left out');
    ok(str_starts_with(rowWith($b, 'GINGERBREAD'), '8 GINGERBREAD 1l') && str_contains(rowWith($b, 'GINGERBREAD'), '74003 ×7'), 'Merged order counted with its main order: ' . rowWith($b, 'GINGERBREAD'));
    ok(str_starts_with(rowWith($b, 'Wosk do kominka'), '3 Wosk do kominka'), 'Item without SKU listed by name');
    ok(str_contains(rowWith($b, 'Razem sztuk'), '18'), 'Total pieces');
    $mat = (string) strstr($b, 'Materiały razem');
    ok($mat !== '' && rowWith($mat, 'Olejek Wanilia') === 'Zapach: Olejek Wanilia 36 g' && rowWith($mat, 'Knot drewniany') === 'Knot: Knot drewniany 13 mm 4 szt.'
        && rowWith($mat, 'Golden 464') === 'Wosk: Wosk sojowy Golden 464 600 g', 'Materials summed for the whole list');
    ok(str_contains($b, 'Uwagi klientów') && str_contains($b, 'Proszę zapakować na prezent'), 'Customer notes listed');
    ok(str_contains($b, '3 zamówienia') && !str_contains($b, 'Tomasz Nowak'), 'Only selected orders');

    // Grupowanie po zapachu (domyślne): różne produkty z tym samym zapachem pod jednym nagłówkiem, bez zapachu na końcu.
    $g = request($boss, 'admin/production_list.php?ids=101,102,103')['body'];
    preg_match_all('#<tr class="grp">(.*?)</tr>#s', $g, $gm);
    $heads = array_map(static fn($t) => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $t)), ENT_QUOTES, 'UTF-8'))), $gm[1]);
    ok($heads === ['1 Zapach: Cynamon 1 pozycja', '5 Zapach: Wanilia 2 pozycje', '12 Bez zapachu 3 pozycje'], 'Groups by scent with piece sums, no scent last: ' . implode(' | ', $heads));
    $pos = static fn(string $n) => strpos($g, $n);
    ok($pos('Zapach: <b>Wanilia') < $pos('Świeca kokosowa') && $pos('Świeca kokosowa') < $pos('Bez zapachu') && $pos('Zapach: <b>Wanilia') < strpos($g, '>Świeca sojowa 180 ml<', $pos('Zapach: <b>Wanilia')),
        'Different products with the same scent sit together under their scent');
    ok(str_starts_with(rowWith($g, 'Świeca kokosowa'), '2 Świeca kokosowa z drewnianym knotem KOK') && str_contains($g, 'id="groupScent" checked'), 'Scent left out of rows inside a group (it is in the header); toggle on');
    ok(!str_contains(request($boss, 'admin/production_list.php?ids=101,102,103&grp=0')['body'], 'class="grp"')
        && str_contains(request($boss, 'admin/production_list.php?ids=101&grp=0')['body'], '&grp=0'), 'grp=0: plain list, PDF without groups too');
    $gp = pdfText(request($boss, 'admin/production_list.php?ids=101,102,103&pdf=1')['body']);
    ok(str_contains($gp, 'Zapach: Wanilia') && str_contains($gp, 'Bez zapachu') && strpos($gp, 'Zapach: Cynamon') < strpos($gp, 'Bez zapachu'), 'PDF grouped by scent');

    $r = request($boss, 'admin/production_list.php?ids=103,104');
    ok(str_starts_with(rowWith($r['body'], 'GINGERBREAD'), '7 GINGERBREAD'), 'Main and merged both selected: merged items not doubled');
    $r = request($boss, 'admin/production_list.php?ids=104');
    ok(str_starts_with(rowWith($r['body'], 'GINGERBREAD'), '5 GINGERBREAD'), 'Merged order alone: its own items');
    ok(str_contains(request($boss, 'admin/production_list.php')['body'], 'Brak produktów do zrobienia'), 'No selection: empty message');

    // iPhone, aplikacja z ekranu początkowego: „Drukuj” udostępnia plik PDF (window.print() tam nic nie robi).
    ok(str_contains(request($boss, 'admin/production_list.php?ids=101,102')['body'], '"production_list.php?ids=101%2C102&pdf=1"'), 'Page knows its PDF address');
    ok(str_contains(request($boss, 'admin/production_list.php')['body'], 'var pdfUrl = "";'), 'Empty list: no PDF');
    $r = request($boss, 'admin/production_list.php?ids=101,102,103&pdf=1');
    ok($r['status'] === 200 && str_starts_with($r['body'], '%PDF-1.4') && str_contains($r['headers'], 'Content-Type: application/pdf')
        && str_contains($r['headers'], 'Cache-Control: private, no-store'), 'PDF of the list');
    $txt = pdfText($r['body']);
    ok(str_contains($txt, 'Lista produkcji') && str_contains($txt, 'Świeca sojowa 180 ml') && str_contains($txt, 'Zapach: Wanilia')
        && str_contains($txt, 'Knot: Knot drewniany 13 mm (3 szt.)') && str_contains($txt, '74001 ×2, 74002') && str_contains($txt, 'Razem sztuk')
        && str_contains($txt, 'MATERIAŁY RAZEM') && str_contains($txt, 'Proszę zapakować na prezent') && str_contains($txt, '3 zamówienia · 18 szt. · 6 pozycji'),
        'PDF holds products, scents, wicks, orders, materials and notes (Polish letters intact)');
    $no = pdfText(request($boss, 'admin/production_list.php?ids=101,102,103&pdf=1&nr=0')['body']);
    ok(!str_contains($no, "\nZAMÓWIENIA\n") && !str_contains($no, '74001 ×2') && str_contains($no, 'Zapach: Wanilia'), 'PDF without order numbers');
    ok(request(client(), 'admin/production_list.php?ids=101&pdf=1')['status'] === 302, 'PDF needs login');
    $many = [];
    for ($i = 0; $i < 60; ++$i) { $many[] = $woo('Świeca testowa nr ' . $i . ' z bardzo długą nazwą, która zawija się w dwa wiersze na wydruku', 1, 'T-' . $i, ['Zapach' => 'Wanilia']); }
    $oi->execute([106, 1206, '74006', 'Duże Zamówienie', '2026-11-16 10:00:00', json_encode(['line_items' => $many])]);
    $big = request($boss, 'admin/production_list.php?ids=106&pdf=1')['body'];
    ok(substr_count($big, '/Type /Page ') >= 3 && str_contains(pdfText($big), 'Strona 1 z ') && str_contains(pdfText($big), 'nr 59 '), 'Long list continues on next pages with page numbers');

    // Uprawnienia: strona idzie za uprawnieniem „Zamówienia”.
    $perms = array_fill_keys(array_keys(PagePermissions::registry()), 'hidden');
    PagePermissions::save($pdo, 3, array_merge($perms, ['orders' => 'view']));
    $vi = login('vi');
    ok(request($vi, 'admin/production_list.php?ids=101')['status'] === 200, 'View-only account can print the list');
    PagePermissions::save($pdo, 3, $perms);
    ok(request($vi, 'admin/production_list.php?ids=101')['status'] === 403, 'Account without orders permission is blocked');
    ok(request(client(), 'admin/production_list.php?ids=101')['status'] === 302, 'Not logged in: redirected to login');

    // Zrzuty (opcjonalnie): SHOTS=katalog NODE_PATH=... php tests/production_list_http.php
    if (($shots = getenv('SHOTS')) && getenv('NODE_PATH')) {
        passthru('node ' . escapeshellarg(__DIR__ . '/production_list_ui.js') . ' ' . escapeshellarg($base) . ' ' . escapeshellarg($shots), $rc);
        ok($rc === 0, 'Screenshots saved');
    }

    echo "\nAll {$checks} production list checks passed.\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
    if (is_file("$temp/server.log")) { echo "--- server.log ---\n", substr((string) file_get_contents("$temp/server.log"), -4000), "\n"; }
    exit(1);
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    exec('rm -rf ' . escapeshellarg($temp));
}
