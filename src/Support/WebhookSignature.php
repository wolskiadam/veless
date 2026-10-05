<?php
declare(strict_types=1);

namespace Pase\Support;

final class WebhookSignature
{
    public static function valid(string $body, mixed $secret, mixed $signature): bool
    {
        return is_string($secret) && trim($secret) !== '' && is_string($signature)
            && hash_equals(base64_encode(hash_hmac('sha256', $body, $secret, true)), $signature);
    }

    /**
     * Podpis powiadomienia PayU: nagłówek OpenPayu-Signature
     * "sender=checkout;signature=...;algorithm=MD5;content=DOCUMENT", podpis = hash(treść + drugi klucz (MD5)).
     * @param list<string> $secondKeys drugie klucze skonfigurowanych punktów płatności (wystarczy zgodność z jednym)
     */
    public static function payuValid(string $body, mixed $header, array $secondKeys): bool
    {
        if (!is_string($header) || $header === '') {
            return false;
        }
        $parts = [];
        foreach (explode(';', $header) as $pair) {
            [$k, $v] = array_map('trim', explode('=', $pair, 2) + [1 => '']);
            $parts[strtolower($k)] = $v;
        }
        $algo = match (strtoupper(str_replace('-', '', $parts['algorithm'] ?? 'MD5'))) {
            'MD5' => 'md5', 'SHA', 'SHA1' => 'sha1', 'SHA256' => 'sha256', 'SHA384' => 'sha384', 'SHA512' => 'sha512',
            default => null,
        };
        $sent = strtolower($parts['signature'] ?? '');
        if ($algo === null || $sent === '') {
            return false;
        }
        foreach ($secondKeys as $key) {
            if (is_string($key) && trim($key) !== '' && hash_equals(hash($algo, $body . trim($key)), $sent)) {
                return true;
            }
        }
        return false;
    }
}
