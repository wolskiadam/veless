<?php
declare(strict_types=1);

namespace Pase\Support;

/**
 * Zwykły tekst wiadomości -> bezpieczny HTML: escapowanie, klikalne adresy (http(s)://, www.) i łamanie linii.
 */
final class TextLinks
{
    public static function html(string $text): string
    {
        $parts = preg_split('~(https?://[^\s<>"]+|www\.[^\s<>"]+)~iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $out = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $out .= self::esc($part);
                continue;
            }
            // Kropka, przecinek, nawias itp. na końcu zdania nie należą do adresu.
            $tail = '';
            while ($part !== '' && preg_match('~[.,;:!?)\]}\'»”]$~u', $part) && !self::balancedParen($part)) {
                $tail = mb_substr($part, -1) . $tail;
                $part = mb_substr($part, 0, -1);
            }
            $href = preg_match('~^https?://~i', $part) ? $part : 'https://' . $part;
            $out .= '<a href="' . self::esc($href) . '" target="_blank" rel="noopener noreferrer nofollow">' . self::esc($part) . '</a>' . self::esc($tail);
        }
        return nl2br($out);
    }

    /** Adres kończący się „)" z otwierającym nawiasem w środku (np. Wikipedia) zostawiamy w całości. */
    private static function balancedParen(string $url): bool
    {
        return str_ends_with($url, ')') && substr_count($url, '(') >= substr_count($url, ')');
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
