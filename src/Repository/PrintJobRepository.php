<?php
declare(strict_types=1);

namespace Pase\Repository;

use PDO;

/**
 * Kolejka zadań druku dla lokalnego agenta (patrz public/print_agent_poll.php).
 * Zawartość (ZPL/EPL/PDF...) trzymamy jako base64 w LONGTEXT - prościej niż BLOB
 * przy tym samym wzorcu PDO co reszta aplikacji, a treści etykiet są niewielkie.
 */
final class PrintJobRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param string $target 'zebra' (drukarka etykiet) albo 'a4' (zwykła drukarka).
     *                       Agent ma skonfigurowane obie i wybiera po tym polu.
     */
    public function enqueue(
        string $kind,
        string $format,
        string $rawContent,
        ?string $filename,
        string $target = 'zebra'
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO print_jobs (kind, target, format, filename, content_b64, status)
             VALUES (:kind, :target, :format, :filename, :content, \'pending\')'
        );
        $stmt->execute([
            ':kind'     => $kind,
            ':target'   => $target === 'a4' ? 'a4' : 'zebra',
            ':format'   => $format,
            ':filename' => $filename,
            ':content'  => base64_encode($rawContent),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Atomowo "claim'uje" najstarsze oczekujące zadanie (pending -> sent), żeby dwóch
     * agentów (albo dwa odpytania naraz) nie odebrały tego samego zadania podwójnie.
     * @return array{id:int,kind:string,format:string,filename:?string,content_b64:string}|null
     */
    public function claimNext(): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM print_jobs WHERE status = \'pending\' ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute();
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }

        $upd = $this->pdo->prepare(
            "UPDATE print_jobs SET status = 'sent', sent_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'pending'"
        );
        $upd->execute([(int) $id]);
        if ($upd->rowCount() === 0) {
            // Ktoś inny (drugie równoległe odpytanie) zdążył pierwszy - bez pętli retry,
            // agent i tak zapyta ponownie za chwilę.
            return null;
        }

        $row = $this->pdo->prepare('SELECT * FROM print_jobs WHERE id = ?');
        $row->execute([(int) $id]);
        return $row->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function markDone(int $id): void
    {
        $this->pdo->prepare("UPDATE print_jobs SET status = 'done', done_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute([$id]);
    }

    public function markFailed(int $id, string $error): void
    {
        $this->pdo->prepare("UPDATE print_jobs SET status = 'failed', error = ? WHERE id = ?")
            ->execute([$error, $id]);
    }

    /**
     * Zwraca do kolejki zadania utknięte w stanie 'sent' (agent padł/zawiesił się
     * w trakcie druku bez potwierdzenia) - ten sam problem co blokada workera,
     * patrz cli/worker.php. Wywoływane przy każdym odpytaniu agenta.
     */
    public function requeueStale(int $olderThanMinutes = 10): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE print_jobs SET status = 'pending', sent_at = NULL
             WHERE status = 'sent' AND sent_at < (UTC_TIMESTAMP() - INTERVAL ? MINUTE)"
        );
        $stmt->execute([$olderThanMinutes]);
        return $stmt->rowCount();
    }

    /** Ostatnie zadania (podgląd w panelu). */
    public function recent(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare('SELECT id, kind, target, format, filename, status, error, created_at, sent_at, done_at FROM print_jobs ORDER BY id DESC LIMIT ' . max(1, $limit));
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
