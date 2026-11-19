<?php
declare(strict_types=1);

namespace Pase\Support;

/**
 * Podpis paczek wydania (Ed25519, libsodium).
 *
 * cli/build_release.php podpisuje package.json kluczem prywatnym Klubu (sekret VELESS_SIGNING_KEY w GitHub Actions)
 * i dokłada do paczki plik package.sig. Manifest zawiera sumy SHA-256 wszystkich plików, więc podpis manifestu
 * chroni całą paczkę. Aktualizacje pobierane z serwera Veless instalują się tylko z poprawnym podpisem.
 *
 * Klucz publiczny jest wpisany niżej. Dodatkowe klucze (np. do testów albo przy wymianie klucza)
 * można dopisać w .env: RELEASE_PUBLIC_KEYS="klucz1,klucz2" (base64).
 */
final class ReleaseSignature
{
    /**
     * Klucze publiczne wydań Veless (base64). Parę kluczy tworzy `php cli/release_keygen.php` na komputerze
     * właściciela: klucz publiczny wpisuje się tutaj, prywatny tylko do sekretu VELESS_SIGNING_KEY w GitHubie.
     * Dopóki lista jest pusta, aktualizacje z serwera się nie instalują (ręczne wgranie paczki działa).
     */
    public const PUBLIC_KEYS = [
        '7QCi9kMZK601XWGjunypkCGjvlsNTLGYtTMhPOlHciM=', // Klub Woskarzy, 2026-11-19
    ];

    public static function available(): bool
    {
        return function_exists('sodium_crypto_sign_verify_detached');
    }

    /** @return list<string> klucze publiczne (surowe, 32 bajty) */
    public static function trustedKeys(): array
    {
        $all = self::PUBLIC_KEYS;
        foreach (explode(',', (string) Env::get('RELEASE_PUBLIC_KEYS', '')) as $k) {
            if (trim($k) !== '') { $all[] = trim($k); }
        }
        $out = [];
        foreach ($all as $b64) {
            $raw = base64_decode($b64, true);
            if ($raw !== false && strlen($raw) === 32) { $out[] = $raw; }
        }
        return $out;
    }

    /** true = podpis pasuje do jednego z zaufanych kluczy. */
    public static function verify(string $manifestRaw, string $signatureB64): bool
    {
        if (!self::available()) { return false; }
        $sig = base64_decode(trim($signatureB64), true);
        if ($sig === false || strlen($sig) !== 64) { return false; }
        foreach (self::trustedKeys() as $key) {
            try {
                if (sodium_crypto_sign_verify_detached($sig, $manifestRaw, $key)) { return true; }
            } catch (\Throwable) {}
        }
        return false;
    }

    /** Podpis (dla cli/build_release.php). $secretKeyB64 = 64 bajty klucza Ed25519 w base64. */
    public static function sign(string $manifestRaw, string $secretKeyB64): string
    {
        $sk = base64_decode(trim($secretKeyB64), true);
        if ($sk === false || strlen($sk) !== 64) {
            throw new \RuntimeException('Klucz podpisu musi być kluczem prywatnym Ed25519 (64 bajty, base64).');
        }
        return base64_encode(sodium_crypto_sign_detached($manifestRaw, $sk));
    }
}
