<?php
declare(strict_types=1);

namespace PaseExt\Przyklad;

use PDO;

/**
 * Notatki wewnętrzne do zamówień - własna tabela rozszerzenia (tworzona przy pierwszym użyciu).
 * Jedna notatka na zamówienie; zapis zastępuje poprzednią.
 */
final class Notes
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ext_przyklad_notes (
            woo_order_id BIGINT NOT NULL PRIMARY KEY,
            note TEXT NOT NULL,
            updated_by VARCHAR(100) NULL,
            updated_at DATETIME NOT NULL
        )');
    }

    public function get(int $orderId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM ext_przyklad_notes WHERE woo_order_id = ?');
        $st->execute([$orderId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @param int[] $ids @return array<int,string> zamówienie => notatka */
    public function forOrders(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $st = $this->pdo->prepare('SELECT woo_order_id, note FROM ext_przyklad_notes WHERE woo_order_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        return array_column($st->fetchAll(PDO::FETCH_ASSOC), 'note', 'woo_order_id');
    }

    public function save(int $orderId, string $note, string $by): void
    {
        $note = mb_substr(trim($note), 0, 2000);
        $this->pdo->prepare('DELETE FROM ext_przyklad_notes WHERE woo_order_id = ?')->execute([$orderId]);
        if ($note !== '') {
            $this->pdo->prepare('INSERT INTO ext_przyklad_notes (woo_order_id, note, updated_by, updated_at) VALUES (?, ?, ?, ?)')
                ->execute([$orderId, $note, $by, gmdate('Y-m-d H:i:s')]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function latest(int $limit = 100): array
    {
        return $this->pdo->query('SELECT * FROM ext_przyklad_notes ORDER BY updated_at DESC LIMIT ' . max(1, $limit))->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countBetween(string $from, string $to): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM ext_przyklad_notes WHERE updated_at >= ? AND updated_at < ?');
        $st->execute([$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']);
        return (int) $st->fetchColumn();
    }
}
