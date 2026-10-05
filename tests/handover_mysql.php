<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Przekazanie pracy online ⇄ komputer (Services\Handover): pakiet z bazy A wgrany do bazy B.
// Jak sales_mysql.php: tylko loopback, losowe nowe bazy, bez .env aplikacji.
if (getenv('CRM_TEST_MYSQL') !== '1') {
    fwrite(STDERR, "NOT RUN: set CRM_TEST_MYSQL=1 and dedicated local test MySQL credentials.\n");
    exit(2);
}
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    }
});
use Pase\Repository\SettingsRepository;
use Pase\Services\Handover;
use Pase\Support\Env;
use Pase\Support\Migrator;

$checks = 0;
function check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    ++$checks; echo "OK: $label\n";
}
function rejects(callable $fn, string $label): void {
    try { $fn(); } catch (RuntimeException) { check(true, $label); return; }
    check(false, $label);
}
$port = (int) (getenv('CRM_TEST_MYSQL_PORT') ?: 3306);
if ($port < 1 || $port > 65535) { throw new RuntimeException('Invalid test port'); }
$connect = static fn(): PDO => new PDO('mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4',
    getenv('CRM_TEST_MYSQL_USER') ?: 'root', getenv('CRM_TEST_MYSQL_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$a = $connect(); $b = $connect();
$dbA = 'crm_test_' . bin2hex(random_bytes(12)); $dbB = 'crm_test_' . bin2hex(random_bytes(12));
$tmp = sys_get_temp_dir() . '/crm-handover-' . bin2hex(random_bytes(6));
$rootA = $tmp . '/a'; $rootB = $tmp . '/b';
foreach ([$rootA . '/storage/uploads', $rootB . '/storage/uploads', $rootA . '/storage/security', $rootB . '/storage/security'] as $d) { mkdir($d, 0700, true); }
file_put_contents($tmp . '/.env', "APP_MODE=local\nTOTP_KEY_FILE=\n");
Env::load($tmp . '/.env');
define('PASE_ROOT', $rootB);

$a->exec("CREATE DATABASE `$dbA` CHARACTER SET utf8mb4"); $b->exec("CREATE DATABASE `$dbB` CHARACTER SET utf8mb4");
try {
    $a->exec("USE `$dbA`"); $b->exec("USE `$dbB`");
    (new Migrator($a))->migrate(); (new Migrator($b))->migrate();
    $sa = new SettingsRepository($a); $sb = new SettingsRepository($b);

    // Kod połączenia
    $online = new Handover($a, $sa, $rootA);
    $code = $online->newPairingCode('https://sklep.pl/crm/public/');
    ['url' => $url, 'token' => $token] = Handover::parseCode($code);
    check($url === 'https://sklep.pl/crm/public' && $online->verifyToken($token), 'Pairing code carries address and secret');
    check(!$online->verifyToken(str_repeat('a', 64)) && !$online->verifyToken(''), 'Wrong secret rejected');
    check(!str_contains((string) $sa->get('HANDOVER_TOKEN_HASH'), $token), 'Only a hash of the secret is stored');
    rejects(static fn() => Handover::parseCode('https://sklep.pl#abc'), 'Garbage code rejected');

    // Dane nadawcy (A) i odbiorcy (B)
    $sa->setMany(['SHOP_NAME' => 'Sklep A', 'APP_BASE_URL' => 'https://sklep.pl/crm/public', 'SYNC_ORDERS_EVERY' => '5', 'LOCAL_ORDERS_CURSOR_3' => '2020-01-01T00:00:00']);
    $sb->setMany(['SHOP_NAME' => 'stara nazwa', 'APP_BASE_URL' => 'http://localhost:8081', 'SYNC_ORDERS_EVERY' => '1', 'ONLY_ON_B' => 'x']);
    $a->exec("INSERT INTO job_queue (job_type, payload, dedup_key, max_attempts, status, available_at) VALUES ('test.job', '{}', 'test:1', 3, 'pending', UTC_TIMESTAMP())");
    file_put_contents($rootA . '/storage/uploads/faktura.pdf', '%PDF-1.4 test');
    file_put_contents($rootA . '/storage/app.log', 'log nie jedzie');
    file_put_contents($rootB . '/storage/uploads/tylko-b.txt', 'zostaje');
    // Odbiorca ma kolumnę, której nadawca nie zna (nowsza wersja CRM): dostaje domyślną wartość.
    $b->exec("ALTER TABLE job_queue ADD COLUMN extra_flag INT NOT NULL DEFAULT 7");

    $zip = $online->buildPackage($token);
    check(is_file($zip) && filesize($zip) > 0, 'Package built');
    $peek = new ZipArchive(); $peek->open($zip);
    check($peek->getFromName('package.json') === false, 'Package cannot be read without the secret');
    $peek->setPassword($token);
    $manifest = json_decode((string) $peek->getFromName('package.json'), true);
    $peek->close();
    check(isset($manifest['files']['storage/uploads/faktura.pdf']) && !isset($manifest['files']['storage/app.log']), 'Attachments go, logs stay');

    $computer = new Handover($b, $sb, $rootB);
    rejects(static fn() => $computer->importPackage($zip, str_repeat('b', 64)), 'Package with another secret refused');
    check($sb->get('SHOP_NAME') === 'stara nazwa', 'Refused package changed nothing');

    $computer->importPackage($zip, $token);
    check($sb->get('SHOP_NAME') === 'Sklep A', 'Settings come from the sender');
    check($sb->get('APP_BASE_URL') === 'http://localhost:8081' && $sb->get('SYNC_ORDERS_EVERY') === '1', 'Address and pull frequency of the receiver stay');
    check($sb->get('LOCAL_ORDERS_CURSOR_3') === null, 'Sender order cursors are dropped');
    check($sb->get('ONLY_ON_B') === null, 'Receiver-only settings are replaced with the sender set');
    $job = $b->query("SELECT job_type, extra_flag FROM job_queue WHERE dedup_key = 'test:1'")->fetch();
    check($job['job_type'] === 'test.job' && (int) $job['extra_flag'] === 7, 'Unfinished jobs move over; newer receiver columns get defaults');
    check(file_get_contents($rootB . '/storage/uploads/faktura.pdf') === '%PDF-1.4 test', 'Files copied');
    check(is_file($rootB . '/storage/uploads/tylko-b.txt'), 'Receiver files not deleted');
    check($b->query("SHOW TABLES LIKE 'hov%'")->fetchAll() === [], 'No temporary tables left');

    // Stan przekazania
    check(!$computer->isAway(), 'Receiver works after import');
    $computer->setAway('online');
    check($computer->away()['to'] === 'online', 'Away state stored');
    $computer->resumeHere();
    check(!$computer->isAway() && $computer->task() === null, 'Resume clears state');
    $online->setAway('local');
    rejects(static fn() => $online->serverStartExport(), 'Paused online does not hand out stale data');
    $online->resumeHere();
    rejects(static fn() => $online->serverStartUpload(), 'Working online refuses data from the computer');
} finally {
    $a->exec("DROP DATABASE IF EXISTS `$dbA`"); $b->exec("DROP DATABASE IF EXISTS `$dbB`");
    exec('rm -rf ' . escapeshellarg($tmp));
}
echo "\nAll {$checks} handover checks passed.\n";
