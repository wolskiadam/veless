<?php
declare(strict_types=1);

namespace PaseExt\Kontrola;

use PDO;

/**
 * Wyniki sprawdzeń (ostatni wynik każdego sprawdzenia) i rozbieżności stanów między przebiegami.
 * Własne tabele ext_kontrola_*; składnia działa w MySQL i SQLite (testy).
 */
final class Store
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ext_kontrola_results (
            check_id VARCHAR(96) NOT NULL PRIMARY KEY,
            grp VARCHAR(16) NOT NULL,
            title VARCHAR(190) NOT NULL,
            state VARCHAR(8) NOT NULL,
            data MEDIUMTEXT NOT NULL,
            since VARCHAR(20) NOT NULL,
            checked_at VARCHAR(20) NOT NULL
        )');
        // Rozbieżność stanu CRM z kanałem: first_seen pozwala zgłaszać tylko te, które trwają dłużej niż jeden przebieg
        // (zamówienie z Allegro zdejmuje stan tam od razu, a w CRM dopiero po imporcie - to nie jest błąd).
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ext_kontrola_stock (
            channel VARCHAR(32) NOT NULL,
            product_id BIGINT NOT NULL,
            ref VARCHAR(64) NOT NULL,
            crm INT NOT NULL,
            remote INT NOT NULL,
            first_seen VARCHAR(20) NOT NULL,
            last_seen VARCHAR(20) NOT NULL,
            pushed_at VARCHAR(20) NULL,
            PRIMARY KEY (channel, product_id, ref)
        )');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** Zapisuje wynik; „since” = od kiedy sprawdzenie ma ten sam stan (do „czerwone od 3 godzin”). */
    public function save(array $r, int $now): void
    {
        $at = gmdate('Y-m-d H:i:s', $now);
        $st = $this->pdo->prepare('SELECT state, since FROM ext_kontrola_results WHERE check_id = ?');
        $st->execute([$r['id']]);
        $prev = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $since = $prev !== null && $prev['state'] === $r['state'] ? (string) $prev['since'] : $at;
        $data = json_encode(array_diff_key($r, array_flip(['id', 'group', 'title', 'state'])), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->pdo->prepare('DELETE FROM ext_kontrola_results WHERE check_id = ?')->execute([$r['id']]);
        $this->pdo->prepare('INSERT INTO ext_kontrola_results (check_id, grp, title, state, data, since, checked_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$r['id'], $r['group'], mb_substr((string) $r['title'], 0, 190), $r['state'], (string) $data, $since, $at]);
    }

    /** @return array<string,array<string,mixed>> check_id => wynik (z since i checked_at) */
    public function results(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT * FROM ext_kontrola_results ORDER BY check_id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $data = json_decode((string) $row['data'], true);
            $out[(string) $row['check_id']] = ['id' => (string) $row['check_id'], 'group' => (string) $row['grp'], 'title' => (string) $row['title'],
                'state' => (string) $row['state'], 'since' => (string) $row['since'], 'checked_at' => (string) $row['checked_at']] + (is_array($data) ? $data : []);
        }
        return $out;
    }

    /** Usuwa wyniki sprawdzeń, których już nie ma (np. usunięte konto integracji). @param list<string> $keep */
    public function prune(array $keep): void
    {
        foreach (array_keys($this->results()) as $id) {
            if (!in_array($id, $keep, true)) {
                $this->pdo->prepare('DELETE FROM ext_kontrola_results WHERE check_id = ?')->execute([$id]);
            }
        }
    }

    /**
     * Zapisuje rozbieżności kanału po przebiegu: znikają te, które już się zgadzają, nowe dostają first_seen = teraz.
     * @param list<array{product_id:int,ref:string,crm:int,remote:int}> $found
     * @param list<int>|null $checkedProducts produkty sprawdzone w tym przebiegu (null = cały kanał)
     */
    public function syncStock(string $channel, array $found, ?array $checkedProducts, int $now): void
    {
        $at = gmdate('Y-m-d H:i:s', $now);
        $old = [];
        $st = $this->pdo->prepare('SELECT * FROM ext_kontrola_stock WHERE channel = ?');
        $st->execute([$channel]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $old[$row['product_id'] . '|' . $row['ref']] = $row;
        }
        $seen = [];
        foreach ($found as $f) {
            $key = $f['product_id'] . '|' . $f['ref'];
            $seen[$key] = true;
            $prev = $old[$key] ?? null;
            $this->pdo->prepare('DELETE FROM ext_kontrola_stock WHERE channel = ? AND product_id = ? AND ref = ?')->execute([$channel, $f['product_id'], $f['ref']]);
            $this->pdo->prepare('INSERT INTO ext_kontrola_stock (channel, product_id, ref, crm, remote, first_seen, last_seen, pushed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$channel, $f['product_id'], $f['ref'], $f['crm'], $f['remote'], $prev['first_seen'] ?? $at, $at, $prev['pushed_at'] ?? null]);
        }
        $checked = $checkedProducts === null ? null : array_flip($checkedProducts);
        foreach ($old as $key => $row) {
            if (!isset($seen[$key]) && ($checked === null || isset($checked[(int) $row['product_id']]))) {
                $this->pdo->prepare('DELETE FROM ext_kontrola_stock WHERE channel = ? AND product_id = ? AND ref = ?')->execute([$channel, $row['product_id'], $row['ref']]);
            }
        }
    }

    /** @return list<array<string,mixed>> rozbieżności kanału z nazwą i SKU produktu */
    public function stock(string $channel): array
    {
        $st = $this->pdo->prepare('SELECT s.*, p.sku, p.name FROM ext_kontrola_stock s LEFT JOIN products p ON p.id = s.product_id
            WHERE s.channel = ? ORDER BY s.first_seen, s.product_id');
        $st->execute([$channel]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function clearStock(string $channel): void
    {
        $this->pdo->prepare('DELETE FROM ext_kontrola_stock WHERE channel = ?')->execute([$channel]);
    }

    public function markPushed(string $channel, array $productIds, int $now): void
    {
        foreach ($productIds as $pid) {
            $this->pdo->prepare('UPDATE ext_kontrola_stock SET pushed_at = ? WHERE channel = ? AND product_id = ?')
                ->execute([gmdate('Y-m-d H:i:s', $now), $channel, (int) $pid]);
        }
    }
}
