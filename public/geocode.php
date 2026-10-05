<?php
declare(strict_types=1);

/**
 * Endpoint JSON dla mapy dostawy na stronie klienta (order.php).
 * Zwraca przybliżone współrzędne nadawcy i odbiorcy (poziom miasta/kodu pocztowego)
 * do narysowania trasy na mapie Leaflet.
 *
 * Dostęp: token zamówienia (jak order.php) + zweryfikowana sesja (jeśli weryfikacja włączona).
 * NIE ujawnia dokładnego adresu — tylko współrzędne geokodowane z kodu pocztowego/miasta.
 */

use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\SettingsRepository;
use Pase\Repository\WooOrderRepository;
use Pase\Services\ClientVerify;
use Pase\Services\Geocoder;

$config = require dirname(__DIR__) . '/config/config.php';
\Pase\Support\Logger::toFile(PASE_ROOT . '/storage/app.log');
$pdo = (require PASE_ROOT . '/config/database.php')($config['db']);

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$fail = static function (string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg]);
    exit;
};

$token = (string) ($_GET['token'] ?? '');
$order = (new WooOrderRepository($pdo))->findByClientToken($token);
if ($order === null) {
    $fail('not_found', 404);
}

$wooOrderId = (int) $order['woo_order_id'];
$payload    = json_decode($order['payload'] ?? '{}', true) ?: [];

// Bramka: jeśli weryfikacja włączona i pasują pola, wymagaj zweryfikowanej sesji.
$settings      = new SettingsRepository($pdo);
$verifyEnabled = ($settings->get('CLIENT_VERIFY_ENABLED', '1') ?? '1') !== '0';
$availFields   = ClientVerify::availableFields($payload);
$isVerified    = !empty($_SESSION['order_verified_' . $wooOrderId]) || !$verifyEnabled || $availFields === [];
if (!$isVerified) {
    $fail('unverified', 403);
}

// Adres odbiorcy (shipping, a w razie braku billing).
$shipping = $payload['shipping'] ?? [];
$billing  = $payload['billing'] ?? [];
$hasShip  = trim(($shipping['address_1'] ?? '') . ($shipping['city'] ?? '')) !== '';
$recAddr  = $hasShip ? $shipping : $billing;

// Adres nadawcy z konfiguracji aktywnej integracji kurierskiej (BLPaczka).
$senderAddr = [];
$acc = (new IntegrationAccountRepository($pdo))->firstActive('blpaczka');
if ($acc !== null) {
    $sender = ($acc['config'] ?? [])['sender'] ?? [];
    $senderAddr = [
        'postcode' => $sender['postal']  ?? '',
        'city'     => $sender['city']    ?? '',
        'country'  => $sender['country'] ?? 'PL',
    ];
}

$geo       = new Geocoder($pdo);
$recipient = $geo->geocodeAddress($recAddr);
$origin    = $senderAddr !== [] ? $geo->geocodeAddress($senderAddr) : null;

echo json_encode([
    'ok'        => true,
    'origin'    => $origin,    // {lat,lon} | null
    'recipient' => $recipient, // {lat,lon} | null
]);
