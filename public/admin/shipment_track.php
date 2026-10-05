<?php
declare(strict_types=1);

/**
 * Sprawdzenie etapu przesyłki u przewoźnika „na żądanie" (przycisk ↻ na stronie zamówienia).
 * POST: csrf, shipment=<id>. Zwraca JSON {ok, message, status}.
 * Zmiana etapu uruchamia reguły 'shipment.status' tak samo jak w harmonogramie.
 */

use Pase\Services\ShipmentTracking;
use PasePlugin\Allegro\AllegroPlugin;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

header('Content-Type: application/json');

$token = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? '');
if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'message' => 'Nieprawidłowy token CSRF.']);
    return;
}

// Ręczne ustawienie etapu (gdy przewoźnik nie podaje danych).
if (($_POST['action'] ?? '') === 'set_status') {
    $ok = ShipmentTracking::setManual($pdo, (int) ($_POST['shipment'] ?? 0), (string) ($_POST['status'] ?? ''), currentUserName());
    echo json_encode(['ok' => $ok, 'message' => $ok ? 'Zapisano status.' : 'Nie można ustawić tego statusu dla tej przesyłki.']);
    return;
}

$config = require PASE_ROOT . '/config/config.php';
$cfg    = $config['allegro'] ?? [];

try {
    $tracker = new ShipmentTracking($pdo, AllegroPlugin::makeClient($pdo, $cfg), $cfg);
    echo json_encode($tracker->checkOne((int) ($_POST['shipment'] ?? 0)));
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'message' => 'Błąd śledzenia: ' . $e->getMessage(), 'status' => null]);
}
