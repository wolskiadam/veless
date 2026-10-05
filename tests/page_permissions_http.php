<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Uprawnienia do stron (Ukryte / Podgląd / Edycja) na prawdziwym auth.php, users.php i menu.
// Strony docelowe są uproszczonymi atrapami; baza SQLite w katalogu tymczasowym.
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Pase\\')) {
        require $root . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    }
});
use Pase\Support\PagePermissions;

$checks = 0;
function ok(bool $condition, string $name): void {
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $name); }
    ++$checks; echo "OK: {$name}\n";
}
function csrf(string $html): string {
    if (!preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $match)) { throw new RuntimeException('Missing CSRF in test response'); }
    return $match[1];
}
function client(): CurlHandle {
    $handle = curl_init();
    curl_setopt_array($handle, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_HEADER => true]);
    return $handle;
}
function request(CurlHandle $handle, string $path, ?array $data = null): array {
    global $base;
    curl_setopt($handle, CURLOPT_URL, $base . $path);
    curl_setopt($handle, CURLOPT_POST, $data !== null);
    if ($data !== null) { curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($data)); }
    $result = curl_exec($handle);
    if ($result === false) { throw new RuntimeException(curl_error($handle)); }
    $length = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    return ['status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'headers' => substr($result, 0, $length), 'body' => substr($result, $length)];
}
function login(string $username): CurlHandle {
    $h = client();
    $page = request($h, 'login.php');
    $r = request($h, 'login.php', ['csrf' => csrf($page['body']), 'username' => $username, 'password' => 'Only-Test-Password-42']);
    if ($r['status'] !== 302 && $r['status'] !== 303) { throw new RuntimeException('Login failed for ' . $username); }
    return $h;
}

// --- Rejestr: każda strona panelu z logowaniem musi być w rejestrze albo na liście wyjątków ---
// ext.php (strony rozszerzeń) sprawdza rolę z definicji każdej strony - jak users.php, poza rejestrem.
$exempt = ['auth.php', 'login.php', 'users.php', 'security.php', 'quick_approve.php', 'notifications.php', 'layout_save.php', 'ui_scale_save.php', 'header.php', 'nav.php', 'footer.php', 'bootstrap_admin.php', 'ext.php'];
$missing = [];
foreach (glob($root . '/public/admin/*.php') as $file) {
    $name = basename($file);
    if (in_array($name, $exempt, true) || !preg_match("#require(_once)? __DIR__ \\. '/auth\\.php'#", (string) file_get_contents($file))) { continue; }
    if (PagePermissions::keyForFile($name) === null) { $missing[] = $name; }
}
ok($missing === [], 'Every panel page is covered by the permission registry' . ($missing ? ': ' . implode(', ', $missing) : ''));
foreach (PagePermissions::registry() as $key => $page) {
    foreach ($page['files'] as $f) {
        if (!is_file($root . '/public/admin/' . $f)) { throw new RuntimeException("Registry file missing: $f"); }
    }
}
ok(true, 'Every registry file exists');
ok(PagePermissions::roleDefault('editor', 'backup') === 'hidden' && PagePermissions::roleDefault('editor', 'orders') === 'edit'
    && PagePermissions::roleDefault('viewer', 'orders') === 'view' && PagePermissions::roleDefault('viewer', 'allegro_sync') === 'hidden',
    'Role defaults match previous role behaviour');

$temp = sys_get_temp_dir() . '/crm-perms-http-' . bin2hex(random_bytes(8));
mkdir($temp . '/public/admin', 0700, true);
mkdir($temp . '/storage/security', 0700, true);
$process = null;
try {
    file_put_contents($temp . '/.env', "TOTP_REQUIRE_ADMIN=0\n");
    foreach (['auth.php', 'login.php', 'users.php', 'header.php', 'nav.php', 'footer.php'] as $file) {
        copy($root . '/public/admin/' . $file, $temp . '/public/admin/' . $file);
    }
    file_put_contents($temp . '/public/admin/bootstrap_admin.php', '<?php \Pase\Support\PagePermissions::migrate($pdo);');
    // Atrapy stron: ta sama kolejność co w prawdziwych plikach (auth.php, potem ewentualne requireRole).
    $stub = static function (string $name, string $body) use ($temp): void {
        file_put_contents($temp . '/public/admin/' . $name, "<?php require __DIR__ . '/auth.php';\n" . $body);
    };
    $stub('index.php', 'echo "ORDERS ", canEdit() ? "EDIT" : "VIEW"; if ($_SERVER["REQUEST_METHOD"] === "POST") { echo " SAVED"; }');
    $stub('products.php', '$PAGE_TITLE = "Produkty"; require __DIR__ . "/header.php"; echo "PRODUCTS ", canEdit() ? "EDIT" : "VIEW"; if ($_SERVER["REQUEST_METHOD"] === "POST") { echo " SAVED"; } require __DIR__ . "/footer.php";');
    $stub('categories.php', '$PAGE_TITLE = "Kategorie"; require __DIR__ . "/header.php"; echo "CATEGORIES"; require __DIR__ . "/footer.php";');
    $stub('import_orders.php', 'requireRole(["admin", "editor"]); echo isset($_GET["run"]) ? "IMPORT RUN" : "IMPORT";');
    $stub('allegro_sync.php', 'requireRole(["admin", "editor"]); echo "ALLEGRO";');
    $stub('menu_order.php', 'requireRole(["admin"]); echo "MENUORDER"; if ($_SERVER["REQUEST_METHOD"] === "POST") { echo " SAVED"; }');
    $stub('statistics.php', 'echo "STATS";');
    $stub('token.php', 'echo \'name="csrf" value="\', csrfToken(), \'"\';');
    mkdir($temp . '/lang');
    foreach (glob($root . '/lang/*.php') as $f) { copy($f, $temp . '/lang/' . basename($f)); }
    $bootstrap = '<?php define("PASE_ROOT", ' . var_export($temp, true) . ');'
        . 'spl_autoload_register(static function($class) { if(str_starts_with($class, "Pase\\\\")) require '
        . var_export($root . '/src/', true) . '.str_replace("\\\\", "/", substr($class,5)).".php"; });'
        . '\\Pase\\Support\\Env::load(PASE_ROOT."/.env");'
        . '$pdo=new PDO("sqlite:".PASE_ROOT."/test.sqlite",null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);'
        . 'if (!function_exists("t")) { function t($key,$vars=[]) {return \\Pase\\Support\\I18n::t($key,$vars);} }';
    file_put_contents($temp . '/public/admin/db_admin.php', $bootstrap);

    $pdo = new PDO('sqlite:' . $temp . '/test.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY, username TEXT, display_name TEXT, role TEXT, password_hash TEXT, is_active INTEGER, session_version INTEGER, totp_secret TEXT, totp_last_step INTEGER DEFAULT -1, totp_recovery_hashes TEXT, last_login TEXT, totp_required INTEGER NOT NULL DEFAULT 0, email TEXT)');
    $ins = $pdo->prepare("INSERT INTO admin_users (id,username,role,password_hash,is_active,session_version) VALUES (?,?,?,?,1,1)");
    foreach ([1 => ['boss', 'admin'], 2 => ['ed', 'editor'], 3 => ['vi', 'viewer']] as $id => [$name, $role]) {
        $ins->execute([$id, $name, $role, password_hash('Only-Test-Password-42', PASSWORD_DEFAULT)]);
    }
    PagePermissions::migrate($pdo);

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false); fclose($socket);
    $base = 'http://' . $address . '/';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', $temp . '/public/admin'],
        [0 => ['pipe', 'r'], 1 => ['file', $temp . '/server.log', 'a'], 2 => ['file', $temp . '/server.log', 'a']], $pipes);
    fclose($pipes[0]);
    for ($i = 0; $i < 100; ++$i) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($probe) { fclose($probe); break; }
        usleep(10000);
    }

    // --- Konto bez zapisanych uprawnień działa dokładnie wg roli (nikt nie traci dostępu) ---
    $ed = login('ed');
    ok(request($ed, 'index.php')['body'] === 'ORDERS EDIT', 'Editor without permissions keeps role access');
    ok(request($ed, 'import_orders.php')['body'] === 'IMPORT', 'Editor keeps editor-only pages');
    ok(request($ed, 'menu_order.php')['status'] === 403, 'Editor still blocked from admin-only page');
    $vi = login('vi');
    ok(request($vi, 'index.php')['body'] === 'ORDERS VIEW', 'Viewer without permissions keeps read-only role');
    ok(request($vi, 'import_orders.php')['status'] === 403, 'Viewer still blocked from editor pages');

    // --- Administrator zapisuje szczegółowe uprawnienia w Użytkownicy ---
    $boss = login('boss');
    $page = request($boss, 'users.php?edit=3');
    ok(str_contains($page['body'], 'Uprawnienia do stron') && str_contains($page['body'], 'działa teraz według roli'), 'Edit form shows permission grid and role mode');
    ok(substr_count($page['body'], 'type="radio" name="perms[') === 2 * 3 * count(PagePermissions::registry()), 'Grid has three levels for every page (edit + add forms)');
    $perms = array_fill_keys(array_keys(PagePermissions::registry()), 'hidden');
    $perms = array_merge($perms, ['orders' => 'view', 'products' => 'edit', 'categories' => 'view', 'orders_import' => 'view', 'menu_order' => 'view']);
    $r = request($boss, 'users.php', ['action' => 'update', 'id' => 3, 'role' => 'viewer', 'is_active' => '1', 'csrf' => csrf($page['body']), 'perms' => $perms]);
    ok($r['status'] === 303 && PagePermissions::load($pdo, 3)['products'] === 'edit', 'Admin saves page permissions');
    ok(str_contains(request($boss, 'users.php')['body'], 'Edycja: 1 · Podgląd: 4'), 'User list summarises permissions');
    ok(str_contains(request($boss, 'users.php')['body'], 'Wg roli'), 'User list marks role-based accounts');

    // --- Poziomy dostępu użytkownika ---
    $vi = login('vi'); // zapis unieważnił sesję
    ok(request($vi, 'index.php')['body'] === 'ORDERS VIEW', 'View level opens the page');
    $r = request($vi, 'index.php', ['x' => '1']);
    ok($r['status'] === 403 && str_contains($r['body'], 'Tylko podgląd'), 'View level blocks saving');
    ok(request($vi, 'products.php')['status'] === 200 && str_contains(request($vi, 'products.php')['body'], 'PRODUCTS EDIT'), 'Edit level grants editing to a viewer-role account');
    $csrfPage = request($vi, 'token.php');
    ok(str_contains(request($vi, 'products.php', ['x' => 1, 'csrf' => csrf($csrfPage['body'])])['body'], 'SAVED'), 'Edit level allows saving');
    ok(request($vi, 'import_orders.php')['body'] === 'IMPORT', 'Granted view opens page that required editor role');
    ok(request($vi, 'import_orders.php?run=1')['status'] === 403, 'View level blocks GET actions that change data');
    ok(request($vi, 'allegro_sync.php')['status'] === 403, 'Hidden page is blocked');
    ok(request($vi, 'statistics.php')['status'] === 403, 'Page not granted is hidden by default');
    ok(request($vi, 'menu_order.php')['body'] === 'MENUORDER', 'Admin-only page can be granted');
    ok(request($vi, 'menu_order.php', ['x' => 1])['status'] === 403, 'Granted admin-only page stays read-only at view level');
    ok(request($vi, 'users.php')['status'] === 403, 'User management stays admin-only');

    // --- Menu i baner podglądu ---
    $nav = request($vi, 'products.php')['body'];
    ok(str_contains($nav, 'href="products.php"') && str_contains($nav, 'href="index.php" class="nav-dropdown-item'), 'Menu shows granted pages');
    ok(!str_contains($nav, 'href="allegro_sync.php"') && !str_contains($nav, 'href="statistics.php"') && !str_contains($nav, 'href="users.php"'), 'Menu hides hidden pages');
    ok(!str_contains($nav, 'nav.allegro') && !str_contains($nav, '>Marketplace') && !str_contains($nav, 'Allegro</span>'), 'Empty submenu and group disappear');
    ok(!str_contains($nav, 'Tryb podglądu'), 'No view-only banner on editable page');
    ok(str_contains(request($vi, 'categories.php')['body'], 'Tryb podglądu'), 'View-only banner on read-only page');
    $navEd = request($ed, 'index.php');
    ok($navEd['body'] === 'ORDERS EDIT', 'Other accounts unaffected');

    // --- Brak dostępu do strony startowej: przekierowanie na pierwszą dostępną ---
    $pdo->exec("UPDATE user_permissions SET access_level='hidden' WHERE user_id=3 AND page_key='orders'");
    $r = request($vi, 'index.php');
    ok($r['status'] === 302 && str_contains($r['headers'], 'Location: import_orders.php'), 'Hidden start page redirects to first allowed page');

    // --- Zmiana roli na admin czyści uprawnienia; formularz bez pól uprawnień nic nie odbiera ---
    $page = request($boss, 'users.php?edit=2');
    request($boss, 'users.php', ['action' => 'update', 'id' => 2, 'role' => 'editor', 'is_active' => '1', 'csrf' => csrf($page['body'])]);
    ok(PagePermissions::load($pdo, 2) === null, 'Update without permission fields leaves role mode');
    $page = request($boss, 'users.php?edit=3');
    request($boss, 'users.php', ['action' => 'update', 'id' => 3, 'role' => 'admin', 'is_active' => '1', 'csrf' => csrf($page['body']), 'perms' => $perms]);
    ok(PagePermissions::load($pdo, 3) === null, 'Promoting to admin clears page permissions');
    $vi = login('vi');
    ok(request($vi, 'statistics.php')['body'] === 'STATS', 'Admin has full access');

    // --- Nowe konto z uprawnieniami ---
    $page = request($boss, 'users.php');
    request($boss, 'users.php', ['action' => 'create', 'username' => 'nowy', 'password' => 'Only-Test-Password-42', 'role' => 'viewer', 'csrf' => csrf($page['body']),
        'perms' => ['orders' => 'edit', 'bogus_page' => 'edit', 'products' => 'root']]);
    $id = (int) $pdo->query("SELECT id FROM admin_users WHERE username='nowy'")->fetchColumn();
    $saved = PagePermissions::load($pdo, $id);
    ok($saved['orders'] === 'edit' && $saved['products'] === 'hidden' && !isset($saved['bogus_page']), 'Create saves only known pages and levels');
    ok(request(login('nowy'), 'index.php')['body'] === 'ORDERS EDIT', 'New account gets its permissions');

    echo "PASS: {$checks} page permission checks\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (getenv('KEEP_TEST_DIR') !== '1') {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($temp);
    }
}
