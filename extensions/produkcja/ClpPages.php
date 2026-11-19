<?php
declare(strict_types=1);

namespace PaseExt\Produkcja;

use PaseExt\Produkcja\Ui as U;

/** Wzory etykiet CLP: lista, karta z podglądem i listą kontrolną, formularz. */
final class ClpPages
{
    public static function list(Store $s, array $ctx): string
    {
        $arch = isset($_GET['archiwum']);
        $rows = $s->clps($arch);
        $new = $ctx['canEdit'] ? '<a class="btn" href="' . U::h(U::url('clp', ['nowy' => 1])) . '">+ Nowy wzór</a>' : '';
        $html = U::style() . U::tabs('etykiety') . '<div class="pr-top"><p>Wzory etykiet CLP. Przypisz wzór do wyrobu albo do zapachu, a przy partii wydrukujesz etykietę z jej numerem.</p>'
            . '<div class="pr-actions">' . $new . '</div></div>';
        if ($rows === [] && !$arch) {
            return $html . U::empty('Dodaj pierwszy wzór etykiety CLP', 'Wpisz piktogramy, hasło ostrzegawcze i kody zwrotów H i P z karty charakterystyki olejku (np. H317, H412, P102, P501) - pełny tekst wstawi się sam. '
                . 'Potem wybierz wzór na karcie wyrobu albo zapachu.', $new);
        }
        $html .= '<div class="pr-filter"><a class="btn secondary' . ($arch ? ' on' : '') . '" href="' . U::h(U::url('etykiety', $arch ? [] : ['archiwum' => 1])) . '">Archiwum</a></div>';
        if ($rows === []) {
            return $html . '<div class="card pr-muted">Archiwum jest puste.</div>';
        }
        $html .= '<div class="card pr-table" style="padding:6px 10px"><table><thead><tr><th>Wzór</th><th>Piktogramy</th><th class="pr-hide-m">Hasło</th><th class="pr-hide-m">Rozmiar</th><th class="pr-hide-m">Używają</th></tr></thead><tbody>';
        foreach ($rows as $t) {
            $u = $s->clpUsers((string) $t['id']);
            $pics = '';
            foreach (Clp::pictograms($t) as $c) {
                $pics .= '<img src="' . Clp::pictogramSrc($c) . '" alt="' . U::h($c) . '" title="' . U::h(Clp::PICTOGRAMS[$c]) . '" style="width:26px;height:26px;vertical-align:middle">';
            }
            $html .= '<tr><td><a href="' . U::h(U::url('clp', ['id' => $t['id']])) . '"><b>' . U::h($t['name']) . '</b></a></td>'
                . '<td>' . ($pics !== '' ? $pics : '<span class="pr-small">bez piktogramów</span>') . '</td>'
                . '<td class="pr-hide-m">' . U::h($t['signal_word'] !== '' ? $t['signal_word'] : '-') . '</td>'
                . '<td class="pr-hide-m pr-num">' . (int) $t['width_mm'] . '×' . (int) $t['height_mm'] . ' mm</td>'
                . '<td class="pr-hide-m">' . self::usersText($u) . '</td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    private static function usersText(array $u): string
    {
        $n = count($u['products']) + count($u['materials']);
        if ($n === 0) {
            return '<span class="pr-small">nikt</span>';
        }
        $parts = [];
        if ($u['products'] !== []) {
            $parts[] = count($u['products']) . ' ' . (count($u['products']) === 1 ? 'wyrób' : 'wyroby');
        }
        if ($u['materials'] !== []) {
            $parts[] = count($u['materials']) . ' ' . (count($u['materials']) === 1 ? 'zapach' : 'zapachy');
        }
        return implode(', ', $parts);
    }

    public static function card(Store $s, array $ctx, array $cfg, array $t): string
    {
        $u = $s->clpUsers((string) $t['id']);
        $checks = Clp::checks($t, (string) ($cfg['maker'] ?? ''));
        $sample = Clp::label($t, ['title' => $u['products'][0]['name'] ?? 'Nazwa wyrobu', 'code' => 'NUMER-PARTII', 'produced' => date('Y-m-d'),
            'expires' => date('Y-m-d', strtotime('+12 months')), 'maker' => (string) ($cfg['maker'] ?? '')]);
        $html = U::style() . '<style>' . Clp::css() . '.pr-clp-prev{overflow:auto;padding:12px;background:var(--surface-2);border-radius:8px}</style>'
            . U::tabs('etykiety') . U::back(U::url('etykiety'), 'Etykiety CLP')
            . '<div class="pr-top"><h2 style="margin:0">' . U::h($t['name']) . ((int) $t['archived'] === 1 ? ' <span class="pill muted">w archiwum</span>' : '') . '</h2><div class="pr-actions">'
            . ($ctx['canEdit'] ? '<a class="btn secondary" href="' . U::h(U::url('clp', ['id' => $t['id'], 'edytuj' => 1])) . '">Edytuj</a>'
                . '<a class="btn secondary" href="' . U::h(U::url('clp', ['nowy' => 1, 'z' => $t['id']])) . '" title="Nowy wzór na podstawie tego, np. dla innego zapachu">Kopiuj</a>' : '')
            . '</div></div>';
        $html .= '<div class="card"><h3 style="margin:0 0 8px">Podgląd</h3><p class="pr-small" style="margin:0 0 10px">W prawdziwym rozmiarze ' . (int) $t['width_mm'] . '×' . (int) $t['height_mm']
            . ' mm. Przy druku z partii zamiast „NUMER-PARTII” pojawi się numer partii, jej data produkcji i ważność.</p>'
            . '<div class="pr-clp-prev">' . $sample . '</div><p class="pr-warn" id="pr-clp-over" hidden style="margin:10px 0 0">Tekst nie mieści się na etykiecie (czerwona ramka). Powiększ etykietę albo zmniejsz czcionkę.</p></div>';
        $html .= '<div class="pr-two"><div class="card"><h3 style="margin:0 0 8px">Do sprawdzenia</h3>'
            . ($checks === [] ? '<p class="pr-ok" style="margin:0">Wszystko, co da się sprawdzić automatycznie, jest w porządku.</p>'
                : '<ul style="margin:0;padding-left:18px">' . implode('', array_map(static fn(string $c): string => '<li>' . U::h($c) . '</li>', $checks)) . '</ul>')
            . '<p class="pr-small" style="margin:10px 0 0">Klasyfikację (piktogramy, hasło, zwroty) przepisz z karty charakterystyki (SDS) olejku lub mieszaniny. System nie liczy jej sam.</p></div>';
        $html .= '<div class="card"><h3 style="margin:0 0 8px">Gdzie jest używany</h3>';
        if ($u['products'] === [] && $u['materials'] === []) {
            $html .= '<p class="pr-muted" style="margin:0">Jeszcze nigdzie. Wybierz go w polu „Etykieta CLP” na karcie wyrobu (Edytuj) albo zapachu w Materiałach.</p>';
        } else {
            $html .= '<ul class="pr-list">';
            foreach ($u['products'] as $p) {
                $html .= '<li><a href="' . U::h(U::url('wyrob', ['id' => $p['id']])) . '">' . U::h($p['name']) . '</a><span class="pr-small">wyrób</span></li>';
            }
            foreach ($u['materials'] as $m) {
                $html .= '<li><a href="' . U::h(U::url('material', ['id' => $m['id']])) . '">' . U::h($m['name']) . '</a><span class="pr-small">' . U::h(Store::CATEGORIES[$m['category']] ?? '') . ' (gdy wyrób nie ma własnego wzoru)</span></li>';
            }
            $html .= '</ul>';
        }
        return $html . '</div></div>' . Clp::overflowScript('pr-clp-over');
    }

    public static function form(array $ctx, array $cfg, array $t, ?array $posted = null): string
    {
        $v = static fn(string $k, string $d = ''): string => (string) ($posted[$k] ?? $t[$k] ?? $d);
        $edit = isset($t['id']);
        $pict = $posted !== null ? array_map('strval', (array) ($posted['pictograms'] ?? [])) : Clp::pictograms($t);
        $picks = '';
        foreach (Clp::PICTOGRAMS as $c => $label) {
            $picks .= '<label class="pr-pict"><input type="checkbox" name="pictograms[]" value="' . $c . '"' . (in_array($c, $pict, true) ? ' checked' : '') . '>'
                . '<img src="' . Clp::pictogramSrc($c) . '" alt=""><span><b>' . $c . '</b> ' . U::h($label) . '</span></label>';
        }
        $dict = '';
        foreach (Clp::PHRASES as $code => $text) {
            $dict .= '<tr><td class="pr-code" style="white-space:nowrap">' . U::h($code) . '</td><td>' . U::h($text) . '</td></tr>';
        }
        $ta = static fn(string $name, string $label, string $hint, string $ph, int $rows = 4): string => '<label>' . $label
            . '<textarea name="' . $name . '" rows="' . $rows . '" maxlength="4000" placeholder="' . U::h($ph) . '">' . U::h($v($name)) . '</textarea><small>' . $hint . '</small></label>';
        $back = $edit ? U::url('clp', ['id' => $t['id']]) : U::url('etykiety');
        return U::style() . '<style>.pr-picts{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px}'
            . '.pr-form label.pr-pict{flex-direction:row;align-items:center;gap:8px;border:1px solid var(--line);border-radius:8px;padding:6px 8px;cursor:pointer;font-weight:400;color:var(--ink)}'
            . '.pr-pict img{width:40px;height:40px;flex:none}.pr-pict input{flex:none}.pr-pict:has(input:checked){border-color:var(--accent);background:var(--accent-soft)}</style>'
            . U::tabs('etykiety') . U::back($back, $edit ? (string) $t['name'] : 'Etykiety CLP')
            . '<form class="card pr-form" method="post" action="' . U::h(U::url('clp_zapisz')) . '"><h3 style="margin:0">' . ($edit ? 'Edytuj wzór etykiety CLP' : 'Nowy wzór etykiety CLP') . '</h3>'
            . U::csrf($ctx) . ($edit ? '<input type="hidden" name="id" value="' . U::h($t['id']) . '">' : '')
            . '<div class="pr-grid">'
            . U::input('name', $v('name'), 'Nazwa wzoru', 'required maxlength="200"', 'dla Ciebie, np. „Piernik 10% - słoik 180 ml”')
            . U::input('title', $v('title'), 'Nazwa produktu na etykiecie', 'maxlength="200"', 'puste = nazwa wyrobu z partii')
            . U::select('signal_word', ['' => 'brak', 'Uwaga' => 'Uwaga', 'Niebezpieczeństwo' => 'Niebezpieczeństwo'], $v('signal_word'), 'Hasło ostrzegawcze')
            . '</div>'
            . '<div><b>Piktogramy</b><div class="pr-picts" style="margin-top:6px">' . $picks . '</div></div>'
            . $ta('hazards', 'Zwroty H (zagrożenia)', 'Same kody wystarczą, np. „H317, H412” - tekst wstawi się sam. Własny tekst: cała linia, np. „H317 Może powodować…”.', "H317\nH412")
            . $ta('precautions', 'Zwroty P (środki ostrożności)', 'Np. „P101, P102, P280, P302+P352, P501”.', "P101, P102\nP302+P352\nP501 Zawartość/pojemnik usuwać do punktu zbiórki odpadów.")
            . '<label>Zawiera (substancje z karty charakterystyki)<input name="contains_text" value="' . U::h($v('contains_text')) . '" maxlength="1000" placeholder="np. linalol, kumaryna, citral"><small>Na etykiecie jako „Zawiera: …”.</small></label>'
            . $ta('extra', 'Informacje dodatkowe', 'Np. EUH208 z nazwą substancji, EUH210 albo zasady bezpiecznego palenia świecy.', "EUH208 Zawiera citral. Może wywoływać wystąpienie reakcji alergicznej.\nNie pozostawiać palącej się świecy bez nadzoru.", 3)
            . '<div class="pr-grid">'
            . U::input('ufi', $v('ufi'), 'UFI', 'maxlength="20" style="text-transform:uppercase"', 'gdy mieszanina go ma (zgłoszenie do ośrodka toksykologicznego)')
            . U::input('net_qty', $v('net_qty'), 'Masa / pojemność netto', 'maxlength="40"', 'np. 180 g; puste = „Waga (g)” wyrobu')
            . '</div>'
            . '<label>Dostawca (nazwa, adres, telefon)<textarea name="maker" rows="2" maxlength="1000" placeholder="' . U::h(trim((string) ($cfg['maker'] ?? '')) !== '' ? (string) $cfg['maker'] : 'np. Firma, ul. Polna 1, 00-001 Warszawa, tel. 500 000 000') . '">'
            . U::h($v('maker')) . '</textarea><small>Puste = „Producent na etykiecie partii” z ustawień wtyczki.</small></label>'
            . '<div><b>Rozmiar</b><div class="pr-grid" style="margin-top:6px">'
            . U::input('width_mm', $v('width_mm', '100'), 'Szerokość (mm)', 'type="number" min="20" max="300"')
            . U::input('height_mm', $v('height_mm', '60'), 'Wysokość (mm)', 'type="number" min="15" max="300"')
            . U::input('pict_mm', (int) $v('pict_mm', '0') > 0 ? $v('pict_mm') : '', 'Piktogram (mm)', 'type="number" min="8" max="80" placeholder="sam"', 'puste = najmniejszy dozwolony przez CLP')
            . U::input('font_pt', $v('font_pt') !== '' ? U::n($v('font_pt')) : '7', 'Czcionka (pt)', 'inputmode="decimal"', 'co najmniej ok. 6,6 pt')
            . '</div></div>'
            . '<details><summary class="pr-muted">Kody, które znam (' . count(Clp::PHRASES) . ')</summary><div class="pr-table" style="margin-top:8px"><table><tbody>' . $dict . '</tbody></table></div>'
            . '<p class="pr-small">„…” trzeba uzupełnić: wpisz całą linię z kodem i pełną treścią. Innego kodu też użyjesz, wpisując go z treścią.</p></details>'
            . '<div class="pr-actions"><button class="btn" type="submit">Zapisz</button><a class="btn secondary" href="' . U::h($back) . '">Anuluj</a>'
            . ($edit ? '<label class="pr-check" style="margin-left:auto"><input type="checkbox" name="archived" value="1"' . ((int) $t['archived'] === 1 ? ' checked' : '') . '> W archiwum</label>' : '') . '</div>'
            . '</form>';
    }

    /** Pole wyboru wzoru na formularzu wyrobu i materiału. */
    public static function select(Store $s, string $current, string $label, string $hint): string
    {
        $opts = $s->clpOptions();
        if ($opts === []) {
            return '<label>' . $label . '<span class="pr-small" style="font-weight:400">Najpierw dodaj wzór w zakładce <a href="' . U::h(U::url('clp', ['nowy' => 1])) . '">Etykiety CLP</a>.</span></label>';
        }
        return '<label>' . $label . U::select('clp_id', ['' => '- brak -'] + $opts, $current) . '<small>' . $hint . '</small></label>';
    }
}
