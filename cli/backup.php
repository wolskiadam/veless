<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Tylko CLI.'); }

// No config/config.php: backup must never auto-migrate the source database.
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Pase\\')) { require $root . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Support\Env;
use Pase\Services\BackupArchive;
use Pase\Services\DatabaseSnapshot;

umask(0077);
$stage = null; $lock = null;
$exitCode = 0;
try {
    Env::load($root . '/.env');
    $mode = $argv[1] ?? 'help';
    if (!in_array($mode, ['init-key', 'create', 'check'], true)) {
        echo "Commands: init-key | create --writes-paused | check /absolute/backup.zip\nSee BACKUPS.md before running.\n";
        exit(0);
    }
    $directory = realpath(Env::require('BACKUP_DIR'));
    $webRoot = realpath(Env::require('BACKUP_WEB_ROOT'));
    if (!$directory || !$webRoot || $webRoot === '/' || !is_dir($directory) || !is_dir($webRoot)) { throw new RuntimeException('Configure existing BACKUP_DIR and BACKUP_WEB_ROOT directories'); }
    $inside = static fn(string $path, string $parent): bool => $path === $parent || str_starts_with($path, $parent . '/');
    if ($inside($directory, $root) || $inside($directory, $webRoot)) { throw new RuntimeException('BACKUP_DIR must be outside the application and the entire web document root'); }
    $keyPath = Env::require('BACKUP_KEY_FILE');
    $keyParent = realpath(dirname($keyPath));
    if (!str_starts_with($keyPath, '/') || !$keyParent || is_link($keyPath)) { throw new RuntimeException('Backup key requires an absolute path in an existing private directory'); }
    $keyPath = $keyParent . '/' . basename($keyPath);
    if ($inside($keyPath, $root) || $inside($keyPath, $webRoot) || $inside($keyPath, $directory)) { throw new RuntimeException('Keep the backup key outside the application, web root and backup directory'); }
    if ($mode === 'init-key') {
        if (file_exists($keyPath)) { throw new RuntimeException('Backup key already exists; refusing to replace it'); }
        $file = fopen($keyPath, 'xb');
        if (!$file) { throw new RuntimeException('Key already exists or cannot be created'); }
        try {
            $key = base64_encode(random_bytes(32));
            if (fwrite($file, $key) !== strlen($key) || !fflush($file)) { throw new RuntimeException('Key write failed'); }
        } finally { fclose($file); }
        echo "Backup key created. Store an offline copy separately; the key was not printed.\n";
        exit(0);
    }
    if (!is_file($keyPath) || (fileperms($keyPath) & 0077) !== 0) { throw new RuntimeException('Backup key missing or accessible to other users (require 0600)'); }
    $key = trim((string) file_get_contents($keyPath));
    $decoded = base64_decode($key, true);
    if ($decoded === false || strlen($decoded) !== 32) { throw new RuntimeException('Invalid backup key'); }
    $lock = fopen($directory . '/backup.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Another backup/restore check is running'); }
    if ($mode === 'check') {
        $archive = realpath($argv[2] ?? '');
        if (!$archive || !is_file($archive)) { throw new RuntimeException('Supply an existing archive'); }
        $target = $directory . '/restore-check-' . bin2hex(random_bytes(8));
        $manifest = BackupArchive::verify($archive, $key, $target);
        echo "Encrypted archive and all checksums verified. Restored files into: $target\n";
        if (in_array('--mysql-test', $argv, true)) {
            $counts = \Pase\Services\BackupRestoreCheck::mysql($target . '/database.jsonl');
            echo 'MySQL restore test passed: ' . count($counts) . " tables restored and counted. The temporary test database was removed.\n";
        } else {
            echo "Database snapshot is present but has NOT been imported into MySQL. Use --mysql-test with dedicated local TEST server credentials (see BACKUPS.md).\n";
        }
        exit(0);
    }
    if (!in_array('--writes-paused', $argv, true)) { throw new RuntimeException('Pause CRM writes, worker and incoming webhooks, then explicitly use --writes-paused (see BACKUPS.md)'); }
    $stage = $directory . '/stage-' . bin2hex(random_bytes(8));
    if (!mkdir($stage, 0700)) { throw new RuntimeException('Cannot create backup stage'); }
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', Env::get('DB_HOST', 'localhost'), Env::int('DB_PORT', 3306), Env::require('DB_NAME'));
    $pdo = new PDO($dsn, Env::require('DB_USER'), Env::get('DB_PASS', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    DatabaseSnapshot::export($pdo, $stage . '/database.jsonl');
    $files = ['database.jsonl' => $stage . '/database.jsonl'];
    foreach (['src', 'config', 'public', 'integrations', 'extensions', 'cli', 'sql', 'lang', 'storage', 'agent', 'vendor'] as $folder) {
        if (!is_dir($root . '/' . $folder)) { continue; }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $folder, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if ($file->isLink()) { throw new RuntimeException('Symlink in backup sources; resolve it explicitly before backup'); }
            if (!$file->isFile()) { continue; }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($folder === 'storage' && (preg_match('/\.(log|lock)$/', $relative) || preg_match('#^storage/security/.*\.json$#', $relative))) { continue; }
            $files['app/' . $relative] = $file->getPathname();
        }
    }
    foreach (['.env', '.htaccess', 'index.php', 'composer.json', 'composer.lock'] as $file) {
        if (is_file($root . '/' . $file)) { $files['app/' . $file] = $root . '/' . $file; }
    }
    $totpPath = Env::get('TOTP_KEY_FILE', $root . '/storage/security/totp.key');
    if (realpath($totpPath) === realpath($keyPath)) { throw new RuntimeException('Use separate keys for 2FA and backups'); }
    if (is_file($totpPath)) { $files['secrets/totp.key'] = $totpPath; }
    $tables = $pdo->query("SHOW TABLES LIKE 'admin_users'")->fetchAll();
    if ($tables && (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE totp_secret IS NOT NULL AND totp_secret <> ''")->fetchColumn() > 0 && !is_file($totpPath)) {
        throw new RuntimeException('Active 2FA accounts but missing encryption key: refusing an incomplete backup');
    }
    $destination = $directory . '/crm-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.zip';
    BackupArchive::create($destination, $files, $key);
    echo "Backup created and checksums verified: $destination\nRemember to resume paused workers/webhooks and copy the archive off this server.\n";
} catch (Throwable $e) {
    // No PDO connection messages, credentials or customer data in cron output.
    $reason = $e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'Check private configuration, database access, disk space and requirements in BACKUPS.md.';
    fwrite(STDERR, 'Backup/check failed: ' . $reason . " No existing backup or live database was overwritten.\n");
    $exitCode = 1;
} finally {
    // Only remove the single staging file and random directory created by this invocation.
    if ($stage !== null && is_dir($stage)) {
        if (is_file($stage . '/database.jsonl')) { unlink($stage . '/database.jsonl'); }
        rmdir($stage);
    }
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
}
exit($exitCode);
