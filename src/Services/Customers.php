<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;

/**
 * Klienci — zamówienia tej samej osoby zebrane w jedną kartę (ile razy kupował, za ile i na jakich kanałach).
 *
 * Klient powstaje z samych zamówień, bez osobnej bazy kontaktów. Każde zamówienie daje klucze:
 *   email:<adres>     e-mail zamówienia (ręczna korekta w CRM ma pierwszeństwo), małymi literami;
 *                     zamaskowane adresy Allegro (@allegromail.pl) pomijamy, bo nie identyfikują osoby
 *   allegro:<id>      konto kupującego na Allegro (etykieta = login)
 *   phone:<cyfry>     telefon kupującego (bez +48/0048), tylko z danych kupującego, nie odbiorcy
 *
 * Zamówienia o wspólnym kluczu to jeden klient. Telefon łączy tylko wtedy, gdy nie przeczy temu,
 * co już wiadomo: dwóch klientów z różnymi e-mailami albo z różnymi kontami Allegro NIE zostanie
 * połączonych samym telefonem (wspólny numer domowy, firmowy), a imię lub nazwisko musi się
 * powtarzać. Dzięki temu telefon łączy zakupy z Allegro ze sklepem, ale nie skleja różnych osób.
 * Tę samą osobę z dwoma e-mailami łączy się ręcznie.
 *
 * Historia przetrwa usunięcie zamówień: customer_orders trzyma migawkę każdego zamówienia (data, numer,
 * kanał, kwota, status, nazwisko i klucze klienta). Zamówienia z CRM są archiwizowane po 3 miesiącach
 * i kasowane po roku, a karta klienta liczy się z migawek, więc nic z niej nie znika. Migawki
 * odświeżamy, dopóki zamówienie istnieje (zmiana statusu, kwoty, poprawka e-maila); zamówienie
 * wyrzucone do kosza przestaje się liczyć. „Przelicz od nowa” układa klientów z migawek, nie z zamówień.
 *
 * Anonimizacja (na prośbę klienta) usuwa z kart nazwisko, e-maile, telefony i loginy, zostawia liczby.
 * Dane klientów nie wychodzą poza panel: nie ma eksportu.
 */
final class Customers
{
    /** Statusy, których nie liczymy jako zakup (nie wliczamy do liczby zamówień ani kwoty). */
    public const NOT_PURCHASE = ['cancelled'];
    /** Statusy liczone jako zakup, ale bez kwoty (pieniądze wróciły do klienta). */
    public const NO_SPEND = ['cancelled', 'refunded'];

    private const LOCK = 'crm_customers_sync';

    public function __construct(private readonly PDO $pdo) {}

    public static function migrate(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $id   = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $tail = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
            id $id,
            name VARCHAR(255) NULL,
            orders_count INT NOT NULL DEFAULT 0,
            cancelled_count INT NOT NULL DEFAULT 0,
            spent_pln DECIMAL(12,2) NOT NULL DEFAULT 0,
            totals TEXT NULL,
            channels TEXT NULL,
            first_order_at VARCHAR(30) NULL,
            last_order_at VARCHAR(30) NULL,
            anonymized TINYINT NOT NULL DEFAULT 0,
            updated_at VARCHAR(30) NOT NULL
        )$tail");
        if (!self::columnExists($pdo, 'customers', 'anonymized')) {
            $pdo->exec('ALTER TABLE customers ADD COLUMN anonymized TINYINT NOT NULL DEFAULT 0');
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS customer_keys (
            k VARCHAR(191) NOT NULL PRIMARY KEY,
            customer_id BIGINT NOT NULL,
            kind VARCHAR(16) NOT NULL,
            label VARCHAR(255) NULL
        )$tail");
        // Pierwsza wersja (PR #28) trzymała tylko powiązanie zamówienie → klient, liczone z woo_orders.
        // Wszystkie te zamówienia wciąż są w CRM, więc stare dane da się bezpiecznie ułożyć od nowa z migawkami.
        if (self::tableExists($pdo, 'customer_orders') && !self::columnExists($pdo, 'customer_orders', 'order_keys')) {
            $pdo->exec('DROP TABLE customer_orders');
            $pdo->exec('DELETE FROM customer_keys');
            $pdo->exec('DELETE FROM customers');
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS customer_orders (
            woo_order_id BIGINT NOT NULL PRIMARY KEY,
            customer_id BIGINT NULL,
            order_date VARCHAR(30) NULL,
            pase_number BIGINT NULL,
            order_number VARCHAR(64) NULL,
            channel_key VARCHAR(32) NULL,
            channel_label VARCHAR(190) NULL,
            total DECIMAL(12,2) NULL,
            currency VARCHAR(8) NULL,
            pase_status VARCHAR(64) NULL,
            customer_name VARCHAR(255) NULL,
            order_keys TEXT NULL,
            trashed TINYINT NOT NULL DEFAULT 0,
            anonymized TINYINT NOT NULL DEFAULT 0,
            src_updated VARCHAR(30) NULL
        )$tail");
        $pdo->exec("CREATE TABLE IF NOT EXISTS customer_merges (
            id $id,
            key_a VARCHAR(191) NOT NULL,
            key_b VARCHAR(191) NOT NULL,
            created_by VARCHAR(190) NULL,
            created_at VARCHAR(30) NOT NULL
        )$tail");
        $indexes = [
            'idx_ckeys_customer'   => 'customer_keys (customer_id)',
            'idx_corders_customer' => 'customer_orders (customer_id)',
            'idx_corders_number'   => 'customer_orders (order_number)',
            'idx_customers_last'   => 'customers (last_order_at)',
        ];
        foreach ($indexes as $name => $on) {
            if ($driver === 'mysql') {
                $table = strtok($on, ' ');
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
                $stmt->execute([$table, $name]);
                if ((int) $stmt->fetchColumn() === 0) {
                    $pdo->exec("CREATE INDEX $name ON $on");
                }
            } else {
                $pdo->exec("CREATE INDEX IF NOT EXISTS $name ON $on");
            }
        }
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
            $stmt->execute([$table]);
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            $stmt->execute([$table]);
        }
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return in_array($column, array_column($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Trwałe usunięcie zamówienia z kosza: zamówienie wyrzucone do kosza nie liczy się już klientowi.
     * Wołane z WooOrderRepository::purgeMany. Brak tabeli klientów (starsza baza, testy) nie przeszkadza.
     */
    public static function markPurged(PDO $pdo, int $wooOrderId): void
    {
        try {
            $pdo->prepare('UPDATE customer_orders SET trashed = 1 WHERE woo_order_id = ?')->execute([$wooOrderId]);
            // Pusty updated_at = do przeliczenia przy najbliższej synchronizacji.
            $pdo->prepare("UPDATE customers SET updated_at = '' WHERE id = (SELECT customer_id FROM customer_orders WHERE woo_order_id = ?)")->execute([$wooOrderId]);
        } catch (\PDOException $e) {
            // Tabela klientów jeszcze nie istnieje.
        }
    }

    // ------------------------------------------------------------
    //  Klucze zamówienia
    // ------------------------------------------------------------

    public static function normalizeEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));
        if ($email === '' || !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
            return null;
        }
        // Allegro podaje zamaskowany adres pośredniczący, który nie mówi, kim jest kupujący.
        if (preg_match('/@(.+\.)?allegromail\.(pl|com)$/', $email)) {
            return null;
        }
        return $email;
    }

    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '48')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) < 9 || strlen($digits) > 15) {
            return null;
        }
        // Numery-zapychacze wpisywane, byle przejść przez formularz.
        if (preg_match('/^(\d)\1+$/', $digits) || in_array($digits, ['123456789', '987654321', '012345678'], true)) {
            return null;
        }
        return $digits;
    }

    /**
     * Klucze jednego zamówienia (wiersz woo_orders z payloadem).
     * @return list<array{k:string,kind:string,label:string}>
     */
    public static function keysFor(array $row): array
    {
        $payload = is_array($row['payload'] ?? null) ? $row['payload'] : (json_decode((string) ($row['payload'] ?? ''), true) ?: []);
        $isAllegro = (int) ($row['woo_order_id'] ?? 0) >= 9_000_000_000 || isset($payload['buyer']['id']);
        $buyer = is_array($payload['buyer'] ?? null) ? $payload['buyer'] : [];
        $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];

        $rawEmail = $row['local_billing_email'] ?? null;
        if ($rawEmail === null || $rawEmail === '') {
            $rawEmail = $isAllegro ? ($buyer['email'] ?? null) : ($billing['email'] ?? $row['customer_email'] ?? null);
        }
        $rawPhone = $row['local_billing_phone'] ?? null;
        if ($rawPhone === null || $rawPhone === '') {
            $rawPhone = $isAllegro ? ($buyer['phoneNumber'] ?? null) : ($billing['phone'] ?? null);
        }

        $keys = [];
        if ($email = self::normalizeEmail(is_string($rawEmail) ? $rawEmail : null)) {
            $keys[] = ['k' => 'email:' . mb_substr($email, 0, 180), 'kind' => 'email', 'label' => $email];
        }
        if ($isAllegro && !empty($buyer['id'])) {
            $keys[] = ['k' => 'allegro:' . mb_substr((string) $buyer['id'], 0, 170), 'kind' => 'allegro', 'label' => (string) ($buyer['login'] ?? $buyer['id'])];
        }
        if ($phone = self::normalizePhone(is_scalar($rawPhone) ? (string) $rawPhone : null)) {
            $keys[] = ['k' => 'phone:' . $phone, 'kind' => 'phone', 'label' => $phone];
        }
        return $keys;
    }

    // ------------------------------------------------------------
    //  Migawki zamówień i przypisywanie
    // ------------------------------------------------------------

    /** Liczba zamówień jeszcze nieprzypisanych (nowe w CRM + migawki bez klienta). */
    public function pendingCount(): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM woo_orders wo LEFT JOIN customer_orders co ON co.woo_order_id = wo.woo_order_id WHERE co.woo_order_id IS NULL'
        )->fetchColumn() + (int) $this->pdo->query(
            'SELECT COUNT(*) FROM customer_orders WHERE customer_id IS NULL'
        )->fetchColumn();
    }

    /**
     * Dopisuje nowe zamówienia, odświeża migawki zmienionych i przypisuje klientów, w limicie czasu.
     * Równoległe wywołanie (drugi użytkownik, druga karta) nic nie robi, zamiast tworzyć duplikaty.
     * @return array{assigned:int,pending:int,busy:bool}
     */
    public function syncPending(float $budgetSeconds = 15.0): array
    {
        if (!$this->lock()) {
            return ['assigned' => 0, 'pending' => $this->pendingCount(), 'busy' => true];
        }
        $assigned = 0;
        $started = microtime(true);
        $inTime = static fn(): bool => microtime(true) - $started < $budgetSeconds;
        try {
            // 1. Nowe zamówienia → migawki.
            while ($inTime()) {
                $ids = $this->pdo->query(
                    'SELECT wo.woo_order_id FROM woo_orders wo
                     LEFT JOIN customer_orders co ON co.woo_order_id = wo.woo_order_id
                     WHERE co.woo_order_id IS NULL ORDER BY wo.woo_order_id LIMIT 200'
                )->fetchAll(PDO::FETCH_COLUMN);
                if ($ids === []) {
                    break;
                }
                $this->atomic(fn() => $this->snapshot(array_map('intval', $ids)));
            }
            // 2. Zmienione zamówienia (status, kwota, poprawiony e-mail) → odśwież migawki.
            while ($inTime()) {
                $ids = $this->changedOrderIds(200);
                if ($ids === []) {
                    break;
                }
                $this->atomic(function () use ($ids): void {
                    $touched = $this->snapshot($ids);
                    $this->recompute($touched);
                });
            }
            // Klienci oznaczeni do przeliczenia (np. zamówienie trwale usunięte z kosza).
            $stale = array_map('intval', $this->pdo->query("SELECT id FROM customers WHERE updated_at = ''")->fetchAll(PDO::FETCH_COLUMN));
            if ($stale !== []) {
                $this->atomic(fn() => $this->recompute($stale));
            }
            // 3. Migawki bez klienta → klienci (najstarsze najpierw).
            while ($inTime()) {
                $n = $this->atomic(fn(): int => $this->assignPending(200));
                $assigned += $n;
                if ($n === 0) {
                    break;
                }
            }
        } finally {
            $this->unlock();
        }
        return ['assigned' => $assigned, 'pending' => $this->pendingCount(), 'busy' => false];
    }

    /** Klient zamówienia; przypisuje je od razu, jeśli jeszcze nie ma klienta. */
    public function customerIdForOrder(int $wooOrderId): ?int
    {
        $id = $this->mappedCustomer($wooOrderId);
        if ($id !== null || !$this->lock()) {
            return $id;
        }
        try {
            if ($this->mappedCustomer($wooOrderId) === null) {
                $this->atomic(function () use ($wooOrderId): void {
                    $this->snapshot([$wooOrderId]);
                    $this->assignPending(1, [$wooOrderId]);
                });
            }
            return $this->mappedCustomer($wooOrderId);
        } finally {
            $this->unlock();
        }
    }

    /**
     * Przelicza klientów od nowa z migawek (także zamówień już usuniętych z CRM).
     * Ręczne połączenia i anonimizacje zostają; migawki istniejących zamówień odświeżą się przy synchronizacji.
     */
    public function resetAll(): void
    {
        $this->atomic(function (): void {
            $this->pdo->exec('DELETE FROM customer_keys WHERE customer_id IN (SELECT id FROM customers WHERE anonymized = 0)');
            $this->pdo->exec('DELETE FROM customers WHERE anonymized = 0');
            $this->pdo->exec('UPDATE customer_orders SET customer_id = NULL, src_updated = NULL WHERE anonymized = 0');
        });
    }

    /**
     * Po poprawce e-maila / telefonu w zamówieniu: odświeża migawkę i układa od nowa klienta tego zamówienia.
     * @return list<int> ID klientów, do których trafiły jego zamówienia
     */
    public function refreshOrder(int $wooOrderId): array
    {
        $cid = $this->mappedCustomer($wooOrderId);
        if ($cid === null) {
            return [];
        }
        return $this->atomic(function () use ($wooOrderId, $cid): array {
            return $this->reassign($cid, fn() => $this->snapshot([$wooOrderId]));
        });
    }

    private function mappedCustomer(int $wooOrderId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT customer_id FROM customer_orders WHERE woo_order_id = ?');
        $stmt->execute([$wooOrderId]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (int) $v;
    }

    /** Zamówienia, które zmieniły się od ostatniej migawki. @return list<int> */
    private function changedOrderIds(int $limit): array
    {
        if (!in_array('updated_at', $this->orderColumns(), true)) {
            return [];
        }
        return array_map('intval', $this->pdo->query(
            "SELECT wo.woo_order_id FROM woo_orders wo
             JOIN customer_orders co ON co.woo_order_id = wo.woo_order_id
             WHERE co.anonymized = 0 AND (co.src_updated IS NULL OR co.src_updated <> wo.updated_at)
             ORDER BY wo.woo_order_id LIMIT $limit"
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Zapisuje / odświeża migawki zamówień. Gdy zmieniły się dane klienta (e-mail, telefon, nazwisko),
     * klient tego zamówienia jest układany od nowa.
     * @param list<int> $ids
     * @return list<int> klienci do przeliczenia
     */
    private function snapshot(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $cols = array_intersect(['local_billing_email', 'local_billing_phone', 'customer_email', 'updated_at', 'lifecycle', 'pase_number', 'pase_status'], $this->orderColumns());
        $extra = $cols === [] ? '' : ', ' . implode(', ', array_map(static fn($c) => 'wo.' . $c, $cols));
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT wo.woo_order_id, wo.integration_id, wo.order_number, wo.date_created, wo.total, wo.currency, wo.customer_name, wo.payload$extra,
                    ia.type AS channel_type, ia.name AS channel_name
             FROM woo_orders wo LEFT JOIN integration_accounts ia ON ia.id = wo.integration_id
             WHERE wo.woo_order_id IN ($in)"
        );
        $stmt->execute($ids);
        $existing = $this->pdo->prepare('SELECT customer_id, customer_name, order_keys, anonymized FROM customer_orders WHERE woo_order_id = ?');
        $insert = $this->pdo->prepare(
            'INSERT INTO customer_orders (woo_order_id, customer_id, order_date, pase_number, order_number, channel_key, channel_label, total, currency,
                pase_status, customer_name, order_keys, trashed, src_updated) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $update = $this->pdo->prepare(
            'UPDATE customer_orders SET order_date = ?, pase_number = ?, order_number = ?, channel_key = ?, channel_label = ?, total = ?, currency = ?,
                pase_status = ?, customer_name = ?, order_keys = ?, trashed = ?, src_updated = ? WHERE woo_order_id = ?'
        );
        $recompute = [];
        $reassign = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            [$channelKey, $channelLabel] = self::channelOf($row);
            $keys = json_encode(self::keysFor($row), JSON_UNESCAPED_UNICODE);
            $name = trim((string) ($row['customer_name'] ?? '')) !== '' ? mb_substr(trim((string) $row['customer_name']), 0, 250) : null;
            $fields = [
                $row['date_created'] ?? null, $row['pase_number'] ?? null, $row['order_number'] ?? null, $channelKey, mb_substr($channelLabel, 0, 190),
                $row['total'] ?? null, $row['currency'] ?? null, $row['pase_status'] ?? null, $name, $keys,
                ($row['lifecycle'] ?? '') === 'trashed' ? 1 : 0, isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            ];
            $existing->execute([(int) $row['woo_order_id']]);
            $old = $existing->fetch(PDO::FETCH_ASSOC);
            if ($old === false) {
                $insert->execute(array_merge([(int) $row['woo_order_id']], $fields));
                continue;
            }
            if ((int) $old['anonymized'] === 1) {
                continue;
            }
            $update->execute(array_merge($fields, [(int) $row['woo_order_id']]));
            if ($old['customer_id'] !== null) {
                if ($old['order_keys'] !== $keys || $old['customer_name'] !== $name) {
                    $reassign[(int) $old['customer_id']] = true;
                } else {
                    $recompute[(int) $old['customer_id']] = true;
                }
            }
        }
        foreach (array_keys($reassign) as $cid) {
            $this->dismantle($cid);
        }
        return array_keys($recompute);
    }

    /** Rozbiera klienta: jego migawki wracają do przypisania (ręczne połączenia zostają). */
    private function dismantle(int $customerId): void
    {
        $this->pdo->prepare('UPDATE customer_orders SET customer_id = NULL WHERE customer_id = ? AND anonymized = 0')->execute([$customerId]);
        $this->pdo->prepare('DELETE FROM customer_keys WHERE customer_id = ?')->execute([$customerId]);
        $this->pdo->prepare('DELETE FROM customers WHERE id = ? AND anonymized = 0')->execute([$customerId]);
    }

    /**
     * Rozbiera klienta i od razu przypisuje jego zamówienia od nowa.
     * @param callable|null $before np. odświeżenie migawki, po zapamiętaniu zamówień klienta
     * @return list<int>
     */
    private function reassign(int $customerId, ?callable $before = null): array
    {
        $s = $this->pdo->prepare('SELECT woo_order_id FROM customer_orders WHERE customer_id = ?');
        $s->execute([$customerId]);
        $orderIds = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        if ($before !== null) {
            $before();
        }
        $this->dismantle($customerId);
        $this->assignPending(PHP_INT_MAX);
        $ids = [];
        foreach ($orderIds as $oid) {
            $ids[] = $this->mappedCustomer($oid);
        }
        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Przypisuje klientów migawkom bez klienta (najstarsze najpierw), ponawia ręczne połączenia, przelicza.
     * @param list<int>|null $only tylko te zamówienia
     * @return int liczba przypisanych
     */
    private function assignPending(int $limit, ?array $only = null): int
    {
        $sql = 'SELECT woo_order_id, customer_name, order_keys FROM customer_orders WHERE customer_id IS NULL AND anonymized = 0';
        $params = [];
        if ($only !== null) {
            if ($only === []) {
                return 0;
            }
            $sql .= ' AND woo_order_id IN (' . implode(',', array_fill(0, count($only), '?')) . ')';
            $params = $only;
        }
        $sql .= ' ORDER BY order_date, woo_order_id' . ($limit < PHP_INT_MAX ? ' LIMIT ' . $limit : '');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $touched = [];
        $n = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $touched[$this->assignRow($row)] = true;
            ++$n;
        }
        if ($n > 0) {
            $touched += array_fill_keys($this->applyManualMerges(), true);
            $this->recompute(array_keys($touched));
        }
        return $n;
    }

    /** @var list<string>|null */
    private ?array $orderColumnsCache = null;

    /** @return list<string> */
    private function orderColumns(): array
    {
        if ($this->orderColumnsCache === null) {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $this->orderColumnsCache = array_column($this->pdo->query('PRAGMA table_info(woo_orders)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            } else {
                $this->orderColumnsCache = $this->pdo->query(
                    "SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'woo_orders'"
                )->fetchAll(PDO::FETCH_COLUMN);
            }
        }
        return $this->orderColumnsCache;
    }

    /** Przypisuje jedną migawkę (klucze zapisane w migawce); zwraca ID klienta. */
    private function assignRow(array $row): int
    {
        $keys = json_decode((string) ($row['order_keys'] ?? '[]'), true) ?: [];
        $orderKinds = array_values(array_unique(array_column(array_filter($keys, static fn($k) => $k['kind'] !== 'phone'), 'kind')));

        // 1. Klucze mocne (e-mail, konto Allegro) — ich właściciel to ten klient.
        $target = null;
        foreach ($keys as $key) {
            if ($key['kind'] === 'phone') {
                continue;
            }
            $owner = $this->keyOwner($key['k']);
            if ($owner === null) {
                continue;
            }
            if ($target === null) {
                $target = $owner;
            } elseif ($owner !== $target) {
                $target = $this->merge($owner, $target);
            }
        }

        // 2. Telefon — łączy tylko, gdy nie przeczy e-mailowi / kontu Allegro.
        foreach ($keys as $key) {
            if ($key['kind'] !== 'phone') {
                continue;
            }
            $owner = $this->keyOwner($key['k']);
            if ($owner === null || $owner === $target) {
                continue;
            }
            if ($target === null) {
                if (!self::conflicts($orderKinds, $this->kindsOf($owner))
                    && self::namesMatch(self::nameTokens((string) ($row['customer_name'] ?? '')), $this->nameTokensOf($owner))) {
                    $target = $owner;
                }
            } elseif (!self::conflicts($this->kindsOf($target), $this->kindsOf($owner))
                && self::namesMatch($this->nameTokensOf($target) + self::nameTokens((string) ($row['customer_name'] ?? '')), $this->nameTokensOf($owner))) {
                $target = $this->merge($owner, $target);
            }
        }

        if ($target === null) {
            $this->pdo->prepare('INSERT INTO customers (name, updated_at) VALUES (NULL, ?)')->execute([gmdate('Y-m-d H:i:s')]);
            $target = (int) $this->pdo->lastInsertId();
        }

        $insertKey = $this->pdo->prepare('INSERT INTO customer_keys (k, customer_id, kind, label) VALUES (?, ?, ?, ?)');
        foreach ($keys as $key) {
            $owner = $this->keyOwner($key['k']);
            if ($owner === null) {
                $insertKey->execute([$key['k'], $target, $key['kind'], mb_substr((string) $key['label'], 0, 250)]);
            } elseif ($owner !== $target && $key['kind'] === 'phone') {
                // Ten sam numer u innego klienta: pokazujemy go i da się po nim szukać, ale nie łączy.
                $alt = 'phonealt:' . $target . ':' . $key['label'];
                if ($this->keyOwner($alt) === null) {
                    $insertKey->execute([$alt, $target, 'phone', $key['label']]);
                }
            }
        }
        $this->pdo->prepare('UPDATE customer_orders SET customer_id = ? WHERE woo_order_id = ?')->execute([$target, (int) $row['woo_order_id']]);
        return $target;
    }


    /** Dwóch klientów nie łączymy telefonem, gdy obaj mają e-mail albo obaj konto Allegro (a nie wspólne). */
    private static function conflicts(array $kindsA, array $kindsB): bool
    {
        foreach (['email', 'allegro'] as $kind) {
            if (in_array($kind, $kindsA, true) && in_array($kind, $kindsB, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Czy imiona / nazwiska mają wspólny człon (bez polskich znaków, od 3 liter).
     * Brak nazwiska po którejś stronie nie blokuje połączenia.
     * @param array<string,true> $a @param array<string,true> $b
     */
    private static function namesMatch(array $a, array $b): bool
    {
        return $a === [] || $b === [] || array_intersect_key($a, $b) !== [];
    }

    /** @return array<string,true> */
    public static function nameTokens(string $name): array
    {
        $name = mb_strtolower($name);
        $name = strtr($name, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
        $out = [];
        foreach (preg_split('/[^\p{L}]+/u', $name) ?: [] as $t) {
            if (mb_strlen($t) >= 3) {
                $out[$t] = true;
            }
        }
        return $out;
    }

    /** @return array<string,true> */
    private function nameTokensOf(int $customerId): array
    {
        $stmt = $this->pdo->prepare('SELECT DISTINCT customer_name FROM customer_orders WHERE customer_id = ?');
        $stmt->execute([$customerId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $n) {
            $out += self::nameTokens((string) $n);
        }
        return $out;
    }

    private function keyOwner(string $k): ?int
    {
        $stmt = $this->pdo->prepare('SELECT customer_id FROM customer_keys WHERE k = ?');
        $stmt->execute([$k]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    /** @return list<string> */
    private function kindsOf(int $customerId): array
    {
        $stmt = $this->pdo->prepare("SELECT DISTINCT kind FROM customer_keys WHERE customer_id = ? AND kind <> 'phone'");
        $stmt->execute([$customerId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Przenosi klienta $from do $into (starsze ID zostaje). Zwraca ID klienta po połączeniu. */
    private function merge(int $from, int $into): int
    {
        if ($from === $into) {
            return $into;
        }
        [$keep, $drop] = $from < $into ? [$from, $into] : [$into, $from];
        $this->pdo->prepare('UPDATE customer_keys SET customer_id = ? WHERE customer_id = ?')->execute([$keep, $drop]);
        $this->pdo->prepare('UPDATE customer_orders SET customer_id = ? WHERE customer_id = ?')->execute([$keep, $drop]);
        $this->pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$drop]);
        return $keep;
    }

    // ------------------------------------------------------------
    //  Ręczne łączenie
    // ------------------------------------------------------------

    /** Łączy dwóch klientów na stałe (przetrwa przeliczenie). Zwraca ID połączonego klienta. */
    public function mergeManually(int $a, int $b, string $by): int
    {
        if ($a === $b) {
            throw new \RuntimeException('To ten sam klient.');
        }
        $keyA = $this->anchorKey($a);
        $keyB = $this->anchorKey($b);
        if ($keyA === null || $keyB === null) {
            throw new \RuntimeException('Nie znaleziono klienta albo klient nie ma e-maila, telefonu ani konta Allegro, po którym można go połączyć.');
        }
        return $this->atomic(function () use ($a, $b, $keyA, $keyB, $by): int {
            $this->pdo->prepare('INSERT INTO customer_merges (key_a, key_b, created_by, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$keyA, $keyB, mb_substr($by, 0, 190), gmdate('Y-m-d H:i:s')]);
            $id = $this->merge($a, $b);
            $this->recompute([$id]);
            return $id;
        });
    }

    /**
     * Cofa ręczne połączenie i układa zamówienia tego klienta od nowa.
     * @return list<int> ID klientów po rozdzieleniu
     */
    public function unmerge(int $mergeId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM customer_merges WHERE id = ?');
        $stmt->execute([$mergeId]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$m) {
            throw new \RuntimeException('Nie ma takiego połączenia.');
        }
        $customer = $this->keyOwner((string) $m['key_a']) ?? $this->keyOwner((string) $m['key_b']);
        return $this->atomic(function () use ($mergeId, $customer): array {
            $this->pdo->prepare('DELETE FROM customer_merges WHERE id = ?')->execute([$mergeId]);
            return $customer === null ? [] : $this->reassign($customer);
        });
    }

    // ------------------------------------------------------------
    //  Usunięcie danych klienta na jego prośbę
    // ------------------------------------------------------------

    /**
     * Anonimizuje klienta: usuwa nazwisko, e-maile, telefony, loginy Allegro i ręczne połączenia z kart klientów.
     * Zostają liczby (ile zamówień, kiedy, na jakim kanale, za ile) bez danych osobowych. Nieodwracalne.
     * Samych zamówień nie rusza — są w CRM (do skasowania po roku) i w sklepie / na Allegro.
     */
    public function anonymize(int $customerId): void
    {
        if ($this->find($customerId) === null) {
            throw new \RuntimeException('Nie ma takiego klienta.');
        }
        $this->atomic(function () use ($customerId): void {
            $keys = $this->pdo->prepare('SELECT k FROM customer_keys WHERE customer_id = ?');
            $keys->execute([$customerId]);
            $delMerge = $this->pdo->prepare('DELETE FROM customer_merges WHERE key_a = ? OR key_b = ?');
            foreach ($keys->fetchAll(PDO::FETCH_COLUMN) as $k) {
                $delMerge->execute([$k, $k]);
            }
            $this->pdo->prepare('DELETE FROM customer_keys WHERE customer_id = ?')->execute([$customerId]);
            $this->pdo->prepare("UPDATE customer_orders SET customer_name = NULL, order_keys = '[]', anonymized = 1 WHERE customer_id = ?")->execute([$customerId]);
            $this->pdo->prepare('UPDATE customers SET name = NULL, anonymized = 1 WHERE id = ?')->execute([$customerId]);
            $this->recompute([$customerId]);
        });
    }

    /** Ręczne połączenia dotyczące klienta. @return list<array<string,mixed>> */
    public function mergesOf(int $customerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.* FROM customer_merges m
             WHERE m.key_a IN (SELECT k FROM customer_keys WHERE customer_id = ?) OR m.key_b IN (SELECT k FROM customer_keys WHERE customer_id = ?)
             ORDER BY m.id'
        );
        $stmt->execute([$customerId, $customerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Ponawia ręczne połączenia po przypisaniu nowych zamówień. @return list<int> klienci, których dotyczyło */
    private function applyManualMerges(): array
    {
        $touched = [];
        foreach ($this->pdo->query('SELECT key_a, key_b FROM customer_merges ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $a = $this->keyOwner((string) $m['key_a']);
            $b = $this->keyOwner((string) $m['key_b']);
            if ($a !== null && $b !== null && $a !== $b) {
                $touched[] = $this->merge($a, $b);
            }
        }
        return $touched;
    }

    /** Klucz, po którym zapamiętujemy ręczne połączenie: najpierw e-mail, potem konto Allegro, potem telefon. */
    private function anchorKey(int $customerId): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT k FROM customer_keys WHERE customer_id = ? AND k NOT LIKE 'phonealt:%'
             ORDER BY CASE kind WHEN 'email' THEN 0 WHEN 'allegro' THEN 1 ELSE 2 END, k LIMIT 1"
        );
        $stmt->execute([$customerId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    // ------------------------------------------------------------
    //  Podsumowanie klienta
    // ------------------------------------------------------------

    /** @param list<int> $customerIds */
    private function recompute(array $customerIds): void
    {
        $exists = $this->pdo->prepare('SELECT 1 FROM customers WHERE id = ?');
        $update = $this->pdo->prepare(
            'UPDATE customers SET name = ?, orders_count = ?, cancelled_count = ?, spent_pln = ?, totals = ?, channels = ?,
                first_order_at = ?, last_order_at = ?, updated_at = ? WHERE id = ?'
        );
        foreach (array_unique($customerIds) as $cid) {
            $exists->execute([$cid]);
            if ($exists->fetchColumn() === false) {
                continue;
            }
            $s = self::summarize($this->ordersOf((int) $cid));
            $update->execute([
                $s['name'], $s['orders_count'], $s['cancelled_count'], $s['totals']['PLN'] ?? 0,
                json_encode($s['totals']), json_encode($s['channels'], JSON_UNESCAPED_UNICODE),
                $s['first_order_at'], $s['last_order_at'], gmdate('Y-m-d H:i:s'), $cid,
            ]);
        }
    }

    /**
     * Historia zamówień klienta z migawek (także zamówień już usuniętych z CRM), bez kosza, najnowsze najpierw.
     * 'in_crm' = zamówienie wciąż jest w CRM (można je otworzyć).
     * @return list<array<string,mixed>>
     */
    public function ordersOf(int $customerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT co.woo_order_id, co.pase_number, co.order_number, co.order_date AS date_created, co.total, co.currency, co.pase_status,
                    co.customer_name, co.channel_key, co.channel_label, CASE WHEN wo.woo_order_id IS NULL THEN 0 ELSE 1 END AS in_crm
             FROM customer_orders co
             LEFT JOIN woo_orders wo ON wo.woo_order_id = co.woo_order_id
             WHERE co.customer_id = ? AND co.trashed = 0
             ORDER BY co.order_date DESC, co.woo_order_id DESC'
        );
        $stmt->execute([$customerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{0:string,1:string} klucz i nazwa kanału sprzedaży */
    public static function channelOf(array $order): array
    {
        $isAllegro = ($order['channel_type'] ?? null) === 'allegro' || (int) ($order['woo_order_id'] ?? 0) >= 9_000_000_000;
        if (!empty($order['integration_id']) && !empty($order['channel_name'])) {
            $label = (string) $order['channel_name'];
            if ($isAllegro && stripos($label, 'allegro') === false) {
                $label = 'Allegro · ' . $label;
            }
            return ['int:' . (int) $order['integration_id'], $label];
        }
        if (ManualOrders::isManual((int) ($order['woo_order_id'] ?? 0))) {
            return ['manual', 'Zamówienie ręczne'];
        }
        return $isAllegro ? ['allegro', 'Allegro'] : ['shop', 'Sklep'];
    }


    /**
     * @param list<array<string,mixed>> $orders najnowsze najpierw
     * @return array{name:?string,orders_count:int,cancelled_count:int,totals:array<string,float>,channels:array<string,array{label:string,count:int,spent:array<string,float>,last:?string}>,first_order_at:?string,last_order_at:?string}
     */
    public static function summarize(array $orders): array
    {
        $out = ['name' => null, 'orders_count' => 0, 'cancelled_count' => 0, 'totals' => [], 'channels' => [], 'first_order_at' => null, 'last_order_at' => null];
        foreach ($orders as $o) {
            $status = (string) ($o['pase_status'] ?? '');
            if ($out['name'] === null && trim((string) ($o['customer_name'] ?? '')) !== '') {
                $out['name'] = trim((string) $o['customer_name']);
            }
            if (in_array($status, self::NOT_PURCHASE, true)) {
                ++$out['cancelled_count'];
                continue;
            }
            ++$out['orders_count'];
            $date = $o['date_created'] ?? null;
            if ($date !== null && ($out['first_order_at'] === null || $date < $out['first_order_at'])) {
                $out['first_order_at'] = (string) $date;
            }
            if ($date !== null && ($out['last_order_at'] === null || $date > $out['last_order_at'])) {
                $out['last_order_at'] = (string) $date;
            }
            [$ck, $cl] = isset($o['channel_key']) ? [$o['channel_key'], $o['channel_label']] : self::channelOf($o);
            $ch = $out['channels'][$ck] ?? ['label' => $cl, 'count' => 0, 'spent' => [], 'last' => null];
            ++$ch['count'];
            if ($date !== null && ($ch['last'] === null || $date > $ch['last'])) {
                $ch['last'] = (string) $date;
            }
            if (!in_array($status, self::NO_SPEND, true)) {
                $cur = (string) ($o['currency'] ?: 'PLN');
                $amount = round((float) ($o['total'] ?? 0), 2);
                $out['totals'][$cur] = round(($out['totals'][$cur] ?? 0) + $amount, 2);
                $ch['spent'][$cur] = round(($ch['spent'][$cur] ?? 0) + $amount, 2);
            }
            $out['channels'][$ck] = $ch;
        }
        uasort($out['channels'], static fn($a, $b) => $b['count'] <=> $a['count']);
        return $out;
    }

    // ------------------------------------------------------------
    //  Odczyt do panelu
    // ------------------------------------------------------------

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$id]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$c) {
            return null;
        }
        $c['totals'] = json_decode((string) $c['totals'], true) ?: [];
        $c['channels'] = json_decode((string) $c['channels'], true) ?: [];
        $k = $this->pdo->prepare("SELECT k, kind, label FROM customer_keys WHERE customer_id = ? ORDER BY CASE kind WHEN 'email' THEN 0 WHEN 'phone' THEN 1 ELSE 2 END, label");
        $k->execute([$id]);
        $c['keys'] = [];
        foreach ($k->fetchAll(PDO::FETCH_ASSOC) as $key) {
            $c['keys'][$key['kind'] . ':' . $key['label']] = $key;
        }
        $c['keys'] = array_values($c['keys']);
        return $c;
    }

    /**
     * Lista klientów. $sort: last | orders | spent; $minOrders: 2 = tylko powracający.
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function list(string $q = '', string $sort = 'last', int $minOrders = 0, int $limit = 50, int $offset = 0): array
    {
        // Klient, którego wszystkie zamówienia są w koszu, znika z listy.
        $where = ['(c.orders_count > 0 OR c.cancelled_count > 0)', 'c.anonymized = 0'];
        $params = [];
        if ($minOrders > 0) {
            $where[] = 'c.orders_count >= ?';
            $params[] = $minOrders;
        }
        $q = trim($q);
        if ($q !== '') {
            $like = '%' . mb_strtolower($q) . '%';
            $phone = preg_replace('/\D+/', '', $q) ?? '';
            $cond = 'LOWER(c.name) LIKE ? OR c.id IN (SELECT customer_id FROM customer_keys WHERE LOWER(label) LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            if (strlen($phone) >= 5) {
                $cond .= ' OR c.id IN (SELECT customer_id FROM customer_keys WHERE kind = ? AND label LIKE ?)';
                $params[] = 'phone';
                $params[] = '%' . (strlen($phone) === 11 && str_starts_with($phone, '48') ? substr($phone, 2) : $phone) . '%';
            }
            // Numer zamówienia (własny CRM, sklepu albo Allegro).
            $num = ltrim($q, '#');
            $cond .= ' OR c.id IN (SELECT customer_id FROM customer_orders WHERE order_number = ? OR pase_number = ?)';
            $params[] = $num;
            $params[] = ctype_digit($num) ? (int) $num : -1;
            $where[] = "($cond)";
        }
        $order = match ($sort) {
            'orders' => 'c.orders_count DESC, c.last_order_at DESC',
            'spent'  => 'c.spent_pln DESC, c.orders_count DESC',
            default  => 'c.last_order_at DESC, c.id DESC',
        };
        $whereSql = implode(' AND ', $where);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM customers c WHERE $whereSql");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $stmt = $this->pdo->prepare("SELECT c.* FROM customers c WHERE $whereSql ORDER BY $order LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows !== []) {
            $ids = array_map(static fn($r) => (int) $r['id'], $rows);
            $in = implode(',', $ids);
            $keys = [];
            foreach ($this->pdo->query("SELECT customer_id, kind, label FROM customer_keys WHERE customer_id IN ($in) ORDER BY label")->fetchAll(PDO::FETCH_ASSOC) as $k) {
                $keys[(int) $k['customer_id']][$k['kind']][$k['label']] = $k['label'];
            }
            foreach ($rows as &$r) {
                $r['channels'] = json_decode((string) $r['channels'], true) ?: [];
                $r['totals'] = json_decode((string) $r['totals'], true) ?: [];
                $r['keys'] = $keys[(int) $r['id']] ?? [];
            }
        }
        return ['rows' => $rows, 'total' => $total];
    }

    /** Klient po e-mailu, telefonie, loginie Allegro albo numerze zamówienia (do ręcznego łączenia). */
    public function findByIdentifier(string $q): ?int
    {
        $q = trim($q);
        if ($q === '') {
            return null;
        }
        if ($email = self::normalizeEmail($q)) {
            if (($id = $this->keyOwner('email:' . $email)) !== null) {
                return $id;
            }
        }
        if ($phone = self::normalizePhone($q)) {
            if (($id = $this->keyOwner('phone:' . $phone)) !== null) {
                return $id;
            }
        }
        $stmt = $this->pdo->prepare("SELECT customer_id FROM customer_keys WHERE kind = 'allegro' AND LOWER(label) = ? LIMIT 1");
        $stmt->execute([mb_strtolower($q)]);
        if (($v = $stmt->fetchColumn()) !== false) {
            return (int) $v;
        }
        $num = ltrim($q, '#');
        $stmt = $this->pdo->prepare('SELECT customer_id FROM customer_orders WHERE (order_number = ? OR pase_number = ?) AND customer_id IS NOT NULL LIMIT 1');
        $stmt->execute([$num, ctype_digit($num) ? (int) $num : -1]);
        if (($v = $stmt->fetchColumn()) !== false) {
            return (int) $v;
        }
        $stmt = $this->pdo->prepare('SELECT woo_order_id FROM woo_orders WHERE order_number = ? OR pase_number = ? LIMIT 1');
        $stmt->execute([$num, ctype_digit($num) ? (int) $num : -1]);
        $oid = $stmt->fetchColumn();
        return $oid === false ? null : $this->customerIdForOrder((int) $oid);
    }

    /**
     * Wykonuje w transakcji (albo w już otwartej). @template T @param callable():T $fn @return T
     */
    private function atomic(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // ------------------------------------------------------------

    private function lock(): bool
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return true;
        }
        return (int) $this->pdo->query("SELECT GET_LOCK('" . self::LOCK . "', 0)")->fetchColumn() === 1;
    }

    private function unlock(): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $this->pdo->query("SELECT RELEASE_LOCK('" . self::LOCK . "')")->fetchColumn();
        }
    }
}
