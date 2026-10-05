<?php
declare(strict_types=1);

namespace Pase\Services;

/**
 * Weryfikacja tożsamości klienta na publicznej stronie zamówienia.
 * Sam token nie wystarcza - klient potwierdza dodatkowo daną z zamówienia
 * (e-mail lub telefon). Porównanie jest odporne na drobne różnice formatowania
 * (wielkość liter, spacje, prefiks kraju w telefonie).
 */
final class ClientVerify
{
    /** Pola, którymi klient może się zweryfikować (etykiety do UI). */
    public const FIELDS = [
        'email' => 'Adres e-mail z zamówienia',
        'phone' => 'Numer telefonu z zamówienia',
    ];

    /**
     * Czy podana wartość zgadza się z danym polem zamówienia.
     * @param array $payload zdekodowany payload Woo (billing)
     */
    public static function matches(string $field, string $input, array $payload): bool
    {
        $input = trim($input);
        if ($input === '') {
            return false;
        }
        $billing = $payload['billing'] ?? [];

        return match ($field) {
            'email' => self::eq($input, (string) ($billing['email'] ?? '')),
            'phone' => self::phone($input) !== ''
                && self::phone($input) === self::phone((string) ($billing['phone'] ?? '')),
            default => false,
        };
    }

    /**
     * Czy podana wartość pasuje do KTÓREGOKOLWIEK pola weryfikacji (e-mail/telefon).
     * Klient wpisuje jedną informację, a system sam rozpoznaje, którą podał.
     */
    public static function matchesAny(string $input, array $payload): bool
    {
        foreach (['email', 'phone'] as $field) {
            if (self::matches($field, $input, $payload)) {
                return true;
            }
        }
        return false;
    }

    /** Które pola są w ogóle dostępne dla tego zamówienia (mają wartość). */
    public static function availableFields(array $payload): array
    {
        $billing = $payload['billing'] ?? [];
        $out = [];
        if (($billing['email'] ?? '') !== '') {
            $out['email'] = self::FIELDS['email'];
        }
        if (($billing['phone'] ?? '') !== '') {
            $out['phone'] = self::FIELDS['phone'];
        }
        return $out;
    }

    private static function eq(string $a, string $b): bool
    {
        return $b !== '' && mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }

    /** Telefon do porównania: same cyfry, bez prefiksu 48/0048/0. */
    private static function phone(string $s): string
    {
        $d = preg_replace('/\D+/', '', $s) ?? '';
        if (str_starts_with($d, '0048')) {
            $d = substr($d, 4);
        } elseif (str_starts_with($d, '48') && strlen($d) > 9) {
            $d = substr($d, 2);
        }
        $d = ltrim($d, '0');
        return $d;
    }
}
