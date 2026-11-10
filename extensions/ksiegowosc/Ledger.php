<?php
declare(strict_types=1);

namespace PaseExt\Ksiegowosc;

/**
 * Księgi: lata obrotowe, plan kont, dokumenty z dekretacją (Wn/Ma), księgowanie z numerem w dzienniku, storno,
 * obroty i salda, zapisy na koncie i dziennik.
 *
 * Zasady (ustawa o rachunkowości): zapis zaksięgowany jest trwały - dokument po zaksięgowaniu nie zmienia się
 * i nie znika, błąd poprawia się stornem (czerwonym: te same strony, kwoty z minusem, więc obroty wracają do stanu
 * sprzed błędu). Pozycje dziennika mają ciągłą numerację w roku obrotowym. Zapisy idą tylko na konta bez kont
 * podrzędnych (analityczne albo syntetyczne bez analityki), Wn = Ma w każdym dokumencie.
 */
final class Ledger
{
    public const TYPES = [
        'PK' => 'Polecenie księgowania',
        'FS' => 'Faktura sprzedaży',
        'FZ' => 'Faktura zakupu',
        'WB' => 'Wyciąg bankowy',
        'RK' => 'Raport kasowy',
        'LP' => 'Lista płac',
        'BO' => 'Bilans otwarcia',
        'ST' => 'Storno',
    ];
    /** Rodzaje, które można wybrać w formularzu (storno powstaje tylko z przycisku). */
    public const MANUAL_TYPES = ['PK', 'FS', 'FZ', 'WB', 'RK', 'LP', 'BO'];
    public const MAX_LINES = 500;

    public function __construct(public readonly Db $db)
    {
    }

    public static function validDate(string $d): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    // ------------------------------------------------------------------ lata obrotowe

    /** @return list<array<string,mixed>> od najnowszego */
    public function years(): array
    {
        return $this->db->all('SELECT * FROM ext_ksg_years ORDER BY starts_on DESC');
    }

    public function year(string $id): ?array
    {
        return $this->db->one('SELECT * FROM ext_ksg_years WHERE id = ?', [$id]);
    }

    public function yearFor(string $date): ?array
    {
        return $this->db->one('SELECT * FROM ext_ksg_years WHERE starts_on <= ? AND ends_on >= ?', [$date, $date]);
    }

    /** Rok na dziś albo najnowszy. */
    public function currentYear(): ?array
    {
        return $this->yearFor(date('Y-m-d')) ?? ($this->years()[0] ?? null);
    }

    /**
     * Nowy rok obrotowy z planem kont: 'template' (wzór dla spółki), 'empty' albo id roku, z którego kopiujemy plan.
     */
    public function createYear(string $name, string $start, string $end, string $chart, string $user): array
    {
        $name = trim($name);
        if ($name === '') {
            $name = substr($start, 0, 4);
        }
        if (!self::validDate($start) || !self::validDate($end) || $end < $start) {
            throw new \RuntimeException('Podaj poprawne daty początku i końca roku obrotowego.');
        }
        // Pierwszy rok spółki może trwać dłużej niż 12 miesięcy, ale nie dłużej niż 24.
        if ((new \DateTimeImmutable($start))->modify('+24 months') <= new \DateTimeImmutable($end)) {
            throw new \RuntimeException('Rok obrotowy nie może być dłuższy niż 24 miesiące.');
        }
        if ($this->db->one('SELECT id FROM ext_ksg_years WHERE starts_on <= ? AND ends_on >= ?', [$end, $start]) !== null) {
            throw new \RuntimeException('Ten okres nachodzi na istniejący rok obrotowy.');
        }
        $source = null;
        if (!in_array($chart, ['template', 'empty'], true)) {
            $source = $this->year($chart);
            if ($source === null) {
                throw new \RuntimeException('Nie ma roku, z którego miałby być skopiowany plan kont.');
            }
        }
        return $this->db->tx(function () use ($name, $start, $end, $chart, $source, $user): array {
            $year = ['id' => Db::id(), 'name' => mb_substr($name, 0, 40), 'starts_on' => $start, 'ends_on' => $end, 'locked_until' => null,
                'status' => 'open', 'created_at' => Db::now()];
            $this->db->insert('ext_ksg_years', $year);
            $rows = [];
            if ($chart === 'template') {
                $rows = array_map(static fn(array $a): array => ['number' => $a[0], 'name' => $a[1], 'kind' => $a[2], 'active' => 1, 'notes' => ''],
                    ChartTemplate::spzoo());
            } elseif ($source !== null) {
                $rows = $this->db->all('SELECT number, name, kind, active, notes FROM ext_ksg_accounts WHERE year_id = ? ORDER BY number', [$source['id']]);
            }
            foreach ($rows as $r) {
                $this->db->insert('ext_ksg_accounts', ['id' => Db::id(), 'year_id' => $year['id'], 'number' => $r['number'], 'name' => $r['name'],
                    'kind' => $r['kind'], 'active' => (int) $r['active'], 'notes' => (string) $r['notes'], 'created_at' => Db::now(), 'updated_at' => Db::now()]);
            }
            if ($chart === 'template') {
                $contractors = new Contractors($this->db);
                foreach ($contractors->list() as $c) {
                    $contractors->ensureAccounts($c, $user);
                }
            }
            $this->db->log($user, 'year.create', 'year', $year['id'], $year['name'] . ' ' . $start . ' - ' . $end . ', plan kont: '
                . ($chart === 'template' ? 'wzór' : ($chart === 'empty' ? 'pusty' : 'kopia ' . $source['name'])) . ' (' . count($rows) . ' kont)');
            return $year;
        });
    }

    /** Zamknięcie miesięcy: do tej daty (włącznie) nie da się nic zaksięgować. Pusta = bez blokady. */
    public function lockUntil(string $yearId, string $date, string $user): void
    {
        $year = $this->year($yearId) ?? throw new \RuntimeException('Nie ma takiego roku.');
        $date = trim($date);
        if ($date !== '' && (!self::validDate($date) || $date < $year['starts_on'] || $date > $year['ends_on'])) {
            throw new \RuntimeException('Data blokady musi leżeć w roku obrotowym.');
        }
        $this->db->update('ext_ksg_years', $yearId, ['locked_until' => $date === '' ? null : $date]);
        $this->db->log($user, 'year.lock', 'year', $yearId, $date === '' ? 'bez blokady' : 'zamknięte do ' . $date);
    }

    // ------------------------------------------------------------------ plan kont

    /**
     * Konta roku z oznaczeniem, czy mają konta podrzędne (wtedy są tylko sumą) i czy mają zapisy.
     * @return list<array<string,mixed>>
     */
    public function accounts(string $yearId, bool $onlyActive = false): array
    {
        $rows = $this->db->all('SELECT a.*, (SELECT COUNT(*) FROM ext_ksg_entries e WHERE e.account_id = a.id) AS entries
            FROM ext_ksg_accounts a WHERE a.year_id = ?' . ($onlyActive ? ' AND a.active = 1' : '') . ' ORDER BY a.number', [$yearId]);
        $numbers = array_column($this->db->all('SELECT number FROM ext_ksg_accounts WHERE year_id = ?', [$yearId]), 'number');
        foreach ($rows as &$r) {
            $r['entries'] = (int) $r['entries'];
            $r['parent'] = self::parentNumber((string) $r['number']);
            $r['level'] = substr_count((string) $r['number'], '-');
            $r['has_children'] = false;
            foreach ($numbers as $n) {
                if (str_starts_with((string) $n, $r['number'] . '-')) {
                    $r['has_children'] = true;
                    break;
                }
            }
        }
        return $rows;
    }

    public function account(string $id): ?array
    {
        return $this->db->one('SELECT * FROM ext_ksg_accounts WHERE id = ?', [$id]);
    }

    public function accountByNumber(string $yearId, string $number): ?array
    {
        return $this->db->one('SELECT * FROM ext_ksg_accounts WHERE year_id = ? AND number = ?', [$yearId, trim($number)]);
    }

    public static function parentNumber(string $number): ?string
    {
        $p = strrpos($number, '-');
        return $p === false ? null : substr($number, 0, $p);
    }

    private function hasChildren(string $yearId, string $number): bool
    {
        return $this->db->one('SELECT id FROM ext_ksg_accounts WHERE year_id = ? AND number LIKE ?', [$yearId, $number . '-%']) !== null;
    }

    private function entryCount(string $accountId): int
    {
        return (int) ($this->db->one('SELECT COUNT(*) AS n FROM ext_ksg_entries WHERE account_id = ?', [$accountId])['n'] ?? 0);
    }

    public function saveAccount(string $yearId, ?string $id, array $in, string $user): array
    {
        $year = $this->year($yearId) ?? throw new \RuntimeException('Nie ma takiego roku.');
        $old = $id !== null ? ($this->account($id) ?? throw new \RuntimeException('Nie ma takiego konta.')) : null;
        $number = trim((string) ($in['number'] ?? ''));
        $name = trim((string) ($in['name'] ?? ''));
        $kind = (string) ($in['kind'] ?? '');
        if (!preg_match('/^\d{1,3}(-[0-9A-Za-z]{1,10}){0,4}$/', $number)) {
            throw new \RuntimeException('Numer konta to 1-3 cyfry, a analityka po kresce, np. 130-01 albo 202-ALLEGRO.');
        }
        if ($name === '') {
            throw new \RuntimeException('Wpisz nazwę konta.');
        }
        if (!isset(ChartTemplate::KINDS[$kind])) {
            throw new \RuntimeException('Wybierz rodzaj konta.');
        }
        if ($old !== null && $old['number'] !== $number && $this->entryCount($old['id']) > 0) {
            throw new \RuntimeException('Konto ma już zapisy, więc numeru nie da się zmienić.');
        }
        if ($old !== null && $old['number'] !== $number && $this->hasChildren($yearId, (string) $old['number'])) {
            throw new \RuntimeException('Konto ma konta podrzędne, więc numeru nie da się zmienić.');
        }
        $dup = $this->accountByNumber($yearId, $number);
        if ($dup !== null && $dup['id'] !== ($old['id'] ?? null)) {
            throw new \RuntimeException('Konto ' . $number . ' już jest w planie kont.');
        }
        $parent = self::parentNumber($number);
        if ($parent !== null) {
            $p = $this->accountByNumber($yearId, $parent);
            if ($p === null) {
                throw new \RuntimeException('Najpierw dodaj konto nadrzędne ' . $parent . '.');
            }
            if ($this->entryCount($p['id']) > 0) {
                throw new \RuntimeException('Konto ' . $parent . ' ma już zapisy - analitykę zakłada się przed pierwszym zapisem.');
            }
        }
        $row = ['number' => $number, 'name' => mb_substr($name, 0, 200), 'kind' => $kind, 'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 2000),
            'updated_at' => Db::now()];
        if ($old === null) {
            $row += ['id' => Db::id(), 'year_id' => $year['id'], 'active' => 1, 'created_at' => Db::now()];
            $this->db->insert('ext_ksg_accounts', $row);
            $this->db->log($user, 'account.create', 'account', $row['id'], $number . ' ' . $row['name']);
            return $this->account($row['id']);
        }
        $this->db->update('ext_ksg_accounts', $old['id'], $row);
        $this->db->log($user, 'account.update', 'account', $old['id'], $old['number'] . ' ' . $old['name'] . ' → ' . $number . ' ' . $row['name']);
        return $this->account($old['id']);
    }

    public function setAccountActive(string $id, bool $active, string $user): void
    {
        $a = $this->account($id) ?? throw new \RuntimeException('Nie ma takiego konta.');
        $this->db->update('ext_ksg_accounts', $id, ['active' => $active ? 1 : 0, 'updated_at' => Db::now()]);
        $this->db->log($user, $active ? 'account.activate' : 'account.deactivate', 'account', $id, (string) $a['number']);
    }

    public function deleteAccount(string $id, string $user): void
    {
        $a = $this->account($id) ?? throw new \RuntimeException('Nie ma takiego konta.');
        if ($this->entryCount($id) > 0) {
            throw new \RuntimeException('Konto ma zapisy - można je tylko wyłączyć.');
        }
        if ($this->hasChildren((string) $a['year_id'], (string) $a['number'])) {
            throw new \RuntimeException('Konto ma konta podrzędne - usuń je najpierw.');
        }
        $this->db->run('DELETE FROM ext_ksg_accounts WHERE id = ?', [$id]);
        $this->db->log($user, 'account.delete', 'account', $id, $a['number'] . ' ' . $a['name']);
    }

    // ------------------------------------------------------------------ dokumenty

    public function doc(string $id): ?array
    {
        return $this->db->one('SELECT * FROM ext_ksg_docs WHERE id = ?', [$id]);
    }

    /** @return list<array<string,mixed>> pozycje dekretu z numerem i nazwą konta */
    public function entries(string $docId): array
    {
        return $this->db->all('SELECT e.*, a.number AS account_number, a.name AS account_name FROM ext_ksg_entries e
            LEFT JOIN ext_ksg_accounts a ON a.id = e.account_id WHERE e.doc_id = ? ORDER BY e.position', [$docId]);
    }

    /**
     * Lista dokumentów roku z filtrami: miesiąc RRRR-MM, status, rodzaj, szukany tekst.
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function docs(string $yearId, array $f = [], int $limit = 50, int $offset = 0): array
    {
        $where = ['year_id = ?'];
        $args = [$yearId];
        if (preg_match('/^\d{4}-\d{2}$/', (string) ($f['month'] ?? ''))) {
            $where[] = 'posting_date LIKE ?';
            $args[] = $f['month'] . '-%';
        }
        if (in_array($f['status'] ?? '', ['draft', 'posted'], true)) {
            $where[] = 'status = ?';
            $args[] = $f['status'];
        }
        if (isset(self::TYPES[$f['type'] ?? ''])) {
            $where[] = 'type = ?';
            $args[] = $f['type'];
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], '', $q) . '%';
            $where[] = '(number LIKE ? OR description LIKE ? OR counterparty LIKE ?)';
            array_push($args, $like, $like, $like);
        }
        $w = implode(' AND ', $where);
        $total = (int) ($this->db->one("SELECT COUNT(*) AS n FROM ext_ksg_docs WHERE {$w}", $args)['n'] ?? 0);
        $rows = $this->db->all("SELECT * FROM ext_ksg_docs WHERE {$w} ORDER BY posting_date DESC, CASE WHEN journal_no IS NULL THEN 1 ELSE 0 END DESC,
            journal_no DESC, created_at DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset), $args);
        return ['rows' => $rows, 'total' => $total];
    }

    /** Kolejny numer dokumentu danego rodzaju w miesiącu: PK/3/11/2026. */
    private function nextNumber(string $yearId, string $type, string $date): string
    {
        $mm = substr($date, 5, 2);
        $yyyy = substr($date, 0, 4);
        $n = (int) ($this->db->one('SELECT COUNT(*) AS n FROM ext_ksg_docs WHERE year_id = ? AND type = ? AND posting_date LIKE ?',
            [$yearId, $type, $yyyy . '-' . $mm . '-%'])['n'] ?? 0);
        do {
            $number = $type . '/' . (++$n) . '/' . $mm . '/' . $yyyy;
        } while ($this->db->one('SELECT id FROM ext_ksg_docs WHERE year_id = ? AND type = ? AND number = ?', [$yearId, $type, $number]) !== null);
        return $number;
    }

    /**
     * Pozycje dekretu z formularza: wiersze bez konta i kwot są pomijane; konto po numerze w roku dokumentu.
     * @return list<array{account_id:string,debit_gr:int,credit_gr:int,description:string}>
     */
    private function parseLines(string $yearId, array $lines): array
    {
        $out = [];
        foreach (array_values($lines) as $i => $l) {
            if (!is_array($l)) {
                continue;
            }
            $acc = trim((string) ($l['account'] ?? ''));
            $debit = Money::parse($l['debit'] ?? '') ?? 0;
            $credit = Money::parse($l['credit'] ?? '') ?? 0;
            $desc = mb_substr(trim((string) ($l['description'] ?? '')), 0, 255);
            if ($acc === '' && $debit === 0 && $credit === 0 && $desc === '') {
                continue;
            }
            $row = $i + 1;
            if ($acc === '') {
                throw new \RuntimeException("Pozycja {$row}: wpisz numer konta.");
            }
            // Z podpowiedzi przychodzi „130-01 Rachunek bieżący” - liczy się numer.
            $acc = preg_split('/\s+/', $acc)[0];
            $account = $this->accountByNumber($yearId, $acc);
            if ($account === null) {
                throw new \RuntimeException("Pozycja {$row}: nie ma konta {$acc} w planie kont tego roku.");
            }
            if (($debit === 0) === ($credit === 0)) {
                throw new \RuntimeException("Pozycja {$row}: wpisz kwotę po jednej stronie - Wn albo Ma.");
            }
            $out[] = ['account_id' => (string) $account['id'], 'debit_gr' => $debit, 'credit_gr' => $credit, 'description' => $desc];
        }
        if ($out === []) {
            throw new \RuntimeException('Dodaj co najmniej jedną pozycję dekretu.');
        }
        if (count($out) > self::MAX_LINES) {
            throw new \RuntimeException('Za dużo pozycji w jednym dokumencie (najwyżej ' . self::MAX_LINES . ').');
        }
        return $out;
    }

    /** Zapis szkicu (nowego albo poprawianego). Szkic może być niezbilansowany - pilnuje tego księgowanie. */
    public function saveDraft(?string $id, array $in, string $user, string $source = 'manual', string $sourceRef = ''): array
    {
        $old = $id !== null ? ($this->doc($id) ?? throw new \RuntimeException('Nie ma takiego dokumentu.')) : null;
        if ($old !== null && $old['status'] !== 'draft') {
            throw new \RuntimeException('Dokument jest zaksięgowany - popraw go stornem.');
        }
        $type = (string) ($in['type'] ?? 'PK');
        if (!in_array($type, self::MANUAL_TYPES, true)) {
            throw new \RuntimeException('Wybierz rodzaj dokumentu.');
        }
        $posting = trim((string) ($in['posting_date'] ?? ''));
        if (!self::validDate($posting)) {
            throw new \RuntimeException('Podaj datę księgowania.');
        }
        $docDate = trim((string) ($in['doc_date'] ?? '')) ?: $posting;
        $opDate = trim((string) ($in['op_date'] ?? '')) ?: $docDate;
        if (!self::validDate($docDate) || !self::validDate($opDate)) {
            throw new \RuntimeException('Niepoprawna data dokumentu albo operacji.');
        }
        $due = trim((string) ($in['due_date'] ?? ''));
        if ($due !== '' && !self::validDate($due)) {
            throw new \RuntimeException('Niepoprawny termin płatności.');
        }
        $year = $this->yearFor($posting) ?? throw new \RuntimeException('Data księgowania ' . $posting . ' nie leży w żadnym roku obrotowym. Załóż rok w zakładce Lata obrotowe.');
        $description = trim((string) ($in['description'] ?? ''));
        if ($description === '') {
            throw new \RuntimeException('Wpisz opis operacji (treść dokumentu).');
        }
        $lines = $this->parseLines((string) $year['id'], (array) ($in['lines'] ?? []));
        $vat = Vat::parse((array) ($in['vat'] ?? []), $type, substr($opDate, 0, 7), substr($posting, 0, 7));
        $contractor = null;
        if (trim((string) ($in['contractor_id'] ?? '')) !== '') {
            $contractor = (new Contractors($this->db))->get((string) $in['contractor_id']) ?? throw new \RuntimeException('Nie ma takiego kontrahenta.');
        }
        $number = mb_substr(trim((string) ($in['number'] ?? '')), 0, 60);
        return $this->db->tx(function () use ($old, $type, $posting, $docDate, $opDate, $due, $year, $description, $lines, $vat, $contractor, $number, $in, $user, $source, $sourceRef): array {
            if ($number === '') {
                $number = $old !== null && $old['type'] === $type && substr((string) $old['posting_date'], 0, 7) === substr($posting, 0, 7)
                    ? (string) $old['number'] : $this->nextNumber((string) $year['id'], $type, $posting);
            }
            $row = ['year_id' => $year['id'], 'type' => $type, 'number' => $number, 'doc_date' => $docDate, 'op_date' => $opDate,
                'posting_date' => $posting, 'description' => mb_substr($description, 0, 255),
                'counterparty' => mb_substr(trim((string) ($in['counterparty'] ?? '')) ?: (string) ($contractor['name'] ?? ''), 0, 200),
                'contractor_id' => $contractor['id'] ?? null, 'due_date' => $due !== '' ? $due : null,
                'total_gr' => array_sum(array_column($lines, 'debit_gr')), 'updated_at' => Db::now()];
            if ($old === null) {
                $row += ['id' => Db::id(), 'journal_no' => null, 'status' => 'draft', 'reversal_of' => null, 'reversed_by' => null,
                    'source' => $source, 'source_ref' => $sourceRef, 'created_by' => $user, 'created_at' => Db::now(), 'posted_by' => null, 'posted_at' => null];
                $this->db->insert('ext_ksg_docs', $row);
                $docId = $row['id'];
            } else {
                $docId = (string) $old['id'];
                $this->db->update('ext_ksg_docs', $docId, $row);
                $this->db->run('DELETE FROM ext_ksg_entries WHERE doc_id = ?', [$docId]);
            }
            foreach ($lines as $i => $l) {
                $this->db->insert('ext_ksg_entries', ['id' => Db::id(), 'doc_id' => $docId, 'position' => $i + 1] + $l);
            }
            (new Vat($this->db))->replace($docId, $vat);
            $this->db->log($user, $old === null ? 'doc.create' : 'doc.update', 'doc', $docId, $number . ', ' . Money::format($row['total_gr']) . ' zł');
            return $this->doc($docId);
        });
    }

    public function deleteDraft(string $id, string $user): void
    {
        $d = $this->doc($id) ?? throw new \RuntimeException('Nie ma takiego dokumentu.');
        if ($d['status'] !== 'draft') {
            throw new \RuntimeException('Zaksięgowanego dokumentu nie da się usunąć - popraw go stornem.');
        }
        $this->db->tx(function () use ($id, $d, $user): void {
            $this->db->run('DELETE FROM ext_ksg_entries WHERE doc_id = ?', [$id]);
            $this->db->run('DELETE FROM ext_ksg_vat WHERE doc_id = ?', [$id]);
            $this->db->run('DELETE FROM ext_ksg_docs WHERE id = ?', [$id]);
            $this->db->log($user, 'doc.delete', 'doc', $id, (string) $d['number']);
        });
    }

    /** Problemy, przez które szkicu nie da się zaksięgować (pusta lista = można). @return list<string> */
    public function postingProblems(array $doc): array
    {
        $problems = [];
        $year = $this->year((string) $doc['year_id']);
        if ($year === null) {
            return ['Dokument nie należy do żadnego roku obrotowego.'];
        }
        if ($year['status'] !== 'open') {
            $problems[] = 'Rok obrotowy ' . $year['name'] . ' jest zamknięty.';
        }
        if ($year['locked_until'] !== null && $year['locked_until'] !== '' && $doc['posting_date'] <= $year['locked_until']) {
            $problems[] = 'Okres do ' . $year['locked_until'] . ' jest zamknięty - zmień datę księgowania albo zdejmij blokadę w Latach obrotowych.';
        }
        $entries = $this->entries((string) $doc['id']);
        $debit = array_sum(array_map('intval', array_column($entries, 'debit_gr')));
        $credit = array_sum(array_map('intval', array_column($entries, 'credit_gr')));
        if ($debit !== $credit) {
            $problems[] = 'Strony się nie bilansują: Wn ' . Money::format($debit) . ', Ma ' . Money::format($credit) . ' (różnica ' . Money::format($debit - $credit) . ').';
        } elseif ($debit === 0) {
            $problems[] = 'Dokument ma zerową wartość.';
        }
        foreach ($entries as $e) {
            if ($e['account_number'] === null) {
                $problems[] = 'Pozycja ' . $e['position'] . ': konto zostało usunięte.';
                continue;
            }
            $a = $this->account((string) $e['account_id']);
            if ((int) $a['active'] !== 1) {
                $problems[] = 'Konto ' . $a['number'] . ' jest wyłączone.';
            }
            if ($this->hasChildren((string) $a['year_id'], (string) $a['number'])) {
                $problems[] = 'Konto ' . $a['number'] . ' ma konta podrzędne - księguj na analitykę.';
            }
        }
        return array_values(array_unique($problems));
    }

    /**
     * Ostrzeżenia (nie blokują księgowania): VAT z rejestru niezgodny z zapisem na kontach 221/222, faktura bez VAT
     * albo bez kontrahenta. @return list<string>
     */
    public function warnings(array $doc): array
    {
        $out = [];
        $vat = (new Vat($this->db))->forDoc((string) $doc['id']);
        $due = $input = 0;
        foreach ($vat as $v) {
            if ($v['register'] === 'S') {
                $due += (int) $v['vat_gr'];
            } else {
                $input += Vat::deductible($v);
            }
        }
        $on222 = $on221 = 0;
        foreach ($this->entries((string) $doc['id']) as $e) {
            $n = (string) $e['account_number'];
            if ($n === '222' || str_starts_with($n, '222-')) {
                $on222 += (int) $e['credit_gr'] - (int) $e['debit_gr'];
            }
            if ($n === '221' || str_starts_with($n, '221-')) {
                $on221 += (int) $e['debit_gr'] - (int) $e['credit_gr'];
            }
        }
        if ($due !== $on222) {
            $out[] = 'VAT należny z rejestru (' . Money::format($due) . ') różni się od zapisu na koncie 222 (' . Money::format($on222) . ').';
        }
        if ($input !== $on221) {
            $out[] = 'VAT naliczony do odliczenia z rejestru (' . Money::format($input) . ') różni się od zapisu na koncie 221 (' . Money::format($on221) . ').';
        }
        if (in_array($doc['type'], ['FS', 'FZ'], true) && $vat === []) {
            $out[] = 'Faktura nie ma pozycji VAT - nie trafi do rejestru VAT.';
        }
        if (in_array($doc['type'], ['FS', 'FZ'], true) && ($doc['contractor_id'] ?? null) === null) {
            $out[] = 'Faktura nie ma wybranego kontrahenta z kartoteki.';
        }
        return $out;
    }

    /**
     * Podpowiedź dekretu z pozycji VAT: sprzedaż - Wn rozrachunki z odbiorcą (brutto), Ma przychód (netto), Ma 222 (VAT);
     * zakup - Wn koszt (netto + VAT nieodliczony), Wn 221 (VAT do odliczenia), Ma rozrachunki z dostawcą (brutto).
     * $counter = konto przychodu albo kosztu. @return list<array{account:string,debit:string,credit:string,description:string}>
     */
    public function suggestLines(string $yearId, string $type, ?array $contractor, array $vatLines, string $counter): array
    {
        if ($vatLines === []) {
            throw new \RuntimeException('Najpierw wpisz pozycje VAT (netto i stawkę) - z nich powstanie dekret.');
        }
        $register = (string) $vatLines[0]['register'];
        $net = $vat = $ded = 0;
        foreach ($vatLines as $v) {
            $net += (int) $v['net_gr'];
            $vat += (int) $v['vat_gr'];
            $ded += $register === 'Z' ? Vat::deductible($v) : (int) $v['vat_gr'];
        }
        $parent = $register === 'S' ? Contractors::RECEIVABLE : Contractors::PAYABLE;
        $settle = $contractor !== null ? (new Contractors($this->db))->settlementAccount($contractor, $yearId, $parent) : null;
        if ($settle === null) {
            $p = $this->accountByNumber($yearId, $parent);
            $settle = $p !== null && !$this->hasChildren($yearId, $parent) ? $parent : '';
        }
        $counter = trim((string) preg_split('/\s+/', trim($counter))[0]);
        if ($counter === '') {
            $counter = $register === 'S' ? ($type === 'FS' ? '700' : '730') : '401';
        }
        $m = static fn(int $gr): string => Money::input($gr);
        $row = static fn(string $a, int $d, int $c, string $desc = ''): array => ['account' => $a, 'debit' => $d !== 0 ? $m($d) : '', 'credit' => $c !== 0 ? $m($c) : '', 'description' => $desc];
        if ($register === 'S') {
            $lines = [$row($settle, $net + $vat, 0), $row($counter, 0, $net)];
            if ($vat !== 0) {
                $lines[] = $row('222', 0, $vat, 'VAT należny');
            }
        } else {
            $lines = [$row($counter, $net + ($vat - $ded), 0)];
            if ($ded !== 0) {
                $lines[] = $row('221', $ded, 0, 'VAT naliczony');
            }
            $lines[] = $row($settle, 0, $net + $vat);
        }
        return $lines;
    }

    /** Księgowanie: kolejny numer pozycji w dzienniku roku, od tej chwili dokument jest nieodwracalny. */
    public function post(string $id, string $user): array
    {
        return $this->db->tx(function () use ($id, $user): array {
            $d = $this->doc($id) ?? throw new \RuntimeException('Nie ma takiego dokumentu.');
            if ($d['status'] !== 'draft') {
                throw new \RuntimeException('Dokument jest już zaksięgowany.');
            }
            $problems = $this->postingProblems($d);
            if ($problems !== []) {
                throw new \RuntimeException('Nie można zaksięgować: ' . implode(' ', $problems));
            }
            $no = (int) ($this->db->one('SELECT MAX(journal_no) AS n FROM ext_ksg_docs WHERE year_id = ?', [$d['year_id']])['n'] ?? 0) + 1;
            $this->db->update('ext_ksg_docs', $id, ['status' => 'posted', 'journal_no' => $no, 'posted_by' => $user, 'posted_at' => Db::now(), 'updated_at' => Db::now()]);
            $this->db->log($user, 'doc.post', 'doc', $id, $d['number'] . ' → poz. ' . $no . ' dziennika');
            return $this->doc($id);
        });
    }

    /**
     * Storno czerwone zaksięgowanego dokumentu: nowy dokument ST z tymi samymi kontami i stronami, kwoty z minusem,
     * od razu zaksięgowany. Gdy data storna jest w innym roku, konta szukamy po numerze w planie kont tamtego roku.
     */
    public function reverse(string $id, string $date, string $user, string $reason = ''): array
    {
        return $this->db->tx(function () use ($id, $date, $user, $reason): array {
            $d = $this->doc($id) ?? throw new \RuntimeException('Nie ma takiego dokumentu.');
            if ($d['status'] !== 'posted') {
                throw new \RuntimeException('Stornować można tylko zaksięgowany dokument (szkic po prostu popraw albo usuń).');
            }
            if ($d['reversed_by'] !== null && $d['reversed_by'] !== '') {
                throw new \RuntimeException('Ten dokument jest już wystornowany.');
            }
            if ($d['type'] === 'ST') {
                throw new \RuntimeException('Storna się nie stornuje - zaksięguj dokument jeszcze raz.');
            }
            if (!self::validDate($date)) {
                throw new \RuntimeException('Podaj datę storna.');
            }
            $year = $this->yearFor($date) ?? throw new \RuntimeException('Data storna nie leży w żadnym roku obrotowym.');
            $docId = Db::id();
            $total = 0;
            foreach ($this->entries($id) as $i => $e) {
                $acc = $year['id'] === $d['year_id'] ? $this->account((string) $e['account_id'])
                    : $this->accountByNumber((string) $year['id'], (string) $e['account_number']);
                if ($acc === null) {
                    throw new \RuntimeException('W planie kont roku ' . $year['name'] . ' nie ma konta ' . $e['account_number'] . '.');
                }
                $this->db->insert('ext_ksg_entries', ['id' => Db::id(), 'doc_id' => $docId, 'position' => $i + 1, 'account_id' => $acc['id'],
                    'debit_gr' => -(int) $e['debit_gr'], 'credit_gr' => -(int) $e['credit_gr'], 'description' => (string) $e['description']]);
                $total -= (int) $e['debit_gr'];
            }
            $this->db->insert('ext_ksg_docs', ['id' => $docId, 'year_id' => $year['id'], 'type' => 'ST',
                'number' => $this->nextNumber((string) $year['id'], 'ST', $date), 'journal_no' => null, 'doc_date' => $date, 'op_date' => $date,
                'posting_date' => $date, 'description' => mb_substr('Storno ' . $d['number'] . ($reason !== '' ? ': ' . $reason : ''), 0, 255),
                'counterparty' => $d['counterparty'], 'contractor_id' => $d['contractor_id'], 'due_date' => null, 'status' => 'draft', 'total_gr' => $total, 'reversal_of' => $id, 'reversed_by' => null,
                'source' => 'storno', 'source_ref' => $id, 'created_by' => $user, 'created_at' => Db::now(), 'posted_by' => null, 'posted_at' => null,
                'updated_at' => Db::now()]);
            // Pozycje VAT z minusem w tym samym okresie: błędny dokument znika z rejestru.
            $vat = new Vat($this->db);
            $vat->replace($docId, array_map(static fn(array $v): array => ['net_gr' => -(int) $v['net_gr'], 'vat_gr' => -(int) $v['vat_gr']] + $v, $vat->forDoc($id)));
            $st = $this->post($docId, $user);
            $this->db->update('ext_ksg_docs', $id, ['reversed_by' => $docId, 'updated_at' => Db::now()]);
            // Storno rozlicza oryginał na kontach rozrachunkowych (faktura i jej storno znoszą się w rozrachunkach).
            (new Settlements($this->db))->linkReversal($id, $docId, $user);
            $this->db->log($user, 'doc.reverse', 'doc', $id, $d['number'] . ' → ' . $st['number']);
            return $st;
        });
    }

    // ------------------------------------------------------------------ zestawienia

    /**
     * Obroty i salda: dla każdego konta obroty Wn/Ma w okresie [from, to], narastająco od początku roku do „to”
     * i saldo. Konta z analityką pokazują sumę kont podrzędnych. Tylko dokumenty zaksięgowane.
     * @return array{rows:list<array<string,mixed>>,totals:array<string,int>}
     */
    public function turnover(string $yearId, string $from, string $to): array
    {
        $year = $this->year($yearId) ?? throw new \RuntimeException('Nie ma takiego roku.');
        $sums = [];
        foreach ($this->db->all('SELECT e.account_id,
                SUM(CASE WHEN d.posting_date >= ? THEN e.debit_gr ELSE 0 END) AS pd, SUM(CASE WHEN d.posting_date >= ? THEN e.credit_gr ELSE 0 END) AS pc,
                SUM(e.debit_gr) AS cd, SUM(e.credit_gr) AS cc
            FROM ext_ksg_entries e JOIN ext_ksg_docs d ON d.id = e.doc_id
            WHERE d.year_id = ? AND d.status = \'posted\' AND d.posting_date >= ? AND d.posting_date <= ?
            GROUP BY e.account_id', [$from, $from, $yearId, $year['starts_on'], $to]) as $r) {
            $sums[$r['account_id']] = ['pd' => (int) $r['pd'], 'pc' => (int) $r['pc'], 'cd' => (int) $r['cd'], 'cc' => (int) $r['cc']];
        }
        $accounts = $this->accounts($yearId);
        $byNumber = [];
        foreach ($accounts as $a) {
            $byNumber[$a['number']] = $sums[$a['id']] ?? ['pd' => 0, 'pc' => 0, 'cd' => 0, 'cc' => 0];
        }
        $rows = [];
        $totals = ['pd' => 0, 'pc' => 0, 'cd' => 0, 'cc' => 0, 'sd' => 0, 'sc' => 0];
        foreach ($accounts as $a) {
            $t = ['pd' => 0, 'pc' => 0, 'cd' => 0, 'cc' => 0];
            foreach ($byNumber as $n => $s) {
                // Klucze tablicy z samych cyfr PHP zamienia na int - porównujemy tekst.
                $n = (string) $n;
                if ($n === $a['number'] || str_starts_with($n, $a['number'] . '-')) {
                    foreach ($t as $k => $_) {
                        $t[$k] += $s[$k];
                    }
                }
            }
            $bal = $t['cd'] - $t['cc'];
            $row = $a + $t + ['sd' => max(0, $bal), 'sc' => max(0, -$bal)];
            $rows[] = $row;
            if ($a['parent'] === null) {
                foreach ($totals as $k => $_) {
                    $totals[$k] += $row[$k];
                }
            }
        }
        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * Zapisy na koncie (z kontami podrzędnymi) w okresie, z saldem początkowym od początku roku i saldem bieżącym.
     * @return array{opening:int,rows:list<array<string,mixed>>,debit:int,credit:int}
     */
    public function accountLedger(string $accountId, string $from, string $to): array
    {
        $a = $this->account($accountId) ?? throw new \RuntimeException('Nie ma takiego konta.');
        $year = $this->year((string) $a['year_id']);
        $ids = array_column($this->db->all('SELECT id FROM ext_ksg_accounts WHERE year_id = ? AND (number = ? OR number LIKE ?)',
            [$a['year_id'], $a['number'], $a['number'] . '-%']), 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $open = $this->db->one("SELECT COALESCE(SUM(e.debit_gr), 0) AS d, COALESCE(SUM(e.credit_gr), 0) AS c FROM ext_ksg_entries e
            JOIN ext_ksg_docs d ON d.id = e.doc_id WHERE e.account_id IN ({$in}) AND d.status = 'posted' AND d.posting_date >= ? AND d.posting_date < ?",
            [...$ids, $year['starts_on'], $from]);
        $opening = (int) $open['d'] - (int) $open['c'];
        $rows = $this->db->all("SELECT e.*, a.number AS account_number, d.id AS doc_id, d.number AS doc_number, d.type, d.journal_no, d.posting_date,
                d.description AS doc_description
            FROM ext_ksg_entries e JOIN ext_ksg_docs d ON d.id = e.doc_id JOIN ext_ksg_accounts a ON a.id = e.account_id
            WHERE e.account_id IN ({$in}) AND d.status = 'posted' AND d.posting_date >= ? AND d.posting_date <= ?
            ORDER BY d.posting_date, d.journal_no, e.position", [...$ids, $from, $to]);
        $bal = $opening;
        $debit = $credit = 0;
        foreach ($rows as &$r) {
            $r['debit_gr'] = (int) $r['debit_gr'];
            $r['credit_gr'] = (int) $r['credit_gr'];
            $debit += $r['debit_gr'];
            $credit += $r['credit_gr'];
            $bal += $r['debit_gr'] - $r['credit_gr'];
            $r['balance'] = $bal;
        }
        return ['opening' => $opening, 'rows' => $rows, 'debit' => $debit, 'credit' => $credit];
    }

    /**
     * Dziennik: zaksięgowane dokumenty w kolejności numerów pozycji, z dekretami.
     * @return list<array<string,mixed>>
     */
    public function journal(string $yearId, string $from, string $to): array
    {
        $docs = $this->db->all("SELECT * FROM ext_ksg_docs WHERE year_id = ? AND status = 'posted' AND posting_date >= ? AND posting_date <= ?
            ORDER BY journal_no", [$yearId, $from, $to]);
        foreach ($docs as &$d) {
            $d['entries'] = $this->entries((string) $d['id']);
        }
        return $docs;
    }
}
