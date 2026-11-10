<?php
declare(strict_types=1);

namespace PaseExt\Ksiegowosc;

use Pase\Plugin\Hooks;

/** Wspólne kawałki stron księgowości: formatowanie, zakładki, styl. */
final class Ui
{
    /** Zakładki: klucz => [tytuł, wymagany poziom]. */
    public const TABS = [
        'pulpit' => ['Pulpit', Access::VIEW],
        'dokumenty' => ['Dokumenty', Access::VIEW],
        'dziennik' => ['Dziennik', Access::VIEW],
        'konta' => ['Plan kont', Access::VIEW],
        'kontrahenci' => ['Kontrahenci', Access::VIEW],
        'vat' => ['Rejestry VAT', Access::VIEW],
        'rozrachunki' => ['Rozrachunki', Access::VIEW],
        'bank' => ['Bank', Access::VIEW],
        'obroty' => ['Obroty i salda', Access::VIEW],
        'lata' => ['Lata obrotowe', Access::VIEW],
        'firma' => ['Firma', Access::MANAGE],
        'dostep' => ['Dostęp', Access::MANAGE],
        'zmiany' => ['Historia zmian', Access::MANAGE],
        'skrzynka' => ['Zdarzenia z CRM', Access::MANAGE],
    ];

    public static function h(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    public static function url(string $page, array $q = []): string
    {
        return Hooks::pageUrl('ksiegowosc.' . $page, $q);
    }

    public static function money(int $gr, bool $blankZero = false): string
    {
        return self::h(Money::format($gr, $blankZero));
    }

    public static function date(?string $ymd): string
    {
        if ($ymd === null || $ymd === '') {
            return '-';
        }
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $ymd, $m) ? $m[3] . '.' . $m[2] . '.' . $m[1] : self::h($ymd);
    }

    public static function csrf(array $ctx): string
    {
        return '<input type="hidden" name="csrf" value="' . self::h($ctx['csrf']) . '">';
    }

    /** Formularz POST z jednym przyciskiem (np. Zaksięguj, Usuń). */
    public static function postButton(array $ctx, string $page, array $fields, string $label, string $class = 'btn', string $confirm = ''): string
    {
        $html = '<form method="post" action="' . self::h(self::url($page)) . '"' . ($confirm !== '' ? ' onsubmit="return confirm(' . self::h(json_encode($confirm, JSON_UNESCAPED_UNICODE)) . ')"' : '') . '>'
            . self::csrf($ctx);
        foreach ($fields as $k => $v) {
            $html .= '<input type="hidden" name="' . self::h($k) . '" value="' . self::h($v) . '">';
        }
        return $html . '<button class="' . self::h($class) . '" type="submit">' . $label . '</button></form>';
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

    public static function tabs(string $active, string $level): string
    {
        $html = '<nav class="kg-tabs">';
        foreach (self::TABS as $k => [$label, $need]) {
            if (Access::allows($level, $need)) {
                $html .= '<a class="' . ($k === $active ? 'on' : '') . '" href="' . self::h(self::url($k)) . '">' . $label . '</a>';
            }
        }
        return $html . '</nav>';
    }

    /** Wybór roku obrotowego (GET, zostawia pozostałe parametry strony). @param list<array<string,mixed>> $years */
    public static function yearPicker(string $page, array $years, string $current, array $keep = []): string
    {
        if (count($years) < 2) {
            return '';
        }
        $opts = [];
        foreach ($years as $y) {
            $opts[(string) $y['id']] = 'Rok ' . $y['name'];
        }
        $hidden = '';
        foreach ($keep as $k => $v) {
            $hidden .= '<input type="hidden" name="' . self::h($k) . '" value="' . self::h($v) . '">';
        }
        return '<form method="get" action="ext.php" class="kg-inline"><input type="hidden" name="page" value="ksiegowosc.' . self::h($page) . '">' . $hidden
            . self::select('rok', $opts, $current, '', 'onchange="this.form.submit()"') . '</form>';
    }

    public static function empty(string $title, string $text, string $actions = ''): string
    {
        return '<div class="card kg-empty"><div class="kg-empty-ic">📒</div><h3>' . $title . '</h3><p>' . $text . '</p>'
            . ($actions !== '' ? '<div class="kg-actions">' . $actions . '</div>' : '') . '</div>';
    }

    public static function denied(): string
    {
        return self::style() . '<div class="card kg-empty"><div class="kg-empty-ic">🔒</div><h3>Brak dostępu do księgowości</h3>'
            . '<p>Księgowość ma własne uprawnienia, niezależne od roli w CRM. Poproś administratora o dostęp '
            . '(Księgowość → Dostęp).</p></div>';
    }

    public static function back(string $href, string $label): string
    {
        return '<p class="kg-back"><a href="' . self::h($href) . '">← ' . self::h($label) . '</a></p>';
    }

    public static function style(): string
    {
        return '<style>
.kg-tabs{display:flex;gap:4px;flex-wrap:wrap;margin:-6px 0 16px;border-bottom:1px solid var(--line)}
.kg-tabs a{padding:8px 12px;color:var(--ink-2);text-decoration:none;font-weight:600;font-size:14px;border-bottom:2px solid transparent;margin-bottom:-1px}
.kg-tabs a.on{color:var(--accent);border-bottom-color:var(--accent)}
.kg-tabs a:hover{color:var(--ink)}
.kg-top{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-bottom:14px}
.kg-top p{margin:0;color:var(--ink-2)}
.kg-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.kg-actions form,.kg-inline{margin:0;display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap}
.kg-filter{display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin:0 0 14px}
.kg-filter input[type=search]{flex:1;min-width:200px}
.kg-filter label{display:flex;flex-direction:column;gap:3px;font-size:12.5px;color:var(--ink-2);font-weight:600}
.kg-form{display:flex;flex-direction:column;gap:14px;max-width:1000px}
.kg-form label,.kg-grid label{display:flex;flex-direction:column;gap:4px;font-size:13px;color:var(--ink-2);font-weight:600}
.kg-form label small,.kg-grid label small{font-weight:400;color:var(--ink-3)}
.kg-form input:not([type=checkbox]):not([type=radio]),.kg-form select,.kg-form textarea,.kg-grid input:not([type=checkbox]),.kg-grid select{width:100%;box-sizing:border-box;font-weight:400;color:var(--ink)}
.kg-form textarea{padding:8px 10px;border:1px solid var(--line);border-radius:7px;font-size:14px}
.kg-form label.kg-check{flex-direction:row;align-items:center;gap:8px;color:var(--ink);font-weight:500}
.kg-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}
.kg-num{font-family:var(--font-num);font-variant-numeric:tabular-nums;white-space:nowrap;text-align:right}
th.kg-num{text-align:right}
.kg-table{overflow-x:auto}
.kg-table table{min-width:640px;width:100%}
.kg-table tfoot td{font-weight:700;border-top:2px solid var(--line)}
.kg-table tr.syn td{font-weight:600;background:var(--surface-2)}
.kg-table tr.off td{color:var(--ink-3)}
.kg-muted{color:var(--ink-2)}
.kg-small{font-size:12.5px;color:var(--ink-3)}
.kg-acc{font-family:var(--font-num);font-weight:600;white-space:nowrap}
a.kg-acc{color:var(--accent);text-decoration:none}
.kg-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-bottom:18px}
.kg-cards .stat{padding:14px 16px}
.kg-cards .stat .num{font-size:24px}
.kg-cards a.stat{color:inherit;text-decoration:none}
.kg-two{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:18px;align-items:start}
.kg-two>.card{margin:0}
.kg-empty{text-align:center;padding:34px 20px}
.kg-empty-ic{font-size:34px;width:72px;height:72px;line-height:72px;margin:0 auto 10px;border-radius:50%;background:var(--accent-soft)}
.kg-empty h3{margin:0 0 6px}
.kg-empty p{margin:0 auto 16px;max-width:560px;color:var(--ink-2)}
.kg-empty .kg-actions{justify-content:center}
.kg-back{margin:0 0 10px}
.kg-back a{text-decoration:none;font-weight:600}
.kg-dl{display:grid;grid-template-columns:max-content 1fr;gap:6px 16px;margin:0}
.kg-dl dt{color:var(--ink-2)}
.kg-dl dd{margin:0}
.kg-warn{background:var(--warn-bg);color:var(--warn-ink);border-radius:8px;padding:10px 12px;font-size:14px;margin:0 0 12px}
.kg-warn ul{margin:6px 0 0;padding-left:18px}
.kg-ok{color:var(--success-ink)}
.kg-bad{color:var(--danger-ink)}
.kg-lines td{vertical-align:middle;padding:4px}
.kg-lines input{width:100%;box-sizing:border-box}
.kg-lines .kg-amt{max-width:130px;text-align:right}
.kg-lines .kg-del{background:none;border:0;color:var(--danger-ink);font-size:18px;cursor:pointer}
.kg-sum{display:flex;gap:18px;flex-wrap:wrap;justify-content:flex-end;font-weight:600;padding:8px 4px}
.kg-jdoc{border-top:1px solid var(--line);padding:10px 0}
.kg-jdoc:first-child{border-top:0}
.kg-jdoc table{margin-top:6px}
.kg-pill{display:inline-block;padding:1px 8px;border-radius:999px;font-size:12px;font-weight:600;background:var(--surface-2);color:var(--ink-2)}
.kg-pill.posted{background:var(--success-bg,#e7f5ec);color:var(--success-ink)}
.kg-pill.draft{background:var(--warn-bg);color:var(--warn-ink)}
.kg-pill.rev{background:var(--danger-bg,#fdeaea);color:var(--danger-ink)}
.kg-pager{display:flex;gap:8px;justify-content:center;margin-top:12px}
@media (max-width:700px){.kg-top{flex-direction:column;align-items:stretch}.kg-tabs{flex-wrap:nowrap;overflow-x:auto}.kg-tabs a{white-space:nowrap}
.kg-hide-m{display:none}.kg-table td,.kg-table th{padding:8px 6px}}
</style>';
    }

    /** Status dokumentu jako plakietka. */
    public static function status(array $doc): string
    {
        if ($doc['status'] === 'draft') {
            return '<span class="kg-pill draft">Szkic</span>';
        }
        if (($doc['reversed_by'] ?? null) !== null && $doc['reversed_by'] !== '') {
            return '<span class="kg-pill rev">Wystornowany</span>';
        }
        return '<span class="kg-pill posted">Zaksięgowany</span>';
    }

    public static function pager(string $page, array $q, int $total, int $per, int $p): string
    {
        $pages = (int) ceil($total / $per);
        if ($pages <= 1) {
            return '';
        }
        $html = '<div class="kg-pager">';
        if ($p > 1) {
            $html .= '<a class="btn secondary" href="' . self::h(self::url($page, $q + ['s' => $p - 1])) . '">← Nowsze</a>';
        }
        $html .= '<span class="kg-muted" style="align-self:center">Strona ' . $p . ' z ' . $pages . '</span>';
        if ($p < $pages) {
            $html .= '<a class="btn secondary" href="' . self::h(self::url($page, $q + ['s' => $p + 1])) . '">Starsze →</a>';
        }
        return $html . '</div>';
    }
}
