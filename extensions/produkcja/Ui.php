<?php
declare(strict_types=1);

namespace PaseExt\Produkcja;

use Pase\Plugin\Hooks;

/** Wspólne kawałki stron: formatowanie, zakładki, styl. */
final class Ui
{
    public static function h(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    public static function url(string $page, array $q = []): string
    {
        return Hooks::pageUrl('produkcja.' . $page, $q);
    }

    public static function money(mixed $v, int $dec = 2): string
    {
        return $v === null || $v === '' ? '-' : number_format((float) $v, $dec, ',', "\u{a0}") . "\u{a0}zł";
    }

    public static function n(mixed $v): string
    {
        $s = number_format((float) $v, 3, ',', "\u{a0}");
        return rtrim(rtrim($s, '0'), ',');
    }

    public static function qty(mixed $v, string $unit): string
    {
        $v = (float) $v;
        if ($unit === 'g' && abs($v) >= 1000) {
            return self::n($v / 1000) . "\u{a0}kg";
        }
        if ($unit === 'ml' && abs($v) >= 1000) {
            return self::n($v / 1000) . "\u{a0}l";
        }
        return self::n($v) . "\u{a0}" . (Store::UNITS[$unit] ?? $unit);
    }

    /** Cena za kg/l/szt. z ceny za g/ml/szt. */
    public static function unitPrice(?float $perUnit, string $unit): string
    {
        if ($perUnit === null) {
            return '-';
        }
        return in_array($unit, ['g', 'ml'], true) ? self::money($perUnit * 1000) . '/' . ($unit === 'g' ? 'kg' : 'l') : self::money($perUnit) . '/szt.';
    }

    public static function date(?string $ymd): string
    {
        if ($ymd === null || $ymd === '') {
            return '-';
        }
        $t = strtotime($ymd);
        return $t ? date('d.m.Y', $t) : $ymd;
    }

    public static function csrf(array $ctx): string
    {
        return '<input type="hidden" name="csrf" value="' . self::h($ctx['csrf']) . '">';
    }

    public static function input(string $name, mixed $value, string $label, string $attrs = '', string $hint = ''): string
    {
        return '<label>' . $label . '<input name="' . self::h($name) . '" value="' . self::h($value) . '" ' . $attrs . '>'
            . ($hint !== '' ? '<small>' . $hint . '</small>' : '') . '</label>';
    }

    /** @param array<string,string> $options */
    public static function select(string $name, array $options, string $current, string $label = '', string $attrs = ''): string
    {
        $o = '';
        foreach ($options as $k => $v) {
            $o .= '<option value="' . self::h($k) . '"' . ((string) $k === $current ? ' selected' : '') . '>' . self::h($v) . '</option>';
        }
        $sel = '<select name="' . self::h($name) . '" ' . $attrs . '>' . $o . '</select>';
        return $label === '' ? $sel : '<label>' . $label . $sel . '</label>';
    }

    /** Zakładki wtyczki nad treścią (ta sama nawigacja na telefonie i komputerze). */
    public static function tabs(string $active): string
    {
        $tabs = ['pulpit' => 'Pulpit', 'materialy' => 'Materiały i magazyn', 'wyroby' => 'Wyroby', 'partie' => 'Partie', 'testy' => 'Testy spalania'];
        $html = '<nav class="pr-tabs">';
        foreach ($tabs as $k => $label) {
            $html .= '<a class="' . ($k === $active ? 'on' : '') . '" href="' . self::h(self::url($k)) . '">' . $label . '</a>';
        }
        return $html . '</nav>';
    }

    public static function empty(string $title, string $text, string $actions = ''): string
    {
        return '<div class="card pr-empty"><div class="pr-empty-ic">🕯</div><h3>' . $title . '</h3><p>' . $text . '</p>'
            . ($actions !== '' ? '<div class="pr-actions">' . $actions . '</div>' : '') . '</div>';
    }

    public static function back(string $href, string $label): string
    {
        return '<p class="pr-back"><a href="' . self::h($href) . '">← ' . self::h($label) . '</a></p>';
    }

    public static function style(): string
    {
        return '<style>
.pr-tabs{display:flex;gap:4px;flex-wrap:wrap;margin:-6px 0 16px;border-bottom:1px solid var(--line)}
.pr-tabs a{padding:8px 12px;color:var(--ink-2);text-decoration:none;font-weight:600;font-size:14px;border-bottom:2px solid transparent;margin-bottom:-1px}
.pr-tabs a.on{color:var(--accent);border-bottom-color:var(--accent)}
.pr-tabs a:hover{color:var(--ink)}
.pr-top{display:flex;gap:10px;flex-wrap:wrap;align-items:end;justify-content:space-between;margin-bottom:14px}
.pr-top p{margin:0;color:var(--ink-2)}
.pr-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.pr-actions form{margin:0}
.pr-filter{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 14px}
.pr-filter input[type=search]{flex:1;min-width:200px}
.pr-filter .btn.on{background:var(--accent-soft);color:var(--accent-ink)}
.pr-form{display:flex;flex-direction:column;gap:14px;max-width:900px}
.pr-form label,.pr-grid label{display:flex;flex-direction:column;gap:4px;font-size:13px;color:var(--ink-2);font-weight:600}
.pr-form label small,.pr-grid label small{font-weight:400;color:var(--ink-3)}
.pr-form input:not([type=checkbox]):not([type=radio]):not([type=file]),.pr-form select,.pr-form textarea,.pr-grid input:not([type=checkbox]),.pr-grid select{width:100%;box-sizing:border-box;font-weight:400;color:var(--ink)}
.pr-form textarea{padding:8px 10px;border:1px solid var(--line);border-radius:7px;font-size:14px}
.pr-form label.pr-check{flex-direction:row;align-items:center;gap:8px;color:var(--ink);font-weight:500}
.pr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
.pr-num{font-family:var(--font-num);font-variant-numeric:tabular-nums;white-space:nowrap}
td.pr-num,th.pr-num{text-align:right}
.pr-table{overflow-x:auto}
.pr-table table{min-width:640px}
.pr-table tr.low td{background:#fff8ec}
.pr-muted{color:var(--ink-2)}
.pr-small{font-size:12.5px;color:var(--ink-3)}
.pr-code{font-family:var(--font-num);font-weight:600;letter-spacing:.03em}
a.pr-code{color:var(--accent);text-decoration:none}
a.pr-code:hover{text-decoration:underline}
.pr-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:18px}
.pr-cards .stat{padding:14px 16px}
.pr-cards .stat .num{font-size:26px}
.pr-cards a.stat{color:inherit;text-decoration:none}
.pr-cards a.stat:hover{border-color:var(--accent)}
.pr-two{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:18px;align-items:start}
.pr-two>.card{margin:0}
.pr-list{list-style:none;margin:0;padding:0}
.pr-list li{display:flex;flex-wrap:wrap;gap:4px 10px;justify-content:space-between;align-items:baseline;padding:8px 0;border-top:1px solid var(--line)}
.pr-list li .pr-num{font-size:13px;color:var(--ink-2)}
.pr-list li:first-child{border-top:0}
.pr-empty{text-align:center;padding:34px 20px}
.pr-empty-ic{font-size:34px;width:72px;height:72px;line-height:72px;margin:0 auto 10px;border-radius:50%;background:var(--accent-soft)}
.pr-empty h3{margin:0 0 6px}
.pr-empty p{margin:0 auto 16px;max-width:520px;color:var(--ink-2)}
.pr-empty .pr-actions{justify-content:center}
.pr-recipe td{vertical-align:middle}
.pr-recipe select,.pr-recipe input{width:100%}
.pr-recipe .pr-del{background:none;border:0;color:var(--danger-ink);font-size:18px;cursor:pointer}
.pr-cost{display:grid;grid-template-columns:max-content 1fr;gap:6px 16px;margin:0}
.pr-cost dt{color:var(--ink-2)}
.pr-cost dd{margin:0;font-family:var(--font-num)}
.pr-cost .tot{font-weight:700;color:var(--ink)}
.pr-codebox{display:flex;gap:10px;align-items:center;flex-wrap:wrap;background:var(--surface-2);border:1px dashed var(--line);border-radius:8px;padding:10px 14px}
.pr-codebox .pr-code{font-size:20px}
.pr-back{margin:0 0 10px}
.pr-back a{text-decoration:none;font-weight:600}
.pr-dl{display:grid;grid-template-columns:max-content 1fr;gap:6px 16px;margin:0}
.pr-dl dt{color:var(--ink-2)}
.pr-dl dd{margin:0}
.pr-warn{background:var(--warn-bg);color:var(--warn-ink);border-radius:8px;padding:10px 12px;font-size:14px}
.pr-ok{color:var(--success-ink)}
.pr-bad{color:var(--danger-ink)}
.pr-photo{max-width:100%;max-height:360px;border-radius:8px;border:1px solid var(--line)}
@media (max-width:700px){.pr-top{flex-direction:column;align-items:stretch}.pr-tabs{flex-wrap:nowrap;overflow-x:auto}.pr-tabs a{white-space:nowrap}
.pr-hide-m{display:none}.pr-table table{min-width:0}.pr-table table.pr-wide{min-width:560px}.pr-table td,.pr-table th{padding:8px 6px}}
</style>';
    }
}
