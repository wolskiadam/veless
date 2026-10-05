<?php
declare(strict_types=1);

/**
 * Endpoint odpytywany przez lokalnego agenta druku (program na komputerze z drukarką
 * Zebra USB - patrz cli/print_agent.py). Autoryzacja kluczem API (ustawienia: Drukowanie),
 * nie sesją - agent działa poza panelem, bez logowania.
 *
 * GET ?key=...   -> {"job": null} albo {"job": {id, format, filename, content_b64}}
 */

use Pase\Repository\PrintJobRepository;
use Pase\Repository\SettingsRepository;
use Pase\Support\Logger;

$config = require dirname(__DIR__) . '/config/config.php';
Logger::toFile(PASE_ROOT . '/storage/app.log');

$pdo = (require PASE_ROOT . '/config/database.php')($config['db']);

header('Content-Type: application/json; charset=utf-8');

$settings = new SettingsRepository($pdo);
$expectedKey = $settings->get('PRINT_AGENT_API_KEY', '');
$key = (string) ($_GET['key'] ?? '');

if ($expectedKey === '' || $key === '' || !hash_equals($expectedKey, $key)) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    return;
}

$seen = ['PRINT_AGENT_LAST_SEEN' => gmdate('Y-m-d H:i:s')];

// Agent melduje przy okazji ustawienia swojej stacji (rozdzielczość drukarki,
// szerokość taśmy, czy ma drukarkę A4). Panel używa ich do podglądu wydruku -
// bez tego liczyłby rozmiar na wartościach domyślnych. Starsze wersje agenta
// tych parametrów nie wysyłają i wtedy po prostu zostają poprzednie wartości.
$reportedDpi = (int) ($_GET['dpi'] ?? 0);
if ($reportedDpi > 0) {
    $seen['PRINT_AGENT_DPI'] = (string) $reportedDpi;
}
$reportedLabelMm = (float) str_replace(',', '.', (string) ($_GET['label_mm'] ?? '0'));
if ($reportedLabelMm > 0) {
    $seen['PRINT_AGENT_LABEL_MM'] = (string) $reportedLabelMm;
}
if (isset($_GET['a4'])) {
    $seen['PRINT_AGENT_HAS_A4'] = $_GET['a4'] === '1' ? '1' : '0';
}
// Wersja agenta (od 1.1) - panel Drukowanie pokazuje, czy stacja ma aktualny program.
$reportedVersion = (string) ($_GET['v'] ?? '');
if (preg_match('/^[0-9]{1,3}(\.[0-9]{1,3}){0,2}$/D', $reportedVersion)) {
    $seen['PRINT_AGENT_VERSION'] = $reportedVersion;
}

$settings->setMany($seen);

$jobs = new PrintJobRepository($pdo);
$jobs->requeueStale();

// Tempo odpytywania (next_poll) i wstrzymanie wydruków - System → Obciążenie serwera,
// czasy w Konfiguracja → Synchronizacja. Agent sprzed 1.1 ignoruje next_poll (pyta co 4 s).
$paused = (new \Pase\Services\ProcessControl($settings))->isPaused('print');
if ($paused) {
    echo json_encode(['job' => null, 'paused' => true,
        'next_poll' => \Pase\Services\PrintAgentPacing::nextPoll($settings, PASE_ROOT, true, false)]);
    return;
}

$job = $jobs->claimNext();
if ($job === null) {
    echo json_encode(['job' => null, 'next_poll' => \Pase\Services\PrintAgentPacing::nextPoll($settings, PASE_ROOT, false, false),
        'mode' => \Pase\Services\PrintAgentPacing::mode($settings, PASE_ROOT)]);
    return;
}
$settings->setMany([\Pase\Services\PrintAgentPacing::LAST_JOB_KEY => (string) time()]);
$morePending = (int) $pdo->query("SELECT COUNT(*) FROM print_jobs WHERE status = 'pending'")->fetchColumn() > 0;

echo json_encode(['job' => [
    'id'          => (int) $job['id'],
    'format'      => $job['format'],
    // Na którą drukarkę: 'zebra' (etykiety) albo 'a4' (zwykła drukarka).
    // Starsza wersja agenta tego pola nie zna i wszystko drukuje na Zebrze,
    // dlatego wartością domyślną musi pozostać 'zebra'.
    'target'      => $job['target'] ?? 'zebra',
    'filename'    => $job['filename'],
    'content_b64' => $job['content_b64'],
], 'next_poll' => \Pase\Services\PrintAgentPacing::nextPoll($settings, PASE_ROOT, false, $morePending), 'mode' => 'active']);
