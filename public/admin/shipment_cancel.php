<?php
declare(strict_types=1);

/**
 * Anulowanie przesyłki przez wtyczkę kurierską konta (Courier::cancelShipment).
 * POST: shipment=<id w shipments>, csrf. Zwraca JSON.
 */

use Pase\Plugin\Contract\Courier;
use Pase\Plugin\PluginRegistry;
use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\ShipmentRepository;
use Pase\Support\Logger;

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

$shipmentId = (int) ($_POST['shipment'] ?? 0);
$repo = new ShipmentRepository($pdo);
$sh   = $repo->find($shipmentId);

if ($sh === null) {
    echo json_encode(['ok' => false, 'message' => 'Nie znaleziono przesyłki.']);
    return;
}
if (($sh['status'] ?? '') === 'cancelled') {
    echo json_encode(['ok' => true, 'message' => 'Przesyłka już anulowana.']);
    return;
}

// Nieudana próba nadania albo przesyłka wpisana ręcznie („Inne") - nic do anulowania u przewoźnika, sprzątamy listę.
if (($sh['status'] ?? '') === 'error' || empty($sh['integration_id'])) {
    $repo->setStatus($shipmentId, 'cancelled');
    echo json_encode(['ok' => true, 'message' => 'Usunięto z listy przesyłek.']);
    return;
}

// ID u przewoźnika jako tekst (BLPaczka - liczba, „Wysyłam z Allegro" - UUID).
$externalId = trim((string) ($sh['bl_order_id'] ?? ''));
if ($externalId === '' || $externalId === '0') {
    echo json_encode(['ok' => false, 'message' => 'Brak ID przesyłki u przewoźnika — nie można anulować.']);
    return;
}

$bl = (new IntegrationAccountRepository($pdo))->find((int) $sh['integration_id']);
if ($bl === null) {
    echo json_encode(['ok' => false, 'message' => 'Brak integracji kurierskiej.']);
    return;
}
$courierApi = PluginRegistry::forAccount($bl['type'], $bl['config'] ?? []);
if (!$courierApi instanceof Courier) {
    echo json_encode(['ok' => false, 'message' => 'Wtyczka kurierska niedostępna lub wyłączona.']);
    return;
}

$r = $courierApi->cancelShipment($externalId);
Logger::info('Kurier anulowanie', ['shipment' => $shipmentId, 'external_id' => $externalId, 'ok' => $r['ok']]);

if ($r['ok']) {
    $repo->setStatus($shipmentId, 'cancelled');
}

echo json_encode(['ok' => $r['ok'], 'message' => $r['message']]);
