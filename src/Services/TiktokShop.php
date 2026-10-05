<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Automation\RuleEngine;
use Pase\Domain\OrderStatus;
use Pase\Plugin\PluginRegistry;
use Pase\Queue\Queue;
use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\SettingsRepository;
use Pase\Repository\WooOrderRepository;
use Pase\Support\Logger;

/**
 * TikTok Shop: połączenie OAuth, import zamówień, stany CRM do TikTok i numery przesyłek do TikTok.
 *
 * Klienta API daje wtyczka integrations/tiktokshop (bez wtyczki nic tu nie działa - available() = false).
 * Worker woła run() co RUN_EVERY_MIN minut, dla każdego aktywnego konta TikTok Shop:
 *
 *  1. Token: odnawiany refresh_tokenem na dobę przed wygaśnięciem (token żyje ok. 7 dni).
 *  2. Zamówienia: zmienione od ostatniego sprawdzenia (update_time) trafiają na wspólną listę zamówień w formacie Woo,
 *     z własnym numerem z zakresu ID_MIN-ID_MAX (Woo ma małe numery, ręczne 8 mld, Allegro 9 mld+). Opłacone zamówienie
 *     zdejmuje stan w CRM raz (pozycje dopasowane po SKU sprzedawcy = SKU w magazynie); anulowane w TikTok oddaje go.
 *     Status CRM ustawiamy przy pierwszym imporcie; później tylko anulowanie w TikTok zmienia „Nowe” na „Anulowane”.
 *  3. Stany: katalog SKU z TikTok odświeżany co CATALOG_EVERY_MIN; SKU sprzedawcy = SKU w CRM dostaje stan CRM
 *     (pase_stock), tylko gdy różni się od ostatnio wysłanego. Produkty bez stanu w CRM (NULL) pomijamy.
 *  4. Przesyłki: zamówienie czekające na wysyłkę (AWAITING_SHIPMENT, wysyłka sprzedawcy) z numerem przesyłki w CRM
 *     oznaczamy w TikTok jako wysłane z tym numerem i przewoźnikiem dopasowanym po nazwie kuriera.
 */
final class TiktokShop
{
    public const TYPE = 'tiktokshop';
    public const ID_MIN = 6_000_000_001;
    public const ID_MAX = 6_999_999_999;
    public const RUN_EVERY_MIN = 5;
    public const CATALOG_EVERY_MIN = 60;
    private const REFRESH_BEFORE = 86400;       // odnów token dobę przed końcem
    private const PAGES_PER_RUN = 10;
    private const STOCK_PUSH_LIMIT = 100;
    private const TRACKING_MAX_ATTEMPTS = 5;

    /** Opłacone i niezakończone anulowaniem - te zdejmują stan w CRM. */
    private const PAID_STATUSES = ['AWAITING_SHIPMENT', 'PARTIALLY_SHIPPING', 'AWAITING_COLLECTION', 'IN_TRANSIT', 'DELIVERED', 'COMPLETED'];

    public const STATUS_LABELS = [
        'UNPAID'              => 'nieopłacone',
        'ON_HOLD'             => 'wstrzymane (okres na anulowanie)',
        'AWAITING_SHIPMENT'   => 'czeka na wysyłkę',
        'PARTIALLY_SHIPPING'  => 'wysłane częściowo',
        'AWAITING_COLLECTION' => 'czeka na odbiór przez kuriera',
        'IN_TRANSIT'          => 'w drodze',
        'DELIVERED'           => 'dostarczone',
        'COMPLETED'           => 'zakończone',
        'CANCELLED'           => 'anulowane',
    ];

    /** Słowa w nazwie kuriera w CRM -> słowa w nazwie przewoźnika w TikTok. */
    private const CARRIERS = [
        'inpost' => ['inpost', 'paczkomat'], 'paczkomat' => ['inpost', 'paczkomat'], 'dpd' => ['dpd'], 'dhl' => ['dhl'],
        'gls' => ['gls'], 'ups' => ['ups'], 'fedex' => ['fedex'], 'poczta' => ['poczta', 'pocztex', 'polish post'],
        'pocztex' => ['pocztex', 'poczta', 'polish post'], 'orlen' => ['orlen'], 'allegro one' => ['allegro'],
    ];

    private SettingsRepository $settings;
    /** @var callable(array,?string,?string):object */
    private $clientFactory;

    /**
     * @param ?callable $clientFactory w testach: fn(array $account, ?string $token, ?string $cipher) => klient z atrapą HTTP
     */
    public function __construct(
        private readonly PDO $pdo,
        ?callable $clientFactory = null,
        private readonly ?Queue $queue = null,
        private readonly ?RuleEngine $ruleEngine = null,
        private readonly ?\Closure $clock = null
    ) {
        $this->settings = new SettingsRepository($pdo);
        $this->clientFactory = $clientFactory ?? static function (array $account, ?string $token, ?string $cipher): object {
            $plugin = PluginRegistry::forAccount(self::TYPE, $account['config'] ?? []);
            if ($plugin === null || !method_exists($plugin, 'client')) {
                throw new \RuntimeException('Wtyczka TikTok Shop nie jest zainstalowana albo jest wyłączona.');
            }
            return $plugin->client($token, $cipher);
        };
    }

    public static function available(): bool
    {
        return PluginRegistry::get(self::TYPE) !== null;
    }

    public static function isTiktok(int $wooOrderId): bool
    {
        return $wooOrderId >= self::ID_MIN && $wooOrderId <= self::ID_MAX;
    }

    public static function migrate(PDO $pdo): void
    {
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pdo->exec('CREATE TABLE IF NOT EXISTS tiktokshop_orders (
            id ' . $pk . ',
            integration_id INT NOT NULL,
            tt_order_id VARCHAR(40) NOT NULL,
            woo_order_id BIGINT NOT NULL,
            tt_status VARCHAR(40) NULL,
            stock_state VARCHAR(12) NULL,
            stock_lines TEXT NULL,
            tracking_state VARCHAR(12) NULL,
            tracking_number VARCHAR(128) NULL,
            tracking_error VARCHAR(500) NULL,
            tracking_attempts INT NOT NULL DEFAULT 0,
            tracking_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE (integration_id, tt_order_id),
            UNIQUE (woo_order_id)
        )' . $tail);
        $pdo->exec('CREATE TABLE IF NOT EXISTS tiktokshop_skus (
            id ' . $pk . ',
            integration_id INT NOT NULL,
            tt_product_id VARCHAR(40) NOT NULL,
            tt_sku_id VARCHAR(40) NOT NULL,
            seller_sku VARCHAR(191) NULL,
            product_title VARCHAR(255) NULL,
            warehouse_id VARCHAR(40) NULL,
            tt_quantity INT NULL,
            pushed_qty INT NULL,
            pushed_at DATETIME NULL,
            error VARCHAR(500) NULL,
            seen_at DATETIME NULL,
            UNIQUE (integration_id, tt_sku_id)
        )' . $tail);
    }

    /** @return array<int,array<string,mixed>> aktywne konta TikTok Shop */
    public function accounts(): array
    {
        return (new IntegrationAccountRepository($this->pdo))->activeByType(self::TYPE);
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }

    private function nowSql(): string
    {
        return date('Y-m-d H:i:s', $this->now());
    }

    // ---------------------------------------------------------------- stan konta (settings)

    public function state(int $accountId, string $key, ?string $default = null): ?string
    {
        return $this->settings->get('TIKTOKSHOP_' . $accountId . '_' . $key, $default);
    }

    private function setState(int $accountId, array $values): void
    {
        $out = [];
        foreach ($values as $k => $v) {
            $out['TIKTOKSHOP_' . $accountId . '_' . $k] = (string) $v;
        }
        $this->settings->setMany($out);
    }

    /** Stan połączenia do strony integracji. @return array<string,mixed> */
    public function status(array $account): array
    {
        $id = (int) $account['id'];
        return [
            'connected'   => trim((string) ($account['access_token'] ?? '')) !== '' && (string) $this->state($id, 'SHOP_CIPHER', '') !== '',
            'shop'        => (string) $this->state($id, 'SHOP_NAME', ''),
            'seller'      => (string) $this->state($id, 'SELLER', ''),
            'expires_at'  => $account['expires_at'] ?? null,
            'last_run'    => (string) $this->state($id, 'LAST_RUN', ''),
            'last_error'  => (string) $this->state($id, 'LAST_ERROR', ''),
            'orders_from' => (int) $this->state($id, 'ORDERS_FROM', '0'),
            'orders'      => (int) $this->scalar('SELECT COUNT(*) FROM tiktokshop_orders WHERE integration_id = ?', [$id]),
            'skus'        => (int) $this->scalar('SELECT COUNT(*) FROM tiktokshop_skus WHERE integration_id = ?', [$id]),
            'skus_linked' => (int) $this->scalar('SELECT COUNT(*) FROM tiktokshop_skus s JOIN products p ON p.sku = s.seller_sku WHERE s.integration_id = ?', [$id]),
            'sku_errors'  => $this->rows('SELECT seller_sku, product_title, error FROM tiktokshop_skus WHERE integration_id = ? AND error IS NOT NULL ORDER BY id LIMIT 20', [$id]),
            'tracking_errors' => $this->rows('SELECT t.woo_order_id, t.tt_order_id, t.tracking_error, w.pase_number FROM tiktokshop_orders t
                LEFT JOIN woo_orders w ON w.woo_order_id = t.woo_order_id
                WHERE t.integration_id = ? AND t.tracking_state = \'error\' ORDER BY t.id DESC LIMIT 20', [$id]),
        ];
    }

    // ---------------------------------------------------------------- OAuth

    /** Adres strony zgody TikTok dla konta; zapamiętuje jednorazowy state. */
    public function connectUrl(array $account): string
    {
        $state = (int) $account['id'] . '.' . bin2hex(random_bytes(16));
        $this->setState((int) $account['id'], ['OAUTH_STATE' => $state . '|' . $this->now()]);
        return ($this->clientFactory)($account, null, null)->authorizeUrl($state);
    }

    /**
     * Powrót ze strony zgody: kod -> token, wybór sklepu (pierwszy z dostępnych).
     * @return array{ok:bool,message:string,account_id:int}
     */
    public function handleCallback(string $code, string $state): array
    {
        $accountId = (int) explode('.', $state, 2)[0];
        $repo = new IntegrationAccountRepository($this->pdo);
        $account = $accountId > 0 ? $repo->find($accountId) : null;
        if ($account === null || ($account['type'] ?? '') !== self::TYPE) {
            return ['ok' => false, 'message' => 'Nieznane konto TikTok Shop w odpowiedzi - zacznij łączenie jeszcze raz.', 'account_id' => 0];
        }
        [$saved, $at] = array_pad(explode('|', (string) $this->state($accountId, 'OAUTH_STATE', '')), 2, '0');
        if ($saved === '' || !hash_equals($saved, $state) || $this->now() - (int) $at > 3600) {
            return ['ok' => false, 'message' => 'Odpowiedź TikTok nie pasuje do rozpoczętego łączenia albo minęła godzina - kliknij „Połącz z TikTok Shop” jeszcze raz.', 'account_id' => $accountId];
        }
        $this->setState($accountId, ['OAUTH_STATE' => '']);
        if (trim($code) === '') {
            return ['ok' => false, 'message' => 'TikTok nie przekazał kodu - zgoda nie została udzielona.', 'account_id' => $accountId];
        }
        try {
            $data = ($this->clientFactory)($account, null, null)->exchangeCode(trim($code));
            $this->saveToken($account, $data);
            $account = $repo->find($accountId) ?? $account;
            $shops = ($this->clientFactory)($account, (string) $data['access_token'], null)->shops();
            if ($shops === []) {
                return ['ok' => false, 'message' => 'Połączono, ale TikTok nie zwrócił żadnego sklepu dla tego konta.', 'account_id' => $accountId];
            }
            $shop = $shops[0];
            $this->setState($accountId, [
                'SHOP_CIPHER' => (string) ($shop['cipher'] ?? ''),
                'SHOP_ID'     => (string) ($shop['id'] ?? ''),
                'SHOP_NAME'   => trim((string) ($shop['name'] ?? '') . ' (' . (string) ($shop['region'] ?? '') . ')'),
                'SELLER'      => (string) ($data['seller_name'] ?? ''),
                'LAST_ERROR'  => '',
            ]);
            $more = count($shops) > 1 ? ' Konto ma ' . count($shops) . ' sklepy - użyto pierwszego; dla kolejnego dodaj osobną integrację i połącz ją z innym kontem sprzedawcy.' : '';
            return ['ok' => true, 'message' => 'Połączono ze sklepem TikTok Shop „' . ($shop['name'] ?? '') . '”.' . $more, 'account_id' => $accountId];
        } catch (\Throwable $e) {
            Logger::warn('TikTok Shop: łączenie konta #' . $accountId . ' nie powiodło się: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Nie udało się połączyć: ' . $e->getMessage(), 'account_id' => $accountId];
        }
    }

    private function saveToken(array $account, array $data): void
    {
        $exp = (int) ($data['access_token_expire_in'] ?? 0);
        // TikTok podaje moment wygaśnięcia (unix); gdyby przyszła długość ważności w sekundach - licz od teraz.
        if ($exp > 0 && $exp < 1_000_000_000) {
            $exp += $this->now();
        }
        if ($exp <= 0) {
            $exp = $this->now() + 6 * 86400;
        }
        (new IntegrationAccountRepository($this->pdo))->saveTokens(
            (int) $account['id'],
            (string) $data['access_token'],
            isset($data['refresh_token']) ? (string) $data['refresh_token'] : ($account['refresh_token'] ?? null),
            (new \DateTimeImmutable('@' . $exp))->setTimezone(new \DateTimeZone(date_default_timezone_get()))
        );
    }

    /** Klient z ważnym tokenem i sklepem (odnawia token, gdy trzeba). */
    public function client(array $account): object
    {
        $id = (int) $account['id'];
        $token = trim((string) ($account['access_token'] ?? ''));
        if ($token === '') {
            throw new \RuntimeException('Konto TikTok Shop nie jest połączone - kliknij „Połącz z TikTok Shop” na stronie integracji.');
        }
        $expires = !empty($account['expires_at']) ? (int) strtotime((string) $account['expires_at']) : 0;
        if ($expires > 0 && $expires - $this->now() < self::REFRESH_BEFORE) {
            $refresh = trim((string) ($account['refresh_token'] ?? ''));
            if ($refresh === '') {
                throw new \RuntimeException('Token TikTok Shop wygasa, a brak refresh tokenu - połącz konto jeszcze raz.');
            }
            $data = ($this->clientFactory)($account, null, null)->refreshToken($refresh);
            $this->saveToken($account, $data);
            $token = (string) $data['access_token'];
            Logger::info('TikTok Shop: odnowiono token konta #' . $id);
        }
        return ($this->clientFactory)($account, $token, (string) $this->state($id, 'SHOP_CIPHER', ''));
    }

    /** Test połączenia: lista sklepów z tokenem. @return array{ok:bool,message:string} */
    public function test(array $account): array
    {
        try {
            $shops = $this->client($account)->shops();
            $names = array_map(static fn(array $s): string => (string) ($s['name'] ?? '?'), $shops);
            return ['ok' => true, 'message' => 'Połączenie OK. Sklepy: ' . ($names !== [] ? implode(', ', $names) : 'brak') . '.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    // ---------------------------------------------------------------- cykl workera

    /** Wszystkie konta: token, zamówienia, stany, przesyłki. Błąd jednego kroku nie zatrzymuje reszty. */
    public function run(): array
    {
        $out = ['orders' => 0, 'stock' => 0, 'tracking' => 0];
        foreach ($this->accounts() as $account) {
            if (trim((string) ($account['access_token'] ?? '')) === '') {
                continue;
            }
            $id = (int) $account['id'];
            $errors = [];
            try {
                $client = $this->client($account);
            } catch (\Throwable $e) {
                $this->setState($id, ['LAST_RUN' => $this->nowSql(), 'LAST_ERROR' => mb_substr('Token: ' . $e->getMessage(), 0, 500)]);
                Logger::warn('TikTok Shop #' . $id . ': ' . $e->getMessage());
                continue;
            }
            foreach (['orders' => 'syncOrders', 'stock' => 'pushStock', 'tracking' => 'pushTracking'] as $key => $step) {
                try {
                    $out[$key] += $this->{$step}($account, $client);
                } catch (\Throwable $e) {
                    $errors[] = ['orders' => 'Zamówienia', 'stock' => 'Stany', 'tracking' => 'Przesyłki'][$key] . ': ' . $e->getMessage();
                    Logger::warn('TikTok Shop #' . $id . ' [' . $key . ']: ' . $e->getMessage());
                }
            }
            $this->setState($id, ['LAST_RUN' => $this->nowSql(), 'LAST_ERROR' => mb_substr(implode(' | ', $errors), 0, 500)]);
        }
        return $out;
    }

    // ---------------------------------------------------------------- zamówienia

    /** Pobiera zamówienia zmienione od ostatniego sprawdzenia. @return int liczba zapisanych */
    public function syncOrders(array $account, object $client): int
    {
        $id = (int) $account['id'];
        $from = (int) $this->state($id, 'ORDERS_FROM', '0');
        if ($from <= 0) {
            $days = max(1, min(60, (int) ($account['config']['import_days'] ?? 7) ?: 7));
            $from = $this->now() - $days * 86400;
        }
        $maxSeen = $from;
        $n = 0;
        $token = '';
        for ($page = 0; $page < self::PAGES_PER_RUN; $page++) {
            $res = $client->searchOrders($from, $token);
            foreach ($res['orders'] as $order) {
                if (!is_array($order) || empty($order['id'])) {
                    continue;
                }
                $this->importOrder($account, $order);
                $maxSeen = max($maxSeen, (int) ($order['update_time'] ?? 0));
                $n++;
            }
            $token = $res['next'];
            if ($token === '' || $res['orders'] === []) {
                break;
            }
        }
        // update_time_ge obejmuje granicę - ostatnie zamówienie wróci raz jeszcze, zapis jest idempotentny.
        $this->setState($id, ['ORDERS_FROM' => (string) $maxSeen]);
        return $n;
    }

    /** Zapis jednego zamówienia TikTok (nowe albo aktualizacja). @return int woo_order_id w CRM */
    public function importOrder(array $account, array $order): int
    {
        $accountId = (int) $account['id'];
        $ttId = (string) $order['id'];
        $ttStatus = strtoupper((string) ($order['status'] ?? ''));
        $link = $this->link($accountId, $ttId);

        // Dopasowanie pozycji do magazynu po SKU sprzedawcy.
        $skus = array_values(array_unique(array_filter(array_map(static fn($li): string => trim((string) ($li['seller_sku'] ?? '')), (array) ($order['line_items'] ?? [])))));
        $products = [];
        if ($skus !== []) {
            $st = $this->pdo->prepare('SELECT id, sku FROM products WHERE sku IN (' . implode(',', array_fill(0, count($skus), '?')) . ')');
            $st->execute($skus);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $products[(string) $p['sku']] = (int) $p['id'];
            }
        }
        $payload = self::toWoo($order, $products);
        $fields = [
            'status'         => $ttStatus,
            'currency'       => $payload['currency'],
            'total'          => (float) $payload['total'],
            'customer_name'  => trim(($payload['billing']['first_name'] ?? '') . ' ' . ($payload['billing']['last_name'] ?? '')) ?: null,
            'customer_email' => ($payload['billing']['email'] ?? '') !== '' ? $payload['billing']['email'] : null,
        ];

        $orders = new WooOrderRepository($this->pdo);
        $wasPaid = $link !== null && $this->ruleEngine !== null
            ? \Pase\Automation\OrderEvents::isPaidNow($this->pdo, (int) $link['woo_order_id']) : null;
        if ($link === null) {
            $wooId = $this->insertOrder($accountId, $ttId, $ttStatus, $fields, $payload);
            $link = $this->link($accountId, $ttId);
            Logger::info("TikTok Shop: nowe zamówienie {$ttId} -> #{$wooId}", ['status' => $ttStatus, 'total' => $fields['total']]);
        } else {
            $wooId = (int) $link['woo_order_id'];
            $this->pdo->prepare('UPDATE woo_orders SET status = ?, currency = ?, total = ?, customer_name = ?, customer_email = ?, payload = ? WHERE woo_order_id = ?')
                ->execute([$fields['status'], $fields['currency'], $fields['total'], $fields['customer_name'], $fields['customer_email'],
                    json_encode($payload, JSON_UNESCAPED_UNICODE), $wooId]);
            // Anulowanie w TikTok zmienia status CRM tylko, gdy nikt jeszcze nie ruszył zamówienia.
            if ($ttStatus === 'CANCELLED') {
                $this->pdo->prepare('UPDATE woo_orders SET pase_status = ? WHERE woo_order_id = ? AND pase_status = ?')
                    ->execute([OrderStatus::CANCELLED, $wooId, OrderStatus::NEW]);
            }
        }
        // Same produkty wirtualne -> „Zamówienie wirtualne — bez wysyłki” (bez nadpisywania wyboru z karty).
        (new VirtualProducts($this->pdo))->applyToOrder($wooId, (array) ($payload['line_items'] ?? []));
        $this->pdo->prepare('UPDATE tiktokshop_orders SET tt_status = ?, updated_at = ? WHERE id = ?')->execute([$ttStatus, $this->nowSql(), (int) $link['id']]);

        // Magazyn: opłacone zdejmuje stan raz, anulowane po zdjęciu oddaje.
        if (in_array($ttStatus, self::PAID_STATUSES, true) && ($link['stock_state'] ?? null) === null) {
            $this->takeStock((int) $link['id'], $wooId, $payload['line_items']);
        } elseif ($ttStatus === 'CANCELLED' && ($link['stock_state'] ?? null) === 'taken') {
            $this->returnStock((int) $link['id'], $wooId, (string) ($link['stock_lines'] ?? '[]'));
        }

        // Automatyzacje („Pobrano zamówienie”) - raz, gdy zamówienie jest opłacone (np. faktura nie powstanie dla nieopłaconego).
        if ($this->ruleEngine !== null && in_array($ttStatus, self::PAID_STATUSES, true) && !$orders->automationsDone($wooId)) {
            try {
                $this->ruleEngine->dispatch('order.imported', $wooId, $payload);
                $orders->markAutomationsDone($wooId);
            } catch (\Throwable $e) {
                Logger::error("TikTok Shop: automatyzacje zamówienia #{$wooId} nie zadziałały", ['error' => $e->getMessage()]);
            }
        }
        if ($this->ruleEngine !== null) {
            \Pase\Automation\OrderEvents::paidTransition($this->pdo, $wooId, $wasPaid, $this->ruleEngine);
        }
        return $wooId;
    }

    private function link(int $accountId, string $ttId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM tiktokshop_orders WHERE integration_id = ? AND tt_order_id = ?');
        $st->execute([$accountId, $ttId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Nowy wiersz woo_orders z numerem z zakresu TikTok (przy równoległym zapisie - kolejny numer). */
    private function insertOrder(int $accountId, string $ttId, string $ttStatus, array $fields, array $payload): int
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $st = $this->pdo->prepare('SELECT MAX(woo_order_id) FROM woo_orders WHERE woo_order_id BETWEEN ? AND ?');
            $st->execute([self::ID_MIN, self::ID_MAX]);
            $id = max(self::ID_MIN, (int) $st->fetchColumn() + 1) + $attempt;
            try {
                $this->pdo->beginTransaction();
                $this->pdo->prepare('INSERT INTO woo_orders (woo_order_id, integration_id, order_number, status, pase_status, currency, total,
                        customer_name, customer_email, date_created, client_token, payload)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                    $id, $accountId, $ttId, $ttStatus, self::paseStatus($ttStatus), $fields['currency'], $fields['total'],
                    $fields['customer_name'], $fields['customer_email'], $payload['date_created'], bin2hex(random_bytes(24)),
                    json_encode($payload, JSON_UNESCAPED_UNICODE),
                ]);
                $this->pdo->prepare('INSERT INTO tiktokshop_orders (integration_id, tt_order_id, woo_order_id, tt_status, updated_at) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$accountId, $ttId, $id, $ttStatus, $this->nowSql()]);
                $this->pdo->commit();
            } catch (\PDOException $e) {
                $this->pdo->rollBack();
                if ($this->link($accountId, $ttId) !== null) {
                    return (int) $this->link($accountId, $ttId)['woo_order_id'];   // równoległy przebieg już zapisał
                }
                if (!preg_match('/Duplicate|UNIQUE|PRIMARY/i', $e->getMessage())) {
                    throw $e;
                }
                continue;
            }
            (new WooOrderRepository($this->pdo))->assignNumberIfMissing($id);
            return $id;
        }
        throw new \RuntimeException('Nie udało się nadać numeru zamówieniu TikTok ' . $ttId);
    }

    /** Status CRM przy pierwszym imporcie. */
    public static function paseStatus(string $ttStatus): string
    {
        return match ($ttStatus) {
            'AWAITING_COLLECTION', 'PARTIALLY_SHIPPING' => OrderStatus::PROCESSING,
            'IN_TRANSIT', 'DELIVERED', 'COMPLETED' => OrderStatus::SHIPPED,
            'CANCELLED' => OrderStatus::CANCELLED,
            default => OrderStatus::NEW,
        };
    }

    /**
     * Zamówienie TikTok w formacie Woo (strona zamówienia, faktura, wydruki, klienci). Surowe dane zostają pod 'tiktok'.
     * TikTok podaje pozycje po jednej sztuce - łączymy je po SKU TikTok w pozycje z ilością.
     * @param array<string,int> $products SKU w CRM => id produktu
     */
    public static function toWoo(array $order, array $products = []): array
    {
        $addr = is_array($order['recipient_address'] ?? null) ? $order['recipient_address'] : [];
        $first = trim((string) ($addr['first_name'] ?? ''));
        $last = trim((string) ($addr['last_name'] ?? ''));
        if ($first === '' && $last === '') {
            $parts = preg_split('/\s+/u', trim((string) ($addr['name'] ?? '')), 2) ?: [''];
            [$first, $last] = [$parts[0] ?? '', $parts[1] ?? ''];
        }
        $city = '';
        $country = strtoupper((string) ($addr['region_code'] ?? ''));
        foreach ((array) ($addr['district_info'] ?? []) as $d) {
            $lvl = strtolower((string) ($d['address_level_name'] ?? ''));
            if (in_array($lvl, ['city', 'town', 'post town', 'district'], true) && $city === '') {
                $city = (string) ($d['address_name'] ?? '');
            }
        }
        $lines = array_values(array_filter(array_map(static fn($k): string => trim((string) ($addr[$k] ?? '')), ['address_line1', 'address_line2', 'address_line3', 'address_line4'])));
        if ($lines === [] && trim((string) ($addr['address_detail'] ?? '')) !== '') {
            $lines = [trim((string) $addr['address_detail'])];
        }
        $person = [
            'first_name' => $first,
            'last_name'  => $last,
            'company'    => '',
            'address_1'  => $lines[0] ?? '',
            'address_2'  => implode(', ', array_slice($lines, 1)),
            'city'       => $city,
            'postcode'   => (string) ($addr['postal_code'] ?? ''),
            'country'    => $country,
            'phone'      => (string) ($addr['phone_number'] ?? ''),
        ];

        $grouped = [];
        foreach ((array) ($order['line_items'] ?? []) as $li) {
            if (!is_array($li)) {
                continue;
            }
            if (in_array(strtoupper((string) ($li['display_status'] ?? '')), ['CANCELLED'], true) && strtoupper((string) ($order['status'] ?? '')) !== 'CANCELLED') {
                continue;   // pozycja anulowana w niezanulowanym zamówieniu
            }
            $key = (string) ($li['sku_id'] ?? $li['id'] ?? count($grouped));
            $price = (float) ($li['sale_price'] ?? $li['original_price'] ?? 0);
            if (!isset($grouped[$key])) {
                $sku = trim((string) ($li['seller_sku'] ?? ''));
                $name = trim((string) ($li['product_name'] ?? 'Pozycja TikTok Shop'));
                $variant = trim((string) ($li['sku_name'] ?? ''));
                if ($variant !== '' && strcasecmp($variant, 'Default') !== 0) {
                    $name .= ' — ' . $variant;
                }
                $grouped[$key] = [
                    'id'              => (string) ($li['id'] ?? $key),
                    'product_id'      => $sku !== '' ? (int) ($products[$sku] ?? 0) : 0,
                    'name'            => $name,
                    'sku'             => $sku !== '' ? $sku : null,
                    'quantity'        => 0,
                    // Ceny brutto (jak Allegro): bez total_tax faktura liczy brutto = total.
                    'price'           => $price,
                    'total'           => 0.0,
                    'tiktok_sku_id'   => (string) ($li['sku_id'] ?? ''),
                    'tiktok_line_ids' => [],
                    'in_warehouse'    => $sku !== '' && !empty($products[$sku]),
                ];
            }
            $grouped[$key]['quantity']++;
            $grouped[$key]['total'] += $price;
            $grouped[$key]['tiktok_line_ids'][] = (string) ($li['id'] ?? '');
        }
        $items = [];
        foreach ($grouped as $it) {
            $it['total'] = number_format(round((float) $it['total'], 2), 2, '.', '');
            $items[] = $it;
        }

        $pay = is_array($order['payment'] ?? null) ? $order['payment'] : [];
        $shipFee = (float) ($pay['shipping_fee'] ?? 0);
        $itemsSum = array_sum(array_map(static fn(array $i): float => (float) $i['total'], $items));
        $total = isset($pay['total_amount']) ? (float) $pay['total_amount'] : $itemsSum + $shipFee;
        $created = (int) ($order['create_time'] ?? 0);
        $paid = (int) ($order['paid_time'] ?? 0);
        $method = trim((string) ($order['payment_method_name'] ?? ''));

        return [
            'id'                   => (string) $order['id'],
            'number'               => (string) $order['id'],
            'status'               => strtoupper((string) ($order['status'] ?? '')),
            'created_via'          => 'tiktokshop',
            'date_created'         => $created > 0 ? gmdate('Y-m-d H:i:s', $created) : null,
            'date_paid'            => $paid > 0 ? gmdate('Y-m-d H:i:s', $paid) : null,
            'currency'             => (string) ($pay['currency'] ?? 'PLN'),
            'total'                => number_format($total, 2, '.', ''),
            'shipping_total'       => number_format($shipFee, 2, '.', ''),
            'billing'              => $person + ['email' => (string) ($order['buyer_email'] ?? '')],
            'shipping'             => $person,
            'line_items'           => $items,
            'shipping_lines'       => [[
                'method_title' => trim((string) ($order['delivery_option_name'] ?? '')) ?: 'Dostawa TikTok Shop',
                'total'        => number_format($shipFee, 2, '.', ''),
            ]],
            'payment_method'       => 'tiktokshop',
            'payment_method_title' => 'TikTok Shop' . ($method !== '' ? ' — ' . $method : ''),
            'customer_note'        => (string) ($order['buyer_message'] ?? ''),
            'meta_data'            => [],
            'tiktok'               => $order,
        ];
    }

    /** Zdejmuje sprzedane sztuki ze stanu CRM (i stanu faktycznego) i kolejkuje wysłanie stanu do sklepu. */
    private function takeStock(int $linkId, int $wooId, array $items): void
    {
        $lines = [];
        foreach ($items as $it) {
            if (!empty($it['product_id']) && (int) $it['quantity'] > 0) {
                $lines[(string) $it['product_id']] = ($lines[(string) $it['product_id']] ?? 0) + (int) $it['quantity'];
            }
        }
        $this->atomic(function () use ($linkId, $lines): void {
            // Znacznik najpierw: drugi przebieg nie zdejmie stanu jeszcze raz.
            $claim = $this->pdo->prepare("UPDATE tiktokshop_orders SET stock_state = 'taken', stock_lines = ? WHERE id = ? AND stock_state IS NULL");
            $claim->execute([json_encode($lines), $linkId]);
            if ($claim->rowCount() !== 1) {
                return;
            }
            $upd = $this->pdo->prepare('UPDATE products SET
                    pase_stock = CASE WHEN COALESCE(pase_stock, 0) > ? THEN COALESCE(pase_stock, 0) - ? ELSE 0 END,
                    actual_stock = CASE WHEN actual_stock IS NULL THEN NULL WHEN actual_stock > ? THEN actual_stock - ? ELSE 0 END
                WHERE id = ?');
            foreach ($lines as $pid => $qty) {
                foreach ([$qty, $qty, $qty, $qty, (int) $pid] as $n => $val) {
                    $upd->bindValue($n + 1, $val, PDO::PARAM_INT);
                }
                $upd->execute();
            }
        });
        $this->queueShopPush($wooId, array_keys($lines), 'tiktok');
    }

    /** Oddaje stan zdjęty przy imporcie (zamówienie anulowane w TikTok). */
    private function returnStock(int $linkId, int $wooId, string $linesJson): void
    {
        $lines = json_decode($linesJson, true) ?: [];
        $this->atomic(function () use ($linkId, $lines): void {
            $claim = $this->pdo->prepare("UPDATE tiktokshop_orders SET stock_state = 'returned' WHERE id = ? AND stock_state = 'taken'");
            $claim->execute([$linkId]);
            if ($claim->rowCount() !== 1) {
                return;
            }
            $upd = $this->pdo->prepare('UPDATE products SET pase_stock = COALESCE(pase_stock, 0) + ?,
                    actual_stock = CASE WHEN actual_stock IS NULL THEN NULL ELSE actual_stock + ? END WHERE id = ?');
            foreach ($lines as $pid => $qty) {
                foreach ([(int) $qty, (int) $qty, (int) $pid] as $n => $val) {
                    $upd->bindValue($n + 1, $val, PDO::PARAM_INT);
                }
                $upd->execute();
            }
        });
        Logger::info("TikTok Shop: zamówienie #{$wooId} anulowane w TikTok - oddano stan", ['produkty' => $lines]);
        $this->queueShopPush($wooId, array_keys($lines), 'tiktok-cancel');
    }

    private function queueShopPush(int $wooId, array $productIds, string $why): void
    {
        foreach ($productIds as $pid) {
            try {
                $this->queue?->enqueue('woo.stock.push', ['product_id' => (int) $pid], 'woo.stock.push:' . $pid . ':' . $why . ':' . $wooId);
            } catch (\Throwable $e) {
                Logger::warn("TikTok Shop #{$wooId}: nie zakolejkowano stanu produktu {$pid} do sklepu: " . $e->getMessage());
            }
        }
    }

    private function atomic(callable $fn): void
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $fn();
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    // ---------------------------------------------------------------- stany

    /** Odświeża katalog SKU z TikTok (co CATALOG_EVERY_MIN albo na żądanie). @return int liczba SKU */
    public function refreshCatalog(array $account, object $client, bool $force = false): int
    {
        $id = (int) $account['id'];
        if (!$force && $this->now() - (int) $this->state($id, 'CATALOG_AT', '0') < self::CATALOG_EVERY_MIN * 60) {
            return -1;
        }
        $seen = $this->nowSql();
        $n = 0;
        $token = '';
        $find = $this->pdo->prepare('SELECT id FROM tiktokshop_skus WHERE integration_id = ? AND tt_sku_id = ?');
        $ins = $this->pdo->prepare('INSERT INTO tiktokshop_skus (integration_id, tt_product_id, tt_sku_id, seller_sku, product_title, warehouse_id, tt_quantity, seen_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $upd = $this->pdo->prepare('UPDATE tiktokshop_skus SET tt_product_id = ?, seller_sku = ?, product_title = ?, warehouse_id = ?, tt_quantity = ?, seen_at = ? WHERE id = ?');
        for ($page = 0; $page < 50; $page++) {
            $res = $client->searchProducts($token);
            foreach ($res['products'] as $p) {
                foreach ((array) ($p['skus'] ?? []) as $sku) {
                    if (!is_array($sku) || empty($sku['id'])) {
                        continue;
                    }
                    $inv = is_array($sku['inventory'][0] ?? null) ? $sku['inventory'][0] : [];
                    $vals = [(string) ($p['id'] ?? ''), trim((string) ($sku['seller_sku'] ?? '')) ?: null, mb_substr((string) ($p['title'] ?? ''), 0, 255),
                        (string) ($inv['warehouse_id'] ?? ''), isset($inv['quantity']) ? (int) $inv['quantity'] : null, $seen];
                    $find->execute([$id, (string) $sku['id']]);
                    $rowId = $find->fetchColumn();
                    if ($rowId !== false) {
                        $upd->execute([...$vals, (int) $rowId]);
                    } else {
                        $ins->execute([$id, $vals[0], (string) $sku['id'], ...array_slice($vals, 1)]);
                    }
                    $n++;
                }
            }
            $token = $res['next'];
            if ($token === '' || $res['products'] === []) {
                break;
            }
        }
        // SKU usunięte w TikTok - nie ma czego aktualizować.
        $this->pdo->prepare('DELETE FROM tiktokshop_skus WHERE integration_id = ? AND (seen_at IS NULL OR seen_at < ?)')->execute([$id, $seen]);
        $this->setState($id, ['CATALOG_AT' => (string) $this->now()]);
        return $n;
    }

    /** Wysyła stan CRM do SKU TikTok, które go nie mają. @return int liczba wysłanych */
    public function pushStock(array $account, object $client): int
    {
        if (($account['config']['push_stock'] ?? 'on') === 'off') {
            return 0;
        }
        $id = (int) $account['id'];
        $this->refreshCatalog($account, $client);
        $st = $this->pdo->prepare('SELECT s.id, s.tt_product_id, s.tt_sku_id, s.warehouse_id, s.seller_sku, p.pase_stock
            FROM tiktokshop_skus s JOIN products p ON p.sku = s.seller_sku
            WHERE s.integration_id = ? AND p.pase_stock IS NOT NULL
              AND (s.pushed_qty IS NULL OR s.pushed_qty <> (CASE WHEN p.pase_stock < 0 THEN 0 ELSE p.pase_stock END))
            ORDER BY s.id LIMIT ' . self::STOCK_PUSH_LIMIT);
        $st->execute([$id]);
        $ok = $this->pdo->prepare('UPDATE tiktokshop_skus SET pushed_qty = ?, tt_quantity = ?, pushed_at = ?, error = NULL WHERE id = ?');
        $bad = $this->pdo->prepare('UPDATE tiktokshop_skus SET error = ? WHERE id = ?');
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $qty = max(0, (int) $r['pase_stock']);
            try {
                $client->updateInventory((string) $r['tt_product_id'], (string) $r['tt_sku_id'], (string) $r['warehouse_id'], $qty);
                $ok->execute([$qty, $qty, $this->nowSql(), (int) $r['id']]);
                $n++;
            } catch (\Throwable $e) {
                // Zapamiętany błąd pokazuje strona integracji; kolejna próba przy następnym przebiegu.
                $bad->execute([mb_substr($e->getMessage(), 0, 500), (int) $r['id']]);
                Logger::warn('TikTok Shop: stan SKU ' . $r['seller_sku'] . ' nie wysłany: ' . $e->getMessage());
            }
        }
        return $n;
    }

    // ---------------------------------------------------------------- przesyłki

    /** Numery przesyłek z CRM do zamówień czekających w TikTok na wysyłkę. @return int liczba wysłanych */
    public function pushTracking(array $account, object $client): int
    {
        if (($account['config']['push_tracking'] ?? 'on') === 'off') {
            return 0;
        }
        $id = (int) $account['id'];
        $st = $this->pdo->prepare("SELECT t.*, w.payload FROM tiktokshop_orders t JOIN woo_orders w ON w.woo_order_id = t.woo_order_id
            WHERE t.integration_id = ? AND t.tt_status = 'AWAITING_SHIPMENT'
              AND (t.tracking_state IS NULL OR (t.tracking_state = 'error' AND t.tracking_attempts < " . self::TRACKING_MAX_ATTEMPTS . '))
            ORDER BY t.id LIMIT 50');
        $st->execute([$id]);
        $shipQ = $this->pdo->prepare("SELECT waybill_no, courier_code FROM shipments WHERE woo_order_id = ? AND waybill_no IS NOT NULL AND waybill_no <> ''
            AND (status IS NULL OR status NOT IN ('cancelled', 'error', 'pending')) ORDER BY id DESC LIMIT 1");
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $shipQ->execute([(int) $t['woo_order_id']]);
            $sh = $shipQ->fetch(PDO::FETCH_ASSOC);
            if (!$sh) {
                continue;   // jeszcze bez numeru przesyłki
            }
            $order = (json_decode((string) $t['payload'], true) ?: [])['tiktok'] ?? [];
            if (strtoupper((string) ($order['shipping_type'] ?? 'SELLER')) === 'TIKTOK') {
                $this->trackingResult((int) $t['id'], 'skipped', (string) $sh['waybill_no'], 'Wysyłka przez TikTok (etykieta TikTok) - numer nadaje TikTok, CRM go nie wysyła.');
                continue;
            }
            try {
                $providers = $client->shippingProviders((string) ($order['delivery_option_id'] ?? ''));
                $provider = self::matchProvider((string) $sh['courier_code'], $providers);
                if ($provider === null) {
                    throw new \RuntimeException('Nie rozpoznano przewoźnika „' . $sh['courier_code'] . '” wśród dostępnych w TikTok: '
                        . implode(', ', array_column($providers, 'name')) . '.');
                }
                $lineIds = array_values(array_filter(array_map(static fn($li): string => (string) ($li['id'] ?? ''), (array) ($order['line_items'] ?? []))));
                $client->markShipped((string) $t['tt_order_id'], (string) $sh['waybill_no'], $provider['id'], $lineIds);
                $this->trackingResult((int) $t['id'], 'sent', (string) $sh['waybill_no'], null);
                Logger::info('TikTok Shop: zamówienie ' . $t['tt_order_id'] . ' oznaczone jako wysłane, numer ' . $sh['waybill_no'] . ' (' . $provider['name'] . ')');
                $n++;
            } catch (\Throwable $e) {
                $this->trackingResult((int) $t['id'], 'error', (string) $sh['waybill_no'], $e->getMessage());
                Logger::warn('TikTok Shop: numer przesyłki zamówienia ' . $t['tt_order_id'] . ' nie wysłany: ' . $e->getMessage());
            }
        }
        return $n;
    }

    private function trackingResult(int $linkId, string $state, string $number, ?string $error): void
    {
        $this->pdo->prepare('UPDATE tiktokshop_orders SET tracking_state = ?, tracking_number = ?, tracking_error = ?,
                tracking_attempts = tracking_attempts + ?, tracking_at = ? WHERE id = ?')
            ->execute([$state, $number, $error !== null ? mb_substr($error, 0, 500) : null, $state === 'error' ? 1 : 0, $this->nowSql(), $linkId]);
    }

    /** Operator poprawił kuriera/numer - spróbuj wysłać numer jeszcze raz. */
    public function retryTracking(int $wooOrderId): void
    {
        $this->pdo->prepare("UPDATE tiktokshop_orders SET tracking_state = NULL, tracking_error = NULL, tracking_attempts = 0 WHERE woo_order_id = ? AND tracking_state = 'error'")
            ->execute([$wooOrderId]);
    }

    /** Wiersz powiązania zamówienia CRM z TikTok (do strony zamówienia). */
    public function orderLink(int $wooOrderId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM tiktokshop_orders WHERE woo_order_id = ?');
        $st->execute([$wooOrderId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Przewoźnik TikTok pasujący do nazwy kuriera w CRM (np. „InPost Paczkomat (ręcznie)” -> „InPost”).
     * Jedyny dostępny przewoźnik pasuje zawsze.
     * @param array<int,array{id:string,name:string}> $providers
     * @return array{id:string,name:string}|null
     */
    public static function matchProvider(string $courier, array $providers): ?array
    {
        $courier = mb_strtolower($courier);
        foreach (self::CARRIERS as $needle => $names) {
            if (!str_contains($courier, $needle)) {
                continue;
            }
            foreach ($providers as $p) {
                foreach ($names as $name) {
                    if (str_contains(mb_strtolower($p['name']), $name)) {
                        return $p;
                    }
                }
            }
        }
        $plain = trim((string) preg_replace('/\(.*\)/u', '', $courier));
        foreach ($providers as $p) {
            $pn = mb_strtolower(trim($p['name']));
            if ($pn !== '' && $plain !== '' && (str_contains($plain, $pn) || str_contains($pn, $plain))) {
                return $p;
            }
        }
        return count($providers) === 1 ? $providers[0] : null;
    }

    // ---------------------------------------------------------------- pomocnicze

    private function scalar(string $sql, array $params): mixed
    {
        try {
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchColumn();
        } catch (\PDOException) {
            return 0;
        }
    }

    private function rows(string $sql, array $params): array
    {
        try {
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }
    }
}
