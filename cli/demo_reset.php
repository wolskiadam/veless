<?php
declare(strict_types=1);

/**
 * Reset wersji demonstracyjnej: czyści bazę i pliki robocze, tworzy tabele od nowa
 * i wgrywa fikcyjny „Sklep Demo” (Pase\Services\DemoData) z kontem demo / demo.
 *
 * Działa tylko przy DEMO_MODE=1, żeby nie dało się nim przypadkiem wyczyścić prawdziwego
 * systemu. Cron co noc, np.:  0 3 * * *  php /ścieżka/cli/demo_reset.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Tylko CLI.');
}

use Pase\Services\DemoData;
use Pase\Support\Demo;

$config = require dirname(__DIR__) . '/config/config.php';
if (!Demo::on()) {
    fwrite(STDERR, "To polecenie działa tylko w wersji demo (DEMO_MODE=1 w .env). Nic nie zmieniono.\n");
    exit(1);
}

// 1. Usuń wszystkie tabele (osobnym połączeniem, zanim auto-migracja zobaczy bazę).
$db = $config['db'];
$raw = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], $db['port'], $db['name'], $db['charset']),
    $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$raw->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($raw->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$name, $type]) {
    $raw->exec(($type === 'VIEW' ? 'DROP VIEW ' : 'DROP TABLE ') . '`' . str_replace('`', '``', $name) . '`');
}
$raw->exec('SET FOREIGN_KEY_CHECKS = 1');
$raw = null;

// 2. Pliki robocze: sesje (wszyscy wylogowani), załączniki, etykiety, dokumenty, limity logowania.
$wipe = static function (string $dir): void {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        if (in_array($item->getFilename(), ['.gitignore', '.htaccess'], true)) {
            continue;
        }
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
};
foreach (['sessions', 'uploads', 'labels', 'documents', 'cache', 'backups'] as $sub) {
    if (is_dir(PASE_ROOT . '/storage/' . $sub)) {
        $wipe(PASE_ROOT . '/storage/' . $sub);
    }
}
foreach (glob(PASE_ROOT . '/storage/security/*.json') ?: [] as $file) {
    @unlink($file);
}

// 3. Tabele od nowa (auto-migracja przy połączeniu) i konto demo.
$pdo = (require PASE_ROOT . '/config/database.php')($config['db']);
require PASE_ROOT . '/public/admin/bootstrap_admin.php';
@unlink(PASE_ROOT . '/storage/ADMIN_PASSWORD.txt');
$pdo->prepare("UPDATE admin_users SET username = ?, password_hash = ?, email = 'demo@example.com', display_name = 'Konto demo', role = 'admin'")
    ->execute([Demo::USERNAME, password_hash(Demo::PASSWORD, PASSWORD_DEFAULT)]);

// 4. Fikcyjny sklep.
DemoData::seed($pdo, PASE_ROOT . '/public/demo-img');

echo 'Demo zresetowane: ', $pdo->query('SELECT COUNT(*) FROM woo_orders')->fetchColumn(), " zamówień.\n";
