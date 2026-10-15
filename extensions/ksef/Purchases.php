<?php
declare(strict_types=1);

namespace PaseExt\Ksef;

use PDO;

/**
 * Faktury pobrane z KSeF: zakupowe (firma jest nabywcą) albo sprzedażowe (firma jest sprzedawcą).
 * Tabele tworzone przy pierwszym użyciu (MySQL i SQLite):
 *  - ext_ksef_purchases / ext_ksef_sales: metadane faktury z KSeF (numer KSeF, kontrahent, kwoty), XML dociągany przy pierwszym podglądzie;
 *    kontrahent to sprzedawca (seller_*) w zakupowych i nabywca (buyer_*) w sprzedażowych,
 *  - ext_ksef_sync: punkt, od którego zaczyna się kolejne pobieranie (osobno dla rodzaju i środowiska).
 */
final class Purchases
{
    public const PURCHASE = 'purchase';
    public const SALE = 'sale';

    public const TYPES = ['Vat' => 'VAT', 'Kor' => 'korygująca', 'Zal' => 'zaliczkowa', 'Roz' => 'rozliczeniowa', 'Upr' => 'uproszczona',
        'KorZal' => 'korekta zaliczkowej', 'KorRoz' => 'korekta rozliczeniowej', 'VatPef' => 'PEF', 'VatPefSp' => 'PEF', 'KorPef' => 'korekta PEF',
        'VatRr' => 'VAT RR', 'KorVatRr' => 'korekta VAT RR'];

    private readonly string $table;
    /** Prefiks kolumn kontrahenta: seller albo buyer. */
    private readonly string $party;

    public function __construct(private readonly PDO $pdo, private readonly string $kind = self::PURCHASE)
    {
        if ($kind !== self::PURCHASE && $kind !== self::SALE) {
            throw new \InvalidArgumentException('Nieznany rodzaj faktur: ' . $kind);
        }
        $this->table = $kind === self::SALE ? 'ext_ksef_sales' : 'ext_ksef_purchases';
        $this->party = $kind === self::SALE ? 'buyer' : 'seller';
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->table . ' (
            env VARCHAR(8) NOT NULL,
            ksef_number VARCHAR(64) NOT NULL,
            invoice_number VARCHAR(256) NOT NULL,
            invoice_type VARCHAR(16) NULL,
            ' . $this->party . '_nip VARCHAR(16) NULL,
            ' . $this->party . '_name VARCHAR(512) NULL,
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

    public function kind(): string
    {
        return $this->kind;
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
            $this->party . '_nip' => mb_substr($this->partyId($m), 0, 16),
            $this->party . '_name' => mb_substr((string) ($m[$this->party]['name'] ?? ''), 0, 512),
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
            $this->pdo->prepare('UPDATE ' . $this->table . ' SET ' . $set . ' WHERE env = ? AND ksef_number = ?')
                ->execute([...array_values($fields), $env, $ksef]);
            return false;
        }
        $cols = ['env', 'ksef_number', ...array_keys($fields), 'created_at'];
        $this->pdo->prepare('INSERT INTO ' . $this->table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
            ->execute([$env, $ksef, ...array_values($fields), date('Y-m-d H:i:s')]);
        return true;
    }

    /** @return array<string,mixed>|null */
    public function get(string $env, string $ksefNumber): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM ' . $this->table . ' WHERE env = ? AND ksef_number = ?');
        $st->execute([$env, $ksefNumber]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function setXml(string $env, string $ksefNumber, string $xml): void
    {
        $this->pdo->prepare('UPDATE ' . $this->table . ' SET xml = ? WHERE env = ? AND ksef_number = ?')->execute([$xml, $env, $ksefNumber]);
    }

    /**
     * Faktury środowiska od najnowszej (bez XML). $q szuka w numerze faktury, nazwie i NIP sprzedawcy oraz numerze KSeF;
     * $month w formacie RRRR-MM zawęża do miesiąca wystawienia.
     * @return list<array<string,mixed>>
     */
    public function latest(string $env, string $q = '', string $month = '', int $limit = 500, int $offset = 0): array
    {
        [$where, $args] = $this->filter($env, $q, $month);
        // party_nip / party_name: kontrahent (sprzedawca zakupu albo nabywca sprzedaży)
        $st = $this->pdo->prepare('SELECT env, ksef_number, invoice_number, invoice_type, ' . $this->party . '_nip AS party_nip, ' . $this->party . '_name AS party_name,
                issue_date, storage_date, net, vat, gross, currency, invoice_hash, CASE WHEN xml IS NULL THEN 0 ELSE 1 END AS has_xml FROM ' . $this->table . ' WHERE ' . $where
            . ' ORDER BY issue_date DESC, storage_date DESC, ksef_number DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset));
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Liczba faktur i sumy kwot dla tych samych filtrów co latest(), osobno dla waluty.
     * @return array{count:int,sums:array<string,array{net:float,vat:float,gross:float}>}
     */
    public function totals(string $env, string $q = '', string $month = ''): array
    {
        [$where, $args] = $this->filter($env, $q, $month);
        $st = $this->pdo->prepare('SELECT currency, COUNT(*) AS n, SUM(net) AS net, SUM(vat) AS vat, SUM(gross) AS gross FROM ' . $this->table . ' WHERE '
            . $where . ' GROUP BY currency ORDER BY currency');
        $st->execute($args);
        $count = 0;
        $sums = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $count += (int) $r['n'];
            $sums[(string) ($r['currency'] ?: 'PLN')] = ['net' => (float) $r['net'], 'vat' => (float) $r['vat'], 'gross' => (float) $r['gross']];
        }
        return ['count' => $count, 'sums' => $sums];
    }

    /**
     * Miesiące wystawienia, w których są faktury, od najnowszego.
     * @return array<string,int> RRRR-MM => liczba faktur
     */
    public function months(string $env): array
    {
        $st = $this->pdo->prepare('SELECT SUBSTR(issue_date, 1, 7) AS m, COUNT(*) AS n FROM ' . $this->table . ' WHERE env = ? GROUP BY SUBSTR(issue_date, 1, 7) ORDER BY m DESC');
        $st->execute([$env]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (preg_match('/^\d{4}-\d{2}$/', (string) $r['m'])) {
                $out[(string) $r['m']] = (int) $r['n'];
            }
        }
        return $out;
    }

    /** @return array{0:string,1:list<string>} */
    private function filter(string $env, string $q, string $month): array
    {
        $sql = 'env = ?';
        $args = [$env];
        $q = trim($q);
        if ($q !== '') {
            $sql .= ' AND (invoice_number LIKE ? OR ' . $this->party . '_name LIKE ? OR ' . $this->party . '_nip LIKE ? OR ksef_number LIKE ?)';
            $like = '%' . $q . '%';
            array_push($args, $like, $like, $like, $like);
        }
        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            $sql .= ' AND issue_date LIKE ?';
            $args[] = $month . '-%';
        }
        return [$sql, $args];
    }

    public function syncPoint(string $env): ?string
    {
        $st = $this->pdo->prepare('SELECT value FROM ext_ksef_sync WHERE name = ?');
        $st->execute([$this->syncName($env)]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public function setSyncPoint(string $env, string $value): void
    {
        $name = $this->syncName($env);
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

    private function syncName(string $env): string
    {
        return ($this->kind === self::SALE ? 'sales_' : 'purchases_') . $env;
    }

    /** NIP kontrahenta z metadanych: sprzedawca ma pole nip, nabywca identyfikator {type, value}. */
    private function partyId(array $m): string
    {
        $p = (array) ($m[$this->party] ?? []);
        return (string) ($p['nip'] ?? $p['identifier']['value'] ?? '');
    }
}
