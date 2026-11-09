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
 * InPost vonHalsky (marketplace InPost): import zamówień, przyjmowanie/odrzucanie, stany i ceny CRM do ofert.
 *
 * Klienta API daje wtyczka integrations/vonhalsky (bez wtyczki nic tu nie działa - available() = false).
 * Konto łączy się przez OAuth2 z PKCE (public/admin/vonhalsky_connect.php); token żyje kilka minut, odnawiamy go
 * refresh tokenem przed każdym przebiegiem. Worker woła run() co RUN_EVERY_MIN minut, dla każdego połączonego konta:
 *
 *  1. Zamówienia: opłacone, zmienione od ostatniego sprawdzenia (updatedAtGte, sort updatedAt, strony po 30) trafiają na
 *     wspólną listę zamówień w formacie Woo, z numerem z zakresu ID_MIN-ID_MAX (TikTok 6 mld, ERLI 7 mld, ręczne 8 mld,
 *     Allegro 9 mld+). Zamówienie CREATED/ACCEPTED zdejmuje stan w CRM raz (pozycje po SKU, bez SKU po externalId oferty);
 *     odrzucone/anulowane oddaje go. Status CRM ustawiamy przy pierwszym imporcie, później tylko anulowanie
 *     w vonHalsky zmienia „Nowe” na „Anulowane”.
 *  2. Przyjmowanie: nowe zamówienie (CREATED) przyjmujemy w InPost od razu, gdy w ustawieniach konta jest auto_accept;
 *     inaczej operator klika „Przyjmij” albo „Odrzuć” na stronie zamówienia.
 *  3. Stany i ceny: oferty sprzedawcy odświeżane co CATALOG_EVERY_MIN; oferta, której SKU (albo externalId) = SKU w CRM,
 *     dostaje stan CRM (pase_stock) i - gdy włączone - cenę (pase_price × mnożnik), tylko gdy różni się od ostatnio wysłanej.
 *
 * Wysyłkę obsługuje InPost (paczkomat albo kurier InPost); numery paczek z zamówienia pokazujemy na stronie zamówienia.
 */
final class Vonhalsky
{
    public const TYPE = 'vonhalsky';
    public const ID_MIN = 5_000_000_001;
    public const ID_MAX = 5_999_999_999;
    public const RUN_EVERY_MIN = 5;
    public const CATALOG_EVERY_MIN = 60;
    private const REFRESH_BEFORE = 60;          // access token żyje ok. 9 min - odnawiamy minutę przed końcem
    private const PAGES_PER_RUN = 10;
    private const OFFERS_PAGE = 50;
    private const PUSH_LIMIT = 200;
    private const ACCEPT_MAX_ATTEMPTS = 5;

    /** Zamówienia, które czekają na realizację - zdejmują stan w CRM. */
    public const LIVE_STATUSES = ['CREATED', 'ACCEPTED'];
    /** Zamówienia zakończone bez realizacji - oddają stan. */
    public const DEAD_STATUSES = ['REFUSED', 'REJECTED', 'CANCELED', 'CANCELLED'];

    public const STATUS_LABELS = [
        'CREATED'   => 'czeka na przyjęcie',
        'ACCEPTED'  => 'przyjęte',
        'REFUSED'   => 'odrzucone przez sprzedawcę',
        'REJECTED'  => 'odrzucone przez InPost',
        'CANCELED'  => 'anulowane',
        'CANCELLED' => 'anulowane',
        'UNKNOWN'   => 'nieznany',
    ];

    public const PARCEL_LABELS = [
        'NEW'      => 'nadana',
        'SENT'     => 'w drodze',
        'RECEIVED' => 'odebrana',
        'UNKNOWN'  => 'nieznany',
    ];

    private SettingsRepository $settings;
    /** @var callable(array,?string):object */
    private $clientFactory;

    /**
     * @param ?callable $clientFactory w testach: fn(array $account, ?string $token) => klient z atrapą HTTP
     */
    public function __construct(
        private readonly PDO $pdo,
        ?callable $clientFactory = null,
        private readonly ?Queue $queue = null,
        private readonly ?RuleEngine $ruleEngine = null,
        private readonly ?\Closure $clock = null
    ) {
        $this->settings = new SettingsRepository($pdo);
        $this->clientFactory = $clientFactory ?? static function (array $account, ?string $token): object {
            $plugin = PluginRegistry::forAccount(self::TYPE, $account['config'] ?? []);
            if ($plugin === null || !method_exists($plugin, 'client')) {
                throw new \RuntimeException('Wtyczka InPost vonHalsky nie jest zainstalowana albo jest wyłączona.');
            }
            return $plugin->client($token);
        };
    }

    public static function available(): bool
    {
        return PluginRegistry::get(self::TYPE) !== null;
    }

    public static function isVonhalsky(int $wooOrderId): bool
    {
        return $wooOrderId >= self::ID_MIN && $wooOrderId <= self::ID_MAX;
    }

    public static function migrate(PDO $pdo): void
    {
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pdo->exec('CREATE TABLE IF NOT EXISTS vonhalsky_orders (
            id ' . $pk . ',
            integration_id INT NOT NULL,
            vh_order_id VARCHAR(64) NOT NULL,
            woo_order_id BIGINT NOT NULL,
            vh_status VARCHAR(20) NULL,
            accept_state VARCHAR(12) NULL,
            accept_error VARCHAR(500) NULL,
            accept_attempts INT NOT NULL DEFAULT 0,
            accept_at DATETIME NULL,
            stock_state VARCHAR(12) NULL,
            stock_lines TEXT NULL,
            updated_at DATETIME NULL,
            UNIQUE (integration_id, vh_order_id),
            UNIQUE (woo_order_id)
        )' . $tail);
        $pdo->exec('CREATE TABLE IF NOT EXISTS vonhalsky_offers (
            id ' . $pk . ',
            integration_id INT NOT NULL,
            offer_id VARCHAR(64) NOT NULL,
            match_sku VARCHAR(191) NULL,
            product_name VARCHAR(255) NULL,
            offer_status VARCHAR(20) NULL,
            vh_stock INT NULL,
            stock_unit VARCHAR(10) NULL,
            vh_price DECIMAL(12,2) NULL,
            currency VARCHAR(3) NULL,
            pushed_qty INT NULL,
            pushed_price DECIMAL(12,2) NULL,
            pushed_at DATETIME NULL,
            error VARCHAR(500) NULL,
            seen_at DATETIME NULL,
            UNIQUE (integration_id, offer_id)
        )' . $tail);
    }

    /** @return array<int,array<string,mixed>> aktywne konta vonHalsky */
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
        return $this->settings->get('VONHALSKY_' . $accountId . '_' . $key, $default);
    }

    private function setState(int $accountId, array $values): void
    {
        $out = [];
        foreach ($values as $k => $v) {
            $out['VONHALSKY_' . $accountId . '_' . $k] = (string) $v;
        }
        $this->settings->setMany($out);
    }

    private static function connected(array $account): bool
    {
        return trim((string) ($account['refresh_token'] ?? '')) !== '' || trim((string) ($account['access_token'] ?? '')) !== '';
    }

    /** Organizacja (sklep), w której działamy: wpisana w ustawieniach albo wybrana przy połączeniu. */
    public function organizationId(array $account): string
    {
        $cfg = trim((string) ($account['config']['organization_id'] ?? ''));
        return $cfg !== '' ? $cfg : (string) $this->state((int) $account['id'], 'ORG_ID', '');
    }

    /** Stan połączenia do strony integracji. @return array<string,mixed> */
    public function status(array $account): array
    {
        $id = (int) $account['id'];
        return [
            'connected'   => self::connected($account) && $this->organizationId($account) !== '',
            'organization' => $this->organizationId($account),
            'org_name'    => (string) $this->state($id, 'ORG_NAME', ''),
            'last_run'    => (string) $this->state($id, 'LAST_RUN', ''),
            'last_error'  => (string) $this->state($id, 'LAST_ERROR', ''),
            'orders'      => (int) $this->scalar('SELECT COUNT(*) FROM vonhalsky_orders WHERE integration_id = ?', [$id]),
            'waiting'     => (int) $this->scalar("SELECT COUNT(*) FROM vonhalsky_orders WHERE integration_id = ? AND vh_status = 'CREATED'", [$id]),
            'offers'      => (int) $this->scalar('SELECT COUNT(*) FROM vonhalsky_offers WHERE integration_id = ?', [$id]),
            'offers_linked' => (int) $this->scalar('SELECT COUNT(*) FROM vonhalsky_offers o JOIN products p ON p.sku = o.match_sku WHERE o.integration_id = ?', [$id]),
            'offer_errors' => $this->rows('SELECT offer_id, match_sku, product_name, error FROM vonhalsky_offers WHERE integration_id = ? AND error IS NOT NULL ORDER BY id LIMIT 20', [$id]),
            'accept_errors' => $this->rows("SELECT v.woo_order_id, v.vh_order_id, v.accept_error, w.pase_number FROM vonhalsky_orders v
                LEFT JOIN woo_orders w ON w.woo_order_id = v.woo_order_id
                WHERE v.integration_id = ? AND v.accept_state = 'error' ORDER BY v.id DESC LIMIT 20", [$id]),
        ];
    }

    // ---------------------------------------------------------------- OAuth2

    /** Adres powrotu ze strony zgody - trzeba go zgłosić w InPost jako redirect URI. */
    public function redirectUri(): string
    {
        return rtrim((string) $this->settings->get('APP_BASE_URL', ''), '/') . '/admin/vonhalsky_connect.php';
    }

    /** Adres strony zgody InPost dla konta; zapamiętuje jednorazowy state i code_verifier (PKCE). */
    public function connectUrl(array $account): string
    {
        $client = ($this->clientFactory)($account, null);
        $state = (int) $account['id'] . '.' . bin2hex(random_bytes(16));
        [$verifier, $challenge] = $client::pkcePair();
        $this->setState((int) $account['id'], ['OAUTH_STATE' => $state . '|' . $this->now() . '|' . $verifier]);
        return $client->authorizeUrl($state, $challenge, $this->redirectUri());
    }

    /**
     * Powrót ze strony zgody: kod -> tokeny, wybór organizacji (sklepu).
     * @return array{ok:bool,message:string,account_id:int}
     */
    public function handleCallback(string $code, string $state, string $error = ''): array
    {
        $accountId = (int) explode('.', $state, 2)[0];
        $repo = new IntegrationAccountRepository($this->pdo);
        $account = $accountId > 0 ? $repo->find($accountId) : null;
        if ($account === null || ($account['type'] ?? '') !== self::TYPE) {
            return ['ok' => false, 'message' => 'Nieznane konto InPost vonHalsky w odpowiedzi - zacznij łączenie jeszcze raz.', 'account_id' => 0];
        }
        [$saved, $at, $verifier] = array_pad(explode('|', (string) $this->state($accountId, 'OAUTH_STATE', '')), 3, '');
        if ($saved === '' || !hash_equals($saved, $state) || $this->now() - (int) $at > 3600) {
            return ['ok' => false, 'message' => 'Odpowiedź InPost nie pasuje do rozpoczętego łączenia albo minęła godzina - kliknij „Połącz z InPost vonHalsky” jeszcze raz.', 'account_id' => $accountId];
        }
        $this->setState($accountId, ['OAUTH_STATE' => '']);
        if (trim($code) === '') {
            return ['ok' => false, 'message' => 'InPost nie przekazał kodu - zgoda nie została udzielona' . ($error !== '' ? ' (' . $error . ')' : '') . '.', 'account_id' => $accountId];
        }
        try {
            $data = ($this->clientFactory)($account, null)->exchangeCode(trim($code), $verifier, $this->redirectUri());
            $this->saveToken($account, $data);
            $account = $repo->find($accountId) ?? $account;
            $orgs = ($this->clientFactory)($account, (string) $data['access_token'])->organizations();
            $wanted = trim((string) ($account['config']['organization_id'] ?? ''));
            $org = null;
            foreach ($orgs as $o) {
                if ($wanted !== '' ? (string) ($o['id'] ?? '') === $wanted : true) {
                    $org = $o;
                    break;
                }
            }
            if ($org === null) {
                $this->setState($accountId, ['ORG_ID' => '', 'ORG_NAME' => '']);
                return ['ok' => false, 'message' => $wanted !== ''
                    ? 'Połączono, ale konto nie ma dostępu do organizacji ' . $wanted . ' - popraw ID w ustawieniach albo zostaw puste.'
                    : 'Połączono, ale InPost nie zwrócił żadnej organizacji (sklepu) dla tego konta.', 'account_id' => $accountId];
            }
            $this->setState($accountId, ['ORG_ID' => (string) $org['id'], 'ORG_NAME' => trim((string) ($org['name'] ?? '')), 'LAST_ERROR' => '']);
            $more = $wanted === '' && count($orgs) > 1
                ? ' Konto ma ' . count($orgs) . ' organizacje - użyto pierwszej; inną wybierzesz, wpisując jej ID w ustawieniach (' . implode(', ', array_map(
                    static fn(array $o): string => trim((string) ($o['name'] ?? '')) . ' = ' . ($o['id'] ?? ''), array_slice($orgs, 0, 5))) . ').'
                : '';
            return ['ok' => true, 'message' => 'Połączono ze sklepem InPost vonHalsky „' . trim((string) ($org['name'] ?? $org['id'])) . '”.' . $more, 'account_id' => $accountId];
        } catch (\Throwable $e) {
            Logger::warn('vonHalsky: łączenie konta #' . $accountId . ' nie powiodło się: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Nie udało się połączyć: ' . $e->getMessage(), 'account_id' => $accountId];
        }
    }

    private function saveToken(array $account, array $data): void
    {
        $ttl = (int) ($data['expires_in'] ?? 0);
        $exp = $this->now() + ($ttl > 0 ? $ttl : 540);
        (new IntegrationAccountRepository($this->pdo))->saveTokens(
            (int) $account['id'],
            (string) $data['access_token'],
            isset($data['refresh_token']) && $data['refresh_token'] !== '' ? (string) $data['refresh_token'] : ($account['refresh_token'] ?? null),
            (new \DateTimeImmutable('@' . $exp))->setTimezone(new \DateTimeZone(date_default_timezone_get()))
        );
    }

    /** Klient z ważnym tokenem (odnawia go, gdy wygasa). */
    public function client(array $account): object
    {
        $id = (int) $account['id'];
        if (!self::connected($account)) {
            throw new \RuntimeException('Konto InPost vonHalsky nie jest połączone - kliknij „Połącz z InPost vonHalsky” na stronie integracji.');
        }
        $token = trim((string) ($account['access_token'] ?? ''));
        $expires = !empty($account['expires_at']) ? (int) strtotime((string) $account['expires_at']) : 0;
        if ($token === '' || $expires - $this->now() < self::REFRESH_BEFORE) {
            $refresh = trim((string) ($account['refresh_token'] ?? ''));
            if ($refresh === '') {
                throw new \RuntimeException('Token InPost vonHalsky wygasł, a brak refresh tokenu - połącz konto jeszcze raz.');
            }
            try {
                $data = ($this->clientFactory)($account, null)->refreshToken($refresh);
            } catch (\Throwable $e) {
                throw new \RuntimeException('Nie udało się odnowić tokenu InPost (refresh token ważny ok. 30 dni) - połącz konto jeszcze raz. ' . $e->getMessage(), 0, $e);
            }
            $this->saveToken($account, $data);
            $token = (string) $data['access_token'];
        }
        return ($this->clientFactory)($account, $token);
    }

    /** Test połączenia: lista organizacji z tokenem. @return array{ok:bool,message:string} */
    public function test(array $account): array
    {
        try {
            $orgs = $this->client($account)->organizations();
            $names = array_map(static fn(array $o): string => trim((string) ($o['name'] ?? $o['id'] ?? '?')), $orgs);
            $org = $this->organizationId($account);
            return ['ok' => true, 'message' => 'Połączenie OK. Organizacje: ' . ($names !== [] ? implode(', ', $names) : 'brak') . '.'
                . ($org !== '' ? ' CRM używa: ' . $org . '.' : ' Nie wybrano organizacji - połącz konto jeszcze raz albo wpisz jej ID.')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    // ---------------------------------------------------------------- cykl workera

    /** Wszystkie konta: zamówienia, przyjmowanie, stany i ceny. Błąd jednego kroku nie zatrzymuje reszty. */
    public function run(): array
    {
        $out = ['orders' => 0, 'accepted' => 0, 'stock' => 0];
        foreach ($this->accounts() as $account) {
            if (!self::connected($account)) {
                continue;
            }
            $id = (int) $account['id'];
            $errors = [];
            try {
                $client = $this->client($account);
                if ($this->organizationId($account) === '') {
                    throw new \RuntimeException('Nie wybrano organizacji (sklepu) - połącz konto jeszcze raz albo wpisz ID organizacji w ustawieniach.');
                }
            } catch (\Throwable $e) {
                $this->setState($id, ['LAST_RUN' => $this->nowSql(), 'LAST_ERROR' => mb_substr($e->getMessage(), 0, 500)]);
                Logger::warn('vonHalsky #' . $id . ': ' . $e->getMessage());
                continue;
            }
            foreach (['orders' => 'syncOrders', 'accepted' => 'acceptPending', 'stock' => 'pushStock'] as $key => $step) {
                try {
                    $out[$key] += $this->{$step}($account, $client);
                } catch (\Throwable $e) {
                    $errors[] = ['orders' => 'Zamówienia', 'accepted' => 'Przyjmowanie', 'stock' => 'Stany i ceny'][$key] . ': ' . $e->getMessage();
                    Logger::warn('vonHalsky #' . $id . ' [' . $key . ']: ' . $e->getMessage());
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
        $org = $this->organizationId($account);
        $from = (int) $this->state($id, 'ORDERS_FROM', '0');
        if ($from <= 0) {
            $days = max(1, min(60, (int) ($account['config']['import_days'] ?? 7) ?: 7));
            $from = $this->now() - $days * 86400;
        }
        $maxSeen = $from;
        $n = 0;
        $size = $client::ORDERS_PAGE_MAX;
        for ($page = 0; $page < self::PAGES_PER_RUN; $page++) {
            $orders = $client->orders($org, $from, $page * $size, $size);
            foreach ($orders as $order) {
                if (empty($order['id'])) {
                    continue;
                }
                $this->importOrder($account, $order);
                $updated = strtotime((string) ($order['updatedAt'] ?? $order['createdAt'] ?? '')) ?: 0;
                $maxSeen = max($maxSeen, $updated);
                $n++;
            }
            if (count($orders) < $size) {
                break;
            }
        }
        // Filtr updatedAtGte obejmuje granicę - ostatnie zamówienie wróci raz jeszcze, zapis jest idempotentny.
        $this->setState($id, ['ORDERS_FROM' => (string) $maxSeen]);
        return $n;
    }

    /** Zapis jednego zamówienia vonHalsky (nowe albo aktualizacja). @return int woo_order_id w CRM */
    public function importOrder(array $account, array $order): int
    {
        $accountId = (int) $account['id'];
        $vhId = (string) $order['id'];
        $status = strtoupper((string) ($order['status'] ?? ''));
        $link = $this->link($accountId, $vhId);

        $skus = [];
        foreach ((array) ($order['orderLines'] ?? []) as $line) {
            $sku = self::lineSku(is_array($line) ? $line : []);
            if ($sku !== '') {
                $skus[$sku] = true;
            }
        }
        $products = [];
        if ($skus !== []) {
            $st = $this->pdo->prepare('SELECT id, sku FROM products WHERE sku IN (' . implode(',', array_fill(0, count($skus), '?')) . ')');
            $st->execute(array_keys($skus));
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
            $wooId = $this->insertOrder($accountId, $vhId, $status, $fields, $payload);
            $link = $this->link($accountId, $vhId);
            Logger::info("vonHalsky: nowe zamówienie {$vhId} -> #{$wooId}", ['status' => $status, 'total' => $fields['total']]);
        } else {
            $wooId = (int) $link['woo_order_id'];
            $this->pdo->prepare('UPDATE woo_orders SET status = ?, currency = ?, total = ?, customer_name = ?, customer_email = ?, payload = ? WHERE woo_order_id = ?')
                ->execute([$fields['status'], $fields['currency'], $fields['total'], $fields['customer_name'], $fields['customer_email'],
                    json_encode($payload, JSON_UNESCAPED_UNICODE), $wooId]);
            // Anulowanie w vonHalsky zmienia status CRM tylko, gdy nikt jeszcze nie ruszył zamówienia.
            if (in_array($status, self::DEAD_STATUSES, true)) {
                $this->pdo->prepare('UPDATE woo_orders SET pase_status = ? WHERE woo_order_id = ? AND pase_status = ?')
                    ->execute([OrderStatus::CANCELLED, $wooId, OrderStatus::NEW]);
            }
        }
        (new VirtualProducts($this->pdo))->applyToOrder($wooId, (array) ($payload['line_items'] ?? []));
        $this->pdo->prepare('UPDATE vonhalsky_orders SET vh_status = ?, updated_at = ? WHERE id = ?')
            ->execute([$status, $this->nowSql(), (int) $link['id']]);
        if ($status !== 'CREATED' && in_array((string) ($link['accept_state'] ?? ''), ['', 'error'], true)) {
            // Rozstrzygnięte w InPost (np. przyjęte w panelu) - nie ma czego przyjmować.
            $this->pdo->prepare("UPDATE vonhalsky_orders SET accept_state = 'done', accept_error = NULL WHERE id = ?")->execute([(int) $link['id']]);
        }

        // Magazyn: czekające na realizację zdejmuje stan raz, odrzucone/anulowane po zdjęciu oddaje.
        if (in_array($status, self::LIVE_STATUSES, true) && ($link['stock_state'] ?? null) === null) {
            $this->takeStock((int) $link['id'], $wooId, $payload['line_items']);
        } elseif (in_array($status, self::DEAD_STATUSES, true) && ($link['stock_state'] ?? null) === 'taken') {
            $this->returnStock((int) $link['id'], $wooId, (string) ($link['stock_lines'] ?? '[]'));
        }

        if ($this->ruleEngine !== null && in_array($status, self::LIVE_STATUSES, true) && !$orders->automationsDone($wooId)) {
            try {
                $this->ruleEngine->dispatch('order.imported', $wooId, $payload);
                $orders->markAutomationsDone($wooId);
            } catch (\Throwable $e) {
                Logger::error("vonHalsky: automatyzacje zamówienia #{$wooId} nie zadziałały", ['error' => $e->getMessage()]);
            }
        }
        if ($this->ruleEngine !== null) {
            \Pase\Automation\OrderEvents::paidTransition($this->pdo, $wooId, $wasPaid, $this->ruleEngine);
        }
        return $wooId;
    }

    private function link(int $accountId, string $vhId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM vonhalsky_orders WHERE integration_id = ? AND vh_order_id = ?');
        $st->execute([$accountId, $vhId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Nowy wiersz woo_orders z numerem z zakresu vonHalsky (przy równoległym zapisie - kolejny numer). */
    private function insertOrder(int $accountId, string $vhId, string $status, array $fields, array $payload): int
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
                    $id, $accountId, $vhId, $status, self::paseStatus($status), $fields['currency'], $fields['total'],
                    $fields['customer_name'], $fields['customer_email'], $payload['date_created'], bin2hex(random_bytes(24)),
                    json_encode($payload, JSON_UNESCAPED_UNICODE),
                ]);
                $this->pdo->prepare('INSERT INTO vonhalsky_orders (integration_id, vh_order_id, woo_order_id, vh_status, updated_at) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$accountId, $vhId, $id, $status, $this->nowSql()]);
                $this->pdo->commit();
            } catch (\PDOException $e) {
                $this->pdo->rollBack();
                if ($this->link($accountId, $vhId) !== null) {
                    return (int) $this->link($accountId, $vhId)['woo_order_id'];
                }
                if (!preg_match('/Duplicate|UNIQUE|PRIMARY/i', $e->getMessage())) {
                    throw $e;
                }
                continue;
            }
            (new WooOrderRepository($this->pdo))->assignNumberIfMissing($id);
            return $id;
        }
        throw new \RuntimeException('Nie udało się nadać numeru zamówieniu vonHalsky ' . $vhId);
    }

    /** Status CRM przy pierwszym imporcie. */
    public static function paseStatus(string $status): string
    {
        return in_array(strtoupper($status), self::DEAD_STATUSES, true) ? OrderStatus::CANCELLED : OrderStatus::NEW;
    }

    /** SKU pozycji do dopasowania z magazynem: SKU produktu, a bez niego externalId oferty. */
    private static function lineSku(array $line): string
    {
        $offer = is_array($line['offer'] ?? null) ? $line['offer'] : [];
        $sku = trim((string) ($offer['product']['sku'] ?? ''));
        return $sku !== '' ? $sku : trim((string) ($offer['externalId'] ?? ''));
    }

    private static function amount(mixed $money): float
    {
        return is_array($money) ? round((float) ($money['amount'] ?? 0), 2) : 0.0;
    }

    private static function money(float $v): string
    {
        return number_format($v, 2, '.', '');
    }

    /** „Jan Kowalski” -> [Jan, Kowalski]. */
    private static function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        $pos = mb_strrpos($name, ' ');
        return $pos === false ? [$name, ''] : [mb_substr($name, 0, $pos), mb_substr($name, $pos + 1)];
    }

    /**
     * Zamówienie vonHalsky w formacie Woo (strona zamówienia, faktura, wydruki, klienci). Surowe dane zostają pod 'vonhalsky'.
     * @param array<string,int> $products SKU w CRM => id produktu
     */
    public static function toWoo(array $order, array $products = []): array
    {
        $addr = static function (array $a): array {
            $line = trim((string) ($a['street'] ?? '') . ' ' . (string) ($a['building'] ?? ''));
            $flat = trim((string) ($a['flat'] ?? ''));
            return [
                'address_1' => $line . ($flat !== '' ? '/' . $flat : ''),
                'address_2' => '',
                'city'      => (string) ($a['city'] ?? ''),
                'postcode'  => (string) ($a['postCode'] ?? ''),
                'country'   => strtoupper((string) ($a['countryCode'] ?? 'PL')) ?: 'PL',
            ];
        };
        $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
        $delivery = is_array($order['delivery'] ?? null) ? $order['delivery'] : [];
        $apm = strtoupper((string) ($delivery['deliveryType'] ?? '')) === 'APM';

        // Odbiorca: dane z dostawy (imię i nazwisko w jednym polu), bez nich - kupujący.
        [$first, $last] = trim((string) ($delivery['name'] ?? '')) !== ''
            ? self::splitName((string) $delivery['name'])
            : [trim((string) ($customer['firstName'] ?? '')), trim((string) ($customer['lastName'] ?? ''))];
        $delAddr = is_array($delivery['address'] ?? null) ? $delivery['address'] : (is_array($customer['address'] ?? null) ? $customer['address'] : []);
        $phone = (string) (($delivery['phoneNumber'] ?? '') !== '' ? $delivery['phoneNumber'] : ($customer['phoneNumber'] ?? ''));
        $shipping = ['first_name' => $first, 'last_name' => $last, 'company' => ''] + $addr($apm && !isset($delivery['address']) ? [] : $delAddr) + ['phone' => $phone];

        // Faktura: dane z invoice (firma z NIP albo osoba), bez nich - kupujący z jego adresem.
        $inv = is_array($order['invoice'] ?? null) ? $order['invoice'] : null;
        $custAddr = is_array($customer['address'] ?? null) ? $customer['address'] : $delAddr;
        $billing = [
            'first_name' => trim((string) ($customer['firstName'] ?? '')) ?: $first,
            'last_name'  => trim((string) ($customer['lastName'] ?? '')) ?: $last,
            'company'    => '',
        ] + $addr($custAddr) + ['phone' => (string) ($customer['phoneNumber'] ?? $phone)];
        if ($inv !== null) {
            $company = trim((string) ($inv['companyName'] ?? ''));
            $billing = [
                'first_name' => $company !== '' ? '' : (trim((string) ($inv['firstName'] ?? '')) ?: $billing['first_name']),
                'last_name'  => $company !== '' ? '' : (trim((string) ($inv['lastName'] ?? '')) ?: $billing['last_name']),
                'company'    => $company,
            ] + $addr(is_array($inv['address'] ?? null) ? $inv['address'] : $custAddr) + ['phone' => $billing['phone']];
            $nip = preg_replace('/\s+/', '', (string) ($inv['taxId'] ?? '')) ?? '';
            if ($nip !== '') {
                $prefix = strtoupper(trim((string) ($inv['taxIdPrefix'] ?? '')));
                $billing['nip'] = ($prefix !== '' && $prefix !== 'PL' ? $prefix : '') . $nip;
            }
        }
        $billing['email'] = (string) (($customer['email'] ?? '') !== '' ? $customer['email'] : ($inv['email'] ?? $delivery['email'] ?? ''));

        // Pozycje: API podaje linię na ofertę (często osobno każdą sztukę) - łączymy te same oferty w jedną pozycję.
        $items = [];
        $currency = '';
        foreach ((array) ($order['orderLines'] ?? []) as $line) {
            if (!is_array($line) || !is_array($line['offer'] ?? null)) {
                continue;
            }
            $offer = $line['offer'];
            $price = self::amount($offer['finalPrice'] ?? $offer['price'] ?? null);
            $currency = $currency !== '' ? $currency : (string) ($offer['finalPrice']['currency'] ?? $offer['price']['currency'] ?? '');
            $qty = max(1, (int) ($line['quantity'] ?? 1));
            $sku = self::lineSku($line);
            $key = (string) ($offer['offerId'] ?? '') . '|' . self::money($price);
            if (isset($items[$key])) {
                $items[$key]['quantity'] += $qty;
                $items[$key]['total'] = self::money($price * $items[$key]['quantity']);
                continue;
            }
            $items[$key] = [
                'id'                => (string) ($offer['offerId'] ?? count($items)),
                'product_id'        => $sku !== '' ? (int) ($products[$sku] ?? 0) : 0,
                'name'              => trim((string) ($offer['product']['name'] ?? '')) ?: 'Pozycja vonHalsky',
                'sku'               => $sku !== '' ? $sku : null,
                'quantity'          => $qty,
                // Ceny brutto (jak Allegro): bez total_tax faktura liczy brutto = total.
                'price'             => $price,
                'total'             => self::money($price * $qty),
                'vonhalsky_offer_id' => (string) ($offer['offerId'] ?? ''),
                'ean'               => (string) ($offer['product']['ean'] ?? ''),
                'in_warehouse'      => $sku !== '' && !empty($products[$sku]),
            ];
        }

        $shipFee = self::money(self::amount($delivery['price'] ?? null));
        $meta = [];
        $point = trim((string) ($delivery['deliveryPoint'] ?? ''));
        if ($apm && $point !== '') {
            // Paczkomat w meta metody wysyłki - Support\PickupPoint znajdzie go po kluczu pickup_point_id.
            $meta = [
                ['key' => 'pickup_point_id', 'value' => $point],
                ['key' => 'pickup_point_name', 'value' => 'Paczkomat ' . $point],
                ['key' => 'pickup_point_provider', 'value' => 'inpost'],
            ];
        }
        $payments = (array) ($order['paymentDetails']['payments'] ?? []);
        $paid = 0;
        foreach ($payments as $p) {
            $paid = max($paid, is_array($p) ? (strtotime((string) ($p['paymentDate'] ?? '')) ?: 0) : 0);
        }
        $payType = strtoupper((string) ($order['paymentDetails']['selectedPaymentType'] ?? ''));
        $cod = $payType === 'CASH_ON_DELIVERY';
        $created = strtotime((string) ($order['createdAt'] ?? '')) ?: 0;
        $total = self::amount($order['finalPrice'] ?? null);
        $note = trim((string) ($order['comment'] ?? ''));
        $courierNote = trim((string) ($delivery['courierNote'] ?? ''));

        return [
            'id'                   => (string) $order['id'],
            'number'               => (string) $order['id'],
            'status'               => strtoupper((string) ($order['status'] ?? '')),
            'created_via'          => 'vonhalsky',
            'date_created'         => $created > 0 ? gmdate('Y-m-d H:i:s', $created) : null,
            'date_paid'            => $paid > 0 ? gmdate('Y-m-d H:i:s', $paid) : ($created > 0 && !$cod ? gmdate('Y-m-d H:i:s', $created) : null),
            'currency'             => (string) ($order['finalPrice']['currency'] ?? '') ?: ($currency !== '' ? $currency : 'PLN'),
            'total'                => self::money($total),
            'shipping_total'       => $shipFee,
            'billing'              => $billing,
            'shipping'             => $shipping,
            'line_items'           => array_values($items),
            'shipping_lines'       => [[
                'method_title' => $apm ? 'InPost Paczkomat 24/7' : 'InPost Kurier',
                'total'        => $shipFee,
                'meta_data'    => $meta,
            ]],
            'payment_method'       => $cod ? 'cod' : 'vonhalsky',
            'payment_method_title' => $cod ? 'InPost vonHalsky — za pobraniem' : 'InPost vonHalsky' . ($payType !== '' && $payType !== 'UNKNOWN' ? ' (' . $payType . ')' : ''),
            'customer_note'        => trim($note . ($courierNote !== '' ? ($note !== '' ? "\n" : '') . 'Dla kuriera: ' . $courierNote : '')),
            'meta_data'            => [],
            'vonhalsky'            => $order,
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
            $claim = $this->pdo->prepare("UPDATE vonhalsky_orders SET stock_state = 'taken', stock_lines = ? WHERE id = ? AND stock_state IS NULL");
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
        $this->queueShopPush($wooId, array_keys($lines), 'vonhalsky');
    }

    /** Oddaje stan zdjęty przy imporcie (zamówienie odrzucone albo anulowane). */
    private function returnStock(int $linkId, int $wooId, string $linesJson): void
    {
        $lines = json_decode($linesJson, true) ?: [];
        $this->atomic(function () use ($linkId, $lines): void {
            $claim = $this->pdo->prepare("UPDATE vonhalsky_orders SET stock_state = 'returned' WHERE id = ? AND stock_state = 'taken'");
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
        Logger::info("vonHalsky: zamówienie #{$wooId} odrzucone/anulowane - oddano stan", ['produkty' => $lines]);
        $this->queueShopPush($wooId, array_keys($lines), 'vonhalsky-cancel');
    }

    private function queueShopPush(int $wooId, array $productIds, string $why): void
    {
        foreach ($productIds as $pid) {
            try {
                $this->queue?->enqueue('woo.stock.push', ['product_id' => (int) $pid], 'woo.stock.push:' . $pid . ':' . $why . ':' . $wooId);
            } catch (\Throwable $e) {
                Logger::warn("vonHalsky #{$wooId}: nie zakolejkowano stanu produktu {$pid} do sklepu: " . $e->getMessage());
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

    // ---------------------------------------------------------------- przyjmowanie / odrzucanie

    /** Automatyczne przyjęcie nowych zamówień (auto_accept w ustawieniach konta). @return int liczba przyjętych */
    public function acceptPending(array $account, object $client): int
    {
        if (($account['config']['auto_accept'] ?? 'on') === 'off') {
            return 0;
        }
        $st = $this->pdo->prepare("SELECT * FROM vonhalsky_orders WHERE integration_id = ? AND vh_status = 'CREATED'
            AND (accept_state IS NULL OR (accept_state = 'error' AND accept_attempts < " . self::ACCEPT_MAX_ATTEMPTS . ')) ORDER BY id LIMIT 50');
        $st->execute([(int) $account['id']]);
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $link) {
            if ($this->decide($account, $client, $link, true)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Przyjęcie albo odrzucenie zamówienia z poziomu strony zamówienia.
     * @return array{ok:bool,message:string}
     */
    public function decideOrder(int $wooOrderId, bool $accept): array
    {
        $link = $this->orderLink($wooOrderId);
        if ($link === null) {
            return ['ok' => false, 'message' => 'To nie jest zamówienie z InPost vonHalsky.'];
        }
        if (($link['vh_status'] ?? '') !== 'CREATED') {
            return ['ok' => false, 'message' => 'Zamówienie ma w InPost status „' . (self::STATUS_LABELS[(string) $link['vh_status']] ?? $link['vh_status']) . '” - nie da się go już ' . ($accept ? 'przyjąć' : 'odrzucić') . '.'];
        }
        $account = (new IntegrationAccountRepository($this->pdo))->find((int) $link['integration_id']);
        if ($account === null) {
            return ['ok' => false, 'message' => 'Konto InPost vonHalsky tego zamówienia zostało usunięte.'];
        }
        try {
            $client = $this->client($account);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        $ok = $this->decide($account, $client, $link, $accept);
        $fresh = $this->orderLink($wooOrderId) ?? $link;
        return $ok
            ? ['ok' => true, 'message' => $accept ? 'Zamówienie przyjęte w InPost vonHalsky.' : 'Zamówienie odrzucone w InPost vonHalsky - stan wrócił do magazynu.']
            : ['ok' => false, 'message' => (string) ($fresh['accept_error'] ?? 'InPost nie przyjął polecenia.')];
    }

    private function decide(array $account, object $client, array $link, bool $accept): bool
    {
        $org = $this->organizationId($account);
        try {
            $accept ? $client->acceptOrder($org, (string) $link['vh_order_id']) : $client->refuseOrder($org, (string) $link['vh_order_id']);
        } catch (\Throwable $e) {
            $this->pdo->prepare("UPDATE vonhalsky_orders SET accept_state = 'error', accept_error = ?, accept_attempts = accept_attempts + 1, accept_at = ? WHERE id = ?")
                ->execute([mb_substr(($accept ? 'Przyjęcie: ' : 'Odrzucenie: ') . $e->getMessage(), 0, 500), $this->nowSql(), (int) $link['id']]);
            Logger::warn('vonHalsky: ' . ($accept ? 'przyjęcie' : 'odrzucenie') . ' zamówienia ' . $link['vh_order_id'] . ' nie powiodło się: ' . $e->getMessage());
            return false;
        }
        // Polecenie jest asynchroniczne - status w InPost zmieni się chwilę później; lokalnie zapisujemy go od razu.
        $newStatus = $accept ? 'ACCEPTED' : 'REFUSED';
        $this->pdo->prepare('UPDATE vonhalsky_orders SET vh_status = ?, accept_state = ?, accept_error = NULL, accept_at = ? WHERE id = ?')
            ->execute([$newStatus, $accept ? 'accepted' : 'refused', $this->nowSql(), (int) $link['id']]);
        $this->pdo->prepare('UPDATE woo_orders SET status = ? WHERE woo_order_id = ?')->execute([$newStatus, (int) $link['woo_order_id']]);
        if (!$accept) {
            $this->pdo->prepare('UPDATE woo_orders SET pase_status = ? WHERE woo_order_id = ? AND pase_status = ?')
                ->execute([OrderStatus::CANCELLED, (int) $link['woo_order_id'], OrderStatus::NEW]);
            if (($link['stock_state'] ?? null) === 'taken') {
                $this->returnStock((int) $link['id'], (int) $link['woo_order_id'], (string) ($link['stock_lines'] ?? '[]'));
            }
        }
        Logger::info('vonHalsky: zamówienie ' . $link['vh_order_id'] . ($accept ? ' przyjęte' : ' odrzucone'));
        return true;
    }

    // ---------------------------------------------------------------- stany i ceny

    /** Odświeża listę ofert (co CATALOG_EVERY_MIN albo na żądanie). @return int liczba ofert (-1 = jeszcze nie pora) */
    public function refreshCatalog(array $account, object $client, bool $force = false): int
    {
        $id = (int) $account['id'];
        if (!$force && $this->now() - (int) $this->state($id, 'CATALOG_AT', '0') < self::CATALOG_EVERY_MIN * 60) {
            return -1;
        }
        $org = $this->organizationId($account);
        $seen = $this->nowSql();
        $seenIds = [];
        $n = 0;
        $find = $this->pdo->prepare('SELECT id FROM vonhalsky_offers WHERE integration_id = ? AND offer_id = ?');
        $ins = $this->pdo->prepare('INSERT INTO vonhalsky_offers (integration_id, offer_id, match_sku, product_name, offer_status, vh_stock, stock_unit, vh_price, currency, seen_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $upd = $this->pdo->prepare('UPDATE vonhalsky_offers SET match_sku = ?, product_name = ?, offer_status = ?, vh_stock = ?, stock_unit = ?, vh_price = ?, currency = ?, seen_at = ? WHERE id = ?');
        for ($page = 0; $page < 200; $page++) {
            [$list] = $client->offers($org, $page * self::OFFERS_PAGE, self::OFFERS_PAGE);
            foreach ($list as $o) {
                $offerId = trim((string) ($o['id'] ?? ''));
                if ($offerId === '') {
                    continue;
                }
                $sku = trim((string) ($o['product']['sku'] ?? ''));
                $ext = trim((string) ($o['externalId'] ?? ''));
                $price = $o['price']['grossPrice'] ?? null;
                $vals = [
                    ($sku !== '' ? $sku : $ext) ?: null,
                    mb_substr((string) ($o['product']['name'] ?? ''), 0, 255),
                    (string) ($o['status'] ?? '') ?: null,
                    isset($o['stock']['quantity']) ? (int) $o['stock']['quantity'] : null,
                    (string) ($o['stock']['unit'] ?? '') ?: null,
                    is_array($price) ? round((float) ($price['amount'] ?? 0), 2) : null,
                    is_array($price) ? ((string) ($price['currency'] ?? '') ?: null) : null,
                    $seen,
                ];
                $find->execute([$id, $offerId]);
                $rowId = $find->fetchColumn();
                if ($rowId !== false) {
                    $upd->execute([...$vals, (int) $rowId]);
                    $seenIds[(int) $rowId] = true;
                } else {
                    $ins->execute([$id, $offerId, ...$vals]);
                    $seenIds[(int) $this->pdo->lastInsertId()] = true;
                }
                $n++;
            }
            if (count($list) < self::OFFERS_PAGE) {
                break;
            }
        }
        $all = $this->pdo->prepare('SELECT id FROM vonhalsky_offers WHERE integration_id = ?');
        $all->execute([$id]);
        $del = $this->pdo->prepare('DELETE FROM vonhalsky_offers WHERE id = ?');
        foreach ($all->fetchAll(PDO::FETCH_COLUMN) as $rowId) {
            if (!isset($seenIds[(int) $rowId])) {
                $del->execute([(int) $rowId]);
            }
        }
        $this->setState($id, ['CATALOG_AT' => (string) $this->now()]);
        return $n;
    }

    /** Cena brutto do vonHalsky: cena w magazynie × mnożnik z ustawień konta; null, gdy produkt nie ma ceny. */
    public static function offerPrice(array $account, mixed $pasePrice): ?float
    {
        if ($pasePrice === null || (float) $pasePrice <= 0) {
            return null;
        }
        $mult = (float) str_replace(',', '.', (string) ($account['config']['price_multiplier'] ?? '1'));
        if ($mult <= 0) {
            $mult = 1.0;
        }
        return round((float) $pasePrice * $mult, 2);
    }

    /**
     * Wysyła stan CRM (i cenę, gdy włączona) do ofert, które mają inne niż ostatnio wysłane. Zamknięte oferty pomijamy.
     * @return int liczba przyjętych zmian ofert
     */
    public function pushStock(array $account, object $client): int
    {
        $stockOn = ($account['config']['push_stock'] ?? 'on') !== 'off';
        $priceOn = ($account['config']['push_price'] ?? 'off') === 'on';
        if (!$stockOn && !$priceOn) {
            return 0;
        }
        $id = (int) $account['id'];
        $this->refreshCatalog($account, $client);
        $st = $this->pdo->prepare("SELECT o.id, o.offer_id, o.stock_unit, o.currency, o.pushed_qty, o.pushed_price, p.pase_stock, p.pase_price
            FROM vonhalsky_offers o JOIN products p ON p.sku = o.match_sku
            WHERE o.integration_id = ? AND (o.offer_status IS NULL OR o.offer_status NOT IN ('CLOSED', 'REJECTED'))
              AND (p.pase_stock IS NOT NULL OR p.pase_price IS NOT NULL)
            ORDER BY o.id");
        $st->execute([$id]);
        $stocks = [];
        $prices = [];
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $offerId = (string) $r['offer_id'];
            $changed = false;
            if ($stockOn && $r['pase_stock'] !== null) {
                $qty = max(0, (int) $r['pase_stock']);
                if ($r['pushed_qty'] === null || (int) $r['pushed_qty'] !== $qty) {
                    $stocks[$offerId] = ['quantity' => $qty, 'unit' => (string) ($r['stock_unit'] ?? '') ?: 'UNIT'];
                    $changed = true;
                }
            }
            $price = $priceOn ? self::offerPrice($account, $r['pase_price']) : null;
            if ($price !== null && ($r['pushed_price'] === null || abs((float) $r['pushed_price'] - $price) >= 0.005)) {
                $prices[$offerId] = ['amount' => $price, 'currency' => (string) ($r['currency'] ?? '') ?: 'PLN'];
                $changed = true;
            }
            if ($changed) {
                $rows[$offerId] = $r;
                if (count($rows) >= self::PUSH_LIMIT) {
                    break;
                }
            }
        }
        if ($rows === []) {
            return 0;
        }
        $org = $this->organizationId($account);
        $failed = [];
        $bad = $this->pdo->prepare('UPDATE vonhalsky_offers SET error = ? WHERE id = ?');
        $limit = $client::BATCH_LIMIT;
        foreach (['stock' => $stocks, 'price' => $prices] as $kind => $changes) {
            foreach (array_chunk($changes, $limit, true) as $chunk) {
                try {
                    $res = $kind === 'stock' ? $client->updateStocks($org, $chunk) : $client->updatePrices($org, $chunk);
                } catch (\Throwable $e) {
                    foreach (array_keys($chunk) as $offerId) {
                        $failed[$offerId][$kind] = $e->getMessage();
                    }
                    continue;
                }
                foreach ($res as $cmd) {
                    if (strtoupper((string) ($cmd['status'] ?? '')) === 'FAILURE' && isset($chunk[(string) ($cmd['offerId'] ?? '')])) {
                        $failed[(string) $cmd['offerId']][$kind] = 'InPost odrzucił zmianę.';
                    }
                }
            }
        }
        $n = 0;
        foreach ($rows as $offerId => $r) {
            $set = ['pushed_at = ?'];
            $vals = [$this->nowSql()];
            if (isset($stocks[$offerId]) && !isset($failed[$offerId]['stock'])) {
                array_push($set, 'pushed_qty = ?', 'vh_stock = ?');
                array_push($vals, $stocks[$offerId]['quantity'], $stocks[$offerId]['quantity']);
            }
            if (isset($prices[$offerId]) && !isset($failed[$offerId]['price'])) {
                array_push($set, 'pushed_price = ?', 'vh_price = ?');
                array_push($vals, $prices[$offerId]['amount'], $prices[$offerId]['amount']);
            }
            $err = isset($failed[$offerId]) ? implode(' ', array_map(
                static fn(string $k, string $m): string => ($k === 'stock' ? 'Stan: ' : 'Cena: ') . $m, array_keys($failed[$offerId]), $failed[$offerId])) : null;
            $set[] = 'error = ?';
            $vals[] = $err !== null ? mb_substr($err, 0, 500) : null;
            $vals[] = (int) $r['id'];
            $this->pdo->prepare('UPDATE vonhalsky_offers SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
            if ($err === null) {
                $n++;
            } else {
                Logger::warn('vonHalsky: oferta ' . $offerId . ' nie zaktualizowana: ' . $err);
            }
        }
        if ($failed !== [] && $n === 0) {
            $first = reset($failed);
            throw new \RuntimeException('InPost nie przyjął żadnej zmiany stanu/ceny: ' . (string) reset($first));
        }
        return $n;
    }

    // ---------------------------------------------------------------- strona zamówienia

    /** Wiersz powiązania zamówienia CRM z vonHalsky (do strony zamówienia). */
    public function orderLink(int $wooOrderId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM vonhalsky_orders WHERE woo_order_id = ?');
        $st->execute([$wooOrderId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Paczki z zamówienia (numer, status) do strony zamówienia. @return array<int,array{number:string,status:string}> */
    public static function parcels(array $payload): array
    {
        $out = [];
        foreach ((array) ($payload['vonhalsky']['delivery']['parcels'] ?? []) as $p) {
            if (is_array($p) && trim((string) ($p['trackingNumber'] ?? '')) !== '') {
                $st = strtoupper((string) ($p['status'] ?? ''));
                $out[] = ['number' => trim((string) $p['trackingNumber']), 'status' => self::PARCEL_LABELS[$st] ?? $st];
            }
        }
        return $out;
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
