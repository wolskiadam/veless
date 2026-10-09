<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Rozmowy Allegro w CRM: zapis z Allegro (page.id), stronicowanie, wyszukiwarka, archiwum
// ręczne i razem z zamówieniem, powrót z archiwum po nowej wiadomości (atrapa klienta, bez sieci).
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\AllegroInbox as I;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY, order_number TEXT, pase_number INTEGER, lifecycle TEXT NOT NULL DEFAULT 'active')");
$pdo->exec('CREATE TABLE settings (setting_key VARCHAR(64) PRIMARY KEY, setting_value TEXT NULL)');
$pdo->exec("INSERT INTO woo_orders VALUES (9000000001, 'cf-1', 517, 'active'), (9000000002, 'cf-2', 518, 'active')");
I::migrate($pdo);
I::migrate($pdo);
$inbox = new I($pdo);

// Atrapa: 45 rozmów na 3 stronach (20/20/5), najnowsza pierwsza.
$fake = new class {
    public array $threads = [];
    public array $threadCalls = [];
    public array $messageCalls = [];
    public array $messages = [];
    public function messageThreads(string $pageId = '', array $filters = []): array {
        $this->threadCalls[] = $pageId;
        $start = $pageId === '' ? 0 : (int) $pageId;
        $slice = array_slice($this->threads, $start, 20);
        $next = $start + 20 < count($this->threads) ? (string) ($start + 20) : '';
        return ['ok' => true, 'threads' => $slice, 'next' => $next, 'message' => ''];
    }
    public function threadMessages(string $id, int $limit = 100): array {
        $this->messageCalls[] = $id;
        return $this->messages[$id] ?? [['id' => "m-$id", 'createdAt' => '2026-10-01T10:00:00Z', 'text' => "Pytanie o świecę $id", 'author' => ['role' => 'BUYER', 'login' => "kupujacy$id"]]];
    }
    public function accountLogin(): string { return 'sklep'; }
};
for ($i = 1; $i <= 45; $i++) {
    $fake->threads[] = ['id' => "t$i", 'read' => $i > 2, 'lastMessageDateTime' => gmdate('Y-m-d\TH:i:s\Z', time() - 2 * 86400 - $i * 3600 * 15),
        'participants' => [['role' => 'BUYER', 'login' => "kupujacy$i"], ['role' => 'SELLER', 'login' => 'sklep']]];
}
$fake->threads[0]['orders'] = [['id' => 'cf-1']];
$fake->messages['t2'] = [
    ['createdAt' => '2026-10-29T09:00:00Z', 'text' => 'Wysłaliśmy paczkę', 'author' => ['role' => 'SELLER', 'login' => 'sklep']],
    ['createdAt' => '2026-10-29T08:00:00Z', 'text' => 'Gdzie jest moja lawenda?', 'author' => ['role' => 'BUYER', 'login' => 'kupujacy2'], 'relatesTo' => ['order' => ['id' => 'cf-2']]],
];

// Na stronie Wiadomości: tylko pierwsza strona, bez pełnego pobrania.
$r = $inbox->sync($fake, 1, false);
check($r['ok'] && $r['changed'] === 20 && $fake->threadCalls === [''], 'page visit fetches only the newest page');
// Cron: pierwsze pełne pobranie idzie do końca mimo braku zmian na pierwszej stronie.
$fake->threadCalls = []; $fake->messageCalls = [];
$r = $inbox->sync($fake);
check($r['changed'] === 25 && $fake->threadCalls === ['', '20', '40'] && count($fake->messageCalls) === 25, 'first cron sync walks all pages, reads messages only of new threads');
$fake->threadCalls = []; $fake->messageCalls = [];
$r = $inbox->sync($fake);
check($r['changed'] === 0 && $fake->threadCalls === [''] && $fake->messageCalls === [], 'later syncs stop at the first page without changes');

$p1 = $inbox->page();
check($p1['total'] === 45 && $p1['pages'] === 2 && count($p1['rows']) === 30 && $p1['rows'][0]['remote_id'] === 't1', 'list is paged, newest first');
check(count($inbox->page('', '', 2)['rows']) === 15 && $inbox->page('', '', 99)['page'] === 2, 'second page and page overflow');
check($inbox->counts() === ['unread' => 2, 'archive' => 0] && count($inbox->page('unread')['rows']) === 2, 'unread filter');
$t1 = $inbox->thread('t1');
$t2 = $inbox->thread('t2');
check((int) $t1['woo_order_id'] === 9000000001 && I::orderLabel($t1) === '#517' && $t1['buyer_login'] === 'kupujacy1', 'thread linked to its order from the thread');
check((int) $t2['woo_order_id'] === 9000000002 && $t2['last_role'] === 'SELLER' && $t2['last_text'] === 'Wysłaliśmy paczkę', 'order from a message, last message is ours');

// Wyszukiwarka: login, treść, numer zamówienia CRM.
check($inbox->page('', 'KUPUJACY4')['total'] === 7, 'search by login (case-insensitive, partial)');
check(array_column($inbox->page('', 'lawend')['rows'], 'remote_id') === ['t2'], 'search in message text');
check(array_column($inbox->page('', '#518')['rows'], 'remote_id') === ['t2'] && array_column($inbox->page('', '517')['rows'], 'remote_id') === ['t1'], 'search by CRM order number');
check($inbox->page('', '100%_')['total'] === 0, 'LIKE wildcards are escaped');

// Archiwum ręczne i przywracanie.
check($inbox->archive('t3') && $inbox->page()['total'] === 44 && array_column($inbox->page('archive')['rows'], 'remote_id') === ['t3'], 'manual archive moves the thread to Archiwum');
check(in_array('t3', array_column($inbox->page('', 'kupujacy3')['rows'], 'remote_id'), true), 'default search also finds archived threads');
check($inbox->restore('t3') && $inbox->page()['total'] === 45, 'restore brings it back');

// Archiwum razem z zamówieniem; nieprzeczytane czekają.
$pdo->exec("UPDATE woo_orders SET lifecycle = 'archived'");
$n = $inbox->autoArchive(3650);
check($n === 0 && (int) $inbox->thread('t1')['archived'] === 0 && (int) $inbox->thread('t2')['archived'] === 0, 'unread thread is not archived with its order');
$pdo->exec("UPDATE allegro_threads SET is_read = 1");
$inbox->autoArchive(3650);
check((int) $inbox->thread('t1')['archived'] === 1 && $inbox->thread('t1')['archived_by'] === 'auto', 'thread goes to archive with its order once read');
check((int) $inbox->thread('t3')['archived'] === 0, 'manually restored thread stays out of the automatic archive');
// Bez zamówienia: po X dniach ciszy.
$old = $inbox->autoArchive(1);
check($old >= 40 && (int) $inbox->thread('t5')['archived'] === 1 && (int) $inbox->thread('t3')['archived'] === 0, 'threads without order archived after the quiet period');

// Nowa wiadomość od kupującego wyciąga rozmowę z archiwum (i nie wraca z zamówieniem).
$fake->threads[0]['lastMessageDateTime'] = gmdate('Y-m-d\TH:i:s\Z');
$fake->threads[0]['read'] = false;
$fake->messages['t1'] = [['createdAt' => gmdate('Y-m-d\TH:i:s\Z'), 'text' => 'Jeszcze jedno pytanie', 'author' => ['role' => 'BUYER', 'login' => 'kupujacy1']]];
$inbox->sync($fake);
$t1 = $inbox->thread('t1');
check((int) $t1['archived'] === 0 && (int) $t1['is_read'] === 0 && $t1['last_text'] === 'Jeszcze jedno pytanie', 'new buyer message brings the thread back from the archive');
$pdo->exec("UPDATE allegro_threads SET is_read = 1 WHERE remote_id = 't1'");
$inbox->autoArchive(3650);
check((int) $inbox->thread('t1')['archived'] === 0, 'reopened thread does not go back with its archived order');
check(str_contains((string) $inbox->thread('t1')['search_text'], 'Pytanie o świecę t1') && str_contains((string) $inbox->thread('t1')['search_text'], 'Jeszcze jedno'), 'search text keeps older messages');

// Otwarcie rozmowy: przeczytana.
$pdo->exec("UPDATE allegro_threads SET is_read = 0 WHERE remote_id = 't4'");
$inbox->opened(['id' => 't4', 'read' => false, 'lastMessageDateTime' => '2026-10-29T10:00:00Z'], [['createdAt' => '2026-10-29T10:00:00Z', 'text' => 'Dziękuję', 'author' => ['role' => 'BUYER', 'login' => 'kupujacy4']]], 'sklep');
check((int) $inbox->thread('t4')['is_read'] === 1 && $inbox->thread('t4')['last_text'] === 'Dziękuję', 'opened thread is read and refreshed');

echo "\n$checks checks passed\n";
