<?php
declare(strict_types=1);

/**
 * API asystenta pakowania dla zalogowanego panelu (sesja). Telefon bez logowania
 * używa public/pack/api.php - obie drogi obsługuje ten sam Pase\Services\PackingApi.
 *
 * GET  ?action=order&id=…  | orders&ids[]=… | queue | browse&status=&q= | find&q=… | devices | settings
 * POST action=set|reset|packed|problem|photo|queue_add|queue_remove|pair_code|revoke_device|settings_save
 *      (nagłówek X-CSRF-Token albo pole csrf)
 */

use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\OrderStatusRepository;
use Pase\Repository\SettingsRepository;
use Pase\Services\OrderStatusService;
use Pase\Services\PackingApi;

$_SERVER['HTTP_ACCEPT'] = 'application/json'; // błędy uprawnień z auth.php jako JSON
require __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$in = $isPost ? $_POST : $_GET;
if ($isPost && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
}
$action = (string) ($in['action'] ?? '');

$readOnly = ['order', 'orders', 'queue', 'find', 'browse', 'loc_scan', 'devices', 'settings'];
if (!in_array($action, $readOnly, true)) {
    if (!$isPost) {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Ta operacja wymaga POST.']);
        return;
    }
    $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $in['csrf'] ?? '');
    if ($sent === '' || !hash_equals(csrfToken(), $sent)) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'error' => 'Sesja wygasła — odśwież stronę.']);
        return;
    }
}

$changeStatus = static function (int $id, string $status) use ($pdo, $config): string {
    return (new OrderStatusService($pdo, new IntegrationAccountRepository($pdo), new OrderStatusRepository($pdo), $config['allegro'] ?? []))
        ->change($id, $status);
};

// Adres, pod który telefon ma wejść z QR: ustawienie APP_BASE_URL (katalog public/), a gdy puste - z żądania.
$base = rtrim((string) ((new SettingsRepository($pdo))->get('APP_BASE_URL', '') ?? ''), '/');
if ($base === '') {
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
    $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
        . rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/admin/x.php'), 2)), '/');
}

$api = new PackingApi($pdo, [
    'user_id'   => (int) currentUserId(),
    'user_name' => currentUserName(),
    'can_edit'  => canEdit(),
    'is_admin'  => isAdmin(),
    'panel'     => true,
    'can_locations' => canEditPage('locations'),
], $changeStatus, $base);

[$code, $body] = $api->handle($action, $in, $_FILES);
http_response_code($code);
echo json_encode($body, JSON_UNESCAPED_UNICODE);
