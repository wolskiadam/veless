<?php
declare(strict_types=1);

namespace Pase\Services;

/**
 * Odczyt wątków i wiadomości Centrum wiadomości Allegro w wersji beta.v1 (i dawnej public.v1).
 * beta.v1 zamiast interlocutor / author.isInterlocutor podaje uczestników i autora z rolą
 * (BUYER, SELLER, a w zwykłych rozmowach USER) — tu ustalamy, kto jest kupującym, a kto nami.
 */
final class AllegroThreads
{
    /** Typ wątku „Problem z zakupem" (od 28.10.2026, tylko przez /messaging). */
    public const PROBLEM = 'POST_PURCHASE_ISSUE';

    /** Znane rodzaje problemu (subType); nieznany = '' (pełny wątek i tak jest w CRM). */
    public const SUBTYPE_LABELS = [
        'PRODUCT_INCONSISTENT_WITH_THE_OFFER' => 'Produkt niezgodny z ofertą',
    ];

    public static function isProblem(array $thread): bool
    {
        return strtoupper((string) ($thread['type'] ?? '')) === self::PROBLEM;
    }

    public static function isClosed(array $thread): bool
    {
        return strtoupper((string) ($thread['status'] ?? '')) === 'CLOSED';
    }

    public static function subTypeLabel(mixed $subType): string
    {
        $subType = is_string($subType) ? strtoupper(trim($subType)) : '';
        return self::SUBTYPE_LABELS[$subType] ?? '';
    }

    /** Login rozmówcy (kupującego) w wątku; $me = login naszego konta, gdy Allegro podaje same role USER. */
    public static function buyerLogin(array $thread, string $me = ''): string
    {
        $login = trim((string) ($thread['interlocutor']['login'] ?? ''));
        if ($login !== '') {
            return $login;
        }
        $people = array_values(array_filter((array) ($thread['participants'] ?? []), 'is_array'));
        foreach ($people as $p) {
            if (strtoupper((string) ($p['role'] ?? '')) === 'BUYER' && ($p['login'] ?? '') !== '') {
                return (string) $p['login'];
            }
        }
        foreach ($people as $p) {
            $l = (string) ($p['login'] ?? '');
            if ($l !== '' && strtoupper((string) ($p['role'] ?? '')) !== 'SELLER' && strcasecmp($l, $me) !== 0) {
                return $l;
            }
        }
        return '';
    }

    /** Czy wiadomość napisaliśmy my (sprzedawca). */
    public static function isMine(array $author, string $me = ''): bool
    {
        if (array_key_exists('isInterlocutor', $author)) {
            return !$author['isInterlocutor'];
        }
        $role = strtoupper((string) ($author['role'] ?? ''));
        if ($role === 'SELLER' || $role === 'FULFILLMENT') {
            return true;
        }
        if ($role === 'BUYER' || $role === 'ADMIN') {
            return false;
        }
        return $me !== '' && strcasecmp((string) ($author['login'] ?? ''), $me) === 0;
    }

    /** Rola autora do zapisu w CRM: SELLER (my), ADMIN (Allegro) albo BUYER. */
    public static function authorRole(array $author, string $me = ''): string
    {
        if (strtoupper((string) ($author['role'] ?? '')) === 'ADMIN') {
            return 'ADMIN';
        }
        return self::isMine($author, $me) ? 'SELLER' : 'BUYER';
    }
}
