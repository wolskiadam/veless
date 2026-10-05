<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Zdjęcie główne Allegro 2560×2560: kwadrat z białym tłem, bez przycinania. Bez sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\AllegroMainPhoto;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

check(AllegroMainPhoto::available(), 'GD available');
// Poziome zdjęcie 1200×800: czerwony prostokąt z przezroczystym paskiem u góry.
$im = imagecreatetruecolor(1200, 800);
imagesavealpha($im, true);
imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
imagealphablending($im, false);
imagefilledrectangle($im, 0, 0, 1199, 99, imagecolorallocatealpha($im, 0, 0, 0, 127));
ob_start(); imagepng($im); $png = (string) ob_get_clean();

check(AllegroMainPhoto::dimensions($png) === [1200, 800], 'dimensions read');
check(!AllegroMainPhoto::isReady(1200, 800) && AllegroMainPhoto::isReady(2560, 2560), 'only 2560×2560 counts as ready');
$jpg = AllegroMainPhoto::square($png);
check($jpg !== null && AllegroMainPhoto::dimensions($jpg) === [2560, 2560], 'result is 2560×2560');
$out = imagecreatefromstring($jpg);
$rgb = static fn(int $x, int $y): array => array_values(array_slice(imagecolorsforindex($out, imagecolorat($out, $x, $y)), 0, 3));
$near = static fn(array $c, array $want): bool => max(array_map(static fn($a, $b) => abs($a - $b), $c, $want)) <= 12;
check($near($rgb(10, 10), [255, 255, 255]) && $near($rgb(1280, 2550), [255, 255, 255]), 'padding above/below is white (no cropping of the wide photo)');
check($near($rgb(10, 1280), [200, 30, 30]) && $near($rgb(2550, 1280), [200, 30, 30]), 'photo spans the full width');
check($near($rgb(1280, 480), [255, 255, 255]), 'transparent part becomes white');
check(AllegroMainPhoto::square('to nie jest zdjęcie') === null, 'not an image -> null');

check(AllegroMainPhoto::urlAllowed('https://sklep.example/a.jpg', ['sklep.example']), 'shop photo host allowed');
check(AllegroMainPhoto::urlAllowed('https://a.allegroimg.com/original/x.jpg', []), 'Allegro image host allowed');
check(!AllegroMainPhoto::urlAllowed('http://127.0.0.1/x.jpg', ['sklep.example']), 'unknown host refused');
check(!AllegroMainPhoto::urlAllowed('file:///etc/passwd', ['sklep.example']), 'non-http refused');
check(!AllegroMainPhoto::urlAllowed('https://sklep.example:8080/a.jpg', ['sklep.example']), 'custom port refused');
check(!AllegroMainPhoto::urlAllowed('https://evilallegroimg.com/x.jpg', []), 'lookalike host refused');

echo "\n{$checks} checks passed\n";
