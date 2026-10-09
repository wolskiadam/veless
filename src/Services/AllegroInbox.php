<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;

/**
 * Rozmowy z Centrum wiadomości Allegro zapisane w CRM (tabela allegro_threads), żeby lista
 * w Wiadomościach miała stronicowanie, wyszukiwarkę i archiwum niezależnie od tego, ile
 * rozmów jest na Allegro. Treść rozmowy przy otwarciu i tak czytamy na żywo z Allegro;
 * tu trzymamy ostatnią wiadomość, powiązane zamówienie i tekst do wyszukiwania.
 *
 * Archiwum jest tylko w CRM (Allegro go nie ma): rozmowa trafia tam ręcznie, razem z jej
 * zamówieniem (gdy zamówienie idzie do archiwum) albo po ARCHIVE_AFTER_DAYS bez nowych
 * wiadomości, gdy nie ma zamówienia. Nowa wiadomość od kupującego wyciąga ją z archiwum.
 */
final class AllegroInbox
{
    public const PER_PAGE          = 30;
    public const FULL_SYNC_KEY     = 'ALLEGRO_INBOX_FULL_SYNC_AT';
    public const FIRST_SYNC_PAGES  = 25;   // pierwsze pobranie: do ok. 500 rozmów wstecz
    public const SEARCH_TEXT_MAX   = 20000;
    private const MESSAGES_PER_THREAD = 20;

    private const JOIN = 'LEFT JOIN woo_orders o ON o.woo_order_id = t.woo_order_id';

    public function __construct(private readonly PDO $pdo) {}

    public static function migrate(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $id   = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $tail = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $pdo->exec("CREATE TABLE IF NOT EXISTS allegro_threads (
            id $id,
            remote_id VARCHAR(80) NOT NULL,
            buyer_login VARCHAR(190) NULL,
            type VARCHAR(32) NULL,
            status VARCHAR(16) NULL,
            is_read TINYINT NOT NULL DEFAULT 1,
            last_message_at VARCHAR(30) NULL,
            last_role VARCHAR(16) NULL,
            last_text TEXT NULL,
            order_ref VARCHAR(64) NULL,
            woo_order_id BIGINT NULL,
            search_text MEDIUMTEXT NULL,
            archived TINYINT NOT NULL DEFAULT 0,
            archived_by VARCHAR(16) NULL,
            archived_at VARCHAR(30) NULL,
            created_at VARCHAR(30) NOT NULL,
            updated_at VARCHAR(30) NOT NULL
        )$tail");
        if ($driver === 'mysql') {
            if (!$pdo->query("SHOW INDEX FROM allegro_threads WHERE Key_name = 'uq_thread_remote'")->fetch()) {
                $pdo->exec('CREATE UNIQUE INDEX uq_thread_remote ON allegro_threads (remote_id)');
                $pdo->exec('CREATE INDEX idx_thread_list ON allegro_threads (archived, last_message_at)');
                $pdo->exec('CREATE INDEX idx_thread_order ON allegro_threads (woo_order_id)');
            }
        } else {
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_thread_remote ON allegro_threads (remote_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_thread_list ON allegro_threads (archived, last_message_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_thread_order ON allegro_threads (woo_order_id)');
        }
    }

    // ------------------------------------------------------------
    //  Lista
    // ------------------------------------------------------------

    /**
     * Strona listy rozmów: najnowsze na górze.
     * $filter: '' (bez archiwum) | 'unread' | 'archive'; $q szuka w loginie, numerze zamówienia i treści.
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int}
     */
    public function page(string $filter = '', string $q = '', int $page = 1, int $perPage = self::PER_PAGE): array
    {
        [$where, $args] = $this->where($filter, $q);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM allegro_threads t ' . self::JOIN . " $where");
        $count->execute($args);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min(max(1, $page), $pages);
        $stmt = $this->pdo->prepare("SELECT t.*, o.pase_number, o.order_number AS order_number_crm
            FROM allegro_threads t " . self::JOIN . "
            $where ORDER BY COALESCE(t.last_message_at, t.created_at) DESC, t.id DESC
            LIMIT " . max(1, $perPage) . ' OFFSET ' . (($page - 1) * max(1, $perPage)));
        $stmt->execute($args);
        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** Liczniki do filtrów: nieprzeczytane (poza archiwum) i w archiwum. @return array{unread:int,archive:int} */
    public function counts(): array
    {
        $row = $this->pdo->query('SELECT SUM(CASE WHEN archived = 0 AND is_read = 0 THEN 1 ELSE 0 END) AS unread,
            SUM(CASE WHEN archived = 1 THEN 1 ELSE 0 END) AS archive FROM allegro_threads')->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['unread' => (int) ($row['unread'] ?? 0), 'archive' => (int) ($row['archive'] ?? 0)];
    }

    /** @return array<string,mixed>|null */
    public function thread(string $remoteId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT t.*, o.pase_number, o.order_number AS order_number_crm
            FROM allegro_threads t LEFT JOIN woo_orders o ON o.woo_order_id = t.woo_order_id WHERE t.remote_id = ?');
        $stmt->execute([$remoteId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Numer zamówienia do pokazania przy rozmowie ('' = brak zamówienia w CRM). */
    public static function orderLabel(array $row): string
    {
        if (empty($row['woo_order_id'])) {
            return '';
        }
        $n = (string) ($row['pase_number'] ?? '');
        return '#' . ($n !== '' ? $n : (int) $row['woo_order_id']);
    }

    /** @return array{0:string,1:array<int,string>} */
    private function where(string $filter, string $q): array
    {
        $cond = [match ($filter) {
            'archive' => 't.archived = 1',
            'unread'  => 't.archived = 0 AND t.is_read = 0',
            default   => 't.archived = 0',
        }];
        $args = [];
        $q = trim(mb_substr($q, 0, 100));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)) . '%';
            $cond[] = "(LOWER(COALESCE(t.buyer_login, '')) LIKE ? ESCAPE '\\' OR LOWER(COALESCE(t.search_text, '')) LIKE ? ESCAPE '\\'
                OR LOWER(COALESCE(o.pase_number, '')) LIKE ? ESCAPE '\\' OR LOWER(COALESCE(t.order_ref, '')) LIKE ? ESCAPE '\\'
                OR CAST(COALESCE(t.woo_order_id, '') AS CHAR) = ?)";
            $num = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(ltrim($q, '#'))) . '%';
            array_push($args, $like, $like, $num, $like, ltrim($q, '#'));
            // Wyszukiwanie w „Archiwum” i „Nieprzeczytanych” zostaje w tym filtrze; w domyślnym widoku szuka też w archiwum.
            if ($filter === '') {
                $cond[0] = '1 = 1';
            }
        }
        return ['WHERE ' . implode(' AND ', $cond), $args];
    }

    // ------------------------------------------------------------
    //  Archiwum
    // ------------------------------------------------------------

    public function archive(string $remoteId): bool
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("UPDATE allegro_threads SET archived = 1, archived_by = 'manual', archived_at = ?, updated_at = ? WHERE remote_id = ?");
        $stmt->execute([$now, $now, $remoteId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Automatyczne archiwum: rozmowy, których zamówienie jest w archiwum, oraz rozmowy bez zamówienia
     * bez nowej wiadomości od $days dni. Nie rusza rozmów nieprzeczytanych ani przywróconych ręcznie;
     * rozmowa, którą z archiwum wyciągnęła nowa wiadomość, nie wraca tam razem z zamówieniem.
     */
    public function autoArchive(int $days = 90): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, $days) * 86400);
        $hasLifecycle = LowStock::columnExists($this->pdo, 'woo_orders', 'lifecycle');
        $byOrder = $hasLifecycle
            ? "(woo_order_id IS NOT NULL AND woo_order_id IN (SELECT woo_order_id FROM woo_orders WHERE lifecycle IN ('archived', 'trashed')))"
            : '1 = 0';
        $stmt = $this->pdo->prepare("UPDATE allegro_threads SET archived = 1, archived_by = 'auto', archived_at = ?, updated_at = ?
            WHERE archived = 0 AND is_read = 1 AND COALESCE(archived_by, '') <> 'restored'
              AND (($byOrder AND COALESCE(archived_by, '') <> 'reopened')
                   OR ((woo_order_id IS NULL OR archived_by = 'reopened') AND COALESCE(last_message_at, created_at) < ?))");
        $stmt->execute([$now, $now, $cutoff]);
        return $stmt->rowCount();
    }

    /** Ręczne „Przywróć” – automat już tej rozmowy nie zarchiwizuje (chyba że dojdzie nowa wiadomość). */
    public function restore(string $remoteId): bool
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("UPDATE allegro_threads SET archived = 0, archived_by = 'restored', archived_at = NULL, updated_at = ? WHERE remote_id = ?");
        $stmt->execute([$now, $remoteId]);
        return $stmt->rowCount() > 0;
    }

    // ------------------------------------------------------------
    //  Zapis z Allegro
    // ------------------------------------------------------------

    /**
     * Pobiera rozmowy z Allegro (od najnowszych) i zapisuje zmienione. Kończy, gdy cała strona
     * jest bez zmian (wszystko starsze już mamy) albo po $maxPages stronach. Przy pustej tabeli
     * ($deepFirstRun, tylko w cronie) sięga do FIRST_SYNC_PAGES stron wstecz.
     * @param object $client AllegroClient (messageThreads(), threadMessages(), accountLogin())
     * @return array{ok:bool,changed:int,message:string}
     */
    public function sync(object $client, int $maxPages = 3, bool $deepFirstRun = true): array
    {
        $settings = new \Pase\Repository\SettingsRepository($this->pdo);
        $empty = $deepFirstRun && ($settings->get(self::FULL_SYNC_KEY, '') ?? '') === '';
        $maxPages = $empty ? max($maxPages, self::FIRST_SYNC_PAGES) : $maxPages;
        $changed = 0;
        $pageId = '';
        $me = null;
        for ($page = 0; $page < $maxPages; $page++) {
            $res = $client->messageThreads($pageId);
            if (!$res['ok']) {
                return ['ok' => false, 'changed' => $changed, 'message' => $res['message']];
            }
            $pageChanged = 0;
            foreach ($res['threads'] as $t) {
                $id = (string) ($t['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $known = $this->thread($id);
                $lastAt = self::dt($t['lastMessageDateTime'] ?? null);
                if ($known !== null && $known['last_message_at'] === $lastAt) {
                    // Bez nowych wiadomości - odświeżamy tylko „przeczytane” i status.
                    $this->pdo->prepare('UPDATE allegro_threads SET is_read = ?, status = ? WHERE id = ?')
                        ->execute([!empty($t['read']) ? 1 : 0, self::str($t['status'] ?? null) ?? $known['status'], (int) $known['id']]);
                    continue;
                }
                $me ??= $client->accountLogin();
                $this->store($t, $client->threadMessages($id, self::MESSAGES_PER_THREAD), $me);
                $pageChanged++;
            }
            $changed += $pageChanged;
            $pageId = (string) ($res['next'] ?? '');
            if ($pageId === '' || ($pageChanged === 0 && !$empty)) {
                break;
            }
        }
        if ($empty) {
            $settings->setMany([self::FULL_SYNC_KEY => gmdate('Y-m-d H:i:s')]);
        }
        return ['ok' => true, 'changed' => $changed, 'message' => ''];
    }

    /**
     * Zapisuje rozmowę: wątek z GET /messaging/threads i jej najnowsze wiadomości (od najnowszej).
     * @param array<string,mixed> $t
     * @param array<int,array<string,mixed>> $messages
     */
    public function store(array $t, array $messages, string $me = ''): void
    {
        $remoteId = (string) ($t['id'] ?? '');
        if ($remoteId === '') {
            return;
        }
        usort($messages, static fn($a, $b) => strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? '')));
        $last = $messages[0] ?? null;
        $orderRef = trim((string) ($t['orders'][0]['id'] ?? ''));
        foreach ($messages as $m) {
            if ($orderRef === '' && !empty($m['relatesTo']['order']['id'])) {
                $orderRef = (string) $m['relatesTo']['order']['id'];
            }
        }
        $buyer = AllegroThreads::buyerLogin($t, $me);
        if ($buyer === '') {
            foreach ($messages as $m) {
                if (!AllegroThreads::isMine((array) ($m['author'] ?? []), $me) && ($m['author']['login'] ?? '') !== '') {
                    $buyer = (string) $m['author']['login'];
                    break;
                }
            }
        }
        $lastRole = $last !== null ? AllegroThreads::authorRole((array) ($last['author'] ?? []), $me) : null;
        $now = gmdate('Y-m-d H:i:s');
        $existing = $this->thread($remoteId);
        $text = $existing !== null ? (string) ($existing['search_text'] ?? '') : '';
        foreach (array_reverse($messages) as $m) {
            $line = trim(html_entity_decode((string) ($m['text'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($line !== '' && !str_contains($text, $line)) {
                $text .= ($text !== '' ? "\n" : '') . $line;
            }
        }
        $text = mb_substr($text, -self::SEARCH_TEXT_MAX);
        $fields = [
            'buyer_login'     => $buyer !== '' ? $buyer : ($existing['buyer_login'] ?? null),
            'type'            => self::str($t['type'] ?? null),
            'status'          => self::str($t['status'] ?? null),
            'is_read'         => !empty($t['read']) ? 1 : 0,
            'last_message_at' => self::dt($t['lastMessageDateTime'] ?? null) ?? self::dt($last['createdAt'] ?? null),
            'last_role'       => $lastRole,
            'last_text'       => $last !== null ? mb_substr(trim(html_entity_decode((string) ($last['text'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 500) : ($existing['last_text'] ?? null),
            'order_ref'       => $orderRef !== '' ? $orderRef : ($existing['order_ref'] ?? null),
            'search_text'     => $text,
        ];
        $wooId = $fields['order_ref'] !== null ? $this->findAllegroOrderId((string) $fields['order_ref']) : null;
        $fields['woo_order_id'] = $wooId ?? ($existing['woo_order_id'] ?? null);
        // Nowa wiadomość od kupującego wyciąga rozmowę z archiwum.
        if ($existing !== null && (int) $existing['archived'] === 1 && $lastRole === 'BUYER'
            && $fields['last_message_at'] !== $existing['last_message_at']) {
            $fields += ['archived' => 0, 'archived_by' => 'reopened', 'archived_at' => null];
        }
        if ($existing !== null) {
            $sets = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($fields)));
            $this->pdo->prepare("UPDATE allegro_threads SET $sets, updated_at = ? WHERE id = ?")
                ->execute([...array_values($fields), $now, (int) $existing['id']]);
            return;
        }
        $cols = array_keys($fields);
        $this->pdo->prepare('INSERT INTO allegro_threads (remote_id, ' . implode(', ', $cols) . ', created_at, updated_at) VALUES ('
            . implode(', ', array_fill(0, count($cols) + 3, '?')) . ')')
            ->execute([$remoteId, ...array_values($fields), $now, $now]);
    }

    /** Rozmowa otwarta w CRM: przeczytana, a pobrane wiadomości uzupełniają wyszukiwanie. */
    public function opened(array $t, array $messages, string $me = ''): void
    {
        $this->store(['read' => true] + $t, $messages, $me);
        $this->pdo->prepare('UPDATE allegro_threads SET is_read = 1 WHERE remote_id = ?')->execute([(string) ($t['id'] ?? '')]);
    }

    private function findAllegroOrderId(string $checkoutFormId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT woo_order_id FROM woo_orders WHERE order_number = ? AND woo_order_id >= 9000000000 LIMIT 1');
        $stmt->execute([$checkoutFormId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    private static function str(mixed $v): ?string
    {
        return is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
    }

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
