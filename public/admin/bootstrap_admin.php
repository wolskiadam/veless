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

// Tabela kont, brakujące kolumny i uprawnienia do stron (wspólne z kreatorem instalacji).
\Pase\Support\AdminSchema::migrate($pdo);

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
