<?php
declare(strict_types=1);

namespace Pase\Support;

use PDO;

/**
 * Szyfrowanie sekretów w bazie: tokeny OAuth, klucze API, hasła SMTP.
 *
 * AES-256-GCM, a klucz to podklucz (HKDF) klucza 2FA z storage/security/totp.key
 * (albo TOTP_KEY_FILE). Ten plik już teraz jedzie z każdą kopią, pakietem
 * przeniesienia i instalatorem, więc zaszyfrowane wartości odtwarzają się razem z bazą,
 * a sam zrzut bazy (wyciek, phpMyAdmin, eksport) nie zdradza tokenów.
 *
 * Wartość zaszyfrowana ma przedrostek "enc:v1:". Wartości bez niego (zapisane przed
 * tą zmianą, przywrócone z kopii) są czytane jak dotąd i szyfrowane przez sealAll().
 * Bez pliku klucza zapis zostaje jawny (jak przed zmianą) - nigdy nie blokuje pracy.
 * Klucz tworzy wyłącznie sealAll(), i to tylko gdy w bazie nie ma nic zaszyfrowanego.
 */
final class SecretStore
{
    public const PREFIX = 'enc:v1:';

    /** Kolumny z sekretami w tabelach (poza config i settings, które mają osobne reguły). */
    public const COLUMNS = [
        'integrations'         => ['access_token', 'refresh_token', 'webhook_secret'],
        'integration_accounts' => ['access_token', 'refresh_token', 'webhook_secret'],
        'mail_accounts'        => ['pass'],
    ];

    private static ?string $key = null;
    private static bool $loaded = false;
    private static ?string $keyFile = null;
    private static bool $warned = false;

    /** Ścieżka pliku klucza (testy podmieniają ją przez useKeyFile()). */
    public static function keyFile(): string
    {
        if (self::$keyFile !== null) {
            return self::$keyFile;
        }
        $root = defined('PASE_ROOT') ? PASE_ROOT : dirname(__DIR__, 2);
        return (string) Env::get('TOTP_KEY_FILE', $root . '/storage/security/totp.key');
    }

    public static function useKeyFile(?string $path): void
    {
        self::$keyFile = $path;
        self::$key = null;
        self::$loaded = false;
    }

    public static function available(): bool
    {
        return self::key() !== null;
    }

    public static function isSealed(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    /** Szyfruje wartość. Pusta lub już zaszyfrowana wraca bez zmian; bez klucza - jawna. */
    public static function seal(?string $value, string $context): ?string
    {
        if ($value === null || $value === '' || self::isSealed($value)) {
            return $value;
        }
        $key = self::key();
        if ($key === null) {
            if (!self::$warned) {
                self::$warned = true;
                Logger::warn('SecretStore: brak klucza ' . self::keyFile() . ' - sekret zapisany bez szyfrowania');
            }
            return $value;
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'crm-secret:' . $context, 16);
        if ($cipher === false) {
            throw new \RuntimeException('Nie można zaszyfrować sekretu.');
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /** Odszyfrowuje wartość. Jawna (stara) wraca bez zmian. */
    public static function open(?string $value, string $context): ?string
    {
        if (!self::isSealed($value)) {
            return $value;
        }
        $key = self::key();
        if ($key === null) {
            throw new \RuntimeException('Brak klucza szyfrowania (' . self::keyFile() . '). Przywróć plik klucza 2FA z kopii zapasowej - bez niego zapisane tokeny i klucze API są nieczytelne.');
        }
        $raw = base64_decode(substr((string) $value, strlen(self::PREFIX)), true);
        $plain = $raw === false || strlen($raw) < 29 ? false
            : openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'crm-secret:' . $context);
        if ($plain === false) {
            throw new \RuntimeException('Nie można odszyfrować sekretu (' . $context . '): inny klucz albo uszkodzone dane.');
        }
        return $plain;
    }

    /**
     * Jak open(), ale brak klucza lub uszkodzona wartość nie wywraca strony: wpis w logu i pusta wartość
     * (panel działa, integracja zgłosi brak klucza API). Do odczytów w repozytoriach.
     */
    public static function reveal(?string $value, string $context): ?string
    {
        try {
            return self::open($value, $context);
        } catch (\RuntimeException $e) {
            Logger::error('SecretStore: ' . $e->getMessage());
            return '';
        }
    }

    // ---------------------------------------------------------------- reguły: które pola są sekretami

    /** Klucz w config konta integracji (consumer_secret, api_key, token, password...). */
    public static function isSecretConfigKey(string $key): bool
    {
        return preg_match('/(^|_)(secret|password|pass|token|key)$/i', $key) === 1;
    }

    /** Klucz w tabeli settings (WFIRMA_SECRET_KEY, WOO_CONSUMER_SECRET, MAIL_SMTP_PASS...). */
    public static function isSecretSettingKey(string $key): bool
    {
        return preg_match('/(SECRET|PASSWORD|_PASS|TOKEN|_KEY)$/', $key) === 1;
    }

    /** @param array<string,mixed> $config */
    public static function sealConfig(array $config): array
    {
        foreach ($config as $k => $v) {
            if (is_string($v) && self::isSecretConfigKey((string) $k)) {
                $config[$k] = self::seal($v, 'config.' . $k);
            }
        }
        return $config;
    }

    /** @param array<string,mixed> $config */
    public static function openConfig(array $config, bool $strict = true): array
    {
        foreach ($config as $k => $v) {
            if (self::isSealed($v)) {
                $config[$k] = $strict ? self::open($v, 'config.' . $k) : self::reveal($v, 'config.' . $k);
            }
        }
        return $config;
    }

    public static function sealSetting(string $key, ?string $value): ?string
    {
        return self::isSecretSettingKey($key) ? self::seal($value, 'settings.' . $key) : $value;
    }

    public static function openSetting(string $key, ?string $value, bool $strict = true): ?string
    {
        if (!self::isSealed($value)) {
            return $value;
        }
        return $strict ? self::open($value, 'settings.' . $key) : self::reveal($value, 'settings.' . $key);
    }

    /**
     * Cały wiersz tabeli (kopie sekcji: eksport odszyfrowuje do archiwum chronionego hasłem,
     * przywracanie szyfruje kluczem tej instalacji).
     * @param array<string,mixed> $row
     */
    public static function sealRow(string $table, array $row): array
    {
        return self::mapRow($table, $row, true);
    }

    /**
     * Odczyt wiersza: kolumny z sekretami i config odszyfrowane. Nie-strict = jak reveal().
     * @param array<string,mixed> $row
     */
    public static function openRow(string $table, array $row, bool $strict = true): array
    {
        return self::mapRow($table, $row, false, $strict);
    }

    /** @param array<string,mixed> $row */
    private static function mapRow(string $table, array $row, bool $seal, bool $strict = true): array
    {
        foreach (self::COLUMNS[$table] ?? [] as $col) {
            if (isset($row[$col]) && is_string($row[$col])) {
                $ctx = $table . '.' . $col;
                $row[$col] = $seal ? self::seal($row[$col], $ctx) : ($strict ? self::open($row[$col], $ctx) : self::reveal($row[$col], $ctx));
            }
        }
        if ($table === 'integration_accounts' && isset($row['config']) && is_string($row['config'])) {
            $cfg = json_decode($row['config'], true);
            if (is_array($cfg)) {
                $new = $seal ? self::sealConfig($cfg) : self::openConfig($cfg, $strict);
                if ($new !== $cfg) {
                    $row['config'] = json_encode($new, JSON_UNESCAPED_UNICODE);
                }
            }
        }
        if ($table === 'settings' && isset($row['setting_key'], $row['setting_value']) && is_string($row['setting_value'])) {
            $row['setting_value'] = $seal ? self::sealSetting((string) $row['setting_key'], $row['setting_value'])
                : self::openSetting((string) $row['setting_key'], $row['setting_value'], $strict);
        }
        return $row;
    }

    /**
     * Szyfruje wszystkie jeszcze jawne sekrety w bazie (migracja, po przywróceniu kopii).
     * Tworzy klucz, jeśli go nie ma, ale tylko gdy nic w bazie nie zależy od starego klucza.
     * Zwraca liczbę zaszyfrowanych wierszy.
     */
    public static function sealAll(PDO $pdo): int
    {
        if (!self::ensureKey($pdo)) {
            return 0;
        }
        $done = 0;
        foreach (array_keys(self::COLUMNS) + [99 => 'settings'] as $table) {
            try {
                $rows = $pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable) {
                continue; // tabela jeszcze nie istnieje
            }
            $id = $table === 'settings' ? 'setting_key' : 'id';
            foreach ($rows as $row) {
                if (!array_key_exists($id, $row)) {
                    continue;
                }
                $sealed = self::sealRow($table, $row);
                $changed = array_diff_assoc(array_map('strval', array_filter($sealed, 'is_string')), array_map('strval', array_filter($row, 'is_string')));
                if ($changed === []) {
                    continue;
                }
                $sets = implode(', ', array_map(static fn($c) => $c . ' = ?', array_keys($changed)));
                $pdo->prepare("UPDATE {$table} SET {$sets} WHERE {$id} = ?")->execute([...array_values($changed), $row[$id]]);
                $done++;
            }
        }
        if ($done > 0) {
            Logger::info("SecretStore: zaszyfrowano sekrety w {$done} wierszach");
        }
        return $done;
    }

    private static function ensureKey(PDO $pdo): bool
    {
        if (self::available()) {
            return true;
        }
        $path = self::keyFile();
        if (is_file($path)) {
            return false; // plik jest, ale nieczytelny/uszkodzony - nie nadpisujemy
        }
        // Nowy klucz unieważniłby istniejące dane 2FA i zaszyfrowane sekrety - wtedy tylko odtworzenie z kopii.
        try {
            if ((int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE totp_secret IS NOT NULL AND totp_secret <> ''")->fetchColumn() > 0) {
                return false;
            }
        } catch (\Throwable) {
            // brak tabeli kont - nic nie zależy od klucza
        }
        foreach (array_keys(self::COLUMNS) + [99 => 'settings'] as $table) {
            try {
                foreach ($pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    foreach ($row as $v) {
                        if (is_string($v) && str_contains($v, self::PREFIX)) {
                            return false;
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }
        try {
            TwoFactorCipher::fromFile($path, true);
        } catch (\Throwable $e) {
            Logger::warn('SecretStore: nie można utworzyć klucza: ' . $e->getMessage());
            return false;
        }
        self::$loaded = false;
        return self::available();
    }

    private static function key(): ?string
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $encoded = @file_get_contents(self::keyFile());
            $master = $encoded === false ? false : base64_decode(trim($encoded), true);
            self::$key = $master !== false && strlen($master) === 32
                ? hash_hkdf('sha256', $master, 32, 'crm-secrets-v1')
                : null;
        }
        return self::$key;
    }
}
