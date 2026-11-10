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
