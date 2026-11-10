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

    /** @param ?\Closure():Wfirma $wfirma klient API wFirma z kluczami z ustawień wtyczki */
    public function __construct(private readonly Db $db, private readonly array $ctx, private readonly string $level, private readonly PDO $crm,
        private readonly ?\Closure $wfirma = null)
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
        if ($d['type'] !== 'ST' && ($warnings = $this->ledger->warnings($d)) !== []) {
            $html .= '<div class="kg-warn" style="background:var(--surface-2);color:var(--ink-2)"><b>Do sprawdzenia:</b><ul><li>' . implode('</li><li>', array_map([U::class, 'h'], $warnings)) . '</li></ul></div>';
        }
        $html .= '<div class="card"><dl class="kg-dl"><dt>Opis</dt><dd>' . U::h($d['description']) . '</dd>'
            . (($c = ($d['contractor_id'] ?? null) ? (new Contractors($this->db))->get((string) $d['contractor_id']) : null) !== null
                ? '<dt>Kontrahent</dt><dd><a href="' . U::h(U::url('kontrahent', ['id' => $c['id']])) . '">' . U::h($c['name']) . '</a>' . ($c['nip'] !== '' ? ' · NIP ' . U::h($c['nip']) : '') . '</dd>'
                : ($d['counterparty'] !== '' ? '<dt>Kontrahent</dt><dd>' . U::h($d['counterparty']) . '</dd>' : ''))
            . '<dt>Data księgowania</dt><dd>' . U::date($d['posting_date']) . '</dd><dt>Data dokumentu</dt><dd>' . U::date($d['doc_date']) . '</dd>'
            . '<dt>Data operacji</dt><dd>' . U::date($d['op_date']) . '</dd>'
            . (($d['due_date'] ?? null) !== null ? '<dt>Termin płatności</dt><dd>' . U::date($d['due_date']) . '</dd>' : '')
            . '<dt>Wprowadził</dt><dd>' . U::h($d['created_by']) . ' · ' . U::h(substr((string) $d['created_at'], 0, 16)) . ' UTC</dd>'
            . ($d['posted_by'] !== null ? '<dt>Zaksięgował</dt><dd>' . U::h($d['posted_by']) . ' · ' . U::h(substr((string) $d['posted_at'], 0, 16)) . ' UTC</dd>' : '');
        if ($d['reversal_of'] !== null && ($orig = $this->ledger->doc((string) $d['reversal_of'])) !== null) {
            $html .= '<dt>Storno dokumentu</dt><dd><a href="' . U::h(U::url('dokument', ['id' => $orig['id']])) . '">' . U::h($orig['number']) . '</a></dd>';
        }
        if ($d['reversed_by'] !== null && ($rev = $this->ledger->doc((string) $d['reversed_by'])) !== null) {
            $html .= '<dt>Wystornowany przez</dt><dd><a href="' . U::h(U::url('dokument', ['id' => $rev['id']])) . '">' . U::h($rev['number']) . '</a> z ' . U::date($rev['posting_date']) . '</dd>';
        }
        $html .= '</dl></div>' . $this->vatTable((new Vat($this->db))->forDoc((string) $d['id'])) . $this->entriesTable($entries) . $this->docSettlements($d);
        if ($d['status'] === 'posted' && $d['type'] !== 'ST' && ($d['reversed_by'] ?? null) === null && $this->can(Access::BOOK)) {
            $html .= '<div class="card"><h3 style="margin-top:0">Storno</h3><p class="kg-muted">Zaksięgowanego dokumentu nie zmienia się. Storno dopisuje dokument z tymi samymi kontami i kwotami z minusem, więc obroty wracają do stanu sprzed błędu. Potem wprowadź dokument jeszcze raz poprawnie.</p>'
                . '<form method="post" action="' . U::h(U::url('dokument_storno')) . '" class="kg-form" onsubmit="return confirm(\'Wystornować ' . U::h($d['number']) . '?\')">' . U::csrf($this->ctx)
                . '<input type="hidden" name="id" value="' . U::h($d['id']) . '"><div class="kg-grid">' . U::input('date', date('Y-m-d'), 'Data storna', 'type="date" required')
                . U::input('reason', '', 'Powód', 'maxlength="150" placeholder="np. błędne konto"') . '</div><div><button class="btn secondary" type="submit">Wystornuj</button></div></form></div>';
        }
        return $html;
    }

    /** @param list<array<string,mixed>> $vat */
    private function vatTable(array $vat): string
    {
        if ($vat === []) {
            return '';
        }
        $html = '<div class="card kg-table" style="padding:6px 10px"><table><thead><tr><th>Rejestr VAT</th><th>Okres</th><th>Stawka</th><th class="kg-num">Netto</th><th class="kg-num">VAT</th><th class="kg-num">Brutto</th></tr></thead><tbody>';
        $n = $t = 0;
        foreach ($vat as $x) {
            $n += (int) $x['net_gr'];
            $t += (int) $x['vat_gr'];
            $html .= '<tr><td>' . ($x['register'] === 'S' ? 'Sprzedaż' : 'Zakup' . ' <span class="kg-small">' . U::h(Vat::PURCHASE_KINDS[$x['kind']] ?? '') . ($x['deduct'] !== '100' ? ', ' . U::h(Vat::DEDUCT[$x['deduct']] ?? '') : '') . '</span>') . '</td>'
                . '<td><a href="' . U::h(U::url('vat', ['m' => $x['period'], 'r' => $x['register']])) . '">' . U::h($x['period']) . '</a></td><td>' . U::h(Vat::RATES[$x['rate']][0] ?? $x['rate']) . '</td>'
                . '<td class="kg-num">' . U::money((int) $x['net_gr']) . '</td><td class="kg-num">' . U::money((int) $x['vat_gr']) . '</td><td class="kg-num">' . U::money((int) $x['net_gr'] + (int) $x['vat_gr']) . '</td></tr>';
        }
        return $html . '</tbody><tfoot><tr><td colspan="3">Razem</td><td class="kg-num">' . U::money($n) . '</td><td class="kg-num">' . U::money($t) . '</td><td class="kg-num">' . U::money($n + $t) . '</td></tr></tfoot></table></div>';
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
        $vatRows = [];
        if ($in !== null) {
            $vatRows = array_values(array_filter((array) ($in['vat'] ?? []), 'is_array'));
        } elseif ($doc !== null) {
            foreach ((new Vat($this->db))->forDoc((string) $doc['id']) as $x) {
                $vatRows[] = ['register' => $x['register'], 'rate' => $x['rate'], 'net' => Money::input((int) $x['net_gr']), 'vat' => Money::input((int) $x['vat_gr']),
                    'period' => $x['period'], 'kind' => $x['kind'], 'deduct' => $x['deduct']];
            }
        }
        while (count($vatRows) < 1) {
            $vatRows[] = ['register' => '', 'rate' => '23', 'net' => '', 'vat' => '', 'period' => '', 'kind' => 'other', 'deduct' => '100'];
        }
        $rates = [];
        foreach (Vat::RATES as $k => [$label]) {
            $rates[(string) $k] = $label;
        }
        $vatRow = static function (int $i, array $x) use ($rates): string {
            $reg = (string) ($x['register'] ?? '');
            return '<tr><td>' . U::select('vat[' . $i . '][register]', ['' => 'wg rodzaju', 'S' => 'Sprzedaż', 'Z' => 'Zakup'], $reg) . '</td>'
                . '<td>' . U::select('vat[' . $i . '][rate]', $rates, (string) ($x['rate'] ?? '23'), '', 'class="kg-vrate"') . '</td>'
                . '<td><input class="kg-amt-v kg-vnet" name="vat[' . $i . '][net]" value="' . U::h($x['net'] ?? '') . '" inputmode="decimal" placeholder="Netto"></td>'
                . '<td><input class="kg-amt-v kg-vvat" name="vat[' . $i . '][vat]" value="' . U::h($x['vat'] ?? '') . '" inputmode="decimal" placeholder="VAT (sam)"></td>'
                . '<td><input type="month" name="vat[' . $i . '][period]" value="' . U::h($x['period'] ?? '') . '" title="Okres JPK; puste = miesiąc operacji (sprzedaż) albo księgowania (zakup)"></td>'
                . '<td>' . U::select('vat[' . $i . '][kind]', Vat::PURCHASE_KINDS, (string) ($x['kind'] ?? 'other'))
                . U::select('vat[' . $i . '][deduct]', Vat::DEDUCT, (string) ($x['deduct'] ?? '100')) . '</td>'
                . '<td><button type="button" class="kg-del" title="Usuń pozycję">×</button></td></tr>';
        };
        $vatHtml = '';
        foreach ($vatRows as $i => $x) {
            $vatHtml .= $vatRow($i, $x);
        }
        $contractors = ['' => '- bez kontrahenta z kartoteki -'];
        foreach ((new Contractors($this->db))->list() as $c) {
            $contractors[(string) $c['id']] = $c['name'] . ($c['nip'] !== '' ? ' · NIP ' . $c['nip'] : '') . ' (' . $c['code'] . ')';
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
            . U::input('op_date', $v('op_date'), 'Data operacji', 'type="date"', 'Puste = data dokumentu.')
            . U::input('due_date', $v('due_date'), 'Termin płatności', 'type="date"', 'Faktury: od niego liczą się zaległości. Puste = data dokumentu.') . '</div>'
            . '<div class="kg-grid" style="margin-top:12px">' . U::input('description', $v('description'), 'Opis operacji', 'required maxlength="255"')
            . U::select('contractor_id', $contractors, $v('contractor_id'), 'Kontrahent z kartoteki')
            . U::input('counterparty', $v('counterparty'), 'Kontrahent (tekst)', 'maxlength="200"', 'Puste = nazwa z kartoteki. <a href="' . U::h(U::url('kontrahent', ['nowy' => 1])) . '" target="_blank">+ nowy kontrahent</a>') . '</div></div>'
            . '<div class="card kg-table"><h3 style="margin-top:0">VAT</h3><p class="kg-small" style="margin-top:-6px">Pozycje do rejestru VAT. Faktura sprzedaży trafia do rejestru sprzedaży, zakupu do rejestru zakupu (albo wybierz sam). Pole VAT puste = policzy się ze stawki. Rodzaj i odliczenie dotyczą tylko zakupu.</p>'
            . '<table class="kg-lines"><thead><tr><th>Rejestr</th><th>Stawka</th><th class="kg-num">Netto</th><th class="kg-num">VAT</th><th>Okres</th><th>Zakup</th><th></th></tr></thead><tbody id="kg-vrows">' . $vatHtml . '</tbody></table>'
            . '<div class="kg-actions" style="justify-content:space-between"><button type="button" class="btn secondary" id="kg-vadd">+ Stawka</button>'
            . '<div class="kg-actions">' . U::input('counter_account', (string) ($in['counter_account'] ?? ''), '', 'list="kg-accounts" placeholder="Konto przychodu / kosztu (np. 700, 401)" style="min-width:260px" autocomplete="off"')
            . '<button class="btn secondary" type="submit" name="suggest" value="1" formnovalidate title="Wypełnia dekretację z pozycji VAT i kontrahenta">↓ Podpowiedz dekret</button></div></div></div>'
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
            . 'tb.addEventListener("input",sum);sum();'
            . 'var vb=document.getElementById("kg-vrows"),vn=vb.rows.length,pct={"23":23,"8":8,"5":5};'
            . 'function vcalc(){vb.querySelectorAll("tr").forEach(function(r){var p=pct[r.querySelector(".kg-vrate").value]||0,g=num(r.querySelector(".kg-vnet").value);r.querySelector(".kg-vvat").placeholder=g?fmt(Math.round(g*p/100))+" (sam)":"VAT (sam)";});}'
            . 'document.getElementById("kg-vadd").addEventListener("click",function(){var r=vb.rows[vb.rows.length-1].cloneNode(true);'
            . 'r.querySelectorAll("input").forEach(function(i){i.value="";});r.querySelectorAll("input,select").forEach(function(i){i.name=i.name.replace(/vat\\[\\d+\\]/,"vat["+vn+"]");});vn++;vb.appendChild(r);vcalc();});'
            . 'vb.addEventListener("click",function(e){if(e.target.classList.contains("kg-del")){if(vb.rows.length>1){e.target.closest("tr").remove();}else{e.target.closest("tr").querySelectorAll("input").forEach(function(i){i.value="";});}vcalc();}});'
            . 'vb.addEventListener("input",vcalc);vb.addEventListener("change",vcalc);vcalc();})();</script>';
        return $html;
    }

    public function docSave(): string
    {
        $this->need(Access::BOOK);
        $id = (string) ($_POST['id'] ?? '');
        $old = $id !== '' ? $this->ledger->doc($id) : null;
        if (isset($_POST['suggest'])) {
            return $this->docForm($old, $this->withSuggestion($_POST));
        }
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

    /** Formularz z dekretacją podpowiedzianą z pozycji VAT (nic nie zapisuje). */
    private function withSuggestion(array $in): array
    {
        try {
            $type = (string) ($in['type'] ?? 'PK');
            $posting = (string) ($in['posting_date'] ?? '');
            $year = (Ledger::validDate($posting) ? $this->ledger->yearFor($posting) : null) ?? throw new \RuntimeException('Najpierw podaj datę księgowania w roku obrotowym.');
            $op = (string) ($in['op_date'] ?? '') ?: ((string) ($in['doc_date'] ?? '') ?: $posting);
            $vat = Vat::parse((array) ($in['vat'] ?? []), $type, substr($op, 0, 7), substr($posting, 0, 7));
            $c = trim((string) ($in['contractor_id'] ?? '')) !== '' ? (new Contractors($this->db))->get((string) $in['contractor_id']) : null;
            $in['lines'] = $this->ledger->suggestLines((string) $year['id'], $type, $c, $vat, (string) ($in['counter_account'] ?? ''));
            if (trim((string) ($in['description'] ?? '')) === '' && $vat !== []) {
                $in['description'] = ($vat[0]['register'] === 'S' ? 'Sprzedaż' : 'Zakup') . ($c !== null ? ' - ' . $c['name'] : '');
            }
            flash('Podpowiedziano dekretację - sprawdź konta i zapisz.');
        } catch (\RuntimeException $e) {
            flash($e->getMessage(), 'err');
        }
        return $in;
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

    // ============================================================== kontrahenci

    public function contractors(): string
    {
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        $all = isset($_GET['wszyscy']);
        $rows = (new Contractors($this->db))->list($q, $all);
        $html = $this->head('kontrahenci') . '<div class="kg-top"><p>Kartoteka kontrahentów księgowości. Każdy ma własne konta rozrachunkowe (200-kod odbiorca, 202-kod dostawca).</p>'
            . '<div class="kg-actions">' . ($this->can(Access::BOOK) ? '<a class="btn" href="' . U::h(U::url('kontrahent', ['nowy' => 1])) . '">+ Nowy kontrahent</a>' : '') . '</div></div>'
            . '<form class="kg-filter" method="get" action="ext.php"><input type="hidden" name="page" value="ksiegowosc.kontrahenci">'
            . '<input type="search" name="q" value="' . U::h($q) . '" placeholder="Szukaj po nazwie, NIP-ie, kodzie, mieście">'
            . ($all ? '<input type="hidden" name="wszyscy" value="1">' : '') . '<button class="btn secondary" type="submit">Szukaj</button>'
            . '<a class="btn secondary' . ($all ? ' on' : '') . '" href="' . U::h(U::url('kontrahenci', $all ? [] : ['wszyscy' => 1])) . '">' . ($all ? 'Tylko aktywni' : 'Także wyłączeni') . '</a></form>';
        if ($rows === []) {
            return $html . ($q === '' ? U::empty('Nie ma jeszcze kontrahentów', 'Dodaj odbiorców i dostawców, żeby faktury trafiały do rejestrów VAT z NIP-em, a rozrachunki z każdym liczyły się osobno.',
                $this->can(Access::BOOK) ? '<a class="btn" href="' . U::h(U::url('kontrahent', ['nowy' => 1])) . '">+ Nowy kontrahent</a>' : '') : '<div class="card kg-muted">Nic nie pasuje.</div>');
        }
        $html .= '<div class="card kg-table" style="padding:6px 10px"><table><thead><tr><th>Kod</th><th>Nazwa</th><th>NIP</th><th class="kg-hide-m">Miejscowość</th><th class="kg-hide-m">Rodzaj</th></tr></thead><tbody>';
        foreach ($rows as $c) {
            $html .= '<tr class="' . ((int) $c['active'] !== 1 ? 'off' : '') . '"><td class="kg-acc">' . U::h($c['code']) . '</td><td><a href="' . U::h(U::url('kontrahent', ['id' => $c['id']])) . '"><b>' . U::h($c['name']) . '</b></a></td>'
                . '<td>' . U::h(($c['country'] !== 'PL' ? $c['country'] . ' ' : '') . $c['nip']) . '</td><td class="kg-hide-m">' . U::h($c['city']) . '</td>'
                . '<td class="kg-hide-m">' . U::h(Contractors::KINDS[$c['kind']] ?? $c['kind']) . '</td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    private function contractorForm(?array $c, array $in = []): string
    {
        $v = static fn(string $k, string $d = ''): string => (string) ($in[$k] ?? $c[$k] ?? $d);
        return '<form method="post" action="' . U::h(U::url('kontrahent_zapisz')) . '" class="kg-form">' . U::csrf($this->ctx)
            . ($c !== null ? '<input type="hidden" name="id" value="' . U::h($c['id']) . '">' : '')
            . '<div class="kg-grid">' . U::input('name', $v('name'), 'Nazwa', 'required maxlength="200"')
            . U::input('nip', $v('nip'), 'NIP / numer VAT', 'maxlength="24"', 'Polski NIP sprawdzamy sumą kontrolną.')
            . U::input('country', $v('country', 'PL'), 'Kraj', 'maxlength="2" style="text-transform:uppercase"')
            . U::select('kind', Contractors::KINDS, $v('kind', 'both'), 'Rodzaj') . '</div>'
            . '<div class="kg-grid">' . U::input('street', $v('street'), 'Ulica i numer', 'maxlength="200"') . U::input('postcode', $v('postcode'), 'Kod pocztowy', 'maxlength="12"')
            . U::input('city', $v('city'), 'Miejscowość', 'maxlength="100"') . '</div>'
            . '<div class="kg-grid">' . U::input('email', $v('email'), 'E-mail', 'type="email" maxlength="150"') . U::input('phone', $v('phone'), 'Telefon', 'maxlength="40"')
            . U::input('bank_account', $v('bank_account'), 'Rachunek bankowy', 'maxlength="40"') . '</div>'
            . '<label>Uwagi<textarea name="notes" rows="2" maxlength="2000">' . U::h($v('notes')) . '</textarea></label>'
            . '<div><button class="btn" type="submit">' . ($c === null ? 'Dodaj kontrahenta' : 'Zapisz') . '</button></div></form>';
    }

    public function contractor(): string
    {
        $cs = new Contractors($this->db);
        if (isset($_GET['nowy'])) {
            $this->need(Access::BOOK);
            return $this->head('kontrahenci') . U::back(U::url('kontrahenci'), 'Kontrahenci') . '<div class="card">' . $this->contractorForm(null) . '</div>';
        }
        $c = $cs->get((string) ($_GET['id'] ?? ''));
        if ($c === null) {
            return $this->head('kontrahenci') . '<div class="flash err">Nie ma takiego kontrahenta.</div>';
        }
        if (isset($_GET['edytuj']) && $this->can(Access::BOOK)) {
            return $this->head('kontrahenci') . U::back(U::url('kontrahent', ['id' => $c['id']]), (string) $c['name']) . '<div class="card">' . $this->contractorForm($c) . '</div>';
        }
        $addr = trim($c['street'] . ', ' . trim($c['postcode'] . ' ' . $c['city']), ' ,');
        $html = $this->head('kontrahenci') . U::back(U::url('kontrahenci'), 'Kontrahenci')
            . '<div class="kg-top"><div><h2 style="margin:0">' . U::h($c['name']) . ((int) $c['active'] !== 1 ? ' <span class="kg-pill">wyłączony</span>' : '') . '</h2>'
            . '<p>Kod <b>' . U::h($c['code']) . '</b>' . ($c['nip'] !== '' ? ' · NIP ' . U::h(($c['country'] !== 'PL' ? $c['country'] : '') . $c['nip']) : '') . ' · ' . U::h(Contractors::KINDS[$c['kind']] ?? '') . '</p></div>'
            . '<div class="kg-actions">' . ($this->can(Access::BOOK) ? '<a class="btn secondary" href="' . U::h(U::url('kontrahent', ['id' => $c['id'], 'edytuj' => 1])) . '">Popraw</a>'
                . U::postButton($this->ctx, 'kontrahent_status', ['id' => $c['id'], 'active' => (int) $c['active'] === 1 ? 0 : 1], (int) $c['active'] === 1 ? 'Wyłącz' : 'Włącz', 'btn secondary') : '') . '</div></div>';
        $html .= '<div class="kg-two"><div class="card"><dl class="kg-dl">' . ($addr !== '' ? '<dt>Adres</dt><dd>' . U::h($addr) . ($c['country'] !== 'PL' ? ', ' . U::h($c['country']) : '') . '</dd>' : '')
            . ($c['email'] !== '' ? '<dt>E-mail</dt><dd>' . U::h($c['email']) . '</dd>' : '') . ($c['phone'] !== '' ? '<dt>Telefon</dt><dd>' . U::h($c['phone']) . '</dd>' : '')
            . ($c['bank_account'] !== '' ? '<dt>Rachunek</dt><dd class="kg-acc">' . U::h($c['bank_account']) . '</dd>' : '')
            . ($c['notes'] !== '' ? '<dt>Uwagi</dt><dd>' . nl2br(U::h($c['notes'])) . '</dd>' : '') . '</dl></div>';
        $year = $this->ledger->currentYear();
        $html .= '<div class="card"><h3 style="margin-top:0">Rozrachunki' . ($year !== null ? ' ' . U::h($year['name']) : '') . '</h3>';
        if ($year === null) {
            $html .= '<p class="kg-muted">Brak roku obrotowego.</p>';
        } else {
            $any = false;
            foreach (Contractors::accountNumbers($c) as $parent => $number) {
                $a = $this->ledger->accountByNumber((string) $year['id'], $number);
                if ($a === null) {
                    continue;
                }
                $any = true;
                $l = $this->ledger->accountLedger((string) $a['id'], (string) $year['starts_on'], (string) $year['ends_on']);
                $bal = $l['opening'] + $l['debit'] - $l['credit'];
                $label = $parent === Contractors::RECEIVABLE ? ($bal >= 0 ? 'Należność od kontrahenta' : 'Nadpłata kontrahenta') : ($bal <= 0 ? 'Zobowiązanie wobec kontrahenta' : 'Nadpłata u kontrahenta');
                $html .= '<p><a class="kg-acc" href="' . U::h(U::url('konto', ['id' => $a['id']])) . '">' . U::h($number) . '</a> ' . $label . ': <b>' . U::money(abs($bal)) . ' zł</b></p>';
            }
            if (!$any) {
                $html .= '<p class="kg-muted">Brak kont rozrachunkowych w tym roku (konto 200/202 ma już zapisy bez analityki).</p>';
            }
        }
        $html .= '</div></div>';
        $open = (new Settlements($this->db))->items(['open' => true, 'contractor' => $c['id']]);
        if ($open !== []) {
            $html .= '<div class="card kg-table"><h3 style="margin-top:0">Nierozliczone</h3>' . $this->itemsTable($open, true);
            if ($this->can(Access::BOOK)) {
                foreach (array_unique(array_column($open, 'account')) as $acc) {
                    $debts = array_filter($open, static fn(array $i): bool => $i['account'] === $acc && $i['kind'] === 'debt');
                    $pays = array_filter($open, static fn(array $i): bool => $i['account'] === $acc && $i['kind'] === 'pay');
                    if ($debts !== [] && $pays !== []) {
                        $html .= $this->linkForm($debts, $pays, ['kontrahent' => $c['id']]);
                    }
                }
            }
            $html .= '</div>';
        }
        $docs = $this->db->all('SELECT * FROM ext_ksg_docs WHERE contractor_id = ? ORDER BY posting_date DESC, created_at DESC LIMIT 100', [$c['id']]);
        if ($docs !== []) {
            $html .= '<div class="card kg-table" style="padding:6px 10px"><table><thead><tr><th>Data</th><th>Numer</th><th>Opis</th><th class="kg-num">Kwota</th><th>Status</th></tr></thead><tbody>';
            foreach ($docs as $d) {
                $html .= '<tr><td>' . U::date($d['posting_date']) . '</td><td><a href="' . U::h(U::url('dokument', ['id' => $d['id']])) . '">' . U::h($d['number']) . '</a></td>'
                    . '<td>' . U::h($d['description']) . '</td><td class="kg-num">' . U::money((int) $d['total_gr']) . '</td><td>' . U::status($d) . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        return $html;
    }

    public function contractorSave(): string
    {
        $this->need(Access::BOOK);
        $id = (string) ($_POST['id'] ?? '');
        $cs = new Contractors($this->db);
        try {
            $c = $cs->save($id !== '' ? $id : null, $_POST, $this->user());
            flash(($id === '' ? 'Dodano kontrahenta ' : 'Zapisano kontrahenta ') . $c['name'] . ' (kod ' . $c['code'] . ').');
            $this->go('kontrahent', ['id' => $c['id']]);
        } catch (\RuntimeException $e) {
            flash($e->getMessage(), 'err');
            return $this->head('kontrahenci') . U::back(U::url('kontrahenci'), 'Kontrahenci') . '<div class="card">' . $this->contractorForm($id !== '' ? $cs->get($id) : null, $_POST) . '</div>';
        }
    }

    public function contractorStatus(): void
    {
        $this->need(Access::BOOK);
        $id = (string) ($_POST['id'] ?? '');
        try {
            (new Contractors($this->db))->setActive($id, (int) ($_POST['active'] ?? 0) === 1, $this->user());
            flash((int) ($_POST['active'] ?? 0) === 1 ? 'Kontrahent włączony.' : 'Kontrahent wyłączony - nie podpowiada się w dokumentach.');
        } catch (\RuntimeException $e) {
            flash($e->getMessage(), 'err');
        }
        $this->go('kontrahent', ['id' => $id]);
    }

    // ============================================================== rejestry VAT

    private function vatPeriod(): string
    {
        $m = (string) ($_GET['m'] ?? '');
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : date('Y-m', strtotime('first day of last month'));
    }

    public function vatRegisters(): string
    {
        $vat = new Vat($this->db);
        $m = $this->vatPeriod();
        $reg = ($_GET['r'] ?? 'S') === 'Z' ? 'Z' : 'S';
        $r = $vat->register($reg, $m);
        $set = $vat->settlement($m);
        $prev = date('Y-m', strtotime($m . '-01 -1 month'));
        $next = date('Y-m', strtotime($m . '-01 +1 month'));
        $html = $this->head('vat') . '<div class="kg-top"><p>Rejestry VAT za miesiąc (tylko dokumenty zaksięgowane). Okres to miesiąc ujęcia w JPK.</p><div class="kg-actions">'
            . '<a class="btn secondary" href="' . U::h(U::url('vat', ['m' => $prev, 'r' => $reg])) . '">←</a>'
            . '<form method="get" action="ext.php" class="kg-inline"><input type="hidden" name="page" value="ksiegowosc.vat"><input type="hidden" name="r" value="' . $reg . '">'
            . '<input type="month" name="m" value="' . U::h($m) . '" onchange="this.form.submit()"></form>'
            . '<a class="btn secondary" href="' . U::h(U::url('vat', ['m' => $next, 'r' => $reg])) . '">→</a>'
            . '<a class="btn secondary" href="' . U::h(U::url('vat_csv', ['m' => $m, 'r' => $reg])) . '">⬇ CSV</a></div></div>';
        $html .= '<div class="kg-cards"><div class="card stat"><div class="label">VAT należny (sprzedaż)</div><div class="num">' . U::money($set['due']) . ' zł</div></div>'
            . '<div class="card stat"><div class="label">VAT naliczony do odliczenia</div><div class="num">' . U::money($set['input']) . ' zł</div></div>'
            . '<div class="card stat"><div class="label">' . ($set['balance'] >= 0 ? 'Do zapłaty za ' : 'Nadwyżka naliczonego za ') . U::h($m) . '</div><div class="num' . ($set['balance'] < 0 ? ' kg-ok' : '') . '">' . U::money(abs($set['balance'])) . ' zł</div>'
            . '<div class="kg-small">bez nadwyżki z poprzednich miesięcy (dojdzie z deklaracją)</div></div></div>';
        $html .= '<nav class="kg-tabs" style="margin-top:0">';
        foreach (Vat::REGISTERS as $k => $label) {
            $html .= '<a class="' . ($k === $reg ? 'on' : '') . '" href="' . U::h(U::url('vat', ['m' => $m, 'r' => $k])) . '">' . U::h($label) . '</a>';
        }
        $html .= '</nav>';
        if ($r['docs'] === []) {
            return $html . '<div class="card kg-muted">Brak zaksięgowanych dokumentów w tym rejestrze za ' . U::h($m) . '.</div>';
        }
        $html .= '<div class="card kg-table" style="padding:6px 10px"><table><thead><tr><th>Lp.</th><th>Data</th><th>Dokument</th><th>Kontrahent</th><th>NIP</th><th>Stawki</th>'
            . '<th class="kg-num">Netto</th><th class="kg-num">VAT</th>' . ($reg === 'Z' ? '<th class="kg-num">Do odliczenia</th>' : '') . '</tr></thead><tbody>';
        foreach ($r['docs'] as $i => $d) {
            $rates = implode(', ', array_unique(array_map(static fn(array $l): string => (string) (Vat::RATES[$l['rate']][0] ?? $l['rate']), $d['lines'])));
            $html .= '<tr><td>' . ($i + 1) . '</td><td>' . U::date($reg === 'S' ? $d['op_date'] : $d['doc_date']) . '</td>'
                . '<td><a href="' . U::h(U::url('dokument', ['id' => $d['id']])) . '">' . U::h($d['number']) . '</a>' . ($d['reversal'] ? ' <span class="kg-pill rev">storno</span>' : '') . '</td>'
                . '<td>' . U::h($d['name']) . '</td><td>' . U::h(($d['country'] !== '' && $d['country'] !== 'PL' ? $d['country'] : '') . $d['nip']) . '</td><td>' . U::h($rates) . '</td>'
                . '<td class="kg-num">' . U::money($d['net']) . '</td><td class="kg-num">' . U::money($d['vat']) . '</td>' . ($reg === 'Z' ? '<td class="kg-num">' . U::money($d['deductible']) . '</td>' : '') . '</tr>';
        }
        $html .= '</tbody><tfoot>';
        foreach ($r['by_rate'] as $rate => $t) {
            $html .= '<tr><td colspan="6" style="font-weight:400">Stawka ' . U::h(Vat::RATES[$rate][0] ?? $rate) . '</td><td class="kg-num" style="font-weight:400">' . U::money($t['net']) . '</td><td class="kg-num" style="font-weight:400">' . U::money($t['vat']) . '</td>' . ($reg === 'Z' ? '<td></td>' : '') . '</tr>';
        }
        return $html . '<tr><td colspan="6">Razem</td><td class="kg-num">' . U::money($r['net']) . '</td><td class="kg-num">' . U::money($r['vat']) . '</td>' . ($reg === 'Z' ? '<td class="kg-num">' . U::money($r['deductible']) . '</td>' : '') . '</tr></tfoot></table></div>';
    }

    public function vatCsv(): void
    {
        $m = $this->vatPeriod();
        $reg = ($_GET['r'] ?? 'S') === 'Z' ? 'Z' : 'S';
        $r = (new Vat($this->db))->register($reg, $m);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="rejestr-vat-' . ($reg === 'S' ? 'sprzedaz' : 'zakup') . '-' . $m . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\u{FEFF}");
        $n = static fn(int $gr): string => str_replace("\u{a0}", '', Money::format($gr));
        $safe = static fn(string $t): string => preg_match('/^[=+\-@]/', $t) ? "'" . $t : $t;
        fputcsv($out, ['Lp.', 'Data', 'Dokument', 'Kontrahent', 'Kraj', 'NIP', 'Stawka', 'Netto', 'VAT', 'Do odliczenia'], ';', '"', '');
        foreach ($r['docs'] as $i => $d) {
            foreach ($d['lines'] as $l) {
                fputcsv($out, [$i + 1, $reg === 'S' ? $d['op_date'] : $d['doc_date'], $safe((string) $d['number']), $safe((string) $d['name']), $d['country'], $d['nip'],
                    Vat::RATES[$l['rate']][0] ?? $l['rate'], $n($l['net_gr']), $n($l['vat_gr']), $reg === 'Z' ? $n(Vat::deductible($l)) : ''], ';', '"', '');
            }
        }
        fputcsv($out, ['', '', '', 'Razem', '', '', '', $n($r['net']), $n($r['vat']), $reg === 'Z' ? $n($r['deductible']) : ''], ';', '"', '');
        fclose($out);
    }

    // ============================================================== rozrachunki

    /** Pozycja rozrachunkowa jako tekst do listy wyboru. */
    private static function itemLabel(array $i): string
    {
        return $i['number'] . ' · ' . U::date($i['doc_date']) . ' · ' . ($i['contractor']['name'] ?? $i['account_name']) . ' · ' . Money::format($i['remaining']) . ' zł'
            . ($i['remaining'] !== $i['amount'] ? ' z ' . Money::format($i['amount']) : '');
    }

    /** Tabela pozycji rozrachunkowych (dokument, termin, kwota, zostało). */
    private function itemsTable(array $items, bool $withAccount = false): string
    {
        $html = '<table><thead><tr><th>Dokument</th><th>Data</th><th>Termin</th>' . ($withAccount ? '<th>Konto</th>' : '') . '<th>Opis</th><th class="kg-num">Kwota</th><th class="kg-num">Zostało</th></tr></thead><tbody>';
        foreach ($items as $i) {
            $late = $i['late_days'] > 0 ? ' <span class="kg-pill rev">' . $i['late_days'] . ' dni po terminie</span>' : '';
            $html .= '<tr><td><a href="' . U::h(U::url('dokument', ['id' => $i['doc_id']])) . '">' . U::h($i['number']) . '</a>' . ($i['kind'] === 'pay' ? ' <span class="kg-pill">zapłata</span>' : '') . '</td>'
                . '<td>' . U::date($i['doc_date']) . '</td><td>' . ($i['kind'] === 'debt' ? U::date($i['due']) . $late : '') . '</td>'
                . ($withAccount ? '<td class="kg-acc">' . U::h($i['account']) . '</td>' : '')
                . '<td>' . U::h($i['label']) . '</td><td class="kg-num">' . U::money($i['amount']) . '</td><td class="kg-num"><b>' . U::money($i['remaining']) . '</b></td></tr>';
        }
        return $html . '</tbody></table>';
    }

    public function settlements(): string
    {
        $st = new Settlements($this->db);
        $parent = ($_GET['r'] ?? '200') === '202' ? '202' : '200';
        $late = isset($_GET['zalegle']);
        $q = mb_strtolower(mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100));
        $all = $st->items(['open' => true]);
        $sum = static function (array $items, string $parent, bool $lateOnly) : int {
            $t = 0;
            foreach ($items as $i) {
                if ($i['parent'] === $parent && $i['kind'] === 'debt' && (!$lateOnly || $i['late_days'] > 0)) {
                    $t += $i['remaining'];
                }
            }
            return $t;
        };
        $html = $this->head('rozrachunki') . '<div class="kg-top"><p>Nierozliczone faktury i zapłaty na kontach 200 (należności) i 202 (zobowiązania). Zapłatę łączy się z fakturą ręcznie albo automatycznie (numer faktury w tytule, równa kwota); wyciąg bankowy rozlicza wskazane faktury sam.</p>'
            . '<div class="kg-actions">' . ($this->can(Access::BOOK) ? U::postButton($this->ctx, 'rozlicz_auto', ['r' => $parent], 'Rozlicz automatycznie', 'btn secondary') : '') . '</div></div>'
            . '<div class="kg-cards"><a class="card stat" href="' . U::h(U::url('rozrachunki', ['r' => '200'])) . '"><div class="label">Należności do zapłaty</div><div class="num">' . U::money($sum($all, '200', false)) . ' zł</div></a>'
            . '<a class="card stat" href="' . U::h(U::url('rozrachunki', ['r' => '200', 'zalegle' => 1])) . '"><div class="label">w tym przeterminowane</div><div class="num kg-bad">' . U::money($sum($all, '200', true)) . ' zł</div></a>'
            . '<a class="card stat" href="' . U::h(U::url('rozrachunki', ['r' => '202'])) . '"><div class="label">Zobowiązania do zapłaty</div><div class="num">' . U::money($sum($all, '202', false)) . ' zł</div></a>'
            . '<a class="card stat" href="' . U::h(U::url('rozrachunki', ['r' => '202', 'zalegle' => 1])) . '"><div class="label">w tym przeterminowane</div><div class="num kg-bad">' . U::money($sum($all, '202', true)) . ' zł</div></a></div>';
        $html .= '<nav class="kg-tabs" style="margin-top:0">';
        foreach (Settlements::LABELS as $k => $label) {
            $html .= '<a class="' . ((string) $k === $parent ? 'on' : '') . '" href="' . U::h(U::url('rozrachunki', ['r' => $k])) . '">' . U::h($label) . '</a>';
        }
        $html .= '</nav><form class="kg-filter" method="get" action="ext.php"><input type="hidden" name="page" value="ksiegowosc.rozrachunki"><input type="hidden" name="r" value="' . $parent . '">'
            . '<input type="search" name="q" value="' . U::h($q) . '" placeholder="Kontrahent, konto albo numer dokumentu">'
            . '<label class="kg-check"><input type="checkbox" name="zalegle" value="1"' . ($late ? ' checked' : '') . '> tylko przeterminowane</label><button class="btn secondary" type="submit">Pokaż</button></form>';
        $items = array_filter($all, static function (array $i) use ($parent, $q): bool {
            if ($i['parent'] !== $parent) {
                return false;
            }
            return $q === '' || str_contains(mb_strtolower($i['account'] . ' ' . $i['account_name'] . ' ' . $i['number'] . ' ' . ($i['contractor']['name'] ?? '') . ' ' . $i['counterparty']), $q);
        });
        $groups = $st->summary(array_values($items));
        if ($late) {
            $groups = array_values(array_filter($groups, static fn(array $g): bool => $g['late_30'] + $g['late_90'] + $g['late_more'] > 0));
        }
        if ($groups === []) {
            return $html . '<div class="card kg-muted">Brak nierozliczonych pozycji.</div>';
        }
        foreach ($groups as $g) {
            $html .= '<div class="card kg-table" style="padding:10px 12px"><div class="kg-top" style="margin:0 0 6px"><div><b>' . U::h($g['contractor']['name'] ?? $g['account_name']) . '</b> <a class="kg-acc" href="'
                . U::h(U::url('kontrahenci', ['q' => $g['account']])) . '">' . U::h($g['account']) . '</a>'
                . '<div class="kg-small">Do zapłaty ' . U::money($g['debt']) . ' zł' . ($g['pay'] > 0 ? ' · nierozliczone zapłaty ' . U::money($g['pay']) . ' zł' : '')
                . ($g['late_30'] + $g['late_90'] + $g['late_more'] > 0 ? ' · <span class="kg-bad">po terminie: do 30 dni ' . U::money($g['late_30']) . ', 31-90 ' . U::money($g['late_90']) . ', ponad 90 ' . U::money($g['late_more']) . '</span>' : '') . '</div></div>'
                . ($this->can(Access::BOOK) && $g['debt'] > 0 && $g['pay'] > 0 ? '<div class="kg-actions">' . U::postButton($this->ctx, 'rozlicz_auto', ['r' => $parent, 'account' => $g['account']], 'Rozlicz automatycznie', 'btn secondary') . '</div>' : '') . '</div>'
                . $this->itemsTable($g['items']);
            $debts = array_filter($g['items'], static fn(array $i): bool => $i['kind'] === 'debt');
            $pays = array_filter($g['items'], static fn(array $i): bool => $i['kind'] === 'pay');
            if ($this->can(Access::BOOK) && $debts !== [] && $pays !== []) {
                $html .= $this->linkForm($debts, $pays, ['r' => $parent]);
            }
            $html .= '</div>';
        }
        return $html;
    }

    /** Formularz ręcznego rozliczenia: faktura + zapłata + kwota (puste = ile się da). */
    private function linkForm(array $debts, array $pays, array $back): string
    {
        $d = $p = [];
        foreach ($debts as $i) {
            $d[$i['entry_id']] = self::itemLabel($i);
        }
        foreach ($pays as $i) {
            $p[$i['entry_id']] = self::itemLabel($i);
        }
        $hidden = '';
        foreach ($back as $k => $v) {
            $hidden .= '<input type="hidden" name="back[' . U::h($k) . ']" value="' . U::h($v) . '">';
        }
        return '<form method="post" action="' . U::h(U::url('rozlicz')) . '" class="kg-form kg-inline" style="margin-top:8px">' . U::csrf($this->ctx) . $hidden
            . U::select('debt', $d, '', '', 'aria-label="Faktura"') . U::select('pay', $p, '', '', 'aria-label="Zapłata"')
            . '<input name="amount" inputmode="decimal" placeholder="Kwota (puste = całość)" style="max-width:170px"><button class="btn secondary" type="submit">Rozlicz</button></form>';
    }

    private function settleBack(): never
    {
        $back = array_filter((array) ($_POST['back'] ?? []), 'is_string');
        if (isset($back['doc'])) {
            $this->go('dokument', ['id' => $back['doc']]);
        }
        if (isset($back['kontrahent'])) {
            $this->go('kontrahent', ['id' => $back['kontrahent']]);
        }
        $this->go('rozrachunki', array_intersect_key($back, ['r' => 1]));
    }

    public function settleLink(): void
    {
        $this->need(Access::BOOK);
        try {
            $amount = Money::parse((string) ($_POST['amount'] ?? ''));
            $done = (new Settlements($this->db))->link((string) ($_POST['debt'] ?? ''), (string) ($_POST['pay'] ?? ''), $amount, $this->user());
            flash('Rozliczono ' . Money::format($done) . ' zł.');
        } catch (\RuntimeException $e) {
            flash($e->getMessage(), 'err');
        }
        $this->settleBack();
    }

    public function settleUnlink(): void
    {
        $this->need(Access::BOOK);
        try {
            (new Settlements($this->db))->unlink((string) ($_POST['id'] ?? ''), $this->user());
            flash('Usunięto rozliczenie - pozycje znów są otwarte.');
        } catch (\RuntimeException $e) {
            flash($e->getMessage(), 'err');
        }
        $this->settleBack();
    }

    public function settleAuto(): void
    {
        $this->need(Access::BOOK);
        $n = (new Settlements($this->db))->autoMatch($this->user(), (string) ($_POST['account'] ?? ''));
        flash($n > 0 ? 'Rozliczono automatycznie: ' . $n . '.' : 'Nie znalazłem par do rozliczenia (numer faktury w tytule albo ta sama kwota). Rozlicz ręcznie.');
        $_POST['back'] = ['r' => (string) ($_POST['r'] ?? '200')];
        $this->settleBack();
    }

    /** Rozrachunki dokumentu: jego pozycje na kontach 200/202 i powiązania z zapłatami albo fakturami. */
    private function docSettlements(array $d): string
    {
        if ($d['status'] !== 'posted') {
            return '';
        }
        $st = new Settlements($this->db);
        $items = $st->items(['doc' => $d['id']]);
        if ($items === []) {
            return '';
        }
        $links = $st->linksForDoc((string) $d['id']);
        $html = '<div class="card kg-table"><h3 style="margin-top:0">Rozrachunki</h3>' . $this->itemsTable($items, true);
        if ($links !== []) {
            $html .= '<table style="margin-top:10px"><thead><tr><th>Rozliczenie</th><th>Konto</th><th class="kg-num">Kwota</th><th></th></tr></thead><tbody>';
            foreach ($links as $l) {
                $other = $l['debt_doc_id'] === $d['id'] ? ['id' => $l['pay_doc_id'], 'number' => $l['pay_number']] : ['id' => $l['debt_doc_id'], 'number' => $l['debt_number']];
                $html .= '<tr><td>' . ($l['debt_doc_id'] === $d['id'] ? 'zapłata ' : 'faktura ') . '<a href="' . U::h(U::url('dokument', ['id' => $other['id']])) . '">' . U::h($other['number']) . '</a>'
                    . ($l['source'] !== 'manual' ? ' <span class="kg-small">(' . U::h(['auto' => 'automatycznie', 'bank' => 'z wyciągu', 'storno' => 'storno'][$l['source']] ?? $l['source']) . ')</span>' : '') . '</td>'
                    . '<td class="kg-acc">' . U::h($l['account']) . '</td><td class="kg-num">' . U::money((int) $l['amount_gr']) . '</td><td>'
                    . ($this->can(Access::BOOK) ? U::postButton($this->ctx, 'rozlicz_usun', ['id' => $l['id'], 'back[doc]' => $d['id']], 'Cofnij', 'btn secondary', 'Cofnąć to rozliczenie?') : '') . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        $open = array_filter($items, static fn(array $i): bool => $i['remaining'] > 0);
        if ($open !== [] && $this->can(Access::BOOK)) {
            foreach ($open as $i) {
                $others = array_filter($st->items(['open' => true, 'account' => $i['account']]), static fn(array $o): bool => $o['kind'] !== $i['kind']);
                if ($others !== []) {
                    $html .= $i['kind'] === 'debt' ? $this->linkForm([$i], $others, ['doc' => $d['id']]) : $this->linkForm($others, [$i], ['doc' => $d['id']]);
                }
            }
        }
        return $html . '</div>';
    }

    // ============================================================== wyciągi bankowe

    /** Konta bankowe (13x bez analityki) bieżącego roku. @return array<string,string> */
    private function bankAccounts(): array
    {
        $year = $this->ledger->currentYear();
        $out = [];
        if ($year !== null) {
            foreach ($this->ledger->accounts((string) $year['id']) as $a) {
                if (preg_match('/^13\d/', (string) $a['number']) && !$a['has_children'] && (int) $a['active'] === 1) {
                    $out[(string) $a['number']] = $a['number'] . ' ' . $a['name'];
                }
            }
        }
        return $out;
    }

    public function bank(): string
    {
        $bank = new Bank($this->db);
        $html = $this->head('bank') . '<div class="kg-top"><p>Wyciągi z banku: plik MT940 albo CSV z bankowości internetowej. Operacje dostają podpowiedź kontrahenta, konta i faktury; po sprawdzeniu cały wyciąg księguje się jednym dokumentem WB i rozlicza wskazane faktury.</p></div>';
        if ($this->can(Access::BOOK)) {
            $accounts = $this->bankAccounts();
            $html .= $accounts === [] ? '<div class="card kg-muted">Brak konta bankowego (13x) w planie kont bieżącego roku.</div>'
                : '<form method="post" enctype="multipart/form-data" action="' . U::h(U::url('wyciag_import')) . '" class="kg-form card">' . U::csrf($this->ctx)
                    . '<div class="kg-grid">' . U::select('account', $accounts, (string) array_key_first($accounts), 'Konto bankowe')
                    . '<label>Plik wyciągu<input type="file" name="file" accept=".sta,.mt940,.txt,.csv,.940" required><small>MT940 (.sta, .txt) albo CSV z historii rachunku. Operacje już wczytane są pomijane.</small></label></div>'
                    . '<div><button class="btn" type="submit">Wczytaj wyciąg</button></div></form>';
        }
        $rows = $bank->statements();
        if ($rows === []) {
            return $html . U::empty('Nie ma jeszcze wyciągów', 'Pobierz z banku historię rachunku (MT940 albo CSV) i wczytaj ją tutaj.');
        }
        $html .= '<div class="card kg-table" style="padding:6px 10px"><table><thead><tr><th>Okres</th><th>Konto</th><th>Plik</th><th class="kg-num">Operacje</th><th class="kg-num">Wpływy</th><th class="kg-num">Wydatki</th><th>Status</th></tr></thead><tbody>';
        foreach ($rows as $s) {
            $html .= '<tr><td><a href="' . U::h(U::url('wyciag', ['id' => $s['id']])) . '">' . U::date($s['date_from']) . ($s['date_to'] !== $s['date_from'] ? ' - ' . U::date($s['date_to']) : '') . '</a></td>'
                . '<td class="kg-acc">' . U::h($s['account']) . '</td><td>' . U::h($s['file_name']) . '</td><td class="kg-num">' . (int) $s['n'] . '</td>'
                . '<td class="kg-num">' . U::money((int) $s['inflow']) . '</td><td class="kg-num">' . U::money(-(int) $s['outflow']) . '</td>'
                . '<td><span class="kg-pill ' . ($s['status'] === 'booked' ? 'posted' : 'draft') . '">' . U::h(Bank::STATUSES[$s['status']] ?? $s['status']) . '</span></td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    public function bankImport(): string
    {
        $this->need(Access::BOOK);
        try {
            [$name, $content] = $this->uploaded(5);
            $s = (new Bank($this->db))->import((string) ($_POST['account'] ?? ''), $name, $content, $this->user());
            flash('Wczytano wyciąg' . ($s['skipped'] > 0 ? ', pominięto ' . $s['skipped'] . ' operacji wczytanych wcześniej' : '') . '. Sprawdź konta i zaksięguj.');
            $this->go('wyciag', ['id' => $s['id']]);
        } catch (\RuntimeException $e) {
            flash($e->getMessage(), 'err');
            $this->go('bank');
        }
    }

    public function statement(): string
    {
        $bank = new Bank($this->db);
        $s = $bank->statement((string) ($_GET['id'] ?? ''));
        if ($s === null) {
            return $this->head('bank') . '<div class="flash err">Nie ma takiego wyciągu.</div>';
        }
        $lines = $bank->lines((string) $s['id']);
        $open = $s['status'] === 'open';
        $edit = $open && $this->can(Access::BOOK);
        $cs = new Contractors($this->db);
        $contractors = ['' => '- kontrahent -'];
        $names = [];
        foreach ($cs->list('', true) as $c) {
            $contractors[$c['id']] = $c['name'] . ' (' . $c['code'] . ')';
            $names[$c['id']] = $c['name'];
        }
        $st = new Settlements($this->db);
        $debts = ['' => '- bez rozliczenia -'];
        $debtItems = [];
        foreach ($st->items(['open' => true]) as $i) {
            if ($i['kind'] === 'debt') {
                $debts[$i['entry_id']] = self::itemLabel($i);
                $debtItems[$i['entry_id']] = $i;
            }
        }
        $sum = array_sum(array_map(static fn(array $l): int => (int) $l['amount_gr'], $lines));
        $html = $this->head('bank') . U::back(U::url('bank'), 'Wyciągi')
            . '<div class="kg-top"><div><h2 style="margin:0">Wyciąg ' . U::h($s['account']) . ' · ' . U::date($s['date_from']) . ($s['date_to'] !== $s['date_from'] ? ' - ' . U::date($s['date_to']) : '') . '</h2>'
            . '<p>' . U::h($s['file_name']) . ' (' . U::h(strtoupper($s['format'])) . ')' . ($s['iban'] !== '' ? ' · rachunek ' . U::h($s['iban']) : '') . ' · ' . count($lines) . ' operacji, razem ' . U::money($sum) . ' zł'
            . ($s['opening_gr'] !== null && $s['closing_gr'] !== null ? ' · saldo ' . U::money((int) $s['opening_gr']) . ' → ' . U::money((int) $s['closing_gr'])
                . ((int) $s['opening_gr'] + $sum === (int) $s['closing_gr'] ? ' <span class="kg-ok">✓</span>' : ' <span class="kg-bad">(nie zgadza się z operacjami)</span>') : '') . '</p></div><div class="kg-actions">';
        if ($edit) {
            $html .= U::postButton($this->ctx, 'wyciag_usun', ['id' => $s['id']], 'Usuń wyciąg', 'btn secondary', 'Usunąć wczytany wyciąg? Można go potem wczytać jeszcze raz.');
        }
        if (!$open && $s['doc_id'] !== null && ($doc = $this->ledger->doc((string) $s['doc_id'])) !== null) {
            $html .= '<a class="btn secondary" href="' . U::h(U::url('dokument', ['id' => $doc['id']])) . '">Dokument ' . U::h($doc['number']) . '</a>';
        }
        $html .= '</div></div>';
        $year = $this->ledger->currentYear();
        $opts = '';
        if ($year !== null) {
            foreach ($this->ledger->accounts((string) $year['id']) as $a) {
                if (!$a['has_children'] && (int) $a['active'] === 1) {
                    $opts .= '<option value="' . U::h($a['number'] . ' ' . $a['name']) . '"></option>';
                }
            }
        }
        $html .= ($edit ? '<form method="post" action="' . U::h(U::url('wyciag_zapisz')) . '" class="kg-form">' . U::csrf($this->ctx) . '<input type="hidden" name="id" value="' . U::h($s['id']) . '"><datalist id="kg-accounts">' . $opts . '</datalist>' : '')
            . '<div class="card kg-table" style="padding:6px 10px"><table class="kg-lines"><thead><tr><th>#</th><th>Data</th><th>Operacja</th><th class="kg-num">Kwota</th><th>Kontrahent</th><th>Konto</th><th>Rozlicza fakturę</th>' . ($edit ? '<th>Pomiń</th>' : '') . '</tr></thead><tbody>';
        foreach ($lines as $l) {
            $n = 'lines[' . $l['id'] . ']';
            $party = trim($l['party_name'] . ($l['party_iban'] !== '' ? ' · ' . $l['party_iban'] : ''));
            $html .= '<tr' . ($l['status'] === 'skip' ? ' style="opacity:.55"' : '') . '><td>' . (int) $l['position'] . '</td><td>' . U::date($l['op_date']) . '</td>'
                . '<td>' . U::h($l['title']) . ($party !== '' ? '<div class="kg-small">' . U::h($party) . '</div>' : '') . '</td>'
                . '<td class="kg-num ' . ((int) $l['amount_gr'] < 0 ? 'kg-bad' : 'kg-ok') . '">' . U::money((int) $l['amount_gr']) . '</td>';
            if ($edit) {
                $m = (string) ($l['match_entry_id'] ?? '');
                $choices = $debts + ($m !== '' && !isset($debts[$m]) ? [$m => '(rozliczona pozycja)'] : []);
                $html .= '<td>' . U::select($n . '[contractor_id]', $contractors, (string) ($l['contractor_id'] ?? ''), '', 'style="max-width:190px"') . '</td>'
                    . '<td><input name="' . $n . '[account]" value="' . U::h($l['account']) . '" list="kg-accounts" placeholder="Konto" autocomplete="off" style="max-width:130px"></td>'
                    . '<td>' . U::select($n . '[match]', $choices, $m, '', 'style="max-width:260px"') . '</td>'
                    . '<td><input type="checkbox" name="' . $n . '[skip]" value="1"' . ($l['status'] === 'skip' ? ' checked' : '') . ' title="Nie księguj tej operacji"></td>';
            } else {
                $mi = $l['match_entry_id'] !== null ? ($debtItems[$l['match_entry_id']] ?? $st->item((string) $l['match_entry_id'])) : null;
                $html .= '<td>' . U::h($names[$l['contractor_id']] ?? '') . '</td><td class="kg-acc">' . U::h($l['account']) . '</td>'
                    . '<td>' . ($mi !== null ? '<a href="' . U::h(U::url('dokument', ['id' => $mi['doc_id']])) . '">' . U::h($mi['number']) . '</a>' : '') . ($l['status'] === 'skip' ? ' <span class="kg-pill">pominięta</span>' : '') . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
        if ($edit) {
            $html .= '<div class="kg-actions"><button class="btn secondary" type="submit" name="save" value="1">Zapisz zmiany</button>'
                . '<button class="btn" type="submit" name="book" value="1" onclick="return confirm(\'Zaksięgować wyciąg dokumentem WB? Wybrane faktury zostaną rozliczone.\')">Zapisz i zaksięguj</button></div></form>';
        }
        return $html;
    }

    public function statementSave(): void
    {
        $this->need(Access::BOOK);
        $id = (string) ($_POST['id'] ?? '');
        $bank = new Bank($this->db);
        try {
            $bank->saveLines($id, (array) ($_POST['lines'] ?? []), $this->user());
            if (isset($_POST['book'])) {
                $doc = $bank->book($id, $this->user());
                flash('Zaksięgowano wyciąg: ' . $doc['number'] . ' (pozycja dziennika ' . $doc['journal_no'] . '), rozliczone faktury: ' . $doc['settled'] . '.');
            } else {
                flash('Zapisano zmiany w wyciągu.');
            }
        } catch (\RuntimeException $e) {
            flash($e->getMessage(), 'err');
        }
        $this->go('wyciag', ['id' => $id]);
    }

    public function statementDelete(): void
    {
        $this->need(Access::BOOK);
        try {
            (new Bank($this->db))->delete((string) ($_POST['id'] ?? ''), $this->user());
            flash('Usunięto wyciąg.');
        } catch (\RuntimeException $e) {
            flash($e->getMessage(), 'err');
            $this->go('wyciag', ['id' => (string) ($_POST['id'] ?? '')]);
        }
        $this->go('bank');
    }

    // ============================================================== import z innego programu (JPK)

    /** Treść przesłanego pliku (pole „file”). @return array{0:string,1:string} [nazwa, treść] */
    private function uploaded(int $maxMb): array
    {
        $f = $_FILES['file'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            throw new \RuntimeException('Wybierz plik.');
        }
        if ((int) $f['size'] > $maxMb * 1024 * 1024) {
            throw new \RuntimeException('Plik jest za duży (najwyżej ' . $maxMb . ' MB).');
        }
        return [basename((string) $f['name']), (string) file_get_contents((string) $f['tmp_name'])];
    }

    public function importPage(): string
    {
        $this->need(Access::MANAGE);
        $imp = new JpkImport($this->db);
        $form = fn(string $page, string $title, string $help): string => '<form method="post" enctype="multipart/form-data" action="' . U::h(U::url($page)) . '" class="kg-form card">' . U::csrf($this->ctx)
            . '<h3 style="margin-top:0">' . $title . '</h3><p class="kg-small" style="margin-top:-6px">' . $help . '</p>'
            . '<label>Plik XML<input type="file" name="file" accept=".xml" required></label><div><button class="btn" type="submit">Importuj</button></div></form>';
        $html = $this->head('import') . '<div class="kg-top"><p>Przeniesienie ksiąg z innego programu (np. wFirma): księgi i VAT z plików JPK, dokumenty i zapłaty z wFirma także po API. Służy do porównania: po imporcie zakładka pokazuje, czy nasze obroty i salda zgadzają się z programem źródłowym.</p></div>'
            . '<div class="kg-two">' . $form('import_kr', '1. JPK_KR - księgi', 'Plan kont, bilans otwarcia i wszystkie zapisy dziennika za rok obrotowy. Tworzy rok obrotowy (albo zastępuje poprzedni import tego roku). Nie działa na rok z dokumentami wprowadzonymi w CRM.')
            . $form('import_v7', '2. JPK_V7 - VAT i kontrahenci', 'Ewidencja VAT za miesiąc: kontrahenci z NIP-ami i pozycje VAT przypinane do zaimportowanych dokumentów po numerze. Importuj po JPK_KR, miesiąc po miesiącu.') . '</div>';
        $html .= $this->wfirmaCard();
        $rows = $imp->imports();
        if ($rows !== []) {
            $html .= '<div class="card kg-table" style="padding:6px 10px"><table><thead><tr><th>Kiedy</th><th>Plik</th><th>Rodzaj</th><th>Rok / okres</th><th></th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $html .= '<tr><td>' . U::h(substr((string) $r['created_at'], 0, 16)) . '</td><td>' . U::h($r['file_name']) . '</td><td>' . U::h(JpkImport::KINDS[$r['kind']] ?? $r['kind']) . '</td>'
                    . '<td>' . U::h($r['year_name'] ?? '') . ($r['period'] !== '' ? ' · ' . U::h($r['period']) : '') . '</td>'
                    . '<td>' . ($r['kind'] === 'jpk_kr' ? '<a href="' . U::h(U::url('porownanie', ['rok' => $r['year_id']])) . '">Porównanie</a>' : '') . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        return $html;
    }

    private function wf(): Wfirma
    {
        return $this->wfirma !== null ? ($this->wfirma)() : new Wfirma($this->db);
    }

    /** Karta „wFirma po API” na stronie importu: stan, pobranie za okres, sprawdzenie kluczy. */
    private function wfirmaCard(): string
    {
        $wf = $this->wf();
        $last = $wf->lastResult();
        $html = '<div class="card"><h3 style="margin-top:0">3. wFirma po API - faktury, wydatki, zapłaty</h3>'
            . '<p class="kg-small" style="margin-top:-6px">Faktury sprzedaży i wydatki z wFirma przypinane do dokumentów z JPK_KR po numerze: kontrahent, termin płatności, '
            . 'pozycje VAT (gdy dokument ich nie ma - JPK_V7 ma pierwszeństwo) i stan zapłaty do porównania z naszymi rozrachunkami. '
            . 'Dziennika księgowań API wFirma nie wydaje, więc księgi dalej przychodzą z pliku JPK_KR.</p>';
        if (!$wf->configured()) {
            return $html . '<p class="flash err">W CRM nie ma aktywnej integracji wFirma z kluczami API. Pobieranie korzysta z kluczy tej integracji '
                . '(Integracje → wFirma) - Księgowość ich nie widzi, dostaje tylko dokumenty.</p></div>';
        }
        if (($last['ok'] ?? null) === false) {
            $html .= '<p class="flash err">Ostatnie automatyczne pobranie (' . U::h($last['at'] ?? '') . ') nie udało się: ' . U::h($last['error'] ?? '') . '</p>';
        } elseif (($last['at'] ?? '') !== '') {
            $html .= '<p class="kg-small">Ostatnio pobrano ' . U::h(substr((string) $last['at'], 0, 16)) . ' (okres ' . U::h(($last['from'] ?? '') . ' - ' . ($last['to'] ?? ''))
                . '): faktur ' . (int) ($last['sales'] ?? 0) . ', wydatków ' . (int) ($last['purchases'] ?? 0) . ', przypiętych do ksiąg ' . (int) ($last['matched'] ?? 0) . '.</p>';
        }
        $year = $this->ledger->currentYear();
        $from = $year !== null ? (string) $year['starts_on'] : date('Y-01-01');
        return $html . '<form method="post" action="' . U::h(U::url('wfirma_pobierz')) . '" class="kg-form">' . U::csrf($this->ctx) . '<div class="kg-grid">'
            . U::input('from', $from, 'Od (data wystawienia)', 'type="date" required') . U::input('to', date('Y-m-d'), 'Do', 'type="date" required') . '</div>'
            . '<div class="kg-actions"><button class="btn" type="submit">Pobierz z wFirma</button>'
            . ' <button class="btn secondary" type="submit" formaction="' . U::h(U::url('wfirma_test')) . '" formnovalidate>Sprawdź połączenie</button>'
            . ' <a class="btn secondary" href="' . U::h(U::url('porownanie_wf')) . '">Porównanie zapłat</a></div></form></div>';
    }

    public function wfirmaTest(): void
    {
        $this->need(Access::MANAGE);
        try {
            flash($this->wf()->test());
        } catch (\RuntimeException $e) {
            flash($e->getMessage(), 'err');
        }
        $this->go('import');
    }

    public function wfirmaSync(): void
    {
        $this->need(Access::MANAGE);
        try {
            $r = $this->wf()->sync((string) ($_POST['from'] ?? ''), (string) ($_POST['to'] ?? ''), $this->user());
            flash('Z wFirma: ' . $r['sales'] . ' faktur sprzedaży i ' . $r['purchases'] . ' wydatków (' . $r['from'] . ' - ' . $r['to'] . '). Przypięto do ksiąg ' . $r['matched']
                . ', nowych kontrahentów ' . $r['contractors'] . '.'
                . ($r['unmatched'] !== [] ? ' Bez dokumentu w księgach (' . count($r['unmatched']) . '): ' . implode(', ', array_slice($r['unmatched'], 0, 5)) . (count($r['unmatched']) > 5 ? '…' : '') . '.' : ''),
                $r['unmatched'] !== [] ? 'err' : 'ok');
            $this->go('porownanie_wf', ['od' => $r['from'], 'do' => $r['to']]);
        } catch (\RuntimeException $e) {
            flash($e->getMessage(), 'err');
            $this->go('import');
        }
    }

    /** Stan zapłaty faktur i wydatków: wFirma vs nasze rozrachunki. */
    public function wfirmaComparison(): string
    {
        $from = (string) ($_GET['od'] ?? '');
        $to = (string) ($_GET['do'] ?? '');
        $year = $this->ledger->currentYear();
        $from = Ledger::validDate($from) ? $from : ($year !== null ? (string) $year['starts_on'] : date('Y-01-01'));
        $to = Ledger::validDate($to) ? $to : date('Y-m-d');
        $all = isset($_GET['wszystkie']);
        $rows = $this->wf()->compare($from, $to);
        $bad = array_values(array_filter($rows, static fn(array $r): bool => !$r['ok']));
        $html = $this->head($this->can(Access::MANAGE) ? 'import' : 'rozrachunki')
            . '<div class="kg-top"><p>Ile zostało do zapłaty według wFirma, a ile według rozrachunków w CRM (konta 200 i 202). Różnica zwykle znaczy, że zapłata nie jest '
            . 'u nas rozliczona z fakturą - spróbuj „Rozlicz automatycznie” w Rozrachunkach.</p>'
            . '<form method="get" action="ext.php" class="kg-filter"><input type="hidden" name="page" value="ksiegowosc.porownanie_wf">'
            . '<input type="date" name="od" value="' . U::h($from) . '"> <input type="date" name="do" value="' . U::h($to) . '">'
            . ($all ? '<input type="hidden" name="wszystkie" value="1">' : '') . ' <button class="btn secondary" type="submit">Pokaż</button>'
            . ' <a class="btn secondary" href="' . U::h(U::url('porownanie_wf', ['od' => $from, 'do' => $to] + ($all ? [] : ['wszystkie' => 1]))) . '">' . ($all ? 'Tylko różnice' : 'Wszystkie dokumenty') . '</a></form></div>';
        if ($rows === []) {
            return $html . '<div class="card kg-muted">W tym okresie nie ma dokumentów pobranych z wFirma.</div>';
        }
        $html .= $bad === [] ? '<div class="flash ok">✓ Wszystkie ' . count($rows) . ' dokumentów zgadza się z wFirma.</div>'
            : '<div class="flash err">Różnice na ' . count($bad) . ' z ' . count($rows) . ' dokumentów.</div>';
        $show = $all ? $rows : $bad;
        if ($show === []) {
            return $html;
        }
        $html .= '<div class="card kg-table" style="padding:6px 10px"><table><thead><tr><th>Rodzaj</th><th>Numer</th><th>Data</th><th>Kontrahent</th><th class="kg-num">Brutto</th>'
            . '<th class="kg-num">Do zapłaty wFirma</th><th class="kg-num">Do zapłaty CRM</th></tr></thead><tbody>';
        foreach ($show as $r) {
            $num = $r['doc_id'] !== null ? '<a href="' . U::h(U::url('dokument', ['id' => $r['doc_id']])) . '">' . U::h($r['number']) . '</a>' : U::h($r['number']);
            $ours = $r['ours_left'] === null ? '<span class="kg-bad">brak w księgach</span>' : U::money((int) $r['ours_left']);
            $html .= '<tr><td>' . ($r['kind'] === 'sale' ? 'Sprzedaż' : 'Zakup') . '</td><td>' . $num . ($r['ok'] ? ' <span class="kg-ok">✓</span>' : '') . '</td><td>' . U::h($r['doc_date']) . '</td>'
                . '<td>' . U::h($r['name']) . '</td><td class="kg-num">' . U::money((int) $r['gross_gr']) . ($r['currency'] !== 'PLN' ? ' ' . U::h($r['currency']) : '') . '</td>'
                . '<td class="kg-num">' . U::money(max(0, (int) $r['wf_left'])) . '</td><td class="kg-num' . ($r['ok'] ? '' : ' kg-bad') . '">' . $ours . '</td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    public function importKr(): void
    {
        $this->need(Access::MANAGE);
        try {
            [$name, $xml] = $this->uploaded(60);
            $r = (new JpkImport($this->db))->importKr($name, $xml, $this->user());
            flash('Zaimportowano księgi: ' . $r['accounts'] . ' kont, ' . $r['docs'] . ' dokumentów, ' . $r['entries'] . ' zapisów.'
                . ($r['unbalanced'] !== [] ? ' Niezbilansowane w pliku: ' . implode(', ', array_slice($r['unbalanced'], 0, 5)) . (count($r['unbalanced']) > 5 ? '…' : '') . '.' : '')
                . ($r['warnings'] !== [] ? ' ' . implode(' ', array_slice($r['warnings'], 0, 3)) : '')
                . ($r['wfirma']['matched'] + count($r['wfirma']['unmatched']) > 0 ? ' Dokumenty z wFirma: przypięto ' . $r['wfirma']['matched'] . ', bez dokumentu w księgach '
                    . count($r['wfirma']['unmatched']) . '.' : ''), $r['unbalanced'] !== [] ? 'err' : 'ok');
            $this->go('porownanie', ['rok' => $r['year_id']]);
        } catch (\RuntimeException $e) {
            flash($e->getMessage(), 'err');
            $this->go('import');
        }
    }

    public function importV7(): void
    {
        $this->need(Access::MANAGE);
        try {
            [$name, $xml] = $this->uploaded(30);
            $r = (new JpkImport($this->db))->importV7($name, $xml, $this->user());
            flash('JPK_V7 za ' . $r['period'] . ': przypięto ' . $r['matched'] . ' faktur, nowych kontrahentów ' . $r['contractors'] . '.'
                . ($r['unmatched'] !== [] ? ' Bez dokumentu w księgach (' . count($r['unmatched']) . '): ' . implode(', ', array_slice($r['unmatched'], 0, 5)) . (count($r['unmatched']) > 5 ? '…' : '') . '.' : ''),
                $r['unmatched'] !== [] ? 'err' : 'ok');
            $this->go('vat', ['m' => $r['period']]);
        } catch (\RuntimeException $e) {
            flash($e->getMessage(), 'err');
            $this->go('import');
        }
    }

    public function comparison(): string
    {
        $year = $this->pickYear();
        if ($year === null) {
            return $this->noYear('import');
        }
        $cmp = (new JpkImport($this->db))->compare((string) $year['id']);
        $html = $this->head($this->can(Access::MANAGE) ? 'import' : 'obroty') . U::yearPicker('porownanie', $this->ledger->years(), (string) $year['id']);
        if ($cmp === null) {
            return $html . '<div class="card kg-muted">Dla roku ' . U::h($year['name']) . ' nie było importu JPK_KR - nie ma z czym porównać.</div>';
        }
        $all = isset($_GET['wszystkie']);
        $html .= '<div class="kg-top"><p>Zestawienie obrotów i sald z pliku <b>' . U::h($cmp['file']) . '</b> (import ' . U::h(substr($cmp['created_at'], 0, 16)) . ') porównane z księgami w CRM na dziś. Obroty narastająco bez bilansu otwarcia, saldo netto (Wn - Ma).</p>'
            . '<div class="kg-actions"><a class="btn secondary" href="' . U::h(U::url('porownanie', ['rok' => $year['id']] + ($all ? [] : ['wszystkie' => 1]))) . '">' . ($all ? 'Tylko różnice' : 'Wszystkie konta') . '</a></div></div>'
            . ($cmp['diff'] === 0 ? '<div class="flash ok">✓ Wszystkie ' . count($cmp['rows']) . ' kont zgadza się z plikiem.</div>'
                : '<div class="flash err">Różnice na ' . $cmp['diff'] . ' z ' . count($cmp['rows']) . ' kont.</div>');
        $rows = $all ? $cmp['rows'] : array_filter($cmp['rows'], static fn(array $r): bool => !$r['ok']);
        if ($rows === []) {
            return $html;
        }
        $html .= '<div class="card kg-table" style="padding:6px 10px"><table><thead><tr><th>Konto</th><th>Nazwa</th><th class="kg-num">BO plik</th><th class="kg-num">BO CRM</th>'
            . '<th class="kg-num">Obroty Wn plik</th><th class="kg-num">Obroty Wn CRM</th><th class="kg-num">Obroty Ma plik</th><th class="kg-num">Obroty Ma CRM</th>'
            . '<th class="kg-num">Saldo plik</th><th class="kg-num">Saldo CRM</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $f = $r['file'];
            $o = $r['ours'];
            $cell = static fn(int $a, int $b): string => '<td class="kg-num">' . U::money($a) . '</td><td class="kg-num' . ($a !== $b ? ' kg-bad' : '') . '">' . U::money($b) . '</td>';
            $html .= '<tr><td class="kg-acc">' . U::h($r['number']) . '</td><td>' . U::h($r['name']) . ($r['ok'] ? ' <span class="kg-ok">✓</span>' : '') . '</td>'
                . $cell((int) $f['bo_d'] - (int) $f['bo_c'], $o['bo_d'] - $o['bo_c']) . $cell((int) $f['to_d'], $o['to_d']) . $cell((int) $f['to_c'], $o['to_c'])
                . $cell($r['file_saldo'], $o['saldo']) . '</tr>';
        }
        return $html . '</tbody></table></div>';
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
            'company.update' => 'Dane firmy', 'contractor.create' => 'Nowy kontrahent', 'contractor.update' => 'Zmiana kontrahenta',
            'contractor.activate' => 'Włączenie kontrahenta', 'contractor.deactivate' => 'Wyłączenie kontrahenta',
            'settle.link' => 'Rozliczenie', 'settle.unlink' => 'Cofnięcie rozliczenia', 'bank.import' => 'Wczytanie wyciągu', 'bank.update' => 'Poprawa wyciągu',
            'bank.book' => 'Zaksięgowanie wyciągu', 'bank.delete' => 'Usunięcie wyciągu', 'import.jpk_kr' => 'Import JPK_KR', 'import.jpk_v7' => 'Import JPK_V7', 'import.wfirma' => 'Pobranie z wFirma (API)'];
        $html .= '<div class="card kg-table" style="padding:6px 10px"><table><thead><tr><th>Kiedy</th><th>Kto</th><th>Co</th><th>Szczegóły</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $link = $r['object_type'] === 'doc' && $r['action'] !== 'doc.delete' ? U::url('dokument', ['id' => $r['object_id']])
                : ($r['object_type'] === 'account' && $r['action'] !== 'account.delete' ? U::url('konto', ['id' => $r['object_id']])
                : ($r['object_type'] === 'contractor' ? U::url('kontrahent', ['id' => $r['object_id']])
                : ($r['object_type'] === 'bank' && $r['action'] !== 'bank.delete' ? U::url('wyciag', ['id' => $r['object_id']]) : '')));
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
