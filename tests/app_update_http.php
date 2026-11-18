<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// System → Aktualizacja: instalacja z paczki wersji A, wgranie paczki B przez panel (php -S), aktualizacja,
// odmowa cofnięcia do starszej paczki, cofnięcie z kopii. Explicit opt-in, random new database, loopback only.
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
$dbName = 'crm_test_' . bin2hex(random_bytes(12));
$temp = sys_get_temp_dir() . '/veless-update-' . bin2hex(random_bytes(8));
$proc = null;
$rm = static function (string $path) use (&$rm): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) { return; }
    foreach (scandir($path) as $e) { if ($e !== '.' && $e !== '..') { $rm($path . '/' . $e); } }
    rmdir($path);
};
$copyTree = static function (string $from, string $to) use (&$copyTree): void {
    @mkdir($to, 0755, true);
    foreach (scandir($from) as $e) {
        if ($e === '.' || $e === '..' || $e === '.git' || $e === 'dist') { continue; }
        $src = $from . '/' . $e;
        if (is_link($src)) { continue; }
        is_dir($src) ? $copyTree($src, $to . '/' . $e) : copy($src, $to . '/' . $e);
    }
};
$build = static function (string $root, string $version, string $out): string {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/cli/build_release.php') . ' --version=' . $version . ' --commit=test --out=' . escapeshellarg($out) . ' 2>&1', $o, $rc);
    if ($rc !== 0) { throw new RuntimeException('build failed: ' . implode("\n", $o)); }
    return $out . '/veless-' . $version . '.zip';
};

try {
    $server->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4");

    // --- Paczki A (1.0.10) i B (1.0.11: zmieniony plik, nowy plik, dwa usunięte) ---
    $pkgA = $build($repo, '1.0.10', $temp . '/dist-a');
    $srcB = $temp . '/src-b';
    $copyTree($repo, $srcB);
    file_put_contents($srcB . '/lang/pl.php', str_replace("'nav.update' => 'Aktualizacja',", "'nav.update' => 'Aktualizacja B',", (string) file_get_contents($srcB . '/lang/pl.php')));
    file_put_contents($srcB . '/src/Support/UpdateProbe.php', "<?php\ndeclare(strict_types=1);\n\nnamespace Pase\\Support;\n\nfinal class UpdateProbe { public const B = true; }\n");
    unlink($srcB . '/cli/demo_reset.php');
    unlink($srcB . '/sql/migration_002_woo_orders.sql');
    $pkgB = $build($srcB, '1.0.11', $temp . '/dist-b');

    // --- Instalacja A (to samo, co robi kreator: pliki, .env, klucz, manifest, tabele, admin) ---
    $app = $temp . '/web/veless';
    $zip = new ZipArchive(); $zip->open($pkgA);
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = $zip->getNameIndex($i);
        if (!str_starts_with($n, 'app/')) { continue; }
        @mkdir(dirname($app . '/' . substr($n, 4)), 0755, true);
        file_put_contents($app . '/' . substr($n, 4), $zip->getFromIndex($i));
    }
    file_put_contents($app . '/storage/release.json', $zip->getFromName('package.json'));
    $zip->close();
    if (!is_dir($app . '/storage/security')) { mkdir($app . '/storage/security', 0700, true); }
    file_put_contents($app . '/storage/security/totp.key', base64_encode(random_bytes(32)));
    $env = "APP_ENV=production\nAPP_MODE=server\nDB_HOST=\"127.0.0.1\"\nDB_PORT=$port\nDB_NAME=\"$dbName\"\nDB_USER=\"$user\"\nDB_PASS=\"$pass\"\n";
    file_put_contents($app . '/.env', $env);
    // Lokalna poprawka w pliku, którego B już nie ma - nie może zniknąć. Własna wtyczka spoza paczki - nietknięta.
    file_put_contents($app . '/sql/migration_002_woo_orders.sql', "-- moja poprawka\n", FILE_APPEND);
    mkdir($app . '/extensions/moja', 0755, true);
    file_put_contents($app . '/extensions/moja/plugin.php', "<?php // moja\n");
    exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('$c = require "' . $app . '/config/config.php"; $p = (require "' . $app . '/config/database.php")($c["db"]); \Pase\Support\AdminSchema::migrate($p);'
        . ' $p->prepare("INSERT INTO admin_users (username, email, password_hash, role) VALUES (?, ?, ?, \'admin\')")->execute(["szef", "szef@example.com", password_hash("Mocne-haslo-123", PASSWORD_DEFAULT)]);') . ' 2>&1', $o, $rc);
    check($rc === 0, 'Version A installed: ' . implode(' ', $o));

    // --- Panel przez php -S ---
    $httpPort = 18000 + random_int(0, 1999);
    $proc = proc_open([PHP_BINARY, '-d', 'upload_max_filesize=16M', '-d', 'post_max_size=16M', '-S', '127.0.0.1:' . $httpPort, '-t', $temp . '/web'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', $temp . '/server.log', 'w']],
        $pipes, null, ['PATH' => getenv('PATH') ?: '/usr/bin', 'PHP_CLI_SERVER_WORKERS' => '2']);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $httpPort) === false; ++$i) { usleep(100000); }
    $base = 'http://127.0.0.1:' . $httpPort . '/veless/public/admin/';
    $jar = $temp . '/cookies.txt';
    $http = static function (string $path, ?array $post = null) use ($base, $jar): array {
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120]);
        if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $loc = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        return [$code, $body, $loc];
    };
    $follow = static function (array $r) use ($http, $base): string {
        clearstatcache();
        return $r[0] === 303 || $r[0] === 302 ? $http(substr($r[2], strlen($base)))[1] : $r[1];
    };
    $csrf = static fn(string $html): string => preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : '';
    [, $login] = $http('login.php');
    [$code] = $http('login.php', ['csrf' => $csrf($login), 'username' => 'szef', 'password' => 'Mocne-haslo-123']);
    check($code === 302, 'Admin logs in');

    [$code, $page] = $http('update.php');
    check($code === 200 && str_contains($page, '1.0.10') && str_contains($page, 'Wgraj nową wersję'), 'Update page shows installed version 1.0.10 and the upload form');
    check(str_contains($http('index.php')[1], 'update.php'), 'System menu links to the update page');

    // Wgranie czegoś, co nie jest paczką.
    file_put_contents($temp . '/bad.zip', 'not a zip');
    $page = $follow($http('update.php', ['csrf' => $csrf($page), 'action' => 'upload', 'package' => new CURLFile($temp . '/bad.zip', 'application/zip', 'bad.zip')]));
    check(str_contains($page, 'nie jest poprawny plik ZIP') && glob($app . '/storage/updates/*.zip') === [], 'A broken file is rejected and not stored');

    $page = $follow($http('update.php', ['csrf' => $csrf($page), 'action' => 'upload', 'package' => new CURLFile($pkgB, 'application/zip', 'cokolwiek.zip')]));
    check(is_file($app . '/storage/updates/veless-1.0.11.zip') && str_contains($page, 'Paczka veless-1.0.11.zip'), 'Package B uploaded (stored under its own version name) and inspected');
    check(preg_match('#<small>Zmienione pliki</small><strong>(\d+)</strong>#', $page, $c1) && (int) $c1[1] >= 2
        && preg_match('#<small>Nowe pliki</small><strong>1</strong>#', $page) && preg_match('#<small>Do usunięcia</small><strong>1</strong>#', $page),
        'Plan: changed files (lang + build.json), 1 new, 1 to delete (the locally edited one is kept)');

    $page = $follow($http('update.php', ['csrf' => $csrf($page), 'action' => 'apply', 'file' => 'veless-1.0.11.zip']));
    check(str_contains($page, 'potwierdzenie kopii') && !is_file($app . '/src/Support/UpdateProbe.php'), 'Update requires the backup confirmation');

    // Worker w trakcie pracy: aktualizacja czeka.
    $lock = fopen($app . '/storage/worker.lock', 'c+'); flock($lock, LOCK_EX);
    $page = $follow($http('update.php', ['csrf' => $csrf($page), 'action' => 'apply', 'file' => 'veless-1.0.11.zip', 'confirm' => '1']));
    flock($lock, LOCK_UN); fclose($lock);
    check(str_contains($page, 'Właśnie działa zadanie w tle') && !is_file($app . '/src/Support/UpdateProbe.php'), 'Update refuses while the worker holds its lock, nothing changed');

    $envBefore = file_get_contents($app . '/.env');
    $keyBefore = file_get_contents($app . '/storage/security/totp.key');
    $page = $follow($http('update.php', ['csrf' => $csrf($page), 'action' => 'apply', 'file' => 'veless-1.0.11.zip', 'confirm' => '1']));
    check(str_contains($page, 'do 1.0.11') && str_contains($page, 'z wersji 1.0.10'), 'Update succeeds with a summary');
    check(is_file($app . '/src/Support/UpdateProbe.php') && str_contains((string) file_get_contents($app . '/lang/pl.php'), "'Aktualizacja B'"), 'New and changed files in place');
    check(!is_file($app . '/cli/demo_reset.php') && is_file($app . '/sql/migration_002_woo_orders.sql'), 'File dropped by B removed; locally edited one kept');
    check(file_get_contents($app . '/.env') === $envBefore && file_get_contents($app . '/storage/security/totp.key') === $keyBefore
        && is_file($app . '/extensions/moja/plugin.php'), '.env, encryption key and own plugin untouched');
    $buildJson = json_decode((string) file_get_contents($app . '/config/build.json'), true);
    $release = json_decode((string) file_get_contents($app . '/storage/release.json'), true);
    check($buildJson['version'] === '1.0.11' && $release['version'] === '1.0.11', 'build.json and stored manifest say 1.0.11');
    check(glob($app . '/storage/updates/*.zip') === [] && count(glob($app . '/storage/backups/update/update-*.zip')) === 1, 'Uploaded package cleaned up, one rollback copy kept');
    check(str_contains($page, 'Aktualizacja B') && preg_match('#class="up-ver">1\.0\.11<#', $page), 'Next request runs the new code (menu label from B, version 1.0.11)');
    check(!glob($app . '/**/*.upd-part') && !glob($app . '/*/*/*.upd-part'), 'No temporary files left');

    // Starsza paczka: odmowa.
    $page = $follow($http('update.php', ['csrf' => $csrf($page), 'action' => 'upload', 'package' => new CURLFile($pkgA, 'application/zip', 'veless-1.0.10.zip')]));
    check(str_contains($page, 'To starsza wersja'), 'Older package is flagged as a downgrade');
    $page = $follow($http('update.php', ['csrf' => $csrf($page), 'action' => 'apply', 'file' => 'veless-1.0.10.zip', 'confirm' => '1']));
    check(str_contains($page, 'Cofnięcie wersji nie jest obsługiwane') && is_file($app . '/src/Support/UpdateProbe.php'), 'Downgrade from a package is refused');
    $page = $follow($http('update.php', ['csrf' => $csrf($page), 'action' => 'delete', 'file' => 'veless-1.0.10.zip']));

    // Cofnięcie z kopii.
    $backup = basename(glob($app . '/storage/backups/update/update-*.zip')[0]);
    check(str_contains($page, '1.0.10 → 1.0.11') && str_contains($page, 'value="rollback"'), 'Rollback offered for the last update');
    $page = $follow($http('update.php', ['csrf' => $csrf($page), 'action' => 'rollback', 'file' => $backup, 'confirm' => '1']));
    check(str_contains($page, 'Działa teraz wersja 1.0.10'), 'Rollback succeeds');
    check(!is_file($app . '/src/Support/UpdateProbe.php') && is_file($app . '/cli/demo_reset.php')
        && !str_contains((string) file_get_contents($app . '/lang/pl.php'), "'Aktualizacja B'"), 'Files are back to version A (new file removed, deleted file restored)');
    check(json_decode((string) file_get_contents($app . '/config/build.json'), true)['version'] === '1.0.10'
        && json_decode((string) file_get_contents($app . '/storage/release.json'), true)['version'] === '1.0.10', 'Version and manifest back to 1.0.10');
    check(str_contains((string) file_get_contents($app . '/sql/migration_002_woo_orders.sql'), 'moja poprawka'), 'Local edit survived update and rollback');

    // Instalacja wdrażana z GitHuba (build.json bez wersji): aktualizacja z paczki wyłączona.
    file_put_contents($app . '/config/build.json', json_encode(['id' => 'run-1', 'deployed_at' => time(), 'commit' => 'x']));
    [, $page] = $http('update.php');
    check(str_contains($page, 'aktualizowana automatycznie') && !str_contains($page, 'Wgraj nową wersję'), 'Deploy-managed installation (like AWiEN) has updates disabled');
    $r = $http('update.php', ['csrf' => $csrf($page), 'action' => 'upload', 'package' => new CURLFile($pkgB, 'application/zip', 'b.zip')]);
    check(glob($app . '/storage/updates/*.zip') === [], 'Upload refused there too');

    $log = (string) file_get_contents($temp . '/server.log');
    check(!preg_match('/PHP (Warning|Notice|Deprecated|Fatal)/', $log), 'No PHP warnings in the server log');
    echo "\nAll $checks update checks passed.\n";
} finally {
    if ($proc) { @proc_terminate($proc); }
    try { $server->exec("DROP DATABASE IF EXISTS `$dbName`"); } catch (Throwable) {}
    if (getenv('KEEP_TEMP') !== '1') { $rm($temp); } else { echo "Temp kept: $temp\n"; }
}
