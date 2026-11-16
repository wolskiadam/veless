<?php
declare(strict_types=1);

namespace PaseExt\Kontrola;

use PDO;
use Pase\Plugin\Hooks;
use Pase\Plugin\PluginRegistry;
use Pase\Repository\SettingsRepository;
use Pase\Services\LowStock;
use Pase\Services\OrderItemProducts;
use Pase\Services\OrderMerge;
use Pase\Services\ProcessControl;
use Pase\Services\TaskTimings;
use Pase\Support\HealthBridge;

/**
 * Sprawdzenia stanu systemu. Każde sprawdzenie to osobna definicja (id => grupa, tytuł, run), żeby przebieg w tle
 * mógł je robić po kilka na minutę, a sprawdzenia z połączeniem z zewnątrz (network) nie blokowały workera.
 *
 * Wynik: state ok|warn|bad|skip, summary (jedno zdanie), items (lista rzeczy, których dotyczy, z linkami),
 * count, link (np. do listy zamówień), fix (przycisk „Napraw”, patrz Fixes).
 *
 * Inne rozszerzenia dokładają swoje sprawdzenia filtrem 'health.checks' (np. KSeF).
 */
final class Checks
{
    public const GROUPS = [
        'conn'  => ['Połączenia', 'Czy CRM dogaduje się z Allegro, sklepami, kurierami i księgowością.'],
        'tasks' => ['Zadania w tle', 'Cron, pobieranie zamówień, śledzenie, stany i kolejka zadań.'],
        'data'  => ['Zgodność danych', 'Stany w kanałach sprzedaży i zamówienia, którym czegoś brakuje.'],
        'ship'  => ['Przesyłki', 'Paczki, o których przewoźnik nic nie mówi.'],
    ];

    /** Ile rzeczy pokazać przy jednym sprawdzeniu (reszta to „i N więcej”). */
    public const MAX_ITEMS = 50;

    private const FLOW_NAMES = [
        'orders'   => ['Pobieranie zamówień', 'SYNC_ORDERS_EVERY', 'SYNC_ORDERS_AT'],
        'tracking' => ['Śledzenie przesyłek', 'SYNC_TRACKING_EVERY', 'SYNC_TRACKING_AT'],
        'stock'    => ['Wysyłanie stanów do sklepu', 'SYNC_STOCK_EVERY', 'SYNC_STOCK_AT'],
        'price'    => ['Wysyłanie cen do sklepu', 'SYNC_PRICE_EVERY', 'SYNC_PRICE_AT'],
        'products' => ['Import produktów ze sklepu', 'SYNC_PRODUCTS_EVERY', 'SYNC_PRODUCTS_AT'],
    ];

    private const JOB_NAMES = [
        'woo.order.import'   => 'Import zamówienia ze sklepu',
        'allegro.order.new'  => 'Import zamówienia z Allegro',
        'woo.stock.push'     => 'Wysłanie stanu do sklepu i na Allegro',
        'woo.stock.sync'     => 'Stan ze sklepu na Allegro',
        'woo.product.import' => 'Import produktu ze sklepu',
    ];

    private readonly SettingsRepository $settings;
    /** @var list<array<string,mixed>>|null */
    private ?array $accountsCache = null;
    /** @var list<array<string,mixed>>|null */
    private ?array $ordersCache = null;

    /**
     * @param array<string,mixed> $cfg ustawienia rozszerzenia (stale_days, doc_days, days)
     * @param (\Closure(): ?object)|null $allegro Allegro do odczytu (connected, checkConnection, listOffers); null = HealthBridge
     * @param (\Closure(array): object)|null $woo klient sklepu dla konta (fetchProductsByIds, fetchVariations)
     * @param (\Closure(array): array)|null $tester test połączenia konta integracji -> ['ok' => bool, 'message' => string]
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $cfg = [],
        private readonly ?\Closure $allegro = null,
        private readonly ?\Closure $woo = null,
        private readonly ?\Closure $tester = null,
        private ?int $now = null,
    ) {
        $this->settings = new SettingsRepository($pdo);
    }

    public function setNow(int $now): void
    {
        $this->now = $now;
    }

    private function now(): int
    {
        return $this->now ?? time();
    }

    /**
     * Wszystkie sprawdzenia w kolejności wyświetlania.
     * @return array<string,array{group:string,title:string,run:\Closure,live?:bool,network?:bool}>
     */
    public function definitions(): array
    {
        $d = [];
        $d['conn.allegro'] = ['group' => 'conn', 'title' => 'Allegro', 'network' => true, 'run' => fn(): array => $this->allegroConnection()];
        foreach ($this->accounts() as $acc) {
            if ((string) $acc['type'] === 'allegro') {
                continue;   // token Allegro jest w osobnej tabeli - sprawdza go conn.allegro
            }
            $d['conn.acc.' . (int) $acc['id']] = ['group' => 'conn', 'title' => $this->accountTitle($acc), 'network' => true,
                'run' => fn(): array => $this->accountConnection($acc)];
        }
        $d['tasks.worker'] = ['group' => 'tasks', 'title' => 'Cron (praca w tle)', 'live' => true, 'run' => fn(): array => $this->worker()];
        $d['tasks.flows'] = ['group' => 'tasks', 'title' => 'Zadania cykliczne', 'live' => true, 'run' => fn(): array => $this->flows()];
        $d['tasks.paused'] = ['group' => 'tasks', 'title' => 'Wstrzymane procesy', 'live' => true, 'run' => fn(): array => $this->paused()];
        $d['tasks.queue_failed'] = ['group' => 'tasks', 'title' => 'Zadania z błędem', 'live' => true, 'run' => fn(): array => $this->queueFailed()];
        $d['tasks.queue_stuck'] = ['group' => 'tasks', 'title' => 'Zadania, które czekają za długo', 'live' => true, 'run' => fn(): array => $this->queueStuck()];
        $d['data.stock_allegro'] = ['group' => 'data', 'title' => 'Stany CRM a Allegro', 'network' => true, 'run' => fn(): array => $this->stockAllegro()];
        foreach ($this->accounts() as $acc) {
            if ((string) $acc['type'] === 'woocommerce') {
                $d['data.stock_woo.' . (int) $acc['id']] = ['group' => 'data', 'title' => 'Stany CRM a sklep ' . $acc['name'], 'network' => true,
                    'run' => fn(): array => $this->stockWoo($acc)];
            }
        }
        $d['data.orders_no_product'] = ['group' => 'data', 'title' => 'Zamówienia z pozycją spoza magazynu', 'run' => fn(): array => $this->ordersWithoutProduct()];
        $d['data.shipped_no_tracking'] = ['group' => 'data', 'title' => 'Wysłane bez numeru przesyłki', 'run' => fn(): array => $this->shippedWithoutTracking()];
        $d['data.paid_no_document'] = ['group' => 'data', 'title' => 'Opłacone bez paragonu i faktury', 'run' => fn(): array => $this->paidWithoutDocument()];
        $d['ship.stale'] = ['group' => 'ship', 'title' => 'Paczki bez statusu od przewoźnika', 'run' => fn(): array => $this->staleShipments()];

        // Sprawdzenia innych rozszerzeń: ['id' => ['group' => 'conn', 'title' => ..., 'run' => fn(): array, 'network' => bool]]
        $ext = Hooks::applyFilters('health.checks', []);
        foreach (is_array($ext) ? $ext : [] as $id => $def) {
            if (is_string($id) && preg_match('/^[a-z0-9_.]{3,80}$/', $id) && !isset($d[$id]) && is_array($def)
                && isset(self::GROUPS[$def['group'] ?? '']) && is_callable($def['run'] ?? null)) {
                $d[$id] = ['group' => (string) $def['group'], 'title' => (string) ($def['title'] ?? $id), 'network' => (bool) ($def['network'] ?? false),
                    'run' => \Closure::fromCallable($def['run'])];
            }
        }
        // Kolejność: grupami, w każdej grupie jak wyżej.
        $order = array_flip(array_keys(self::GROUPS));
        uasort($d, static fn(array $a, array $b): int => $order[$a['group']] <=> $order[$b['group']]);
        return $d;
    }

    /** Uruchamia jedno sprawdzenie; wyjątek = żółte światło z opisem (sprawdzenie nie powinno wywracać przebiegu). */
    public function run(string $id, array $def): array
    {
        try {
            $r = ($def['run'])();
            if (!is_array($r) || !in_array($r['state'] ?? '', ['ok', 'warn', 'bad', 'skip'], true)) {
                $r = self::result('warn', 'Sprawdzenie nie oddało wyniku.');
            }
        } catch (\Throwable $e) {
            $r = self::result('warn', 'Nie udało się sprawdzić: ' . mb_substr($e->getMessage(), 0, 300));
        }
        $items = is_array($r['items'] ?? null) ? $r['items'] : [];
        $r['listed'] = count($items);   // ile rzeczy jest na liście (count bywa liczbą zadań, nie wierszy)
        $r['items'] = array_values(array_slice($items, 0, self::MAX_ITEMS));
        return ['id' => $id, 'group' => $def['group'], 'title' => $r['title'] ?? $def['title'], 'state' => $r['state']] + $r;
    }

    /** @param array<string,mixed> $extra items, count, link, fix @return array<string,mixed> */
    public static function result(string $state, string $summary, array $extra = []): array
    {
        return ['state' => $state, 'summary' => $summary] + $extra;
    }

    // ================= Połączenia =================

    private function allegroConnection(): array
    {
        $row = HealthBridge::allegroToken($this->pdo);
        $hasAccount = array_filter($this->accounts(), static fn(array $a): bool => $a['type'] === 'allegro') !== [];
        $link = ['label' => 'Połączenie z Allegro', 'href' => 'allegro_connect.php'];
        if ($row === null || !$row['connected']) {
            return $hasAccount
                ? self::result('bad', 'Konto Allegro nie jest połączone. Zamówienia z Allegro nie przychodzą.', ['link' => $link])
                : self::result('skip', 'Allegro nie jest używane.');
        }
        $exp = self::ts($row['expires_at'] ?? null);
        if ($exp > 0 && $exp < $this->now()) {
            return self::result('bad', 'Token Allegro wygasł ' . self::ago($this->now() - $exp) . ' i nie odnowił się sam. Połącz konto ponownie.', ['link' => $link]);
        }
        $client = $this->allegroClient();
        if ($client === null) {
            return self::result('warn', 'Brak konfiguracji aplikacji Allegro (Client ID) - nie da się sprawdzić połączenia.', ['link' => $link]);
        }
        [$ok, $msg] = $client->checkConnection();
        if (!$ok) {
            return self::result('bad', (string) $msg, ['link' => $link]);
        }
        return self::result('ok', rtrim((string) $msg, '.') . '.' . ($exp > 0 ? ' Token odnawia się sam, obecny jest ważny do ' . self::time($exp) . '.' : ''));
    }

    private function accountConnection(array $acc): array
    {
        $link = ['label' => 'Ustawienia integracji', 'href' => 'integration_edit.php?id=' . (int) $acc['id']];
        $exp = self::ts($acc['expires_at'] ?? null);
        if ($exp > 0 && $exp < $this->now() && empty($acc['refreshable'])) {
            return self::result('bad', 'Token wygasł ' . self::ago($this->now() - $exp) . '. Połącz konto ponownie.', ['link' => $link]);
        }
        $r = $this->tester !== null ? ($this->tester)($acc) : HealthBridge::testAccount($this->pdo, (int) $acc['id']);
        if ($r === null) {
            return self::result('skip', 'Ta integracja nie ma testu połączenia.', ['link' => $link]);
        }
        $msg = trim((string) ($r['message'] ?? ''));
        if (empty($r['ok'])) {
            return self::result('bad', $msg !== '' ? $msg : 'Test połączenia nie przeszedł.', ['link' => $link]);
        }
        return self::result('ok', $msg !== '' ? $msg : 'Połączenie działa.', ['link' => $link]);
    }

    private function accountTitle(array $acc): string
    {
        $name = trim((string) $acc['name']);
        $plugin = PluginRegistry::get((string) $acc['type']);
        $kind = '';
        try {
            $kind = $plugin !== null ? $plugin->manifest()->name : '';
        } catch (\Throwable) {
        }
        return $kind !== '' && mb_stripos($name, $kind) === false ? "{$name} ({$kind})" : ($name !== '' ? $name : ($kind !== '' ? $kind : (string) $acc['type']));
    }

    // ================= Zadania w tle =================

    private function worker(): array
    {
        $at = (int) ($this->settings->get('WORKER_LAST_RUN_AT', '0') ?? '0');
        $link = ['label' => 'Obciążenie serwera', 'href' => 'server_usage.php'];
        if ($at <= 0) {
            return self::result('bad', 'Cron jeszcze ani razu nie uruchomił pracy w tle (cli/worker.php). Bez tego nic nie pobiera się samo.', ['link' => $link]);
        }
        $age = $this->now() - $at;
        if ($age > 600) {
            return self::result('bad', 'Cron nie działa: ostatni przebieg ' . self::ago($age) . '. Zamówienia, stany i śledzenie stoją.', ['link' => $link]);
        }
        if ($age > 180) {
            return self::result('warn', 'Ostatni przebieg crona ' . self::ago($age) . ' (powinien być co minutę).', ['link' => $link]);
        }
        return self::result('ok', 'Cron działa, ostatni przebieg ' . self::ago($age) . '.');
    }

    private function flows(): array
    {
        $control = new ProcessControl($this->settings);
        $items = [];
        $state = 'ok';
        $late = 0;
        foreach (self::FLOW_NAMES as $flow => [$name, $everyKey, $atKey]) {
            $every = (int) ($this->settings->get($everyKey, \Pase\Services\Scheduler::DEFAULT_EVERY[$everyKey] ?? '0') ?? '0');
            $at = (int) ($this->settings->get($atKey, '0') ?? '0');
            if ($every <= 0) {
                $items[] = ['text' => $name, 'note' => 'wyłączone w ustawieniach', 'muted' => true];
                continue;
            }
            if ($control->isPaused($flow)) {
                $items[] = ['text' => $name, 'note' => 'wstrzymane', 'muted' => true];
                continue;
            }
            $age = $at > 0 ? $this->now() - $at : PHP_INT_MAX;
            $limit = max(3 * $every * 60, $every * 60 + 900);
            $note = 'co ' . self::minutes($every) . ', ostatnio ' . ($at > 0 ? self::ago($age) : 'nigdy');
            if ($age > $limit) {
                $late++;
                $bad = $flow === 'orders' || $flow === 'tracking' ? $age > max(6 * $every * 60, 7200) : false;
                $state = $bad ? 'bad' : ($state === 'bad' ? 'bad' : 'warn');
                $items[] = ['text' => $name, 'note' => $note, 'state' => $bad ? 'bad' : 'warn'];
            } else {
                $items[] = ['text' => $name, 'note' => $note, 'state' => 'ok'];
            }
        }
        $tokensAt = (int) ($this->settings->get('TOKENS_REFRESH_AT', '0') ?? '0');
        $tokensEvery = TaskTimings::get($this->settings, 'TOKENS_EVERY');
        if ($tokensAt > 0 && $this->now() - $tokensAt > max(1800, 6 * $tokensEvery * 60)) {
            $late++;
            $state = 'bad';
            $items[] = ['text' => 'Odświeżanie tokenów', 'note' => 'ostatnio ' . self::ago($this->now() - $tokensAt), 'state' => 'bad'];
        }
        $link = ['label' => 'Ustawienia synchronizacji', 'href' => 'sync_settings.php'];
        if ($late === 0) {
            return self::result('ok', 'Wszystkie włączone zadania cykliczne chodzą na czas.', ['items' => $items, 'link' => $link]);
        }
        return self::result($state, $late === 1 ? 'Jedno zadanie cykliczne nie uruchamia się na czas.' : "{$late} zadania cykliczne nie uruchamiają się na czas.",
            ['items' => $items, 'count' => $late, 'link' => $link]);
    }

    private function paused(): array
    {
        $control = new ProcessControl($this->settings);
        $items = [];
        foreach ($control->activePauses($this->now()) as $proc => $until) {
            $name = ProcessControl::PROCESSES[$proc][0] ?? $proc;
            $items[] = ['text' => $name, 'note' => (int) $until >= ProcessControl::INDEFINITE - 86400 ? 'do odwołania' : 'do ' . self::time((int) $until)];
        }
        if ($items === []) {
            return self::result('ok', 'Nic nie jest wstrzymane.');
        }
        return self::result('warn', count($items) === 1 ? 'Jeden proces jest wstrzymany ręcznie.' : count($items) . ' procesy są wstrzymane ręcznie.',
            ['items' => $items, 'count' => count($items), 'link' => ['label' => 'Obciążenie serwera', 'href' => 'server_usage.php']]);
    }

    private function queueFailed(): array
    {
        $days = 7;
        $since = gmdate('Y-m-d H:i:s', $this->now() - $days * 86400);
        $st = $this->pdo->prepare("SELECT job_type, COUNT(*) AS n, MAX(id) AS last_id FROM job_queue WHERE status = 'failed' AND updated_at >= ? GROUP BY job_type ORDER BY n DESC");
        $st->execute([$since]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $total = array_sum(array_map(static fn(array $r): int => (int) $r['n'], $rows));
        $link = ['label' => 'Kolejka zadań', 'href' => 'queue.php?status=failed'];
        if ($total === 0) {
            return self::result('ok', "Brak zadań z błędem w ostatnich {$days} dniach.", ['link' => $link]);
        }
        $items = [];
        $err = $this->pdo->prepare('SELECT last_error FROM job_queue WHERE id = ?');
        foreach ($rows as $r) {
            $err->execute([(int) $r['last_id']]);
            $msg = trim(preg_replace('/\s+/', ' ', (string) $err->fetchColumn()) ?? '');
            $items[] = ['text' => (self::JOB_NAMES[$r['job_type']] ?? $r['job_type']) . ': ' . (int) $r['n'],
                'note' => $msg !== '' ? 'ostatni błąd: ' . mb_substr($msg, 0, 180) : ''];
        }
        return self::result($total >= 20 ? 'bad' : 'warn', self::plural($total, 'zadanie zakończyło się', 'zadania zakończyły się', 'zadań zakończyło się') . " błędem w ostatnich {$days} dniach.",
            ['items' => $items, 'count' => $total, 'link' => $link, 'fix' => ['id' => 'queue.retry', 'label' => 'Ponów zadania z błędem']]);
    }

    private function queueStuck(): array
    {
        $stuckMin = max(10, TaskTimings::get($this->settings, 'QUEUE_STUCK_MINUTES'));
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM job_queue WHERE status = 'reserved' AND reserved_at < ?");
        $st->execute([gmdate('Y-m-d H:i:s', $this->now() - 2 * $stuckMin * 60)]);
        $reserved = (int) $st->fetchColumn();
        $st = $this->pdo->prepare("SELECT COUNT(*) AS n, MIN(available_at) AS oldest FROM job_queue WHERE status = 'pending' AND available_at < ?");
        $st->execute([gmdate('Y-m-d H:i:s', $this->now() - 1800)]);
        $p = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'oldest' => null];
        $pending = (int) $p['n'];
        $oldest = self::ts($p['oldest']) ?: $this->now();
        $link = ['label' => 'Kolejka zadań', 'href' => 'queue.php'];
        if ($reserved === 0 && $pending === 0) {
            return self::result('ok', 'Kolejka zadań na bieżąco.', ['link' => $link]);
        }
        $items = [];
        if ($reserved > 0) {
            $items[] = ['text' => self::plural($reserved, 'zadanie utknęło', 'zadania utknęły', 'zadań utknęło') . ' w trakcie wykonywania', 'note' => 'dłużej niż ' . (2 * $stuckMin) . ' min', 'state' => 'bad'];
        }
        if ($pending > 0) {
            $items[] = ['text' => self::plural($pending, 'zadanie czeka', 'zadania czekają', 'zadań czeka') . ' na wykonanie', 'note' => 'najdłużej od ' . self::ago($this->now() - $oldest, false)];
        }
        $paused = (new ProcessControl($this->settings))->isPaused('queue') || (new ProcessControl($this->settings))->isPaused('worker');
        $bad = $reserved > 0 || $pending > 200 || $this->now() - $oldest > 3 * 3600;
        return self::result($paused ? 'warn' : ($bad ? 'bad' : 'warn'),
            $paused ? 'Kolejka jest wstrzymana, zadania czekają na wznowienie.' : 'Kolejka nie nadąża albo coś w niej utknęło.',
            ['items' => $items, 'count' => $reserved + $pending, 'link' => $link] + ($reserved > 0 ? ['fix' => ['id' => 'queue.unstick', 'label' => 'Odblokuj utknięte zadania']] : []));
    }

    // ================= Zgodność danych =================

    private function stockAllegro(): array
    {
        $client = $this->allegroClient();
        if ($client === null || !$client->connected()) {
            (new Store($this->pdo))->clearStock('allegro');
            return self::result('skip', 'Allegro nie jest połączone.');
        }
        $bySku = $this->productsBySku();
        $mapped = [];
        try {
            foreach ($this->pdo->query("SELECT allegro_offer_id, sku FROM product_mappings WHERE allegro_offer_id IS NOT NULL AND allegro_offer_id <> ''")->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $mapped[trim((string) $m['allegro_offer_id'])] = trim((string) $m['sku']);
            }
        } catch (\PDOException) {
        }
        $busy = $this->pendingStockPush();
        $found = [];
        $offers = 0;
        $linked = 0;
        for ($page = 0; $page < 10; $page++) {
            $r = $client->listOffers(['publication.status' => 'ACTIVE', 'limit' => 1000, 'offset' => $page * 1000]);
            if (empty($r['ok'])) {
                throw new \RuntimeException('Allegro nie oddało listy ofert: ' . ($r['message'] ?? ''));
            }
            foreach ($r['offers'] as $o) {
                $offers++;
                $id = (string) ($o['id'] ?? '');
                $sku = $mapped[$id] ?? trim((string) ($o['external']['id'] ?? ''));
                $p = $sku !== '' ? ($bySku[$sku] ?? null) : null;
                if ($p === null || !isset($o['stock']['available'])) {
                    continue;
                }
                $linked++;
                $remote = (int) $o['stock']['available'];
                if ($remote !== $p['stock'] && !isset($busy[$p['id']])) {
                    $found[] = ['product_id' => $p['id'], 'ref' => $id, 'crm' => $p['stock'], 'remote' => $remote];
                }
            }
            if (count($r['offers']) < 1000 || $offers >= (int) ($r['total'] ?? 0)) {
                break;
            }
        }
        $store = new Store($this->pdo);
        $store->syncStock('allegro', $found, null, $this->now());
        return $this->stockResult($store->stock('allegro'), 'allegro', 'na Allegro', "Sprawdzono {$linked} z {$offers} aktywnych ofert (powiązanych z produktem w CRM).",
            static fn(array $r): string => 'oferta ' . $r['ref']);
    }

    private function stockWoo(array $acc): array
    {
        $accId = (int) $acc['id'];
        $channel = 'woo:' . $accId;
        $store = new Store($this->pdo);
        $single = count(array_filter($this->accounts(), static fn(array $a): bool => $a['type'] === 'woocommerce')) === 1;
        $cursorKey = 'EXT_KONTROLA_WOO_CURSOR_' . $accId;
        $cursor = (int) ($this->settings->get($cursorKey, '0') ?? '0');
        $where = 'woo_product_id IS NOT NULL AND woo_product_id > 0 AND pase_stock IS NOT NULL AND '
            . ($single ? '(source_integration_id = ? OR source_integration_id IS NULL)' : 'source_integration_id = ?');
        $st = $this->pdo->prepare("SELECT id, sku, pase_stock, woo_product_id, woo_variation_id FROM products WHERE {$where} AND id > ? ORDER BY id LIMIT 300");
        $st->execute([$accId, $cursor]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === [] && $cursor > 0) {
            $st->execute([$accId, 0]);   // koniec listy - od początku
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        $last = $cursor;
        if ($rows === []) {
            $store->clearStock($channel);
            return self::result('skip', 'Żaden produkt w CRM nie jest powiązany z tym sklepem.');
        }
        $client = $this->woo !== null ? ($this->woo)($acc) : HealthBridge::woo($this->pdo, $accId);
        // Proste produkty jednym zapytaniem po 100, warianty po rodzicu (najwyżej 15 rodziców na przebieg).
        $simple = [];
        $parents = [];
        foreach ($rows as $r) {
            if ((int) ($r['woo_variation_id'] ?? 0) > 0) {
                if (!isset($parents[(int) $r['woo_product_id']]) && count($parents) >= 15) {
                    break;
                }
                $parents[(int) $r['woo_product_id']][(int) $r['woo_variation_id']] = $r;
            } else {
                $simple[(int) $r['woo_product_id']] = $r;
            }
            $last = (int) $r['id'];
        }
        $remote = [];   // klucz "pid:vid" => stan (null = sklep nie pilnuje stanu)
        foreach (array_chunk(array_keys($simple), 100) as $chunk) {
            foreach ($client->fetchProductsByIds($chunk) as $wp) {
                $remote[(int) $wp['id'] . ':0'] = ($wp['manage_stock'] ?? false) === true && isset($wp['stock_quantity']) ? (int) $wp['stock_quantity'] : null;
            }
        }
        foreach (array_keys($parents) as $pid) {
            for ($page = 1; $page <= 3; $page++) {
                $res = $client->fetchVariations($pid, $page, 100);
                foreach ($res['variations'] ?? [] as $wv) {
                    $remote[$pid . ':' . (int) $wv['id']] = ($wv['manage_stock'] ?? false) === true && isset($wv['stock_quantity']) ? (int) $wv['stock_quantity'] : null;
                }
                if (count($res['variations'] ?? []) < 100) {
                    break;
                }
            }
        }
        $busy = $this->pendingStockPush();
        $found = [];
        $checked = [];
        $missing = 0;
        $check = function (array $r, string $key) use (&$found, &$checked, &$missing, $remote, $busy): void {
            $checked[] = (int) $r['id'];
            if (!array_key_exists($key, $remote)) {
                $missing++;
                return;
            }
            $crm = max(0, (int) $r['pase_stock']);
            if ($remote[$key] !== null && max(0, $remote[$key]) !== $crm && !isset($busy[(int) $r['id']])) {
                $found[] = ['product_id' => (int) $r['id'], 'ref' => $key, 'crm' => $crm, 'remote' => $remote[$key]];
            }
        };
        foreach ($simple as $wpid => $r) {
            $check($r, $wpid . ':0');
        }
        foreach ($parents as $wpid => $vars) {
            foreach ($vars as $vid => $r) {
                $check($r, $wpid . ':' . $vid);
            }
        }
        $this->settings->setMany([$cursorKey => (string) $last]);
        $store->syncStock($channel, $found, $checked, $this->now());
        $cnt = $this->pdo->prepare("SELECT COUNT(*) FROM products WHERE {$where}");
        $cnt->execute([$accId]);
        $total = (int) $cnt->fetchColumn();
        $note = 'W tym przebiegu sprawdzono ' . count($checked) . ' z ' . $total . ' powiązanych produktów' . (count($checked) < $total ? ' (reszta w kolejnych, po kolei)' : '') . '.'
            . ($missing > 0 ? ' ' . self::plural($missing, 'produktu', 'produktów', 'produktów') . ' nie ma już w sklepie.' : '');
        return $this->stockResult($store->stock($channel), $channel, 'w sklepie', $note, static fn(array $r): string => '');
    }

    /** @param list<array<string,mixed>> $rows rozbieżności z ext_kontrola_stock */
    private function stockResult(array $rows, string $channel, string $where, string $note, \Closure $ref): array
    {
        // Potwierdzona = widziana w dwóch przebiegach albo od ponad 20 minut; świeża może wynikać z zamówienia w drodze.
        $cut = gmdate('Y-m-d H:i:s', $this->now() - 1200);
        $confirmed = array_values(array_filter($rows, static fn(array $r): bool => (string) $r['first_seen'] <= $cut || (string) $r['first_seen'] !== (string) $r['last_seen']));
        $fresh = count($rows) - count($confirmed);
        $items = [];
        foreach ($rows as $r) {
            $isNew = !in_array($r, $confirmed, true);
            $sku = trim((string) ($r['sku'] ?? ''));
            $items[] = ['text' => ($sku !== '' ? $sku . ' ' : '') . trim((string) ($r['name'] ?? '')), 'href' => 'product_view.php?id=' . (int) $r['product_id'],
                'note' => 'CRM ' . (int) $r['crm'] . ' szt., ' . $where . ' ' . (int) $r['remote'] . ' szt.' . (($x = $ref($r)) !== '' ? ' · ' . $x : '')
                    . ($isNew ? ' · nowa, potwierdzę przy kolejnym sprawdzeniu' : ' · od ' . self::ago($this->now() - (self::ts($r['first_seen']) ?: $this->now()), false))
                    . (!empty($r['pushed_at']) ? ' · stan wysłany ' . self::ago($this->now() - (self::ts($r['pushed_at']) ?: $this->now())) : ''),
                'state' => $isNew ? 'muted' : 'warn'];
        }
        if ($confirmed === []) {
            return self::result('ok', ($fresh > 0 ? self::plural($fresh, 'nowa różnica czeka', 'nowe różnice czekają', 'nowych różnic czeka')
                . ' na potwierdzenie przy kolejnym sprawdzeniu (to może być zamówienie w drodze). ' : 'Stany się zgadzają. ') . $note,
                ['items' => $items]);
        }
        return self::result('warn', self::plural(count($confirmed), 'produkt ma', 'produkty mają', 'produktów ma') . " {$where} inny stan niż w CRM. {$note}",
            ['items' => $items, 'count' => count($confirmed), 'fix' => ['id' => 'stock.push:' . $channel, 'label' => 'Wyślij stany z CRM']]);
    }

    private function ordersWithoutProduct(): array
    {
        $types = $this->accountTypes();
        $orders = [];
        $skuCache = [];
        foreach ($this->recentOrders() as $row) {
            $crmIds = ($types[(int) ($row['integration_id'] ?? 0)] ?? '') === 'allegro' || \Pase\Services\ManualOrders::isManual((int) $row['woo_order_id']);
            $missing = [];
            foreach (OrderMerge::items($row) as $li) {
                if (($li['in_warehouse'] ?? true) === false || (($li['virtual'] ?? null) === true && trim((string) ($li['sku'] ?? '')) === '')) {
                    continue;
                }
                $key = ($crmIds ? 'c' : 'w') . '|' . ($li['product_id'] ?? '') . '|' . ($li['variation_id'] ?? '') . '|' . ($li['sku'] ?? '');
                $skuCache[$key] ??= OrderItemProducts::match($this->pdo, $li, $crmIds) !== null;
                if (!$skuCache[$key]) {
                    $sku = trim((string) ($li['sku'] ?? ''));
                    $missing[] = ($sku !== '' ? $sku : 'bez SKU') . ' „' . mb_substr(trim((string) ($li['name'] ?? '')), 0, 60) . '”';
                }
            }
            if ($missing !== []) {
                $orders[] = self::orderItem($row, implode(', ', $missing));
            }
        }
        return $this->ordersResult($orders, 'warn', 'Wszystkie pozycje zamówień z ostatnich ' . $this->days() . ' dni mają produkt w magazynie.',
            'spoza magazynu CRM (bez produktu stan się nie zdejmuje).', ['zamówienie ma pozycję', 'zamówienia mają pozycje', 'zamówień ma pozycje']);
    }

    private function shippedWithoutTracking(): array
    {
        $orders = [];
        $sh = $this->pdo->prepare("SELECT COUNT(*) FROM shipments WHERE woo_order_id = ? AND waybill_no IS NOT NULL AND waybill_no <> ''
            AND (status IS NULL OR status NOT IN ('cancelled', 'canceled', 'failed', 'error'))");
        foreach ($this->recentOrders() as $row) {
            if (($row['pase_status'] ?? '') !== 'shipped') {
                continue;
            }
            $payload = self::payload($row);
            if (\Pase\Automation\OrderEvents::isVirtual($row, $payload) || self::isPickup($payload)) {
                continue;
            }
            $sh->execute([(int) $row['woo_order_id']]);
            if ((int) $sh->fetchColumn() === 0) {
                $orders[] = self::orderItem($row, '');
            }
        }
        return $this->ordersResult($orders, 'warn', 'Każde wysłane zamówienie z ostatnich ' . $this->days() . ' dni ma numer przesyłki.',
            'numeru przesyłki w CRM.', ['wysłane zamówienie nie ma', 'wysłane zamówienia nie mają', 'wysłanych zamówień nie ma']);
    }

    private function paidWithoutDocument(): array
    {
        $types = $this->accountTypes();
        $ksef = $this->tableExists('ext_ksef_invoices');
        if (!in_array('wfirma', $types, true) && !$ksef) {
            return self::result('skip', 'Nie ma integracji, która wystawia paragony i faktury (wFirma, KSeF).');
        }
        $docDays = max(1, (int) ($this->cfg['doc_days'] ?? 2));
        $cut = gmdate('Y-m-d H:i:s', $this->now() - $docDays * 86400);
        $doc = $this->pdo->prepare("SELECT COUNT(*) FROM order_documents WHERE woo_order_id = ? AND status = 'issued' AND document_type IN ('normal', 'receipt', 'receipt_fiscal')");
        $ks = $ksef ? $this->pdo->prepare("SELECT COUNT(*) FROM ext_ksef_invoices WHERE woo_order_id = ? AND status NOT IN ('error', 'rejected')") : null;
        $orders = [];
        foreach ($this->recentOrders() as $row) {
            if (in_array($row['pase_status'] ?? '', ['cancelled', 'refunded', 'new'], true) || (string) ($row['date_created'] ?? '') > $cut) {
                continue;
            }
            $payload = self::payload($row);
            if (\Pase\Automation\OrderEvents::paymentState($row, $payload) !== 'paid') {
                continue;
            }
            $doc->execute([(int) $row['woo_order_id']]);
            if ((int) $doc->fetchColumn() > 0) {
                continue;
            }
            if ($ks !== null) {
                $ks->execute([(int) $row['woo_order_id']]);
                if ((int) $ks->fetchColumn() > 0) {
                    continue;
                }
            }
            $orders[] = self::orderItem($row, \Pase\Automation\OrderEvents::wantsInvoice($row, $payload) ? 'klient chce fakturę' : 'paragon');
        }
        return $this->ordersResult($orders, 'warn', "Opłacone zamówienia starsze niż {$docDays} " . ($docDays === 1 ? 'dzień' : 'dni') . ' mają paragon albo fakturę.',
            "paragonu ani faktury od ponad {$docDays} " . ($docDays === 1 ? 'dnia' : 'dni') . '.', ['opłacone zamówienie nie ma', 'opłacone zamówienia nie mają', 'opłaconych zamówień nie ma']);
    }

    /** @param list<array<string,mixed>> $orders @param array{0:string,1:string,2:string} $forms */
    private function ordersResult(array $orders, string $state, string $okText, string $what, array $forms): array
    {
        if ($orders === []) {
            return self::result('ok', $okText);
        }
        $ids = array_slice(array_map(static fn(array $o): int => (int) $o['id'], $orders), 0, 300);
        return self::result($state, self::plural(count($orders), ...$forms) . ' ' . $what, ['items' => $orders, 'count' => count($orders),
            'link' => ['label' => 'Pokaż na liście zamówień', 'href' => 'index.php?ids=' . implode(',', $ids)]]);
    }

    // ================= Przesyłki =================

    private function staleShipments(): array
    {
        $days = max(1, (int) ($this->cfg['stale_days'] ?? 2));
        $st = $this->pdo->prepare("SELECT s.id, s.woo_order_id, s.waybill_no, s.courier_code, s.created_at, o.pase_number, o.order_number, o.customer_name
            FROM shipments s LEFT JOIN woo_orders o ON o.woo_order_id = s.woo_order_id
            WHERE s.status = 'created' AND s.waybill_no IS NOT NULL AND s.waybill_no <> ''
              AND (s.tracking_status IS NULL OR s.tracking_status = '')
              AND s.created_at <= ? AND s.created_at >= ?
            ORDER BY s.created_at DESC LIMIT 500");
        $st->execute([gmdate('Y-m-d H:i:s', $this->now() - $days * 86400), gmdate('Y-m-d H:i:s', $this->now() - $this->days() * 86400)]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return self::result('ok', "Każda paczka nadana ponad {$days} " . ($days === 1 ? 'dzień' : 'dni') . ' temu ma status od przewoźnika.');
        }
        $items = [];
        $ids = [];
        foreach ($rows as $r) {
            $ids[(int) $r['woo_order_id']] = true;
            $num = $r['pase_number'] !== null ? '#' . $r['pase_number'] : ($r['order_number'] !== null ? '#' . $r['order_number'] : 'bez zamówienia');
            $items[] = ['text' => $r['waybill_no'] . ($r['courier_code'] ? ' (' . $r['courier_code'] . ')' : ''),
                'href' => $r['woo_order_id'] !== null ? 'order_view.php?id=' . (int) $r['woo_order_id'] : null,
                'note' => $num . ' · ' . trim((string) $r['customer_name']) . ' · nadana ' . self::ago($this->now() - (self::ts($r['created_at']) ?: $this->now()), true)];
        }
        $tracking = (int) ($this->settings->get('SYNC_TRACKING_EVERY', '60') ?? '60');
        return self::result(count($rows) > 20 ? 'bad' : 'warn', self::plural(count($rows), 'paczka nie ma', 'paczki nie mają', 'paczek nie ma') . " żadnego statusu od przewoźnika od ponad {$days} " . ($days === 1 ? 'dnia' : 'dni') . '.'
            . ($tracking <= 0 ? ' Śledzenie przesyłek jest wyłączone w ustawieniach synchronizacji.' : ''),
            ['items' => $items, 'count' => count($rows), 'link' => ['label' => 'Pokaż zamówienia', 'href' => 'index.php?ids=' . implode(',', array_slice(array_filter(array_keys($ids)), 0, 300))],
             'fix' => ['id' => 'tracking.refresh', 'label' => 'Sprawdź te paczki teraz']]);
    }

    // ================= Pomocnicze =================

    /** Zamówienia z ostatnich N dni, aktywne, bez anulowanych i dołączonych do innych. @return list<array<string,mixed>> */
    private function recentOrders(): array
    {
        if ($this->ordersCache !== null) {
            return $this->ordersCache;
        }
        $where = "lifecycle = 'active' AND date_created >= ? AND (pase_status IS NULL OR pase_status NOT IN ('cancelled', 'refunded'))";
        if (LowStock::columnExists($this->pdo, 'woo_orders', 'merged_into')) {
            $where .= ' AND merged_into IS NULL';
        }
        $st = $this->pdo->prepare("SELECT * FROM woo_orders WHERE {$where} ORDER BY date_created DESC LIMIT 2000");
        $st->execute([gmdate('Y-m-d H:i:s', $this->now() - $this->days() * 86400)]);
        return $this->ordersCache = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function days(): int
    {
        return min(90, max(3, (int) ($this->cfg['days'] ?? 30)));
    }

    private static function orderItem(array $row, string $note): array
    {
        $num = $row['pase_number'] !== null && $row['pase_number'] !== '' ? '#' . $row['pase_number'] : '#' . ($row['order_number'] ?? $row['woo_order_id']);
        $date = (string) ($row['date_created'] ?? '');
        return ['id' => (int) $row['woo_order_id'], 'text' => $num . ' ' . trim((string) ($row['customer_name'] ?? '')),
            'href' => 'order_view.php?id=' . (int) $row['woo_order_id'],
            'note' => trim(($date !== '' ? date('d.m.Y', self::ts($date)) : '') . ($note !== '' ? ' · ' . $note : ''), ' ·')];
    }

    private static function payload(array $row): array
    {
        $p = json_decode((string) ($row['payload'] ?? ''), true);
        return is_array($p) ? $p : [];
    }

    private static function isPickup(array $payload): bool
    {
        $lines = $payload['shipping_lines'] ?? [];
        if (!is_array($lines) || $lines === []) {
            return false;
        }
        foreach ($lines as $line) {
            if (explode(':', (string) ($line['method_id'] ?? ''))[0] !== 'local_pickup') {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,array{id:int,stock:int}> SKU => produkt (tylko z wpisanym stanem) */
    private function productsBySku(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT id, sku, pase_stock FROM products WHERE pase_stock IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $sku = trim((string) $p['sku']);
            if ($sku !== '') {
                $out[$sku] = ['id' => (int) $p['id'], 'stock' => max(0, (int) $p['pase_stock'])];
            }
        }
        return $out;
    }

    /** Produkty, których stan właśnie czeka w kolejce na wysłanie - różnica jest wtedy chwilowa. @return array<int,true> */
    private function pendingStockPush(): array
    {
        $out = [];
        foreach ($this->pdo->query("SELECT payload FROM job_queue WHERE job_type = 'woo.stock.push' AND status IN ('pending', 'reserved') LIMIT 5000")->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $pid = (int) ((json_decode((string) $json, true) ?: [])['product_id'] ?? 0);
            if ($pid > 0) {
                $out[$pid] = true;
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> aktywne konta integracji */
    private function accounts(): array
    {
        if ($this->accountsCache === null) {
            try {
                $this->accountsCache = HealthBridge::accounts($this->pdo);
            } catch (\Throwable) {
                $this->accountsCache = [];
            }
        }
        return $this->accountsCache;
    }

    /** @return array<int,string> id konta => typ */
    private function accountTypes(): array
    {
        $out = [];
        foreach ($this->accounts() as $a) {
            $out[(int) $a['id']] = (string) $a['type'];
        }
        return $out;
    }

    private function tableExists(string $table): bool
    {
        try {
            $this->pdo->query("SELECT 1 FROM {$table} LIMIT 1");
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    private function allegroClient(): ?object
    {
        return $this->allegro !== null ? ($this->allegro)() : HealthBridge::allegro($this->pdo);
    }

    // ================= Tekst =================

    /** Data z bazy (UTC) jako znacznik czasu; pusta = 0. */
    public static function ts(mixed $value): int
    {
        $v = trim((string) $value);
        return $v === '' ? 0 : (strtotime($v . ' UTC') ?: 0);
    }

    public static function ago(int $seconds, bool $suffix = true): string
    {
        $seconds = max(0, $seconds);
        $t = match (true) {
            $seconds < 90 => $seconds < 45 ? 'przed chwilą' : '1 min',
            $seconds < 5400 => (int) round($seconds / 60) . ' min',
            $seconds < 129600 => self::plural((int) round($seconds / 3600), 'godzinę', 'godziny', 'godzin'),
            default => self::plural((int) round($seconds / 86400), 'dzień', 'dni', 'dni'),
        };
        return $t === 'przed chwilą' ? $t : ($suffix ? $t . ' temu' : $t);
    }

    public static function minutes(int $min): string
    {
        return $min % 1440 === 0 ? self::plural(intdiv($min, 1440), 'dzień', 'dni', 'dni') : ($min % 60 === 0 ? self::plural(intdiv($min, 60), 'godzinę', 'godziny', 'godzin') : "{$min} min");
    }

    /** Czas polski (panel pokazuje czas lokalny). */
    public static function time(int $ts): string
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('d.m H:i');
    }

    public static function plural(int $n, string $one, string $few, string $many): string
    {
        if ($n === 1) {
            return "1 {$one}";
        }
        $f = in_array($n % 10, [2, 3, 4], true) && !in_array($n % 100, [12, 13, 14], true);
        return $n . ' ' . ($f ? $few : $many);
    }
}
