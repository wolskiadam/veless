<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/Services/BackupArchive.php';
$temp = sys_get_temp_dir() . '/crm-backup-cli-' . bin2hex(random_bytes(8));
mkdir($temp, 0700); $checks = 0;
function check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    ++$checks; echo "OK: $label\n";
}
try {
    $root = $temp . '/web/app';
    foreach (['/cli', '/src/Services', '/src/Support'] as $part) { mkdir($root . $part, 0700, true); }
    mkdir($temp . '/private-backups', 0700); mkdir($temp . '/private-keys', 0700);
    foreach (['cli/backup.php','src/Services/BackupArchive.php','src/Support/Env.php'] as $file) {
        copy(dirname(__DIR__) . '/' . $file, $root . '/' . $file);
    }
    $config = "BACKUP_DIR=$temp/private-backups\nBACKUP_WEB_ROOT=$temp/web\nBACKUP_KEY_FILE=$temp/private-keys/backup.key\n";
    file_put_contents($root . '/.env', $config);
    $run = static function (array $args) use ($root): array {
        $process = proc_open([PHP_BINARY, $root . '/cli/backup.php', ...$args], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); return [proc_close($process), $out, $err];
    };
    $result = $run(['init-key']);
    check($result[0] === 0, 'CLI creates separate private key');
    $key = file_get_contents($temp . '/private-keys/backup.key');
    check(!str_contains($result[1] . $result[2], $key), 'CLI does not print key');
    check($run(['init-key'])[0] !== 0 && file_get_contents($temp . '/private-keys/backup.key') === $key, 'CLI refuses key overwrite');
    check($run(['create'])[0] !== 0, 'CLI requires paused-write confirmation before opening source database');
    check(glob($temp . '/private-backups/stage-*') === [], 'Refused backup leaves no plaintext staging data');
    file_put_contents($temp . '/database.jsonl', '{"format":"test-only"}');
    \Pase\Services\BackupArchive::create($temp . '/backup.zip', ['database.jsonl' => $temp . '/database.jsonl'], $key);
    $result = $run(['check', $temp . '/backup.zip']);
    check($result[0] === 0 && str_contains($result[1], 'NOT been imported into MySQL'), 'CLI distinguishes file verification from MySQL restore');
    check(count(glob($temp . '/private-backups/restore-check-*')) === 1, 'CLI restores into a new private directory');
    file_put_contents($root . '/.env', str_replace("BACKUP_DIR=$temp/private-backups", "BACKUP_DIR=$temp/web", $config));
    check($run(['check', $temp . '/backup.zip'])[0] !== 0, 'CLI rejects backup directory inside web root');
    file_put_contents($root . '/.env', $config);
    chmod($temp . '/private-keys/backup.key', 0644);
    check($run(['check', $temp . '/backup.zip'])[0] !== 0, 'CLI rejects publicly readable key');
    echo "PASS: $checks backup CLI checks; disposable fixture, no source DB connection\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($temp);
}
