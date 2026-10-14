<?php
declare(strict_types=1);

namespace PaseExt\Ksef;

use PDO;

/**
 * Faktury zakupowe (otrzymane) pobrane z KSeF. Tabele tworzone przy pierwszym użyciu (MySQL i SQLite):
 *  - ext_ksef_purchases: metadane faktury z KSeF (numer KSeF, sprzedawca, kwoty), XML dociągany przy pierwszym podglądzie,
 *  - ext_ksef_sync: punkt, od którego zaczyna się kolejne pobieranie (osobno dla środowiska).
 */
final class Purchases
{
    public const TYPES = ['Vat' => 'VAT', 'Kor' => 'korygująca', 'Zal' => 'zaliczkowa', 'Roz' => 'rozliczeniowa', 'Upr' => 'uproszczona',
        'KorZal' => 'korekta zaliczkowej', 'KorRoz' => 'korekta rozliczeniowej', 'VatPef' => 'PEF', 'VatPefSp' => 'PEF', 'KorPef' => 'korekta PEF',
        'VatRr' => 'VAT RR', 'KorVatRr' => 'korekta VAT RR'];

    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ext_ksef_purchases (
            env VARCHAR(8) NOT NULL,
            ksef_number VARCHAR(64) NOT NULL,
            invoice_number VARCHAR(256) NOT NULL,
            invoice_type VARCHAR(16) NULL,
            seller_nip VARCHAR(16) NULL,
            seller_name VARCHAR(512) NULL,
            issue_date VARCHAR(10) NOT NULL,
            storage_date VARCHAR(40) NULL,
            net DECIMAL(14,2) NULL,
            vat DECIMAL(14,2) NULL,
            gross DECIMAL(14,2) NULL,
            currency VARCHAR(3) NULL,
            invoice_hash VARCHAR(64) NULL,
            xml MEDIUMTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (env, ksef_number)
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ext_ksef_sync (
            name VARCHAR(64) NOT NULL PRIMARY KEY,
            value VARCHAR(64) NOT NULL
        )');
    }

    /** Zapisuje metadane z KSeF (InvoiceMetadata). @return bool true = nowa faktura */
    public function save(string $env, array $m): bool
    {
        $ksef = trim((string) ($m['ksefNumber'] ?? ''));
        if ($ksef === '') {
            return false;
        }
        $fields = [
            'invoice_number' => mb_substr((string) ($m['invoiceNumber'] ?? ''), 0, 256),
            'invoice_type' => mb_substr((string) ($m['invoiceType'] ?? ''), 0, 16),
            'seller_nip' => mb_substr((string) ($m['seller']['nip'] ?? ''), 0, 16),
            'seller_name' => mb_substr((string) ($m['seller']['name'] ?? ''), 0, 512),
            'issue_date' => substr((string) ($m['issueDate'] ?? ''), 0, 10),
            'storage_date' => mb_substr((string) ($m['permanentStorageDate'] ?? ''), 0, 40),
            'net' => is_numeric($m['netAmount'] ?? null) ? (float) $m['netAmount'] : null,
            'vat' => is_numeric($m['vatAmount'] ?? null) ? (float) $m['vatAmount'] : null,
            'gross' => is_numeric($m['grossAmount'] ?? null) ? (float) $m['grossAmount'] : null,
            'currency' => mb_substr((string) ($m['currency'] ?? 'PLN'), 0, 3),
            'invoice_hash' => mb_substr((string) ($m['invoiceHash'] ?? ''), 0, 64),
        ];
        if ($this->get($env, $ksef) !== null) {
            $set = implode(', ', array_map(static fn(string $k): string => $k . ' = ?', array_keys($fields)));
            $this->pdo->prepare('UPDATE ext_ksef_purchases SET ' . $set . ' WHERE env = ? AND ksef_number = ?')
                ->execute([...array_values($fields), $env, $ksef]);
            return false;
        }
        $cols = ['env', 'ksef_number', ...array_keys($fields), 'created_at'];
        $this->pdo->prepare('INSERT INTO ext_ksef_purchases (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
            ->execute([$env, $ksef, ...array_values($fields), date('Y-m-d H:i:s')]);
        return true;
    }

    /** @return array<string,mixed>|null */
    public function get(string $env, string $ksefNumber): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM ext_ksef_purchases WHERE env = ? AND ksef_number = ?');
        $st->execute([$env, $ksefNumber]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function setXml(string $env, string $ksefNumber, string $xml): void
    {
        $this->pdo->prepare('UPDATE ext_ksef_purchases SET xml = ? WHERE env = ? AND ksef_number = ?')->execute([$xml, $env, $ksefNumber]);
    }

    /**
     * Faktury środowiska od najnowszej (bez XML). $q szuka w numerze faktury, nazwie i NIP sprzedawcy oraz numerze KSeF;
     * $month w formacie RRRR-MM zawęża do miesiąca wystawienia.
     * @return list<array<string,mixed>>
     */
    public function latest(string $env, string $q = '', string $month = '', int $limit = 500): array
    {
        $sql = 'SELECT env, ksef_number, invoice_number, invoice_type, seller_nip, seller_name, issue_date, storage_date, net, vat, gross, currency, invoice_hash,
                CASE WHEN xml IS NULL THEN 0 ELSE 1 END AS has_xml FROM ext_ksef_purchases WHERE env = ?';
        $args = [$env];
        $q = trim($q);
        if ($q !== '') {
            $sql .= ' AND (invoice_number LIKE ? OR seller_name LIKE ? OR seller_nip LIKE ? OR ksef_number LIKE ?)';
            $like = '%' . $q . '%';
            array_push($args, $like, $like, $like, $like);
        }
        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            $sql .= ' AND issue_date LIKE ?';
            $args[] = $month . '-%';
        }
        $st = $this->pdo->prepare($sql . ' ORDER BY issue_date DESC, storage_date DESC LIMIT ' . max(1, $limit));
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function syncPoint(string $env): ?string
    {
        $st = $this->pdo->prepare('SELECT value FROM ext_ksef_sync WHERE name = ?');
        $st->execute(['purchases_' . $env]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public function setSyncPoint(string $env, string $value): void
    {
        $name = 'purchases_' . $env;
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM ext_ksef_sync WHERE name = ?')->execute([$name]);
            $this->pdo->prepare('INSERT INTO ext_ksef_sync (name, value) VALUES (?, ?)')->execute([$name, $value]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
