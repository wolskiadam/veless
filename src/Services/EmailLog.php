<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;

/**
 * Dziennik wysłanych e-maili (System → Wysłane e-maile).
 *
 * Każda próba wysyłki przez Mailer::send() zapisuje jeden wiersz: rodzaj wiadomości,
 * szablon, odbiorcę, temat, konto nadawcy, zamówienie, kto/co ją wywołał i czy się udała.
 * Treści maila nie zapisujemy (dane klienta, linki z tokenami). Wpisy starsze niż
 * KEEP_DAYS są usuwane przy okazji kolejnych zapisów.
 *
 * Zapis nigdy nie może zepsuć wysyłki: każdy błąd bazy jest cicho pomijany.
 */
final class EmailLog
{
    public const KEEP_DAYS = 365;

    /** Rodzaje wiadomości (klucz w bazie => etykieta w panelu). */
    public const TYPES = [
        'automation'     => 'Automatyzacja (szablon e-mail)',
        'order_message'  => 'Wiadomość do klienta (zamówienie)',
        'client_message' => 'Powiadomienie: klient napisał',
        'password_reset' => 'Reset hasła do panelu',
        'smtp_test'      => 'Test SMTP',
        'template_test'  => 'Test szablonu e-mail',
        'ops_alert'      => 'Alarm monitora systemu',
        'other'          => 'Inne',
    ];

    private static bool $migrated = false;

    public function __construct(private readonly PDO $pdo) {}

    public static function migrate(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $id   = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $tail = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $pdo->exec("CREATE TABLE IF NOT EXISTS email_log (
            id $id,
            created_at VARCHAR(30) NOT NULL,
            type VARCHAR(40) NOT NULL,
            template_key VARCHAR(100) NULL,
            woo_order_id BIGINT NULL,
            recipient VARCHAR(190) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            sender VARCHAR(190) NULL,
            status VARCHAR(10) NOT NULL,
            error VARCHAR(500) NULL,
            source VARCHAR(20) NULL,
            actor_name VARCHAR(190) NULL
        )$tail");
        foreach (['email_log_created' => '(created_at)', 'email_log_order' => '(woo_order_id)'] as $name => $cols) {
            if ($driver === 'mysql') {
                if (!$pdo->query("SHOW INDEX FROM email_log WHERE Key_name = '$name'")->fetch()) {
                    $pdo->exec("CREATE INDEX $name ON email_log $cols");
                }
            } else {
                $pdo->exec("CREATE INDEX IF NOT EXISTS $name ON email_log $cols");
            }
        }
        self::$migrated = true;
    }

    /**
     * Zapis próby wysyłki z Mailera. Połączenie z bazy procesu (Runtime); bez niego (np. testy
     * samego Mailera) wpis jest pomijany.
     * @param array{type?:string,template?:?string,order_id?:int|string|null} $meta
     */
    public static function recordAttempt(array $meta, string $to, string $subject, string $sender, bool $ok, string $error = ''): void
    {
        try {
            $pdo = \Pase\Support\Runtime::pdo();
        } catch (\Throwable) {
            return;
        }
        try {
            (new self($pdo))->record($meta, $to, $subject, $sender, $ok, $error);
        } catch (\Throwable $e) {
            \Pase\Support\Logger::warn('Dziennik e-maili: nie zapisano wpisu - ' . $e->getMessage());
        }
    }

    /** @param array{type?:string,template?:?string,order_id?:int|string|null} $meta */
    public function record(array $meta, string $to, string $subject, string $sender, bool $ok, string $error = ''): void
    {
        if (!self::$migrated) {
            self::migrate($this->pdo);
        }
        $type = (string) ($meta['type'] ?? 'other');
        if (!isset(self::TYPES[$type])) {
            $type = 'other';
        }
        $orderId = (int) ($meta['order_id'] ?? 0);
        $actorId = $_SESSION['pase_user_id'] ?? null;
        $actor   = $actorId ? (string) ($_SESSION['pase_username'] ?? 'Użytkownik') : null;
        $this->pdo->prepare('INSERT INTO email_log
            (created_at, type, template_key, woo_order_id, recipient, subject, sender, status, error, source, actor_name)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            gmdate('Y-m-d H:i:s'),
            $type,
            ($meta['template'] ?? '') !== '' ? mb_substr((string) $meta['template'], 0, 100) : null,
            $orderId > 0 ? $orderId : null,
            mb_substr($to, 0, 190),
            mb_substr($subject, 0, 255),
            $sender !== '' ? mb_substr($sender, 0, 190) : null,
            $ok ? 'sent' : 'failed',
            $ok || $error === '' ? null : mb_substr($error, 0, 500),
            \Pase\Support\UsageStats::kind(),
            $actor !== null ? mb_substr($actor, 0, 190) : null,
        ]);
        // Sprzątanie rzadko (ok. 1 na 200 zapisów), żeby nie dokładać DELETE do każdej wysyłki.
        if (random_int(1, 200) === 1) {
            $this->purge();
        }
    }

    public function purge(int $days = self::KEEP_DAYS): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM email_log WHERE created_at < ?');
        $stmt->execute([gmdate('Y-m-d H:i:s', time() - $days * 86400)]);
        return $stmt->rowCount();
    }

    /**
     * Warunek WHERE z filtrów panelu. Daty $from/$to (UTC 'Y-m-d H:i:s'): od włącznie, do wyłącznie.
     * @param array{from?:string,to?:string,type?:string,status?:string,order_id?:int,q?:string} $f
     * @return array{0:string,1:list<mixed>}
     */
    private function where(array $f): array
    {
        $c = []; $p = [];
        if (($f['from'] ?? '') !== '') { $c[] = 'created_at >= ?'; $p[] = $f['from']; }
        if (($f['to'] ?? '') !== '')   { $c[] = 'created_at < ?';  $p[] = $f['to']; }
        if (isset(self::TYPES[$f['type'] ?? ''])) { $c[] = 'type = ?'; $p[] = $f['type']; }
        if (in_array($f['status'] ?? '', ['sent', 'failed'], true)) { $c[] = 'status = ?'; $p[] = $f['status']; }
        if ((int) ($f['order_id'] ?? 0) > 0) { $c[] = 'woo_order_id = ?'; $p[] = (int) $f['order_id']; }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            // Znak ucieczki '!' zamiast backslasha - backslash w literale SQL MySQL i SQLite czytają różnie.
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
            $c[] = "(recipient LIKE ? ESCAPE '!' OR subject LIKE ? ESCAPE '!' OR template_key LIKE ? ESCAPE '!')";
            array_push($p, $like, $like, $like);
        }
        return [$c ? ' WHERE ' . implode(' AND ', $c) : '', $p];
    }

    /**
     * Liczby wysłanych / nieudanych w rodzajach wiadomości.
     * @return array<string,array{sent:int,failed:int}>
     */
    public function countsByType(array $filters): array
    {
        [$w, $p] = $this->where($filters);
        $stmt = $this->pdo->prepare("SELECT type, status, COUNT(*) AS n FROM email_log$w GROUP BY type, status");
        $stmt->execute($p);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['type']] ??= ['sent' => 0, 'failed' => 0];
            $out[$r['type']][$r['status'] === 'sent' ? 'sent' : 'failed'] += (int) $r['n'];
        }
        return $out;
    }

    /**
     * Liczby według szablonu (automatyzacje) - który szablon ile razy poszedł.
     * @return list<array{template_key:string,sent:int,failed:int}>
     */
    public function countsByTemplate(array $filters): array
    {
        [$w, $p] = $this->where($filters);
        $w .= ($w === '' ? ' WHERE ' : ' AND ') . 'template_key IS NOT NULL';
        $stmt = $this->pdo->prepare("SELECT template_key,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN status = 'sent' THEN 0 ELSE 1 END) AS failed
            FROM email_log$w GROUP BY template_key ORDER BY COUNT(*) DESC");
        $stmt->execute($p);
        return array_map(static fn(array $r): array => [
            'template_key' => (string) $r['template_key'], 'sent' => (int) $r['sent'], 'failed' => (int) $r['failed'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Liczby według konta nadawcy (skąd poszły maile).
     * @return list<array{sender:string,sent:int,failed:int}>
     */
    public function countsBySender(array $filters): array
    {
        [$w, $p] = $this->where($filters);
        $stmt = $this->pdo->prepare("SELECT COALESCE(sender, '') AS sender,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN status = 'sent' THEN 0 ELSE 1 END) AS failed
            FROM email_log$w GROUP BY COALESCE(sender, '') ORDER BY COUNT(*) DESC");
        $stmt->execute($p);
        return array_map(static fn(array $r): array => [
            'sender' => (string) $r['sender'], 'sent' => (int) $r['sent'], 'failed' => (int) $r['failed'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Liczby dzień po dniu w strefie $tz (grupowanie po godzinie UTC, potem przeliczenie na dzień lokalny).
     * @return array<string,array{sent:int,failed:int}> klucz 'Y-m-d', od najnowszego
     */
    public function countsByDay(array $filters, string $tz = 'Europe/Warsaw'): array
    {
        [$w, $p] = $this->where($filters);
        $stmt = $this->pdo->prepare("SELECT SUBSTR(created_at, 1, 13) AS h, status, COUNT(*) AS n FROM email_log$w GROUP BY SUBSTR(created_at, 1, 13), status");
        $stmt->execute($p);
        $zone = new \DateTimeZone($tz);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $day = (new \DateTimeImmutable($r['h'] . ':00:00', new \DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d');
            $out[$day] ??= ['sent' => 0, 'failed' => 0];
            $out[$day][$r['status'] === 'sent' ? 'sent' : 'failed'] += (int) $r['n'];
        }
        krsort($out);
        return $out;
    }

    /**
     * Wpisy w kolejności od najnowszych (UTC w created_at).
     * @return list<array<string,mixed>>
     */
    public function entries(array $filters, int $limit, int $offset = 0): array
    {
        [$w, $p] = $this->where($filters);
        $stmt = $this->pdo->prepare("SELECT * FROM email_log$w ORDER BY id DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset));
        $stmt->execute($p);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Wysyłki powiązane z zamówieniem (rosnąco wg czasu) - do wątku „Wiadomości z klientem".
     * @param list<string> $types rodzaje z TYPES
     * @return list<array<string,mixed>>
     */
    public function forOrder(int $wooOrderId, array $types = ['automation']): array
    {
        $types = array_values(array_filter($types, static fn(string $t): bool => isset(self::TYPES[$t])));
        if ($wooOrderId <= 0 || $types === []) {
            return [];
        }
        $in = implode(', ', array_fill(0, count($types), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM email_log WHERE woo_order_id = ? AND type IN ($in) ORDER BY created_at ASC, id ASC");
        $stmt->execute([$wooOrderId, ...$types]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function count(array $filters): int
    {
        [$w, $p] = $this->where($filters);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM email_log$w");
        $stmt->execute($p);
        return (int) $stmt->fetchColumn();
    }
}
