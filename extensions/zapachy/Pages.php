<?php
declare(strict_types=1);

namespace PaseExt\Zapachy;

use PaseExt\Zapachy\Ui as U;

/** Strony wtyczki Zapachy (HTML). Zapis obsługuje ZapachyExtension. */
final class Pages
{
    // ---------------------------------------------------------------- lista zapachów

    public static function list(Store $s, array $ctx): string
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $note = trim((string) ($_GET['nuta'] ?? ''));
        $cat = trim((string) ($_GET['kategoria'] ?? ''));
        $archived = isset($_GET['archiwum']);
        $all = $s->allFragrances();
        $html = U::style() . U::tabs('lista');

        if ($all === [] && !$archived) {
            return $html . '<div class="card zp-empty"><div class="ic">🌸</div><h3>Dodaj pierwszy zapach</h3>'
                . '<p>Każdy olejek dostaje numer miejsca w magazynie (np. Sweet Caramel = 18) i nuty zapachowe. Przypisz zapach do produktu, '
                . 'a numer pokaże się przy pakowaniu i na liście produkcji.</p><div class="zp-actions" style="justify-content:center">'
                . ($ctx['canEdit'] ? '<a class="btn" href="' . U::h(U::url('edytuj', ['nowy' => 1])) . '">+ Dodaj zapach</a>'
                    . '<a class="btn secondary" href="' . U::h(U::url('import')) . '">Import z Aroma Helper</a>' : '')
                . '</div></div>';
        }

        $rows = $s->search($q, $note, $cat, $archived);
        $counts = $s->productCounts();
        $cats = $s->noteCategories();
        $mats = $s->productionScents();
        $linked = count(array_filter($all, static fn(array $f) => ($counts[$f['id']] ?? 0) > 0));
        $noNumber = count(array_filter($all, static fn(array $f) => trim((string) $f['storage_no']) === ''));

        $html .= '<div class="zp-top"><p>Olejki z numerem miejsca w magazynie i nutami. Szukaj po nazwie, numerze albo nucie.</p><div class="zp-actions">'
            . ($ctx['canEdit'] ? '<a class="btn" href="' . U::h(U::url('edytuj', ['nowy' => 1])) . '">+ Dodaj zapach</a>' : '')
            . ($ctx['canEdit'] ? '<a class="btn secondary" href="' . U::h(U::url('import')) . '">Import</a>' : '')
            . '<a class="btn secondary" href="' . U::h(U::url('eksport')) . '">⬇ Eksport CSV</a></div></div>';
        $html .= '<div class="zp-cards">'
            . '<div class="stat"><div class="label">Zapachy</div><div class="num">' . count($all) . '</div></div>'
            . '<a class="stat" style="color:inherit;text-decoration:none" href="' . U::h(U::url('przypisz')) . '"><div class="label">Z produktami</div><div class="num">' . $linked . '</div></a>'
            . '<div class="stat"><div class="label">Bez numeru miejsca</div><div class="num">' . $noNumber . '</div></div>'
            . '<a class="stat" style="color:inherit;text-decoration:none" href="' . U::h(U::url('nuty')) . '"><div class="label">Nuty w słowniku</div><div class="num">' . count($cats) . '</div></a>'
            . '</div>';

        $html .= '<form class="zp-filter" method="get" action="ext.php"><input type="hidden" name="page" value="zapachy.lista">'
            . '<input type="search" name="q" value="' . U::h($q) . '" placeholder="Nazwa, numer (np. 18), nuta, producent…" autofocus>';
        $categories = $s->categories();
        if ($categories !== []) {
            $html .= '<select name="kategoria" onchange="this.form.submit()"><option value="">Kategoria nut: wszystkie</option>';
            foreach ($categories as $c) {
                $html .= '<option value="' . U::h($c) . '"' . ($c === $cat ? ' selected' : '') . '>' . U::h($c) . '</option>';
            }
            $html .= '</select>';
        }
        if ($note !== '') {
            $html .= '<input type="hidden" name="nuta" value="' . U::h($note) . '">';
        }
        if ($archived) {
            $html .= '<input type="hidden" name="archiwum" value="1">';
        }
        $html .= '<button class="btn secondary" type="submit">Szukaj</button>'
            . (($q !== '' || $note !== '' || $cat !== '') ? '<a class="btn secondary" href="' . U::h(U::url('lista')) . '">Wyczyść</a>' : '')
            . '<a class="btn secondary" href="' . U::h($archived ? U::url('lista') : U::url('lista', ['archiwum' => 1])) . '">' . ($archived ? '← Aktywne' : 'Archiwum') . '</a>'
            . '</form>';

        $top = array_slice($s->topNotes(), 0, 18, true);
        if ($top !== []) {
            $html .= '<div class="zp-topnotes"><span class="zp-small">Najczęstsze nuty:</span>';
            foreach ($top as $n => $c) {
                $on = mb_strtolower($note) === $n;
                $chip = U::noteChips($n, $cats, false);
                $html .= '<a href="' . U::h($on ? U::url('lista') : U::url('lista', ['nuta' => $n])) . '" style="text-decoration:none" title="' . $c . ' zapachów">'
                    . str_replace('class="zp-note"', 'class="zp-note' . ($on ? ' on' : '') . '"', $chip) . '</a>';
            }
            $html .= '</div>';
        }
        if ($note !== '') {
            $html .= '<p class="zp-small" style="margin:-6px 0 12px">Zapachy z nutą <b>' . U::h($note) . '</b>: ' . count($rows) . '.</p>';
        }

        if ($rows === []) {
            return $html . '<div class="card"><p class="zp-small" style="margin:0">Nic nie znaleziono.</p></div>';
        }
        $html .= '<div class="card zp-table"><table class="zp-main"><thead><tr><th>Nr</th><th>Zapach</th><th>Nuty</th>'
            . ($mats !== [] ? '<th class="pr-num">Olejek</th>' : '') . '<th>Produkty</th></tr></thead><tbody>';
        foreach ($rows as $f) {
            $pyr = '';
            foreach (['head_notes' => 'Głowa', 'heart_notes' => 'Serce', 'base_notes' => 'Baza'] as $k => $l) {
                if (trim((string) $f[$k]) !== '') {
                    $pyr .= '<dt>' . $l . '</dt><dd>' . U::noteChips((string) $f[$k], $cats) . '</dd>';
                }
            }
            $aliases = trim((string) $f['aliases']);
            $sub = array_filter([$aliases, trim($f['producer'] . ' ' . $f['producer_code'])]);
            $n = $counts[$f['id']] ?? 0;
            $html .= '<tr><td class="c-no">' . U::no($f) . '</td>'
                . '<td><a class="zp-name" href="' . U::h(U::url('zapach', ['id' => $f['id']])) . '">' . U::h($f['name']) . '</a>'
                . ($sub !== [] ? '<div class="zp-alias">' . U::h(implode(' · ', $sub)) . '</div>' : '') . '</td>'
                . '<td>' . ($pyr !== '' ? '<dl class="zp-pyr">' . $pyr . '</dl>' : '<span class="zp-small">brak nut</span>') . '</td>'
                . ($mats !== [] ? '<td class="c-hide">' . self::stock($f, $mats) . '</td>' : '')
                . '<td class="c-hide">' . ($n > 0 ? '<a href="' . U::h(U::url('zapach', ['id' => $f['id']])) . '#produkty">' . $n . ' ' . self::plural($n, 'produkt', 'produkty', 'produktów') . '</a>' : '<span class="zp-small">—</span>') . '</td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    /** Stan olejku z wtyczki Produkcja. */
    public static function stock(array $f, array $mats): string
    {
        $m = $mats[(string) $f['material_id']] ?? null;
        if ($m === null) {
            return '<span class="zp-small">—</span>';
        }
        $low = $m['stock'] <= 0 || ($m['min_stock'] !== null && $m['stock'] < $m['min_stock']);
        return '<span class="zp-stock' . ($low ? ' low' : '') . '" title="' . U::h($m['name']) . ' (Produkcja)">' . U::h(U::qty($m['stock'], $m['unit'])) . '</span>';
    }

    // ---------------------------------------------------------------- karta zapachu

    public static function card(Store $s, array $ctx, array $f): string
    {
        $cats = $s->noteCategories();
        $html = U::style() . U::tabs('lista') . U::back(U::url('lista'), 'Zapachy');
        $html .= '<div class="zp-top"><div class="zp-head">' . U::no($f, ' big') . '<div><h2>' . U::h($f['name']) . '</h2>'
            . (trim((string) $f['aliases']) !== '' ? '<div class="zp-alias">Także: ' . U::h($f['aliases']) . '</div>' : '')
            . ((int) $f['archived'] === 1 ? '<div class="zp-small">W archiwum</div>' : '') . '</div></div>';
        if ($ctx['canEdit']) {
            $html .= '<div class="zp-actions"><a class="btn" href="' . U::h(U::url('edytuj', ['id' => $f['id']])) . '">Edytuj</a>'
                . '<form method="post" action="' . U::h(U::url('archiwizuj')) . '">' . U::csrf($ctx) . '<input type="hidden" name="id" value="' . U::h($f['id']) . '">'
                . '<input type="hidden" name="archive" value="' . ((int) $f['archived'] === 1 ? 0 : 1) . '"><button class="btn secondary" type="submit">'
                . ((int) $f['archived'] === 1 ? 'Przywróć z archiwum' : 'Do archiwum') . '</button></form>'
                . '<form method="post" action="' . U::h(U::url('usun')) . '" onsubmit="return confirm(\'Usunąć zapach i jego przypisania do produktów?\')">' . U::csrf($ctx)
                . '<input type="hidden" name="id" value="' . U::h($f['id']) . '"><button class="btn secondary" type="submit" style="color:var(--danger-ink)">Usuń</button></form></div>';
        }
        $html .= '</div><div class="zp-two">';

        // Nuty
        $pyr = '';
        foreach (['head_notes' => 'Głowa', 'heart_notes' => 'Serce', 'base_notes' => 'Baza'] as $k => $l) {
            $pyr .= '<dt>' . $l . '</dt><dd>' . (trim((string) $f[$k]) !== '' ? U::noteChips((string) $f[$k], $cats) : '<span class="zp-small">—</span>') . '</dd>';
        }
        $html .= '<div class="card"><strong>Nuty zapachowe</strong><dl class="zp-pyr" style="margin-top:10px">' . $pyr . '</dl>';
        $byCat = [];
        foreach (Store::fragranceNotes($f) as $n) {
            if (($cats[$n] ?? '') !== '') {
                $byCat[$cats[$n]] = ($byCat[$cats[$n]] ?? 0) + 1;
            }
        }
        if ($byCat !== []) {
            arsort($byCat);
            $total = array_sum($byCat);
            $html .= '<p class="zp-small" style="margin:12px 0 4px">Charakter:</p>';
            foreach ($byCat as $c => $n) {
                $html .= U::catChip($c) . '<span class="zp-small" style="margin-right:8px">' . round($n / $total * 100) . '%</span>';
            }
        }
        if (trim((string) $f['description']) !== '') {
            $html .= '<p class="zp-small" style="margin:14px 0 4px">Opis</p><div class="zp-desc">' . U::h($f['description']) . '</div>';
        }
        $html .= '</div>';

        // Dane
        $mats = $s->productionScents();
        $m = $mats[(string) $f['material_id']] ?? null;
        $dl = '';
        $add = static function (string $l, string $v) use (&$dl): void {
            if (trim(strip_tags($v)) !== '') {
                $dl .= '<dt>' . $l . '</dt><dd>' . $v . '</dd>';
            }
        };
        $add('Miejsce w magazynie', U::h($f['storage_no']));
        $add('Producent', U::h($f['producer']));
        $add('Kod producenta', U::h($f['producer_code']));
        $add('Cena', $f['price_per_g'] !== null ? U::h(number_format((float) $f['price_per_g'] * 1000, 2, ',', ' ') . ' zł/kg') : '');
        $add('Maks. stężenie', $f['max_concentration'] !== null ? U::h(U::n($f['max_concentration']) . '%') : '');
        $add('UFI', U::h($f['ufi']));
        if ($m !== null) {
            $add('Olejek w Produkcji', '<a href="' . U::h(\Pase\Plugin\Hooks::pageUrl('produkcja.material', ['id' => $m['id']])) . '">' . U::h($m['name']) . '</a> · ' . self::stock($f, $mats));
        }
        $html .= '<div class="card"><strong>Dane</strong>' . ($dl !== '' ? '<dl class="zp-dl" style="margin-top:10px">' . $dl . '</dl>' : '<p class="zp-small">Brak.</p>');
        if (trim((string) $f['comments']) !== '') {
            $html .= '<p class="zp-small" style="margin:14px 0 4px">Uwagi</p><div class="zp-desc">' . U::h($f['comments']) . '</div>';
        }
        $html .= '</div></div>';

        // Produkty
        $products = $s->productsOf((string) $f['id']);
        $html .= '<div class="card" id="produkty" style="margin-top:18px"><strong>Produkty z tym zapachem (' . count($products) . ')</strong>';
        if ($products === []) {
            $html .= '<p class="zp-small">Jeszcze żaden. Znajdź produkt poniżej albo przypisz zapach na karcie produktu w Magazynie.</p>';
        } else {
            $html .= '<ul class="zp-list" style="margin-top:8px">';
            foreach ($products as $p) {
                $html .= '<li><span><a href="product_view.php?id=' . (int) $p['id'] . '">' . U::h($p['name']) . '</a> <span class="zp-small">' . U::h($p['sku']) . '</span></span>'
                    . ($ctx['canEdit'] ? '<form method="post" action="' . U::h(U::url('odlacz')) . '">' . U::csrf($ctx) . '<input type="hidden" name="id" value="' . U::h($f['id']) . '">'
                        . '<input type="hidden" name="product_id" value="' . (int) $p['id'] . '"><button class="zp-x" type="submit" title="Odłącz">✕</button></form>' : '') . '</li>';
            }
            $html .= '</ul>';
        }
        if ($ctx['canEdit']) {
            $find = trim((string) ($_GET['szukaj'] ?? ''));
            $html .= '<form method="get" action="ext.php" class="zp-filter" style="margin-top:14px"><input type="hidden" name="page" value="zapachy.zapach">'
                . '<input type="hidden" name="id" value="' . U::h($f['id']) . '"><input type="search" name="szukaj" value="' . U::h($find) . '" placeholder="Szukaj produktu: nazwa albo SKU">'
                . '<button class="btn secondary" type="submit">Szukaj</button></form>';
            if ($find !== '') {
                $html .= self::productPicker($s, $ctx, $f, $find);
            }
        }
        return $html . '</div>';
    }

    private static function productPicker(Store $s, array $ctx, array $f, string $find): string
    {
        $like = '%' . str_replace(['%', '_'], '', $find) . '%';
        try {
            $rows = $s->all('SELECT id, sku, name FROM products WHERE name LIKE ? OR sku LIKE ? ORDER BY name, sku LIMIT 60', [$like, $like]);
        } catch (\PDOException) {
            $rows = [];
        }
        if ($rows === []) {
            return '<p class="zp-small">Nie ma takiego produktu.</p>';
        }
        $have = array_flip(array_map('intval', array_column($s->productsOf((string) $f['id']), 'id')));
        $html = '<form method="post" action="' . U::h(U::url('przypisz_zapisz')) . '">' . U::csrf($ctx) . '<input type="hidden" name="fragrance_id" value="' . U::h($f['id']) . '">'
            . '<input type="hidden" name="back" value="zapach"><ul class="zp-list">';
        foreach ($rows as $p) {
            $on = isset($have[(int) $p['id']]);
            $html .= '<li><label style="display:flex;gap:8px;align-items:center;flex:1"><input type="checkbox" name="products[]" value="' . (int) $p['id'] . '"' . ($on ? ' checked disabled' : '') . '>'
                . '<span>' . U::h($p['name']) . ' <span class="zp-small">' . U::h($p['sku']) . '</span></span></label>' . ($on ? '<span class="zp-small">już ma</span>' : '') . '</li>';
        }
        return $html . '</ul><button class="btn" type="submit" style="margin-top:8px">Przypisz zaznaczone</button></form>';
    }

    // ---------------------------------------------------------------- formularz

    public static function form(Store $s, array $ctx, array $f, ?array $posted = null): string
    {
        $v = static fn(string $k): string => (string) ($posted[$k] ?? $f[$k] ?? '');
        $new = ($f['id'] ?? '') === '';
        $html = U::style() . U::tabs('lista') . U::back($new ? U::url('lista') : U::url('zapach', ['id' => $f['id']]), $new ? 'Zapachy' : (string) $f['name']);
        $html .= '<form class="card zp-form" method="post" action="' . U::h(U::url('zapisz')) . '">' . U::csrf($ctx)
            . '<input type="hidden" name="id" value="' . U::h($f['id'] ?? '') . '"><h3 style="margin:0">' . ($new ? 'Nowy zapach' : 'Edycja zapachu') . '</h3>'
            . '<div class="zp-grid" style="grid-template-columns:140px 1fr">'
            . '<label>Numer miejsca<input name="storage_no" value="' . U::h($v('storage_no')) . '" maxlength="20" placeholder="np. 18" style="font-size:18px;font-weight:700"><small>gdzie stoi olejek</small></label>'
            . '<label>Nazwa<input name="name" value="' . U::h($v('name')) . '" maxlength="200" required placeholder="np. Sweet Caramel"></label></div>'
            . '<label>Inne nazwy<input name="aliases" value="' . U::h($v('aliases')) . '" placeholder="po przecinku, np. nazwa handlowa producenta">'
            . '<small>Po nich też znajdziesz zapach, a CRM rozpozna go w wariancie zamówienia („Zapach: …”) i w nazwie produktu.</small></label>'
            . '<div class="zp-grid">'
            . '<label>Nuty głowy<textarea name="head_notes" rows="2" placeholder="bergamotka, cytryna" list="zp-notes">' . U::h($v('head_notes')) . '</textarea></label>'
            . '<label>Nuty serca<textarea name="heart_notes" rows="2" placeholder="karmel, wanilia">' . U::h($v('heart_notes')) . '</textarea></label>'
            . '<label>Nuty bazy<textarea name="base_notes" rows="2" placeholder="piżmo, tonka">' . U::h($v('base_notes')) . '</textarea></label></div>'
            . '<p class="zp-small" style="margin:-6px 0 0">Nuty oddzielaj przecinkami. Nowe nuty trafią do słownika (zakładka Nuty), gdzie dasz im kategorię.</p>'
            . '<div class="zp-grid">'
            . '<label>Producent<input name="producer" value="' . U::h($v('producer')) . '" maxlength="200"></label>'
            . '<label>Kod producenta<input name="producer_code" value="' . U::h($v('producer_code')) . '" maxlength="60"></label>'
            . '<label>Cena (zł za gram)<input name="price_per_g" value="' . U::h($v('price_per_g') !== '' ? U::n($v('price_per_g')) : '') . '" inputmode="decimal"></label>'
            . '<label>Maks. stężenie (%)<input name="max_concentration" value="' . U::h($v('max_concentration') !== '' ? U::n($v('max_concentration')) : '') . '" inputmode="decimal"></label></div>'
            . '<label>UFI<input name="ufi" value="' . U::h($v('ufi')) . '" maxlength="255" placeholder="np. 6%: XXXX-XXXX-XXXX-XXXX, 10%: …"></label>';
        $mats = $s->productionScents();
        if ($mats !== []) {
            $opts = ['' => '— bez powiązania —'];
            foreach ($mats as $m) {
                $opts[$m['id']] = $m['name'] . ' (' . U::qty($m['stock'], $m['unit']) . ')';
            }
            $cur = (string) ($posted['material_id'] ?? $f['material_id'] ?? '');
            $sel = '';
            foreach ($opts as $k => $l) {
                $sel .= '<option value="' . U::h($k) . '"' . ((string) $k === $cur ? ' selected' : '') . '>' . U::h($l) . '</option>';
            }
            $html .= '<label>Olejek w Produkcji<select name="material_id">' . $sel . '</select><small>Stan olejku i jego zużycie prowadzi wtyczka Produkcja (materiały z kategorii Zapach).</small></label>';
        }
        $html .= '<label>Opis<textarea name="description" rows="4">' . U::h($v('description')) . '</textarea></label>'
            . '<label>Uwagi wewnętrzne<textarea name="comments" rows="2">' . U::h($v('comments')) . '</textarea></label>'
            . '<div class="zp-actions"><button class="btn" type="submit">Zapisz</button></div>';
        $html .= '<datalist id="zp-notes">';
        foreach (array_keys($s->noteCategories()) as $n) {
            $html .= '<option value="' . U::h($n) . '">';
        }
        return $html . '</datalist></form>';
    }

    // ---------------------------------------------------------------- nuty

    public static function notes(Store $s, array $ctx): string
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $rows = $s->noteDict($q);
        $usage = $s->topNotes();
        $html = U::style() . U::tabs('nuty');
        $html .= '<div class="zp-top"><p>Słownik nut z kategorią (cytrusowa, kwiatowa, słodka…). Kategorie dają kolory nut i filtr na liście zapachów.</p></div><div class="zp-two">';
        $html .= '<div class="card"><form class="zp-filter" method="get" action="ext.php"><input type="hidden" name="page" value="zapachy.nuty">'
            . '<input type="search" name="q" value="' . U::h($q) . '" placeholder="Szukaj nuty albo kategorii"><button class="btn secondary">Szukaj</button></form>';
        if ($rows === []) {
            $html .= '<p class="zp-small">Brak nut.</p>';
        } else {
            $html .= '<div class="zp-table"><table><thead><tr><th>Nuta</th><th>Kategoria</th><th class="pr-num">Zapachy</th>' . ($ctx['canEdit'] ? '<th></th>' : '') . '</tr></thead><tbody>';
            foreach ($rows as $n) {
                $c = $usage[mb_strtolower((string) $n['name'])] ?? 0;
                $html .= '<tr><td>' . ($c > 0 ? '<a href="' . U::h(U::url('lista', ['nuta' => $n['name']])) . '">' . U::h($n['name']) . '</a>' : U::h($n['name'])) . '</td><td>';
                if ($ctx['canEdit']) {
                    $html .= '<form method="post" action="' . U::h(U::url('nuta_zapisz')) . '" style="display:flex;gap:6px;margin:0">' . U::csrf($ctx)
                        . '<input type="hidden" name="name" value="' . U::h($n['name']) . '"><input name="category" value="' . U::h($n['category']) . '" list="zp-cats" placeholder="kategoria" style="width:150px">'
                        . '<button class="btn secondary" type="submit" title="Zapisz kategorię">✓</button></form>';
                } else {
                    $html .= U::catChip((string) $n['category']);
                }
                $html .= '</td><td class="pr-num">' . $c . '</td>';
                if ($ctx['canEdit']) {
                    $html .= '<td><form method="post" action="' . U::h(U::url('nuta_usun')) . '" style="margin:0">' . U::csrf($ctx) . '<input type="hidden" name="id" value="' . U::h($n['id']) . '">'
                        . '<button class="zp-x" type="submit" title="Usuń ze słownika">✕</button></form></td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div>';
        }
        $html .= '<datalist id="zp-cats">';
        foreach ($s->categories() as $c) {
            $html .= '<option value="' . U::h($c) . '">';
        }
        $html .= '</datalist></div>';
        if ($ctx['canEdit']) {
            $html .= '<div class="card"><strong>Dodaj nuty</strong><form method="post" action="' . U::h(U::url('nuty_dodaj')) . '" class="zp-form" style="margin-top:10px">' . U::csrf($ctx)
                . '<label>Jedna nuta w wierszu: nazwa, kategoria<textarea name="bulk" rows="8" placeholder="wanilia, słodka&#10;bergamotka, cytrusowa&#10;róża, kwiatowa"></textarea>'
                . '<small>Nuty, które już są, zostają (dostaną kategorię, jeśli jej nie miały).</small></label><div><button class="btn" type="submit">Dodaj</button></div></form></div>';
        }
        return $html . '</div>';
    }

    // ---------------------------------------------------------------- przypisanie do produktów

    public static function assign(Store $s, array $ctx): string
    {
        $html = U::style() . U::tabs('przypisz');
        $html .= '<div class="zp-top"><p>Produkty z Magazynu bez zapachu, dla których CRM rozpoznał zapach po atrybucie „Zapach” albo nazwie. '
            . 'Zaznacz te, które się zgadzają, i przypisz jednym kliknięciem. Pojedynczy produkt przypiszesz też na jego karcie w Magazynie.</p></div>';
        if ($s->allFragrances() === []) {
            return $html . '<div class="card"><p class="zp-small" style="margin:0">Najpierw dodaj zapachy albo zaimportuj je z Aroma Helper.</p></div>';
        }
        $sug = $s->suggestions();
        try {
            $without = (int) $s->one('SELECT COUNT(*) AS n FROM products p WHERE NOT EXISTS (SELECT 1 FROM ext_zap_links l WHERE l.product_id = p.id)')['n'];
        } catch (\PDOException) {
            $without = 0;
        }
        $html .= '<p class="zp-small">Produkty bez zapachu: ' . $without . ', z propozycją: ' . count($sug) . '.</p>';
        if ($sug === []) {
            return $html . '<div class="card"><p class="zp-small" style="margin:0">Brak propozycji. Przypisz zapach na karcie zapachu („Szukaj produktu”) albo na karcie produktu.</p></div>';
        }
        $how = ['variant' => 'z atrybutu', 'name' => 'z nazwy'];
        $html .= '<form method="post" action="' . U::h(U::url('przypisz_zapisz')) . '" class="card zp-table">' . U::csrf($ctx)
            . '<table><thead><tr><th><input type="checkbox" checked onclick="document.querySelectorAll(\'.zp-sug\').forEach(c=>c.checked=this.checked)"></th><th>Produkt</th><th>Zapach</th><th></th></tr></thead><tbody>';
        foreach ($sug as $r) {
            $p = $r['product'];
            $html .= '<tr><td><input type="checkbox" class="zp-sug" name="pairs[]" value="' . (int) $p['id'] . ':' . U::h($r['fragrance']['id']) . '" checked' . ($ctx['canEdit'] ? '' : ' disabled') . '></td>'
                . '<td><a href="product_view.php?id=' . (int) $p['id'] . '">' . U::h($p['name']) . '</a> <span class="zp-small">' . U::h($p['sku']) . '</span></td>'
                . '<td>' . U::chip($r['fragrance']) . '</td><td class="zp-how">' . ($how[$r['how']] ?? '') . '</td></tr>';
        }
        $html .= '</tbody></table>' . ($ctx['canEdit'] ? '<div style="margin-top:12px"><button class="btn" type="submit">Przypisz zaznaczone</button></div>' : '') . '</form>';
        return $html;
    }

    // ---------------------------------------------------------------- import

    public static function import(Store $s, array $ctx): string
    {
        $html = U::style() . U::tabs('lista') . U::back(U::url('lista'), 'Zapachy') . '<h3 style="margin:0 0 10px">Import i eksport danych</h3>';
        $html .= '<div class="zp-top"><p>Przeniesienie zapachów i nut z wtyczki Aroma Helper ze sklepu albo z Excela. Ponowny import niczego nie dubluje, a puste pole w pliku nie kasuje danych w CRM.</p>'
            . '<div class="zp-actions"><a class="btn secondary" href="' . U::h(U::url('eksport')) . '">⬇ Eksport zapachów (CSV)</a>'
            . '<a class="btn secondary" href="' . U::h(U::url('eksport_nuty')) . '">⬇ Eksport nut (CSV)</a></div></div>';
        $html .= '<div class="zp-two"><div class="card"><strong>Kopia bazy albo plik CSV</strong>'
            . '<p class="zp-small">Najpełniejszy import (numery, nuty, producent, ceny, opisy i słownik nut): w panelu hostingu otwórz <b>phpMyAdmin</b>, '
            . 'zaznacz tabele <code>wp_aroma_fragrances</code> i <code>wp_aroma_notes</code> → Eksport → SQL (albo CSV) i wgraj plik tutaj. '
            . 'Działa też własny plik z Excela z nagłówkami, np. <code>numer;nazwa;nuty głowy;nuty serca;nuty bazy;opis</code>, '
            . 'i plik z eksportu tej wtyczki (kolumna <code>produkty sku</code> przypisuje zapach do produktów).</p>'
            . '<form method="post" enctype="multipart/form-data" action="' . U::h(U::url('import_zapisz')) . '" class="zp-form">' . U::csrf($ctx)
            . '<input type="hidden" name="mode" value="file"><input type="file" name="file" accept=".sql,.csv,.txt,.html,.htm" required>'
            . '<div><button class="btn" type="submit">Importuj plik</button></div></form></div>';
        $html .= '<div class="card"><strong>Ze strony katalogu w sklepie</strong>'
            . '<p class="zp-small">Jeśli na stronie sklepu jest katalog zapachów (shortcode <code>[aroma_zapachy]</code>), wklej jej adres. '
            . 'Przeniesie numer, nazwę, nuty głowy, serca i bazy oraz opis (bez producenta i cen).</p>'
            . '<form method="post" action="' . U::h(U::url('import_zapisz')) . '" class="zp-form">' . U::csrf($ctx)
            . '<input type="hidden" name="mode" value="url"><input type="url" name="url" placeholder="https://twojsklep.pl/zapachy/" required>'
            . '<div><button class="btn secondary" type="submit">Pobierz ze strony</button></div></form></div></div>';
        if ($s->hasProduction()) {
            $mats = $s->productionScents();
            $linked = count(array_filter($s->allFragrances(), static fn(array $f) => !empty($f['material_id'])));
            $html .= '<div class="card" style="margin-top:18px"><strong>Olejki z wtyczki Produkcja</strong>'
                . '<p class="zp-small">W Produkcji jest ' . count($mats) . ' olejków (materiały z kategorii Zapach), z zapachami połączonych: ' . $linked . '. '
                . 'Połączenie pokazuje stan olejku przy zapachu, a numer miejsca przy recepturze na liście produkcji.</p>'
                . '<form method="post" action="' . U::h(U::url('polacz_produkcja')) . '">' . U::csrf($ctx) . '<button class="btn secondary" type="submit">Połącz po nazwie</button></form></div>';
        }
        return $html;
    }

    // ---------------------------------------------------------------- produkt w Magazynie

    public static function productSection(Store $s, array $ctx, array $p): string
    {
        $pid = (int) $p['id'];
        $mine = $s->forProducts([$pid])[$pid] ?? [];
        $cats = $s->noteCategories();
        $html = U::chipStyle();
        if ($mine === []) {
            $html .= '<p class="zp-small" style="margin:0 0 8px">Brak przypisanego zapachu.</p>';
        }
        foreach ($mine as $f) {
            $notes = '';
            foreach (['head_notes' => 'Głowa', 'heart_notes' => 'Serce', 'base_notes' => 'Baza'] as $k => $l) {
                if (trim((string) $f[$k]) !== '') {
                    $notes .= '<div><span class="zp-small" style="display:inline-block;width:52px">' . $l . '</span>' . U::noteChips((string) $f[$k], $cats) . '</div>';
                }
            }
            $html .= '<div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:10px">'
                . '<span style="display:inline-flex;align-items:center;justify-content:center;min-width:40px;height:40px;border-radius:10px;background:var(--ink,#1f1f1f);color:#fff;font-family:var(--font-num);font-weight:700;font-size:18px;padding:0 6px">'
                . U::h(trim((string) $f['storage_no']) !== '' ? $f['storage_no'] : '–') . '</span><div><a href="' . U::h(U::url('zapach', ['id' => $f['id']])) . '" style="font-weight:600">' . U::h($f['name']) . '</a>'
                . $notes . '</div></div>';
        }
        $suggest = $mine === [] ? $s->resolve('', self::productAttrs($p), (string) ($p['name'] ?? ''), -1) : [];
        if (!$ctx['canEdit']) {
            return $html;
        }
        $ids = array_column($mine, 'id');
        $all = $s->allFragrances();
        if ($all === []) {
            return $html . '<p class="zp-small"><a href="' . U::h(U::url('edytuj', ['nowy' => 1])) . '">Dodaj zapachy</a>, żeby je przypisywać.</p>';
        }
        // Przypisane na górze, reszta po numerze. Szukanie po numerze, nazwie, innych nazwach i nutach (bez polskich znaków).
        usort($all, static fn(array $a, array $b) => (int) !in_array($a['id'], $ids, true) <=> (int) !in_array($b['id'], $ids, true));
        $rows = '';
        foreach ($all as $f) {
            $on = in_array($f['id'], $ids, true);
            $hay = Store::norm(implode(' ', [$f['storage_no'], $f['name'], $f['aliases'], $f['head_notes'], $f['heart_notes'], $f['base_notes']]));
            $sub = trim((string) $f['aliases']);
            $rows .= '<label class="zp-pick-row" data-s="' . U::h($hay) . '" data-no="' . U::h(mb_strtolower(trim((string) $f['storage_no']))) . '">'
                . '<input type="checkbox" name="fragrances[]" value="' . U::h($f['id']) . '"' . ($on ? ' checked' : '') . '>'
                . '<b>' . U::h(trim((string) $f['storage_no']) !== '' ? '#' . $f['storage_no'] : '#–') . '</b> <span>' . U::h($f['name']) . '</span>'
                . ($sub !== '' ? ' <span class="zp-small">' . U::h($sub) . '</span>' : '') . '</label>';
        }
        $html .= '<style>.zp-pick{max-width:560px}.zp-pick input[type=search]{width:100%;box-sizing:border-box;font-size:16px}'
            . '.zp-pick-list{max-height:260px;overflow-y:auto;border:1px solid var(--line,#e6e3da);border-radius:8px;margin-top:6px;background:var(--surface,#fff)}'
            . '.zp-pick-row{display:flex;gap:8px;align-items:baseline;padding:7px 10px;border-top:1px solid var(--line,#eee);cursor:pointer;font-size:14px}'
            . '.zp-pick-row:first-child{border-top:0}.zp-pick-row:hover{background:var(--surface-2,#f7f6f2)}.zp-pick-row b{font-family:var(--font-num);min-width:44px}'
            . '.zp-pick-row[hidden]{display:none}.zp-pick-row input{align-self:center}.zp-pick-row:has(input:checked){background:#f3ecfb}.zp-pick-empty{padding:10px;color:var(--ink-3,#888);font-size:13px}</style>'
            . '<form method="post" action="' . U::h(U::url('produkt_zapisz')) . '" class="zp-pick" style="margin:0">'
            . U::csrf($ctx) . '<input type="hidden" name="product_id" value="' . $pid . '">'
            . '<input type="search" class="zp-pick-q" placeholder="Szukaj zapachu: nazwa, numer (np. 18) albo nuta" autocomplete="off" aria-label="Szukaj zapachu">'
            . '<div class="zp-pick-list">' . $rows . '<div class="zp-pick-empty" hidden>Nie ma takiego zapachu.</div></div>'
            . '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:8px"><button class="btn" type="submit">Zapisz zapach</button>'
            . '<span class="zp-small zp-pick-count"></span></div></form>'
            . '<p class="zp-small" style="margin:6px 0 0">Zaznacz jeden albo kilka (zestaw). Odznacz wszystkie, żeby usunąć zapach.</p>'
            . <<<'JS'
<script>
(() => {
    const form = document.currentScript.previousElementSibling.previousElementSibling;
    if (!form || !form.classList.contains('zp-pick')) return;
    const q = form.querySelector('.zp-pick-q'), rows = [...form.querySelectorAll('.zp-pick-row')], empty = form.querySelector('.zp-pick-empty'), count = form.querySelector('.zp-pick-count');
    const norm = (t) => t.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/ł/g, 'l').replace(/[^a-z0-9]+/g, ' ').trim();
    const filter = () => {
        const raw = q.value.trim().replace(/^#/, '').toLowerCase();
        const words = norm(raw).split(' ').filter(Boolean);
        const numOnly = /^\d+[a-z]?$/.test(raw);
        let shown = 0;
        rows.forEach((r) => {
            const on = r.querySelector('input').checked;
            const hit = !words.length || (numOnly ? r.dataset.no === raw || r.dataset.no.startsWith(raw) : words.every((w) => r.dataset.s.includes(w)));
            r.hidden = !(hit || on);
            if (!r.hidden) shown++;
        });
        empty.hidden = shown > 0;
    };
    const updCount = () => {
        const n = rows.filter((r) => r.querySelector('input').checked).length;
        count.textContent = n ? 'Zaznaczone: ' + n : '';
    };
    q.addEventListener('input', filter);
    q.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const vis = rows.filter((r) => !r.hidden && !r.querySelector('input').checked);
        if (vis.length === 1) { vis[0].querySelector('input').checked = true; q.value = ''; filter(); updCount(); }
    });
    form.addEventListener('change', updCount);
    updCount();
})();
</script>
JS;
        if ($suggest !== []) {
            $f = $suggest[0];
            $html .= '<form method="post" action="' . U::h(U::url('produkt_zapisz')) . '" style="margin:10px 0 0">' . U::csrf($ctx)
                . '<input type="hidden" name="product_id" value="' . $pid . '"><input type="hidden" name="fragrances[]" value="' . U::h($f['id']) . '">'
                . '<span class="zp-small">Pasuje ' . ($f['how'] === 'variant' ? 'po atrybucie' : 'po nazwie') . ':</span> ' . U::chip($f, false)
                . ' <button class="btn" type="submit">Przypisz</button></form>';
        }
        return $html;
    }

    /** Atrybuty produktu z jedną wartością. @return list<array{0:string,1:string}> */
    public static function productAttrs(array $p): array
    {
        return Store::productAttrs($p);
    }

    // ---------------------------------------------------------------- karta zamówienia

    /** Pozycje zamówienia z numerem zapachu (gdzie stoi olejek). */
    public static function orderSection(Store $s, array $items): string
    {
        $lines = '';
        foreach ($items as $it) {
            $frags = $s->resolve((string) ($it['sku'] ?? ''), self::lineAttrs($it), (string) ($it['name'] ?? ''));
            if ($frags === []) {
                continue;
            }
            $lines .= '<li><span>' . (int) ($it['quantity'] ?? 0) . ' × ' . U::h($it['name'] ?? '') . '</span><span>'
                . implode('', array_map(static fn(array $f) => U::chip($f), $frags)) . '</span></li>';
        }
        if ($lines === '') {
            return '';
        }
        return U::chipStyle() . '<style>.zp-ol{list-style:none;margin:0;padding:0}.zp-ol li{display:flex;gap:8px;justify-content:space-between;align-items:center;flex-wrap:wrap;padding:6px 0;border-top:1px solid var(--line)}.zp-ol li:first-child{border-top:0}</style>'
            . '<ul class="zp-ol">' . $lines . '</ul>';
    }

    /** Wariant pozycji zamówienia (Woo meta_data). @return list<array{0:string,1:string}> */
    public static function lineAttrs(array $it): array
    {
        $out = [];
        foreach ((array) ($it['meta_data'] ?? []) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $key = trim((string) ($m['display_key'] ?? $m['key'] ?? ''));
            $val = $m['display_value'] ?? $m['value'] ?? '';
            if ($key !== '' && !str_starts_with($key, '_') && is_scalar($val) && trim((string) $val) !== '') {
                $out[] = [$key, trim(html_entity_decode(strip_tags((string) $val), ENT_QUOTES, 'UTF-8'))];
            }
        }
        return $out;
    }

    public static function plural(int $n, string $one, string $few, string $many): string
    {
        if ($n === 1) {
            return $one;
        }
        return ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14)) ? $few : $many;
    }
}
