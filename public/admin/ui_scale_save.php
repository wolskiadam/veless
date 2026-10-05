<?php
declare(strict_types=1);

/**
 * Zapis skali interfejsu (AJAX z przełącznika w pasku nawigacji). POST: csrf, scale (procent).
 */

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

use Pase\Support\UiScale;

header('Content-Type: application/json');

$token = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? '');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
    http_response_code(419);
    echo json_encode(['error' => 'csrf']);
    return;
}

$userId = currentUserId();
if ($userId === null) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_request']);
    return;
}

try {
    $scale = UiScale::save($pdo, $userId, UiScale::normalize($_POST['scale'] ?? UiScale::DEFAULT));
} catch (\Throwable) {
    http_response_code(500);
    echo json_encode(['error' => 'save_failed']);
    return;
}
$_SESSION['ui_scale'] = $scale;
$_SESSION['ui_scale_user'] = $userId;

echo json_encode(['ok' => true, 'scale' => $scale]);
