<?php
declare(strict_types=1);
namespace Pase\Services;

use PDO;

/** Append-only application history. Never pass request bodies, tokens or API responses here. */
final class AuditTrail
{
    public function __construct(private readonly PDO $pdo) {}

    public static function migrate(PDO $pdo): void
    {
        $id = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_events (
            id $id, order_id BIGINT NOT NULL, actor_id BIGINT NULL,
            actor_name VARCHAR(190) NOT NULL, action VARCHAR(80) NOT NULL,
            before_json TEXT NOT NULL, after_json TEXT NOT NULL,
            created_at VARCHAR(30) NOT NULL
        )" . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''));
        // Index creation is part of the initial table on MySQL via a separate idempotent check.
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $index = $pdo->query("SHOW INDEX FROM audit_events WHERE Key_name = 'audit_order_id'")->fetch();
            if (!$index) { $pdo->exec('CREATE INDEX audit_order_id ON audit_events (order_id, id)'); }
        } else {
            $pdo->exec('CREATE INDEX IF NOT EXISTS audit_order_id ON audit_events (order_id, id)');
        }
    }

    public function atomic(callable $work): mixed
    {
        $outer = $this->pdo->inTransaction();
        $savepoint = 'audit_' . bin2hex(random_bytes(8));
        if ($outer) { $this->pdo->exec("SAVEPOINT $savepoint"); }
        else { $this->pdo->beginTransaction(); }
        try {
            $result = $work();
            if ($outer) { $this->pdo->exec("RELEASE SAVEPOINT $savepoint"); }
            else { $this->pdo->commit(); }
            return $result;
        } catch (\Throwable $e) {
            if ($outer) {
                $this->pdo->exec("ROLLBACK TO SAVEPOINT $savepoint");
                $this->pdo->exec("RELEASE SAVEPOINT $savepoint");
            } elseif ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
    }

    public function record(int $orderId, string $action, array $before, array $after): void
    {
        // Allowlist prevents accidental retention of passwords, customer contacts and payloads.
        $allowed = array_flip(['pase_status', 'lifecycle', 'local_items', 'contact_fields', 'invoice_fields',
            'document_type', 'provider', 'remote_id', 'integration_id', 'merged_into', 'absorbed', 'paid_amount', 'no_shipping']);
        $clean = static function (array $values) use ($allowed): array {
            $values = array_intersect_key($values, $allowed);
            if (isset($values['local_items']) && is_array($values['local_items'])) {
                $values['local_items'] = array_map(static fn(array $item): array =>
                    array_intersect_key($item, array_flip(['product_id', 'sku', 'quantity', 'price', 'total'])), $values['local_items']);
            }
            return $values;
        };
        $actorId = $_SESSION['pase_user_id'] ?? null;
        $actorName = $actorId ? (string) ($_SESSION['pase_username'] ?? 'Użytkownik') : 'System / automatyzacja';
        $stmt = $this->pdo->prepare('INSERT INTO audit_events
            (order_id, actor_id, actor_name, action, before_json, after_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$orderId, $actorId, mb_substr($actorName, 0, 190), $action,
            json_encode($clean($before), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            json_encode($clean($after), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), gmdate('Y-m-d H:i:s')]);
    }

    /** Local mutation and its history succeed or fail together; locks prevent incorrect before-values. */
    public function changeOrder(int $id, string $action, array $changes): bool
    {
        $columns = ['pase_status', 'lifecycle', 'local_items', 'local_edited_at', 'local_billing_email', 'local_billing_phone', 'local_invoice', 'no_shipping'];
        if (!$changes || array_diff(array_keys($changes), $columns)) {
            throw new \InvalidArgumentException('Unsupported audited order field');
        }
        return $this->atomic(function () use ($id, $action, $changes): bool {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->pdo->prepare('SELECT * FROM woo_orders WHERE woo_order_id = ?' . $lock);
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new \RuntimeException('Nie znaleziono zamówienia.'); }
            $before = $after = [];
            $contactFields = [];
            foreach ($changes as $key => $value) {
                if (($row[$key] ?? null) === $value || $key === 'local_edited_at') { continue; }
                if ($key === 'local_invoice') {
                    // Dane do faktury: tylko nazwy zmienionych pól (bez danych klienta).
                    $after['invoice_fields'] = InvoiceData::changedFields($row[$key] ?? null, $value) ?: ['saved'];
                    if ($value === null) { $after['invoice_fields'] = ['restored']; }
                    continue;
                }
                if (str_starts_with($key, 'local_billing_')) {
                    $contactFields[] = $key === 'local_billing_email' ? 'email' : 'phone';
                    continue;
                }
                $before[$key] = $key === 'local_items' ? json_decode($row[$key] ?? 'null', true) : ($row[$key] ?? null);
                $after[$key] = $key === 'local_items' ? json_decode($value ?? 'null', true) : $value;
            }
            if ($contactFields) { $after['contact_fields'] = $contactFields; }
            if (!$before && !$after) { return false; }
            $sql = implode(', ', array_map(static fn(string $key): string => "$key = ?", array_keys($changes)));
            $this->pdo->prepare("UPDATE woo_orders SET $sql WHERE woo_order_id = ?")
                ->execute([...array_values($changes), $id]);
            $this->record($id, $action, $before, $after);
            return true;
        });
    }
}
