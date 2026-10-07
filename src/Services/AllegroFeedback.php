<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;

/**
 * Dyskusje (i reklamacje) oraz oceny sprzedaży z Allegro, pokazywane w Wiadomościach.
 *
 * Cron (Scheduler) co SYNC_EVERY_MIN minut tylko pobiera i zapisuje dane:
 *   - GET /sale/issues          → tabela allegro_issues (dyskusje DISPUTE i reklamacje CLAIM),
 *   - GET /messaging/threads?type=POST_PURCHASE_ISSUE (beta.v1) → ta sama tabela, typ PROBLEM
 *     („Problemy z zakupem" — od 28.10.2026 zastępują nowe dyskusje i są tylko w Centrum wiadomości),
 *   - GET /sale/user-ratings    → tabela allegro_ratings (od ostatniej zmiany, pierwszy raz RATINGS_DAYS_BACK dni).
 * Każda wiadomość do kupującego (odpowiedź w dyskusji, odpowiedź na ocenę) idzie wyłącznie
 * po kliknięciu operatora w Wiadomościach — tutaj nic nie jest wysyłane automatycznie.
 */
final class AllegroFeedback
{
    public const SYNC_AT_KEY       = 'ALLEGRO_FEEDBACK_AT';
    public const SYNC_ERROR_KEY    = 'ALLEGRO_FEEDBACK_ERROR';
    public const SYNC_EVERY_MIN    = 30;
    public const RATINGS_DAYS_BACK = 180;
    private const ISSUE_PAGES      = 5;   // do 500 najnowszych dyskusji i reklamacji
    private const RATING_PAGES     = 20;
    private const PROBLEM_PAGES    = 10;

    /** Problem z zakupem z Centrum wiadomości (remote_id = id wątku). */
    public const TYPE_PROBLEM = 'PROBLEM';

    /** Statusy, w których sprawa jest otwarta (wymaga uwagi sprzedawcy). */
    public const OPEN_STATUSES = ['DISPUTE_ONGOING', 'DISPUTE_UNRESOLVED', 'CLAIM_SUBMITTED', 'PROBLEM_OPEN'];

    public const STATUS_LABELS = [
        'DISPUTE_ONGOING'    => 'Dyskusja otwarta',
        'DISPUTE_UNRESOLVED' => 'Dyskusja nierozwiązana',
        'DISPUTE_CLOSED'     => 'Dyskusja zamknięta',
        'CLAIM_SUBMITTED'    => 'Reklamacja zgłoszona',
        'CLAIM_ACCEPTED'     => 'Reklamacja uznana',
        'CLAIM_REJECTED'     => 'Reklamacja odrzucona',
        'PROBLEM_OPEN'       => 'Otwarty',
        'PROBLEM_CLOSED'     => 'Zamknięty',
    ];

    /** Rodzaje wiadomości sprzedawcy w dyskusji (POST /sale/issues/{id}/message, pole type). */
    public const MESSAGE_TYPES = [
        'REGULAR'     => 'Zwykła odpowiedź',
        'END_REQUEST' => 'Odpowiedź + prośba do kupującego o zakończenie dyskusji',
    ];

    public function __construct(private readonly PDO $pdo) {}

    public static function migrate(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $id   = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $tail = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $pdo->exec("CREATE TABLE IF NOT EXISTS allegro_issues (
            id $id,
            remote_id VARCHAR(64) NOT NULL,
            type VARCHAR(16) NOT NULL,
            status VARCHAR(40) NOT NULL,
            reference_number VARCHAR(64) NULL,
            subject TEXT NULL,
            buyer_login VARCHAR(190) NULL,
            order_ref VARCHAR(64) NULL,
            woo_order_id BIGINT NULL,
            offer_id VARCHAR(32) NULL,
            chat_active TINYINT NOT NULL DEFAULT 1,
            messages_count INT NOT NULL DEFAULT 0,
            last_message_role VARCHAR(16) NULL,
            last_message_text TEXT NULL,
            last_message_at VARCHAR(30) NULL,
            status_due_at VARCHAR(30) NULL,
            decision_due_at VARCHAR(30) NULL,
            opened_at VARCHAR(30) NULL,
            payload MEDIUMTEXT NULL,
            created_at VARCHAR(30) NOT NULL,
            updated_at VARCHAR(30) NOT NULL
        )$tail");
        $pdo->exec("CREATE TABLE IF NOT EXISTS allegro_ratings (
            id $id,
            remote_id VARCHAR(64) NOT NULL,
            recommended TINYINT NOT NULL,
            comment TEXT NULL,
            buyer_login VARCHAR(190) NULL,
            order_ref VARCHAR(64) NULL,
            woo_order_id BIGINT NULL,
            answer_text TEXT NULL,
            answer_at VARCHAR(30) NULL,
            excluded TINYINT NOT NULL DEFAULT 0,
            removal_possible_to VARCHAR(30) NULL,
            rated_at VARCHAR(30) NULL,
            last_changed_at VARCHAR(30) NULL,
            payload MEDIUMTEXT NULL,
            created_at VARCHAR(30) NOT NULL,
            updated_at VARCHAR(30) NOT NULL
        )$tail");
        if ($driver === 'mysql') {
            foreach (['allegro_issues' => ['uq_issue_remote', 'idx_issue_order'], 'allegro_ratings' => ['uq_rating_remote', 'idx_rating_order']] as $table => [$uq, $ix]) {
                $idx = $pdo->query("SHOW INDEX FROM $table WHERE Key_name = '$uq'")->fetch();
                if (!$idx) {
                    $pdo->exec("CREATE UNIQUE INDEX $uq ON $table (remote_id)");
                    $pdo->exec("CREATE INDEX $ix ON $table (woo_order_id)");
                }
            }
        } else {
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_issue_remote ON allegro_issues (remote_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_issue_order ON allegro_issues (woo_order_id)');
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_rating_remote ON allegro_ratings (remote_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rating_order ON allegro_ratings (woo_order_id)');
        }
    }

    // ------------------------------------------------------------
    //  Odczyt
    // ------------------------------------------------------------

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? $status;
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'CLAIM'            => 'Reklamacja',
            self::TYPE_PROBLEM => 'Problem z zakupem',
            default            => 'Dyskusja',
        };
    }

    public static function isOpen(array $issue): bool
    {
        return in_array((string) $issue['status'], self::OPEN_STATUSES, true);
    }

    /** Otwarta sprawa, w której ostatnio pisał kupujący (albo nikt jeszcze nie odpisał). */
    public static function awaitsSeller(array $issue): bool
    {
        return self::isOpen($issue) && !in_array((string) ($issue['last_message_role'] ?? ''), ['SELLER', 'FULFILLMENT'], true);
    }

    /**
     * Dyskusje i reklamacje: otwarte najpierw, potem od najnowszej.
     * $filter: '' (wszystkie) | 'open' | 'closed'.
     * @return array<int,array<string,mixed>>
     */
    public function issues(string $filter = '', int $limit = 200): array
    {
        $open = "status IN ('" . implode("','", self::OPEN_STATUSES) . "')";
        $where = match ($filter) {
            'open'   => "WHERE $open",
            'closed' => "WHERE NOT $open",
            default  => '',
        };
        $stmt = $this->pdo->query("SELECT * FROM allegro_issues $where
            ORDER BY CASE WHEN $open THEN 0 ELSE 1 END, COALESCE(last_message_at, opened_at, created_at) DESC LIMIT " . max(1, $limit));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function issue(string $remoteId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM allegro_issues WHERE remote_id = ?');
        $stmt->execute([$remoteId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Oceny od najnowszej. $filter: '' (wszystkie) | 'negative' | 'unanswered' (negatywne bez odpowiedzi) | 'positive'.
     * @return array<int,array<string,mixed>>
     */
    public function ratings(string $filter = '', int $limit = 200): array
    {
        $where = match ($filter) {
            'negative'   => 'WHERE recommended = 0',
            'unanswered' => "WHERE recommended = 0 AND (answer_text IS NULL OR answer_text = '')",
            'positive'   => 'WHERE recommended = 1',
            default      => '',
        };
        $stmt = $this->pdo->query("SELECT * FROM allegro_ratings $where ORDER BY COALESCE(rated_at, created_at) DESC LIMIT " . max(1, $limit));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function rating(string $remoteId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM allegro_ratings WHERE remote_id = ?');
        $stmt->execute([$remoteId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Liczniki do zakładek: otwarte sprawy, otwarte czekające na sprzedawcę, negatywne oceny bez odpowiedzi.
     * @return array{open:int,awaiting:int,negative_unanswered:int}
     */
    public function counts(): array
    {
        $open = 0;
        $awaiting = 0;
        $stmt = $this->pdo->query("SELECT status, last_message_role FROM allegro_issues WHERE status IN ('" . implode("','", self::OPEN_STATUSES) . "')");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $open++;
            if (self::awaitsSeller($row)) {
                $awaiting++;
            }
        }
        $neg = (int) $this->pdo->query("SELECT COUNT(*) FROM allegro_ratings WHERE recommended = 0 AND (answer_text IS NULL OR answer_text = '')")->fetchColumn();
        return ['open' => $open, 'awaiting' => $awaiting, 'negative_unanswered' => $neg];
    }

    /**
     * Dyskusje i oceny zamówienia (karta zamówienia).
     * @return array{issues:array<int,array<string,mixed>>,ratings:array<int,array<string,mixed>>}
     */
    public function forOrder(int $wooOrderId): array
    {
        $i = $this->pdo->prepare('SELECT * FROM allegro_issues WHERE woo_order_id = ? ORDER BY id DESC');
        $i->execute([$wooOrderId]);
        $r = $this->pdo->prepare('SELECT * FROM allegro_ratings WHERE woo_order_id = ? ORDER BY id DESC');
        $r->execute([$wooOrderId]);
        return ['issues' => $i->fetchAll(PDO::FETCH_ASSOC), 'ratings' => $r->fetchAll(PDO::FETCH_ASSOC)];
    }

    // ------------------------------------------------------------
    //  Zapis danych z Allegro
    // ------------------------------------------------------------

    /**
     * Zapisuje / aktualizuje dyskusję lub reklamację z GET /sale/issues.
     * @param array<string,mixed> $is
     */
    public function upsertIssue(array $is): ?int
    {
        $remoteId = trim((string) ($is['id'] ?? ''));
        if ($remoteId === '') {
            return null;
        }
        $orderRef = trim((string) ($is['checkoutForm']['id'] ?? ''));
        $state = (array) ($is['currentState'] ?? []);
        $last = is_array($is['chat']['lastMessage'] ?? null) ? $is['chat']['lastMessage'] : [];
        $type = strtoupper((string) ($is['type'] ?? 'DISPUTE'));
        $fields = [
            'type'              => $type,
            'status'            => (string) ($state['status'] ?? $is['status'] ?? ($type === 'CLAIM' ? 'CLAIM_SUBMITTED' : 'DISPUTE_ONGOING')),
            'reference_number'  => self::str($is['referenceNumber'] ?? null),
            'subject'           => self::str($is['subject'] ?? $is['reason']['description'] ?? null),
            'buyer_login'       => self::str($is['buyer']['login'] ?? null),
            'order_ref'         => $orderRef !== '' ? $orderRef : null,
            'woo_order_id'      => $orderRef !== '' ? $this->findAllegroOrderId($orderRef) : null,
            'offer_id'          => self::str($is['offer']['id'] ?? null),
            'chat_active'       => array_key_exists('chatActive', $state) ? ((bool) $state['chatActive'] ? 1 : 0) : 1,
            'messages_count'    => (int) ($is['chat']['messagesCount'] ?? 0),
            'last_message_role' => self::str($last['author']['role'] ?? null),
            'last_message_text' => self::str($last['text'] ?? null),
            'last_message_at'   => self::dt($last['createdAt'] ?? null),
            'status_due_at'     => self::dt($state['statusDueDate'] ?? null),
            'decision_due_at'   => self::dt($is['decisionDueDate'] ?? null),
            'opened_at'         => self::dt($is['openedDate'] ?? $is['createdAt'] ?? null),
            'payload'           => json_encode($is, JSON_UNESCAPED_UNICODE),
        ];
        return $this->upsert('allegro_issues', $remoteId, $fields);
    }

    /**
     * Zapisuje / aktualizuje Problem z zakupem z GET /messaging/threads (beta.v1, typ POST_PURCHASE_ISSUE).
     * $last = najnowsza wiadomość wątku (gdy pobrana), $me = login naszego konta.
     * @param array<string,mixed> $t
     * @param array<string,mixed>|null $last
     */
    public function upsertProblem(array $t, ?array $last = null, string $me = ''): ?int
    {
        $remoteId = trim((string) ($t['id'] ?? ''));
        if ($remoteId === '') {
            return null;
        }
        $order = is_array($t['orders'][0] ?? null) ? $t['orders'][0] : [];
        $orderRef = trim((string) ($order['id'] ?? ''));
        $buyer = AllegroThreads::buyerLogin($t, $me);
        $fields = [
            'type'            => self::TYPE_PROBLEM,
            'status'          => AllegroThreads::isClosed($t) ? 'PROBLEM_CLOSED' : 'PROBLEM_OPEN',
            'subject'         => self::str(AllegroThreads::subTypeLabel($t['subType'] ?? null)),
            'buyer_login'     => $buyer !== '' ? $buyer : null,
            'order_ref'       => $orderRef !== '' ? $orderRef : null,
            'woo_order_id'    => $orderRef !== '' ? $this->findAllegroOrderId($orderRef) : null,
            'offer_id'        => self::str($order['offers'][0]['id'] ?? null),
            'chat_active'     => AllegroThreads::isClosed($t) ? 0 : 1,
            'last_message_at' => self::dt($t['lastMessageDateTime'] ?? null),
            'opened_at'       => self::dt($t['createdAt'] ?? null),
            'payload'         => json_encode($t, JSON_UNESCAPED_UNICODE),
        ];
        if ($last !== null) {
            $fields['last_message_role'] = AllegroThreads::authorRole((array) ($last['author'] ?? []), $me);
            $fields['last_message_text'] = self::str($last['text'] ?? null);
            $fields['last_message_at'] = self::dt($last['createdAt'] ?? null) ?? $fields['last_message_at'];
        }
        return $this->upsert('allegro_issues', $remoteId, $fields);
    }

    /**
     * Zapisuje / aktualizuje ocenę z GET /sale/user-ratings. Obsługuje obie wersje odpowiedzi:
     * public.v1 (comment jako tekst, answer.message) i beta.v1 (comment.text, answer.text).
     * @param array<string,mixed> $r
     */
    public function upsertRating(array $r): ?int
    {
        $remoteId = trim((string) ($r['id'] ?? ''));
        if ($remoteId === '') {
            return null;
        }
        $orderRef = trim((string) ($r['order']['id'] ?? ''));
        $comment = is_array($r['comment'] ?? null) ? ($r['comment']['text'] ?? null) : ($r['comment'] ?? null);
        $answer = is_array($r['answer'] ?? null) ? $r['answer'] : [];
        $excluded = !empty($r['excludedFromAverageRates']) || !empty($r['exclusion']['reason']);
        $fields = [
            'recommended'         => !empty($r['recommended']) ? 1 : 0,
            'comment'             => self::str($comment),
            'buyer_login'         => self::str($r['buyer']['login'] ?? null),
            'order_ref'           => $orderRef !== '' ? $orderRef : null,
            'woo_order_id'        => $orderRef !== '' ? $this->findAllegroOrderId($orderRef) : null,
            'answer_text'         => self::str($answer['text'] ?? $answer['message'] ?? null),
            'answer_at'           => self::dt($answer['createdAt'] ?? null),
            'excluded'            => $excluded ? 1 : 0,
            'removal_possible_to' => self::dt($r['removal']['possibleTo'] ?? null),
            'rated_at'            => self::dt($r['createdAt'] ?? null),
            'last_changed_at'     => self::dt($r['lastChangedAt'] ?? $r['editedAt'] ?? $r['createdAt'] ?? null),
            'payload'             => json_encode($r, JSON_UNESCAPED_UNICODE),
        ];
        return $this->upsert('allegro_ratings', $remoteId, $fields);
    }

    /** @param array<string,mixed> $fields */
    private function upsert(string $table, string $remoteId, array $fields): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("SELECT id, woo_order_id FROM $table WHERE remote_id = ?");
        $stmt->execute([$remoteId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ($fields['woo_order_id'] === null) {
                unset($fields['woo_order_id']); // zamówienie mogło jeszcze nie dojść do CRM - nie kasujemy powiązania
            }
            $sets = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($fields)));
            $this->pdo->prepare("UPDATE $table SET $sets, updated_at = ? WHERE id = ?")
                ->execute([...array_values($fields), $now, (int) $existing['id']]);
            return (int) $existing['id'];
        }
        $cols = array_keys($fields);
        $this->pdo->prepare("INSERT INTO $table (remote_id, " . implode(', ', $cols) . ', created_at, updated_at) VALUES ('
            . implode(', ', array_fill(0, count($cols) + 3, '?')) . ')')
            ->execute([$remoteId, ...array_values($fields), $now, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    private function findAllegroOrderId(string $checkoutFormId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT woo_order_id FROM woo_orders WHERE order_number = ? AND woo_order_id >= 9000000000 LIMIT 1');
        $stmt->execute([$checkoutFormId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    /** Dołącza do zamówień te dyskusje i oceny, których zamówienie trafiło do CRM później. */
    private function relinkOrders(): void
    {
        foreach (['allegro_issues', 'allegro_ratings'] as $table) {
            $rows = $this->pdo->query("SELECT id, order_ref FROM $table WHERE woo_order_id IS NULL AND order_ref IS NOT NULL LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $oid = $this->findAllegroOrderId((string) $row['order_ref']);
                if ($oid !== null) {
                    $this->pdo->prepare("UPDATE $table SET woo_order_id = ? WHERE id = ?")->execute([$oid, (int) $row['id']]);
                }
            }
        }
    }

    // ------------------------------------------------------------
    //  Synchronizacja
    // ------------------------------------------------------------

    /**
     * Pobiera dyskusje/reklamacje i oceny. Błąd jednej części nie blokuje drugiej.
     * @param object $client AllegroClient (issues(), userRatings())
     * @return array{ok:bool,issues:int,ratings:int,messages:array<int,string>}
     */
    public function sync(object $client): array
    {
        $messages = [];
        $issues = 0;
        for ($page = 0; $page < self::ISSUE_PAGES; $page++) {
            $res = $client->issues(['limit' => 100, 'offset' => $page * 100]);
            if (!$res['ok']) {
                $messages[] = 'Dyskusje: ' . $res['message'];
                break;
            }
            foreach ($res['items'] as $is) {
                if ($this->upsertIssue($is) !== null) {
                    $issues++;
                }
            }
            if (count($res['items']) < 100) {
                break;
            }
        }

        $problems = $this->syncProblems($client, $messages);
        $issues += $problems;

        $ratings = 0;
        $last = $this->pdo->query('SELECT MAX(last_changed_at) FROM allegro_ratings')->fetchColumn();
        // Zapas 1 dnia na opóźnienia po stronie Allegro; pierwszy raz - RATINGS_DAYS_BACK dni wstecz.
        $since = $last ? strtotime($last . ' UTC') - 86400 : time() - self::RATINGS_DAYS_BACK * 86400;
        $sinceIso = gmdate('Y-m-d\TH:i:s.000\Z', (int) $since);
        for ($page = 0; $page < self::RATING_PAGES; $page++) {
            $res = $client->userRatings(['lastChangedAt.gte' => $sinceIso, 'limit' => 100, 'offset' => $page * 100]);
            if (!$res['ok']) {
                $messages[] = 'Oceny: ' . $res['message'];
                break;
            }
            foreach ($res['items'] as $r) {
                if ($this->upsertRating($r) !== null) {
                    $ratings++;
                }
            }
            if (count($res['items']) < 100) {
                break;
            }
        }
        $this->relinkOrders();
        return ['ok' => $messages === [], 'issues' => $issues, 'ratings' => $ratings, 'messages' => $messages];
    }

    /**
     * Problemy z zakupem z Centrum wiadomości. Najnowszą wiadomość (kto pisał ostatni) pobieramy
     * tylko dla otwartych wątków, w których coś się zmieniło od ostatniej synchronizacji.
     * @param object $client AllegroClient (messageThreads(), threadMessages(), accountLogin())
     * @param array<int,string> $messages
     */
    private function syncProblems(object $client, array &$messages): int
    {
        $count = 0;
        $pageId = '';
        $me = null;
        $known = $this->pdo->prepare('SELECT last_message_at, last_message_role FROM allegro_issues WHERE remote_id = ?');
        for ($page = 0; $page < self::PROBLEM_PAGES; $page++) {
            $res = $client->messageThreads($pageId, ['type' => AllegroThreads::PROBLEM]);
            if (!$res['ok']) {
                $messages[] = 'Problemy z zakupem: ' . $res['message'];
                break;
            }
            foreach ($res['threads'] as $t) {
                $id = (string) ($t['id'] ?? '');
                if ($id === '' || !AllegroThreads::isProblem($t + ['type' => AllegroThreads::PROBLEM])) {
                    continue;
                }
                $known->execute([$id]);
                $row = $known->fetch(PDO::FETCH_ASSOC) ?: null;
                $changed = $row === null || $row['last_message_role'] === null
                    || $row['last_message_at'] !== self::dt($t['lastMessageDateTime'] ?? null);
                $last = null;
                if ($changed && !AllegroThreads::isClosed($t)) {
                    $last = $client->threadMessages($id, 1)[0] ?? null;
                    if ($last !== null && !in_array(strtoupper((string) ($last['author']['role'] ?? '')), ['BUYER', 'SELLER', 'ADMIN'], true)) {
                        $me ??= $client->accountLogin();
                    }
                }
                if ($this->upsertProblem($t, $last, (string) $me) !== null) {
                    $count++;
                }
            }
            $pageId = $res['next'];
            if ($pageId === '') {
                break;
            }
        }
        return $count;
    }

    // ------------------------------------------------------------
    //  Wysyłka do kupującego (tylko po kliknięciu operatora)
    // ------------------------------------------------------------

    /**
     * Odpowiedź w dyskusji / reklamacji. Po wysłaniu odświeża sprawę z Allegro.
     * @param object $client AllegroClient (sendIssueMessage(), issue())
     * @return array{ok:bool,message:string}
     */
    public function replyToIssue(object $client, string $remoteId, string $text, string $type = 'REGULAR'): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['ok' => false, 'message' => 'Wpisz treść odpowiedzi.'];
        }
        if (!isset(self::MESSAGE_TYPES[$type])) {
            $type = 'REGULAR';
        }
        $issue = $this->issue($remoteId);
        if ($issue === null) {
            return ['ok' => false, 'message' => 'Nie znaleziono tej dyskusji w CRM — odśwież dane z Allegro.'];
        }
        $problem = $issue['type'] === self::TYPE_PROBLEM;
        // Problem z zakupem to wątek Centrum wiadomości - odpowiedź idzie przez /messaging (beta.v1).
        $res = $problem ? $client->sendMessage($remoteId, $text) : $client->sendIssueMessage($remoteId, $text, $type);
        if (!$res['ok']) {
            $msg = $res['status'] === 0
                ? 'Allegro nie odpowiedziało — nie wiadomo, czy wiadomość doszła. Odśwież czat i sprawdź, zanim wyślesz ponownie.'
                : 'Allegro: ' . $res['message'];
            return ['ok' => false, 'message' => $msg];
        }
        $fresh = $problem ? null : $client->issue($remoteId);
        if (is_array($fresh)) {
            $this->upsertIssue($fresh);
        } else {
            $this->pdo->prepare("UPDATE allegro_issues SET last_message_role = 'SELLER', last_message_text = ?, last_message_at = ?, messages_count = messages_count + 1, updated_at = ? WHERE remote_id = ?")
                ->execute([$text, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'), $remoteId]);
        }
        if ($issue['woo_order_id'] !== null) {
            (new AuditTrail($this->pdo))->record((int) $issue['woo_order_id'], 'allegro.issue_reply', [], ['provider' => 'allegro', 'remote_id' => $remoteId]);
        }
        return ['ok' => true, 'message' => 'Wysłano odpowiedź do kupującego.'];
    }

    /**
     * Publiczna odpowiedź na ocenę. Po wysłaniu odświeża ocenę z Allegro.
     * @param object $client AllegroClient (answerUserRating(), userRating())
     * @return array{ok:bool,message:string}
     */
    public function answerRating(object $client, string $remoteId, string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['ok' => false, 'message' => 'Wpisz treść odpowiedzi.'];
        }
        $rating = $this->rating($remoteId);
        if ($rating === null) {
            return ['ok' => false, 'message' => 'Nie znaleziono tej oceny w CRM — odśwież dane z Allegro.'];
        }
        $res = $client->answerUserRating($remoteId, $text);
        if (!$res['ok']) {
            $msg = $res['status'] === 0
                ? 'Allegro nie odpowiedziało — nie wiadomo, czy odpowiedź zapisała się. Odśwież oceny i sprawdź, zanim wyślesz ponownie.'
                : 'Allegro: ' . $res['message'];
            return ['ok' => false, 'message' => $msg];
        }
        $fresh = $client->userRating($remoteId);
        if (is_array($fresh) && !empty($fresh['id'])) {
            $this->upsertRating($fresh);
        }
        // Allegro może jeszcze nie oddać odpowiedzi w GET - zapisujemy ją od razu, żeby zniknęła z „bez odpowiedzi".
        $this->pdo->prepare("UPDATE allegro_ratings SET answer_text = ?, answer_at = COALESCE(answer_at, ?), updated_at = ? WHERE remote_id = ?")
            ->execute([$text, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'), $remoteId]);
        if ($rating['woo_order_id'] !== null) {
            (new AuditTrail($this->pdo))->record((int) $rating['woo_order_id'], 'allegro.rating_answer', [], ['provider' => 'allegro', 'remote_id' => $remoteId]);
        }
        return ['ok' => true, 'message' => 'Odpowiedź na ocenę opublikowana na Allegro.'];
    }

    private static function str(mixed $v): ?string
    {
        if (!is_scalar($v)) {
            return null;
        }
        $s = trim((string) $v);
        return $s !== '' ? $s : null;
    }

    /** ISO 8601 z Allegro → 'Y-m-d H:i:s' UTC. */
    private static function dt(mixed $v): ?string
    {
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($v))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
