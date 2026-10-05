<?php
declare(strict_types=1);

namespace Pase\Repository;

use PDO;

/**
 * Wątek wiadomości per zamówienie (tabela order_messages).
 * sender: 'client' (publiczna strona) | 'staff' (panel). Załącznik opcjonalny.
 */
final class OrderMessageRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int,array<string,mixed>> wiadomości rosnąco wg czasu */
    public function thread(int $wooOrderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM order_messages WHERE woo_order_id = ? ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute([$wooOrderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM order_messages WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Dodaje wiadomość do wątku. Zwraca id.
     * @param 'client'|'staff' $sender
     */
    public function add(
        int $wooOrderId,
        string $sender,
        string $body,
        ?string $authorName = null,
        ?string $attachmentPath = null,
        ?string $attachmentName = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO order_messages
                (woo_order_id, sender, author_name, body, attachment_path, attachment_name, is_read)
             VALUES (:o, :s, :a, :b, :ap, :an, :r)'
        );
        $stmt->execute([
            ':o'  => $wooOrderId,
            ':s'  => $sender === 'client' ? 'client' : 'staff',
            ':a'  => $authorName,
            ':b'  => $body,
            ':ap' => $attachmentPath,
            ':an' => $attachmentName,
            // Wiadomość od staff jest od razu "przeczytana" (to my piszemy); od klienta - nie.
            ':r'  => $sender === 'staff' ? 1 : 0,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Oznacza wiadomości klienta jako przeczytane (po otwarciu zamówienia w panelu). */
    public function markClientRead(int $wooOrderId): void
    {
        $this->pdo->prepare(
            "UPDATE order_messages SET is_read = 1 WHERE woo_order_id = ? AND sender = 'client' AND is_read = 0"
        )->execute([$wooOrderId]);
    }

    /** Liczba nieprzeczytanych wiadomości od klientów (do badge'a w panelu). */
    public function unreadCount(): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM order_messages WHERE sender = 'client' AND is_read = 0"
        )->fetchColumn();
    }

    /**
     * Mapa: woo_order_id => liczba nieprzeczytanych wiadomości od klienta.
     * Do podświetlania zamówień na liście oraz filtra „z nowymi wiadomościami".
     * @return array<int,int>
     */
    public function unreadByOrder(): array
    {
        $rows = $this->pdo->query(
            "SELECT woo_order_id, COUNT(*) AS n FROM order_messages
             WHERE sender = 'client' AND is_read = 0 GROUP BY woo_order_id"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        return array_map('intval', $rows ?: []);
    }

    /**
     * Usuwa wiadomość KLIENTA należącą do danego zamówienia. Zwraca usunięty rekord
     * (do skasowania ewentualnego załącznika) lub null, gdy nic nie usunięto.
     * Ograniczenie do sender='client' chroni przed kasowaniem odpowiedzi obsługi.
     */
    public function deleteClientMessage(int $id, int $wooOrderId): ?array
    {
        return $this->deleteBySender($id, $wooOrderId, 'client');
    }

    /**
     * Usuwa wiadomość OBSŁUGI (staff) należącą do danego zamówienia. Zwraca usunięty
     * rekord (do skasowania ewentualnego załącznika) lub null. Ograniczenie do
     * sender='staff' chroni przed kasowaniem wiadomości klienta z panelu.
     */
    public function deleteStaffMessage(int $id, int $wooOrderId): ?array
    {
        return $this->deleteBySender($id, $wooOrderId, 'staff');
    }

    /** @param 'client'|'staff' $sender */
    private function deleteBySender(int $id, int $wooOrderId, string $sender): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM order_messages WHERE id = ? AND woo_order_id = ? AND sender = ?'
        );
        $stmt->execute([$id, $wooOrderId, $sender]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $this->pdo->prepare('DELETE FROM order_messages WHERE id = ?')->execute([$id]);
        return $row;
    }
}
