<?php
declare(strict_types=1);

namespace PaseExt\Ksef;

/**
 * Faktura VAT w strukturze FA(3) (schemat_FA(3)_v1-0E.xsd, przestrzeń http://crd.gov.pl/wzor/2025/06/25/13775/).
 *
 * Ceny są brutto, jak w sklepie: pozycje mają cenę jednostkową brutto (P_9B) i wartość brutto (P_11A),
 * a podatek liczymy od sumy wartości brutto w każdej stawce (art. 106e ust. 7 i 8 ustawy o VAT):
 * VAT = brutto × stawka / (100 + stawka), netto = brutto − VAT.
 *
 * Wejście (tablica):
 *   number, issue_date (RRRR-MM-DD), place?, currency (tylko PLN), created_at? (UTC, RRRR-MM-DDTGG:MM:SSZ), system_info?
 *   seller:  nip, name, address1, address2?, country?, email?, phone?
 *   buyer:   nip? | eu_code + eu_vat? (UE) | brak = BrakID; name, address1?, address2?, country?, email?, phone?
 *   lines:   [{name, qty, unit?, unit_gross, gross, rate: 23|8|5|0|zw}]
 *   exempt_basis? (podstawa zwolnienia, gdy jest pozycja „zw”)
 *   paid?, paid_date?, payment_form? (1..7 wg FA(3)), payment_desc?, due_date?, bank_account?
 *   order_number?, order_date?, footer?
 */
final class Fa3Xml
{
    public const NS = 'http://crd.gov.pl/wzor/2025/06/25/13775/';

    /** Stawka w CRM -> [kod P_12, pole netto, pole VAT (null = bez VAT)]. Kolejność = kolejność pól w schemacie. */
    private const RATES = [
        '23' => ['23', 'P_13_1', 'P_14_1'],
        '8'  => ['8', 'P_13_2', 'P_14_2'],
        '5'  => ['5', 'P_13_3', 'P_14_3'],
        '0'  => ['0 KR', 'P_13_6_1', null],
        'zw' => ['zw', 'P_13_7', null],
    ];

    /**
     * Sumy w stawkach i razem.
     * @return array{rates:array<string,array{gross:float,net:float,vat:float}>,gross:float}
     */
    public static function totals(array $lines): array
    {
        $rates = [];
        foreach ($lines as $l) {
            $r = (string) $l['rate'];
            $rates[$r]['gross'] = round(($rates[$r]['gross'] ?? 0.0) + (float) $l['gross'], 2);
        }
        $out = [];
        $total = 0.0;
        foreach (array_keys(self::RATES) as $r) {
            if (!isset($rates[$r])) {
                continue;
            }
            $gross = $rates[$r]['gross'];
            $vat = is_numeric($r) && (int) $r > 0 ? round($gross * (int) $r / (100 + (int) $r), 2) : 0.0;
            $out[$r] = ['gross' => $gross, 'net' => round($gross - $vat, 2), 'vat' => $vat];
            $total += $gross;
        }
        return ['rates' => $out, 'gross' => round($total, 2)];
    }

    public static function build(array $inv): string
    {
        self::validate($inv);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElementNS(self::NS, 'Faktura');
        $doc->appendChild($root);
        $add = static function (\DOMElement $parent, string $name, ?string $value = null) use ($doc): \DOMElement {
            $el = $doc->createElementNS(self::NS, $name);
            if ($value !== null) {
                $el->appendChild($doc->createTextNode(self::clean($value)));
            }
            $parent->appendChild($el);
            return $el;
        };

        $h = $add($root, 'Naglowek');
        $kod = $add($h, 'KodFormularza', 'FA');
        $kod->setAttribute('kodSystemowy', 'FA (3)');
        $kod->setAttribute('wersjaSchemy', '1-0E');
        $add($h, 'WariantFormularza', '3');
        $add($h, 'DataWytworzeniaFa', (string) ($inv['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z')));
        $add($h, 'SystemInfo', self::cut((string) ($inv['system_info'] ?? 'CRM'), 256));

        // Sprzedawca
        $s = $inv['seller'];
        $p1 = $add($root, 'Podmiot1');
        $id = $add($p1, 'DaneIdentyfikacyjne');
        $add($id, 'NIP', (string) $s['nip']);
        $add($id, 'Nazwa', self::cut((string) $s['name'], 512));
        self::address($add, $p1, $s);
        self::contact($add, $p1, $s);

        // Nabywca
        $b = $inv['buyer'];
        $p2 = $add($root, 'Podmiot2');
        $id = $add($p2, 'DaneIdentyfikacyjne');
        if (($b['nip'] ?? '') !== '') {
            $add($id, 'NIP', (string) $b['nip']);
        } elseif (($b['eu_code'] ?? '') !== '' && ($b['eu_vat'] ?? '') !== '') {
            $add($id, 'KodUE', (string) $b['eu_code']);
            $add($id, 'NrVatUE', (string) $b['eu_vat']);
        } else {
            $add($id, 'BrakID', '1');
        }
        if (trim((string) ($b['name'] ?? '')) !== '') {
            $add($id, 'Nazwa', self::cut((string) $b['name'], 512));
        }
        if (trim((string) ($b['address1'] ?? '')) !== '') {
            self::address($add, $p2, $b);
        }
        self::contact($add, $p2, $b);
        $add($p2, 'JST', '2');
        $add($p2, 'GV', '2');

        // Faktura
        $fa = $add($root, 'Fa');
        $add($fa, 'KodWaluty', (string) $inv['currency']);
        $add($fa, 'P_1', (string) $inv['issue_date']);
        if (trim((string) ($inv['place'] ?? '')) !== '') {
            $add($fa, 'P_1M', self::cut((string) $inv['place'], 256));
        }
        $add($fa, 'P_2', (string) $inv['number']);
        $totals = self::totals($inv['lines']);
        foreach (self::RATES as $r => [, $netField, $vatField]) {
            if (!isset($totals['rates'][$r])) {
                continue;
            }
            $add($fa, $netField, self::money($totals['rates'][$r]['net']));
            if ($vatField !== null) {
                $add($fa, $vatField, self::money($totals['rates'][$r]['vat']));
            }
        }
        $add($fa, 'P_15', self::money($totals['gross']));

        $an = $add($fa, 'Adnotacje');
        $add($an, 'P_16', '2');   // metoda kasowa: nie
        $add($an, 'P_17', '2');   // samofakturowanie: nie
        $add($an, 'P_18', '2');   // odwrotne obciążenie: nie
        $add($an, 'P_18A', '2');  // mechanizm podzielonej płatności: nie
        $zw = $add($an, 'Zwolnienie');
        if (isset($totals['rates']['zw'])) {
            $add($zw, 'P_19', '1');
            $add($zw, 'P_19A', self::cut((string) $inv['exempt_basis'], 256));
        } else {
            $add($zw, 'P_19N', '1');
        }
        $add($add($an, 'NoweSrodkiTransportu'), 'P_22N', '1');
        $add($an, 'P_23', '2');   // procedura uproszczona (trójstronna): nie
        $add($add($an, 'PMarzy'), 'P_PMarzyN', '1');
        $add($fa, 'RodzajFaktury', 'VAT');

        foreach (array_values($inv['lines']) as $i => $l) {
            $w = $add($fa, 'FaWiersz');
            $add($w, 'NrWierszaFa', (string) ($i + 1));
            $add($w, 'P_7', self::cut((string) $l['name'], 512));
            $add($w, 'P_8A', self::cut((string) ($l['unit'] ?? 'szt.'), 256));
            $add($w, 'P_8B', self::qty((float) $l['qty']));
            $add($w, 'P_9B', self::price((float) $l['unit_gross']));
            $add($w, 'P_11A', self::money((float) $l['gross']));
            $add($w, 'P_12', self::RATES[(string) $l['rate']][0]);
        }

        $paid = !empty($inv['paid']);
        $form = (int) ($inv['payment_form'] ?? 0);
        $desc = trim((string) ($inv['payment_desc'] ?? ''));
        $due = trim((string) ($inv['due_date'] ?? ''));
        $bank = preg_replace('/\s+/', '', (string) ($inv['bank_account'] ?? '')) ?? '';
        if ($paid || $form > 0 || $desc !== '' || $due !== '' || $bank !== '') {
            $pl = $add($fa, 'Platnosc');
            if ($paid) {
                $add($pl, 'Zaplacono', '1');
                $add($pl, 'DataZaplaty', (string) ($inv['paid_date'] ?? $inv['issue_date']));
            } elseif ($due !== '') {
                $add($add($pl, 'TerminPlatnosci'), 'Termin', $due);
            }
            if ($form >= 1 && $form <= 7) {
                $add($pl, 'FormaPlatnosci', (string) $form);
            } elseif ($desc !== '') {
                $add($pl, 'PlatnoscInna', '1');
                $add($pl, 'OpisPlatnosci', self::cut($desc, 256));
            }
            if ($bank !== '' && !$paid) {
                $add($add($pl, 'RachunekBankowy'), 'NrRB', $bank);
            }
        }
        if (trim((string) ($inv['order_number'] ?? '')) !== '') {
            $z = $add($add($fa, 'WarunkiTransakcji'), 'Zamowienia');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($inv['order_date'] ?? ''))) {
                $add($z, 'DataZamowienia', (string) $inv['order_date']);
            }
            $add($z, 'NrZamowienia', self::cut((string) $inv['order_number'], 256));
        }
        if (trim((string) ($inv['footer'] ?? '')) !== '') {
            $add($add($add($root, 'Stopka'), 'Informacje'), 'StopkaFaktury', self::cut((string) $inv['footer'], 3500));
        }

        return (string) $doc->saveXML();
    }

    /** Sprawdza dane, których schemat nie przepuści, i mówi po polsku, co poprawić. */
    public static function validate(array $inv): void
    {
        $err = [];
        if (($inv['currency'] ?? '') !== 'PLN') {
            $err[] = 'wtyczka wystawia faktury tylko w PLN (zamówienie jest w ' . ($inv['currency'] ?? '?') . ')';
        }
        if (!self::validNip((string) ($inv['seller']['nip'] ?? ''))) {
            $err[] = 'NIP sprzedawcy w ustawieniach jest niepoprawny';
        }
        foreach (['name' => 'nazwa', 'address1' => 'adres'] as $k => $label) {
            if (trim((string) ($inv['seller'][$k] ?? '')) === '') {
                $err[] = 'brak: ' . $label . ' sprzedawcy w ustawieniach';
            }
        }
        $nip = (string) ($inv['buyer']['nip'] ?? '');
        if ($nip !== '' && !self::validNip($nip)) {
            $err[] = 'NIP nabywcy „' . $nip . '” jest niepoprawny - popraw dane do faktury';
        }
        if (($inv['buyer']['eu_vat'] ?? '') !== '' && !preg_match('/^(\d|[A-Z]|\+|\*){1,12}$/', (string) $inv['buyer']['eu_vat'])) {
            $err[] = 'numer VAT UE nabywcy jest niepoprawny';
        }
        if (trim((string) ($inv['number'] ?? '')) === '') {
            $err[] = 'brak numeru faktury';
        }
        if (empty($inv['lines'])) {
            $err[] = 'faktura nie ma pozycji';
        }
        foreach ((array) ($inv['lines'] ?? []) as $l) {
            if (!isset(self::RATES[(string) ($l['rate'] ?? '')])) {
                $err[] = 'nieobsługiwana stawka VAT „' . ($l['rate'] ?? '') . '” (pozycja ' . ($l['name'] ?? '') . ')';
            }
            if ((float) ($l['qty'] ?? 0) <= 0) {
                $err[] = 'pozycja „' . ($l['name'] ?? '') . '” ma ilość 0';
            }
        }
        if (in_array('zw', array_map(static fn($l) => (string) ($l['rate'] ?? ''), (array) ($inv['lines'] ?? [])), true)
            && trim((string) ($inv['exempt_basis'] ?? '')) === '') {
            $err[] = 'pozycja ze stawką „zw” wymaga podstawy zwolnienia w ustawieniach';
        }
        if ($err !== []) {
            throw new \RuntimeException('Nie można wystawić e-faktury: ' . implode('; ', $err) . '.');
        }
    }

    /** NIP: 10 cyfr, suma kontrolna i wzorzec ze schematu (bez zer na początku). */
    public static function validNip(string $nip): bool
    {
        if (!preg_match('/^[1-9]((\d[1-9])|([1-9]\d))\d{7}$/', $nip)) {
            return false;
        }
        $w = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += $w[$i] * (int) $nip[$i];
        }
        return $sum % 11 === (int) $nip[9];
    }

    private static function address(callable $add, \DOMElement $parent, array $p): void
    {
        $a = $add($parent, 'Adres');
        $add($a, 'KodKraju', strtoupper((string) (($p['country'] ?? '') ?: 'PL')));
        $add($a, 'AdresL1', self::cut((string) $p['address1'], 512));
        if (trim((string) ($p['address2'] ?? '')) !== '') {
            $add($a, 'AdresL2', self::cut((string) $p['address2'], 512));
        }
    }

    private static function contact(callable $add, \DOMElement $parent, array $p): void
    {
        $email = trim((string) ($p['email'] ?? ''));
        $phone = trim(preg_replace('/[^\d+ ]/', '', (string) ($p['phone'] ?? '')) ?? '');
        $email = preg_match('/^.+@.+$/', $email) && strlen($email) <= 255 ? $email : '';
        if ($email === '' && $phone === '') {
            return;
        }
        $c = $add($parent, 'DaneKontaktowe');
        if ($email !== '') {
            $add($c, 'Email', $email);
        }
        if ($phone !== '') {
            $add($c, 'Telefon', substr($phone, 0, 16));
        }
    }

    public static function money(float $v): string
    {
        return number_format(round($v, 2), 2, '.', '');
    }

    private static function price(float $v): string
    {
        // Do 8 miejsc po przecinku (TKwotowy2), co najmniej 2: 12.3 -> 12.30, 3.333333333 -> 3.33333333.
        [$int, $frac] = explode('.', number_format(round($v, 8), 8, '.', ''));
        return $int . '.' . str_pad(rtrim($frac, '0'), 2, '0');
    }

    private static function qty(float $v): string
    {
        $s = rtrim(rtrim(number_format(round($v, 6), 6, '.', ''), '0'), '.');
        return $s === '' ? '0' : $s;
    }

    /** Tekst bez znaków sterujących i nadmiarowych spacji (typ xsd:token). */
    private static function clean(string $v): string
    {
        $v = preg_replace('/\s+/u', ' ', $v) ?? '';
        return trim(preg_replace('/\p{Cc}/u', '', $v) ?? '');
    }

    private static function cut(string $v, int $max): string
    {
        $v = self::clean($v);
        return mb_strlen($v) > $max ? mb_substr($v, 0, $max) : $v;
    }
}
