<?php
declare(strict_types=1);

namespace Pase\Support;

/** Authenticated encryption; the key is separate from the database and backups. */
final class TwoFactorCipher
{
    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== 32) {
            throw new \RuntimeException('Nieprawidłowy klucz szyfrowania 2FA.');
        }
    }

    public static function fromFile(string $path, bool $create = false): self
    {
        if ($create && !is_file($path)) {
            $dir = dirname($path);
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException('Nie można utworzyć katalogu klucza 2FA.');
            }
            $oldMask = umask(0077);
            try {
                $handle = @fopen($path, 'x');
                if ($handle !== false) {
                    try {
                        $key = base64_encode(random_bytes(32));
                        if (fwrite($handle, $key) !== strlen($key) || !fflush($handle)) {
                            throw new \RuntimeException('Nie można zapisać klucza 2FA.');
                        }
                    } finally {
                        fclose($handle);
                    }
                }
            } finally {
                umask($oldMask);
            }
        }
        $encoded = @file_get_contents($path);
        $key = $encoded === false ? false : base64_decode(trim($encoded), true);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('Brak lub uszkodzony klucz 2FA. Przywróć go z bezpiecznej kopii zapasowej.');
        }
        return new self($key);
    }

    public function encrypt(string $secret, int $userId): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($secret, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, 'crm-totp:' . $userId, 16);
        if ($encrypted === false) {
            throw new \RuntimeException('Nie można zaszyfrować sekretu 2FA.');
        }
        return 'v1:' . base64_encode($iv . $tag . $encrypted);
    }

    public function decrypt(string $value, int $userId): string
    {
        $raw = str_starts_with($value, 'v1:') ? base64_decode(substr($value, 3), true) : false;
        if ($raw === false || strlen($raw) < 29) {
            throw new \RuntimeException('Nieprawidłowe dane 2FA.');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA,
            substr($raw, 0, 12), substr($raw, 12, 16), 'crm-totp:' . $userId);
        if ($plain === false) {
            throw new \RuntimeException('Nie można odszyfrować danych 2FA.');
        }
        return $plain;
    }
}
