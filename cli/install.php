<?php
declare(strict_types=1);

/**
 * Instalator CLI (alternatywa dla install.php przez przeglądarkę).
 * Uruchom:  php cli/install.php
 * Pyta o dane bazy, testuje połączenie, tworzy tabele i zapisuje .env.
 */

if (\PHP_SAPI !== 'cli') {
    exit('Ten instalator uruchamia się tylko z linii poleceń.');
}

$root       = dirname(__DIR__);
$envPath    = $root . '/.env';
$schemaPath = $root . '/sql/schema.sql';

fwrite(STDOUT, "=== Instalator Veless (CLI) ===\n\n");

if (is_file($envPath)) {
    fwrite(STDOUT, "Plik .env już istnieje. Nadpisać dane bazy? [t/N]: ");
    $ans = strtolower(trim((string) fgets(STDIN)));
    if ($ans !== 't') {
        fwrite(STDOUT, "Przerwano.\n");
        exit(0);
    }
}

function ask(string $label, string $default = ''): string
{
    $suffix = $default !== '' ? " [{$default}]" : '';
    fwrite(STDOUT, "{$label}{$suffix}: ");
    $val = trim((string) fgets(STDIN));
    return $val !== '' ? $val : $default;
}

$db = [
    'host' => ask('Host bazy', 'localhost'),
    'port' => ask('Port', '3306'),
    'name' => ask('Nazwa bazy'),
    'user' => ask('Użytkownik bazy'),
    'pass' => ask('Hasło bazy'),
];

if ($db['name'] === '' || $db['user'] === '') {
    fwrite(STDERR, "BŁĄD: nazwa bazy i użytkownik są wymagane.\n");
    exit(1);
}

// --- Test połączenia ---
try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $db['host'], (int) $db['port'], $db['name']);
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    fwrite(STDOUT, "✓ Połączenie z bazą OK\n");
} catch (\Throwable $e) {
    fwrite(STDERR, "✗ Błąd połączenia: {$e->getMessage()}\n");
    exit(1);
}

// --- Import schematu ---
$sql = file_get_contents($schemaPath);
if ($sql === false) {
    fwrite(STDERR, "✗ Brak pliku sql/schema.sql\n");
    exit(1);
}
try {
    $pdo->exec($sql);
    fwrite(STDOUT, "✓ Tabele utworzone\n");
} catch (\Throwable $e) {
    fwrite(STDERR, "✗ Błąd importu schematu: {$e->getMessage()}\n");
    exit(1);
}

// --- Zapis .env ---
$template = file_get_contents($root . '/.env.example');
if ($template === false) {
    fwrite(STDERR, "✗ Brak .env.example\n");
    exit(1);
}
$secret = bin2hex(random_bytes(24));
$map = [
    '/^DB_HOST=.*/m'            => 'DB_HOST=' . $db['host'],
    '/^DB_PORT=.*/m'            => 'DB_PORT=' . $db['port'],
    '/^DB_NAME=.*/m'            => 'DB_NAME=' . $db['name'],
    '/^DB_USER=.*/m'            => 'DB_USER=' . $db['user'],
    '/^DB_PASS=.*/m'            => 'DB_PASS=' . $db['pass'],
    '/^WORKER_HTTP_SECRET=.*/m' => 'WORKER_HTTP_SECRET=' . $secret,
];
$env = $template;
foreach ($map as $pattern => $replacement) {
    $env = preg_replace($pattern, $replacement, $env);
}
if (file_put_contents($envPath, $env) === false) {
    fwrite(STDERR, "✗ Nie mogę zapisać .env\n");
    exit(1);
}
fwrite(STDOUT, "✓ Plik .env zapisany\n\n");

fwrite(STDOUT, "Instalacja zakończona. Dalej:\n");
fwrite(STDOUT, "  1. Uzupełnij klucze API w .env (Allegro / WooCommerce / wFirma)\n");
fwrite(STDOUT, "  2. Dodaj wpisy cron (worker co 1 min, refresh co 5 min) - patrz README.md\n");
fwrite(STDOUT, "  3. Jeśli wgrywałeś też install.php przez web - usuń public/install.php\n");
exit(0);
