<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Wtyczka Księgowość - logika ksiąg na SQLite: kwoty w groszach, lata obrotowe, plan kont (wzór, analityka, blokady),
// dokumenty Wn/Ma, księgowanie z ciągłą numeracją dziennika, zamknięcie okresu, storno czerwone (też w innym roku),
// obroty i salda z sumą analityki, zapisy na koncie, dostęp, skrzynka zdarzeń, osobna baza (EXT_KSIEGOWOSC_DB_DSN), Api.
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Pase\\')) { require $root . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
    if (str_starts_with($class, 'PaseExt\\Ksiegowosc\\')) { require $root . '/extensions/ksiegowosc/' . substr($class, 19) . '.php'; }
});
use PaseExt\Ksiegowosc\{Access, Api, ChartTemplate, Db, Inbox, Ledger, Money, Pages};

$checks = 0;
function ok(bool $c, string $name): void { global $checks; if (!$c) { throw new RuntimeException('FAIL: ' . $name); } ++$checks; echo "OK: {$name}\n"; }
function fails(callable $fn, string $needle, string $name): void {
    try { $fn(); } catch (RuntimeException $e) { ok(str_contains($e->getMessage(), $needle), $name . ' (' . $e->getMessage() . ')'); return; }
    throw new RuntimeException('FAIL: ' . $name . ' - brak wyjątku');
}

// --- Kwoty
ok(Money::parse('1 234,56') === 123456 && Money::parse('0,5') === 50 && Money::parse('-12') === -1200 && Money::parse('') === null && Money::parse('7.05') === 705, 'Money::parse');
fails(fn() => Money::parse('1,234'), 'Niepoprawna kwota', 'Three decimals rejected');
fails(fn() => Money::parse('abc'), 'Niepoprawna kwota', 'Text rejected');
ok(Money::format(123456) === "1\u{a0}234,56" && Money::format(-5) === '-0,05' && Money::format(0, true) === '' && Money::input(-123456) === '-1234,56', 'Money::format/input');
ok(Pages::validNip('9522282284') && !Pages::validNip('9522282285'), 'NIP checksum');

// CRM_TEST_MYSQL=1: ten sam scenariusz na MySQL (osobna, tymczasowa baza), inaczej SQLite w pamięci.
$mysql = getenv('CRM_TEST_MYSQL') === '1';
$database = null;
if ($mysql) {
    $pdo = new PDO('mysql:host=127.0.0.1;port=' . (int) (getenv('CRM_TEST_MYSQL_PORT') ?: 3306) . ';charset=utf8mb4',
        getenv('CRM_TEST_MYSQL_USER') ?: 'root', getenv('CRM_TEST_MYSQL_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $database = 'crm_test_ksg_' . bin2hex(random_bytes(8));
    $pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
    $pdo->exec("USE `$database`");
    register_shutdown_function(static function () use ($pdo, $database): void { $pdo->exec("DROP DATABASE IF EXISTS `$database`"); });
} else {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
echo 'Baza: ' . ($mysql ? 'MySQL' : 'SQLite') . "\n";
$tablesOf = static fn(PDO $p): array => $p->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
    ? $p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)
    : $p->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
\Pase\Support\Runtime::setPdo($pdo);
$db = Db::connect($pdo);
ok($db->pdo === $pdo && $db->meta('schema') === Db::SCHEMA, 'Default connection uses CRM database, schema version in ext_ksg_meta');
$tables = $tablesOf($pdo);
ok(array_diff($tables, array_keys(Db::tables())) === [] && count($tables) === count(Db::tables()), 'Only the module tables are created');
ok(array_filter($tables, static fn(string $t): bool => !str_starts_with($t, 'ext_ksg_')) === [], 'Every table has the ext_ksg_ prefix');
new Db($pdo); // drugi raz bez błędów
$l = new Ledger($db);

// --- Lata obrotowe
fails(fn() => $l->createYear('2026', '2026-01-01', '2028-01-01', 'template', 'adam'), '24 miesiące', 'Year longer than 24 months rejected');
fails(fn() => $l->createYear('x', '2026-02-30', '2026-12-31', 'template', 'adam'), 'poprawne daty', 'Invalid date rejected');
$y26 = $l->createYear('2026', '2026-01-01', '2026-12-31', 'template', 'adam');
ok(count($l->accounts($y26['id'])) === count(ChartTemplate::spzoo()), 'Template chart loaded into the year');
fails(fn() => $l->createYear('dup', '2026-06-01', '2027-05-31', 'empty', 'adam'), 'nachodzi', 'Overlapping year rejected');
ok($l->yearFor('2026-07-15')['id'] === $y26['id'] && $l->yearFor('2025-12-31') === null, 'yearFor');

// --- Plan kont
$acc = fn(string $n) => $l->accountByNumber($y26['id'], $n);
$all = array_column($l->accounts($y26['id']), null, 'number');
ok($all['130']['has_children'] && !$all['130-01']['has_children'] && $all['130-01']['parent'] === '130' && $all['402-01']['level'] === 1, 'Synthetic/analytic flags');
fails(fn() => $l->saveAccount($y26['id'], null, ['number' => '999-01', 'name' => 'X', 'kind' => 'A'], 'adam'), 'konto nadrzędne 999', 'Analytic needs parent');
fails(fn() => $l->saveAccount($y26['id'], null, ['number' => '1300', 'name' => 'X', 'kind' => 'A'], 'adam'), 'Numer konta', 'Bad number rejected');
fails(fn() => $l->saveAccount($y26['id'], null, ['number' => '130-01', 'name' => 'X', 'kind' => 'A'], 'adam'), 'już jest', 'Duplicate number rejected');
$a202 = $l->saveAccount($y26['id'], null, ['number' => '202-01', 'name' => 'Dostawca wosku', 'kind' => 'AP'], 'adam');
$l->saveAccount($y26['id'], null, ['number' => '200-ALLEGRO', 'name' => 'Allegro', 'kind' => 'AP'], 'adam');
$l->saveAccount($y26['id'], null, ['number' => '200-SKLEP', 'name' => 'Sklep', 'kind' => 'AP'], 'adam');
ok($acc('202-01')['name'] === 'Dostawca wosku', 'Analytic account added');

// --- Dokumenty
$line = static fn(string $a, string $d, string $c, string $desc = ''): array => ['account' => $a, 'debit' => $d, 'credit' => $c, 'description' => $desc];
fails(fn() => $l->saveDraft(null, ['type' => 'PK', 'posting_date' => '2025-05-05', 'description' => 'x', 'lines' => [$line('100', '1', '')]], 'adam'), 'nie leży w żadnym roku', 'Date outside years rejected');
fails(fn() => $l->saveDraft(null, ['type' => 'PK', 'posting_date' => '2026-05-05', 'description' => 'x', 'lines' => [$line('100', '1', '1')]], 'adam'), 'po jednej stronie', 'Both sides on one line rejected');
fails(fn() => $l->saveDraft(null, ['type' => 'PK', 'posting_date' => '2026-05-05', 'description' => 'x', 'lines' => [$line('555', '1', '')]], 'adam'), 'nie ma konta 555', 'Unknown account rejected');
fails(fn() => $l->saveDraft(null, ['type' => 'PK', 'posting_date' => '2026-05-05', 'description' => 'x', 'lines' => [['account' => '', 'debit' => '', 'credit' => '']]], 'adam'), 'co najmniej jedną', 'Empty lines rejected');
fails(fn() => $l->saveDraft(null, ['type' => 'ST', 'posting_date' => '2026-05-05', 'description' => 'x', 'lines' => [$line('100', '1', '')]], 'adam'), 'rodzaj', 'Storno type not manual');

// Bilans otwarcia: kapitał zakładowy wpłacony na rachunek.
$bo = $l->saveDraft(null, ['type' => 'BO', 'posting_date' => '2026-01-01', 'description' => 'Bilans otwarcia', 'lines' => [
    $line('130-01 Rachunek bieżący', '5 000,00', ''), $line('800', '', '5000')]], 'adam');
ok($bo['status'] === 'draft' && $bo['number'] === 'BO/1/01/2026' && (int) $bo['total_gr'] === 500000 && $bo['journal_no'] === null, 'Draft with automatic number');
// Faktura zakupu wosku: brutto 1230 = netto 1000 + VAT 230; szkic niezbilansowany (brak VAT).
$fz = $l->saveDraft(null, ['type' => 'FZ', 'number' => 'FV/77/2026', 'posting_date' => '2026-03-10', 'doc_date' => '2026-03-08', 'description' => 'Wosk sojowy',
    'counterparty' => 'Dostawca wosku', 'lines' => [$line('401', '1000', ''), $line('202-01', '', '1230')]], 'ola');
ok($fz['number'] === 'FV/77/2026' && $fz['op_date'] === '2026-03-08', 'Own number and default operation date kept');
ok(in_array(true, array_map(static fn(string $p): bool => str_contains($p, 'nie bilansują'), $l->postingProblems($fz)), true), 'Unbalanced draft has a problem listed');
fails(fn() => $l->post($fz['id'], 'ola'), 'nie bilansują', 'Unbalanced draft cannot be posted');
$fz = $l->saveDraft($fz['id'], ['type' => 'FZ', 'number' => 'FV/77/2026', 'posting_date' => '2026-03-10', 'doc_date' => '2026-03-08', 'description' => 'Wosk sojowy',
    'counterparty' => 'Dostawca wosku', 'lines' => [$line('401', '1000', ''), $line('221', '230', '', 'VAT 23%'), $line('202-01', '', '1230')]], 'ola');
ok(count($l->entries($fz['id'])) === 3 && $l->postingProblems($fz) === [], 'Draft corrected and balanced');
fails(fn() => $l->saveDraft(null, ['type' => 'PK', 'posting_date' => '2026-05-05', 'description' => 'x', 'lines' => [$line('130', '1', ''), $line('800', '', '1')]], 'adam')
    && $l->post($l->docs($y26['id'], ['status' => 'draft', 'type' => 'PK'])['rows'][0]['id'], 'adam'), 'ma konta podrzędne', 'Posting to a synthetic account with analytics rejected');
$l->deleteDraft($l->docs($y26['id'], ['status' => 'draft', 'type' => 'PK'])['rows'][0]['id'], 'adam');

$fz = $l->post($fz['id'], 'ola');
$bo = $l->post($bo['id'], 'adam');
ok($fz['status'] === 'posted' && (int) $fz['journal_no'] === 1 && (int) $bo['journal_no'] === 2, 'Journal numbers in posting order');
fails(fn() => $l->saveDraft($fz['id'], ['type' => 'FZ', 'posting_date' => '2026-03-10', 'description' => 'x', 'lines' => [$line('401', '1', ''), $line('202-01', '', '1')]], 'ola'), 'zaksięgowany', 'Posted document cannot be edited');
fails(fn() => $l->deleteDraft($fz['id'], 'ola'), 'nie da się usunąć', 'Posted document cannot be deleted');
fails(fn() => $l->post($fz['id'], 'ola'), 'już zaksięgowany', 'Double posting rejected');
fails(fn() => $l->saveAccount($y26['id'], null, ['number' => '401-01', 'name' => 'X', 'kind' => 'W'], 'adam'), 'ma już zapisy', 'Analytics cannot be added under an account with entries');
fails(fn() => $l->saveAccount($y26['id'], $acc('401')['id'], ['number' => '402-09', 'name' => 'X', 'kind' => 'W'], 'adam'), 'numeru nie da się zmienić', 'Number of an account with entries is fixed');
fails(fn() => $l->deleteAccount($acc('401')['id'], 'adam'), 'ma zapisy', 'Account with entries cannot be deleted');

// Zapłata z rachunku i sprzedaż.
$pay = $l->post($l->saveDraft(null, ['type' => 'WB', 'posting_date' => '2026-03-20', 'description' => 'Zapłata FV/77', 'lines' => [$line('202-01', '1230', ''), $line('130-01', '', '1230')]], 'ola')['id'], 'ola');
$fs = $l->post($l->saveDraft(null, ['type' => 'FS', 'posting_date' => '2026-04-02', 'description' => 'Sprzedaż świec', 'lines' => [
    $line('200-ALLEGRO', '615', ''), $line('700', '', '500'), $line('222', '', '115')]], 'ola')['id'], 'ola');
ok((int) $pay['journal_no'] === 3 && (int) $fs['journal_no'] === 4 && $pay['number'] === 'WB/1/03/2026', 'More documents posted');

// Zamknięcie okresu.
$l->lockUntil($y26['id'], '2026-03-31', 'adam');
$late = $l->saveDraft(null, ['type' => 'PK', 'posting_date' => '2026-03-15', 'description' => 'Spóźniony', 'lines' => [$line('409', '10', ''), $line('100', '', '10')]], 'ola');
fails(fn() => $l->post($late['id'], 'ola'), 'jest zamknięty', 'Locked period blocks posting');
fails(fn() => $l->reverse($fz['id'], '2026-03-31', 'ola'), 'jest zamknięty', 'Storno dated in a locked period rejected');
fails(fn() => $l->lockUntil($y26['id'], '2027-01-05', 'adam'), 'w roku obrotowym', 'Lock date must be inside the year');

// Obroty i salda.
$tb = $l->turnover($y26['id'], '2026-01-01', '2026-12-31');
$row = array_column($tb['rows'], null, 'number');
ok($row['130']['cd'] === 500000 && $row['130']['cc'] === 123000 && $row['130']['sd'] === 377000 && $row['130-01']['sd'] === 377000, 'Synthetic account sums its analytics');
ok($row["202"]["sd"] === 0 && $row['202']['sc'] === 0 && $row['200']['sd'] === 61500 && $row['800']['sc'] === 500000, 'Balances of settlements and capital');
ok($tb['totals']['cd'] === $tb['totals']['cc'] && $tb['totals']['cd'] === 500000 + 123000 + 123000 + 61500, 'Trial balance: debit = credit, analytics not double counted');
$tbApr = $l->turnover($y26['id'], '2026-04-01', '2026-04-30');
$r = array_column($tbApr['rows'], null, 'number');
ok($r['700']['pc'] === 50000 && $r['130']['pd'] === 0 && $r['130']['cd'] === 500000, 'Period vs cumulative turnover');

// Zapisy na koncie.
$led = $l->accountLedger($acc('130')['id'], '2026-03-01', '2026-12-31');
ok($led['opening'] === 500000 && count($led['rows']) === 1 && $led['rows'][0]['balance'] === 377000 && $led['credit'] === 123000, 'Account ledger with opening balance');

// Storno w tym samym roku.
$st = $l->reverse($fs['id'], '2026-04-30', 'adam', 'zły kontrahent');
ok($st['type'] === 'ST' && $st['status'] === 'posted' && (int) $st['journal_no'] === 5 && (int) $st['total_gr'] === -61500 && $st['reversal_of'] === $fs['id'], 'Red storno document');
ok($l->doc($fs['id'])['reversed_by'] === $st['id'] && $l->doc($fs['id'])['status'] === 'posted', 'Original stays posted, marked reversed');
$r = array_column($l->turnover($y26['id'], '2026-01-01', '2026-12-31')['rows'], null, 'number');
ok($r['700']['cc'] === 0 && $r['200-ALLEGRO']['sd'] === 0, 'Storno brings turnover back');
fails(fn() => $l->reverse($fs['id'], '2026-05-01', 'adam'), 'już wystornowany', 'Second storno rejected');
fails(fn() => $l->reverse($st['id'], '2026-05-01', 'adam'), 'nie stornuje', 'Storno of storno rejected');
fails(fn() => $l->reverse($late['id'], '2026-05-01', 'adam'), 'tylko zaksięgowany', 'Draft cannot be reversed');

// Nowy rok z kopią planu kont i storno w innym roku.
$y27 = $l->createYear('', '2027-01-01', '2027-12-31', $y26['id'], 'adam');
ok($y27['name'] === '2027' && $l->accountByNumber($y27['id'], '202-01') !== null && count($l->accounts($y27['id'])) === count($l->accounts($y26['id'])), 'Next year copies the chart of accounts');
$st2 = $l->reverse($pay['id'], '2027-01-10', 'adam');
ok($st2['year_id'] === $y27['id'] && (int) $st2['journal_no'] === 1 && $l->entries($st2['id'])[0]['account_number'] === '202-01'
    && $l->entries($st2['id'])[0]['account_id'] === $l->accountByNumber($y27['id'], '202-01')['id'], 'Storno in another year uses that year accounts and numbering');

// Wyłączone konto.
$l->setAccountActive($acc('409')['id'], false, 'adam');
$l->lockUntil($y26['id'], '', 'adam');
fails(fn() => $l->post($late['id'], 'ola'), 'wyłączone', 'Inactive account blocks posting');
$l->setAccountActive($acc('409')['id'], true, 'adam');
ok((int) $l->post($late['id'], 'ola')['journal_no'] === 6, 'Unlocked period posts again');

// Lista dokumentów.
ok($l->docs($y26['id'], ['q' => 'wosk'])['total'] === 1 && $l->docs($y26['id'], ['month' => '2026-03'])['total'] === 3 && $l->docs($y26['id'], ['type' => 'ST'])['total'] === 1, 'Document filters');
$j = $l->journal($y26['id'], '2026-01-01', '2026-12-31');
ok(array_map(static fn(array $d): int => (int) $d['journal_no'], $j) === [1, 2, 3, 4, 5, 6], 'Journal in posting order');

// Dziennik zmian.
$log = $db->all('SELECT action FROM ext_ksg_log');
ok(in_array('doc.post', array_column($log, 'action'), true) && in_array('doc.reverse', array_column($log, 'action'), true) && in_array('year.lock', array_column($log, 'action'), true), 'Every change is logged');

// --- Dostęp
$acl = new Access($db);
ok($acl->level(null, true) === Access::MANAGE && $acl->level(7, false) === Access::NONE, 'Admin full, others none by default');
$acl->set(7, Access::BOOK, 'adam');
ok($acl->level(7, false) === Access::BOOK && Access::allows(Access::BOOK, Access::VIEW) && !Access::allows(Access::BOOK, Access::MANAGE), 'Access levels');
$acl->set(7, Access::NONE, 'adam');
ok($acl->level(7, false) === Access::NONE && $acl->all() === [], 'Access removed');
fails(fn() => $acl->set(7, 'boss', 'adam'), 'Nieznany', 'Unknown level rejected');
$pdo->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY, username VARCHAR(50), display_name VARCHAR(100), role VARCHAR(20), is_active INTEGER)');
$pdo->exec("INSERT INTO admin_users VALUES (1,'boss','Adam','admin',1),(2,'ola','Ola','editor',1),(3,'old','Stary','viewer',0)");
ok(array_column(Access::crmUsers($pdo), 'name') === ['Adam', 'Ola'], 'CRM users for access (active only)');

// --- Skrzynka i Api
$inbox = new Inbox($db);
$inbox->record('order.imported', 42, ['number' => '42', 'total' => '123.00', 'billing' => ['last_name' => 'Kowalska']]);
$inbox->record('shipment.created', 42, []);
$inbox->record('status.changed', 42, ['status' => 'completed', 'big' => str_repeat('x', 250000)]);
$list = $inbox->list();
ok($list['total'] === 2 && $list['pending'] === 2, 'Inbox stores only known events');
ok(json_decode($db->one("SELECT payload FROM ext_ksg_inbox WHERE event = 'status.changed'")['payload'], true)['_truncated'] === true, 'Huge payload truncated');
$api = Api::make($pdo);
ok($api->orderEvents(42) === 2 && $api->orderEvents(1) === 0, 'Api::orderEvents');
ok($api->balance('130', '2026-12-31') === 377000 && $api->balance('130', '2027-02-01') === 123000 && $api->balance('999', '2026-05-05') === null, 'Api::balance (with storno in the next year)');
$l->lockUntil($y26['id'], '2026-06-30', 'adam');
ok(!$api->periodOpen('2026-06-30') && $api->periodOpen('2026-07-01') && !$api->periodOpen('2030-01-01'), 'Api::periodOpen');

// --- Kontrahenci (etap 2a)
use PaseExt\Ksiegowosc\{Contractors, Vat};
$cs = new Contractors($db);
fails(fn() => $cs->save(null, ['name' => ''], 'adam'), 'nazwę', 'Contractor needs a name');
fails(fn() => $cs->save(null, ['name' => 'X', 'nip' => '952-228-22-85'], 'adam'), 'sumę kontrolną', 'Bad NIP checksum rejected');
fails(fn() => $cs->save(null, ['name' => 'X', 'country' => 'POL'], 'adam'), 'dwuliterowy', 'Bad country rejected');
$wosk = $cs->save(null, ['name' => 'Woskownia Sp. z o.o.', 'nip' => 'PL 952-228-22-84', 'kind' => 'supplier', 'city' => 'Łódź'], 'adam');
ok($wosk['code'] === '0001' && $wosk['nip'] === '9522282284' && $wosk['kind'] === 'supplier', 'Contractor saved with code and clean NIP');
fails(fn() => $cs->save(null, ['name' => 'Kopia', 'nip' => '9522282284'], 'adam'), 'już jest', 'Duplicate NIP rejected');
$de = $cs->save(null, ['name' => 'Kerzen GmbH', 'nip' => 'DE123456789', 'country' => 'de', 'kind' => 'customer'], 'adam');
$both = $cs->save(null, ['name' => 'Hurtownia Świec', 'kind' => 'both'], 'adam');
ok($de['code'] === '0002' && $de['country'] === 'DE' && $de['nip'] === 'DE123456789', 'Foreign VAT number kept');
ok(Contractors::accountNumbers($wosk) === ['202' => '202-0001'] && Contractors::accountNumbers($de) === ['200' => '200-0002']
    && Contractors::accountNumbers($both) === ['200' => '200-0003', '202' => '202-0003'], 'Settlement account numbers by kind');
ok($l->accountByNumber($y27['id'], '202-0001') !== null && $l->accountByNumber($y27['id'], '200-0003') !== null
    && $l->accountByNumber($y27['id'], '200-0001') === null, 'Settlement analytics created in open years');
$cs->save($wosk['id'], ['name' => 'Woskownia SA', 'nip' => '9522282284', 'kind' => 'supplier'], 'adam');
ok($l->accountByNumber($y27['id'], '202-0001')['name'] === 'Woskownia SA', 'Rename follows to the analytic account');
ok(count($cs->list('wosk')) === 1 && count($cs->list('228')) === 1 && count($cs->list('')) === 3, 'Contractor search by name and NIP');
$cs->setActive($de['id'], false, 'adam');
ok(count($cs->list()) === 2 && count($cs->list('', true)) === 3, 'Inactive contractor hidden from the list');
$cs->setActive($de['id'], true, 'adam');
ok($cs->settlementAccount($wosk, $y27['id'], '202') === '202-0001' && $cs->settlementAccount($de, $y27['id'], '202') === null, 'settlementAccount');

// --- VAT (etap 2a)
ok(Vat::vatFor(100000, '23') === 23000 && Vat::vatFor(1, '23') === 0 && Vat::vatFor(3, '23') === 1 && Vat::vatFor(-3, '23') === -1
    && Vat::vatFor(1000, '8') === 80 && Vat::vatFor(5000, 'zw') === 0 && Vat::vatFor(5000, '0') === 0, 'vatFor with rounding');
ok(Vat::registerFor('FZ') === 'Z' && Vat::registerFor('FS') === 'S' && Vat::registerFor('PK') === 'S', 'Default register by document type');
$p = Vat::parse([['rate' => '23', 'net' => '100'], ['rate' => '8', 'net' => '', 'vat' => ''], ['rate' => 'zw', 'net' => '50']], 'FS', '2027-02', '2027-03');
ok(count($p) === 2 && $p[0]['vat_gr'] === 2300 && $p[0]['register'] === 'S' && $p[0]['period'] === '2027-02' && $p[1]['vat_gr'] === 0, 'Parse VAT rows (empty skipped, VAT computed)');
$p = Vat::parse([['rate' => '23', 'net' => '1000', 'vat' => '230,01', 'deduct' => '50', 'kind' => 'fixed']], 'FZ', '2027-02', '2027-03');
ok($p[0]['register'] === 'Z' && $p[0]['period'] === '2027-03' && $p[0]['vat_gr'] === 23001 && Vat::deductible($p[0]) === 11501 && $p[0]['kind'] === 'fixed', 'Purchase VAT: own amount, period, 50% deduction');
fails(fn() => Vat::parse([['rate' => 'zw', 'net' => '10', 'vat' => '1']], 'FS', '2027-02', '2027-02'), 'musi być 0', 'VAT on exempt rate rejected');
fails(fn() => Vat::parse([['rate' => '99', 'net' => '10']], 'FS', '2027-02', '2027-02'), 'stawkę', 'Unknown rate rejected');
fails(fn() => Vat::parse([['rate' => '23', 'net' => '10', 'period' => '2027-13']], 'FS', '2027-02', '2027-02'), 'RRRR-MM', 'Bad VAT period rejected');

// Podpowiedź dekretu i faktury z VAT.
$vz = Vat::parse([['rate' => '23', 'net' => '1000'], ['rate' => '23', 'net' => '200', 'deduct' => '50']], 'FZ', '2027-02', '2027-02');
$sug = $l->suggestLines($y27['id'], 'FZ', $wosk, $vz, '401 Zużycie materiałów');
ok(array_column($sug, 'account') === ['401', '221', '202-0001'] && $sug[0]['debit'] === '1223,00' && $sug[1]['debit'] === '253,00' && $sug[2]['credit'] === '1476,00', 'Purchase posting suggestion (non-deductible VAT to cost)');
$vs = Vat::parse([['rate' => '23', 'net' => '500'], ['rate' => '8', 'net' => '100']], 'FS', '2027-02', '2027-02');
$sug = $l->suggestLines($y27['id'], 'FS', $de, $vs, '');
ok(array_column($sug, 'account') === ['200-0002', '700', '222'] && $sug[0]['debit'] === '723,00' && $sug[2]['credit'] === '123,00', 'Sale posting suggestion');
fails(fn() => $l->suggestLines($y27['id'], 'FS', null, [], ''), 'pozycje VAT', 'Suggestion needs VAT rows');

$fs = $l->saveDraft(null, ['type' => 'FS', 'number' => 'FS/1/02/2027', 'posting_date' => '2027-02-10', 'op_date' => '2027-01-31', 'description' => 'Świece', 'contractor_id' => $de['id'],
    'vat' => [['rate' => '23', 'net' => '500'], ['rate' => '8', 'net' => '100']], 'lines' => $l->suggestLines($y27['id'], 'FS', $de, $vs, '')], 'ola');
ok($fs['counterparty'] === 'Kerzen GmbH' && $fs['contractor_id'] === $de['id'] && count((new Vat($db))->forDoc($fs['id'])) === 2, 'Sales invoice with contractor and VAT rows');
ok((new Vat($db))->forDoc($fs['id'])[0]['period'] === '2027-01', 'Sales VAT period follows the operation date');
ok($l->warnings($fs) === [], 'No warnings when VAT matches account 222');
$fzv = $l->saveDraft(null, ['type' => 'FZ', 'number' => 'W/9', 'posting_date' => '2027-01-20', 'description' => 'Wosk', 'contractor_id' => $wosk['id'],
    'vat' => [['rate' => '23', 'net' => '1000'], ['rate' => '23', 'net' => '200', 'deduct' => '50']], 'lines' => [$line('401', '1000', ''), $line('202-0001', '', '1000')]], 'ola');
ok(count($l->warnings($fzv)) === 1 && str_contains($l->warnings($fzv)[0], 'konto 221') === false && str_contains($l->warnings($fzv)[0], '221'), 'Warning when VAT does not match account 221');
$noVat = $l->saveDraft(null, ['type' => 'FS', 'posting_date' => '2027-01-21', 'description' => 'Bez VAT', 'lines' => [$line('130-01', '10', ''), $line('700', '', '10')]], 'ola');
ok(count($l->warnings($noVat)) === 2, 'Invoice without VAT rows and contractor is warned about');
$l->deleteDraft($noVat['id'], 'ola');
$fzv = $l->saveDraft($fzv['id'], ['type' => 'FZ', 'number' => 'W/9', 'posting_date' => '2027-01-20', 'description' => 'Wosk', 'contractor_id' => $wosk['id'],
    'vat' => [['rate' => '23', 'net' => '1000'], ['rate' => '23', 'net' => '200', 'deduct' => '50']], 'lines' => $l->suggestLines($y27['id'], 'FZ', $wosk, $vz, '401')], 'ola');
ok($l->warnings($fzv) === [] && $l->postingProblems($fzv) === [], 'Suggested purchase posting is balanced and matches VAT');

// Rejestry liczą tylko dokumenty zaksięgowane.
$vat = new Vat($db);
ok($vat->register('S', '2027-01')['docs'] === [], 'Draft not in the VAT register');
$l->post($fs['id'], 'ola');
$l->post($fzv['id'], 'ola');
$rs = $vat->register('S', '2027-01');
ok(count($rs['docs']) === 1 && $rs['net'] === 60000 && $rs['vat'] === 12300 && $rs['by_rate']['23']['vat'] === 11500 && $rs['by_rate']['8']['net'] === 10000
    && $rs['docs'][0]['nip'] === 'DE123456789' && array_map('strval', array_keys($rs['by_rate'])) === ['23', '8'], 'Sales register with totals per rate');
$rz = $vat->register('Z', '2027-01');
ok(count($rz['docs']) === 1 && $rz['vat'] === 27600 && $rz['deductible'] === 25300, 'Purchase register with deductible VAT');
ok($vat->settlement('2027-01') === ['due' => 12300, 'input' => 25300, 'balance' => -13000], 'VAT settlement for the month');
ok($vat->settlement('2027-02') === ['due' => 0, 'input' => 0, 'balance' => 0], 'Empty month settles to zero');
$stv = $l->reverse($fs['id'], '2027-02-15', 'ola', 'Zła faktura');
ok(array_map(static fn(array $v): int => (int) $v['vat_gr'], $vat->forDoc($stv['id'])) === [-11500, -800] && $vat->forDoc($stv['id'])[0]['period'] === '2027-01'
    && $stv['contractor_id'] === $de['id'], 'Storno copies VAT rows negated in the same period');
$rs = $vat->register('S', '2027-01');
ok(count($rs['docs']) === 2 && $rs['vat'] === 0 && $rs['net'] === 0, 'Reversed invoice nets out of the register');

// Migracja ze schematu 1: brak kolumny contractor_id i nowych tabel.
$old = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$old->exec('CREATE TABLE ext_ksg_meta (k VARCHAR(64) PRIMARY KEY, v TEXT)');
$old->exec("INSERT INTO ext_ksg_meta VALUES ('schema', '1')");
$old->exec('CREATE TABLE ext_ksg_docs (id VARCHAR(16) PRIMARY KEY, number VARCHAR(60))');
$old->exec("INSERT INTO ext_ksg_docs VALUES ('aaaaaaaaaaaaaaaa', 'PK/1')");
$mig = new Db($old);
ok($mig->meta('schema') === Db::SCHEMA && $mig->one('SELECT contractor_id FROM ext_ksg_docs')['contractor_id'] === null && count($tablesOf($old)) === count(Db::tables()), 'Schema 1 migrates to schema 2');

// --- Rozrachunki (etap 2b)
use PaseExt\Ksiegowosc\{Bank, Settlements};
$se = new Settlements($db);
ok($mig->one('SELECT due_date FROM ext_ksg_docs')['due_date'] === null, 'Schema migration adds due_date');
$w9 = $se->items(['doc' => $fzv['id']]);
ok(count($w9) === 1 && $w9[0]['account'] === '202-0001' && $w9[0]['kind'] === 'debt' && $w9[0]['remaining'] === 147600 && $w9[0]['contractor']['id'] === $wosk['id'], 'Purchase invoice is an open payable');
ok(array_sum(array_column($se->items(['doc' => $fs['id']]), 'remaining')) === 0 && array_sum(array_column($se->items(['doc' => $stv['id']]), 'remaining')) === 0, 'Storno settles the reversed invoice');
fails(fn() => $l->saveDraft(null, ['type' => 'FS', 'posting_date' => '2027-03-01', 'due_date' => '2027-02-30', 'description' => 'x', 'lines' => [$line('100', '1', '')]], 'ola'), 'termin płatności', 'Invalid due date rejected');
$inv = fn(string $no, string $date, string $due, string $gross) => $l->post($l->saveDraft(null, ['type' => 'FS', 'number' => $no, 'posting_date' => $date, 'due_date' => $due, 'description' => 'Świece', 'contractor_id' => $both['id'],
    'lines' => [$line('200-0003', $gross, ''), $line('700', '', $gross)]], 'ola')['id'], 'ola');
$f5 = $inv('FS/5/2027', '2027-03-01', '2027-03-15', '1230');
ok($f5['due_date'] === '2027-03-15', 'Due date stored');
$debt5 = $se->items(['doc' => $f5['id'], 'as_of' => '2027-04-01'])[0];
ok($debt5['kind'] === 'debt' && $debt5['late_days'] === 17 && $debt5['due'] === '2027-03-15', 'Overdue days from the due date');
$payDoc = fn(string $date, string $amount, string $desc, string $acc = '200-0003') => $l->post($l->saveDraft(null, ['type' => 'WB', 'posting_date' => $date, 'description' => $desc,
    'lines' => [$line('130-01', $amount, ''), $line($acc, '', $amount, $desc)]], 'ola')['id'], 'ola');
$p1 = $payDoc('2027-03-20', '1000', 'Wpłata częściowa');
$pay1 = $se->items(['doc' => $p1['id']]);
$payE = array_values(array_filter($pay1, static fn(array $i): bool => $i['account'] === '200-0003'))[0];
ok($payE['kind'] === 'pay' && $payE['remaining'] === 100000, 'Payment on the receivable account');
fails(fn() => $se->link($debt5['entry_id'], $payE['entry_id'], 100001, 'ola'), 'od 0,01 do', 'Settlement above the open amount rejected');
fails(fn() => $se->link($debt5['entry_id'], $debt5['entry_id'], null, 'ola'), 'przeciwnych stronach', 'Two debts cannot be settled together');
fails(fn() => $se->link($w9[0]['entry_id'], $payE['entry_id'], null, 'ola'), 'różnych kontach', 'Different accounts rejected');
ok($se->link($payE['entry_id'], $debt5['entry_id'], null, 'ola') === 100000, 'Partial settlement (order of arguments does not matter)');
ok($se->item($debt5['entry_id'])['remaining'] === 23000 && $se->item($payE['entry_id'])['remaining'] === 0, 'Remaining after partial payment');
$lnk = $se->linksForDoc($f5['id']);
ok(count($lnk) === 1 && $lnk[0]['pay_doc_id'] === $p1['id'], 'Links listed for the invoice');
$se->unlink($lnk[0]['id'], 'ola');
ok($se->item($debt5['entry_id'])['remaining'] === 123000, 'Unlink reopens the invoice');
// Automatyczne: numer faktury w tytule, potem równa kwota.
$f6 = $inv('FS/6/2027', '2027-03-02', '2027-03-16', '500');
$p2 = $payDoc('2027-03-21', '230', 'Dopłata FS-5-2027');
$p3 = $payDoc('2027-03-22', '500', 'Przelew');
ok($se->autoMatch('ola', '200-0003') === 3, 'Auto match by invoice number and equal amount');
ok($se->item($debt5['entry_id'])['remaining'] === 0 && $se->items(['doc' => $f6['id']])[0]['remaining'] === 0, 'Invoices settled automatically');
ok($se->items(['open' => true, 'account' => '200-0003']) === [], 'Nothing open on the account');
$sum = $se->summary($se->items(['open' => true, 'as_of' => '2027-06-01']));
$g = array_column($sum, null, 'account')['202-0001'];
ok($g['debt'] === 147600 && $g['late_more'] === 147600 && $g['pay'] === 0, 'Summary with overdue buckets');

// --- Wyciągi bankowe (etap 2b)
ok(Bank::csvAmount('-1 234,56') === -123456 && Bank::csvAmount('+12.50') === 1250 && Bank::csvAmount('1.234,56 PLN') === 123456 && Bank::csvAmount('1,234.56') === 123456 && Bank::csvAmount('Saldo') === null, 'CSV amounts');
ok(Bank::csvDate('2027-03-05') === '2027-03-05' && Bank::csvDate('05.03.2027') === '2027-03-05' && Bank::csvDate('05-03-2027') === '2027-03-05' && Bank::csvDate('31.02.2027') === null, 'CSV dates');
ok(Bank::nrb('PL 61 1090 1014 0000 0712 1981 2874') === '61109010140000071219812874' && Bank::cleanIban('xx') === '', 'Account numbers normalized');
$mt = ":20:ST1\n:25:/PL61109010140000071219812874\n:28C:1\n:60F:C270331PLN1000,00\n"
    . ":61:2704020402C1230,00NTRFNONREF\n:86:020~00VE02~20Zaplata za FS/7/2027~21dziekujemy~3010901014~310000071219812874~32HURTOWNIA SWIEC~38PL61109010140000071219812874\n"
    . ":61:2704030403D1476,00NTRFNONREF\n:86:020~00VE02~20Faktura W 9~32WOSKOWNIA SA\n:61:2704030403D5,00NMSCNONREF\n:86:073~00OP~20Oplata za przelew\n:62F:C270403PLN-251,00\n-";
$p = Bank::parse($mt);
ok($p['format'] === 'mt940' && count($p['lines']) === 3 && $p['lines'][0]['amount'] === 123000 && $p['lines'][1]['amount'] === -147600 && $p['opening'] === 100000, 'MT940 parsed');
ok($p['lines'][0]['title'] === 'Zaplata za FS/7/2027dziekujemy' && $p['lines'][0]['name'] === 'HURTOWNIA SWIEC' && $p['lines'][0]['iban'] === 'PL61109010140000071219812874' && $p['lines'][0]['date'] === '2027-04-02', 'MT940 details field');
$csv = iconv('UTF-8', 'WINDOWS-1250', "Historia rachunku\nData transakcji;Dane kontrahenta;Tytuł;Nr rachunku;Kwota transakcji (waluta rachunku);Waluta;Saldo po transakcji\n"
    . "2027-04-05;\"Hurtownia Świec\";\"FS/8/2027\";'61 1090 1014 0000 0712 1981 2874';\"615,00\";PLN;\"1 000,00\"\n2027-04-06;Bank;Opłata;;-3,50;PLN;996,50\nRazem;;;;611,50;;\n");
$p = Bank::parse($csv);
ok($p['format'] === 'csv' && count($p['lines']) === 2 && $p['lines'][0]['name'] === 'Hurtownia Świec' && $p['lines'][0]['amount'] === 61500 && $p['lines'][1]['amount'] === -350 && $p['lines'][0]['iban'] === '61109010140000071219812874', 'CSV in Windows-1250 parsed');
fails(fn() => Bank::parse("a;b\n1;2"), 'Nie rozpoznaję', 'Unknown file rejected');

$cs->save($both['id'], ['name' => 'Hurtownia Świec', 'kind' => 'both', 'bank_account' => '61 1090 1014 0000 0712 1981 2874'], 'adam');
$f7 = $inv('FS/7/2027', '2027-03-25', '2027-04-08', '1230');
$bank = new Bank($db);
fails(fn() => $bank->import('401', 'x.sta', $mt, 'ola'), 'konto bankowe', 'Import needs a bank account');
$s1 = $bank->import('130-01', 'kwiecien.sta', $mt, 'ola');
$bl = $bank->lines($s1['id']);
ok($s1['format'] === 'mt940' && $s1['date_from'] === '2027-04-02' && $s1['date_to'] === '2027-04-03' && count($bl) === 3 && $s1['skipped'] === 0, 'Statement imported');
ok($bl[0]['contractor_id'] === $both['id'] && $bl[0]['account'] === '200-0003' && $bl[0]['match_entry_id'] === $se->items(['doc' => $f7['id']])[0]['entry_id'], 'Inflow: contractor by account number, invoice by number in title');
ok($bl[1]['contractor_id'] === $wosk['id'] && $bl[1]['account'] === '202-0001' && $bl[1]['match_entry_id'] === $w9[0]['entry_id'], 'Outflow: contractor by name, invoice by equal amount');
ok($bl[2]['account'] === '' && $bl[2]['contractor_id'] === null, 'Unknown operation left for the user');
fails(fn() => $bank->import('130-01', 'kwiecien.sta', $mt, 'ola'), 'już zaimportowane', 'Same file is not imported twice');
fails(fn() => $bank->book($s1['id'], 'ola'), 'operacji: 3', 'Booking needs an account for every operation');
fails(fn() => $bank->saveLines($s1['id'], [$bl[2]['id'] => ['account' => '999']], 'ola'), 'nie ma konta 999', 'Unknown account rejected');
fails(fn() => $bank->saveLines($s1['id'], [$bl[0]['id'] => ['account' => '200-0002', 'match' => $bl[0]['match_entry_id']]], 'ola'), 'jest na koncie', 'Invoice on another account rejected');
$bank->saveLines($s1['id'], [$bl[2]['id'] => ['account' => '751 Koszty finansowe']], 'ola');
$wb = $bank->book($s1['id'], 'ola');
$we = $l->entries($wb['id']);
ok($wb['type'] === 'WB' && $wb['status'] === 'posted' && $wb['posting_date'] === '2027-04-03' && count($we) === 6 && $wb['settled'] === 2, 'Statement booked as one WB document');
ok($we[0]['account_number'] === '130-01' && (int) $we[0]['debit_gr'] === 123000 && $we[1]['account_number'] === '200-0003' && (int) $we[1]['credit_gr'] === 123000
    && $we[5]['account_number'] === '751' && (int) $we[5]['debit_gr'] === 500, 'Bank lines posted against the counter accounts');
ok($se->item($w9[0]['entry_id'])['remaining'] === 0 && $se->items(['doc' => $f7['id']])[0]['remaining'] === 0, 'Invoices settled from the statement');
ok($bank->statement($s1['id'])['status'] === 'booked' && $bank->lines($s1['id'])[2]['status'] === 'booked', 'Statement marked booked');
fails(fn() => $bank->delete($s1['id'], 'ola'), 'wystornuj', 'Booked statement cannot be deleted');
$stw = $l->reverse($wb['id'], '2027-04-30', 'ola', 'zły wyciąg');
ok($se->item($w9[0]['entry_id'])['remaining'] === 147600 && $se->items(['doc' => $f7['id']])[0]['remaining'] === 123000, 'Reversing the WB reopens the invoices');
$s2 = $bank->import('130-01', 'historia.csv', $csv, 'ola');
$b2 = $bank->lines($s2['id']);
ok($b2[0]['contractor_id'] === $both['id'] && $b2[0]['match_entry_id'] === null, 'CSV import: contractor known, no matching invoice');
$bank->saveLines($s2['id'], [$b2[1]['id'] => ['skip' => '1'], $b2[0]['id'] => ['account' => '200-0003']], 'ola');
ok($bank->lines($s2['id'])[1]['status'] === 'skip', 'Operation skipped');
$bank->delete($s2['id'], 'ola');
ok($bank->statement($s2['id']) === null && $bank->lines($s2['id']) === [], 'Open statement deleted');

// --- Import z innego programu: JPK_KR i JPK_V7
use PaseExt\Ksiegowosc\JpkImport;
ok(JpkImport::gr('1234.56') === 123456 && JpkImport::gr('-0.5') === -50 && JpkImport::gr('10') === 1000 && JpkImport::gr('1.005') === 101 && JpkImport::gr('') === 0, 'JPK amounts');
ok(JpkImport::docType('Faktura sprzedaży', 'FV/1') === 'FS' && JpkImport::docType('', 'FZ 7/2028') === 'FZ' && JpkImport::docType('Wyciąg bankowy', '1') === 'WB' && JpkImport::docType('', 'PK/3') === 'PK', 'Document type from source program');
ok(JpkImport::rateOf(10000, 2300) === '23' && JpkImport::rateOf(10000, 800) === '8' && JpkImport::rateOf(10000, 0) === '0', 'Purchase rate from VAT ratio');
$zo = static fn(string $n, string $name, string $type, string $bod, string $boc, string $od, string $oc, string $sd, string $sc): string =>
    "<tns:ZOiS><tns:KodKonta>$n</tns:KodKonta><tns:OpisKonta>$name</tns:OpisKonta><tns:TypKonta>$type</tns:TypKonta><tns:KodZespolu>1</tns:KodZespolu>"
    . "<tns:BilansOtwarciaWinien>$bod</tns:BilansOtwarciaWinien><tns:BilansOtwarciaMa>$boc</tns:BilansOtwarciaMa><tns:ObrotyWinien>$od</tns:ObrotyWinien><tns:ObrotyMa>$oc</tns:ObrotyMa>"
    . "<tns:ObrotyWinienNarast>$od</tns:ObrotyWinienNarast><tns:ObrotyMaNarast>$oc</tns:ObrotyMaNarast><tns:SaldoWinien>$sd</tns:SaldoWinien><tns:SaldoMa>$sc</tns:SaldoMa></tns:ZOiS>";
$dz = static fn(int $lp, string $nr, string $doc, string $kind, string $date, string $desc, string $amt): string =>
    "<tns:Dziennik><tns:LpZapisuDziennika>$lp</tns:LpZapisuDziennika><tns:NrZapisuDziennika>$nr</tns:NrZapisuDziennika><tns:OpisDziennika>Dziennik</tns:OpisDziennika>"
    . "<tns:NrDowoduKsiegowego>$doc</tns:NrDowoduKsiegowego><tns:RodzajDowodu>$kind</tns:RodzajDowodu><tns:DataOperacji>$date</tns:DataOperacji><tns:DataDowodu>$date</tns:DataDowodu>"
    . "<tns:DataKsiegowania>$date</tns:DataKsiegowania><tns:KodOperatora>ksiegowa</tns:KodOperatora><tns:OpisOperacji>$desc</tns:OpisOperacji><tns:DziennikKwotaOperacji>$amt</tns:DziennikKwotaOperacji></tns:Dziennik>";
$kz = static fn(int $lp, string $nr, string $wn, string $wa, string $ma, string $mamt): string =>
    "<tns:KontoZapis><tns:LpZapisu>$lp</tns:LpZapisu><tns:NrZapisu>$nr</tns:NrZapisu><tns:KodKontaWinien>$wn</tns:KodKontaWinien><tns:KwotaWinien>$wa</tns:KwotaWinien>"
    . "<tns:KodKontaMa>$ma</tns:KodKontaMa><tns:KwotaMa>$mamt</tns:KwotaMa></tns:KontoZapis>";
$kr = '<?xml version="1.0" encoding="UTF-8"?><tns:JPK xmlns:tns="http://jpk.mf.gov.pl/wzor/2016/03/09/03091/" xmlns:etd="http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2016/01/25/eD/DefinicjeTypy/">'
    . '<tns:Naglowek><tns:KodFormularza kodSystemowy="JPK_KR (1)" wersjaSchemy="1-0">JPK_KR</tns:KodFormularza><tns:CelZlozenia>1</tns:CelZlozenia><tns:DataWytworzeniaJPK>2029-01-15T10:00:00</tns:DataWytworzeniaJPK>'
    . '<tns:DataOd>2028-01-01</tns:DataOd><tns:DataDo>2028-12-31</tns:DataDo><tns:DomyslnyKodWaluty>PLN</tns:DomyslnyKodWaluty></tns:Naglowek>'
    . '<tns:Podmiot1><tns:IdentyfikatorPodmiotu><etd:NIP>9522282284</etd:NIP><etd:PelnaNazwa>Klub</etd:PelnaNazwa></tns:IdentyfikatorPodmiotu></tns:Podmiot1>'
    . $zo('130', 'Rachunek bankowy', 'Bilansowe', '10000.00', '0.00', '1230.00', '0.00', '11230.00', '0.00')
    . $zo('201', 'Rozrachunki z odbiorcami', 'Bilansowe', '0.00', '0.00', '1230.00', '1230.00', '0.00', '0.00')
    . $zo('201-1-1-0001', 'Hurt Sp. z o.o.', 'Bilansowe', '0.00', '0.00', '1230.00', '1230.00', '0.00', '0.00')
    . $zo('202-1-1-0001', 'Dostawca wosku', 'Bilansowe', '0.00', '0.00', '0.00', '615.00', '0.00', '615.00')
    . $zo('221', 'VAT naliczony', 'Bilansowe', '0.00', '0.00', '115.00', '0.00', '115.00', '0.00')
    . $zo('222', 'VAT należny', 'Bilansowe', '0.00', '0.00', '0.00', '230.00', '0.00', '230.00')
    . $zo('401', 'Zużycie materiałów', 'Wynikowe', '0.00', '0.00', '500.00', '0.00', '500.00', '0.00')
    . $zo('700', 'Przychody ze sprzedaży', 'Wynikowe', '0.00', '0.00', '0.00', '1000.00', '0.00', '1000.00')
    . $zo('800', 'Kapitał zakładowy', 'Bilansowe', '0.00', '10000.00', '0.00', '0.00', '0.00', '10000.00')
    . $dz(1, 'D1', 'FV/1/2028', 'Faktura sprzedaży', '2028-01-10', 'Sprzedaż świec', '1230.00')
    . $dz(2, 'D2', 'FZ 7/2028', 'Faktura zakupu', '2028-01-12', 'Wosk', '615.00')
    . $dz(3, 'D3', 'WB/1/2028', 'Wyciąg bankowy', '2028-01-20', 'Zapłata FV/1/2028', '1230.00')
    . '<tns:DziennikCtrl><tns:LiczbaWierszyDziennika>3</tns:LiczbaWierszyDziennika><tns:SumaKwotOperacji>3075.00</tns:SumaKwotOperacji></tns:DziennikCtrl>'
    . $kz(1, 'D1', '201-1-1-0001', '1230.00', '700', '1000.00') . $kz(2, 'D1', 'null', '0.00', '222', '230.00')
    . $kz(3, 'D2', '401', '500.00', '202-1-1-0001', '615.00') . $kz(4, 'D2', '221', '115.00', 'null', '0.00')
    . $kz(5, 'D3', '130', '1230.00', '201-1-1-0001', '1230.00')
    . '<tns:KontoZapisCtrl><tns:LiczbaWierszyKontoZapisj>5</tns:LiczbaWierszyKontoZapisj></tns:KontoZapisCtrl></tns:JPK>';
$pk = JpkImport::parseKr($kr);
ok($pk['from'] === '2028-01-01' && count($pk['zois']) === 9 && count($pk['journal']) === 3 && count($pk['journal'][0]['lines']) === 3 && $pk['ctrl']['rows'] === 3, 'JPK_KR parsed (namespaced, both sides per row)');
fails(fn() => JpkImport::parseKr('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><x>&e;</x>'), 'DOCTYPE', 'XML with DOCTYPE refused');
fails(fn() => JpkImport::parseKr('<JPK><Naglowek><KodFormularza>JPK_V7M</KodFormularza></Naglowek></JPK>'), 'potrzebny jest JPK_KR', 'Other JPK refused');
$ji = new JpkImport($db);
$r1 = $ji->importKr('jpk_kr_2028.xml', $kr, 'adam');
$y28 = $l->year($r1['year_id']);
ok($y28['starts_on'] === '2028-01-01' && $r1['docs'] === 4 && $r1['entries'] === 10 && $r1['unbalanced'] === [] && $r1['warnings'] === [], 'JPK_KR imported: BO + 3 documents');
ok($l->accountByNumber($y28['id'], '201-1-1') !== null && $l->accountByNumber($y28['id'], '201-1') !== null && $l->accountByNumber($y28['id'], '401')['kind'] === 'W', 'Missing parent accounts created, kinds from file');
$d28 = $l->docs($y28['id'])['rows'];
$byNo = array_column($d28, null, 'number');
ok($byNo['BO/2028']['type'] === 'BO' && (int) $byNo['BO/2028']['journal_no'] === 1 && $byNo['FV/1/2028']['type'] === 'FS' && $byNo['FZ 7/2028']['type'] === 'FZ' && (int) $byNo['WB/1/2028']['journal_no'] === 4, 'Documents typed and numbered in journal order');
$cmp = $ji->compare($y28['id']);
ok($cmp['diff'] === 0 && count($cmp['rows']) === 9, 'Books match the file after import');
$r2 = $ji->importKr('jpk_kr_2028.xml', $kr, 'adam');
ok($r2['year_id'] === $y28['id'] && count($l->docs($y28['id'])['rows']) === 4 && $ji->compare($y28['id'])['diff'] === 0, 'Re-import replaces the previous import');
$byNo = array_column($l->docs($y28['id'])['rows'], null, 'number'); // nowe dokumenty po ponownym imporcie
// JPK_V7: kontrahenci i VAT przypięte do dokumentów po numerze.
$v7 = '<?xml version="1.0" encoding="UTF-8"?><JPK xmlns="http://crd.gov.pl/wzor/2021/12/27/11148/"><Naglowek><KodFormularza kodSystemowy="JPK_V7M (2)">JPK_VAT</KodFormularza>'
    . '<WariantFormularza>2</WariantFormularza><Rok>2028</Rok><Miesiac>1</Miesiac></Naglowek><Ewidencja>'
    . '<SprzedazWiersz><LpSprzedazy>1</LpSprzedazy><NrKontrahenta>5260250274</NrKontrahenta><NazwaKontrahenta>Hurt Sp. z o.o.</NazwaKontrahenta><DowodSprzedazy>FV/1/2028</DowodSprzedazy>'
    . '<DataWystawienia>2028-01-10</DataWystawienia><K_19>1000.00</K_19><K_20>230.00</K_20></SprzedazWiersz>'
    . '<SprzedazWiersz><LpSprzedazy>2</LpSprzedazy><NrKontrahenta>brak</NrKontrahenta><NazwaKontrahenta>Sprzedaż detaliczna</NazwaKontrahenta><DowodSprzedazy>FV/99/2028</DowodSprzedazy>'
    . '<DataWystawienia>2028-01-11</DataWystawienia><K_19>10.00</K_19><K_20>2.30</K_20></SprzedazWiersz>'
    . '<ZakupWiersz><LpZakupu>1</LpZakupu><NrDostawcy>9522282284</NrDostawcy><NazwaDostawcy>Woskownia SA</NazwaDostawcy><DowodZakupu>FZ-7/2028</DowodZakupu><DataZakupu>2028-01-12</DataZakupu>'
    . '<K_42>500.00</K_42><K_43>115.00</K_43></ZakupWiersz></Ewidencja></JPK>';
$pv = JpkImport::parseV7($v7);
ok($pv['period'] === '2028-01' && count($pv['sales']) === 2 && $pv['sales'][0]['lines'][0]['rate'] === '23' && $pv['purchases'][0]['lines'][0]['rate'] === '23' && $pv['purchases'][0]['lines'][0]['vat_gr'] === 11500, 'JPK_V7 parsed');
$before = count($cs->list('', true));
$v = $ji->importV7('jpk_v7m_2028_01.xml', $v7, 'adam');
ok($v['matched'] === 2 && $v['contractors'] === 1 && count($v['unmatched']) === 1 && str_contains($v['unmatched'][0], 'FV/99/2028'), 'JPK_V7: two rows matched by number, one reported');
ok(count($cs->list('', true)) === $before + 1 && $l->accountByNumber($y28['id'], '200-' . $cs->list('Hurt')[0]['code']) === null, 'New contractor created without settlement accounts');
$fvDoc = $l->doc($byNo['FV/1/2028']['id']);
ok($fvDoc['contractor_id'] === $cs->list('Hurt')[0]['id'] && $l->doc($byNo['FZ 7/2028']['id'])['contractor_id'] === $wosk['id'], 'Documents linked to contractors (existing one by NIP)');
$rs = (new Vat($db))->register('S', '2028-01');
$rz = (new Vat($db))->register('Z', '2028-01');
ok($rs['vat'] === 23000 && $rz['deductible'] === 11500 && $l->warnings($fvDoc) === [], 'VAT registers filled; VAT matches account 222');
$ji->importV7('jpk_v7m_2028_01.xml', $v7, 'adam');
ok(count((new Vat($db))->forDoc($fvDoc['id'])) === 1, 'Re-import of the same month does not duplicate VAT');
// Ręczny dokument w roku z importu: porównanie pokazuje różnicę, ponowny import jest zablokowany.
$l->post($l->saveDraft(null, ['type' => 'PK', 'posting_date' => '2028-02-01', 'description' => 'Ręcznie', 'lines' => [$line('401', '50', ''), $line('130', '', '50')]], 'adam')['id'], 'adam');
$cmp = $ji->compare($y28['id']);
$bad = array_column(array_filter($cmp['rows'], static fn(array $r): bool => !$r['ok']), 'number');
ok($cmp['diff'] === 2 && in_array('130', array_map('strval', $bad), true) && in_array('401', array_map('strval', $bad), true), 'Comparison shows accounts that differ');
fails(fn() => $ji->importKr('jpk_kr_2028.xml', $kr, 'adam'), 'dokumenty wprowadzone w CRM', 'Import refused into a year with CRM documents');
fails(fn() => $ji->importKr('x.xml', str_replace(['2028-01-01', '2028-12-31'], ['2027-07-01', '2028-06-30'], $kr), 'adam'), 'nachodzi', 'Overlapping period refused');

// --- wFirma po API: faktury i wydatki przypięte do dokumentów z JPK_KR, porównanie zapłat
use PaseExt\Ksiegowosc\Wfirma;
$calls = [];
$wfFake = static function (string $method, string $url, array $headers, ?array $body) use (&$calls): array {
    $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
    $ok = ['code' => 'OK'];
    if (str_contains($url, '/companies/get/')) {
        return ['status' => 200, 'json' => ['status' => $ok, 'companies' => [['company' => ['name' => 'Klub Testowy']]]]];
    }
    if (str_contains($url, '/invoices/find')) {
        return ['status' => 200, 'json' => ['status' => $ok, 'invoices' => [
            '0' => ['invoice' => ['id' => '11', 'type' => 'normal', 'fullnumber' => 'FV/1/2028', 'date' => '2028-01-10', 'disposaldate' => '2028-01-10', 'paymentdate' => '2028-01-24',
                'netto' => '1000.00', 'tax' => '230.00', 'total' => '1230.00', 'alreadypaid' => '1230.00', 'remaining' => '0.00', 'currency' => 'PLN',
                'contractor_detail' => ['name' => 'Hurt Sp. z o.o.', 'nip' => '5260250274'],
                'vat_contents' => ['0' => ['vat_content' => ['netto' => '1000.00', 'tax' => '230.00', 'brutto' => '1230.00', 'vat_code' => ['id' => '222', 'code' => '23']]]]]],
            '1' => ['invoice' => ['id' => '12', 'type' => 'proforma', 'fullnumber' => 'PRO/1/2028', 'date' => '2028-01-11', 'total' => '50.00']],
            'parameters' => ['limit' => 100, 'page' => 1, 'total' => 2],
        ]]];
    }
    if (str_contains($url, '/expenses/find')) {
        return ['status' => 200, 'json' => ['status' => $ok, 'expenses' => [
            '0' => ['expense' => ['id' => '21', 'type' => 'invoice', 'fullnumber' => 'FZ-7/2028', 'date' => '2028-01-12', 'taxregister_date' => '2028-01-12', 'payment_date' => '2028-01-26',
                'netto' => '500.00', 'tax' => '115.00', 'brutto' => '615.00', 'alreadypaid' => '0.00', 'contractor' => ['name' => 'Woskownia SA', 'nip' => '9522282284'],
                'vat_contents' => ['vat_content' => ['netto' => '500.00', 'tax' => '115.00']]]],
            '1' => ['expense' => ['id' => '22', 'type' => 'invoice', 'fullnumber' => 'FZ/404/2028', 'date' => '2028-01-15', 'netto' => '100.00', 'tax' => '23.00',
                'paid' => '1', 'contractor' => ['name' => 'Nowy Dostawca', 'nip' => '']]],
        ]]];
    }
    return ['status' => 200, 'json' => ['status' => ['code' => 'ERROR', 'message' => 'Nieznany moduł']]];
};
$wfCfg = ['access_key' => 'ak', 'secret_key' => 'sk', 'app_key' => 'apk', 'company_id' => '123'];
fails(fn() => (new Wfirma($db, ['access_key' => 'ak'], $wfFake))->test(), 'Wpisz klucze API', 'wFirma without keys refused before any request');
ok($calls === [], 'No request without keys');
$wf = new Wfirma($db, $wfCfg, $wfFake);
ok($wf->test() === 'Połączenie z wFirma działa: Klub Testowy.' && $calls[0]['headers']['accessKey'] === 'ak' && $calls[0]['headers']['appKey'] === 'apk'
    && str_contains($calls[0]['url'], 'company_id=123') && str_contains($calls[0]['url'], 'outputFormat=json') && str_starts_with($calls[0]['url'], Wfirma::BASE_URL . '/'), 'wFirma connection test sends keys as headers');
$n = Wfirma::normalize('purchase', ['id' => '9', 'fullnumber' => 'X', 'date' => '2028-02-03', 'netto' => '100.00', 'tax' => '0.00', 'paid' => '1',
    'vat_contents' => [['vat_content' => ['netto' => '100.00', 'tax' => '0.00', 'vat_code' => ['code' => 'ZW']]]]]);
ok($n['gross_gr'] === 10000 && $n['paid_gr'] === 10000 && $n['lines'][0]['rate'] === 'zw' && $n['lines'][0]['register'] === 'Z' && $n['period'] === '2028-02', 'wFirma document normalized (gross from net+VAT, paid flag, ZW code)');
$calls = [];
$fvDue = $l->doc($byNo['FV/1/2028']['id'])['due_date'];
$sy = $wf->sync('2028-01-01', '2028-01-31', 'adam');
$find = array_values(array_filter($calls, static fn(array $c): bool => str_contains($c['url'], '/find')));
ok($sy['sales'] === 1 && $sy['skipped'] === 1 && $sy['purchases'] === 2 && $sy['matched'] === 2 && count($sy['unmatched']) === 1 && str_contains($sy['unmatched'][0], 'FZ/404/2028'),
    'wFirma sync: proforma skipped, two documents matched by number, one reported');
ok($find[0]['method'] === 'POST' && $find[0]['body']['invoices']['parameters']['conditions']->{'0'}['condition']['value'] === '2028-01-01', 'wFirma find filters by date');
ok($fvDue === null && $l->doc($byNo['FV/1/2028']['id'])['due_date'] === '2028-01-24' && $l->doc($byNo['FZ 7/2028']['id'])['due_date'] === '2028-01-26', 'Due dates filled from wFirma');
ok(count((new Vat($db))->forDoc($byNo['FV/1/2028']['id'])) === 1, 'VAT from JPK_V7 kept (not duplicated by wFirma)');
$wf->sync('2028-01-01', '2028-01-31', 'adam');
ok((int) $db->one('SELECT COUNT(*) AS n FROM ext_ksg_wf_docs')['n'] === 3, 'Repeated sync updates stored documents');
$fs2 = $l->post($l->saveDraft(null, ['type' => 'FS', 'number' => 'FV/2/2028', 'posting_date' => '2028-01-20', 'description' => 'Bez VAT w księgach',
    'lines' => [$line('201-1-1-0001', '123', ''), $line('700', '', '100'), $line('222', '', '23')]], 'adam')['id'], 'adam');
$wf->store([Wfirma::normalize('sale', ['id' => '13', 'type' => 'normal', 'fullnumber' => 'FV/2/2028', 'date' => '2028-01-20', 'netto' => '100.00', 'tax' => '23.00', 'total' => '123.00',
    'contractor_detail' => ['name' => 'Hurt Sp. z o.o.', 'nip' => '5260250274']])], 'adam');
$v2 = (new Vat($db))->forDoc($fs2['id']);
ok(count($v2) === 1 && (int) $v2[0]['vat_gr'] === 2300 && $v2[0]['register'] === 'S' && $v2[0]['period'] === '2028-01' && $l->doc($fs2['id'])['contractor_id'] === $cs->list('Hurt')[0]['id'],
    'Document without VAT gets VAT lines and contractor from wFirma');
$st28 = new Settlements($db);
ok(Settlements::parentOf('201-1-1-0001') === '200' && count($st28->items(['parent' => '200', 'doc' => $byNo['FV/1/2028']['id']])) === 1, 'Account 201 (wFirma plan) counts as receivables');
$cw = array_column($wf->compare('2028-01-01', '2028-01-31'), null, 'number');
ok(!$cw['FV/1/2028']['ok'] && $cw['FV/1/2028']['wf_left'] === 0 && $cw['FV/1/2028']['ours_left'] === 123000 && $cw['FZ-7/2028']['ok'] && $cw['FZ/404/2028']['ours_left'] === null,
    'Payment comparison: paid in wFirma, open in CRM; purchase matches; missing document flagged');
$st28->autoMatch('adam');
$cw = array_column($wf->compare('2028-01-01', '2028-01-31'), null, 'number');
ok($cw['FV/1/2028']['ok'] && $cw['FV/1/2028']['ours_left'] === 0, 'After automatic matching the invoice agrees with wFirma');
// Automatyczne pobieranie: interwał z ustawień, błąd zapisany do pokazania.
$db->setMeta('wf_last_try', '');
$calls = [];
$wf->autoSync(strtotime('2028-02-10 12:00:00'), '60');
ok(count($calls) === 2 && $calls[0]['body']['invoices']['parameters']['conditions']->{'0'}['condition']['value'] === '2028-01-01', 'Auto sync from the start of the previous month');
$wf->autoSync(strtotime('2028-02-10 12:30:00'), '60');
ok(count($calls) === 2, 'Auto sync waits for the interval');
$wf->autoSync(strtotime('2028-02-10 13:01:00'), '0');
ok(count($calls) === 2, 'Auto sync off');
$bad = new Wfirma($db, $wfCfg, static fn(): array => ['status' => 401, 'json' => []]);
$bad->autoSync(strtotime('2028-02-10 14:00:00'), '60');
ok($bad->lastResult()['ok'] === false && str_contains($bad->lastResult()['error'], 'odrzuciła klucze'), 'Auto sync error saved for the page');

// --- Haki rozszerzenia: strony, menu, automation.event
use Pase\Plugin\Hooks;
Hooks::bootAs('ksiegowosc', static fn() => (new \PaseExt\Ksiegowosc\KsiegowoscExtension())->boot());
ok(Hooks::page('ksiegowosc.pulpit') !== null && Hooks::page('ksiegowosc.dokument_zapisz')['menu'] === false && Hooks::page('ksiegowosc.obroty_csv')['raw'] === true, 'Pages registered');
$menu = ['orders' => ['label' => 'Zamówienia', 'items' => [['href' => 'orders.php'], ['href' => 'ext.php?page=ksiegowosc.pulpit'], ['href' => 'ext.php?page=ksiegowosc.obroty']]],
    'config' => ['label' => 'Konfiguracja', 'items' => [['href' => 'settings.php']]]];
$asAdmin = Hooks::applyFilters('admin.menu', $menu, true, true);
ok(array_keys($asAdmin) === ['orders', 'ksiegowosc', 'config'] && count($asAdmin['ksiegowosc']['items']) === 2 && count($asAdmin['orders']['items']) === 1, 'Own menu group before Konfiguracja (admin)');
$asOther = Hooks::applyFilters('admin.menu', $menu, true, false);
ok(!isset($asOther['ksiegowosc']) && count($asOther['orders']['items']) === 1, 'Menu group hidden without access');
$before = $inbox->list()['total'];
Hooks::doAction('automation.event', 'order.imported', 77, ['number' => '77']);
ok($inbox->list()['total'] === $before, 'Hook ignores events while the inbox is off');
$db->setMeta('inbox', '1');
Hooks::doAction('automation.event', 'order.imported', 77, ['number' => '77']);
Hooks::doAction('automation.event', 'shipment.created', 77, []);
ok($inbox->list()['total'] === $before + 1 && $api->orderEvents(77) === 1, 'Hook records order events once the inbox is on');

// --- Osobna baza (EXT_KSIEGOWOSC_DB_DSN, połączenie z rdzenia)
$file = sys_get_temp_dir() . '/ksg-separate-' . bin2hex(random_bytes(4)) . '.sqlite';
$envVars = new ReflectionProperty(\Pase\Support\Env::class, 'vars');
$envVars->setValue(null, ['EXT_KSIEGOWOSC_DB_DSN' => 'sqlite:' . $file]);
Db::resetForTests();
$crm2 = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sep = Db::connect($crm2);
ok(Db::separate() && $sep->pdo !== $crm2 && $tablesOf($crm2) === [] && count($tablesOf($sep->pdo)) === count(Db::tables()), 'EXT_KSIEGOWOSC_DB_DSN puts every table in a separate database');
$envVars->setValue(null, []);
@unlink($file);

echo "\nAll {$checks} checks passed.\n";
