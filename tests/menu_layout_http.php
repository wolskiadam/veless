<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Układ menu (Konfiguracja → Układ menu): własne zakładki, podkategorie, przenoszenie i ukrywanie pozycji.
// Najpierw logika AdminMenu na prostym menu, potem zapis w edytorze i pasek menu na prawdziwych stronach (SQLite).
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Pase\\')) { require $root . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Support\AdminMenu;
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

// --- Logika układu na prostym menu ---
$menu = [
    'orders' => ['label' => 'Zamówienia', 'items' => [
        ['href' => 'index.php', 'label' => 'Lista'], ['href' => 'packing.php', 'label' => 'Pakowanie'], ['href' => 'queue.php', 'label' => 'Kolejka'],
    ]],
    'marketplace' => ['label' => 'Marketplace', 'items' => [
        ['key' => 'allegro', 'label' => 'Allegro', 'children' => [['href' => 'a1.php', 'label' => 'A1'], ['href' => 'a2.php', 'label' => 'A2']]],
    ]],
    'config' => ['label' => 'Konfiguracja', 'items' => [['href' => 'menu_order.php', 'label' => 'Układ menu'], ['href' => 'company.php', 'label' => 'Firma']]],
];
$posted = ['v' => 2, 'hidden' => ['queue.php', 'menu_order.php', 'nope.php'], 'groups' => [
    ['key' => 'custom_moja1', 'name' => "  Moja <b>zakładka</b>\n", 'items' => [
        'company.php',
        ['key' => 'custom_sub1', 'name' => 'Wysyłka', 'children' => ['packing.php', 'a2.php', 'evil.php', 'company.php']],
        ['key' => 'allegro', 'name' => '', 'children' => ['a1.php']],
    ]],
    ['key' => 'orders', 'name' => 'Sprzedaż', 'items' => ['queue.php', 'index.php', 'index.php']],
    ['key' => 'bad key', 'items' => ['menu_order.php']],
    ['key' => 'orders', 'items' => []],
]];
$clean = AdminMenu::sanitizeLayout($posted, $menu);
ok(array_column($clean['groups'], 'key') === ['custom_moja1', 'orders'], 'Sanitize: unknown and duplicate tab keys dropped');
ok($clean['groups'][0]['name'] === 'Moja <b>zakładka</b>', 'Sanitize: name trimmed, control characters removed, raw text kept');
ok($clean['groups'][0]['items'][1]['children'] === ['packing.php', 'a2.php'], 'Sanitize: unknown and repeated items dropped from a subcategory');
ok($clean['groups'][1]['items'] === ['queue.php', 'index.php'], 'Sanitize: each item placed once');
ok($clean['hidden'] === ['queue.php'], 'Sanitize: menu layout page and unknown items cannot be hidden');
ok(mb_strlen(AdminMenu::sanitizeLayout(['groups' => [['key' => 'orders', 'name' => str_repeat('ż', 80), 'items' => []]]], $menu)['groups'][0]['name']) === 40, 'Sanitize: name limited to 40 characters');

$json = json_encode($clean, JSON_UNESCAPED_UNICODE);
$nav = AdminMenu::applyLayout($menu, $json);
ok(array_keys($nav) === ['custom_moja1', 'orders', 'config'], 'Custom tab first, empty Marketplace gone, Konfiguracja keeps its menu layout page');
ok($nav['custom_moja1']['label'] === 'Moja &lt;b&gt;zakładka&lt;/b&gt;' && $nav['custom_moja1']['name'] === 'Moja <b>zakładka</b>', 'Custom tab name escaped for the menu bar');
ok($nav['orders']['label'] === 'Sprzedaż' && $nav['orders']['default'] === 'Zamówienia', 'Built-in tab renamed');
$sub = $nav['custom_moja1']['items'][1];
ok($sub['key'] === 'custom_sub1' && $sub['label'] === 'Wysyłka' && array_column($sub['children'], 'href') === ['packing.php', 'a2.php'], 'Custom subcategory with items from different tabs');
ok($nav['custom_moja1']['items'][2]['label'] === 'Allegro', 'Built-in subcategory moved to a custom tab keeps its name');
ok(array_column($nav['orders']['items'], 'href') === ['index.php'], 'Hidden item not shown in the menu');
ok(array_column($nav['config']['items'], 'href') === ['menu_order.php'], 'Items not in the layout return to their default tab');

$editor = AdminMenu::applyLayout($menu, $json, true);
ok(array_column($editor['orders']['items'], 'href') === ['queue.php', 'index.php'] && !empty($editor['orders']['items'][0]['hidden']), 'Editor keeps hidden items in place');

// Nowa pozycja w kodzie (spoza zapisanego układu) - na domyślne miejsce, także do pod-menu.
$menu2 = $menu;
$menu2['orders']['items'][] = ['href' => 'new.php', 'label' => 'Nowa'];
$menu2['marketplace']['items'][0]['children'][] = ['href' => 'a3.php', 'label' => 'A3'];
$nav2 = AdminMenu::applyLayout($menu2, $json);
ok(in_array('new.php', array_column($nav2['orders']['items'], 'href'), true), 'New item appears in its default tab');
ok(array_column($nav2['custom_moja1']['items'][2]['children'], 'href') === ['a1.php', 'a3.php'], 'New Allegro page follows the moved Allegro subcategory');

// Użytkownik widzi tylko część pozycji - reszta układu znika, puste zakładki też.
$limited = AdminMenu::filter($menu, static fn(string $h): bool => in_array($h, ['index.php', 'packing.php'], true));
$nav3 = AdminMenu::applyLayout($limited, $json);
ok(array_keys($nav3) === ['custom_moja1', 'orders'] && array_column($nav3['custom_moja1']['items'][0]['children'], 'href') === ['packing.php'], 'Layout does not reveal items the user cannot see');

// Stary zapis (sama kolejność) nadal działa.
$old = AdminMenu::applyLayout($menu, json_encode(['groups' => ['config', 'orders'], 'items' => ['orders' => ['queue.php']]]));
ok(array_key_first($old) === 'config' && $old['orders']['items'][0]['href'] === 'queue.php', 'Old order-only setting still applied');

// --- Edytor i pasek menu przez HTTP ---
$temp = sys_get_temp_dir() . '/crm-menu-layout-http-' . bin2hex(random_bytes(6));
foreach (['public/admin/assets', 'public/pack/assets', 'config', 'storage/security', 'lang'] as $d) { mkdir("$temp/$d", 0700, true); }
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
    $pdo->exec("INSERT INTO admin_users (id,username,display_name,role,password_hash,is_active,session_version) VALUES (4,'perm','Piotr','viewer','" . password_hash('Only-Test-Password-42', PASSWORD_DEFAULT) . "',1,1)");
    PagePermissions::save($pdo, 4, ['orders' => 'view', 'packing' => 'view']);

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', "$temp/public"],
        [0 => ['pipe', 'r'], 1 => ['file', "$temp/server.log", 'a'], 2 => ['file', "$temp/server.log", 'a']], $pipes);
    fclose($pipes[0]);
    for ($i = 0; $i < 100; ++$i) { $p = @stream_socket_client('tcp://' . $address, $e1, $e2, 0.1); if ($p) { fclose($p); break; } usleep(10000); }

    $boss = login('boss');
    $page = request($boss, 'admin/menu_order.php');
    ok($page['status'] === 200 && str_contains($page['body'], 'Układ menu') && str_contains($page['body'], '+ Nowa zakładka'), 'Menu layout editor opens for admin');

    $layout = ['v' => 2, 'hidden' => ['queue.php'], 'groups' => [
        ['key' => 'custom_abc1', 'name' => 'Magazynier', 'items' => [
            'products.php',
            ['key' => 'custom_sub9', 'name' => 'Paczki', 'children' => ['packing.php', 'returns.php']],
        ]],
        ['key' => 'orders', 'name' => '', 'items' => ['index.php', 'queue.php']],
    ]];
    $r = request($boss, 'admin/menu_order.php', ['csrf' => csrfOf($page['body']), 'action' => 'save', 'layout' => json_encode($layout)]);
    ok(in_array($r['status'], [302, 303], true), 'Layout saved');
    $saved = json_decode((string) $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'NAV_ORDER'")->fetchColumn(), true);
    ok(($saved['v'] ?? null) === 2 && $saved['groups'][0]['key'] === 'custom_abc1', 'Layout stored as version 2');

    $page = request($boss, 'admin/menu_order.php');
    $bar = substr($page['body'], strpos($page['body'], '<nav class="nav-bar">'), strpos($page['body'], '</nav>') - strpos($page['body'], '<nav class="nav-bar">'));
    ok(strpos($bar, 'Magazynier') < strpos($bar, 'Zamówienia'), 'Custom tab shown first in the menu bar');
    ok(preg_match('~Paczki</span>.*?class="nav-flyout">\s*<a href="packing.php"~s', $bar) === 1, 'Custom subcategory rendered as a flyout with its items');
    ok(!str_contains($bar, 'href="queue.php"'), 'Hidden item missing from the menu bar');
    ok(str_contains($bar, 'href="customers.php"'), 'Items left out of the layout stay in their default tab');
    ok(str_contains($page['body'], 'value="Magazynier"') && str_contains($page['body'], 'class="mo-hidden" checked'), 'Editor shows saved names and hidden items');

    // Konto z uprawnieniami do kilku stron widzi własną zakładkę tylko z tymi stronami.
    $perm = login('perm');
    $page = request($perm, 'admin/index.php');
    $bar = substr($page['body'], strpos($page['body'], '<nav class="nav-bar">'), strpos($page['body'], '</nav>') - strpos($page['body'], '<nav class="nav-bar">'));
    ok(str_contains($bar, 'Paczki') && str_contains($bar, 'href="packing.php"') && !str_contains($bar, 'href="products.php"') && !str_contains($bar, 'href="returns.php"'), 'Limited account sees only permitted items in the custom tab');

    $page = request($boss, 'admin/menu_order.php');
    request($boss, 'admin/menu_order.php', ['csrf' => csrfOf($page['body']), 'action' => 'reset']);
    $page = request($boss, 'admin/menu_order.php');
    ok(!str_contains($page['body'], 'Magazynier') && str_contains($page['body'], 'href="queue.php"'), 'Reset restores the default menu');

    $warnings = preg_grep('/PHP (Warning|Fatal|Parse|Deprecated|Notice)/', file("$temp/server.log") ?: []);
    ok($warnings === [], 'No PHP warnings' . ($warnings ? ': ' . implode(' | ', array_map('trim', $warnings)) : ''));
    echo "PASS: {$checks} menu layout checks\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    exec('rm -rf ' . escapeshellarg($temp));
}
