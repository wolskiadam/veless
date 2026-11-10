<?php
declare(strict_types=1);

namespace PaseExt\Ksiegowosc;

use Pase\Plugin\ExtensionDatabase;
use PDO;

/**
 * Baza księgowości. Wszystkie tabele mają prefiks ext_ksg_ i nie są czytane ani pisane przez CRM; księgowość też nie
 * dotyka tabel CRM (wyjątek: lista użytkowników do nadania dostępu, patrz Access).
 *
 * Domyślnie tabele leżą w bazie CRM. Gdy w .env jest EXT_KSIEGOWOSC_DB_DSN (np. mysql:host=localhost;dbname=ksiegowosc;
 * charset=utf8mb4, do tego EXT_KSIEGOWOSC_DB_USER i _PASS), rdzeń (ExtensionDatabase) daje połączenie z osobną bazą -
 * to pierwszy krok do wyniesienia modułu na osobny system. Wersja schematu jest w ext_ksg_meta, nie w ustawieniach CRM, żeby baza była samowystarczalna.
 *
 * Składnia działa w MySQL i SQLite (testy). Identyfikatory to losowy tekst, kwoty to grosze (BIGINT) - bez zaokrągleń.
 */
final class Db
{
    public const SCHEMA = '5';

    /** @var array<int,bool> spl_object_id(PDO) => schemat sprawdzony */
    private static array $ready = [];

    public function __construct(public readonly PDO $pdo)
    {
        $key = spl_object_id($pdo);
        if (!isset(self::$ready[$key])) {
            $this->migrate();
            self::$ready[$key] = true;
        }
    }

    /** Połączenie księgowości: osobna baza (EXT_KSIEGOWOSC_DB_DSN w .env) albo baza CRM. */
    public static function connect(PDO $crm): self
    {
        return new self(ExtensionDatabase::connection('ksiegowosc') ?? $crm);
    }

    public static function separate(): bool
    {
        return ExtensionDatabase::configured('ksiegowosc');
    }

    public static function resetForTests(): void
    {
        ExtensionDatabase::reset();
        self::$ready = [];
    }

    /** @return array<string,string> tabela => kolumny */
    public static function tables(): array
    {
        return [
            'ext_ksg_meta' => 'k VARCHAR(64) NOT NULL PRIMARY KEY, v TEXT NOT NULL',
            'ext_ksg_access' => 'user_id INT NOT NULL PRIMARY KEY, level VARCHAR(10) NOT NULL, updated_by VARCHAR(100) NOT NULL, updated_at DATETIME NOT NULL',
            'ext_ksg_log' => 'id VARCHAR(16) NOT NULL PRIMARY KEY, created_at DATETIME NOT NULL, user_name VARCHAR(100) NOT NULL,
                action VARCHAR(40) NOT NULL, object_type VARCHAR(20) NOT NULL, object_id VARCHAR(16) NOT NULL, details TEXT NOT NULL',
            'ext_ksg_years' => 'id VARCHAR(16) NOT NULL PRIMARY KEY, name VARCHAR(40) NOT NULL, starts_on VARCHAR(10) NOT NULL, ends_on VARCHAR(10) NOT NULL,
                locked_until VARCHAR(10) NULL, status VARCHAR(10) NOT NULL, created_at DATETIME NOT NULL',
            'ext_ksg_accounts' => 'id VARCHAR(16) NOT NULL PRIMARY KEY, year_id VARCHAR(16) NOT NULL, number VARCHAR(30) NOT NULL, name VARCHAR(200) NOT NULL,
                kind VARCHAR(4) NOT NULL, active INT NOT NULL DEFAULT 1, notes TEXT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL',
            'ext_ksg_docs' => 'id VARCHAR(16) NOT NULL PRIMARY KEY, year_id VARCHAR(16) NOT NULL, type VARCHAR(4) NOT NULL, number VARCHAR(60) NOT NULL,
                journal_no INT NULL, doc_date VARCHAR(10) NOT NULL, op_date VARCHAR(10) NOT NULL, posting_date VARCHAR(10) NOT NULL,
                description VARCHAR(255) NOT NULL, counterparty VARCHAR(200) NOT NULL, contractor_id VARCHAR(16) NULL, due_date VARCHAR(10) NULL, status VARCHAR(10) NOT NULL,
                total_gr BIGINT NOT NULL DEFAULT 0, reversal_of VARCHAR(16) NULL, reversed_by VARCHAR(16) NULL,
                source VARCHAR(20) NOT NULL, source_ref VARCHAR(100) NOT NULL, created_by VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL,
                posted_by VARCHAR(100) NULL, posted_at DATETIME NULL, updated_at DATETIME NOT NULL',
            'ext_ksg_entries' => 'id VARCHAR(16) NOT NULL PRIMARY KEY, doc_id VARCHAR(16) NOT NULL, position INT NOT NULL, account_id VARCHAR(16) NOT NULL,
                debit_gr BIGINT NOT NULL DEFAULT 0, credit_gr BIGINT NOT NULL DEFAULT 0, description VARCHAR(255) NOT NULL',
            'ext_ksg_contractors' => 'id VARCHAR(16) NOT NULL PRIMARY KEY, code VARCHAR(10) NOT NULL, name VARCHAR(200) NOT NULL, nip VARCHAR(20) NOT NULL,
                country VARCHAR(2) NOT NULL, kind VARCHAR(10) NOT NULL, street VARCHAR(200) NOT NULL, postcode VARCHAR(12) NOT NULL, city VARCHAR(100) NOT NULL,
                email VARCHAR(150) NOT NULL, phone VARCHAR(40) NOT NULL, bank_account VARCHAR(40) NOT NULL, notes TEXT NOT NULL, active INT NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL',
            'ext_ksg_vat' => 'id VARCHAR(16) NOT NULL PRIMARY KEY, doc_id VARCHAR(16) NOT NULL, position INT NOT NULL, register VARCHAR(1) NOT NULL,
                period VARCHAR(7) NOT NULL, rate VARCHAR(4) NOT NULL, net_gr BIGINT NOT NULL, vat_gr BIGINT NOT NULL, kind VARCHAR(10) NOT NULL,
                deduct VARCHAR(4) NOT NULL',
            'ext_ksg_settle' => 'id VARCHAR(16) NOT NULL PRIMARY KEY, debt_entry_id VARCHAR(16) NOT NULL, pay_entry_id VARCHAR(16) NOT NULL,
                amount_gr BIGINT NOT NULL, source VARCHAR(10) NOT NULL, created_by VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL',
            'ext_ksg_bank_statements' => 'id VARCHAR(16) NOT NULL PRIMARY KEY, account VARCHAR(30) NOT NULL, iban VARCHAR(40) NOT NULL, file_name VARCHAR(200) NOT NULL,
                format VARCHAR(10) NOT NULL, date_from VARCHAR(10) NOT NULL, date_to VARCHAR(10) NOT NULL, opening_gr BIGINT NULL, closing_gr BIGINT NULL,
                status VARCHAR(10) NOT NULL, doc_id VARCHAR(16) NULL, created_by VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL',
            'ext_ksg_bank_lines' => 'id VARCHAR(16) NOT NULL PRIMARY KEY, statement_id VARCHAR(16) NOT NULL, position INT NOT NULL, op_date VARCHAR(10) NOT NULL,
                amount_gr BIGINT NOT NULL, title VARCHAR(500) NOT NULL, party_name VARCHAR(200) NOT NULL, party_iban VARCHAR(40) NOT NULL, hash VARCHAR(40) NOT NULL,
                contractor_id VARCHAR(16) NULL, account VARCHAR(30) NOT NULL, match_entry_id VARCHAR(16) NULL, status VARCHAR(10) NOT NULL',
            'ext_ksg_imports' => 'id VARCHAR(16) NOT NULL PRIMARY KEY, year_id VARCHAR(16) NOT NULL, kind VARCHAR(10) NOT NULL, period VARCHAR(7) NOT NULL,
                file_name VARCHAR(200) NOT NULL, data LONGTEXT NOT NULL, created_by VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL',
            'ext_ksg_wf_docs' => 'id VARCHAR(16) NOT NULL PRIMARY KEY, kind VARCHAR(10) NOT NULL, remote_id VARCHAR(30) NOT NULL, type VARCHAR(30) NOT NULL,
                number VARCHAR(60) NOT NULL, doc_date VARCHAR(10) NOT NULL, period VARCHAR(7) NOT NULL, due_date VARCHAR(10) NULL, name VARCHAR(200) NOT NULL,
                nip VARCHAR(30) NOT NULL, currency VARCHAR(3) NOT NULL, net_gr BIGINT NOT NULL, vat_gr BIGINT NOT NULL, gross_gr BIGINT NOT NULL, paid_gr BIGINT NOT NULL,
                doc_id VARCHAR(16) NULL, data TEXT NOT NULL, fetched_at DATETIME NOT NULL',
            'ext_ksg_inbox' => 'id VARCHAR(16) NOT NULL PRIMARY KEY, event VARCHAR(60) NOT NULL, ref_type VARCHAR(20) NOT NULL, ref_id VARCHAR(40) NOT NULL,
                payload TEXT NOT NULL, received_at DATETIME NOT NULL, processed_at DATETIME NULL, result VARCHAR(255) NOT NULL',
        ];
    }

    private function migrate(): void
    {
        if ($this->schemaVersion() === self::SCHEMA) {
            return;
        }
        foreach (self::tables() as $name => $cols) {
            $this->pdo->prepare("CREATE TABLE IF NOT EXISTS {$name} ({$cols})")->execute();
        }
        // Indeksy tylko przy pierwszym założeniu (MySQL nie zna CREATE INDEX IF NOT EXISTS).
        foreach (['ext_ksg_accounts (year_id, number)', 'ext_ksg_docs (year_id, posting_date)', 'ext_ksg_docs (year_id, journal_no)',
            'ext_ksg_entries (doc_id)', 'ext_ksg_entries (account_id)', 'ext_ksg_log (object_id)', 'ext_ksg_inbox (ref_type, ref_id)',
            'ext_ksg_vat (doc_id)', 'ext_ksg_vat (register, period)', 'ext_ksg_docs (contractor_id)', 'ext_ksg_contractors (nip)',
            'ext_ksg_settle (debt_entry_id)', 'ext_ksg_settle (pay_entry_id)', 'ext_ksg_bank_lines (statement_id)', 'ext_ksg_bank_lines (hash)', 'ext_ksg_wf_docs (kind, remote_id)', 'ext_ksg_wf_docs (doc_date)'] as $i => $idx) {
            try {
                $this->pdo->prepare('CREATE INDEX ext_ksg_idx' . $i . ' ON ' . $idx)->execute();
            } catch (\PDOException) {
                // już jest
            }
        }
        // Wersja 2: kontrahent przy dokumencie, wersja 3: termin płatności (nowa instalacja ma kolumny od razu).
        foreach (['contractor_id VARCHAR(16) NULL', 'due_date VARCHAR(10) NULL'] as $col) {
            try {
                $this->pdo->prepare('ALTER TABLE ext_ksg_docs ADD COLUMN ' . $col)->execute();
            } catch (\PDOException) {
                // już jest
            }
        }
        $this->setMeta('schema', self::SCHEMA);
    }

    private function schemaVersion(): string
    {
        try {
            $st = $this->pdo->prepare("SELECT v FROM ext_ksg_meta WHERE k = 'schema'");
            $st->execute();
            return (string) ($st->fetchColumn() ?: '');
        } catch (\PDOException) {
            return '';
        }
    }

    public function meta(string $key, string $default = ''): string
    {
        $r = $this->one('SELECT v FROM ext_ksg_meta WHERE k = ?', [$key]);
        return $r === null ? $default : (string) $r['v'];
    }

    public function setMeta(string $key, string $value): void
    {
        if ($this->run('UPDATE ext_ksg_meta SET v = ? WHERE k = ?', [$value, $key]) === 0
            && $this->one('SELECT k FROM ext_ksg_meta WHERE k = ?', [$key]) === null) {
            $this->insert('ext_ksg_meta', ['k' => $key, 'v' => $value]);
        }
    }

    public static function id(): string
    {
        return bin2hex(random_bytes(8));
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public function one(string $sql, array $args = []): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function all(string $sql, array $args = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function run(string $sql, array $args = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st->rowCount();
    }

    public function insert(string $table, array $row): void
    {
        $cols = array_keys($row);
        $this->run('INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($row));
    }

    public function update(string $table, string $id, array $row): void
    {
        $set = implode(', ', array_map(static fn(string $c): string => $c . ' = ?', array_keys($row)));
        $this->run('UPDATE ' . $table . ' SET ' . $set . ' WHERE id = ?', [...array_values($row), $id]);
    }

    public function tx(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn();
        }
        $this->pdo->beginTransaction();
        try {
            $r = $fn();
            $this->pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Wpis w dzienniku zmian księgowości (kto, kiedy, co). */
    public function log(string $user, string $action, string $type, string $id, string $details = ''): void
    {
        $this->insert('ext_ksg_log', ['id' => self::id(), 'created_at' => self::now(), 'user_name' => mb_substr($user, 0, 100),
            'action' => $action, 'object_type' => $type, 'object_id' => $id, 'details' => $details]);
    }
}
