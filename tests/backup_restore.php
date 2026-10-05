<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\BackupArchive;
use Pase\Services\DatabaseSnapshot;
use Pase\Services\DocumentIssueGuard;
use Pase\Support\TwoFactorCipher;
$checks = 0;
function check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    ++$checks; echo "OK: $label\n";
}
function rejects(callable $fn, string $label): void {
    try { $fn(); } catch (Throwable) { check(true, $label); return; }
    check(false, $label);
}
$temp = sys_get_temp_dir() . '/crm-backup-test-' . bin2hex(random_bytes(8));
mkdir($temp, 0700);
try {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, amount DECIMAL(12,2), payload BLOB, optional TEXT, label TEXT UNIQUE); CREATE INDEX order_amount ON orders(amount)');
    $blob = "binary\0\xff\xfe";
    $pdo->prepare('INSERT INTO orders VALUES (?,?,?,?,?)')->execute([1, '89.70', $blob, null, 'Świeczka']);
    $pdo->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY, totp_secret TEXT)');
    $totpKey = random_bytes(32); $cipher = new TwoFactorCipher($totpKey);
    $pdo->prepare('INSERT INTO admin_users VALUES (1,?)')->execute([$cipher->encrypt('TEST-SECRET', 1)]);
    DocumentIssueGuard::migrate($pdo);
    $pdo->exec("INSERT INTO document_issue_operations VALUES (1,'receipt',1,'test-reservation','uncertain',NULL,'2026-01-01','2026-01-01')");
    $metadata = DatabaseSnapshot::export($pdo, $temp . '/database.jsonl');
    check($metadata['tables']['orders'] === 1, 'Snapshot table counts');
    file_put_contents($temp . '/totp.key', base64_encode($totpKey));
    file_put_contents($temp . '/attachment.bin', $blob);
    file_put_contents($temp . '/env', 'DUMMY_CONFIG=only-test');
    $key = base64_encode(random_bytes(32));
    $zip = $temp . '/backup.zip';
    BackupArchive::create($zip, ['database.jsonl' => $temp . '/database.jsonl', 'secrets/totp.key' => $temp . '/totp.key',
        'app/storage/uploads/test.bin' => $temp . '/attachment.bin', 'app/.env' => $temp . '/env'], $key);
    check(is_file($zip) && (fileperms($zip) & 0077) === 0, 'Archive published privately');
    $reader = new ZipArchive(); $reader->open($zip);
    check($reader->getFromName('database.jsonl') === false, 'Database inaccessible without key'); $reader->close();
    rejects(fn() => BackupArchive::verify($zip, base64_encode(random_bytes(32))), 'Wrong backup key rejected');
    rejects(fn() => BackupArchive::create($zip, [], $key), 'Existing backup cannot be overwritten');
    $manifest = BackupArchive::verify($zip, $key, $temp . '/restored');
    check(count($manifest['files']) === 4, 'All backup files verified and restored');
    check(file_get_contents($temp . '/restored/app/storage/uploads/test.bin') === $blob, 'Binary attachment restored exactly');
    check(file_get_contents($temp . '/restored/app/.env') === 'DUMMY_CONFIG=only-test', 'Configuration restored');
    rejects(fn() => BackupArchive::verify($zip, $key, $temp . '/restored'), 'Existing restore directory cannot be overwritten');
    $restored = new PDO('sqlite:' . $temp . '/restored.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $counts = DatabaseSnapshot::restore($restored, $temp . '/restored/database.jsonl');
    check($counts === $metadata['tables'], 'Restore counts equal original snapshot');
    check($restored->query('SELECT payload FROM orders')->fetchColumn() === $blob, 'Database binary field survives restore');
    check($restored->query('SELECT optional FROM orders')->fetchColumn() === null, 'NULL survives restore');
    check($restored->query('SELECT label FROM orders')->fetchColumn() === 'Świeczka', 'Unicode survives restore');
    check((float) $restored->query('SELECT amount FROM orders')->fetchColumn() === 89.70, 'Monetary value survives restore');
    check($restored->query("SELECT name FROM sqlite_master WHERE type='index' AND name='order_amount'")->fetchColumn() === 'order_amount', 'Indexes restored');
    check($restored->query('SELECT state FROM document_issue_operations')->fetchColumn() === 'uncertain', 'Duplicate-prevention reservation survives restore');
    $restoredCipher = TwoFactorCipher::fromFile($temp . '/restored/secrets/totp.key');
    check($restoredCipher->decrypt($restored->query('SELECT totp_secret FROM admin_users')->fetchColumn(), 1) === 'TEST-SECRET', '2FA remains decryptable after restore');
    rejects(fn() => DatabaseSnapshot::restore($restored, $temp . '/restored/database.jsonl'), 'Nonempty database cannot be overwritten');
    rejects(fn() => BackupArchive::create($temp . '/unsafe.zip', ['../escape' => $temp . '/env'], $key), 'Traversal filename rejected');
    // Construct an encrypted but inconsistent archive to exercise checksum validation.
    $bad = new ZipArchive(); $bad->open($temp . '/bad.zip', ZipArchive::CREATE); $bad->setPassword($key);
    $bad->addFromString('x', 'changed'); $bad->setEncryptionName('x', ZipArchive::EM_AES_256);
    $bad->addFromString('manifest.json', json_encode(['format'=>'crm-backup-v1','files'=>['x'=>['size'=>7,'sha256'=>hash('sha256','original')]]]));
    $bad->setEncryptionName('manifest.json', ZipArchive::EM_AES_256); $bad->close();
    rejects(fn() => BackupArchive::verify($temp . '/bad.zip', $key), 'Content checksum mismatch rejected');
    file_put_contents($temp . '/truncated.jsonl', "{\"format\":\"crm-db-v1\",\"driver\":\"sqlite\"}\n");
    rejects(fn() => DatabaseSnapshot::restore(new PDO('sqlite::memory:'), $temp . '/truncated.jsonl'), 'Incomplete snapshot rejected');
    echo "PASS: $checks backup/restore checks; synthetic SQLite data only\n";
} finally {
    $pdo = $restored = null;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($temp);
}
