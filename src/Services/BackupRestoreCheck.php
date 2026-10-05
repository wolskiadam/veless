<?php
declare(strict_types=1);
namespace Pase\Services;

use PDO;

/** Explicit opt-in test server only; credentials must not be from production. */
final class BackupRestoreCheck
{
    public static function mysql(string $snapshot): array
    {
        if (getenv('CRM_TEST_MYSQL') !== '1') { throw new \RuntimeException('Explicit CRM_TEST_MYSQL=1 opt-in required'); }
        $port = (int) (getenv('CRM_TEST_MYSQL_PORT') ?: 3306);
        if ($port < 1 || $port > 65535) { throw new \RuntimeException('Invalid test port'); }
        $pdo = new PDO('mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4',
            getenv('CRM_TEST_MYSQL_USER') ?: 'root', getenv('CRM_TEST_MYSQL_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]);
        $database = 'crm_restore_' . bin2hex(random_bytes(12));
        $pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
        try {
            $pdo->exec("USE `$database`");
            return DatabaseSnapshot::restore($pdo, $snapshot);
        } finally {
            // The only target is the unique database created above by this invocation.
            $pdo->exec("DROP DATABASE `$database`");
        }
    }
}
