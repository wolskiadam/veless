<?php
declare(strict_types=1);

namespace Pase\Repository;

use PDO;

/** Dokumenty sprzedaży wystawione do zamówień przez zewnętrzne integracje. */
final class OrderDocumentRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int,array<string,mixed>> */
    public function forOrder(int $wooOrderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM order_documents WHERE woo_order_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$wooOrderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findForOrderAndType(int $wooOrderId, string $provider, string $type): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM order_documents
             WHERE woo_order_id = ? AND provider = ? AND document_type = ? LIMIT 1'
        );
        $stmt->execute([$wooOrderId, $provider, $type]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Usuwa wyłącznie lokalne powiązanie paragonu z podanym zamówieniem. */
    public function unlinkReceipt(int $id, int $wooOrderId, bool $remoteDeleted = false): bool
    {
        $audit = new \Pase\Services\AuditTrail($this->pdo);
        return $audit->atomic(function () use ($audit, $id, $wooOrderId, $remoteDeleted): bool {
        $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $read = $this->pdo->prepare("SELECT provider, document_type, remote_id, integration_id FROM order_documents
            WHERE id = ? AND woo_order_id = ? AND provider = 'wfirma'
            AND document_type IN ('receipt', 'receipt_fiscal')" . $lock);
        $read->execute([$id, $wooOrderId]);
        $before = $read->fetch(PDO::FETCH_ASSOC);
        if (!$before) { return false; }
        $stmt = $this->pdo->prepare(
            "DELETE FROM order_documents WHERE id = ? AND woo_order_id = ?
             AND provider = 'wfirma' AND document_type IN ('receipt', 'receipt_fiscal')"
        );
        $stmt->execute([$id, $wooOrderId]);
        (new \Pase\Services\DocumentIssueGuard($this->pdo))->releaseIssued($wooOrderId, (string) $before['document_type'], (string) $before['remote_id']);
        $audit->record($wooOrderId, $remoteDeleted ? 'document.remote_deleted' : 'document.unlinked', $before, []);
        return true;
        });
    }

    public function create(
        int $wooOrderId,
        int $integrationId,
        string $provider,
        string $type,
        string $remoteId,
        string $message = ''
    ): int {
        $audit = new \Pase\Services\AuditTrail($this->pdo);
        return $audit->atomic(function () use ($audit, $wooOrderId, $integrationId, $provider, $type, $remoteId, $message): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO order_documents
                (woo_order_id, integration_id, provider, document_type, remote_id, status, message)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$wooOrderId, $integrationId, $provider, $type, $remoteId, 'issued', $message]);
        $id = (int) $this->pdo->lastInsertId();
        $audit->record($wooOrderId, 'document.linked', [], [
            'integration_id' => $integrationId, 'provider' => $provider,
            'document_type' => $type, 'remote_id' => $remoteId,
        ]);
        return $id;
        });
    }
}
