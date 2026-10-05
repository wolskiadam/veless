<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;

/**
 * Płatności PayU przy zamówieniach i zwroty pieniędzy przez PayU.
 *
 *  - Przypisanie: zamówienie ze sklepu opłacone bramką PayU ma w WooCommerce numer płatności PayU (transaction_id,
 *    ewentualnie pole meta wtyczki PayU). CRM czyta tę płatność z PayU (GET orders/{orderId}) kluczami kolejnych
 *    sklepów PayU, aż któryś ją zna, i zapisuje ją w payu_order_payments. Numer można też wpisać ręcznie na zamówieniu.
 *    Po stronie PayU nic się przy tym nie zmienia.
 *  - Zwrot: tylko po potwierdzeniu, kwota <= opłacone minus dotychczasowe zwroty (bez anulowanych). Własny extRefundId
 *    zapisany przed wysłaniem blokuje podwójny zwrot. Brak odpowiedzi PayU = „nie wiadomo” (UNKNOWN), bez ponawiania.
 *
 * Klienta API i konta sklepów daje PayuPayouts (wtyczka integrations/payu). Zamówienia z Allegro płacone są przez
 * Allegro, nie przez PayU sklepu - ich tu nie dotyczy.
 */
final class PayuPayments
{
    /** Płatność PayU zakończona - tylko z niej można zwracać. */
    public const PAID = 'COMPLETED';
    public const STATUS_LABELS = [
        'NEW' => 'nowa', 'PENDING' => 'w toku', 'WAITING_FOR_CONFIRMATION' => 'czeka na odbiór', 'COMPLETED' => 'opłacona', 'CANCELED' => 'anulowana',
    ];
    public const REFUND_LABELS = [
        'SENDING' => 'wysyłanie do PayU', 'PENDING' => 'w realizacji', 'FINALIZED' => 'zwrócono', 'CANCELED' => 'anulowany',
        'ERROR' => 'odrzucony', 'UNKNOWN' => 'nie wiadomo - sprawdź w panelu PayU',
    ];
    public const PAY_METHODS = [
        'PBL' => 'szybki przelew', 'CARD_TOKEN' => 'karta', 'c' => 'karta', 'blik' => 'BLIK', 'BLIK' => 'BLIK', 'BLIK_TOKEN' => 'BLIK', 'INSTALLMENTS' => 'raty',
        'PAYMENT_WALL' => 'strona płatności PayU', 'dp' => 'płacę później (PayPo)', 'ai' => 'raty PayU', 'ap' => 'Google Pay', 'jp' => 'Apple Pay',
    ];

    public function __construct(private readonly PDO $pdo, private readonly PayuPayouts $payu) {}

    public static function migrate(PDO $pdo): void
    {
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $auto = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pdo->exec('CREATE TABLE IF NOT EXISTS payu_order_payments (
            id ' . $auto . ',
            woo_order_id BIGINT NOT NULL,
            payu_order_id VARCHAR(64) NOT NULL UNIQUE,
            integration_id INT NULL,
            status VARCHAR(40) NULL,
            amount INT NULL,
            currency VARCHAR(3) NULL,
            pay_method VARCHAR(255) NULL,
            buyer VARCHAR(255) NULL,
            paid_at VARCHAR(40) NULL,
            ext_order_id VARCHAR(100) NULL,
            data ' . ($sqlite ? 'TEXT' : 'MEDIUMTEXT') . ' NULL,
            linked_by VARCHAR(120) NULL,
            created_at DATETIME NOT NULL,
            fetched_at DATETIME NULL
        )' . $tail);
        $pdo->exec('CREATE TABLE IF NOT EXISTS payu_refunds (
            id ' . $auto . ',
            woo_order_id BIGINT NOT NULL,
            payu_order_id VARCHAR(64) NOT NULL,
            integration_id INT NULL,
            ext_refund_id VARCHAR(64) NOT NULL UNIQUE,
            refund_id VARCHAR(64) NULL,
            amount INT NOT NULL,
            currency VARCHAR(3) NOT NULL DEFAULT \'PLN\',
            description VARCHAR(255) NULL,
            status VARCHAR(20) NOT NULL,
            error VARCHAR(500) NULL,
            return_id INT NULL,
            created_by VARCHAR(120) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL
        )' . $tail);
        // Linki do płatności wystawione z CRM (createLink): ext_order_id zapisany przed wysłaniem blokuje podwójne utworzenie.
        $pdo->exec('CREATE TABLE IF NOT EXISTS payu_payment_links (
            id ' . $auto . ',
            woo_order_id BIGINT NOT NULL,
            integration_id INT NULL,
            ext_order_id VARCHAR(64) NOT NULL UNIQUE,
            payu_order_id VARCHAR(64) NULL,
            link VARCHAR(1024) NULL,
            amount INT NOT NULL,
            currency VARCHAR(3) NOT NULL DEFAULT \'PLN\',
            description VARCHAR(255) NULL,
            expires_at DATETIME NULL,
            status VARCHAR(20) NOT NULL,
            error VARCHAR(500) NULL,
            paid_recorded_at DATETIME NULL,
            created_by VARCHAR(120) NULL,
            created_at DATETIME NOT NULL
        )' . $tail);
        foreach (['CREATE INDEX idx_payu_pay_order ON payu_order_payments (woo_order_id)', 'CREATE INDEX idx_payu_ref_order ON payu_refunds (woo_order_id)',
            'CREATE INDEX idx_payu_link_order ON payu_payment_links (woo_order_id)', 'CREATE INDEX idx_payu_link_payu ON payu_payment_links (payu_order_id)'] as $sql) {
            try { $pdo->exec($sql); } catch (\PDOException) { /* już jest */ }
        }
    }

    /** Czy zamówienie może mieć płatność PayU sklepu (nie Allegro). */
    public static function eligible(array $order): bool
    {
        // TikTok Shop rozlicza płatność sam - zamówienia z TikTok (zakres TiktokShop::ID_MIN-ID_MAX) nie mają PayU.
        return (int) ($order['woo_order_id'] ?? 0) > 0 && (int) $order['woo_order_id'] < 9000000000
            && !TiktokShop::isTiktok((int) $order['woo_order_id']);
    }

    /**
     * Numer płatności PayU zapisany przez sklep: transaction_id przy metodzie płatności PayU albo pole meta wtyczki PayU.
     * Numer PayU (orderId) to 20-40 wielkich liter i cyfr, np. VVLR1HXK2S160929GUEST000P01.
     */
    public static function candidateId(array $payload): ?string
    {
        $valid = static fn(string $v): bool => (bool) preg_match('/^[A-Z0-9]{16,40}$/', $v);
        $method = strtolower((string) ($payload['payment_method'] ?? ''));
        $tx = trim((string) ($payload['transaction_id'] ?? ''));
        if (str_starts_with($method, 'payu') && $valid($tx)) {
            return $tx;
        }
        foreach ((array) ($payload['meta_data'] ?? []) as $m) {
            $key = strtolower((string) ($m['key'] ?? ''));
            $val = is_scalar($m['value'] ?? null) ? trim((string) $m['value']) : '';
            if (str_contains($key, 'payu') && str_contains($key, 'order') && !str_contains($key, 'status') && $valid($val)) {
                return $val;
            }
        }
        return null;
    }

    /** @return list<array<string,mixed>> płatności PayU zamówienia */
    public function payments(int $wooOrderId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM payu_order_payments WHERE woo_order_id = ? ORDER BY id');
        $st->execute([$wooOrderId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> zwroty PayU zamówienia, najnowsze pierwsze */
    public function refunds(int $wooOrderId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM payu_refunds WHERE woo_order_id = ? ORDER BY id DESC');
        $st->execute([$wooOrderId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function payment(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM payu_order_payments WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Przy otwarciu zamówienia: automatyczne przypisanie po numerze ze sklepu i odświeżenie płatności w toku / zwrotów w toku.
     * Błędy PayU pomija (zamówienie ma się otworzyć), zwraca komunikat do pokazania albo null.
     */
    public function sync(array $order): ?string
    {
        if (!self::eligible($order) || $this->payu->accounts() === []) {
            return null;
        }
        $wooId = (int) $order['woo_order_id'];
        try {
            if ($this->payments($wooId) === []) {
                $cand = self::candidateId(json_decode((string) ($order['payload'] ?? ''), true) ?: []);
                if ($cand !== null && !$this->linkedElsewhere($cand, $wooId)) {
                    $this->link($wooId, $cand, 'auto');
                }
                return null;
            }
            foreach ($this->payments($wooId) as $p) {
                $stale = $p['fetched_at'] === null || strtotime((string) $p['fetched_at']) < time() - 600;
                $open = !in_array($p['status'], [self::PAID, 'CANCELED'], true) || $this->hasOpenRefund($wooId);
                $legacy = !isset((json_decode((string) $p['data'], true) ?: [])['order']);   // zapis sprzed pełnych szczegółów
                if (($open && $stale) || $legacy) {
                    $this->refresh((int) $p['id']);
                }
            }
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        return null;
    }

    private function linkedElsewhere(string $payuOrderId, int $wooId): bool
    {
        $st = $this->pdo->prepare('SELECT woo_order_id FROM payu_order_payments WHERE payu_order_id = ?');
        $st->execute([$payuOrderId]);
        $other = $st->fetchColumn();
        return $other !== false && (int) $other !== $wooId;
    }

    private function hasOpenRefund(int $wooId): bool
    {
        foreach ($this->refunds($wooId) as $r) {
            if (in_array($r['status'], ['SENDING', 'PENDING'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Przypisuje płatność PayU do zamówienia (odczyt z PayU; próbuje kluczy każdego sklepu).
     * @return array<string,mixed> zapisana płatność
     */
    public function link(int $wooOrderId, string $payuOrderId, string $by): array
    {
        $payuOrderId = strtoupper(trim($payuOrderId));
        if (!preg_match('/^[A-Z0-9]{10,64}$/', $payuOrderId)) {
            throw new \RuntimeException('To nie wygląda na numer płatności PayU (np. VVLR1HXK2S160929GUEST000P01).');
        }
        if ($this->linkedElsewhere($payuOrderId, $wooOrderId)) {
            throw new \RuntimeException('Ta płatność PayU jest już przypisana do innego zamówienia.');
        }
        [$accountId, $order, $props] = $this->findOrder($payuOrderId);
        $now = date('Y-m-d H:i:s');
        $st = $this->pdo->prepare('SELECT id FROM payu_order_payments WHERE payu_order_id = ?');
        $st->execute([$payuOrderId]);
        if ($st->fetchColumn() === false) {
            $this->pdo->prepare('INSERT INTO payu_order_payments (woo_order_id, payu_order_id, integration_id, linked_by, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$wooOrderId, $payuOrderId, $accountId, $by, $now]);
        }
        $this->store($payuOrderId, $accountId, $order, $props);
        $st->execute([$payuOrderId]);
        $p = (array) $this->payment((int) $st->fetchColumn());
        try {
            $this->syncRefunds($p);   // zwroty zrobione wcześniej w panelu PayU
        } catch (\Throwable) {
        }
        return $p;
    }

    public function unlink(int $wooOrderId, int $paymentId): void
    {
        $this->pdo->prepare('DELETE FROM payu_order_payments WHERE id = ? AND woo_order_id = ?')->execute([$paymentId, $wooOrderId]);
    }

    /** Odświeża płatność i jej zwroty z PayU. @return array<string,mixed> */
    public function refresh(int $paymentId): array
    {
        $p = $this->payment($paymentId);
        if ($p === null) {
            throw new \RuntimeException('Nie ma takiej płatności.');
        }
        $api = $this->payu->api((int) $p['integration_id']);
        [$st, $data] = $api->getOrder((string) $p['payu_order_id']);
        if ($st !== 200 || !isset($data['orders'][0])) {
            throw new \RuntimeException($api::errorMessage($st, $data));
        }
        $this->store((string) $p['payu_order_id'], (int) $p['integration_id'], $data['orders'][0], (array) ($data['properties'] ?? []));
        $this->syncRefunds($p);
        return (array) $this->payment($paymentId);
    }

    /** @return array{0:int,1:array<string,mixed>,2:array<int,mixed>} konto sklepu, który zna płatność, jej dane i properties (PAYMENT_ID) */
    private function findOrder(string $payuOrderId): array
    {
        $errors = [];
        foreach ($this->payu->accounts() as $acc) {
            $id = (int) $acc['id'];
            if (!$this->payu->configured($id)) {
                continue;
            }
            $api = $this->payu->api($id);
            [$st, $data] = $api->getOrder($payuOrderId);
            if ($st === 200 && isset($data['orders'][0]) && is_array($data['orders'][0])) {
                return [$id, $data['orders'][0], (array) ($data['properties'] ?? [])];
            }
            if ($st !== 404 && $st !== 400) {
                $errors[] = $acc['name'] . ': ' . $api::errorMessage($st, $data);
            }
        }
        throw new \RuntimeException($errors ? 'Nie udało się odczytać płatności z PayU. ' . implode(' · ', $errors)
            : 'Żaden z Twoich sklepów PayU nie zna płatności ' . $payuOrderId . '.');
    }

    /**
     * Zapisuje dane płatności z PayU (+ transakcja: metoda, karta, rachunek płacącego, gdy PayU ją poda).
     * W kolumnie data: {order, properties, transaction} - pełne dane do „Szczegółów płatności”.
     */
    private function store(string $payuOrderId, int $accountId, array $order, array $properties = []): void
    {
        $method = self::payMethodLabel($order, []);
        $transaction = [];
        try {
            [$st, $tx] = $this->payu->api($accountId)->getTransactions($payuOrderId);
            if ($st === 200 && is_array($tx['transactions'][0] ?? null)) {
                $transaction = $tx['transactions'][0];
                $method = self::payMethodLabel($order, $transaction);
            }
        } catch (\Throwable) {
        }
        $buyer = (array) ($order['buyer'] ?? []);
        $buyerText = trim(trim((string) ($buyer['firstName'] ?? '') . ' ' . (string) ($buyer['lastName'] ?? '')) . ' ' . (string) ($buyer['email'] ?? ''));
        $this->pdo->prepare('UPDATE payu_order_payments SET integration_id = ?, status = ?, amount = ?, currency = ?, pay_method = ?, buyer = ?, paid_at = ?, ext_order_id = ?, data = ?, fetched_at = ?
            WHERE payu_order_id = ?')->execute([
            $accountId, (string) ($order['status'] ?? ''), (int) ($order['totalAmount'] ?? 0), (string) ($order['currencyCode'] ?? 'PLN'), $method,
            mb_substr($buyerText, 0, 255), (string) ($order['orderCreateDate'] ?? ''), mb_substr((string) ($order['extOrderId'] ?? ''), 0, 100),
            json_encode(['order' => $order, 'properties' => $properties, 'transaction' => $transaction], JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), $payuOrderId,
        ]);
        if ((string) ($order['status'] ?? '') === self::PAID) {
            $this->recordLinkPaid($payuOrderId, (int) ($order['totalAmount'] ?? 0));
        }
    }

    /**
     * Link do płatności opłacony: raz na link dopisujemy kwotę do wpłaty zamówienia („Zapłacono: X z Y”, OrderPayment).
     * Zamówienie, które sklep już oznaczył jako opłacone (date_paid) bez ręcznego wpisu, zostawiamy bez zmian.
     */
    private function recordLinkPaid(string $payuOrderId, int $amount): void
    {
        try {
            $st = $this->pdo->prepare('SELECT woo_order_id FROM payu_payment_links WHERE payu_order_id = ? AND paid_recorded_at IS NULL');
            $st->execute([$payuOrderId]);
            $wooId = $st->fetchColumn();
            if ($wooId === false) {
                return;
            }
            $upd = $this->pdo->prepare('UPDATE payu_payment_links SET paid_recorded_at = ? WHERE payu_order_id = ? AND paid_recorded_at IS NULL');
            $upd->execute([date('Y-m-d H:i:s'), $payuOrderId]);
            if ($upd->rowCount() !== 1) {
                return;   // równoległe odświeżenie już dopisało
            }
            $o = $this->pdo->prepare('SELECT paid_amount, payload FROM woo_orders WHERE woo_order_id = ?');
            $o->execute([(int) $wooId]);
            $row = $o->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return;
            }
            $shopPaid = !empty((json_decode((string) $row['payload'], true) ?: [])['date_paid']);
            if ($row['paid_amount'] === null && $shopPaid) {
                return;
            }
            (new OrderPayment($this->pdo))->set((int) $wooId, round((float) ($row['paid_amount'] ?? 0) + $amount / 100, 2), 'PayU - link do płatności');
        } catch (\Throwable $e) {
            \Pase\Support\Logger::warn('PayU: nie zapisano wpłaty z linku ' . $payuOrderId . ': ' . $e->getMessage());
        }
    }

    /** @return list<array<string,mixed>> linki do płatności zamówienia (najnowsze pierwsze) ze statusem płatności z PayU */
    public function links(int $wooOrderId): array
    {
        $st = $this->pdo->prepare('SELECT l.*, p.status AS payment_status, p.id AS payment_id FROM payu_payment_links l
            LEFT JOIN payu_order_payments p ON p.payu_order_id = l.payu_order_id WHERE l.woo_order_id = ? ORDER BY l.id DESC');
        $st->execute([$wooOrderId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function newLinkExtId(int $wooOrderId): string
    {
        return 'crmpay-' . $wooOrderId . '-' . date('ymdHis') . '-' . bin2hex(random_bytes(3));
    }

    /**
     * Link do płatności PayU dla klienta: nowe zamówienie w PayU w wybranym sklepie, ważne $days dni.
     * CRM nic nie wysyła klientowi - link pokazujemy do skopiowania. Płatność od razu jest przypisana do zamówienia,
     * a gdy klient zapłaci (powiadomienie PayU, odświeżenie z crona albo przy otwarciu zamówienia), kwota trafia do wpłaty.
     * @param int $amount grosze
     * @param array{notifyUrl?:string,continueUrl?:string,customerIp?:string,buyer?:array<string,string>} $opts
     * @return array<string,mixed> zapisany link (payu_payment_links)
     */
    public function createLink(int $wooOrderId, int $accountId, int $amount, string $description, int $days, string $extId, string $user, array $opts = []): array
    {
        if (!preg_match('/^crmpay-\d+-[0-9a-z-]{8,40}$/', $extId)) {
            throw new \RuntimeException('Nieprawidłowe zlecenie linku. Otwórz zamówienie i zacznij od nowa.');
        }
        $st = $this->pdo->prepare('SELECT * FROM payu_payment_links WHERE ext_order_id = ?');
        $st->execute([$extId]);
        if ($prev = $st->fetch(PDO::FETCH_ASSOC)) {
            if ($prev['status'] === 'CREATED') {
                return $prev;   // ponowne wysłanie formularza - ten sam link, nic nowego w PayU
            }
            throw new \RuntimeException('Ten link był już zlecany - odśwież stronę zamówienia i spróbuj ponownie.');
        }
        if ($amount < 1 || $amount > 99999999) {
            throw new \RuntimeException('Podaj kwotę większą od zera.');
        }
        $days = max(1, min(90, $days));
        $description = mb_substr(trim($description) !== '' ? trim($description) : 'Zamówienie', 0, 200);
        $api = $this->payu->api($accountId);
        $now = date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', time() + $days * 86400);
        $this->pdo->prepare('INSERT INTO payu_payment_links (woo_order_id, integration_id, ext_order_id, amount, currency, description, expires_at, status, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$wooOrderId, $accountId, $extId, $amount, 'PLN', $description, $expires, 'SENDING', mb_substr($user, 0, 120), $now]);

        $body = [
            'extOrderId'   => $extId,
            'customerIp'   => (string) ($opts['customerIp'] ?? '') !== '' ? (string) $opts['customerIp'] : '127.0.0.1',
            'description'  => $description,
            'currencyCode' => 'PLN',
            'totalAmount'  => (string) $amount,
            'validityTime' => (string) ($days * 86400),
            'products'     => [['name' => mb_substr($description, 0, 200), 'unitPrice' => (string) $amount, 'quantity' => '1']],
        ];
        foreach (['notifyUrl', 'continueUrl'] as $k) {
            if (preg_match('~^https://[^\s]{4,1000}$~', (string) ($opts[$k] ?? ''))) {
                $body[$k] = (string) $opts[$k];
            }
        }
        $buyer = array_filter((array) ($opts['buyer'] ?? []), static fn($v): bool => is_string($v) && trim($v) !== '');
        if (isset($buyer['email']) && filter_var($buyer['email'], FILTER_VALIDATE_EMAIL)) {
            $body['buyer'] = ['language' => 'pl'] + array_intersect_key($buyer, array_flip(['email', 'phone', 'firstName', 'lastName']));
        }

        $set = function (string $status, ?string $payuId, ?string $link, ?string $error) use ($extId): void {
            $this->pdo->prepare('UPDATE payu_payment_links SET status = ?, payu_order_id = COALESCE(?, payu_order_id), link = COALESCE(?, link), error = ? WHERE ext_order_id = ?')
                ->execute([$status, $payuId, $link, $error !== null ? mb_substr($error, 0, 500) : null, $extId]);
        };
        try {
            [$code, $data] = $api->createOrder($body);
        } catch (\Throwable $e) {
            $set('UNKNOWN', null, null, $e->getMessage());
            throw new \RuntimeException('Nie wiadomo, czy PayU utworzyło płatność (' . $e->getMessage() . '). Sprawdź w panelu PayU, zanim utworzysz nowy link.');
        }
        $payuId = strtoupper(trim((string) ($data['orderId'] ?? '')));
        $link = trim((string) ($data['redirectUri'] ?? ''));
        $ok = in_array($code, [200, 201, 302], true) && ($data['status']['statusCode'] ?? '') === 'SUCCESS';
        if (!$ok || $payuId === '' || !preg_match('~^https://~', $link)) {
            $msg = $ok ? 'PayU nie zwróciło linku do płatności.' : $api::errorMessage($code, $data);
            $unknown = $code === 0 || $code >= 500;
            $set($unknown ? 'UNKNOWN' : 'ERROR', $payuId !== '' ? $payuId : null, null, $msg);
            throw new \RuntimeException($unknown ? 'Nie wiadomo, czy PayU utworzyło płatność (' . $msg . '). Sprawdź w panelu PayU, zanim utworzysz nowy link.'
                : 'PayU nie utworzyło linku: ' . $msg);
        }
        $set('CREATED', $payuId, $link, null);

        // Płatność od razu przy zamówieniu (sekcja „Płatność PayU”), status „nowa” do czasu zapłaty.
        $this->pdo->prepare('INSERT INTO payu_order_payments (woo_order_id, payu_order_id, integration_id, status, amount, currency, linked_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$wooOrderId, $payuId, $accountId, 'NEW', $amount, 'PLN', mb_substr('link: ' . $user, 0, 120), $now]);
        try {
            $this->refresh((int) $this->pdo->query('SELECT id FROM payu_order_payments WHERE payu_order_id = ' . $this->pdo->quote($payuId))->fetchColumn());
        } catch (\Throwable) {
            // szczegóły dociągną się przy następnym odświeżeniu
        }
        $st->execute([$extId]);
        return (array) $st->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Opcje linku dla zamówienia z CRM: adres powiadomień PayU, powrót klienta na stronę jego zamówienia i dane płacącego
     * (PayU wypełni nimi formularz płatności).
     * @return array{notifyUrl:string,continueUrl:string,customerIp:string,buyer:array<string,string>}
     */
    public static function linkOptions(PDO $pdo, array $row): array
    {
        $base = rtrim((string) ((new \Pase\Repository\SettingsRepository($pdo))->get('APP_BASE_URL', '') ?? ''), '/');
        $billing = InvoiceData::originalBilling(json_decode((string) ($row['payload'] ?? ''), true) ?: []);
        return [
            'notifyUrl'   => $base !== '' ? $base . '/webhook_payu.php' : '',
            'continueUrl' => (string) ((new ClientLinks($pdo))->forOrder($row) ?? ''),
            'customerIp'  => (string) (filter_var($_SERVER['SERVER_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: ''),
            'buyer'       => [
                'email'     => (string) ($row['local_billing_email'] ?? '') ?: (string) ($billing['email'] ?? ''),
                'phone'     => (string) ($row['local_billing_phone'] ?? '') ?: (string) ($billing['phone'] ?? ''),
                'firstName' => (string) ($billing['first_name'] ?? ''),
                'lastName'  => (string) ($billing['last_name'] ?? ''),
            ],
        ];
    }

    /**
     * Powiadomienie PayU (public/webhook_payu.php). Treści nie ufamy: bierzemy z niej tylko numer płatności
     * i - jeśli to płatność z naszego linku - czytamy jej stan z PayU własnymi kluczami.
     * @return bool czy odświeżono płatność
     */
    public function notification(string $raw): bool
    {
        $data = json_decode($raw, true);
        $payuId = is_array($data) ? strtoupper(trim((string) ($data['order']['orderId'] ?? ''))) : '';
        if (!preg_match('/^[A-Z0-9]{10,64}$/', $payuId)) {
            return false;
        }
        $st = $this->pdo->prepare('SELECT p.id, p.fetched_at FROM payu_order_payments p JOIN payu_payment_links l ON l.payu_order_id = p.payu_order_id WHERE p.payu_order_id = ?');
        $st->execute([$payuId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p || ($p['fetched_at'] !== null && strtotime((string) $p['fetched_at']) > time() - 15)) {
            return false;   // nie nasz link albo odświeżony przed chwilą (PayU wysyła kilka powiadomień naraz)
        }
        $this->refresh((int) $p['id']);
        return true;
    }

    /** Cron: odświeża nieopłacone płatności z linków, które jeszcze nie wygasły (na wypadek, gdyby powiadomienie PayU nie doszło). */
    public function refreshOpenLinks(int $limit = 10): int
    {
        $st = $this->pdo->prepare("SELECT p.id FROM payu_payment_links l JOIN payu_order_payments p ON p.payu_order_id = l.payu_order_id
            WHERE l.status = 'CREATED' AND (p.status IS NULL OR p.status NOT IN ('COMPLETED', 'CANCELED')) AND l.expires_at > ?
            ORDER BY p.fetched_at ASC LIMIT " . max(1, $limit));
        $st->execute([date('Y-m-d H:i:s', time() - 86400)]);
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            try {
                $this->refresh((int) $id);
                $n++;
            } catch (\Throwable $e) {
                \Pase\Support\Logger::warn('PayU: odświeżenie płatności z linku nie powiodło się: ' . $e->getMessage());
            }
        }
        return $n;
    }

    /**
     * Szczegóły płatności do rozwijanej sekcji na zamówieniu - układ jak w panelu PayU (Dane wpłaty, Przebieg operacji,
     * Dane płacącego, Szczegóły operacji). Tylko pola, które PayU podało; puste pomijane.
     * @return array<string,array<string,string>> sekcja => [etykieta => wartość]
     */
    public function details(array $payment, string $shopName): array
    {
        $raw = json_decode((string) ($payment['data'] ?? ''), true) ?: [];
        $order = isset($raw['order']) ? (array) $raw['order'] : $raw;   // starsze wpisy: samo zamówienie
        $props = (array) ($raw['properties'] ?? []);
        $tx = (array) ($raw['transaction'] ?? []);
        $prop = static function (string $name) use ($props): string {
            foreach ($props as $p) {
                if (is_array($p) && ($p['name'] ?? '') === $name) {
                    return (string) ($p['value'] ?? '');
                }
            }
            return '';
        };
        $date = static function (?string $iso): string {
            if ($iso === null || $iso === '') {
                return '';
            }
            try {
                return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d H:i');
            } catch (\Throwable) {
                return $iso;
            }
        };
        $cur = (string) ($payment['currency'] ?: 'PLN');
        $buyer = (array) ($order['buyer'] ?? []);
        $delivery = (array) ($buyer['delivery'] ?? []);
        $card = (array) ($tx['card']['cardData'] ?? []);
        $bank = (array) ($tx['bankAccount'] ?? []);
        $refunds = array_filter($this->refunds((int) $payment['woo_order_id']), static fn(array $r): bool => $r['payu_order_id'] === $payment['payu_order_id']);
        $lastRefund = $refunds ? max(array_map(static fn(array $r): string => (string) $r['created_at'], $refunds)) : '';
        $captured = isset($order['capturedAmount']) && (string) $order['capturedAmount'] !== '' && (int) $order['capturedAmount'] !== (int) ($order['totalAmount'] ?? 0)
            ? PayuPayouts::money((int) $order['capturedAmount'], $cur) : '';
        $sections = [
            'Dane wpłaty' => [
                'ID transakcji' => $prop('PAYMENT_ID'),
                'Numer zamówienia w PayU' => (string) $payment['payu_order_id'],
                'Tytuł transakcji' => (string) ($order['description'] ?? ''),
                'Kwota' => PayuPayouts::money((int) ($order['totalAmount'] ?? $payment['amount']), $cur),
                'Pobrano' => $captured,
                'Typ operacji' => (string) $payment['pay_method'],
                'Sklep' => $shopName,
                'Punkt płatności' => (string) ($order['merchantPosId'] ?? ''),
                'Opis dodatkowy' => (string) ($order['additionalDescription'] ?? ''),
                'ID zamówienia (sklep)' => (string) ($order['extOrderId'] ?? ''),
            ],
            'Przebieg operacji' => [
                'Status płatności' => self::statusLabel((string) ($order['status'] ?? $payment['status'])),
                'Data utworzenia' => $date($order['orderCreateDate'] ?? null),
                'Data ostatniego zwrotu' => $lastRefund !== '' ? substr($lastRefund, 0, 16) : '',
                'Opis statusu płatności kartą' => (string) ($card['cardResponseCodeDesc'] ?? ''),
                '3-D Secure' => (string) ($card['card3DsStatusDescription'] ?? ''),
                'Kod wyniku' => (string) ($tx['resultCode'] ?? ''),
            ],
            'Dane płacącego' => [
                'Imię' => (string) ($buyer['firstName'] ?? ''),
                'Nazwisko' => (string) ($buyer['lastName'] ?? ''),
                'E-mail' => (string) ($buyer['email'] ?? ''),
                'Telefon' => (string) ($buyer['phone'] ?? ''),
                'Adres' => (string) ($delivery['street'] ?? ''),
                'Miejscowość' => (string) ($delivery['city'] ?? ''),
                'Kod pocztowy' => (string) ($delivery['postalCode'] ?? ''),
                'IP klienta' => (string) ($order['customerIp'] ?? ''),
            ],
            'Szczegóły operacji' => [
                'Karta' => !empty($card['cardNumberMasked']) ? trim((string) ($card['cardScheme'] ?? '') . ' ' . $card['cardNumberMasked']) : '',
                'Rodzaj karty' => trim((string) ($card['cardClassification'] ?? '') . ' ' . (string) ($card['cardProfile'] ?? '')),
                'Kraj karty' => (string) ($card['cardBinCountry'] ?? ''),
                'Nazwa klienta' => (string) ($bank['name'] ?? ''),
                'Ulica' => (string) ($bank['address'] ?? $bank['street'] ?? ''),
                'Miasto' => (string) ($bank['city'] ?? ''),
                'Kod pocztowy' => (string) ($bank['postalCode'] ?? ''),
                'Numer konta' => (string) ($bank['number'] ?? $bank['accountNumber'] ?? ''),
            ],
        ];
        foreach ($sections as $k => $rows) {
            $sections[$k] = array_filter($rows, static fn(string $v): bool => trim($v) !== '');
        }
        return array_filter($sections);
    }

    /** Czytelna metoda płatności: typ z zamówienia + szczegóły transakcji (karta, bank). */
    public static function payMethodLabel(array $order, array $tx): string
    {
        $type = (string) ($tx['payMethod']['value'] ?? $order['payMethod']['type'] ?? '');
        $label = self::PAY_METHODS[$type] ?? ($type !== '' ? $type : '');
        $card = (array) ($tx['card']['cardData'] ?? []);
        if (!empty($card['cardNumberMasked'])) {
            $label = 'karta ' . trim((string) ($card['cardScheme'] ?? '') . ' ' . $card['cardNumberMasked']);
        } elseif (!empty($tx['bankAccount']['name'])) {
            $label .= ' · ' . (string) $tx['bankAccount']['name'];
        } elseif ($type === 'PBL' && !empty($tx['payMethod']['value'])) {
            $label .= ' (' . (string) $tx['payMethod']['value'] . ')';
        }
        return mb_substr($label, 0, 255);
    }

    /** Aktualizuje statusy zwrotów z PayU (i dopisuje zwroty zrobione poza CRM, np. w panelu PayU). */
    private function syncRefunds(array $p): void
    {
        $api = $this->payu->api((int) $p['integration_id']);
        [$st, $data] = $api->getRefunds((string) $p['payu_order_id']);
        if ($st !== 200) {
            return;
        }
        $list = isset($data['refunds']) ? (array) $data['refunds'] : (array_is_list($data) ? $data : []);
        $now = date('Y-m-d H:i:s');
        foreach ($list as $r) {
            if (!is_array($r) || empty($r['refundId'])) {
                continue;
            }
            $upd = $this->pdo->prepare('UPDATE payu_refunds SET refund_id = ?, status = ?, error = ?, updated_at = ? WHERE payu_order_id = ? AND (refund_id = ? OR ext_refund_id = ?)');
            $err = isset($r['statusError']) ? trim((string) ($r['statusError']['description'] ?? $r['statusError']['code'] ?? '')) : null;
            $upd->execute([(string) $r['refundId'], (string) ($r['status'] ?? ''), $err ?: null, $now, $p['payu_order_id'], (string) $r['refundId'], (string) ($r['extRefundId'] ?? '')]);
            if ($upd->rowCount() === 0) {
                $this->pdo->prepare('INSERT INTO payu_refunds (woo_order_id, payu_order_id, integration_id, ext_refund_id, refund_id, amount, currency, description, status, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                    $p['woo_order_id'], $p['payu_order_id'], $p['integration_id'], 'payu-' . $r['refundId'], (string) $r['refundId'], (int) ($r['amount'] ?? 0),
                    (string) ($r['currencyCode'] ?? $p['currency'] ?? 'PLN'), mb_substr((string) ($r['description'] ?? ''), 0, 255), (string) ($r['status'] ?? ''),
                    'panel PayU', $now, $now,
                ]);
            }
        }
    }

    /**
     * Dopasowanie hurtem: zamówienia ze sklepu opłacone bramką PayU, bez przypisanej płatności, najnowsze pierwsze.
     * Tylko odczyt z PayU. Limit na jedno kliknięcie, żeby nie przekroczyć czasu żądania.
     * @return array{checked:int,linked:int,failed:int,remaining:bool}
     */
    public function matchRecent(int $limit = 40): array
    {
        $st = $this->pdo->prepare("SELECT woo_order_id, payload FROM woo_orders o WHERE o.woo_order_id < 9000000000 AND o.payload LIKE ?
            AND NOT EXISTS (SELECT 1 FROM payu_order_payments p WHERE p.woo_order_id = o.woo_order_id) ORDER BY o.woo_order_id DESC");
        $st->execute(['%"payment_method":"payu%']);
        $out = ['checked' => 0, 'linked' => 0, 'failed' => 0, 'remaining' => false];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $cand = self::candidateId(json_decode((string) $row['payload'], true) ?: []);
            if ($cand === null || $this->linkedElsewhere($cand, (int) $row['woo_order_id'])) {
                continue;
            }
            if ($out['checked'] >= $limit) {
                $out['remaining'] = true;
                break;
            }
            $out['checked']++;
            try {
                $this->link((int) $row['woo_order_id'], $cand, 'auto');
                $out['linked']++;
            } catch (\Throwable) {
                $out['failed']++;
            }
        }
        return $out;
    }

    /** Ile jeszcze można zwrócić z płatności (grosze): opłacone minus zwroty inne niż anulowane i odrzucone. */
    public function refundable(array $payment): int
    {
        if (($payment['status'] ?? '') !== self::PAID) {
            return 0;
        }
        $st = $this->pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payu_refunds WHERE payu_order_id = ? AND status NOT IN ('CANCELED', 'ERROR')");
        $st->execute([$payment['payu_order_id']]);
        return max(0, (int) $payment['amount'] - (int) $st->fetchColumn());
    }

    public static function newExtId(): string
    {
        return 'crmref-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    }

    /**
     * Zwrot przez PayU (po potwierdzeniu). Kwota w groszach.
     * @return array<string,mixed> zapisany zwrot (payu_refunds)
     */
    public function refund(int $paymentId, int $amount, string $description, string $extId, string $user, ?int $returnId = null): array
    {
        if (!preg_match('/^crmref-[0-9a-z-]{8,56}$/', $extId)) {
            throw new \RuntimeException('Nieprawidłowe zlecenie zwrotu. Otwórz zamówienie i zacznij od nowa.');
        }
        $st = $this->pdo->prepare('SELECT 1 FROM payu_refunds WHERE ext_refund_id = ?');
        $st->execute([$extId]);
        if ($st->fetchColumn()) {
            throw new \RuntimeException('Ten zwrot został już zlecony - nic nie wysłano drugi raz.');
        }
        $p = $this->payment($paymentId);
        if ($p === null) {
            throw new \RuntimeException('Nie ma takiej płatności PayU.');
        }
        $p = $this->refresh($paymentId);   // świeży status i zwroty tuż przed wysłaniem
        $max = $this->refundable($p);
        if ($p['status'] !== self::PAID) {
            throw new \RuntimeException('Płatność PayU nie jest opłacona (status: ' . self::statusLabel((string) $p['status']) . ') - nie ma czego zwracać.');
        }
        if ($amount <= 0 || $amount > $max) {
            throw new \RuntimeException('Kwota zwrotu ' . PayuPayouts::money($amount, (string) $p['currency']) . ' jest większa niż możliwa do zwrotu ' . PayuPayouts::money($max, (string) $p['currency']) . '. Nic nie wysłano.');
        }
        $description = mb_substr(trim($description) !== '' ? trim($description) : 'Zwrot', 0, 100);
        $now = date('Y-m-d H:i:s');
        try {
            $this->pdo->prepare('INSERT INTO payu_refunds (woo_order_id, payu_order_id, integration_id, ext_refund_id, amount, currency, description, status, return_id, created_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$p['woo_order_id'], $p['payu_order_id'], $p['integration_id'], $extId, $amount, $p['currency'], $description, 'SENDING', $returnId, $user, $now, $now]);
        } catch (\PDOException) {
            throw new \RuntimeException('Ten zwrot został już zlecony - nic nie wysłano drugi raz.');
        }
        $api = $this->payu->api((int) $p['integration_id']);
        try {
            [$code, $data] = $api->createRefund((string) $p['payu_order_id'], $amount, $description, $extId);
        } catch (\Throwable $e) {
            $this->setRefund($extId, 'UNKNOWN', null, $e->getMessage());
            throw new \RuntimeException('Nie wiadomo, czy PayU przyjęło zwrot (' . $e->getMessage() . '). Sprawdź w panelu PayU, zanim zlecisz go ponownie.');
        }
        $refundId = (string) ($data['refund']['refundId'] ?? '');
        if ($code >= 200 && $code < 300 && $refundId !== '') {
            $this->setRefund($extId, strtoupper((string) ($data['refund']['status'] ?? 'PENDING')), $refundId, null);
            return (array) $this->findRefund($extId);
        }
        $msg = $api::errorMessage($code, $data);
        $unknown = $code === 0 || $code >= 500;
        $this->setRefund($extId, $unknown ? 'UNKNOWN' : 'ERROR', null, $msg);
        throw new \RuntimeException($unknown
            ? 'Nie wiadomo, czy PayU przyjęło zwrot (' . $msg . '). Sprawdź w panelu PayU, zanim zlecisz go ponownie.'
            : 'PayU nie przyjęło zwrotu: ' . $msg);
    }

    public function findRefund(string $extId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM payu_refunds WHERE ext_refund_id = ?');
        $st->execute([$extId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function setRefund(string $extId, string $status, ?string $refundId, ?string $error): void
    {
        $this->pdo->prepare('UPDATE payu_refunds SET status = ?, refund_id = COALESCE(?, refund_id), error = ?, updated_at = ? WHERE ext_refund_id = ?')
            ->execute([$status, $refundId, $error !== null ? mb_substr($error, 0, 500) : null, date('Y-m-d H:i:s'), $extId]);
    }

    /**
     * Zwrot z modułu Zwroty (OrderReturns, sposób „payu”): jedna opłacona płatność PayU zamówienia.
     * @return array{ok:bool,status:int,id:string,message:string}
     */
    public function refundForReturn(int $wooOrderId, float $amount, string $comment, int $returnId, string $user): array
    {
        $paid = array_values(array_filter($this->payments($wooOrderId), static fn(array $p): bool => $p['status'] === self::PAID));
        if (count($paid) !== 1) {
            return ['ok' => false, 'status' => -1, 'id' => '', 'message' => $paid === [] ? 'Zamówienie nie ma opłaconej płatności PayU.' : 'Zamówienie ma kilka płatności PayU - zwróć ze strony zamówienia.'];
        }
        try {
            $r = $this->refund((int) $paid[0]['id'], (int) round($amount * 100), $comment !== '' ? $comment : 'Zwrot towaru', self::newExtId(), $user, $returnId);
        } catch (\RuntimeException $e) {
            // „Nie wiadomo” (brak odpowiedzi) = status 0, żeby moduł Zwroty zablokował ponowienie; odrzucenie = -1.
            return ['ok' => false, 'status' => str_starts_with($e->getMessage(), 'Nie wiadomo') ? 0 : -1, 'id' => '', 'message' => $e->getMessage()];
        }
        return ['ok' => true, 'status' => 201, 'id' => (string) $r['refund_id'], 'message' => ''];
    }

    public static function statusLabel(string $s): string
    {
        return self::STATUS_LABELS[$s] ?? ($s !== '' ? $s : '—');
    }

    public static function refundLabel(string $s): string
    {
        return self::REFUND_LABELS[$s] ?? $s;
    }
}
