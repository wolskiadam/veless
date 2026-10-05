<?php
declare(strict_types=1);

/**
 * Pola parametrów kategorii Allegro w formularzu „Oferta Allegro" (offer_allegro.php) - wszystkie parametry
 * z GET /sale/categories/{id}/parameters, jak w formularzu „Wystaw przedmiot" na Allegro:
 * słownik -> lista (długa z wyszukiwaniem), wielokrotny wybór -> pola wyboru, wartość spoza słownika
 * (customValuesEnabled), liczby z jednostką (mm, szt., g), kilka wartości (allowedNumberOfValues) -> kolejne pola,
 * zakres -> od/do. Warunki displayedIf / requiredIf i zależne słowniki obsługuje skrypt strony.
 * Nazwy pól: params[{id}], params[{id}][] (lista), params[{id}][from|to] (zakres), params_custom[{id}] (własna wartość).
 */

use Pase\Services\AllegroOfferPayload;

if (!defined('PASE_ROOT')) {
    http_response_code(404);
    exit;
}

/**
 * Wartość startowa parametru, gdy oferta nie ma zapisanej: „Stan" = Nowy, EAN z produktu,
 * reszta z atrybutów produktu po nazwie.
 * @param array<string,mixed> $attributes atrybuty produktu (nazwa => wartość)
 */
function offerParamDefault(array $param, array $attributes, string $ean = ''): mixed
{
    if (AllegroOfferPayload::isEanParameter($param)) {
        return $ean !== '' ? $ean : null;
    }
    $name = mb_strtolower(trim((string) ($param['name'] ?? '')));
    $attr = null;
    foreach ($attributes as $k => $v) {
        if (mb_strtolower(trim((string) $k)) === $name) {
            $attr = is_array($v) ? (string) reset($v) : (string) $v;
        }
    }
    if (($param['type'] ?? '') === 'dictionary') {
        $want = $attr !== null ? mb_strtolower(trim($attr)) : ($name === 'stan' ? 'nowy' : null);
        foreach ((array) ($param['dictionary'] ?? []) as $d) {
            if ($want !== null && mb_strtolower(trim((string) ($d['value'] ?? ''))) === $want) {
                return (string) $d['id'];
            }
        }
        return null;
    }
    return $attr;
}

/** Jedno pole parametru (etykieta + kontrolka). */
function renderOfferParam(array $param, mixed $val, string $custom): string
{
    $e = static fn($v) => htmlspecialchars((string) $v);
    $id = (string) ($param['id'] ?? '');
    $type = (string) ($param['type'] ?? 'string');
    $restr = (array) ($param['restrictions'] ?? []);
    $opts = (array) ($param['options'] ?? []);
    $unit = trim((string) ($param['unit'] ?? ''));
    $required = AllegroOfferPayload::isRequired($param);
    $name = 'params[' . $e($id) . ']';
    $multiDict = $type === 'dictionary' && !empty($restr['multipleChoices']);
    $wide = $multiDict || (!empty($restr['range']));

    $attrs = ' class="prm' . ($wide ? ' prm-wide' : '') . '" data-pid="' . $e($id) . '" data-required="' . ($required ? 1 : 0) . '"';
    foreach (['displayedIf' => 'displayed-if', 'requiredIf' => 'required-if'] as $k => $a) {
        if (!empty($param[$k]['parameters'])) {
            $attrs .= ' data-' . $a . '="' . $e(json_encode($param[$k], JSON_UNESCAPED_UNICODE)) . '"';
        }
    }
    $label = '<label class="ol">' . $e($param['name'] ?? $id) . ' <span class="req"' . ($required ? '' : ' hidden') . '>*</span>'
        . '<span class="opt"' . ($required ? ' hidden' : '') . '> (opcjonalnie)</span></label>';
    $suffix = $unit !== '' ? '<span class="unit">' . $e($unit) . '</span>' : '';
    $wrap = static fn(string $input) => '<div class="unit-wrap">' . $input . $suffix . '</div>';

    if (!empty($restr['range'])) {
        $v = is_array($val) ? $val : [];
        $field = '<div style="display:flex;gap:6px">'
            . $wrap('<input name="' . $name . '[from]" placeholder="od" inputmode="decimal" value="' . $e($v['from'] ?? '') . '">')
            . $wrap('<input name="' . $name . '[to]" placeholder="do" inputmode="decimal" value="' . $e($v['to'] ?? '') . '">') . '</div>';
    } elseif ($type === 'dictionary') {
        $sel = array_map('strval', is_array($val) ? array_filter($val, 'is_scalar') : ($val !== null && $val !== '' ? [$val] : []));
        $dict = (array) ($param['dictionary'] ?? []);
        $parent = (string) ($opts['dependsOnParameterId'] ?? '');
        $dep = static fn(array $d) => !empty($d['dependsOnValueIds']) ? ' data-depends="' . $e(implode(',', array_map('strval', $d['dependsOnValueIds']))) . '"' : '';
        if ($multiDict) {
            $field = '<div class="chk-grid"' . ($parent !== '' ? ' data-depends-on="' . $e($parent) . '"' : '') . '>';
            foreach ($dict as $d) {
                $did = (string) ($d['id'] ?? '');
                $field .= '<label' . $dep($d) . '><input type="checkbox" name="' . $name . '[]" value="' . $e($did) . '"'
                    . (in_array($did, $sel, true) ? ' checked' : '') . '> ' . $e($d['value'] ?? $did) . '</label>';
            }
            $field .= '</div>';
        } else {
            $field = count($dict) > 30 ? '<input class="prm-filter" placeholder="Szukaj na liście…">' : '';
            $field .= '<select name="' . $name . '" data-param-id="' . $e($id) . '"' . ($parent !== '' ? ' data-depends-on="' . $e($parent) . '"' : '')
                . '><option value="">— wybierz —</option>';
            foreach ($dict as $d) {
                $did = (string) ($d['id'] ?? '');
                $field .= '<option value="' . $e($did) . '"' . $dep($d) . (in_array($did, $sel, true) ? ' selected' : '') . '>' . $e($d['value'] ?? $did) . '</option>';
            }
            $field .= '</select>';
        }
        if ($dict === [] && empty($opts['customValuesEnabled'])) {
            $field .= '<p class="hint">Allegro nie podało wartości do wyboru dla tego parametru w tej kategorii.</p>';
        }
        if (!empty($opts['customValuesEnabled'])) {
            $field .= '<input name="params_custom[' . $e($id) . ']" class="prm-custom" placeholder="Inna wartość (spoza listy)" value="' . $e($custom) . '">';
        }
    } else {
        $max = AllegroOfferPayload::allowedValues($param);
        $vals = array_values(array_map('strval', is_array($val) ? array_filter($val, 'is_scalar') : ($val !== null && $val !== '' ? [$val] : [])));
        $a = match ($type) {
            'integer' => ' type="number" step="1"' . (isset($restr['min']) ? ' min="' . $e($restr['min']) . '"' : '') . (isset($restr['max']) ? ' max="' . $e($restr['max']) . '"' : ''),
            'float'   => ' inputmode="decimal"',
            default   => isset($restr['maxLength']) ? ' maxlength="' . (int) $restr['maxLength'] . '"' : '',
        };
        if ($max > 1) {
            $field = '<p class="hint" style="margin:0 0 4px">Pojedyncze wartości dodaj w osobnych polach (maks. ' . $max . ').</p><div class="multi" data-max="' . $max . '">';
            foreach (($vals !== [] ? $vals : ['']) as $v) {
                $field .= $wrap('<input name="' . $name . '[]"' . $a . ' value="' . $e($v) . '">');
            }
            $field .= '</div><button type="button" class="btn secondary add-val">+ Dodaj kolejną wartość</button>';
        } else {
            $field = $wrap('<input name="' . $name . '"' . $a . ' data-param-id="' . $e($id) . '" value="' . $e($vals[0] ?? '') . '">');
        }
        if (AllegroOfferPayload::isTariffParameter($param)) {
            $field .= '<p class="hint">Kod CN, min. 8 cyfr bez spacji (dla świec zwykle 34060000). Potrzebny m.in. przy wysyłce poza UE.</p>';
        }
        if (AllegroOfferPayload::isEanParameter($param)) {
            $field .= '<p class="hint">Po EAN Allegro powiąże ofertę z produktem z Katalogu.</p>';
        }
    }
    return '<div' . $attrs . '>' . $label . $field . '</div>';
}

/**
 * Wszystkie parametry kategorii: najpierw parametry oferty (np. „Stan"), potem parametry produktu
 * (ukrywane, gdy oferta jest powiązana z produktem z Katalogu).
 * @param array<int,array<string,mixed>> $params GET /sale/categories/{id}/parameters
 * @param array<string,mixed>|null $values zapisane wartości (params z formularza) albo null = prefill
 * @param array<string,mixed> $attributes atrybuty produktu
 * @param array<string,string> $customs własne wartości słowników (params_custom)
 */
function renderOfferParams(array $params, ?array $values, array $attributes, string $ean = '', array $customs = []): string
{
    $offer = $product = '';
    foreach ($params as $param) {
        $id = (string) ($param['id'] ?? '');
        $val = $values !== null ? ($values[$id] ?? null) : offerParamDefault($param, $attributes, $ean);
        $html = renderOfferParam($param, $val, (string) ($customs[$id] ?? ''));
        if (AllegroOfferPayload::describesProduct($param)) {
            $product .= $html;
        } else {
            $offer .= $html;
        }
    }
    $out = '';
    if ($offer !== '') {
        $out .= '<h4 class="psub">Parametry oferty</h4><div class="pgrid">' . $offer . '</div>';
    }
    if ($product !== '') {
        $out .= '<div id="productParams"><h4 class="psub">Parametry produktu</h4>'
            . '<p class="hint" style="margin:0 0 8px">Wypełnij, żeby utworzyć produkt w Katalogu Allegro, albo wybierz gotowy produkt z Katalogu powyżej.</p>'
            . '<div class="pgrid">' . $product . '</div></div>';
    }
    return $out !== '' ? $out : '<p class="hint">Ta kategoria nie ma parametrów.</p>';
}

/**
 * Sekcja VAT jak w formularzu Allegro: przedmiot oferty, podstawa wyłączenia z VAT i stawki dla krajów
 * (opcje z GET /sale/tax-settings dla kategorii; bez kategorii - puste listy).
 * @param array{subjects:array<string,string>,exemptions:array<string,string>,rates:array<string,array<string,string>>} $tax
 * @param array<string,mixed> $form
 */
function renderTaxFields(array $tax, array $form): string
{
    $e = static fn($v) => htmlspecialchars((string) $v);
    $select = static function (string $name, array $options, string $sel, string $empty) use ($e): string {
        if ($sel !== '' && !isset($options[$sel])) {
            $options[$sel] = $sel;                     // zapisana wartość spoza obecnej listy
        }
        $h = '<select name="' . $name . '" style="width:100%"><option value="">' . $empty . '</option>';
        foreach ($options as $v => $l) {
            $h .= '<option value="' . $e($v) . '"' . ((string) $v === $sel ? ' selected' : '') . '>' . $e($l) . '</option>';
        }
        return $h . '</select>';
    };
    $vat = (array) ($form['vat'] ?? []);
    if ($vat === [] && ($form['vat_rate'] ?? '') !== '') {
        $vat = ['PL' => (string) $form['vat_rate']];
    }
    $h = '<div class="og">'
        . '<div><label class="ol">Przedmiot oferty <span class="opt">(opcjonalnie)</span></label>'
        . $select('tax_subject', $tax['subjects'], (string) ($form['tax_subject'] ?? ''), '— wybierz —') . '</div>'
        . '<div><label class="ol">Podstawa wyłączenia z VAT <span class="opt">(opcjonalnie)</span></label>'
        . $select('tax_exemption', $tax['exemptions'], (string) ($form['tax_exemption'] ?? ''), '— brak —') . '</div>'
        . '</div><p class="hint" style="margin-top:10px">Zadeklaruj stawkę VAT dla wybranych krajów (potrzebne do sprzedaży na Allegro Business).</p><div class="og" style="margin-top:6px">';
    foreach (AllegroOfferPayload::VAT_COUNTRIES as $cc => $country) {
        $h .= '<div><label class="ol">Stawka VAT ' . $e($country) . ' <span class="opt">(opcjonalnie)</span></label>'
            . $select('vat[' . $cc . ']', $tax['rates'][$cc] ?? [], (string) ($vat[$cc] ?? ''), '— wybierz —') . '</div>';
    }
    return $h . '</div>';
}
