<?php
declare(strict_types=1);

// Run independently of worker.php, every five minutes. No automatic repairs.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Tylko CLI.');
}

use Pase\Repository\SettingsRepository;
use Pase\Services\Mailer;
use Pase\Services\OperationalHealth;

$config = require dirname(__DIR__) . '/config/config.php';
$lock = fopen(PASE_ROOT . '/storage/monitor.lock', 'c+');
if ($lock === false) {
    fwrite(STDERR, "Nie można otworzyć blokady monitora.\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
try {
    $pdo = (require PASE_ROOT . '/config/database.php')($config['db']);
    $repository = new SettingsRepository($pdo);
    $settings = $repository->all();
    $failed = (int) $pdo->query("SELECT COUNT(*) FROM job_queue WHERE status = 'failed'")->fetchColumn();
    $now = time();
    $issues = OperationalHealth::issues((int) ($settings['WORKER_LAST_RUN_AT'] ?? 0), $failed, $now);
    $fingerprint = $issues === [] ? '' : hash('sha256', implode("\n", $issues));
    echo $issues === [] ? "OK\n" : implode("\n", $issues) . "\n";
    if (($settings['OPS_ALERT_ENABLED'] ?? '0') !== '1') {
        exit($issues === [] ? 0 : 1);
    }
    $recipient = $settings['OPS_ALERT_EMAIL'] ?? '';
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid alert recipient');
    }
    if (OperationalHealth::shouldNotify($fingerprint, $settings['OPS_ALERT_LAST_STATE'] ?? '',
        (int) ($settings['OPS_ALERT_LAST_ATTEMPT'] ?? 0), $now)) {
        $mailer = new Mailer([
            'host' => $settings['MAIL_SMTP_HOST'] ?? '',
            'port' => $settings['MAIL_SMTP_PORT'] ?? '587',
            'user' => $settings['MAIL_SMTP_USER'] ?? '',
            'pass' => $settings['MAIL_SMTP_PASS'] ?? '',
            'secure' => $settings['MAIL_SMTP_SECURE'] ?? 'tls',
            'from_email' => $settings['MAIL_FROM_EMAIL'] ?? '',
            'from_name' => $settings['MAIL_FROM_NAME'] ?? 'Veless',
        ]);
        // Persist before sending: a crashed SMTP attempt must not cause an email storm.
        $repository->setMany(['OPS_ALERT_LAST_ATTEMPT' => (string) $now]);
        $body = $issues === [] ? 'Monitor nie wykrywa już poprzednich problemów.' : implode("\n", $issues);
        [$sent] = $mailer->send($recipient, $issues === [] ? 'Veless — ustąpienie alarmu' : 'Veless — alarm',
            '<p>' . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')) . '</p>', ['type' => 'ops_alert']);
        if (!$sent) {
            throw new RuntimeException('SMTP failure');
        }
        $repository->setMany(['OPS_ALERT_LAST_STATE' => $fingerprint]);
    }
    exit($issues === [] ? 0 : 1);
} catch (Throwable $e) {
    // Database failure prevents loading SMTP settings; an external monitor is still needed.
    fwrite(STDERR, "Monitor nie ukończył sprawdzenia lub wysyłki. Sprawdź bazę, ustawienia SMTP i logi serwera.\n");
    exit(2);
}
