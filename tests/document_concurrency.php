<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\AuditTrail;
use Pase\Services\DocumentIssueGuard;
if (($argv[1] ?? '') === 'child') {
    $pdo = new PDO('sqlite:' . $argv[2], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA busy_timeout=5000');
    for ($i = 0; $i < 500 && !is_file($argv[3]); ++$i) { usleep(10000); }
    try {
        (new DocumentIssueGuard($pdo))->reserve(1, 'receipt', 1);
        echo 'reserved';
    } catch (Throwable) { echo 'blocked'; }
    exit;
}
$temp = sys_get_temp_dir() . '/crm-document-race-' . bin2hex(random_bytes(8)); mkdir($temp, 0700);
$children = [];
try {
    $pdo = new PDO('sqlite:' . $temp . '/db.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY); INSERT INTO woo_orders VALUES (1);
        CREATE TABLE order_documents (id INTEGER PRIMARY KEY, woo_order_id INTEGER, provider TEXT, document_type TEXT)');
    AuditTrail::migrate($pdo); DocumentIssueGuard::migrate($pdo);
    for ($i = 0; $i < 2; ++$i) {
        $process = proc_open([PHP_BINARY, __FILE__, 'child', $temp . '/db.sqlite', $temp . '/go'],
            [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Cannot spawn test process'); }
        fclose($pipes[0]); $children[] = [$process, $pipes];
    }
    file_put_contents($temp . '/go', 'test');
    $results = [];
    foreach ($children as [$process, $pipes]) {
        $results[] = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0 || $error !== '') { throw new RuntimeException('Child failed: ' . $error); }
    }
    $children = []; sort($results);
    if ($results !== ['blocked','reserved']) { throw new RuntimeException('Expected exactly one permitted issuance'); }
    if ((int) $pdo->query('SELECT COUNT(*) FROM document_issue_operations')->fetchColumn() !== 1) { throw new RuntimeException('Reservation missing'); }
    $blocked = false;
    try { (new DocumentIssueGuard($pdo))->reserve(1, 'receipt', 1); } catch (Throwable) { $blocked = true; }
    if (!$blocked) { throw new RuntimeException('Dead process reservation incorrectly released'); }
    echo "PASS: concurrent processes allow one reservation; process exit does not unlock it\n";
} finally {
    foreach ($children as [$process, $pipes]) { if (is_resource($process)) { proc_terminate($process); proc_close($process); } }
    $pdo = null;
    foreach (new DirectoryIterator($temp) as $file) { if (!$file->isDot() && $file->isFile()) { unlink($file->getPathname()); } }
    rmdir($temp);
}
