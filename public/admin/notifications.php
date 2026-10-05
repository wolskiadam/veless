<?php
declare(strict_types=1);

/**
 * Dzwoneczek powiadomień (JSON). GET - lista; POST action=seen | dismiss_order | read (key) | prefs.
 * Wołane z nav.php przy ładowaniu strony i co minutę.
 */

use Pase\Services\Notifications;
use PasePlugin\Allegro\AllegroPlugin;

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

header('Content-Type: application/json');
header('Cache-Control: no-store');

$uid = (int) (currentUserId() ?? 0);
$svc = new Notifications($pdo);
$allegro = static function () use ($pdo) {
    $config = require PASE_ROOT . '/config/config.php';
    return ($config['allegro']['client_id'] ?? '') !== '' ? AllegroPlugin::makeClient($pdo, $config['allegro']) : null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? '');
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) $token)) {
        http_response_code(419);
        echo json_encode(['ok' => false]);
        return;
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'seen') {
        $svc->markOrdersSeen($uid);
    } elseif ($action === 'dismiss_order') {
        $svc->dismissOrder($uid, (int) ($_POST['order'] ?? 0));
    } elseif ($action === 'read') {
        $svc->markRead($uid, (string) ($_POST['key'] ?? ''), $allegro);
    } elseif ($action === 'prefs') {
        $svc->savePrefs($uid, (array) ($_POST['prefs'] ?? []));
    }
    echo json_encode(['ok' => true]);
    return;
}

$data = $svc->collect($uid, $allegro);
// Sesję zamykamy od razu - inaczej równoległe żądania tej samej karty czekają na blokadę pliku sesji.
session_write_close();
echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
