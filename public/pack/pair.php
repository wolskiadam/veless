<?php
declare(strict_types=1);

/**
 * Wejście z kodu QR: ?c=<kod>. Niesparowany telefon dostaje token (ciasteczko) i staje się
 * stanowiskiem pakowania; sparowany tylko otwiera wskazane zamówienie.
 */

use Pase\Services\Packing;

require __DIR__ . '/_device.php';

$ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
$name = match (true) {
    str_contains($ua, 'iPhone') => 'iPhone',
    str_contains($ua, 'iPad') => 'iPad',
    str_contains($ua, 'Android') => 'Telefon z Androidem',
    default => 'Telefon',
};

try {
    $code = $packing->consumeCode((string) ($_GET['c'] ?? ''));
    if ($packDevice === null || (int) $packUser['id'] !== $code['user_id']) {
        // Nowy telefon albo QR z innego konta - telefon przechodzi na konto z kodu.
        if ($packDevice !== null) {
            $packing->revokeDevice((int) $packUser['id'], (int) $packDevice['id']);
        }
        $device = $packing->issueDevice($code['user_id'], $name);
        packSetCookie($device['token'], Packing::DEVICE_DAYS);
    }
    $orderId = $code['order_id'];
} catch (\RuntimeException $e) {
    http_response_code(410);
    ?><!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kod nieważny — Veless</title></head>
    <body style="margin:0;background:#f6f5f2;font-family:system-ui,sans-serif;color:#22252b">
    <div style="max-width:420px;margin:60px auto;padding:28px;background:#fff;border:1px solid #e6e3da;border-radius:12px;text-align:center">
        <div style="font-size:42px">⌛</div>
        <h2 style="margin:8px 0 10px">Kod QR nie działa</h2>
        <p><?= htmlspecialchars($e->getMessage()) ?></p>
        <?php if ($packDevice !== null): ?><a href="index.php" style="display:inline-block;margin-top:10px;padding:12px 18px;background:#9c6b2e;color:#fff;border-radius:10px;text-decoration:none;font-weight:700">Przejdź do pakowania</a><?php endif; ?>
    </div></body></html><?php
    exit;
}

header('Location: index.php' . ($orderId ? '?order=' . $orderId : ''), true, 303);
