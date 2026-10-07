<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Repository\SettingsRepository;

/**
 * Powiadomienia do dzwoneczka w górnym menu panelu:
 *  - nowe wiadomości od klientów (strona zamówienia),
 *  - nieprzeczytane wiadomości Allegro,
 *  - dyskusje/reklamacje Allegro czekające na odpowiedź i negatywne oceny bez odpowiedzi
 *    (z tabel zapisywanych przez cron - znikają dopiero po odpowiedzi, nie da się ich „odczytać"),
 *  - zwroty, przy których zostało coś do zrobienia (stan, korekta, zwrot pieniędzy).
 *  - produkty z niskim stanem (stan ≤ minimum produktu albo progu domyślnego, patrz LowStock),
 *  - nowe zamówienia od ostatniego otwarcia dzwoneczka (osobno dla każdego użytkownika),
 *  - problemy: wygasły token Allegro, zadania kolejki z błędem (24 h).
 * Wiadomości Allegro wymagają zapytania do API - wynik trzymamy 2 minuty w sesji.
 */
final class Notifications
{
    private const SEEN_KEY = 'NOTIF_ORDERS_SEEN_';   // + id użytkownika, wartość: data wg zegara bazy
    private const DISMISSED_KEY = 'NOTIF_ORDERS_DISMISSED_'; // + id użytkownika: JSON z ID odczytanych zamówień
    private const PREFS_KEY = 'NOTIF_PREFS_';        // + id użytkownika: JSON {typ: bool}
    private const LOW_ACK_KEY = 'NOTIF_LOW_STOCK_ACK_'; // + id użytkownika: JSON z ID produktów z niskim stanem już odczytanych
    private const ALLEGRO_TTL = 120;

    /** Rodzaje powiadomień, które użytkownik może włączyć lub wyłączyć. */
    public const TYPES = [
        'client_msgs'  => 'Wiadomości od klientów',
        'allegro_msgs' => 'Wiadomości Allegro',
        'allegro_issues'  => 'Dyskusje, reklamacje i problemy z zakupem Allegro czekające na odpowiedź',
        'allegro_ratings' => 'Negatywne oceny Allegro bez odpowiedzi',
        'returns'         => 'Zwroty do obsłużenia',
        'low_stock'       => 'Niski stan w magazynie',
        'new_orders'   => 'Nowe zamówienia',
        'problems'     => 'Problemy (token Allegro, błędy kolejki)',
    ];

    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param callable():(\PasePlugin\Allegro\AllegroClient|null) $allegro tworzy klienta tylko gdy trzeba
     * @return array{total:int,items:array<int,array<string,mixed>>}
     */
    public function collect(int $userId, callable $allegro): array
    {
        $items = [];
        $prefs = $this->prefs($userId);
        $sources = [];
        if ($prefs['client_msgs'])  { $sources[] = fn() => $this->clientMessages(); }
        if ($prefs['allegro_msgs']) { $sources[] = fn() => $this->allegroMessages($allegro); }
        if ($prefs['allegro_issues'])  { $sources[] = fn() => $this->allegroIssues(); }
        if ($prefs['allegro_ratings']) { $sources[] = fn() => $this->allegroRatings(); }
        if ($prefs['returns'])         { $sources[] = fn() => $this->openReturns(); }
        if ($prefs['low_stock'])       { $sources[] = fn() => $this->lowStock($userId); }
        if ($prefs['new_orders'])   { $sources[] = fn() => $this->newOrders($userId); }
        if ($prefs['problems'])     { $sources[] = fn() => $this->allegroToken(); $sources[] = fn() => $this->failedJobs(); }
        foreach ($sources as $src) {
            try {
                $it = $src();
                if ($it !== null && $it['count'] > 0) {
                    $items[] = $it;
                }
            } catch (\Throwable) {
                // Jedno źródło z błędem (np. brak tabeli) nie może wyłączyć całego dzwoneczka.
            }
        }
        return ['total' => array_sum(array_column($items, 'count')), 'items' => $items, 'prefs' => $prefs, 'types' => self::TYPES];
    }

    /** @return array<string,bool> co użytkownik chce widzieć (domyślnie wszystko) */
    public function prefs(int $userId): array
    {
        $saved = json_decode((string) ((new SettingsRepository($this->pdo))->get(self::PREFS_KEY . $userId, '') ?? ''), true);
        $out = [];
        foreach (self::TYPES as $k => $_) {
            $out[$k] = is_array($saved) && array_key_exists($k, $saved) ? (bool) $saved[$k] : true;
        }
        return $out;
    }

    /** @param array<string,mixed> $values */
    public function savePrefs(int $userId, array $values): void
    {
        $out = [];
        foreach (self::TYPES as $k => $_) {
            $out[$k] = !empty($values[$k]);
        }
        (new SettingsRepository($this->pdo))->setMany([self::PREFS_KEY . $userId => json_encode($out)]);
    }

    /** Jedno zamówienie odczytane (kliknięte w dzwoneczku lub otwarte). */
    public function dismissOrder(int $userId, int $wooOrderId): void
    {
        $settings = new SettingsRepository($this->pdo);
        $ids = json_decode((string) ($settings->get(self::DISMISSED_KEY . $userId, '[]') ?? '[]'), true) ?: [];
        $ids[] = $wooOrderId;
        $ids = array_slice(array_values(array_unique(array_map('intval', $ids))), -300);
        $settings->setMany([self::DISMISSED_KEY . $userId => json_encode($ids)]);
    }

    /**
     * „Oznacz jako przeczytane" dla grupy: zamówienia - wszystkie do teraz; wiadomości od klientów -
     * wszystkie nieprzeczytane; Allegro - wątki na Allegro oznaczamy jako przeczytane;
     * niski stan - obecne produkty znikają z dzwoneczka, wrócą dopiero po uzupełnieniu i ponownym spadku.
     */
    public function markRead(int $userId, string $key, callable $allegro): void
    {
        $steps = [
            'new_orders'   => fn() => $this->markOrdersSeen($userId),
            'client_msgs'  => fn() => $this->pdo->exec("UPDATE order_messages SET is_read = 1 WHERE sender = 'client' AND is_read = 0"),
            'allegro_msgs' => function () use ($allegro): void {
                $unread = $_SESSION['notif_allegro']['unread'] ?? [];
                $client = $unread ? $allegro() : null;
                foreach ($client ? $unread : [] as $u) {
                    $client->markThreadRead((string) $u['id']);
                }
                unset($_SESSION['notif_allegro']);
            },
            'low_stock'    => function () use ($userId): void {
                $ids = array_map(static fn($r) => (int) $r['id'], (new LowStock($this->pdo))->rows());
                (new SettingsRepository($this->pdo))->setMany([self::LOW_ACK_KEY . $userId => json_encode($ids)]);
            },
        ];
        foreach ($steps as $k => $step) {
            if ($key !== $k && $key !== 'all') {
                continue;
            }
            try {
                $step();
            } catch (\Throwable) {
                // Jedna grupa z błędem (np. brak tabeli, Allegro niedostępne) nie blokuje odczytania pozostałych.
            }
        }
    }

    /** Wątek Allegro odczytany - wyrzucamy go z pamięci podręcznej bez czekania 2 minut. */
    public static function forgetAllegroThread(string $threadId): void
    {
        if (isset($_SESSION['notif_allegro']['unread'])) {
            $_SESSION['notif_allegro']['unread'] = array_values(array_filter(
                $_SESSION['notif_allegro']['unread'], static fn($u) => (string) $u['id'] !== $threadId
            ));
        }
    }

    /** Zapamiętuje, że użytkownik widział zamówienia do teraz. */
    public function markOrdersSeen(int $userId): void
    {
        (new SettingsRepository($this->pdo))->setMany([self::SEEN_KEY . $userId => $this->dbNow()]);
    }

    private function clientMessages(): ?array
    {
        $rows = $this->pdo->query(
            "SELECT m.woo_order_id, COUNT(*) AS n, MAX(m.created_at) AS last, MAX(o.order_number) AS num, MAX(o.customer_name) AS who
             FROM order_messages m LEFT JOIN woo_orders o ON o.woo_order_id = m.woo_order_id
             WHERE m.sender = 'client' AND m.is_read = 0
             GROUP BY m.woo_order_id ORDER BY last DESC LIMIT 20"
        )->fetchAll(PDO::FETCH_ASSOC);
        $count = (int) array_sum(array_column($rows, 'n'));
        return [
            'key' => 'client_msgs', 'title' => 'Wiadomości od klientów', 'count' => $count, 'level' => 'info',
            'link' => 'index.php?unread=1',
            'details' => array_map(fn($r) => [
                'text' => trim(($r['who'] ?: 'Klient') . ' · zam. ' . ($r['num'] ?: $r['woo_order_id'])) . ((int) $r['n'] > 1 ? " ({$r['n']})" : ''),
                'time' => $this->when($r['last']),
                'link' => 'order_view.php?id=' . (int) $r['woo_order_id'] . '#wiadomosci',
            ], array_slice($rows, 0, 8)),
        ];
    }

    private function allegroMessages(callable $allegro): ?array
    {
        $cache = $_SESSION['notif_allegro'] ?? null;
        if (!is_array($cache) || ($cache['t'] ?? 0) < time() - self::ALLEGRO_TTL) {
            $client = $allegro();
            if ($client === null) {
                return null;
            }
            $res = $client->messageThreads();
            if (!$res['ok']) {
                return null;
            }
            $unread = [];
            foreach ($res['threads'] as $t) {
                if (empty($t['read'])) {
                    $login = AllegroThreads::buyerLogin($t, $client->accountLogin());
                    $unread[] = ['id' => (string) ($t['id'] ?? ''),
                                 'login' => (AllegroThreads::isProblem($t) ? 'Problem z zakupem · ' : '') . ($login !== '' ? $login : 'Kupujący'),
                                 'at' => (string) ($t['lastMessageDateTime'] ?? '')];
                }
            }
            $cache = ['t' => time(), 'unread' => $unread];
            $_SESSION['notif_allegro'] = $cache;
        }
        $unread = $cache['unread'];
        return [
            'key' => 'allegro_msgs', 'title' => 'Wiadomości Allegro', 'count' => count($unread), 'level' => 'info',
            'link' => 'allegro_messages.php',
            'details' => array_map(fn($u) => [
                'text' => $u['login'],
                'time' => $u['at'] !== '' ? $this->when($u['at'], true) : '',
                'link' => 'allegro_messages.php?thread=' . rawurlencode($u['id']),
            ], array_slice($unread, 0, 8)),
        ];
    }

    /** Otwarte dyskusje/reklamacje Allegro, w których ostatnio pisał kupujący. */
    private function allegroIssues(): ?array
    {
        $details = [];
        foreach ((new AllegroFeedback($this->pdo))->issues('open', 100) as $is) {
            if (!AllegroFeedback::awaitsSeller($is)) {
                continue;
            }
            $at = $is['last_message_at'] ?? $is['opened_at'] ?? null;
            $details[] = [
                'text' => AllegroFeedback::typeLabel((string) $is['type']) . ' · ' . ($is['buyer_login'] ?: 'Kupujący')
                        . ($is['subject'] ? ' · ' . mb_strimwidth((string) $is['subject'], 0, 40, '…') : ''),
                'time' => $at ? $this->when($at . 'Z', true) : '',
                'link' => 'allegro_messages.php?tab=issues&issue=' . rawurlencode((string) $is['remote_id']),
            ];
        }
        return [
            'key' => 'allegro_issues', 'title' => 'Dyskusje i problemy z zakupem Allegro — czekają na odpowiedź', 'short' => 'Dyskusje',
            'count' => count($details), 'level' => 'err', 'link' => 'allegro_messages.php?tab=issues&f=open',
            'details' => array_slice($details, 0, 8),
        ];
    }

    /** Negatywne oceny Allegro bez odpowiedzi sprzedawcy. */
    private function allegroRatings(): ?array
    {
        $rows = (new AllegroFeedback($this->pdo))->ratings('unanswered', 100);
        return [
            'key' => 'allegro_ratings', 'title' => 'Negatywne oceny Allegro bez odpowiedzi', 'short' => 'Negatywne oceny',
            'count' => count($rows), 'level' => 'err', 'link' => 'allegro_messages.php?tab=ratings&f=unanswered',
            'details' => array_map(fn($r) => [
                'text' => ($r['buyer_login'] ?: 'Kupujący') . ($r['comment'] ? ' · ' . mb_strimwidth((string) $r['comment'], 0, 40, '…') : ''),
                'time' => $r['rated_at'] ? $this->when($r['rated_at'] . 'Z', true) : '',
                'link' => 'allegro_messages.php?tab=ratings&f=unanswered#r-' . rawurlencode((string) $r['remote_id']),
            ], array_slice($rows, 0, 8)),
        ];
    }

    /** Zwroty (bez odrzuconych), przy których nie zrobiono jeszcze stanu, korekty albo zwrotu pieniędzy. */
    private function openReturns(): ?array
    {
        $rows = (new OrderReturns($this->pdo))->list('open', '', 100);
        return [
            'key' => 'returns', 'title' => 'Zwroty do obsłużenia', 'short' => 'Zwroty',
            'count' => count($rows), 'level' => 'info', 'link' => 'returns.php',
            'details' => array_map(fn($r) => [
                'text' => ($r['reference_number'] ?: 'Zwrot #' . $r['id']) . ' · ' . ($r['buyer'] ?: 'Klient') . ' · ' . OrderReturns::statusLabel((string) $r['status']),
                'time' => '',
                'link' => 'returns.php?id=' . (int) $r['id'],
            ], array_slice($rows, 0, 8)),
        ];
    }

    /**
     * Produkty ze stanem nie większym niż minimum - od najniższego stanu - bez tych, które użytkownik
     * już odczytał. Produkt, który wyszedł z niskiego stanu, wypada z listy odczytanych, więc po
     * kolejnym spadku znowu się pokaże.
     */
    private function lowStock(int $userId): ?array
    {
        $lowSvc = new LowStock($this->pdo);
        $rows = $lowSvc->rows();
        $useActual = $lowSvc->usesActualStock();
        $settings = new SettingsRepository($this->pdo);
        $acked = array_map('intval', json_decode((string) ($settings->get(self::LOW_ACK_KEY . $userId, '[]') ?? '[]'), true) ?: []);
        if ($acked !== []) {
            $lowIds = array_map(static fn($r) => (int) $r['id'], $rows);
            $still = array_values(array_intersect($acked, $lowIds));
            if (count($still) !== count($acked)) {
                $settings->setMany([self::LOW_ACK_KEY . $userId => json_encode($still)]);
            }
            $ackSet = array_flip($still);
            $rows = array_values(array_filter($rows, static fn($r) => !isset($ackSet[(int) $r['id']])));
        }
        $low = ['count' => count($rows), 'rows' => array_slice($rows, 0, 50)];   // lista w dzwoneczku przewija się
        return [
            'key' => 'low_stock', 'title' => 'Niski stan w magazynie', 'short' => 'Niski stan',
            'count' => $low['count'], 'level' => 'info', 'link' => 'products.php?stock_op=low',
            'details' => array_map(fn($r) => [
                'text' => $r['sku'] . ($r['name'] ? ' · ' . mb_strimwidth((string) $r['name'], 0, 40, '…') : '')
                        . ' · stan ' . (int) LowStock::stockFor($r, $useActual) . ($useActual && ($r['actual_stock'] ?? null) !== null ? ' (faktyczny)' : '') . ($r['min_stock'] !== null ? ' / min. ' . (int) $r['min_stock'] : ''),
                'time' => '',
                'link' => 'product_view.php?id=' . (int) $r['id'],
            ], $low['rows']),
        ];
    }

    private function newOrders(int $userId): ?array
    {
        $settings = new SettingsRepository($this->pdo);
        $seen = $settings->get(self::SEEN_KEY . $userId);
        if ($seen === null || $seen === '') {
            // Pierwsze uruchomienie: liczymy od teraz, żeby nie pokazać całej historii jako „nowe".
            $this->markOrdersSeen($userId);
            return null;
        }
        $st = $this->pdo->prepare('SELECT woo_order_id, order_number, customer_name, total, currency, imported_at
                                   FROM woo_orders WHERE imported_at > ? ORDER BY imported_at DESC');
        $st->execute([$seen]);
        $dismissed = array_flip(array_map('intval', json_decode((string) ($settings->get(self::DISMISSED_KEY . $userId, '[]') ?? '[]'), true) ?: []));
        $rows = array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), static fn($r) => !isset($dismissed[(int) $r['woo_order_id']])));
        return [
            'key' => 'new_orders', 'title' => 'Nowe zamówienia', 'count' => count($rows), 'level' => 'ok',
            'link' => 'index.php', 'seen' => true,
            'details' => array_map(fn($r) => [
                'text' => '#' . ($r['order_number'] ?: $r['woo_order_id']) . ' · ' . ($r['customer_name'] ?: 'Klient')
                        . ($r['total'] !== null ? ' · ' . number_format((float) $r['total'], 2, ',', ' ') . ' ' . ($r['currency'] ?: 'PLN') : ''),
                'time' => $this->when($r['imported_at']),
                'link' => 'order_view.php?id=' . (int) $r['woo_order_id'],
                'order' => (int) $r['woo_order_id'],
            ], array_slice($rows, 0, 8)),
        ];
    }

    private function allegroToken(): ?array
    {
        $exp = $this->pdo->query("SELECT expires_at FROM integrations WHERE platform_name = 'allegro' LIMIT 1")->fetchColumn();
        if ($exp === false || $exp === null) {
            return null;   // Allegro niepodłączone - to nie jest alarm
        }
        // Token odświeża się sam; przeterminowany oznacza, że odświeżanie przestało działać.
        if (strtotime($exp . ' UTC') > time()) {
            return null;
        }
        return ['key' => 'allegro_token', 'title' => 'Token Allegro wygasł — połącz konto ponownie',
                'count' => 1, 'level' => 'err', 'link' => 'allegro_connect.php', 'details' => []];
    }

    private function failedJobs(): ?array
    {
        $n = (int) $this->pdo->query("SELECT COUNT(*) FROM job_queue WHERE status = 'failed' AND updated_at > NOW() - INTERVAL 1 DAY")->fetchColumn();
        return ['key' => 'failed_jobs', 'title' => 'Zadania z błędem (ostatnie 24 h)', 'count' => $n, 'level' => 'err',
                'link' => 'queue.php', 'details' => []];
    }

    /** Czas „względny" w polskim czasie. Daty z bazy są w strefie bazy, z API w UTC/ISO. */
    private function when(?string $dt, bool $iso = false): string
    {
        if ($dt === null || $dt === '') {
            return '';
        }
        try {
            $d = $iso ? new \DateTimeImmutable($dt) : new \DateTimeImmutable($dt . ' ' . $this->dbTzOffset());
        } catch (\Throwable) {
            return '';
        }
        $diff = time() - $d->getTimestamp();
        if ($diff < 60) { return 'przed chwilą'; }
        if ($diff < 3600) { return (int) floor($diff / 60) . ' min temu'; }
        if ($diff < 86400) { return (int) floor($diff / 3600) . ' godz. temu'; }
        return $d->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('d.m H:i');
    }

    private ?string $tz = null;

    private function dbTzOffset(): string
    {
        if ($this->tz === null) {
            $s = (int) $this->pdo->query('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())')->fetchColumn();
            $this->tz = sprintf('%s%02d:%02d', $s < 0 ? '-' : '+', intdiv(abs($s), 3600), intdiv(abs($s) % 3600, 60));
        }
        return $this->tz;
    }

    private function dbNow(): string
    {
        return (string) $this->pdo->query('SELECT NOW()')->fetchColumn();
    }
}
