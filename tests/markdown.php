<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Renderer Markdown dla dokumentacji wtyczek (Wtyczki → Dokumentacja): poprawny HTML i brak surowego HTML z pliku.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Support\Markdown;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$h = Markdown::toHtml("# Tytuł\n\nTekst **gruby** i `kod <b>`.\n");
check(str_contains($h, '<h1>Tytuł</h1>'), 'heading');
check(str_contains($h, '<strong>gruby</strong>') && str_contains($h, '<code>kod &lt;b&gt;</code>'), 'inline bold + escaped code');

$h = Markdown::toHtml("<script>alert(1)</script>\n\n```php\n<?php echo 1;\n```");
check(!str_contains($h, '<script>') && str_contains($h, '&lt;?php echo 1;'), 'raw HTML escaped, code block kept');
check(str_contains($h, 'class="lang-php"'), 'code block language');

$h = Markdown::toHtml("| A | B |\n|---|---|\n| `x` | y |\n");
check(str_contains($h, '<th>A</th>') && str_contains($h, '<td><code>x</code></td>'), 'table');

$h = Markdown::toHtml("1. raz\n2. dwa\n   - pod\n   - pod2\n3. trzy\n   ciąg dalszy\n");
check(substr_count($h, '<ol>') === 1 && substr_count($h, '<ul>') === 1 && str_contains($h, 'trzy ciąg dalszy'), 'nested list + continuation');

$h = Markdown::toHtml("> **Uwaga:** tekst\n> dalej");
check(str_contains($h, '<blockquote><p><strong>Uwaga:</strong> tekst dalej</p></blockquote>'), 'blockquote');

$h = Markdown::toHtml('[a](javascript:alert(1)) [b](https://x.pl)');
check(!str_contains($h, 'href="javascript') && str_contains($h, 'href="https://x.pl"'), 'only http(s) links');

// Prawdziwa dokumentacja renderuje się i opisuje pole logo.
$h = Markdown::toHtml((string) file_get_contents(dirname(__DIR__) . '/integrations/README.md'));
check(str_contains($h, '<h2>Pola manifestu (<code>PluginManifest</code>)</h2>') && str_contains($h, '<code>logo</code>'), 'README renders with logo field');
check(!preg_match('/<(?!\/?(h\d|p|ul|ol|li|pre|code|strong|table|thead|tbody|tr|th|td|blockquote|a)[\s>])/', $h), 'README produces only whitelisted tags');

echo "\n$checks checks passed\n";
