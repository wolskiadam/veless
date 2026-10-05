<?php
declare(strict_types=1);

/**
 * Potwierdzenie wykonania zadania druku przez lokalnego agenta (patrz print_agent_poll.php).
 * POST: key=..., job_id=..., status=done|failed, error=... (opcjonalnie, przy failed)
 */

use Pase\Repository\PrintJobRepository;
use Pase\Repository\SettingsRepository;
use Pase\Support\Logger;

$config = require dirname(__DIR__) . '/config/config.php';
Logger::toFile(PASE_ROOT . '/storage/app.log');

$pdo = (require PASE_ROOT . '/config/database.php')($config['db']);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    return;
}

$settings = new SettingsRepository($pdo);
$expectedKey = $settings->get('PRINT_AGENT_API_KEY', '');
$key = (string) ($_POST['key'] ?? '');

if ($expectedKey === '' || $key === '' || !hash_equals($expectedKey, $key)) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    return;
}

$jobId  = (int) ($_POST['job_id'] ?? 0);
$status = ($_POST['status'] ?? '') === 'failed' ? 'failed' : 'done';

if ($jobId <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'missing_job_id']);
    return;
}

$jobs = new PrintJobRepository($pdo);
if ($status === 'failed') {
    $error = trim((string) ($_POST['error'] ?? 'nieznany błąd agenta'));
    $jobs->markFailed($jobId, $error);
    Logger::warn("print_agent: zadanie #{$jobId} nieudane - {$error}");
} else {
    $jobs->markDone($jobId);
    Logger::info("print_agent: zadanie #{$jobId} wydrukowane");
}

echo json_encode(['ok' => true]);
