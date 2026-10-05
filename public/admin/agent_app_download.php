<?php
declare(strict_types=1);

/**
 * Pobranie programu Veless (drukowanie) (Windows .exe / Mac .app w zip) z panelu: Konfiguracja → Drukowanie.
 * Plik zbudowany przez GitHub Actions leży w storage/agent-builds (patrz Pase\Services\AgentBuilds).
 */

use Pase\Services\AgentBuilds;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);

$platform = (string) ($_GET['platform'] ?? '');
$build = (new AgentBuilds(PASE_ROOT))->build($platform);
if ($build === null) {
    http_response_code(404);
    exit('Program dla tego systemu nie jest jeszcze zbudowany. Zajrzyj za kilka minut po wdrożeniu.');
}
AgentBuilds::stream($build, AgentBuilds::PLATFORMS[$platform]['download_name']);
