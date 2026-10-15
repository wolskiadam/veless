<?php
declare(strict_types=1);

namespace PaseExt\Ksef;

/**
 * PDF faktury z XML KSeF - ten sam układ co podgląd (InvoiceView::html), na wzór wizualizacji KSeF:
 * nagłówek z numerem, strony, szczegóły, pozycje, stawki, płatność, kod QR z linkiem weryfikacyjnym.
 * PDF jest tylko wizualizacją; dokumentem jest XML w KSeF.
 */
final class InvoicePdf
{
    private const L = 15.0;
    private const R = 195.0;
    private const BOTTOM = 280.0;
    private const INK = '2c2a26';
    private const MUTED = '6b675f';
    private const LINE = 'd9d5cc';
    private const HEAD = 'f2efe8';

    private Pdf $pdf;
    private float $y = 0;

    private function __construct(private readonly array $inv, private readonly array $meta)
    {
        $this->pdf = new Pdf();
    }

    /** @param array{ksef?:string,qr?:string} $meta */
    public static function render(string $xml, array $meta = []): string
    {
        $self = new self(InvoiceView::parse($xml), $meta);
        return $self->build();
    }

    private function build(): string
    {
        $inv = $this->inv;
        $p = $this->pdf;
        $p->setTitle('Faktura ' . $inv['number']);
        $this->newPage();

        // Nagłówek
        $p->text(self::L, $this->y + 3, 'NUMER FAKTURY', 7, false, self::MUTED);
        $p->text(self::L, $this->y + 10, $inv['number'] ?: '-', 16, true, self::INK);
        $p->text(self::L, $this->y + 15.5, InvoiceView::kindTitle($inv['kind']), 9, false, self::MUTED);
        if (!empty($this->meta['ksef'])) {
            $p->text(self::R, $this->y + 3, 'NUMER KSEF', 7, false, self::MUTED, 'R');
            $p->text(self::R, $this->y + 8, (string) $this->meta['ksef'], 9, true, self::INK, 'R');
        }
        $this->y += 19;
        $p->line(self::L, $this->y, self::R, $this->y, self::INK, 0.5);
        $this->y += 5;

        // Sprzedawca i nabywca
        $colW = (self::R - self::L - 6) / 2;
        $boxes = [];
        foreach ([['Sprzedawca', $inv['seller']], ['Nabywca', $inv['buyer']]] as $i => [$title, $party]) {
            $lines = [];
            foreach ($p->wrap($party['name'] ?: '-', $colW - 8, 10, true) as $l) {
                $lines[] = [$l, 10, true];
            }
            foreach ([InvoiceView::partyId($party), $party['address'], $party['contact']] as $t) {
                foreach ($t !== '' ? $p->wrap($t, $colW - 8, 9) : [] as $l) {
                    $lines[] = [$l, 9, false];
                }
            }
            $boxes[$i] = [$title, $lines];
        }
        $boxH = 10 + max(array_map(static fn(array $b): float => array_sum(array_map(static fn(array $l): float => $l[1] * 0.45, $b[1])), $boxes));
        foreach ($boxes as $i => [$title, $lines]) {
            $x = self::L + $i * ($colW + 6);
            $p->rect($x, $this->y, $colW, $boxH, null, self::LINE, 0.3);
            $p->text($x + 4, $this->y + 5, mb_strtoupper($title), 7, true, self::MUTED);
            $ly = $this->y + 10;
            foreach ($lines as [$t, $size, $bold]) {
                $p->text($x + 4, $ly, $t, $size, $bold, self::INK);
                $ly += $size * 0.45;
            }
        }
        $this->y += $boxH + 2;

        // Szczegóły
        $this->section('Szczegóły');
        $this->keyValues(InvoiceView::details($inv));
        if ($inv['corrected'] !== []) {
            $this->paragraph('Korygowana faktura: ' . implode('; ', $inv['corrected']), true);
        }
        if ($inv['reason'] !== '') {
            $this->paragraph('Przyczyna korekty: ' . $inv['reason']);
        }

        // Pozycje
        $this->section('Pozycje');
        $cols = [['Lp.', 9, 'L'], ['Nazwa towaru lub usługi', 69, 'L'], ['Ilość', 16, 'R'], ['Miara', 14, 'L'], ['Cena jedn.', 24, 'R'], ['Stawka', 15, 'R'], ['Wartość', 33, 'R']];
        $rows = [];
        foreach ($inv['lines'] as $l) {
            [$price, $value, $basis] = InvoiceView::lineAmounts($l);
            $rows[] = [$l['no'], $l['name'], InvoiceView::qty($l['qty']), $l['unit'], InvoiceView::money($price), InvoiceView::rate($l['rate']),
                trim(InvoiceView::money($value) . ($basis !== '' ? ' ' . $basis : ''))];
        }
        if ($rows === []) {
            $this->paragraph('Faktura nie ma pozycji (np. korekta albo faktura zaliczkowa).');
        } else {
            $this->table($cols, $rows);
        }
        $this->ensure(10);
        $this->y += 3;
        $total = InvoiceView::money($inv['total']) . ' ' . $inv['currency'];
        $p->text(self::R, $this->y + 5, $total, 13, true, self::INK, 'R');
        $p->text(self::R - $p->width($total, 13, true) - 4, $this->y + 5, 'Kwota należności ogółem', 9, false, self::MUTED, 'R');
        $this->y += 9;

        if ($inv['rates'] !== []) {
            $this->section('Podsumowanie stawek podatku');
            $this->table([['Stawka', 60, 'L'], ['Kwota netto', 40, 'R'], ['Kwota podatku', 40, 'R'], ['Kwota brutto', 40, 'R']],
                array_map(static fn(array $r): array => [$r['label'], InvoiceView::money($r['net']), InvoiceView::money($r['vat']), InvoiceView::money($r['gross'])], $inv['rates']));
        }

        $pay = InvoiceView::paymentRows($inv['payment']);
        if ($pay !== []) {
            $this->section('Płatność');
            $this->keyValues($pay);
        }

        $extra = array_merge($inv['annotations'] !== [] ? ['Adnotacje: ' . implode(', ', $inv['annotations'])] : [], $inv['notes'],
            $inv['registers'] !== [] ? [implode(' · ', $inv['registers'])] : []);
        if ($extra !== []) {
            $this->section('Dodatkowe informacje');
            foreach ($extra as $n) {
                $this->paragraph($n);
            }
        }

        if (!empty($this->meta['qr'])) {
            $url = (string) $this->meta['qr'];
            $size = 32.0;
            $this->ensure($size + 10);
            $this->y += 6;
            $p->rect(self::L, $this->y, self::R - self::L, $size + 8, null, self::LINE, 0.3);
            $p->qr(QrCode::encode($url), self::L + 4, $this->y + 4, $size);
            $tx = self::L + $size + 10;
            $p->text($tx, $this->y + 10, 'Sprawdź, czy faktura znajduje się w KSeF', 10, true, self::INK);
            $p->text($tx, $this->y + 15, 'Zeskanuj kod QR albo otwórz link:', 8.5, false, self::MUTED);
            $ly = $this->y + 20;
            foreach ($p->wrap($url, self::R - $tx - 4, 8) as $l) {
                $p->text($tx, $ly, $l, 8, false, '1a56a8');
                $ly += 3.6;
            }
            $p->link($tx, $this->y + 16.5, self::R - $tx - 4, $ly - $this->y - 16.5, $url);
            if (!empty($this->meta['ksef'])) {
                $p->text($tx, $ly + 2, 'Numer KSeF: ' . $this->meta['ksef'], 8, false, self::MUTED);
            }
            $this->y += $size + 8;
        }
        return $this->footerAndOutput();
    }

    private function newPage(): void
    {
        $this->pdf->addPage();
        $this->y = 15;
    }

    private function ensure(float $h): void
    {
        if ($this->y + $h > self::BOTTOM) {
            $this->newPage();
        }
    }

    private function section(string $title): void
    {
        $this->ensure(16);
        $this->y += 6;
        $this->pdf->text(self::L, $this->y, mb_strtoupper($title), 8, true, self::MUTED);
        $this->y += 1.8;
        $this->pdf->line(self::L, $this->y, self::R, $this->y, self::LINE, 0.3);
        $this->y += 4.5;
    }

    /** Pary etykieta / wartość w trzech kolumnach. */
    private function keyValues(array $pairs): void
    {
        $colW = (self::R - self::L) / 3;
        $i = 0;
        foreach ($pairs as $label => $value) {
            if ($i % 3 === 0 && $i > 0) {
                $this->y += 9;
            }
            if ($i % 3 === 0) {
                $this->ensure(9);
            }
            $x = self::L + ($i % 3) * $colW;
            $this->pdf->text($x, $this->y, mb_strtoupper((string) $label), 6.5, false, self::MUTED);
            $this->pdf->text($x, $this->y + 4.2, $this->fit((string) $value, $colW - 3, 9), 9, false, self::INK);
            $i++;
        }
        $this->y += $i > 0 ? 8 : 0;
    }

    private function paragraph(string $text, bool $bold = false): void
    {
        foreach ($this->pdf->wrap($text, self::R - self::L, 9, $bold) as $l) {
            $this->ensure(5);
            $this->pdf->text(self::L, $this->y + 1, $l, 9, $bold, self::INK);
            $this->y += 4.3;
        }
        $this->y += 1;
    }

    /**
     * Tabela z nagłówkiem (powtarzanym na nowej stronie) i zawijaniem tekstu w komórkach.
     * @param list<array{0:string,1:float,2:string}> $cols nagłówek, szerokość w mm, wyrównanie
     * @param list<list<string>> $rows
     */
    private function table(array $cols, array $rows): void
    {
        $p = $this->pdf;
        $header = function () use ($cols, $p): void {
            $p->rect(self::L, $this->y, array_sum(array_column($cols, 1)), 6.5, self::HEAD);
            $x = self::L;
            foreach ($cols as [$title, $w, $align]) {
                $p->text($align === 'R' ? $x + $w - 1.5 : $x + 1.5, $this->y + 4.4, $title, 7.5, true, self::MUTED, $align);
                $x += $w;
            }
            $this->y += 6.5;
        };
        $this->ensure(14);
        $header();
        foreach ($rows as $row) {
            $cells = [];
            $lines = 1;
            foreach ($cols as $i => [, $w]) {
                $cells[$i] = $p->wrap((string) ($row[$i] ?? ''), $w - 3, 8.5);
                $lines = max($lines, count($cells[$i]));
            }
            $h = 2.8 + $lines * 3.8;
            if ($this->y + $h > self::BOTTOM) {
                $this->newPage();
                $header();
            }
            $x = self::L;
            foreach ($cols as $i => [, $w, $align]) {
                $ly = $this->y + 4.6;
                foreach ($cells[$i] as $t) {
                    $p->text($align === 'R' ? $x + $w - 1.5 : $x + 1.5, $ly, $t, 8.5, false, self::INK, $align);
                    $ly += 3.8;
                }
                $x += $w;
            }
            $this->y += $h;
            $p->line(self::L, $this->y, self::L + array_sum(array_column($cols, 1)), $this->y, self::LINE, 0.2);
        }
    }

    /** Skraca tekst do szerokości (z „…”). */
    private function fit(string $s, float $w, float $size): string
    {
        if ($this->pdf->width($s, $size) <= $w) {
            return $s;
        }
        while ($s !== '' && $this->pdf->width($s . '…', $size) > $w) {
            $s = mb_substr($s, 0, -1);
        }
        return $s . '…';
    }

    private function footerAndOutput(): string
    {
        // Stopka na końcu, bo dopiero teraz znamy liczbę stron.
        $n = $this->pdf->pageCount();
        for ($i = 0; $i < $n; $i++) {
            $this->pdf->setPage($i);
            $this->pdf->line(self::L, 287, self::R, 287, self::LINE, 0.2);
            $this->pdf->text(self::L, 291, 'Wizualizacja e-faktury z KSeF. Dokumentem jest plik XML w Krajowym Systemie e-Faktur.', 7, false, self::MUTED);
            $this->pdf->text(self::R, 291, 'Strona ' . ($i + 1) . ' z ' . $n, 7, false, self::MUTED, 'R');
        }
        return $this->pdf->output();
    }
}
