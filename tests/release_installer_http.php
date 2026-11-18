<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Kreator instalacji (instaluj.php + veless-<wersja>.zip): budowa paczki i instalacja od zera przez
// przeglądarkę (php -S) do NOWEJ bazy, potem logowanie do panelu założonym kontem.
// Explicit opt-in. Only loopback server, random new databases; never loads the repository .env.
if (getenv('CRM_TEST_MYSQL') !== '1') {
    fwrite(STDERR, "NOT RUN: set CRM_TEST_MYSQL=1 and dedicated local test MySQL credentials.\n");
    exit(2);
}

$checks = 0;
function check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    ++$checks; echo "OK: $label\n";
}
$repo = dirname(__DIR__);
$port = (int) (getenv('CRM_TEST_MYSQL_PORT') ?: 3306);
$user = getenv('CRM_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('CRM_TEST_MYSQL_PASSWORD') ?: '';
$server = new PDO('mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4', $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$busy = 'crm_test_' . bin2hex(random_bytes(12));
$target = 'crm_test_' . bin2hex(random_bytes(12));
$temp = sys_get_temp_dir() . '/veless-release-' . bin2hex(random_bytes(8));
$procs = [];
$rm = static function (string $path) use (&$rm): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) { return; }
    foreach (scandir($path) as $e) { if ($e !== '.' && $e !== '..') { $rm($path . '/' . $e); } }
    rmdir($path);
};
$serve = static function (string $docroot, array $env = [], ?string $router = null) use (&$procs, $temp): string {
    $httpPort = 18000 + random_int(0, 1999);
    $procs[] = proc_open(array_merge([PHP_BINARY, '-S', '127.0.0.1:' . $httpPort, '-t', $docroot], $router !== null ? [$router] : []), [1 => ['file', '/dev/null', 'w'], 2 => ['file', $temp . '/server-' . $httpPort . '.log', 'w']],
        $pipes, null, $env + ['PATH' => getenv('PATH') ?: '/usr/bin', 'PHP_CLI_SERVER_WORKERS' => '4']);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $httpPort) === false; ++$i) { usleep(100000); }
    return 'http://127.0.0.1:' . $httpPort;
};
$client = static function (string $url) {
    $cookies = [];
    return static function (array $post = [], string $path = '') use (&$cookies, $url): array {
        $jar = implode('; ', array_map(static fn($k, $v) => $k . '=' . $v, array_keys($cookies), $cookies));
        $ctx = stream_context_create(['http' => ['method' => $post ? 'POST' : 'GET', 'ignore_errors' => true, 'follow_location' => 0,
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nCookie: $jar\r\n", 'content' => http_build_query($post)]]);
        $body = (string) @file_get_contents($url . $path, false, $ctx);
        $code = 0; $location = '';
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) { $code = (int) $m[1]; }
            if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $line, $m)) { $cookies[$m[1]] = $m[2]; }
            if (preg_match('/^Location:\s*(\S+)/i', $line, $m)) { $location = $m[1]; }
        }
        return [$code, $body, $location];
    };
};
$csrf = static fn(string $html): string => preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : '';

try {
    foreach ([$busy, $target] as $db) { $server->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4"); }
    $server->exec("CREATE TABLE `$busy`.something (id INT)");

    // --- Paczka ---
    $dist = $temp . '/dist';
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/cli/build_release.php') . ' --version=1.0.999 --commit=abc123 --out=' . escapeshellarg($dist), $out, $rc);
    check($rc === 0, 'Release builds: ' . implode(' ', $out));
    foreach (['veless-1.0.999.zip', 'instaluj.php', 'INSTRUKCJA.txt', 'veless-1.0.999-instalacja.zip'] as $f) { check(is_file($dist . '/' . $f), "dist/$f exists"); }
    $zip = new ZipArchive(); $zip->open($dist . '/veless-1.0.999.zip');
    $manifest = json_decode((string) $zip->getFromName('package.json'), true);
    $names = []; for ($i = 0; $i < $zip->numFiles; ++$i) { $names[] = $zip->getNameIndex($i); }
    $build = json_decode((string) $zip->getFromName('app/config/build.json'), true);
    $zip->close();
    check($manifest['format'] === 'veless-release-v1' && $manifest['version'] === '1.0.999' && count($manifest['files']) === count($names) - 1, 'Manifest lists every file with its version');
    check(in_array('app/config/config.php', $names, true) && in_array('app/storage/.htaccess', $names, true) && in_array('app/storage/security/.htaccess', $names, true)
        && in_array('app/.env.example', $names, true), 'Package holds code, .env template and storage protection');
    $allowed = ['app/storage/.htaccess', 'app/storage/.gitkeep', 'app/storage/security/.htaccess', 'app/integrations/README.md', 'app/extensions/README.md'];
    check(!preg_grep('#^app/(tests|docker|site|\.git|\.github|dist|examples|storage)/|^app/\.env$|\.md$#', array_diff($names, $allowed)), 'No tests, Docker, site, git, .env, data or docs in the package');
    check($build['version'] === '1.0.999' && $build['commit'] === 'abc123', 'build.json carries the release version');

    // --- Kopia kreatora w działającym systemie nic nie robi ---
    $repoUrl = $serve($repo);
    [$code] = $client($repoUrl)([], '/src/Installer/setup.php');
    check($code === 404, 'src/Installer/setup.php of a live system answers 404');

    // --- Instalacja przez przeglądarkę, budżet ~0: każdy krok wznawia się wiele razy ---
    $web = $temp . '/web/veless'; mkdir($web, 0755, true);
    copy($dist . '/instaluj.php', $web . '/instaluj.php');
    // Jak hosting z .htaccess: gdy istnieje plik $guard, serwer odmawia .env, storage/ i paczek.
    $guard = $temp . '/guard';
    $router = $temp . '/router.php';
    file_put_contents($router, '<?php $p = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH); if (is_file(' . var_export($guard, true) . ') && preg_match("#/\\.env$|/storage/|\\.zip$#", $p)) { http_response_code(403); echo "Forbidden"; return true; } return false;');
    $base = $serve($temp . '/web', ['VELESS_INSTALLER_BUDGET' => '0.02'], $router);
    $http = $client($base . '/veless/instaluj.php');
    [, $page] = $http();
    check(str_contains($page, '✗') && str_contains($page, 'veless-&lt;wersja&gt;.zip') && str_contains($page, ' disabled'), 'Without the package the requirements page blocks the start');
    copy($dist . '/veless-1.0.999.zip', $web . '/veless-1.0.999.zip');
    [, $page] = $http();
    check(str_contains($page, 'Instalacja Veless 1.0.999') && str_contains($page, 'pdo_mysql') && !str_contains($page, ' disabled'), 'Requirements pass once the package is uploaded');
    $http(['csrf' => $csrf($page), 'action' => 'start']);
    [, $page] = $http();
    check(str_contains($page, 'Konto administratora') && str_contains($page, $base . '/veless/public'), 'Second step asks for database, address and admin with detected URL');

    $intruder = $client($base . '/veless/instaluj.php');
    [, $other] = $intruder();
    check(str_contains($other, 'w innej przeglądarce'), 'Another browser cannot take over a started installation');

    $panel = $base . '/veless/public';
    $form = ['action' => 'configure', 'db_host' => '127.0.0.1', 'db_port' => (string) $port, 'db_user' => $user, 'db_pass' => $pass, 'base_url' => $panel,
        'admin_user' => 'wlasciciel', 'admin_email' => 'wlasciciel@example.com', 'admin_pass' => 'Mocne-haslo-123', 'admin_pass2' => 'Mocne-haslo-123'];
    [, $page] = $http(['csrf' => $csrf($page), 'db_name' => $target, 'admin_pass2' => 'inne-haslo-123'] + $form);
    check(str_contains($page, 'nie są takie same') && !is_dir($web . '/config'), 'Mismatched admin passwords are rejected before any file is written');
    [, $page] = $http(['csrf' => $csrf($page), 'db_name' => $busy] + $form);
    check(str_contains($page, 'nie jest pusta') && !is_dir($web . '/config'), 'A non-empty database is refused');
    [, $page] = $http(['csrf' => $csrf($page), 'db_name' => $target, 'db_pass' => 'zle"haslo'] + $form);
    check(str_contains($page, 'nie mogą zawierać'), 'Database password with quotes is refused (would break .env)');
    $http(['csrf' => $csrf($page), 'db_name' => $target] + $form);

    $steps = 0;
    [, $page] = $http();
    while (++$steps < 5000 && str_contains($page, 'Rozpakowywanie plików')) {
        [$code, $page] = $http(['csrf' => $csrf($page), 'action' => 'continue']);
        if ($code === 303) { [, $page] = $http(); }
        if ($steps === 3) {
            check(str_contains((string) @file_get_contents($web . '/.htaccess'), 'veless-installer') || !is_file($web . '/.htaccess'), 'Installer block is added to .htaccess while files land');
        }
    }
    if (!str_contains($page, 'Bezpieczeństwo i cron')) { echo substr(strip_tags($page), 0, 800), "\n"; }
    check(str_contains($page, 'Bezpieczeństwo i cron'), "Files extracted and system configured ($steps resumed steps)");
    check(str_contains($page, $web . '/cli/worker.php') && str_contains($page, '* * * * *'), 'Cron line with the real install path is shown');
    check(str_contains($page, 'Cron jeszcze nie ruszył'), 'Cron check reports no worker run yet');
    // php -S nie czyta .htaccess, więc .env jest widoczny - kreator musi to wykryć i nie pozwolić skończyć.
    check(str_contains($page, 'można pobrać z internetu') && preg_match('/value="finish"><button type="submit" disabled/', $page) === 1, 'Exposed .env is detected and blocks finishing');

    $env = (string) file_get_contents($web . '/.env');
    check(str_contains($env, 'DB_NAME="' . $target . '"') && str_contains($env, 'APP_MODE=server') && preg_match('/^WORKER_HTTP_SECRET="[a-f0-9]{48}"$/m', $env)
        && preg_match('/^ADMIN_DEFAULT_PASSWORD=$/m', $env) && str_contains($env, 'ALLEGRO_REDIRECT_URI="' . $panel . '/auth_allegro_callback.php"'), '.env written from template with database, secret and redirect');
    check((fileperms($web . '/.env') & 0077) === 0 && (fileperms($web . '/storage/security/totp.key') & 0077) === 0, '.env and encryption key are private (0600)');
    check(strlen((string) base64_decode((string) file_get_contents($web . '/storage/security/totp.key'), true)) === 32, 'Encryption key is 32 random bytes');

    $pdo = new PDO('mysql:host=127.0.0.1;port=' . $port . ';dbname=' . $target . ';charset=utf8mb4', $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    check(count($tables) > 40 && in_array('woo_orders', $tables, true) && in_array('job_queue', $tables, true) && in_array('user_permissions', $tables, true), 'Schema created by the app migrations (' . count($tables) . ' tables)');
    $admins = $pdo->query('SELECT username, email, role, password_hash FROM admin_users')->fetchAll();
    check(count($admins) === 1 && $admins[0]['username'] === 'wlasciciel' && $admins[0]['role'] === 'admin' && password_verify('Mocne-haslo-123', $admins[0]['password_hash']),
        'Exactly one admin, with the login and password from the wizard');
    check(!is_file($web . '/storage/ADMIN_PASSWORD.txt'), 'No default password file');
    check((json_decode((string) @file_get_contents($web . '/storage/release.json'), true)['version'] ?? '') === '1.0.999', 'Release manifest kept for future updates (storage/release.json)');
    $settings = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    check($settings['APP_BASE_URL'] === $panel && $settings['INSTALLED_VERSION'] === '1.0.999', 'Panel address and installed version stored');

    // Pierwszy przebieg crona i blokada .env (jak na hostingu z .htaccess) - wtedy kreator pozwala skończyć.
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($web . '/cli/worker.php') . ' 2>&1', $wout, $wrc);
    $lastRun = (int) $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'WORKER_LAST_RUN_AT'")->fetchColumn();
    check($wrc === 0 && $lastRun >= time() - 120, 'Worker (cron) runs on the fresh installation: ' . substr(implode(' ', $wout), 0, 200));
    touch($guard);
    [, $page] = $http();
    check(str_contains($page, 'są zablokowane z internetu') && str_contains($page, 'Cron działa'), 'With protection in place and worker running both checks pass');
    [, $page] = $http(['csrf' => $csrf($page), 'action' => 'finish']);
    [, $page] = $http();
    check(str_contains($page, 'Veless 1.0.999 zainstalowany') && str_contains($page, $panel . '/admin/'), 'Final page links to the panel');
    check(!is_file($web . '/instaluj.php') && !is_file($web . '/veless-1.0.999.zip') && !is_file($web . '/.veless-install.json'), 'Installer, package and state file are removed');
    check(!str_contains((string) file_get_contents($web . '/.htaccess'), 'veless-installer'), 'Installer block removed from .htaccess');

    // Logowanie do panelu nowym kontem.
    $panelHttp = $client($panel . '/admin/');
    [, $login] = $panelHttp([], 'login.php');
    $token = preg_match('/name="(csrf|_csrf|csrf_token)" value="([^"]+)"/', $login, $m) ? [$m[1], $m[2]] : ['csrf', ''];
    [$code, $body, $loc] = $panelHttp([$token[0] => $token[1], 'username' => 'wlasciciel', 'password' => 'Mocne-haslo-123'], 'login.php');
    check(in_array($code, [302, 303], true) && !str_contains($loc, 'login.php'), 'Admin logs into the panel with the wizard password (HTTP ' . $code . ' → ' . $loc . ')');
    [$code, $dash] = $panelHttp([], 'index.php');
    check($code === 200 && str_contains($dash, 'v1.0.999'), 'Panel opens after login and shows the release version (HTTP ' . $code . ')');
    check((int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() === 1, 'Opening the panel does not add the default admin account');

    // Ponowne wgranie kreatora do zainstalowanego katalogu nic nie robi.
    copy($dist . '/instaluj.php', $web . '/instaluj.php');
    [, $page] = $client($base . '/veless/instaluj.php')();
    check(str_contains($page, 'jest już zainstalowany'), 'Installer refuses to run over an existing installation');
    echo "\nAll $checks checks passed.\n";
} finally {
    foreach ($procs as $p) { @proc_terminate($p); }
    foreach ([$busy, $target] as $db) { try { $server->exec("DROP DATABASE IF EXISTS `$db`"); } catch (Throwable) {} }
    if (getenv('KEEP_TEMP') !== '1') { $rm($temp); } else { echo "Temp kept: $temp\n"; }
}
