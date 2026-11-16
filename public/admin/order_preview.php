<?php
declare(strict_types=1);

/**
 * Szybki podgląd zamówienia na liście zamówień (AJAX, JSON): produkty z miniaturą, wariantem
 * i ilością, dostawa i uwaga klienta. Dane buduje Services\OrderPreview.
 *
 * GET: id = woo_order_id.
 */

use Pase\Services\OrderItemThumbnails;
use Pase\Services\OrderPreview;

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT o.*, ia.type AS source_type FROM woo_orders o LEFT JOIN integration_accounts ia ON ia.id = o.integration_id WHERE o.woo_order_id = ?');
$stmt->execute([$id]);
$row = $id > 0 ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'Nie znaleziono zamówienia.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Miniatury jak na karcie zamówienia: magazyn CRM, a dla ofert Allegro spoza magazynu - zdjęcie oferty
// (pobrane raz i zapisane w zamówieniu). Bez dociągania zdjęć produktu głównego ze sklepu - okienko ma być szybkie.
$allegro = null;
if (($row['source_type'] ?? '') === 'allegro') {
    try {
        $allegro = \PasePlugin\Allegro\AllegroPlugin::makeClient($pdo, $config['allegro'] ?? []);
    } catch (\Throwable $e) {
        $allegro = null;
    }
}
$thumbs = new OrderItemThumbnails($pdo, $allegro);
$preview = (new OrderPreview($pdo, $thumbs))->build($row);

if ($thumbs->fetchedFromAllegro !== [] && ($row['local_items'] ?? null) === null) {
    $payload = json_decode((string) $row['payload'], true);
    if (is_array($payload) && isset($payload['line_items'])) {
        foreach ($thumbs->fetchedFromAllegro as $i => $url) {
            if (isset($payload['line_items'][$i])) {
                $payload['line_items'][$i]['image'] = $url;
            }
        }
        $pdo->prepare('UPDATE woo_orders SET payload = ? WHERE woo_order_id = ?')
            ->execute([json_encode($payload, JSON_UNESCAPED_UNICODE), $id]);
    }
}

echo json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
