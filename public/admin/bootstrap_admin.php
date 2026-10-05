<?php
declare(strict_types=1);

/**
 * Inicjalizacja panelu: tabela admin_users + domyślne konto admina.
 * Includowane ZARÓWNO przez auth.php, jak i login.php, żeby konto powstało
 * niezależnie od tego, którą stronę użytkownik otworzy jako pierwszą.
 *
 * Wymaga wcześniej załadowanego $pdo (przez db_admin.php) oraz PASE_ROOT/autoloadera.
 */

/** @var PDO $pdo */

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

if (!$pdo->query("SHOW COLUMNS FROM admin_users LIKE 'session_version'")->fetch()) {
    try {
        $pdo->exec('ALTER TABLE admin_users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1');
    } catch (\PDOException $e) {
        // A concurrent request may have added the column already.
        if (!$pdo->query("SHOW COLUMNS FROM admin_users LIKE 'session_version'")->fetch()) {
            throw $e;
        }
    }
}

foreach (['totp_secret' => 'TEXT NULL', 'totp_required' => 'TINYINT(1) NOT NULL DEFAULT 0', 'totp_last_step' => 'BIGINT NOT NULL DEFAULT -1', 'totp_recovery_hashes' => 'TEXT NULL'] as $column => $definition) {
    if (!$pdo->query("SHOW COLUMNS FROM admin_users LIKE '{$column}'")->fetch()) {
        try {
            $pdo->exec("ALTER TABLE admin_users ADD COLUMN {$column} {$definition}");
        } catch (\PDOException $e) {
            if (!$pdo->query("SHOW COLUMNS FROM admin_users LIKE '{$column}'")->fetch()) {
                throw $e;
            }
        }
    }
}

// Uprawnienia do stron (System → Użytkownicy) - patrz Pase\Support\PagePermissions.
\Pase\Support\PagePermissions::migrate($pdo);

$adminExists = (int) $pdo->query("SELECT COUNT(*) FROM admin_users")->fetchColumn() > 0;
if (!$adminExists) {
    $plain = \Pase\Support\Env::get('ADMIN_DEFAULT_PASSWORD', '');
    if ($plain === '') {
        $plain = bin2hex(random_bytes(6)); // 12 znaków
        @file_put_contents(
            PASE_ROOT . '/storage/ADMIN_PASSWORD.txt',
            "Login: admin\nHaslo: {$plain}\n\nZaloguj sie i zmien haslo, potem usun ten plik.\n"
        );
    }
    $hash = password_hash($plain, PASSWORD_DEFAULT);
    $pdo->prepare("INSERT IGNORE INTO admin_users (username, email, password_hash, display_name, role)
                   VALUES ('admin', :email, :hash, 'Administrator', 'admin')")
        ->execute([':email' => 'admin@example.com', ':hash' => $hash]);
}
