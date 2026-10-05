<?php
declare(strict_types=1);

/**
 * Wspólny początek stron telefonu (stanowiska pakowania): baza, token z ciasteczka,
 * konto i jego uprawnienie do pakowania. Plik tylko do dołączania (blokada w .htaccess).
 *
 * Ustawia: $config, $pdo, $packing, $packDevice (null = telefon niesparowany/odłączony),
 * $packUser, $packAccess ('hidden'|'view'|'edit').
 */

use Pase\Services\Packing;
use Pase\Support\PagePermissions;

$config = require dirname(__DIR__, 2) . '/config/config.php';
$pdo = (require PASE_ROOT . '/config/database.php')($config['db']);

Packing::migrate($pdo);
$packing = new Packing($pdo);

$packDevice = null;
$packUser = null;
$packAccess = 'hidden';
$packLocAccess = 'hidden';   // Lokalizacje w magazynie - przypisywanie półek skanerem
$found = $packing->deviceByToken((string) ($_COOKIE[Packing::DEVICE_COOKIE] ?? ''));
if ($found !== null) {
    $packDevice = $found['device'];
    $packUser = $found['user'];
    PagePermissions::migrate($pdo);
    $perms = $packUser['role'] === 'admin' ? null : PagePermissions::load($pdo, (int) $packUser['id']);
    $packAccess = PagePermissions::access((string) $packUser['role'], $perms, 'packing');
    $packLocAccess = PagePermissions::access((string) $packUser['role'], $perms, 'locations');
    // Historia zmian zamówienia (AuditTrail) i zmiana statusu podpisują się kontem z $_SESSION.
    $_SESSION = [
        'pase_user_id'   => (int) $packUser['id'],
        'pase_username'  => ($packUser['display_name'] ?: $packUser['username']) . ' (telefon)',
        'pase_user_role' => $packUser['role'],
    ];
}

function packIsHttps(): bool
{
    return (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function packSetCookie(string $value, int $days): void
{
    setcookie(Packing::DEVICE_COOKIE, $value, [
        'expires'  => $value === '' ? time() - 3600 : time() + $days * 86400,
        'path'     => '/',
        'secure'   => packIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
