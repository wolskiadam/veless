<?php
declare(strict_types=1);

namespace Pase\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Podział opisu produktu (HTML ze sklepu) na sekcje - np. zakładki „Specyfikacja”,
 * „Dodatkowe informacje”, „Bezpieczeństwo”.
 *
 * Sklep trzyma zakładki w JEDNYM polu opisu, a dzieli je dopiero jego szablon/wtyczka.
 * Rozpoznajemy typowe układy (bez znajomości konkretnej wtyczki):
 *   1. ARIA: elementy role="tab" + role="tabpanel",
 *   2. nawigacja zakładek (lista tytułów) + te same tytuły powtórzone przed treścią,
 *   3. nagłówki h2–h4 jako tytuły sekcji,
 *   4. brak struktury -> jedna sekcja „Opis”.
 *
 * Wybór sekcji na Allegro: per produkt (products.allegro_sections: {klucz: true|false}),
 * a gdy produkt nie ma ustawienia - domyślne wykluczenia (settings ALLEGRO_EXCLUDED_SECTIONS).
 */
final class DescriptionSections
{
    private const BLOCK = ['p', 'div', 'section', 'article', 'aside', 'header', 'footer', 'main', 'nav', 'ul', 'ol', 'li',
        'dl', 'dt', 'dd', 'table', 'thead', 'tbody', 'tr', 'blockquote', 'pre', 'figure', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'details', 'summary'];
    private const CONTAINERS = ['div', 'section', 'article', 'aside', 'header', 'footer', 'main', 'nav', 'ul', 'ol', 'dl', 'details', 'figure'];
    /** Elementy inline, które w nawigacji zakładek bywają „kafelkami” tytułów. */
    private const INLINE_TITLES = ['a', 'button', 'label', 'span', 'strong', 'b'];

    /**
     * @return array<int,array{key:string,title:string,html:string}>
     */
    public static function split(?string $html): array
    {
        $html = trim((string) $html);
        if ($html === '' || trim(strip_tags($html)) === '') {
            return [];
        }

        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="__ds_root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = (new DOMXPath($dom))->query('//*[@id="__ds_root"]')->item(0);
        if (!$root instanceof DOMElement) {
            return [self::section('Opis', $html)];
        }

        // 1. ARIA tabs.
        $xp = new DOMXPath($dom);
        $tabs = $xp->query('.//*[@role="tab"]', $root);
        $panels = $xp->query('.//*[@role="tabpanel"]', $root);
        if ($tabs->length >= 2 && $tabs->length === $panels->length) {
            $out = [];
            foreach ($tabs as $i => $tab) {
                $title = self::text($tab);
                $body = self::inner($panels->item($i));
                if ($title !== '' && trim(strip_tags($body)) !== '') {
                    $out[] = self::section($title, self::dropLeadingTitle($body, $title));
                }
            }
            if (count($out) >= 2) {
                return $out;
            }
        }

        // Bloki w kolejności dokumentu.
        $blocks = [];
        self::collect($root, $blocks);

        // 2. Nawigacja zakładek + powtórzone tytuły.
        $sections = self::byRepeatedTitles($blocks);
        if ($sections !== null) {
            return $sections;
        }

        // 3. Nagłówki h2-h4.
        $sections = self::byHeadings($blocks);
        if ($sections !== null) {
            return $sections;
        }

        return [self::section('Opis', $html)];
    }

    /** Klucz ustawienia z sekcjami wyłączonymi domyślnie na Allegro (JSON: lista kluczy). */
    public const SETTING_DEFAULT_EXCLUDED = 'ALLEGRO_EXCLUDED_SECTIONS';

    /** @return string[] klucze sekcji wyłączonych domyślnie (dla produktów bez własnego wyboru) */
    public static function defaultExcluded(?\PDO $pdo = null): array
    {
        try {
            $pdo ??= Runtime::pdo();
            $raw = (new \Pase\Repository\SettingsRepository($pdo))->get(self::SETTING_DEFAULT_EXCLUDED, '[]');
            $list = json_decode((string) $raw, true);
            return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Klucz sekcji do zapisu wyboru (niezależny od wielkości liter / spacji). */
    public static function key(string $title): string
    {
        $t = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $title) ?? $title));
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $t), '-') ?: 'opis';
    }

    /**
     * Czy sekcja idzie na Allegro.
     * @param array<string,bool> $productChoice products.allegro_sections
     * @param string[]           $defaultExcluded klucze wyłączone domyślnie
     */
    public static function includedOnAllegro(string $key, array $productChoice, array $defaultExcluded): bool
    {
        if (array_key_exists($key, $productChoice)) {
            return (bool) $productChoice[$key];
        }
        return !in_array($key, $defaultExcluded, true);
    }

    /**
     * Opis na Allegro: tylko włączone sekcje, każda z nagłówkiem (gdy jest ich więcej niż jedna),
     * oczyszczony do znaczników, które przyjmuje Allegro (h1, h2, p, ul, ol, li, b).
     * @param array<string,mixed> $product wiersz products
     */
    public static function allegroHtml(array $product, array $defaultExcluded): string
    {
        $sections = self::split((string) ($product['description'] ?? ''));
        $choice = json_decode((string) ($product['allegro_sections'] ?? ''), true);
        $choice = is_array($choice) ? $choice : [];
        $multi = count($sections) > 1;
        $out = '';
        foreach ($sections as $s) {
            if (!self::includedOnAllegro($s['key'], $choice, $defaultExcluded)) {
                continue;
            }
            $out .= ($multi ? '<h2>' . htmlspecialchars($s['title']) . '</h2>' : '') . self::allegroClean($s['html']);
        }
        return $out;
    }

    /**
     * HTML -> podzbiór dozwolony przez Allegro: h2, p, ul/ol/li, b. Budowane z drzewa DOM,
     * więc <br> dzieli akapity bez rozcinania pogrubień, a układ zakładek/divów znika.
     */
    public static function allegroClean(string $html): string
    {
        if (trim(strip_tags($html)) === '') {
            return '';
        }
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="__ac_root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = (new DOMXPath($dom))->query('//*[@id="__ac_root"]')->item(0);
        if ($root === null) {
            return '<p>' . htmlspecialchars(self::norm(strip_tags($html))) . '</p>';
        }
        $out = [];
        $para = [];
        self::walkAllegro($root, false, $out, $para);
        self::flushPara($out, $para);
        return implode('', $out);
    }

    /** @param string[] $out  @param array<int,array{0:string,1:bool}> $para */
    private static function walkAllegro(DOMNode $node, bool $bold, array &$out, array &$para): void
    {
        foreach ($node->childNodes as $c) {
            if ($c->nodeType === XML_TEXT_NODE) {
                $t = preg_replace('/\s+/u', ' ', $c->textContent) ?? '';
                if ($t !== '') {
                    $para[] = [$t, $bold];
                }
                continue;
            }
            if (!$c instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($c->tagName);
            if (in_array($tag, ['script', 'style', 'img', 'svg', 'iframe', 'noscript', 'button'], true)) {
                continue;
            }
            if ($tag === 'br') {
                self::flushPara($out, $para);
            } elseif (in_array($tag, ['b', 'strong'], true)) {
                self::walkAllegro($c, true, $out, $para);
            } elseif (preg_match('/^h[1-6]$/', $tag)) {
                self::flushPara($out, $para);
                $t = self::norm($c->textContent);
                if ($t !== '') {
                    $out[] = '<h2>' . htmlspecialchars($t) . '</h2>';
                }
            } elseif ($tag === 'ul' || $tag === 'ol') {
                self::flushPara($out, $para);
                $items = '';
                foreach ($c->childNodes as $li) {
                    if ($li instanceof DOMElement && strtolower($li->tagName) === 'li') {
                        $sub = [];
                        $subPara = [];
                        self::walkAllegro($li, $bold, $sub, $subPara);
                        $inline = self::renderSegments($subPara) . implode(' ', array_map('strip_tags', $sub));
                        if (trim(strip_tags($inline)) !== '') {
                            $items .= '<li>' . trim($inline) . '</li>';
                        }
                    }
                }
                if ($items !== '') {
                    $out[] = "<{$tag}>{$items}</{$tag}>";
                }
            } elseif (in_array($tag, self::BLOCK, true)) {
                self::flushPara($out, $para);
                self::walkAllegro($c, $bold, $out, $para);
                self::flushPara($out, $para);
            } else {
                self::walkAllegro($c, $bold, $out, $para);   // a, span, em, i, u... - sam tekst
            }
        }
    }

    private static function flushPara(array &$out, array &$para): void
    {
        $html = trim(self::renderSegments($para));
        if ($html !== '' && trim(strip_tags($html)) !== '') {
            $out[] = '<p>' . $html . '</p>';
        }
        $para = [];
    }

    /** Segmenty [tekst, pogrubiony] -> HTML z <b> (sąsiednie o tym samym stylu łączymy). */
    private static function renderSegments(array $segs): string
    {
        $html = '';
        $buf = '';
        $curBold = null;
        $emit = static function () use (&$html, &$buf, &$curBold): void {
            if ($buf !== '') {
                $html .= $curBold ? '<b>' . htmlspecialchars($buf) . '</b>' : htmlspecialchars($buf);
            }
            $buf = '';
        };
        foreach ($segs as [$t, $b]) {
            if ($curBold !== null && $b !== $curBold) {
                $emit();
            }
            $curBold = $b;
            $buf .= $t;
        }
        $emit();
        // Spacje na brzegach akapitu nie są potrzebne.
        return preg_replace('/^(<b>)?\s+|\s+(<\/b>)?$/u', '$1$2', $html) ?? $html;
    }

    // ------------------------------------------------------------------

    /** @param array<int,array{node:DOMNode,text:string,tag:string}> $blocks */
    private static function collect(DOMNode $node, array &$blocks): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, self::CONTAINERS, true) && self::hasBlockChildren($child)) {
                    self::collect($child, $blocks);
                    continue;
                }
                if (in_array($tag, self::CONTAINERS, true) && self::isTitleRow($child)) {
                    // Kontener z samymi „kafelkami” (np. <div><button>…</button><button>…</button></div>).
                    self::collect($child, $blocks);
                    continue;
                }
                $blocks[] = ['node' => $child, 'text' => self::text($child), 'tag' => $tag];
            } elseif ($child->nodeType === XML_TEXT_NODE && trim($child->textContent) !== '') {
                $blocks[] = ['node' => $child, 'text' => self::norm($child->textContent), 'tag' => '#text'];
            }
        }
    }

    private static function hasBlockChildren(DOMElement $el): bool
    {
        foreach ($el->childNodes as $c) {
            if ($c instanceof DOMElement && in_array(strtolower($c->tagName), self::BLOCK, true)) {
                return true;
            }
        }
        return false;
    }

    /** Kontener, którego dziećmi są wyłącznie ≥2 krótkie elementy inline (bez luźnego tekstu). */
    private static function isTitleRow(DOMElement $el): bool
    {
        $n = 0;
        foreach ($el->childNodes as $c) {
            if ($c->nodeType === XML_TEXT_NODE && trim($c->textContent) !== '') {
                return false;
            }
            if ($c instanceof DOMElement) {
                if (!in_array(strtolower($c->tagName), self::INLINE_TITLES, true) || !self::isShortTitle(self::text($c))) {
                    return false;
                }
                $n++;
            }
        }
        return $n >= 2;
    }

    /** @param array<int,array{node:DOMNode,text:string,tag:string}> $blocks */
    private static function byRepeatedTitles(array $blocks): ?array
    {
        $count = [];
        foreach ($blocks as $b) {
            if (self::isShortTitle($b['text'])) {
                $k = self::key($b['text']);
                $count[$k] = ($count[$k] ?? 0) + 1;
            }
        }
        $titles = array_keys(array_filter($count, static fn($n) => $n >= 2));
        if (count($titles) < 2) {
            return null;
        }
        $isTitle = static fn(array $b): bool => self::isShortTitle($b['text']) && in_array(self::key($b['text']), $titles, true);

        // Nawigacja = pierwszy ciąg kolejnych bloków-tytułów (≥2) - pomijamy ją.
        $n = count($blocks);
        $navStart = $navEnd = -1;
        for ($i = 0; $i < $n; $i++) {
            if ($isTitle($blocks[$i])) {
                // Ciąg kończy się na pierwszym POWTÓRZONYM tytule - to już nagłówek pierwszej sekcji,
                // który stoi zaraz za nawigacją (SPECYFIKACJA, INFO, BEZP., SPECYFIKACJA <- start sekcji).
                $seen = [self::key($blocks[$i]['text']) => true];
                $j = $i;
                while ($j + 1 < $n && $isTitle($blocks[$j + 1]) && !isset($seen[self::key($blocks[$j + 1]['text'])])) {
                    $seen[self::key($blocks[$j + 1]['text'])] = true;
                    $j++;
                }
                if ($j > $i) {
                    $navStart = $i;
                    $navEnd = $j;
                }
                break;
            }
        }

        $sections = [];
        $current = null;
        $intro = '';
        for ($i = 0; $i < $n; $i++) {
            if ($i >= $navStart && $i <= $navEnd && $navStart >= 0) {
                continue;
            }
            $b = $blocks[$i];
            if ($isTitle($b)) {
                if ($current !== null) {
                    $sections[] = $current;
                }
                $current = ['title' => $b['text'], 'nodes' => []];
                continue;
            }
            if ($current === null) {
                $intro .= self::outer($b['node']);
            } else {
                $current['nodes'][] = $b['node'];
            }
        }
        if ($current !== null) {
            $sections[] = $current;
        }

        $out = [];
        if (trim(strip_tags($intro)) !== '') {
            $out[] = self::section('Opis', $intro);
        }
        foreach ($sections as $s) {
            $html = self::joinNodes($s['nodes']);
            if (trim(strip_tags($html)) !== '') {
                $out[] = self::section($s['title'], $html);
            }
        }
        return count($out) >= 2 ? self::mergeSameKeys($out) : null;
    }

    /** @param array<int,array{node:DOMNode,text:string,tag:string}> $blocks */
    private static function byHeadings(array $blocks): ?array
    {
        $heads = array_filter($blocks, static fn($b) => in_array($b['tag'], ['h2', 'h3', 'h4'], true) && $b['text'] !== '');
        if (count($heads) < 2) {
            return null;
        }
        $out = [];
        $intro = [];
        $current = null;
        foreach ($blocks as $b) {
            if (in_array($b['tag'], ['h2', 'h3', 'h4'], true) && $b['text'] !== '') {
                if ($current !== null) {
                    $out[] = $current;
                }
                $current = ['title' => $b['text'], 'nodes' => []];
            } elseif ($current === null) {
                $intro[] = $b['node'];
            } else {
                $current['nodes'][] = $b['node'];
            }
        }
        if ($current !== null) {
            $out[] = $current;
        }
        $res = [];
        $introHtml = self::joinNodes($intro);
        if (trim(strip_tags($introHtml)) !== '') {
            $res[] = self::section('Opis', $introHtml);
        }
        foreach ($out as $s) {
            $res[] = self::section($s['title'], self::joinNodes($s['nodes']));
        }
        return self::mergeSameKeys($res);
    }

    /** Węzły -> HTML; kolejne <li> z tej samej listy owijamy z powrotem w <ul>/<ol>. */
    private static function joinNodes(array $nodes): string
    {
        $html = '';
        $openList = null;
        foreach ($nodes as $node) {
            $isLi = $node instanceof DOMElement && strtolower($node->tagName) === 'li';
            $parent = $isLi ? $node->parentNode : null;
            if ($openList !== null && (!$isLi || $parent !== $openList)) {
                $html .= '</' . strtolower($openList->nodeName) . '>';
                $openList = null;
            }
            if ($isLi && $openList === null) {
                $tag = in_array(strtolower((string) $parent?->nodeName), ['ul', 'ol'], true) ? strtolower($parent->nodeName) : 'ul';
                $html .= "<{$tag}>";
                $openList = $parent;
            }
            $html .= self::outer($node);
        }
        if ($openList !== null) {
            $html .= '</' . (in_array(strtolower($openList->nodeName), ['ul', 'ol'], true) ? strtolower($openList->nodeName) : 'ul') . '>';
        }
        return $html;
    }

    private static function mergeSameKeys(array $sections): array
    {
        $out = [];
        foreach ($sections as $s) {
            if (isset($out[$s['key']])) {
                $out[$s['key']]['html'] .= $s['html'];
            } else {
                $out[$s['key']] = $s;
            }
        }
        return array_values($out);
    }

    private static function dropLeadingTitle(string $html, string $title): string
    {
        // Panel często zaczyna się powtórzonym tytułem - nie dublujemy nagłówka.
        $plain = self::norm(strip_tags($html));
        if (str_starts_with(mb_strtolower($plain), mb_strtolower(self::norm($title)))) {
            $q = preg_quote(self::norm($title), '#');
            $html = preg_replace('#^\s*(<[^>]+>\s*)*' . $q . '\s*(</[^>]+>\s*)*#iu', '', $html, 1) ?? $html;
        }
        return $html;
    }

    private static function isShortTitle(string $t): bool
    {
        $t = trim($t);
        if ($t === '' || mb_strlen($t) > 50 || preg_match('/[.!?]$/u', $t)) {
            return false;
        }
        return count(preg_split('/\s+/u', $t) ?: []) <= 6;
    }

    private static function section(string $title, string $html): array
    {
        $title = rtrim(self::norm($title), ':');
        return ['key' => self::key($title), 'title' => self::pretty($title), 'html' => trim($html)];
    }

    /** „SPECYFIKACJA” -> „Specyfikacja” (tytuły zakładek bywają pisane wersalikami). */
    private static function pretty(string $t): string
    {
        return mb_strtoupper($t) === $t ? mb_strtoupper(mb_substr(mb_strtolower($t), 0, 1)) . mb_substr(mb_strtolower($t), 1) : $t;
    }

    private static function text(DOMNode $n): string
    {
        return self::norm($n->textContent);
    }

    private static function norm(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? $s);
    }

    private static function outer(DOMNode $n): string
    {
        return (string) $n->ownerDocument?->saveHTML($n);
    }

    private static function inner(?DOMNode $n): string
    {
        if ($n === null) {
            return '';
        }
        $h = '';
        foreach ($n->childNodes as $c) {
            $h .= self::outer($c);
        }
        return $h;
    }
}
