<?php
declare(strict_types=1);

namespace PaseExt\Produkcja;

use PaseExt\Produkcja\Ui as U;

/** Materiały i magazyn: lista, karta materiału z dostawami i ruchami, przyjęcie dostawy, inwentaryzacja. */
final class MaterialPages
{
    public static function list(Store $s, array $ctx): string
    {
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $cat = (string) ($_GET['cat'] ?? '');
        $low = isset($_GET['low']);
        $arch = isset($_GET['archiwum']);
        $rows = $s->materials($arch, $q, $cat);
        if ($low) {
            $rows = array_values(array_filter($rows, static fn(array $r): bool => $r['low']));
        }
        $all = $q === '' && $cat === '' && !$low && !$arch ? $rows : $s->materials();

        $html = U::style() . U::tabs('materialy')
            . '<div class="pr-top"><p>Surowce i opakowania: stan, ceny z dostaw i to, co się kończy.</p><div class="pr-actions">'
            . ($all !== [] ? '<a class="btn secondary" href="' . U::h(U::url('materialy_eksport')) . '" title="Materiały ze stanem do pliku CSV (da się go wczytać z powrotem)">⬇ Eksport CSV</a>' : '')
            . ($ctx['canEdit'] ? '<a class="btn secondary" href="' . U::h(U::url('inwentaryzacja')) . '">✓ Inwentaryzacja</a>'
                . '<a class="btn" href="' . U::h(U::url('material', ['nowy' => 1])) . '">+ Dodaj materiał</a>' : '')
            . '</div></div>';

        if ($all === [] && !$arch) {
            return $html . U::empty('Dodaj pierwszy materiał', 'Wystarczy nazwa. Stan, cenę i dostawcę uzupełnisz przy przyjęciu dostawy albo wczytasz wszystko z pliku CSV lub z kopii Rejestru Partii.',
                $ctx['canEdit'] ? '<a class="btn" href="' . U::h(U::url('material', ['nowy' => 1])) . '">+ Dodaj materiał</a>' : '');
        }

        $cats = ['' => 'Wszystkie kategorie'] + Store::CATEGORIES;
        $html .= '<form class="pr-filter" method="get" action="ext.php"><input type="hidden" name="page" value="produkcja.materialy">'
            . '<input type="search" name="q" value="' . U::h($q) . '" placeholder="Szukaj po nazwie, kodzie albo dostawcy">'
            . U::select('cat', $cats, $cat, '', 'onchange="this.form.submit()"')
            . ($low ? '<input type="hidden" name="low" value="1">' : '') . ($arch ? '<input type="hidden" name="archiwum" value="1">' : '')
            . '<button class="btn secondary" type="submit">Szukaj</button>'
            . '<a class="btn secondary' . ($low ? ' on' : '') . '" href="' . U::h(U::url('materialy', $low ? [] : ['low' => 1])) . '">Niski stan</a>'
            . '<a class="btn secondary' . ($arch ? ' on' : '') . '" href="' . U::h(U::url('materialy', $arch ? [] : ['archiwum' => 1])) . '">Archiwum</a>'
            . '</form>';

        if ($rows === []) {
            return $html . '<div class="card pr-muted">Nic nie pasuje do filtrów.</div>';
        }
        $html .= '<div class="card pr-table" style="padding:6px 10px"><table><thead><tr><th>Materiał</th><th class="pr-hide-m">Kategoria</th><th class="pr-hide-m">Kod</th>'
            . '<th class="pr-num">Stan</th><th class="pr-num pr-hide-m">Minimum</th><th class="pr-num pr-hide-m">Cena</th><th class="pr-hide-m">Ważność</th></tr></thead><tbody>';
        $today = date('Y-m-d');
        foreach ($rows as $r) {
            $exp = (string) ($r['expires'] ?? '');
            $expPill = $exp === '' ? '' : ($exp < $today ? '<span class="pill bad">' . U::date($exp) . '</span>'
                : ($exp <= date('Y-m-d', strtotime('+30 days')) ? '<span class="pill warn">' . U::date($exp) . '</span>' : U::date($exp)));
            $html .= '<tr class="' . ($r['low'] ? 'low' : '') . '"><td><a href="' . U::h(U::url('material', ['id' => $r['id']])) . '"><b>' . U::h($r['name']) . '</b></a>'
                . ($r['supplier'] !== '' ? '<div class="pr-small">' . U::h($r['supplier']) . '</div>' : '') . '</td>'
                . '<td class="pr-hide-m">' . U::h(Store::CATEGORIES[$r['category']] ?? $r['category']) . '</td>'
                . '<td class="pr-hide-m pr-code">' . U::h($r['code']) . '</td>'
                . '<td class="pr-num">' . ($r['low'] ? '<span class="pill warn">' . U::qty($r['stock'], $r['unit']) . '</span>' : U::qty($r['stock'], $r['unit'])) . '</td>'
                . '<td class="pr-num pr-hide-m">' . ($r['min_stock'] !== null ? U::qty($r['min_stock'], $r['unit']) : '-') . '</td>'
                . '<td class="pr-num pr-hide-m">' . U::unitPrice($r['unit_price'], $r['unit']) . '</td>'
                . '<td class="pr-hide-m">' . $expPill . '</td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    public static function card(Store $s, array $ctx, ?array $m, ?array $posted = null): string
    {
        $html = U::style() . U::tabs('materialy') . U::back(U::url('materialy'), 'Materiały i magazyn');
        if ($m === null || $posted !== null && !isset($m['id'])) {
            return $html . self::form($ctx, $m ?? [], $posted);
        }
        $stock = $s->stock($m['id']);
        $price = $s->unitPrices()[$m['id']] ?? null;
        $html .= '<div class="pr-two"><div class="card"><div class="pr-top" style="margin-bottom:8px"><h2 style="margin:0">' . U::h($m['name']) . '</h2>'
            . ((int) $m['archived'] === 1 ? '<span class="pill muted">w archiwum</span>' : '') . '</div>'
            . '<dl class="pr-dl"><dt>Na stanie</dt><dd><b class="pr-num">' . U::qty($stock, $m['unit']) . '</b>'
            . ($m['min_stock'] !== null && $stock <= (float) $m['min_stock'] ? ' <span class="pill warn">niski stan</span>' : '') . '</dd>'
            . '<dt>Minimum</dt><dd>' . ($m['min_stock'] !== null ? U::qty($m['min_stock'], $m['unit']) : '-') . '</dd>'
            . '<dt>Cena</dt><dd>' . U::unitPrice($price, $m['unit']) . '</dd>'
            . '<dt>Kategoria</dt><dd>' . U::h(Store::CATEGORIES[$m['category']] ?? $m['category']) . '</dd>'
            . ($m['code'] !== '' ? '<dt>Kod w numerze partii</dt><dd class="pr-code">' . U::h($m['code']) . '</dd>' : '')
            . ($m['fill_g'] !== null ? '<dt>Pojemność</dt><dd>' . U::n($m['fill_g']) . ' g wosku</dd>' : '')
            . ($m['supplier'] !== '' ? '<dt>Dostawca</dt><dd>' . U::h($m['supplier']) . '</dd>' : '')
            . ($m['notes'] !== '' ? '<dt>Uwagi</dt><dd style="white-space:pre-line">' . U::h($m['notes']) . '</dd>' : '')
            . '</dl>';
        if ($ctx['canEdit']) {
            $html .= '<div class="pr-actions" style="margin-top:14px"><a class="btn secondary" href="' . U::h(U::url('material', ['id' => $m['id'], 'edytuj' => 1])) . '">Edytuj</a>'
                . '<form method="post" action="' . U::h(U::url('material_zapisz')) . '">' . U::csrf($ctx) . '<input type="hidden" name="id" value="' . U::h($m['id']) . '">'
                . '<input type="hidden" name="archive" value="' . ((int) $m['archived'] === 1 ? 0 : 1) . '"><button class="btn secondary" type="submit">'
                . ((int) $m['archived'] === 1 ? 'Przywróć z archiwum' : 'Do archiwum') . '</button></form></div>';
        }
        $html .= '</div>';

        if ($ctx['canEdit'] && (int) $m['archived'] === 0) {
            $html .= '<form class="card pr-form" method="post" action="' . U::h(U::url('dostawa')) . '"><h3 style="margin:0">Przyjmij dostawę</h3>' . U::csrf($ctx)
                . '<input type="hidden" name="material_id" value="' . U::h($m['id']) . '"><div class="pr-grid">'
                . U::input('qty', '', 'Ilość (' . U::h(Store::UNITS[$m['unit']]) . ')', 'required inputmode="decimal"', $m['unit'] === 'g' ? '1 kg = 1000 g' : ($m['unit'] === 'ml' ? '1 l = 1000 ml' : ''))
                . U::input('price_total', '', 'Cena za całą dostawę (zł)', 'inputmode="decimal"', 'z faktury, brutto albo netto - byle zawsze tak samo')
                . U::input('received_at', date('Y-m-d'), 'Data przyjęcia', 'type="date"')
                . U::input('expires_at', '', 'Ważna do', 'type="date"')
                . U::input('lot_no', '', 'Nr partii dostawcy', 'maxlength="100"', 'z etykiety, do śledzenia partii')
                . U::input('supplier', $m['supplier'], 'Dostawca', 'maxlength="200"')
                . '</div><div class="pr-actions"><button class="btn" type="submit">Przyjmij na stan</button></div></form>';
        }
        $html .= '</div>';

        $dels = $s->deliveries($m['id']);
        $html .= '<div class="card pr-table" style="margin-top:18px"><h3 style="margin:0 0 8px">Dostawy</h3>';
        if ($dels === []) {
            $html .= '<p class="pr-muted" style="margin:0">Jeszcze nie było dostaw.</p>';
        } else {
            $html .= '<table><thead><tr><th>Przyjęto</th><th>Nr partii dostawcy</th><th class="pr-hide-m">Dostawca</th><th>Ważna do</th><th class="pr-num">Ilość</th>'
                . '<th class="pr-num">Zostało</th><th class="pr-num pr-hide-m">Cena</th>' . ($ctx['canEdit'] ? '<th></th>' : '') . '</tr></thead><tbody>';
            foreach ($dels as $d) {
                $exp = (string) ($d['expires_at'] ?? '');
                $expired = $exp !== '' && $exp < date('Y-m-d') && (float) $d['remaining'] > 0;
                $html .= '<tr><td>' . U::date($d['received_at']) . '</td><td class="pr-code">' . U::h($d['lot_no'] !== '' ? $d['lot_no'] : '-') . '</td>'
                    . '<td class="pr-hide-m">' . U::h($d['supplier'] !== '' ? $d['supplier'] : ($d['notes'] !== '' ? $d['notes'] : '-')) . '</td>'
                    . '<td>' . ($expired ? '<span class="pill bad">' . U::date($exp) . '</span>' : U::date($exp)) . '</td>'
                    . '<td class="pr-num">' . U::qty($d['qty'], $m['unit']) . '</td><td class="pr-num">' . U::qty($d['remaining'], $m['unit']) . '</td>'
                    . '<td class="pr-num pr-hide-m">' . U::money($d['price_total']) . '</td>'
                    . ($ctx['canEdit'] ? '<td><form method="post" action="' . U::h(U::url('dostawa_usun')) . '" onsubmit="return confirm(\'Przenieść dostawę do kosza? To, co z niej zostało, zniknie ze stanu.\')">'
                        . U::csrf($ctx) . '<input type="hidden" name="id" value="' . U::h($d['id']) . '"><button class="btn secondary" type="submit" title="Do kosza">🗑</button></form></td>' : '')
                    . '</tr>';
            }
            $html .= '</tbody></table>';
        }
        $trash = $s->deliveries($m['id'], true);
        if ($trash !== [] && $ctx['canEdit']) {
            $html .= '<details style="margin-top:10px"><summary class="pr-muted">Kosz dostaw (' . count($trash) . ', usuwane na stałe po 30 dniach)</summary><ul class="pr-list">';
            foreach ($trash as $d) {
                $html .= '<li><span>' . U::date($d['received_at']) . ' · ' . U::qty($d['qty'], $m['unit']) . ($d['lot_no'] !== '' ? ' · ' . U::h($d['lot_no']) : '') . '</span>'
                    . '<form method="post" action="' . U::h(U::url('dostawa_przywroc')) . '">' . U::csrf($ctx) . '<input type="hidden" name="id" value="' . U::h($d['id']) . '">'
                    . '<button class="btn secondary" type="submit">Przywróć</button></form></li>';
            }
            $html .= '</ul></details>';
        }
        $html .= '</div>';

        $moves = $s->moves($m['id'], 30);
        if ($moves !== []) {
            $html .= '<div class="card pr-table"><h3 style="margin:0 0 8px">Historia stanu</h3><table><thead><tr><th>Kiedy</th><th>Co</th><th class="pr-num">Zmiana</th><th class="pr-hide-m">Kto</th></tr></thead><tbody>';
            foreach ($moves as $mv) {
                $what = ucfirst((string) $mv['kind']) . ($mv['batch_code'] ? ' <a href="' . U::h(U::url('partia', ['id' => $mv['batch_id']])) . '" class="pr-code">' . U::h($mv['batch_code']) . '</a>'
                    : ($mv['note'] !== '' ? ' <span class="pr-muted">' . U::h($mv['note']) . '</span>' : ''));
                $html .= '<tr><td class="pr-num">' . U::h(substr((string) $mv['created_at'], 0, 16)) . '</td><td>' . $what . '</td>'
                    . '<td class="pr-num ' . ((float) $mv['qty'] < 0 ? 'pr-bad' : 'pr-ok') . '">' . ((float) $mv['qty'] > 0 ? '+' : '') . U::qty($mv['qty'], $m['unit']) . '</td>'
                    . '<td class="pr-hide-m">' . U::h($mv['created_by']) . '</td></tr>';
            }
            $html .= '</tbody></table><p class="pr-small" style="margin:8px 0 0">Godziny w UTC.</p></div>';
        }
        return $html;
    }

    public static function form(array $ctx, array $m, ?array $posted = null): string
    {
        $v = static fn(string $k, string $d = ''): string => (string) ($posted[$k] ?? $m[$k] ?? $d);
        $edit = isset($m['id']);
        $cat = $v('category', (string) ($_GET['cat'] ?? 'wosk'));
        return '<form class="card pr-form" method="post" action="' . U::h(U::url('material_zapisz')) . '"><h3 style="margin:0">' . ($edit ? 'Edytuj materiał' : 'Nowy materiał') . '</h3>'
            . U::csrf($ctx) . ($edit ? '<input type="hidden" name="id" value="' . U::h($m['id']) . '">' : '')
            . '<div class="pr-grid">'
            . U::input('name', $v('name'), 'Nazwa', 'required maxlength="200" autofocus')
            . U::select('category', Store::CATEGORIES, $cat, 'Kategoria')
            . U::select('unit', ['g' => 'gramy (g)', 'ml' => 'mililitry (ml)', 'szt' => 'sztuki'], $v('unit', in_array($cat, ['knot', 'pojemnik', 'opakowanie', 'etykieta'], true) ? 'szt' : 'g'), 'Liczę w')
            . U::input('code', $v('code'), 'Kod do numeru partii', 'maxlength="8"', 'wosk i zapach: 2 znaki, np. 01 albo AB')
            . U::input('min_stock', $v('min_stock') !== '' ? U::n($v('min_stock')) : '', 'Minimum (alarm niskiego stanu)', 'inputmode="decimal"')
            . U::input('supplier', $v('supplier'), 'Dostawca', 'maxlength="200"')
            . U::input('fill_g', $v('fill_g') !== '' ? U::n($v('fill_g')) : '', 'Pojemność (g wosku)', 'inputmode="decimal"', 'tylko pojemniki')
            . '</div><label>Uwagi<textarea name="notes" rows="3" maxlength="2000">' . U::h($v('notes')) . '</textarea></label>'
            . (!$edit ? '<details><summary class="pr-muted">Masz już ten materiał na półce? Wpisz stan od razu</summary><div class="pr-grid" style="margin-top:10px">'
                . U::input('stock', '', 'Na stanie teraz', 'inputmode="decimal"')
                . U::input('price_total', '', 'Ile kosztowało (zł za tę ilość)', 'inputmode="decimal"')
                . U::input('expires_at', '', 'Ważne do', 'type="date"') . '</div></details>' : '')
            . '<div class="pr-actions"><button class="btn" type="submit">Zapisz</button><a class="btn secondary" href="' . U::h($edit ? U::url('material', ['id' => $m['id']]) : U::url('materialy')) . '">Anuluj</a></div></form>';
    }

    public static function inventory(Store $s, array $ctx): string
    {
        $rows = $s->materials();
        $html = U::style() . U::tabs('materialy') . U::back(U::url('materialy'), 'Materiały i magazyn');
        if ($rows === []) {
            return $html . '<div class="card pr-muted">Najpierw dodaj materiały.</div>';
        }
        $html .= '<form class="card pr-table" method="post" action="' . U::h(U::url('inwentaryzacja')) . '">' . U::csrf($ctx)
            . '<p class="pr-muted" style="margin:0 0 10px">Wpisz, ile faktycznie jest na półce. Puste pola zostają bez zmian. Brak zdejmujemy z najstarszych dostaw, nadwyżkę dopisujemy jako dostawę „z inwentaryzacji”.</p>'
            . '<table><thead><tr><th>Materiał</th><th class="pr-num">W systemie</th><th style="width:180px">Policzono</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $html .= '<tr><td>' . U::h($r['name']) . ' <span class="pr-small">' . U::h(Store::CATEGORIES[$r['category']] ?? '') . '</span></td>'
                . '<td class="pr-num">' . U::qty($r['stock'], $r['unit']) . '</td>'
                . '<td><input name="counted[' . U::h($r['id']) . ']" inputmode="decimal" placeholder="' . U::h(Store::UNITS[$r['unit']]) . '" style="width:100%"></td></tr>';
        }
        return $html . '</tbody></table><div class="pr-actions" style="margin-top:12px"><button class="btn" type="submit">Zapisz inwentaryzację</button></div></form>';
    }
}
