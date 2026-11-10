<?php
declare(strict_types=1);

namespace PaseExt\Ksiegowosc;

use PaseExt\Ksiegowosc\Ui as U;
use PDO;

/**
 * Strony księgowości. Każda dostaje gotowy poziom dostępu (sprawdzony w KsiegowoscExtension przed wywołaniem),
 * a zapisujące sprawdzają go jeszcze raz przy POST.
 */
final class Pages
{
    public const COMPANY_FIELDS = [
        'name' => 'Pełna nazwa firmy', 'nip' => 'NIP', 'regon' => 'REGON', 'krs' => 'KRS', 'street' => 'Ulica i numer',
        'postcode' => 'Kod pocztowy', 'city' => 'Miejscowość', 'tax_office' => 'Kod urzędu skarbowego',
    ];
    public const LEGAL_FORMS = ['spzoo' => 'Spółka z o.o.', 'psa' => 'Prosta spółka akcyjna', 'sk' => 'Spółka komandytowa', 'jdg' => 'Jednoosobowa działalność (pełne księgi)'];
    public const VAT_PERIODS = ['month' => 'VAT miesięcznie (JPK_V7M)', 'quarter' => 'VAT kwartalnie (JPK_V7K)', 'none' => 'Zwolniony z VAT'];

    private readonly Ledger $ledger;

    public function __construct(private readonly Db $db, private readonly array $ctx, private readonly string $level, private readonly PDO $crm)
    {
        $this->ledger = new Ledger($db);
    }

    private function can(string $need): bool
    {
        return Access::allows($this->level, $need);
    }

    private function need(string $need): void
    {
        if (!$this->can($need)) {
            flash('Brak uprawnień do tej operacji w księgowości.', 'err');
            $this->go('pulpit');
        }
    }

    private function go(string $page, array $q = []): never
    {
        header('Location: ' . U::url($page, $q));
        exit;
    }

    private function user(): string
    {
        return (string) $this->ctx['user'];
    }

    private function head(string $tab): string
    {
        return U::style() . U::tabs($tab, $this->level);
    }

    /** Rok z ?rok= albo bieżący. */
    private function pickYear(): ?array
    {
        $id = (string) ($_GET['rok'] ?? '');
        return ($id !== '' ? $this->ledger->year($id) : null) ?? $this->ledger->currentYear();
    }

    public function company(): array
    {
        $c = json_decode($this->db->meta('company', '{}'), true);
        return is_array($c) ? $c : [];
    }

    private function noYear(string $tab): string
    {
        return $this->head($tab) . U::empty('Najpierw załóż rok obrotowy', 'Księgi prowadzi się w latach obrotowych. Każdy rok ma swój plan kont - przy zakładaniu można wczytać gotowy wzór dla spółki.',
            $this->can(Access::MANAGE) ? '<a class="btn" href="' . U::h(U::url('lata')) . '">+ Załóż rok obrotowy</a>' : '');
    }

    // ============================================================== pulpit

    public function dashboard(): string
    {
        $year = $this->ledger->currentYear();
        $company = $this->company();
        if ($year === null) {
            $steps = '<ol class="kg-muted" style="text-align:left;max-width:520px;margin:0 auto 16px">'
                . '<li>' . (($company['name'] ?? '') !== '' ? '✓ ' : '') . 'Uzupełnij dane firmy (NIP, forma prawna, rozliczanie VAT).</li>'
                . '<li>Załóż rok obrotowy i wczytaj wzorcowy plan kont dla spółki.</li>'
                . '<li>Nadaj dostęp osobom, które mają widzieć albo prowadzić księgi.</li></ol>';
            return $this->head('pulpit') . U::empty('Księgowość jest gotowa do ustawienia',
                'Dane księgowe są w osobnych tabelach (' . (Db::separate() ? 'w osobnej bazie danych' : 'w bazie CRM, z prefiksem ext_ksg_') . ') i nie mieszają się z zamówieniami. ' . $steps,
                $this->can(Access::MANAGE) ? '<a class="btn secondary" href="' . U::h(U::url('firma')) . '">Dane firmy</a><a class="btn" href="' . U::h(U::url('lata')) . '">+ Załóż rok obrotowy</a>' : '');
        }
        $yid = (string) $year['id'];
        $stat = $this->db->one("SELECT SUM(CASE WHEN status = 'posted' THEN 1 ELSE 0 END) AS posted, SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS drafts,
            MAX(journal_no) AS last_no FROM ext_ksg_docs WHERE year_id = ?", [$yid]) ?? [];
        $to = min(date('Y-m-d'), (string) $year['ends_on']);
        $to = max($to, (string) $year['starts_on']);
        $tb = $this->ledger->turnover($yid, (string) $year['starts_on'], $to);
        $bal = static function (array $rows, callable $pick): int {
            $s = 0;
            foreach ($rows as $r) {
                if ($pick($r)) {
                    $s += $r['cd'] - $r['cc'];
                }
            }
            return $s;
        };
        $leaf = static fn(array $r): bool => !$r['has_children'];
        $cash = $bal($tb['rows'], static fn(array $r): bool => $leaf($r) && preg_match('/^1\d\d/', (string) $r['number']) === 1);
        $recv = $bal($tb['rows'], static fn(array $r): bool => $leaf($r) && preg_match('/^200(-|$)/', (string) $r['number']) === 1);
        $pay = -$bal($tb['rows'], static fn(array $r): bool => $leaf($r) && preg_match('/^202(-|$)/', (string) $r['number']) === 1);
        $result = -$bal($tb['rows'], static fn(array $r): bool => $leaf($r) && $r['kind'] === 'W');
        $balanced = $tb['totals']['cd'] === $tb['totals']['cc'];

        $html = $this->head('pulpit') . '<div class="kg-top"><p>Rok obrotowy <b>' . U::h($year['name']) . '</b> (' . U::date($year['starts_on']) . ' - ' . U::date($year['ends_on']) . ')'
            . ($year['locked_until'] ? ', zamknięte do ' . U::date($year['locked_until']) : '') . ($company['name'] ?? '' ? ' · ' . U::h($company['name']) : '') . '</p>'
            . '<div class="kg-actions">' . ($this->can(Access::BOOK) ? '<a class="btn" href="' . U::h(U::url('dokument_nowy')) . '">+ Nowy dokument</a>' : '') . '</div></div>';
        $card = static fn(string $label, string $num, string $href = '', string $sub = ''): string => ($href !== '' ? '<a class="card stat" href="' . U::h($href) . '">' : '<div class="card stat">')
            . '<div class="label">' . $label . '</div><div class="num">' . $num . '</div>' . ($sub !== '' ? '<div class="kg-small">' . $sub . '</div>' : '') . ($href !== '' ? '</a>' : '</div>');
        $html .= '<div class="kg-cards">'
            . $card('Dokumenty zaksięgowane', (string) (int) ($stat['posted'] ?? 0), U::url('dokumenty', ['status' => 'posted']), 'ostatnia pozycja dziennika: ' . (int) ($stat['last_no'] ?? 0))
            . $card('Szkice do zaksięgowania', (string) (int) ($stat['drafts'] ?? 0), U::url('dokumenty', ['status' => 'draft']))
            . $card('Środki pieniężne (zespół 1)', U::money($cash) . ' zł', U::url('obroty'))
            . $card('Należności od odbiorców (200)', U::money($recv) . ' zł')
            . $card('Zobowiązania wobec dostawców (202)', U::money($pay) . ' zł')
            . $card('Wynik narastająco', '<span class="' . ($result < 0 ? 'kg-bad' : '') . '">' . U::money($result) . ' zł</span>', '', 'przychody minus koszty (konta wynikowe) do ' . U::date($to))
            . '</div>';
        $html .= '<div class="card"><b>Kontrola ksiąg:</b> ' . ($balanced
            ? '<span class="kg-ok">✓ obroty Wn i Ma są równe (' . U::money($tb['totals']['cd']) . ' zł)</span>'
            : '<span class="kg-bad">✗ obroty Wn (' . U::money($tb['totals']['cd']) . ') i Ma (' . U::money($tb['totals']['cc']) . ') się różnią</span>')
            . '<div class="kg-small" style="margin-top:6px">Dane księgowe: ' . (Db::separate() ? 'osobna baza danych (EXT_KSIEGOWOSC_DB_DSN)' : 'baza CRM, tabele ext_ksg_*') . '. Moduł nie czyta tabel CRM - zamówienia trafiają tu tylko przez zdarzenia.</div></div>';
        return $html;
    }

    // ============================================================== lata obrotowe

    public function years(): string
    {
        $years = $this->ledger->years();
        $html = $this->head('lata') . '<div class="kg-top"><p>Rok obrotowy to okres, za który zamyka się księgi (zwykle rok kalendarzowy). Każdy rok ma swój plan kont i swoją numerację dziennika.</p></div>';
        if ($years !== []) {
            $html .= '<div class="card kg-table" style="padding:6px 10px"><table><thead><tr><th>Rok</th><th>Okres</th><th class="kg-num">Konta</th><th class="kg-num">Dokumenty</th><th>Zamknięte do</th>'
                . ($this->can(Access::MANAGE) ? '<th>Zamknij miesiące do</th>' : '') . '</tr></thead><tbody>';
            foreach ($years as $y) {
                $n = $this->db->one('SELECT (SELECT COUNT(*) FROM ext_ksg_accounts WHERE year_id = ?) AS a, (SELECT COUNT(*) FROM ext_ksg_docs WHERE year_id = ?) AS d', [$y['id'], $y['id']]);
                $html .= '<tr><td><b>' . U::h($y['name']) . '</b></td><td>' . U::date($y['starts_on']) . ' - ' . U::date($y['ends_on']) . '</td>'
                    . '<td class="kg-num"><a href="' . U::h(U::url('konta', ['rok' => $y['id']])) . '">' . (int) $n['a'] . '</a></td>'
                    . '<td class="kg-num"><a href="' . U::h(U::url('dokumenty', ['rok' => $y['id']])) . '">' . (int) $n['d'] . '</a></td>'
                    . '<td>' . ($y['locked_until'] ? U::date($y['locked_until']) : '<span class="kg-muted">otwarty</span>') . '</td>';
                if ($this->can(Access::MANAGE)) {
                    $html .= '<td><form method="post" action="' . U::h(U::url('rok_blokada')) . '" class="kg-inline">' . U::csrf($this->ctx)
                        . '<input type="hidden" name="id" value="' . U::h($y['id']) . '"><input type="date" name="locked_until" value="' . U::h($y['locked_until'] ?? '') . '" min="'
                        . U::h($y['starts_on']) . '" max="' . U::h($y['ends_on']) . '"><button class="btn secondary" type="submit">Zapisz</button></form></td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table><p class="kg-small">Zamknięcie miesięcy blokuje księgowanie z datą do wskazanego dnia (np. po wysłaniu JPK). Puste pole zdejmuje blokadę.</p></div>';
        }
        if (!$this->can(Access::MANAGE)) {
            return $years === [] ? $this->noYear('lata') : $html;
        }
        $last = $years[0] ?? null;
        $start = $last !== null ? date('Y-m-d', strtotime($last['ends_on'] . ' +1 day')) : date('Y') . '-01-01';
        $end = date('Y-m-d', strtotime($start . ' +1 year -1 day'));
        $charts = ['template' => 'Wzorcowy plan kont dla spółki z o.o. (' . count(ChartTemplate::spzoo()) . ' kont)', 'empty' => 'Pusty plan kont'];
        foreach ($years as $y) {
            $charts[(string) $y['id']] = 'Kopia planu kont z roku ' . $y['name'];
        }
        $html .= '<div class="card"><h3 style="margin-top:0">' . ($years === [] ? 'Załóż pierwszy rok obrotowy' : 'Nowy rok obrotowy') . '</h3>'
            . '<form method="post" action="' . U::h(U::url('rok_zapisz')) . '" class="kg-form">' . U::csrf($this->ctx)
            . '<div class="kg-grid">' . U::input('name', substr($start, 0, 4), 'Nazwa', 'maxlength="40"')
            . U::input('starts_on', $start, 'Początek', 'type="date" required') . U::input('ends_on', $end, 'Koniec', 'type="date" required', 'Pierwszy rok spółki może być dłuższy, do 24 miesięcy.')
            . '</div>' . U::select('chart', $charts, $last !== null ? (string) $last['id'] : 'template', 'Plan kont')
            . '<div><button class="btn" type="submit">Załóż rok</button></div></form></div>';
        return $html;
    }

    public function yearSave(): void
    {
        $this->need(Access::MANAGE);
        try {
            $y = $this->ledger->createYear((string) ($_POST['name'] ?? ''), (string) ($_POST['starts_on'] ?? ''), (string) ($_POST['ends_on'] ?? ''),
                (string) ($_POST['chart'] ?? 'template'), $this->user());
            flash('Założono rok obrotowy ' . $y['name'] . '.');
        } catch (\RuntimeException $e) {
            flash($e->getMessage(), 'err');
        }
        $this->go('lata');
    }

    public function yearLock(): void
    {
        $this->need(Access::MANAGE);
        try {
            $this->ledger->lockUntil((string) ($_POST['id'] ?? ''), (string) ($_POST['locked_until'] ?? ''), $this->user());
            flash('Zapisano zamknięcie okresu.');
        } catch (\RuntimeException $e) {
            flash($e->getMessage(), 'err');
        }
        $this->go('lata');
    }

    // ============================================================== plan kont

    public function accounts(): string
    {
        $year = $this->pickYear();
        if ($year === null) {
            return $this->noYear('konta');
        }
        $rows = $this->ledger->accounts((string) $year['id']);
        $html = $this->head('konta') . '<div class="kg-top"><p>Plan kont roku <b>' . U::h($year['name']) . '</b>. Zapisy idą na konta bez kont podrzędnych; konto z analityką pokazuje ich sumę.</p>'
            . '<div class="kg-actions">' . U::yearPicker('konta', $this->ledger->years(), (string) $year['id']) . '</div></div>';
        if ($rows === []) {
            $html .= '<div class="card kg-muted">Plan kont jest pusty. Dodaj pierwsze konto poniżej.</div>';
        } else {
            $html .= '<div class="card kg-table" style="padding:6px 10px"><table><thead><tr><th>Numer</th><th>Nazwa</th><th class="kg-hide-m">Rodzaj</th><th class="kg-num kg-hide-m">Zapisy</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $html .= '<tr class="' . ($r['has_children'] ? 'syn' : '') . ((int) $r['active'] !== 1 ? ' off' : '') . '">'
                    . '<td style="padding-left:' . (8 + 16 * $r['level']) . 'px"><a class="kg-acc" href="' . U::h(U::url('konto', ['id' => $r['id']])) . '">' . U::h($r['number']) . '</a></td>'
                    . '<td>' . U::h($r['name']) . ((int) $r['active'] !== 1 ? ' <span class="kg-pill">wyłączone</span>' : '') . '</td>'
                    . '<td class="kg-hide-m">' . U::h(ChartTemplate::KINDS[$r['kind']] ?? $r['kind']) . '</td>'
                    . '<td class="kg-num kg-hide-m">' . ($r['entries'] ?: '') . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        if ($this->can(Access::MANAGE)) {
            $html .= '<div class="card"><h3 style="margin-top:0">Dodaj konto</h3>' . $this->accountForm((string) $year['id'], null, []) . '</div>';
        }
        return $html;
    }

    private function accountForm(string $yearId, ?array $acc, array $in): string
    {
        $v = static fn(string $k, string $d = ''): string => (string) ($in[$k] ?? $acc[$k] ?? $d);
        return '<form method="post" action="' . U::h(U::url('konto_zapisz')) . '" class="kg-form">' . U::csrf($this->ctx)
            . '<input type="hidden" name="year_id" value="' . U::h($yearId) . '">' . ($acc !== null ? '<input type="hidden" name="id" value="' . U::h($acc['id']) . '">' : '')
            . '<div class="kg-grid">' . U::input('number', $v('number'), 'Numer', 'required maxlength="30" placeholder="np. 130-03"', 'Analityka po kresce: 202-ALLEGRO.')
            . U::input('name', $v('name'), 'Nazwa', 'required maxlength="200"')
            . U::select('kind', ChartTemplate::KINDS, $v('kind', 'A'), 'Rodzaj') . '</div>'
            . '<label>Uwagi<textarea name="notes" rows="2" maxlength="2000">' . U::h($v('notes')) . '</textarea></label>'
            . '<div><button class="btn" type="submit">' . ($acc === null ? 'Dodaj konto' : 'Zapisz konto') . '</button></div></form>';
    }

    public function account(): string
    {
        $a = $this->ledger->account((string) ($_GET['id'] ?? ''));
        if ($a === null) {
            return $this->head('konta') . '<div class="flash err">Nie ma takiego konta.</div>';
        }
        $year = $this->ledger->year((string) $a['year_id']);
        [$from, $to] = $this->period($year);
        $l = $this->ledger->accountLedger((string) $a['id'], $from, $to);
        $html = $this->head('konta') . U::back(U::url('konta', ['rok' => $year['id']]), 'Plan kont ' . $year['name'])
            . '<div class="kg-top"><div><h2 style="margin:0"><span class="kg-acc">' . U::h($a['number']) . '</span> ' . U::h($a['name']) . '</h2>'
            . '<p>' . U::h(ChartTemplate::KINDS[$a['kind']] ?? $a['kind']) . ((int) $a['active'] !== 1 ? ' · wyłączone' : '') . ($a['notes'] !== '' ? ' · ' . U::h($a['notes']) : '') . '</p></div>'
            . '<div class="kg-actions"><a class="btn secondary" href="' . U::h(U::url('obroty', ['rok' => $year['id']])) . '">Obroty i salda</a></div></div>'
            . $this->periodForm('konto', $year, $from, $to, ['id' => $a['id']]);
        $html .= '<div class="card kg-table" style="padding:6px 10px"><table><thead><tr><th>Data</th><th>Poz.</th><th>Dokument</th><th>Opis</th><th class="kg-num">Wn</th><th class="kg-num">Ma</th><th class="kg-num">Saldo</th></tr></thead><tbody>'
            . '<tr><td colspan="6" class="kg-muted">Saldo na początek okresu</td><td class="kg-num">' . $this->saldo($l['opening']) . '</td></tr>';
        foreach ($l['rows'] as $r) {
            $html .= '<tr><td>' . U::date($r['posting_date']) . '</td><td class="kg-num">' . (int) $r['journal_no'] . '</td>'
                . '<td><a href="' . U::h(U::url('dokument', ['id' => $r['doc_id']])) . '">' . U::h($r['doc_number']) . '</a>' . ($r['account_number'] !== $a['number'] ? ' <span class="kg-small">' . U::h($r['account_number']) . '</span>' : '') . '</td>'
                . '<td>' . U::h($r['description'] !== '' ? $r['description'] : $r['doc_description']) . '</td>'
                . '<td class="kg-num">' . U::money($r['debit_gr'], true) . '</td><td class="kg-num">' . U::money($r['credit_gr'], true) . '</td><td class="kg-num">' . $this->saldo($r['balance']) . '</td></tr>';
        }
        $html .= '</tbody><tfoot><tr><td colspan="4">Obroty okresu</td><td class="kg-num">' . U::money($l['debit']) . '</td><td class="kg-num">' . U::money($l['credit']) . '</td>'
            . '<td class="kg-num">' . $this->saldo($l['opening'] + $l['debit'] - $l['credit']) . '</td></tr></tfoot></table>'
            . ($l['rows'] === [] ? '<p class="kg-muted">Brak zapisów w tym okresie.</p>' : '') . '</div>';
        if ($this->can(Access::MANAGE)) {
            $html .= '<div class="card"><h3 style="margin-top:0">Popraw konto</h3>' . $this->accountForm((string) $year['id'], $a, [])
                . '<div class="kg-actions" style="margin-top:12px">'
                . U::postButton($this->ctx, 'konto_status', ['id' => $a['id'], 'active' => (int) $a['active'] === 1 ? 0 : 1], (int) $a['active'] === 1 ? 'Wyłącz konto' : 'Włącz konto', 'btn secondary')
                . U::postButton($this->ctx, 'konto_usun', ['id' => $a['id']], 'Usuń konto', 'btn secondary', 'Usunąć konto ' . $a['number'] . '? Da się tylko, gdy nie ma zapisów.')
                . '</div></div>';
        }
        return $html;
    }

    private function saldo(int $bal): string
    {
        return $bal === 0 ? '0,00' : U::money(abs($bal)) . ' ' . ($bal > 0 ? 'Wn' : 'Ma');
    }

    public function accountSave(): string
    {
        $this->need(Access::MANAGE);
        $id = (string) ($_POST['id'] ?? '');
        $yearId = (string) ($_POST['year_id'] ?? '');
        try {
            $a = $this->ledger->saveAccount($yearId, $id !== '' ? $id : null, $_POST, $this->user());
            flash(($id === '' ? 'Dodano konto ' : 'Zapisano konto ') . $a['number'] . '.');
            $this->go($id === '' ? 'konta' : 'konto', $id === '' ? ['rok' => $yearId] : ['id' => $id]);
        } catch (\RuntimeException $e) {
            flash($e->getMessage(), 'err');
            $acc = $id !== '' ? $this->ledger->account($id) : null;
            return $this->head('konta') . U::back(U::url('konta', ['rok' => $yearId]), 'Plan kont') . '<div class="card">' . $this->accountForm($yearId, $acc, $_POST) . '</div>';
        }
    }

    public function accountStatus(): void
    {
        $this->need(Access::MANAGE);
        $id = (string) ($_POST['id'] ?? '');
        try {
            $this->ledger->setAccountActive($id, (int) ($_POST['active'] ?? 0) === 1, $this->user());
            flash((int) ($_POST['active'] ?? 0) === 1 ? 'Konto włączone.' : 'Konto wyłączone - nie da się na nie księgować.');
        } catch (\RuntimeException $e) {
            flash($e->getMessage(), 'err');
        }
        $this->go('konto', ['id' => $id]);
    }

    public function accountDelete(): void
    {
        $this->need(Access::MANAGE);
        $a = $this->ledger->account((string) ($_POST['id'] ?? ''));
        try {
            $this->ledger->deleteAccount((string) ($a['id'] ?? ''), $this->user());
            flash('Usunięto konto ' . $a['number'] . '.');
            $this->go('konta', ['rok' => $a['year_id']]);
        } catch (\RuntimeException $e) {
            flash($e->getMessage(), 'err');
            $this->go('konto', ['id' => $a['id'] ?? '']);
        }
    }

    // ============================================================== dokumenty

    public function docs(): string
    {
        $year = $this->pickYear();
        if ($year === null) {
            return $this->noYear('dokumenty');
        }
        $f = ['month' => (string) ($_GET['m'] ?? ''), 'status' => (string) ($_GET['status'] ?? ''), 'type' => (string) ($_GET['type'] ?? ''),
            'q' => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100)];
        $p = max(1, (int) ($_GET['s'] ?? 1));
        $per = 50;
        $res = $this->ledger->docs((string) $year['id'], $f, $per, ($p - 1) * $per);
        $months = ['' => 'Cały rok'];
        for ($t = strtotime(substr((string) $year['starts_on'], 0, 7) . '-01'); date('Y-m-d', $t) <= $year['ends_on']; $t = strtotime('+1 month', $t)) {
            $months[date('Y-m', $t)] = date('m.Y', $t);
        }
        $html = $this->head('dokumenty') . '<div class="kg-top"><p>Dokumenty księgowe roku <b>' . U::h($year['name']) . '</b>: szkice i zaksięgowane.</p><div class="kg-actions">'
            . U::yearPicker('dokumenty', $this->ledger->years(), (string) $year['id'])
            . ($this->can(Access::BOOK) ? '<a class="btn" href="' . U::h(U::url('dokument_nowy')) . '">+ Nowy dokument</a>' : '') . '</div></div>'
            . '<form class="kg-filter" method="get" action="ext.php"><input type="hidden" name="page" value="ksiegowosc.dokumenty"><input type="hidden" name="rok" value="' . U::h($year['id']) . '">'
            . '<input type="search" name="q" value="' . U::h($f['q']) . '" placeholder="Szukaj po numerze, opisie, kontrahencie">'
            . U::select('m', $months, $f['month'], '', 'onchange="this.form.submit()"')
            . U::select('type', ['' => 'Wszystkie rodzaje'] + Ledger::TYPES, $f['type'], '', 'onchange="this.form.submit()"')
            . U::select('status', ['' => 'Wszystkie', 'draft' => 'Szkice', 'posted' => 'Zaksięgowane'], $f['status'], '', 'onchange="this.form.submit()"')
            . '<button class="btn secondary" type="submit">Szukaj</button></form>';
        if ($res['rows'] === []) {
            return $html . ($res['total'] === 0 && array_filter($f) === []
                ? U::empty('Nie ma jeszcze dokumentów', 'Dokument to zapis operacji z dekretacją: po stronie Wn i Ma, z tą samą sumą. Zaksięgowany dostaje numer pozycji w dzienniku.',
                    $this->can(Access::BOOK) ? '<a class="btn" href="' . U::h(U::url('dokument_nowy')) . '">+ Nowy dokument</a>' : '')
                : '<div class="card kg-muted">Nic nie pasuje do filtrów.</div>');
        }
        $html .= '<div class="card kg-table" style="padding:6px 10px"><table><thead><tr><th>Data</th><th class="kg-num">Poz.</th><th>Numer</th><th>Opis</th><th class="kg-hide-m">Kontrahent</th>'
            . '<th class="kg-num">Kwota</th><th>Status</th></tr></thead><tbody>';
        foreach ($res['rows'] as $d) {
            $html .= '<tr><td>' . U::date($d['posting_date']) . '</td><td class="kg-num">' . ($d['journal_no'] ?? '') . '</td>'
                . '<td><a href="' . U::h(U::url('dokument', ['id' => $d['id']])) . '"><b>' . U::h($d['number']) . '</b></a><div class="kg-small">' . U::h(Ledger::TYPES[$d['type']] ?? $d['type']) . '</div></td>'
                . '<td>' . U::h($d['description']) . '</td><td class="kg-hide-m">' . U::h($d['counterparty']) . '</td>'
                . '<td class="kg-num">' . U::money((int) $d['total_gr']) . '</td><td>' . U::status($d) . '</td></tr>';
        }
        $q = array_filter(['rok' => $year['id'], 'm' => $f['month'], 'status' => $f['status'], 'type' => $f['type'], 'q' => $f['q']], static fn($v): bool => $v !== '');
        return $html . '</tbody></table></div>' . U::pager('dokumenty', $q, $res['total'], $per, $p);
    }

    public function doc(): string
    {
        $d = $this->ledger->doc((string) ($_GET['id'] ?? ''));
        if ($d === null) {
            return $this->head('dokumenty') . '<div class="flash err">Nie ma takiego dokumentu.</div>';
        }
        $entries = $this->ledger->entries((string) $d['id']);
        $html = $this->head('dokumenty') . U::back(U::url('dokumenty', ['rok' => $d['year_id']]), 'Dokumenty')
            . '<div class="kg-top"><div><h2 style="margin:0">' . U::h($d['number']) . ' ' . U::status($d) . '</h2><p>' . U::h(Ledger::TYPES[$d['type']] ?? $d['type'])
            . ($d['journal_no'] !== null ? ' · pozycja dziennika <b>' . (int) $d['journal_no'] . '</b>' : '') . '</p></div><div class="kg-actions">';
        if ($d['status'] === 'draft' && $this->can(Access::BOOK)) {
            $html .= '<a class="btn secondary" href="' . U::h(U::url('dokument_edytuj', ['id' => $d['id']])) . '">Popraw</a>'
                . U::postButton($this->ctx, 'dokument_usun', ['id' => $d['id']], 'Usuń szkic', 'btn secondary', 'Usunąć szkic ' . $d['number'] . '?')
                . U::postButton($this->ctx, 'dokument_ksieguj', ['id' => $d['id']], 'Zaksięguj', 'btn', 'Zaksięgować ' . $d['number'] . '? Po zaksięgowaniu dokumentu nie da się zmienić ani usunąć - tylko wystornować.');
        }
        $html .= '</div></div>';
        if ($d['status'] === 'draft') {
            $problems = $this->ledger->postingProblems($d);
            if ($problems !== []) {
                $html .= '<div class="kg-warn"><b>Przed zaksięgowaniem popraw:</b><ul><li>' . implode('</li><li>', array_map([U::class, 'h'], $problems)) . '</li></ul></div>';
            }
        }
        $html .= '<div class="card"><dl class="kg-dl"><dt>Opis</dt><dd>' . U::h($d['description']) . '</dd>'
            . ($d['counterparty'] !== '' ? '<dt>Kontrahent</dt><dd>' . U::h($d['counterparty']) . '</dd>' : '')
            . '<dt>Data księgowania</dt><dd>' . U::date($d['posting_date']) . '</dd><dt>Data dokumentu</dt><dd>' . U::date($d['doc_date']) . '</dd>'
            . '<dt>Data operacji</dt><dd>' . U::date($d['op_date']) . '</dd>'
            . '<dt>Wprowadził</dt><dd>' . U::h($d['created_by']) . ' · ' . U::h(substr((string) $d['created_at'], 0, 16)) . ' UTC</dd>'
            . ($d['posted_by'] !== null ? '<dt>Zaksięgował</dt><dd>' . U::h($d['posted_by']) . ' · ' . U::h(substr((string) $d['posted_at'], 0, 16)) . ' UTC</dd>' : '');
        if ($d['reversal_of'] !== null && ($orig = $this->ledger->doc((string) $d['reversal_of'])) !== null) {
            $html .= '<dt>Storno dokumentu</dt><dd><a href="' . U::h(U::url('dokument', ['id' => $orig['id']])) . '">' . U::h($orig['number']) . '</a></dd>';
        }
        if ($d['reversed_by'] !== null && ($rev = $this->ledger->doc((string) $d['reversed_by'])) !== null) {
            $html .= '<dt>Wystornowany przez</dt><dd><a href="' . U::h(U::url('dokument', ['id' => $rev['id']])) . '">' . U::h($rev['number']) . '</a> z ' . U::date($rev['posting_date']) . '</dd>';
        }
        $html .= '</dl></div>' . $this->entriesTable($entries);
        if ($d['status'] === 'posted' && $d['type'] !== 'ST' && ($d['reversed_by'] ?? null) === null && $this->can(Access::BOOK)) {
            $html .= '<div class="card"><h3 style="margin-top:0">Storno</h3><p class="kg-muted">Zaksięgowanego dokumentu nie zmienia się. Storno dopisuje dokument z tymi samymi kontami i kwotami z minusem, więc obroty wracają do stanu sprzed błędu. Potem wprowadź dokument jeszcze raz poprawnie.</p>'
                . '<form method="post" action="' . U::h(U::url('dokument_storno')) . '" class="kg-form" onsubmit="return confirm(\'Wystornować ' . U::h($d['number']) . '?\')">' . U::csrf($this->ctx)
                . '<input type="hidden" name="id" value="' . U::h($d['id']) . '"><div class="kg-grid">' . U::input('date', date('Y-m-d'), 'Data storna', 'type="date" required')
                . U::input('reason', '', 'Powód', 'maxlength="150" placeholder="np. błędne konto"') . '</div><div><button class="btn secondary" type="submit">Wystornuj</button></div></form></div>';
        }
        return $html;
    }

    /** @param list<array<string,mixed>> $entries */
    private function entriesTable(array $entries): string
    {
        $html = '<div class="card kg-table" style="padding:6px 10px"><table><thead><tr><th>#</th><th>Konto</th><th>Opis pozycji</th><th class="kg-num">Wn</th><th class="kg-num">Ma</th></tr></thead><tbody>';
        $dt = $ct = 0;
        foreach ($entries as $e) {
            $dt += (int) $e['debit_gr'];
            $ct += (int) $e['credit_gr'];
            $html .= '<tr><td>' . (int) $e['position'] . '</td><td><a class="kg-acc" href="' . U::h(U::url('konto', ['id' => $e['account_id']])) . '">' . U::h($e['account_number'] ?? '?') . '</a> '
                . '<span class="kg-small">' . U::h($e['account_name'] ?? '') . '</span></td><td>' . U::h($e['description']) . '</td>'
                . '<td class="kg-num">' . U::money((int) $e['debit_gr'], true) . '</td><td class="kg-num">' . U::money((int) $e['credit_gr'], true) . '</td></tr>';
        }
        return $html . '</tbody><tfoot><tr><td colspan="3">Razem' . ($dt !== $ct ? ' <span class="kg-bad">(różnica ' . U::money($dt - $ct) . ')</span>' : '') . '</td>'
            . '<td class="kg-num">' . U::money($dt) . '</td><td class="kg-num">' . U::money($ct) . '</td></tr></tfoot></table></div>';
    }

    public function docForm(?array $doc = null, ?array $in = null): string
    {
        $this->need(Access::BOOK);
        if ($doc === null && isset($_GET['id'])) {
            $doc = $this->ledger->doc((string) $_GET['id']);
            if ($doc === null || $doc['status'] !== 'draft') {
                flash('Tego dokumentu nie da się poprawić.', 'err');
                $this->go('dokumenty');
            }
        }
        $year = $doc !== null ? $this->ledger->year((string) $doc['year_id']) : $this->ledger->currentYear();
        if ($year === null) {
            return $this->noYear('dokumenty');
        }
        $lines = [];
        if ($in !== null) {
            $lines = array_values(array_filter((array) ($in['lines'] ?? []), 'is_array'));
        } elseif ($doc !== null) {
            foreach ($this->ledger->entries((string) $doc['id']) as $e) {
                $lines[] = ['account' => $e['account_number'], 'description' => $e['description'], 'debit' => Money::input((int) $e['debit_gr']), 'credit' => Money::input((int) $e['credit_gr'])];
            }
        }
        while (count($lines) < 2) {
            $lines[] = ['account' => '', 'description' => '', 'debit' => '', 'credit' => ''];
        }
        $v = static fn(string $k, string $d = ''): string => (string) ($in[$k] ?? $doc[$k] ?? $d);
        $today = min(max(date('Y-m-d'), (string) $year['starts_on']), (string) $year['ends_on']);
        $types = array_intersect_key(Ledger::TYPES, array_flip(Ledger::MANUAL_TYPES));
        $opts = '';
        foreach ($this->ledger->accounts((string) $year['id'], true) as $a) {
            if (!$a['has_children']) {
                $opts .= '<option value="' . U::h($a['number'] . ' ' . $a['name']) . '"></option>';
            }
        }
        $row = static fn(int $i, array $l): string => '<tr><td><input name="lines[' . $i . '][account]" value="' . U::h($l['account'] ?? '') . '" list="kg-accounts" placeholder="Konto" autocomplete="off"></td>'
            . '<td><input name="lines[' . $i . '][description]" value="' . U::h($l['description'] ?? '') . '" maxlength="255" placeholder="Opis pozycji (opcjonalnie)"></td>'
            . '<td><input class="kg-amt" name="lines[' . $i . '][debit]" value="' . U::h($l['debit'] ?? '') . '" inputmode="decimal" placeholder="Wn"></td>'
            . '<td><input class="kg-amt" name="lines[' . $i . '][credit]" value="' . U::h($l['credit'] ?? '') . '" inputmode="decimal" placeholder="Ma"></td>'
            . '<td><button type="button" class="kg-del" title="Usuń pozycję">×</button></td></tr>';
        $rows = '';
        foreach ($lines as $i => $l) {
            $rows .= $row($i, $l);
        }
        $html = $this->head('dokumenty') . U::back($doc !== null ? U::url('dokument', ['id' => $doc['id']]) : U::url('dokumenty'), $doc !== null ? (string) $doc['number'] : 'Dokumenty')
            . '<form method="post" action="' . U::h(U::url('dokument_zapisz')) . '" class="kg-form" id="kg-doc">' . U::csrf($this->ctx)
            . ($doc !== null ? '<input type="hidden" name="id" value="' . U::h($doc['id']) . '">' : '')
            . '<div class="card"><div class="kg-grid">' . U::select('type', $types, $v('type', 'PK'), 'Rodzaj')
            . U::input('number', $v('number'), 'Numer dokumentu', 'maxlength="60" placeholder="puste = nadany sam"', 'Np. numer faktury dostawcy.')
            . U::input('posting_date', $v('posting_date', $today), 'Data księgowania', 'type="date" required', 'Rok ' . U::h($year['name']) . ': ' . U::date($year['starts_on']) . ' - ' . U::date($year['ends_on']))
            . U::input('doc_date', $v('doc_date'), 'Data dokumentu', 'type="date"', 'Puste = data księgowania.')
            . U::input('op_date', $v('op_date'), 'Data operacji', 'type="date"', 'Puste = data dokumentu.') . '</div>'
            . '<div class="kg-grid" style="margin-top:12px">' . U::input('description', $v('description'), 'Opis operacji', 'required maxlength="255"')
            . U::input('counterparty', $v('counterparty'), 'Kontrahent', 'maxlength="200"') . '</div></div>'
            . '<div class="card kg-table"><h3 style="margin-top:0">Dekretacja</h3><datalist id="kg-accounts">' . $opts . '</datalist>'
            . '<table class="kg-lines"><thead><tr><th style="width:30%">Konto</th><th>Opis</th><th class="kg-num">Wn</th><th class="kg-num">Ma</th><th></th></tr></thead><tbody id="kg-rows">' . $rows . '</tbody></table>'
            . '<div class="kg-actions" style="justify-content:space-between"><button type="button" class="btn secondary" id="kg-add">+ Pozycja</button>'
            . '<div class="kg-sum"><span>Wn: <span id="kg-sd">0,00</span></span><span>Ma: <span id="kg-sc">0,00</span></span><span id="kg-diff"></span></div></div></div>'
            . '<div class="kg-actions"><button class="btn secondary" type="submit" name="save" value="1">Zapisz szkic</button>'
            . '<button class="btn" type="submit" name="post" value="1" onclick="return confirm(\'Zapisać i zaksięgować? Zaksięgowanego dokumentu nie da się zmienić.\')">Zapisz i zaksięguj</button></div></form>';
        $html .= '<script>(function(){var tb=document.getElementById("kg-rows"),n=tb.rows.length;'
            . 'function num(v){v=String(v||"").replace(/\s| /g,"").replace(",",".");var f=parseFloat(v);return isNaN(f)?0:Math.round(f*100);}'
            . 'function fmt(g){var s=g<0?"-":"";g=Math.abs(g);return s+Math.floor(g/100).toString().replace(/\B(?=(\d{3})+(?!\d))/g," ")+","+String(g%100).padStart(2,"0");}'
            . 'function sum(){var d=0,c=0;tb.querySelectorAll("tr").forEach(function(r){var i=r.querySelectorAll(".kg-amt");d+=num(i[0].value);c+=num(i[1].value);});'
            . 'document.getElementById("kg-sd").textContent=fmt(d);document.getElementById("kg-sc").textContent=fmt(c);var x=document.getElementById("kg-diff");'
            . 'x.textContent=d===c?(d?"✓ bilansuje się":""):"różnica "+fmt(d-c);x.className=d===c?"kg-ok":"kg-bad";}'
            . 'document.getElementById("kg-add").addEventListener("click",function(){var r=tb.rows[tb.rows.length-1].cloneNode(true);'
            . 'r.querySelectorAll("input").forEach(function(i){i.value="";i.name=i.name.replace(/lines\[\d+\]/,"lines["+n+"]");});n++;tb.appendChild(r);r.querySelector("input").focus();});'
            . 'tb.addEventListener("click",function(e){if(e.target.classList.contains("kg-del")){if(tb.rows.length>1){e.target.closest("tr").remove();}else{e.target.closest("tr").querySelectorAll("input").forEach(function(i){i.value="";});}sum();}});'
            . 'tb.addEventListener("input",sum);sum();})();</script>';
        return $html;
    }

    public function docSave(): string
    {
        $this->need(Access::BOOK);
        $id = (string) ($_POST['id'] ?? '');
        $old = $id !== '' ? $this->ledger->doc($id) : null;
        try {
            $d = $this->ledger->saveDraft($id !== '' ? $id : null, $_POST, $this->user());
        } catch (\RuntimeException $e) {
            flash($e->getMessage(), 'err');
            return $this->docForm($old, $_POST);
        }
        if (isset($_POST['post'])) {
            try {
                $d = $this->ledger->post((string) $d['id'], $this->user());
                flash('Zaksięgowano ' . $d['number'] . ' (pozycja dziennika ' . $d['journal_no'] . ').');
            } catch (\RuntimeException $e) {
                flash('Zapisano szkic ' . $d['number'] . ', ale ' . lcfirst($e->getMessage()), 'err');
            }
        } else {
            flash('Zapisano szkic ' . $d['number'] . '.');
        }
        $this->go('dokument', ['id' => $d['id']]);
    }

    public function docPost(): void
    {
        $this->need(Access::BOOK);
        $id = (string) ($_POST['id'] ?? '');
        try {
            $d = $this->ledger->post($id, $this->user());
            flash('Zaksięgowano ' . $d['number'] . ' (pozycja dziennika ' . $d['journal_no'] . ').');
        } catch (\RuntimeException $e) {
            flash($e->getMessage(), 'err');
        }
        $this->go('dokument', ['id' => $id]);
    }

    public function docDelete(): void
    {
        $this->need(Access::BOOK);
        $d = $this->ledger->doc((string) ($_POST['id'] ?? ''));
        try {
            $this->ledger->deleteDraft((string) ($d['id'] ?? ''), $this->user());
            flash('Usunięto szkic ' . $d['number'] . '.');
            $this->go('dokumenty', ['rok' => $d['year_id']]);
        } catch (\RuntimeException $e) {
            flash($e->getMessage(), 'err');
            $this->go('dokument', ['id' => $d['id'] ?? '']);
        }
    }

    public function docReverse(): void
    {
        $this->need(Access::BOOK);
        $id = (string) ($_POST['id'] ?? '');
        try {
            $st = $this->ledger->reverse($id, (string) ($_POST['date'] ?? ''), $this->user(), mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 150));
            flash('Wystornowano: ' . $st['number'] . ' (pozycja dziennika ' . $st['journal_no'] . ').');
            $this->go('dokument', ['id' => $st['id']]);
        } catch (\RuntimeException $e) {
            flash($e->getMessage(), 'err');
            $this->go('dokument', ['id' => $id]);
        }
    }

    // ============================================================== zestawienia

    /** Okres z ?od= / ?do=, przycięty do roku; domyślnie od początku roku do dziś (albo końca roku). @return array{0:string,1:string} */
    private function period(array $year): array
    {
        $from = (string) ($_GET['od'] ?? '');
        $to = (string) ($_GET['do'] ?? '');
        if (!Ledger::validDate($from) || $from < $year['starts_on'] || $from > $year['ends_on']) {
            $from = (string) $year['starts_on'];
        }
        if (!Ledger::validDate($to) || $to > $year['ends_on'] || $to < $from) {
            $to = date('Y-m-d') >= $from && date('Y-m-d') <= $year['ends_on'] ? date('Y-m-d') : (string) $year['ends_on'];
        }
        return [$from, $to];
    }

    private function periodForm(string $page, array $year, string $from, string $to, array $keep = []): string
    {
        $hidden = '';
        foreach ($keep + ['rok' => $year['id']] as $k => $v) {
            $hidden .= '<input type="hidden" name="' . U::h($k) . '" value="' . U::h($v) . '">';
        }
        return '<form class="kg-filter" method="get" action="ext.php"><input type="hidden" name="page" value="ksiegowosc.' . U::h($page) . '">' . $hidden
            . '<label>Od<input type="date" name="od" value="' . U::h($from) . '" min="' . U::h($year['starts_on']) . '" max="' . U::h($year['ends_on']) . '"></label>'
            . '<label>Do<input type="date" name="do" value="' . U::h($to) . '" min="' . U::h($year['starts_on']) . '" max="' . U::h($year['ends_on']) . '"></label>'
            . '<button class="btn secondary" type="submit">Pokaż</button></form>';
    }

    public function turnover(): string
    {
        $year = $this->pickYear();
        if ($year === null) {
            return $this->noYear('obroty');
        }
        [$from, $to] = $this->period($year);
        $tb = $this->ledger->turnover((string) $year['id'], $from, $to);
        $hideEmpty = !isset($_GET['wszystkie']);
        $q = ['rok' => $year['id'], 'od' => $from, 'do' => $to];
        $html = $this->head('obroty') . '<div class="kg-top"><p>Zestawienie obrotów i sald za okres ' . U::date($from) . ' - ' . U::date($to) . '. Narastająco = od początku roku ' . U::h($year['name']) . '.</p>'
            . '<div class="kg-actions">' . U::yearPicker('obroty', $this->ledger->years(), (string) $year['id'])
            . '<a class="btn secondary" href="' . U::h(U::url('obroty', $q + ($hideEmpty ? ['wszystkie' => 1] : []))) . '">' . ($hideEmpty ? 'Pokaż konta bez obrotów' : 'Ukryj konta bez obrotów') . '</a>'
            . '<a class="btn secondary" href="' . U::h(U::url('obroty_csv', $q)) . '">⬇ CSV</a></div></div>' . $this->periodForm('obroty', $year, $from, $to);
        $html .= '<div class="card kg-table" style="padding:6px 10px"><table><thead><tr><th rowspan="2">Konto</th><th rowspan="2">Nazwa</th><th colspan="2" class="kg-num">Obroty okresu</th>'
            . '<th colspan="2" class="kg-num kg-hide-m">Obroty narastająco</th><th colspan="2" class="kg-num">Saldo</th></tr>'
            . '<tr><th class="kg-num">Wn</th><th class="kg-num">Ma</th><th class="kg-num kg-hide-m">Wn</th><th class="kg-num kg-hide-m">Ma</th><th class="kg-num">Wn</th><th class="kg-num">Ma</th></tr></thead><tbody>';
        $shown = 0;
        foreach ($tb['rows'] as $r) {
            if ($hideEmpty && $r['cd'] === 0 && $r['cc'] === 0) {
                continue;
            }
            $shown++;
            $html .= '<tr class="' . ($r['has_children'] ? 'syn' : '') . '"><td style="padding-left:' . (8 + 16 * $r['level']) . 'px"><a class="kg-acc" href="'
                . U::h(U::url('konto', ['id' => $r['id'], 'od' => $from, 'do' => $to])) . '">' . U::h($r['number']) . '</a></td><td>' . U::h($r['name']) . '</td>'
                . '<td class="kg-num">' . U::money($r['pd'], true) . '</td><td class="kg-num">' . U::money($r['pc'], true) . '</td>'
                . '<td class="kg-num kg-hide-m">' . U::money($r['cd'], true) . '</td><td class="kg-num kg-hide-m">' . U::money($r['cc'], true) . '</td>'
                . '<td class="kg-num">' . U::money($r['sd'], true) . '</td><td class="kg-num">' . U::money($r['sc'], true) . '</td></tr>';
        }
        $t = $tb['totals'];
        $html .= '</tbody><tfoot><tr><td colspan="2">Razem (konta syntetyczne)</td><td class="kg-num">' . U::money($t['pd']) . '</td><td class="kg-num">' . U::money($t['pc']) . '</td>'
            . '<td class="kg-num kg-hide-m">' . U::money($t['cd']) . '</td><td class="kg-num kg-hide-m">' . U::money($t['cc']) . '</td>'
            . '<td class="kg-num">' . U::money($t['sd']) . '</td><td class="kg-num">' . U::money($t['sc']) . '</td></tr></tfoot></table>'
            . ($shown === 0 ? '<p class="kg-muted">Brak zaksięgowanych obrotów w tym okresie.</p>' : '')
            . '<p class="kg-small">' . ($t['pd'] === $t['pc'] && $t['cd'] === $t['cc'] ? '<span class="kg-ok">✓ Obroty Wn i Ma są równe.</span>' : '<span class="kg-bad">✗ Obroty Wn i Ma się różnią - sprawdź dokumenty.</span>') . '</p></div>';
        return $html;
    }

    public function turnoverCsv(): void
    {
        $year = $this->pickYear();
        if ($year === null) {
            http_response_code(404);
            return;
        }
        [$from, $to] = $this->period($year);
        $tb = $this->ledger->turnover((string) $year['id'], $from, $to);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="obroty-i-salda-' . $from . '-' . $to . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\u{FEFF}");
        $n = static fn(int $gr): string => str_replace("\u{a0}", '', Money::format($gr));
        fputcsv($out, ['Konto', 'Nazwa', 'Obroty okresu Wn', 'Obroty okresu Ma', 'Obroty narastająco Wn', 'Obroty narastająco Ma', 'Saldo Wn', 'Saldo Ma'], ';', '"', '');
        foreach ($tb['rows'] as $r) {
            // Nazwy z planu kont trafiają do Excela - znak = + - @ na początku nie może stać się formułą.
            $name = preg_match('/^[=+\-@]/', (string) $r['name']) ? "'" . $r['name'] : (string) $r['name'];
            fputcsv($out, [$r['number'], $name, $n($r['pd']), $n($r['pc']), $n($r['cd']), $n($r['cc']), $n($r['sd']), $n($r['sc'])], ';', '"', '');
        }
        $t = $tb['totals'];
        fputcsv($out, ['', 'Razem', $n($t['pd']), $n($t['pc']), $n($t['cd']), $n($t['cc']), $n($t['sd']), $n($t['sc'])], ';', '"', '');
        fclose($out);
    }

    public function journal(): string
    {
        $year = $this->pickYear();
        if ($year === null) {
            return $this->noYear('dziennik');
        }
        [$from, $to] = $this->period($year);
        $docs = $this->ledger->journal((string) $year['id'], $from, $to);
        $html = $this->head('dziennik') . '<div class="kg-top"><p>Dziennik: zaksięgowane dokumenty w kolejności pozycji, za okres ' . U::date($from) . ' - ' . U::date($to) . '.</p>'
            . '<div class="kg-actions">' . U::yearPicker('dziennik', $this->ledger->years(), (string) $year['id']) . '</div></div>' . $this->periodForm('dziennik', $year, $from, $to);
        if ($docs === []) {
            return $html . '<div class="card kg-muted">Brak zaksięgowanych dokumentów w tym okresie.</div>';
        }
        $html .= '<div class="card">';
        $dt = $ct = 0;
        foreach ($docs as $d) {
            $html .= '<div class="kg-jdoc"><b>Poz. ' . (int) $d['journal_no'] . '</b> · ' . U::date($d['posting_date']) . ' · <a href="' . U::h(U::url('dokument', ['id' => $d['id']])) . '">' . U::h($d['number']) . '</a>'
                . ' · ' . U::h($d['description']) . ' <span class="kg-small">(' . U::h($d['posted_by']) . ')</span><div class="kg-table"><table><tbody>';
            foreach ($d['entries'] as $e) {
                $dt += (int) $e['debit_gr'];
                $ct += (int) $e['credit_gr'];
                $html .= '<tr><td style="width:120px"><span class="kg-acc">' . U::h($e['account_number']) . '</span></td><td>' . U::h($e['description'] !== '' ? $e['description'] : $e['account_name']) . '</td>'
                    . '<td class="kg-num" style="width:130px">' . U::money((int) $e['debit_gr'], true) . '</td><td class="kg-num" style="width:130px">' . U::money((int) $e['credit_gr'], true) . '</td></tr>';
            }
            $html .= '</tbody></table></div></div>';
        }
        return $html . '<div class="kg-sum">Razem okres: Wn ' . U::money($dt) . ' · Ma ' . U::money($ct) . '</div></div>';
    }

    // ============================================================== firma, dostęp, historia, skrzynka

    public function companyPage(): string
    {
        $this->need(Access::MANAGE);
        $c = $this->company();
        $html = $this->head('firma') . '<div class="kg-top"><p>Dane firmy do ksiąg, deklaracji i faktur. Zapisane w bazie księgowości, nie w ustawieniach CRM.</p></div>'
            . '<form method="post" action="' . U::h(U::url('firma_zapisz')) . '" class="kg-form">' . U::csrf($this->ctx) . '<div class="card"><div class="kg-grid">';
        foreach (self::COMPANY_FIELDS as $k => $label) {
            $html .= U::input('c[' . $k . ']', $c[$k] ?? '', $label, 'maxlength="200"' . ($k === 'name' ? ' required' : ''));
        }
        $html .= U::select('c[legal_form]', self::LEGAL_FORMS, (string) ($c['legal_form'] ?? 'spzoo'), 'Forma prawna')
            . U::select('c[vat_period]', self::VAT_PERIODS, (string) ($c['vat_period'] ?? 'month'), 'VAT') . '</div></div>'
            . '<div class="card"><h3 style="margin-top:0">Zdarzenia z CRM</h3><label class="kg-check"><input type="checkbox" name="inbox" value="1"' . ($this->db->meta('inbox', '0') === '1' ? ' checked' : '') . '> '
            . 'Zbieraj zdarzenia zamówień (nowe zamówienie, zmiana statusu) w skrzynce księgowości</label>'
            . '<p class="kg-small">Kopia danych zamówienia z chwili zdarzenia. Z niej kolejne etapy zrobią faktury i zapisy sprzedaży - bez czytania tabel CRM.</p></div>'
            . '<div><button class="btn" type="submit">Zapisz</button></div></form>';
        return $html;
    }

    public function companySave(): void
    {
        $this->need(Access::MANAGE);
        $in = (array) ($_POST['c'] ?? []);
        $c = [];
        foreach (array_keys(self::COMPANY_FIELDS) as $k) {
            $c[$k] = mb_substr(trim((string) ($in[$k] ?? '')), 0, 200);
        }
        $c['nip'] = preg_replace('/[^0-9A-Z]/', '', strtoupper($c['nip']));
        if ($c['nip'] !== '' && !self::validNip(preg_replace('/^PL/', '', $c['nip']))) {
            flash('NIP ' . $c['nip'] . ' ma złą sumę kontrolną.', 'err');
            $this->go('firma');
        }
        $c['legal_form'] = isset(self::LEGAL_FORMS[$in['legal_form'] ?? '']) ? (string) $in['legal_form'] : 'spzoo';
        $c['vat_period'] = isset(self::VAT_PERIODS[$in['vat_period'] ?? '']) ? (string) $in['vat_period'] : 'month';
        $this->db->setMeta('company', (string) json_encode($c, JSON_UNESCAPED_UNICODE));
        $this->db->setMeta('inbox', isset($_POST['inbox']) ? '1' : '0');
        $this->db->log($this->user(), 'company.update', 'company', '', $c['name'] . ', NIP ' . $c['nip'] . ', skrzynka ' . (isset($_POST['inbox']) ? 'wł.' : 'wył.'));
        flash('Zapisano dane firmy.');
        $this->go('firma');
    }

    public static function validNip(string $nip): bool
    {
        if (!preg_match('/^\d{10}$/', $nip)) {
            return false;
        }
        $w = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $s = 0;
        for ($i = 0; $i < 9; $i++) {
            $s += (int) $nip[$i] * $w[$i];
        }
        return $s % 11 === (int) $nip[9];
    }

    public function accessPage(): string
    {
        $this->need(Access::MANAGE);
        $access = new Access($this->db);
        $levels = $access->all();
        $users = Access::crmUsers($this->crm);
        $html = $this->head('dostep') . '<div class="kg-top"><p>Kto widzi i prowadzi księgi. To osobne uprawnienia od ról w CRM: domyślnie nikt poza administratorem nie ma dostępu.</p></div>'
            . '<form method="post" action="' . U::h(U::url('dostep_zapisz')) . '">' . U::csrf($this->ctx)
            . '<div class="card kg-table" style="padding:6px 10px"><table><thead><tr><th>Użytkownik</th><th>Rola w CRM</th><th>Dostęp do księgowości</th></tr></thead><tbody>';
        foreach ($users as $u) {
            $html .= '<tr><td><b>' . U::h($u['name']) . '</b> <span class="kg-small">' . U::h($u['username']) . '</span></td><td>' . U::h($u['role']) . '</td><td>'
                . ($u['role'] === 'admin' ? '<span class="kg-muted">Pełny (administrator CRM)</span>'
                    : U::select('level[' . $u['id'] . ']', Access::LEVELS, $levels[$u['id']] ?? Access::NONE)
                    . ($u['role'] === 'viewer' && in_array($levels[$u['id']] ?? '', [Access::BOOK, Access::MANAGE], true) ? '<div class="kg-small">Rola „podgląd” w CRM nie pozwala zapisywać - będzie tylko podgląd.</div>' : ''))
                . '</td></tr>';
        }
        return $html . '</tbody></table><p class="kg-small">Podgląd ksiąg: dokumenty, dziennik, plan kont, obroty. Księgowanie: dodawanie, księgowanie i stornowanie dokumentów. '
            . 'Zarządzanie: także plan kont, lata obrotowe, dane firmy, dostęp i historia zmian.</p></div><div><button class="btn" type="submit">Zapisz dostęp</button></div></form>';
    }

    public function accessSave(): void
    {
        $this->need(Access::MANAGE);
        $access = new Access($this->db);
        $roles = array_column(Access::crmUsers($this->crm), 'role', 'id');
        foreach ((array) ($_POST['level'] ?? []) as $uid => $level) {
            $uid = (int) $uid;
            if (isset($roles[$uid]) && $roles[$uid] !== 'admin' && isset(Access::LEVELS[(string) $level])) {
                $access->set($uid, (string) $level, $this->user());
            }
        }
        flash('Zapisano dostęp do księgowości.');
        $this->go('dostep');
    }

    public function history(): string
    {
        $this->need(Access::MANAGE);
        $p = max(1, (int) ($_GET['s'] ?? 1));
        $per = 100;
        $total = (int) ($this->db->one('SELECT COUNT(*) AS n FROM ext_ksg_log')['n'] ?? 0);
        $rows = $this->db->all('SELECT * FROM ext_ksg_log ORDER BY created_at DESC, id LIMIT ' . $per . ' OFFSET ' . (($p - 1) * $per));
        $html = $this->head('zmiany') . '<div class="kg-top"><p>Każda zmiana w księgowości: kto, kiedy i co (czas UTC).</p></div>';
        if ($rows === []) {
            return $html . '<div class="card kg-muted">Jeszcze nic się nie zmieniło.</div>';
        }
        $names = ['year.create' => 'Nowy rok', 'year.lock' => 'Zamknięcie okresu', 'account.create' => 'Nowe konto', 'account.update' => 'Zmiana konta',
            'account.activate' => 'Włączenie konta', 'account.deactivate' => 'Wyłączenie konta', 'account.delete' => 'Usunięcie konta', 'doc.create' => 'Nowy dokument',
            'doc.update' => 'Poprawa szkicu', 'doc.delete' => 'Usunięcie szkicu', 'doc.post' => 'Zaksięgowanie', 'doc.reverse' => 'Storno', 'access' => 'Dostęp',
            'company.update' => 'Dane firmy'];
        $html .= '<div class="card kg-table" style="padding:6px 10px"><table><thead><tr><th>Kiedy</th><th>Kto</th><th>Co</th><th>Szczegóły</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $link = $r['object_type'] === 'doc' && $r['action'] !== 'doc.delete' ? U::url('dokument', ['id' => $r['object_id']])
                : ($r['object_type'] === 'account' && $r['action'] !== 'account.delete' ? U::url('konto', ['id' => $r['object_id']]) : '');
            $html .= '<tr><td class="kg-num" style="text-align:left">' . U::h(substr((string) $r['created_at'], 0, 16)) . '</td><td>' . U::h($r['user_name']) . '</td>'
                . '<td>' . U::h($names[$r['action']] ?? $r['action']) . '</td><td>' . ($link !== '' ? '<a href="' . U::h($link) . '">' . U::h($r['details']) . '</a>' : U::h($r['details'])) . '</td></tr>';
        }
        return $html . '</tbody></table></div>' . U::pager('zmiany', [], $total, $per, $p);
    }

    public function inbox(): string
    {
        $this->need(Access::MANAGE);
        $p = max(1, (int) ($_GET['s'] ?? 1));
        $res = (new Inbox($this->db))->list(50, ($p - 1) * 50);
        $on = $this->db->meta('inbox', '0') === '1';
        $html = $this->head('skrzynka') . '<div class="kg-top"><p>Zdarzenia zamówień, które CRM przekazał księgowości. To jedyna droga danych z CRM do ksiąg. '
            . ($on ? 'Zbieranie jest <b>włączone</b>.' : 'Zbieranie jest <b>wyłączone</b> - włączysz je w zakładce Firma.') . '</p></div>';
        if ($res['rows'] === []) {
            return $html . '<div class="card kg-muted">Skrzynka jest pusta.</div>';
        }
        $html .= '<div class="card kg-table" style="padding:6px 10px"><table><thead><tr><th>Kiedy (UTC)</th><th>Zdarzenie</th><th>Zamówienie</th><th class="kg-hide-m">Kwota</th><th>Przetworzone</th></tr></thead><tbody>';
        foreach ($res['rows'] as $r) {
            $pl = json_decode((string) $r['payload'], true) ?: [];
            $html .= '<tr><td>' . U::h(substr((string) $r['received_at'], 0, 16)) . '</td><td>' . U::h(Inbox::EVENTS[$r['event']] ?? $r['event'])
                . (isset($pl['status']) ? ' <span class="kg-small">' . U::h($pl['status']) . '</span>' : '') . '</td>'
                . '<td>' . U::h($r['ref_id']) . (isset($pl['billing']['last_name']) ? ' <span class="kg-small">' . U::h(trim(($pl['billing']['first_name'] ?? '') . ' ' . $pl['billing']['last_name'])) . '</span>' : '') . '</td>'
                . '<td class="kg-hide-m">' . U::h(isset($pl['total']) ? $pl['total'] . ' ' . ($pl['currency'] ?? '') : '') . '</td>'
                . '<td>' . ($r['processed_at'] ? U::h($r['result'] ?: 'tak') : '<span class="kg-muted">czeka (kolejne etapy)</span>') . '</td></tr>';
        }
        return $html . '</tbody></table></div>' . U::pager('skrzynka', [], $res['total'], 50, $p);
    }
}
