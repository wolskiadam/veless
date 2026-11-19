<?php
declare(strict_types=1);

namespace PaseExt\Zapachy;

use Pase\Plugin\Hooks;

/** Wspólne kawałki stron wtyczki Zapachy: formatowanie, zakładki, plakietki, styl. */
final class Ui
{
    /** Kolory kategorii nut (stałe dla nazwy kategorii). */
    private const PALETTE = [['#fdecc8', '#7a4b00'], ['#dff3e4', '#1e6b3a'], ['#fde2e4', '#9b1c31'], ['#e3ecfd', '#1d4ea3'], ['#efe4fb', '#5b2a92'],
        ['#e6f6f6', '#0f6b6b'], ['#f6ece2', '#6b4423'], ['#fbe7f3', '#8a1f5c'], ['#eef3dc', '#4d6114'], ['#ececec', '#444444']];

    public static function h(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    public static function url(string $page, array $q = []): string
    {
        return Hooks::pageUrl('zapachy.' . $page, $q);
    }

    public static function csrf(array $ctx): string
    {
        return '<input type="hidden" name="csrf" value="' . self::h($ctx['csrf']) . '">';
    }

    public static function n(mixed $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 3, ',', "\u{a0}"), '0'), ',');
    }

    public static function qty(float $v, string $unit): string
    {
        if ($unit === 'g' && abs($v) >= 1000) {
            return self::n($v / 1000) . "\u{a0}kg";
        }
        if ($unit === 'ml' && abs($v) >= 1000) {
            return self::n($v / 1000) . "\u{a0}l";
        }
        return self::n($v) . "\u{a0}" . ($unit === 'szt' ? 'szt.' : $unit);
    }

    /** Numer miejsca jako duża plakietka (albo kreska). */
    public static function no(array $f, string $extra = ''): string
    {
        $no = trim((string) $f['storage_no']);
        return $no === '' ? '<span class="zp-no none' . $extra . '" title="Bez numeru miejsca">–</span>'
            : '<span class="zp-no' . $extra . '" title="Miejsce w magazynie">' . self::h($no) . '</span>';
    }

    /** „#18 Sweet Caramel” jako mała plakietka z linkiem do karty zapachu. */
    public static function chip(array $f, bool $link = true): string
    {
        $inner = '<b>' . self::h(trim((string) $f['storage_no']) !== '' ? '#' . $f['storage_no'] : '#–') . '</b> ' . self::h($f['name']);
        return $link ? '<a class="zp-chip" href="' . self::h(self::url('zapach', ['id' => $f['id']])) . '">' . $inner . '</a>' : '<span class="zp-chip">' . $inner . '</span>';
    }

    /** @param array<string,string> $cats nuta => kategoria */
    public static function noteChips(string $text, array $cats, bool $link = true): string
    {
        $out = '';
        foreach (Store::notes($text) as $n) {
            $cat = $cats[$n] ?? '';
            [$bg, $fg] = $cat !== '' ? self::PALETTE[crc32($cat) % count(self::PALETTE)] : ['var(--surface-2,#f4f3ef)', 'var(--ink-2,#555)'];
            $style = 'background:' . $bg . ';color:' . $fg;
            $title = $cat !== '' ? ' title="' . self::h($cat) . '"' : '';
            $out .= $link ? '<a class="zp-note" style="' . $style . '"' . $title . ' href="' . self::h(self::url('lista', ['nuta' => $n])) . '">' . self::h($n) . '</a>'
                : '<span class="zp-note" style="' . $style . '"' . $title . '>' . self::h($n) . '</span>';
        }
        return $out;
    }

    public static function catChip(string $cat): string
    {
        if ($cat === '') {
            return '<span class="zp-small">bez kategorii</span>';
        }
        [$bg, $fg] = self::PALETTE[crc32($cat) % count(self::PALETTE)];
        return '<span class="zp-note" style="background:' . $bg . ';color:' . $fg . '">' . self::h($cat) . '</span>';
    }

    /** Zakładki wtyczki nad treścią. */
    public static function tabs(string $active): string
    {
        $tabs = ['lista' => 'Zapachy', 'nuty' => 'Nuty', 'przypisz' => 'Przypisz do produktów'];
        $html = '<nav class="zp-tabs">';
        foreach ($tabs as $k => $label) {
            $html .= '<a class="' . ($k === $active ? 'on' : '') . '" href="' . self::h(self::url($k)) . '">' . $label . '</a>';
        }
        return $html . '</nav>';
    }

    public static function back(string $href, string $label): string
    {
        return '<p class="zp-back"><a href="' . self::h($href) . '">← ' . self::h($label) . '</a></p>';
    }

    /** Styl plakietek używanych także poza stronami wtyczki (Magazyn, karta zamówienia). */
    public static function chipStyle(): string
    {
        return '<style>
.zp-chip{display:inline-flex;gap:4px;align-items:baseline;padding:2px 8px;border-radius:999px;background:#f3ecfb;color:#4b2a7a;font-size:12px;font-weight:500;text-decoration:none;white-space:nowrap;margin:2px 4px 2px 0}
.zp-chip b{font-family:var(--font-num);font-weight:700}
a.zp-chip:hover{background:#e8dcf7}
.zp-note{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;line-height:1.5;margin:2px 4px 2px 0;text-decoration:none;white-space:nowrap}
a.zp-note:hover{filter:brightness(.96)}
.zp-small{font-size:12.5px;color:var(--ink-3,#888)}
</style>';
    }

    public static function style(): string
    {
        return self::chipStyle() . '<style>
.zp-tabs{display:flex;gap:4px;flex-wrap:wrap;margin:-6px 0 16px;border-bottom:1px solid var(--line)}
.zp-tabs a{padding:8px 12px;color:var(--ink-2);text-decoration:none;font-weight:600;font-size:14px;border-bottom:2px solid transparent;margin-bottom:-1px}
.zp-tabs a.on{color:var(--accent);border-bottom-color:var(--accent)}
.zp-top{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-bottom:14px}
.zp-top p{margin:0;color:var(--ink-2)}
.zp-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.zp-actions form{margin:0}
.zp-filter{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 12px}
.zp-filter input[type=search]{flex:1;min-width:220px;font-size:15px}
.zp-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:16px}
.zp-cards .stat{padding:12px 16px}
.zp-cards .stat .num{font-size:24px}
.zp-no{display:inline-flex;align-items:center;justify-content:center;min-width:42px;height:42px;padding:0 8px;box-sizing:border-box;border-radius:10px;
 background:var(--ink,#1f1f1f);color:#fff;font-family:var(--font-num);font-weight:700;font-size:19px;letter-spacing:-.01em}
.zp-no.none{background:var(--surface-2,#f1f0ec);color:var(--ink-3,#999)}
.zp-no.big{min-width:76px;height:76px;font-size:34px;border-radius:16px}
.zp-table{overflow-x:auto}
.zp-table td{vertical-align:middle}
.zp-name{font-weight:600;color:var(--ink);text-decoration:none;font-size:15px}
.zp-name:hover{color:var(--accent)}
.zp-alias{font-size:12.5px;color:var(--ink-3,#888)}
.zp-pyr{display:grid;grid-template-columns:max-content 1fr;gap:4px 10px;align-items:baseline}
.zp-pyr dt{font-size:12px;color:var(--ink-3,#888);text-transform:uppercase;letter-spacing:.04em;font-weight:600}
.zp-pyr dd{margin:0}
.zp-main td{padding-top:7px;padding-bottom:7px}
.zp-main .zp-no{min-width:34px;height:34px;font-size:15px;border-radius:8px}
.zp-main .zp-pyr{gap:0 8px}.zp-main .zp-pyr dt{font-size:10.5px}
.zp-main .zp-note{padding:0 7px;margin:1px 3px 1px 0;font-size:11.5px}
.zp-pager{display:flex;align-items:center;gap:10px 16px;flex-wrap:wrap;margin:0 0 10px}
.zp-pages{display:flex;gap:4px;flex-wrap:wrap}
.zp-pg{display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 8px;box-sizing:border-box;border-radius:8px;
 border:1px solid var(--line,#e6e3da);background:var(--surface,#fff);color:var(--ink);text-decoration:none;font-size:14px}
a.zp-pg:hover{border-color:var(--accent)}.zp-pg.on{background:var(--ink);color:var(--surface,#fff);border-color:var(--ink);font-weight:600}
.zp-pg.off{border-color:transparent;background:none;color:var(--ink-3,#aaa)}
.zp-per{margin-left:auto}.zp-per a{color:var(--ink-2,#555)}
.zp-stock{font-family:var(--font-num);white-space:nowrap}
.zp-stock.low{color:var(--danger-ink,#b42318);font-weight:600}
.zp-form{display:flex;flex-direction:column;gap:14px;max-width:900px}
.zp-form label{display:flex;flex-direction:column;gap:4px;font-size:13px;color:var(--ink-2);font-weight:600}
.zp-form label small{font-weight:400;color:var(--ink-3)}
.zp-form input:not([type=checkbox]),.zp-form select,.zp-form textarea{width:100%;box-sizing:border-box;font-weight:400;color:var(--ink)}
.zp-form textarea{padding:8px 10px;border:1px solid var(--line);border-radius:7px;font-size:14px;font-family:inherit}
.zp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}
.zp-head{display:flex;gap:16px;align-items:center;margin-bottom:6px}
.zp-head h2{margin:0;font-size:24px}
.zp-two{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:18px;align-items:start}
.zp-two>.card{margin:0}
.zp-dl{display:grid;grid-template-columns:max-content 1fr;gap:6px 16px;margin:0}
.zp-dl dt{color:var(--ink-2)}
.zp-dl dd{margin:0}
@media (max-width:700px){.zp-hm{display:none}}
.zp-back{margin:0 0 10px}
.zp-back a{text-decoration:none;font-weight:600}
.zp-list{list-style:none;margin:0;padding:0}
.zp-list li{display:flex;gap:10px;align-items:center;justify-content:space-between;padding:7px 0;border-top:1px solid var(--line)}
.zp-list li:first-child{border-top:0}
.zp-list form{margin:0}
.zp-x{background:none;border:0;color:var(--danger-ink,#b42318);font-size:17px;cursor:pointer;padding:2px 6px}
.zp-empty{text-align:center;padding:34px 20px}
.zp-empty .ic{font-size:34px;width:72px;height:72px;line-height:72px;margin:0 auto 10px;border-radius:50%;background:#f3ecfb}
.zp-empty h3{margin:0 0 6px}
.zp-empty p{margin:0 auto 16px;max-width:540px;color:var(--ink-2)}
.zp-topnotes{margin:0 0 14px;display:flex;flex-wrap:wrap;gap:2px;align-items:center}
.zp-topnotes .zp-small{margin-right:6px}
.zp-note.on{outline:2px solid var(--ink);outline-offset:1px}
.zp-desc{white-space:pre-line;color:var(--ink-2);line-height:1.5}
.zp-how{font-size:11px;color:var(--ink-3,#888)}
@media (max-width:700px){
 .zp-top{flex-direction:column;align-items:stretch}
 .zp-cards{grid-template-columns:1fr 1fr;gap:8px}.zp-cards .stat{padding:10px 12px}.zp-cards .stat .num{font-size:20px}
 .zp-tabs{flex-wrap:nowrap;overflow-x:auto}.zp-tabs a{white-space:nowrap}
 .zp-table table.zp-main thead{display:none}
 .zp-table table.zp-main tr{display:grid;grid-template-columns:auto 1fr;gap:2px 12px;padding:10px 4px;border-top:1px solid var(--line)}
 .zp-table table.zp-main td{border:0;padding:0}
 .zp-table table.zp-main td.c-no{grid-row:span 3}
 .zp-table table.zp-main td.c-hide{display:none}
 .zp-head h2{font-size:20px}.zp-no.big{min-width:60px;height:60px;font-size:28px}
}
</style>';
    }
}
