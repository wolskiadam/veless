<?php
declare(strict_types=1);

/**
 * Powiadomienia push tego urządzenia (JSON, z dzwoneczka w nav.php - Services\WebPush).
 * GET - klucz publiczny VAPID; POST action=subscribe (endpoint, p256dh, auth) | unsubscribe (endpoint) | test
 *   | sources (endpoint) | save_sources (endpoint, all=1 albo sources[]=id konta, 0 = ręczne).
 */

use Pase\Services\WebPush;

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

header('Content-Type: application/json');
header('Cache-Control: no-store');

$uid = currentUserId();
if ($uid === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Brak użytkownika.']);
    return;
}
$push = new WebPush($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Adres panelu jako „sub” w podpisie VAPID (Apple wymaga adresu https albo mailto).
    $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
    try {
        echo json_encode(['ok' => true, 'publicKey' => $push->publicKey($host !== '' ? 'https://' . $host : null)]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    return;
}

$token = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? '');
if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    return;
}
session_write_close();

$action = (string) ($_POST['action'] ?? '');
try {
    if ($action === 'subscribe') {
        $push->subscribe($uid, (string) ($_POST['endpoint'] ?? ''), (string) ($_POST['p256dh'] ?? ''), (string) ($_POST['auth'] ?? ''),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        echo json_encode(['ok' => true]);
    } elseif ($action === 'unsubscribe') {
        $push->unsubscribe((string) ($_POST['endpoint'] ?? ''), $uid);
        echo json_encode(['ok' => true]);
    } elseif ($action === 'sources') {
        // Sklepy/źródła tego urządzenia (zaznaczone) i wszystkie do wyboru.
        echo json_encode(['ok' => true, 'options' => $push->sourceOptions(),
            'selected' => $push->sources((string) ($_POST['endpoint'] ?? ''), $uid)], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'save_sources') {
        $ids = !empty($_POST['all']) ? null : array_map('intval', (array) ($_POST['sources'] ?? []));
        $saved = $push->saveSources((string) ($_POST['endpoint'] ?? ''), $uid, $ids);
        echo json_encode(['ok' => $saved, 'error' => $saved ? null : 'Nie znaleziono tego urządzenia - włącz powiadomienia ponownie.'],
            JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'test') {
        $sent = $push->send(['title' => 'Powiadomienia działają', 'body' => 'Tak będą wyglądać powiadomienia o nowych zamówieniach.',
            'url' => 'orders.php', 'tag' => 'test'], $uid);
        echo json_encode(['ok' => $sent > 0, 'sent' => $sent, 'error' => $sent > 0 ? null : 'Nie udało się wysłać powiadomienia na żadne urządzenie.'],
            JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Nieznana akcja.'], JSON_UNESCAPED_UNICODE);
    }
} catch (\InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Błąd zapisu: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
