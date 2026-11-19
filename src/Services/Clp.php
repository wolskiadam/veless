<?php
declare(strict_types=1);

namespace Pase\Services;

/**
 * Etykiety CLP (wspólne dla wtyczek Zapachy i Produkcja).
 *
 * Dwie części: klasyfikacja (piktogramy GHS, hasło ostrzegawcze, zwroty H i P, „Zawiera”, UFI) - w Zapachach przy olejku,
 * osobno dla każdego stężenia mieszaniny - oraz wygląd etykiety (kształt, rozmiar, podtytuł, obrazki, QR, producent) - w Produkcji.
 * Przy druku partii wstawia się jej numer, data produkcji i ważność, a z produktu GTIN i masa netto.
 *
 * Zwroty wpisuje się kodami (H317, P102, P302+P352) - tekst po polsku wstawia się sam ze słownika poniżej; linia z kodem
 * i własnym tekstem albo zwykły tekst zostaje bez zmian. Piktogramy GHS01-GHS09 (domena publiczna, UNECE) leżą w clp/
 * obok tej klasy i są wstawiane w stronę jako obrazki, więc drukują się też bez internetu.
 */
final class Clp
{
    public const PICTOGRAMS = [
        'GHS01' => 'Bomba (materiały wybuchowe)', 'GHS02' => 'Płomień (łatwopalne)', 'GHS03' => 'Płomień nad okręgiem (utleniające)',
        'GHS04' => 'Butla z gazem', 'GHS05' => 'Korozja (żrące)', 'GHS06' => 'Czaszka (toksyczne)',
        'GHS07' => 'Wykrzyknik (szkodliwe, drażniące, uczulające)', 'GHS08' => 'Zagrożenie dla zdrowia', 'GHS09' => 'Środowisko (szkodliwe dla organizmów wodnych)',
    ];
    public const SIGNALS = ['', 'Uwaga', 'Niebezpieczeństwo'];

    /** Najczęstsze zwroty przy olejkach zapachowych, świecach i dyfuzorach (brzmienie z polskiej wersji CLP). „…” = do uzupełnienia. */
    public const PHRASES = [
        'H225' => 'Wysoce łatwopalna ciecz i pary.',
        'H226' => 'Łatwopalna ciecz i pary.',
        'H227' => 'Ciecz palna.',
        'H301' => 'Działa toksycznie po połknięciu.',
        'H302' => 'Działa szkodliwie po połknięciu.',
        'H304' => 'Połknięcie i dostanie się przez drogi oddechowe może grozić śmiercią.',
        'H312' => 'Działa szkodliwie w kontakcie ze skórą.',
        'H314' => 'Powoduje poważne oparzenia skóry oraz uszkodzenia oczu.',
        'H315' => 'Działa drażniąco na skórę.',
        'H317' => 'Może powodować reakcję alergiczną skóry.',
        'H318' => 'Powoduje poważne uszkodzenie oczu.',
        'H319' => 'Działa drażniąco na oczy.',
        'H331' => 'Działa toksycznie w następstwie wdychania.',
        'H332' => 'Działa szkodliwie w następstwie wdychania.',
        'H334' => 'Może powodować objawy alergii lub astmy lub trudności w oddychaniu w następstwie wdychania.',
        'H335' => 'Może powodować podrażnienie dróg oddechowych.',
        'H336' => 'Może wywoływać uczucie senności lub zawroty głowy.',
        'H341' => 'Podejrzewa się, że powoduje wady genetyczne.',
        'H351' => 'Podejrzewa się, że powoduje raka.',
        'H361' => 'Podejrzewa się, że działa szkodliwie na płodność lub na dziecko w łonie matki.',
        'H361d' => 'Podejrzewa się, że działa szkodliwie na dziecko w łonie matki.',
        'H361f' => 'Podejrzewa się, że działa szkodliwie na płodność.',
        'H373' => 'Może powodować uszkodzenie narządów poprzez długotrwałe lub powtarzane narażenie.',
        'H400' => 'Działa bardzo toksycznie na organizmy wodne.',
        'H410' => 'Działa bardzo toksycznie na organizmy wodne, powodując długotrwałe skutki.',
        'H411' => 'Działa toksycznie na organizmy wodne, powodując długotrwałe skutki.',
        'H412' => 'Działa szkodliwie na organizmy wodne, powodując długotrwałe skutki.',
        'H413' => 'Może powodować długotrwałe szkodliwe skutki dla organizmów wodnych.',
        'EUH066' => 'Powtarzające się narażenie może powodować wysuszanie lub pękanie skóry.',
        'EUH208' => 'Zawiera …. Może wywoływać wystąpienie reakcji alergicznej.',
        'EUH210' => 'Karta charakterystyki dostępna na żądanie.',
        'P101' => 'W razie konieczności zasięgnięcia porady lekarza należy pokazać pojemnik lub etykietę.',
        'P102' => 'Chronić przed dziećmi.',
        'P103' => 'Przeczytać uważnie i stosować się do wszystkich instrukcji.',
        'P210' => 'Przechowywać z dala od źródeł ciepła, gorących powierzchni, źródeł iskrzenia, otwartego ognia i innych źródeł zapłonu. Palenie wzbronione.',
        'P233' => 'Przechowywać pojemnik szczelnie zamknięty.',
        'P261' => 'Unikać wdychania pyłu/dymu/gazu/mgły/par/rozpylonej cieczy.',
        'P262' => 'Nie dopuścić do kontaktu z oczami, skórą lub odzieżą.',
        'P264' => 'Dokładnie umyć ręce po użyciu.',
        'P270' => 'Nie jeść, nie pić i nie palić podczas używania produktu.',
        'P271' => 'Stosować wyłącznie na zewnątrz lub w dobrze wentylowanym pomieszczeniu.',
        'P272' => 'Zanieczyszczonej odzieży ochronnej nie wynosić poza miejsce pracy.',
        'P273' => 'Unikać uwolnienia do środowiska.',
        'P280' => 'Stosować rękawice ochronne/odzież ochronną/ochronę oczu/ochronę twarzy.',
        'P301+P310' => 'W PRZYPADKU POŁKNIĘCIA: Natychmiast skontaktować się z OŚRODKIEM ZATRUĆ/lekarzem.',
        'P301+P312' => 'W PRZYPADKU POŁKNIĘCIA: W przypadku złego samopoczucia skontaktować się z OŚRODKIEM ZATRUĆ/lekarzem.',
        'P302+P352' => 'W PRZYPADKU KONTAKTU ZE SKÓRĄ: Umyć dużą ilością wody.',
        'P303+P361+P353' => 'W PRZYPADKU KONTAKTU ZE SKÓRĄ (lub z włosami): Natychmiast zdjąć całą zanieczyszczoną odzież. Spłukać skórę pod strumieniem wody lub prysznicem.',
        'P304+P340' => 'W PRZYPADKU DOSTANIA SIĘ DO DRÓG ODDECHOWYCH: wyprowadzić lub wynieść poszkodowanego na świeże powietrze i zapewnić mu warunki do swobodnego oddychania.',
        'P305+P351+P338' => 'W PRZYPADKU DOSTANIA SIĘ DO OCZU: Ostrożnie płukać wodą przez kilka minut. Wyjąć soczewki kontaktowe, jeżeli są i można je łatwo usunąć. Nadal płukać.',
        'P312' => 'W przypadku złego samopoczucia skontaktować się z OŚRODKIEM ZATRUĆ/lekarzem.',
        'P331' => 'NIE wywoływać wymiotów.',
        'P332+P313' => 'W przypadku wystąpienia podrażnienia skóry: Zasięgnąć porady/zgłosić się pod opiekę lekarza.',
        'P333+P313' => 'W przypadku wystąpienia podrażnienia skóry lub wysypki: Zasięgnąć porady/zgłosić się pod opiekę lekarza.',
        'P337+P313' => 'W przypadku utrzymywania się działania drażniącego na oczy: Zasięgnąć porady/zgłosić się pod opiekę lekarza.',
        'P362+P364' => 'Zdjąć zanieczyszczoną odzież i wyprać ją przed ponownym użyciem.',
        'P370+P378' => 'W przypadku pożaru: Użyć … do gaszenia.',
        'P391' => 'Zebrać wyciek.',
        'P403+P233' => 'Przechowywać w dobrze wentylowanym miejscu. Przechowywać pojemnik szczelnie zamknięty.',
        'P403+P235' => 'Przechowywać w dobrze wentylowanym miejscu. Przechowywać w chłodnym miejscu.',
        'P405' => 'Przechowywać pod zamknięciem.',
        'P501' => 'Zawartość/pojemnik usuwać do ….',
    ];

    /** Kod zwrotu na początku linii: H317, EUH208, P302+P352, P303 + P361 + P353. */
    private const CODE = '(?:EUH\d{3}|H\d{3}[df]?|P\d{3}(?:\s*\+\s*P\d{3})*)';
    public const SHAPES = ['rect' => 'prostokąt', 'round' => 'okrąg'];

    public static function h(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    private static function n(float $v, string $dec = ','): string
    {
        return rtrim(rtrim(number_format($v, 2, $dec, ''), '0'), $dec);
    }

    private static function date(string $ymd): string
    {
        $t = $ymd !== '' ? strtotime($ymd) : false;
        return $t ? date('d.m.Y', $t) : $ymd;
    }

    /**
     * Zwroty z pola. Linia z samymi kodami („H317, H412”) rozpada się na zwroty ze słownika; linia „kod + tekst”
     * i zwykły tekst zostają tak, jak je wpisano. „EUH208 citral, linalol” daje pełne zdanie EUH208 z tymi substancjami.
     * @return list<array{code:string,text:string,known:bool,todo:bool}>
     */
    public static function phrases(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(?:' . self::CODE . '[\s,;]*)+$/iu', $line)) {
                preg_match_all('/' . self::CODE . '/iu', $line, $m);
                foreach ($m[0] as $code) {
                    $code = self::normCode($code);
                    $t = self::PHRASES[$code] ?? '';
                    $out[] = ['code' => $code, 'text' => $t, 'known' => $t !== '', 'todo' => str_contains($t, '…')];
                }
                continue;
            }
            if (preg_match('/^(' . self::CODE . ')\s*[:.\-–]?\s+(.+)$/iu', $line, $m)) {
                $code = self::normCode($m[1]);
                $t = trim($m[2]);
                if ($code === 'EUH208' && !preg_match('/zawiera/iu', $t)) {
                    $t = 'Zawiera ' . rtrim($t, '. ') . '. Może wywoływać wystąpienie reakcji alergicznej.';
                }
                $out[] = ['code' => $code, 'text' => $t, 'known' => true, 'todo' => str_contains($t, '…')];
                continue;
            }
            $out[] = ['code' => '', 'text' => $line, 'known' => true, 'todo' => str_contains($line, '…')];
        }
        return $out;
    }

    private static function normCode(string $code): string
    {
        $c = strtoupper((string) preg_replace('/\s+/', '', $code));
        return preg_match('/^H\d{3}[DF]$/', $c) ? substr($c, 0, 4) . strtolower(substr($c, 4)) : $c;
    }

    /** Pole etykiety w mm² (okrąg: π r²). */
    public static function area(array $layout): float
    {
        $w = (int) $layout['width_mm'];
        $h = ($layout['shape'] ?? 'rect') === 'round' ? $w : (int) $layout['height_mm'];
        return ($layout['shape'] ?? 'rect') === 'round' ? M_PI * ($w / 2) ** 2 : (float) ($w * $h);
    }

    /** Bok piktogramu w mm: wpisany albo najmniejszy dozwolony (romb p²/2 ≥ 1/15 etykiety i ≥ 1 cm²). */
    public static function pictSize(array $layout): int
    {
        return (int) ($layout['pict_mm'] ?? 0) > 0 ? (int) $layout['pict_mm'] : (int) ceil(sqrt(max(100.0, self::area($layout) / 15) * 2));
    }

    /** @return list<string> kody piktogramów */
    public static function pictograms(array $c): array
    {
        return array_values(array_filter(explode(',', (string) ($c['pictograms'] ?? '')), static fn(string $x): bool => isset(self::PICTOGRAMS[$x])));
    }

    /** Piktogram jako data: URI (obrazek w stronie, drukuje się bez sieci). */
    public static function pictogramSrc(string $code): string
    {
        static $cache = [];
        if (!isset(self::PICTOGRAMS[$code])) {
            return '';
        }
        return $cache[$code] ??= 'data:image/svg+xml;base64,' . base64_encode((string) @file_get_contents(__DIR__ . '/clp/' . $code . '.svg'));
    }

    /** Pola klasyfikacji z formularza (wspólne dla mieszaniny w Zapachach i własnej klasyfikacji wzoru). @return array<string,string> */
    public static function classFromInput(array $in): array
    {
        $pict = array_values(array_intersect(array_keys(self::PICTOGRAMS), array_map('strval', (array) ($in['pictograms'] ?? []))));
        $txt = static fn(string $k, int $max = 4000): string => mb_substr(trim(str_replace("\r", '', (string) ($in[$k] ?? ''))), 0, $max);
        return [
            'signal_word' => in_array($sig = (string) ($in['signal_word'] ?? ''), self::SIGNALS, true) ? $sig : '',
            'pictograms' => implode(',', $pict),
            'hazards' => $txt('hazards'),
            'precautions' => $txt('precautions'),
            'contains_text' => $txt('contains_text', 1000),
            'extra' => $txt('extra'),
            'ufi' => strtoupper(mb_substr((string) preg_replace('/[^A-Za-z0-9-]/', '', (string) ($in['ufi'] ?? '')), 0, 20)),
        ];
    }

    public static function hasClass(array $c): bool
    {
        foreach (['pictograms', 'signal_word', 'hazards', 'precautions', 'contains_text', 'extra'] as $k) {
            if (trim((string) ($c[$k] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    /** Pola klasyfikacji w formularzu (piktogramy z obrazkami, hasło, H, P, Zawiera, dodatkowe, UFI). */
    public static function classFields(array $c, string $ufiHint = 'gdy mieszanina go ma'): string
    {
        $v = static fn(string $k): string => (string) ($c[$k] ?? '');
        $pict = is_array($c['pictograms'] ?? null) ? array_map('strval', $c['pictograms']) : self::pictograms($c);
        $picks = '';
        foreach (self::PICTOGRAMS as $code => $label) {
            $picks .= '<label class="clp-pick"><input type="checkbox" name="pictograms[]" value="' . $code . '"' . (in_array($code, $pict, true) ? ' checked' : '') . '>'
                . '<img src="' . self::pictogramSrc($code) . '" alt=""><span><b>' . $code . '</b> ' . self::h($label) . '</span></label>';
        }
        $dict = '';
        foreach (self::PHRASES as $code => $text) {
            $dict .= '<tr><td style="white-space:nowrap;font-weight:600">' . self::h($code) . '</td><td>' . self::h($text) . '</td></tr>';
        }
        $sig = '';
        foreach (['' => 'brak', 'Uwaga' => 'Uwaga', 'Niebezpieczeństwo' => 'Niebezpieczeństwo'] as $k => $l) {
            $sig .= '<option value="' . self::h($k) . '"' . ($v('signal_word') === $k ? ' selected' : '') . '>' . $l . '</option>';
        }
        $ta = static fn(string $name, string $label, string $hint, string $ph, int $rows = 3): string => '<label>' . $label
            . '<textarea name="' . $name . '" rows="' . $rows . '" maxlength="4000" placeholder="' . self::h($ph) . '">' . self::h($v($name)) . '</textarea><small>' . $hint . '</small></label>';
        return '<style>.clp-picks{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px}'
            . 'label.clp-pick{display:flex!important;flex-direction:row!important;align-items:center;gap:8px;border:1px solid var(--line);border-radius:8px;padding:6px 8px;cursor:pointer;font-weight:400!important;color:var(--ink)!important;font-size:13px}'
            . '.clp-pick img{width:40px;height:40px;flex:none}.clp-pick input{flex:none}.clp-pick:has(input:checked){border-color:var(--accent);background:var(--accent-soft)}</style>'
            . '<label>Hasło ostrzegawcze<select name="signal_word">' . $sig . '</select></label>'
            . '<div><b>Piktogramy</b><div class="clp-picks" style="margin-top:6px">' . $picks . '</div></div>'
            . $ta('hazards', 'Zwroty H (zagrożenia)', 'Same kody wystarczą, np. „H317, H412” - tekst wstawi się sam. Własny tekst: cała linia z kodem i treścią.', "H317\nH412")
            . $ta('precautions', 'Zwroty P (środki ostrożności)', 'Np. „P101, P102, P273, P302+P352” i w osobnej linii P501 z własnym tekstem.', "P101, P102, P273\nP501 Zawartość/pojemnik usuwać do zbiorników do segregacji odpadów obecnych w swojej gminie.")
            . $ta('contains_text', 'EUH208 / Zawiera (substancje z karty charakterystyki)', 'Wpisz same nazwy, np. „citral, linalol” - na etykiecie: „EUH208: Zawiera citral, linalol. Może wywoływać wystąpienie reakcji alergicznej.”. Gdy mieszanina ma H317, zaznacz poniżej „Zawiera:”.', 'np. Eugenol, Kumaryna, Aldehyd cynamonowy', 2)
            . '<label class="clp-check" style="flex-direction:row;align-items:center;gap:8px;font-weight:500"><input type="checkbox" name="contains_plain" value="1"' . (str_starts_with($v('contains_text'), 'Zawiera:') ? ' checked' : '') . '> Pisz „Zawiera: …” zamiast zdania EUH208 (mieszanina klasyfikowana jako uczulająca, H317)</label>'
            . $ta('extra', 'Informacje dodatkowe', 'Np. EUH210 albo inne zdania.', 'EUH210', 2)
            . '<label>UFI<input name="ufi" value="' . self::h($v('ufi')) . '" maxlength="20" style="text-transform:uppercase"><small>' . self::h($ufiHint) . '</small></label>'
            . '<details><summary style="color:var(--ink-2)">Kody, które znam (' . count(self::PHRASES) . ')</summary><div style="overflow-x:auto;margin-top:8px"><table><tbody>' . $dict . '</tbody></table></div>'
            . '<p style="font-size:12.5px;color:var(--ink-3)">„…” trzeba uzupełnić: wpisz całą linię z kodem i pełną treścią. Innego kodu też użyjesz, wpisując go z treścią.</p></details>';
    }

    /** Zdanie EUH208 / „Zawiera:” z pola substancji. */
    public static function containsSentence(string $contains): string
    {
        $c = trim($contains);
        if ($c === '') {
            return '';
        }
        if (preg_match('/^zawiera:\s*(.+)$/iu', $c, $m)) {
            return 'Zawiera: ' . rtrim(trim($m[1]), '.') . '.';
        }
        if (preg_match('/^EUH208/iu', $c) || preg_match('/^zawiera\b/iu', $c)) {
            return $c;
        }
        return 'EUH208: Zawiera ' . rtrim($c, '. ') . '. Może wywoływać wystąpienie reakcji alergicznej.';
    }

    /** Pole substancji z formularza (z kratką „Zawiera:”). */
    public static function containsFromInput(array $in): string
    {
        $c = trim((string) preg_replace('/^(zawiera:?|EUH208:?\s*zawiera)\s*/iu', '', trim((string) ($in['contains_text'] ?? ''))));
        return $c === '' ? '' : mb_substr((!empty($in['contains_plain']) ? 'Zawiera: ' : '') . $c, 0, 1000);
    }

    /**
     * Co sprawdzić przed drukiem (wymagania CLP, które da się policzyć).
     * @return list<string>
     */
    public static function checks(array $layout, array $class, string $maker): array
    {
        $out = [];
        $pict = self::pictograms($class);
        $h = self::phrases((string) ($class['hazards'] ?? ''));
        $all = array_merge($h, self::phrases((string) ($class['precautions'] ?? '')), self::phrases((string) ($class['extra'] ?? '')));
        foreach ($all as $p) {
            if (!$p['known']) {
                $out[] = 'Nie znam kodu ' . $p['code'] . ' - wpisz go razem z treścią, np. „' . $p['code'] . ' treść zwrotu”.';
            } elseif ($p['todo']) {
                $out[] = 'Uzupełnij „…” w ' . ($p['code'] !== '' ? $p['code'] : 'tekście') . ' - wpisz całą linię z kodem i pełną treścią.';
            }
        }
        if ($pict !== [] && ($class['signal_word'] ?? '') === '') {
            $out[] = 'Są piktogramy, a nie ma hasła ostrzegawczego (Uwaga albo Niebezpieczeństwo).';
        }
        if ($pict !== [] && array_filter($h, static fn(array $p): bool => str_starts_with($p['code'], 'H')) === []) {
            $out[] = 'Są piktogramy, a nie ma zwrotów H.';
        }
        if ($layout === []) {
            return $out;
        }
        if (trim($maker) === '') {
            $out[] = 'Brak danych dostawcy (nazwa, adres, telefon). Wpisz je we wzorze albo w ustawieniach wtyczki Produkcja („Producent na etykiecie partii”).';
        }
        $p = self::pictSize($layout);
        $need = max(100.0, self::area($layout) / 15);
        if ($p * $p / 2 < $need) {
            $out[] = 'Piktogram ' . $p . ' mm jest za mały: romb powinien mieć co najmniej ' . self::n(round($need / 100, 2)) . ' cm² (1/15 etykiety, min. 1 cm²), czyli piktogram ok. '
                . (int) ceil(sqrt($need * 2)) . ' mm.';
        }
        $x = (float) $layout['font_pt'] * 0.3528 * 0.52;
        if ($x < 1.2) {
            $out[] = 'Czcionka ' . self::n((float) $layout['font_pt']) . ' pt daje małe litery wysokości ok. ' . self::n(round($x, 2)) . ' mm. Zmienione przepisy CLP wymagają co najmniej 1,2 mm (od ok. 6,6 pt).';
        }
        return $out;
    }

    /**
     * Jedna etykieta. $layout: wygląd (Produkcja), $class: klasyfikacja (Zapachy albo własna wzoru),
     * $d: title, code (numer partii), produced, expires, net, gtin, sku, maker. QR z adresu wzoru ({sku}, {partia}, {gtin}).
     */
    public static function label(array $layout, array $class, array $d): string
    {
        $round = ($layout['shape'] ?? 'rect') === 'round';
        $w = (int) $layout['width_mm'];
        $h = $round ? $w : (int) $layout['height_mm'];
        $p = self::pictSize($layout);
        $pics = '';
        foreach (self::pictograms($class) as $c) {
            $pics .= '<img class="clp-ghs" src="' . self::pictogramSrc($c) . '" alt="' . self::h($c) . '" title="' . self::h(self::PICTOGRAMS[$c]) . '" style="width:' . $p . 'mm;height:' . $p . 'mm">';
        }
        $safety = (string) ($layout['safety_img'] ?? '');
        $sh = (int) ($layout['safety_mm'] ?? 0) > 0 ? (int) $layout['safety_mm'] : $p;
        $icons = $pics . ($safety !== '' ? '<img class="clp-safe" src="' . self::h($safety) . '" alt="" style="height:' . $sh . 'mm">' : '');
        $codes = !empty($layout['show_codes']);
        $ph = static function (array $list) use ($codes): string {
            $out = [];
            foreach ($list as $x) {
                $out[] = ($codes && $x['code'] !== '' ? '<b>' . self::h($x['code']) . '</b> ' : ($x['code'] === 'EUH208' ? '<b>EUH208:</b> ' : '')) . self::h($x['text']);
            }
            return implode(' ', $out);
        };
        $body = trim(implode(' ', array_filter([
            ($cs = self::containsSentence((string) ($class['contains_text'] ?? ''))) !== '' ? (str_starts_with($cs, 'EUH208:') ? '<b>EUH208:</b>' . self::h(substr($cs, 7)) : self::h($cs)) : '',
            $ph(self::phrases((string) ($class['hazards'] ?? ''))),
            $ph(self::phrases((string) ($class['precautions'] ?? ''))),
            $ph(self::phrases((string) ($class['extra'] ?? ''))),
        ])));
        $signal = (string) ($class['signal_word'] ?? '');
        $warn = trim((string) ($layout['warning'] ?? ''));
        $ids = [];
        if (($d['code'] ?? '') !== '') {
            $ids[] = 'Nr partii: <b class="clp-lot">' . self::h($d['code']) . '</b>';
        }
        if (!empty($layout['show_dates']) && ($d['produced'] ?? '') !== '') {
            $ids[] = 'Prod.: ' . self::date((string) $d['produced']);
        }
        if (!empty($layout['show_dates']) && ($d['expires'] ?? '') !== '') {
            $ids[] = 'Najlepiej do: ' . self::date((string) $d['expires']);
        }
        if (($d['gtin'] ?? '') !== '') {
            $ids[] = 'GTIN: ' . self::h($d['gtin']);
        }
        if (trim((string) ($class['ufi'] ?? '')) !== '') {
            $ids[] = '<b>UFI: ' . self::h($class['ufi']) . '</b>';
        }
        $maker = trim((string) ($d['maker'] ?? ''));
        $net = trim((string) ($d['net'] ?? ''));
        $recycle = (string) ($layout['recycle_img'] ?? '');
        $qr = trim((string) ($layout['qr_text'] ?? ''));
        $qr = $qr !== '' ? strtr($qr, ['{sku}' => (string) ($d['sku'] ?? ''), '{partia}' => (string) ($d['code'] ?? ''), '{gtin}' => (string) ($d['gtin'] ?? '')]) : '';
        $bottom = ($qr !== '' ? '<div class="clp-qr" data-text="' . self::h($qr) . '"></div>' : '')
            . ($maker !== '' ? '<div class="clp-maker">' . nl2br(self::h($maker)) . '</div>' : '')
            . ($ids !== [] ? '<div class="clp-ids">' . implode('<br>', $ids) . '</div>' : '')
            . ($recycle !== '' ? '<img class="clp-rec" src="' . self::h($recycle) . '" alt="">' : '');
        $pad = $round ? 'padding:' . self::n($w * 0.08, '.') . 'mm ' . self::n($w * 0.1, '.') . 'mm ' . self::n($w * 0.06, '.') . 'mm;' : '';
        return '<div class="clp' . ($round ? ' round' : '') . '" style="' . $pad . 'width:' . $w . 'mm;height:' . $h . 'mm;font-size:' . str_replace(',', '.', (string) (float) $layout['font_pt']) . 'pt"><div class="clp-in">'
            . (($d['title'] ?? '') !== '' ? '<div class="clp-title">' . self::h(mb_strtoupper((string) $d['title'])) . '</div>' : '')
            . (trim((string) ($layout['subtitle'] ?? '')) !== '' ? '<div class="clp-sub">' . self::h(mb_strtoupper(trim((string) $layout['subtitle']))) . '</div>' : '')
            . ($signal !== '' || $warn !== '' ? '<div class="clp-warn">' . ($signal !== '' ? '<b>' . self::h($signal) . '!</b> ' : '') . self::h($warn) . '</div>' : '')
            . ($icons !== '' ? '<div class="clp-icons">' . $icons . '</div>' : '')
            . ($body !== '' ? '<div class="clp-body">' . $body . '</div>' : '')
            . ($bottom !== '' ? '<div class="clp-bottom">' . $bottom . '</div>' : '')
            . ($net !== '' ? '<div class="clp-net">' . self::h($net) . '</div>' : '')
            . '</div></div>';
    }

    /** Styl etykiety (podgląd i druk). */
    public static function css(): string
    {
        return '.clp{box-sizing:border-box;padding:1.8mm;border:0.2mm dashed #bbb;background:#fff;color:#000;overflow:hidden;display:flex;'
            . 'font-family:"Roboto Condensed","Arial Narrow",Arial,Helvetica,sans-serif;line-height:1.15;text-align:center;break-inside:avoid}'
            . '.clp.round{border-radius:50%}.clp.round .clp-bottom{width:86%}'
            . '.clp-in{margin:auto;width:100%;max-height:100%;overflow:hidden;display:flex;flex-direction:column;align-items:center;gap:0.7mm}'
            . '.clp-title{font-family:Montserrat,Arial,Helvetica,sans-serif;font-weight:800;font-size:2.2em;line-height:1.05;letter-spacing:.01em}'
            . '.clp-sub{font-family:Montserrat,Arial,Helvetica,sans-serif;font-size:1.25em;letter-spacing:.03em;margin-top:-0.6mm}'
            . '.clp-icons{display:flex;justify-content:center;align-items:center;gap:1.2mm;max-width:100%}.clp-icons img{display:block}.clp-ghs{flex:none}.clp-safe{min-width:0;flex:0 1 auto;object-fit:contain;object-position:left center}'
            . '.clp-body{max-width:100%}.clp-lot{font-weight:600}'
            . '.clp-bottom{display:flex;justify-content:center;align-items:center;gap:2mm;width:100%}.clp-maker{text-align:center;flex:0 1 auto}.clp-ids{text-align:left;white-space:nowrap}.clp-qr,.clp-rec{flex:none}'
            . '.clp-qr svg{display:block;width:12mm;height:12mm}.clp-rec{height:9mm;display:block}'
            . '.clp-net{font-family:Montserrat,Arial,Helvetica,sans-serif;font-weight:600;font-size:1.6em}'
            . '.clp.over{outline:0.6mm solid #d33}@media print{.clp{border-color:#eee}.clp.round{border-color:#ddd}.clp.over{outline:0}}';
    }

    /** Skrypt: kody QR (qrcodegen.js) i zaznaczenie etykiety, na której treść się nie mieści (tylko na ekranie). */
    public static function script(string $warnId): string
    {
        return '<script>(function(){function qr(){if(typeof qrcodegen==="undefined")return;document.querySelectorAll(".clp-qr[data-text]").forEach(function(el){'
            . 'var q=qrcodegen.QrCode.encodeText(el.dataset.text,qrcodegen.QrCode.Ecc.MEDIUM),n=q.size,b=1,d="";for(var y=0;y<n;y++)for(var x=0;x<n;x++)if(q.getModule(x,y))d+="M"+(x+b)+","+(y+b)+"h1v1h-1z";'
            . 'el.innerHTML=\'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 \'+(n+2*b)+" "+(n+2*b)+\'" shape-rendering="crispEdges"><path d="\'+d+\'" fill="#000"/></svg>\';});}'
            . 'function c(){var bad=false;document.querySelectorAll(".clp").forEach(function(l){var t=l.querySelector(".clp-in");var o=t&&t.scrollHeight>t.clientHeight+1;l.classList.toggle("over",!!o);bad=bad||o;});'
            . 'var w=document.getElementById(' . json_encode($warnId) . ');if(w){w.hidden=!bad;}}function go(){qr();c();}if(document.readyState!=="loading"){go();}else{document.addEventListener("DOMContentLoaded",go);}window.addEventListener("load",c);})();</script>';
    }
}
