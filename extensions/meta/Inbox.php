<?php
declare(strict_types=1);

namespace PaseExt\Meta;

use PDO;
use Pase\Repository\SettingsRepository;

/**
 * Rozmowy z Messengera i Instagrama zapisane w CRM.
 *
 * Meta oddaje z Instagrama treść tylko 20 ostatnich wiadomości rozmowy, więc każdą pobraną wiadomość trzymamy
 * u siebie (ext_meta_messages) - historia rośnie od chwili podłączenia. Zdjęcia z wiadomości są pobierane
 * do storage/uploads/meta/ (linki z CDN Meta po czasie wygasają) i pokazywane tylko po zalogowaniu.
 *
 * Klucze rozmów i wiadomości to skróty SHA-1 z identyfikatorów Meta (te bywają dłuższe niż 100 znaków),
 * pełne identyfikatory są w kolumnach remote_id. Tabele tworzą się same (MySQL i SQLite).
 */
final class Inbox
{
    public const PLATFORMS = ['fb' => 'Messenger', 'ig' => 'Instagram'];
    /** Meta pozwala odpowiedzieć do 24 h od ostatniej wiadomości klienta. */
    public const REPLY_WINDOW = 86400;
    private const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    private const MAX_IMAGE = 8 * 1024 * 1024;
    private const MAX_PAGES = 4;

    /** @var array<int,true> bazy, w których tabele już sprawdzono */
    private static array $ready = [];

    public function __construct(private readonly PDO $pdo)
    {
        if (isset(self::$ready[spl_object_id($pdo)])) {
            return;
        }
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ext_meta_conversations (
            conv_key VARCHAR(40) NOT NULL PRIMARY KEY,
            platform VARCHAR(2) NOT NULL,
            remote_id TEXT NOT NULL,
            peer_id VARCHAR(64) NOT NULL,
            peer_name VARCHAR(190) NOT NULL,
            peer_username VARCHAR(190) NOT NULL,
            last_text TEXT NOT NULL,
            last_from_peer INT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            last_peer_at DATETIME NULL,
            seen_at DATETIME NULL,
            unread INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ext_meta_messages (
            msg_key VARCHAR(40) NOT NULL PRIMARY KEY,
            conv_key VARCHAR(40) NOT NULL,
            remote_id TEXT NOT NULL,
            from_peer INT NOT NULL,
            author VARCHAR(190) NOT NULL,
            body TEXT NOT NULL,
            attachments TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            sent_by VARCHAR(100) NULL
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ext_meta_orders (
            woo_order_id INT NOT NULL PRIMARY KEY,
            conv_key VARCHAR(40) NOT NULL,
            created_by VARCHAR(100) NULL,
            created_at DATETIME NOT NULL
        )');
        self::$ready[spl_object_id($pdo)] = true;
    }

    public static function key(string $platform, string $remoteId): string
    {
        return sha1($platform . ':' . $remoteId);
    }

    public static function dir(): string
    {
        return (defined('PASE_ROOT') ? PASE_ROOT : dirname(__DIR__, 2)) . '/storage/uploads/meta';
    }

    // ---------------------------------------------------------------- odczyt

    /** @return array<string,mixed>|null */
    public function get(string $key): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM ext_meta_conversations WHERE conv_key = ?');
        $st->execute([$key]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Rozmowy od ostatnio zmienionej.
     * @return list<array<string,mixed>>
     */
    public function list(string $platform = '', string $q = '', int $limit = 300): array
    {
        $sql = 'SELECT * FROM ext_meta_conversations WHERE 1 = 1';
        $args = [];
        if (isset(self::PLATFORMS[$platform])) {
            $sql .= ' AND platform = ?';
            $args[] = $platform;
        }
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], '', $q) . '%';
            $sql .= ' AND (peer_name LIKE ? OR peer_username LIKE ? OR conv_key IN (SELECT conv_key FROM ext_meta_messages WHERE body LIKE ?))';
            array_push($args, $like, $like, $like);
        }
        $st = $this->pdo->prepare($sql . ' ORDER BY updated_at DESC LIMIT ' . max(1, $limit));
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> od najstarszej */
    public function messages(string $key): array
    {
        $st = $this->pdo->prepare('SELECT * FROM ext_meta_messages WHERE conv_key = ? ORDER BY created_at, msg_key');
        $st->execute([$key]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<string> $keys @return list<array<string,mixed>> */
    public function messagesByKeys(string $conv, array $keys): array
    {
        $keys = array_values(array_filter($keys, static fn($k): bool => is_string($k) && preg_match('/^[a-f0-9]{40}$/', $k) === 1));
        if ($keys === []) {
            return [];
        }
        $st = $this->pdo->prepare('SELECT * FROM ext_meta_messages WHERE conv_key = ? AND msg_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ') ORDER BY created_at, msg_key');
        $st->execute(array_merge([$conv], $keys));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function unreadTotal(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(SUM(unread), 0) FROM ext_meta_conversations')->fetchColumn();
    }

    public function markSeen(string $key): void
    {
        $this->pdo->prepare('UPDATE ext_meta_conversations SET seen_at = ?, unread = 0 WHERE conv_key = ?')->execute([self::now(), $key]);
    }

    /** Czy Meta przyjmie jeszcze odpowiedź (24 h od ostatniej wiadomości klienta). */
    public static function canReply(array $conv, ?int $now = null): bool
    {
        $at = (string) ($conv['last_peer_at'] ?? '');
        return $at !== '' && ($now ?? time()) - (int) strtotime($at) < self::REPLY_WINDOW;
    }

    /** Do kiedy można odpowiedzieć (znacznik czasu) albo null. */
    public static function replyUntil(array $conv): ?int
    {
        $at = (string) ($conv['last_peer_at'] ?? '');
        return $at !== '' ? (int) strtotime($at) + self::REPLY_WINDOW : null;
    }

    // ---------------------------------------------------------------- zamówienia

    public function linkOrder(int $orderId, string $convKey, string $user): void
    {
        if ($this->get($convKey) === null) {
            return;
        }
        $this->pdo->prepare('DELETE FROM ext_meta_orders WHERE woo_order_id = ?')->execute([$orderId]);
        $this->pdo->prepare('INSERT INTO ext_meta_orders (woo_order_id, conv_key, created_by, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$orderId, $convKey, $user, self::now()]);
    }

    /** Zamówienia z rozmowy, od najnowszego. @return list<array{woo_order_id:int,pase_number:?int,order_number:string,total:float,currency:string}> */
    public function ordersOf(string $convKey): array
    {
        $st = $this->pdo->prepare('SELECT o.woo_order_id, o.pase_number, o.order_number, o.total, o.currency, o.pase_status
            FROM ext_meta_orders m JOIN woo_orders o ON o.woo_order_id = m.woo_order_id WHERE m.conv_key = ? ORDER BY m.created_at DESC');
        $st->execute([$convKey]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Rozmowy zamówień z listy (jedna kwerenda na stronę listy). @param list<int> $ids @return array<int,array<string,mixed>> */
    public function forOrders(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $st = $this->pdo->prepare('SELECT m.woo_order_id, c.* FROM ext_meta_orders m JOIN ext_meta_conversations c ON c.conv_key = m.conv_key
            WHERE m.woo_order_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['woo_order_id']] = $r;
        }
        return $out;
    }

    // ---------------------------------------------------------------- pobieranie z Meta

    /**
     * Pobiera nowe wiadomości z jednego konta: rozmowy zmienione od ostatniego pobrania (przy pierwszym - z $firstDays dni).
     * @param string $platform 'fb' albo 'ig'
     * @param string $graphPlatform 'messenger' albo 'instagram'
     * @return array{new:int,conversations:int,account:string}
     */
    public function sync(MetaApi $api, string $platform, string $graphPlatform, SettingsRepository $store, int $firstDays = 30, ?int $now = null): array
    {
        $now ??= time();
        $me = $api->me();
        $own = array_values(array_filter([$me['id'], $me['ig_id']]));
        $ownName = $platform === 'ig' ? $me['ig_username'] : '';
        $account = $platform === 'ig' ? ($me['ig_username'] !== '' ? '@' . $me['ig_username'] : $me['name']) : $me['name'];
        $markKey = 'EXT_META_SYNC_' . strtoupper($platform);
        $first = $store->get($markKey) === null;
        $since = $first ? $now - max(1, $firstDays) * 86400 : (int) $store->get($markKey, '0') - 300;
        $newest = $first ? 0 : (int) $store->get($markKey, '0');

        $new = 0;
        $touched = 0;
        $after = null;
        for ($page = 0; $page < self::MAX_PAGES; ++$page) {
            $res = $api->conversations($graphPlatform, $after);
            $stop = false;
            foreach ($res['data'] as $c) {
                $updated = self::ts((string) ($c['updated_time'] ?? ''));
                if ($updated !== null && $updated < $since) {
                    $stop = true;
                    break;
                }
                $newest = max($newest, (int) $updated);
                $added = $this->store($api, $platform, $c, $own, $ownName, $first);
                $new += $added;
                ++$touched;
            }
            if ($stop || $res['next'] === null) {
                break;
            }
            $after = $res['next'];
        }
        $store->setMany([$markKey => (string) max($newest, $first ? $now - max(1, $firstDays) * 86400 : 0)]);
        return ['new' => $new, 'conversations' => $touched, 'account' => $account];
    }

    /**
     * Zapisuje rozmowę i jej nowe wiadomości. Zwraca liczbę nowych wiadomości.
     * @param list<string> $own nasze identyfikatory (strona, konto Instagram)
     */
    private function store(MetaApi $api, string $platform, array $c, array $own, string $ownName, bool $first): int
    {
        $remote = (string) ($c['id'] ?? '');
        if ($remote === '') {
            return 0;
        }
        $key = self::key($platform, $remote);
        $isOwn = static fn(array $p): bool => in_array((string) ($p['id'] ?? ''), $own, true)
            || ($ownName !== '' && strcasecmp((string) ($p['username'] ?? ''), $ownName) === 0);

        // Rozmówca: z uczestników, a gdy ich nie ma (Instagram Login) - z nadawców i adresatów wiadomości.
        $peer = null;
        foreach ((array) ($c['participants']['data'] ?? []) as $p) {
            if (is_array($p) && !$isOwn($p)) {
                $peer = $p;
                break;
            }
        }
        $msgs = array_values(array_filter((array) ($c['messages']['data'] ?? []), 'is_array'));
        if ($peer === null) {
            foreach ($msgs as $m) {
                foreach (array_merge([(array) ($m['from'] ?? [])], (array) ($m['to']['data'] ?? [])) as $p) {
                    if (is_array($p) && ($p['id'] ?? '') !== '' && !$isOwn($p)) {
                        $peer ??= $p;
                    }
                }
            }
        }
        $peer ??= [];
        $peerId = (string) ($peer['id'] ?? '');
        $conv = $this->get($key);
        $peerName = trim((string) ($peer['name'] ?? ''));
        $peerUser = trim((string) ($peer['username'] ?? ''));
        if ($peerName === '' && $peerId !== '' && ($conv === null || (string) $conv['peer_name'] === '')) {
            $prof = $api->profile($peerId);
            $peerName = trim((string) ($prof['name'] ?? ''));
            $peerUser = $peerUser !== '' ? $peerUser : trim((string) ($prof['username'] ?? ''));
        }
        $nowStr = self::now();
        if ($conv === null) {
            $this->pdo->prepare('INSERT INTO ext_meta_conversations (conv_key, platform, remote_id, peer_id, peer_name, peer_username, last_text, last_from_peer, updated_at, last_peer_at, seen_at, unread, created_at)
                VALUES (?, ?, ?, ?, ?, ?, \'\', 0, ?, NULL, ?, 0, ?)')
                ->execute([$key, $platform, $remote, mb_substr($peerId, 0, 64), mb_substr($peerName, 0, 190), mb_substr($peerUser, 0, 190),
                    self::local((string) ($c['updated_time'] ?? '')) ?? $nowStr, $first ? $nowStr : null, $nowStr]);
        } else {
            $this->pdo->prepare('UPDATE ext_meta_conversations SET peer_id = ?, peer_name = ?, peer_username = ? WHERE conv_key = ?')
                ->execute([mb_substr($peerId ?: (string) $conv['peer_id'], 0, 64), mb_substr($peerName ?: (string) $conv['peer_name'], 0, 190),
                    mb_substr($peerUser ?: (string) $conv['peer_username'], 0, 190), $key]);
        }

        $added = 0;
        $exists = $this->pdo->prepare('SELECT 1 FROM ext_meta_messages WHERE msg_key = ?');
        $ins = $this->pdo->prepare('INSERT INTO ext_meta_messages (msg_key, conv_key, remote_id, from_peer, author, body, attachments, created_at, sent_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)');
        foreach ($msgs as $m) {
            $mid = (string) ($m['id'] ?? '');
            $created = self::local((string) ($m['created_time'] ?? ''));
            if ($mid === '' || $created === null) {
                continue;
            }
            $mkey = self::key($platform, $mid);
            $exists->execute([$mkey]);
            if ($exists->fetchColumn()) {
                continue;
            }
            $from = (array) ($m['from'] ?? []);
            $fromPeer = $from !== [] ? !$isOwn($from) : false;
            $author = trim((string) ($from['name'] ?? '') ?: (string) ($from['username'] ?? ''));
            try {
                $ins->execute([$mkey, $key, $mid, $fromPeer ? 1 : 0, mb_substr($author, 0, 190), mb_substr(trim((string) ($m['message'] ?? '')), 0, 20000),
                    json_encode($this->attachments($m), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $created]);
                ++$added;
            } catch (\PDOException) {
                // Ta sama wiadomość zapisana właśnie przez drugie pobieranie (cron i „Pobierz teraz”).
            }
        }
        $this->refreshStats($key);
        return $added;
    }

    /** Ostatnia wiadomość, czas ostatniej wiadomości klienta i nieprzeczytane po nowych wiadomościach. */
    private function refreshStats(string $key): void
    {
        $conv = $this->get($key);
        if ($conv === null) {
            return;
        }
        $st = $this->pdo->prepare('SELECT body, attachments, from_peer, created_at FROM ext_meta_messages WHERE conv_key = ? ORDER BY created_at DESC, msg_key DESC LIMIT 1');
        $st->execute([$key]);
        $last = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $st = $this->pdo->prepare('SELECT MAX(created_at) FROM ext_meta_messages WHERE conv_key = ? AND from_peer = 1');
        $st->execute([$key]);
        $peerAt = $st->fetchColumn() ?: null;
        $seen = (string) ($conv['seen_at'] ?? '');
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM ext_meta_messages WHERE conv_key = ? AND from_peer = 1' . ($seen !== '' ? ' AND created_at > ?' : ''));
        $st->execute($seen !== '' ? [$key, $seen] : [$key]);
        $unread = (int) $st->fetchColumn();
        $text = $last !== null ? self::preview($last) : '';
        $this->pdo->prepare('UPDATE ext_meta_conversations SET last_text = ?, last_from_peer = ?, updated_at = ?, last_peer_at = ?, unread = ? WHERE conv_key = ?')
            ->execute([mb_substr($text, 0, 300), $last !== null ? (int) $last['from_peer'] : 0, $last['created_at'] ?? $conv['updated_at'], $peerAt, $unread, $key]);
    }

    /** Krótki opis wiadomości na liście rozmów. */
    public static function preview(array $msg): string
    {
        $body = trim(preg_replace('/\s+/u', ' ', (string) ($msg['body'] ?? '')) ?? '');
        if ($body !== '') {
            return $body;
        }
        $att = json_decode((string) ($msg['attachments'] ?? '[]'), true) ?: [];
        return $att === [] ? '' : (($att[0]['type'] ?? '') === 'image' ? '📷 Zdjęcie' : '📎 Załącznik');
    }

    /**
     * Załączniki wiadomości: zdjęcia pobrane na dysk, reszta jako link.
     * @return list<array{type:string,url:string,name:string,file?:string}>
     */
    private function attachments(array $m): array
    {
        $out = [];
        foreach ((array) ($m['attachments']['data'] ?? []) as $a) {
            if (!is_array($a)) {
                continue;
            }
            $mime = strtolower((string) ($a['mime_type'] ?? ''));
            $url = (string) ($a['image_data']['url'] ?? $a['video_data']['url'] ?? $a['file_url'] ?? $a['payload']['url'] ?? $a['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $type = isset($a['image_data']) || str_starts_with($mime, 'image/') ? 'image' : (isset($a['video_data']) || str_starts_with($mime, 'video/') ? 'video' : 'file');
            $item = ['type' => $type, 'url' => $url, 'name' => mb_substr((string) ($a['name'] ?? ''), 0, 120)];
            if ($type === 'image') {
                $file = $this->saveImage($url);
                if ($file !== null) {
                    $item['file'] = $file;
                }
            }
            $out[] = $item;
            if (count($out) >= 10) {
                break;
            }
        }
        return $out;
    }

    private function saveImage(string $url): ?string
    {
        try {
            $data = MetaApi::download($url, self::MAX_IMAGE);
            if ($data === null) {
                return null;
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($data) ?: '';
            $ext = self::IMAGE_TYPES[$mime] ?? null;
            if ($ext === null) {
                return null;
            }
            $dir = self::dir();
            if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
                return null;
            }
            $name = bin2hex(random_bytes(16)) . '.' . $ext;
            return file_put_contents($dir . '/' . $name, $data) !== false ? $name : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Ścieżka zapisanego zdjęcia (tylko nazwy nadane przez nas). */
    public static function filePath(string $name): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|gif)$/', $name)) {
            return null;
        }
        $p = self::dir() . '/' . $name;
        return is_file($p) ? $p : null;
    }

    public static function mimeOf(string $name): string
    {
        return (string) (array_search(pathinfo($name, PATHINFO_EXTENSION), self::IMAGE_TYPES, true) ?: 'application/octet-stream');
    }

    // ---------------------------------------------------------------- odpowiedź

    /** Wysyła odpowiedź do rozmowy i zapisuje ją w CRM. */
    public function reply(MetaApi $api, array $conv, string $text, string $user): void
    {
        $text = trim(str_replace("\r\n", "\n", $text));
        if ($text === '') {
            throw new \RuntimeException('Wpisz treść odpowiedzi.');
        }
        if (mb_strlen($text) > 2000) {
            throw new \RuntimeException('Odpowiedź może mieć najwyżej 2000 znaków.');
        }
        if (!self::canReply($conv)) {
            throw new \RuntimeException('Minęły 24 godziny od ostatniej wiadomości klienta - Meta nie pozwala już odpowiedzieć z CRM. Odpowiedz w Meta Business Suite.');
        }
        if ((string) $conv['peer_id'] === '') {
            throw new \RuntimeException('Nie wiadomo, do kogo wysłać odpowiedź. Kliknij „Pobierz teraz” i spróbuj jeszcze raz.');
        }
        $mid = $api->send((string) $conv['peer_id'], $text);
        $mid = $mid !== '' ? $mid : 'crm-' . bin2hex(random_bytes(8));
        $this->pdo->prepare('INSERT INTO ext_meta_messages (msg_key, conv_key, remote_id, from_peer, author, body, attachments, created_at, sent_by) VALUES (?, ?, ?, 0, ?, ?, \'[]\', ?, ?)')
            ->execute([self::key((string) $conv['platform'], $mid), $conv['conv_key'], $mid, mb_substr($user, 0, 190), $text, self::now(), mb_substr($user, 0, 100)]);
        $this->markSeen((string) $conv['conv_key']);
        $this->refreshStats((string) $conv['conv_key']);
    }

    // ---------------------------------------------------------------- czas

    /** Czas w bazie: UTC, jak w całym CRM (config.php). */
    public static function now(?int $ts = null): string
    {
        return gmdate('Y-m-d H:i:s', $ts ?? time());
    }

    /** Czas z Meta („2026-11-04T19:11:07+0000”) jako czas lokalny CRM. */
    public static function local(string $iso): ?string
    {
        $ts = self::ts($iso);
        return $ts !== null ? self::now($ts) : null;
    }

    private static function ts(string $iso): ?int
    {
        if ($iso === '') {
            return null;
        }
        $t = strtotime($iso);
        return $t !== false ? $t : null;
    }
}
