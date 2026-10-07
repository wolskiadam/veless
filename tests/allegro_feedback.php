<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Dyskusje, reklamacje i oceny Allegro w Wiadomościach: zapis z API, powiązanie z zamówieniem,
// liczniki, synchronizacja i odpowiedzi do kupującego na atrapie klienta Allegro (bez sieci).
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Services\AllegroFeedback as F;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE woo_orders (woo_order_id INTEGER PRIMARY KEY, order_number TEXT)');
$pdo->exec("INSERT INTO woo_orders VALUES (9000000123, 'cf-1'), (501, 'cf-2')"); // 501 = zamówienie ze sklepu, nie Allegro
Pase\Services\AuditTrail::migrate($pdo);
F::migrate($pdo);
F::migrate($pdo);
$fb = new F($pdo);

// --- Dyskusja z GET /sale/issues ---
$dispute = [
    'id' => 'iss-1', 'type' => 'DISPUTE', 'subject' => 'Nie otrzymałem przedmiotu', 'openedDate' => '2026-09-20T10:00:00.000Z',
    'checkoutForm' => ['id' => 'cf-1', 'createdAt' => '2026-09-18T08:00:00.000Z'],
    'buyer' => ['id' => '77', 'login' => 'anna_k'], 'offer' => ['id' => '111', 'quantity' => 1],
    'currentState' => ['status' => 'DISPUTE_ONGOING', 'statusDueDate' => '2026-09-27T10:00:00.000Z', 'chatActive' => true],
    'chat' => ['messagesCount' => 1, 'lastMessage' => ['text' => 'Gdzie paczka?', 'author' => ['login' => 'anna_k', 'role' => 'BUYER'], 'createdAt' => '2026-09-20T10:00:00.000Z']],
];
$id = $fb->upsertIssue($dispute);
$is = $fb->issue('iss-1');
check($is !== null && (int) $is['woo_order_id'] === 9000000123 && $is['buyer_login'] === 'anna_k' && $is['offer_id'] === '111', 'dispute saved and linked to the Allegro order');
check($is['status'] === 'DISPUTE_ONGOING' && $is['status_due_at'] === '2026-09-27 10:00:00' && $is['last_message_role'] === 'BUYER', 'status, deadline and last message kept');
check(F::isOpen($is) && F::awaitsSeller($is), 'open dispute where buyer wrote last awaits the seller');
$dispute['currentState']['status'] = 'DISPUTE_CLOSED';
check($fb->upsertIssue($dispute) === $id && !F::isOpen($fb->issue('iss-1')), 'next sync updates status without a duplicate');
$dispute['currentState']['status'] = 'DISPUTE_ONGOING';
$fb->upsertIssue($dispute);

// Reklamacja, zamówienie spoza CRM, a potem dochodzi do CRM.
$fb->upsertIssue(['id' => 'iss-2', 'type' => 'CLAIM', 'referenceNumber' => 'R-1', 'checkoutForm' => ['id' => 'cf-9'],
    'reason' => ['type' => 'DEFECT', 'description' => 'Pęknięte szkło'], 'decisionDueDate' => '2026-10-04T00:00:00Z',
    'currentState' => ['status' => 'CLAIM_SUBMITTED', 'chatActive' => true],
    'chat' => ['lastMessage' => ['author' => ['role' => 'SELLER'], 'createdAt' => '2026-09-21T09:00:00Z']]]);
$claim = $fb->issue('iss-2');
check($claim['woo_order_id'] === null && $claim['subject'] === 'Pęknięte szkło' && $claim['decision_due_at'] === '2026-10-04 00:00:00', 'claim outside CRM kept with reason and decision deadline');
check(F::isOpen($claim) && !F::awaitsSeller($claim), 'claim where seller wrote last is open but not waiting');
check($fb->upsertIssue(['id' => '']) === null && $fb->upsertIssue(['type' => 'DISPUTE']) === null, 'issue without id ignored');
$fb->upsertIssue(['id' => 'iss-3', 'type' => 'DISPUTE', 'currentState' => ['status' => 'DISPUTE_CLOSED'], 'openedDate' => '2026-09-25T00:00:00Z']);
$list = $fb->issues();
check(count($list) === 3 && $list[2]['remote_id'] === 'iss-3', 'open issues listed before closed ones');
check(count($fb->issues('open')) === 2 && count($fb->issues('closed')) === 1, 'issue filters');

// --- Oceny: public.v1 (comment tekstem) i beta.v1 (comment/answer obiektami) ---
$fb->upsertRating(['id' => 'rt-1', 'createdAt' => '2026-09-22T12:00:00Z', 'lastChangedAt' => '2026-09-22T12:00:00Z', 'recommended' => false,
    'buyer' => ['login' => 'anna_k'], 'comment' => ['text' => 'Paczka szła tydzień', 'language' => 'pl-PL'], 'order' => ['id' => 'cf-1'],
    'removal' => ['possibleTo' => '2026-10-02T12:00:00Z'], 'exclusion' => null]);
$fb->upsertRating(['id' => 'rt-2', 'createdAt' => '2026-09-23T12:00:00Z', 'lastChangedAt' => '2026-09-24T12:00:00Z', 'recommended' => true,
    'buyer' => ['login' => 'jan'], 'comment' => 'Super świece', 'answer' => ['message' => 'Dziękujemy!', 'createdAt' => '2026-09-24T12:00:00Z'],
    'excludedFromAverageRates' => true, 'order' => ['id' => 'cf-2']]);
$r1 = $fb->rating('rt-1');
$r2 = $fb->rating('rt-2');
check((int) $r1['recommended'] === 0 && $r1['comment'] === 'Paczka szła tydzień' && (int) $r1['woo_order_id'] === 9000000123 && $r1['answer_text'] === null, 'beta rating: comment object, linked to order');
check($r1['removal_possible_to'] === '2026-10-02 12:00:00' && (int) $r1['excluded'] === 0, 'removal deadline kept');
check((int) $r2['recommended'] === 1 && $r2['comment'] === 'Super świece' && $r2['answer_text'] === 'Dziękujemy!' && (int) $r2['excluded'] === 1, 'v1 rating: text comment, answer.message, excluded');
check($r2['woo_order_id'] === null, 'rating is linked only to Allegro orders (not a shop order with the same number)');
check(count($fb->ratings('unanswered')) === 1 && count($fb->ratings('negative')) === 1 && count($fb->ratings('positive')) === 1 && count($fb->ratings()) === 2, 'rating filters');
check($fb->counts() === ['open' => 2, 'awaiting' => 1, 'negative_unanswered' => 1], 'tab counters');
$forOrder = $fb->forOrder(9000000123);
check(count($forOrder['issues']) === 1 && count($forOrder['ratings']) === 1, 'order card sees its dispute and rating');

// --- Synchronizacja: stronicowanie, kursor ocen, dołączanie zamówień, błąd jednej części ---
$pdo->exec("INSERT INTO woo_orders VALUES (9000000999, 'cf-9')");
$fake = new class {
    public array $issueCalls = [];
    public array $ratingCalls = [];
    public array $sent = [];
    public bool $failRatings = false;
    public int $sendStatus = 201;
    public function issues(array $q): array {
        $this->issueCalls[] = $q;
        $items = [];
        if ($q['offset'] === 0) { for ($i = 0; $i < 100; $i++) { $items[] = ['id' => 'bulk-' . $i, 'type' => 'DISPUTE', 'currentState' => ['status' => 'DISPUTE_CLOSED']]; } }
        else { $items[] = ['id' => 'iss-4', 'type' => 'DISPUTE', 'checkoutForm' => ['id' => 'cf-1'], 'currentState' => ['status' => 'DISPUTE_UNRESOLVED']]; }
        return ['ok' => true, 'items' => $items, 'message' => ''];
    }
    public function userRatings(array $q): array {
        $this->ratingCalls[] = $q;
        if ($this->failRatings) { return ['ok' => false, 'items' => [], 'message' => 'Brak uprawnień']; }
        return ['ok' => true, 'items' => [['id' => 'rt-3', 'recommended' => false, 'createdAt' => '2026-09-25T10:00:00Z', 'lastChangedAt' => '2026-09-25T10:00:00Z', 'comment' => ['text' => 'Złamany knot']]], 'message' => ''];
    }
    public function sendIssueMessage(string $id, string $text, string $type): array {
        $this->sent[] = [$id, $text, $type];
        return ($this->sendStatus >= 200 && $this->sendStatus < 300) ? ['ok' => true, 'status' => $this->sendStatus, 'message' => ''] : ['ok' => false, 'status' => $this->sendStatus, 'message' => 'Błąd'];
    }
    public function issue(string $id): ?array { return null; }
    public function answerUserRating(string $id, string $text): array { $this->sent[] = [$id, $text, 'ANSWER']; return ['ok' => true, 'status' => 200, 'message' => '']; }
    public function userRating(string $id): ?array { return null; }
    // Problemy z zakupem: Centrum wiadomości beta.v1, stronicowanie page.id
    public array $threadCalls = [];
    public array $messageCalls = [];
    public function messageThreads(string $pageId = '', array $filters = []): array {
        $this->threadCalls[] = [$pageId, $filters];
        if ($pageId === '') {
            return ['ok' => true, 'next' => 'p2', 'message' => '', 'threads' => [[
                'id' => 'thr-1', 'type' => 'POST_PURCHASE_ISSUE', 'subType' => 'PRODUCT_INCONSISTENT_WITH_THE_OFFER', 'status' => 'OPEN',
                'createdAt' => '2026-10-28T09:00:00Z', 'lastMessageDateTime' => '2026-10-28T10:00:00Z',
                'participants' => [['role' => 'BUYER', 'login' => 'ola'], ['role' => 'SELLER', 'login' => 'sklep']],
                'orders' => [['id' => 'cf-1', 'offers' => [['id' => '222', 'quantity' => 1]]]]]]];
        }
        return ['ok' => true, 'next' => '', 'message' => '', 'threads' => [
            ['id' => 'thr-2', 'type' => 'POST_PURCHASE_ISSUE', 'subType' => 'SOMETHING_NEW', 'status' => 'CLOSED', 'participants' => [['role' => 'BUYER', 'login' => 'piotr']]],
            ['id' => 'thr-x', 'type' => 'COMMON', 'status' => 'OPEN'],
        ]];
    }
    public function threadMessages(string $id, int $limit = 100): array {
        $this->messageCalls[] = [$id, $limit];
        return [['id' => 'm2', 'createdAt' => '2026-10-28T10:00:00Z', 'text' => 'Wymienimy.', 'author' => ['role' => 'SELLER', 'login' => 'sklep']]];
    }
    public function accountLogin(): string { return 'sklep'; }
    public function sendMessage(string $id, string $text): array { $this->sent[] = [$id, $text, 'THREAD']; return ['ok' => true, 'status' => 201, 'message' => '']; }
};
$r = $fb->sync($fake);
check($r['ok'] && $r['issues'] === 103 && $r['ratings'] === 1 && count($fake->issueCalls) === 2 && $fake->issueCalls[1]['offset'] === 100, 'sync pages issues and saves ratings');
check($fake->ratingCalls[0]['lastChangedAt.gte'] === '2026-09-23T12:00:00.000Z', 'ratings fetched from the last change minus one day');
check((int) $fb->issue('iss-2')['woo_order_id'] === 9000000999, 'issue is linked once its order reaches the CRM');
$p1 = $fb->issue('thr-1');
$p2 = $fb->issue('thr-2');
check($fake->threadCalls[0] === ['', ['type' => 'POST_PURCHASE_ISSUE']] && $fake->threadCalls[1][0] === 'p2', 'purchase problems fetched from /messaging by type, paged with page.id');
check($p1['type'] === F::TYPE_PROBLEM && $p1['status'] === 'PROBLEM_OPEN' && $p1['buyer_login'] === 'ola' && (int) $p1['woo_order_id'] === 9000000123
    && $p1['offer_id'] === '222' && $p1['subject'] === 'Produkt niezgodny z ofertą' && $p1['opened_at'] === '2026-10-28 09:00:00', 'open purchase problem saved and linked to the order');
check($p1['last_message_role'] === 'SELLER' && !F::awaitsSeller($p1) && F::isOpen($p1), 'last message author read for an open problem');
check($p2['status'] === 'PROBLEM_CLOSED' && $p2['subject'] === null && !F::isOpen($p2) && $fb->issue('thr-x') === null, 'closed problem kept, unknown subtype has no label, common thread ignored');
check($fake->messageCalls === [['thr-1', 1]], 'only the changed open problem asks for its newest message');
check(F::typeLabel(F::TYPE_PROBLEM) === 'Problem z zakupem' && F::typeLabel('CLAIM') === 'Reklamacja' && F::typeLabel('DISPUTE') === 'Dyskusja', 'type labels');
$fake->failRatings = true;
$r = $fb->sync($fake);
check(!$r['ok'] && $r['issues'] === 103 && $r['messages'] === ['Oceny: Brak uprawnień'], 'ratings error reported, issues still synced');

// --- Odpowiedzi: tylko na wywołanie, błędy nie zmieniają stanu ---
check($fb->replyToIssue($fake, 'iss-1', '   ')['ok'] === false && $fake->sent === [], 'empty reply is not sent');
check($fb->replyToIssue($fake, 'nope', 'Hej')['ok'] === false && $fake->sent === [], 'unknown issue is not sent');
$res = $fb->replyToIssue($fake, 'iss-1', 'Paczka wyszła wczoraj.', 'END_REQUEST');
check($res['ok'] && $fake->sent[0] === ['iss-1', 'Paczka wyszła wczoraj.', 'END_REQUEST'], 'reply sent with chosen type');
check($fb->issue('iss-1')['last_message_role'] === 'SELLER' && !F::awaitsSeller($fb->issue('iss-1')), 'after reply the dispute no longer waits for the seller');
$fb->replyToIssue($fake, 'iss-1', 'x', 'HACK');
check($fake->sent[1][2] === 'REGULAR', 'unknown message type falls back to REGULAR');
$res = $fb->replyToIssue($fake, 'thr-1', 'Prosimy o zdjęcie.', 'END_REQUEST');
check($res['ok'] && end($fake->sent) === ['thr-1', 'Prosimy o zdjęcie.', 'THREAD'] && $fb->issue('thr-1')['last_message_text'] === 'Prosimy o zdjęcie.', 'purchase problem reply goes to the /messaging thread');
$fake->sendStatus = 0;
$res = $fb->replyToIssue($fake, 'iss-1', 'y');
check(!$res['ok'] && str_contains($res['message'], 'nie wiadomo'), 'no response from Allegro warns before resending');
$res = $fb->answerRating($fake, 'rt-1', 'Przepraszamy za opóźnienie.');
check($res['ok'] && $fb->rating('rt-1')['answer_text'] === 'Przepraszamy za opóźnienie.' && $fb->counts()['negative_unanswered'] === 1, 'rating answered, leaves the unanswered list');
$audit = (int) $pdo->query("SELECT COUNT(*) FROM audit_events WHERE action IN ('allegro.issue_reply', 'allegro.rating_answer')")->fetchColumn();
check($audit === 4, 'replies recorded in the order history');

// --- Dzwoneczek i ikony przy tytule: sprawy czekające na odpowiedź, negatywne oceny, zwroty ---
$pdo->exec('CREATE TABLE settings (setting_key VARCHAR(64) PRIMARY KEY, setting_value TEXT NULL)');
Pase\Services\OrderReturns::migrate($pdo);
(new Pase\Services\OrderReturns($pdo))->upsertAllegro(['id' => 'ret-1', 'referenceNumber' => 'ZW-1', 'orderId' => 'cf-1', 'status' => 'DELIVERED', 'buyer' => ['login' => 'anna_k'], 'items' => []]);
$notif = new Pase\Services\Notifications($pdo);
// pozostałe źródła wyłączone - potrzebują tabel spoza testu
$notif->savePrefs(1, ['allegro_issues' => '1', 'allegro_ratings' => '1', 'returns' => '1']);
$bell = $notif->collect(1, static fn() => null);
$groups = array_column($bell['items'], null, 'key');
check($bell['total'] === 3 && $groups['allegro_issues']['count'] === 1 && $groups['allegro_ratings']['count'] === 1 && $groups['returns']['count'] === 1,
    'bell: dispute waiting for the seller, unanswered negative rating, open return');
check(str_contains($groups['allegro_issues']['details'][0]['link'], 'tab=issues&issue=iss-4') && $groups['allegro_issues']['short'] === 'Dyskusje'
    && str_contains($groups['allegro_ratings']['details'][0]['text'], 'Złamany knot') && str_contains($groups['returns']['details'][0]['text'], 'ZW-1'),
    'bell rows and chip labels point to the dispute, rating and return');
$fb->answerRating($fake, 'rt-3', 'Wymienimy świecę.');
check(!isset(array_column($notif->collect(1, static fn() => null)['items'], null, 'key')['allegro_ratings']), 'answered rating leaves the bell');
$notif->savePrefs(1, []);
check($notif->collect(1, static fn() => null)['total'] === 0, 'groups can be switched off');

echo "\n$checks checks passed\n";
