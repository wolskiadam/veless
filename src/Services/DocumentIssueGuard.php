<?php
declare(strict_types=1);
namespace Pase\Services;

use PDO;

/** Durable reservation: network errors and process death never automatically permit another issue. */
final class DocumentIssueGuard
{
    public function __construct(private readonly PDO $pdo) {}

    public static function migrate(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS document_issue_operations (
            order_id BIGINT NOT NULL, document_type VARCHAR(32) NOT NULL,
            integration_id BIGINT NOT NULL, attempt_token VARCHAR(64) NOT NULL,
            state VARCHAR(20) NOT NULL, remote_id VARCHAR(128) NULL,
            created_at VARCHAR(30) NOT NULL, updated_at VARCHAR(30) NOT NULL,
            PRIMARY KEY (order_id, document_type)
        )" . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''));
    }

    public function reserve(int $id, string $type, int $integration): string
    {
        // Must commit before sending the request. Caller-owned transactions would break that guarantee.
        if ($this->pdo->inTransaction()) { throw new \RuntimeException('Wystawianie dokumentu nie może działać wewnątrz otwartej transakcji.'); }
        return (new AuditTrail($this->pdo))->atomic(function () use ($id, $type, $integration): string {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->pdo->prepare('SELECT woo_order_id FROM woo_orders WHERE woo_order_id = ?' . $lock);
            $stmt->execute([$id]);
            if (!$stmt->fetchColumn()) { throw new \RuntimeException('Nie znaleziono zamówienia.'); }
            $stmt = $this->pdo->prepare("SELECT id FROM order_documents WHERE woo_order_id = ? AND provider = 'wfirma' AND document_type = ?");
            $stmt->execute([$id, $type]);
            if ($stmt->fetchColumn()) { throw new \RuntimeException('Ten typ dokumentu został już wystawiony.'); }
            $stmt = $this->pdo->prepare('SELECT state FROM document_issue_operations WHERE order_id = ? AND document_type = ?');
            $stmt->execute([$id, $type]);
            if ($stmt->fetchColumn() !== false) {
                throw new \RuntimeException('Wystawianie tego dokumentu już rozpoczęto. Wynik może wymagać sprawdzenia w wFirma. Ponowienie zablokowano, aby uniknąć duplikatu.');
            }
            $token = bin2hex(random_bytes(24));
            $this->pdo->prepare('INSERT INTO document_issue_operations
                (order_id, document_type, integration_id, attempt_token, state, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $type, $integration, $token, 'pending', gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]);
            (new AuditTrail($this->pdo))->record($id, 'document.issue_started', [], ['document_type' => $type, 'integration_id' => $integration]);
            return $token;
        });
    }

    public function finish(int $id, string $type, string $token, string $remoteId): void
    {
        $stmt = $this->pdo->prepare("UPDATE document_issue_operations SET state = 'issued', remote_id = ?, updated_at = ?
            WHERE order_id = ? AND document_type = ? AND attempt_token = ? AND state = 'pending'");
        $stmt->execute([$remoteId, gmdate('Y-m-d H:i:s'), $id, $type, $token]);
        if ($stmt->rowCount() !== 1) { throw new \RuntimeException('Nie można zatwierdzić rezerwacji dokumentu.'); }
    }

    public function uncertain(int $id, string $type, string $token, ?string $remoteId): void
    {
        $this->pdo->prepare("UPDATE document_issue_operations SET state = 'uncertain', remote_id = ?, updated_at = ?
            WHERE order_id = ? AND document_type = ? AND attempt_token = ? AND state = 'pending'")
            ->execute([$remoteId, gmdate('Y-m-d H:i:s'), $id, $type, $token]);
    }

    /** Only an explicitly removed, completed document allows a new issuance. */
    public function releaseIssued(int $id, string $type, string $remoteId): void
    {
        $this->pdo->prepare("DELETE FROM document_issue_operations WHERE order_id = ? AND document_type = ? AND remote_id = ? AND state = 'issued'")
            ->execute([$id, $type, $remoteId]);
    }

    /** Operator reconciliation, never automatic retry. Token prevents a stale form changing a newer operation. */
    public function reconcile(int $id, string $type, string $token, ?string $remoteId): void
    {
        (new AuditTrail($this->pdo))->atomic(function () use ($id, $type, $token, $remoteId): void {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->pdo->prepare('SELECT * FROM document_issue_operations WHERE order_id = ? AND document_type = ?' . $lock);
            $stmt->execute([$id, $type]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || !hash_equals($row['attempt_token'], $token) || $row['state'] === 'issued') {
                throw new \RuntimeException('Operacja została już rozstrzygnięta lub formularz jest nieaktualny.');
            }
            if ($remoteId === null) {
                // Pending could still be executing, including after a crash. Never unlock it by time alone.
                if ($row['state'] !== 'uncertain' || !empty($row['remote_id'])) {
                    throw new \RuntimeException('Nie można odblokować operacji trwającej lub mającej ID dokumentu. Powiąż istniejący dokument.');
                }
                $this->pdo->prepare('DELETE FROM document_issue_operations WHERE order_id = ? AND document_type = ?')->execute([$id, $type]);
                (new AuditTrail($this->pdo))->record($id, 'document.absence_confirmed', ['document_type' => $type, 'integration_id' => (int) $row['integration_id']], []);
            } else {
                if (!preg_match('/^[0-9]{1,30}$/D', $remoteId)) { throw new \RuntimeException('Podaj numeryczne ID dokumentu z wFirma.'); }
                if (!empty($row['remote_id']) && $row['remote_id'] !== $remoteId) { throw new \RuntimeException('ID nie zgadza się z odpowiedzią wFirma.'); }
                (new \Pase\Repository\OrderDocumentRepository($this->pdo))->create($id, (int) $row['integration_id'], 'wfirma', $type, $remoteId);
                $this->pdo->prepare("UPDATE document_issue_operations SET state = 'issued', remote_id = ?, updated_at = ? WHERE order_id = ? AND document_type = ?")
                    ->execute([$remoteId, gmdate('Y-m-d H:i:s'), $id, $type]);
            }
        });
    }
}
