<?php
declare(strict_types=1);

/**
 * Cron: wykrywa nowe zamówienia Allegro (Order Events API).
 *
 * UWAGA: od wersji z jednym cronem ten skrypt NIE JEST JUŻ POTRZEBNY. Tę samą
 * robotę wykonuje Scheduler przy każdym przebiegu cli/worker.php, więc wystarczy
 * jeden wpis w crontabie (worker co minutę). Skrypt zostaje, bo:
 *   - starsze instalacje mają go w crontabie i mają dalej działać,
 *   - bywa wygodny do ręcznego wymuszenia pobrania z linii poleceń.
 *
 * Uruchomienie go RÓWNOLEGLE z workerem jest bezpieczne: kursor zdarzeń jest
 * wspólny (settings), a klucz dedup w kolejce nie dopuści do podwójnego importu.
 *
 * Cała logika siedzi w Pase\Services\AllegroOrderPoller - tutaj tylko bootstrap.
 */

if (\PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Tylko CLI.');
}

use Pase\Queue\Queue;
use Pase\Repository\SettingsRepository;
use Pase\Services\AllegroOrderPoller;
use Pase\Support\Logger;

$config = require dirname(__DIR__) . '/config/config.php';
$pdo    = (require PASE_ROOT . '/config/database.php')($config['db']);

$poller = new AllegroOrderPoller(
    $pdo,
    new SettingsRepository($pdo),
    new Queue($pdo),
    $config['allegro'] ?? []
);

if (!$poller->isConnected()) {
    Logger::info('poll_allegro_orders: konto Allegro nie jest połączone - pomijam');
    exit(0);
}

$queued = $poller->poll();
Logger::info("poll_allegro_orders: dodano do kolejki: {$queued}");
exit(0);
