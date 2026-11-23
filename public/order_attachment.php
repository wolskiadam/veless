<?php
declare(strict_types=1);

/**
 * Pobieranie załącznika wiadomości przez KLIENTA (publiczne, dostęp przez token).
 * Wymaga token zamówienia + id wiadomości; sprawdzamy, że wiadomość należy do
 * zamówienia spod tokenu (ochrona przed IDOR).
 *
 * Ta sama bramka weryfikacji co order.php: gdy weryfikacja jest włączona, plik
 * wydajemy tylko w sesji, która przeszła ją na stronie zamówienia (sam link nie wystarcza).
 */

use Pase\Repository\OrderMessageRepository;
use Pase\Repository\WooOrderRepository;
use Pase\Repository\SettingsRepository;
use Pase\Services\Attachments;
use Pase\Services\ClientVerify;

$config = require dirname(__DIR__) . '/config/config.php';
$pdo = (require PASE_ROOT . '/config/database.php')($config['db']);

$token = (string) ($_GET['token'] ?? '');
$id    = (int) ($_GET['id'] ?? 0);

$order = (new WooOrderRepository($pdo))->findByClientToken($token);
if ($order === null) {
    http_response_code(404);
    exit('Brak dostępu.');
}

$verifyEnabled = ((new SettingsRepository($pdo))->get('CLIENT_VERIFY_ENABLED', '1') ?? '1') !== '0';
$payload = json_decode($order['payload'] ?? '{}', true) ?: [];
if ($verifyEnabled && ClientVerify::availableFields($payload) !== []) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['order_verified_' . (int) $order['woo_order_id']])) {
        http_response_code(403);
        exit('Najpierw potwierdź dane na stronie zamówienia.');
    }
}

$m = (new OrderMessageRepository($pdo))->find($id);
if ($m === null || (int) $m['woo_order_id'] !== (int) $order['woo_order_id'] || empty($m['attachment_path'])) {
    http_response_code(404);
    exit('Nie znaleziono załącznika.');
}

$full = Attachments::absolutePath($m['attachment_path']);
if ($full === null || !is_file($full)) {
    http_response_code(404);
    exit('Plik niedostępny.');
}

$name = $m['attachment_name'] ?: basename($full);
header('Content-Type: ' . Attachments::mimeFor($name));
header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
header('Content-Length: ' . (string) filesize($full));
header('X-Content-Type-Options: nosniff');
readfile($full);
