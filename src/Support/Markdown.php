<?php
declare(strict_types=1);

namespace Pase\Support;

/**
 * Minimalny, bezpieczny renderer Markdown -> HTML dla dokumentacji w panelu
 * (np. integrations/README.md na stronie Wtyczki).
 *
 * Obsługuje: nagłówki (#..######), akapity, bloki kodu ```, cytaty (>), listy (- / 1.)
 * z jednym poziomem zagnieżdżenia, tabele (|a|b|), kod `inline`, **pogrubienie**
 * i linki [tekst](http...). Cały tekst jest escapowany - surowy HTML z pliku nie przechodzi.
 */
final class Markdown
{
    public static function toHtml(string $md): string
    {
        $lines = preg_split('/\R/u', str_replace("\t", '    ', $md)) ?: [];
        $out = [];
        $n = count($lines);
        $i = 0;

        while ($i < $n) {
            $line = $lines[$i];

            if (trim($line) === '') { $i++; continue; }

            // Blok kodu.
            if (preg_match('/^\s*```(\w*)/u', $line, $m)) {
                $code = [];
                for ($i++; $i < $n && !preg_match('/^\s*```/u', $lines[$i]); $i++) {
                    $code[] = $lines[$i];
                }
                $i++;
                $cls = $m[1] !== '' ? ' class="lang-' . self::e($m[1]) . '"' : '';
                $out[] = '<pre><code' . $cls . '>' . self::e(implode("\n", $code)) . '</code></pre>';
                continue;
            }

            // Nagłówek.
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*$/u', $line, $m)) {
                $lvl = strlen($m[1]);
                $out[] = "<h$lvl>" . self::inline($m[2]) . "</h$lvl>";
                $i++;
                continue;
            }

            // Tabela: wiersz z | i linia separatora pod spodem.
            if (str_contains($line, '|') && $i + 1 < $n && preg_match('/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?\s*$/u', $lines[$i + 1])) {
                $head = self::cells($line);
                $i += 2;
                $rows = [];
                while ($i < $n && str_contains($lines[$i], '|') && trim($lines[$i]) !== '') {
                    $rows[] = self::cells($lines[$i]);
                    $i++;
                }
                $html = '<table><thead><tr>';
                foreach ($head as $c) { $html .= '<th>' . self::inline($c) . '</th>'; }
                $html .= '</tr></thead><tbody>';
                foreach ($rows as $r) {
                    $html .= '<tr>';
                    foreach ($r as $c) { $html .= '<td>' . self::inline($c) . '</td>'; }
                    $html .= '</tr>';
                }
                $out[] = $html . '</tbody></table>';
                continue;
            }

            // Cytat.
            if (preg_match('/^\s*>/u', $line)) {
                $buf = [];
                while ($i < $n && preg_match('/^\s*>\s?(.*)$/u', $lines[$i], $m)) {
                    $buf[] = $m[1];
                    $i++;
                }
                $out[] = '<blockquote>' . self::toHtml(implode("\n", $buf)) . '</blockquote>';
                continue;
            }

            // Lista.
            if (preg_match('/^(\s*)([-*]|\d+\.)\s+/u', $line, $m)) {
                [$html, $i] = self::list($lines, $i, strlen($m[1]));
                $out[] = $html;
                continue;
            }

            // Akapit: do pustej linii albo początku innego bloku.
            $buf = [];
            while ($i < $n && trim($lines[$i]) !== '' && !self::startsBlock($lines, $i)) {
                $buf[] = trim($lines[$i]);
                $i++;
            }
            if ($buf === []) { $buf[] = trim($lines[$i]); $i++; }
            $out[] = '<p>' . self::inline(implode(' ', $buf)) . '</p>';
        }

        return implode("\n", $out);
    }

    /** @return array{0:string,1:int} */
    private static function list(array $lines, int $i, int $indent): array
    {
        $n = count($lines);
        preg_match('/^\s*([-*]|\d+\.)/u', $lines[$i], $m);
        $tag = ctype_digit($m[1][0]) ? 'ol' : 'ul';
        $items = [];

        while ($i < $n) {
            $line = $lines[$i];
            if (trim($line) === '') {
                // Pusta linia kończy listę, chyba że dalej jest kolejny element na tym samym poziomie.
                if ($i + 1 < $n && preg_match('/^(\s*)([-*]|\d+\.)\s+/u', $lines[$i + 1], $mm) && strlen($mm[1]) === $indent) { $i++; continue; }
                break;
            }
            $lead = strlen($line) - strlen(ltrim($line));
            if (preg_match('/^(\s*)([-*]|\d+\.)\s+(.*)$/u', $line, $mm)) {
                if ($lead === $indent) {
                    $items[] = ['text' => $mm[3], 'sub' => ''];
                    $i++;
                    continue;
                }
                if ($lead > $indent && $items !== []) {
                    [$sub, $i] = self::list($lines, $i, $lead);
                    $items[count($items) - 1]['sub'] .= $sub;
                    continue;
                }
                break;
            }
            if ($lead > $indent && $items !== [] && !preg_match('/^\s*(```|#)/u', $line)) {
                $items[count($items) - 1]['text'] .= ' ' . trim($line); // kontynuacja elementu
                $i++;
                continue;
            }
            break;
        }

        $html = "<$tag>";
        foreach ($items as $it) {
            $html .= '<li>' . self::inline($it['text']) . $it['sub'] . '</li>';
        }
        return [$html . "</$tag>", $i];
    }

    private static function startsBlock(array $lines, int $i): bool
    {
        $l = $lines[$i];
        return (bool) preg_match('/^\s*(```|#{1,6}\s|>|[-*]\s|\d+\.\s)/u', $l)
            || (str_contains($l, '|') && isset($lines[$i + 1]) && preg_match('/^\s*\|?\s*:?-{3,}/u', $lines[$i + 1]));
    }

    /** @return string[] */
    private static function cells(string $row): array
    {
        $row = trim($row);
        $row = preg_replace('/^\||\|$/u', '', $row) ?? $row;
        return array_map('trim', explode('|', $row));
    }

    private static function inline(string $text): string
    {
        // Kod inline najpierw - jego treść nie podlega dalszemu formatowaniu.
        $codes = [];
        $text = preg_replace_callback('/`([^`]+)`/u', static function (array $m) use (&$codes): string {
            $codes[] = '<code>' . self::e($m[1]) . '</code>';
            return "\x00" . (count($codes) - 1) . "\x00";
        }, $text) ?? $text;

        $text = self::e($text);
        $text = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/u', static fn(array $m): string
            => '<a href="' . $m[2] . '" target="_blank" rel="noopener">' . $m[1] . '</a>', $text) ?? $text;

        return preg_replace_callback("/\x00(\d+)\x00/u", static fn(array $m): string => $codes[(int) $m[1]], $text) ?? $text;
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
