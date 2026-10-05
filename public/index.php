<?php
declare(strict_types=1);

/**
 * Health-check / strona startowa. Nie ujawnia żadnych danych wrażliwych.
 * Pozwala sprawdzić, czy PHP i połączenie z bazą działają.
 */

use Pase\Support\Logger;

$config = require dirname(__DIR__) . '/config/config.php';
Logger::toFile(PASE_ROOT . '/storage/app.log');

header('Content-Type: application/json');

try {
    $pdo = (require PASE_ROOT . '/config/database.php')($config['db']);
    $pdo->query('SELECT 1');
    $dbOk = true;
} catch (\Throwable $e) {
    $dbOk = false;
}

echo json_encode([
    'service' => 'pase-middleware',
    'status'  => $dbOk ? 'ok' : 'degraded',
    'db'      => $dbOk ? 'connected' : 'error',
    'time'    => gmdate('c'),
], JSON_PRETTY_PRINT);
