<?php
declare(strict_types=1);

namespace PaseExt\Produkcja;

use PaseExt\Produkcja\Ui as U;

/** Wyroby: świeca z recepturą, kosztem jednej sztuki, ceną i marżą oraz powiązaniem z produktem CRM (SKU). */
final class ProductPages
{
    public static function list(Store $s, Production $p, array $ctx, array $cfg): string
    {
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $arch = isset($_GET['archiwum']);
        $rows = $s->products($arch, $q);
        $html = U::style() . U::tabs('wyroby') . '<div class="pr-top"><p>Receptura, koszt jednej sztuki, cena i marża.</p><div class="pr-actions">'
            . ($ctx['canEdit'] ? '<a class="btn" href="' . U::h(U::url('wyrob', ['nowy' => 1])) . '">+ Nowy wyrób</a>' : '') . '</div></div>';
        if ($rows === [] && $q === '' && !$arch) {
            return $html . U::empty('Dodaj pierwszy wyrób', 'Wyrób to świeca, którą robisz: receptura (ile wosku, zapachu, jaki knot i pojemnik), cena i SKU produktu w sklepie. Z receptury policzymy koszt sztuki, a partia zdejmie materiały z magazynu.',
                $ctx['canEdit'] ? '<a class="btn" href="' . U::h(U::url('wyrob', ['nowy' => 1])) . '">+ Nowy wyrób</a>' : '');
        }
        $html .= '<form class="pr-filter" method="get" action="ext.php"><input type="hidden" name="page" value="produkcja.wyroby">'
            . '<input type="search" name="q" value="' . U::h($q) . '" placeholder="Szukaj po nazwie albo SKU">' . ($arch ? '<input type="hidden" name="archiwum" value="1">' : '')
            . '<button class="btn secondary" type="submit">Szukaj</button>'
            . '<a class="btn secondary' . ($arch ? ' on' : '') . '" href="' . U::h(U::url('wyroby', $arch ? [] : ['archiwum' => 1])) . '">Archiwum</a></form>';
        if ($rows === []) {
            return $html . '<div class="card pr-muted">Nic nie pasuje do filtrów.</div>';
        }
        $rate = (float) ($cfg['hourly_rate'] ?? 0);
        $stocks = array_column($s->materials(), 'stock', 'id');
        $html .= '<div class="card pr-table" style="padding:6px 10px"><table><thead><tr><th>Wyrób</th><th class="pr-hide-m">SKU</th><th class="pr-num">Koszt szt.</th>'
            . '<th class="pr-num">Cena</th><th class="pr-num pr-hide-m">Marża</th><th class="pr-num pr-hide-m">W sklepie</th><th class="pr-num pr-hide-m">Zrobisz z magazynu</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $cost = $s->unitCost($r, $rate);
            $m = self::margin($r, $cost['total']);
            $crm = $r['sku'] !== '' ? $p->crmProduct((string) $r['sku']) : null;
            $make = $s->canMake((string) $r['id'], $stocks);
            $html .= '<tr><td><a href="' . U::h(U::url('wyrob', ['id' => $r['id']])) . '"><b>' . U::h($r['name']) . '</b></a></td>'
                . '<td class="pr-hide-m pr-code">' . U::h($r['sku'] !== '' ? $r['sku'] : '-') . '</td>'
                . '<td class="pr-num">' . ($cost['total'] > 0 ? U::money($cost['total']) : '-') . ($cost['missing'] !== [] ? ' <span class="pill warn" title="Bez ceny: ' . U::h(implode(', ', $cost['missing'])) . '">?</span>' : '') . '</td>'
                . '<td class="pr-num">' . U::money($r['price']) . '</td>'
                . '<td class="pr-num pr-hide-m">' . ($m !== null ? '<span class="' . ($m['margin'] < 0 ? 'pr-bad' : '') . '">' . U::n(round($m['margin'], 1)) . '%</span>' : '-') . '</td>'
                . '<td class="pr-num pr-hide-m">' . self::crmStock($crm) . '</td>'
                . '<td class="pr-num pr-hide-m">' . ($make === null ? '<span class="pr-small">bez receptury</span>' : $make . ' szt.') . '</td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    /** @return array{net:float,profit:float,margin:float,markup:?float}|null */
    public static function margin(array $product, float $cost): ?array
    {
        if ($product['price'] === null || (float) $product['price'] <= 0) {
            return null;
        }
        $net = (float) $product['price'] / (1 + (int) $product['vat'] / 100);
        $profit = $net - $cost;
        return ['net' => $net, 'profit' => $profit, 'margin' => $profit / $net * 100, 'markup' => $cost > 0 ? $profit / $cost * 100 : null];
    }

    public static function crmStock(?array $crm): string
    {
        if ($crm === null) {
            return '<span class="pr-small">-</span>';
        }
        $actual = array_key_exists('actual_stock', $crm) && $crm['actual_stock'] !== null ? (int) $crm['actual_stock'] : null;
        return $actual !== null ? $actual . ' szt. <span class="pr-small" title="stan CRM (sklep): ' . (int) $crm['pase_stock'] . '">faktycznie</span>'
            : (int) $crm['pase_stock'] . ' szt.';
    }

    public static function card(Store $s, Production $p, array $ctx, array $cfg, array $w): string
    {
        $rate = (float) ($cfg['hourly_rate'] ?? 0);
        $cost = $s->unitCost($w, $rate);
        $m = self::margin($w, $cost['total']);
        $crm = $w['sku'] !== '' ? $p->crmProduct((string) $w['sku']) : null;
        $recipe = $s->recipe((string) $w['id']);
        $prices = $s->unitPrices();
        $make = $s->canMake((string) $w['id']);

        $html = U::style() . U::tabs('wyroby') . U::back(U::url('wyroby'), 'Wyroby')
            . '<div class="pr-top"><h2 style="margin:0">' . U::h($w['name']) . ((int) $w['archived'] === 1 ? ' <span class="pill muted">w archiwum</span>' : '') . '</h2><div class="pr-actions">'
            . ($ctx['canEdit'] ? '<a class="btn" href="' . U::h(U::url('partia_nowa', ['wyrob' => $w['id']])) . '">+ Nowa partia</a>'
                . '<a class="btn secondary" href="' . U::h(U::url('partia_nowa', ['wyrob' => $w['id'], 'wycena' => 1])) . '">Wycena robocza</a>'
                . '<a class="btn secondary" href="' . U::h(U::url('wyrob', ['id' => $w['id'], 'edytuj' => 1])) . '">Edytuj</a>' : '') . '</div></div>';

        $html .= '<div class="pr-two"><div class="card"><h3 style="margin:0 0 8px">Receptura na 1 sztukę</h3>';
        if ($recipe === []) {
            $html .= '<p class="pr-muted" style="margin:0">Brak receptury. Bez niej partia nie zdejmie materiałów, a koszt nie policzy się sam.</p>';
        } else {
            $html .= '<table><tbody>';
            foreach ($recipe as $l) {
                $c = isset($prices[$l['material_id']]) ? $prices[$l['material_id']] * (float) $l['qty'] : null;
                $html .= '<tr><td><a href="' . U::h(U::url('material', ['id' => $l['material_id']])) . '">' . U::h($l['name']) . '</a></td>'
                    . '<td class="pr-num">' . U::qty($l['qty'], $l['unit']) . '</td><td class="pr-num">' . ($c !== null ? U::money($c) : '<span class="pill warn">brak ceny</span>') . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        $html .= '<p class="pr-small" style="margin:10px 0 0">' . ($make === null ? '' : 'Z obecnych materiałów zrobisz <b>' . $make . ' szt.</b>') . '</p></div>';

        $html .= '<div class="card"><h3 style="margin:0 0 8px">Koszt i cena</h3><dl class="pr-cost">'
            . '<dt>Materiały</dt><dd>' . U::money($cost['materials']) . '</dd>'
            . ($cost['extra'] > 0 ? '<dt>Inne koszty</dt><dd>' . U::money($cost['extra']) . '</dd>' : '')
            . ($cost['labor'] > 0 ? '<dt>Robocizna (' . U::n($w['labor_minutes']) . ' min)</dt><dd>' . U::money($cost['labor']) . '</dd>' : '')
            . '<dt class="tot">Koszt sztuki</dt><dd class="tot">' . U::money($cost['total']) . '</dd>';
        if ($m !== null) {
            $html .= '<dt>Cena brutto</dt><dd>' . U::money($w['price']) . ' <span class="pr-small">VAT ' . (int) $w['vat'] . '%</span></dd>'
                . '<dt>Cena netto</dt><dd>' . U::money($m['net']) . '</dd>'
                . '<dt>Zysk na sztuce</dt><dd class="' . ($m['profit'] < 0 ? 'pr-bad' : 'pr-ok') . '">' . U::money($m['profit']) . '</dd>'
                . '<dt>Marża</dt><dd>' . U::n(round($m['margin'], 1)) . '%</dd>'
                . ($m['markup'] !== null ? '<dt>Narzut</dt><dd>' . U::n(round($m['markup'], 1)) . '%</dd>' : '');
            $fixed = (float) ($cfg['fixed_monthly'] ?? 0);
            if ($fixed > 0 && $m['profit'] > 0) {
                $html .= '<dt>Próg opłacalności</dt><dd>' . (int) ceil($fixed / $m['profit']) . ' szt./mies. <span class="pr-small">przy kosztach stałych ' . U::money($fixed) . '</span></dd>';
            }
        }
        $html .= '</dl>' . ($cost['missing'] !== [] ? '<p class="pr-warn" style="margin:10px 0 0">Bez ceny: ' . U::h(implode(', ', $cost['missing'])) . '. Wpisz cenę przy dostawie, żeby koszt był pełny.</p>' : '')
            . self::simulator($cost['total'], (int) $w['vat']) . '</div>';

        $html .= '<div class="card"><h3 style="margin:0 0 8px">Produkt w CRM</h3><dl class="pr-dl"><dt>SKU</dt><dd class="pr-code">' . U::h($w['sku'] !== '' ? $w['sku'] : '-') . '</dd>';
        if ($w['sku'] === '') {
            $html .= '</dl><p class="pr-muted" style="margin:8px 0 0">Wpisz SKU produktu ze sklepu, a partie dołożą sztuki do jego stanu.</p>';
        } elseif ($crm === null) {
            $html .= '</dl><p class="pr-warn" style="margin:8px 0 0">W CRM nie ma produktu o tym SKU. Partie zapiszą się, ale stan produktu się nie zmieni.</p>';
        } else {
            $html .= '<dt>Produkt</dt><dd><a href="products.php?q=' . U::h(rawurlencode((string) $crm['sku'])) . '">' . U::h($crm['name']) . '</a></dd>'
                . '<dt>Stan</dt><dd>' . self::crmStock($crm) . '</dd>'
                . ($w['min_stock'] !== null ? '<dt>Trzymaj co najmniej</dt><dd>' . (int) $w['min_stock'] . ' szt.</dd>' : '') . '</dl>';
        }
        $html .= '<dl class="pr-dl" style="margin-top:10px"><dt>Ważność</dt><dd>' . ((int) $w['shelf_life_months'] > 0 ? (int) $w['shelf_life_months'] . ' mies. od produkcji' : 'bez daty') . '</dd>'
            . ($w['notes'] !== '' ? '<dt>Uwagi</dt><dd style="white-space:pre-line">' . U::h($w['notes']) . '</dd>' : '') . '</dl></div></div>';

        $batches = $s->batches(['product' => $w['id']], 20);
        $html .= '<div class="card pr-table" style="margin-top:18px"><h3 style="margin:0 0 8px">Ostatnie partie</h3>' . BatchPages::table($batches, false);
        $tests = $s->tests((string) $w['id']);
        $html .= '</div><div class="card"><div class="pr-top" style="margin-bottom:8px"><h3 style="margin:0">Testy spalania</h3>'
            . ($ctx['canEdit'] ? '<a class="btn secondary" href="' . U::h(U::url('test', ['wyrob' => $w['id']])) . '">+ Nowy test</a>' : '') . '</div>'
            . ($tests === [] ? '<p class="pr-muted" style="margin:0">Brak testów.</p>' : TestPages::table($tests)) . '</div>';
        return $html;
    }

    /** Symulator marży: cena → zysk i marża, docelowa marża → sugerowana cena (bez zapisu). */
    private static function simulator(float $cost, int $vat): string
    {
        return '<details style="margin-top:12px"><summary class="pr-muted">Symulator ceny</summary><div class="pr-grid" style="margin-top:10px" id="pr-sim" data-cost="' . U::h(round($cost, 4)) . '" data-vat="' . $vat . '">'
            . '<label>Cena brutto (zł)<input id="pr-sim-price" inputmode="decimal"></label><label>albo docelowa marża (%)<input id="pr-sim-margin" inputmode="decimal"></label></div>'
            . '<p id="pr-sim-out" class="pr-muted" style="margin:8px 0 0"></p></details>'
            . '<script>(function(){var b=document.getElementById("pr-sim");if(!b)return;var c=+b.dataset.cost,v=+b.dataset.vat,p=document.getElementById("pr-sim-price"),m=document.getElementById("pr-sim-margin"),o=document.getElementById("pr-sim-out");'
            . 'function n(x){return parseFloat(String(x).replace(/\s/g,"").replace(",","."));}function f(x){return x.toFixed(2).replace(".",",")+" zł";}'
            . 'p.addEventListener("input",function(){var g=n(p.value);if(!(g>0)){o.textContent="";return;}var net=g/(1+v/100),pr=net-c;o.textContent="Netto "+f(net)+", zysk "+f(pr)+", marża "+(pr/net*100).toFixed(1).replace(".",",")+"%";});'
            . 'm.addEventListener("input",function(){var t=n(m.value);if(!(t<100)){o.textContent="";return;}var net=c/(1-t/100);o.textContent="Sugerowana cena: "+f(net*(1+v/100))+" brutto ("+f(net)+" netto)";});})();</script>';
    }

    public static function form(Store $s, array $ctx, array $w, ?array $posted = null): string
    {
        $v = static fn(string $k, string $d = ''): string => (string) ($posted[$k] ?? $w[$k] ?? $d);
        $edit = isset($w['id']);
        $mats = $s->materials();
        $recipe = $posted !== null ? array_values((array) ($posted['recipe'] ?? [])) : ($edit ? $s->recipe((string) $w['id']) : []);
        $opts = '<option value="">- wybierz materiał -</option>';
        $byCat = [];
        foreach ($mats as $m) {
            $byCat[$m['category']][] = $m;
        }
        foreach ($byCat as $cat => $list) {
            $opts .= '<optgroup label="' . U::h(Store::CATEGORIES[$cat] ?? $cat) . '">';
            foreach ($list as $m) {
                $opts .= '<option value="' . U::h($m['id']) . '" data-unit="' . U::h(Store::UNITS[$m['unit']]) . '">' . U::h($m['name']) . '</option>';
            }
            $opts .= '</optgroup>';
        }
        $row = static function (int $i, string $mid, string $qty) use ($opts): string {
            $o = $mid !== '' ? str_replace('value="' . U::h($mid) . '"', 'value="' . U::h($mid) . '" selected', $opts) : $opts;
            return '<tr><td><select name="recipe[' . $i . '][material_id]">' . $o . '</select></td>'
                . '<td style="width:150px"><input name="recipe[' . $i . '][qty]" value="' . U::h($qty) . '" inputmode="decimal" placeholder="ilość"></td>'
                . '<td style="width:40px"><button type="button" class="pr-del" title="Usuń" onclick="this.closest(\'tr\').remove()">×</button></td></tr>';
        };
        $rows = '';
        foreach ($recipe as $i => $l) {
            $rows .= $row((int) $i, (string) ($l['material_id'] ?? ''), isset($l['qty']) && $l['qty'] !== '' ? U::n($l['qty']) : '');
        }
        if ($rows === '') {
            $rows = $row(0, '', '') . $row(1, '', '') . $row(2, '', '');
        }
        $tpl = $row(999, '', '');

        return U::style() . U::tabs('wyroby') . U::back($edit ? U::url('wyrob', ['id' => $w['id']]) : U::url('wyroby'), $edit ? (string) $w['name'] : 'Wyroby')
            . '<form class="card pr-form" method="post" action="' . U::h(U::url('wyrob_zapisz')) . '"><h3 style="margin:0">' . ($edit ? 'Edytuj wyrób' : 'Nowy wyrób') . '</h3>'
            . U::csrf($ctx) . ($edit ? '<input type="hidden" name="id" value="' . U::h($w['id']) . '">' : '')
            . '<div class="pr-grid">'
            . U::input('name', $v('name'), 'Nazwa', 'required maxlength="200"')
            . U::input('sku', $v('sku'), 'SKU produktu w sklepie', 'maxlength="191" list="pr-skus" autocomplete="off"', 'partie dołożą sztuki do tego produktu')
            . U::input('price', $v('price') !== '' ? U::n($v('price')) : '', 'Cena brutto (zł)', 'inputmode="decimal"')
            . U::select('vat', ['23' => '23%', '8' => '8%', '5' => '5%', '0' => 'zw. / 0%'], $v('vat', '23'), 'VAT')
            . U::input('shelf_life_months', $v('shelf_life_months', '12'), 'Ważność (miesiące)', 'type="number" min="0" max="120"', '0 = bez daty ważności')
            . U::input('min_stock', $v('min_stock'), 'Trzymaj co najmniej (szt.)', 'type="number" min="0"', 'poniżej trafia na listę „Do zrobienia”')
            . U::input('extra_cost', $v('extra_cost') !== '' ? U::n($v('extra_cost')) : '', 'Inne koszty na sztukę (zł)', 'inputmode="decimal"', 'czego nie ma w materiałach, np. prąd')
            . U::input('labor_minutes', $v('labor_minutes') !== '' ? U::n($v('labor_minutes')) : '', 'Czas pracy na sztukę (min)', 'inputmode="decimal"')
            . '</div>'
            . '<div><b>Receptura na 1 sztukę</b><p class="pr-small" style="margin:2px 0 8px">Np. wosk 180 g, zapach 14 g, knot 1 szt., słoik 1 szt., etykieta 1 szt. Ilość w jednostce materiału (g, ml albo sztuki).</p>'
            . ($mats === [] ? '<p class="pr-warn">Najpierw dodaj materiały w zakładce Materiały i magazyn.</p>' : '')
            . '<table class="pr-recipe"><tbody id="pr-recipe">' . $rows . '</tbody></table>'
            . '<button type="button" class="btn secondary" style="margin-top:8px" id="pr-add">+ Dodaj materiał</button>'
            . '<template id="pr-row">' . $tpl . '</template></div>'
            . '<label>Uwagi<textarea name="notes" rows="3" maxlength="2000">' . U::h($v('notes')) . '</textarea></label>'
            . '<div class="pr-actions"><button class="btn" type="submit">Zapisz</button><a class="btn secondary" href="' . U::h($edit ? U::url('wyrob', ['id' => $w['id']]) : U::url('wyroby')) . '">Anuluj</a>'
            . ($edit ? '<label class="pr-check" style="margin-left:auto"><input type="checkbox" name="archived" value="1"' . ((int) $w['archived'] === 1 ? ' checked' : '') . '> W archiwum</label>' : '') . '</div>'
            . self::skuList($s) . '</form>'
            . '<script>(function(){var n=1000;document.getElementById("pr-add").addEventListener("click",function(){var t=document.getElementById("pr-row").innerHTML.replace(/\[999\]/g,"["+(n++)+"]");document.getElementById("pr-recipe").insertAdjacentHTML("beforeend",t);});})();</script>';
    }

    private static function skuList(Store $s): string
    {
        try {
            $rows = $s->all('SELECT sku, name FROM products ORDER BY name LIMIT 2000');
        } catch (\PDOException) {
            return '';
        }
        $html = '<datalist id="pr-skus">';
        foreach ($rows as $r) {
            $html .= '<option value="' . U::h($r['sku']) . '">' . U::h($r['name']) . '</option>';
        }
        return $html . '</datalist>';
    }
}
