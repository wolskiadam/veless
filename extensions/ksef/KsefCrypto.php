<?php
declare(strict_types=1);

namespace PaseExt\Ksef;

/**
 * Szyfrowanie wymagane przez KSeF API 2.0:
 *  - RSAES-OAEP z SHA-256 i MGF1-SHA-256 (token KSeF i klucz sesji) - kluczem publicznym MF z certyfikatu,
 *  - AES-256-CBC z dopełnieniem PKCS#7 (treść faktury),
 *  - skróty SHA-256 w Base64 (faktura przed i po zaszyfrowaniu) i Base64URL (link weryfikacyjny KOD I).
 *
 * openssl_public_encrypt() w PHP zna tylko OAEP z SHA-1, więc dopełnienie OAEP (RFC 8017, 7.1.1)
 * liczymy sami, a OpenSSL robi samo potęgowanie RSA (OPENSSL_NO_PADDING).
 */
final class KsefCrypto
{
    /** Certyfikat X.509 z KSeF (DER w Base64, bez nagłówków) -> klucz publiczny. */
    public static function publicKey(string $certificateBase64): \OpenSSLAsymmetricKey
    {
        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(trim($certificateBase64), 64, "\n") . "-----END CERTIFICATE-----\n";
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new \RuntimeException('KSeF: nie udało się odczytać klucza publicznego Ministerstwa Finansów.');
        }
        return $key;
    }

    /** RSAES-OAEP (SHA-256, MGF1-SHA-256, pusta etykieta). Zwraca szyfrogram (binarnie). */
    public static function rsaOaepSha256(string $message, \OpenSSLAsymmetricKey $key, ?string $seed = null): string
    {
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new \RuntimeException('KSeF: klucz publiczny MF nie jest kluczem RSA.');
        }
        $k = intdiv((int) $details['bits'] + 7, 8);
        $hLen = 32;
        if (strlen($message) > $k - 2 * $hLen - 2) {
            throw new \RuntimeException('KSeF: dane za długie do zaszyfrowania kluczem RSA.');
        }
        $lHash = hash('sha256', '', true);
        $db = $lHash . str_repeat("\0", $k - strlen($message) - 2 * $hLen - 2) . "\x01" . $message;
        $seed ??= random_bytes($hLen);
        $maskedDb = $db ^ self::mgf1($seed, $k - $hLen - 1);
        $maskedSeed = $seed ^ self::mgf1($maskedDb, $hLen);
        $em = "\0" . $maskedSeed . $maskedDb;
        if (!openssl_public_encrypt($em, $out, $key, OPENSSL_NO_PADDING)) {
            throw new \RuntimeException('KSeF: szyfrowanie RSA nie powiodło się (' . (string) openssl_error_string() . ').');
        }
        return $out;
    }

    /** MGF1 z SHA-256 (RFC 8017, B.2.1). */
    public static function mgf1(string $seed, int $length): string
    {
        $out = '';
        for ($i = 0; strlen($out) < $length; $i++) {
            $out .= hash('sha256', $seed . pack('N', $i), true);
        }
        return substr($out, 0, $length);
    }

    /** AES-256-CBC, PKCS#7. */
    public static function aesEncrypt(string $data, string $key, string $iv): string
    {
        $out = openssl_encrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($out === false) {
            throw new \RuntimeException('KSeF: szyfrowanie AES nie powiodło się.');
        }
        return $out;
    }

    public static function sha256(string $data): string
    {
        return base64_encode(hash('sha256', $data, true));
    }

    public static function sha256Url(string $data): string
    {
        return rtrim(strtr(self::sha256($data), '+/', '-_'), '=');
    }
}
