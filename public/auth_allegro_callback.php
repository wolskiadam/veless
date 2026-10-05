<?php
declare(strict_types=1);

/**
 * Moduł 1: endpoint OAuth callback Allegro.
 * Allegro przekierowuje tu po zgodzie sprzedawcy: ?code=...&state=...
 *
 * URL produkcyjny: https://twojadomena.pl/auth_allegro_callback.php
 * (musi być identyczny z ALLEGRO_REDIRECT_URI w .env)
 */

use PasePlugin\Allegro\AllegroPlugin;
use Pase\Support\Logger;
use Pase\Support\Session;

$config = require dirname(__DIR__) . '/config/config.php';
Logger::toFile(PASE_ROOT . '/storage/app.log');

// Ta sama sesja co panel (ciasteczko PASE_ADMIN, katalog storage/sessions) - tam
// allegro_connect.php zapisał `state`. Zwykłe session_start() otwierało inną, pustą sesję
// i każda autoryzacja kończyła się błędem „nieprawidłowy parametr state".
Session::startAdmin();

$pdo  = (require PASE_ROOT . '/config/database.php')($config['db']);

// --- Walidacja parametrów ---
$code  = $_GET['code']  ?? null;
$state = $_GET['state'] ?? null;
$error = $_GET['error'] ?? null;

if ($error !== null) {
    Logger::error('Allegro OAuth odrzucony przez użytkownika', ['error' => $error]);
    http_response_code(400);
    echo 'Autoryzacja Allegro odrzucona: ' . htmlspecialchars((string) $error);
    return;
}

if (!is_string($code) || $code === '') {
    http_response_code(400);
    echo 'Brak parametru code.';
    return;
}

// Weryfikacja `state` względem wartości z sesji (ochrona CSRF). Musi się zgadzać.
$expectedState = $_SESSION['allegro_oauth_state'] ?? '';
unset($_SESSION['allegro_oauth_state']); // jednorazowy
if ($expectedState === '' || !is_string($state) || !hash_equals($expectedState, $state)) {
    Logger::warn('Allegro OAuth: niezgodny state (możliwy CSRF lub wygasła sesja)', ['state' => $state]);
    http_response_code(400);
    echo 'Nieprawidłowy lub wygasły parametr state — rozpocznij autoryzację ponownie z panelu.';
    return;
}
Logger::info('Allegro OAuth callback - state OK');

$service = AllegroPlugin::makeClient($pdo, $config['allegro']);

if ($service->exchangeCodeForToken($code)) {
    // Wracamy do panelu z komunikatem zamiast pustej strony z tekstem.
    $_SESSION['pase_flash'][] = ['type' => 'ok', 'msg' => 'Połączono konto Allegro — token odświeżony.'];
    $back = $_SESSION['allegro_oauth_back'] ?? 'admin/dashboard.php';
    unset($_SESSION['allegro_oauth_back']);
    header('Location: ' . (preg_match('#^admin/[a-z0-9_]+\.php(\?[^\s]*)?$#i', (string) $back) ? $back : 'admin/dashboard.php'));
    exit;
} else {
    http_response_code(502);
    echo 'Allegro: nie udało się wymienić kodu na token. Sprawdź logi (storage/app.log) — '
       . 'najczęściej zła konfiguracja redirect_uri / client_secret lub środowisko (sandbox vs produkcja).';
}
