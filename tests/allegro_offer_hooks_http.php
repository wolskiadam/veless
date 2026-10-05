<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Haki formularza oferty Allegro dla rozszerzeń przez HTTP: allegro_offer.description_tools, allegro_offer.ext_data
// (dane rozszerzenia przy ofercie, przeniesienie starego klucza), window.OfferForm. Rozszerzenie testowe
// tests/fixtures/extensions/opisdemo na prawdziwej stronie offer_allegro.php, SQLite.
$root = dirname(__DIR__);
$plugin = __DIR__ . '/fixtures/extensions/opisdemo';
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

$temp = sys_get_temp_dir() . '/crm-offer-hooks-http-' . bin2hex(random_bytes(6));
foreach (['public/admin', 'config', 'storage/security', 'lang', 'integrations/allegro', 'extensions'] as $d) { mkdir("$temp/$d", 0700, true); }
$process = null;
try {
    file_put_contents("$temp/.env", "TOTP_REQUIRE_ADMIN=0\n");
    foreach (glob("$root/public/admin/*.php") as $f) { if (!in_array(basename($f), ['bootstrap_admin.php', 'db_admin.php'], true)) { copy($f, "$temp/public/admin/" . basename($f)); } }
    // Rozszerzenie testowe zainstalowane jak przez FTP.
    exec('cp -r ' . escapeshellarg($plugin) . ' ' . escapeshellarg("$temp/extensions/opisdemo"));
    foreach (glob("$root/integrations/allegro/*.php") as $f) { copy($f, "$temp/integrations/allegro/" . basename($f)); }
    foreach (glob("$root/lang/*.php") as $f) { copy($f, "$temp/lang/" . basename($f)); }
    file_put_contents("$temp/public/admin/bootstrap_admin.php", '<?php \Pase\Support\PagePermissions::migrate($pdo);');
    $boot = '<?php if (!defined("PASE_ROOT")) { define("PASE_ROOT", ' . var_export($temp, true) . ');'
        . 'spl_autoload_register(static function($c) { if (str_starts_with($c, "Pase\\\\")) require ' . var_export("$root/src/", true)
        . ' . str_replace("\\\\", "/", substr($c, 5)) . ".php";'
        . ' if (str_starts_with($c, "PasePlugin\\\\Allegro\\\\")) require PASE_ROOT . "/integrations/allegro/" . substr($c, 19) . ".php"; });'
        . '\Pase\Support\Env::load(PASE_ROOT . "/.env");'
        . 'if (!function_exists("t")) { function t($k, $v = []) { return \Pase\Support\I18n::t($k, $v); } } }'
        . 'return ["db" => [], "allegro" => []];';
    file_put_contents("$temp/config/config.php", $boot);
    file_put_contents("$temp/config/database.php", '<?php return static function ($db) { $pdo = new PDO("sqlite:" . PASE_ROOT . "/test.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); \\Pase\\Support\\Runtime::setPdo($pdo); return $pdo; };');
    file_put_contents("$temp/public/admin/db_admin.php", '<?php $config = require dirname(__DIR__, 2) . "/config/config.php"; $pdo = (require PASE_ROOT . "/config/database.php")($config["db"]);');

    $pdo = new PDO("sqlite:$temp/test.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT, role TEXT, password_hash TEXT, is_active INTEGER, session_version INTEGER, totp_secret TEXT, totp_last_step INTEGER DEFAULT -1, totp_recovery_hashes TEXT, last_login TEXT, totp_required INTEGER NOT NULL DEFAULT 0, email TEXT)');
    $ins = $pdo->prepare('INSERT INTO admin_users (id,username,display_name,role,password_hash,is_active,session_version) VALUES (?,?,?,?,?,1,1)');
    foreach ([1 => ['boss', 'Adam', 'admin'], 3 => ['vi', 'Wiktor', 'viewer']] as $id => [$u, $n, $role]) {
        $ins->execute([$id, $u, $n, $role, password_hash('Only-Test-Password-42', PASSWORD_DEFAULT)]);
    }
    PagePermissions::migrate($pdo);
    \Pase\Services\AuditTrail::migrate($pdo);
    $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
    $pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT, is_active INTEGER, config TEXT, webhook_secret TEXT NULL, access_token TEXT NULL, refresh_token TEXT NULL, token_expires_at TEXT NULL)');
    $pdo->exec('CREATE TABLE integrations (id INTEGER PRIMARY KEY, platform_name TEXT, webhook_secret TEXT, access_token TEXT, refresh_token TEXT, token_expires_at TEXT, meta TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, description TEXT, short_description TEXT, pase_stock INTEGER, actual_stock INTEGER NULL,
        pase_price REAL, images TEXT, categories TEXT, attributes TEXT, ean TEXT, weight REAL, width REAL, length REAL, height REAL, woo_product_id INTEGER, allegro_sections TEXT, custom_fields TEXT)');
    $pins = $pdo->prepare('INSERT INTO products (id, sku, name, description, pase_stock, pase_price, images, categories, weight, width, length, height) VALUES (?,?,?,?,5,39.9,?,?,?,?,?,?)');
    $pins->execute([1, 'PJ-100', 'Świeca sojowa Pieczone Jabłko 100 g', '<p>Wypełnij swoje wnętrze ciepłem.</p>',
        json_encode(['https://sklep.example/pj1.jpg', 'https://sklep.example/pj2.jpg']), json_encode(['Świece'], JSON_UNESCAPED_UNICODE), 0.24, 6.2, 6.2, 6.3]);
    $pins->execute([2, 'PI-100', 'Świeca sojowa Piernik 100 g', '', json_encode(['https://sklep.example/piernik.jpg']), json_encode(['Świece'], JSON_UNESCAPED_UNICODE), null, null, null, null]);
    $pins->execute([3, 'WO-1', 'Wosk sojowy 1 kg', '', json_encode(['https://sklep.example/wosk.jpg']), json_encode(['Woski'], JSON_UNESCAPED_UNICODE), null, null, null, null]);
    $pdo->exec('CREATE TABLE product_mappings (id INTEGER PRIMARY KEY, sku TEXT, woo_product_id INTEGER, woo_variant_id INTEGER, allegro_offer_id TEXT, created_at TEXT, updated_at TEXT)');
    $pdo->exec("INSERT INTO product_mappings (sku, allegro_offer_id) VALUES ('PJ-100', '17000000001')");
    $pdo->exec('CREATE TABLE offer_templates (id INTEGER PRIMARY KEY, product_id INTEGER, marketplace TEXT, data TEXT, status TEXT, external_id TEXT, created_at TEXT, updated_at TEXT)');
    // Oferty zapisane wcześniej: dane rozszerzenia pod starym kluczem i już pod 'ext'.
    $tins = $pdo->prepare("INSERT INTO offer_templates (product_id, marketplace, data, status) VALUES (?, 'allegro', ?, 'draft')");
    $tins->execute([3, json_encode(['stary_klucz' => 'Z dawnej wersji'], JSON_UNESCAPED_UNICODE)]);
    $tins->execute([2, json_encode(['ext' => ['opisdemo' => ['tekst' => 'Zapisany'], 'obce' => ['x' => 1]]], JSON_UNESCAPED_UNICODE)]);
    $pdo->exec('CREATE TABLE product_fields (id INTEGER PRIMARY KEY AUTOINCREMENT, field_key TEXT UNIQUE, label TEXT, type TEXT DEFAULT "text", options TEXT, categories TEXT, position INTEGER DEFAULT 0, woo_meta_key TEXT)');
    $pdo->exec("INSERT INTO product_fields (field_key, label, position) VALUES ('aroma_head', 'Aroma head', 1), ('aroma_heart', 'Aroma heart', 2), ('aroma_base', 'Aroma base', 3), ('opis_kr', 'Krótki opis', 4)");
    $pdo->prepare('UPDATE products SET custom_fields = ? WHERE id = 1')->execute([json_encode(['aroma_head' => 'Jabłko', 'aroma_heart' => 'Cynamon', 'aroma_base' => 'Wanilia', 'opis_kr' => 'Szarlotka babci'], JSON_UNESCAPED_UNICODE)]);
    $pdo->exec('CREATE TABLE allegro_desc_templates (id INTEGER PRIMARY KEY, name TEXT, categories TEXT, sections TEXT, created_at TEXT, updated_at TEXT)');

    $free = static function (): string { $s = stream_socket_server('tcp://127.0.0.1:0'); $a = stream_socket_get_name($s, false); fclose($s); return $a; };
    $address = $free();
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes);
    fclose($pipes[0]);
    for ($i = 0; $i < 100; ++$i) { $p = @stream_socket_client('tcp://' . $address, $e1, $e2, 0.1); if ($p) { fclose($p); break; } usleep(10000); }

    $boss = login('boss');
    $extOf = static function (string $body): ?array {
        preg_match('/id="f_ext_json" value="([^"]*)"/', $body, $m);
        return isset($m[1]) ? json_decode(html_entity_decode($m[1], ENT_QUOTES), true) : null;
    };
    $page = request($boss, 'admin/offer_allegro.php?product=1');
    ok($page['status'] === 200 && preg_match('/<button type="button" id="opisDemo" data-product="1" data-csrf="([a-f0-9]+)">Świeca sojowa Pieczone Jabłko 100 g<\/button>/', $page['body'], $m) === 1,
        'description_tools: extension HTML above the description editor with product and CSRF');
    ok(strpos($page['body'], 'id="opisDemo"') < strpos($page['body'], 'id="descEditor"'), 'Extension tools are in the description card');
    ok($extOf($page['body']) === [], 'New offer: no extension data');
    ok(str_contains($page['body'], 'window.OfferForm = {') && str_contains($page['body'], "setExt: function (key, value)")
        && str_contains($page['body'], "fd.append('ext_data', extInput.value)"), 'OfferForm API on the page; live description push sends extension data');
    ok($extOf(request($boss, 'admin/offer_allegro.php?product=3')['body']) === ['opisdemo' => ['tekst' => 'Z dawnej wersji']], 'ext_data filter on open moves data from an old key');
    ok($extOf(request($boss, 'admin/offer_allegro.php?product=2')['body']) === ['opisdemo' => ['tekst' => 'Zapisany'], 'obce' => ['x' => 1]],
        'Saved extension data comes back, also for a disabled extension');
    $r = request($boss, 'admin/offer_allegro.php?product=1&ajax=push_desc', ['csrf' => $m[1], 'offer_id' => '17000000001',
        'description_sections' => json_encode([['items' => [['type' => 'TEXT', 'content' => '<p>Nowy</p>']]]]), 'ext_data' => json_encode(['opisdemo' => ['tekst' => 'x']])]);
    ok(($r['json']['ok'] ?? null) === false, 'Description push with extension data still needs the Allegro offer');
    ok(\Pase\Services\AllegroLiveOffer::editedForm(['ext' => ['a' => 1], 'title' => 'A'], ['ext' => ['a' => 2], 'title' => 'B'], ['ext_data']) === ['ext' => ['a' => 2], 'title' => 'A'],
        'Live offer update keeps changed extension data');

    // Wyłączone rozszerzenie: bez przycisku, formularz działa.
    touch("$temp/extensions/opisdemo/.disabled");
    $page = request($boss, 'admin/offer_allegro.php?product=1');
    ok($page['status'] === 200 && !str_contains($page['body'], 'id="opisDemo"') && $extOf($page['body']) === [], 'Disabled extension: form without its tools');

    echo "\n{$checks} checks passed\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (getenv('KEEP_TEMP')) { echo "temp: $temp\n"; } else { exec('rm -rf ' . escapeshellarg($temp)); }
}
