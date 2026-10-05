<?php
declare(strict_types=1);

namespace Pase\Services;

/** Restricted, passive markup for print templates, including agent HTML. */
final class PrintHtml
{
    private const TAGS = ['div', 'span', 'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'colgroup', 'col',
        'strong', 'b', 'em', 'i', 'u', 's', 'small', 'sub', 'sup', 'pre', 'code',
        'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'section', 'article', 'header', 'footer', 'img'];

    private const CSS = ['color', 'background-color', 'font', 'font-family', 'font-size',
        'font-weight', 'font-style', 'line-height', 'letter-spacing', 'text-align',
        'text-decoration', 'text-transform', 'white-space', 'word-break', 'overflow-wrap',
        'display', 'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height',
        'margin', 'margin-top', 'margin-bottom', 'margin-left', 'margin-right',
        'padding', 'padding-top', 'padding-bottom', 'padding-left', 'padding-right',
        'border', 'border-top', 'border-bottom', 'border-left', 'border-right',
        'border-width', 'border-style', 'border-color', 'border-radius', 'border-collapse',
        'border-spacing', 'vertical-align', 'table-layout', 'box-sizing', 'float', 'clear',
        'flex', 'flex-direction', 'flex-wrap', 'justify-content', 'align-items', 'gap',
        'grid-template-columns', 'page-break-before', 'page-break-after', 'page-break-inside',
        'break-before', 'break-after', 'break-inside'];

    public static function sanitize(string $html): string
    {
        if (!class_exists(\DOMDocument::class)) {
            throw new \RuntimeException('Bezpieczne wydruki HTML wymagają rozszerzenia PHP DOM.');
        }
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>'
                . $html . '</body></html>', LIBXML_NONET);
            $body = $dom->getElementsByTagName('body')->item(0);
            return $body === null ? '' : self::children($body);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function children(\DOMNode $parent): string
    {
        $html = '';
        foreach ($parent->childNodes as $node) {
            if ($node instanceof \DOMText) {
                $html .= self::escape($node->textContent);
                continue;
            }
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($node->tagName);
            // Drop entire foreign/active/unknown subtrees, not just their tags.
            if (!in_array($tag, self::TAGS, true)) {
                continue;
            }
            $attrs = '';
            foreach ($node->attributes as $attr) {
                $name = strtolower($attr->name);
                $value = $attr->value;
                if ($name === 'style') {
                    $value = self::style($value);
                } elseif ($name === 'src' && $tag === 'img') {
                    // No external resources, SVG, navigation or arbitrary local files.
                    if (!preg_match('~\A(?:print_template_image\.php\?tpl=[0-9]+|data:image/(?:png|jpeg|gif|webp|bmp);base64,[A-Za-z0-9+/=]+)\z~D', $value)) {
                        continue;
                    }
                } elseif (in_array($name, ['colspan', 'rowspan', 'width', 'height'], true)) {
                    if (!preg_match('/\A[0-9]{1,4}%?\z/D', $value)) {
                        continue;
                    }
                } elseif (!in_array($name, ['alt', 'title', 'class'], true)) {
                    continue;
                }
                $attrs .= ' ' . $name . '="' . self::escape($value) . '"';
            }
            $html .= '<' . $tag . $attrs . '>';
            if (!in_array($tag, ['br', 'hr', 'img', 'col'], true)) {
                $html .= self::children($node) . '</' . $tag . '>';
            }
        }
        return $html;
    }

    private static function style(string $css): string
    {
        $safe = [];
        foreach (explode(';', $css) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$property, $value] = array_map('trim', $parts);
            $property = strtolower($property);
            // Small value grammar: no escapes, comments, URLs, @ rules or functions
            // or other active values. Unknown CSS is deliberately omitted.
            if (in_array($property, self::CSS, true)
                && preg_match('/\A[a-zA-Z0-9\s#.,%\-"\x27]+\z/D', $value)
                && !preg_match('/(?:url|expression|javascript|@)/i', $value)) {
                $safe[] = $property . ':' . $value;
            }
        }
        return implode(';', $safe);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
