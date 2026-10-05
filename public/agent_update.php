<?php
declare(strict_types=1);

/**
 * Aktualizacje programu Veless (drukowanie) (Windows / Mac). Autoryzacja kluczem API agenta
 * (jak print_agent_poll.php) - program działa poza panelem, bez logowania.
 *
 * GET ?key=...&platform=windows|macos            -> {"version": "2.0.1", "sha256": "...", "size": 123} albo {"version": null}
 * GET ?key=...&platform=windows|macos&download=1 -> plik programu
 */

use Pase\Repository\SettingsRepository;
use Pase\Services\AgentBuilds;

$config = require dirname(__DIR__) . '/config/config.php';
$pdo = (require PASE_ROOT . '/config/database.php')($config['db']);

$settings = new SettingsRepository($pdo);
$expectedKey = (string) ($settings->get('PRINT_AGENT_API_KEY', '') ?? '');
$key = (string) ($_GET['key'] ?? '');
if ($expectedKey === '' || $key === '' || !hash_equals($expectedKey, $key)) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'unauthorized']);
    return;
}

$platform = (string) ($_GET['platform'] ?? '');
$build = (new AgentBuilds(PASE_ROOT))->build($platform);

if (isset($_GET['download'])) {
    if ($build === null) {
        http_response_code(404);
        exit('Brak programu dla tego systemu.');
    }
    AgentBuilds::stream($build, AgentBuilds::PLATFORMS[$platform]['download_name']);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($build === null
    ? ['version' => null]
    : ['version' => $build['version'], 'sha256' => $build['sha256'], 'size' => $build['size']]);
