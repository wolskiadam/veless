<?php
declare(strict_types=1);

/**
 * Webhook WooCommerce dla ZAMÓWIEŃ (order.created / order.updated) - per integracja.
 *
 * URL zawiera ?integration=ID, dzięki czemu PASE wie, z którego sklepu pochodzi
 * zamówienie (multi-integracja). Sekret webhooka brany jest z tej integracji.
 *
 * Cokolwiek nie jest prawdziwym zamówieniem (ping/test) -> 200, by Woo zapisał webhook.
 */

use Pase\Queue\Queue;
use Pase\Repository\IntegrationAccountRepository;
use Pase\Support\Logger;

$config = require dirname(__DIR__) . '/config/config.php';
Logger::toFile(PASE_ROOT . '/storage/app.log');

$pdo = (require PASE_ROOT . '/config/database.php')($config['db']);
\Pase\Services\Handover::exitWhenAway($pdo); // praca przeniesiona na drugą instalację - ta strona nic nie robi

$integrationId = (int) ($_GET['integration'] ?? 0);
$raw = file_get_contents('php://input') ?: '';

// 1) Ping / test / puste / nie-POST -> 200.
$topic = $_SERVER['HTTP_X_WC_WEBHOOK_TOPIC'] ?? '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $raw === '' || $topic === '' || str_contains(strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 'webhook')) {
    http_response_code(200);
    echo 'ok';
    Logger::info('Woo webhook: ping/test 200', ['integration' => $integrationId, 'topic' => $topic]);
    return;
}

$payload = json_decode($raw, true);
if (!is_array($payload) || (int) ($payload['id'] ?? 0) <= 0) {
    http_response_code(200);
    echo 'ok';
    return;
}

// 2) Ustal integrację i jej sekret.
$repo = new IntegrationAccountRepository($pdo);
$integration = $integrationId ? $repo->find($integrationId) : null;

// Fallback: brak ?integration=ID w URL (częsta literówka przy konfiguracji webhooka w Woo).
// Jeśli jest DOKŁADNIE JEDNA aktywna integracja WooCommerce, użyj jej - dzięki temu
// zamówienia wpadają nawet bez parametru. Przy wielu sklepach wymagamy jawnego ID.
if ($integration === null) {
    $wooActive = $repo->activeByType('woocommerce');
    if (count($wooActive) === 1) {
        $integration   = $wooActive[0];
        $integrationId = (int) $integration['id'];
        Logger::info('Woo webhook: brak integration w URL - użyto jedynej aktywnej', ['integration' => $integrationId]);
    }
}

if ($integration === null || $integration['type'] !== 'woocommerce' || !$integration['is_active']) {
    http_response_code(404);
    echo json_encode(['error' => 'unknown_or_inactive_integration']);
    Logger::warn('Woo webhook: nieznana/nieaktywna integracja (dodaj ?integration=ID do URL webhooka w Woo)', ['integration' => $integrationId]);
    return;
}

// 3) Weryfikacja podpisu sekretem TEJ integracji.
$secret = $integration['webhook_secret'] ?? '';
if (!is_string($secret) || trim($secret) === '') {
    http_response_code(503);
    echo json_encode(['error' => 'webhook_not_configured']);
    return;
}
if ($secret !== '') {
    $signature = $_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] ?? '';
    if (!\Pase\Support\WebhookSignature::valid($raw, $secret, $signature)) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_signature']);
        Logger::warn('Woo webhook: zły podpis', ['integration' => $integrationId]);
        return;
    }
}

$wooOrderId = (int) $payload['id'];
$status = $payload['status'] ?? 'unknown';

// 4) Do kolejki - z integration_id w payloadzie, by worker wiedział, do którego sklepu należy.
$payload['__integration_id'] = $integrationId;

$queue = new Queue($pdo);
$queue->enqueue(
    jobType: 'woo.order.import',
    payload: $payload,
    dedupKey: "woo.order.import:{$integrationId}:{$wooOrderId}:{$status}"
);

http_response_code(200);
echo json_encode(['status' => 'queued', 'woo_order_id' => $wooOrderId, 'integration' => $integrationId]);
