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

    /**
     * Pole produktu z podpowiedziami na żywo z Magazynu: wpisujesz nazwę, a lista pokazuje pasujące produkty
     * z wariantem, SKU i informacją o etykiecie CLP. Wybrany produkt trafia do ukrytego pola $idName (id produktu).
     * Wpisanie własnej nazwy zostawia pole id puste (produkt niepołączony).
     */
    public static function productPicker(string $textName, string $text, string $idName, ?array $crm, string $label, string $attrs = '', bool $hint = true): string
    {
        $info = '';
        if ($hint) {
            $info = '<small class="pr-pick-info">' . ($crm !== null ? self::pickInfo($crm) : 'Zacznij pisać nazwę i wybierz produkt z listy - wtedy druk weźmie jego etykietę CLP.') . '</small>';
        }
        return '<div class="pr-pick">' . $label
            . '<input name="' . self::h($textName) . '" value="' . self::h($text) . '" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list"'
            . ' data-pick-url="' . self::h(self::url('produkty_szukaj')) . '" data-pick-name="' . self::h($crm['name'] ?? '') . '" ' . $attrs . '>'
            . '<input type="hidden" name="' . self::h($idName) . '" value="' . self::h($crm !== null ? (string) (int) $crm['id'] : '') . '">'
            . '<div class="pr-pick-list" role="listbox" hidden></div>' . $info . '</div>' . self::pickerScript();
    }

    /** Opis wybranego produktu pod polem (SKU, wariant, etykieta CLP). @param array{sku?:string,variant?:string,label?:bool,shared?:bool} $p */
    public static function pickInfo(array $p): string
    {
        $bits = array_filter([(string) ($p['variant'] ?? ''), (string) ($p['weight'] ?? ''), ($p['sku'] ?? '') !== '' ? 'SKU ' . $p['sku'] : '']);
        return '<span class="pr-ok">✓ Połączono z Magazynem</span>' . ($bits !== [] ? ' · ' . self::h(implode(' · ', $bits)) : '') . ' · '
            . (!empty($p['label']) ? '<span class="pr-ok">ma etykietę CLP' . (!empty($p['shared']) ? ' (wspólną z wariantem)' : '') . '</span>' : '<span class="pr-bad">bez etykiety CLP</span>');
    }

    private static bool $pickerJs = false;

    private static function pickerScript(): string
    {
        if (self::$pickerJs) {
            return '';
        }
        self::$pickerJs = true;
        return <<<'HTML'
<style>
.pr-pick{position:relative;display:flex;flex-direction:column;gap:4px;font-size:13px;color:var(--ink-2);font-weight:600}
.pr-pick input{width:100%;box-sizing:border-box;font-weight:400;color:var(--ink)}
.pr-pick small{font-weight:400;color:var(--ink-3)}
.pr-pick-list{position:absolute;z-index:30;left:0;top:100%;margin-top:2px;min-width:100%;width:max-content;max-width:min(560px,92vw);max-height:340px;overflow-y:auto;background:var(--surface,#fff);border:1px solid var(--line);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);padding:4px}
.pr-pick-list[hidden]{display:none}
.pr-pick-it{display:flex;align-items:center;gap:10px;width:100%;text-align:left;background:none;border:0;border-radius:6px;padding:7px 9px;cursor:pointer;font:inherit;color:var(--ink)}
.pr-pick-it:hover,.pr-pick-it.on{background:var(--accent-soft)}
.pr-pick-it>img,.pr-pick-it>i{flex:0 0 48px;width:48px;height:48px;border-radius:6px;object-fit:cover;background:var(--surface-2,#f3f1ec);border:1px solid var(--line)}
.pr-pick-it>div{display:flex;flex-direction:column;gap:2px;min-width:0}
.pr-pick-it b{font-weight:600;font-size:14px}
.pr-pick-it strong{font-weight:700;color:var(--ink)}
.pr-pick-it span{font-weight:400;font-size:12.5px;color:var(--ink-2);display:flex;flex-wrap:wrap;gap:4px 8px;align-items:center}
.pr-pick-it .pill{font-size:11px;font-style:normal}
@media (max-width:700px){.pr-pick-list{width:100%;max-width:none}}
.pr-pick-none{padding:8px 10px;font-weight:400;color:var(--ink-3)}
</style>
<script>(function(){if(window.prPick)return;window.prPick=1;
function esc(t){var d=document.createElement('div');d.textContent=t==null?'':String(t);return d.innerHTML;}
function info(p){var b=[];if(p.variant)b.push(esc(p.variant));if(p.weight)b.push(esc(p.weight));if(p.sku)b.push('SKU '+esc(p.sku));
return '<span class="pr-ok">✓ Połączono z Magazynem</span>'+(b.length?' · '+b.join(' · '):'')+' · '+(p.label?'<span class="pr-ok">ma etykietę CLP'+(p.shared?' (wspólną z wariantem)':'')+'</span>':'<span class="pr-bad">bez etykiety CLP</span>');}
function setup(inp){var box=inp.parentNode,hid=box.querySelector('input[type=hidden]'),list=box.querySelector('.pr-pick-list'),inf=box.querySelector('.pr-pick-info'),t,seq=0,items=[],act=-1;
function close(){list.hidden=true;inp.setAttribute('aria-expanded','false');act=-1;}
function mark(){list.querySelectorAll('.pr-pick-it').forEach(function(e,i){e.classList.toggle('on',i===act);if(i===act)e.scrollIntoView({block:'nearest'});});}
function choose(i){var p=items[i];if(!p)return;inp.value=p.name;inp.dataset.pickName=p.name;hid.value=p.id;if(inf)inf.innerHTML=info(p);close();inp.dispatchEvent(new Event('change',{bubbles:true}));}
function show(r){items=r||[];act=-1;if(!items.length){list.innerHTML='<div class="pr-pick-none">Nie ma takiego produktu w Magazynie. Możesz zostawić własną nazwę.</div>';}
else{list.innerHTML=items.map(function(p,i){var m=[];if(p.weight)m.push('<strong>'+esc(p.weight)+'</strong>');if(p.variant)m.push('<i style="font-style:normal">'+esc(p.variant)+'</i>');m.push(p.sku?'SKU '+esc(p.sku):'bez SKU');
if(p.parent)m.push('<em class="pill">produkt główny</em>');m.push(p.label?'<em class="pill ok">etykieta CLP</em>':'<em class="pill">bez etykiety</em>');
var im=p.img?'<img src="'+esc(p.img)+'" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.replaceWith(document.createElement(\'i\'))">':'<i></i>';
return '<button type="button" class="pr-pick-it" role="option" data-i="'+i+'">'+im+'<div><b>'+esc(p.name)+'</b><span>'+m.join('')+'</span></div></button>';}).join('');}
list.hidden=false;inp.setAttribute('aria-expanded','true');}
function search(){var q=inp.value.trim(),my=++seq;if(q.length<2){close();return;}
fetch(inp.dataset.pickUrl+'&q='+encodeURIComponent(q),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(r){if(my===seq&&document.activeElement===inp)show(r.items);}).catch(function(){});}
inp.addEventListener('input',function(){if(inp.value!==inp.dataset.pickName&&hid.value!==''){hid.value='';if(inf)inf.innerHTML='Nie połączono z produktem z Magazynu - wybierz go z listy, żeby druk wziął etykietę CLP.';}
clearTimeout(t);t=setTimeout(search,180);});
inp.addEventListener('focus',function(){if(inp.value.trim().length>=2&&hid.value==='')search();});
inp.addEventListener('keydown',function(e){if(list.hidden)return;var n=items.length;
if(e.key==='ArrowDown'){e.preventDefault();act=(act+1)%n;mark();}else if(e.key==='ArrowUp'){e.preventDefault();act=(act-1+n)%n;mark();}
else if(e.key==='Enter'&&act>=0){e.preventDefault();choose(act);}else if(e.key==='Escape'){close();}});
inp.addEventListener('blur',function(){setTimeout(close,150);});
list.addEventListener('mousedown',function(e){e.preventDefault();});
list.addEventListener('click',function(e){var b=e.target.closest('.pr-pick-it');if(b)choose(+b.dataset.i);});}
function init(){document.querySelectorAll('input[data-pick-url]').forEach(function(i){if(!i.dataset.pickOn){i.dataset.pickOn=1;setup(i);}});}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();})();</script>
HTML;
    }

    /** Zakładki wtyczki nad treścią (ta sama nawigacja na telefonie i komputerze). */
    public static function tabs(string $active): string
    {
        $tabs = ['pulpit' => 'Pulpit', 'materialy' => 'Materiały i magazyn', 'wyroby' => 'Wyroby', 'partie' => 'Partie', 'etykiety' => 'Etykiety CLP', 'testy' => 'Testy spalania'];
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
