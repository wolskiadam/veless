<?php
declare(strict_types=1);

namespace Pase\Repository;

use PDO;

/**
 * Dostęp do tabeli `woo_orders` - zamówienia pobrane z WooCommerce.
 * Upsert po woo_order_id zapewnia idempotencję: powtórny webhook (created
 * potem updated dla tego samego zamówienia) aktualizuje istniejący wiersz.
 */
final class WooOrderRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Zapisuje lub aktualizuje zamówienie. Zwraca true, jeśli to nowy wiersz.
     *
     * @param array $fields  wyciągnięte pola (status, total, customer_*, ...)
     * @param array $payload pełne zamówienie z Woo (zapisywane jako JSON)
     */
    public function upsert(int $wooOrderId, array $fields, array $payload): bool
    {
        $sql = 'INSERT INTO woo_orders
                    (woo_order_id, integration_id, order_number, status, pase_status, currency, total,
                     customer_name, customer_email, date_created, client_token, payload)
                VALUES
                    (:id, :intid, :num, :status, :pase, :cur, :total, :cname, :cemail, :dcreated, :token, :payload)
                ON DUPLICATE KEY UPDATE
                    integration_id = COALESCE(integration_id, VALUES(integration_id)),
                    order_number   = VALUES(order_number),
                    status         = VALUES(status),
                    -- pase_status to status systemu (źródło prawdy): ustawiamy go
                    -- tylko gdy jeszcze pusty (pierwszy import); później NIE
                    -- nadpisujemy statusem Woo, bo CRM rządzi statusem.
                    pase_status    = COALESCE(pase_status, VALUES(pase_status)),
                    currency       = VALUES(currency),
                    total          = VALUES(total),
                    customer_name  = VALUES(customer_name),
                    customer_email = VALUES(customer_email),
                    date_created   = VALUES(date_created),
                    -- token klienta nadajemy tylko raz (pierwszy import), nie nadpisujemy.
                    client_token   = COALESCE(client_token, VALUES(client_token)),
                    payload        = VALUES(payload)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id'       => $wooOrderId,
            ':intid'    => $fields['integration_id'] ?? null,
            ':num'      => $fields['order_number'] ?? null,
            ':status'   => $fields['status'] ?? null,
            ':pase'     => $fields['pase_status'] ?? null,
            ':cur'      => $fields['currency'] ?? null,
            ':total'    => $fields['total'] ?? null,
            ':cname'    => $fields['customer_name'] ?? null,
            ':cemail'   => $fields['customer_email'] ?? null,
            ':dcreated' => $fields['date_created'] ?? null,
            ':token'    => bin2hex(random_bytes(24)),
            ':payload'  => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        // rowCount: 1 = insert, 2 = update (MySQL), 0 = brak zmian.
        return $stmt->rowCount() === 1;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function find(int $wooOrderId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM woo_orders WHERE woo_order_id = ? LIMIT 1');
        $stmt->execute([$wooOrderId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Najnowsza data zamówienia dla integracji (do cyklicznego pobierania „tylko nowsze").
     * @return ?string 'Y-m-d H:i:s' albo null gdy brak zamówień
     */
    public function latestOrderDate(int $integrationId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT MAX(date_created) FROM woo_orders WHERE integration_id = ?'
        );
        $stmt->execute([$integrationId]);
        $v = $stmt->fetchColumn();
        return $v ?: null;
    }

    /** Zamówienie po tokenie klienta (publiczna strona zamówienia). Token musi być długi. */
    public function findByClientToken(string $token): ?array
    {
        if (strlen($token) < 32) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM woo_orders WHERE client_token = ? LIMIT 1');
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    /** Czy reguły automatyzacji zostały już przepuszczone dla tego zamówienia. */
    public function automationsDone(int $wooOrderId): bool
    {
        $stmt = $this->pdo->prepare('SELECT automations_done FROM woo_orders WHERE woo_order_id = ?');
        $stmt->execute([$wooOrderId]);
        return (int) $stmt->fetchColumn() === 1;
    }

    public function markAutomationsDone(int $wooOrderId): void
    {
        $this->pdo->prepare('UPDATE woo_orders SET automations_done = 1 WHERE woo_order_id = ?')
            ->execute([$wooOrderId]);
    }

    /**
     * Nadaje kolejny własny numer PASE, jeśli zamówienie jeszcze go nie ma.
     * MAX(pase_number)+1 w jednym UPDATE - atomowo względem tego wiersza.
     * UNIQUE na kolumnie chroni przed kolizją przy równoległych workerach
     * (w razie kolizji ponawiamy raz).
     */
    public function assignNumberIfMissing(int $wooOrderId): void
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->pdo->prepare(
                    'UPDATE woo_orders
                     SET pase_number = (SELECT next_no FROM (SELECT COALESCE(MAX(pase_number),0)+1 AS next_no FROM woo_orders) t)
                     WHERE woo_order_id = ? AND pase_number IS NULL'
                )->execute([$wooOrderId]);
                return;
            } catch (\PDOException $e) {
                // Kolizja UNIQUE przy wyścigu - spróbuj ponownie raz.
                if ($attempt === 0 && str_contains($e->getMessage(), 'Duplicate')) {
                    continue;
                }
                throw $e;
            }
        }
    }

    // ===== Lokalna (PASE-only) edycja pozycji zamówienia - NIE wysyłana do sklepu =====

    /**
     * Zapisuje efektywną listę pozycji zamówienia po edycji w PASE (ilości,
     * dodane/usunięte produkty). Nadpisuje wyświetlanie w order_view.php,
     * ale NIE dotyka oryginalnego payloadu ani nie synchronizuje się ze sklepem.
     *
     * @param array<int,array{product_id:?int,name:string,sku:?string,price:float,quantity:int,total:float}> $items
     */
    public function updateLocalItems(int $wooOrderId, array $items): void
    {
        (new \Pase\Services\AuditTrail($this->pdo))->changeOrder($wooOrderId, 'order.items_changed', [
            'local_items' => json_encode(array_values($items), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'local_edited_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** Kasuje lokalną edycję - z powrotem pokazujemy oryginalne pozycje ze sklepu. */
    public function clearLocalItems(int $wooOrderId): void
    {
        (new \Pase\Services\AuditTrail($this->pdo))->changeOrder($wooOrderId, 'order.items_restored', [
            'local_items' => null, 'local_edited_at' => null,
        ]);
    }

    /**
     * Lokalna korekta e-maila/telefonu klienta (np. literówka w zamówieniu).
     * Pusta wartość = NULL = brak nadpisania, pokazujemy oryginał ze sklepu.
     * NIE wpływa na weryfikację klienta na jego stronie zamówienia (ClientVerify
     * sprawdza zawsze oryginalny payload).
     */
    public function updateLocalContact(int $wooOrderId, string $email, string $phone): void
    {
        (new \Pase\Services\AuditTrail($this->pdo))->changeOrder($wooOrderId, 'order.contact_changed', [
            'local_billing_email' => $email !== '' ? $email : null,
            'local_billing_phone' => $phone !== '' ? $phone : null,
        ]);
    }

    /**
     * Poprawione w CRM dane do faktury (JSON z Services\InvoiceData::fromForm) albo null = wróć do danych ze sklepu.
     * Tylko w CRM - nie wysyłane do sklepu. Historia zmian zapisuje nazwy pól, bez wartości.
     */
    public function updateLocalInvoice(int $wooOrderId, ?string $json): bool
    {
        return (new \Pase\Services\AuditTrail($this->pdo))->changeOrder($wooOrderId, 'order.invoice_data_changed', [
            'local_invoice' => $json,
        ]);
    }

    // ===== Cykl życia: active | archived | trashed =====

    private const LIFECYCLES = ['active', 'archived', 'trashed'];

    /** Ustawia cykl życia jednego zamówienia (po woo_order_id). */
    public function setLifecycle(int $wooOrderId, string $lifecycle): void
    {
        if (!in_array($lifecycle, self::LIFECYCLES, true)) {
            return;
        }
        (new \Pase\Services\AuditTrail($this->pdo))->changeOrder($wooOrderId, 'order.lifecycle_changed', ['lifecycle' => $lifecycle]);
    }

    /**
     * Masowa zmiana cyklu życia. @param int[] $wooOrderIds @return int liczba zmienionych
     */
    public function setLifecycleMany(array $wooOrderIds, string $lifecycle): int
    {
        if (!in_array($lifecycle, self::LIFECYCLES, true)) {
            return 0;
        }
        $ids = array_values(array_filter(array_map('intval', $wooOrderIds), static fn($i) => $i > 0));
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $audit = new \Pase\Services\AuditTrail($this->pdo);
        return $audit->atomic(function () use ($audit, $ids, $lifecycle, $in): int {
            $stmt = $this->pdo->prepare("SELECT woo_order_id FROM woo_orders WHERE woo_order_id IN ($in) ORDER BY woo_order_id");
            $stmt->execute($ids);
            $count = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $count += (int) $audit->changeOrder((int) $id, 'order.lifecycle_changed', ['lifecycle' => $lifecycle]);
            }
            return $count;
        });
    }

    /** Trwałe usunięcie zamówień (tylko z Kosza). @param int[] $wooOrderIds @return int */
    public function purgeMany(array $wooOrderIds): int
    {
        $ids = array_values(array_filter(array_map('intval', $wooOrderIds), static fn($i) => $i > 0));
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $audit = new \Pase\Services\AuditTrail($this->pdo);
        return $audit->atomic(function () use ($audit, $ids, $in): int {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $read = $this->pdo->prepare("SELECT woo_order_id FROM woo_orders WHERE woo_order_id IN ($in) AND lifecycle = 'trashed' ORDER BY woo_order_id" . $lock);
            $read->execute($ids);
            $targets = $read->fetchAll(PDO::FETCH_COLUMN);
            $delete = $this->pdo->prepare("DELETE FROM woo_orders WHERE woo_order_id = ? AND lifecycle = 'trashed'");
            $hasMerge = \Pase\Services\LowStock::columnExists($this->pdo, 'woo_orders', 'merged_into');
            foreach ($targets as $id) {
                if ($hasMerge) {
                    // Zamówienia dołączone do usuwanego (scalanie) wracają jako osobne.
                    $this->pdo->prepare('UPDATE woo_orders SET merged_into = NULL, merged_at = NULL WHERE merged_into = ?')->execute([$id]);
                }
                // Zamówienie z kosza nie liczy się już w historii klienta (Klienci).
                \Pase\Services\Customers::markPurged($this->pdo, (int) $id);
                $delete->execute([$id]);
                $audit->record((int) $id, 'order.deleted', ['lifecycle' => 'trashed'], []);
            }
            return count($targets);
        });
    }

    /** Opróżnia kosz w całości. @return int liczba usuniętych */
    public function emptyTrash(): int
    {
        $ids = $this->pdo->query("SELECT woo_order_id FROM woo_orders WHERE lifecycle = 'trashed'")->fetchAll(PDO::FETCH_COLUMN);
        return $this->purgeMany($ids);
    }

    /**
     * Auto-archiwizacja: aktywne zamówienia starsze niż $days dni (wg date_created)
     * przenosi do 'archived'. @return int liczba zarchiwizowanych
     */
    public function autoArchiveOlderThan(int $days = 90): int
    {
        $audit = new \Pase\Services\AuditTrail($this->pdo);
        return $audit->atomic(function () use ($days): int {
        $stmt = $this->pdo->prepare(
            "SELECT woo_order_id FROM woo_orders
             WHERE lifecycle = 'active'
               AND date_created IS NOT NULL
               AND date_created < (NOW() - INTERVAL ? DAY)
             ORDER BY woo_order_id FOR UPDATE"
        );
        $stmt->execute([$days]);
        return $this->setLifecycleMany($stmt->fetchAll(PDO::FETCH_COLUMN), 'archived');
        });
    }
}
