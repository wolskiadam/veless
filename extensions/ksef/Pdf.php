<?php
declare(strict_types=1);

namespace PaseExt\Ksef;

/**
 * Minimalny generator PDF (A4, współrzędne w mm od lewego górnego rogu) do wizualizacji faktur.
 * Bez zależności: czcionki standardowe PDF (Helvetica i Helvetica-Bold, niewbudowane) z własnym kodowaniem,
 * które obejmuje polskie litery - każda przeglądarka PDF ma te czcionki i glify.
 * Tekst spoza tabeli znaków zastępuje „?”.
 */
final class Pdf
{
    public const W = 210.0;
    public const H = 297.0;
    private const K = 72 / 25.4;

    /** Znaki spoza ASCII: UTF-8 => [kod bajtu, nazwa glifu, szerokość Helvetica, szerokość Helvetica-Bold]. */
    private const EXTRA = [
        'ą' => [128, 'aogonek', 556, 556], 'Ą' => [129, 'Aogonek', 667, 722], 'ć' => [130, 'cacute', 500, 556], 'Ć' => [131, 'Cacute', 722, 722],
        'ę' => [132, 'eogonek', 556, 556], 'Ę' => [133, 'Eogonek', 667, 667], 'ł' => [134, 'lslash', 222, 278], 'Ł' => [135, 'Lslash', 556, 611],
        'ń' => [136, 'nacute', 556, 611], 'Ń' => [137, 'Nacute', 722, 722], 'ó' => [138, 'oacute', 556, 611], 'Ó' => [139, 'Oacute', 778, 778],
        'ś' => [140, 'sacute', 500, 556], 'Ś' => [141, 'Sacute', 667, 667], 'ź' => [142, 'zacute', 500, 500], 'Ź' => [143, 'Zacute', 611, 611],
        'ż' => [144, 'zdotaccent', 500, 500], 'Ż' => [145, 'Zdotaccent', 611, 611], '€' => [146, 'Euro', 556, 556], '„' => [147, 'quotedblbase', 333, 500],
        '”' => [148, 'quotedblright', 333, 500], '“' => [149, 'quotedblleft', 333, 500], '–' => [150, 'endash', 556, 556], '—' => [151, 'emdash', 1000, 1000],
        '…' => [152, 'ellipsis', 1000, 1000], '•' => [153, 'bullet', 350, 350], '°' => [154, 'degree', 400, 400], '§' => [155, 'section', 556, 556],
        'é' => [156, 'eacute', 556, 556], 'É' => [157, 'Eacute', 667, 667], 'ü' => [158, 'udieresis', 556, 611], 'Ü' => [159, 'Udieresis', 722, 722],
        'ö' => [160, 'odieresis', 556, 611], 'Ö' => [161, 'Odieresis', 778, 778], 'ä' => [162, 'adieresis', 556, 556], 'Ä' => [163, 'Adieresis', 667, 722],
        'ß' => [164, 'germandbls', 611, 611], '×' => [165, 'multiply', 584, 584], '·' => [166, 'periodcentered', 278, 278], '’' => [167, 'quoteright', 222, 278],
        '‘' => [168, 'quoteleft', 222, 278], 'á' => [169, 'aacute', 556, 556], 'í' => [170, 'iacute', 278, 278], 'ú' => [171, 'uacute', 556, 611],
        'č' => [172, 'ccaron', 500, 556], 'š' => [173, 'scaron', 500, 556], 'ž' => [174, 'zcaron', 500, 500], 'ř' => [175, 'rcaron', 333, 389],
        'ě' => [176, 'ecaron', 556, 556], 'Č' => [177, 'Ccaron', 722, 722], 'Š' => [178, 'Scaron', 667, 667], 'Ž' => [179, 'Zcaron', 611, 611],
    ];

    /** Szerokości ASCII 32-126 (1/1000 em). */
    private const WIDTHS = [
        [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
            1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
            333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584],
        [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
            975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
            333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584],
    ];

    /** @var list<string> strumienie treści stron */
    private array $pages = [];
    private string $title = '';
    private int $current = -1;
    /** @var array<int,list<array{0:string,1:string}>> linki stron: [prostokąt w pt, URL] */
    private array $links = [];

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->current = count($this->pages) - 1;
    }

    /** Wraca do strony $i (od 0), np. by dopisać stopkę z liczbą stron. */
    public function setPage(int $i): void
    {
        if (!isset($this->pages[$i])) {
            throw new \OutOfRangeException('Nie ma strony ' . ($i + 1) . '.');
        }
        $this->current = $i;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    /** Tekst od lewej krawędzi $x; $y to linia bazowa. $align: L, R (x = prawa krawędź), C (x = środek). */
    public function text(float $x, float $y, string $s, float $size = 9, bool $bold = false, string $color = '000000', string $align = 'L'): void
    {
        if ($s === '') {
            return;
        }
        if ($align !== 'L') {
            $w = $this->width($s, $size, $bold);
            $x -= $align === 'R' ? $w : $w / 2;
        }
        $this->out(sprintf('BT %s rg /F%d %.2F Tf %.2F %.2F Td (%s) Tj ET', $this->rgb($color), $bold ? 2 : 1, $size,
            $x * self::K, (self::H - $y) * self::K, $this->escape($this->encode($s))));
    }

    /** Szerokość tekstu w mm. */
    public function width(string $s, float $size = 9, bool $bold = false): float
    {
        $w = 0;
        foreach (mb_str_split($s) as $ch) {
            $o = ord($ch);
            if (strlen($ch) === 1 && $o >= 32 && $o <= 126) {
                $w += self::WIDTHS[$bold ? 1 : 0][$o - 32];
            } elseif (isset(self::EXTRA[$ch])) {
                $w += self::EXTRA[$ch][$bold ? 3 : 2];
            } else {
                $w += $bold ? 611 : 556;
            }
        }
        return $w * $size / 1000 / self::K;
    }

    /**
     * Dzieli tekst na wiersze mieszczące się w $width mm (po słowach; za długie słowo tnie na znaki).
     * @return list<string>
     */
    public function wrap(string $s, float $width, float $size = 9, bool $bold = false): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $s) ?: [] as $para) {
            $line = '';
            foreach (preg_split('/ +/u', trim($para)) ?: [] as $word) {
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($this->width($try, $size, $bold) <= $width) {
                    $line = $try;
                    continue;
                }
                if ($line !== '') {
                    $out[] = $line;
                }
                $line = '';
                foreach (mb_str_split($word) as $ch) {
                    if ($line !== '' && $this->width($line . $ch, $size, $bold) > $width) {
                        $out[] = $line;
                        $line = '';
                    }
                    $line .= $ch;
                }
            }
            $out[] = $line;
        }
        return $out;
    }

    public function rect(float $x, float $y, float $w, float $h, ?string $fill = null, ?string $stroke = null, float $lineWidth = 0.2): void
    {
        $op = $fill !== null && $stroke !== null ? 'B' : ($fill !== null ? 'f' : 'S');
        $this->out(($fill !== null ? $this->rgb($fill) . ' rg ' : '') . ($stroke !== null ? strtoupper($this->rgb($stroke)) . ' RG ' . sprintf('%.2F w ', $lineWidth * self::K) : '')
            . sprintf('%.2F %.2F %.2F %.2F re %s', $x * self::K, (self::H - $y - $h) * self::K, $w * self::K, $h * self::K, $op));
    }

    public function line(float $x1, float $y1, float $x2, float $y2, string $color = '000000', float $width = 0.2): void
    {
        $this->out(sprintf('%s RG %.2F w %.2F %.2F m %.2F %.2F l S', strtoupper($this->rgb($color)), $width * self::K,
            $x1 * self::K, (self::H - $y1) * self::K, $x2 * self::K, (self::H - $y2) * self::K));
    }

    /** Kod QR jako wektor (ciemne moduły), lewy górny róg w ($x, $y), bok $size mm bez marginesu. */
    public function qr(QrCode $qr, float $x, float $y, float $size): void
    {
        $m = $size / $qr->size;
        $ops = '0 0 0 rg';
        for ($r = 0; $r < $qr->size; $r++) {
            for ($c = 0; $c < $qr->size; $c++) {
                if ($qr->module($c, $r)) {
                    $ops .= sprintf(' %.3F %.3F %.3F %.3F re', ($x + $c * $m) * self::K, (self::H - $y - ($r + 1) * $m) * self::K, $m * self::K + 0.01, $m * self::K + 0.01);
                }
            }
        }
        $this->out($ops . ' f');
    }

    /** Link (obszar klikalny) na bieżącej stronie. */
    public function link(float $x, float $y, float $w, float $h, string $url): void
    {
        if ($this->pages === []) {
            $this->addPage();
        }
        $this->links[$this->current][] = [sprintf('%.2F %.2F %.2F %.2F', $x * self::K, (self::H - $y - $h) * self::K, ($x + $w) * self::K, (self::H - $y) * self::K), $url];
    }

    public function output(): string
    {
        $objects = [];
        $diff = '';
        foreach (self::EXTRA as [$code, $name]) {
            $diff .= ' ' . $code . ' /' . $name;
        }
        $objects[3] = '<< /Type /Encoding /BaseEncoding /WinAnsiEncoding /Differences [' . trim($diff) . '] >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding 3 0 R >>';
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding 3 0 R >>';
        $next = 6;
        $kids = [];
        foreach ($this->pages ?: [''] as $page => $stream) {
            $annots = [];
            foreach ($this->links[$page] ?? [] as [$rect, $url]) {
                $annots[] = $next;
                $objects[$next++] = '<< /Type /Annot /Subtype /Link /Rect [' . $rect . '] /Border [0 0 0] /A << /S /URI /URI (' . $this->escape($url) . ') >> >>';
            }
            $contentId = $next++;
            $objects[$contentId] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
            $kids[] = $next;
            $objects[$next++] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents '
                . $contentId . ' 0 R' . ($annots !== [] ? ' /Annots [' . implode(' ', array_map(static fn(int $i): string => $i . ' 0 R', $annots)) . ']' : '') . ' >>';
        }
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', array_map(static fn(int $i): string => $i . ' 0 R', $kids)) . '] /Count ' . count($kids) . ' >>';
        $info = $next;
        $objects[$info] = '<< /Producer (Veless) /Title <' . bin2hex("\xFE\xFF" . mb_convert_encoding($this->title, 'UTF-16BE', 'UTF-8')) . '> /CreationDate (D:' . gmdate('YmdHis') . 'Z) >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($info + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $info; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        return $pdf . 'trailer << /Size ' . ($info + 1) . ' /Root 1 0 R /Info ' . $info . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    private function out(string $op): void
    {
        if ($this->pages === []) {
            $this->addPage();
        }
        $this->pages[$this->current] .= $op . "\n";
    }

    private function encode(string $s): string
    {
        $out = '';
        foreach (mb_str_split($s) as $ch) {
            $o = ord($ch);
            if (strlen($ch) === 1 && $o >= 32 && $o <= 126) {
                $out .= $ch;
            } elseif (isset(self::EXTRA[$ch])) {
                $out .= chr(self::EXTRA[$ch][0]);
            } elseif ($ch === "\t" || $ch === "\u{00A0}") {
                $out .= ' ';
            } else {
                $out .= '?';
            }
        }
        return $out;
    }

    private function escape(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r', "\n" => '\\n']);
    }

    private function rgb(string $hex): string
    {
        $hex = str_pad(ltrim($hex, '#'), 6, '0');
        return sprintf('%.3F %.3F %.3F', hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255);
    }
}
