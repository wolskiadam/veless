<?php
declare(strict_types=1);

/**
 * Okienko „Opis ikon zamówienia” na liście zamówień (AJAX, JSON): dokumenty z wFirma
 * (numer + link) i przesyłki (numer listu + link do śledzenia + etap). Legendę ikon
 * z wiersza rysuje sama lista - tu tylko dane, których w wierszu nie ma.
 *
 * GET: id = woo_order_id.
 */

use Pase\Repository\OrderDocumentRepository;
use Pase\Repository\ShipmentRepository;
use Pase\Services\OrderIconDetails;
use Pase\Services\OrderMerge;

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

header('Content-Type: application/json; charset=utf-8');

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT woo_order_id FROM woo_orders WHERE woo_order_id = ?');
$stmt->execute([$id]);
if ($id <= 0 || $stmt->fetchColumn() === false) {
    http_response_code(404);
    echo json_encode(['error' => 'Nie znaleziono zamówienia.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Scalone: dokument i paczka są na zamówieniu głównym (jak na karcie zamówienia).
$into = (new OrderMerge($pdo))->listInfo([$id])[$id]['into'] ?? null;
$documents = (new OrderDocumentRepository($pdo))->forOrder($id);
$docsFrom = null;
if ($documents === [] && $into !== null) {
    $documents = (new OrderDocumentRepository($pdo))->forOrder((int) $into['id']);
    $docsFrom = $into;
}
$shipments = (new ShipmentRepository($pdo))->forOrder($id);
$shipFrom = null;
if ($shipments === [] && $into !== null) {
    $shipments = (new ShipmentRepository($pdo))->forOrder((int) $into['id']);
    $shipFrom = $into;
}

$legend = [];
foreach (OrderIconDetails::LEGEND as $entry) {
    $legend[] = [
        'kind' => $entry['kind'],
        'state' => $entry['state'],
        'label' => !empty($entry['literal']) ? $entry['label'] : t($entry['label']),
    ];
}

echo json_encode([
    'order_url' => 'order_view.php?id=' . $id,
    'documents' => OrderIconDetails::documents($documents),
    'documents_from' => $docsFrom,
    'shipments' => OrderIconDetails::shipments($shipments),
    'shipments_from' => $shipFrom,
    'legend' => $legend,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
