<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Zdalne aktualizacje: serwer aktualizacji (updates-server/, php -S) + zainstalowany Veless, który w przebiegu
// workera (cron) sam sprawdza, pobiera i instaluje podpisaną nową wersję. Klucz podpisu testowy (RELEASE_PUBLIC_KEYS).
// Explicit opt-in, random new database, loopback only.
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
$temp = sys_get_temp_dir() . '/veless-remote-' . bin2hex(random_bytes(8));
$proc = null;
$rm = static function (string $path) use (&$rm): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) { return; }
    foreach (scandir($path) as $e) { if ($e !== '.' && $e !== '..') { $rm($path . '/' . $e); } }
    rmdir($path);
};

try {
    $server->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4");
    mkdir($temp . '/upd/releases', 0755, true); mkdir($temp . '/upd/data', 0755, true);

    // --- Serwer aktualizacji ---
    $httpPort = 18000 + random_int(0, 1999);
    $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $httpPort, '-t', $repo . '/updates-server'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', $temp . '/server.log', 'w']],
        $pipes, null, ['PATH' => getenv('PATH') ?: '/usr/bin', 'VELESS_UPDATES_DIR' => $temp . '/upd', 'PHP_CLI_SERVER_WORKERS' => '2']);
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $httpPort) === false; ++$i) { usleep(100000); }
    // Serwer aktualizacji musi być na https w paczce - tu adres ustawiamy w panelu (UPDATE_SERVER_URL), jak może to zrobić klient.
    $updUrl = 'http://127.0.0.1:' . $httpPort . '/index.php';
    check(json_decode((string) file_get_contents($updUrl), true) === ['service' => 'veless-updates'], 'Update server answers');

    // --- Klucz testowy i paczki ---
    $kp = sodium_crypto_sign_keypair();
    $keyFile = $temp . '/sign.key';
    file_put_contents($keyFile, base64_encode(sodium_crypto_sign_secretkey($kp)));
    $pub = base64_encode(sodium_crypto_sign_publickey($kp));
    $build = static function (string $version, bool $sign = true, string $notes = '') use ($repo, $temp, $keyFile): string {
        $out = $temp . '/dist-' . $version;
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/cli/build_release.php') . ' --version=' . $version . ' --commit=test --out=' . escapeshellarg($out)
            . ($sign ? ' --key-file=' . escapeshellarg($keyFile) : '') . ($notes !== '' ? ' --notes=' . escapeshellarg($notes) : '');
        exec('env -u VELESS_SIGNING_KEY ' . $cmd . ' 2>&1', $o, $rc);
        if ($rc !== 0) { throw new RuntimeException('build failed: ' . implode("\n", $o)); }
        return $out . '/veless-' . $version . '.zip';
    };
    $pkgA = $build('1.0.20');
    $zip = new ZipArchive(); $zip->open($pkgA);
    check($zip->getFromName('package.sig') !== false && sodium_crypto_sign_verify_detached(base64_decode((string) $zip->getFromName('package.sig')), (string) $zip->getFromName('package.json'), base64_decode($pub)),
        'Release is signed and the signature verifies');

    // --- Instalacja A ---
    $app = $temp . '/app';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = $zip->getNameIndex($i);
        if (!str_starts_with($n, 'app/')) { continue; }
        @mkdir(dirname($app . '/' . substr($n, 4)), 0755, true);
        file_put_contents($app . '/' . substr($n, 4), $zip->getFromIndex($i));
    }
    @mkdir($app . '/storage/security', 0700, true);
    file_put_contents($app . '/storage/release.json', $zip->getFromName('package.json'));
    $zip->close();
    file_put_contents($app . '/storage/security/totp.key', base64_encode(random_bytes(32)));
    file_put_contents($app . '/.env', "APP_ENV=production\nAPP_MODE=server\nDB_HOST=\"127.0.0.1\"\nDB_PORT=$port\nDB_NAME=\"$dbName\"\nDB_USER=\"$user\"\nDB_PASS=\"$pass\"\nRELEASE_PUBLIC_KEYS=\"$pub\"\n");
    $php = static function (string $code) use ($app): string {
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('$c = require "' . $app . '/config/config.php"; $p = (require "' . $app . '/config/database.php")($c["db"]); ' . $code) . ' 2>&1', $o, $rc);
        if ($rc !== 0) { throw new RuntimeException('php -r failed: ' . implode("\n", $o)); }
        return implode("\n", $o);
    };
    $set = static function (array $kv) use ($php): void {
        $php('(new \Pase\Repository\SettingsRepository($p))->setMany(' . var_export($kv, true) . ');');
    };
    $get = static function (string $k) use ($php): string {
        return $php('echo (new \Pase\Repository\SettingsRepository($p))->get(' . var_export($k, true) . ', "");');
    };
    $set(['APP_BASE_URL' => 'https://sklep-testowy.example/veless/public', 'UPDATE_SERVER_URL' => $updUrl, 'UPDATE_AUTO_HOURS' => '0-24']);
    $version = static fn(): string => (string) (json_decode((string) file_get_contents($app . '/config/build.json'), true)['version'] ?? '');
    $worker = static function () use ($app): string {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($app . '/cli/worker.php') . ' 2>&1', $o, $rc);
        if ($rc !== 0) { throw new RuntimeException('worker failed: ' . implode("\n", $o)); }
        clearstatcache();
        return implode("\n", $o);
    };
    check($version() === '1.0.20', 'Installed 1.0.20');

    // Brak nowej wersji: worker pyta serwer i nic nie robi.
    $worker();
    $installs = json_decode((string) file_get_contents($temp . '/upd/data/installs.json'), true);
    $rec = $installs ? reset($installs) : [];
    check(count($installs) === 1 && $rec['version'] === '1.0.20' && $rec['site'] === 'https://sklep-testowy.example/veless/public' && $rec['php'] === PHP_VERSION,
        'Server records the installation (version, panel address, PHP)');
    check($get('UPDATE_CHECKED_AT') !== '' && $version() === '1.0.20', 'Nothing to install yet');

    // Nowa poprawka 1.0.21 na serwerze: następny dzienny przebieg sam ją instaluje.
    copy($build('1.0.21', true, 'Poprawki listy zamówień'), $temp . '/upd/releases/veless-1.0.21.zip');
    $worker();
    check($version() === '1.0.20', 'Daily check is not repeated within 24 h');
    $set(['UPDATE_CHECKED_AT' => '0']);
    $out = $worker();
    check($version() === '1.0.21' && str_contains($out, 'zainstalowano automatycznie 1.0.21'), 'Worker downloaded, verified and installed 1.0.21 by itself');
    check(json_decode($get('UPDATE_AUTO_RESULT'), true)['ok'] === true && count(glob($app . '/storage/backups/update/update-*.zip')) === 1, 'Result stored and a rollback copy kept');
    $worker();
    check(str_contains($php('echo json_encode(\Pase\Support\AppVersion::current());'), '1.0.21'), 'Next run works on the new code');

    // Paczka zmieniona po podpisaniu: odrzucona, nic nie instaluje.
    $bad = $build('1.0.22');
    $z = new ZipArchive(); $z->open($bad);
    $z->addFromString('package.json', str_replace('"notes": ""', '"notes": "podmienione"', (string) $z->getFromName('package.json')));
    $z->close();
    copy($bad, $temp . '/upd/releases/veless-1.0.22.zip');
    $set(['UPDATE_CHECKED_AT' => '0']);
    $worker();
    check($version() === '1.0.21' && str_contains($get('UPDATE_LAST_ERROR'), 'Podpis paczki się nie zgadza') && glob($app . '/storage/updates/*.zip') === [],
        'Tampered package rejected (signature), nothing installed or kept');
    unlink($temp . '/upd/releases/veless-1.0.22.zip');

    // Niepodpisana paczka: serwer jej nie ogłasza.
    copy($build('1.0.23', false), $temp . '/upd/releases/veless-1.0.23.zip');
    $check = json_decode((string) file_get_contents($updUrl . '?action=check&install=' . str_repeat('a', 32) . '&version=1.0.21'), true);
    check($check['latest']['version'] === '1.0.21', 'Unsigned package is never offered');
    unlink($temp . '/upd/releases/veless-1.0.23.zip');

    // Większa wersja 1.1.0: w trybie „same poprawki” tylko pobrana i pokazana, nie instaluje się sama.
    copy($build('1.1.0', true, 'Nowe raporty'), $temp . '/upd/releases/veless-1.1.0.zip');
    $set(['UPDATE_CHECKED_AT' => '0']);
    $worker();
    $latest = json_decode($get('UPDATE_LATEST'), true);
    check($version() === '1.0.21' && $latest['version'] === '1.1.0' && $latest['notes'] === 'Nowe raporty' && is_file($app . '/storage/updates/veless-1.1.0.zip'),
        'Minor release downloaded and announced, not auto-installed in patch mode');
    // Serwer może zablokować samoczynną instalację danej wersji.
    file_put_contents($temp . '/upd/releases/veless-1.1.0.json', '{"auto": false}');
    $set(['UPDATE_AUTO' => 'all', 'UPDATE_CHECKED_AT' => '0']);
    $worker();
    check($version() === '1.0.21', 'Server "auto": false keeps it manual even in "all" mode');
    unlink($temp . '/upd/releases/veless-1.1.0.json');
    $set(['UPDATE_CHECKED_AT' => '0']);
    $worker();
    check($version() === '1.1.0', 'Mode "all" installs 1.1.0');
    // Poza godzinami nocnymi nic się nie instaluje.
    copy($build('1.1.1'), $temp . '/upd/releases/veless-1.1.1.zip');
    $hour = (int) (new DateTimeImmutable('now', new DateTimeZone('Europe/Warsaw')))->format('G');
    $set(['UPDATE_CHECKED_AT' => '0', 'UPDATE_AUTO_HOURS' => $hour >= 12 ? '1-2' : '22-23']);
    $worker();
    check($version() === '1.1.0' && json_decode($get('UPDATE_LATEST'), true)['version'] === '1.1.1', 'Outside the night window the update waits (downloaded only)');

    // Lista instalacji dla Klubu.
    $page = (string) @file_get_contents($updUrl . '?action=installs&key=zle');
    check($page === '', 'Install list needs the admin key');
    file_put_contents($repo . '/updates-server/config.php', "<?php return ['admin_key' => 'test-key-123'];\n");
    try {
        $page = (string) file_get_contents($updUrl . '?action=installs&key=test-key-123');
    } finally { unlink($repo . '/updates-server/config.php'); }
    check(str_contains($page, 'sklep-testowy.example') && str_contains($page, '1.1.0') && str_contains($page, 'Najnowsza wersja: <strong>1.1.1'), 'Install list shows the shop, its version and the newest release');

    $log = (string) file_get_contents($temp . '/server.log');
    check(!preg_match('/PHP (Warning|Notice|Deprecated|Fatal)/', $log), 'No PHP warnings on the update server');
    echo "\nAll $checks remote update checks passed.\n";
} finally {
    if ($proc) { @proc_terminate($proc); }
    try { $server->exec("DROP DATABASE IF EXISTS `$dbName`"); } catch (Throwable) {}
    if (getenv('KEEP_TEMP') !== '1') { $rm($temp); } else { echo "Temp kept: $temp\n"; }
}
