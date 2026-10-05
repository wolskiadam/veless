<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Tryb lokalny (APP_MODE=local, Docker): odpytywanie Woo po dacie modyfikacji zamiast webhooka
// i ustawienia startowe. Jak sales_mysql.php: tylko loopback, losowa nowa baza, bez .env aplikacji.
if (getenv('CRM_TEST_MYSQL') !== '1') {
    fwrite(STDERR, "NOT RUN: set CRM_TEST_MYSQL=1 and dedicated local test MySQL credentials.\n");
    exit(2);
}
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    }
});
use Pase\Queue\Queue;
use Pase\Repository\SettingsRepository;
use Pase\Services\Scheduler;
use Pase\Support\AppMode;
use Pase\Support\Env;
use Pase\Support\Migrator;

$checks = 0;
function check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    ++$checks; echo "OK: $label\n";
}
$port = (int) (getenv('CRM_TEST_MYSQL_PORT') ?: 3306);
if ($port < 1 || $port > 65535) { throw new RuntimeException('Invalid test port'); }
$pdo = new PDO('mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4',
    getenv('CRM_TEST_MYSQL_USER') ?: 'root', getenv('CRM_TEST_MYSQL_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$database = 'crm_test_' . bin2hex(random_bytes(12));
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
$envFile = tempnam(sys_get_temp_dir(), 'crm-env');
try {
    $pdo->exec("USE `$database`");
    (new Migrator($pdo))->migrate();
    $settings = new SettingsRepository($pdo);

    check(!AppMode::isLocal(), 'Without APP_MODE the installation works as before (server)');
    AppMode::applyLocalDefaults($settings);
    check($settings->get('SYNC_ORDERS_EVERY') === null, 'Server mode does not touch settings');

    file_put_contents($envFile, "APP_MODE=local\nAPP_URL=http://localhost:9090/\n");
    Env::load($envFile);
    check(AppMode::isLocal(), 'APP_MODE=local switches to local mode');

    $settings->setMany(['APP_BASE_URL' => 'http://crm.lan:8081']);
    AppMode::applyLocalDefaults($settings);
    check($settings->get('SYNC_ORDERS_EVERY') === '1', 'Local mode pulls orders every minute by default');
    check($settings->get('APP_BASE_URL') === 'http://crm.lan:8081', 'Address set in the panel is kept');
    $settings->setMany(['SYNC_ORDERS_EVERY' => '0']);
    AppMode::applyLocalDefaults($settings);
    check($settings->get('SYNC_ORDERS_EVERY') === '0', 'Turning order pulls off in the panel survives the next worker run');

    // Sklep w pamięci: zwraca zamówienia zmienione po kursorze, rosnąco po dacie modyfikacji.
    $shop = new class {
        /** @var list<array<string,mixed>> */
        public array $orders = [];
        /** @var list<string> */
        public array $asked = [];
        public function fetchOrdersModifiedSince(int $page, int $perPage, string $sinceGmt): array
        {
            $this->asked[] = $sinceGmt;
            $match = array_values(array_filter($this->orders, static fn(array $o): bool => $o['date_modified_gmt'] > $sinceGmt));
            usort($match, static fn(array $a, array $b): int => strcmp($a['date_modified_gmt'], $b['date_modified_gmt']));
            return array_slice($match, ($page - 1) * $perPage, $perPage);
        }
    };
    $scheduler = new Scheduler($pdo, $settings, new Queue($pdo));
    $pull = (new ReflectionMethod(Scheduler::class, 'pullModifiedOrders'))->getClosure($scheduler);
    $acc = ['id' => 7, 'type' => 'woocommerce'];
    $jobs = static fn(): array => $pdo->query("SELECT dedup_key FROM job_queue WHERE job_type = 'woo.order.import' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

    $recent = gmdate('Y-m-d\TH:i:s', time() - 3600);
    $shop->orders = [
        ['id' => 101, 'status' => 'pending', 'date_modified_gmt' => $recent],
        ['id' => 100, 'status' => 'completed', 'date_modified_gmt' => gmdate('Y-m-d\TH:i:s', time() - 3 * 86400)],
    ];
    check($pull($shop, $acc) === 1, 'First run takes the last day only');
    check($jobs() === ['woo.order.import:7:101:pending'], 'Order queued with the same dedup key as the webhook');
    check($settings->get('LOCAL_ORDERS_CURSOR_7') === gmdate('Y-m-d\TH:i:s', strtotime($recent . ' UTC') - 1),
        'Cursor moves to the newest change minus one second');

    check($pull($shop, $acc) === 0, 'Re-reading the boundary order does not queue it twice');

    $paid = gmdate('Y-m-d\TH:i:s', time() - 60);
    $shop->orders[0] = ['id' => 101, 'status' => 'processing', 'date_modified_gmt' => $paid];
    check($pull($shop, $acc) === 1 && end($jobs()) === 'woo.order.import:7:101:processing',
        'Status change in the shop (paid) reaches the CRM without a webhook');

    $shop->orders = [];
    for ($i = 1; $i <= 260; $i++) {
        $shop->orders[] = ['id' => 1000 + $i, 'status' => 'pending', 'date_modified_gmt' => gmdate('Y-m-d\TH:i:s', strtotime($paid . ' UTC') + $i)];
    }
    check($pull($shop, $acc) === 250, 'One run takes at most 5 pages of 50');
    check($pull($shop, $acc) === 10, 'Next run continues where the previous one stopped');
} finally {
    @unlink($envFile);
    $pdo->exec("DROP DATABASE `$database`");
}
echo "\nAll {$checks} local mode checks passed.\n";
