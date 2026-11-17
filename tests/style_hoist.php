<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Style z <body> przenoszone do <head> (Support\StyleHoist), żeby strona nie mignęła bez wyglądu przy ładowaniu.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Support\StyleHoist;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$page = "<!DOCTYPE html><html><head><style>:root{--a:1}</style></head><body>\n"
    . "<div class=\"print-menu\"><a href=\"x\">Karta</a></div>\n"
    . "<style>.print-menu{display:none}</style>\n"
    . "<link rel=\"stylesheet\" href=\"quill.css\">\n"
    . "<script>w.document.write('<style>@page{size:A4}</style>');</script>\n"
    . "<textarea name=\"tpl\"><style>.mail{color:red}</style></textarea>\n"
    . "<svg><style>.ic{fill:red}</style></svg>\n"
    . "<STYLE media=\"print\">.no-print{display:none}</STYLE>\n"
    . "</body></html>";
$out = StyleHoist::hoist($page);
[$head, $body] = explode('</head>', $out, 2);
check(str_contains($head, '.print-menu{display:none}') && !str_contains($body, '.print-menu{display:none}'), 'Page <style> moved from body to head');
check(str_contains($head, 'href="quill.css"') && !str_contains($body, 'quill.css'), 'Body stylesheet <link> moved to head');
check(strpos($head, '--a:1') < strpos($head, '.print-menu') && strpos($head, '.print-menu') < strpos($head, 'quill.css')
    && strpos($head, 'quill.css') < strpos($head, '.no-print'), 'Order of styles kept (head first, then body in document order)');
check(str_contains($body, "w.document.write('<style>@page{size:A4}</style>')"), 'Style inside <script> left untouched');
check(str_contains($body, '<textarea name="tpl"><style>.mail{color:red}</style></textarea>'), 'Style inside <textarea> left untouched');
check(str_contains($body, '<svg><style>.ic{fill:red}</style></svg>'), 'Style inside <svg> left untouched');
check(str_contains($body, '<a href="x">Karta</a>'), 'Page content unchanged');
check(StyleHoist::hoist('{"ok":true}') === '{"ok":true}', 'Output without <head> (JSON, fragments) unchanged');
$noBodyStyles = '<html><head><style>a{}</style></head><body><p>x</p></body></html>';
check(StyleHoist::hoist($noBodyStyles) === $noBodyStyles, 'Page without body styles unchanged');

// Bufor w header.php: cała strona przepisywana przy wysłaniu.
ob_start();
StyleHoist::start();
echo '<html><head></head><body><b>x</b>';
echo '<style>b{color:red}</style></body></html>';
ob_end_flush();
$sent = (string) ob_get_clean();
check(str_starts_with($sent, '<html><head><style>b{color:red}</style>') && !str_contains(substr($sent, (int) strpos($sent, '</head>')), '<style>'),
    'Output buffer hoists styles of the whole page');

echo "PASS: $checks checks\n";
