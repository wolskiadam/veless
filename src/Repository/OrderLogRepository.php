<?php
declare(strict_types=1);

namespace Pase\Repository;

use PDO;

/**
 * Dostęp do tabeli `order_logs` - dziennik zamówień i klucz idempotentności.
 *
 * Idempotentność (wymaganie z briefu): zanim utworzymy zamówienie w Woo lub
 * fakturę w wFirma, sprawdzamy tu, czy źródłowe zamówienie nie jest już
 * przetworzone. UNIQUE(source_platform, source_order_id) blokuje wyścig.
 */
final class OrderLogRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findBySource(string $platform, string $sourceOrderId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM order_logs WHERE source_platform = ? AND source_order_id = ? LIMIT 1'
        );
        $stmt->execute([$platform, $sourceOrderId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Tworzy log w stanie 'processing' ALBO zwraca istniejący.
     * Dzięki INSERT IGNORE + UNIQUE dwa równoległe workery nie zdublują zamówienia.
     *
     * @return array{0:int,1:bool}  [id logu, czy nowy]
     */
    public function claim(string $platform, string $sourceOrderId): array
    {
        $insert = $this->pdo->prepare(
            "INSERT IGNORE INTO order_logs (internal_status, source_platform, source_order_id)
             VALUES ('processing', :platform, :sid)"
        );
        $insert->execute([':platform' => $platform, ':sid' => $sourceOrderId]);

        if ($insert->rowCount() > 0) {
            return [(int) $this->pdo->lastInsertId(), true];
        }

        // Już istniał - zwracamy istniejący wiersz.
        $existing = $this->findBySource($platform, $sourceOrderId);
        return [(int) $existing['id'], false];
    }

    public function markWooCreated(int $id, int $wooOrderId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE order_logs SET internal_status = 'woo_created', woo_order_id = :woo WHERE id = :id"
        );
        $stmt->execute([':woo' => $wooOrderId, ':id' => $id]);
    }

    /** Zapisuje ID zamówienia w CRM bez zmiany internal_status. */
    public function setWooOrderId(int $id, int $wooOrderId): void
    {
        $stmt = $this->pdo->prepare('UPDATE order_logs SET woo_order_id = :woo WHERE id = :id');
        $stmt->execute([':woo' => $wooOrderId, ':id' => $id]);
    }

    public function markInvoiced(int $id, string $invoiceId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE order_logs SET internal_status = 'invoiced', wfirma_invoice_id = :inv WHERE id = :id"
        );
        $stmt->execute([':inv' => $invoiceId, ':id' => $id]);
    }

    public function markCompleted(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE order_logs SET internal_status = 'completed', last_error = NULL WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);
    }

    public function markFailed(int $id, string $error): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE order_logs SET internal_status = 'failed', last_error = :err WHERE id = :id"
        );
        $stmt->execute([':err' => $error, ':id' => $id]);
    }
}
