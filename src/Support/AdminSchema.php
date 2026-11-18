<?php
declare(strict_types=1);

namespace Pase\Support;

use PDO;

/**
 * Tabela kont panelu (admin_users) i jej brakujące kolumny, idempotentnie.
 * Wołane z public/admin/bootstrap_admin.php oraz z kreatora instalacji (instaluj.php),
 * który zakłada pierwsze konto administratora z danymi podanymi w przeglądarce.
 */
final class AdminSchema
{
    public static function migrate(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            email VARCHAR(255) DEFAULT NULL,
            password_hash VARCHAR(255) NOT NULL,
            display_name VARCHAR(100) DEFAULT NULL,
            role ENUM('admin','editor','viewer') NOT NULL DEFAULT 'admin',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            session_version INT UNSIGNED NOT NULL DEFAULT 1,
            totp_secret TEXT NULL,
            totp_required TINYINT(1) NOT NULL DEFAULT 0,
            totp_last_step BIGINT NOT NULL DEFAULT -1,
            totp_recovery_hashes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_login TIMESTAMP NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $columns = [
            'session_version'      => 'INT UNSIGNED NOT NULL DEFAULT 1',
            'totp_secret'          => 'TEXT NULL',
            'totp_required'        => 'TINYINT(1) NOT NULL DEFAULT 0',
            'totp_last_step'       => 'BIGINT NOT NULL DEFAULT -1',
            'totp_recovery_hashes' => 'TEXT NULL',
        ];
        foreach ($columns as $column => $definition) {
            if (!$pdo->query("SHOW COLUMNS FROM admin_users LIKE '{$column}'")->fetch()) {
                try {
                    $pdo->exec("ALTER TABLE admin_users ADD COLUMN {$column} {$definition}");
                } catch (\PDOException $e) {
                    // Równoległe żądanie mogło już dodać kolumnę.
                    if (!$pdo->query("SHOW COLUMNS FROM admin_users LIKE '{$column}'")->fetch()) {
                        throw $e;
                    }
                }
            }
        }

        // Uprawnienia do stron (System → Użytkownicy) - patrz PagePermissions.
        PagePermissions::migrate($pdo);
    }
}
