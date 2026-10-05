<?php
declare(strict_types=1);

/**
 * Stanowisko pakowania na telefonie. Telefon parujemy kodem QR z panelu (pair.php) -
 * bez hasła, z dostępem tylko do pakowania. Tu trafiają zamówienia wysłane „📱 Na telefon”.
 */

require __DIR__ . '/_device.php';

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

if ($packDevice === null || $packAccess === 'hidden') {
    $noAccess = $packDevice !== null;
    ?><!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex"><title>Pakowanie — Veless</title></head>
    <body style="margin:0;background:#f6f5f2;font-family:system-ui,sans-serif;color:#22252b">
    <div style="max-width:440px;margin:40px auto;padding:28px 24px;background:#fff;border:1px solid #e6e3da;border-radius:14px;text-align:center">
        <div style="font-size:48px">📦</div>
        <h2 style="margin:8px 0 12px">Stanowisko pakowania</h2>
        <?php if ($noAccess): ?>
            <p>Konto <strong><?= htmlspecialchars((string) ($packUser['display_name'] ?: $packUser['username'])) ?></strong> nie ma dostępu do pakowania.
                Poproś administratora o uprawnienie „Asystent pakowania”.</p>
        <?php else: ?>
            <p>Ten telefon nie jest jeszcze sparowany z Veless.</p>
            <ol style="text-align:left;line-height:1.6;padding-left:22px">
                <li>Na komputerze otwórz <strong>Zamówienia → 📦 Pakowanie</strong> (albo zaznacz zamówienia i kliknij „📦 Pakuj”).</li>
                <li>Kliknij <strong>▦ QR</strong>.</li>
                <li>Zeskanuj kod aparatem tego telefonu.</li>
            </ol>
            <p style="color:#6d7076;font-size:14px">Telefon dostanie dostęp tylko do pakowania — bez logowania hasłem. Odłączysz go w tym samym oknie z kodem QR.</p>
        <?php endif; ?>
    </div></body></html><?php
    exit;
}

$openId = (int) ($_GET['order'] ?? $_GET['open'] ?? 0);
$app = [
    'mode'     => 'phone',
    'api'      => 'api.php',
    'photoUrl' => 'photo.php',
    'orderUrl' => '',
    'csrf'     => '',
    'ids'      => $openId > 0 ? [$openId] : [],
    'openId'   => $openId ?: null,
    'useQueue' => true,
    'isAdmin'  => false,
    'canEdit'  => $packAccess === 'edit',
    'canLocations' => $packLocAccess === 'edit',
    'backUrl'  => '',
    'userName' => (string) ($packUser['display_name'] ?: $packUser['username']),
];
$assetBase = 'assets';
$qrLib = '';
require __DIR__ . '/_app.php';
