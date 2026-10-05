<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Klikalne linki w wiadomościach (strona klienta + panel zamówienia), tekst bezpiecznie escapowany.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Support\TextLinks;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }
$a = static fn(string $href, string $text): string => '<a href="' . $href . '" target="_blank" rel="noopener noreferrer nofollow">' . $text . '</a>';

check(TextLinks::html('Śledź: https://inpost.pl/sledzenie?number=620&x=1') === 'Śledź: ' . $a('https://inpost.pl/sledzenie?number=620&amp;x=1', 'https://inpost.pl/sledzenie?number=620&amp;x=1'), 'https link with query');
check(TextLinks::html('Zobacz www.example.com.') === 'Zobacz ' . $a('https://www.example.com', 'www.example.com') . '.', 'www link, trailing dot outside');
check(TextLinks::html('(https://g.page/r/abc/review)') === '(' . $a('https://g.page/r/abc/review', 'https://g.page/r/abc/review') . ')', 'closing paren outside');
check(str_contains(TextLinks::html('https://pl.wikipedia.org/wiki/Wosk_(materiał)'), 'Wosk_(materiał)</a>'), 'balanced paren kept');
check(TextLinks::html("A\nB") === "A<br />\nB", 'line breaks');
check(TextLinks::html('<script>alert(1)</script> https://x.pl/"onmouseover=1') === '&lt;script&gt;alert(1)&lt;/script&gt; ' . $a('https://x.pl/', 'https://x.pl/') . '&quot;onmouseover=1', 'HTML escaped, quote ends the link');
check(!str_contains(TextLinks::html('javascript:alert(1)'), '<a'), 'javascript: is not linked');
check(TextLinks::html('Bez linków, 14.75 zł') === 'Bez linków, 14.75 zł', 'plain text unchanged');

echo "\n$checks checks passed\n";
