<?php
declare(strict_types=1);

namespace Pase\Services;

use Pase\Support\Pdf;

/**
 * Lista produkcji jako plik PDF (A4) - ten sam układ co strona do druku. Potrzebny w aplikacji na iPhonie:
 * tam window.print() nic nie robi, a plik PDF udostępniony przez navigator.share daje arkusz z „Drukuj”.
 * Wejście: wynik ProductionList::build().
 */
final class ProductionListPdf
{
    private const L = 12.0;          // margines lewy
    private const R = 198.0;         // prawa krawędź treści
    private const BOTTOM = 284.0;    // dół treści (niżej numer strony)

    private Pdf $pdf;
    private float $y = 0.0;
    private bool $numbers = true;

    /** @param array{rows:list<array>,orders:list<array>,materials:list<array>,total:int} $list */
    public function render(array $list, bool $numbers = true, ?string $when = null): string
    {
        $this->pdf = new Pdf();
        $this->numbers = $numbers;
        $this->pdf->setTitle('Lista produkcji ' . date('d.m.Y'));
        $this->pdf->addPage();
        $p = $this->pdf;
        $nOrders = count($list['orders']);
        $p->text(self::L, 20, 'Lista produkcji', 17, true);
        $p->text(self::R, 15, $when ?? date('d.m.Y H:i'), 9, false, '444444', 'R');
        $p->text(self::R, 20, $nOrders . ' ' . self::plural($nOrders, 'zamówienie', 'zamówienia', 'zamówień') . ' · '
            . (int) $list['total'] . ' szt. · ' . count($list['rows']) . ' ' . self::plural(count($list['rows']), 'pozycja', 'pozycje', 'pozycji'),
            9, false, '444444', 'R');
        $p->line(self::L, 23, self::R, 23, '111111', 0.6);
        $this->y = 23;

        if ($list['rows'] === []) {
            $p->text(self::L, 40, 'Brak produktów do zrobienia.', 11);
            return $this->finish();
        }

        $this->itemsHeader();
        $nameW = $numbers ? 104.0 : 160.0;
        foreach ($list['rows'] as $r) {
            $name = $p->wrap($r['name'] . ($r['sku'] !== '' ? '  ' : ''), $nameW, 10.5, true);
            $attrs = $r['attrs'] !== [] ? $p->wrap(ProductionList::attrText($r['attrs']), $nameW, 9) : [];
            $details = $r['details'] !== [] ? $p->wrap(implode(' · ', array_map(static fn(array $d) => $d['label'] . ': ' . $d['value']
                . ($d['total'] !== null ? ' (' . ProductionList::amount($d['total'], $d['unit']) . ')' : ''), $r['details'])), $nameW, 8.5) : [];
            $orders = $numbers ? $p->wrap(implode(', ', array_map(static fn($label, $q) => $label . ($q > 1 ? ' ×' . (int) $q : ''),
                array_keys($r['orders']), $r['orders'])), 194 - 152, 8) : [];
            $h = 3 + count($name) * 4.6 + count($attrs) * 4.1 + count($details) * 3.8;
            $h = max($h, 3 + count($orders) * 3.6, 10) + 1.5;
            if ($this->y + $h > self::BOTTOM) {
                $this->newPage();
                $this->itemsHeader();
            }
            $top = $this->y;
            $p->rect(self::L + 1, $top + 2.6, 4.2, 4.2, null, '111111', 0.35);
            $p->text(34, $top + 6.6, (string) (int) $r['qty'], 13, true, '111111', 'R');
            $ly = $top + 6.4;
            foreach ($name as $i => $line) {
                $p->text(38, $ly, $line, 10.5, true);
                if ($i === count($name) - 1 && $r['sku'] !== '') {
                    $sx = 38 + $p->width($line . '  ', 10.5, true);
                    if ($sx + $p->width($r['sku'], 7.5) <= 38 + $nameW + 8) {
                        $p->text($sx, $ly, $r['sku'], 7.5, false, '666666');
                    }
                }
                $ly += 4.6;
            }
            $ly -= 0.4;
            foreach ($attrs as $line) {
                $p->text(38, $ly, $line, 9);
                $ly += 4.1;
            }
            foreach ($details as $line) {
                $p->text(38, $ly - 0.2, $line, 8.5, false, '333333');
                $ly += 3.8;
            }
            $oy = $top + 6;
            foreach ($orders as $line) {
                $p->text(152, $oy, $line, 8, false, '444444');
                $oy += 3.6;
            }
            $this->y = $top + $h;
            $p->line(self::L, $this->y, self::R, $this->y, 'CFCCC5', 0.2);
        }
        $this->need(10);
        $p->text(34, $this->y + 7, (string) (int) $list['total'], 13, true, '111111', 'R');
        $p->text(38, $this->y + 7, 'Razem sztuk', 10, true);
        $this->y += 10;

        if ($list['materials'] !== []) {
            $this->section('Materiały razem');
            foreach ($list['materials'] as $m) {
                $this->need(7.5);
                $p->rect(self::L + 1, $this->y + 1.8, 4.2, 4.2, null, '111111', 0.35);
                $p->text(20, $this->y + 5.2, $m['label'] . ': ' . $m['value'], 9.5);
                $p->text(self::R - 1, $this->y + 5.2, ProductionList::amount($m['total'], $m['unit']), 9.5, true, '111111', 'R');
                $this->y += 7.5;
                $p->line(self::L, $this->y, self::R, $this->y, 'CFCCC5', 0.2);
            }
        }

        $notes = array_values(array_filter($list['orders'], static fn(array $o) => $o['note'] !== ''));
        if ($notes !== []) {
            $this->section('Uwagi klientów');
            foreach ($notes as $o) {
                $lines = $p->wrap($o['note'], 112, 9);
                $h = max(1, count($lines)) * 4.2 + 3;
                $this->need($h);
                $p->text(self::L + 1, $this->y + 4.6, $o['label'], 9, true);
                $p->text(36, $this->y + 4.6, mb_strimwidth($o['customer'], 0, 28, '…'), 9);
                foreach ($lines as $i => $line) {
                    $p->text(86, $this->y + 4.6 + $i * 4.2, $line, 9);
                }
                $this->y += $h;
                $p->line(self::L, $this->y, self::R, $this->y, 'CFCCC5', 0.2);
            }
        }

        $this->section('Zamówienia na liście');
        foreach ($p->wrap(implode(', ', array_map(static fn(array $o) => $o['label'] . ' ' . $o['customer'], $list['orders'])), self::R - self::L, 8.5) as $line) {
            $this->need(4.2);
            $p->text(self::L, $this->y + 4, $line, 8.5, false, '444444');
            $this->y += 4.2;
        }
        return $this->finish();
    }

    private function itemsHeader(): void
    {
        $p = $this->pdf;
        $p->text(34, $this->y + 5.5, 'SZT.', 7.5, true, '555555', 'R');
        $p->text(38, $this->y + 5.5, 'PRODUKT', 7.5, true, '555555');
        if ($this->numbers) {
            $p->text(152, $this->y + 5.5, 'ZAMÓWIENIA', 7.5, true, '555555');
        }
        $this->y += 7.5;
        $p->line(self::L, $this->y, self::R, $this->y, '111111', 0.3);
    }

    private function section(string $title): void
    {
        $this->need(16);
        $this->y += 6;
        $this->pdf->text(self::L, $this->y + 4, mb_strtoupper($title), 9, true, '333333');
        $this->y += 6;
    }

    private function need(float $h): void
    {
        if ($this->y + $h > self::BOTTOM) {
            $this->newPage();
        }
    }

    private function newPage(): void
    {
        $this->pdf->addPage();
        $this->y = 12;
    }

    private function finish(): string
    {
        $n = $this->pdf->pageCount();
        if ($n > 1) {
            for ($i = 0; $i < $n; $i++) {
                $this->pdf->setPage($i);
                $this->pdf->text(self::R, 291, 'Strona ' . ($i + 1) . ' z ' . $n, 7.5, false, '666666', 'R');
            }
        }
        return $this->pdf->output();
    }

    private static function plural(int $n, string $one, string $few, string $many): string
    {
        if ($n === 1) {
            return $one;
        }
        return ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14)) ? $few : $many;
    }
}
