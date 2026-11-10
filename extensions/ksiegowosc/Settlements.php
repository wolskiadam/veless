<?php
declare(strict_types=1);

namespace PaseExt\Ksiegowosc;

/**
 * Rozrachunki z kontrahentami: rozliczanie pozycji na kontach 200 (należności) i 202 (zobowiązania).
 *
 * Każdy zaksięgowany zapis na koncie rozrachunkowym to pozycja. Na 200 zapis Wn (faktura sprzedaży) jest długiem,
 * a zapis Ma (wpłata) - zapłatą; na 202 odwrotnie. Storno ma kwotę z minusem, więc działa jak zapłata i znosi
 * oryginał. Rozliczenie (`ext_ksg_settle`) łączy dług z zapłatą na kwotę; jedna faktura może mieć kilka wpłat
 * i jedna wpłata może pokryć kilka faktur. Rozliczenia nie zmieniają ksiąg - salda kont liczą się z zapisów.
 */
final class Settlements
{
    /** Konto syntetyczne => znak długu (Wn - Ma): należności dodatni, zobowiązania ujemny. */
    public const PARENTS = [Contractors::RECEIVABLE => 1, Contractors::PAYABLE => -1];
    /** Inne konta należności spotykane w planach kont (wFirma: 201 - rozrachunki z odbiorcami); liczą się jak 200. */
    public const ALIASES = ['201' => Contractors::RECEIVABLE];
    public const LABELS = [Contractors::RECEIVABLE => 'Należności', Contractors::PAYABLE => 'Zobowiązania'];

    public function __construct(private readonly Db $db)
    {
    }

    public static function parentOf(string $number): ?string
    {
        foreach (array_merge(array_keys(self::PARENTS), array_keys(self::ALIASES)) as $p) {
            $p = (string) $p;
            if ($number === $p || str_starts_with($number, $p . '-')) {
                return self::ALIASES[$p] ?? $p;
            }
        }
        return null;
    }

    /**
     * Pozycje rozrachunkowe (tylko zaksięgowane) z kwotą rozliczoną i pozostałą.
     * Filtry: parent ('200'|'202'), account (numer konta), contractor (id), entry (id), open (tylko nierozliczone), as_of (dzień do liczenia zaległości).
     * @return list<array<string,mixed>>
     */
    public function items(array $f = []): array
    {
        $where = ["d.status = 'posted'"];
        $args = [];
        $parents = isset($f['parent']) && isset(self::PARENTS[$f['parent']]) ? [(string) $f['parent']] : array_map('strval', array_keys(self::PARENTS));
        $or = [];
        foreach (self::ALIASES as $alias => $to) {
            if (in_array($to, $parents, true)) {
                $parents[] = (string) $alias;
            }
        }
        foreach ($parents as $p) {
            $or[] = '(a.number = ? OR a.number LIKE ?)';
            array_push($args, $p, $p . '-%');
        }
        $where[] = '(' . implode(' OR ', $or) . ')';
        if (($f['account'] ?? '') !== '') {
            $where[] = 'a.number = ?';
            $args[] = (string) $f['account'];
        }
        if (($f['entry'] ?? '') !== '') {
            $where[] = 'e.id = ?';
            $args[] = (string) $f['entry'];
        }
        if (($f['doc'] ?? '') !== '') {
            $where[] = 'd.id = ?';
            $args[] = (string) $f['doc'];
        }
        $rows = $this->db->all('SELECT e.id AS entry_id, e.debit_gr, e.credit_gr, e.description AS entry_description, a.number AS account, a.name AS account_name,
                d.id AS doc_id, d.number, d.type, d.doc_date, d.posting_date, d.due_date, d.description, d.counterparty, d.contractor_id, d.reversal_of,
                (SELECT COALESCE(SUM(s.amount_gr), 0) FROM ext_ksg_settle s WHERE s.debt_entry_id = e.id OR s.pay_entry_id = e.id) AS settled
            FROM ext_ksg_entries e JOIN ext_ksg_docs d ON d.id = e.doc_id JOIN ext_ksg_accounts a ON a.id = e.account_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY a.number, d.doc_date, d.posting_date, d.journal_no, e.position', $args);
        $codes = [];
        $byId = [];
        foreach ($this->db->all('SELECT id, code, name FROM ext_ksg_contractors') as $c) {
            $codes[(string) $c['code']] = $c;
            $byId[(string) $c['id']] = $c;
        }
        $asOf = (string) ($f['as_of'] ?? date('Y-m-d'));
        $out = [];
        foreach ($rows as $r) {
            $parent = (string) self::parentOf((string) $r['account']);
            $signed = (int) $r['debit_gr'] - (int) $r['credit_gr'];
            if ($signed === 0) {
                continue;
            }
            $amount = abs($signed);
            $settled = (int) $r['settled'];
            $kind = $signed * self::PARENTS[$parent] > 0 ? 'debt' : 'pay';
            $code = str_contains((string) $r['account'], '-') ? substr((string) $r['account'], strpos((string) $r['account'], '-') + 1) : '';
            $c = $codes[$code] ?? ($r['contractor_id'] !== null ? ($byId[(string) $r['contractor_id']] ?? null) : null);
            if (($f['contractor'] ?? '') !== '' && ($c['id'] ?? null) !== $f['contractor'] && $r['contractor_id'] !== $f['contractor']) {
                continue;
            }
            $due = (string) ($r['due_date'] ?? '') !== '' ? (string) $r['due_date'] : (string) $r['doc_date'];
            $remaining = $amount - $settled;
            if (!empty($f['open']) && $remaining <= 0) {
                continue;
            }
            $late = $kind === 'debt' && $remaining > 0 && $due < $asOf ? (int) ((strtotime($asOf) - strtotime($due)) / 86400) : 0;
            $out[] = $r + ['parent' => $parent, 'kind' => $kind, 'amount' => $amount, 'settled' => $settled, 'remaining' => $remaining, 'due' => $due,
                'late_days' => $late, 'contractor' => $c, 'label' => trim((string) $r['entry_description']) !== '' ? (string) $r['entry_description'] : (string) $r['description']];
        }
        return $out;
    }

    public function item(string $entryId): ?array
    {
        return $this->items(['entry' => $entryId])[0] ?? null;
    }

    /**
     * Rozlicza dług z zapłatą na kwotę (domyślnie tyle, ile zostało do rozliczenia po obu stronach).
     */
    public function link(string $debtEntryId, string $payEntryId, ?int $amount, string $user, string $source = 'manual'): int
    {
        $debt = $this->item($debtEntryId) ?? throw new \RuntimeException('Nie ma takiej pozycji rozrachunkowej (albo dokument nie jest zaksięgowany).');
        $pay = $this->item($payEntryId) ?? throw new \RuntimeException('Nie ma takiej pozycji rozrachunkowej (albo dokument nie jest zaksięgowany).');
        if ($debt['kind'] === 'pay' && $pay['kind'] === 'debt') {
            [$debt, $pay] = [$pay, $debt];
        }
        if ($debt['kind'] !== 'debt' || $pay['kind'] !== 'pay') {
            throw new \RuntimeException('Rozlicza się należność (zobowiązanie) z zapłatą - wybierz pozycje po przeciwnych stronach konta.');
        }
        if ($debt['account'] !== $pay['account']) {
            throw new \RuntimeException('Pozycje są na różnych kontach (' . $debt['account'] . ' i ' . $pay['account'] . ').');
        }
        $max = min($debt['remaining'], $pay['remaining']);
        if ($max <= 0) {
            throw new \RuntimeException('Jedna z pozycji jest już całkiem rozliczona.');
        }
        $amount ??= $max;
        if ($amount <= 0 || $amount > $max) {
            throw new \RuntimeException('Kwota rozliczenia musi być od 0,01 do ' . Money::format($max) . ' zł.');
        }
        $this->db->insert('ext_ksg_settle', ['id' => Db::id(), 'debt_entry_id' => $debt['entry_id'], 'pay_entry_id' => $pay['entry_id'], 'amount_gr' => $amount,
            'source' => $source, 'created_by' => $user, 'created_at' => Db::now()]);
        $this->db->log($user, 'settle.link', 'doc', (string) $debt['doc_id'], $debt['number'] . ' ↔ ' . $pay['number'] . ', ' . Money::format($amount) . ' zł');
        return $amount;
    }

    public function unlink(string $id, string $user): void
    {
        $s = $this->db->one('SELECT * FROM ext_ksg_settle WHERE id = ?', [$id]) ?? throw new \RuntimeException('Nie ma takiego rozliczenia.');
        $this->db->run('DELETE FROM ext_ksg_settle WHERE id = ?', [$id]);
        $d = $this->db->one('SELECT d.id, d.number FROM ext_ksg_entries e JOIN ext_ksg_docs d ON d.id = e.doc_id WHERE e.id = ?', [$s['debt_entry_id']]);
        $this->db->log($user, 'settle.unlink', 'doc', (string) ($d['id'] ?? ''), ($d['number'] ?? '?') . ', ' . Money::format((int) $s['amount_gr']) . ' zł');
    }

    /** Rozliczenia dotyczące dokumentu (po obu stronach). @return list<array<string,mixed>> */
    public function linksForDoc(string $docId): array
    {
        return $this->db->all('SELECT s.*, a.number AS account, dd.id AS debt_doc_id, dd.number AS debt_number, pd.id AS pay_doc_id, pd.number AS pay_number,
                pd.posting_date AS pay_date
            FROM ext_ksg_settle s
            JOIN ext_ksg_entries de ON de.id = s.debt_entry_id JOIN ext_ksg_docs dd ON dd.id = de.doc_id JOIN ext_ksg_accounts a ON a.id = de.account_id
            JOIN ext_ksg_entries pe ON pe.id = s.pay_entry_id JOIN ext_ksg_docs pd ON pd.id = pe.doc_id
            WHERE dd.id = ? OR pd.id = ? ORDER BY s.created_at', [$docId, $docId]);
    }

    /**
     * Storno rozlicza się z oryginałem pozycja po pozycji. Wcześniejsze rozliczenia oryginału są zdejmowane: wystornowana
     * faktura nie jest już zapłacona (wpłata wraca do nierozliczonych), a wystornowana wpłata otwiera z powrotem faktury.
     */
    public function linkReversal(string $origDocId, string $stornoDocId, string $user): void
    {
        foreach ($this->linksForDoc($origDocId) as $l) {
            $this->unlink((string) $l['id'], $user);
        }
        $orig = $this->items(['doc' => $origDocId]);
        $st = $this->items(['doc' => $stornoDocId]);
        foreach ($orig as $o) {
            foreach ($st as $k => $s) {
                if ($s['account'] === $o['account'] && $s['kind'] !== $o['kind'] && $s['amount'] === $o['amount']) {
                    $this->link($o['kind'] === 'debt' ? $o['entry_id'] : $s['entry_id'], $o['kind'] === 'debt' ? $s['entry_id'] : $o['entry_id'], $o['amount'], $user, 'storno');
                    unset($st[$k]);
                    break;
                }
            }
        }
    }

    /**
     * Rozliczenie automatyczne na kontach (jednym albo wszystkich rozrachunkowych): najpierw zapłata, której opis
     * albo numer dokumentu zawiera numer faktury, potem pary o równej kwocie (od najstarszych). Reszta zostaje do ręcznego.
     * @return int liczba utworzonych rozliczeń
     */
    public function autoMatch(string $user, string $account = ''): int
    {
        $byAccount = [];
        foreach ($this->items(['open' => true, 'account' => $account]) as $i) {
            $byAccount[$i['account']][$i['kind']][] = $i;
        }
        $n = 0;
        foreach ($byAccount as $groups) {
            $debts = $groups['debt'] ?? [];
            $pays = $groups['pay'] ?? [];
            if ($debts === [] || $pays === []) {
                continue;
            }
            // 1) numer faktury w opisie zapłaty
            foreach ($pays as &$p) {
                $text = self::norm($p['label'] . ' ' . $p['description'] . ' ' . $p['number']);
                foreach ($debts as &$d) {
                    if ($p['remaining'] <= 0) {
                        break;
                    }
                    $num = self::norm((string) $d['number']);
                    if ($d['remaining'] <= 0 || strlen($num) < 3 || !str_contains($text, $num)) {
                        continue;
                    }
                    $amt = min($d['remaining'], $p['remaining']);
                    $this->link($d['entry_id'], $p['entry_id'], $amt, $user, 'auto');
                    $d['remaining'] -= $amt;
                    $p['remaining'] -= $amt;
                    ++$n;
                }
                unset($d);
            }
            unset($p);
            // 2) równe kwoty
            foreach ($pays as &$p) {
                if ($p['remaining'] <= 0) {
                    continue;
                }
                foreach ($debts as &$d) {
                    if ($d['remaining'] === $p['remaining']) {
                        $this->link($d['entry_id'], $p['entry_id'], $d['remaining'], $user, 'auto');
                        $d['remaining'] = 0;
                        $p['remaining'] = 0;
                        ++$n;
                        break;
                    }
                }
                unset($d);
            }
            unset($p);
        }
        return $n;
    }

    /** Numer dokumentu do porównania: tylko litery i cyfry, wielkie litery. */
    public static function norm(string $s): string
    {
        return (string) preg_replace('/[^0-9A-Z]/', '', mb_strtoupper($s));
    }

    /**
     * Podsumowanie otwartych pozycji według kont (kontrahentów): do zapłaty, nierozliczone zapłaty, saldo, zaległe
     * w przedziałach dni. @return list<array<string,mixed>>
     */
    public function summary(array $items): array
    {
        $out = [];
        foreach ($items as $i) {
            $k = $i['account'];
            $out[$k] ??= ['account' => $k, 'account_name' => $i['account_name'], 'parent' => $i['parent'], 'contractor' => $i['contractor'],
                'debt' => 0, 'pay' => 0, 'not_due' => 0, 'late_30' => 0, 'late_90' => 0, 'late_more' => 0, 'items' => []];
            $out[$k]['items'][] = $i;
            if ($i['remaining'] <= 0) {
                continue;
            }
            if ($i['kind'] === 'pay') {
                $out[$k]['pay'] += $i['remaining'];
                continue;
            }
            $out[$k]['debt'] += $i['remaining'];
            $bucket = $i['late_days'] <= 0 ? 'not_due' : ($i['late_days'] <= 30 ? 'late_30' : ($i['late_days'] <= 90 ? 'late_90' : 'late_more'));
            $out[$k][$bucket] += $i['remaining'];
        }
        return array_values($out);
    }
}
