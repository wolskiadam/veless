<?php
declare(strict_types=1);

/**
 * Start autoryzacji OAuth z Allegro. Generuje losowy `state` (ochrona CSRF,
 * zapamiętany w sesji), buduje URL zgody sprzedawcy i przekierowuje do Allegro.
 * Po zgodzie Allegro wróci na ALLEGRO_REDIRECT_URI (auth_allegro_callback.php).
 */

use PasePlugin\Allegro\AllegroPlugin;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$config = require PASE_ROOT . '/config/config.php';
$allegroCfg = $config['allegro'] ?? [];

if (($allegroCfg['client_id'] ?? '') === '' || ($allegroCfg['redirect_uri'] ?? '') === '') {
    $PAGE_TITLE = 'Allegro'; $PAGE_KEY = 'integrations';
    require __DIR__ . '/header.php';
    echo '<div class="flash err">Brak konfiguracji Allegro (ALLEGRO_CLIENT_ID / ALLEGRO_REDIRECT_URI w .env).</div>';
    echo '<p><a class="btn secondary" href="integrations.php">← Integracje</a></p>';
    require __DIR__ . '/footer.php';
    return;
}

// Losowy state do weryfikacji w callbacku (ochrona przed CSRF/podmianą).
$state = bin2hex(random_bytes(16));
$_SESSION['allegro_oauth_state'] = $state;
// Po autoryzacji wracamy na stronę, z której kliknięto „Połącz konto".
$ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
$_SESSION['allegro_oauth_back'] = preg_match('#/admin/([a-z0-9_]+\.php(\?[^\s#]*)?)#i', $ref, $m) && $m[1] !== 'allegro_connect.php' ? 'admin/' . $m[1] : 'admin/dashboard.php';

$service = AllegroPlugin::makeClient($pdo, $allegroCfg);
header('Location: ' . $service->buildAuthorizationUrl($state));
exit;
