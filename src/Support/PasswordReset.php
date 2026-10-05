<?php
declare(strict_types=1);

namespace Pase\Support;

use PDO;
use Pase\Repository\SettingsRepository;
use Pase\Services\MailAccounts;
use Pase\Services\Mailer;

/**
 * Reset hasła do panelu przez e-mail.
 *
 * - Link jest jednorazowy i ważny 60 minut. W bazie trzymamy tylko skrót (sha256) tokenu,
 *   więc wyciek bazy nie daje działających linków.
 * - Odpowiedź na formularz „Nie pamiętasz hasła?" jest zawsze taka sama - nie zdradza,
 *   czy dany login lub e-mail istnieje.
 * - Nowe hasło podbija session_version: wszystkie inne zalogowane sesje tego konta wygasają.
 * - Adres w linku bierzemy z ustawienia APP_BASE_URL, a nie z nagłówka Host żądania -
 *   inaczej ktoś mógłby podstawić własną domenę i przechwycić token.
 */
final class PasswordReset
{
    public const TTL = 3600;
    public const MIN_LENGTH = 8;

    public function __construct(private readonly PDO $pdo)
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_password_resets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at INT UNSIGNED NOT NULL,
            used_at INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_token (token_hash),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /**
     * Wysyła link, jeśli login albo e-mail pasuje do aktywnego konta z adresem e-mail.
     * Zwraca, czy coś wysłano (tylko do logów - użytkownik zawsze widzi ten sam komunikat).
     */
    public function request(string $loginOrEmail): bool
    {
        $loginOrEmail = trim($loginOrEmail);
        if ($loginOrEmail === '') {
            return false;
        }
        $st = $this->pdo->prepare('SELECT * FROM admin_users WHERE (username = ? OR email = ?) AND is_active = 1 AND email IS NOT NULL AND email <> "" LIMIT 1');
        $st->execute([$loginOrEmail, $loginOrEmail]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            Logger::info('Reset hasła: brak aktywnego konta z e-mailem dla podanych danych');
            return false;
        }

        $link = $this->baseUrl();
        if ($link === null) {
            Logger::warn('Reset hasła: brak APP_BASE_URL - nie da się zbudować linku');
            return false;
        }

        // Stare, niewykorzystane linki tego konta przestają działać.
        $this->pdo->prepare('UPDATE admin_password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL')
            ->execute([time(), (int) $user['id']]);

        $token = bin2hex(random_bytes(32));
        $this->pdo->prepare('INSERT INTO admin_password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)')
            ->execute([(int) $user['id'], hash('sha256', $token), time() + self::TTL]);

        $url  = $link . '/admin/reset_password.php?token=' . $token;
        $name = htmlspecialchars((string) ($user['display_name'] ?: $user['username']));
        $html = '<p>Dzień dobry ' . $name . ',</p>'
              . '<p>ktoś (prawdopodobnie Ty) poprosił o ustawienie nowego hasła do panelu dla konta <strong>'
              . htmlspecialchars((string) $user['username']) . '</strong>.</p>'
              . '<p><a href="' . htmlspecialchars($url) . '" style="display:inline-block;background:#9c6b2e;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:bold">Ustaw nowe hasło</a></p>'
              . '<p>Link działa przez 60 minut i tylko raz. Jeśli to nie Ty — zignoruj tę wiadomość, hasło się nie zmieni.</p>'
              . '<p style="color:#888;font-size:12px">Jeśli przycisk nie działa, skopiuj adres: ' . htmlspecialchars($url) . '</p>';

        $mailer = new Mailer((new MailAccounts($this->pdo))->mailerConfig(null));
        if (!$mailer->isConfigured()) {
            Logger::warn('Reset hasła: nie skonfigurowano wysyłki e-mail');
            return false;
        }
        $r = $mailer->send((string) $user['email'], 'Ustaw nowe hasło do panelu', $html, ['type' => 'password_reset']);
        $ok = (bool) ($r['ok'] ?? $r[0] ?? false);
        Logger::info('Reset hasła: wysłano link', ['user_id' => (int) $user['id'], 'ok' => $ok]);
        return $ok;
    }

    /** Konto dla ważnego tokenu albo null. @return array<string,mixed>|null */
    public function userForToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $st = $this->pdo->prepare('SELECT u.*, r.id AS reset_id FROM admin_password_resets r
                                   JOIN admin_users u ON u.id = r.user_id
                                   WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > ? AND u.is_active = 1 LIMIT 1');
        $st->execute([hash('sha256', $token), time()]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Ustawia nowe hasło i zużywa token. Zwraca komunikat błędu albo null przy sukcesie. */
    public function reset(string $token, string $password, string $repeat): ?string
    {
        $user = $this->userForToken($token);
        if ($user === null) {
            return 'Link wygasł albo został już użyty. Poproś o nowy.';
        }
        if (strlen($password) < self::MIN_LENGTH) {
            return 'Hasło musi mieć co najmniej ' . self::MIN_LENGTH . ' znaków.';
        }
        if (!hash_equals($password, $repeat)) {
            return 'Hasła nie są takie same.';
        }
        // Zużycie tokenu warunkowe (used_at IS NULL) - dwa równoległe żądania nie użyją go dwa razy.
        $use = $this->pdo->prepare('UPDATE admin_password_resets SET used_at = ? WHERE id = ? AND used_at IS NULL');
        $use->execute([time(), (int) $user['reset_id']]);
        if ($use->rowCount() !== 1) {
            return 'Link wygasł albo został już użyty. Poproś o nowy.';
        }
        $this->pdo->prepare('UPDATE admin_users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
        Logger::info('Reset hasła: ustawiono nowe hasło', ['user_id' => (int) $user['id']]);
        return null;
    }

    /** Adres katalogu public/ (jak w linkach dla klientów). */
    private function baseUrl(): ?string
    {
        $base = rtrim((string) ((new SettingsRepository($this->pdo))->get('APP_BASE_URL', '') ?? ''), '/');
        return preg_match('#^https?://#i', $base) ? $base : null;
    }
}
