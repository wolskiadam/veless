<?php
declare(strict_types=1);

namespace Pase\Support;

use PDO;
use Pase\Services\MailAccounts;
use Pase\Services\Mailer;

/**
 * Szybkie logowanie nowego urządzenia innym, już zalogowanym (telefon ↔ komputer).
 *
 * Nowe urządzenie pokazuje krótki kod (i QR z tym kodem), a zalogowane urządzenie go zatwierdza.
 * Sesję dostaje wyłącznie przeglądarka, która rozpoczęła logowanie: zna losowy sekret
 * trzymany w jej sesji PHP. Przechwycony kod sam niczego nie otwiera.
 *
 * - Kod jest ważny TTL sekund i działa raz. W bazie leżą tylko skróty kodu i sekretu.
 * - Zatwierdzenie zapisuje podpis konta (AdminSession::stamp). Zmiana hasła, roli, 2FA
 *   albo „wyloguj wszędzie” przed odebraniem zatwierdzenia unieważnia je.
 * - Wiersze tabeli są historią: kto, kiedy, z jakiego IP i przeglądarki (30 dni).
 */
final class QuickLogin
{
    /** Czas na zatwierdzenie kodu na zalogowanym urządzeniu. */
    public const TTL = 60;
    /** Czas, w którym komputer musi odebrać zatwierdzenie. */
    public const PICKUP_TTL = 60;
    /** Jak długo trzymać historię. */
    public const KEEP_DAYS = 30;
    /** 32 znaki bez mylących 0/O i 1/I: 8 znaków = 40 bitów. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const LENGTH = 8;

    public function __construct(private readonly PDO $pdo)
    {
        self::migrate($pdo);
    }

    public static function migrate(PDO $pdo): void
    {
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_quick_logins (
            id $id,
            code_hash CHAR(64) NOT NULL,
            browser_hash CHAR(64) NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'pending',
            user_id INT NULL,
            user_stamp CHAR(64) NULL,
            request_ip VARCHAR(45) NOT NULL DEFAULT '',
            request_agent VARCHAR(255) NOT NULL DEFAULT '',
            approver_ip VARCHAR(45) NULL,
            created_at INT NOT NULL,
            expires_at INT NOT NULL,
            decided_at INT NULL,
            used_at INT NULL
        )" . ($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
        if ($mysql) {
            if (!$pdo->query("SHOW INDEX FROM admin_quick_logins WHERE Key_name = 'idx_code'")->fetch()) {
                $pdo->exec('CREATE INDEX idx_code ON admin_quick_logins (code_hash)');
            }
            if (!$pdo->query("SHOW INDEX FROM admin_quick_logins WHERE Key_name = 'idx_user'")->fetch()) {
                $pdo->exec('CREATE INDEX idx_user ON admin_quick_logins (user_id, id)');
            }
        } else {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_code ON admin_quick_logins (code_hash)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_user ON admin_quick_logins (user_id, id)');
        }
    }

    /**
     * Nowa prośba o logowanie dla tej przeglądarki. Sekret trzeba zachować w jej sesji.
     * @return array{id:int, code:string, secret:string, expires:int}
     */
    public function start(string $ip, string $userAgent, ?int $now = null): array
    {
        $now ??= time();
        $this->pdo->prepare('DELETE FROM admin_quick_logins WHERE created_at < ?')
            ->execute([$now - self::KEEP_DAYS * 86400]);
        $code = '';
        for ($i = 0; $i < self::LENGTH; ++$i) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $secret = bin2hex(random_bytes(32));
        $this->pdo->prepare('INSERT INTO admin_quick_logins
            (code_hash, browser_hash, status, request_ip, request_agent, created_at, expires_at)
            VALUES (?, ?, \'pending\', ?, ?, ?, ?)')
            ->execute([hash('sha256', $code), hash('sha256', $secret), mb_substr($ip, 0, 45),
                mb_substr($userAgent, 0, 255), $now, $now + self::TTL]);
        return ['id' => (int) $this->pdo->lastInsertId(), 'code' => $code, 'secret' => $secret, 'expires' => $now + self::TTL];
    }

    /** Kod w postaci kanonicznej (bez spacji i myślników, wielkie litery) albo '' gdy niepoprawny. */
    public static function normalize(string $code): string
    {
        $code = strtoupper((string) preg_replace('/[\s-]+/', '', $code));
        return preg_match('/^[' . self::ALPHABET . ']{' . self::LENGTH . '}$/', $code) ? $code : '';
    }

    /** ABCD-EFGH - czytelniej do przepisania. */
    public static function format(string $code): string
    {
        return substr($code, 0, 4) . '-' . substr($code, 4);
    }

    /** Oczekująca, ważna prośba dla kodu wpisanego na zalogowanym urządzeniu. @return array<string,mixed>|null */
    public function findPending(string $code, ?int $now = null): ?array
    {
        $code = self::normalize($code);
        if ($code === '') {
            return null;
        }
        $st = $this->pdo->prepare("SELECT * FROM admin_quick_logins
            WHERE code_hash = ? AND status = 'pending' AND expires_at > ? ORDER BY id DESC LIMIT 1");
        $st->execute([hash('sha256', $code), $now ?? time()]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Telefon zatwierdza prośbę dla swojego konta. Warunkowy UPDATE: działa tylko raz. */
    public function approve(int $id, array $approver, string $ip, ?int $now = null): bool
    {
        $now ??= time();
        $st = $this->pdo->prepare("UPDATE admin_quick_logins
            SET status = 'approved', user_id = ?, user_stamp = ?, approver_ip = ?, decided_at = ?, expires_at = ?
            WHERE id = ? AND status = 'pending' AND expires_at > ?");
        $st->execute([(int) $approver['id'], AdminSession::stamp($approver), mb_substr($ip, 0, 45),
            $now, $now + self::PICKUP_TTL, $id, $now]);
        return $st->rowCount() === 1;
    }

    public function reject(int $id, array $approver, string $ip, ?int $now = null): bool
    {
        $now ??= time();
        $st = $this->pdo->prepare("UPDATE admin_quick_logins
            SET status = 'rejected', user_id = ?, approver_ip = ?, decided_at = ?
            WHERE id = ? AND status = 'pending' AND expires_at > ?");
        $st->execute([(int) $approver['id'], mb_substr($ip, 0, 45), $now, $id, $now]);
        return $st->rowCount() === 1;
    }

    /**
     * Komputer pyta o wynik. Przy zatwierdzeniu prośba zostaje zużyta, a zwrócone konto
     * można od razu zalogować.
     * @return array{status:string, user:array<string,mixed>|null}
     */
    public function poll(int $id, string $secret, ?int $now = null): array
    {
        $now ??= time();
        $st = $this->pdo->prepare('SELECT * FROM admin_quick_logins WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || !hash_equals((string) $row['browser_hash'], hash('sha256', $secret))) {
            return ['status' => 'expired', 'user' => null];
        }
        if ($row['status'] === 'rejected') {
            return ['status' => 'rejected', 'user' => null];
        }
        if ((int) $row['expires_at'] <= $now || !in_array($row['status'], ['pending', 'approved'], true)) {
            return ['status' => 'expired', 'user' => null];
        }
        if ($row['status'] === 'pending') {
            return ['status' => 'pending', 'user' => null];
        }
        $use = $this->pdo->prepare("UPDATE admin_quick_logins SET status = 'used', used_at = ?
            WHERE id = ? AND status = 'approved' AND expires_at > ?");
        $use->execute([$now, $id, $now]);
        if ($use->rowCount() !== 1) {
            return ['status' => 'expired', 'user' => null];
        }
        $st = $this->pdo->prepare('SELECT * FROM admin_users WHERE id = ?');
        $st->execute([(int) $row['user_id']]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if (!$user || (int) $user['is_active'] !== 1
            || !hash_equals(AdminSession::stamp($user), (string) $row['user_stamp'])) {
            $this->pdo->prepare("UPDATE admin_quick_logins SET status = 'revoked' WHERE id = ?")->execute([$id]);
            return ['status' => 'expired', 'user' => null];
        }
        return ['status' => 'approved', 'user' => $user];
    }

    /** Ostatnie zdarzenia konta (zatwierdzone, odrzucone, użyte). @return list<array<string,mixed>> */
    public function recent(int $userId, int $limit = 10): array
    {
        $st = $this->pdo->prepare('SELECT status, request_ip, request_agent, approver_ip, decided_at, used_at
            FROM admin_quick_logins WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)));
        $st->execute([$userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** „Chrome, Windows” - wystarczy, żeby rozpoznać swoje urządzenie. */
    public static function describeAgent(string $ua): string
    {
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Nieznana przeglądarka',
        };
        $system = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'nieznany system',
        };
        return $browser . ', ' . $system;
    }

    /** E-mail „zalogowano na nowym urządzeniu”. Błąd wysyłki nie blokuje logowania. */
    public function notify(array $user, string $ip, string $userAgent): void
    {
        if (trim((string) ($user['email'] ?? '')) === '') {
            return;
        }
        try {
            $mailer = new Mailer((new MailAccounts($this->pdo))->mailerConfig(null));
            if (!$mailer->isConfigured()) {
                return;
            }
            $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = '<p>Dzień dobry ' . $e((string) ($user['display_name'] ?: $user['username'])) . ',</p>'
                . '<p>na koncie <strong>' . $e((string) $user['username']) . '</strong> zalogowano nowe urządzenie przez szybkie logowanie innym urządzeniem.</p>'
                . '<p>Urządzenie: ' . $e(self::describeAgent($userAgent)) . '<br>Adres IP: ' . $e($ip)
                . '<br>Czas: ' . $e(date('Y-m-d H:i')) . '</p>'
                . '<p>Jeśli to nie Ty, od razu zmień hasło w panelu. Zmiana hasła wyloguje wszystkie urządzenia.</p>';
            $mailer->send((string) $user['email'], 'Nowe logowanie do panelu', $html, ['type' => 'quick_login']);
        } catch (\Throwable $e) {
            Logger::warn('Szybkie logowanie: nie wysłano powiadomienia', ['user_id' => (int) $user['id']]);
        }
    }
}
