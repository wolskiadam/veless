<?php
declare(strict_types=1);

namespace PaseExt\Ksef;

/**
 * Kod QR (tryb bajtowy) do linku weryfikacyjnego KSeF na podglądzie i w PDF faktury.
 * Port algorytmu z biblioteki QR Code generator (Project Nayuki, licencja MIT) - tej samej, której panel używa
 * w public/admin/assets/qrcodegen.js; tutaj tylko tryb bajtowy, poziom korekcji M podnoszony, gdy dane się mieszczą.
 */
final class QrCode
{
    private const ECC_CODEWORDS_PER_BLOCK = [
        [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];
    private const NUM_ERROR_CORRECTION_BLOCKS = [
        [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];
    /** Poziomy korekcji: [indeks w tablicach, bity formatu] dla L, M, Q, H. */
    private const ECL = [[0, 1], [1, 0], [2, 3], [3, 2]];

    public readonly int $size;
    /** @var list<list<bool>> [y][x], true = ciemny moduł */
    private array $modules = [];
    /** @var list<list<bool>> */
    private array $isFunction = [];
    private int $ecl;

    private function __construct(private readonly int $version, int $ecl, array $dataCodewords)
    {
        $this->ecl = $ecl;
        $this->size = $version * 4 + 17;
        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->isFunction = array_fill(0, $this->size, $row);
        $this->drawFunctionPatterns();
        $this->drawCodewords($this->addEccAndInterleave($dataCodewords));
        $best = 0;
        $minPenalty = PHP_INT_MAX;
        for ($i = 0; $i < 8; $i++) {
            $this->applyMask($i);
            $this->drawFormatBits($i);
            $penalty = $this->penaltyScore();
            if ($penalty < $minPenalty) {
                $best = $i;
                $minPenalty = $penalty;
            }
            $this->applyMask($i);
        }
        $this->applyMask($best);
        $this->drawFormatBits($best);
        $this->isFunction = [];
    }

    /** Kod QR dla tekstu (UTF-8, tryb bajtowy). */
    public static function encode(string $data): self
    {
        $len = strlen($data);
        $ecl = 1;
        for ($version = 1; ; $version++) {
            $used = 4 + ($version <= 9 ? 8 : 16) + $len * 8;
            if ($used <= self::numDataCodewords($version, $ecl) * 8) {
                break;
            }
            if ($version >= 40) {
                throw new \RuntimeException('Za dużo danych na kod QR.');
            }
        }
        foreach ([1, 2, 3] as $e) {
            if ($used <= self::numDataCodewords($version, $e) * 8) {
                $ecl = $e;
            }
        }
        $bits = [];
        self::appendBits(0x4, 4, $bits);
        self::appendBits($len, $version <= 9 ? 8 : 16, $bits);
        for ($i = 0; $i < $len; $i++) {
            self::appendBits(ord($data[$i]), 8, $bits);
        }
        $capacity = self::numDataCodewords($version, $ecl) * 8;
        self::appendBits(0, min(4, $capacity - count($bits)), $bits);
        self::appendBits(0, (8 - count($bits) % 8) % 8, $bits);
        for ($pad = 0xEC; count($bits) < $capacity; $pad ^= 0xEC ^ 0x11) {
            self::appendBits($pad, 8, $bits);
        }
        $codewords = array_fill(0, intdiv(count($bits), 8), 0);
        foreach ($bits as $i => $b) {
            $codewords[$i >> 3] |= $b << (7 - ($i & 7));
        }
        return new self($version, $ecl, $codewords);
    }

    public function module(int $x, int $y): bool
    {
        return $x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size && $this->modules[$y][$x];
    }

    /** SVG z ciemnymi modułami jako jedna ścieżka, z marginesem $border modułów. */
    public function svg(int $border = 2, string $title = ''): string
    {
        $path = '';
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->modules[$y][$x]) {
                    $path .= 'M' . ($x + $border) . ',' . ($y + $border) . 'h1v1h-1z';
                }
            }
        }
        $dim = $this->size + $border * 2;
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges" role="img"'
            . ($title !== '' ? ' aria-label="' . htmlspecialchars($title, ENT_QUOTES) . '"' : '') . '>'
            . '<rect width="100%" height="100%" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
    }

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }
        $this->drawFinder(3, 3);
        $this->drawFinder($this->size - 4, 3);
        $this->drawFinder(3, $this->size - 4);
        $pos = $this->alignmentPositions();
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (!($i === 0 && $j === 0 || $i === 0 && $j === $n - 1 || $i === $n - 1 && $j === 0)) {
                    for ($dy = -2; $dy <= 2; $dy++) {
                        for ($dx = -2; $dx <= 2; $dx++) {
                            $this->setFunction($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                        }
                    }
                }
            }
        }
        $this->drawFormatBits(0);
        if ($this->version >= 7) {
            $rem = $this->version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = $this->version << 12 | $rem;
            for ($i = 0; $i < 18; $i++) {
                $dark = (($bits >> $i) & 1) === 1;
                $a = $this->size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->setFunction($a, $b, $dark);
                $this->setFunction($b, $a, $dark);
            }
        }
    }

    private function drawFormatBits(int $mask): void
    {
        $data = self::ECL[$this->ecl][1] << 3 | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = ($data << 10 | $rem) ^ 0x5412;
        $bit = static fn(int $i): bool => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) {
            $this->setFunction(8, $i, $bit($i));
        }
        $this->setFunction(8, 7, $bit(6));
        $this->setFunction(8, 8, $bit(7));
        $this->setFunction(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFunction(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->setFunction($this->size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunction(8, $this->size - 15 + $i, $bit($i));
        }
        $this->setFunction(8, $this->size - 8, true);
    }

    private function drawFinder(int $x, int $y): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $dist = max(abs($dx), abs($dy));
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx >= 0 && $xx < $this->size && $yy >= 0 && $yy < $this->size) {
                    $this->setFunction($xx, $yy, $dist !== 2 && $dist !== 4);
                }
            }
        }
    }

    private function setFunction(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    /** @return list<int> */
    private function addEccAndInterleave(array $data): array
    {
        $eclIdx = self::ECL[$this->ecl][0];
        $numBlocks = self::NUM_ERROR_CORRECTION_BLOCKS[$eclIdx][$this->version];
        $blockEccLen = self::ECC_CODEWORDS_PER_BLOCK[$eclIdx][$this->version];
        $raw = intdiv(self::numRawDataModules($this->version), 8);
        $numShort = $numBlocks - $raw % $numBlocks;
        $shortLen = intdiv($raw, $numBlocks);
        $divisor = self::rsDivisor($blockEccLen);
        $blocks = [];
        for ($i = 0, $k = 0; $i < $numBlocks; $i++) {
            $dat = array_slice($data, $k, $shortLen - $blockEccLen + ($i < $numShort ? 0 : 1));
            $k += count($dat);
            $ecc = self::rsRemainder($dat, $divisor);
            if ($i < $numShort) {
                $dat[] = 0;
            }
            $blocks[] = array_merge($dat, $ecc);
        }
        $result = [];
        $len = count($blocks[0]);
        for ($i = 0; $i < $len; $i++) {
            foreach ($blocks as $j => $block) {
                if ($i !== $shortLen - $blockEccLen || $j >= $numShort) {
                    $result[] = $block[$i];
                }
            }
        }
        return $result;
    }

    private function drawCodewords(array $data): void
    {
        $i = 0;
        $total = count($data) * 8;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $y = (($right + 1) & 2) === 0 ? $this->size - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($invert && !$this->isFunction[$y][$x]) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    private function penaltyScore(): int
    {
        $result = 0;
        $n = $this->size;
        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $n; $a++) {
                $runColor = false;
                $run = 0;
                $history = [0, 0, 0, 0, 0, 0, 0];
                for ($b = 0; $b < $n; $b++) {
                    $c = $pass === 0 ? $this->modules[$a][$b] : $this->modules[$b][$a];
                    if ($c === $runColor) {
                        $run++;
                        if ($run === 5) {
                            $result += 3;
                        } elseif ($run > 5) {
                            $result++;
                        }
                    } else {
                        $this->addHistory($run, $history);
                        if (!$runColor) {
                            $result += $this->countPatterns($history) * 40;
                        }
                        $runColor = $c;
                        $run = 1;
                    }
                }
                if ($runColor) {
                    $this->addHistory($run, $history);
                    $run = 0;
                }
                $this->addHistory($run + $n, $history);
                $result += $this->countPatterns($history) * 40;
            }
        }
        $dark = 0;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $c = $this->modules[$y][$x];
                $dark += $c ? 1 : 0;
                if ($y < $n - 1 && $x < $n - 1 && $c === $this->modules[$y][$x + 1] && $c === $this->modules[$y + 1][$x] && $c === $this->modules[$y + 1][$x + 1]) {
                    $result += 3;
                }
            }
        }
        $total = $n * $n;
        $k = (int) ceil(abs($dark * 20 - $total * 10) / $total) - 1;
        return $result + $k * 10;
    }

    private function countPatterns(array $h): int
    {
        $n = $h[1];
        $core = $n > 0 && $h[2] === $n && $h[3] === $n * 3 && $h[4] === $n && $h[5] === $n;
        return ($core && $h[0] >= $n * 4 && $h[6] >= $n ? 1 : 0) + ($core && $h[6] >= $n * 4 && $h[0] >= $n ? 1 : 0);
    }

    private function addHistory(int $run, array &$h): void
    {
        if ($h[0] === 0) {
            $run += $this->size;
        }
        array_pop($h);
        array_unshift($h, $run);
    }

    /** @return list<int> */
    private function alignmentPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }
        $num = intdiv($this->version, 7) + 2;
        $step = $this->version === 32 ? 26 : (int) ceil(($this->version * 4 + 4) / ($num * 2 - 2)) * 2;
        $result = [6];
        for ($pos = $this->size - 7; count($result) < $num; $pos -= $step) {
            array_splice($result, 1, 0, [$pos]);
        }
        return $result;
    }

    private static function numRawDataModules(int $ver): int
    {
        $result = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $num = intdiv($ver, 7) + 2;
            $result -= (25 * $num - 10) * $num - 55;
            if ($ver >= 7) {
                $result -= 36;
            }
        }
        return $result;
    }

    private static function numDataCodewords(int $ver, int $ecl): int
    {
        $idx = self::ECL[$ecl][0];
        return intdiv(self::numRawDataModules($ver), 8) - self::ECC_CODEWORDS_PER_BLOCK[$idx][$ver] * self::NUM_ERROR_CORRECTION_BLOCKS[$idx][$ver];
    }

    /** @return list<int> */
    private static function rsDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::rsMultiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::rsMultiply($root, 0x02);
        }
        return $result;
    }

    /** @return list<int> */
    private static function rsRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coef) {
                $result[$i] ^= self::rsMultiply($coef, $factor);
            }
        }
        return $result;
    }

    private static function rsMultiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }

    private static function appendBits(int $val, int $len, array &$bits): void
    {
        for ($i = $len - 1; $i >= 0; $i--) {
            $bits[] = ($val >> $i) & 1;
        }
    }
}
