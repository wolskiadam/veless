<?php
declare(strict_types=1);

namespace PaseExt\Produkcja;

use PaseExt\Produkcja\Ui as U;

/**
 * Etykiety CLP: wzór (piktogramy GHS, hasło ostrzegawcze, zwroty H i P, „Zawiera”, UFI, dane dostawcy) przypisany do wyrobu
 * albo do zapachu, a przy druku partii system wstawia jej numer, datę produkcji i ważność.
 *
 * Zwroty wpisuje się kodami (H317, P102, P302+P352) - tekst po polsku wstawia się sam ze słownika poniżej, a linia
 * z kodem i własnym tekstem albo zwykły tekst zostaje bez zmian. Piktogramy GHS01-GHS09 (domena publiczna, UNECE)
 * leżą w assets/clp/ i są wstawiane do strony jako obrazki, więc drukują się też bez internetu.
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

    /**
     * Zwroty z pola wzoru. Linia z samymi kodami („H317, H412”) rozpada się na zwroty ze słownika; linia „kod + tekst”
     * i zwykły tekst zostają tak, jak je wpisano.
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
                $out[] = ['code' => self::normCode($m[1]), 'text' => trim($m[2]), 'known' => true, 'todo' => str_contains($m[2], '…')];
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

    /** Bok piktogramu w mm: wpisany albo najmniejszy dozwolony (romb p²/2 ≥ 1/15 etykiety i ≥ 1 cm²). */
    public static function pictSize(array $t): int
    {
        return (int) ($t['pict_mm'] ?? 0) > 0 ? (int) $t['pict_mm'] : (int) ceil(sqrt(max(100.0, (int) $t['width_mm'] * (int) $t['height_mm'] / 15) * 2));
    }

    /** @return list<string> kody piktogramów wzoru */
    public static function pictograms(array $t): array
    {
        return array_values(array_filter(explode(',', (string) ($t['pictograms'] ?? '')), static fn(string $c): bool => isset(self::PICTOGRAMS[$c])));
    }

    /** Piktogram jako data: URI (obrazek w stronie, drukuje się bez sieci). */
    public static function pictogramSrc(string $code): string
    {
        static $cache = [];
        if (!isset(self::PICTOGRAMS[$code])) {
            return '';
        }
        return $cache[$code] ??= 'data:image/svg+xml;base64,' . base64_encode((string) @file_get_contents(__DIR__ . '/assets/clp/' . $code . '.svg'));
    }

    /**
     * Wzór CLP dla partii: wyrób → zapach z partii (materiał z wzorem) → zapach z uwag partii zaimportowanej.
     * @return array{clp:?array,from:string}
     */
    public static function forBatch(Store $s, array $b, array $product): array
    {
        if (($product['clp_id'] ?? null) && ($t = $s->clp((string) $product['clp_id'])) !== null) {
            return ['clp' => $t, 'from' => 'z wyrobu ' . $product['name']];
        }
        foreach (json_decode((string) ($b['lines_json'] ?? ''), true) ?: [] as $l) {
            $m = $s->material((string) ($l['material_id'] ?? ''));
            if ($m !== null && $m['clp_id'] && ($t = $s->clp((string) $m['clp_id'])) !== null) {
                return ['clp' => $t, 'from' => 'z zapachu ' . $m['name']];
            }
        }
        if (preg_match('/^Zapach:\s*(.+)$/mu', (string) ($b['notes'] ?? ''), $mm)) {
            $m = $s->one('SELECT * FROM ext_prod_materials WHERE LOWER(name) = LOWER(?) AND clp_id IS NOT NULL', [trim($mm[1])]);
            if ($m !== null && ($t = $s->clp((string) $m['clp_id'])) !== null) {
                return ['clp' => $t, 'from' => 'z zapachu ' . $m['name']];
            }
        }
        return ['clp' => null, 'from' => ''];
    }

    /** Wzór dla wyrobu: własny albo z zapachu w recepturze. @return array{clp:?array,from:string} */
    public static function forProduct(Store $s, array $w): array
    {
        if (($w['clp_id'] ?? null) && ($t = $s->clp((string) $w['clp_id'])) !== null) {
            return ['clp' => $t, 'from' => ''];
        }
        foreach ($s->recipe((string) $w['id']) as $l) {
            $m = $s->material((string) $l['material_id']);
            if ($m !== null && $m['clp_id'] && ($t = $s->clp((string) $m['clp_id'])) !== null) {
                return ['clp' => $t, 'from' => 'z zapachu ' . $m['name']];
            }
        }
        return ['clp' => null, 'from' => ''];
    }

    /**
     * Co sprawdzić przed drukiem (wymagania CLP, które da się policzyć).
     * @return list<string>
     */
    public static function checks(array $t, string $defaultMaker): array
    {
        $out = [];
        $pict = self::pictograms($t);
        $h = self::phrases((string) $t['hazards']);
        $all = array_merge($h, self::phrases((string) $t['precautions']), self::phrases((string) $t['extra']));
        foreach ($all as $p) {
            if (!$p['known']) {
                $out[] = 'Nie znam kodu ' . $p['code'] . ' - wpisz go razem z treścią, np. „' . $p['code'] . ' treść zwrotu”.';
            } elseif ($p['todo']) {
                $out[] = 'Uzupełnij „…” w ' . ($p['code'] !== '' ? $p['code'] : 'tekście') . ' - wpisz całą linię z kodem i pełną treścią.';
            }
        }
        if ($pict !== [] && $t['signal_word'] === '') {
            $out[] = 'Są piktogramy, a nie ma hasła ostrzegawczego (Uwaga albo Niebezpieczeństwo).';
        }
        if ($pict !== [] && array_filter($h, static fn(array $p): bool => str_starts_with($p['code'], 'H')) === []) {
            $out[] = 'Są piktogramy, a nie ma zwrotów H.';
        }
        if (trim((string) $t['maker']) === '' && trim($defaultMaker) === '') {
            $out[] = 'Brak danych dostawcy (nazwa, adres, telefon). Wpisz je we wzorze albo w ustawieniach wtyczki („Producent na etykiecie partii”).';
        }
        $w = (int) $t['width_mm'];
        $hh = (int) $t['height_mm'];
        $p = self::pictSize($t);
        // Romb piktogramu wypełnia kwadrat p×p, więc jego pole to p²/2. CLP: co najmniej 1/15 pola etykiety i nie mniej niż 1 cm².
        $need = max(100.0, $w * $hh / 15);
        if ($pict !== [] && $p * $p / 2 < $need) {
            $out[] = 'Piktogram ' . $p . ' mm jest za mały: romb powinien mieć co najmniej ' . U::n(round($need / 100, 2)) . ' cm² (1/15 etykiety, min. 1 cm²), czyli piktogram ok. '
                . (int) ceil(sqrt($need * 2)) . ' mm.';
        }
        if ($pict !== [] && $p > $hh - 3) {
            $out[] = 'Piktogram (' . $p . ' mm) nie mieści się w wysokości etykiety. Zmień jej proporcje.';
        }
        $x = (float) $t['font_pt'] * 0.3528 * 0.52;
        if ($x < 1.2) {
            $out[] = 'Czcionka ' . U::n($t['font_pt']) . ' pt daje małe litery wysokości ok. ' . U::n(round($x, 2)) . ' mm. Zmienione przepisy CLP wymagają co najmniej 1,2 mm (od ok. 6,6 pt).';
        }
        return $out;
    }

    /**
     * Jedna etykieta CLP. $d: title, code (numer partii), produced, expires, net, maker.
     */
    public static function label(array $t, array $d): string
    {
        $w = (int) $t['width_mm'];
        $h = (int) $t['height_mm'];
        $p = self::pictSize($t);
        $pict = self::pictograms($t);
        $cols = max(1, min(count($pict) ?: 1, intdiv((int) floor($w * 0.62) + 1, $p + 1)));
        $pics = '';
        foreach ($pict as $c) {
            $pics .= '<img src="' . self::pictogramSrc($c) . '" alt="' . U::h($c) . '" title="' . U::h(self::PICTOGRAMS[$c]) . '">';
        }
        $ph = static function (array $list): string {
            $out = [];
            foreach ($list as $x) {
                $out[] = '<span>' . ($x['code'] !== '' ? '<b>' . U::h($x['code']) . '</b> ' : '') . U::h($x['text'] !== '' ? $x['text'] : '') . '</span>';
            }
            return implode(' ', $out);
        };
        $hz = self::phrases((string) $t['hazards']);
        $pr = self::phrases((string) $t['precautions']);
        $ex = trim((string) $t['extra']);
        $foot = [];
        if (($d['code'] ?? '') !== '') {
            $foot[] = 'Partia: <b class="clp-lot">' . U::h($d['code']) . '</b>';
        }
        if (($d['produced'] ?? '') !== '') {
            $foot[] = 'Prod.: ' . U::date($d['produced']);
        }
        if (($d['expires'] ?? '') !== '') {
            $foot[] = 'Najlepiej do: ' . U::date($d['expires']);
        }
        $net = trim((string) ($t['net_qty'] !== '' ? $t['net_qty'] : ($d['net'] ?? '')));
        if ($net !== '') {
            $foot[] = 'Masa netto: ' . U::h($net);
        }
        $maker = trim((string) ($t['maker'] !== '' ? $t['maker'] : ($d['maker'] ?? '')));
        $title = trim((string) ($t['title'] !== '' ? $t['title'] : ($d['title'] ?? '')));
        return '<div class="clp" style="width:' . $w . 'mm;height:' . $h . 'mm;font-size:' . U::h(str_replace(',', '.', (string) (float) $t['font_pt'])) . 'pt">'
            . '<div class="clp-txt">'
            . ($pict !== [] ? '<div class="clp-pic" style="grid-template-columns:repeat(' . $cols . ',' . $p . 'mm)">' . str_replace('<img ', '<img style="width:' . $p . 'mm;height:' . $p . 'mm" ', $pics) . '</div>' : '')
            . ($title !== '' ? '<div class="clp-name">' . U::h($title) . '</div>' : '')
            . ($t['signal_word'] !== '' ? '<div class="clp-sig">' . U::h(mb_strtoupper((string) $t['signal_word'])) . '</div>' : '')
            . (trim((string) $t['contains_text']) !== '' ? '<div class="clp-in">' . (preg_match('/^zawiera/iu', trim((string) $t['contains_text'])) ? '' : 'Zawiera: ') . U::h(trim((string) $t['contains_text'])) . '</div>' : '')
            . ($hz !== [] ? '<div class="clp-h">' . $ph($hz) . '</div>' : '')
            . ($pr !== [] ? '<div class="clp-p">' . $ph($pr) . '</div>' : '')
            . ($ex !== '' ? '<div class="clp-x">' . $ph(self::phrases($ex)) . '</div>' : '')
            . (trim((string) $t['ufi']) !== '' ? '<div class="clp-ufi">UFI: ' . U::h($t['ufi']) . '</div>' : '')
            . ($foot !== [] ? '<div class="clp-foot">' . implode(' · ', $foot) . '</div>' : '')
            . ($maker !== '' ? '<div class="clp-maker">' . nl2br(U::h($maker)) . '</div>' : '')
            . '</div></div>';
    }

    /** Styl etykiety CLP (ta sama na podglądzie i w druku). */
    public static function css(): string
    {
        return '.clp{display:block;box-sizing:border-box;padding:1.5mm;border:0.2mm dashed #bbb;background:#fff;color:#000;overflow:hidden;'
            . 'font-family:Arial,Helvetica,sans-serif;line-height:1.18;text-align:left}'
            . '.clp-pic{display:grid;gap:1mm;float:left;margin:0 1.5mm 0.5mm 0}.clp-pic img{display:block}'
            . '.clp-txt{height:100%;overflow:hidden}.clp-txt>div{margin-bottom:0.5mm}'
            . '.clp-name{font-weight:700;font-size:1.25em}.clp-sig{font-weight:800;font-size:1.15em;letter-spacing:.02em}'
            . '.clp-h b,.clp-p b,.clp-x b{font-weight:700}.clp-in{font-weight:600}.clp-ufi{font-weight:700}'
            . '.clp-foot{padding-top:0.4mm;border-top:0.2mm solid #000;overflow:hidden}.clp-lot{font-family:ui-monospace,Menlo,Consolas,monospace;font-weight:700}'
            . '.clp-maker{font-size:.92em}.clp.over{outline:0.6mm solid #d33;outline-offset:0}'
            . '@media print{.clp{border-color:#eee}.clp.over{outline:0}}';
    }

    /** Skrypt: zaznacza etykietę, na której tekst się nie mieści (tylko na ekranie). */
    public static function overflowScript(string $warnId): string
    {
        return '<script>(function(){function c(){var bad=false;document.querySelectorAll(".clp").forEach(function(l){var t=l.querySelector(".clp-txt");var o=t&&t.scrollHeight>t.clientHeight+1;l.classList.toggle("over",!!o);bad=bad||o;});'
            . 'var w=document.getElementById(' . json_encode($warnId) . ');if(w){w.hidden=!bad;}}if(document.readyState!=="loading"){c();}else{document.addEventListener("DOMContentLoaded",c);}window.addEventListener("load",c);})();</script>';
    }
}
