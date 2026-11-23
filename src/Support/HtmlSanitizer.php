<?php
declare(strict_types=1);

namespace Pase\Support;

/**
 * Usuwa z HTML-a szablonu (e-mail, wydruk, opis oferty) wszystko, co może uruchomić kod
 * w przeglądarce osoby, która go potem otworzy w panelu: <script>, ramki, obiekty,
 * formularze, <meta>/<base>/<link>, atrybuty on* i adresy javascript:/vbscript:/data:
 * (poza obrazkami data:image/…).
 *
 * Szablony edytuje redaktor, a oglądają je administratorzy - bez czyszczenia redaktor
 * mógłby przejąć konto administratora (atak XSS zapisany w treści szablonu).
 *
 * Zmienne {{...}} zostają nietknięte. Gdy w HTML-u nie ma nic groźnego, zwracamy go
 * bajt w bajt - zapis nie zmienia formatowania istniejących szablonów.
 */
final class HtmlSanitizer
{
    /** Elementy usuwane razem z zawartością. */
    private const DROP = [
        'script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form',
        'meta', 'base', 'link', 'noscript', 'template', 'portal', 'svg', 'math',
        'input', 'button', 'textarea', 'select',
    ];

    /** Atrybuty, w których może stać adres. */
    private const URL_ATTRS = ['href', 'src', 'action', 'formaction', 'xlink:href', 'background', 'poster', 'srcset', 'data', 'lowsrc', 'dynsrc'];

    public static function clean(string $html): string
    {
        if (trim($html) === '' || !self::looksDangerous($html)) {
            return $html;
        }

        // Zmienne {{...}} zamieniamy na znaczniki, bo libxml koduje { } w atrybutach href/src.
        $vars = [];
        $marked = (string) preg_replace_callback('/\{\{\s*[a-zA-Z0-9_.]+\s*\}\}/', static function (array $m) use (&$vars): string {
            $key = 'PASEVAR' . count($vars) . 'X';
            $vars[$key] = $m[0];
            return $key;
        }, $html);

        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="pase-sanitize-root">' . $marked . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = null;
        foreach ($doc->getElementsByTagName('div') as $div) {
            if ($div->getAttribute('id') === 'pase-sanitize-root') {
                $root = $div;
                break;
            }
        }
        if ($root === null) {
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        self::walk($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        return strtr($out, $vars);
    }

    /** Szybki test, czy w ogóle trzeba parsować (większość szablonów jest czysta). */
    public static function looksDangerous(string $html): bool
    {
        $tags = implode('|', self::DROP);
        return (bool) preg_match('#<\s*/?\s*(' . $tags . ')\b#i', $html)
            || (bool) preg_match('#\son[a-z]+\s*=#i', $html)
            || (bool) preg_match('#(java|vb)script\s*:|data\s*:\s*(?!image/(png|jpe?g|gif|webp))|expression\s*\(|&\#|-moz-binding#i', $html);
    }

    private static function walk(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            if (in_array(strtolower($child->nodeName), self::DROP, true)) {
                $node->removeChild($child);
                continue;
            }
            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = strtolower($attr->nodeName);
                $value = (string) $attr->nodeValue;
                if (str_starts_with($name, 'on')
                    || (in_array($name, self::URL_ATTRS, true) && self::badUrl($value))
                    || ($name === 'style' && preg_match('#expression\s*\(|javascript\s*:|-moz-binding|behavior\s*:#i', $value))) {
                    $child->removeAttribute($attr->nodeName);
                }
            }
            self::walk($child);
        }
    }

    private static function badUrl(string $value): bool
    {
        // Usuń znaki sterujące i spacje, którymi da się „rozbić” słowo javascript:.
        $v = strtolower((string) preg_replace('/[\x00-\x20\x7f]+/', '', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (preg_match('#^(javascript|vbscript|livescript):#', $v)) {
            return true;
        }
        if (str_starts_with($v, 'data:')) {
            return !preg_match('#^data:image/(png|jpe?g|gif|webp);#', $v);
        }
        return false;
    }
}
