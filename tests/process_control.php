<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Wstrzymywanie pracy w tle i odczyt miejsca na koncie hostingu (DirectAdmin) - SQLite, bez sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Repository\SettingsRepository;
use Pase\Services\HostingQuota;
use Pase\Services\ProcessControl;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
$settings = new SettingsRepository($pdo);
$pc = new ProcessControl($settings);

// --- Wstrzymywanie ---
check($pc->activePauses() === [] && !$pc->isPaused('orders'), 'Nothing paused by default');
$until = $pc->pause('orders', 3600);
check($pc->isPaused('orders') && abs($until - (time() + 3600)) < 5, 'Pause for 1 hour');
check(!$pc->isPaused('orders', time() + 3601), 'Pause expires by itself');
$pc->pause('worker', null);
check($pc->pausedUntil('worker') === ProcessControl::INDEFINITE, 'Pause until further notice');
check(array_keys($pc->activePauses()) === ['worker', 'orders'], 'Active pauses listed');
$pc->resume('orders');
check(!$pc->isPaused('orders') && $pc->isPaused('worker'), 'Resume only the selected process');
$settings->setMany(['PAUSE_QUEUE' => 'junk']);
check(!$pc->isPaused('queue'), 'Corrupt value means running');
try { $pc->pause('evil', 60); check(false, 'Unknown process rejected'); } catch (InvalidArgumentException) { check(true, 'Unknown process rejected'); }
check($pc->batchSize(50) === 50, 'Default batch size from config');
$pc->setBatchSize(10);
check($pc->batchSize(50) === 10, 'Batch limit from panel');
$pc->setBatchSize(999);
check($pc->batchSize(50) === 50, 'Invalid batch limit ignored');

// Kopie ustawień nie przenoszą wstrzymań (to stan pracy, nie konfiguracja).
$re = (new ReflectionClassConstant(Pase\Services\SectionBackup::class, 'RUNTIME_SETTINGS'))->getValue();
check((bool) preg_match($re, 'PAUSE_WORKER') && !preg_match($re, 'WORKER_BATCH_LIMIT'), 'Pauses excluded from settings backup');

// Wszystkie przepływy Schedulera da się wstrzymać.
$flows = array_keys((new ReflectionClassConstant(Pase\Services\Scheduler::class, 'FLOWS'))->getValue());
check(array_diff($flows, array_keys(ProcessControl::PROCESSES)) === [], 'Every scheduler flow has a pause switch');
$src = file_get_contents(dirname(__DIR__) . '/cli/worker.php');
check(strpos($src, "isPaused('worker')") < strpos($src, '->recoverStuck(') && strpos($src, "WORKER_LAST_RUN_AT") < strpos($src, "isPaused('worker')"),
    'Worker writes heartbeat, then stops before any work when paused');

// --- Czasy zadań w tle (Konfiguracja → Synchronizacja) ---
use Pase\Services\TaskTimings;
check(TaskTimings::get($settings, 'ARCHIVE_EVERY') === 60 && TaskTimings::get($settings, 'ARCHIVE_AFTER_DAYS') === 90
    && TaskTimings::get($settings, 'TOKENS_EVERY') === 5 && TaskTimings::get($settings, 'TRACKING_MAX_AGE_DAYS') === 45
    && TaskTimings::get($settings, 'QUEUE_STUCK_MINUTES') === 10, 'Defaults equal previous hard-coded values');
check(TaskTimings::sanitize('TOKENS_EVERY', 60) === 10 && TaskTimings::sanitize('TOKENS_EVERY', 0) === 1, 'Token refresh stays within 1-10 min (cannot be disabled)');
check(TaskTimings::sanitize('ARCHIVE_EVERY', 0) === 0 && TaskTimings::sanitize('ARCHIVE_EVERY', 2) === 5, 'Archive can be disabled, minimum 5 min');
check(TaskTimings::sanitize('ARCHIVE_AFTER_DAYS', 1) === 7 && TaskTimings::sanitize('ARCHIVE_AFTER_DAYS', 'abc') === 90, 'Archive age clamped; junk = default');
$settings->setMany(['ARCHIVE_EVERY' => '120', 'TRACKING_MAX_AGE_DAYS' => '9999']);
check(TaskTimings::label($settings, 'ARCHIVE_EVERY') === 'co 120 min' && TaskTimings::get($settings, 'TRACKING_MAX_AGE_DAYS') === 365, 'Saved values used, stored junk clamped');
$settings->setMany(['ARCHIVE_EVERY' => '0']);
check(TaskTimings::label($settings, 'ARCHIVE_EVERY') === 'wyłączona' && TaskTimings::label($settings, 'QUEUE_STUCK_MINUTES') === '10 min', 'Readable labels');
$src = file_get_contents(dirname(__DIR__) . '/cli/worker.php') . file_get_contents(dirname(__DIR__) . '/src/Services/Scheduler.php') . file_get_contents(dirname(__DIR__) . '/src/Services/ShipmentTracking.php')
    . file_get_contents(dirname(__DIR__) . '/src/Services/PrintAgentPacing.php');
check(!str_contains($src, 'recoverStuck(10)') && !str_contains($src, 'autoArchiveOlderThan(90)') && !str_contains($src, '>= 3600') && !str_contains($src, 'TOKENS_EVERY_SECONDS'),
    'No hard-coded timings left in worker/scheduler');
foreach (array_keys(TaskTimings::DEFS) as $k) { check(str_contains($src, "'" . $k . "'"), "Timing {$k} is used by background code"); }

// --- Czyszczenie kolejki ---
$pdo->exec('CREATE TABLE job_queue (id INTEGER PRIMARY KEY, job_type TEXT, payload TEXT, dedup_key TEXT UNIQUE, status TEXT, updated_at TEXT)');
$ins = $pdo->prepare('INSERT INTO job_queue (job_type, payload, dedup_key, status, updated_at) VALUES (?,?,?,?,?)');
$old = date('Y-m-d H:i:s', time() - 20 * 86400); $recent = date('Y-m-d H:i:s', time() - 2 * 86400);
$pdo->beginTransaction();
for ($i = 1; $i <= 2500; $i++) { $ins->execute(['woo.order.import', '{}', "old-done-$i", 'done', $old]); }
for ($i = 1; $i <= 30; $i++) { $ins->execute(['woo.order.import', '{}', "old-failed-$i", 'failed', $old]); }
for ($i = 1; $i <= 40; $i++) { $ins->execute(['woo.order.import', '{}', "new-done-$i", 'done', $recent]); }
$ins->execute(['woo.order.import', '{}', 'pending-1', 'pending', $old]);
$pdo->commit();
$q = new Pase\Queue\Queue($pdo);
check($q->purgeDone(0) === 0 && (int) $pdo->query('SELECT COUNT(*) FROM job_queue')->fetchColumn() === 2571, 'Purge disabled with 0 days');
check($q->purgeDone(14) === 2500, 'Old done jobs removed in batches (2500 > batch of 1000)');
$left = $pdo->query('SELECT status, COUNT(*) FROM job_queue GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
check($left === ['done' => 40, 'failed' => 30, 'pending' => 1], 'Failed, pending and recent done jobs kept');
check(TaskTimings::get($settings, 'QUEUE_KEEP_DONE_DAYS') === 14 && TaskTimings::sanitize('QUEUE_KEEP_DONE_DAYS', 0) === 0, 'Keep-days default 14, 0 = keep forever');
check(isset(ProcessControl::PROCESSES['cleanup']), 'Cleanup can be paused');
$w = file_get_contents(dirname(__DIR__) . '/cli/worker.php');
check(str_contains($w, "isPaused('cleanup')") && str_contains($w, "'QUEUE_KEEP_DONE_DAYS'") && strpos($w, 'purgeDone') > strpos($w, "isPaused('worker')"), 'Worker runs hourly cleanup unless paused');

// --- Agent drukarki: tempo odpytywania ---
use Pase\Services\PrintAgentPacing;
$root = sys_get_temp_dir() . '/crm-pacing-' . bin2hex(random_bytes(6));
mkdir($root . '/storage/stats', 0700, true);
$settings->setMany(['PRINT_LAST_JOB_AT' => '']);
check(PrintAgentPacing::nextPoll($settings, $root, false, false) === 30, 'Nobody working: slow polling (30 s)');
PrintAgentPacing::touchPanelActivity($root, '/x/public/admin/notifications.php');
check(PrintAgentPacing::lastPanelActivity($root) === 0, 'Bell refresh does not count as work');
PrintAgentPacing::touchPanelActivity($root, '/x/public/admin/index.php');
check(PrintAgentPacing::nextPoll($settings, $root, false, false) === 4, 'Panel in use: fast polling (4 s)');
check(PrintAgentPacing::nextPoll($settings, $root, false, false, time() + 11 * 60) === 30, 'After 10 min without activity: slow again');
check(PrintAgentPacing::nextPoll($settings, $root, true, false) === 30 && PrintAgentPacing::nextPoll($settings, $root, false, true) === 1, 'Paused = slow; more jobs waiting = 1 s');
@unlink($root . '/storage/stats/panel-activity');
$settings->setMany(['PRINT_LAST_JOB_AT' => (string) time()]);
check(PrintAgentPacing::isActive($settings, $root), 'Recent print keeps agent fast');
$settings->setMany(['PRINT_POLL_ACTIVE' => '2', 'PRINT_POLL_IDLE' => '9999', 'PRINT_IDLE_AFTER' => '5']);
check(PrintAgentPacing::nextPoll($settings, $root, true, false) === 300 && PrintAgentPacing::nextPoll($settings, $root, false, false) === 2, 'Panel settings used, idle capped at 300 s');
check(PrintAgentPacing::latestAgentVersion(dirname(__DIR__)) === '1.1', 'Latest agent version read from agent/print_agent.py');
check(isset(ProcessControl::PROCESSES['print']), 'Printing can be paused');
$poll = file_get_contents(dirname(__DIR__) . '/public/print_agent_poll.php');
check(strpos($poll, "isPaused('print')") < strpos($poll, '->claimNext()') && substr_count($poll, "'next_poll'") === 3, 'Poll endpoint: pause checked before handing out jobs; next_poll in every answer');
@unlink($root . '/storage/stats/panel-activity'); @rmdir($root . '/storage/stats'); @rmdir($root . '/storage'); @rmdir($root);

// --- Miejsce na koncie hostingu ---
$cache = sys_get_temp_dir() . '/crm-quota-' . bin2hex(random_bytes(6)) . '.json';
$calls = [];
$responses = [];
$fake = static function (string $m, string $url, array $h) use (&$calls, &$responses): array {
    $calls[] = [$url, $h['Authorization'] ?? ''];
    foreach ($responses as $cmd => $r) { if (str_contains($url, $cmd . '?')) { return $r; } }
    return ['status' => 404, 'body' => ''];
};
$hq = new HostingQuota($settings, $cache, $fake);
check($hq->status()['source'] === null, 'No data without configuration');
$settings->setMany([HostingQuota::S_MANUAL_GB => '20']);
$st = $hq->status();
check($st['source'] === 'manual' && $st['limit'] === 20.0 * 1024 ** 3 && $st['used'] === null, 'Manual limit');

$settings->setMany([HostingQuota::S_URL => 'https://h1.example.pl:2222/', HostingQuota::S_USER => 'klub', HostingQuota::S_KEY => 'SECRETKEY']);
$responses = [
    'CMD_API_SHOW_USER_USAGE' => ['status' => 200, 'body' => json_encode(['quota' => '16998.4', 'bandwidth' => '100', 'db_quota' => '512'])],
    'CMD_API_SHOW_USER_CONFIG' => ['status' => 200, 'body' => json_encode(['quota' => '20480', 'bandwidth' => 'unlimited'])],
];
$st = $hq->status(true);
check($st['source'] === 'directadmin' && abs($st['used'] - 16998.4 * 1048576) < 1 && $st['limit'] === 20480.0 * 1048576, 'DirectAdmin usage and limit (JSON)');
check($st['db'] === 512.0 * 1048576, 'Database size reported separately');
check($calls[0][0] === 'https://h1.example.pl:2222/CMD_API_SHOW_USER_USAGE?json=yes' && $calls[0][1] === 'Basic ' . base64_encode('klub:SECRETKEY'), 'Basic auth with login key');
$n = count($calls);
$hq->status();
check(count($calls) === $n, 'Result cached (no request on every page view)');
check(!str_contains((string) file_get_contents($cache), 'SECRETKEY'), 'Key not stored in cache file');

$responses['CMD_API_SHOW_USER_USAGE']['body'] = 'quota=1024&bandwidth=5';       // starszy format klucz=wartość
$responses['CMD_API_SHOW_USER_CONFIG']['body'] = 'quota=unlimited';
$st = $hq->status(true);
check($st['used'] === 1024.0 * 1048576 && $st['limit'] === 20.0 * 1024 ** 3, 'Legacy format; unlimited falls back to manual limit');

$responses['CMD_API_SHOW_USER_USAGE'] = ['status' => 401, 'body' => ''];
$st = $hq->status(true);
check($st['source'] === 'manual' && str_contains((string) $st['error'], 'odrzucił'), 'Wrong key: clear error, manual fallback');
$responses['CMD_API_SHOW_USER_USAGE'] = ['status' => 200, 'body' => json_encode(['error' => '1', 'text' => 'Permission denied'])];
$st = $hq->status(true);
check(str_contains((string) $st['error'], 'Permission denied'), 'Panel error message shown');
$settings->setMany([HostingQuota::S_URL => 'http:/zle']);
check(str_contains((string) $hq->status(true)['error'], 'https://'), 'Invalid panel address rejected');
@unlink($cache);

echo "PASS: {$checks} process control and hosting quota checks\n";
