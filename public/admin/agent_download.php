<?php
declare(strict_types=1);

/**
 * Pobranie programu agenta druku jako archiwum ZIP.
 * Źródłem jest katalog agent/ w projekcie - ten sam, który leży w repozytorium,
 * więc panel zawsze oddaje aktualną wersję, bez ręcznego wgrywania paczek.
 *
 * GET: [config=1] - dołącza config.json z wpisanym adresem panelu i kluczem API,
 *      żeby na stacji trzeba było wybrać już tylko drukarkę.
 */

use Pase\Repository\SettingsRepository;
use Pase\Services\ZipBuilder;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$agentDir = PASE_ROOT . '/agent';
if (!is_dir($agentDir)) {
    http_response_code(404);
    exit('Katalog agent/ nie został wgrany na serwer.');
}

// Do archiwum trafia tylko to, co potrzebne na stacji: kod, skrypty i instrukcja.
// Śmieci systemowe i cache Pythona pomijamy - inaczej user rozpakowuje folder
// __pycache__ z plikami .pyc skompilowanymi dla cudzej wersji Pythona.
$allowedExtensions = ['py', 'bat', 'md', 'txt'];
$files = [];

foreach (scandir($agentDir) ?: [] as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    $path = $agentDir . '/' . $entry;
    if (!is_file($path)) {
        continue;
    }
    if (!in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), $allowedExtensions, true)) {
        continue;
    }
    $files['PaseAgent/' . $entry] = $path;
}

if ($files === []) {
    http_response_code(500);
    exit('Katalog agent/ jest pusty - wgraj pliki programu na serwer.');
}

$zip = ZipBuilder::build($files);

// Wariant z gotową konfiguracją: dokładamy config.json, żeby instalacja sprowadzała
// się do wyboru drukarki. Plik zawiera klucz API, więc pobiera go zalogowana obsługa,
// a nazwę archiwum wyróżniamy, żeby nie krążyło przypadkiem po firmie.
$withConfig = ($_GET['config'] ?? '') === '1';
$downloadName = 'PaseAgent.zip';

if ($withConfig) {
    $settings = new SettingsRepository($pdo);
    $apiKey   = (string) ($settings->get('PRINT_AGENT_API_KEY', '') ?? '');

    if ($apiKey === '') {
        http_response_code(422);
        exit('Najpierw wygeneruj klucz API agenta w ustawieniach drukowania.');
    }

    $baseUrl = rtrim((string) ($settings->get('APP_BASE_URL', '') ?? ''), '/');
    if ($baseUrl === '') {
        // Awaryjnie odtwarzamy adres z bieżącego żądania - panel działa w /admin,
        // a agent odpytuje katalog wyżej (tam leżą print_agent_poll.php i _ack.php).
        $scheme  = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host    = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $baseUrl = $scheme . '://' . $host . rtrim(dirname(dirname((string) ($_SERVER['PHP_SELF'] ?? ''))), '/');
    }

    $config = [
        'base_url'       => $baseUrl,
        'api_key'        => $apiKey,
        // Drukarek nie zgadujemy - agent zapyta o nie przy pierwszym uruchomieniu.
        'printer_name'   => '',
        'printer_a4'     => '',
        'dpi'            => 203,
        'label_width_mm' => 101.6,
        'threshold'      => 160,
        'browser_path'   => '',
    ];

    $configPath = tempnam(sys_get_temp_dir(), 'pase-cfg-');
    if ($configPath !== false) {
        file_put_contents(
            $configPath,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        $files['PaseAgent/config.json'] = $configPath;
        $zip = ZipBuilder::build($files);
        @unlink($configPath);
        $downloadName = 'PaseAgent-skonfigurowany.zip';
    }
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . strlen($zip));
header('X-Content-Type-Options: nosniff');
echo $zip;
