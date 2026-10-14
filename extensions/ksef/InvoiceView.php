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
     *   payment:array<string,string>,notes:list<string>}
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
                $rates[] = ['label' => $label, 'net' => $net, 'vat' => $vat];
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
            'place' => $v->text('P_1M', $fa),
            'kind' => $v->text('RodzajFaktury', $fa),
            'currency' => $v->text('KodWaluty', $fa) ?: 'PLN',
            'lines' => $lines,
            'rates' => $rates,
            'total' => $v->text('P_15', $fa),
            'payment' => $payment,
            'notes' => array_values(array_filter($notes, static fn(string $n): bool => $n !== '')),
        ];
    }

    /** Podgląd faktury jako HTML (dane z XML są escapowane). */
    public static function html(string $xml): string
    {
        $inv = self::parse($xml);
        $h = static fn(mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES);
        $m = static fn(string $s): string => $s === '' ? '' : (is_numeric($s) ? number_format((float) $s, 2, ',', ' ') : htmlspecialchars($s));
        $party = static function (string $title, array $p) use ($h): string {
            $id = $p['nip'] !== '' ? 'NIP ' . $p['nip'] : ($p['eu'] !== '' ? 'VAT UE ' . $p['eu'] : ($p['other'] !== '' ? $p['other'] : ''));
            return '<div><div style="color:var(--ink-3);font-size:12px">' . $h($title) . '</div><b>' . $h($p['name'] ?: '-') . '</b>'
                . ($id !== '' ? '<br>' . $h($id) : '') . ($p['address'] !== '' ? '<br>' . $h($p['address']) : '')
                . ($p['contact'] !== '' ? '<br><small>' . $h($p['contact']) . '</small>' : '') . '</div>';
        };
        $html = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin-bottom:12px">'
            . $party('Sprzedawca', $inv['seller']) . $party('Nabywca', $inv['buyer']) . '</div>'
            . '<p><b>Faktura ' . $h(self::kindLabel($inv['kind'])) . ' nr ' . $h($inv['number']) . '</b><br>Data wystawienia: ' . $h($inv['issue_date'])
            . ($inv['sale_date'] !== '' ? ', data sprzedaży: ' . $h($inv['sale_date']) : '') . ($inv['place'] !== '' ? ', ' . $h($inv['place']) : '') . '</p>';

        $html .= '<div style="overflow-x:auto"><table><tr><th>Lp.</th><th>Nazwa</th><th>Ilość</th><th>Cena</th><th>Wartość</th><th>VAT</th></tr>';
        foreach ($inv['lines'] as $l) {
            $gross = $l['value_gross'] !== '' || $l['price_gross'] !== '';
            $html .= '<tr><td>' . $h($l['no']) . '</td><td>' . $h($l['name']) . '</td><td>' . $h(trim($l['qty'] . ' ' . $l['unit'])) . '</td>'
                . '<td>' . $m($gross ? $l['price_gross'] : $l['price_net']) . ($gross ? ' <small>brutto</small>' : ($l['price_net'] !== '' ? ' <small>netto</small>' : '')) . '</td>'
                . '<td>' . $m($gross ? $l['value_gross'] : $l['value_net']) . '</td><td>' . $h(is_numeric($l['rate']) ? $l['rate'] . '%' : $l['rate']) . '</td></tr>';
        }
        if ($inv['lines'] === []) {
            $html .= '<tr><td colspan="6" style="color:var(--ink-2)">Faktura nie ma pozycji (np. korekta albo faktura zaliczkowa).</td></tr>';
        }
        $html .= '</table></div><table style="margin-top:10px"><tr><th>Stawka</th><th>Netto</th><th>VAT</th></tr>';
        foreach ($inv['rates'] as $r) {
            $html .= '<tr><td>' . $h($r['label']) . '</td><td>' . $m($r['net']) . '</td><td>' . $m($r['vat']) . '</td></tr>';
        }
        $html .= '<tr><td><b>Do zapłaty</b></td><td colspan="2"><b>' . $m($inv['total']) . ' ' . $h($inv['currency']) . '</b></td></tr></table>';

        $p = $inv['payment'];
        $pay = [];
        if ($p['paid'] === '1') {
            $pay[] = 'zapłacono' . ($p['paid_date'] !== '' ? ' ' . $h($p['paid_date']) : '');
        } elseif ($p['due'] !== '') {
            $pay[] = 'termin płatności ' . $h($p['due']);
        }
        if ($p['form'] !== '') {
            $pay[] = $h($p['form']);
        }
        if ($p['account'] !== '') {
            $nrb = preg_match('/^\d{26}$/', $p['account']) ? substr($p['account'], 0, 2) . ' ' . implode(' ', str_split(substr($p['account'], 2), 4)) : $p['account'];
            $pay[] = 'rachunek <code>' . $h($nrb) . '</code>' . ($p['bank'] !== '' ? ' (' . $h($p['bank']) . ')' : '');
        }
        if ($pay !== []) {
            $html .= '<p><b>Płatność:</b> ' . implode(', ', $pay) . '</p>';
        }
        foreach ($inv['notes'] as $n) {
            $html .= '<p style="color:var(--ink-2);white-space:pre-wrap;margin:4px 0">' . $h($n) . '</p>';
        }
        return $html;
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
            'address' => trim($this->text('Adres/AdresL1', $p) . ', ' . $this->text('Adres/AdresL2', $p), ', '),
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
