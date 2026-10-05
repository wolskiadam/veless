<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Support\Env;
use Pase\Support\RateLimiter;
use Pase\Support\Totp;
use Pase\Support\TwoFactorCipher;

final class TwoFactorService
{
    public function __construct(private readonly PDO $pdo, private readonly TwoFactorCipher $cipher) {}

    public static function forDatabase(PDO $pdo): self
    {
        // Never generate a replacement key when encrypted secrets already exist.
        $empty = (int) $pdo->query('SELECT COUNT(*) FROM admin_users WHERE totp_secret IS NOT NULL')->fetchColumn() === 0;
        return new self($pdo, TwoFactorCipher::fromFile(
            Env::get('TOTP_KEY_FILE', PASE_ROOT . '/storage/security/totp.key'), $empty
        ));
    }

    public static function required(array $user): bool
    {
        return (int) ($user['totp_required'] ?? 0) === 1
            || ($user['role'] === 'admin' && Env::bool('TOTP_REQUIRE_ADMIN', false));
    }

    public static function allowAttempt(int $userId): bool
    {
        return (new RateLimiter(PASE_ROOT . '/storage/security/two-factor.json'))->consume([
            'account:' . $userId => [10, 900],
            'ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') => [40, 900],
        ]);
    }

    public function protect(string $secret, int $userId): string { return $this->cipher->encrypt($secret, $userId); }
    public function reveal(string $value, int $userId): string { return $this->cipher->decrypt($value, $userId); }

    /** 128-bit random codes; only SHA-256 hashes are retained in the database. */
    public static function recoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 10; ++$i) {
            $codes[] = implode('-', str_split(strtoupper(bin2hex(random_bytes(16))), 8));
        }
        return $codes;
    }

    public static function recoveryHash(string $code): string
    {
        return hash('sha256', strtoupper(str_replace(['-', ' '], '', trim($code))));
    }

    /** Atomic consumption prevents replay, including concurrent requests. */
    public function consume(array $user, string $code, ?int $now = null): bool
    {
        if (empty($user['totp_secret']) || !(int) $user['is_active']) {
            return false;
        }
        $code = trim($code);
        if (preg_match('/\A[0-9]{6}\z/D', $code)) {
            $step = Totp::match($this->reveal($user['totp_secret'], (int) $user['id']), $code, (int) $user['totp_last_step'], $now);
            if ($step === null) { return false; }
            $stmt = $this->pdo->prepare('UPDATE admin_users SET totp_last_step = ? WHERE id = ? AND totp_last_step < ? AND session_version = ? AND totp_secret = ? AND is_active = 1');
            $stmt->execute([$step, $user['id'], $step, $user['session_version'], $user['totp_secret']]);
            return $stmt->rowCount() === 1;
        }
        $hashes = json_decode($user['totp_recovery_hashes'] ?? '[]', true, 512, JSON_THROW_ON_ERROR);
        $hash = self::recoveryHash($code);
        foreach ($hashes as $index => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($hashes[$index]);
                $stmt = $this->pdo->prepare('UPDATE admin_users SET totp_recovery_hashes = ? WHERE id = ? AND totp_recovery_hashes = ? AND session_version = ? AND totp_secret = ? AND is_active = 1');
                $stmt->execute([json_encode(array_values($hashes)), $user['id'], $user['totp_recovery_hashes'], $user['session_version'], $user['totp_secret']]);
                return $stmt->rowCount() === 1;
            }
        }
        return false;
    }

    public function enable(array $user, string $encrypted, string $code): ?array
    {
        $secret = $this->reveal($encrypted, (int) $user['id']);
        $step = Totp::match($secret, trim($code));
        if ($step === null) { return null; }
        $codes = self::recoveryCodes();
        $stmt = $this->pdo->prepare('UPDATE admin_users SET totp_secret = ?, totp_last_step = ?, totp_recovery_hashes = ?, session_version = session_version + 1 WHERE id = ? AND totp_secret IS NULL AND session_version = ? AND is_active = 1');
        $stmt->execute([$encrypted, $step, json_encode(array_map([self::class, 'recoveryHash'], $codes)), $user['id'], $user['session_version']]);
        return $stmt->rowCount() === 1 ? $codes : null;
    }

    public function disable(array $user): bool
    {
        $stmt = $this->pdo->prepare('UPDATE admin_users SET totp_secret = NULL, totp_last_step = -1, totp_recovery_hashes = NULL, session_version = session_version + 1 WHERE id = ? AND session_version = ? AND is_active = 1');
        $stmt->execute([$user['id'], $user['session_version']]);
        return $stmt->rowCount() === 1;
    }

    public function regenerate(array $user): ?array
    {
        $codes = self::recoveryCodes();
        $stmt = $this->pdo->prepare('UPDATE admin_users SET totp_recovery_hashes = ?, session_version = session_version + 1 WHERE id = ? AND session_version = ? AND totp_secret IS NOT NULL AND is_active = 1');
        $stmt->execute([json_encode(array_map([self::class, 'recoveryHash'], $codes)), $user['id'], $user['session_version']]);
        return $stmt->rowCount() === 1 ? $codes : null;
    }
}
