<?php
declare(strict_types=1);

namespace Pase\Support;

final class AdminSession
{
    public static function stamp(array $user): string
    {
        return hash('sha256', implode('|', [
            $user['id'], $user['password_hash'], $user['role'],
            $user['is_active'], $user['session_version'],
            $user['totp_secret'] ?? '',
        ]));
    }

    public static function valid(array|false $user, array $session): bool
    {
        return $user !== false && (int) $user['is_active'] === 1
            && (empty($user['totp_secret']) || ($session['pase_mfa_verified'] ?? false) === true)
            && isset($session['pase_auth_stamp']) && is_string($session['pase_auth_stamp'])
            && hash_equals(self::stamp($user), $session['pase_auth_stamp']);
    }

    public static function pendingValid(array|false $user, array $pending, ?int $now = null): bool
    {
        return $user !== false && (int) $user['is_active'] === 1
            && !empty($user['totp_secret'])
            && (int) ($pending['expires'] ?? 0) > ($now ?? time())
            && is_string($pending['stamp'] ?? null)
            && hash_equals(self::stamp($user), $pending['stamp']);
    }

    /** @param string|null $method jak zalogowano (lista urządzeń): 'password', '2fa', 'quick'. */
    public static function login(\PDO $pdo, array $user, bool $mfaVerified, ?string $method = null): void
    {
        if (!(int) $user['is_active'] || (!empty($user['totp_secret']) && !$mfaVerified)) {
            throw new \RuntimeException('Wymagane potwierdzenie 2FA.');
        }
        $language = $_SESSION['pase_lang'] ?? 'pl';
        $_SESSION = ['pase_lang' => $language];
        session_regenerate_id(true);
        $_SESSION['pase_auth_stamp'] = self::stamp($user);
        $_SESSION['pase_mfa_verified'] = $mfaVerified;
        $_SESSION['pase_user_id'] = (int) $user['id'];
        $_SESSION['pase_username'] = $user['username'];
        $_SESSION['pase_user_role'] = $user['role'];
        $_SESSION['pase_display_name'] = $user['display_name'] ?: $user['username'];
        $pdo->prepare('UPDATE admin_users SET last_login = CURRENT_TIMESTAMP WHERE id = ?')->execute([$user['id']]);
        $devices = new AdminDevices($pdo);
        $devices->prune(Session::lifetime() + 30 * 86400);
        $devices->register($user, $method ?? ($mfaVerified ? '2fa' : 'password'));
    }
}
