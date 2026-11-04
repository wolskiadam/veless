<?php
declare(strict_types=1);

namespace PaseExt\Produkcja;

/**
 * Numer partii - ten sam wzór co w Rejestrze Partii (batch-number-generator-pro), żeby stare numery dalej się odczytywały.
 *
 * Znaczniki wzoru: {DD} {MM} {YY} {YYYY} dzień/miesiąc/rok cyframi, {DC} {MC} {YC} dzień/miesiąc/rok literami
 * (alfabet bez I i O), {QTY} ilość na 3 cyfrach, {WC} kod wosku, {SC} kod zapachu, {WK} knot (B bawełniany,
 * K drewniany, X inny), {PC} kod zmiany albo kolejna wolna litera, {SEQ} numer partii tego dnia (2 cyfry).
 */
final class BatchCode
{
    public const DEFAULT_PATTERN = '{DC}{QTY}{MC}{YC}{WC}{SC}{WK}{PC}';
    private const LETTERS = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const MONTHS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'J', 'K', 'L', 'M'];
    private const YEARS = [2024 => 'V', 2025 => 'W', 2026 => 'X', 2027 => 'Y', 2028 => 'Z', 2029 => 'A', 2030 => 'B', 2031 => 'C'];
    private const LENGTHS = ['{YYYY}' => 4, '{DD}' => 2, '{MM}' => 2, '{YY}' => 2, '{SEQ}' => 2, '{DC}' => 2, '{MC}' => 1, '{YC}' => 1,
        '{WC}' => 2, '{SC}' => 2, '{WK}' => 1, '{PC}' => 1, '{QTY}' => 3];
    private const MONTH_NAMES = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];

    /**
     * @param array{wax?:string,scent?:string,wick?:string,shift?:string,qty?:int,seq?:int} $p
     * @param list<string> $existing numery już zapisane (do wolnej litery {PC})
     */
    public static function generate(string $pattern, string $date, array $p, array $existing = []): string
    {
        $ts = strtotime($date) ?: time();
        [$d, $m, $y] = [(int) date('j', $ts), (int) date('n', $ts), (int) date('Y', $ts)];
        $shift = strtoupper(substr(trim((string) ($p['shift'] ?? '')), 0, 1));
        $code = strtr($pattern === '' ? self::DEFAULT_PATTERN : $pattern, [
            '{YYYY}' => (string) $y,
            '{DD}'  => sprintf('%02d', $d),
            '{MM}'  => sprintf('%02d', $m),
            '{YY}'  => substr((string) $y, -2),
            '{SEQ}' => sprintf('%02d', max(1, (int) ($p['seq'] ?? 1))),
            '{DC}'  => self::LETTERS[intdiv($d - 1, 24)] . self::LETTERS[($d - 1) % 24],
            '{MC}'  => self::MONTHS[$m - 1],
            '{YC}'  => self::YEARS[$y] ?? 'X',
            '{QTY}' => sprintf('%03d', min(999, max(1, (int) ($p['qty'] ?? 1)))),
            '{WC}'  => self::fixed((string) ($p['wax'] ?? ''), 2, '00'),
            '{SC}'  => self::fixed((string) ($p['scent'] ?? ''), 2, 'AA'),
            '{WK}'  => self::wick((string) ($p['wick'] ?? '')),
            '{PC}'  => $shift !== '' ? $shift : "\0",
        ]);
        $code = strtoupper($code);
        $pos = strpos($code, "\0");
        if ($pos === false) {
            return $code;
        }
        // Bez zmiany: pierwsza litera, której nie ma jeszcze w numerze o tej samej reszcie (jak w Rejestrze Partii).
        $base = substr($code, 0, $pos) . substr($code, $pos + 1);
        $used = [];
        foreach ($existing as $c) {
            $c = strtoupper((string) $c);
            if (strlen($c) === strlen($code) && substr($c, 0, $pos) . substr($c, $pos + 1) === $base) {
                $used[$c[$pos]] = true;
            }
        }
        foreach (str_split(self::LETTERS) as $l) {
            if (!isset($used[$l])) {
                return substr($code, 0, $pos) . $l . substr($code, $pos + 1);
            }
        }
        return substr($code, 0, $pos) . 'Z' . substr($code, $pos + 1);
    }

    /** Wycena robocza nie ma jeszcze numeru partii. */
    public static function draft(string $date): string
    {
        $rand = '';
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($i = 0; $i < 4; $i++) {
            $rand .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return 'WYC-' . str_replace('-', '', $date) . '-' . $rand;
    }

    /** Knot z nazwy, jak w Rejestrze Partii. */
    public static function wick(string $name): string
    {
        $n = mb_strtolower($name);
        return str_contains($n, 'bawełn') ? 'B' : (str_contains($n, 'drewn') ? 'K' : 'X');
    }

    /**
     * Odczyt numeru wg wzoru.
     * @param array{wax?:array<string,string>,scent?:array<string,string>,shift?:array<string,string>} $maps kod => nazwa
     * @return list<array{0:string,1:string}> [etykieta, wartość]
     */
    public static function decode(string $pattern, string $code, array $maps = []): array
    {
        $code = strtoupper(trim($code));
        $pattern = $pattern === '' ? self::DEFAULT_PATTERN : $pattern;
        $out = [];
        $pos = 0;
        while ($pattern !== '') {
            $tag = null;
            foreach (self::LENGTHS as $t => $len) {
                if (str_starts_with($pattern, $t)) {
                    $tag = $t;
                    break;
                }
            }
            if ($tag === null) {
                if (($code[$pos] ?? '') === $pattern[0]) {
                    $pos++;
                }
                $pattern = substr($pattern, 1);
                continue;
            }
            $len = self::LENGTHS[$tag];
            $val = substr($code, $pos, $len);
            $pos += $len;
            $pattern = substr($pattern, strlen($tag));
            if ($val === '') {
                continue;
            }
            $out[] = match ($tag) {
                '{DD}' => ['Dzień', (string) (int) $val],
                '{MM}' => ['Miesiąc', self::MONTH_NAMES[(int) $val - 1] ?? $val],
                '{YY}' => ['Rok', '20' . $val],
                '{YYYY}' => ['Rok', $val],
                '{SEQ}' => ['Nr kolejny', $val],
                '{DC}' => ['Dzień', strlen($val) === 2 && strpos(self::LETTERS, $val[0]) !== false && strpos(self::LETTERS, $val[1]) !== false
                    ? (string) (strpos(self::LETTERS, $val[0]) * 24 + strpos(self::LETTERS, $val[1]) + 1) : $val],
                '{MC}' => ['Miesiąc', self::MONTH_NAMES[array_search($val, self::MONTHS, true) === false ? 99 : array_search($val, self::MONTHS, true)] ?? $val],
                '{YC}' => ['Rok', (string) (array_search($val, self::YEARS, true) ?: $val)],
                '{WC}' => ['Wosk', $maps['wax'][$val] ?? $val],
                '{SC}' => ['Zapach', $maps['scent'][$val] ?? $val],
                '{WK}' => ['Knot', $val === 'B' ? 'bawełniany' : ($val === 'K' ? 'drewniany' : 'inny')],
                '{PC}' => ['Zmiana / partia', $maps['shift'][$val] ?? $val],
                '{QTY}' => ['Ilość', (string) (int) $val],
            };
        }
        return $out;
    }

    private static function fixed(string $v, int $len, string $default): string
    {
        $v = strtoupper(trim($v));
        return $v === '' ? $default : str_pad(substr($v, 0, $len), $len, '0', STR_PAD_LEFT);
    }
}
