<?php
declare(strict_types=1);

// Disabled until verified sender authentication is implemented.
// Import continues through authenticated cli/poll_allegro_orders.php.
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['error' => 'webhook_disabled', 'message' => 'Use authenticated order polling.']);
