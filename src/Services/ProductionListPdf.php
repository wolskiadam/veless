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
    private bool $marked = false;

    /**
     * @param array{rows:list<array>,orders:list<array>,materials:list<array>,total:int} $list
     * @param bool $marked pozycje oznaczone wspólnym zapachem (ProductionList::markScents) - kolumna z numerem zapachu w kółku
     */
    public function render(array $list, bool $numbers = true, ?string $when = null, bool $marked = false): string
    {
        $this->pdf = new Pdf();
        $this->numbers = $numbers;
        $this->marked = $marked;
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
        // Gęsto, żeby na stronie zmieściło się dużo pozycji: nazwa (pogrubiona) + jedna linia parametrów, waga w kolumnie.
        $nameW = ($numbers ? 119.0 : 149.0) - ($marked ? 7.5 : 0);
        $nx = $marked ? 36.5 : 29.0;   // początek nazwy (przy literach zapachu przesunięty)
        $prevMark = '';
        foreach ($list['rows'] as $r) {
            $name = $p->wrap($r["name"], $nameW, 9, true);
            $params = array_merge(array_map(static fn(array $a) => $a[0] . ': ' . $a[1], $r['attrs']),
                array_map(static fn(array $d) => $d['label'] . ': ' . ($d['value'] === '' && $d['total'] !== null ? ProductionList::amount($d['total'], $d['unit'])
                    : $d['value'] . ($d['total'] !== null ? ' (' . ProductionList::amount($d['total'], $d['unit']) . ')' : '')), $r['details']));
            $paramText = implode(' · ', $params);
            $params = $paramText !== '' || $r['sku'] !== '' ? $p->wrap(trim($paramText . ($r['sku'] !== '' ? '   ' . $r['sku'] : '')), $nameW, 7.8) : [];
            $orders = $numbers ? $p->wrap(implode(', ', array_map(static fn($label, $q) => $label . ($q > 1 ? ' ×' . (int) $q : ''),
                array_keys($r['orders']), $r['orders'])), self::R - 170, 7) : [];
            $h = max(1.6 + count($name) * 3.6 + count($params) * 3.2, 1.6 + count($orders) * 3, 6.5) + 1.2;
            if ($this->y + $h > self::BOTTOM) {
                $this->newPage();
                $this->itemsHeader();
            }
            $mk = $marked ? (string) ($r['mark'] ?? '') : '';
            $shared = $mk !== '' && !empty($r['shared']);
            if ($shared && $mk !== $prevMark) {
                // Pierwsza pozycja ze wspólnym zapachem: grubsza linia nad nią.
                $p->line(self::L, $this->y, self::R, $this->y, '111111', 0.5);
            }
            $prevMark = $shared ? $mk : '';
            $top = $this->y;
            $p->rect(self::L + 0.8, $top + 1.6, 3.4, 3.4, null, '111111', 0.3);
            $p->text(26, $top + 4.6, (string) (int) $r['qty'], 11, true, '111111', 'R');
            if ($mk !== '') {
                // Numer zapachu w kółku; wspólny zapach (kilka pozycji) - kółko wypełnione na czarno, biały numer.
                $fs = strlen($mk) > 2 ? 6.5 : 8;
                $p->circle(31.5, $top + 3.4, max(2.3, $p->width($mk, $fs, true) / 2 + 0.7), '111111', 0.4, $shared ? '111111' : null);
                $p->text(31.5, $top + 3.4 + $fs * 0.125, $mk, $fs, true, $shared ? 'FFFFFF' : '111111', 'C');
            }
            $ly = $top + 4.4;
            foreach ($name as $line) {
                $p->text($nx, $ly, $line, 9, true);
                $ly += 3.6;
            }
            $ly -= 0.3;
            foreach ($params as $line) {
                $p->text($nx, $ly, $line, 7.8, false, '333333');
                $ly += 3.2;
            }
            if ($r['weight'] !== '') {
                $p->text($numbers ? 151 : 181, $top + 4.4, $r['weight'], 9.5, true);
            }
            $oy = $top + 4.2;
            foreach ($orders as $line) {
                $p->text(170, $oy, $line, 7, false, '444444');
                $oy += 3;
            }
            $this->y = $top + $h;
            $p->line(self::L, $this->y, self::R, $this->y, 'D9D6CF', 0.2);
        }
        $this->need(8);
        $p->text(26, $this->y + 5.5, (string) (int) $list['total'], 11, true, '111111', 'R');
        $p->text(29, $this->y + 5.5, 'Razem sztuk', 9.5, true);
        if ($marked && array_filter(array_column($list['rows'], 'shared')) !== []) {
            $p->circle(self::R - 62, $this->y + 4.5, 1.6, '111111', 0.3, '111111');
            $p->text(self::R - 59.5, $this->y + 5.5, 'czarne kółko = ten sam zapach w kilku pozycjach', 7.5, false, '444444');
        }
        $this->y += 8;

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
        $p->text(26, $this->y + 4.5, 'SZT.', 6.5, true, '555555', 'R');
        if ($this->marked) {
            $p->text(31.5, $this->y + 4.5, 'ZAP.', 6.5, true, '555555', 'C');
        }
        $p->text($this->marked ? 36.5 : 29, $this->y + 4.5, 'PRODUKT', 6.5, true, '555555');
        $p->text($this->numbers ? 151 : 181, $this->y + 4.5, 'WAGA', 6.5, true, '555555');
        if ($this->numbers) {
            $p->text(170, $this->y + 4.5, 'ZAMÓWIENIA', 6.5, true, '555555');
        }
        $this->y += 6;
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
