<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Przycisk „Pobierz teraz” na liście zamówień (Services\OrdersSyncNow): pobranie i od razu przetworzenie importów
// zamówień, bez czekania na cron. Jak local_mode_mysql.php: tylko loopback, losowa nowa baza, bez .env aplikacji.
if (getenv('CRM_TEST_MYSQL') !== '1') {
    fwrite(STDERR, "NOT RUN: set CRM_TEST_MYSQL=1 and dedicated local test MySQL credentials.\n");
    exit(2);
}
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    }
});
use Pase\Queue\JobRunner;
use Pase\Queue\Queue;
use Pase\Repository\SettingsRepository;
use Pase\Services\OrdersSyncNow;
use Pase\Services\ProcessControl;
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
try {
    $pdo->exec("USE `$database`");
    (new Migrator($pdo))->migrate();
    $settings = new SettingsRepository($pdo);
    $queue = new Queue($pdo);
    $status = static fn(string $key): string => (string) $pdo->query('SELECT status FROM job_queue WHERE dedup_key = ' . $pdo->quote($key))->fetchColumn();

    // Zamówienie ze sklepu czeka w kolejce (np. z webhooka) obok zadania stanów, które przycisk ma zostawić workerowi.
    $order = ['id' => 92700, 'number' => '92700', 'status' => 'processing', 'currency' => 'PLN', 'total' => '49.90',
        'date_created_gmt' => gmdate('Y-m-d\TH:i:s'), 'billing' => ['first_name' => 'Anna', 'last_name' => 'Kowalska', 'email' => 'anna@example.com'],
        'line_items' => [], '__integration_id' => 7];
    $queue->enqueue('woo.order.import', $order, 'woo.order.import:7:92700:processing');
    $queue->enqueue('woo.stock.push', ['product_id' => 1, 'what' => 'stock'], 'woo.stock.push:stock:1');

    $reserved = $queue->reserveNext(['woo.stock.push']);
    check(($reserved['job_type'] ?? '') === 'woo.stock.push', 'reserveNext with types takes only those job types (skips the older order import)');
    $pdo->exec("UPDATE job_queue SET status = 'pending', reserved_token = NULL WHERE job_type = 'woo.stock.push'");

    $control = new ProcessControl($settings);
    $control->pause('orders', 3600);
    $r = (new OrdersSyncNow($pdo, $settings, []))->run();
    check(!$r['ok'] && str_contains($r['message'], 'wstrzymane') && $status('woo.order.import:7:92700:processing') === 'pending',
        'Paused order pulls: the button explains it and imports nothing');
    $control->resume('orders');

    $settings->setMany(['SYNC_ORDERS_EVERY' => '10', 'SYNC_ORDERS_AT' => '1', 'ERLI_RUN_AT' => (string) time(), 'TIKTOKSHOP_RUN_AT' => (string) time()]);
    $r = (new OrdersSyncNow($pdo, $settings, []))->run();
    check($r['ok'] && $r['imported'] === 1 && $r['waiting'] === 0, 'Queued order is imported right away: ' . $r['message']);
    check((int) $pdo->query('SELECT COUNT(*) FROM woo_orders WHERE woo_order_id = 92700')->fetchColumn() === 1, 'Order is on the order list');
    check($status('woo.order.import:7:92700:processing') === 'done', 'Import job is marked done, so the worker will not repeat it');
    check($status('woo.stock.push:stock:1') === 'pending', 'Other jobs (stock push) are left for the worker');
    check(time() - (int) $settings->get('SYNC_ORDERS_AT') < 60, 'Scheduled pull timer moves, so cron does not pull again a minute later');
    check($settings->get('ERLI_RUN_AT') === '0' && $settings->get('TIKTOKSHOP_RUN_AT') === '0', 'ERLI and TikTok Shop are checked on the next worker run');

    $r = (new OrdersSyncNow($pdo, $settings, []))->run();
    check($r['ok'] && $r['imported'] === 0 && str_contains($r['message'], 'brak nowych'), 'Nothing new: says so');

    $runner = new JobRunner($pdo, []);
    $failed = false;
    try { $runner->handle('nieznany.typ', []); } catch (\RuntimeException $e) { $failed = str_contains($e->getMessage(), 'Nieznany typ'); }
    check($failed, 'Unknown job type is rejected as before');
} finally {
    $pdo->exec("DROP DATABASE `$database`");
}
echo "\nAll {$checks} order sync-now checks passed.\n";
