<?php
declare(strict_types=1);

namespace PaseExt\Produkcja;

use PaseExt\Produkcja\Ui as U;

/** Partie: historia, nowa partia z numerem z generatora, karta partii, wyceny robocze, kosz, dekoder, etykiety. */
final class BatchPages
{
    public static function list(Store $s, Production $p, array $ctx): string
    {
        $s->purge();
        $view = (string) ($_GET['widok'] ?? '');
        $f = self::filters();
        $rows = $s->batches($f, 300);
        $q = array_filter(['widok' => $view, 'q' => $f['q'], 'wyrob' => $f['product'], 'od' => $f['from'], 'do' => $f['to']], static fn($v) => $v !== '');
        $html = U::style() . U::tabs('partie') . '<div class="pr-top"><p>Każda partia ma numer do śledzenia: z czego ją zrobiono i komu poszła.</p><div class="pr-actions">'
            . '<a class="btn secondary" href="' . U::h(U::url('partie_eksport', $q)) . '" title="Partie z widoku poniżej (z filtrami) do pliku CSV dla Excela">⬇ Eksport CSV</a>'
            . ($ctx['canEdit'] ? '<a class="btn secondary" href="' . U::h(U::url('import', ['co' => 'partie'])) . '">⬆ Import</a>'
                . '<a class="btn secondary" href="' . U::h(U::url('partia_nowa', ['wycena' => 1])) . '">Wycena robocza</a><a class="btn" href="' . U::h(U::url('partia_nowa')) . '">+ Nowa partia</a>' : '')
            . '</div></div>';

        $prods = ['' => 'Wszystkie wyroby'];
        foreach ($s->products() as $w) {
            $prods[$w['id']] = $w['name'];
        }
        $tab = static fn(string $k, string $label): string => '<a class="btn secondary' . ($view === $k ? ' on' : '') . '" href="' . U::h(U::url('partie', $k === '' ? [] : ['widok' => $k])) . '">' . $label . '</a>';
        $html .= '<form class="pr-filter" method="get" action="ext.php"><input type="hidden" name="page" value="produkcja.partie">'
            . ($view !== '' ? '<input type="hidden" name="widok" value="' . U::h($view) . '">' : '')
            . '<input type="search" name="q" value="' . U::h($f['q']) . '" placeholder="Numer partii, wyrób, materiał, nr partii dostawcy">'
            . U::select('wyrob', $prods, $f['product'], '', 'onchange="this.form.submit()"')
            . '<input type="date" name="od" value="' . U::h($f['from']) . '" title="od"><input type="date" name="do" value="' . U::h($f['to']) . '" title="do">'
            . '<button class="btn secondary" type="submit">Pokaż</button>' . $tab('', 'Partie') . $tab('wyceny', 'Wyceny robocze') . $tab('kosz', 'Kosz') . '</form>';

        $html .= self::decoder($s, $p);
        if ($rows === []) {
            return $html . ($view === '' && $f['q'] === '' && $f['product'] === '' ? U::empty('Jeszcze nie ma partii', 'Partia to jedno lanie świec: wyrób, ilość i data. Zapis zdejmie materiały z magazynu i doda sztuki do produktu w CRM.',
                $ctx['canEdit'] ? '<a class="btn" href="' . U::h(U::url('partia_nowa')) . '">+ Nowa partia</a>' : '')
                : '<div class="card pr-muted">' . ($view === 'kosz' ? 'Kosz jest pusty. Usunięte partie leżą tu 30 dni.' : 'Nic nie pasuje do filtrów.') . '</div>');
        }
        return $html . '<div class="card pr-table" style="padding:6px 10px">' . self::table($rows, $view === 'kosz', $ctx) . '</div>';
    }

    /** Filtry listy partii z adresu (lista i eksport CSV). @return array<string,mixed> */
    public static function filters(): array
    {
        $view = (string) ($_GET['widok'] ?? '');
        $f = ['q' => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100), 'product' => (string) ($_GET['wyrob'] ?? ''),
            'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['od'] ?? '')) ? (string) $_GET['od'] : '',
            'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['do'] ?? '')) ? (string) $_GET['do'] : ''];
        if ($view === 'kosz') {
            $f['trash'] = true;
        } else {
            $f['draft'] = $view === 'wyceny';
        }
        return $f;
    }

    public static function table(array $rows, bool $trash = false, array $ctx = []): string
    {
        if ($rows === []) {
            return '<p class="pr-muted" style="margin:0">Brak partii.</p>';
        }
        $html = '<table><thead><tr><th>Numer</th><th>Wyrób</th><th>Data</th><th class="pr-num">Szt.</th><th class="pr-hide-m">Ważna do</th>'
            . '<th class="pr-num pr-hide-m">Koszt szt.</th>' . ($trash ? '<th></th>' : '') . '</tr></thead><tbody>';
        foreach ($rows as $b) {
            $unit = $b['cost_materials'] !== null && (int) $b['qty'] > 0 ? ((float) $b['cost_materials'] + (float) $b['cost_labor']) / (int) $b['qty'] : null;
            $html .= '<tr><td><a class="pr-code" href="' . U::h(U::url('partia', ['id' => $b['id']])) . '">' . U::h($b['code']) . '</a>'
                . ((int) $b['is_draft'] === 1 ? ' <span class="pill info">wycena</span>' : '') . ((int) $b['imported'] === 1 ? ' <span class="pill muted" title="z Rejestru Partii">import</span>' : '') . '</td>'
                . '<td>' . U::h($b['product_name']) . '</td><td class="pr-num">' . U::date($b['produced_at']) . '</td><td class="pr-num">' . (int) $b['qty'] . '</td>'
                . '<td class="pr-hide-m">' . U::date($b['expires_at']) . '</td><td class="pr-num pr-hide-m">' . ($unit !== null ? U::money($unit) : '-') . '</td>'
                . ($trash && !empty($ctx['canEdit']) ? '<td><form method="post" action="' . U::h(U::url('partia_przywroc')) . '">' . U::csrf($ctx)
                    . '<input type="hidden" name="id" value="' . U::h($b['id']) . '"><button class="btn secondary" type="submit">Przywróć</button></form></td>' : ($trash ? '<td></td>' : ''))
                . '</tr>';
        }
        return $html . '</tbody></table>';
    }

    private static function decoder(Store $s, Production $p): string
    {
        $code = mb_substr(trim((string) ($_GET['dekoduj'] ?? '')), 0, 60);
        $out = '';
        if ($code !== '') {
            $found = $s->batchByCode($code);
            $maps = ['wax' => [], 'scent' => [], 'shift' => []];
            foreach ($s->materials() as $m) {
                if ($m['code'] !== '' && in_array($m['category'], ['wosk', 'zapach'], true)) {
                    $maps[$m['category'] === 'wosk' ? 'wax' : 'scent'][$m['code']] = $m['name'];
                }
            }
            foreach ($s->dict('shift') as $d) {
                $maps['shift'][$d['code']] = $d['name'];
            }
            $out = '<dl class="pr-dl" style="margin-top:10px">';
            foreach (BatchCode::decode($p->pattern(), $code, $maps) as [$k, $v]) {
                $out .= '<dt>' . U::h($k) . '</dt><dd>' . U::h($v) . '</dd>';
            }
            $out .= '</dl>' . ($found !== null ? '<p style="margin:8px 0 0">To partia <a href="' . U::h(U::url('partia', ['id' => $found['id']])) . '"><b>' . U::h($found['product_name']) . '</b></a> z ' . U::date($found['produced_at']) . '.</p>'
                : '<p class="pr-muted" style="margin:8px 0 0">Nie ma takiej partii w bazie - odczyt tylko ze wzoru numeru.</p>');
        }
        return '<details class="card" style="padding:12px 16px"' . ($code !== '' ? ' open' : '') . '><summary><b>Dekoder numeru partii</b> <span class="pr-small">wpisz numer z etykiety, żeby odczytać datę i skład</span></summary>'
            . '<form class="pr-filter" method="get" action="ext.php" style="margin:10px 0 0"><input type="hidden" name="page" value="produkcja.partie">'
            . '<input type="search" name="dekoduj" value="' . U::h($code) . '" placeholder="np. AL001BW02AAB" style="max-width:280px;text-transform:uppercase"><button class="btn secondary" type="submit">Odczytaj</button></form>'
            . $out . '</details>';
    }

    public static function card(Store $s, Production $p, array $ctx, array $b): string
    {
        $draft = (int) $b['is_draft'] === 1;
        $lines = json_decode((string) $b['lines_json'], true) ?: [];
        $stock = json_decode((string) $b['stock_json'], true) ?: [];
        $deleted = $b['deleted_at'] !== null;
        $html = U::style() . U::tabs('partie') . U::back(U::url('partie', $draft ? ['widok' => 'wyceny'] : []), $draft ? 'Wyceny robocze' : 'Partie')
            . '<div class="pr-top"><div><h2 style="margin:0" class="pr-code">' . U::h($b['code']) . '</h2><p>' . U::h($b['product_name']) . ' · ' . (int) $b['qty'] . ' szt. · ' . U::date($b['produced_at'])
            . ($draft ? ' · <span class="pill info">wycena robocza</span>' : '') . ($deleted ? ' · <span class="pill bad">w koszu</span>' : '') . '</p></div><div class="pr-actions">';
        if (!$draft && !$deleted) {
            $html .= '<a class="btn secondary" target="_blank" rel="noopener" href="' . U::h(U::url('etykieta', ['id' => $b['id']])) . '">🖨 Etykiety</a>';
        }
        if ($ctx['canEdit'] && !$deleted) {
            if ($draft) {
                $html .= '<form method="post" action="' . U::h(U::url('partia_zatwierdz')) . '">' . U::csrf($ctx) . '<input type="hidden" name="id" value="' . U::h($b['id']) . '">'
                    . '<button class="btn" type="submit">Zatwierdź jako partię</button></form>';
            }
            if ((int) $b['imported'] === 0) {
                $html .= '<a class="btn secondary" href="' . U::h(U::url('partia_nowa', ['id' => $b['id']])) . '">Edytuj</a>';
            }
            $html .= '<form method="post" action="' . U::h(U::url('partia_usun')) . '" onsubmit="return confirm(\'Przenieść do kosza? Materiały wrócą na stan, a sztuki zejdą z produktu.\')">' . U::csrf($ctx)
                . '<input type="hidden" name="id" value="' . U::h($b['id']) . '"><button class="btn danger" type="submit">Usuń</button></form>';
        } elseif ($ctx['canEdit'] && $deleted) {
            $html .= '<form method="post" action="' . U::h(U::url('partia_przywroc')) . '">' . U::csrf($ctx) . '<input type="hidden" name="id" value="' . U::h($b['id']) . '">'
                . '<button class="btn" type="submit">Przywróć</button></form>';
        }
        $html .= '</div></div>';

        $total = $b['cost_materials'] !== null ? (float) $b['cost_materials'] + (float) $b['cost_labor'] : null;
        $html .= '<div class="pr-two"><div class="card"><dl class="pr-dl">'
            . '<dt>Data produkcji</dt><dd>' . U::date($b['produced_at']) . '</dd><dt>Ważna do</dt><dd>' . U::date($b['expires_at']) . '</dd>'
            . '<dt>Ilość</dt><dd>' . (int) $b['qty'] . ' szt.</dd>'
            . ($b['person'] !== '' ? '<dt>Robił(a)</dt><dd>' . U::h($b['person']) . '</dd>' : '') . ($b['shift'] !== '' ? '<dt>Zmiana / seria</dt><dd>' . U::h($b['shift']) . '</dd>' : '')
            . ($b['work_hours'] !== null ? '<dt>Czas pracy</dt><dd>' . U::n($b['work_hours']) . ' h</dd>' : '')
            . '<dt>Koszt materiałów</dt><dd>' . U::money($b['cost_materials']) . '</dd>' . ($b['cost_labor'] !== null ? '<dt>Robocizna</dt><dd>' . U::money($b['cost_labor']) . '</dd>' : '')
            . ($total !== null && (int) $b['qty'] > 0 ? '<dt><b>Koszt sztuki</b></dt><dd><b>' . U::money($total / (int) $b['qty']) . '</b></dd>' : '')
            . ($b['notes'] !== '' ? '<dt>Uwagi</dt><dd style="white-space:pre-line">' . U::h($b['notes']) . '</dd>' : '')
            . '<dt>Zapisał(a)</dt><dd>' . U::h($b['created_by']) . '</dd></dl>';
        if (!$draft && !$deleted && (int) $b['imported'] === 0) {
            $html .= '<p class="pr-small" style="margin:12px 0 0">' . (!empty($stock['product_id'])
                ? 'Dołożono do produktu ' . U::h($stock['sku'] ?? '') . ': ' . implode(', ', array_filter([(int) ($stock['actual'] ?? 0) ? '+' . (int) $stock['actual'] . ' szt. stanu faktycznego' : '',
                    (int) ($stock['crm'] ?? 0) ? '+' . (int) $stock['crm'] . ' szt. stanu CRM' : ''])) . (($stock['note'] ?? '') !== '' ? ' (' . U::h($stock['note']) . ')' : '')
                : U::h($stock['note'] ?? 'Stan produktu w CRM bez zmian.')) . '</p>';
        }
        $html .= '</div>';

        $html .= '<div class="card pr-table"><h3 style="margin:0 0 8px">' . ($draft ? 'Materiały (wyliczenie)' : 'Zużyte materiały') . '</h3>';
        if ($lines === []) {
            $html .= '<p class="pr-muted" style="margin:0">' . ((int) $b['imported'] === 1 ? 'Partia z Rejestru Partii - skład w uwagach.' : 'Brak (wyrób bez receptury).') . '</p>';
        } else {
            $html .= '<table><thead><tr><th>Materiał</th><th class="pr-num">Ilość</th><th>Z dostawy</th><th class="pr-num">Koszt</th></tr></thead><tbody>';
            foreach ($lines as $l) {
                $lots = array_map(static fn(array $t): string => ($t['lot_no'] !== '' ? U::h($t['lot_no']) : 'bez numeru') . ' <span class="pr-small">(' . U::qty($t['qty'], $l['unit']) . ')</span>', $l['taken'] ?? []);
                $html .= '<tr><td><a href="' . U::h(U::url('material', ['id' => $l['material_id']])) . '">' . U::h($l['name']) . '</a></td><td class="pr-num">' . U::qty($l['need'], $l['unit']) . '</td>'
                    . '<td>' . implode('<br>', $lots) . ((float) ($l['missing'] ?? 0) > 0 ? ' <span class="pill warn">zabrakło ' . U::qty($l['missing'], $l['unit']) . '</span>' : '') . '</td>'
                    . '<td class="pr-num">' . ($l['cost'] !== null ? U::money($l['cost']) : '-') . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        $html .= '</div></div>';

        if (!$draft) {
            $orders = $s->ordersWithBatch((string) $b['code']);
            $html .= '<div class="card pr-table" style="margin-top:18px"><h3 style="margin:0 0 8px">Zamówienia z tą partią</h3>';
            if ($orders === []) {
                $html .= '<p class="pr-muted" style="margin:0">Jeszcze żadne. Numer partii przypisuje się przy pakowaniu.</p>';
            } else {
                $html .= '<table><thead><tr><th>Zamówienie</th><th>Klient</th><th>Pozycja</th><th class="pr-num">Szt.</th></tr></thead><tbody>';
                foreach ($orders as $o) {
                    $html .= '<tr><td><a href="order_view.php?id=' . (int) $o['order_id'] . '">#' . U::h($o['pase_number'] ?? $o['order_number'] ?? $o['order_id']) . '</a></td>'
                        . '<td>' . U::h($o['customer_name'] ?? '') . '</td><td>' . U::h($o['item_name']) . '</td><td class="pr-num">' . (int) $o['qty'] . '</td></tr>';
                }
                $html .= '</tbody></table>';
            }
            $tests = $s->all('SELECT t.*, NULL AS product_name, NULL AS batch_code FROM ext_prod_tests t WHERE t.batch_id = ?', [$b['id']]);
            $html .= '</div>' . ($tests !== [] ? '<div class="card"><h3 style="margin:0 0 8px">Testy spalania</h3>' . TestPages::table($tests) . '</div>' : '');
        }
        return $html;
    }

    public static function form(Store $s, Production $p, array $ctx, ?array $b, ?array $posted = null): string
    {
        $edit = $b !== null;
        $draft = $posted !== null ? !empty($posted['draft']) : ($edit ? (int) $b['is_draft'] === 1 : isset($_GET['wycena']));
        $products = $s->products();
        $v = static function (string $k, string $d = '') use ($posted, $b): string {
            return (string) ($posted[$k] ?? $b[$k] ?? $d);
        };
        $html = U::style() . U::tabs('partie') . U::back($edit ? U::url('partia', ['id' => $b['id']]) : U::url('partie'), $edit ? (string) $b['code'] : 'Partie');
        if ($products === []) {
            return $html . U::empty('Najpierw dodaj wyrób', 'Partia powstaje z wyrobu i jego receptury.', '<a class="btn" href="' . U::h(U::url('wyrob', ['nowy' => 1])) . '">+ Nowy wyrób</a>');
        }
        $pid = $v('product_id', (string) ($_GET['wyrob'] ?? ''));
        $opts = ['' => '- wybierz wyrób -'];
        foreach ($products as $w) {
            $opts[$w['id']] = $w['name'] . ($w['sku'] !== '' ? ' (' . $w['sku'] . ')' : '');
        }
        $people = ['' => '-'];
        $personId = '';
        foreach ($s->dict('person') as $d) {
            $people[$d['id']] = $d['name'];
            if ($b !== null && $d['name'] === $b['person']) {
                $personId = $d['id'];
            }
        }
        $shifts = ['' => '- bez zmiany (kolejna litera) -'];
        $shiftId = '';
        foreach ($s->dict('shift') as $d) {
            $shifts[$d['id']] = $d['name'] . ($d['code'] !== '' ? ' (' . $d['code'] . ')' : '');
            if ($b !== null && $d['name'] === $b['shift']) {
                $shiftId = $d['id'];
            }
        }
        $product = $pid !== '' ? $s->product($pid) : null;
        $qty = (int) $v('qty', '1');
        $pick = (array) ($posted['pick'] ?? []);
        if ($b !== null && $posted === null) {
            foreach (json_decode((string) $b['lines_json'], true) ?: [] as $l) {
                $pick[$l['material_id']] = (string) ($l['taken'][0]['delivery_id'] ?? '');
            }
        }

        $html .= '<form class="card pr-form" method="post" action="' . U::h(U::url('partia_nowa')) . '" id="pr-batch" data-code-url="' . U::h(U::url('partia_kod')) . '">'
            . '<h3 style="margin:0">' . ($edit ? 'Edytuj ' . ($draft ? 'wycenę' : 'partię') : ($draft ? 'Wycena robocza' : 'Nowa partia')) . '</h3>'
            . ($draft ? '<p class="pr-muted" style="margin:0">Wycena robocza liczy koszt, ale nie zdejmuje materiałów i nie dokłada sztuk. Zatwierdzisz ją później jako partię.</p>' : '')
            . U::csrf($ctx) . ($edit ? '<input type="hidden" name="id" value="' . U::h($b['id']) . '">' : '') . ($draft ? '<input type="hidden" name="draft" value="1">' : '')
            . '<div class="pr-grid">'
            . U::select('product_id', $opts, $pid, 'Wyrób', 'required onchange="this.form.querySelector(\'[name=reload]\').value=1;this.form.submit()"')
            . U::input('qty', (string) max(1, $qty), 'Ilość (szt.)', 'type="number" min="1" max="99999" required')
            . U::input('produced_at', $v('produced_at', date('Y-m-d')), 'Data produkcji', 'type="date" required')
            . U::select('person', $people, (string) ($posted['person'] ?? $personId), 'Kto robił')
            . U::select('shift', $shifts, (string) ($posted['shift'] ?? $shiftId), 'Zmiana / seria')
            . U::input('work_hours', $v('work_hours') !== '' ? U::n($v('work_hours')) : '', 'Czas pracy (godz.)', 'inputmode="decimal"')
            . '</div><input type="hidden" name="reload" value="">';

        if (!$draft) {
            $html .= '<div class="pr-codebox"><span class="pr-muted">Numer partii:</span><span class="pr-code" id="pr-code">'
                . U::h($product !== null ? ($edit && !(int) $b['is_draft'] ? $b['code'] : $p->code($product, $v('produced_at', date('Y-m-d')), max(1, $qty), '', $b['id'] ?? null)) : '-') . '</span>'
                . ($edit && (int) $b['is_draft'] === 0 ? '<label class="pr-check"><input type="checkbox" name="keep_code" value="1" checked> zostaw obecny numer</label>' : '')
                . '<span class="pr-small">wzór ' . U::h($p->pattern()) . '</span></div>';
        }

        if ($product !== null) {
            $plan = $p->plan($product, max(1, $qty), $pick);
            if ($plan === []) {
                $html .= '<p class="pr-warn" style="margin:0">Ten wyrób nie ma receptury, więc partia nie zdejmie materiałów. <a href="' . U::h(U::url('wyrob', ['id' => $product['id'], 'edytuj' => 1])) . '">Dodaj recepturę</a></p>';
            } else {
                // Przy edycji to, co partia już zużyła, wraca na stan przed zapisem.
                $back = [];
                if ($b !== null && !$draft) {
                    foreach (json_decode((string) $b['lines_json'], true) ?: [] as $l) {
                        foreach ($l['taken'] ?? [] as $t) {
                            $back[$l['material_id']][$t['delivery_id']] = ($back[$l['material_id']][$t['delivery_id']] ?? 0) + (float) $t['qty'];
                        }
                    }
                }
                $prices = $s->unitPrices();
                $html .= '<div class="pr-table"><b>Materiały na ' . max(1, $qty) . ' szt.</b><table class="pr-wide" style="margin-top:6px"><thead><tr><th>Materiał</th><th class="pr-num">Potrzeba</th><th class="pr-num">Na stanie</th>'
                    . (!$draft ? '<th>Z dostawy</th>' : '') . '<th class="pr-num">Koszt</th></tr></thead><tbody>';
                $sum = 0.0;
                foreach ($plan as $l) {
                    $have = $l['stock'] + array_sum($back[$l['material_id']] ?? []);
                    $short = !$draft && $l['need'] > $have + 0.0005;
                    $c = isset($prices[$l['material_id']]) ? $prices[$l['material_id']] * $l['need'] : null;
                    $sum += (float) $c;
                    $dopts = '<option value="">najstarsza (FIFO)</option>';
                    foreach ($s->deliveries($l['material_id']) as $d) {
                        $left = (float) $d['remaining'] + (float) ($back[$l['material_id']][$d['id']] ?? 0);
                        if ($left <= 0) {
                            continue;
                        }
                        $dopts .= '<option value="' . U::h($d['id']) . '"' . ($l['pick'] === $d['id'] ? ' selected' : '') . '>' . U::date($d['received_at'])
                            . ($d['lot_no'] !== '' ? ' · ' . U::h($d['lot_no']) : '') . ' · ' . U::qty($left, $l['unit']) . ($d['expires_at'] ? ' · do ' . U::date($d['expires_at']) : '') . '</option>';
                    }
                    $html .= '<tr><td>' . U::h($l['name']) . '</td><td class="pr-num">' . U::qty($l['need'], $l['unit']) . '</td>'
                        . '<td class="pr-num">' . ($short ? '<span class="pill bad">' . U::qty($have, $l['unit']) . '</span>' : U::qty($have, $l['unit'])) . '</td>'
                        . (!$draft ? '<td><select name="pick[' . U::h($l['material_id']) . ']" style="width:100%">' . $dopts . '</select></td>' : '')
                        . '<td class="pr-num">' . ($c !== null ? U::money($c) : '-') . '</td></tr>';
                }
                $html .= '<tr><td colspan="' . ($draft ? 3 : 4) . '"><b>Razem materiały</b> <span class="pr-small">(' . U::money($sum / max(1, $qty)) . ' za sztukę)</span></td><td class="pr-num"><b>' . U::money($sum) . '</b></td></tr>'
                    . '</tbody></table></div>';
                if (!$draft) {
                    $html .= '<label class="pr-check"><input type="checkbox" name="allow_short" value="1"' . (!empty($posted['allow_short']) ? ' checked' : '') . '> Zapisz mimo braków (zdejmij tyle, ile jest)</label>';
                }
            }
        }
        return $html . '<label>Uwagi<textarea name="notes" rows="3" maxlength="2000">' . U::h($v('notes')) . '</textarea></label>'
            . '<div class="pr-actions"><button class="btn" type="submit" name="save" value="1">' . ($draft ? 'Zapisz wycenę' : ($edit ? 'Zapisz zmiany' : 'Zapisz partię')) . '</button>'
            . '<button class="btn secondary" type="submit" name="reload" value="1">Przelicz</button>'
            . '<a class="btn secondary" href="' . U::h($edit ? U::url('partia', ['id' => $b['id']]) : U::url('partie')) . '">Anuluj</a></div></form>'
            . '<script>(function(){var f=document.getElementById("pr-batch"),out=document.getElementById("pr-code");if(!f||!out)return;var t;function go(){clearTimeout(t);t=setTimeout(function(){'
            . 'var d=new FormData(f);d.delete("csrf");var q=new URLSearchParams(d);fetch(f.dataset.codeUrl+"&"+q.toString(),{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(r){if(r&&r.code){var k=f.querySelector("[name=keep_code]");if(!k||!k.checked)out.textContent=r.code;}});},250);}'
            . '["qty","produced_at","shift"].forEach(function(n){var e=f.querySelector("[name="+n+"]");if(e){e.addEventListener("input",go);e.addEventListener("change",go);}});'
            . 'var k=f.querySelector("[name=keep_code]");if(k)k.addEventListener("change",go);})();</script>';
    }

    /** Arkusz etykiet partii do druku (nazwa, numer, data produkcji, ważność). */
    public static function labels(array $b, array $product, array $cfg): string
    {
        $w = max(20, min(150, (int) ($cfg['label_w'] ?? 50)));
        $h = max(15, min(150, (int) ($cfg['label_h'] ?? 30)));
        $n = max(1, min(500, (int) ($_GET['n'] ?? $b['qty'])));
        $maker = trim((string) ($cfg['maker'] ?? ''));
        $one = '<div class="l"><b>' . U::h($product['name'] ?? $b['product_name']) . '</b><span>Partia: <i>' . U::h($b['code']) . '</i></span>'
            . '<span>Prod.: ' . U::date($b['produced_at']) . ($b['expires_at'] ? ' · Najlepiej do: ' . U::date($b['expires_at']) : '') . '</span>'
            . ($maker !== '' ? '<small>' . nl2br(U::h($maker)) . '</small>' : '') . '</div>';
        return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>Etykiety ' . U::h($b['code']) . '</title><style>'
            . '@page{margin:8mm}body{font-family:system-ui,sans-serif;margin:0}.bar{padding:10px;background:#f6f5f2;display:flex;gap:10px;align-items:center}'
            . '.sheet{display:flex;flex-wrap:wrap;gap:2mm;padding:4mm}.l{width:' . $w . 'mm;height:' . $h . 'mm;border:0.2mm dashed #bbb;box-sizing:border-box;padding:2mm;display:flex;flex-direction:column;justify-content:center;gap:0.6mm;overflow:hidden;font-size:2.6mm;line-height:1.2}'
            . '.l b{font-size:3.2mm}.l i{font-style:normal;font-family:ui-monospace,monospace;font-weight:700}.l small{font-size:2.1mm;color:#444}@media print{.bar{display:none}.l{border-color:#eee}}</style></head><body>'
            . '<div class="bar"><form method="get" action="ext.php"><input type="hidden" name="page" value="produkcja.etykieta"><input type="hidden" name="id" value="' . U::h($b['id']) . '">'
            . 'Ile etykiet: <input type="number" name="n" min="1" max="500" value="' . $n . '" style="width:80px"> <button>Pokaż</button></form><button onclick="print()">🖨 Drukuj</button>'
            . '<span style="color:#666;font-size:13px">' . $w . '×' . $h . ' mm, rozmiar zmienisz w ustawieniach wtyczki</span></div>'
            . '<div class="sheet">' . str_repeat($one, $n) . '</div></body></html>';
    }
}
