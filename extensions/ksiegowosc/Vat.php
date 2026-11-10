<?php
declare(strict_types=1);

namespace PaseExt\Ksiegowosc;

/**
 * Pozycje VAT dokumentu i rejestry VAT (sprzedaży i zakupu) za miesiąc.
 *
 * Pozycja należy do rejestru S (sprzedaż, VAT należny) albo Z (zakup, VAT naliczony) i do okresu RRRR-MM, w którym
 * ujmuje się ją w JPK (sprzedaż: obowiązek podatkowy, zwykle miesiąc dostawy; zakup: miesiąc odliczenia). W rejestrach
 * liczą się tylko dokumenty zaksięgowane. Storno kopiuje pozycje z minusem do tego samego okresu, więc błędny dokument
 * znika z rejestru, jakby go nie było (wysłany już JPK wymaga wtedy korekty).
 */
final class Vat
{
    /** Stawka => [opis, procent albo null]. */
    public const RATES = [
        '23' => ['23%', 23], '8' => ['8%', 8], '5' => ['5%', 5], '0' => ['0%', 0],
        'zw' => ['zwolnione', null], 'np' => ['nie podlega', null], 'oo' => ['odwrotne obciążenie', null],
    ];
    public const REGISTERS = ['S' => 'Sprzedaż (VAT należny)', 'Z' => 'Zakup (VAT naliczony)'];
    /** Zakup: co nabyto (JPK rozróżnia środki trwałe od pozostałych nabyć) i ile VAT odliczamy. */
    public const PURCHASE_KINDS = ['other' => 'Pozostałe nabycia', 'fixed' => 'Środki trwałe'];
    public const DEDUCT = ['100' => 'Odliczenie 100%', '50' => 'Odliczenie 50% (samochód)', '0' => 'Bez odliczenia'];

    public function __construct(private readonly Db $db)
    {
    }

    /** Domyślny rejestr dla rodzaju dokumentu. */
    public static function registerFor(string $docType): string
    {
        return in_array($docType, ['FZ'], true) ? 'Z' : 'S';
    }

    /** VAT od netto według stawki, w groszach (zaokrąglenie do grosza, połówki w górę). */
    public static function vatFor(int $netGr, string $rate): int
    {
        $pct = self::RATES[$rate][1] ?? null;
        if ($pct === null || $pct === 0) {
            return 0;
        }
        $v = abs($netGr) * $pct;
        $r = intdiv($v, 100) + ($v % 100 >= 50 ? 1 : 0);
        return $netGr < 0 ? -$r : $r;
    }

    /**
     * Pozycje VAT z formularza: puste wiersze pomijane, VAT liczony sam, gdy pole puste.
     * @return list<array<string,mixed>>
     */
    public static function parse(array $rows, string $docType, string $periodS, string $periodZ): array
    {
        $out = [];
        foreach (array_values($rows) as $i => $r) {
            if (!is_array($r)) {
                continue;
            }
            $net = Money::parse($r['net'] ?? '');
            $vatIn = Money::parse($r['vat'] ?? '');
            if ($net === null && $vatIn === null) {
                continue;
            }
            $n = $i + 1;
            $rate = (string) ($r['rate'] ?? '23');
            if (!isset(self::RATES[$rate])) {
                throw new \RuntimeException("VAT, pozycja {$n}: wybierz stawkę.");
            }
            $net ??= 0;
            $vat = $vatIn ?? self::vatFor($net, $rate);
            if (self::RATES[$rate][1] === null || self::RATES[$rate][1] === 0) {
                if ($vat !== 0) {
                    throw new \RuntimeException("VAT, pozycja {$n}: przy stawce " . self::RATES[$rate][0] . ' kwota VAT musi być 0.');
                }
            }
            if ($net === 0 && $vat === 0) {
                throw new \RuntimeException("VAT, pozycja {$n}: wpisz kwotę netto.");
            }
            $register = (string) ($r['register'] ?? self::registerFor($docType));
            if (!isset(self::REGISTERS[$register])) {
                $register = self::registerFor($docType);
            }
            $period = trim((string) ($r['period'] ?? ''));
            if ($period === '') {
                $period = $register === 'S' ? $periodS : $periodZ;
            }
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
                throw new \RuntimeException("VAT, pozycja {$n}: okres to miesiąc w formacie RRRR-MM.");
            }
            $kind = isset(self::PURCHASE_KINDS[$r['kind'] ?? '']) ? (string) $r['kind'] : 'other';
            $deduct = isset(self::DEDUCT[(string) ($r['deduct'] ?? '')]) ? (string) $r['deduct'] : '100';
            $out[] = ['register' => $register, 'period' => $period, 'rate' => $rate, 'net_gr' => $net, 'vat_gr' => $vat,
                'kind' => $register === 'Z' ? $kind : 'other', 'deduct' => $register === 'Z' ? $deduct : '100'];
        }
        return $out;
    }

    /** VAT do odliczenia z pozycji zakupu (100% / 50% / 0%). */
    public static function deductible(array $line): int
    {
        $vat = (int) $line['vat_gr'];
        return match ((string) $line['deduct']) {
            '0' => 0,
            '50' => intdiv($vat, 2) + ($vat % 2),
            default => $vat,
        };
    }

    /** @return list<array<string,mixed>> */
    public function forDoc(string $docId): array
    {
        return $this->db->all('SELECT * FROM ext_ksg_vat WHERE doc_id = ? ORDER BY position', [$docId]);
    }

    /** @param list<array<string,mixed>> $lines */
    public function replace(string $docId, array $lines): void
    {
        $this->db->run('DELETE FROM ext_ksg_vat WHERE doc_id = ?', [$docId]);
        foreach ($lines as $i => $l) {
            $this->db->insert('ext_ksg_vat', ['id' => Db::id(), 'doc_id' => $docId, 'position' => $i + 1, 'register' => $l['register'], 'period' => $l['period'],
                'rate' => $l['rate'], 'net_gr' => (int) $l['net_gr'], 'vat_gr' => (int) $l['vat_gr'], 'kind' => $l['kind'], 'deduct' => $l['deduct']]);
        }
    }

    /**
     * Rejestr VAT za miesiąc: dokumenty zaksięgowane z pozycjami tego rejestru, z sumami według stawek.
     * @return array{docs:list<array<string,mixed>>,by_rate:array<string,array{net:int,vat:int}>,net:int,vat:int,deductible:int}
     */
    public function register(string $register, string $period): array
    {
        $rows = $this->db->all("SELECT v.*, d.id AS doc_id, d.number, d.type, d.doc_date, d.op_date, d.posting_date, d.journal_no, d.counterparty,
                d.contractor_id, d.reversal_of, c.name AS c_name, c.nip AS c_nip, c.country AS c_country
            FROM ext_ksg_vat v JOIN ext_ksg_docs d ON d.id = v.doc_id LEFT JOIN ext_ksg_contractors c ON c.id = d.contractor_id
            WHERE v.register = ? AND v.period = ? AND d.status = 'posted' ORDER BY d.doc_date, d.journal_no, v.position", [$register, $period]);
        $docs = [];
        $byRate = [];
        $net = $vat = $ded = 0;
        foreach ($rows as $r) {
            $id = (string) $r['doc_id'];
            if (!isset($docs[$id])) {
                $docs[$id] = ['id' => $id, 'number' => $r['number'], 'type' => $r['type'], 'doc_date' => $r['doc_date'], 'op_date' => $r['op_date'],
                    'journal_no' => $r['journal_no'], 'name' => $r['c_name'] ?? $r['counterparty'], 'nip' => $r['c_nip'] ?? '',
                    'country' => $r['c_country'] ?? '', 'reversal' => $r['reversal_of'] !== null, 'lines' => [], 'net' => 0, 'vat' => 0, 'deductible' => 0];
            }
            $r['net_gr'] = (int) $r['net_gr'];
            $r['vat_gr'] = (int) $r['vat_gr'];
            $d = self::deductible($r);
            $docs[$id]['lines'][] = $r;
            $docs[$id]['net'] += $r['net_gr'];
            $docs[$id]['vat'] += $r['vat_gr'];
            $docs[$id]['deductible'] += $d;
            $byRate[$r['rate']]['net'] = ($byRate[$r['rate']]['net'] ?? 0) + $r['net_gr'];
            $byRate[$r['rate']]['vat'] = ($byRate[$r['rate']]['vat'] ?? 0) + $r['vat_gr'];
            $net += $r['net_gr'];
            $vat += $r['vat_gr'];
            $ded += $d;
        }
        uksort($byRate, static fn($a, $b): int => array_search((string) $a, array_map('strval', array_keys(self::RATES)), true)
            <=> array_search((string) $b, array_map('strval', array_keys(self::RATES)), true));
        return ['docs' => array_values($docs), 'by_rate' => $byRate, 'net' => $net, 'vat' => $vat, 'deductible' => $ded];
    }

    /**
     * Rozliczenie miesiąca: VAT należny minus naliczony do odliczenia. Dodatnie = do zapłaty, ujemne = nadwyżka.
     * Odwrotne obciążenie (oo) w zakupie wykazuje się też jako należny - to robi etap deklaracji, tu tylko suma.
     * @return array{due:int,input:int,balance:int}
     */
    public function settlement(string $period): array
    {
        $s = $this->register('S', $period);
        $z = $this->register('Z', $period);
        return ['due' => $s['vat'], 'input' => $z['deductible'], 'balance' => $s['vat'] - $z['deductible']];
    }
}
