<?php
declare(strict_types=1);

namespace Pase\Support;

use PDO;

/**
 * Zalogowane urządzenia konta (Bezpieczeństwo konta → 2FA).
 *
 * Każde logowanie zapisuje wiersz z losowym tokenem, którego skrót trzymamy w bazie,
 * a sam token w sesji tej przeglądarki. auth.php przy każdym żądaniu sprawdza, czy
 * wiersz nadal istnieje i nie został wylogowany - dzięki temu można zakończyć sesję
 * na konkretnym urządzeniu, bez „wyloguj wszędzie”.
 *
 * Wiersze ze starszym session_version niż konto (zmiana hasła, roli, 2FA) albo dawno
 * nieużywane i tak nie dają już dostępu, więc lista ich nie pokazuje.
 */
final class AdminDevices
{
    /** Jak często zapisywać „ostatnio aktywne” (sekundy) - nie przy każdym żądaniu. */
    private const TOUCH_EVERY = 300;

    public function __construct(private readonly PDO $pdo)
    {
        self::migrate($pdo);
    }

    public static function migrate(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS admin_devices (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token_hash CHAR(64) NOT NULL,
                session_version INT UNSIGNED NOT NULL,
                method VARCHAR(20) NOT NULL DEFAULT 'password',
                ip VARCHAR(45) NOT NULL DEFAULT '',
                user_agent VARCHAR(255) NOT NULL DEFAULT '',
                created_at INT NOT NULL,
                last_seen INT NOT NULL,
                revoked_at INT NULL,
                KEY idx_user (user_id, revoked_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_devices (
            id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT NOT NULL, token_hash CHAR(64) NOT NULL,
            session_version INT NOT NULL, method VARCHAR(20) NOT NULL DEFAULT 'password',
            ip VARCHAR(45) NOT NULL DEFAULT '', user_agent VARCHAR(255) NOT NULL DEFAULT '',
            created_at INT NOT NULL, last_seen INT NOT NULL, revoked_at INT NULL)");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_devices_user ON admin_devices (user_id, revoked_at)');
    }

    /** Zapisuje urządzenie bieżącej sesji. Wołane przez AdminSession::login po regeneracji sesji. */
    public function register(array $user, string $method, ?int $now = null): void
    {
        $now ??= time();
        $token = bin2hex(random_bytes(32));
        $this->pdo->prepare('INSERT INTO admin_devices
            (user_id, token_hash, session_version, method, ip, user_agent, created_at, last_seen)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([(int) $user['id'], hash('sha256', $token), (int) $user['session_version'], $method,
                mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $now, $now]);
        $_SESSION['pase_device_id'] = (int) $this->pdo->lastInsertId();
        $_SESSION['pase_device_token'] = $token;
        $_SESSION['pase_device_seen'] = $now;
    }

    /**
     * Czy urządzenie tej sesji nadal może pracować. Sesja sprzed wdrożenia (bez tokenu)
     * zostaje dopisana, żeby wdrożenie nikogo nie wylogowało.
     */
    public function check(array $account, ?int $now = null): bool
    {
        $now ??= time();
        $id = (int) ($_SESSION['pase_device_id'] ?? 0);
        $token = $_SESSION['pase_device_token'] ?? null;
        if ($id === 0 || !is_string($token)) {
            $this->register($account, 'earlier', $now);
            return true;
        }
        $st = $this->pdo->prepare('SELECT user_id, token_hash, revoked_at FROM admin_devices WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['revoked_at'] !== null || (int) $row['user_id'] !== (int) $account['id']
            || !hash_equals((string) $row['token_hash'], hash('sha256', $token))) {
            return false;
        }
        if ($now - (int) ($_SESSION['pase_device_seen'] ?? 0) >= self::TOUCH_EVERY) {
            $this->pdo->prepare('UPDATE admin_devices SET last_seen = ?, ip = ? WHERE id = ?')
                ->execute([$now, mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), $id]);
            $_SESSION['pase_device_seen'] = $now;
        }
        return true;
    }

    /** Aktywne urządzenia konta, najpierw ostatnio używane. @return list<array<string,mixed>> */
    public function active(array $account, int $maxIdleSeconds, ?int $now = null): array
    {
        $st = $this->pdo->prepare('SELECT id, method, ip, user_agent, created_at, last_seen FROM admin_devices
            WHERE user_id = ? AND revoked_at IS NULL AND session_version = ? AND last_seen > ?
            ORDER BY last_seen DESC, id DESC LIMIT 50');
        $st->execute([(int) $account['id'], (int) $account['session_version'], ($now ?? time()) - $maxIdleSeconds]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Wylogowuje jedno urządzenie tego konta (nie da się wylogować cudzego). */
    public function revoke(int $userId, int $deviceId, ?int $now = null): bool
    {
        $st = $this->pdo->prepare('UPDATE admin_devices SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL');
        $st->execute([$now ?? time(), $deviceId, $userId]);
        return $st->rowCount() === 1;
    }

    /** Wylogowuje wszystkie urządzenia konta poza wskazanym. Zwraca ich liczbę. */
    public function revokeOthers(int $userId, int $keepId, ?int $now = null): int
    {
        $st = $this->pdo->prepare('UPDATE admin_devices SET revoked_at = ? WHERE user_id = ? AND id <> ? AND revoked_at IS NULL');
        $st->execute([$now ?? time(), $userId, $keepId]);
        return $st->rowCount();
    }

    /** Sprząta stare wpisy przy okazji logowania. */
    public function prune(int $olderThanSeconds, ?int $now = null): void
    {
        $this->pdo->prepare('DELETE FROM admin_devices WHERE last_seen < ?')->execute([($now ?? time()) - $olderThanSeconds]);
    }
}
