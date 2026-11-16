<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Rozszerzenie Kontrola stanu (extensions/kontrola): sprawdzenia na bazie SQLite z atrapami Allegro, sklepu i testów połączenia,
// przebieg porcjami w tle (cron.tick), mail przy czerwonym świetle (i po ustąpieniu, bez powtarzania) oraz przyciski „Napraw”.
$root = dirname(__DIR__);
$temp = sys_get_temp_dir() . '/crm-kontrola-' . bin2hex(random_bytes(6));
define('PASE_ROOT', $temp);
foreach (['integrations', 'storage/logs', 'extensions'] as $d) { mkdir("$temp/$d", 0700, true); }
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Pase\\')) { require $root . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
    if (str_starts_with($class, 'PaseExt\\Kontrola\\')) { require $root . '/extensions/kontrola/' . substr($class, strlen('PaseExt\\Kontrola\\')) . '.php'; }
});

use PaseExt\Kontrola\Checks;
use PaseExt\Kontrola\Fixes;
use PaseExt\Kontrola\Runner;
use PaseExt\Kontrola\Store;
use Pase\Plugin\Hooks;
use Pase\Repository\SettingsRepository;

$checks = 0;
function ok(bool $condition, string $name): void { global $checks; if (!$condition) { throw new RuntimeException('FAIL: ' . $name); } ++$checks; echo "OK: {$name}\n"; }

try {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    \Pase\Support\Runtime::setPdo($pdo);
    $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE integrations (id INTEGER PRIMARY KEY, platform_name TEXT, access_token TEXT, refresh_token TEXT, expires_at TEXT, webhook_secret TEXT, meta TEXT)');
    $pdo->exec('CREATE TABLE integration_accounts (id INTEGER PRIMARY KEY, type TEXT, name TEXT, is_active INTEGER, config TEXT, webhook_secret TEXT, access_token TEXT, refresh_token TEXT, expires_at TEXT)');
    $pdo->exec("CREATE TABLE job_queue (id INTEGER PRIMARY KEY, job_type TEXT, payload TEXT, dedup_key TEXT UNIQUE, status TEXT, attempts INTEGER DEFAULT 0, max_attempts INTEGER DEFAULT 3,
        reserved_at TEXT, reserved_token TEXT, available_at TEXT, last_error TEXT, created_at TEXT, updated_at TEXT)");
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, sku TEXT, name TEXT, pase_stock INTEGER, source_integration_id INTEGER, woo_product_id INTEGER, woo_variation_id INTEGER, is_virtual INTEGER DEFAULT 0)');
    $pdo->exec('CREATE TABLE product_mappings (id INTEGER PRIMARY KEY, sku TEXT, allegro_offer_id TEXT)');
    $pdo->exec("CREATE TABLE woo_orders (id INTEGER PRIMARY KEY, woo_order_id INTEGER UNIQUE, integration_id INTEGER, pase_number INTEGER, order_number TEXT, status TEXT,
        pase_status TEXT, total REAL, customer_name TEXT, date_created TEXT, lifecycle TEXT DEFAULT 'active', payload TEXT, local_items TEXT, local_invoice TEXT, no_shipping INTEGER,
        paid_amount REAL, paid_amount_total REAL)");
    $pdo->exec('CREATE TABLE shipments (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, courier_code TEXT, waybill_no TEXT, status TEXT, created_at TEXT,
        tracking_status TEXT, tracking_code TEXT, tracking_checked_at TEXT)');
    $pdo->exec("CREATE TABLE order_documents (id INTEGER PRIMARY KEY, woo_order_id INTEGER, integration_id INTEGER, provider TEXT, document_type TEXT, remote_id TEXT, status TEXT DEFAULT 'issued')");

    $now = strtotime('2026-11-16 15:00:00 UTC');
    $ago = static fn(int $sec): string => gmdate('Y-m-d H:i:s', $now - $sec);
    $settings = new SettingsRepository($pdo);
    $settings->setMany(['WORKER_LAST_RUN_AT' => (string) ($now - 40), 'SYNC_ORDERS_EVERY' => '5', 'SYNC_ORDERS_AT' => (string) ($now - 120),
        'SYNC_TRACKING_EVERY' => '60', 'SYNC_TRACKING_AT' => (string) ($now - 5 * 3600), 'TOKENS_REFRESH_AT' => (string) ($now - 200), 'OPS_ALERT_EMAIL' => 'szef@example.com']);

    $pdo->exec("INSERT INTO integration_accounts (id, type, name, is_active, config) VALUES (1, 'woocommerce', 'Sklep Świece', 1, '{}'), (2, 'inpost', 'InPost', 1, '{}'),
        (3, 'wfirma', 'wFirma', 1, '{}'), (4, 'erli', 'ERLI', 0, '{}'), (5, 'allegro', 'Allegro', 1, '{}')");
    $pdo->prepare("INSERT INTO integrations (platform_name, access_token, refresh_token, expires_at) VALUES ('allegro', 'tok', 'ref', ?)")->execute([$ago(-6 * 3600)]);

    $pdo->exec("INSERT INTO products (id, sku, name, pase_stock, source_integration_id, woo_product_id, woo_variation_id) VALUES
        (1, 'GB-1L', 'Gingerbread 1l', 5, 1, 101, NULL), (2, 'MW-1L', 'Mulled wine 1l', 3, 1, 102, NULL), (3, 'SW-S', 'Świeca S', 7, 1, 200, 201),
        (4, 'SW-M', 'Świeca M', 2, 1, 200, 202), (5, 'NO-SHOP', 'Bez sklepu', 9, NULL, NULL, NULL)");
    $pdo->exec("INSERT INTO product_mappings (sku, allegro_offer_id) VALUES ('MW-1L', '9002')");

    // Zamówienia: 11 ma pozycję spoza magazynu, 12 wysłane bez przesyłki, 13 opłacone bez dokumentu, 14 wszystko w porządku,
    // 15 wysłane, ale odbiór osobisty, 16 anulowane (pomijane), 17 Allegro z produktem po ID w CRM.
    $pl = static fn(array $items, array $extra = []): string => json_encode(array_replace(['status' => 'processing', 'date_paid' => '2026-11-10T10:00:00', 'total' => '50.00',
        'line_items' => $items, 'shipping_lines' => [['method_id' => 'flat_rate:1', 'method_title' => 'Kurier']]], $extra));
    $ins = $pdo->prepare('INSERT INTO woo_orders (woo_order_id, integration_id, pase_number, order_number, status, pase_status, total, customer_name, date_created, payload) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $ins->execute([11, 1, 511, '11', 'processing', 'processing', 50, 'Anna Kowalska', $ago(86400), $pl([['product_id' => 101, 'sku' => 'GB-1L', 'name' => 'Gingerbread', 'quantity' => 1],
        ['product_id' => 999, 'sku' => 'XMAS-TREE', 'name' => 'Choinka zapachowa', 'quantity' => 1]])]);
    $ins->execute([12, 1, 512, '12', 'completed', 'shipped', 50, 'Jan Nowak', $ago(3 * 86400), $pl([['product_id' => 101, 'sku' => 'GB-1L', 'name' => 'Gingerbread', 'quantity' => 1]])]);
    $ins->execute([13, 1, 513, '13', 'processing', 'processing', 50, 'Ola Lis', $ago(4 * 86400), $pl([['product_id' => 102, 'sku' => 'MW-1L', 'name' => 'Mulled', 'quantity' => 1]])]);
    $ins->execute([14, 1, 514, '14', 'completed', 'shipped', 50, 'Ewa Kot', $ago(4 * 86400), $pl([['product_id' => 102, 'sku' => 'MW-1L', 'name' => 'Mulled', 'quantity' => 1]])]);
    $ins->execute([15, 1, 515, '15', 'completed', 'shipped', 50, 'Piotr Odbiór', $ago(2 * 86400), $pl([['product_id' => 101, 'sku' => 'GB-1L', 'name' => 'G', 'quantity' => 1]],
        ['shipping_lines' => [['method_id' => 'local_pickup:2', 'method_title' => 'Odbiór osobisty']]])]);
    $ins->execute([16, 1, 516, '16', 'cancelled', 'cancelled', 50, 'Anulowany', $ago(2 * 86400), $pl([['product_id' => 9, 'sku' => 'NIE-MA', 'name' => 'X', 'quantity' => 1]])]);
    $ins->execute([17, 5, 517, 'abc', 'READY_FOR_PROCESSING', 'processing', 50, 'Kupujący Allegro', $ago(3600), $pl([['product_id' => 5, 'sku' => '', 'name' => 'Bez sklepu', 'quantity' => 1]])]);
    $pdo->exec("INSERT INTO order_documents (woo_order_id, integration_id, provider, document_type, remote_id) VALUES (14, 3, 'wfirma', 'receipt', 'R1'), (12, 3, 'wfirma', 'normal', 'F1'), (11, 3, 'wfirma', 'receipt', 'R2')");
    $pdo->prepare("INSERT INTO shipments (woo_order_id, courier_code, waybill_no, status, created_at, tracking_status) VALUES (14, 'inpost', '6800001', 'created', ?, 'delivered'),
        (13, 'dpd', '1000123', 'created', ?, NULL), (11, 'inpost', '6800002', 'created', ?, NULL)")->execute([$ago(4 * 86400), $ago(3 * 86400), $ago(3600)]);

    // Kolejka: 2 z błędem (świeże), 1 stary z błędem (pomijany), 1 utknięte, 1 czekające na wysłanie stanu produktu 4.
    $q = $pdo->prepare('INSERT INTO job_queue (job_type, payload, dedup_key, status, attempts, reserved_at, available_at, last_error, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $q->execute(['woo.order.import', '{}', 'a', 'failed', 3, null, $ago(7200), 'HTTP 500 od sklepu', $ago(7200), $ago(3600)]);
    $q->execute(['woo.order.import', '{}', 'b', 'failed', 3, null, $ago(7200), 'HTTP 502', $ago(7200), $ago(1800)]);
    $q->execute(['allegro.order.new', '{}', 'c', 'failed', 3, null, $ago(30 * 86400), 'stary', $ago(30 * 86400), $ago(20 * 86400)]);
    $q->execute(['woo.product.import', '{}', 'd', 'reserved', 1, $ago(3 * 3600), $ago(3 * 3600), null, $ago(3 * 3600), $ago(3 * 3600)]);
    $q->execute(['woo.stock.push', '{"product_id":4,"what":"stock"}', 'e', 'pending', 0, null, $ago(60), null, $ago(60), $ago(60)]);

    // Atrapy: Allegro (oferty ze stanem), sklep (produkty i warianty), test połączeń kont.
    $allegroOk = true;
    $allegro = new class($allegroOk) {
        public function __construct(public bool &$ok) {}
        public array $offers = [
            ['id' => '9001', 'external' => ['id' => 'GB-1L'], 'stock' => ['available' => 5]],
            ['id' => '9002', 'external' => ['id' => 'inna-sygnatura'], 'stock' => ['available' => 1]],   // MW-1L z Powiązań: 3 w CRM
            ['id' => '9003', 'external' => ['id' => 'SW-M'], 'stock' => ['available' => 9]],             // stan czeka w kolejce - pomijane
            ['id' => '9004', 'external' => ['id' => 'OBCY'], 'stock' => ['available' => 4]],
        ];
        public function connected(): bool { return true; }
        public function checkConnection(): array { return $this->ok ? [true, 'Połączono z Allegro (konto: swiece_pl).'] : [false, 'Token wygasł lub jest nieprawidłowy — połącz konto ponownie.']; }
        public function listOffers(array $f): array { return ['ok' => true, 'offers' => $f['offset'] === 0 ? $this->offers : [], 'total' => count($this->offers), 'message' => '']; }
    };
    $woo = new class {
        public array $calls = [];
        public function fetchProductsByIds(array $ids): array { $this->calls[] = ['ids', $ids];
            return [['id' => 101, 'manage_stock' => true, 'stock_quantity' => 4], ['id' => 102, 'manage_stock' => true, 'stock_quantity' => 3]]; }
        public function fetchVariations(int $pid, int $page = 1, int $per = 100): array { $this->calls[] = ['var', $pid];
            return ['variations' => [['id' => 201, 'manage_stock' => true, 'stock_quantity' => 7], ['id' => 202, 'manage_stock' => true, 'stock_quantity' => 0]]]; }
    };
    $tested = [];
    $tester = static function (array $acc) use (&$tested): array {
        $tested[] = $acc['type'];
        return $acc['type'] === 'inpost' ? ['ok' => false, 'message' => 'InPost odrzucił token (401).'] : ['ok' => true, 'message' => 'Połączenie OK.'];
    };
    $make = static fn(array $cfg = []): Checks => new Checks($pdo, $cfg, static fn() => $allegro, static fn(array $a) => $woo, $tester, $now);

    // ---- Definicje
    Hooks::addFilter('health.checks', static fn(array $c): array => $c + ['conn.zewn' => ['group' => 'conn', 'title' => 'Usługa X', 'run' => static fn(): array => ['state' => 'ok', 'summary' => 'Działa.']],
        'zly id!' => ['group' => 'conn', 'title' => 'Zły', 'run' => static fn() => []], 'conn.bez_grupy' => ['group' => 'inna', 'title' => 'Zły', 'run' => static fn() => []]]);
    $c = $make();
    $defs = $c->definitions();
    $ids = array_keys($defs);
    ok(isset($defs['conn.allegro'], $defs['conn.acc.1'], $defs['conn.acc.2'], $defs['conn.acc.3'], $defs['data.stock_woo.1'], $defs['conn.zewn'])
        && !isset($defs['conn.acc.4']) && !isset($defs['conn.acc.5']) && !isset($defs['zly id!']) && !isset($defs['conn.bez_grupy']),
        'Checks: one per active account (Allegro separately), stock per shop, valid extension checks only');
    ok(array_search('conn.zewn', $ids, true) < array_search('tasks.worker', $ids, true) && $ids[count($ids) - 1] === 'ship.stale', 'Checks are ordered by group');
    ok($defs['conn.acc.1']['title'] === 'Sklep Świece', 'Account title falls back to its name when the plugin is not installed');
    $run = static fn(string $id) => $c->run($id, $defs[$id]);

    // ---- Połączenia
    $r = $run('conn.allegro');
    ok($r['state'] === 'ok' && str_contains($r['summary'], 'swiece_pl') && str_contains($r['summary'], 'ważny do'), 'Allegro connection ok with token expiry');
    $allegroOk = false;
    ok($run('conn.allegro')['state'] === 'bad', 'Allegro connection failing is red');
    $allegroOk = true;
    $pdo->prepare("UPDATE integrations SET expires_at = ?")->execute([$ago(7200)]);
    $r = $run('conn.allegro');
    ok($r['state'] === 'bad' && str_contains($r['summary'], 'wygasł 2 godziny temu') && $r['link']['href'] === 'allegro_connect.php', 'Expired Allegro token is red with the connect link');
    $pdo->prepare("UPDATE integrations SET expires_at = ?")->execute([$ago(-6 * 3600)]);
    $r = $run('conn.acc.2');
    ok($r['state'] === 'bad' && $r['summary'] === 'InPost odrzucił token (401).' && $r['link']['href'] === 'integration_edit.php?id=2', 'Failed account test is red with its message');
    ok($run('conn.acc.1')['state'] === 'ok', 'Working account is green');
    ok($run('conn.zewn')['state'] === 'ok', 'Extension check runs');

    // ---- Zadania w tle
    ok($run('tasks.worker')['state'] === 'ok', 'Worker heartbeat fresh');
    $settings->setMany(['WORKER_LAST_RUN_AT' => (string) ($now - 1500)]);
    $r = $run('tasks.worker');
    ok($r['state'] === 'bad' && str_contains($r['summary'], '25 min temu'), 'Worker not running is red');
    $settings->setMany(['WORKER_LAST_RUN_AT' => (string) ($now - 40)]);
    $r = $run('tasks.flows');
    $names = array_column($r['items'], 'state', 'text');
    ok($r['state'] === 'warn' && $names['Śledzenie przesyłek'] === 'warn' && $names['Pobieranie zamówień'] === 'ok'
        && in_array('wyłączone w ustawieniach', array_column($r['items'], 'note'), true), 'Late tracking flow is yellow, disabled flows are listed');
    $settings->setMany(['SYNC_TRACKING_AT' => (string) ($now - 9 * 3600)]);
    ok($run('tasks.flows')['state'] === 'bad', 'Tracking late for many hours is red');
    $settings->setMany(['SYNC_TRACKING_AT' => (string) ($now - 600)]);
    ok($run('tasks.flows')['state'] === 'ok', 'Flows on time are green');
    $settings->setMany(['PAUSE_TRACKING' => (string) \Pase\Services\ProcessControl::INDEFINITE]);
    $r = $run('tasks.paused');
    ok($r['state'] === 'warn' && $r['items'][0]['text'] === 'Śledzenie przesyłek' && $r['items'][0]['note'] === 'do odwołania', 'Paused process is listed');
    $settings->setMany(['PAUSE_TRACKING' => '']);
    $r = $run('tasks.queue_failed');
    ok($r['state'] === 'warn' && $r['count'] === 2 && str_contains($r['summary'], '2 zadania zakończyły się błędem')
        && str_contains($r['items'][0]['text'], 'Import zamówienia ze sklepu: 2') && str_contains($r['items'][0]['note'], 'HTTP 502') && $r['fix']['id'] === 'queue.retry',
        'Failed jobs from the last 7 days are counted by type, old ones skipped');
    $r = $run('tasks.queue_stuck');
    ok($r['state'] === 'bad' && $r['fix']['id'] === 'queue.unstick' && str_contains($r['items'][0]['text'], '1 zadanie utknęło'), 'Stuck reserved job is red');

    // ---- Stany
    $r = $run('data.stock_allegro');
    ok($r['state'] === 'ok' && count($r['items']) === 1 && str_contains($r['items'][0]['note'], 'nowa, potwierdzę') && str_contains($r['summary'], '1 nowa różnica czeka na potwierdzenie'),
        'First Allegro mismatch is only noted until confirmed');
    $rows = (new Store($pdo))->stock('allegro');
    ok(count($rows) === 1 && (int) $rows[0]['product_id'] === 2 && $rows[0]['ref'] === '9002' && (int) $rows[0]['crm'] === 3 && (int) $rows[0]['remote'] === 1,
        'Mismatch uses the offer from Powiązania; products with a stock push waiting are skipped');
    $c->setNow($now + 3600);
    $r = $run('data.stock_allegro');
    ok($r['state'] === 'warn' && $r['count'] === 1 && str_contains($r['summary'], '1 produkt ma na Allegro inny stan niż w CRM') && $r['fix']['id'] === 'stock.push:allegro'
        && str_contains($r['items'][0]['note'], 'CRM 3 szt., na Allegro 1 szt.'), 'Mismatch seen again is yellow with the push button');
    $c->setNow($now);
    $r = $run('data.stock_woo.1');
    ok(count($woo->calls) === 2 && $woo->calls[0] === ['ids', [101, 102]] && $woo->calls[1] === ['var', 200], 'Shop: simple products in one call, variations per parent');
    $wrows = (new Store($pdo))->stock('woo:1');
    ok(count($wrows) === 1 && (int) $wrows[0]['product_id'] === 1 && (int) $wrows[0]['remote'] === 4 && str_contains($r['summary'], 'sprawdzono 4 z 4'),
        'Shop mismatch found (variation with a waiting push skipped)');
    ok($settings->get('EXT_KONTROLA_WOO_CURSOR_1') === '4', 'Shop cursor moves on');

    // ---- Zamówienia
    $r = $run('data.orders_no_product');
    ok($r['state'] === 'warn' && $r['count'] === 1 && $r['items'][0]['text'] === '#511 Anna Kowalska' && str_contains($r['items'][0]['note'], 'XMAS-TREE „Choinka zapachowa”')
        && $r['link']['href'] === 'index.php?ids=11' && str_contains($r['summary'], '1 zamówienie ma pozycję spoza magazynu'),
        'Order with an item outside the warehouse (cancelled skipped, Allegro matched by CRM id)');
    $r = $run('data.shipped_no_tracking');
    ok($r['state'] === 'warn' && $r['count'] === 1 && $r['items'][0]['href'] === 'order_view.php?id=12', 'Shipped order without a waybill (pickup skipped)');
    $r = $run('data.paid_no_document');
    ok($r['state'] === 'warn' && array_column($r['items'], 'id') === [15, 13] && str_contains($r['items'][1]['note'], 'paragon') && str_contains($r['summary'], '2 opłacone zamówienia nie mają paragonu ani faktury od ponad 2 dni'),
        'Paid orders older than 2 days without a document (fresh order skipped)');
    $pdo->exec("UPDATE integration_accounts SET is_active = 0 WHERE type = 'wfirma'");
    ok($make()->run('data.paid_no_document', $make()->definitions()['data.paid_no_document'])['state'] === 'skip', 'Without wFirma or KSeF the document check does not apply');
    $pdo->exec("UPDATE integration_accounts SET is_active = 1 WHERE type = 'wfirma'");

    // ---- Przesyłki
    $r = $run('ship.stale');
    ok($r['state'] === 'warn' && $r['count'] === 1 && $r['items'][0]['text'] === '1000123 (dpd)' && str_contains($r['items'][0]['note'], '#513') && $r['fix']['id'] === 'tracking.refresh',
        'Shipment without status for over 2 days (fresh one skipped)');
    $c5 = $make(['stale_days' => '5']);
    ok($c5->run('ship.stale', $c5->definitions()['ship.stale'])['state'] === 'ok', 'Threshold from settings');

    // ---- Wyjątek w sprawdzeniu
    $r = $c->run('x.y', ['group' => 'conn', 'title' => 'Psuje', 'run' => static fn() => throw new RuntimeException('awaria API')]);
    ok($r['state'] === 'warn' && str_contains($r['summary'], 'awaria API'), 'Exception in a check is a yellow light, not a crash');

    // ---- Przebieg w tle porcjami + mail
    $mails = [];
    $mail = static function (string $to, string $subject, string $html) use (&$mails): bool { $mails[] = [$to, $subject, $html]; return true; };
    $settings->setMany(['APP_BASE_URL' => 'https://crm.example.com']);
    $runner = new Runner($make(), new Store($pdo), $settings, ['every' => '60'], $mail);
    ok($runner->tick($now) !== null, 'First tick starts a run');
    $st = $runner->state();
    ok($st['pending'] === [] && $st['finished'] === $now && $st['by'] === 'cron', 'Fast checks finish in one tick');
    ok(count($mails) === 1 && $mails[0][0] === 'szef@example.com' && str_contains($mails[0][1], 'problem') && str_contains($mails[0][2], 'InPost odrzucił token')
        && str_contains($mails[0][2], 'https://crm.example.com/admin/ext.php?page=kontrola.stan'), 'Red light sends one mail to the alert address with a link');
    ok($runner->tick($now + 60) === null, 'No new run before the interval');
    $runner->runAll($now + 1800, 'Adam');
    ok(count($mails) === 1, 'Same red set does not mail again');
    $tester = static fn(array $acc): array => ['ok' => true, 'message' => 'OK'];
    $pdo->exec("UPDATE job_queue SET status = 'done' WHERE status = 'reserved'");
    $settings->setMany(['WORKER_LAST_RUN_AT' => (string) ($now + 5380), 'SYNC_ORDERS_AT' => (string) ($now + 5300), 'SYNC_TRACKING_AT' => (string) ($now + 5300), 'TOKENS_REFRESH_AT' => (string) ($now + 5300)]);
    $runner = new Runner(new Checks($pdo, [], static fn() => $allegro, static fn(array $a) => $woo, $tester, $now + 5400), new Store($pdo), $settings, ['every' => '60'], $mail);
    ok($runner->tick($now + 3700) === null, 'Interval counts from the last start (manual run too)');
    ok($runner->tick($now + 1800 + 3600) !== null, 'Next background run after the interval');
    $still = array_keys(array_filter((new Store($pdo))->results(), static fn(array $r): bool => $r['state'] === 'bad'));
    ok($still === [] && count($mails) === 2 && str_contains($mails[1][1], 'wszystko znów działa'), 'Resolved problems send an all-clear mail');
    $results = $runner->results($now + 5400);
    ok($results['conn.acc.2']['state'] === 'ok' && isset($results['tasks.worker']['live']), 'Results page data: stored and live checks');

    // ---- Naprawy
    // Kolejka jak Pase\Queue\Queue, ale bez INSERT IGNORE (MySQL).
    $fakeQueue = new class($pdo) { public function __construct(private PDO $pdo) {}
        public function enqueue(string $type, array $payload, ?string $dedup = null): bool {
            $this->pdo->prepare("INSERT OR IGNORE INTO job_queue (job_type, payload, dedup_key, status) VALUES (?, ?, ?, 'pending')")->execute([$type, json_encode($payload), $dedup]);
            return true; } };
    $fx = new Fixes($pdo, static fn() => new class { public function checkOne(int $id): array { return ['ok' => true, 'status' => $id === 2 ? 'in_transit' : null]; } }, $fakeQueue);
    $msg = $fx->run('queue.retry', $now);
    ok(str_contains($msg, 'Ponowiono 2 zadania') && (int) $pdo->query("SELECT COUNT(*) FROM job_queue WHERE status = 'failed'")->fetchColumn() === 1, 'Retry resets recent failed jobs only');
    $pdo->prepare("UPDATE job_queue SET status = 'reserved', reserved_at = ? WHERE dedup_key = 'd'")->execute([$ago(3600)]);
    ok(str_contains($fx->run('queue.unstick', $now), 'Odblokowano 1 zadanie'), 'Unstick returns stuck jobs to pending');
    $msg = $fx->run('stock.push:allegro', $now);
    $job = $pdo->query("SELECT * FROM job_queue WHERE dedup_key LIKE 'woo.stock.push:2:kontrola:%'")->fetch();
    ok(str_contains($msg, 'dla 1 produktu') && $job && json_decode($job['payload'], true) === ['product_id' => 2, 'what' => 'stock']
        && (new Store($pdo))->stock('allegro')[0]['pushed_at'] !== null, 'Stock push queues the CRM stock and marks the mismatch');
    $msg = $fx->run('tracking.refresh', $now);
    ok(str_contains($msg, 'Sprawdzono 1 paczkę'), 'Tracking refresh checks stale shipments');
    $thrown = false;
    try { $fx->run('rm -rf', $now); } catch (RuntimeException) { $thrown = true; }
    ok($thrown, 'Unknown fix is refused');

    echo "\nAll {$checks} checks passed.\n";
} finally {
    exec('rm -rf ' . escapeshellarg($temp));
}
