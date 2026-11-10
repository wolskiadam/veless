<?php
declare(strict_types=1);

namespace PaseExt\Ksiegowosc;

/**
 * Wyciągi bankowe: import pliku (MT940 albo CSV z banku), podpowiedź kontrahenta, konta i faktury dla każdej
 * operacji, a potem zaksięgowanie całego wyciągu jednym dokumentem WB z rozliczeniem wskazanych faktur.
 *
 * Operacje już zaimportowane (ta sama data, kwota, tytuł i rachunek na tym samym koncie) są pomijane, więc
 * ten sam plik albo nakładające się okresy można wczytać bez dubli.
 */
final class Bank
{
    public const STATUSES = ['open' => 'Do zaksięgowania', 'booked' => 'Zaksięgowany'];
    private const MAX_LINES = 2000;

    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------------ parsowanie

    /**
     * Rozpoznaje format i zwraca operacje.
     * @return array{format:string,iban:string,opening:?int,closing:?int,lines:list<array{date:string,amount:int,title:string,name:string,iban:string}>}
     */
    public static function parse(string $content): array
    {
        $content = self::utf8($content);
        if (preg_match('/^:20:|\R:61:/m', $content) && str_contains($content, ':61:')) {
            return self::parseMt940($content);
        }
        return self::parseCsv($content);
    }

    /** Plik z banku bywa w Windows-1250 - zamiana na UTF-8, BOM usunięty. */
    public static function utf8(string $s): string
    {
        $s = (string) preg_replace('/^\xEF\xBB\xBF/', '', $s);
        if (!mb_check_encoding($s, 'UTF-8')) {
            $c = function_exists('iconv') ? @iconv('WINDOWS-1250', 'UTF-8//IGNORE', $s) : false;
            $s = $c !== false ? $c : mb_convert_encoding($s, 'UTF-8', 'ISO-8859-2');
        }
        return str_replace("\r\n", "\n", $s);
    }

    /** MT940 (format SWIFT, w polskich bankach z polem :86: w podpolach ~20..~63). */
    public static function parseMt940(string $s): array
    {
        $iban = '';
        $opening = $closing = null;
        $lines = [];
        // Złączenie linii kontynuacji (nie zaczynających się od :XX:).
        $fields = [];
        foreach (explode("\n", $s) as $row) {
            $row = rtrim($row);
            if (preg_match('/^:(\d{2}[A-Z]?):(.*)$/', $row, $m)) {
                $fields[] = [$m[1], $m[2]];
            } elseif ($fields !== [] && $row !== '' && $row !== '-' && !str_starts_with($row, '{')) {
                $fields[count($fields) - 1][1] .= "\n" . $row;
            }
        }
        $cur = null;
        foreach ($fields as [$tag, $val]) {
            if ($tag === '25') {
                $iban = self::cleanIban($val);
            } elseif ($tag === '60F' || $tag === '60M') {
                $opening ??= self::mtBalance($val);
            } elseif ($tag === '62F' || $tag === '62M') {
                $closing = self::mtBalance($val);
            } elseif ($tag === '61') {
                if ($cur !== null) {
                    $lines[] = $cur;
                }
                if (!preg_match('/^(\d{2})(\d{2})(\d{2})(\d{4})?(R?[CD])[A-Z]?([\d,]+)/', str_replace("\n", '', $val), $m)) {
                    throw new \RuntimeException('MT940: nie umiem odczytać operacji ' . mb_substr($val, 0, 40) . '.');
                }
                $amount = Money::parse($m[6]) ?? 0;
                $credit = $m[5] === 'C' || $m[5] === 'RD';
                $cur = ['date' => '20' . $m[1] . '-' . $m[2] . '-' . $m[3], 'amount' => $credit ? $amount : -$amount, 'title' => '', 'name' => '', 'iban' => ''];
            } elseif ($tag === '86' && $cur !== null) {
                $d = self::mt86($val);
                $cur['title'] = $d['title'];
                $cur['name'] = $d['name'];
                $cur['iban'] = $d['iban'];
            }
        }
        if ($cur !== null) {
            $lines[] = $cur;
        }
        return ['format' => 'mt940', 'iban' => $iban, 'opening' => $opening, 'closing' => $closing, 'lines' => $lines];
    }

    private static function mtBalance(string $v): ?int
    {
        if (!preg_match('/^([CD])\d{6}[A-Z]{3}([\d,]+)/', trim($v), $m)) {
            return null;
        }
        $a = Money::parse($m[2]) ?? 0;
        return $m[1] === 'D' ? -$a : $a;
    }

    /** Pole :86: z podpolami ~XX (tytuł 20-25 i 60-63, nazwa 27-29 / 32-33, rachunek 38 albo 30+31). @return array{title:string,name:string,iban:string} */
    public static function mt86(string $v): array
    {
        $v = str_replace("\n", '', $v);
        $sep = preg_match('/[~^]\d{2}/', $v, $m) ? $m[0][0] : '';
        if ($sep === '') {
            return ['title' => trim($v), 'name' => '', 'iban' => ''];
        }
        $sub = [];
        foreach (preg_split('/' . preg_quote($sep, '/') . '(?=\d{2})/', $v) as $i => $part) {
            if ($i === 0 || strlen($part) < 2) {
                continue;
            }
            $sub[substr($part, 0, 2)] = ($sub[substr($part, 0, 2)] ?? '') . substr($part, 2);
        }
        $join = static function (array $keys) use ($sub): string {
            $t = '';
            foreach ($keys as $k) {
                $t .= $sub[$k] ?? '';
            }
            return trim((string) preg_replace('/\s+/', ' ', $t));
        };
        $iban = self::cleanIban($sub['38'] ?? (($sub['30'] ?? '') . ($sub['31'] ?? '')));
        return ['title' => $join(['20', '21', '22', '23', '24', '25', '26', '60', '61', '62', '63']), 'name' => $join(['32', '33']) ?: $join(['27', '28', '29']), 'iban' => $iban];
    }

    /** CSV z banku: nagłówek rozpoznawany po nazwach kolumn (data, kwota, tytuł/opis, nadawca/odbiorca, rachunek). */
    public static function parseCsv(string $s): array
    {
        $rows = array_values(array_filter(explode("\n", $s), static fn(string $r): bool => trim($r) !== ''));
        $head = null;
        $delim = ';';
        foreach (array_slice($rows, 0, 60, true) as $i => $r) {
            $low = mb_strtolower($r);
            if (str_contains($low, 'data') && str_contains($low, 'kwota')) {
                $head = $i;
                $counts = [';' => substr_count($r, ';'), ',' => substr_count($r, ','), "\t" => substr_count($r, "\t")];
                arsort($counts);
                $delim = (string) array_key_first($counts);
                break;
            }
        }
        if ($head === null) {
            throw new \RuntimeException('Nie rozpoznaję pliku: to nie MT940, a w CSV nie ma nagłówka z kolumnami „Data” i „Kwota”.');
        }
        $cols = array_map(static fn(string $c): string => trim(mb_strtolower(trim($c)), " #\"'"), str_getcsv($rows[$head], $delim, '"', ''));
        $find = static function (array $names, array $avoid = []) use ($cols): ?int {
            foreach ($names as $n) {
                foreach ($cols as $i => $c) {
                    if (str_contains($c, $n) && !array_filter($avoid, static fn(string $a): bool => str_contains($c, $a))) {
                        return $i;
                    }
                }
            }
            return null;
        };
        $cDate = $find(['data operacji', 'data transakcji', 'data księgowania', 'data ksiegowania', 'data waluty', 'data']);
        $cAmount = $find(['kwota transakcji', 'kwota operacji', 'kwota'], ['saldo', 'blokad']);
        $cTitle = $find(['tytuł', 'tytul', 'opis', 'szczegóły', 'szczegoly']);
        $cName = $find(['nadawca', 'odbiorca', 'kontrahent', 'dane kontrahenta', 'nazwa']);
        $cIban = $find(['rachunek kontrahenta', 'konto kontrahenta', 'nr rachunku', 'numer rachunku', 'rachunek', 'nr konta']);
        $cBalance = $find(['saldo po']);
        if ($cDate === null || $cAmount === null) {
            throw new \RuntimeException('CSV: nie znalazłem kolumn daty i kwoty (są: ' . implode(', ', $cols) . ').');
        }
        $lines = [];
        $closing = null;
        foreach (array_slice($rows, $head + 1) as $r) {
            $c = str_getcsv($r, $delim, '"', '');
            $date = self::csvDate((string) ($c[$cDate] ?? ''));
            $amount = self::csvAmount((string) ($c[$cAmount] ?? ''));
            if ($date === null || $amount === null) {
                continue; // stopka, podsumowanie, pusta linia
            }
            $lines[] = ['date' => $date, 'amount' => $amount, 'title' => trim((string) preg_replace('/\s+/', ' ', (string) ($c[$cTitle] ?? ''))),
                'name' => trim((string) preg_replace('/\s+/', ' ', (string) ($c[$cName] ?? ''))), 'iban' => self::cleanIban((string) ($c[$cIban] ?? ''))];
            if ($cBalance !== null && ($b = self::csvAmount((string) ($c[$cBalance] ?? ''))) !== null && $closing === null) {
                $closing = $b; // banki zwykle podają najnowsze na górze
            }
        }
        return ['format' => 'csv', 'iban' => '', 'opening' => null, 'closing' => null, 'lines' => $lines];
    }

    public static function csvDate(string $v): ?string
    {
        $v = trim($v, " \"'");
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) || preg_match('/^(\d{4})\.(\d{2})\.(\d{2})/', $v, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
        }
        if (preg_match('/^(\d{2})[.\-\/](\d{2})[.\-\/](\d{4})/', $v, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? "{$m[3]}-{$m[2]}-{$m[1]}" : null;
        }
        return null;
    }

    public static function csvAmount(string $v): ?int
    {
        $v = (string) preg_replace('/[\s\x{00A0}]|PLN|zł|EUR|"/ui', '', $v);
        if ($v === '' || !preg_match('/\d/', $v)) {
            return null;
        }
        if (str_contains($v, ',') && str_contains($v, '.')) {
            $v = strrpos($v, ',') > strrpos($v, '.') ? str_replace('.', '', $v) : str_replace(',', '', $v);
        }
        $v = ltrim($v, '+');
        try {
            return Money::parse($v);
        } catch (\RuntimeException) {
            return null;
        }
    }

    public static function cleanIban(string $v): string
    {
        $v = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $v));
        return preg_match('/^([A-Z]{2})?\d{10,34}$/', $v) ? $v : '';
    }

    /** Ostatnie 26 cyfr (polski NRB) do porównań rachunków z prefiksem PL albo bez. */
    public static function nrb(string $iban): string
    {
        $d = (string) preg_replace('/\D/', '', $iban);
        return strlen($d) >= 26 ? substr($d, -26) : $d;
    }

    // ------------------------------------------------------------------ wyciągi

    public function statement(string $id): ?array
    {
        return $this->db->one('SELECT * FROM ext_ksg_bank_statements WHERE id = ?', [$id]);
    }

    /** @return list<array<string,mixed>> */
    public function statements(int $limit = 100): array
    {
        return $this->db->all('SELECT s.*, (SELECT COUNT(*) FROM ext_ksg_bank_lines l WHERE l.statement_id = s.id) AS n,
                (SELECT COALESCE(SUM(amount_gr), 0) FROM ext_ksg_bank_lines l WHERE l.statement_id = s.id AND l.amount_gr > 0) AS inflow,
                (SELECT COALESCE(SUM(amount_gr), 0) FROM ext_ksg_bank_lines l WHERE l.statement_id = s.id AND l.amount_gr < 0) AS outflow
            FROM ext_ksg_bank_statements s ORDER BY s.date_to DESC, s.created_at DESC LIMIT ' . max(1, $limit));
    }

    /** @return list<array<string,mixed>> */
    public function lines(string $statementId): array
    {
        return $this->db->all('SELECT * FROM ext_ksg_bank_lines WHERE statement_id = ? ORDER BY position', [$statementId]);
    }

    /**
     * Import pliku na konto bankowe (np. 130-01). Zwraca wyciąg z liczbą pominiętych dubli w 'skipped'.
     */
    public function import(string $account, string $fileName, string $content, string $user): array
    {
        $ledger = new Ledger($this->db);
        $account = trim((string) preg_split('/\s+/', trim($account))[0]);
        if (!preg_match('/^13\d/', $account)) {
            throw new \RuntimeException('Wybierz konto bankowe (13x) z planu kont.');
        }
        if (trim($content) === '') {
            throw new \RuntimeException('Wybierz plik wyciągu.');
        }
        $p = self::parse($content);
        if ($p['lines'] === []) {
            throw new \RuntimeException('W pliku nie ma żadnych operacji.');
        }
        if (count($p['lines']) > self::MAX_LINES) {
            throw new \RuntimeException('Za dużo operacji w jednym pliku (najwyżej ' . self::MAX_LINES . ').');
        }
        $new = [];
        $skipped = 0;
        $seen = [];
        foreach ($p['lines'] as $l) {
            $h = sha1($account . '|' . $l['date'] . '|' . $l['amount'] . '|' . self::nrb($l['iban']) . '|' . Settlements::norm($l['title']));
            $k = $h . '#' . ($seen[$h] = ($seen[$h] ?? 0) + 1);
            $hash = substr(sha1($k), 0, 40);
            if ($this->db->one('SELECT l.id FROM ext_ksg_bank_lines l JOIN ext_ksg_bank_statements s ON s.id = l.statement_id WHERE l.hash = ? AND s.account = ?', [$hash, $account]) !== null) {
                ++$skipped;
                continue;
            }
            $new[] = $l + ['hash' => $hash];
        }
        if ($new === []) {
            throw new \RuntimeException('Wszystkie operacje z tego pliku są już zaimportowane (' . $skipped . ').');
        }
        usort($new, static fn(array $a, array $b): int => $a['date'] <=> $b['date']);
        $dates = array_column($new, 'date');
        return $this->db->tx(function () use ($account, $fileName, $p, $new, $dates, $user, $skipped, $ledger): array {
            $id = Db::id();
            $this->db->insert('ext_ksg_bank_statements', ['id' => $id, 'account' => $account, 'iban' => $p['iban'], 'file_name' => mb_substr($fileName, 0, 200),
                'format' => $p['format'], 'date_from' => min($dates), 'date_to' => max($dates), 'opening_gr' => $p['opening'], 'closing_gr' => $p['closing'],
                'status' => 'open', 'doc_id' => null, 'created_by' => $user, 'created_at' => Db::now()]);
            foreach ($new as $i => $l) {
                $row = ['id' => Db::id(), 'statement_id' => $id, 'position' => $i + 1, 'op_date' => $l['date'], 'amount_gr' => $l['amount'],
                    'title' => mb_substr($l['title'], 0, 500), 'party_name' => mb_substr($l['name'], 0, 200), 'party_iban' => mb_substr($l['iban'], 0, 40),
                    'hash' => $l['hash'], 'contractor_id' => null, 'account' => '', 'match_entry_id' => null, 'status' => 'open'];
                $this->db->insert('ext_ksg_bank_lines', array_merge($row, $this->suggest($row)));
            }
            $this->db->log($user, 'bank.import', 'bank', $id, $fileName . ': ' . count($new) . ' operacji' . ($skipped > 0 ? ', ' . $skipped . ' pominiętych (już były)' : ''));
            return $this->statement($id) + ['skipped' => $skipped];
        });
    }

    /**
     * Podpowiedź dla operacji: kontrahent (rachunek, NIP w tytule, nazwa), konto rozrachunkowe i faktura do rozliczenia
     * (numer w tytule, a gdy go nie ma - jedyna otwarta pozycja na tę kwotę).
     * @return array{contractor_id:?string,account:string,match_entry_id:?string}
     */
    public function suggest(array $line): array
    {
        $ledger = new Ledger($this->db);
        $year = $ledger->yearFor((string) $line['op_date']);
        $cs = new Contractors($this->db);
        $contractor = null;
        $nrb = self::nrb((string) $line['party_iban']);
        $all = $this->db->all('SELECT * FROM ext_ksg_contractors WHERE active = 1');
        if (strlen($nrb) >= 10) {
            foreach ($all as $c) {
                if ($c['bank_account'] !== '' && self::nrb((string) $c['bank_account']) === $nrb) {
                    $contractor = $c;
                    break;
                }
            }
        }
        if ($contractor === null && preg_match_all('/(?<!\d)(\d{3}-?\d{3}-?\d{2}-?\d{2}|\d{10})(?!\d)/', (string) $line['title'], $m)) {
            foreach ($m[1] as $nip) {
                $nip = str_replace('-', '', $nip);
                foreach ($all as $c) {
                    if ($c['nip'] === $nip) {
                        $contractor = $c;
                        break 2;
                    }
                }
            }
        }
        $name = mb_strtolower((string) $line['party_name']);
        if ($contractor === null && mb_strlen($name) >= 3) {
            foreach ($all as $c) {
                $core = mb_strtolower(trim((string) preg_replace('/\b(sp\.?\s*z\s*o\.?\s*o\.?|s\.?a\.?|sp\.?\s*j\.?|spółka.*)$/iu', '', (string) $c['name'])));
                if (mb_strlen($core) >= 3 && (str_contains($name, $core) || str_contains($core, $name))) {
                    $contractor = $c;
                    break;
                }
            }
        }
        $amount = (int) $line['amount_gr'];
        $st = new Settlements($this->db);
        $title = Settlements::norm((string) $line['title']);
        $match = null;
        $account = '';
        $parents = $amount > 0 ? [Contractors::RECEIVABLE, Contractors::PAYABLE] : [Contractors::PAYABLE, Contractors::RECEIVABLE];
        if ($contractor !== null && $year !== null) {
            foreach ($parents as $parent) {
                $acc = $cs->settlementAccount($contractor, (string) $year['id'], $parent);
                if ($acc !== null && (Contractors::accountNumbers($contractor)[$parent] ?? null) === $acc) {
                    $account = $acc;
                    break;
                }
            }
        }
        // Faktura: wpłata (+) rozlicza dług na 200, wypłata (-) dług na 202 (albo zwrot nadpłaty).
        $candidates = array_filter($st->items(['open' => true, 'account' => $account]), static fn(array $i): bool => $i['kind'] === 'debt'
            && ($amount > 0 ? $i['parent'] === Contractors::RECEIVABLE : $i['parent'] === Contractors::PAYABLE));
        foreach ($candidates as $i) {
            $num = Settlements::norm((string) $i['number']);
            if (strlen($num) >= 3 && str_contains($title, $num)) {
                $match = $i;
                break;
            }
        }
        if ($match === null && $account !== '') {
            $same = array_values(array_filter($candidates, static fn(array $i): bool => $i['remaining'] === abs($amount)));
            $match = count($same) === 1 ? $same[0] : null;
        }
        if ($match !== null) {
            $account = (string) $match['account'];
            $contractor ??= $match['contractor'] !== null ? $cs->get((string) $match['contractor']['id']) : null;
        }
        return ['contractor_id' => $contractor['id'] ?? null, 'account' => $account, 'match_entry_id' => $match['entry_id'] ?? null];
    }

    /** Zapis zmian w operacjach (konto, kontrahent, faktura, pominięcie) przed zaksięgowaniem. @param array<string,array<string,string>> $in id operacji => pola */
    public function saveLines(string $statementId, array $in, string $user): void
    {
        $s = $this->statement($statementId) ?? throw new \RuntimeException('Nie ma takiego wyciągu.');
        if ($s['status'] !== 'open') {
            throw new \RuntimeException('Wyciąg jest już zaksięgowany.');
        }
        $ledger = new Ledger($this->db);
        $st = new Settlements($this->db);
        foreach ($this->lines($statementId) as $l) {
            if (!isset($in[$l['id']]) || !is_array($in[$l['id']])) {
                continue;
            }
            $x = $in[$l['id']];
            $acc = trim((string) preg_split('/\s+/', trim((string) ($x['account'] ?? '')))[0]);
            $year = $ledger->yearFor((string) $l['op_date']);
            if ($acc !== '' && ($year === null || $ledger->accountByNumber((string) $year['id'], $acc) === null)) {
                throw new \RuntimeException('Operacja ' . $l['position'] . ': w planie kont nie ma konta ' . $acc . '.');
            }
            $cid = trim((string) ($x['contractor_id'] ?? '')) ?: null;
            $match = trim((string) ($x['match'] ?? '')) ?: null;
            if ($match !== null) {
                $i = $st->item($match);
                if ($i === null || $i['kind'] !== 'debt') {
                    throw new \RuntimeException('Operacja ' . $l['position'] . ': nie ma takiej faktury do rozliczenia.');
                }
                if ($acc === '') {
                    $acc = (string) $i['account'];
                } elseif ($acc !== $i['account']) {
                    throw new \RuntimeException('Operacja ' . $l['position'] . ': faktura ' . $i['number'] . ' jest na koncie ' . $i['account'] . ', a wybrano ' . $acc . '.');
                }
            }
            $this->db->update('ext_ksg_bank_lines', (string) $l['id'], ['account' => $acc, 'contractor_id' => $cid, 'match_entry_id' => $match,
                'status' => !empty($x['skip']) ? 'skip' : ($l['status'] === 'skip' ? 'open' : $l['status'])]);
        }
        $this->db->log($user, 'bank.update', 'bank', $statementId, (string) $s['file_name']);
    }

    /**
     * Księguje wyciąg: dokument WB (konto bankowe ↔ konto przeciwstawne dla każdej operacji), potem rozliczenie
     * wskazanych faktur. Operacje pominięte nie trafiają do ksiąg.
     */
    public function book(string $statementId, string $user): array
    {
        $s = $this->statement($statementId) ?? throw new \RuntimeException('Nie ma takiego wyciągu.');
        if ($s['status'] !== 'open') {
            throw new \RuntimeException('Wyciąg jest już zaksięgowany.');
        }
        $lines = array_values(array_filter($this->lines($statementId), static fn(array $l): bool => $l['status'] === 'open'));
        if ($lines === []) {
            throw new \RuntimeException('Wszystkie operacje są pominięte - nie ma czego księgować.');
        }
        $missing = array_column(array_filter($lines, static fn(array $l): bool => $l['account'] === ''), 'position');
        if ($missing !== []) {
            throw new \RuntimeException('Wybierz konto dla operacji: ' . implode(', ', $missing) . ' (albo zaznacz „pomiń”).');
        }
        $ledger = new Ledger($this->db);
        $years = array_unique(array_map(static fn(array $l): string => (string) ($ledger->yearFor((string) $l['op_date'])['id'] ?? ''), $lines));
        if (count($years) !== 1 || $years[0] === '') {
            throw new \RuntimeException('Operacje wyciągu muszą leżeć w jednym roku obrotowym.');
        }
        $date = max(array_column($lines, 'op_date'));
        $contractors = [];
        foreach ($this->db->all('SELECT id, name FROM ext_ksg_contractors') as $c) {
            $contractors[$c['id']] = $c['name'];
        }
        $entries = [];
        foreach ($lines as $l) {
            $amt = (int) $l['amount_gr'];
            $desc = mb_substr(trim(($l['party_name'] !== '' ? $l['party_name'] : ($contractors[$l['contractor_id']] ?? '')) . ': ' . $l['title'], ' :'), 0, 255);
            $m = Money::input(abs($amt));
            $entries[] = ['account' => $s['account'], 'debit' => $amt > 0 ? $m : '', 'credit' => $amt < 0 ? $m : '', 'description' => $desc];
            $entries[] = ['account' => $l['account'], 'debit' => $amt < 0 ? $m : '', 'credit' => $amt > 0 ? $m : '', 'description' => $desc];
        }
        return $this->db->tx(function () use ($s, $lines, $entries, $date, $ledger, $user, $statementId): array {
            $doc = $ledger->saveDraft(null, ['type' => 'WB', 'posting_date' => $date, 'doc_date' => $date, 'op_date' => $date,
                'description' => 'Wyciąg bankowy ' . $s['account'] . ' ' . $s['date_from'] . ($s['date_to'] !== $s['date_from'] ? ' - ' . $s['date_to'] : ''),
                'lines' => $entries], $user, 'bank', $statementId);
            $doc = $ledger->post((string) $doc['id'], $user);
            $posted = $ledger->entries((string) $doc['id']);
            $st = new Settlements($this->db);
            $settled = 0;
            foreach ($lines as $i => $l) {
                $this->db->update('ext_ksg_bank_lines', (string) $l['id'], ['status' => 'booked']);
                $e = $posted[$i * 2 + 1] ?? null;
                if ($l['match_entry_id'] === null || $e === null) {
                    continue;
                }
                $debt = $st->item((string) $l['match_entry_id']);
                $pay = $st->item((string) $e['id']);
                if ($debt !== null && $pay !== null && $debt['account'] === $pay['account'] && $pay['kind'] === 'pay' && min($debt['remaining'], $pay['remaining']) > 0) {
                    $settled += $st->link((string) $debt['entry_id'], (string) $pay['entry_id'], min($debt['remaining'], $pay['remaining']), $user, 'bank') > 0 ? 1 : 0;
                }
            }
            $this->db->update('ext_ksg_bank_statements', $statementId, ['status' => 'booked', 'doc_id' => $doc['id']]);
            $this->db->log($user, 'bank.book', 'bank', $statementId, $doc['number'] . ', ' . count($lines) . ' operacji, rozliczonych faktur: ' . $settled);
            return $doc + ['settled' => $settled];
        });
    }

    public function delete(string $statementId, string $user): void
    {
        $s = $this->statement($statementId) ?? throw new \RuntimeException('Nie ma takiego wyciągu.');
        if ($s['status'] !== 'open') {
            throw new \RuntimeException('Zaksięgowanego wyciągu nie da się usunąć - wystornuj dokument WB.');
        }
        $this->db->tx(function () use ($statementId, $s, $user): void {
            $this->db->run('DELETE FROM ext_ksg_bank_lines WHERE statement_id = ?', [$statementId]);
            $this->db->run('DELETE FROM ext_ksg_bank_statements WHERE id = ?', [$statementId]);
            $this->db->log($user, 'bank.delete', 'bank', $statementId, (string) $s['file_name']);
        });
    }
}
