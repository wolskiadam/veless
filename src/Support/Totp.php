<?php
declare(strict_types=1);

namespace Pase\Support;

/** RFC 6238 / Google Authenticator: SHA-1, six digits, 30-second period. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return self::encode(random_bytes(20));
    }

    public static function encode(string $bytes): string
    {
        $buffer = 0; $bits = 0; $out = '';
        foreach (unpack('C*', $bytes) as $byte) {
            $buffer = ($buffer << 8) | $byte;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::ALPHABET[($buffer >> $bits) & 31];
            }
            $buffer &= (1 << $bits) - 1;
        }
        if ($bits > 0) {
            $out .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        }
        return $out;
    }

    private static function decode(string $secret): string
    {
        if (!preg_match('/\A[A-Z2-7]+\z/D', $secret)) {
            throw new \InvalidArgumentException('Invalid TOTP secret');
        }
        $buffer = 0; $bits = 0; $out = '';
        foreach (str_split($secret) as $character) {
            $buffer = ($buffer << 5) | strpos(self::ALPHABET, $character);
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buffer >> $bits) & 255);
            }
            $buffer &= (1 << $bits) - 1;
        }
        return $out;
    }

    public static function code(string $secret, int $step, int $digits = 6): string
    {
        if ($step < 0 || !in_array($digits, [6, 8], true)) {
            throw new \InvalidArgumentException('Invalid TOTP parameters');
        }
        $counter = pack('N2', intdiv($step, 4294967296), $step % 4294967296);
        $hash = hash_hmac('sha1', $counter, self::decode($secret), true);
        $offset = ord($hash[19]) & 15;
        $number = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
        return str_pad((string) ($number % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /** Returns the matched time step. Caller must atomically persist it to stop replay. */
    public static function match(string $secret, string $code, int $lastStep = -1, ?int $now = null): ?int
    {
        if (!preg_match('/\A[0-9]{6}\z/D', $code)) {
            return null;
        }
        $step = intdiv($now ?? time(), 30);
        foreach ([$step, $step - 1, $step + 1] as $candidate) {
            if ($candidate >= 0 && $candidate > $lastStep && hash_equals(self::code($secret, $candidate), $code)) {
                return $candidate;
            }
        }
        return null;
    }

    public static function uri(string $secret, string $account, string $issuer = 'CRM'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?' . http_build_query(['secret' => $secret, 'issuer' => $issuer,
                'algorithm' => 'SHA1', 'digits' => 6, 'period' => 30], '', '&', PHP_QUERY_RFC3986);
    }
}
