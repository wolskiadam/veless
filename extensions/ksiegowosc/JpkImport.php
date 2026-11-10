<?php
declare(strict_types=1);

namespace PaseExt\Ksiegowosc;

/**
 * Import ksiąg z innego programu (np. wFirma) z plików urzędowych, bez kluczy API:
 * - JPK_KR: plan kont (zestawienie obrotów i sald), bilans otwarcia i wszystkie zapisy dziennika - księgi 1:1,
 * - JPK_V7 (M/K): kontrahenci z NIP-ami i pozycje VAT, przypinane do zaimportowanych dokumentów po numerze dowodu.
 *
 * Zestawienie obrotów i sald z pliku zostaje zapisane, więc można porównać, czy nasze księgi liczą to samo.
 * Rok obrotowy z importu można zaimportować ponownie (stare dokumenty z importu są wtedy zastępowane), ale
 * nie da się importować do roku z dokumentami wprowadzonymi ręcznie.
 */
final class JpkImport
{
    public const KINDS = ['jpk_kr' => 'JPK_KR (księgi)', 'jpk_v7' => 'JPK_V7 (VAT)'];
    private const MAX_BYTES = 60 * 1024 * 1024;

    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------------ XML

    private static function dom(string $xml): \DOMXPath
    {
        if (strlen($xml) > self::MAX_BYTES) {
            throw new \RuntimeException('Plik jest za duży (najwyżej 60 MB).');
        }
        $xml = (string) preg_replace('/^\xEF\xBB\xBF/', '', $xml);
        if (preg_match('/<!DOCTYPE/i', substr($xml, 0, 2000))) {
            throw new \RuntimeException('Plik zawiera deklarację DOCTYPE - to nie jest plik JPK.');
        }
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            throw new \RuntimeException('To nie jest poprawny plik XML.');
        }
        return new \DOMXPath($doc);
    }

    /** Elementy po nazwie bez względu na przestrzeń nazw (różne wersje schematu). @return list<\DOMElement> */
    private static function all(\DOMXPath $x, string $name, ?\DOMNode $ctx = null): array
    {
        $out = [];
        foreach ($x->query(($ctx !== null ? './/' : '//') . '*[local-name()="' . $name . '"]', $ctx) ?: [] as $n) {
            if ($n instanceof \DOMElement) {
                $out[] = $n;
            }
        }
        return $out;
    }

    /** Pola dziecięce elementu: nazwa lokalna => tekst. @return array<string,string> */
    private static function fields(\DOMElement $e): array
    {
        $f = [];
        foreach ($e->childNodes as $c) {
            if ($c instanceof \DOMElement) {
                $f[$c->localName] = trim($c->textContent);
            }
        }
        return $f;
    }

    private static function first(\DOMXPath $x, string $name): string
    {
        $n = self::all($x, $name);
        return $n !== [] ? trim($n[0]->textContent) : '';
    }

    /** Kwota z JPK (kropka dziesiętna) w groszach. */
    public static function gr(string $v): int
    {
        $v = str_replace([' ', ','], ['', '.'], trim($v));
        if ($v === '') {
            return 0;
        }
        if (!preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $v, $m)) {
            throw new \RuntimeException('Niepoprawna kwota w pliku: ' . $v);
        }
        $frac = substr(($m[3] ?? '') . '00', 0, 2);
        $gr = (int) $m[2] * 100 + (int) $frac;
        if (isset($m[3]) && strlen($m[3]) > 2 && (int) $m[3][2] >= 5) {
            ++$gr;
        }
        return $m[1] === '-' ? -$gr : $gr;
    }

    private static function account(string $v): string
    {
        $v = trim($v);
        return strtolower($v) === 'null' ? '' : mb_substr($v, 0, 30);
    }

    // ------------------------------------------------------------------ JPK_KR

    /**
     * Odczyt JPK_KR: okres, NIP, zestawienie obrotów i sald, zapisy dziennika z dekretami.
     * @return array{from:string,to:string,nip:string,zois:list<array<string,mixed>>,journal:list<array<string,mixed>>,ctrl:array<string,int>}
     */
    public static function parseKr(string $xml): array
    {
        $x = self::dom($xml);
        $code = self::first($x, 'KodFormularza');
        if ($code !== '' && stripos($code, 'JPK_KR') === false) {
            throw new \RuntimeException('To plik ' . $code . ', a potrzebny jest JPK_KR.');
        }
        $from = self::first($x, 'DataOd');
        $to = self::first($x, 'DataDo');
        if (!Ledger::validDate($from) || !Ledger::validDate($to)) {
            throw new \RuntimeException('W pliku nie ma okresu (DataOd, DataDo) - to nie jest JPK_KR.');
        }
        $zois = [];
        foreach (self::all($x, 'ZOiS') as $e) {
            $f = self::fields($e);
            $n = self::account($f['KodKonta'] ?? '');
            if ($n === '') {
                continue;
            }
            $zois[] = ['number' => $n, 'name' => mb_substr($f['OpisKonta'] ?? $n, 0, 200), 'type' => mb_strtolower($f['TypKonta'] ?? ''),
                'bo_d' => self::gr($f['BilansOtwarciaWinien'] ?? ''), 'bo_c' => self::gr($f['BilansOtwarciaMa'] ?? ''),
                'to_d' => self::gr($f['ObrotyWinienNarast'] ?? $f['ObrotyWinien'] ?? ''), 'to_c' => self::gr($f['ObrotyMaNarast'] ?? $f['ObrotyMa'] ?? ''),
                's_d' => self::gr($f['SaldoWinien'] ?? ''), 's_c' => self::gr($f['SaldoMa'] ?? '')];
        }
        $entries = [];
        foreach (self::all($x, 'KontoZapis') as $e) {
            $f = self::fields($e);
            $nr = $f['NrZapisu'] ?? '';
            foreach ([['KodKontaWinien', 'KwotaWinien', 'OpisZapisuWinien', 'd'], ['KodKontaMa', 'KwotaMa', 'OpisZapisuMa', 'c']] as [$acc, $amt, $desc, $side]) {
                $a = self::account($f[$acc] ?? '');
                $g = self::gr($f[$amt] ?? '');
                if ($a === '' || $g === 0) {
                    continue;
                }
                $entries[$nr][] = ['account' => $a, 'side' => $side, 'gr' => $g, 'description' => mb_substr($f[$desc] ?? '', 0, 255)];
            }
        }
        $journal = [];
        foreach (self::all($x, 'Dziennik') as $e) {
            $f = self::fields($e);
            $nr = $f['NrZapisuDziennika'] ?? '';
            $posting = $f['DataKsiegowania'] ?? ($f['DataOperacji'] ?? '');
            $journal[] = ['lp' => (int) ($f['LpZapisuDziennika'] ?? 0), 'nr' => $nr, 'number' => mb_substr(($f['NrDowoduKsiegowego'] ?? '') ?: $nr, 0, 60),
                'kind' => $f['RodzajDowodu'] ?? '', 'journal' => $f['OpisDziennika'] ?? '', 'description' => mb_substr(($f['OpisOperacji'] ?? '') ?: ($f['RodzajDowodu'] ?? 'Zapis'), 0, 255),
                'op_date' => $f['DataOperacji'] ?? $posting, 'doc_date' => $f['DataDowodu'] ?? $posting, 'posting_date' => $posting,
                'amount' => self::gr($f['DziennikKwotaOperacji'] ?? ''), 'lines' => $entries[$nr] ?? []];
            unset($entries[$nr]);
        }
        usort($journal, static fn(array $a, array $b): int => [$a['lp'], $a['posting_date']] <=> [$b['lp'], $b['posting_date']]);
        // Zapisy na kontach bez pozycji dziennika (spotykane przy innym numerowaniu) - osobne dokumenty, żeby nic nie zginęło.
        foreach ($entries as $nr => $lines) {
            $journal[] = ['lp' => PHP_INT_MAX, 'nr' => (string) $nr, 'number' => mb_substr((string) $nr, 0, 60), 'kind' => '', 'journal' => '',
                'description' => 'Zapis bez pozycji dziennika ' . $nr, 'op_date' => $to, 'doc_date' => $to, 'posting_date' => $to, 'amount' => 0, 'lines' => $lines];
        }
        return ['from' => $from, 'to' => $to, 'nip' => self::first($x, 'NIP'), 'zois' => $zois, 'journal' => $journal,
            'ctrl' => ['rows' => (int) self::first($x, 'LiczbaWierszyDziennika'), 'sum' => self::gr(self::first($x, 'SumaKwotOperacji'))]];
    }

    /** Rodzaj dokumentu u nas na podstawie rodzaju i numeru dowodu z innego programu. */
    public static function docType(string $kind, string $number): string
    {
        $t = mb_strtoupper(trim($kind . ' ' . $number));
        return match (true) {
            (bool) preg_match('/^BO\b|BILANS OTWARCIA/u', $t) => 'BO',
            (bool) preg_match('/^(FS|FV|FA)\b|SPRZEDA/u', $t) => 'FS',
            (bool) preg_match('/^(FZ|FK|RZ)\b|ZAKUP|KOSZT|WYDAT/u', $t) => 'FZ',
            (bool) preg_match('/^WB\b|BANK|WYCI[AĄ]G/u', $t) => 'WB',
            (bool) preg_match('/^RK\b|KASA|KASOW/u', $t) => 'RK',
            (bool) preg_match('/^LP\b|P[LŁ]AC|WYNAGRODZ/u', $t) => 'LP',
            default => 'PK',
        };
    }

    /** Rodzaj konta z typu z JPK i numeru (zespół 4 i 7 wynikowe, 0 pozabilansowe… tylko gdy typ nic nie mówi). */
    private static function accountKind(string $type, string $number): string
    {
        if (str_contains($type, 'wynik')) {
            return 'W';
        }
        if (str_contains($type, 'pozabil')) {
            return 'PB';
        }
        if (str_contains($type, 'bilans')) {
            return 'AP';
        }
        return match ($number[0] ?? '') {
            '4', '5', '7' => 'W',
            default => 'AP',
        };
    }

    /**
     * Import JPK_KR: rok obrotowy (nowy albo z poprzedniego importu), plan kont, bilans otwarcia i dziennik jako
     * zaksięgowane dokumenty w kolejności pozycji dziennika.
     * @return array{year_id:string,accounts:int,docs:int,entries:int,unbalanced:list<string>,warnings:list<string>,wfirma:array{matched:int,contractors:int,unmatched:list<string>}}
     */
    public function importKr(string $fileName, string $xml, string $user): array
    {
        $p = self::parseKr($xml);
        $ledger = new Ledger($this->db);
        $year = $this->db->one('SELECT * FROM ext_ksg_years WHERE starts_on <= ? AND ends_on >= ?', [$p['to'], $p['from']]);
        if ($year !== null && ($year['starts_on'] !== $p['from'] || $year['ends_on'] !== $p['to'])) {
            throw new \RuntimeException('Okres pliku (' . $p['from'] . ' - ' . $p['to'] . ') nachodzi na rok obrotowy ' . $year['name'] . ' o innych datach.');
        }
        if ($year !== null) {
            $manual = (int) ($this->db->one("SELECT COUNT(*) AS n FROM ext_ksg_docs WHERE year_id = ? AND source NOT IN ('jpk_kr', 'storno')", [$year['id']])['n'] ?? 0);
            if ($manual > 0) {
                throw new \RuntimeException('W roku ' . $year['name'] . ' są dokumenty wprowadzone w CRM (' . $manual . '). Import ksiąg działa tylko na rok bez nich.');
            }
        }
        $warnings = [];
        if ($p['ctrl']['rows'] > 0 && $p['ctrl']['rows'] !== count(array_filter($p['journal'], static fn(array $j): bool => $j['lp'] !== PHP_INT_MAX))) {
            $warnings[] = 'Plik deklaruje ' . $p['ctrl']['rows'] . ' pozycji dziennika, a odczytano ' . count($p['journal']) . '.';
        }
        $r = $this->db->tx(function () use ($p, $year, $ledger, $fileName, $user, $warnings): array {
            if ($year === null) {
                $year = $ledger->createYear(substr($p['from'], 0, 4), $p['from'], $p['to'], 'empty', $user);
            } else {
                $this->clearYear((string) $year['id']);
            }
            $yearId = (string) $year['id'];
            // Plan kont: konta z zestawienia, z dekretów i brakujące konta nadrzędne.
            $names = [];
            foreach ($p['zois'] as $z) {
                $names[$z['number']] = ['name' => $z['name'], 'kind' => self::accountKind($z['type'], $z['number'])];
            }
            foreach ($p['journal'] as $j) {
                foreach ($j['lines'] as $l) {
                    $names[$l['account']] ??= ['name' => $l['account'], 'kind' => self::accountKind('', $l['account'])];
                }
            }
            foreach ($names as $n => $a) {
                $n = (string) $n;
                while (($pos = strrpos($n, '-')) !== false) {
                    $n = substr($n, 0, $pos);
                    $names[$n] ??= ['name' => $n, 'kind' => $a['kind']];
                }
            }
            $ids = [];
            foreach ($this->db->all('SELECT id, number FROM ext_ksg_accounts WHERE year_id = ?', [$yearId]) as $a) {
                $ids[(string) $a['number']] = (string) $a['id'];
            }
            foreach ($names as $n => $a) {
                $n = (string) $n;
                if (isset($ids[$n])) {
                    $this->db->update('ext_ksg_accounts', $ids[$n], ['name' => $a['name'], 'kind' => $a['kind'], 'updated_at' => Db::now()]);
                    continue;
                }
                $ids[$n] = Db::id();
                $this->db->insert('ext_ksg_accounts', ['id' => $ids[$n], 'year_id' => $yearId, 'number' => $n, 'name' => $a['name'], 'kind' => $a['kind'],
                    'active' => 1, 'notes' => 'Import ' . $fileName, 'created_at' => Db::now(), 'updated_at' => Db::now()]);
            }
            $leaf = static function (string $n) use ($names): bool {
                foreach (array_keys($names) as $o) {
                    if (str_starts_with((string) $o, $n . '-')) {
                        return false;
                    }
                }
                return true;
            };
            // Bilans otwarcia z zestawienia (konta bez analityki), o ile dziennik nie ma własnego zapisu BO.
            $hasBo = array_filter($p['journal'], static fn(array $j): bool => self::docType($j['kind'], $j['number']) === 'BO') !== [];
            $journal = $p['journal'];
            if (!$hasBo) {
                $bo = [];
                foreach ($p['zois'] as $z) {
                    if (($z['bo_d'] !== 0 || $z['bo_c'] !== 0) && $leaf($z['number'])) {
                        if ($z['bo_d'] !== 0) {
                            $bo[] = ['account' => $z['number'], 'side' => 'd', 'gr' => $z['bo_d'], 'description' => 'Bilans otwarcia'];
                        }
                        if ($z['bo_c'] !== 0) {
                            $bo[] = ['account' => $z['number'], 'side' => 'c', 'gr' => $z['bo_c'], 'description' => 'Bilans otwarcia'];
                        }
                    }
                }
                if ($bo !== []) {
                    array_unshift($journal, ['lp' => 0, 'nr' => 'BO', 'number' => 'BO/' . substr($p['from'], 0, 4), 'kind' => 'BO', 'journal' => '',
                        'description' => 'Bilans otwarcia (z JPK_KR)', 'op_date' => $p['from'], 'doc_date' => $p['from'], 'posting_date' => $p['from'],
                        'amount' => 0, 'lines' => $bo]);
                }
            }
            $no = 0;
            $entries = 0;
            $unbalanced = [];
            foreach ($journal as $j) {
                if ($j['lines'] === []) {
                    $warnings[] = 'Pozycja ' . $j['nr'] . ' (' . $j['number'] . ') nie ma zapisów na kontach - pominięta.';
                    continue;
                }
                $posting = Ledger::validDate((string) $j['posting_date']) ? (string) $j['posting_date'] : $p['to'];
                $d = $c = 0;
                foreach ($j['lines'] as $l) {
                    $l['side'] === 'd' ? $d += $l['gr'] : $c += $l['gr'];
                }
                if ($d !== $c) {
                    $unbalanced[] = $j['number'] . ' (Wn ' . Money::format($d) . ', Ma ' . Money::format($c) . ')';
                }
                $docId = Db::id();
                $this->db->insert('ext_ksg_docs', ['id' => $docId, 'year_id' => $yearId, 'type' => self::docType($j['kind'], $j['number']), 'number' => $j['number'],
                    'journal_no' => ++$no, 'doc_date' => Ledger::validDate((string) $j['doc_date']) ? $j['doc_date'] : $posting,
                    'op_date' => Ledger::validDate((string) $j['op_date']) ? $j['op_date'] : $posting, 'posting_date' => $posting,
                    'description' => $j['description'], 'counterparty' => '', 'contractor_id' => null, 'due_date' => null, 'status' => 'posted', 'total_gr' => $d,
                    'reversal_of' => null, 'reversed_by' => null, 'source' => 'jpk_kr', 'source_ref' => mb_substr((string) $j['nr'], 0, 100),
                    'created_by' => $user, 'created_at' => Db::now(), 'posted_by' => $user, 'posted_at' => Db::now(), 'updated_at' => Db::now()]);
                foreach ($j['lines'] as $i => $l) {
                    $this->db->insert('ext_ksg_entries', ['id' => Db::id(), 'doc_id' => $docId, 'position' => $i + 1, 'account_id' => $ids[$l['account']],
                        'debit_gr' => $l['side'] === 'd' ? $l['gr'] : 0, 'credit_gr' => $l['side'] === 'c' ? $l['gr'] : 0, 'description' => $l['description']]);
                    ++$entries;
                }
            }
            $this->saveSnapshot($yearId, 'jpk_kr', $fileName, ['from' => $p['from'], 'to' => $p['to'], 'nip' => $p['nip'], 'zois' => $p['zois']], $user);
            $this->db->log($user, 'import.jpk_kr', 'year', $yearId, $fileName . ': ' . count($names) . ' kont, ' . $no . ' dokumentów, ' . $entries . ' zapisów'
                . ($unbalanced !== [] ? ', niezbilansowanych: ' . count($unbalanced) : ''));
            return ['year_id' => $yearId, 'accounts' => count($names), 'docs' => $no, 'entries' => $entries, 'unbalanced' => $unbalanced, 'warnings' => $warnings];
        });
        // Faktury i wydatki pobrane wcześniej z wFirma przypinają się od razu do nowych dokumentów.
        $r['wfirma'] = (new Wfirma($this->db))->attach([], $user);
        return $r;
    }

    /** Usuwa z roku wszystko z poprzedniego importu (dokumenty, zapisy, VAT, rozliczenia). */
    private function clearYear(string $yearId): void
    {
        $docs = array_column($this->db->all('SELECT id FROM ext_ksg_docs WHERE year_id = ?', [$yearId]), 'id');
        foreach (array_chunk($docs, 200) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $this->db->run("DELETE FROM ext_ksg_settle WHERE debt_entry_id IN (SELECT id FROM ext_ksg_entries WHERE doc_id IN ($in))
                OR pay_entry_id IN (SELECT id FROM ext_ksg_entries WHERE doc_id IN ($in))", array_merge($chunk, $chunk));
            $this->db->run("DELETE FROM ext_ksg_entries WHERE doc_id IN ($in)", $chunk);
            $this->db->run("DELETE FROM ext_ksg_vat WHERE doc_id IN ($in)", $chunk);
            $this->db->run("DELETE FROM ext_ksg_docs WHERE id IN ($in)", $chunk);
        }
    }

    private function saveSnapshot(string $yearId, string $kind, string $fileName, array $data, string $user): void
    {
        $this->db->run('DELETE FROM ext_ksg_imports WHERE year_id = ? AND kind = ? AND period = ?', [$yearId, $kind, (string) ($data['period'] ?? '')]);
        $this->db->insert('ext_ksg_imports', ['id' => Db::id(), 'year_id' => $yearId, 'kind' => $kind, 'period' => (string) ($data['period'] ?? ''),
            'file_name' => mb_substr($fileName, 0, 200), 'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'created_by' => $user, 'created_at' => Db::now()]);
    }

    /** @return list<array<string,mixed>> */
    public function imports(): array
    {
        return $this->db->all('SELECT i.id, i.year_id, i.kind, i.period, i.file_name, i.created_by, i.created_at, y.name AS year_name
            FROM ext_ksg_imports i LEFT JOIN ext_ksg_years y ON y.id = i.year_id ORDER BY i.created_at DESC');
    }

    /**
     * Porównanie zestawienia obrotów i sald z pliku z naszymi księgami (bilans otwarcia, obroty narastająco bez BO,
     * saldo netto Wn - Ma). Konta z analityką sumują konta podrzędne, tak jak w zestawieniu.
     * @return array{file:string,rows:list<array<string,mixed>>,diff:int}|null
     */
    public function compare(string $yearId): ?array
    {
        $snap = $this->db->one("SELECT * FROM ext_ksg_imports WHERE year_id = ? AND kind = 'jpk_kr' ORDER BY created_at DESC LIMIT 1", [$yearId]);
        if ($snap === null) {
            return null;
        }
        $data = json_decode((string) $snap['data'], true);
        $sums = [];
        foreach ($this->db->all("SELECT a.number, d.type, SUM(e.debit_gr) AS d, SUM(e.credit_gr) AS c FROM ext_ksg_entries e
                JOIN ext_ksg_docs d ON d.id = e.doc_id JOIN ext_ksg_accounts a ON a.id = e.account_id
                WHERE d.year_id = ? AND d.status = 'posted' GROUP BY a.number, d.type", [$yearId]) as $r) {
            $k = (string) $r['number'];
            $sums[$k] ??= ['bo_d' => 0, 'bo_c' => 0, 'to_d' => 0, 'to_c' => 0];
            $bo = $r['type'] === 'BO';
            $sums[$k][$bo ? 'bo_d' : 'to_d'] += (int) $r['d'];
            $sums[$k][$bo ? 'bo_c' : 'to_c'] += (int) $r['c'];
        }
        $rows = [];
        $diff = 0;
        foreach ((array) ($data['zois'] ?? []) as $z) {
            $n = (string) $z['number'];
            $o = ['bo_d' => 0, 'bo_c' => 0, 'to_d' => 0, 'to_c' => 0];
            foreach ($sums as $k => $s) {
                $k = (string) $k;
                if ($k === $n || str_starts_with($k, $n . '-')) {
                    foreach ($o as $f => $_) {
                        $o[$f] += $s[$f];
                    }
                }
            }
            $fileSaldo = (int) $z['s_d'] - (int) $z['s_c'];
            $ourSaldo = $o['bo_d'] + $o['to_d'] - $o['bo_c'] - $o['to_c'];
            $ok = $o['bo_d'] - $o['bo_c'] === (int) $z['bo_d'] - (int) $z['bo_c'] && $o['to_d'] === (int) $z['to_d'] && $o['to_c'] === (int) $z['to_c'] && $ourSaldo === $fileSaldo;
            $diff += $ok ? 0 : 1;
            $rows[] = ['number' => $n, 'name' => $z['name'], 'file' => $z, 'ours' => $o + ['saldo' => $ourSaldo], 'file_saldo' => $fileSaldo, 'ok' => $ok];
        }
        return ['file' => (string) $snap['file_name'], 'created_at' => (string) $snap['created_at'], 'rows' => $rows, 'diff' => $diff];
    }

    // ------------------------------------------------------------------ JPK_V7

    /** Stawki z pól JPK_V7: pole netto => [stawka, pole VAT albo null]. */
    private const V7_SALES = [
        'K_10' => ['zw', null], 'K_11' => ['np', null], 'K_12' => ['np', null], 'K_13' => ['0', null], 'K_14' => ['0', null],
        'K_15' => ['5', 'K_16'], 'K_17' => ['8', 'K_18'], 'K_19' => ['23', 'K_20'], 'K_21' => ['0', null], 'K_22' => ['0', null],
        'K_23' => ['oo', 'K_24'], 'K_25' => ['oo', 'K_26'], 'K_27' => ['oo', 'K_28'], 'K_29' => ['oo', 'K_30'], 'K_31' => ['oo', 'K_32'],
        'K_34' => ['oo', 'K_35'],
    ];
    private const V7_PURCHASE = ['K_40' => ['fixed', 'K_41'], 'K_42' => ['other', 'K_43']];

    /**
     * Odczyt JPK_V7M/K: okres i wiersze sprzedaży i zakupu.
     * @return array{period:string,sales:list<array<string,mixed>>,purchases:list<array<string,mixed>>}
     */
    public static function parseV7(string $xml): array
    {
        $x = self::dom($xml);
        $code = self::first($x, 'KodFormularza');
        if ($code !== '' && stripos($code, 'JPK_V7') === false && stripos($code, 'JPK_VAT') === false) {
            throw new \RuntimeException('To plik ' . $code . ', a potrzebny jest JPK_V7.');
        }
        $y = self::first($x, 'Rok');
        $m = self::first($x, 'Miesiac');
        if (!preg_match('/^\d{4}$/', $y) || !preg_match('/^\d{1,2}$/', $m)) {
            throw new \RuntimeException('W pliku nie ma roku i miesiąca - to nie jest JPK_V7.');
        }
        $period = $y . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
        $sales = [];
        foreach (self::all($x, 'SprzedazWiersz') as $e) {
            $f = self::fields($e);
            $lines = [];
            foreach (self::V7_SALES as $net => [$rate, $vat]) {
                $n = self::gr($f[$net] ?? '');
                $v = $vat !== null ? self::gr($f[$vat] ?? '') : 0;
                if ($n !== 0 || $v !== 0) {
                    $lines[] = ['register' => 'S', 'period' => $period, 'rate' => $rate, 'net_gr' => $n, 'vat_gr' => $v, 'kind' => 'other', 'deduct' => '100'];
                }
            }
            $sales[] = ['nip' => $f['NrKontrahenta'] ?? '', 'country' => $f['KodKrajuNadaniaTIN'] ?? '', 'name' => $f['NazwaKontrahenta'] ?? '',
                'number' => $f['DowodSprzedazy'] ?? '', 'date' => $f['DataWystawienia'] ?? '', 'lines' => $lines];
        }
        $purchases = [];
        foreach (self::all($x, 'ZakupWiersz') as $e) {
            $f = self::fields($e);
            $lines = [];
            foreach (self::V7_PURCHASE as $net => [$kind, $vat]) {
                $n = self::gr($f[$net] ?? '');
                $v = self::gr($f[$vat] ?? '');
                if ($n !== 0 || $v !== 0) {
                    $lines[] = ['register' => 'Z', 'period' => $period, 'rate' => self::rateOf($n, $v), 'net_gr' => $n, 'vat_gr' => $v, 'kind' => $kind, 'deduct' => '100'];
                }
            }
            $purchases[] = ['nip' => $f['NrDostawcy'] ?? '', 'country' => $f['KodKrajuNadaniaTIN'] ?? '', 'name' => $f['NazwaDostawcy'] ?? '',
                'number' => $f['DowodZakupu'] ?? '', 'date' => $f['DataZakupu'] ?? '', 'lines' => $lines];
        }
        return ['period' => $period, 'sales' => $sales, 'purchases' => $purchases];
    }

    /** Stawka zakupu z proporcji VAT do netto (JPK_V7 nie podaje stawki przy zakupach). */
    public static function rateOf(int $net, int $vat): string
    {
        if ($net === 0) {
            return '23';
        }
        $pct = abs($vat) * 100 / abs($net);
        $best = '23';
        $dist = PHP_FLOAT_MAX;
        foreach (['23' => 23, '8' => 8, '5' => 5, '0' => 0] as $k => $v) {
            if (abs($pct - $v) < $dist) {
                $dist = abs($pct - $v);
                $best = (string) $k;
            }
        }
        return $best;
    }

    /**
     * Import JPK_V7: kontrahenci (po NIP-ie, bez zakładania kont) i pozycje VAT przypięte do zaimportowanych
     * dokumentów po numerze dowodu. Wiersze bez pasującego dokumentu są zwracane do sprawdzenia.
     * @return array{period:string,matched:int,contractors:int,unmatched:list<string>}
     */
    public function importV7(string $fileName, string $xml, string $user): array
    {
        $p = self::parseV7($xml);
        $ledger = new Ledger($this->db);
        $year = $ledger->yearFor($p['period'] . '-01') ?? throw new \RuntimeException('Najpierw zaimportuj JPK_KR za rok, do którego należy ' . $p['period'] . '.');
        return $this->db->tx(function () use ($p, $year, $fileName, $user): array {
            $docs = [];
            foreach ($this->db->all("SELECT id, number, type FROM ext_ksg_docs WHERE year_id = ? AND status = 'posted' AND reversal_of IS NULL", [$year['id']]) as $d) {
                $docs[Settlements::norm((string) $d['number'])][] = $d;
            }
            $vat = new Vat($this->db);
            $made = 0;
            $matched = 0;
            $unmatched = [];
            $byDoc = [];
            foreach (array_merge($p['sales'], $p['purchases']) as $r) {
                $key = Settlements::norm((string) $r['number']);
                $cands = $docs[$key] ?? [];
                $reg = ($r['lines'][0]['register'] ?? 'S');
                $pick = null;
                foreach ($cands as $d) {
                    if (($reg === 'S' && $d['type'] === 'FS') || ($reg === 'Z' && $d['type'] === 'FZ')) {
                        $pick = $d;
                        break;
                    }
                }
                $pick ??= $cands[0] ?? null;
                if ($pick === null || $key === '') {
                    $unmatched[] = ($reg === 'S' ? 'sprzedaż ' : 'zakup ') . $r['number'] . ' (' . $r['name'] . ')';
                    continue;
                }
                $cid = $this->contractorFor($r, $reg, $user, $made);
                $byDoc[$pick['id']]['lines'] = array_merge($byDoc[$pick['id']]['lines'] ?? [], $r['lines']);
                $byDoc[$pick['id']]['contractor'] = $cid;
                $byDoc[$pick['id']]['name'] = $r['name'];
                ++$matched;
            }
            foreach ($byDoc as $docId => $b) {
                // Ten sam okres w JPK zastępuje pozycje z poprzedniego importu tego okresu; inne okresy zostają.
                $keep = array_values(array_filter($vat->forDoc((string) $docId), static fn(array $v): bool => $v['period'] !== $p['period']));
                $vat->replace((string) $docId, array_merge($keep, $b['lines']));
                $this->db->update('ext_ksg_docs', (string) $docId, ['contractor_id' => $b['contractor'], 'counterparty' => mb_substr((string) $b['name'], 0, 200), 'updated_at' => Db::now()]);
            }
            $this->saveSnapshot((string) $year['id'], 'jpk_v7', $fileName, ['period' => $p['period'], 'sales' => count($p['sales']), 'purchases' => count($p['purchases']),
                'unmatched' => $unmatched], $user);
            $this->db->log($user, 'import.jpk_v7', 'year', (string) $year['id'], $fileName . ' (' . $p['period'] . '): przypiętych ' . $matched . ', nowych kontrahentów ' . $made
                . ($unmatched !== [] ? ', bez dokumentu ' . count($unmatched) : ''));
            return ['period' => $p['period'], 'matched' => $matched, 'contractors' => $made, 'unmatched' => $unmatched];
        });
    }

    /** Kontrahent z wiersza JPK (albo dokumentu z wFirma) po NIP-ie (albo nazwie, gdy NIP-u brak); nowy bez zakładania kont rozrachunkowych. */
    public function contractorFor(array $r, string $reg, string $user, int &$made, string $note = 'Z importu JPK_V7'): ?string
    {
        $country = strtoupper(trim((string) $r['country'])) ?: 'PL';
        $nip = Contractors::normalizeNip((string) $r['nip'], $country);
        if (in_array(strtoupper($nip), ['', 'BRAK'], true)) {
            $nip = '';
        }
        $name = trim((string) $r['name']);
        $found = $nip !== '' ? $this->db->one('SELECT id FROM ext_ksg_contractors WHERE nip = ?', [$nip])
            : ($name !== '' ? $this->db->one('SELECT id FROM ext_ksg_contractors WHERE name = ?', [mb_substr($name, 0, 200)]) : null);
        if ($found !== null) {
            return (string) $found['id'];
        }
        if ($name === '') {
            return null;
        }
        $cs = new Contractors($this->db);
        $in = ['name' => $name, 'nip' => $nip, 'country' => $country, 'kind' => $reg === 'S' ? 'customer' : 'supplier', 'notes' => $note];
        try {
            $c = $cs->save(null, $in, $user, false);
        } catch (\RuntimeException) {
            $c = $cs->save(null, ['nip' => ''] + $in, $user, false); // np. NIP z błędną sumą kontrolną w pliku
        }
        ++$made;
        return (string) $c['id'];
    }
}
