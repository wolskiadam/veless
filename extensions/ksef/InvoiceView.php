<?php
declare(strict_types=1);

namespace PaseExt\Ksef;

/**
 * Czytelny podgląd e-faktury z XML (FA(2) i FA(3)): strony, pozycje, podsumowanie stawek, płatność.
 * Nie sprawdza zgodności ze schematem; elementów szuka po nazwie, bez względu na przestrzeń nazw wersji schematu.
 */
final class InvoiceView
{
    /** Pola P_13_x (netto) i P_14_x (VAT) podsumowania z opisem stawki. */
    private const RATES = [
        '1' => '23% / 22%', '2' => '8% / 7%', '3' => '5%', '4' => 'ryczałt taxi', '5' => 'procedura OSS',
        '6_1' => '0% (kraj)', '6_2' => '0% (WDT)', '6_3' => '0% (eksport)', '7' => 'zwolnione', '8' => 'np. (poza krajem)',
        '9' => 'np. (art. 100 ust. 1 pkt 4)', '10' => 'odwrotne obciążenie', '11' => 'marża',
    ];

    private \DOMXPath $xp;

    private function __construct(\DOMDocument $doc)
    {
        $this->xp = new \DOMXPath($doc);
    }

    /**
     * @return array{seller:array<string,string>,buyer:array<string,string>,number:string,issue_date:string,sale_date:string,place:string,
     *   kind:string,currency:string,lines:list<array<string,string>>,rates:list<array{label:string,net:string,vat:string}>,total:string,
     *   payment:array<string,string>,period:string,annotations:list<string>,corrected:list<string>,reason:string,registers:list<string>,notes:list<string>}
     */
    public static function parse(string $xml): array
    {
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $xml !== '' && $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            throw new \RuntimeException('Nie udało się odczytać XML faktury.');
        }
        $v = new self($doc);
        $root = $doc->documentElement;
        $fa = $v->node('Fa', $root);
        if ($fa === null) {
            throw new \RuntimeException('XML nie wygląda na e-fakturę (brak elementu Fa).');
        }

        $lines = [];
        foreach ($v->nodes('FaWiersz', $fa) as $w) {
            $lines[] = [
                'no' => $v->text('NrWierszaFa', $w),
                'name' => $v->text('P_7', $w),
                'unit' => $v->text('P_8A', $w),
                'qty' => $v->text('P_8B', $w),
                'price_net' => $v->text('P_9A', $w),
                'price_gross' => $v->text('P_9B', $w),
                'value_net' => $v->text('P_11', $w),
                'value_gross' => $v->text('P_11A', $w),
                'rate' => $v->text('P_12', $w),
            ];
        }
        $rates = [];
        foreach (self::RATES as $k => $label) {
            $net = $v->text('P_13_' . $k, $fa);
            $vat = $v->text('P_14_' . $k, $fa);
            if ($net !== '' || $vat !== '') {
                $rates[] = ['label' => $label, 'net' => $net, 'vat' => $vat, 'gross' => number_format((float) $net + (float) $vat, 2, '.', '')];
            }
        }
        $pay = $v->node('Platnosc', $fa);
        $payment = ['paid' => '', 'paid_date' => '', 'due' => '', 'form' => '', 'account' => '', 'bank' => ''];
        if ($pay !== null) {
            $payment = [
                'paid' => $v->text('Zaplacono', $pay) === '1' ? '1' : '',
                'paid_date' => $v->text('DataZaplaty', $pay),
                'due' => $v->text('TerminPlatnosci/Termin', $pay),
                'form' => self::paymentForm($v->text('FormaPlatnosci', $pay), $v->text('OpisPlatnosci', $pay)),
                'account' => $v->text('RachunekBankowy/NrRB', $pay),
                'bank' => $v->text('RachunekBankowy/NazwaBanku', $pay),
            ];
        }
        $annotations = [];
        foreach (['P_16' => 'metoda kasowa', 'P_17' => 'samofakturowanie', 'P_18' => 'odwrotne obciążenie', 'P_18A' => 'mechanizm podzielonej płatności'] as $k => $label) {
            if ($v->text('Adnotacje/' . $k, $fa) === '1') {
                $annotations[] = $label;
            }
        }
        if ($v->text('Adnotacje/Zwolnienie/P_19', $fa) === '1') {
            $annotations[] = 'zwolnienie z VAT: ' . trim($v->text('Adnotacje/Zwolnienie/P_19A', $fa) . ' ' . $v->text('Adnotacje/Zwolnienie/P_19B', $fa) . ' ' . $v->text('Adnotacje/Zwolnienie/P_19C', $fa));
        }
        if ($v->text('Adnotacje/PMarzy/P_PMarzy', $fa) === '1') {
            $annotations[] = 'procedura marży';
        }
        $corrected = [];
        foreach ($v->nodes('DaneFaKorygowanej', $fa) as $d) {
            $ksef = $v->text('NrKSeFFaKorygowanej', $d);
            $corrected[] = trim($v->text('NrFaKorygowanej', $d) . ($v->text('DataWystFaKorygowanej', $d) !== '' ? ' z ' . $v->text('DataWystFaKorygowanej', $d) : '')
                . ($ksef !== '' ? ', KSeF ' . $ksef : ''));
        }
        $registers = [];
        foreach ($v->nodes('Stopka/Rejestry', $root) as $r) {
            foreach (['KRS' => 'KRS', 'REGON' => 'REGON', 'BDO' => 'BDO'] as $k => $label) {
                if ($v->text($k, $r) !== '') {
                    $registers[] = $label . ' ' . $v->text($k, $r);
                }
            }
        }
        $notes = [];
        foreach ($v->nodes('DodatkowyOpis', $fa) as $d) {
            $notes[] = trim($v->text('Klucz', $d) . ': ' . $v->text('Wartosc', $d), ': ');
        }
        foreach ($v->nodes('Stopka/Informacje/StopkaFaktury', $root) as $s) {
            $notes[] = trim($s->textContent);
        }

        return [
            'seller' => $v->party($v->node('Podmiot1', $root)),
            'buyer' => $v->party($v->node('Podmiot2', $root)),
            'number' => $v->text('P_2', $fa),
            'issue_date' => $v->text('P_1', $fa),
            'sale_date' => $v->text('P_6', $fa),
            'period' => trim($v->text('OkresFa/P_6_Od', $fa) . ' - ' . $v->text('OkresFa/P_6_Do', $fa), ' -'),
            'place' => $v->text('P_1M', $fa),
            'kind' => $v->text('RodzajFaktury', $fa),
            'currency' => $v->text('KodWaluty', $fa) ?: 'PLN',
            'lines' => $lines,
            'rates' => $rates,
            'total' => $v->text('P_15', $fa),
            'payment' => $payment,
            'annotations' => $annotations,
            'corrected' => $corrected,
            'reason' => $v->text('PrzyczynaKorekty', $fa),
            'registers' => $registers,
            'notes' => array_values(array_filter($notes, static fn(string $n): bool => $n !== '')),
        ];
    }

    /**
     * Podgląd faktury jako HTML w układzie wizualizacji KSeF (dane z XML są escapowane).
     * @param array{ksef?:string,qr?:string} $meta numer KSeF i link weryfikacyjny (KOD I) - z nich kod QR pod fakturą
     */
    public static function html(string $xml, array $meta = []): string
    {
        $inv = self::parse($xml);
        $h = static fn(mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES);
        $m = static fn(string $s): string => $h(self::money($s));
        $cur = $h($inv['currency']);
        $section = static fn(string $title): string => '<h4 class="ksef-h">' . $h($title) . '</h4>';
        $party = static function (string $title, array $p) use ($h): string {
            $id = self::partyId($p);
            return '<div class="ksef-party"><div class="ksef-label">' . $h($title) . '</div><b>' . $h($p['name'] ?: '-') . '</b>'
                . ($id !== '' ? '<div>' . $h($id) . '</div>' : '') . ($p['address'] !== '' ? '<div>' . $h($p['address']) . '</div>' : '')
                . ($p['contact'] !== '' ? '<div class="ksef-muted">' . $h($p['contact']) . '</div>' : '') . '</div>';
        };
        $html = '<style>
.ksef-doc{max-width:900px;font-size:14px;line-height:1.45}
.ksef-top{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;border-bottom:2px solid var(--ink-1,#2c2a26);padding-bottom:10px;margin-bottom:14px}
.ksef-top h3{margin:0;font-size:20px}.ksef-label{color:var(--ink-3,#888);font-size:11px;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px}
.ksef-muted{color:var(--ink-2,#666)}.ksef-h{margin:18px 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-2,#666);border-bottom:1px solid var(--line,#e5e2da);padding-bottom:4px}
.ksef-parties{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px}
.ksef-party{border:1px solid var(--line,#e5e2da);border-radius:8px;padding:10px 12px}
.ksef-kv{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:6px 16px}
.ksef-doc table{width:100%}.ksef-doc td.n,.ksef-doc th.n{text-align:right;white-space:nowrap}
.ksef-total{display:flex;justify-content:flex-end;gap:12px;align-items:baseline;margin-top:10px;font-size:16px}
.ksef-total b{font-size:20px}
.ksef-qr{display:flex;gap:16px;align-items:center;border:1px solid var(--line,#e5e2da);border-radius:8px;padding:12px;margin-top:18px}
.ksef-qr svg{width:120px;height:120px;flex:none}.ksef-qr a{word-break:break-all;font-size:12px}
</style><div class="ksef-doc">';
        $html .= '<div class="ksef-top"><div><div class="ksef-label">Numer faktury</div><h3>' . $h($inv['number'] ?: '-') . '</h3>'
            . '<div class="ksef-muted">' . $h(self::kindTitle($inv['kind'])) . '</div></div>'
            . (!empty($meta['ksef']) ? '<div style="text-align:right"><div class="ksef-label">Numer KSeF</div><code>' . $h($meta['ksef']) . '</code></div>' : '') . '</div>';
        $html .= '<div class="ksef-parties">' . $party('Sprzedawca', $inv['seller']) . $party('Nabywca', $inv['buyer']) . '</div>';

        $html .= $section('Szczegóły') . '<div class="ksef-kv">';
        foreach (self::details($inv) as $label => $value) {
            $html .= '<div><div class="ksef-label">' . $h($label) . '</div>' . $h($value) . '</div>';
        }
        $html .= '</div>';
        if ($inv['corrected'] !== [] || $inv['reason'] !== '') {
            $html .= '<p style="margin:8px 0 0">' . ($inv['corrected'] !== [] ? 'Korygowana faktura: <b>' . $h(implode('; ', $inv['corrected'])) . '</b>' : '')
                . ($inv['reason'] !== '' ? '<br>Przyczyna korekty: ' . $h($inv['reason']) : '') . '</p>';
        }

        $html .= $section('Pozycje') . '<div style="overflow-x:auto"><table><tr><th>Lp.</th><th>Nazwa towaru lub usługi</th><th class="n">Ilość</th><th>Miara</th>'
            . '<th class="n">Cena jedn.</th><th class="n">Stawka</th><th class="n">Wartość</th></tr>';
        foreach ($inv['lines'] as $l) {
            [$price, $value, $basis] = self::lineAmounts($l);
            $html .= '<tr><td>' . $h($l['no']) . '</td><td>' . $h($l['name']) . '</td><td class="n">' . $h(self::qty($l['qty'])) . '</td><td>' . $h($l['unit']) . '</td>'
                . '<td class="n">' . $m($price) . '</td><td class="n">' . $h(self::rate($l['rate'])) . '</td><td class="n">' . $m($value)
                . ($basis !== '' ? ' <small class="ksef-muted">' . $basis . '</small>' : '') . '</td></tr>';
        }
        if ($inv['lines'] === []) {
            $html .= '<tr><td colspan="7" class="ksef-muted">Faktura nie ma pozycji (np. korekta albo faktura zaliczkowa).</td></tr>';
        }
        $html .= '</table></div><div class="ksef-total"><span>Kwota należności ogółem</span><b>' . $m($inv['total']) . ' ' . $cur . '</b></div>';

        if ($inv['rates'] !== []) {
            $html .= $section('Podsumowanie stawek podatku') . '<table><tr><th>Stawka</th><th class="n">Kwota netto</th><th class="n">Kwota podatku</th><th class="n">Kwota brutto</th></tr>';
            foreach ($inv['rates'] as $r) {
                $html .= '<tr><td>' . $h($r['label']) . '</td><td class="n">' . $m($r['net']) . '</td><td class="n">' . $m($r['vat']) . '</td><td class="n">' . $m($r['gross']) . '</td></tr>';
            }
            $html .= '</table>';
        }
        $pay = self::paymentRows($inv['payment']);
        if ($pay !== []) {
            $html .= $section('Płatność') . '<div class="ksef-kv">';
            foreach ($pay as $label => $value) {
                $html .= '<div><div class="ksef-label">' . $h($label) . '</div>' . ($label === 'Rachunek' ? '<code>' . $h($value) . '</code>' : $h($value)) . '</div>';
            }
            $html .= '</div>';
        }
        $extra = array_merge($inv['annotations'] !== [] ? ['Adnotacje: ' . implode(', ', $inv['annotations'])] : [], $inv['notes'], $inv['registers'] !== [] ? [implode(' · ', $inv['registers'])] : []);
        if ($extra !== []) {
            $html .= $section('Dodatkowe informacje');
            foreach ($extra as $n) {
                $html .= '<p style="white-space:pre-wrap;margin:4px 0">' . $h($n) . '</p>';
            }
        }
        if (!empty($meta['qr'])) {
            $html .= '<div class="ksef-qr">' . QrCode::encode((string) $meta['qr'])->svg(2, 'Kod QR weryfikacji faktury w KSeF')
                . '<div><b>Sprawdź, czy faktura znajduje się w KSeF</b><br><span class="ksef-muted">Zeskanuj kod albo otwórz link:</span><br>'
                . '<a href="' . $h($meta['qr']) . '" target="_blank" rel="noopener noreferrer">' . $h($meta['qr']) . '</a></div></div>';
        }
        return $html . '</div>';
    }

    /** Kwota z XML w formacie polskim (1 234,56); pusty lub nieliczbowy tekst bez zmian. */
    public static function money(string $s): string
    {
        return $s === '' || !is_numeric($s) ? $s : number_format((float) $s, 2, ',', ' ');
    }

    public static function kindTitle(string $kind): string
    {
        return ['VAT' => 'Faktura podstawowa', 'KOR' => 'Faktura korygująca', 'ZAL' => 'Faktura zaliczkowa', 'ROZ' => 'Faktura rozliczeniowa',
            'UPR' => 'Faktura uproszczona', 'KOR_ZAL' => 'Faktura korygująca zaliczkową', 'KOR_ROZ' => 'Faktura korygująca rozliczeniową'][$kind] ?? ('Faktura ' . $kind);
    }

    /** NIP, numer VAT UE albo inny identyfikator strony. */
    public static function partyId(array $p): string
    {
        return $p['nip'] !== '' ? 'NIP ' . $p['nip'] : ($p['eu'] !== '' ? 'VAT UE ' . $p['eu'] : $p['other']);
    }

    /** @return array<string,string> etykieta => wartość */
    public static function details(array $inv): array
    {
        return array_filter([
            'Data wystawienia' => $inv['issue_date'],
            'Miejsce wystawienia' => $inv['place'],
            'Data dostawy / wykonania usługi' => $inv['sale_date'],
            'Okres' => $inv['period'],
            'Waluta' => $inv['currency'],
        ], static fn(string $v): bool => $v !== '');
    }

    /** @return array<string,string> etykieta => wartość */
    public static function paymentRows(array $p): array
    {
        $nrb = $p['account'] !== '' && preg_match('/^\d{26}$/', $p['account']) ? substr($p['account'], 0, 2) . ' ' . implode(' ', str_split(substr($p['account'], 2), 4)) : $p['account'];
        return array_filter([
            'Status' => $p['paid'] === '1' ? 'zapłacono' . ($p['paid_date'] !== '' ? ' ' . $p['paid_date'] : '') : '',
            'Termin płatności' => $p['paid'] === '1' ? '' : $p['due'],
            'Forma płatności' => $p['form'],
            'Rachunek' => $nrb,
            'Bank' => $p['bank'],
        ], static fn(string $v): bool => $v !== '');
    }

    /**
     * Cena jednostkowa, wartość i podstawa („netto”/„brutto”) pozycji.
     * @return array{0:string,1:string,2:string}
     */
    public static function lineAmounts(array $l): array
    {
        $gross = $l['value_gross'] !== '' || ($l['value_net'] === '' && $l['price_gross'] !== '');
        $price = $gross ? $l['price_gross'] : $l['price_net'];
        $value = $gross ? $l['value_gross'] : $l['value_net'];
        return [$price, $value, $value !== '' || $price !== '' ? ($gross ? 'brutto' : 'netto') : ''];
    }

    public static function rate(string $rate): string
    {
        return is_numeric($rate) ? $rate . '%' : (['zw' => 'zw.', 'oo' => 'o.o.', 'np I' => 'np.', 'np II' => 'np.'][$rate] ?? $rate);
    }

    public static function qty(string $qty): string
    {
        return is_numeric($qty) ? rtrim(rtrim(number_format((float) $qty, 6, ',', ' '), '0'), ',') : $qty;
    }

    public static function kindLabel(string $kind): string
    {
        return ['VAT' => 'VAT', 'KOR' => 'korygująca', 'ZAL' => 'zaliczkowa', 'ROZ' => 'rozliczeniowa', 'UPR' => 'uproszczona',
            'KOR_ZAL' => 'korygująca zaliczkową', 'KOR_ROZ' => 'korygująca rozliczeniową'][$kind] ?? $kind;
    }

    private static function paymentForm(string $code, string $other): string
    {
        return ['1' => 'gotówka', '2' => 'karta', '3' => 'bon', '4' => 'czek', '5' => 'kredyt', '6' => 'przelew', '7' => 'płatność mobilna'][$code] ?? $other;
    }

    /** @return array{name:string,nip:string,eu:string,other:string,address:string,contact:string} */
    private function party(?\DOMNode $p): array
    {
        if ($p === null) {
            return ['name' => '', 'nip' => '', 'eu' => '', 'other' => '', 'address' => '', 'contact' => ''];
        }
        return [
            'name' => $this->text('DaneIdentyfikacyjne/Nazwa', $p),
            'nip' => $this->text('DaneIdentyfikacyjne/NIP', $p),
            'eu' => $this->text('DaneIdentyfikacyjne/KodUE', $p) . $this->text('DaneIdentyfikacyjne/NrVatUE', $p),
            'other' => $this->text('DaneIdentyfikacyjne/NrID', $p),
            'address' => trim($this->text('Adres/AdresL1', $p) . ', ' . $this->text('Adres/AdresL2', $p), ', ')
                . (!in_array($this->text('Adres/KodKraju', $p), ['', 'PL'], true) ? ', ' . $this->text('Adres/KodKraju', $p) : ''),
            'contact' => trim($this->text('DaneKontaktowe/Email', $p) . ', ' . $this->text('DaneKontaktowe/Telefon', $p), ', '),
        ];
    }

    /** Ścieżka A/B/C po nazwach lokalnych, względem $ctx (tylko dzieci, bez szukania w głąb). */
    private function query(string $path, \DOMNode $ctx): \DOMNodeList
    {
        $parts = array_map(static fn(string $n): string => "*[local-name()='" . preg_replace('/[^A-Za-z0-9_]/', '', $n) . "']", explode('/', $path));
        $list = $this->xp->query(implode('/', $parts), $ctx);
        if ($list === false) {
            throw new \RuntimeException('Błędna ścieżka w XML faktury: ' . $path);
        }
        return $list;
    }

    private function node(string $path, \DOMNode $ctx): ?\DOMNode
    {
        return $this->query($path, $ctx)->item(0);
    }

    /** @return list<\DOMNode> */
    private function nodes(string $path, \DOMNode $ctx): array
    {
        return iterator_to_array($this->query($path, $ctx), false);
    }

    private function text(string $path, \DOMNode $ctx): string
    {
        $n = $this->node($path, $ctx);
        return $n === null ? '' : trim($n->textContent);
    }
}
