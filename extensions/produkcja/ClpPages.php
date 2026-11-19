<?php
declare(strict_types=1);

namespace PaseExt\Produkcja;

use Pase\Plugin\Hooks;
use Pase\Services\Clp;
use PaseExt\Produkcja\Ui as U;

/**
 * Etykiety CLP w Produkcji: wzory wyglądu (kształt, rozmiar, podtytuł, obrazki, QR, producent), druk z numerem partii
 * z karty partii i z karty produktu. Klasyfikację (piktogramy, zwroty, EUH208) daje zapach produktu - wtyczka Zapachy,
 * filtr `clp.product`; własna klasyfikacja wzoru tylko wtedy, gdy produkt jej nie ma.
 */
final class ClpPages
{
    // ---------------------------------------------------------------- dane do etykiety

    /**
     * Co wydrukować: wzór, klasyfikacja i dane (nazwa, numer partii, GTIN, waga, producent).
     * @return array{layout:?array,class:array,from:string,d:array,crm:?array,fragrance:?array}
     */
    public static function resolve(Store $s, array $cfg, ?array $batch, ?array $crm, string $layoutId = ''): array
    {
        $wyrob = $batch !== null && $batch['product_id'] !== null ? $s->product((string) $batch['product_id']) : null;
        if ($crm === null && $wyrob !== null && $wyrob['sku'] !== '') {
            try {
                $crm = $s->one('SELECT * FROM products WHERE sku = ?', [$wyrob['sku']]);
            } catch (\PDOException) {
                $crm = null;
            }
        }
        if ($wyrob === null && $crm !== null && (string) ($crm['sku'] ?? '') !== '') {
            $wyrob = $s->one('SELECT * FROM ext_prod_products WHERE sku = ? AND archived = 0', [(string) $crm['sku']]);
        }
        $layout = $layoutId !== '' ? $s->clp($layoutId) : null;
        if ($layout === null && $wyrob !== null && $wyrob['clp_id']) {
            $layout = $s->clp((string) $wyrob['clp_id']);
        }
        $layout ??= $s->defaultClp();

        $found = $crm !== null ? Hooks::applyFilters('clp.product', null, (int) $crm['id']) : null;
        $fragrance = is_array($found) ? $found['fragrance'] : null;
        if (is_array($found)) {
            $class = $found['mixture'];
            $from = 'zapach ' . $found['fragrance']['name'] . ' ' . U::n($found['mixture']['percent']) . '%';
        } elseif ($layout !== null && Clp::hasClass($layout)) {
            $class = $layout;
            $from = 'własna klasyfikacja wzoru';
        } else {
            $class = [];
            $from = '';
        }
        $productName = (string) ($crm['name'] ?? $wyrob['name'] ?? $batch['product_name'] ?? '');
        $title = match ($layout['title_mode'] ?? 'scent') {
            'own' => (string) ($layout['title'] ?? ''),
            'product' => $productName,
            default => $fragrance !== null ? (string) $fragrance['name'] : $productName,
        };
        $net = trim((string) ($layout['net_qty'] ?? ''));
        if ($net === '' && ($wyrob['weight_g'] ?? null) !== null) {
            $net = U::n($wyrob['weight_g']) . ' g';
        }
        if ($net === '' && isset($crm['weight']) && (float) $crm['weight'] > 0) {
            $net = U::n(round((float) $crm['weight'] * 1000, 1)) . ' g';
        }
        $maker = trim((string) ($layout['maker'] ?? '')) !== '' ? (string) $layout['maker'] : (string) ($cfg['maker'] ?? '');
        return ['layout' => $layout, 'class' => $class, 'from' => $from, 'crm' => $crm, 'fragrance' => $fragrance, 'd' => [
            'title' => $title, 'code' => (string) ($batch['code'] ?? ''), 'produced' => (string) ($batch['produced_at'] ?? ''),
            'expires' => (string) ($batch['expires_at'] ?? ''), 'net' => $net, 'gtin' => trim((string) ($crm['ean'] ?? '')),
            'sku' => (string) ($crm['sku'] ?? $wyrob['sku'] ?? ''), 'maker' => trim($maker),
        ]];
    }

    /** Arkusz etykiet do druku (z partii albo z produktu). $form: ukryte pola formularza wyboru. */
    public static function sheet(Store $s, array $r, int $n, string $page, array $hidden, string $pickers, string $info): string
    {
        $head = '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Etykiety CLP</title>'
            . '<style>@page{size:A4;margin:6mm}body{font-family:system-ui,sans-serif;margin:0}.bar{padding:10px;background:#f6f5f2;display:flex;flex-wrap:wrap;gap:10px;align-items:center;font-size:14px}'
            . '.bar form{margin:0;display:flex;flex-wrap:wrap;gap:8px;align-items:center}.sheet{display:flex;flex-wrap:wrap;gap:2mm;padding:3mm;align-items:flex-start}'
            . '.warn{background:#fdecec;color:#a11;padding:8px 10px;border-radius:6px;font-size:13px}.info{color:#666;font-size:13px}.clp-skip{visibility:hidden}' . Clp::css()
            . '@media print{.bar,.warn{display:none!important}}</style></head><body>';
        $skip = max(0, min(200, (int) ($_GET['pomin'] ?? 0))); // zużyte miejsca na rozpoczętym arkuszu A4
        $form = '<form method="get" action="ext.php"><input type="hidden" name="page" value="produkcja.' . U::h($page) . '">';
        foreach ($hidden as $k => $v) {
            $form .= '<input type="hidden" name="' . U::h($k) . '" value="' . U::h($v) . '">';
        }
        $form .= $pickers . ' Ile: <input type="number" name="n" min="1" max="500" value="' . $n . '" style="width:70px">'
            . ' Pomiń: <input type="number" name="pomin" min="0" max="200" value="' . $skip . '" style="width:60px" title="Ile etykiet na arkuszu A4 jest już zużytych - druk zacznie się od następnej"> <button>Pokaż</button></form>';
        $bar = '<div class="bar">' . $form . '<button onclick="print()">🖨 Drukuj</button><span class="info">' . U::h($info) . '</span>'
            . '<span class="warn" id="clp-over" hidden>Treść nie mieści się na etykiecie. Powiększ ją albo zmniejsz czcionkę we wzorze.</span></div>';
        if ($r['layout'] === null) {
            return $head . $bar . '<p style="padding:16px">Nie ma jeszcze wzoru etykiety CLP. Dodaj go w Produkcja → Etykiety CLP.</p></body></html>';
        }
        $one = Clp::label($r['layout'], $r['class'], $r['d']);
        return $head . $bar . '<div class="sheet">' . str_repeat(preg_replace('/^<div class="clp/', '<div aria-hidden="true" class="clp-skip clp', $one, 1), $skip) . str_repeat($one, $n) . '</div><script src="assets/qrcodegen.js"></script>' . Clp::script('clp-over') . '</body></html>';
    }

    /** Podpowiedzi SKU: produkty z przypisanym zapachem (gdy jest wtyczka Zapachy), inaczej wszystkie. */
    public static function skuList(Store $s): string
    {
        try {
            $rows = $s->all('SELECT DISTINCT p.sku, p.name FROM ext_zap_links l JOIN products p ON p.id = l.product_id ORDER BY p.name LIMIT 2000');
        } catch (\PDOException) {
            try {
                $rows = $s->all('SELECT sku, name FROM products ORDER BY name LIMIT 2000');
            } catch (\PDOException) {
                $rows = [];
            }
        }
        $html = '<datalist id="clp-skus">';
        foreach ($rows as $r) {
            $html .= '<option value="' . U::h($r['sku']) . '">' . U::h($r['name']) . '</option>';
        }
        return $html . '</datalist>';
    }

    /** Lista wzorów do wyboru. */
    public static function layoutSelect(Store $s, string $current, string $autoLabel): string
    {
        return 'Wzór: ' . U::select('wzor', ['' => $autoLabel] + $s->clpOptions() + ['prosta' => 'prosta etykieta partii (bez CLP)'], $current, '', 'onchange="this.form.submit()"');
    }

    /** Sekcja „Etykieta CLP” na karcie produktu w Magazynie: druk z numerem partii. */
    public static function productSection(Store $s, array $cfg, array $p): string
    {
        $r = self::resolve($s, $cfg, null, $p);
        $recent = $s->recentBatchesBySku([(string) $p['sku']], 8)[mb_strtolower(trim((string) $p['sku']))] ?? [];
        $dl = '';
        foreach ($recent as $b) {
            $dl .= '<option value="' . U::h($b['code']) . '">' . U::h(U::date($b['date']) . ' · ' . $b['qty'] . ' szt.') . '</option>';
        }
        $html = '<p class="pr-small" style="margin:0 0 8px">' . ($r['from'] !== '' ? 'Klasyfikacja: ' . U::h($r['from']) . '.'
            : '<span style="color:var(--warn-ink)">Brak klasyfikacji CLP: przypisz zapach i wybierz stężenie w sekcji Zapach.</span>')
            . ($r['layout'] !== null ? ' Wzór: ' . U::h($r['layout']['name']) . '.' : ' <a href="' . U::h(U::url('clp', ['nowy' => 1])) . '">Dodaj wzór etykiety</a>.') . '</p>';
        return $html . '<form method="get" action="ext.php" target="_blank" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin:0">'
            . '<input type="hidden" name="page" value="produkcja.etykieta_produkt"><input type="hidden" name="produkt" value="' . (int) $p['id'] . '">'
            . '<label style="display:flex;flex-direction:column;gap:4px;font-size:13px">Numer partii<input name="partia" list="clp-batches-' . (int) $p['id'] . '" autocomplete="off" style="width:200px" placeholder="np. AQ001LX01AVBZ"></label>'
            . '<datalist id="clp-batches-' . (int) $p['id'] . '">' . $dl . '</datalist>'
            . '<label style="display:flex;flex-direction:column;gap:4px;font-size:13px">Ile<input type="number" name="n" min="1" max="500" value="1" style="width:70px"></label>'
            . '<button class="btn" type="submit">🖨 Etykieta CLP</button></form>';
    }

    // ---------------------------------------------------------------- wzory: lista, karta, formularz

    public static function list(Store $s, array $ctx): string
    {
        $arch = isset($_GET['archiwum']);
        $rows = $s->clps($arch);
        $new = $ctx['canEdit'] ? '<a class="btn" href="' . U::h(U::url('clp', ['nowy' => 1])) . '">+ Nowy wzór</a>' : '';
        $html = U::style() . U::tabs('etykiety') . '<div class="pr-top"><p>Wygląd etykiet CLP: kształt, rozmiar, podtytuł, piktogramy świecy, QR i producent. '
            . 'Piktogramy GHS i zwroty biorą się z zapachu produktu (Zapachy → olejek → Etykieta CLP), numer partii z partii.</p><div class="pr-actions">' . $new . '</div></div>';
        if ($rows === [] && !$arch) {
            return $html . U::empty('Dodaj pierwszy wzór etykiety', 'Np. „Okrągła 60 mm” albo „Prostokąt 70×70”. Wgraj obrazek z piktogramami bezpieczeństwa świecy i symbol recyklingu, wpisz podtytuł i producenta. '
                . 'Dane CLP olejku wpiszesz w Zapachach, osobno dla każdego stężenia.', $new);
        }
        $html .= '<div class="pr-filter"><a class="btn secondary' . ($arch ? ' on' : '') . '" href="' . U::h(U::url('etykiety', $arch ? [] : ['archiwum' => 1])) . '">Archiwum</a></div>';
        if ($rows === []) {
            return $html . '<div class="card pr-muted">Archiwum jest puste.</div>';
        }
        $html .= '<div class="card pr-table" style="padding:6px 10px"><table><thead><tr><th>Wzór</th><th>Kształt</th><th class="pr-hide-m">Podtytuł</th><th class="pr-hide-m">Wyroby</th></tr></thead><tbody>';
        foreach ($rows as $t) {
            $html .= '<tr><td><a href="' . U::h(U::url('clp', ['id' => $t['id']])) . '"><b>' . U::h($t['name']) . '</b></a>' . ((int) $t['is_default'] === 1 ? ' <span class="pill info">domyślny</span>' : '') . '</td>'
                . '<td class="pr-num">' . ($t['shape'] === 'round' ? 'okrąg ⌀' . (int) $t['width_mm'] . ' mm' : (int) $t['width_mm'] . '×' . (int) $t['height_mm'] . ' mm') . '</td>'
                . '<td class="pr-hide-m">' . U::h($t['subtitle'] !== '' ? $t['subtitle'] : '-') . '</td><td class="pr-hide-m">' . count($s->clpUsers((string) $t['id'])) . '</td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    /** Przykładowe dane do podglądu wzoru: pierwszy produkt z zapachem i mieszaniną CLP albo własna klasyfikacja. */
    private static function sample(Store $s, array $cfg, array $t): array
    {
        $crm = null;
        try {
            $row = $s->one('SELECT p.* FROM ext_zap_links l JOIN products p ON p.id = l.product_id WHERE l.clp_id IS NOT NULL ORDER BY p.name LIMIT 1');
            $crm = $row;
        } catch (\PDOException) {
            $crm = null;
        }
        $r = self::resolve($s, $cfg, null, $crm, (string) $t['id']);
        $r['d']['code'] = 'NUMER-PARTII';
        $r['d']['produced'] = date('Y-m-d');
        $r['d']['expires'] = date('Y-m-d', strtotime('+12 months'));
        if ($r['d']['title'] === '') {
            $r['d']['title'] = 'Nazwa zapachu';
        }
        return $r;
    }

    public static function card(Store $s, array $ctx, array $cfg, array $t): string
    {
        $r = self::sample($s, $cfg, $t);
        $checks = Clp::checks($t, $r['class'], (string) $r['d']['maker']);
        $users = $s->clpUsers((string) $t['id']);
        $html = U::style() . '<style>' . Clp::css() . '.pr-clp-prev{overflow:auto;padding:14px;background:var(--surface-2);border-radius:8px}</style>'
            . U::tabs('etykiety') . U::back(U::url('etykiety'), 'Etykiety CLP')
            . '<div class="pr-top"><h2 style="margin:0">' . U::h($t['name']) . ((int) $t['is_default'] === 1 ? ' <span class="pill info">domyślny</span>' : '')
            . ((int) $t['archived'] === 1 ? ' <span class="pill muted">w archiwum</span>' : '') . '</h2><div class="pr-actions">'
            . ($ctx['canEdit'] ? '<a class="btn secondary" href="' . U::h(U::url('clp', ['id' => $t['id'], 'edytuj' => 1])) . '">Edytuj</a>'
                . '<a class="btn secondary" href="' . U::h(U::url('clp', ['nowy' => 1, 'z' => $t['id']])) . '" title="Nowy wzór na podstawie tego, np. inny rozmiar">Kopiuj</a>' : '')
            . '</div></div>';
        $html .= '<div class="card"><h3 style="margin:0 0 8px">Podgląd</h3><p class="pr-small" style="margin:0 0 10px">W prawdziwym rozmiarze. '
            . ($r['from'] !== '' ? 'Przykład: ' . U::h($r['crm']['name'] ?? '') . ' (' . U::h($r['from']) . ').' : 'Bez klasyfikacji: przypisz produktowi zapach ze stężeniem, żeby zobaczyć pełną etykietę.')
            . ' Przy druku zamiast „NUMER-PARTII” pojawi się numer partii.</p>'
            . '<div class="pr-clp-prev">' . Clp::label($t, $r['class'], $r['d']) . '</div><p class="pr-warn" id="pr-clp-over" hidden style="margin:10px 0 0">Treść nie mieści się na etykiecie (czerwona ramka). Powiększ etykietę albo zmniejsz czcionkę.</p></div>';
        $html .= '<div class="pr-two"><div class="card"><h3 style="margin:0 0 8px">Do sprawdzenia</h3>'
            . ($checks === [] ? '<p class="pr-ok" style="margin:0">Wszystko, co da się sprawdzić automatycznie, jest w porządku.</p>'
                : '<ul style="margin:0;padding-left:18px">' . implode('', array_map(static fn(string $c): string => '<li>' . U::h($c) . '</li>', $checks)) . '</ul>')
            . '</div><div class="card"><h3 style="margin:0 0 8px">Wyroby z tym wzorem</h3>';
        if ($users === []) {
            $html .= '<p class="pr-muted" style="margin:0">' . ((int) $t['is_default'] === 1 ? 'Domyślny: używają go wszystkie produkty bez własnego wzoru.' : 'Żaden. Wzór wybierasz na karcie wyrobu (Edytuj) albo ręcznie przy druku.') . '</p>';
        } else {
            $html .= '<ul class="pr-list">';
            foreach ($users as $p) {
                $html .= '<li><a href="' . U::h(U::url('wyrob', ['id' => $p['id']])) . '">' . U::h($p['name']) . '</a><span class="pr-small">' . U::h($p['sku']) . '</span></li>';
            }
            $html .= '</ul>';
        }
        return $html . '</div></div><script src="assets/qrcodegen.js"></script>' . Clp::script('pr-clp-over');
    }

    public static function form(array $ctx, array $cfg, array $t, ?array $posted = null): string
    {
        $v = static fn(string $k, string $d = ''): string => (string) ($posted[$k] ?? $t[$k] ?? $d);
        $chk = static fn(string $k, bool $d = false): string => ($posted !== null ? !empty($posted[$k]) : (isset($t[$k]) ? (int) $t[$k] === 1 : $d)) ? ' checked' : '';
        $edit = isset($t['id']);
        $back = $edit ? U::url('clp', ['id' => $t['id']]) : U::url('etykiety');
        $img = static function (string $k, string $label, string $hint) use ($t): string {
            $cur = (string) ($t[$k] ?? '');
            return '<label>' . $label . ($cur !== '' ? '<img src="' . U::h($cur) . '" alt="" style="max-height:70px;max-width:100%;align-self:flex-start;border:1px solid var(--line);border-radius:6px;padding:4px;background:#fff">' : '')
                . '<input type="file" name="' . $k . '" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml"><small>' . $hint . '</small></label>'
                . ($cur !== '' ? '<label class="pr-check"><input type="checkbox" name="' . $k . '_remove" value="1"> usuń obrazek</label>' : '');
        };
        $classOpen = $posted !== null ? Clp::hasClass(Clp::classFromInput($posted)) : Clp::hasClass($t);
        return U::style() . U::tabs('etykiety') . U::back($back, $edit ? (string) $t['name'] : 'Etykiety CLP')
            . '<form class="card pr-form" method="post" enctype="multipart/form-data" action="' . U::h(U::url('clp_zapisz')) . '"><h3 style="margin:0">' . ($edit ? 'Edytuj wzór etykiety' : 'Nowy wzór etykiety') . '</h3>'
            . U::csrf($ctx) . ($edit ? '<input type="hidden" name="id" value="' . U::h($t['id']) . '">' : '') . (!$edit && isset($_GET['z']) ? '<input type="hidden" name="copy_from" value="' . U::h($_GET['z']) . '">' : '')
            . '<div class="pr-grid">'
            . U::input('name', $v('name'), 'Nazwa wzoru', 'required maxlength="200"', 'dla Ciebie, np. „Okrągła 60 mm”')
            . U::select('shape', Clp::SHAPES, $v('shape', 'rect'), 'Kształt')
            . U::input('width_mm', $v('width_mm', '100'), 'Szerokość / średnica (mm)', 'type="number" min="20" max="300"')
            . U::input('height_mm', $v('height_mm', '60'), 'Wysokość (mm)', 'type="number" min="15" max="300"', 'przy okręgu nieużywana')
            . '</div><div class="pr-grid">'
            . U::select('title_mode', ['scent' => 'nazwa zapachu (np. HOT WINE)', 'product' => 'nazwa produktu', 'own' => 'własny tekst'], $v('title_mode', 'scent'), 'Tytuł')
            . U::input('title', $v('title'), 'Własny tytuł', 'maxlength="200"', 'gdy wybrano „własny tekst”')
            . U::input('subtitle', $v('subtitle', $edit ? '' : 'Sojowa świeca zapachowa'), 'Podtytuł', 'maxlength="200"')
            . '</div>'
            . U::input('warning', $v('warning', $edit ? '' : 'Przed użyciem zapoznaj się z etykietą. Przestrzegaj środków ostrożności.'), 'Linijka ostrzeżenia', 'maxlength="300"', 'hasło (Uwaga!) z klasyfikacji dopisze się przed nią')
            . '<div class="pr-grid">'
            . $img('safety_img', 'Piktogramy bezpieczeństwa świecy (obrazek)', 'np. 12 ikon w dwóch rzędach; stoi obok piktogramu GHS')
            . U::input('safety_mm', (int) $v('safety_mm', '0') > 0 ? $v('safety_mm') : '', 'Wysokość tego obrazka (mm)', 'type="number" min="5" max="120" placeholder="jak piktogram"')
            . $img('recycle_img', 'Symbol recyklingu (obrazek)', 'np. trójkąt 72 GL')
            . '</div>'
            . '<label>Producent (nazwa, adres, strona, telefon)<textarea name="maker" rows="4" maxlength="1000" placeholder="' . U::h(trim((string) ($cfg['maker'] ?? '')) !== '' ? (string) $cfg['maker'] : 'Producent: Firma, ul. Polna 1, 00-001 Warszawa, tel. 500 000 000') . '">'
            . U::h($v('maker')) . '</textarea><small>Puste = „Producent na etykiecie partii” z ustawień wtyczki.</small></label>'
            . '<div class="pr-grid">'
            . U::input('qr_text', $v('qr_text'), 'Kod QR: adres', 'maxlength="500" placeholder="https://…"', 'puste = bez QR; {sku}, {partia}, {gtin} wstawią dane produktu')
            . U::input('net_qty', $v('net_qty'), 'Masa netto (stała)', 'maxlength="40"', 'puste = waga z wyrobu albo produktu, np. 100 g')
            . U::input('pict_mm', (int) $v('pict_mm', '0') > 0 ? $v('pict_mm') : '', 'Piktogram GHS (mm)', 'type="number" min="5" max="120" placeholder="sam"', 'puste = najmniejszy dozwolony przez CLP')
            . U::input('font_pt', $v('font_pt') !== '' ? U::n($v('font_pt')) : '7', 'Czcionka (pt)', 'inputmode="decimal"', 'co najmniej ok. 6,6 pt')
            . '</div>'
            . '<label class="pr-check"><input type="checkbox" name="show_dates" value="1"' . $chk('show_dates') . '> Data produkcji i „Najlepiej do” przy numerze partii</label>'
            . '<label class="pr-check"><input type="checkbox" name="show_codes" value="1"' . $chk('show_codes') . '> Kody zwrotów (H317, P102…) przed treścią</label>'
            . '<label class="pr-check"><input type="checkbox" name="is_default" value="1"' . $chk('is_default', !$edit) . '> Wzór domyślny (dla produktów bez własnego wzoru)</label>'
            . '<details' . ($classOpen ? ' open' : '') . '><summary class="pr-muted">Własna klasyfikacja CLP (tylko dla produktów bez zapachu z danymi CLP)</summary><div class="pr-form" style="margin-top:10px">'
            . Clp::classFields($posted ?? $t) . '</div></details>'
            . '<div class="pr-actions"><button class="btn" type="submit">Zapisz</button><a class="btn secondary" href="' . U::h($back) . '">Anuluj</a>'
            . ($edit ? '<label class="pr-check" style="margin-left:auto"><input type="checkbox" name="archived" value="1"' . ((int) $t['archived'] === 1 ? ' checked' : '') . '> W archiwum</label>' : '') . '</div>'
            . '</form>';
    }

    /** Pole wyboru wzoru na formularzu wyrobu. */
    public static function select(Store $s, string $current, string $label, string $hint): string
    {
        $opts = $s->clpOptions();
        if ($opts === []) {
            return '<label>' . $label . '<span class="pr-small" style="font-weight:400">Najpierw dodaj wzór w zakładce <a href="' . U::h(U::url('clp', ['nowy' => 1])) . '">Etykiety CLP</a>.</span></label>';
        }
        return '<label>' . $label . U::select('clp_id', ['' => '- domyślny -'] + $opts, $current) . '<small>' . $hint . '</small></label>';
    }
}
