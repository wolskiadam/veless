<?php
declare(strict_types=1);

/**
 * Zapis układu sekcji per użytkownik (AJAX). Wywoływane przez JS po przeciągnięciu.
 * Body JSON: { view: "order_view", layout: { left: [...ids], right: [...ids] } }
 */

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

header('Content-Type: application/json');

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);

// Token CSRF w nagłówku (JS go dosyła).
$token = $_SERVER['HTTP_X_CSRF'] ?? '';
if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
    http_response_code(419);
    echo json_encode(['error' => 'csrf']);
    return;
}

$view   = preg_replace('/[^a-z_]/', '', (string) ($data['view'] ?? ''));
$layout = $data['layout'] ?? null;
$userId = currentUserId();

if ($view === '' || !is_array($layout) || $userId === null) {
    http_response_code(400);
    echo json_encode(['error' => 'bad_request']);
    return;
}

// Sanityzacja: tylko tablice list stringów (id sekcji).
$clean = [];
foreach (['left', 'right'] as $col) {
    $clean[$col] = [];
    foreach ((array) ($layout[$col] ?? []) as $id) {
        $id = preg_replace('/[^a-z0-9_]/', '', (string) $id);
        if ($id !== '') {
            $clean[$col][] = $id;
        }
    }
}

$stmt = $pdo->prepare(
    'INSERT INTO user_layouts (user_id, view_key, layout)
     VALUES (:u, :v, :l)
     ON DUPLICATE KEY UPDATE layout = VALUES(layout)'
);
$stmt->execute([
    ':u' => $userId,
    ':v' => $view,
    ':l' => json_encode($clean, JSON_UNESCAPED_UNICODE),
]);

echo json_encode(['ok' => true]);
