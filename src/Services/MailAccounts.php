<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Repository\SettingsRepository;
use Pase\Support\SecretStore;

/**
 * Konta nadawcy e-mail (SMTP) - po jednym na sklep / markę.
 *
 * Z którego konta wychodzi wiadomość do klienta (kolejność):
 *   1. konto wybrane w szablonie e-mail („Wyślij z"),
 *   2. konto przypisane do źródła zamówienia (sklep Woo / konto Allegro) - Konfiguracja → E-mail,
 *   3. konto domyślne,
 *   4. (zgodność wstecz) stare ustawienia MAIL_* z tabeli settings.
 * Nazwa nadawcy konta jest też „nazwą sklepu" ({{shop_name}}, strona klienta, wydruki).
 */
final class MailAccounts
{
    /** Przypisania źródło zamówienia (id integracji) => id konta e-mail. */
    public const SOURCE_MAP_KEY = 'MAIL_ACCOUNT_BY_SOURCE';

    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        try {
            return array_map(static fn(array $a): array => SecretStore::openRow('mail_accounts', $a, false),
                $this->pdo->query('SELECT * FROM mail_accounts ORDER BY is_default DESC, name ASC')->fetchAll(PDO::FETCH_ASSOC));
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $st = $this->pdo->prepare('SELECT * FROM mail_accounts WHERE id = ?');
            $st->execute([$id]);
            $a = $st->fetch(PDO::FETCH_ASSOC);
            return $a ? SecretStore::openRow('mail_accounts', $a, false) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function defaultAccount(): ?array
    {
        foreach ($this->all() as $a) {
            if ((int) $a['is_default'] === 1) {
                return $a;
            }
        }
        return $this->all()[0] ?? null;
    }

    /**
     * Zapis konta. Puste hasło przy edycji = bez zmian. Zwraca id.
     * @param array<string,string> $d
     */
    public function save(?int $id, array $d): int
    {
        $f = [
            'name'       => trim($d['name'] ?? '') ?: (trim($d['from_name'] ?? '') ?: 'Konto e-mail'),
            'from_email' => trim($d['from_email'] ?? ''),
            'from_name'  => trim($d['from_name'] ?? ''),
            'reply_to'   => trim($d['reply_to'] ?? '') ?: null,
            'host'       => trim($d['host'] ?? ''),
            'port'       => trim($d['port'] ?? '') ?: '587',
            'user'       => trim($d['user'] ?? ''),
            'secure'     => in_array($d['secure'] ?? 'tls', ['tls', 'ssl', 'none'], true) ? (string) ($d['secure'] ?? 'tls') : 'tls',
        ];
        if (array_key_exists('review_link', $d)) {
            $f['review_link'] = trim((string) $d['review_link']) ?: null;
        }
        if (($d['pass'] ?? '') !== '') {
            $f['pass'] = (string) SecretStore::seal((string) $d['pass'], 'mail_accounts.pass');
        }
        if ($id) {
            $sets = implode(', ', array_map(static fn($k) => "`{$k}` = ?", array_keys($f)));
            $this->pdo->prepare("UPDATE mail_accounts SET {$sets} WHERE id = ?")->execute([...array_values($f), $id]);
        } else {
            $f['pass'] = $f['pass'] ?? '';
            $f['is_default'] = $this->all() === [] ? 1 : 0;
            $cols = implode(', ', array_map(static fn($k) => "`{$k}`", array_keys($f)));
            $this->pdo->prepare("INSERT INTO mail_accounts ({$cols}) VALUES (" . implode(',', array_fill(0, count($f), '?')) . ')')
                ->execute(array_values($f));
            $id = (int) $this->pdo->lastInsertId();
        }
        $this->syncLegacy();
        return $id;
    }

    public function setDefault(int $id): void
    {
        $this->pdo->prepare('UPDATE mail_accounts SET is_default = (id = ?)')->execute([$id]);
        $this->syncLegacy();
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM mail_accounts WHERE id = ?')->execute([$id]);
        if ($this->defaultAccount() !== null && !array_filter($this->all(), static fn($a) => (int) $a['is_default'] === 1)) {
            $this->setDefault((int) $this->all()[0]['id']);
        }
        $map = array_filter($this->sourceMap(), static fn($v) => (int) $v !== $id);
        $this->setSourceMap($map);
        $this->pdo->prepare('UPDATE email_templates SET mail_account_id = NULL WHERE mail_account_id = ?')->execute([$id]);
        $this->syncLegacy();
    }

    /** @return array<string,int> id integracji => id konta */
    public function sourceMap(): array
    {
        $raw = (new SettingsRepository($this->pdo))->get(self::SOURCE_MAP_KEY, '') ?? '';
        $m = json_decode($raw, true);
        return is_array($m) ? array_map('intval', $m) : [];
    }

    /** @param array<string|int,int> $map */
    public function setSourceMap(array $map): void
    {
        $clean = [];
        foreach ($map as $k => $v) {
            if ((int) $v > 0) {
                $clean[(string) (int) $k] = (int) $v;
            }
        }
        (new SettingsRepository($this->pdo))->setMany([self::SOURCE_MAP_KEY => json_encode($clean)]);
    }

    /**
     * Konto dla zamówienia. $forcedId - konto wybrane w szablonie (0/null = automatycznie).
     * @return array<string,mixed>|null wiersz konta albo null (brak kont - użyj ustawień MAIL_*)
     */
    public function accountForOrder(?int $wooOrderId, ?int $forcedId = null): ?array
    {
        if ($forcedId && ($a = $this->find($forcedId)) !== null) {
            return $a;
        }
        if ($wooOrderId) {
            try {
                $st = $this->pdo->prepare('SELECT integration_id FROM woo_orders WHERE woo_order_id = ?');
                $st->execute([$wooOrderId]);
                $src = (int) ($st->fetchColumn() ?: 0);
            } catch (\Throwable $e) {
                $src = 0;
            }
            $mapped = $src ? ($this->sourceMap()[(string) $src] ?? 0) : 0;
            if ($mapped && ($a = $this->find($mapped)) !== null) {
                return $a;
            }
        }
        return $this->defaultAccount();
    }

    /** Konfiguracja dla Mailer. @return array<string,string> */
    public function mailerConfig(?int $wooOrderId, ?int $forcedId = null): array
    {
        $a = $this->accountForOrder($wooOrderId, $forcedId);
        if ($a !== null) {
            return self::toMailerConfig($a) + ['logo_url' => $this->logoUrl($a)];
        }
        $s = new SettingsRepository($this->pdo);
        return [
            'host'       => (string) ($s->get('MAIL_SMTP_HOST', '') ?? ''),
            'port'       => (string) ($s->get('MAIL_SMTP_PORT', '587') ?? '587'),
            'user'       => (string) ($s->get('MAIL_SMTP_USER', '') ?? ''),
            'pass'       => (string) ($s->get('MAIL_SMTP_PASS', '') ?? ''),
            'secure'     => (string) ($s->get('MAIL_SMTP_SECURE', 'tls') ?? 'tls'),
            'from_email' => (string) ($s->get('MAIL_FROM_EMAIL', '') ?? ''),
            'from_name'  => (string) ($s->get('MAIL_FROM_NAME', '') ?? ''),
        ];
    }

    /** @return array<string,string> */
    public static function toMailerConfig(array $a): array
    {
        return [
            'host' => (string) $a['host'], 'port' => (string) $a['port'], 'user' => (string) $a['user'],
            'pass' => (string) $a['pass'], 'secure' => (string) $a['secure'],
            'from_email' => (string) $a['from_email'], 'from_name' => (string) $a['from_name'],
            'reply_to' => (string) ($a['reply_to'] ?? ''),
            'review_link' => (string) ($a['review_link'] ?? ''),
        ];
    }

    public const LOGO_MAX_BYTES = 300 * 1024;
    private const LOGO_TYPES = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_GIF => 'image/gif'];

    /**
     * Logo sklepu (do maili, {{shop_logo}}) - trzymane w bazie, żeby przechodziło z kopią zapasową.
     * Tylko PNG / JPG / GIF (WebP i SVG nie wyświetlają się w części programów pocztowych).
     * @param array<string,mixed> $file element $_FILES
     */
    public function setLogo(int $id, array $file): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new \RuntimeException('Nie udało się wgrać logo.');
        }
        if ((int) ($file['size'] ?? 0) > self::LOGO_MAX_BYTES) {
            throw new \RuntimeException('Logo jest za duże (max 300 KB) - zmniejsz je, np. do 400 px szerokości.');
        }
        $info = @getimagesize((string) $file['tmp_name']);
        $mime = $info !== false ? (self::LOGO_TYPES[$info[2]] ?? null) : null;
        if ($mime === null) {
            throw new \RuntimeException('Logo musi być obrazkiem PNG, JPG albo GIF.');
        }
        $this->pdo->prepare('UPDATE mail_accounts SET logo_mime = ?, logo_data = ? WHERE id = ?')
            ->execute([$mime, base64_encode((string) file_get_contents((string) $file['tmp_name'])), $id]);
    }

    public function removeLogo(int $id): void
    {
        $this->pdo->prepare('UPDATE mail_accounts SET logo_mime = NULL, logo_data = NULL WHERE id = ?')->execute([$id]);
    }

    /** Publiczny adres logo (w mailu obrazek musi być pod adresem, nie w treści). '' gdy brak logo lub adresu CRM. */
    public function logoUrl(array $a): string
    {
        if (empty($a['logo_data']) || empty($a['id'])) {
            return '';
        }
        $base = rtrim((string) ((new SettingsRepository($this->pdo))->get('APP_BASE_URL', '') ?? ''), '/');
        return $base === '' ? '' : $base . '/shop_logo.php?a=' . (int) $a['id'] . '&v=' . substr(md5((string) $a['logo_data']), 0, 10);
    }

    /** Znacznik <img> logo do szablonu ({{shop_logo}}); '' gdy sklep nie ma logo. */
    public static function logoHtml(array $cfg): string
    {
        $url = (string) ($cfg['logo_url'] ?? '');
        if ($url === '') {
            return '';
        }
        return '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '" alt="' . htmlspecialchars((string) ($cfg['from_name'] ?? ''), ENT_QUOTES)
            . '" style="max-width:220px;max-height:90px;border:0;display:block">';
    }

    /** Nazwa sklepu do treści/stron dla zamówienia (nazwa nadawcy jego konta). */
    public function shopName(?int $wooOrderId, string $fallback = 'Sklep'): string
    {
        $cfg = $this->mailerConfig($wooOrderId);
        if ($cfg['from_name'] !== '') {
            return $cfg['from_name'];
        }
        // Bez nazwy nadawcy: nazwa sklepu (integracji), z którego jest zamówienie, np. „Mój Sklep".
        if ($wooOrderId) {
            try {
                $st = $this->pdo->prepare(
                    'SELECT ia.name FROM woo_orders o JOIN integration_accounts ia ON ia.id = o.integration_id WHERE o.woo_order_id = ?'
                );
                $st->execute([$wooOrderId]);
                $name = trim((string) ($st->fetchColumn() ?: ''));
                if ($name !== '') {
                    return $name;
                }
            } catch (\Throwable $e) {
                // brak tabeli / kolumny - zostaje fallback
            }
        }
        return $fallback;
    }

    /** Domyślne konto -> stare klucze MAIL_* (dla miejsc, które jeszcze je czytają). */
    private function syncLegacy(): void
    {
        $d = $this->defaultAccount();
        if ($d === null) {
            return;
        }
        (new SettingsRepository($this->pdo))->setMany([
            'MAIL_SMTP_HOST' => (string) $d['host'], 'MAIL_SMTP_PORT' => (string) $d['port'],
            'MAIL_SMTP_USER' => (string) $d['user'], 'MAIL_SMTP_PASS' => (string) $d['pass'],
            'MAIL_SMTP_SECURE' => (string) $d['secure'], 'MAIL_FROM_EMAIL' => (string) $d['from_email'],
            'MAIL_FROM_NAME' => (string) $d['from_name'],
        ]);
    }
}
