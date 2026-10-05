<?php
declare(strict_types=1);

/**
 * API asystenta pakowania dla sparowanego telefonu (token w ciasteczku, bez sesji panelu).
 * Te same akcje co public/admin/packing_api.php, bez parowania, telefonów i ustawień.
 * Zapisy wymagają nagłówka X-Requested-With: pack - obca strona nie ustawi go bez zgody CORS.
 */

use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\OrderStatusRepository;
use Pase\Services\OrderStatusService;
use Pase\Services\PackingApi;

require __DIR__ . '/_device.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($packDevice === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Telefon nie jest sparowany — zeskanuj kod QR w panelu.', 'unpaired' => true]);
    return;
}
if ($packAccess === 'hidden') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Twoje konto nie ma dostępu do pakowania.']);
    return;
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$in = $isPost ? $_POST : $_GET;
$action = (string) ($in['action'] ?? '');
if (!in_array($action, ['order', 'orders', 'queue', 'find', 'browse', 'loc_scan'], true)
    && (!$isPost || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'pack')) {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Nieprawidłowe żądanie.']);
    return;
}

$changeStatus = static function (int $id, string $status) use ($pdo, $config): string {
    return (new OrderStatusService($pdo, new IntegrationAccountRepository($pdo), new OrderStatusRepository($pdo), $config['allegro'] ?? []))
        ->change($id, $status);
};

$api = new PackingApi($pdo, [
    'user_id'   => (int) $packUser['id'],
    'user_name' => ($packUser['display_name'] ?: $packUser['username']) . ' (telefon)',
    'can_edit'  => $packAccess === 'edit',
    'is_admin'  => false,
    'panel'     => false,
    'can_locations' => $packLocAccess === 'edit',
], $changeStatus);

[$code, $body] = $api->handle($action, $in, $_FILES);
http_response_code($code);
echo json_encode($body, JSON_UNESCAPED_UNICODE);
