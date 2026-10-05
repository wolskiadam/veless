<?php
declare(strict_types=1);

/**
 * Moduł 3 krok 1: endpoint webhooka WooCommerce (product.updated).
 * Waliduje podpis Woo (HMAC SHA256 w nagłówku X-WC-Webhook-Signature),
 * wrzuca zadanie sync stanu do kolejki i zwraca 200.
 */

use Pase\Queue\Queue;
use Pase\Support\Logger;

$config = require dirname(__DIR__) . '/config/config.php';
Logger::toFile(PASE_ROOT . '/storage/app.log');

$pdo = (require PASE_ROOT . '/config/database.php')($config['db']);
\Pase\Services\Handover::exitWhenAway($pdo); // praca przeniesiona na drugą instalację - ta strona nic nie robi

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    return;
}

$raw = file_get_contents('php://input') ?: '';

// --- Weryfikacja podpisu WooCommerce ---
// Woo wysyła base64(HMAC-SHA256(payload, secret)) w nagłówku.
$wooSecret = $config['woo']['webhook_secret'] ?? '';
if (!is_string($wooSecret) || trim($wooSecret) === '') {
    http_response_code(503);
    echo json_encode(['error' => 'webhook_not_configured']);
    return;
}
if ($wooSecret !== '') {
    $signature = $_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] ?? '';
    if (!\Pase\Support\WebhookSignature::valid($raw, $wooSecret, $signature)) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_signature']);
        Logger::warn('Woo webhook: nieprawidłowy podpis');
        return;
    }
}

// WooCommerce wysyła "ping" przy tworzeniu webhooka - odpowiedz 200.
if (($_SERVER['HTTP_X_WC_WEBHOOK_TOPIC'] ?? '') === '' && $raw === '') {
    http_response_code(200);
    echo 'ok';
    return;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_json']);
    return;
}

$sku           = $payload['sku'] ?? null;
$stockQuantity = $payload['stock_quantity'] ?? null;
$productId     = $payload['id'] ?? null;

if ($sku === null || $sku === '') {
    // Produkt bez SKU nie da się zmapować na ofertę Allegro - przyjmujemy i ignorujemy.
    http_response_code(200);
    echo json_encode(['status' => 'ignored_no_sku']);
    Logger::info('Woo webhook: produkt bez SKU - ignoruję', ['product_id' => $productId]);
    return;
}

$queue = new Queue($pdo);
$queue->enqueue(
    jobType: 'woo.stock.sync',
    payload: [
        'sku'            => $sku,
        'stock_quantity' => $stockQuantity,
        'woo_product_id' => $productId,
    ],
    // Dedup po SKU+stan: jeśli ten sam stan przyjdzie 2x, nie dublujemy.
    // Jeśli stan się zmieni, dedup_key też się zmieni -> nowe zadanie.
    dedupKey: "woo.stock.sync:{$sku}:{$stockQuantity}"
);

http_response_code(200);
echo json_encode(['status' => 'queued', 'sku' => $sku]);
