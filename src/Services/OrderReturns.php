<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Queue\Queue;
use Pase\Repository\ProductRepository;
use Pase\Repository\WooOrderRepository;

/**
 * Zwroty towaru od klientów: zwroty zgłoszone na Allegro (GET /order/customer-returns)
 * i zwroty dodane ręcznie w CRM (np. do zamówień ze sklepu).
 *
 * Każdy zwrot ma trzy osobne kroki, każdy wykonywany wyłącznie kliknięciem operatora:
 *   1. przyjęcie zwróconych sztuk na stan magazynu,
 *   2. faktura korygująca w wFirma,
 *   3. zwrot pieniędzy (Allegro: POST /payments/refunds, sklep: zwrot w WooCommerce, albo odnotowanie
 *      zwrotu zrobionego poza CRM).
 * Kroki 2 i 3 są rezerwowane w bazie przed wysłaniem żądania (stan 'pending'). Błąd sieci albo
 * przerwany proces zostawia stan 'uncertain' i blokuje ponowienie, dopóki administrator nie sprawdzi
 * wyniku w wFirma / Allegro / sklepie — tak samo jak przy wystawianiu dokumentów (DocumentIssueGuard).
 */
final class OrderReturns
{
    public const SYNC_AT_KEY    = 'ALLEGRO_RETURNS_AT';
    public const SYNC_EVERY_MIN = 30;
    public const SYNC_DAYS_BACK = 60;

    public const STATUS_LABELS = [
        'CREATED'       => 'Zgłoszony przez kupującego',
        'DISPATCHED'    => 'Nadany przez kupującego',
        'IN_TRANSIT'    => 'W drodze',
        'DELIVERED'     => 'Doręczony do sprzedawcy',
        'FINISHED'      => 'Zakończony',
        'FINISHED_APT'  => 'Zakończony (automatycznie)',
        'REJECTED'      => 'Odrzucony',
        'COMMISSION_REFUND_CLAIMED'  => 'Wniosek o zwrot prowizji',
        'COMMISSION_REFUNDED'        => 'Prowizja zwrócona',
        'WAREHOUSE_DELIVERED'        => 'Doręczony do magazynu',
        'WAREHOUSE_VERIFICATION'     => 'Sprawdzany w magazynie',
        'RECEIVED'      => 'Przyjęty (dodany w CRM)',
    ];

    public const REASON_LABELS = [
        'DAMAGED'         => 'Uszkodzony',
        'NOT_AS_DESCRIBED'=> 'Niezgodny z opisem',
        'DIFFERENT'       => 'Inny niż zamówiony',
        'MISTAKE'         => 'Pomyłka przy zakupie',
        'DONT_LIKE_IT'    => 'Nie spełnia oczekiwań',
        'EXCESSIVE'       => 'Zamówiono za dużo',
        'NO_REASON'       => 'Bez podania przyczyny',
        'OTHER'           => 'Inny powód',
    ];

    /** Stany kroków rezerwowanych (korekta, zwrot pieniędzy). NULL = jeszcze nie wykonano. */
    public const STEP_PENDING   = 'pending';
    public const STEP_DONE      = 'done';
    public const STEP_UNCERTAIN = 'uncertain';

    public function __construct(private readonly PDO $pdo) {}

    public static function migrate(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $id   = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $tail = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_returns (
            id $id,
            woo_order_id BIGINT NULL,
            source VARCHAR(16) NOT NULL,
            remote_id VARCHAR(64) NULL,
            reference_number VARCHAR(64) NULL,
            order_ref VARCHAR(64) NULL,
            status VARCHAR(40) NOT NULL,
            buyer VARCHAR(255) NULL,
            items TEXT NOT NULL,
            waybill VARCHAR(128) NULL,
            carrier VARCHAR(64) NULL,
            bank_account TEXT NULL,
            note TEXT NULL,
            restocked_at VARCHAR(30) NULL,
            restocked_by VARCHAR(190) NULL,
            correction_state VARCHAR(16) NULL,
            correction_remote_id VARCHAR(64) NULL,
            correction_message TEXT NULL,
            correction_at VARCHAR(30) NULL,
            refund_state VARCHAR(16) NULL,
            refund_method VARCHAR(16) NULL,
            refund_amount DECIMAL(12,2) NULL,
            refund_remote_id VARCHAR(128) NULL,
            refund_message TEXT NULL,
            refund_at VARCHAR(30) NULL,
            remote_created_at VARCHAR(30) NULL,
            created_at VARCHAR(30) NOT NULL,
            updated_at VARCHAR(30) NOT NULL
        )$tail");
        if ($driver === 'mysql') {
            $idx = $pdo->query("SHOW INDEX FROM order_returns WHERE Key_name = 'uq_return_remote'")->fetch();
            if (!$idx) {
                $pdo->exec('CREATE UNIQUE INDEX uq_return_remote ON order_returns (source, remote_id)');
                $pdo->exec('CREATE INDEX idx_return_order ON order_returns (woo_order_id)');
            }
        } else {
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_return_remote ON order_returns (source, remote_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_return_order ON order_returns (woo_order_id)');
        }
    }

    // ------------------------------------------------------------
    //  Odczyt
    // ------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM order_returns WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::hydrate($row) : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function forOrder(int $wooOrderId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM order_returns WHERE woo_order_id = ? ORDER BY id DESC');
        $stmt->execute([$wooOrderId]);
        return array_map([self::class, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Lista zwrotów. $filter: '' (wszystkie) | 'open' (coś jeszcze do zrobienia) | 'done'.
     * @return array<int,array<string,mixed>>
     */
    public function list(string $filter = '', string $q = '', int $limit = 100, int $offset = 0): array
    {
        $where = [];
        $params = [];
        $open = "(restocked_at IS NULL OR correction_state IS NULL OR correction_state <> 'done' OR refund_state IS NULL OR refund_state <> 'done')";
        if ($filter === 'open') {
            $where[] = "status <> 'REJECTED' AND $open";
        } elseif ($filter === 'done') {
            $where[] = "NOT $open";
        }
        if ($q !== '') {
            $where[] = '(buyer LIKE ? OR reference_number LIKE ? OR order_ref LIKE ? OR waybill LIKE ? OR items LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $sql = 'SELECT * FROM order_returns' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY COALESCE(remote_created_at, created_at) DESC, id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map([self::class, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row): array
    {
        $row['items'] = json_decode((string) $row['items'], true) ?: [];
        $row['bank_account'] = json_decode((string) ($row['bank_account'] ?? ''), true) ?: null;
        return $row;
    }

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? $status;
    }

    public static function reasonLabel(string $reason): string
    {
        return self::REASON_LABELS[$reason] ?? ($reason !== '' ? $reason : '—');
    }

    /** Wartość brutto zwracanych pozycji. @param array<int,array<string,mixed>> $items */
    public static function itemsValue(array $items): float
    {
        $sum = 0.0;
        foreach ($items as $it) {
            $sum += (float) ($it['price'] ?? 0) * max(0, (int) ($it['quantity'] ?? 0));
        }
        return round($sum, 2);
    }

    // ------------------------------------------------------------
    //  Allegro: zwroty klientów
    // ------------------------------------------------------------

    /**
     * Zapisuje / aktualizuje zwrot z GET /order/customer-returns. Lokalne kroki (stan, korekta,
     * zwrot pieniędzy) nie są nadpisywane; aktualizuje się status, paczka i pozycje.
     * @param array<string,mixed> $cr
     * @return int|null id zwrotu w CRM albo null, gdy dane są niepełne
     */
    public function upsertAllegro(array $cr): ?int
    {
        $remoteId = trim((string) ($cr['id'] ?? ''));
        if ($remoteId === '') {
            return null;
        }
        $orderRef = trim((string) ($cr['orderId'] ?? ''));
        $order = $orderRef !== '' ? $this->findAllegroOrder($orderRef) : null;
        $payload = $order !== null ? (json_decode((string) ($order['payload'] ?? ''), true) ?: []) : [];
        $items = self::allegroItems((array) ($cr['items'] ?? []), $payload);

        $parcel = is_array($cr['parcels'][0] ?? null) ? $cr['parcels'][0] : [];
        $buyer = (array) ($cr['buyer'] ?? []);
        $bank = is_array($cr['refund']['bankAccount'] ?? null) ? $cr['refund']['bankAccount'] : null;
        $fields = [
            'woo_order_id'     => $order !== null ? (int) $order['woo_order_id'] : null,
            'reference_number' => (string) ($cr['referenceNumber'] ?? ''),
            'order_ref'        => $orderRef,
            'status'           => (string) ($cr['status'] ?? 'CREATED'),
            'buyer'            => trim((string) ($buyer['login'] ?? '') . ((isset($buyer['email']) && $buyer['email'] !== '') ? ' <' . $buyer['email'] . '>' : '')),
            'items'            => json_encode($items, JSON_UNESCAPED_UNICODE),
            'waybill'          => (string) ($parcel['waybill'] ?? $parcel['transportingWaybill'] ?? ''),
            'carrier'          => (string) ($parcel['carrierId'] ?? $parcel['transportingCarrierId'] ?? ''),
            'bank_account'     => $bank !== null ? json_encode($bank, JSON_UNESCAPED_UNICODE) : null,
            'remote_created_at'=> isset($cr['createdAt']) ? substr(str_replace('T', ' ', (string) $cr['createdAt']), 0, 19) : null,
        ];

        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("SELECT id FROM order_returns WHERE source = 'allegro' AND remote_id = ?");
        $stmt->execute([$remoteId]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            if ($fields['woo_order_id'] === null) {
                unset($fields['woo_order_id']); // nie kasuj powiązania ustawionego ręcznie przez operatora
            }
            $sets = implode(', ', array_map(static fn($k) => "$k = ?", array_keys($fields)));
            $this->pdo->prepare("UPDATE order_returns SET $sets, updated_at = ? WHERE id = ?")
                ->execute([...array_values($fields), $now, (int) $existing]);
            return (int) $existing;
        }
        $cols = array_keys($fields);
        $this->pdo->prepare('INSERT INTO order_returns (source, remote_id, ' . implode(', ', $cols) . ', created_at, updated_at) VALUES ('
            . implode(', ', array_fill(0, count($cols) + 4, '?')) . ')')
            ->execute(['allegro', $remoteId, ...array_values($fields), $now, $now]);
        $id = (int) $this->pdo->lastInsertId();
        if ($fields['woo_order_id'] !== null) {
            (new AuditTrail($this->pdo))->record((int) $fields['woo_order_id'], 'return.created', [], ['provider' => 'allegro', 'remote_id' => $remoteId]);
        }
        return $id;
    }

    /**
     * Pozycje zwrotu Allegro, uzupełnione o SKU i ID pozycji zamówienia (lineItem.id) z zamówienia w CRM.
     * @param array<int,mixed> $returnItems
     * @param array<string,mixed> $orderPayload checkout-form zapisany w woo_orders.payload
     * @return array<int,array<string,mixed>>
     */
    public static function allegroItems(array $returnItems, array $orderPayload): array
    {
        $lines = [];
        foreach ((array) ($orderPayload['line_items'] ?? []) as $li) {
            if (is_array($li) && !empty($li['allegro_offer_id'])) {
                $lines[(string) $li['allegro_offer_id']][] = $li;
            }
        }
        $out = [];
        foreach ($returnItems as $it) {
            if (!is_array($it)) {
                continue;
            }
            $offerId = (string) ($it['offerId'] ?? $it['offer']['id'] ?? '');
            $line = $lines[$offerId][0] ?? [];
            $out[] = [
                'name'         => (string) ($it['name'] ?? $line['name'] ?? 'Pozycja'),
                'sku'          => isset($line['sku']) && $line['sku'] !== '' ? (string) $line['sku'] : null,
                'offer_id'     => $offerId !== '' ? $offerId : null,
                'line_item_id' => isset($line['id']) ? (string) $line['id'] : null,
                'quantity'     => max(1, (int) ($it['quantity'] ?? 1)),
                'price'        => round((float) ($it['price']['amount'] ?? $line['price'] ?? 0), 2),
                'currency'     => (string) ($it['price']['currency'] ?? 'PLN'),
                'reason'       => (string) ($it['reason']['type'] ?? ''),
                'comment'      => (string) ($it['reason']['userComment'] ?? ''),
            ];
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    private function findAllegroOrder(string $checkoutFormId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM woo_orders WHERE order_number = ? AND woo_order_id >= 9000000000 LIMIT 1');
        $stmt->execute([$checkoutFormId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Pobiera zwroty z Allegro z ostatnich SYNC_DAYS_BACK dni i zapisuje je.
     * @param object $client AllegroClient (customerReturns())
     * @return array{ok:bool,count:int,message:string}
     */
    public function syncAllegro(object $client): array
    {
        $since = gmdate('Y-m-d\TH:i:s.000\Z', time() - self::SYNC_DAYS_BACK * 86400);
        $count = 0;
        for ($page = 0; $page < 20; $page++) {
            $res = $client->customerReturns(['createdAt.gte' => $since, 'limit' => 100, 'offset' => $page * 100]);
            if (!$res['ok']) {
                return ['ok' => false, 'count' => $count, 'message' => $res['message']];
            }
            foreach ($res['items'] as $cr) {
                if ($this->upsertAllegro($cr) !== null) {
                    $count++;
                }
            }
            if (count($res['items']) < 100) {
                break;
            }
        }
        return ['ok' => true, 'count' => $count, 'message' => ''];
    }

    // ------------------------------------------------------------
    //  Zwrot dodany ręcznie
    // ------------------------------------------------------------

    /**
     * Pozycje zamówienia, które można zwrócić (z lokalnej edycji pozycji albo ze sklepu / Allegro).
     * @param array<string,mixed> $order wiersz woo_orders
     * @return array<int,array{key:string,name:string,sku:?string,line_item_id:?string,offer_id:?string,quantity:int,price:float}>
     */
    public static function orderLines(array $order): array
    {
        $payload = json_decode((string) ($order['payload'] ?? ''), true) ?: [];
        $items = ($order['local_items'] ?? null) !== null
            ? (json_decode((string) $order['local_items'], true) ?: [])
            : (array) ($payload['line_items'] ?? []);
        $out = [];
        foreach (array_values($items) as $i => $li) {
            if (!is_array($li)) {
                continue;
            }
            $qty = max(1, (int) ($li['quantity'] ?? 1));
            $gross = isset($li['total'])
                ? ((float) $li['total'] + (float) ($li['total_tax'] ?? 0)) / $qty
                : (float) ($li['price'] ?? 0);
            $out[] = [
                'key'          => (string) $i,
                'name'         => (string) ($li['name'] ?? 'Pozycja'),
                'sku'          => isset($li['sku']) && $li['sku'] !== '' ? (string) $li['sku'] : null,
                'line_item_id' => isset($li['id']) ? (string) $li['id'] : null,
                'offer_id'     => isset($li['allegro_offer_id']) ? (string) $li['allegro_offer_id'] : null,
                'quantity'     => $qty,
                'price'        => round($gross, 2),
            ];
        }
        return $out;
    }

    /**
     * Zwrot dodany przez operatora (np. klient odesłał paczkę ze sklepu).
     * @param array<string,int> $quantities klucz pozycji z orderLines() => zwracana ilość
     */
    public function createManual(int $wooOrderId, array $quantities, string $reason, string $note, string $waybill = ''): int
    {
        $order = (new WooOrderRepository($this->pdo))->find($wooOrderId);
        if ($order === null) {
            throw new \RuntimeException('Nie znaleziono zamówienia.');
        }
        $currency = (string) ($order['currency'] ?? 'PLN');
        $items = [];
        foreach (self::orderLines($order) as $line) {
            $qty = (int) ($quantities[$line['key']] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            if ($qty > $line['quantity']) {
                throw new \RuntimeException('Zwracana ilość „' . $line['name'] . '” jest większa niż w zamówieniu (' . $line['quantity'] . ').');
            }
            $items[] = [
                'name' => $line['name'], 'sku' => $line['sku'], 'offer_id' => $line['offer_id'],
                'line_item_id' => $line['line_item_id'], 'quantity' => $qty, 'price' => $line['price'],
                'currency' => $currency, 'reason' => isset(self::REASON_LABELS[$reason]) ? $reason : 'OTHER', 'comment' => '',
            ];
        }
        if ($items === []) {
            throw new \RuntimeException('Podaj ilość przy co najmniej jednej zwracanej pozycji.');
        }
        $now = gmdate('Y-m-d H:i:s');
        return (int) (new AuditTrail($this->pdo))->atomic(function () use ($wooOrderId, $order, $items, $note, $waybill, $now): int {
            $this->pdo->prepare('INSERT INTO order_returns (woo_order_id, source, order_ref, status, buyer, items, waybill, note, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$wooOrderId, 'manual', (string) ($order['order_number'] ?? $wooOrderId), 'RECEIVED',
                    (string) ($order['customer_name'] ?? ''), json_encode($items, JSON_UNESCAPED_UNICODE),
                    mb_substr(trim($waybill), 0, 128), mb_substr(trim($note), 0, 2000), $now, $now]);
            $id = (int) $this->pdo->lastInsertId();
            (new AuditTrail($this->pdo))->record($wooOrderId, 'return.created', [], ['provider' => 'manual', 'remote_id' => (string) $id]);
            return $id;
        });
    }

    public function saveNote(int $id, string $note): void
    {
        $this->pdo->prepare('UPDATE order_returns SET note = ?, updated_at = ? WHERE id = ?')
            ->execute([mb_substr(trim($note), 0, 2000), gmdate('Y-m-d H:i:s'), $id]);
    }

    /** Przypisuje zwrot Allegro, który nie znalazł zamówienia, do zamówienia w CRM. */
    public function linkOrder(int $id, int $wooOrderId): void
    {
        if ((new WooOrderRepository($this->pdo))->find($wooOrderId) === null) {
            throw new \RuntimeException('Nie znaleziono zamówienia.');
        }
        $this->pdo->prepare('UPDATE order_returns SET woo_order_id = ?, updated_at = ? WHERE id = ?')
            ->execute([$wooOrderId, gmdate('Y-m-d H:i:s'), $id]);
    }

    // ------------------------------------------------------------
    //  Krok 1: przyjęcie na stan
    // ------------------------------------------------------------

    /**
     * Dolicza zwrócone sztuki do stanu CRM (po SKU) i kolejkuje wysłanie stanu do sklepu.
     * Jednorazowe: drugie kliknięcie nic nie zmienia.
     * @param array<int,int>|null $quantities indeks pozycji zwrotu => ilość przyjmowana (np. bez uszkodzonych); null = wszystko
     * @return array{restocked:array<int,array{sku:string,quantity:int}>,skipped:array<int,string>}
     */
    public function restock(int $id, ?array $quantities = null, ?Queue $queue = null): array
    {
        $ret = $this->find($id);
        if ($ret === null) {
            throw new \RuntimeException('Nie znaleziono zwrotu.');
        }
        $products = new ProductRepository($this->pdo);
        $done = [];
        $skipped = [];
        (new AuditTrail($this->pdo))->atomic(function () use ($id, $ret, $quantities, $products, &$done, &$skipped): void {
            $stmt = $this->pdo->prepare('UPDATE order_returns SET restocked_at = ?, restocked_by = ?, updated_at = ? WHERE id = ? AND restocked_at IS NULL');
            $stmt->execute([gmdate('Y-m-d H:i:s'), (string) ($_SESSION['pase_username'] ?? 'System'), gmdate('Y-m-d H:i:s'), $id]);
            if ($stmt->rowCount() !== 1) {
                throw new \RuntimeException('Ten zwrot został już przyjęty na stan.');
            }
            foreach ($ret['items'] as $i => $it) {
                $qty = $quantities === null ? (int) $it['quantity'] : max(0, min((int) $it['quantity'], (int) ($quantities[$i] ?? 0)));
                if ($qty <= 0) {
                    continue;
                }
                $sku = (string) ($it['sku'] ?? '');
                $product = $sku !== '' ? $products->findBySku($sku) : null;
                if ($product === null) {
                    $skipped[] = (string) $it['name'];
                    continue;
                }
                // Stan faktyczny też wraca, o ile jest wpisany (pusty zostaje pusty).
                $this->pdo->prepare('UPDATE products SET pase_stock = COALESCE(pase_stock, 0) + ?, actual_stock = actual_stock + ? WHERE id = ?')
                    ->execute([$qty, $qty, (int) $product['id']]);
                $done[] = ['sku' => $sku, 'quantity' => $qty, 'product_id' => (int) $product['id']];
            }
            if ($ret['woo_order_id'] !== null) {
                (new AuditTrail($this->pdo))->record((int) $ret['woo_order_id'], 'return.restocked', [], ['remote_id' => (string) $id]);
            }
        });
        // Stan w CRM jest już zapisany; błąd kolejki nie może udawać, że przyjęcie się nie udało
        // (cykliczna synchronizacja stanów i tak wyśle nowy stan do sklepu).
        foreach ($done as $d) {
            try {
                $queue?->enqueue('woo.stock.push', ['product_id' => $d['product_id']], 'woo.stock.push:' . $d['product_id'] . ':return:' . $id);
            } catch (\Throwable $e) {
                \Pase\Support\Logger::warn('Zwrot #' . $id . ': nie zakolejkowano wysłania stanu ' . $d['sku'] . ' - ' . $e->getMessage());
            }
        }
        return ['restocked' => array_map(static fn($d) => ['sku' => $d['sku'], 'quantity' => $d['quantity']], $done), 'skipped' => $skipped];
    }

    // ------------------------------------------------------------
    //  Rezerwacja kroków nieodwracalnych (korekta, zwrot pieniędzy)
    // ------------------------------------------------------------

    /** Zajmuje krok przed wysłaniem żądania. Musi zostać zapisane (commit) zanim pójdzie żądanie. */
    public function reserve(int $id, string $step): void
    {
        $col = self::stepColumn($step);
        if ($this->pdo->inTransaction()) {
            throw new \RuntimeException('Rezerwacja kroku nie może działać wewnątrz otwartej transakcji.');
        }
        $stmt = $this->pdo->prepare("UPDATE order_returns SET {$col}_state = 'pending', updated_at = ? WHERE id = ? AND {$col}_state IS NULL");
        $stmt->execute([gmdate('Y-m-d H:i:s'), $id]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException($step === 'correction'
                ? 'Korektę do tego zwrotu już wystawiono albo jej wystawianie trwa. Ponowienie zablokowano, aby nie powstał duplikat.'
                : 'Zwrot pieniędzy do tego zwrotu już wykonano albo trwa. Ponowienie zablokowano, aby nie zwrócić pieniędzy dwa razy.');
        }
    }

    /** @param array<string,mixed> $extra dodatkowe kolumny (np. refund_amount, refund_method) */
    public function finish(int $id, string $step, string $remoteId, string $message = '', array $extra = []): void
    {
        $col = self::stepColumn($step);
        $allowed = ['refund_amount', 'refund_method'];
        $extra = array_intersect_key($extra, array_flip($allowed));
        $sets = '';
        foreach (array_keys($extra) as $k) {
            $sets .= ", $k = ?";
        }
        $stmt = $this->pdo->prepare("UPDATE order_returns SET {$col}_state = 'done', {$col}_remote_id = ?, {$col}_message = ?, {$col}_at = ?, updated_at = ?$sets
            WHERE id = ? AND {$col}_state IN ('pending', 'uncertain')");
        $now = gmdate('Y-m-d H:i:s');
        $stmt->execute([mb_substr($remoteId, 0, 128), mb_substr($message, 0, 2000), $now, $now, ...array_values($extra), $id]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Nie można zapisać wyniku kroku — stan zwrotu zmienił się w międzyczasie.');
        }
        $ret = $this->find($id);
        if ($ret !== null && $ret['woo_order_id'] !== null) {
            (new AuditTrail($this->pdo))->record((int) $ret['woo_order_id'], $step === 'correction' ? 'return.correction_issued' : 'return.refunded', [],
                ['provider' => $step === 'correction' ? 'wfirma' : (string) ($extra['refund_method'] ?? ''), 'remote_id' => $remoteId]);
        }
    }

    /** Wynik nieznany (błąd sieci, odrzucenie po wysłaniu). Blokuje ponowienie do decyzji administratora. */
    public function uncertain(int $id, string $step, string $message): void
    {
        $col = self::stepColumn($step);
        $this->pdo->prepare("UPDATE order_returns SET {$col}_state = 'uncertain', {$col}_message = ?, updated_at = ? WHERE id = ? AND {$col}_state = 'pending'")
            ->execute([mb_substr($message, 0, 2000), gmdate('Y-m-d H:i:s'), $id]);
    }

    /** Odrzucenie przed wysłaniem czegokolwiek (np. walidacja w CRM) — krok wraca do „nie wykonano”. */
    public function release(int $id, string $step, string $message): void
    {
        $col = self::stepColumn($step);
        $this->pdo->prepare("UPDATE order_returns SET {$col}_state = NULL, {$col}_message = ?, updated_at = ? WHERE id = ? AND {$col}_state = 'pending'")
            ->execute([mb_substr($message, 0, 2000), gmdate('Y-m-d H:i:s'), $id]);
    }

    /** Administrator sprawdził w wFirma / Allegro / sklepie, że operacja się NIE wykonała. */
    public function confirmAbsent(int $id, string $step): void
    {
        $col = self::stepColumn($step);
        $stmt = $this->pdo->prepare("UPDATE order_returns SET {$col}_state = NULL, {$col}_message = ?, updated_at = ? WHERE id = ? AND {$col}_state = 'uncertain'");
        $stmt->execute(['Administrator potwierdził, że operacja nie została wykonana.', gmdate('Y-m-d H:i:s'), $id]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Odblokować można tylko krok z nieznanym wynikiem.');
        }
    }

    private static function stepColumn(string $step): string
    {
        return match ($step) {
            'correction' => 'correction',
            'refund'     => 'refund',
            default      => throw new \InvalidArgumentException('Nieznany krok: ' . $step),
        };
    }

    // ------------------------------------------------------------
    //  Krok 2: faktura korygująca w wFirma
    // ------------------------------------------------------------

    /**
     * Pozycje faktury po korekcie. Zwracane ilości dopasowuje po nazwie pozycji (tak jak trafiła na fakturę);
     * operator może je zmienić na ekranie potwierdzenia.
     * @param array<int,array{id:string,name:string,count:float,price:float}> $invoiceLines pozycje oryginalnej faktury
     * @param array<int,array<string,mixed>> $returnItems
     * @return array<int,float> indeks pozycji faktury => proponowana ilość zwracana
     */
    public static function suggestCorrection(array $invoiceLines, array $returnItems): array
    {
        $left = [];
        foreach ($returnItems as $it) {
            $key = self::normName((string) ($it['name'] ?? ''));
            $left[$key] = ($left[$key] ?? 0) + (int) ($it['quantity'] ?? 0);
        }
        $out = [];
        foreach ($invoiceLines as $i => $line) {
            $key = self::normName($line['name']);
            $take = min((float) $line['count'], (float) ($left[$key] ?? 0));
            $out[$i] = $take;
            if ($take > 0) {
                $left[$key] -= $take;
            }
        }
        return $out;
    }

    private static function normName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    /**
     * Pozycje do wysłania jako korekta: każda pozycja oryginału z parent_id i ilością PO korekcie.
     * @param array<int,array{id:string,name:string,count:float,price:float,vat?:string,unit?:string}> $invoiceLines
     * @param array<int,float|int|string> $returned indeks => ilość zwracana
     * @return array{positions:array<int,array<string,string>>,amount:float}
     */
    public static function correctionPositions(array $invoiceLines, array $returned): array
    {
        $positions = [];
        $amount = 0.0;
        $any = false;
        foreach ($invoiceLines as $i => $line) {
            $ret = round((float) str_replace(',', '.', (string) ($returned[$i] ?? 0)), 4);
            if ($ret < 0 || $ret > (float) $line['count'] + 0.00001) {
                throw new \RuntimeException('Zwracana ilość „' . $line['name'] . '” musi być między 0 a ' . self::qty((float) $line['count']) . '.');
            }
            $any = $any || $ret > 0;
            $amount += $ret * (float) $line['price'];
            $pos = [
                'parent_id' => (string) $line['id'],
                'name'      => $line['name'],
                'count'     => self::qty((float) $line['count'] - $ret),
                'price'     => number_format((float) $line['price'], 2, '.', ''),
            ];
            foreach (['vat', 'unit'] as $k) {
                if (isset($line[$k]) && $line[$k] !== '') {
                    $pos[$k] = (string) $line[$k];
                }
            }
            $positions[] = $pos;
        }
        if (!$any) {
            throw new \RuntimeException('Podaj zwracaną ilość przy co najmniej jednej pozycji faktury.');
        }
        return ['positions' => $positions, 'amount' => round($amount, 2)];
    }

    private static function qty(float $v): string
    {
        return rtrim(rtrim(number_format(max(0.0, $v), 4, '.', ''), '0'), '.') ?: '0';
    }

    /** ID faktury wFirma (typ normal) wystawionej do zamówienia, albo null. */
    public function invoiceFor(int $wooOrderId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM order_documents WHERE woo_order_id = ? AND provider = 'wfirma' AND document_type = 'normal' LIMIT 1");
        $stmt->execute([$wooOrderId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Wystawia korektę w wFirma. Wołane wyłącznie po potwierdzeniu operatora.
     * @param object $wfirma WfirmaClient (getInvoice(), issueCorrection())
     * @param array<int,float|int|string> $returned indeks pozycji faktury => ilość zwracana
     * @return array{document_id:string,amount:float,message:string}
     */
    public function issueCorrection(int $id, object $wfirma, array $returned, string $reason): array
    {
        $ret = $this->find($id);
        if ($ret === null || $ret['woo_order_id'] === null) {
            throw new \RuntimeException('Zwrot nie jest powiązany z zamówieniem w CRM.');
        }
        if ($ret['correction_state'] !== null) {
            throw new \RuntimeException('Korekta do tego zwrotu została już wystawiona albo wymaga sprawdzenia.');
        }
        $doc = $this->invoiceFor((int) $ret['woo_order_id']);
        if ($doc === null) {
            throw new \RuntimeException('Do zamówienia nie ma faktury z wFirma w CRM. Paragon lub fakturę spoza CRM skoryguj w wFirma.');
        }
        // Pozycje oryginału zawsze świeżo z wFirma — formularz przesyła tylko zwracane ilości.
        $inv = $wfirma->getInvoice((string) $doc['remote_id']);
        if (!$inv['ok']) {
            throw new \RuntimeException('Nie udało się odczytać faktury z wFirma: ' . $inv['message']);
        }
        $calc = self::correctionPositions($inv['invoice']['lines'], $returned);
        $reason = trim($reason) !== '' ? trim($reason) : 'Zwrot towaru';

        $this->reserve($id, 'correction');
        try {
            $r = $wfirma->issueCorrection((string) $doc['remote_id'], $inv['invoice']['contractor_id'], $calc['positions'],
                'Korekta faktury ' . $inv['invoice']['number'] . ' — ' . $reason, $inv['invoice']['currency'] ?: 'PLN');
        } catch (\Throwable $e) {
            $this->uncertain($id, 'correction', $e->getMessage());
            throw new \RuntimeException('Nie wiadomo, czy wFirma wystawiła korektę (' . $e->getMessage() . '). Sprawdź w wFirma; ponowienie zablokowano.', 0, $e);
        }
        if ($r['ok'] && $r['document_id'] !== '') {
            $this->finish($id, 'correction', $r['document_id'], $r['message']);
            return ['document_id' => $r['document_id'], 'amount' => $calc['amount'], 'message' => $r['message']];
        }
        if (self::definitelyRejected($r['status'], $r['ok'])) {
            $this->release($id, 'correction', $r['message']);
            throw new \RuntimeException('wFirma odrzuciła korektę: ' . $r['message']);
        }
        $this->uncertain($id, 'correction', $r['message']);
        throw new \RuntimeException('Nie wiadomo, czy wFirma wystawiła korektę (' . $r['message'] . '). Sprawdź w wFirma; ponowienie zablokowano.');
    }

    /**
     * Czy odpowiedź na pewno oznacza „nic nie zrobiono”: -1 = nie wysłano, 4xx = odrzucone,
     * 2xx bez sukcesu = błąd walidacji (wFirma zwraca wtedy HTTP 200 ze statusem ERROR).
     * Brak odpowiedzi (0) i 5xx to wynik nieznany.
     */
    private static function definitelyRejected(int $status, bool $ok): bool
    {
        return $status === -1 || ($status >= 400 && $status < 500) || ($status >= 200 && $status < 300 && !$ok);
    }

    /**
     * Zwrot pieniędzy. Wołane wyłącznie po potwierdzeniu operatora.
     * $method: 'allegro' (POST /payments/refunds), 'woo' (zwrot w sklepie), 'payu' (zwrot płatności PayU przypisanej do zamówienia,
     * client = PayuPayments), 'manual' (odnotowanie przelewu zrobionego poza CRM).
     * @param array<string,mixed> $opts quantities, delivery, comment, via_gateway, amount (manual), client (AllegroClient / WoocommerceClient)
     * @return array{amount:float,remote_id:string,message:string}
     */
    public function refund(int $id, string $method, array $opts): array
    {
        $ret = $this->find($id);
        if ($ret === null) {
            throw new \RuntimeException('Nie znaleziono zwrotu.');
        }
        if ($ret['refund_state'] !== null) {
            throw new \RuntimeException('Zwrot pieniędzy do tego zwrotu już wykonano albo wymaga sprawdzenia.');
        }
        $quantities = array_map('intval', (array) ($opts['quantities'] ?? []));
        $comment = (string) ($opts['comment'] ?? '');

        if ($method === 'manual') {
            $amount = round((float) str_replace(',', '.', (string) ($opts['amount'] ?? '0')), 2);
            if ($amount <= 0) {
                throw new \RuntimeException('Podaj zwróconą kwotę.');
            }
            $this->reserve($id, 'refund');
            $this->finish($id, 'refund', '', 'Odnotowano zwrot wykonany poza CRM.' . ($comment !== '' ? ' ' . $comment : ''),
                ['refund_amount' => $amount, 'refund_method' => 'manual']);
            return ['amount' => $amount, 'remote_id' => '', 'message' => 'Odnotowano zwrot pieniędzy.'];
        }

        $order = $ret['woo_order_id'] !== null ? (new WooOrderRepository($this->pdo))->find((int) $ret['woo_order_id']) : null;
        if ($order === null) {
            throw new \RuntimeException('Zwrot nie jest powiązany z zamówieniem w CRM.');
        }
        $client = $opts['client'] ?? null;
        if (!is_object($client)) {
            throw new \RuntimeException('Integracja do zwrotu pieniędzy jest niedostępna.');
        }
        $isAllegro = (int) $order['woo_order_id'] >= 9000000000;
        if ($method === 'allegro') {
            if (!$isAllegro) {
                throw new \RuntimeException('To nie jest zamówienie z Allegro.');
            }
            $payload = json_decode((string) ($order['payload'] ?? ''), true) ?: [];
            $built = self::allegroRefundBody($payload, $ret['items'], $quantities, (float) str_replace(',', '.', (string) ($opts['delivery'] ?? '0')), $comment);
            $call = static fn() => $client->refundPayment($built['body']);
            $what = 'Allegro';
        } elseif ($method === 'woo') {
            if ($isAllegro) {
                throw new \RuntimeException('Zamówienie z Allegro zwracaj przez Allegro.');
            }
            $built = self::wooRefundBody($ret['items'], $quantities, (float) str_replace(',', '.', (string) ($opts['delivery'] ?? '0')), $comment, !empty($opts['via_gateway']));
            $wooId = (int) $order['woo_order_id'];
            $call = static fn() => $client->createRefund($wooId, $built['body']);
            $what = 'sklep';
        } elseif ($method === 'payu') {
            if ($isAllegro) {
                throw new \RuntimeException('Zamówienie z Allegro zwracaj przez Allegro.');
            }
            $built = self::wooRefundBody($ret['items'], $quantities, (float) str_replace(',', '.', (string) ($opts['delivery'] ?? '0')), $comment, false);
            $wooId = (int) $order['woo_order_id'];
            $user = (string) ($opts['user'] ?? '');
            $call = static fn() => $client->refundForReturn($wooId, $built['amount'], $comment, $id, $user);
            $what = 'PayU';
        } else {
            throw new \RuntimeException('Nieznany sposób zwrotu pieniędzy.');
        }

        $this->reserve($id, 'refund');
        try {
            $r = $call();
        } catch (\Throwable $e) {
            $this->uncertain($id, 'refund', $e->getMessage());
            throw new \RuntimeException("Nie wiadomo, czy $what wykonał zwrot pieniędzy (" . $e->getMessage() . '). Sprawdź; ponowienie zablokowano.', 0, $e);
        }
        if ($r['ok']) {
            $this->finish($id, 'refund', (string) $r['id'], 'Zwrot pieniędzy zlecony (' . $what . ').', ['refund_amount' => $built['amount'], 'refund_method' => $method]);
            return ['amount' => $built['amount'], 'remote_id' => (string) $r['id'], 'message' => 'Zlecono zwrot ' . number_format($built['amount'], 2, ',', ' ') . '.'];
        }
        if (self::definitelyRejected((int) $r['status'], false)) {
            $this->release($id, 'refund', $r['message']);
            throw new \RuntimeException("$what odrzucił zwrot pieniędzy: " . $r['message']);
        }
        $this->uncertain($id, 'refund', $r['message']);
        throw new \RuntimeException("Nie wiadomo, czy $what wykonał zwrot pieniędzy (" . $r['message'] . '). Sprawdź; ponowienie zablokowano.');
    }

    /** Administrator wpisuje ID korekty / zwrotu, który jednak powstał (po sprawdzeniu w wFirma / Allegro / sklepie). */
    public function confirmDone(int $id, string $step, string $remoteId): void
    {
        $remoteId = trim($remoteId);
        if ($step === 'correction' && !preg_match('/^[0-9]{1,30}$/D', $remoteId)) {
            throw new \RuntimeException('Podaj numeryczne ID korekty z wFirma.');
        }
        $ret = $this->find($id);
        if ($ret === null || $ret[$step === 'correction' ? 'correction_state' : 'refund_state'] !== self::STEP_UNCERTAIN) {
            throw new \RuntimeException('Potwierdzić można tylko krok z nieznanym wynikiem.');
        }
        $this->finish($id, $step, $remoteId, 'Administrator potwierdził wykonanie.');
    }

    // ------------------------------------------------------------
    //  Krok 3: zwrot pieniędzy
    // ------------------------------------------------------------

    /**
     * Treść POST /payments/refunds dla zamówienia Allegro.
     * @param array<string,mixed> $orderPayload checkout-form
     * @param array<int,int> $quantities indeks pozycji zwrotu => ilość do zwrotu pieniędzy
     * @return array{body:array<string,mixed>,amount:float}
     */
    public static function allegroRefundBody(array $orderPayload, array $returnItems, array $quantities, float $delivery, string $comment): array
    {
        $paymentId = (string) ($orderPayload['payment']['id'] ?? '');
        if ($paymentId === '') {
            throw new \RuntimeException('Zamówienie nie ma ID płatności Allegro — zwrot pieniędzy zrób w panelu Allegro.');
        }
        $currency = (string) ($orderPayload['summary']['totalToPay']['currency'] ?? $orderPayload['payment']['paidAmount']['currency'] ?? 'PLN');
        $lines = [];
        $amount = 0.0;
        foreach ($returnItems as $i => $it) {
            $qty = max(0, min((int) $it['quantity'], (int) ($quantities[$i] ?? 0)));
            if ($qty === 0) {
                continue;
            }
            if (empty($it['line_item_id'])) {
                throw new \RuntimeException('Pozycja „' . $it['name'] . '” nie ma powiązania z pozycją zamówienia Allegro — zwrot pieniędzy zrób w panelu Allegro.');
            }
            $lines[] = ['id' => (string) $it['line_item_id'], 'type' => 'QUANTITY', 'quantity' => $qty];
            $amount += $qty * (float) $it['price'];
        }
        if ($lines === [] && $delivery <= 0) {
            throw new \RuntimeException('Wybierz co najmniej jedną pozycję albo koszt dostawy do zwrotu.');
        }
        $maxDelivery = (float) ($orderPayload['delivery']['cost']['amount'] ?? 0);
        if ($delivery > $maxDelivery + 0.001) {
            throw new \RuntimeException('Zwrot kosztu dostawy nie może przekroczyć kosztu dostawy z zamówienia (' . number_format($maxDelivery, 2, ',', ' ') . ').');
        }
        $body = ['payment' => ['id' => $paymentId], 'reason' => 'REFUND', 'lineItems' => $lines];
        if ($delivery > 0) {
            $body['delivery'] = ['value' => ['amount' => number_format($delivery, 2, '.', ''), 'currency' => $currency]];
            $amount += $delivery;
        }
        if (trim($comment) !== '') {
            $body['sellerComment'] = mb_substr(trim($comment), 0, 100);
        }
        return ['body' => $body, 'amount' => round($amount, 2)];
    }

    /**
     * Treść POST /wp-json/wc/v3/orders/{id}/refunds dla zamówienia ze sklepu.
     * api_refund = true zwraca pieniądze przez bramkę płatności sklepu (jeśli ją obsługuje); false tylko zapisuje zwrot w sklepie.
     * restock_items = false — stan przyjmuje CRM (krok 1), inaczej sklep doliczyłby go drugi raz.
     * @return array{body:array<string,mixed>,amount:float}
     */
    public static function wooRefundBody(array $returnItems, array $quantities, float $extra, string $reason, bool $viaGateway): array
    {
        $amount = 0.0;
        foreach ($returnItems as $i => $it) {
            $qty = max(0, min((int) $it['quantity'], (int) ($quantities[$i] ?? 0)));
            $amount += $qty * (float) $it['price'];
        }
        $amount = round($amount + max(0.0, $extra), 2);
        if ($amount <= 0) {
            throw new \RuntimeException('Kwota zwrotu musi być większa od zera.');
        }
        // Bez line_items: Woo liczy tam kwoty netto i podatek osobno, a CRM zna ceny brutto.
        // Kwota i powód wystarczą, żeby sklep pokazał zamówienie jako (częściowo) zwrócone.
        $body = [
            'amount'        => number_format($amount, 2, '.', ''),
            'reason'        => mb_substr(trim($reason) !== '' ? trim($reason) : 'Zwrot towaru', 0, 200),
            'api_refund'    => $viaGateway,
            'restock_items' => false,
        ];
        return ['body' => $body, 'amount' => $amount];
    }
}
