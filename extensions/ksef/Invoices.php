<?php
declare(strict_types=1);

namespace PaseExt\Ksef;

use PDO;

/**
 * Tabele rozszerzenia (tworzone przy pierwszym użyciu, MySQL i SQLite):
 *  - ext_ksef_invoices: jedna e-faktura na zamówienie w danym środowisku KSeF (test/demo/prod),
 *    z XML-em wysłanym do KSeF, numerem KSeF, numerami referencyjnymi sesji i faktury oraz UPO,
 *  - ext_ksef_counters: liczniki numeracji faktur (osobno dla środowiska i okresu).
 *
 * Statusy: sending (trwa wysyłka), processing (KSeF sprawdza fakturę), accepted (ma numer KSeF),
 * rejected (KSeF odrzucił - można poprawić dane i wysłać ponownie z tym samym numerem),
 * error (wysyłka przerwana - nie wiadomo, czy KSeF ją dostał; ponowienie z tym samym numerem
 * jest bezpieczne, bo KSeF odrzuci duplikat numeru).
 */
final class Invoices
{
    public const OPEN = ['sending', 'processing'];

    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ext_ksef_invoices (
            woo_order_id BIGINT NOT NULL,
            env VARCHAR(8) NOT NULL,
            status VARCHAR(16) NOT NULL,
            number VARCHAR(64) NOT NULL,
            issue_date VARCHAR(10) NOT NULL,
            gross DECIMAL(12,2) NOT NULL,
            buyer VARCHAR(255) NULL,
            ksef_number VARCHAR(64) NULL,
            session_ref VARCHAR(64) NULL,
            invoice_ref VARCHAR(64) NULL,
            xml MEDIUMTEXT NULL,
            upo MEDIUMTEXT NULL,
            message TEXT NULL,
            created_by VARCHAR(100) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (woo_order_id, env)
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ext_ksef_counters (
            period VARCHAR(32) NOT NULL PRIMARY KEY,
            last_no INT NOT NULL
        )');
    }

    /** @return array<string,mixed>|null */
    public function get(int $orderId, string $env): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM ext_ksef_invoices WHERE woo_order_id = ? AND env = ?');
        $st->execute([$orderId, $env]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @param int[] $ids @return array<int,array{status:string,number:string,ksef_number:?string,message:?string}> */
    public function forOrders(array $ids, string $env): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $st = $this->pdo->prepare('SELECT woo_order_id, status, number, ksef_number, message FROM ext_ksef_invoices WHERE env = ? AND woo_order_id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute([$env, ...$ids]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['woo_order_id']] = $r;
        }
        return $out;
    }

    /** @return list<array<string,mixed>> najnowsze e-faktury środowiska (bez treści XML/UPO) */
    public function latest(string $env, int $limit = 200, ?string $status = null): array
    {
        $sql = 'SELECT woo_order_id, env, status, number, issue_date, gross, buyer, ksef_number, message, created_by, created_at, updated_at,
                CASE WHEN upo IS NULL THEN 0 ELSE 1 END AS has_upo
                FROM ext_ksef_invoices WHERE env = ?' . ($status !== null ? ' AND status = ?' : '') . ' ORDER BY created_at DESC LIMIT ' . max(1, $limit);
        $st = $this->pdo->prepare($sql);
        $st->execute($status !== null ? [$env, $status] : [$env]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Rezerwuje e-fakturę zamówienia przed wysyłką (blokuje drugą równoległą wysyłkę).
     * Nowa faktura dostaje kolejny numer; ponowienie po odrzuceniu/błędzie zachowuje numer.
     * @param callable(string $number):array{xml:string,issue_date:string,gross:float,buyer:string} $render
     * @return array<string,mixed> zarezerwowany wiersz
     */
    public function reserve(int $orderId, string $env, string $format, string $user, callable $render): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $existing = $this->get($orderId, $env);
            // „sending” bez numerów referencyjnych sprzed 15 minut = wysyłka przerwana (np. timeout PHP) - można ponowić.
            $stale = $existing !== null && $existing['status'] === 'sending' && empty($existing['invoice_ref'])
                && strtotime((string) $existing['updated_at'] . ' UTC') < time() - 900;
            if ($existing !== null && !$stale && in_array($existing['status'], ['accepted', ...self::OPEN], true)) {
                throw new \RuntimeException(match ($existing['status']) {
                    'accepted' => 'To zamówienie ma już e-fakturę ' . $existing['number'] . ' w KSeF (' . $existing['ksef_number'] . ').',
                    default => 'E-faktura ' . $existing['number'] . ' tego zamówienia jest właśnie wysyłana albo sprawdzana przez KSeF. Odśwież jej status.',
                });
            }
            $number = $existing['number'] ?? $this->nextNumber($env, $format);
            $doc = $render((string) $number);
            if ($existing === null) {
                $this->pdo->prepare('INSERT INTO ext_ksef_invoices (woo_order_id, env, status, number, issue_date, gross, buyer, xml, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$orderId, $env, 'sending', $number, $doc['issue_date'], $doc['gross'], mb_substr($doc['buyer'], 0, 255), $doc['xml'], $user, $now, $now]);
            } else {
                $this->pdo->prepare("UPDATE ext_ksef_invoices SET status = 'sending', issue_date = ?, gross = ?, buyer = ?, xml = ?, ksef_number = NULL,
                    session_ref = NULL, invoice_ref = NULL, upo = NULL, message = NULL, created_by = ?, updated_at = ? WHERE woo_order_id = ? AND env = ?")
                    ->execute([$doc['issue_date'], $doc['gross'], mb_substr($doc['buyer'], 0, 255), $doc['xml'], $user, $now, $orderId, $env]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return (array) $this->get($orderId, $env);
    }

    /** @param array<string,mixed> $fields */
    public function update(int $orderId, string $env, array $fields): void
    {
        $allowed = ['status', 'ksef_number', 'session_ref', 'invoice_ref', 'upo', 'message'];
        $fields = array_intersect_key($fields, array_flip($allowed));
        if ($fields === []) {
            return;
        }
        $set = implode(', ', array_map(static fn(string $k): string => $k . ' = ?', array_keys($fields)));
        $this->pdo->prepare('UPDATE ext_ksef_invoices SET ' . $set . ', updated_at = ? WHERE woo_order_id = ? AND env = ?')
            ->execute([...array_values($fields), gmdate('Y-m-d H:i:s'), $orderId, $env]);
    }

    /**
     * Numer z wzoru: {nr} kolejny numer, {mm} miesiąc, {rrrr} rok. Licznik zaczyna się od 1 co miesiąc,
     * gdy wzór ma {mm}, co rok, gdy ma tylko {rrrr}, a bez nich liczy bez końca.
     */
    public function nextNumber(string $env, string $format, ?\DateTimeImmutable $date = null): string
    {
        $date ??= new \DateTimeImmutable('now', new \DateTimeZone('Europe/Warsaw'));
        $format = trim($format) !== '' ? trim($format) : 'KSEF/{nr}/{mm}/{rrrr}';
        if (!str_contains($format, '{nr}')) {
            throw new \RuntimeException('Wzór numeru faktury w ustawieniach musi zawierać {nr}.');
        }
        $period = $env . ':' . (str_contains($format, '{mm}') ? $date->format('Y-m') : (str_contains($format, '{rrrr}') ? $date->format('Y') : 'all'));
        $inTx = $this->pdo->inTransaction();
        if (!$inTx) {
            $this->pdo->beginTransaction();
        }
        try {
            $upd = $this->pdo->prepare('UPDATE ext_ksef_counters SET last_no = last_no + 1 WHERE period = ?');
            $upd->execute([$period]);
            if ($upd->rowCount() === 0) {
                $this->pdo->prepare('INSERT INTO ext_ksef_counters (period, last_no) VALUES (?, 1)')->execute([$period]);
            }
            $st = $this->pdo->prepare('SELECT last_no FROM ext_ksef_counters WHERE period = ?');
            $st->execute([$period]);
            $no = (int) $st->fetchColumn();
            if (!$inTx) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if (!$inTx) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return strtr($format, ['{nr}' => (string) $no, '{mm}' => $date->format('m'), '{rrrr}' => $date->format('Y'), '{rr}' => $date->format('y')]);
    }
}
