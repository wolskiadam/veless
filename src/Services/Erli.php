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
 * ERLI: import zamówień, stany CRM do ERLI i numery przesyłek do ERLI.
 *
 * Klienta API daje wtyczka integrations/erli (bez wtyczki nic tu nie działa - available() = false).
 * Worker woła run() co RUN_EVERY_MIN minut, dla każdego aktywnego konta ERLI z kluczem API:
 *
 *  1. Zamówienia: zmienione od ostatniego sprawdzenia (updated) trafiają na wspólną listę zamówień w formacie Woo,
 *     z własnym numerem z zakresu ID_MIN-ID_MAX (Woo ma małe numery, TikTok 6 mld, ręczne 8 mld, Allegro 9 mld+).
 *     Opłacone zamówienie (purchased) zdejmuje stan w CRM raz (pozycje dopasowane po SKU, a bez SKU po externalId
 *     produktu w ERLI = SKU w magazynie); anulowane w ERLI oddaje go. Status CRM ustawiamy przy pierwszym imporcie;
 *     później tylko anulowanie w ERLI zmienia „Nowe” na „Anulowane”.
 *  2. Stany: katalog produktów ERLI odświeżany co CATALOG_EVERY_MIN; produkt, którego SKU (albo externalId) = SKU w CRM,
 *     dostaje stan CRM (pase_stock), tylko gdy różni się od ostatnio wysłanego. Produkty bez stanu w CRM (NULL) pomijamy.
 *  3. Przesyłki: opłacone zamówienie, którego ERLI nie ma jeszcze za wysłane, z numerem przesyłki w CRM dostaje
 *     numer i przewoźnika (dopasowanego po nazwie kuriera), a status sprzedawcy zmienia się na „wysłane”.
 */
final class Erli
{
    public const TYPE = 'erli';
    public const ID_MIN = 7_000_000_001;
    public const ID_MAX = 7_999_999_999;
    public const RUN_EVERY_MIN = 5;
    public const CATALOG_EVERY_MIN = 60;
    private const PAGES_PER_RUN = 10;
    private const PAGE_SIZE = 100;
    private const STOCK_PUSH_LIMIT = 200;
    private const TRACKING_MAX_ATTEMPTS = 5;

    public const STATUS_LABELS = [
        'pending'   => 'oczekuje na płatność',
        'purchased' => 'opłacone',
        'cancelled' => 'anulowane',
        'returned'  => 'zwrócone',
    ];

    public const SELLER_STATUS_LABELS = [
        'created'           => 'nowe',
        'readyToProcess'    => 'do realizacji',
        'inProgress'        => 'w realizacji',
        'sent'              => 'wysłane',
        'readyToPickup'     => 'czeka na odbiór',
        'received'          => 'odebrane',
        'returned'          => 'zwrócone',
        'returningToSender' => 'wraca do nadawcy',
        'canceled'          => 'anulowane',
        'unknown'           => 'nieznany',
    ];

    /** Status sprzedawcy, przy którym ERLI ma już zamówienie za wysłane - numeru nie wysyłamy. */
    private const SHIPPED_SELLER_STATUSES = ['sent', 'readyToPickup', 'received', 'returned', 'returningToSender'];

    /** Słowa w nazwie kuriera w CRM -> przewoźnik (deliveryTracking.vendor) w ERLI. Kolejność ma znaczenie. */
    private const VENDORS = [
        'allegro one' => 'other', 'inpost' => 'inpost', 'paczkomat' => 'inpost', 'dpd' => 'dpd', 'dhl' => 'dhl', 'gls' => 'gls',
        'ups' => 'ups', 'fedex' => 'fedex', 'pocztex' => 'pocztaPolska', 'poczta' => 'pocztaPolska', 'orlen' => 'orlen', 'ruch' => 'orlen',
        'geis' => 'geis', 'raben' => 'raben', 'schenker' => 'schenker', 'rhenus' => 'rhenus', 'ambro' => 'ambroExpress', 'dts' => 'dts',
        'pekaes' => 'pekaes', 'tnt' => 'tntExpress', 'hellmann' => 'hellmann', 'dsv' => 'dsv', 'patron' => 'patronService',
        'odbiór osobisty' => 'selfPickup', 'odbior osobisty' => 'selfPickup', 'własny' => 'ownTransport', 'wlasny' => 'ownTransport',
    ];

    private SettingsRepository $settings;
    /** @var callable(array):object */
    private $clientFactory;

    /**
     * @param ?callable $clientFactory w testach: fn(array $account) => klient z atrapą HTTP
     */
    public function __construct(
        private readonly PDO $pdo,
        ?callable $clientFactory = null,
        private readonly ?Queue $queue = null,
        private readonly ?RuleEngine $ruleEngine = null,
        private readonly ?\Closure $clock = null
    ) {
        $this->settings = new SettingsRepository($pdo);
        $this->clientFactory = $clientFactory ?? static function (array $account): object {
            $plugin = PluginRegistry::forAccount(self::TYPE, $account['config'] ?? []);
            if ($plugin === null || !method_exists($plugin, 'client')) {
                throw new \RuntimeException('Wtyczka ERLI nie jest zainstalowana albo jest wyłączona.');
            }
            return $plugin->client();
        };
    }

    public static function available(): bool
    {
        return PluginRegistry::get(self::TYPE) !== null;
    }

    public static function isErli(int $wooOrderId): bool
    {
        return $wooOrderId >= self::ID_MIN && $wooOrderId <= self::ID_MAX;
    }

    public static function migrate(PDO $pdo): void
    {
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pdo->exec('CREATE TABLE IF NOT EXISTS erli_orders (
            id ' . $pk . ',
            integration_id INT NOT NULL,
            erli_order_id VARCHAR(64) NOT NULL,
            woo_order_id BIGINT NOT NULL,
            erli_status VARCHAR(20) NULL,
            seller_status VARCHAR(24) NULL,
            stock_state VARCHAR(12) NULL,
            stock_lines TEXT NULL,
            tracking_state VARCHAR(12) NULL,
            tracking_number VARCHAR(128) NULL,
            tracking_error VARCHAR(500) NULL,
            tracking_attempts INT NOT NULL DEFAULT 0,
            tracking_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE (integration_id, erli_order_id),
            UNIQUE (woo_order_id)
        )' . $tail);
        $pdo->exec('CREATE TABLE IF NOT EXISTS erli_products (
            id ' . $pk . ',
            integration_id INT NOT NULL,
            external_id VARCHAR(191) NOT NULL,
            match_sku VARCHAR(191) NULL,
            product_name VARCHAR(255) NULL,
            erli_stock INT NULL,
            pushed_qty INT NULL,
            pushed_at DATETIME NULL,
            error VARCHAR(500) NULL,
            seen_at DATETIME NULL,
            UNIQUE (integration_id, external_id)
        )' . $tail);
    }

    /** @return array<int,array<string,mixed>> aktywne konta ERLI */
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
        return $this->settings->get('ERLI_' . $accountId . '_' . $key, $default);
    }

    private function setState(int $accountId, array $values): void
    {
        $out = [];
        foreach ($values as $k => $v) {
            $out['ERLI_' . $accountId . '_' . $k] = (string) $v;
        }
        $this->settings->setMany($out);
    }

    private static function hasKey(array $account): bool
    {
        return trim((string) ($account['config']['api_key'] ?? '')) !== '';
    }

    /** Stan synchronizacji do strony integracji. @return array<string,mixed> */
    public function status(array $account): array
    {
        $id = (int) $account['id'];
        return [
            'configured'  => self::hasKey($account),
            'last_run'    => (string) $this->state($id, 'LAST_RUN', ''),
            'last_error'  => (string) $this->state($id, 'LAST_ERROR', ''),
            'orders_from' => (int) $this->state($id, 'ORDERS_FROM', '0'),
            'orders'      => (int) $this->scalar('SELECT COUNT(*) FROM erli_orders WHERE integration_id = ?', [$id]),
            'products'    => (int) $this->scalar('SELECT COUNT(*) FROM erli_products WHERE integration_id = ?', [$id]),
            'products_linked' => (int) $this->scalar('SELECT COUNT(*) FROM erli_products e JOIN products p ON p.sku = e.match_sku WHERE e.integration_id = ?', [$id]),
            'stock_errors' => $this->rows('SELECT external_id, match_sku, product_name, error FROM erli_products WHERE integration_id = ? AND error IS NOT NULL ORDER BY id LIMIT 20', [$id]),
            'tracking_errors' => $this->rows('SELECT e.woo_order_id, e.erli_order_id, e.tracking_error, w.pase_number FROM erli_orders e
                LEFT JOIN woo_orders w ON w.woo_order_id = e.woo_order_id
                WHERE e.integration_id = ? AND e.tracking_state = \'error\' ORDER BY e.id DESC LIMIT 20', [$id]),
        ];
    }

    public function client(array $account): object
    {
        if (!self::hasKey($account)) {
            throw new \RuntimeException('Wpisz klucz API sklepu ERLI w ustawieniach integracji.');
        }
        return ($this->clientFactory)($account);
    }

    // ---------------------------------------------------------------- cykl workera

    /** Wszystkie konta: zamówienia, stany, przesyłki. Błąd jednego kroku nie zatrzymuje reszty. */
    public function run(): array
    {
        $out = ['orders' => 0, 'stock' => 0, 'tracking' => 0];
        foreach ($this->accounts() as $account) {
            if (!self::hasKey($account)) {
                continue;
            }
            $id = (int) $account['id'];
            $errors = [];
            try {
                $client = $this->client($account);
            } catch (\Throwable $e) {
                $this->setState($id, ['LAST_RUN' => $this->nowSql(), 'LAST_ERROR' => mb_substr($e->getMessage(), 0, 500)]);
                Logger::warn('ERLI #' . $id . ': ' . $e->getMessage());
                continue;
            }
            foreach (['orders' => 'syncOrders', 'stock' => 'pushStock', 'tracking' => 'pushTracking'] as $key => $step) {
                try {
                    $out[$key] += $this->{$step}($account, $client);
                } catch (\Throwable $e) {
                    $errors[] = ['orders' => 'Zamówienia', 'stock' => 'Stany', 'tracking' => 'Przesyłki'][$key] . ': ' . $e->getMessage();
                    Logger::warn('ERLI #' . $id . ' [' . $key . ']: ' . $e->getMessage());
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
        $after = '';
        for ($page = 0; $page < self::PAGES_PER_RUN; $page++) {
            $orders = $client->searchOrders($from, $after, self::PAGE_SIZE);
            foreach ($orders as $order) {
                if (empty($order['id'])) {
                    continue;
                }
                $this->importOrder($account, $order);
                $updated = strtotime((string) ($order['updated'] ?? '')) ?: 0;
                $maxSeen = max($maxSeen, $updated);
                $n++;
            }
            $last = end($orders);
            $after = is_array($last) ? (string) ($last['cursor'] ?? '') : '';
            if (count($orders) < self::PAGE_SIZE || $after === '') {
                break;
            }
        }
        // Filtr updated >= obejmuje granicę - ostatnie zamówienie wróci raz jeszcze, zapis jest idempotentny.
        $this->setState($id, ['ORDERS_FROM' => (string) $maxSeen]);
        return $n;
    }

    /** Zapis jednego zamówienia ERLI (nowe albo aktualizacja). @return int woo_order_id w CRM */
    public function importOrder(array $account, array $order): int
    {
        $accountId = (int) $account['id'];
        $erliId = (string) $order['id'];
        $status = (string) ($order['status'] ?? '');
        $sellerStatus = (string) ($order['sellerStatus'] ?? '');
        $link = $this->link($accountId, $erliId);

        // Dopasowanie pozycji do magazynu po SKU, a bez SKU po externalId produktu w ERLI.
        $skus = array_values(array_unique(array_filter(array_map(static fn($it): string => self::itemSku(is_array($it) ? $it : []), (array) ($order['items'] ?? [])))));
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
            'status'         => $status,
            'currency'       => $payload['currency'],
            'total'          => (float) $payload['total'],
            'customer_name'  => trim(($payload['shipping']['first_name'] ?? '') . ' ' . ($payload['shipping']['last_name'] ?? '')) ?: null,
            'customer_email' => ($payload['billing']['email'] ?? '') !== '' ? $payload['billing']['email'] : null,
        ];

        $orders = new WooOrderRepository($this->pdo);
        $wasPaid = $link !== null && $this->ruleEngine !== null
            ? \Pase\Automation\OrderEvents::isPaidNow($this->pdo, (int) $link['woo_order_id']) : null;
        if ($link === null) {
            $wooId = $this->insertOrder($accountId, $erliId, $status, $sellerStatus, $fields, $payload);
            $link = $this->link($accountId, $erliId);
            Logger::info("ERLI: nowe zamówienie {$erliId} -> #{$wooId}", ['status' => $status, 'total' => $fields['total']]);
        } else {
            $wooId = (int) $link['woo_order_id'];
            $this->pdo->prepare('UPDATE woo_orders SET status = ?, currency = ?, total = ?, customer_name = ?, customer_email = ?, payload = ? WHERE woo_order_id = ?')
                ->execute([$fields['status'], $fields['currency'], $fields['total'], $fields['customer_name'], $fields['customer_email'],
                    json_encode($payload, JSON_UNESCAPED_UNICODE), $wooId]);
            // Anulowanie w ERLI zmienia status CRM tylko, gdy nikt jeszcze nie ruszył zamówienia.
            if ($status === 'cancelled') {
                $this->pdo->prepare('UPDATE woo_orders SET pase_status = ? WHERE woo_order_id = ? AND pase_status = ?')
                    ->execute([OrderStatus::CANCELLED, $wooId, OrderStatus::NEW]);
            }
        }
        // Same produkty wirtualne -> „Zamówienie wirtualne — bez wysyłki” (bez nadpisywania wyboru z karty).
        (new VirtualProducts($this->pdo))->applyToOrder($wooId, (array) ($payload['line_items'] ?? []));
        $this->pdo->prepare('UPDATE erli_orders SET erli_status = ?, seller_status = ?, updated_at = ? WHERE id = ?')
            ->execute([$status, $sellerStatus !== '' ? $sellerStatus : null, $this->nowSql(), (int) $link['id']]);

        // Magazyn: opłacone zdejmuje stan raz, anulowane po zdjęciu oddaje.
        if ($status === 'purchased' && ($link['stock_state'] ?? null) === null) {
            $this->takeStock((int) $link['id'], $wooId, $payload['line_items']);
        } elseif ($status === 'cancelled' && ($link['stock_state'] ?? null) === 'taken') {
            $this->returnStock((int) $link['id'], $wooId, (string) ($link['stock_lines'] ?? '[]'));
        }

        // Automatyzacje („Pobrano zamówienie”) - raz, gdy zamówienie jest opłacone.
        if ($this->ruleEngine !== null && $status === 'purchased' && !$orders->automationsDone($wooId)) {
            try {
                $this->ruleEngine->dispatch('order.imported', $wooId, $payload);
                $orders->markAutomationsDone($wooId);
            } catch (\Throwable $e) {
                Logger::error("ERLI: automatyzacje zamówienia #{$wooId} nie zadziałały", ['error' => $e->getMessage()]);
            }
        }
        if ($this->ruleEngine !== null) {
            \Pase\Automation\OrderEvents::paidTransition($this->pdo, $wooId, $wasPaid, $this->ruleEngine);
        }
        return $wooId;
    }

    private function link(int $accountId, string $erliId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM erli_orders WHERE integration_id = ? AND erli_order_id = ?');
        $st->execute([$accountId, $erliId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Nowy wiersz woo_orders z numerem z zakresu ERLI (przy równoległym zapisie - kolejny numer). */
    private function insertOrder(int $accountId, string $erliId, string $status, string $sellerStatus, array $fields, array $payload): int
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
                    $id, $accountId, $erliId, $status, self::paseStatus($status, $sellerStatus), $fields['currency'], $fields['total'],
                    $fields['customer_name'], $fields['customer_email'], $payload['date_created'], bin2hex(random_bytes(24)),
                    json_encode($payload, JSON_UNESCAPED_UNICODE),
                ]);
                $this->pdo->prepare('INSERT INTO erli_orders (integration_id, erli_order_id, woo_order_id, erli_status, seller_status, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$accountId, $erliId, $id, $status, $sellerStatus !== '' ? $sellerStatus : null, $this->nowSql()]);
                $this->pdo->commit();
            } catch (\PDOException $e) {
                $this->pdo->rollBack();
                if ($this->link($accountId, $erliId) !== null) {
                    return (int) $this->link($accountId, $erliId)['woo_order_id'];   // równoległy przebieg już zapisał
                }
                if (!preg_match('/Duplicate|UNIQUE|PRIMARY/i', $e->getMessage())) {
                    throw $e;
                }
                continue;
            }
            (new WooOrderRepository($this->pdo))->assignNumberIfMissing($id);
            return $id;
        }
        throw new \RuntimeException('Nie udało się nadać numeru zamówieniu ERLI ' . $erliId);
    }

    /** Status CRM przy pierwszym imporcie. */
    public static function paseStatus(string $status, string $sellerStatus = ''): string
    {
        if ($status === 'cancelled') {
            return OrderStatus::CANCELLED;
        }
        return match ($sellerStatus) {
            'inProgress' => OrderStatus::PROCESSING,
            'sent', 'readyToPickup', 'received' => OrderStatus::SHIPPED,
            default => OrderStatus::NEW,
        };
    }

    /** SKU pozycji do dopasowania z magazynem: SKU z ERLI, a bez niego externalId produktu. */
    private static function itemSku(array $item): string
    {
        $sku = trim((string) ($item['sku'] ?? ''));
        return $sku !== '' ? $sku : trim((string) ($item['externalId'] ?? ''));
    }

    /** Kwota w groszach -> tekst „12.34”. */
    private static function money(mixed $grosze): string
    {
        return number_format(((int) round((float) $grosze)) / 100, 2, '.', '');
    }

    /**
     * Zamówienie ERLI w formacie Woo (strona zamówienia, faktura, wydruki, klienci). Surowe dane zostają pod 'erli'.
     * @param array<string,int> $products SKU w CRM => id produktu
     */
    public static function toWoo(array $order, array $products = []): array
    {
        $user = is_array($order['user'] ?? null) ? $order['user'] : [];
        $street = static function (array $a): array {
            $line = trim((string) ($a['address'] ?? ''));
            if ($line === '') {
                $line = trim((string) ($a['street'] ?? '') . ' ' . (string) ($a['buildingNumber'] ?? ''));
                $flat = trim((string) ($a['flatNumber'] ?? ''));
                $line .= $flat !== '' ? '/' . $flat : '';
            }
            return [
                'address_1' => $line,
                'address_2' => '',
                'city'      => (string) ($a['city'] ?? ''),
                'postcode'  => (string) ($a['zip'] ?? ''),
                'country'   => strtoupper((string) ($a['country'] ?? 'pl')),
            ];
        };
        $del = is_array($user['deliveryAddress'] ?? null) ? $user['deliveryAddress'] : [];
        $shipping = [
            'first_name' => trim((string) ($del['firstName'] ?? '')),
            'last_name'  => trim((string) ($del['lastName'] ?? '')),
            'company'    => trim((string) ($del['companyName'] ?? '')),
        ] + $street($del) + ['phone' => (string) ($del['phone'] ?? '')];

        // Faktura: adres do faktury z ERLI (firma z NIP albo osoba), bez niego - dane dostawy.
        $inv = is_array($user['invoiceAddress'] ?? null) ? $user['invoiceAddress'] : null;
        $billing = $shipping;
        if ($inv !== null) {
            $company = ($inv['type'] ?? '') === 'company';
            $billing = [
                'first_name' => $company ? '' : trim((string) ($inv['firstName'] ?? '')),
                'last_name'  => $company ? '' : trim((string) ($inv['lastName'] ?? '')),
                'company'    => $company ? trim((string) ($inv['companyName'] ?? '')) : '',
            ] + $street($inv) + ['phone' => $shipping['phone']];
            if ($company && trim((string) ($inv['nip'] ?? '')) !== '') {
                $billing['nip'] = trim((string) $inv['nip']);
            }
        }
        $billing['email'] = (string) ($user['email'] ?? '');

        $items = [];
        foreach ((array) ($order['items'] ?? []) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $sku = self::itemSku($it);
            $qty = max(1, (int) ($it['quantity'] ?? 1));
            $unit = (int) ($it['unitPrice'] ?? 0);
            $items[] = [
                'id'               => (string) ($it['id'] ?? count($items)),
                'product_id'       => $sku !== '' ? (int) ($products[$sku] ?? 0) : 0,
                'name'             => trim((string) ($it['name'] ?? '')) ?: 'Pozycja ERLI',
                'sku'              => $sku !== '' ? $sku : null,
                'quantity'         => $qty,
                // Ceny brutto (jak Allegro): bez total_tax faktura liczy brutto = total.
                'price'            => (float) self::money($unit),
                'total'            => self::money($unit * $qty),
                'erli_external_id' => (string) ($it['externalId'] ?? ''),
                'ean'              => (string) ($it['ean'] ?? ''),
                'in_warehouse'     => $sku !== '' && !empty($products[$sku]),
            ];
        }

        $delivery = is_array($order['delivery'] ?? null) ? $order['delivery'] : [];
        $shipFee = self::money($delivery['price'] ?? 0);
        $meta = [];
        $pp = is_array($delivery['pickupPlace'] ?? null) ? $delivery['pickupPlace'] : null;
        if ($pp !== null && (($pp['externalId'] ?? '') !== '' || ($pp['id'] ?? '') !== '')) {
            // Punkt odbioru w meta metody wysyłki - Support\PickupPoint znajdzie go po kluczu pickup_point_id.
            $meta = [
                ['key' => 'pickup_point_id', 'value' => (string) (($pp['externalId'] ?? '') !== '' ? $pp['externalId'] : $pp['id'])],
                ['key' => 'pickup_point_name', 'value' => trim((string) ($pp['name'] ?? $pp['heading'] ?? ''))],
                ['key' => 'pickup_point_street', 'value' => (string) ($pp['address'] ?? '')],
                ['key' => 'pickup_point_postcode', 'value' => (string) ($pp['zip'] ?? '')],
                ['key' => 'pickup_point_city', 'value' => (string) ($pp['city'] ?? '')],
                ['key' => 'pickup_point_provider', 'value' => (string) ($pp['provider'] ?? '')],
            ];
        }
        $created = strtotime((string) ($order['created'] ?? '')) ?: 0;
        $paid = strtotime((string) ($order['purchasedAt'] ?? '')) ?: 0;
        $cod = !empty($delivery['cod']);

        return [
            'id'                   => (string) $order['id'],
            'number'               => (string) $order['id'],
            'status'               => (string) ($order['status'] ?? ''),
            'created_via'          => 'erli',
            'date_created'         => $created > 0 ? gmdate('Y-m-d H:i:s', $created) : null,
            'date_paid'            => $paid > 0 ? gmdate('Y-m-d H:i:s', $paid) : null,
            'currency'             => (string) ($order['currency'] ?? 'PLN'),
            'total'                => self::money($order['totalPrice'] ?? 0),
            'shipping_total'       => $shipFee,
            'billing'              => $billing,
            'shipping'             => $shipping,
            'line_items'           => $items,
            'shipping_lines'       => [[
                'method_title' => trim((string) ($delivery['name'] ?? '')) ?: 'Dostawa ERLI',
                'total'        => $shipFee,
                'meta_data'    => $meta,
            ]],
            'payment_method'       => $cod ? 'cod' : 'erli',
            'payment_method_title' => $cod ? 'ERLI — za pobraniem' : 'ERLI',
            'customer_note'        => (string) ($order['comment'] ?? ''),
            'meta_data'            => [],
            'erli'                 => $order,
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
            $claim = $this->pdo->prepare("UPDATE erli_orders SET stock_state = 'taken', stock_lines = ? WHERE id = ? AND stock_state IS NULL");
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
        $this->queueShopPush($wooId, array_keys($lines), 'erli');
    }

    /** Oddaje stan zdjęty przy imporcie (zamówienie anulowane w ERLI). */
    private function returnStock(int $linkId, int $wooId, string $linesJson): void
    {
        $lines = json_decode($linesJson, true) ?: [];
        $this->atomic(function () use ($linkId, $lines): void {
            $claim = $this->pdo->prepare("UPDATE erli_orders SET stock_state = 'returned' WHERE id = ? AND stock_state = 'taken'");
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
        Logger::info("ERLI: zamówienie #{$wooId} anulowane w ERLI - oddano stan", ['produkty' => $lines]);
        $this->queueShopPush($wooId, array_keys($lines), 'erli-cancel');
    }

    private function queueShopPush(int $wooId, array $productIds, string $why): void
    {
        foreach ($productIds as $pid) {
            try {
                $this->queue?->enqueue('woo.stock.push', ['product_id' => (int) $pid], 'woo.stock.push:' . $pid . ':' . $why . ':' . $wooId);
            } catch (\Throwable $e) {
                Logger::warn("ERLI #{$wooId}: nie zakolejkowano stanu produktu {$pid} do sklepu: " . $e->getMessage());
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

    /** Odświeża katalog produktów ERLI (co CATALOG_EVERY_MIN albo na żądanie). @return int liczba produktów (-1 = jeszcze nie pora) */
    public function refreshCatalog(array $account, object $client, bool $force = false): int
    {
        $id = (int) $account['id'];
        if (!$force && $this->now() - (int) $this->state($id, 'CATALOG_AT', '0') < self::CATALOG_EVERY_MIN * 60) {
            return -1;
        }
        $seen = $this->nowSql();
        $seenIds = [];
        $n = 0;
        $after = '';
        $find = $this->pdo->prepare('SELECT id FROM erli_products WHERE integration_id = ? AND external_id = ?');
        $ins = $this->pdo->prepare('INSERT INTO erli_products (integration_id, external_id, match_sku, product_name, erli_stock, seen_at) VALUES (?, ?, ?, ?, ?, ?)');
        $upd = $this->pdo->prepare('UPDATE erli_products SET match_sku = ?, product_name = ?, erli_stock = ?, seen_at = ? WHERE id = ?');
        for ($page = 0; $page < 200; $page++) {
            $list = $client->searchProducts($after, self::PAGE_SIZE);
            foreach ($list as $p) {
                $ext = trim((string) ($p['externalId'] ?? ''));
                if ($ext === '') {
                    continue;
                }
                $sku = trim((string) ($p['sku'] ?? ''));
                $vals = [$sku !== '' ? $sku : $ext, mb_substr((string) ($p['name'] ?? ''), 0, 255), isset($p['stock']) ? (int) $p['stock'] : null, $seen];
                $find->execute([$id, $ext]);
                $rowId = $find->fetchColumn();
                if ($rowId !== false) {
                    $upd->execute([...$vals, (int) $rowId]);
                    $seenIds[(int) $rowId] = true;
                } else {
                    $ins->execute([$id, $ext, ...$vals]);
                    $seenIds[(int) $this->pdo->lastInsertId()] = true;
                }
                $n++;
            }
            $last = end($list);
            $after = is_array($last) ? trim((string) ($last['externalId'] ?? '')) : '';
            if (count($list) < self::PAGE_SIZE || $after === '') {
                break;
            }
        }
        // Produkty usunięte w ERLI - nie ma czego aktualizować.
        $all = $this->pdo->prepare('SELECT id FROM erli_products WHERE integration_id = ?');
        $all->execute([$id]);
        $del = $this->pdo->prepare('DELETE FROM erli_products WHERE id = ?');
        foreach ($all->fetchAll(PDO::FETCH_COLUMN) as $rowId) {
            if (!isset($seenIds[(int) $rowId])) {
                $del->execute([(int) $rowId]);
            }
        }
        $this->setState($id, ['CATALOG_AT' => (string) $this->now()]);
        return $n;
    }

    /** Wysyła stan CRM do produktów ERLI, które go nie mają. @return int liczba przyjętych */
    public function pushStock(array $account, object $client): int
    {
        if (($account['config']['push_stock'] ?? 'on') === 'off') {
            return 0;
        }
        $id = (int) $account['id'];
        $this->refreshCatalog($account, $client);
        $st = $this->pdo->prepare('SELECT e.id, e.external_id, e.match_sku, p.pase_stock
            FROM erli_products e JOIN products p ON p.sku = e.match_sku
            WHERE e.integration_id = ? AND p.pase_stock IS NOT NULL
              AND (e.pushed_qty IS NULL OR e.pushed_qty <> (CASE WHEN p.pase_stock < 0 THEN 0 ELSE p.pase_stock END))
            ORDER BY e.id LIMIT ' . self::STOCK_PUSH_LIMIT);
        $st->execute([$id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return 0;
        }
        $stocks = [];
        foreach ($rows as $r) {
            $stocks[(string) $r['external_id']] = max(0, (int) $r['pase_stock']);
        }
        $ok = $this->pdo->prepare('UPDATE erli_products SET pushed_qty = ?, erli_stock = ?, pushed_at = ?, error = NULL WHERE id = ?');
        $bad = $this->pdo->prepare('UPDATE erli_products SET error = ? WHERE id = ?');
        try {
            $errors = $client->updateStocks($stocks);
        } catch (\Throwable $e) {
            // Całe żądanie odrzucone - błąd przy każdym produkcie, kolejna próba przy następnym przebiegu.
            foreach ($rows as $r) {
                $bad->execute([mb_substr($e->getMessage(), 0, 500), (int) $r['id']]);
            }
            throw $e;
        }
        $n = 0;
        foreach ($rows as $r) {
            $ext = (string) $r['external_id'];
            if (isset($errors[$ext])) {
                $bad->execute([mb_substr($errors[$ext], 0, 500), (int) $r['id']]);
                Logger::warn('ERLI: stan produktu ' . $ext . ' nie wysłany: ' . $errors[$ext]);
                continue;
            }
            $ok->execute([$stocks[$ext], $stocks[$ext], $this->nowSql(), (int) $r['id']]);
            $n++;
        }
        return $n;
    }

    // ---------------------------------------------------------------- przesyłki

    /** Numery przesyłek z CRM do opłaconych zamówień, których ERLI nie ma jeszcze za wysłane. @return int liczba wysłanych */
    public function pushTracking(array $account, object $client): int
    {
        if (($account['config']['push_tracking'] ?? 'on') === 'off') {
            return 0;
        }
        $id = (int) $account['id'];
        $st = $this->pdo->prepare("SELECT e.*, w.payload FROM erli_orders e JOIN woo_orders w ON w.woo_order_id = e.woo_order_id
            WHERE e.integration_id = ? AND e.erli_status = 'purchased'
              AND (e.seller_status IS NULL OR e.seller_status NOT IN ('" . implode("','", self::SHIPPED_SELLER_STATUSES) . "', 'canceled'))
              AND (e.tracking_state IS NULL OR (e.tracking_state = 'error' AND e.tracking_attempts < " . self::TRACKING_MAX_ATTEMPTS . '))
            ORDER BY e.id LIMIT 50');
        $st->execute([$id]);
        $shipQ = $this->pdo->prepare("SELECT waybill_no, courier_code FROM shipments WHERE woo_order_id = ? AND waybill_no IS NOT NULL AND waybill_no <> ''
            AND (status IS NULL OR status NOT IN ('cancelled', 'error', 'pending')) ORDER BY id DESC LIMIT 1");
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $shipQ->execute([(int) $e['woo_order_id']]);
            $sh = $shipQ->fetch(PDO::FETCH_ASSOC);
            if (!$sh) {
                continue;   // jeszcze bez numeru przesyłki
            }
            $order = (json_decode((string) $e['payload'], true) ?: [])['erli'] ?? [];
            $existing = trim((string) ($order['deliveryTracking']['trackingNumber'] ?? ''));
            if ($existing !== '' && $existing !== (string) $sh['waybill_no']) {
                // Przesyłka nadana przez ERLI (np. „Wysyłam z Erli”) - numer ma już ERLI.
                $this->trackingResult((int) $e['id'], 'skipped', $existing, 'ERLI ma już numer przesyłki ' . $existing . ' - CRM go nie nadpisuje.');
                continue;
            }
            try {
                $vendor = self::vendorFor((string) $sh['courier_code']);
                $client->markShipped((string) $e['erli_order_id'], (string) $sh['waybill_no'], $vendor);
                $this->trackingResult((int) $e['id'], 'sent', (string) $sh['waybill_no'], null);
                $this->pdo->prepare("UPDATE erli_orders SET seller_status = 'sent' WHERE id = ?")->execute([(int) $e['id']]);
                Logger::info('ERLI: zamówienie ' . $e['erli_order_id'] . ' oznaczone jako wysłane, numer ' . $sh['waybill_no'] . ' (' . $vendor . ')');
                $n++;
            } catch (\Throwable $ex) {
                $this->trackingResult((int) $e['id'], 'error', (string) $sh['waybill_no'], $ex->getMessage());
                Logger::warn('ERLI: numer przesyłki zamówienia ' . $e['erli_order_id'] . ' nie wysłany: ' . $ex->getMessage());
            }
        }
        return $n;
    }

    private function trackingResult(int $linkId, string $state, string $number, ?string $error): void
    {
        $this->pdo->prepare('UPDATE erli_orders SET tracking_state = ?, tracking_number = ?, tracking_error = ?,
                tracking_attempts = tracking_attempts + ?, tracking_at = ? WHERE id = ?')
            ->execute([$state, $number, $error !== null ? mb_substr($error, 0, 500) : null, $state === 'error' ? 1 : 0, $this->nowSql(), $linkId]);
    }

    /** Operator poprawił kuriera/numer - spróbuj wysłać numer jeszcze raz. */
    public function retryTracking(int $wooOrderId): void
    {
        $this->pdo->prepare("UPDATE erli_orders SET tracking_state = NULL, tracking_error = NULL, tracking_attempts = 0 WHERE woo_order_id = ? AND tracking_state = 'error'")
            ->execute([$wooOrderId]);
    }

    /** Wiersz powiązania zamówienia CRM z ERLI (do strony zamówienia). */
    public function orderLink(int $wooOrderId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM erli_orders WHERE woo_order_id = ?');
        $st->execute([$wooOrderId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Przewoźnik ERLI (deliveryTracking.vendor) dla nazwy kuriera w CRM, np. „InPost Paczkomat (ręcznie)” -> inpost. */
    public static function vendorFor(string $courier): string
    {
        $courier = mb_strtolower($courier);
        foreach (self::VENDORS as $needle => $vendor) {
            if (str_contains($courier, $needle)) {
                return $vendor;
            }
        }
        return 'other';
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
