<?php
declare(strict_types=1);

/**
 * Cron: odświeża tokeny OAuth (Allegro) przed wygaśnięciem.
 *
 * UWAGA: od wersji z jednym cronem ten skrypt NIE JEST JUŻ POTRZEBNY. To samo robi
 * Scheduler co 5 minut przy przebiegu cli/worker.php, więc wystarczy jeden wpis
 * w crontabie. Skrypt zostaje dla zgodności ze starszymi instalacjami i do
 * ręcznego wymuszenia odświeżenia z linii poleceń.
 *
 * Cała logika siedzi w Pase\Services\TokenRefresher - tutaj tylko bootstrap.
 */

if (\PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Tylko CLI.');
}

use Pase\Services\TokenRefresher;
use Pase\Support\Logger;

$config = require dirname(__DIR__) . '/config/config.php';
$pdo    = (require PASE_ROOT . '/config/database.php')($config['db']);

$refreshed = (new TokenRefresher($pdo, $config['allegro'] ?? []))->run();
Logger::info("refresh_tokens: odświeżono tokenów: {$refreshed}");
exit(0);
