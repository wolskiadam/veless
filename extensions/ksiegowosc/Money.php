<?php
declare(strict_types=1);

namespace PaseExt\Ksiegowosc;

/** Kwoty w groszach (int): „1 234,56” → 123456, i z powrotem. Bez liczb zmiennoprzecinkowych w księgach. */
final class Money
{
    /** Kwota z formularza w groszach; puste → null; niepoprawna → wyjątek. */
    public static function parse(mixed $v): ?int
    {
        $s = str_replace([' ', "\u{a0}", 'zł', 'zl'], '', trim((string) $v));
        if ($s === '') {
            return null;
        }
        $s = str_replace(',', '.', $s);
        if (!preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', $s, $m)) {
            throw new \RuntimeException('Niepoprawna kwota: ' . trim((string) $v) . '.');
        }
        $gr = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '0', 2, '0');
        if ($gr > 99_999_999_999_99) {
            throw new \RuntimeException('Za duża kwota: ' . trim((string) $v) . '.');
        }
        return ($m[1] ?? '') === '-' ? -$gr : $gr;
    }

    /** 123456 → „1 234,56” (z twardą spacją); 0 → „0,00”. */
    public static function format(int $gr, bool $blankZero = false): string
    {
        if ($gr === 0 && $blankZero) {
            return '';
        }
        $abs = abs($gr);
        return ($gr < 0 ? '-' : '') . number_format(intdiv($abs, 100), 0, ',', "\u{a0}") . ',' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Do pola formularza: 123456 → „1234,56”, 0 → „”. */
    public static function input(int $gr): string
    {
        if ($gr === 0) {
            return '';
        }
        $abs = abs($gr);
        return ($gr < 0 ? '-' : '') . intdiv($abs, 100) . ',' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
