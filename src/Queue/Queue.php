<?php
declare(strict_types=1);

namespace Pase\Queue;

use PDO;
use Pase\Support\Logger;

/**
 * Kolejka oparta na tabeli MySQL `job_queue`.
 * Zastępuje Redis + BullMQ na shared hostingu.
 *
 * Gwarancje:
 *  - Idempotentne wrzucanie:    UNIQUE(dedup_key) + INSERT IGNORE.
 *  - Bezpieczny claim:          atomowy UPDATE ... WHERE status='pending'
 *                               z LIMIT 1, dzięki czemu dwa równoległe
 *                               przebiegi crona nie wezmą tego samego zadania.
 *  - Retry z backoffem:         fail() liczy wykładnicze opóźnienie i przesuwa
 *                               available_at; po max_attempts -> status=failed.
 */
final class Queue
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Wrzuca zadanie. Jeśli `dedupKey` już istnieje w kolejce - nic nie robi
     * (wymaganie idempotentności: ten sam webhook nie wejdzie 2x).
     *
     * @return bool true jeśli faktycznie dodano nowe zadanie
     */
    public function enqueue(string $jobType, array $payload, ?string $dedupKey = null, int $maxAttempts = 3): bool
    {
        $sql = 'INSERT IGNORE INTO job_queue (job_type, payload, dedup_key, max_attempts, status, available_at)
                VALUES (:type, :payload, :dedup, :max, :status, UTC_TIMESTAMP())';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':type'    => $jobType,
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ':dedup'   => $dedupKey,
            ':max'     => $maxAttempts,
            ':status'  => JobStatus::Pending->value,
        ]);

        $added = $stmt->rowCount() > 0;
        if ($added) {
            Logger::info("Queue: dodano zadanie {$jobType}", ['dedup' => $dedupKey]);
        } else {
            Logger::info("Queue: pominięto duplikat {$jobType}", ['dedup' => $dedupKey]);
        }
        return $added;
    }

    /**
     * Wskrzesza zadanie, które wcześniej padło (status failed), z nowym payloadem.
     * Dzięki temu ponowne pobranie z Allegro naprawia zamówienie, które kiedyś się
     * wyłożyło (np. na brakującym SKU), zamiast po cichu pomijać je jako duplikat.
     */
    public function reviveFailed(string $dedupKey, array $payload): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE job_queue
             SET status = :pending, attempts = 0, reserved_at = NULL,
                 available_at = UTC_TIMESTAMP(), last_error = NULL, payload = :payload
             WHERE dedup_key = :dedup AND status = :failed"
        );
        $stmt->execute([
            ':pending' => JobStatus::Pending->value,
            ':failed'  => JobStatus::Failed->value,
            ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ':dedup'   => $dedupKey,
        ]);
        $revived = $stmt->rowCount() > 0;
        if ($revived) {
            Logger::info('Queue: wznowiono zadanie, które wcześniej padło', ['dedup' => $dedupKey]);
        }
        return $revived;
    }

    /**
     * Atomowo rezerwuje JEDNO gotowe zadanie i zwraca je (lub null).
     * Wzorzec "claim by token": najpierw oznaczamy wiersz unikalnym reserved_at,
     * potem go odczytujemy - bez SELECT ... FOR UPDATE, działa też na MyISAM/replica.
     *
     * @return array<string,mixed>|null
     */
    public function reserveNext(): ?array
    {
        // Unikalny znacznik tej rezerwacji (mikrosekundy + pid + losowość).
        $token = sprintf('%s.%d.%s', microtime(true), getmypid() ?: 0, bin2hex(random_bytes(4)));

        // Token idzie do reserved_token (VARCHAR), a reserved_at dostaje
        // prawdziwą datę. Wcześniej token trafiał do reserved_at (DATETIME),
        // przez co odczyt po tokenie zawsze zawodził i zadania utykały.
        $update = $this->pdo->prepare(
            "UPDATE job_queue
             SET status = :reserved, reserved_token = :token, reserved_at = UTC_TIMESTAMP()
             WHERE status = :pending
               AND available_at <= UTC_TIMESTAMP()
             ORDER BY id ASC
             LIMIT 1"
        );
        $update->execute([
            ':reserved' => JobStatus::Reserved->value,
            ':token'    => $token,
            ':pending'  => JobStatus::Pending->value,
        ]);

        if ($update->rowCount() === 0) {
            return null; // brak gotowych zadań
        }

        $select = $this->pdo->prepare('SELECT * FROM job_queue WHERE reserved_token = :token LIMIT 1');
        $select->execute([':token' => $token]);
        $job = $select->fetch();

        return $job ?: null;
    }

    /** Oznacza zadanie jako wykonane. */
    public function complete(int $jobId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE job_queue SET status = :done, last_error = NULL WHERE id = :id'
        );
        $stmt->execute([':done' => JobStatus::Done->value, ':id' => $jobId]);
        Logger::info("Queue: zadanie #{$jobId} zakończone");
    }

    /**
     * Obsługa błędu zgodnie z briefem: 3 próby, wykładniczy backoff.
     * Zwiększa attempts; jeśli < max_attempts -> wraca do pending z opóźnieniem,
     * w przeciwnym razie -> failed (do ręcznej inspekcji / dead letter).
     */
    public function fail(array $job, string $error): void
    {
        $jobId    = (int) $job['id'];
        $attempts = (int) $job['attempts'] + 1;
        $max      = (int) $job['max_attempts'];

        if ($attempts >= $max) {
            $stmt = $this->pdo->prepare(
                'UPDATE job_queue
                 SET status = :failed, attempts = :att, last_error = :err
                 WHERE id = :id'
            );
            $stmt->execute([
                ':failed' => JobStatus::Failed->value,
                ':att'    => $attempts,
                ':err'    => $error,
                ':id'     => $jobId,
            ]);
            Logger::error("Queue: zadanie #{$jobId} FAILED po {$attempts} próbach", ['error' => $error]);
            return;
        }

        // Wykładniczy backoff: 2^attempts * 30s (próba 1 -> 60s, 2 -> 120s, ...).
        $delaySeconds = (2 ** $attempts) * 30;

        $stmt = $this->pdo->prepare(
            'UPDATE job_queue
             SET status = :pending,
                 attempts = :att,
                 last_error = :err,
                 reserved_at = NULL,
                 available_at = (UTC_TIMESTAMP() + INTERVAL :delay SECOND)
             WHERE id = :id'
        );
        $stmt->execute([
            ':pending' => JobStatus::Pending->value,
            ':att'     => $attempts,
            ':err'     => $error,
            ':delay'   => $delaySeconds,
            ':id'      => $jobId,
        ]);
        Logger::warn("Queue: zadanie #{$jobId} ponowienie {$attempts}/{$max} za {$delaySeconds}s", ['error' => $error]);
    }

    /**
     * Odzyskuje "zawieszone" zadania - takie, które utknęły w stanie reserved
     * (np. worker padł). Wywoływane na początku każdego przebiegu workera.
     */
    /**
     * Usuwa WYKONANE zadania starsze niż $days dni (zadania z błędem zostają).
     * Porcjami po 1000, żeby nie blokować tabeli jednym dużym DELETE. Handlery importu
     * same pilnują duplikatów (order_logs / upsert), więc stare klucze dedup nie są potrzebne.
     * @return int liczba usuniętych
     */
    public function purgeDone(int $days, int $maxRows = 20000): int
    {
        if ($days < 1) {
            return 0;
        }
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
        $select = $this->pdo->prepare("SELECT id FROM job_queue WHERE status = 'done' AND updated_at < ? ORDER BY id LIMIT 1000");
        $deleted = 0;
        while ($deleted < $maxRows) {
            $select->execute([$cutoff]);
            $ids = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN));
            if ($ids === []) {
                break;
            }
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $del = $this->pdo->prepare("DELETE FROM job_queue WHERE status = 'done' AND id IN ({$marks})");
            $del->execute($ids);
            $deleted += $del->rowCount();
        }
        return $deleted;
    }

    public function recoverStuck(int $olderThanMinutes = 10): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE job_queue
             SET status = :pending, reserved_at = NULL, reserved_token = NULL
             WHERE status = :reserved
               AND reserved_at <= (UTC_TIMESTAMP() - INTERVAL :mins MINUTE)"
        );
        $stmt->bindValue(':pending', JobStatus::Pending->value);
        $stmt->bindValue(':reserved', JobStatus::Reserved->value);
        $stmt->bindValue(':mins', $olderThanMinutes, PDO::PARAM_INT);
        $stmt->execute();

        $count = $stmt->rowCount();
        if ($count > 0) {
            Logger::warn("Queue: odzyskano {$count} zawieszonych zadań");
        }
        return $count;
    }
}
