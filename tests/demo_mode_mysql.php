<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Wersja demonstracyjna (DEMO_MODE=1): blokady stron i plików, brak połączeń na zewnątrz,
// fikcyjne dane DemoData. Jak local_mode_mysql.php: tylko loopback, losowa nowa baza.
if (getenv('CRM_TEST_MYSQL') !== '1') {
    fwrite(STDERR, "NOT RUN: set CRM_TEST_MYSQL=1 and dedicated local test MySQL credentials.\n");
    exit(2);
}
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    }
});
use Pase\Services\DemoData;
use Pase\Support\Demo;
use Pase\Support\Env;
use Pase\Support\Http;
use Pase\Support\Migrator;

$checks = 0;
function check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    ++$checks; echo "OK: $label\n";
}

check(!Demo::on(), 'Without DEMO_MODE the installation is not a demo');
check(!Demo::blocks('/x/public/admin/backup.php', 'GET', true), 'Without DEMO_MODE nothing is blocked');
check(Demo::bannerHtml() === '', 'Without DEMO_MODE there is no banner');

$envFile = tempnam(sys_get_temp_dir(), 'crm-env');
file_put_contents($envFile, "DEMO_MODE=1\n");
Env::load($envFile);
unlink($envFile);
check(Demo::on(), 'DEMO_MODE=1 switches the demo on');
check(Demo::blocks('/x/public/admin/backup.php', 'GET', false), 'Backups are blocked in demo');
check(Demo::blocks('/x/public/admin/forgot_password.php', 'GET', false), 'Password reset is blocked in demo');
check(Demo::blocks('/x/public/admin/users.php', 'POST', false), 'Changing users is blocked in demo');
check(!Demo::blocks('/x/public/admin/users.php', 'GET', false), 'Users page can still be viewed');
check(Demo::blocks('/x/public/admin/plugins.php', 'POST', false), 'Installing plugins is blocked in demo');
check(Demo::blocks('/x/public/admin/products.php', 'POST', true), 'Any uploaded file is blocked in demo');
check(Demo::blocks('/x/public/order.php', 'POST', true), 'Customer page uploads are blocked in demo');
check(!Demo::blocks('/x/public/admin/products.php', 'POST', false), 'Editing demo data is allowed');
check(str_contains(Demo::bannerHtml(), 'demonstracyjna'), 'Demo banner is shown');
$r = Http::request('GET', 'https://example.com/');
check($r->status === 503 && str_contains($r->body, 'demo'), 'No outgoing HTTP in demo');
check(\Pase\Services\CompanyLookup::curl('GET', 'https://example.com/', [], null)[0] === 0, 'Company lookup does not go out in demo');

$port = (int) (getenv('CRM_TEST_MYSQL_PORT') ?: 3306);
if ($port < 1 || $port > 65535) { throw new RuntimeException('Invalid test port'); }
$pdo = new PDO('mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4',
    getenv('CRM_TEST_MYSQL_USER') ?: 'root', getenv('CRM_TEST_MYSQL_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$database = 'crm_test_' . bin2hex(random_bytes(12));
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
$imgDir = sys_get_temp_dir() . '/crm-demo-img-' . bin2hex(random_bytes(6));
try {
    $pdo->exec("USE `$database`");
    (new Migrator($pdo))->migrate();
    DemoData::seed($pdo, $imgDir);
    check((int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === 12, 'Demo has 12 products');
    check((int) $pdo->query('SELECT COUNT(*) FROM woo_orders')->fetchColumn() === 140, 'Demo has 140 orders');
    check((int) $pdo->query('SELECT COUNT(*) FROM automation_rules')->fetchColumn() === 5, 'Demo has 5 automations');
    check(count(glob($imgDir . '/*.png') ?: []) === 12, 'Product graphics are generated locally');
    $images = (string) $pdo->query("SELECT images FROM products WHERE sku = 'SW-LAW-180'")->fetchColumn();
    check(json_decode($images, true) === ['/demo-img/sw-law-180.png'], 'Product images point at the demo site itself');
    $newest = (string) $pdo->query('SELECT MAX(date_created) FROM woo_orders')->fetchColumn();
    check(strtotime($newest . ' UTC') > time() - 86400, 'Newest demo order is from the last day');
    $emails = $pdo->query("SELECT COUNT(*) FROM woo_orders WHERE customer_email NOT LIKE '%@example.com' AND customer_email NOT LIKE '%@allegromail.pl'")->fetchColumn();
    check((int) $emails === 0, 'Demo customers use example addresses only');
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS `$database`");
    foreach (glob($imgDir . '/*') ?: [] as $f) { unlink($f); }
    @rmdir($imgDir);
}
echo "demo_mode_mysql: {$checks} checks passed\n";
