<?php
declare(strict_types=1);

namespace Pase\Support;

/**
 * Przenosi style z <body> do <head> całej strony panelu.
 *
 * Strony i wtyczki wypisują własne <style> obok swoich elementów (np. menu „Drukuj / Eksportuj” na karcie
 * zamówienia). Przy wolniejszym ładowaniu przeglądarka rysowała już te elementy, zanim doszła do ich stylów:
 * przez chwilę było widać rozwinięte menu jako gołe linki i przyciski bez wyglądu. header.php buforuje
 * całą stronę, a ten filtr na końcu przenosi wszystkie <style> i <link rel="stylesheet"> z <body> na koniec
 * <head>, w tej samej kolejności - więc kolejność reguł (która wygrywa) się nie zmienia, a strona od pierwszego
 * narysowania ma już wszystkie style.
 *
 * Pomijane są fragmenty, w których <style> nie jest stylem tej strony: <script> (np. HTML okna wydruku
 * budowany w JS), <textarea> (edytowane szablony), <template>, <svg>, <noscript>, <iframe srcdoc>.
 */
final class StyleHoist
{
    /** Bufor wyjścia dla header.php: przepisuje gotową stronę przy jej wysłaniu. */
    public static function start(): void
    {
        ob_start(static function (string $buffer, int $phase): string {
            // Tylko cała strona naraz; jeśli ktoś wysłał część wcześniej (flush), reszta idzie bez zmian.
            return ($phase & PHP_OUTPUT_HANDLER_FINAL) && ($phase & PHP_OUTPUT_HANDLER_START)
                ? self::hoist($buffer) : $buffer;
        });
    }

    public static function hoist(string $html): string
    {
        $headEnd = stripos($html, '</head>');
        if ($headEnd === false) {
            return $html;
        }
        $head = substr($html, 0, $headEnd);
        $body = substr($html, $headEnd);

        $moved = [];
        $pattern = '~<(script|textarea|template|svg|noscript|iframe)\b.*?</\1\s*>'
            . '|<style\b[^>]*>.*?</style\s*>'
            . '|<link\b[^>]*\brel\s*=\s*["\']?stylesheet\b[^>]*>~is';
        $newBody = preg_replace_callback($pattern, static function (array $m) use (&$moved): string {
            if (($m[1] ?? '') !== '') {
                return $m[0]; // treść skryptu/pola tekstowego itp. zostaje nietknięta
            }
            $moved[] = $m[0];
            return '';
        }, $body);
        if ($newBody === null || $moved === []) {
            return $html; // błąd wyrażenia (np. limit) albo nic do przeniesienia - strona bez zmian
        }
        return $head . implode("\n", $moved) . "\n" . $newBody;
    }
}
