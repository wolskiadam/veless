<?php
declare(strict_types=1);

namespace Pase\Repository;

use PDO;

/**
 * Dostęp do tabeli automation_rules. Reguły 'jeśli warunki TO akcja'.
 * Warunki i parametry akcji trzymane jako JSON.
 */
final class AutomationRuleRepository
{
    /** Sposób łączenia warunków: 'all' = wszystkie muszą być spełnione (AND), 'any' = wystarczy jeden (OR). */
    public const MATCH_ALL = 'all';
    public const MATCH_ANY = 'any';

    public function __construct(private readonly PDO $pdo) {}

    public static function migrate(PDO $pdo): void
    {
        if (!\Pase\Services\LowStock::columnExists($pdo, 'automation_rules', 'condition_match')) {
            $pdo->exec("ALTER TABLE automation_rules ADD COLUMN condition_match VARCHAR(8) NOT NULL DEFAULT 'all'");
        }
    }

    /**
     * Aktywne reguły danego ZDARZENIA wg priorytetu (rosnąco - mniejsza liczba = wcześniej).
     * Dekoduje JSON-y do tablic dla wygody silnika.
     *
     * @return array<int,array<string,mixed>>
     */
    public function activeForEvent(string $event): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM automation_rules WHERE is_active = 1 AND event = :e ORDER BY priority ASC, id ASC'
        );
        $stmt->execute([':e' => $event]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $this->decode($r);
        }
        return $rows;
    }

    /** Wszystkie reguły (do panelu), z odkodowanymi JSON-ami. */
    public function all(): array
    {
        $rows = $this->pdo->query(
            'SELECT * FROM automation_rules ORDER BY priority ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $this->decode($r);
        }
        return $rows;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM automation_rules WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }
        $this->decode($r);
        return $r;
    }

    /**
     * Zapis reguły w nowym modelu: zdarzenie + warunki + lista akcji.
     *
     * @param array<int,array{field:string,op:string,value:string}> $conditions
     * @param array<int,array{type:string,params:array<string,mixed>}> $actions
     */
    public function save(
        ?int $id,
        string $name,
        string $event,
        bool $isActive,
        int $priority,
        array $conditions,
        array $actions,
        string $match = self::MATCH_ALL
    ): int {
        $match = $match === self::MATCH_ANY ? self::MATCH_ANY : self::MATCH_ALL;
        $condJson    = json_encode(array_values($conditions), JSON_UNESCAPED_UNICODE);
        $actionsJson = json_encode(array_values($actions), JSON_UNESCAPED_UNICODE);
        // Stare kolumny utrzymujemy zsynchronizowane z pierwszą akcją (zgodność wstecz).
        $first       = $actions[0] ?? null;
        $legacyType  = $first['type'] ?? null;
        $legacyParam = json_encode($first['params'] ?? [], JSON_UNESCAPED_UNICODE);

        if ($id) {
            $stmt = $this->pdo->prepare(
                'UPDATE automation_rules
                 SET name=:n, event=:e, is_active=:a, priority=:p, conditions=:c, actions=:ac,
                     action_type=:t, action_params=:ap, condition_match=:m
                 WHERE id=:id'
            );
            $stmt->execute([
                ':n' => $name, ':e' => $event, ':a' => $isActive ? 1 : 0, ':p' => $priority,
                ':c' => $condJson, ':ac' => $actionsJson, ':t' => $legacyType, ':ap' => $legacyParam, ':m' => $match, ':id' => $id,
            ]);
            return $id;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO automation_rules (name, event, is_active, priority, conditions, actions, action_type, action_params, condition_match)
             VALUES (:n, :e, :a, :p, :c, :ac, :t, :ap, :m)'
        );
        $stmt->execute([
            ':n' => $name, ':e' => $event, ':a' => $isActive ? 1 : 0, ':p' => $priority,
            ':c' => $condJson, ':ac' => $actionsJson, ':t' => $legacyType, ':ap' => $legacyParam, ':m' => $match,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Dekoduje JSON-y reguły w miejscu; uzupełnia actions ze starych kolumn, gdy puste. */
    private function decode(array &$r): void
    {
        $r['conditions'] = json_decode($r['conditions'] ?? '[]', true) ?: [];
        $r['actions']    = json_decode($r['actions'] ?? '[]', true) ?: [];
        if ($r['actions'] === [] && !empty($r['action_type'])) {
            $r['actions'] = [[
                'type'   => $r['action_type'],
                'params' => json_decode($r['action_params'] ?? '{}', true) ?: [],
            ]];
        }
        $r['event'] = $r['event'] ?? 'order.imported';
        $r['condition_match'] = ($r['condition_match'] ?? '') === self::MATCH_ANY ? self::MATCH_ANY : self::MATCH_ALL;
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM automation_rules WHERE id = ?')->execute([$id]);
    }

    /** Duplikuje regułę (kopia jako nieaktywna, z dopiskiem „(kopia)"). Zwraca nowe id. */
    public function duplicate(int $id): ?int
    {
        $r = $this->find($id);
        if ($r === null) {
            return null;
        }
        return $this->save(
            null,
            $r['name'] . ' (kopia)',
            $r['event'],
            false,
            (int) $r['priority'],
            $r['conditions'],
            $r['actions'],
            $r['condition_match']
        );
    }

    /**
     * Kolejność z listy (przeciągnij i upuść): pierwsza reguła dostaje priorytet 10, następna 20 itd.
     * Reguły spoza listy zostają za nimi (dostają kolejne numery wg dotychczasowej kolejności).
     * @param list<int> $ids
     */
    public function reorder(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0)));
        $rest = array_values(array_diff(array_map('intval', array_column($this->all(), 'id')), $ids));
        $stmt = $this->pdo->prepare('UPDATE automation_rules SET priority = ? WHERE id = ?');
        $own = !$this->pdo->inTransaction();
        if ($own) { $this->pdo->beginTransaction(); }
        try {
            foreach (array_merge($ids, $rest) as $i => $id) {
                $stmt->execute([($i + 1) * 10, $id]);
            }
            if ($own) { $this->pdo->commit(); }
        } catch (\Throwable $e) {
            if ($own && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
    }

    /** Priorytet dla nowej reguły - na końcu listy. */
    public function nextPriority(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(MAX(priority), 0) FROM automation_rules')->fetchColumn() + 10;
    }

    public function setActive(int $id, bool $active): void
    {
        $this->pdo->prepare('UPDATE automation_rules SET is_active = ? WHERE id = ?')
            ->execute([$active ? 1 : 0, $id]);
    }
}
