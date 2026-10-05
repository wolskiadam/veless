<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Support\AllegroOrderMapper;

/**
 * Marża: koszt zakupu produktów, koszt zapisany na pozycjach zamówień i opłaty Allegro przypisane do zamówień.
 *
 * Koszt zakupu (netto, PLN) wpisuje się przy produkcie: products.purchase_cost; NULL = nie ustawiono
 * (to NIE jest koszt zero — takie pozycje nie wchodzą do marży i są pokazywane osobno).
 *
 * Migawka: gdy zamówienie trafia do CRM, jego pozycje zapisujemy w margin_lines z kosztem z tej chwili
 * (Scheduler co minutę + wejście na statystyki). Późniejsza zmiana kosztu nie przepisuje historii.
 * Lokalna edycja pozycji w CRM (local_items) odświeża migawkę; produkty, które już w niej były,
 * zachowują swój koszt, nowe dostają bieżący.
 * Wyjątek: pozycja, która w chwili zamówienia nie miała kosztu, dostaje go, gdy koszt zostanie wpisany
 * (cost_source = 'later'), bo inaczej nigdy nie weszłaby do marży. „Przelicz od nowa” świadomie
 * nadpisuje wszystkie migawki bieżącymi kosztami.
 *
 * Kwoty netto: koszt zakupu jest netto, więc przychód też. Pozycja z podatkiem (WooCommerce z włączonymi
 * podatkami) ma netto w 'total'; bez podatku (Allegro, sklep bez podatków) cena jest brutto i dzielimy
 * ją przez domyślną stawkę VAT z integracji wFirma (23%, gdy jej nie ma). Dostawa nie wchodzi do marży.
 *
 * Opłaty Allegro: wpisy rozliczeń z numerem zamówienia (prowizja, opłaty za promowanie przy sprzedaży)
 * zapisujemy przy odświeżaniu Dashboardu Allegro. Liczymy grupy 'mandatory' i 'promo'; opłaty za dostawę
 * pomijamy, bo przychód z dostawy też jest poza marżą. Kwoty z Allegro są brutto, dzielimy przez 1,23.
 */
final class OrderMargins
{
    /** Statusy, które nie są sprzedażą (pieniądze nie wpłynęły albo wróciły do klienta). */
    public const NOT_SALE = ['cancelled', 'refunded'];
    /** Grupy opłat Allegro (AllegroDashboard::feeGroup) odejmowane od marży. */
    public const FEE_GROUPS = ['mandatory', 'promo'];
    public const ALLEGRO_FEE_VAT = 23;
    public const FEES_BACKFILL_KEY = 'margin_fees_backfilled';

    private ?int $vat;

    public function __construct(private readonly PDO $pdo, ?int $defaultVat = null)
    {
        $this->vat = $defaultVat;
    }

    public static function migrate(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $tail = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        if (self::tableExists($pdo, 'products')) {
            if (!self::columnExists($pdo, 'products', 'purchase_cost')) {
                $pdo->exec('ALTER TABLE products ADD COLUMN purchase_cost DECIMAL(12,2) NULL');
            }
            if (!self::columnExists($pdo, 'products', 'purchase_cost_at')) {
                $pdo->exec('ALTER TABLE products ADD COLUMN purchase_cost_at VARCHAR(30) NULL');
            }
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS margin_orders (
            woo_order_id BIGINT NOT NULL PRIMARY KEY,
            allegro_order_id VARCHAR(64) NULL,
            src_edited VARCHAR(30) NULL,
            snapshot_at VARCHAR(30) NOT NULL
        )$tail");
        $pdo->exec("CREATE TABLE IF NOT EXISTS margin_lines (
            woo_order_id BIGINT NOT NULL,
            line_no INT NOT NULL,
            product_id BIGINT NULL,
            sku VARCHAR(191) NULL,
            name VARCHAR(255) NULL,
            qty INT NOT NULL DEFAULT 1,
            revenue_net DECIMAL(12,2) NOT NULL DEFAULT 0,
            unit_cost DECIMAL(12,2) NULL,
            cost_source VARCHAR(8) NULL,
            PRIMARY KEY (woo_order_id, line_no)
        )$tail");
        $pdo->exec("CREATE TABLE IF NOT EXISTS allegro_order_fees (
            entry_id VARCHAR(80) NOT NULL PRIMARY KEY,
            allegro_order_id VARCHAR(64) NOT NULL,
            type_id VARCHAR(16) NULL,
            type_name VARCHAR(190) NULL,
            fee_group VARCHAR(16) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            occurred_at VARCHAR(30) NULL
        )$tail");
        $indexes = [
            'idx_mlines_product' => 'margin_lines (product_id)',
            'idx_morders_allegro' => 'margin_orders (allegro_order_id)',
            'idx_afees_order' => 'allegro_order_fees (allegro_order_id)',
        ];
        foreach ($indexes as $name => $on) {
            if ($driver === 'mysql') {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
                $stmt->execute([strtok($on, ' '), $name]);
                if ((int) $stmt->fetchColumn() === 0) {
                    $pdo->exec("CREATE INDEX $name ON $on");
                }
            } else {
                $pdo->exec("CREATE INDEX IF NOT EXISTS $name ON $on");
            }
        }
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        }
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return in_array($column, array_column($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    // ------------------------------------------------------------
    //  Koszt zakupu produktu
    // ------------------------------------------------------------

    /** „12,50”, „12.5 zł”, „1 234,00” → 12.5; puste → null (brak kosztu); śmieci/ujemne → false. */
    public static function parseCost(mixed $raw): float|null|false
    {
        $s = trim(str_replace(["\u{00A0}", ' ', 'zł', 'PLN', 'pln'], '', (string) $raw));
        if ($s === '') {
            return null;
        }
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = str_replace('.', '', $s);   // 1.234,50
        }
        $s = str_replace(',', '.', $s);
        if (!preg_match('/^\d+(\.\d{1,4})?$/', $s)) {
            return false;
        }
        return round((float) $s, 2);
    }

    /**
     * Zapisuje koszty wielu produktów (id => koszt albo null). Zmienia tylko te, które się różnią.
     * Nowy koszt uzupełnia pozycje zamówień, które wcześniej nie miały kosztu.
     * @param array<int,float|null> $costs
     */
    public function setCosts(array $costs): int
    {
        if ($costs === []) {
            return 0;
        }
        $get = $this->pdo->prepare('SELECT purchase_cost FROM products WHERE id = ?');
        $set = $this->pdo->prepare('UPDATE products SET purchase_cost = ?, purchase_cost_at = ? WHERE id = ?');
        $changed = 0;
        foreach ($costs as $id => $cost) {
            $get->execute([(int) $id]);
            $cur = $get->fetchColumn();
            if ($cur === false) {
                continue;
            }
            $old = $cur === null ? null : round((float) $cur, 2);
            $new = $cost === null ? null : round($cost, 2);
            if ($old === $new) {
                continue;
            }
            $set->execute([$new === null ? null : number_format($new, 2, '.', ''), self::now(), (int) $id]);
            $changed++;
        }
        if ($changed > 0) {
            $this->fillMissingCosts();
        }
        return $changed;
    }

    /**
     * Import CSV: kolumny SKU i koszt (średnik, przecinek albo tabulator; nagłówek opcjonalny).
     * Pusta komórka kosztu = bez zmian (import nie kasuje kosztów).
     * @return array{updated:int,unchanged:int,unknown:string[],invalid:string[]}
     */
    public function importCsv(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $first = '';
        foreach ($lines as $l) {
            if (trim($l) !== '') { $first = $l; break; }
        }
        $sep = ';';
        foreach (["\t", ';', ','] as $cand) {
            if (str_contains($first, $cand)) { $sep = $cand; break; }
        }
        $skuCol = 0; $costCol = 1;
        $find = $this->pdo->prepare('SELECT id FROM products WHERE sku = ? LIMIT 1');
        $costs = []; $unknown = []; $invalid = [];
        $headerChecked = false;
        foreach ($lines as $n => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = array_map('trim', str_getcsv($line, $sep, '"', ''));
            if (!$headerChecked) {
                $headerChecked = true;
                $lower = array_map(static fn($c) => mb_strtolower($c), $cells);
                $skuIdx = array_search('sku', $lower, true);
                $costIdx = null;
                foreach ($lower as $i => $c) {
                    if (preg_match('/koszt|cost|zakup/u', $c)) { $costIdx = $i; break; }
                }
                if ($skuIdx !== false || $costIdx !== null) {
                    $skuCol = $skuIdx !== false ? (int) $skuIdx : 0;
                    $costCol = $costIdx ?? ($skuCol === 0 ? 1 : 0);
                    continue;
                }
            }
            $sku = (string) ($cells[$skuCol] ?? '');
            $rawCost = (string) ($cells[$costCol] ?? '');
            if ($sku === '') {
                continue;
            }
            $cost = self::parseCost($rawCost);
            if ($cost === null) {
                continue;
            }
            if ($cost === false) {
                $invalid[] = 'wiersz ' . ($n + 1) . ': ' . $sku . ' „' . $rawCost . '”';
                continue;
            }
            $find->execute([$sku]);
            $id = $find->fetchColumn();
            if ($id === false) {
                $unknown[] = $sku;
                continue;
            }
            $costs[(int) $id] = $cost;
        }
        $updated = $this->setCosts($costs);
        return ['updated' => $updated, 'unchanged' => count($costs) - $updated, 'unknown' => $unknown, 'invalid' => $invalid];
    }

    /** CSV do edycji w arkuszu: SKU;Nazwa;Koszt zakupu netto. */
    public function exportCsv(bool $onlyMissing = false): string
    {
        $out = "\xEF\xBB\xBFSKU;Nazwa;Koszt zakupu netto\n";
        $sql = 'SELECT sku, name, purchase_cost FROM products' . ($onlyMissing ? ' WHERE purchase_cost IS NULL' : '') . ' ORDER BY sku';
        foreach ($this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cells = [(string) $r['sku'], (string) ($r['name'] ?? ''),
                $r['purchase_cost'] === null ? '' : number_format((float) $r['purchase_cost'], 2, ',', '')];
            $out .= implode(';', array_map(static fn($c) => preg_match('/[;"\n]/', $c) ? '"' . str_replace('"', '""', $c) . '"' : $c, $cells)) . "\n";
        }
        return $out;
    }

    /** @return array{total:int,with_cost:int} */
    public function coverage(): array
    {
        $r = $this->pdo->query('SELECT COUNT(*) AS t, SUM(CASE WHEN purchase_cost IS NOT NULL THEN 1 ELSE 0 END) AS c FROM products')->fetch(PDO::FETCH_ASSOC);
        return ['total' => (int) ($r['t'] ?? 0), 'with_cost' => (int) ($r['c'] ?? 0)];
    }

    /**
     * Produkty sprzedane od $since bez kosztu zakupu, od największej sprzedaży — co uzupełnić najpierw.
     * @return array<int,array{product_id:?int,sku:?string,name:?string,qty:int,revenue:float}>
     */
    public function soldWithoutCost(string $since, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT ml.product_id, MAX(ml.sku) AS sku, MAX(ml.name) AS name, SUM(ml.qty) AS qty, SUM(ml.revenue_net) AS revenue
             FROM margin_lines ml JOIN woo_orders wo ON wo.woo_order_id = ml.woo_order_id
             WHERE ml.unit_cost IS NULL AND wo.date_created >= ? AND wo.lifecycle <> 'trashed'
               AND (wo.pase_status IS NULL OR wo.pase_status NOT IN ('" . implode("','", self::NOT_SALE) . "'))
             GROUP BY ml.product_id, CASE WHEN ml.product_id IS NULL THEN ml.sku ELSE '' END
             ORDER BY revenue DESC LIMIT " . max(1, $limit)
        );
        $stmt->execute([$since]);
        return array_map(static fn($r) => [
            'product_id' => $r['product_id'] === null ? null : (int) $r['product_id'],
            'sku' => $r['sku'], 'name' => $r['name'], 'qty' => (int) $r['qty'], 'revenue' => round((float) $r['revenue'], 2),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ------------------------------------------------------------
    //  Migawki kosztu na pozycjach zamówień
    // ------------------------------------------------------------

    public function defaultVat(): int
    {
        if ($this->vat === null) {
            $this->vat = 23;
            try {
                $acc = (new \Pase\Repository\IntegrationAccountRepository($this->pdo))->firstActive('wfirma');
                $v = $acc['config']['default_vat'] ?? null;
                if (is_numeric($v)) {
                    $this->vat = max(0, (int) $v);
                } elseif ($v !== null && $v !== '') {
                    $this->vat = 0;   // „zw”, „np” — sprzedaż bez VAT, cena brutto = netto
                }
            } catch (\Throwable) {
            }
        }
        return $this->vat;
    }

    /** Przychód netto pozycji w formacie WooCommerce (także Allegro po AllegroOrderMapper). */
    public function lineNet(array $li, bool $orderTaxed = false): float
    {
        $total = (float) ($li['total'] ?? 0);
        $tax = isset($li['total_tax']) && is_numeric($li['total_tax']) ? (float) $li['total_tax'] : 0.0;
        // $orderTaxed: pozycja z lokalnej edycji (bez total_tax) w zamówieniu, którego ceny sklep podał netto.
        if ($tax > 0 || ($orderTaxed && !isset($li['total_tax']))) {
            return round($total, 2);
        }
        return round($total / (1 + $this->defaultVat() / 100), 2);
    }

    /** Liczba zamówień bez migawki (nie liczymy kosza). */
    public function pendingCount(): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM woo_orders wo LEFT JOIN margin_orders mo ON mo.woo_order_id = wo.woo_order_id
             WHERE (mo.woo_order_id IS NULL OR COALESCE(wo.local_edited_at, '') <> COALESCE(mo.src_edited, '')) AND wo.lifecycle <> 'trashed'"
        )->fetchColumn();
    }

    /**
     * Zapisuje migawki zamówień, które ich jeszcze nie mają, z bieżącym kosztem produktów.
     * @return int liczba zapisanych zamówień
     */
    public function syncPending(int $limit = 500): int
    {
        $stmt = $this->pdo->query(
            "SELECT wo.woo_order_id, wo.payload, wo.local_items, wo.local_edited_at FROM woo_orders wo
             LEFT JOIN margin_orders mo ON mo.woo_order_id = wo.woo_order_id
             WHERE (mo.woo_order_id IS NULL OR COALESCE(wo.local_edited_at, '') <> COALESCE(mo.src_edited, '')) AND wo.lifecycle <> 'trashed'
             ORDER BY wo.woo_order_id LIMIT " . max(1, $limit)
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $this->snapshot((int) $r['woo_order_id'], (string) $r['payload'], $r['local_items'] ?? null, $r['local_edited_at'] ?? null);
        }
        if ($rows !== []) {
            $this->fillMissingCosts();
        }
        return count($rows);
    }

    /** „Przelicz od nowa”: wszystkie migawki z bieżącymi kosztami (nadpisuje historię — tylko na życzenie). */
    public function rebuildAll(): int
    {
        $this->pdo->exec('DELETE FROM margin_lines');
        $this->pdo->exec('DELETE FROM margin_orders');
        $n = 0;
        $rounds = intdiv((int) $this->pdo->query('SELECT COUNT(*) FROM woo_orders')->fetchColumn(), 1000) + 2;
        do {
            $done = $this->syncPending(1000);
            $n += $done;
        } while ($done > 0 && --$rounds > 0);
        return $n;
    }

    private function snapshot(int $wooOrderId, string $payloadJson, ?string $localItems = null, ?string $localEditedAt = null): void
    {
        $payload = json_decode($payloadJson, true);
        $payload = is_array($payload) ? $payload : [];
        $isAllegro = isset($payload['lineItems']);
        if ($isAllegro && !isset($payload['line_items'])) {
            $payload = AllegroOrderMapper::withWooFields($payload);
        }
        $allegroId = $isAllegro && isset($payload['id']) ? (string) $payload['id'] : null;
        $orderTaxed = false;
        foreach ((array) ($payload['line_items'] ?? []) as $li) {
            $orderTaxed = $orderTaxed || (is_array($li) && is_numeric($li['total_tax'] ?? null) && (float) $li['total_tax'] > 0);
        }
        $items = $payload['line_items'] ?? [];
        if ($localItems !== null && $localItems !== '') {
            $decoded = json_decode($localItems, true);
            $items = is_array($decoded) ? $decoded : $items;
        }

        // Koszt z poprzedniej migawki (lokalna edycja) - produkty, które już były w zamówieniu, go zachowują.
        $kept = [];
        $old = $this->pdo->prepare('SELECT product_id, unit_cost, cost_source FROM margin_lines WHERE woo_order_id = ? AND product_id IS NOT NULL AND unit_cost IS NOT NULL');
        $old->execute([$wooOrderId]);
        foreach ($old->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $kept[(int) $o['product_id']] = [(float) $o['unit_cost'], (string) $o['cost_source']];
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM margin_lines WHERE woo_order_id = ?')->execute([$wooOrderId]);
            $this->pdo->prepare('DELETE FROM margin_orders WHERE woo_order_id = ?')->execute([$wooOrderId]);
            $ins = $this->pdo->prepare(
                'INSERT INTO margin_lines (woo_order_id, line_no, product_id, sku, name, qty, revenue_net, unit_cost, cost_source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach (array_values(is_array($items) ? $items : []) as $i => $li) {
                if (!is_array($li) || (int) ($li['quantity'] ?? 1) <= 0) {
                    continue;
                }
                $product = $this->matchProduct($li, $isAllegro);
                $cost = $product !== null && $product['purchase_cost'] !== null ? (float) $product['purchase_cost'] : null;
                $source = $cost === null ? null : 'order';
                if ($product !== null && isset($kept[$product['id']])) {
                    [$cost, $source] = $kept[$product['id']];
                }
                $sku = trim((string) ($li['sku'] ?? '')) ?: ($product['sku'] ?? null);
                $ins->execute([
                    $wooOrderId, $i, $product['id'] ?? null,
                    $sku !== null ? mb_substr((string) $sku, 0, 191) : null,
                    mb_substr((string) ($li['name'] ?? $product['name'] ?? ''), 0, 255),
                    max(1, (int) ($li['quantity'] ?? 1)),
                    number_format($this->lineNet($li, $orderTaxed), 2, '.', ''),
                    $cost === null ? null : number_format($cost, 2, '.', ''),
                    $source,
                ]);
            }
            $this->pdo->prepare('INSERT INTO margin_orders (woo_order_id, allegro_order_id, src_edited, snapshot_at) VALUES (?, ?, ?, ?)')
                ->execute([$wooOrderId, $allegroId, $localEditedAt, self::now()]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Produkt z magazynu dla pozycji. Allegro (po AllegroOrderMapper) ma product_id = id w CRM;
     * WooCommerce ma product_id/variation_id ze sklepu, więc najpierw SKU, potem ID wariantu i produktu.
     * @return array{id:int,sku:string,name:?string,purchase_cost:mixed}|null
     */
    private function matchProduct(array $li, bool $isAllegro): ?array
    {
        $q = static function (PDO $pdo, string $where, array $args): ?array {
            $s = $pdo->prepare("SELECT id, sku, name, purchase_cost FROM products WHERE $where LIMIT 1");
            $s->execute($args);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            return $r ? ['id' => (int) $r['id']] + $r : null;
        };
        $pid = (int) ($li['product_id'] ?? 0);
        if ($isAllegro && $pid > 0 && ($r = $q($this->pdo, 'id = ?', [$pid]))) {
            return $r;
        }
        $sku = trim((string) ($li['sku'] ?? ''));
        if ($sku !== '' && ($r = $q($this->pdo, 'sku = ?', [$sku]))) {
            return $r;
        }
        if ($isAllegro) {
            return null;
        }
        $vid = (int) ($li['variation_id'] ?? 0);
        if ($vid > 0) {
            return $q($this->pdo, 'woo_variation_id = ?', [$vid]);
        }
        if ($pid > 0) {
            return $q($this->pdo, 'woo_product_id = ? AND (woo_variation_id IS NULL OR woo_variation_id = 0)', [$pid]);
        }
        return null;
    }

    /** Pozycje bez kosztu dostają koszt produktu, gdy został wpisany później. */
    public function fillMissingCosts(): int
    {
        return $this->pdo->exec(
            "UPDATE margin_lines SET cost_source = 'later',
                unit_cost = (SELECT p.purchase_cost FROM products p WHERE p.id = margin_lines.product_id)
             WHERE unit_cost IS NULL AND product_id IS NOT NULL
               AND EXISTS (SELECT 1 FROM products p WHERE p.id = margin_lines.product_id AND p.purchase_cost IS NOT NULL)"
        ) ?: 0;
    }

    // ------------------------------------------------------------
    //  Opłaty Allegro przypisane do zamówień
    // ------------------------------------------------------------

    /**
     * Zapisuje wpisy rozliczeń Allegro z numerem zamówienia (idempotentnie, po id wpisu).
     * @param array<int,array<string,mixed>> $entries z GET /billing/billing-entries
     * @param string[] $adsTypes rodzaje opłat oznaczone w panelu Allegro Ads jako reklama
     */
    public function saveAllegroFees(array $entries, array $adsTypes = []): int
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'mysql'
            ? 'INSERT INTO allegro_order_fees (entry_id, allegro_order_id, type_id, type_name, fee_group, amount, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE allegro_order_id = VALUES(allegro_order_id), type_id = VALUES(type_id), type_name = VALUES(type_name),
                   fee_group = VALUES(fee_group), amount = VALUES(amount), occurred_at = VALUES(occurred_at)'
            : 'INSERT OR REPLACE INTO allegro_order_fees (entry_id, allegro_order_id, type_id, type_name, fee_group, amount, occurred_at) VALUES (?, ?, ?, ?, ?, ?, ?)';
        $ins = $this->pdo->prepare($sql);
        $n = 0;
        foreach ($entries as $e) {
            $orderId = (string) ($e['order']['id'] ?? '');
            $entryId = (string) ($e['id'] ?? '');
            if ($orderId === '' || $entryId === '') {
                continue;
            }
            $typeId = (string) ($e['type']['id'] ?? '');
            $typeName = (string) ($e['type']['name'] ?? $typeId);
            $group = AllegroDashboard::feeGroup($typeId, $typeName, $adsTypes);
            if ($group === null || (string) ($e['value']['currency'] ?? 'PLN') !== 'PLN') {
                continue;
            }
            $ins->execute([mb_substr($entryId, 0, 80), mb_substr($orderId, 0, 64), mb_substr($typeId, 0, 16), mb_substr($typeName, 0, 190),
                $group, number_format((float) ($e['value']['amount'] ?? 0), 2, '.', ''), (string) ($e['occurredAt'] ?? '')]);
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------
    //  Raport
    // ------------------------------------------------------------

    /**
     * Marża w okresie dla zamówień w PLN. Filtry jak na stronie statystyk: source = typ integracji, status = pase_status.
     * Marża liczy się tylko z pozycji z kosztem; opłaty Allegro zamówienia dzielimy proporcjonalnie do udziału
     * pozycji z kosztem w jego przychodzie, żeby nie odejmować opłat od sprzedaży, której marży nie znamy.
     * @return array<string,mixed>
     */
    public function report(string $from, string $to, string $source = '', string $status = ''): array
    {
        $where = ["wo.lifecycle <> 'trashed'", 'wo.date_created >= ?', 'wo.date_created <= ?', "(wo.currency = 'PLN' OR wo.currency IS NULL OR wo.currency = '')",
            "(wo.pase_status IS NULL OR wo.pase_status NOT IN ('" . implode("','", self::NOT_SALE) . "'))"];
        $args = [$from . ' 00:00:00', $to . ' 23:59:59'];
        if ($source !== '') {
            $where[] = 'ia.type = ?';
            $args[] = $source;
        }
        if ($status !== '') {
            $where[] = 'wo.pase_status = ?';
            $args[] = $status;
        }
        $stmt = $this->pdo->prepare(
            'SELECT ml.woo_order_id, ml.product_id, ml.sku, ml.name, ml.qty, ml.revenue_net, ml.unit_cost,
                    wo.date_created, wo.integration_id, ia.type AS channel_type, ia.name AS channel_name, mo.allegro_order_id
             FROM margin_lines ml
             JOIN margin_orders mo ON mo.woo_order_id = ml.woo_order_id
             JOIN woo_orders wo ON wo.woo_order_id = ml.woo_order_id
             LEFT JOIN integration_accounts ia ON ia.id = wo.integration_id
             WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($args);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Opłaty Allegro per zamówienie (netto, jako dodatni koszt).
        $fees = [];
        $allegroIds = array_values(array_unique(array_filter(array_column($lines, 'allegro_order_id'))));
        foreach (array_chunk($allegroIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $groups = "'" . implode("','", self::FEE_GROUPS) . "'";
            $f = $this->pdo->prepare("SELECT allegro_order_id, SUM(amount) AS s FROM allegro_order_fees
                WHERE allegro_order_id IN ($in) AND fee_group IN ($groups) GROUP BY allegro_order_id");
            $f->execute($chunk);
            foreach ($f->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $fees[(string) $r['allegro_order_id']] = round(-(float) $r['s'] / (1 + self::ALLEGRO_FEE_VAT / 100), 2);
            }
        }

        $days = (strtotime($to) - strtotime($from)) / 86400;
        $periodFmt = $days > 62 ? 'Y-m' : 'Y-m-d';

        $blank = static fn(): array => ['revenue' => 0.0, 'revenue_costed' => 0.0, 'cost' => 0.0, 'fees' => 0.0, 'fees_costed' => 0.0, 'qty' => 0, 'orders' => []];
        $total = $blank();
        $byProduct = []; $byChannel = []; $byPeriod = [];
        $orderRev = []; $orderCosted = [];
        foreach ($lines as $l) {
            $oid = (int) $l['woo_order_id'];
            $orderRev[$oid] = ($orderRev[$oid] ?? 0) + (float) $l['revenue_net'];
            if ($l['unit_cost'] !== null) {
                $orderCosted[$oid] = ($orderCosted[$oid] ?? 0) + (float) $l['revenue_net'];
            }
        }
        $feeSeen = [];
        foreach ($lines as $l) {
            $oid = (int) $l['woo_order_id'];
            $rev = (float) $l['revenue_net'];
            $hasCost = $l['unit_cost'] !== null;
            $cost = $hasCost ? (float) $l['unit_cost'] * (int) $l['qty'] : 0.0;
            $pKey = $l['product_id'] !== null ? 'p' . $l['product_id'] : 's' . ($l['sku'] ?? '') . '|' . $l['name'];
            $cKey = $l['integration_id'] !== null ? 'int:' . $l['integration_id'] : 'none';
            $period = date($periodFmt, strtotime((string) $l['date_created']));
            $byProduct[$pKey] ??= $blank() + ['product_id' => $l['product_id'] === null ? null : (int) $l['product_id'], 'sku' => $l['sku'], 'name' => $l['name'], 'missing' => 0];
            $byChannel[$cKey] ??= $blank() + ['label' => $l['channel_name'] ?: ($l['channel_type'] ?: 'Bez kanału')];
            $byPeriod[$period] ??= $blank();
            foreach ([&$total, &$byProduct[$pKey], &$byChannel[$cKey], &$byPeriod[$period]] as &$b) {
                $b['revenue'] += $rev;
                $b['qty'] += (int) $l['qty'];
                $b['orders'][$oid] = true;
                if ($hasCost) {
                    $b['revenue_costed'] += $rev;
                    $b['cost'] += $cost;
                }
            }
            unset($b);
            if (!$hasCost) {
                $byProduct[$pKey]['missing'] += (int) $l['qty'];
            }
            // Opłata zamówienia — raz na zamówienie (do sumy, kanału i okresu; produktom nie przypisujemy).
            $aid = (string) ($l['allegro_order_id'] ?? '');
            if ($aid !== '' && isset($fees[$aid]) && !isset($feeSeen[$oid])) {
                $feeSeen[$oid] = true;
                $fee = $fees[$aid];
                $share = ($orderRev[$oid] ?? 0) > 0 ? ($orderCosted[$oid] ?? 0) / $orderRev[$oid] : 0.0;
                foreach ([&$total, &$byChannel[$cKey], &$byPeriod[$period]] as &$b) {
                    $b['fees'] += $fee;
                    $b['fees_costed'] += $fee * $share;
                }
                unset($b);
            }
        }

        $finish = static function (array $b): array {
            $b['orders'] = count($b['orders']);
            foreach (['revenue', 'revenue_costed', 'cost', 'fees', 'fees_costed'] as $k) {
                $b[$k] = round($b[$k], 2);
            }
            $b['margin'] = round($b['revenue_costed'] - $b['cost'], 2);
            $b['margin_pct'] = $b['revenue_costed'] > 0 ? round($b['margin'] / $b['revenue_costed'] * 100, 1) : null;
            $b['margin_after_fees'] = round($b['margin'] - $b['fees_costed'], 2);
            $b['margin_after_fees_pct'] = $b['revenue_costed'] > 0 ? round($b['margin_after_fees'] / $b['revenue_costed'] * 100, 1) : null;
            $b['coverage_pct'] = $b['revenue'] > 0 ? round($b['revenue_costed'] / $b['revenue'] * 100, 1) : null;
            return $b;
        };
        $byProduct = array_map($finish, $byProduct);
        uasort($byProduct, static fn($a, $b) => $b['revenue'] <=> $a['revenue']);
        $byChannel = array_map($finish, $byChannel);
        uasort($byChannel, static fn($a, $b) => $b['revenue'] <=> $a['revenue']);
        $byPeriod = array_map($finish, $byPeriod);
        ksort($byPeriod);

        return [
            'total' => $finish($total),
            'products' => array_values($byProduct),
            'channels' => $byChannel,
            'periods' => $byPeriod,
            'period_format' => $periodFmt,
            'vat' => $this->defaultVat(),
        ];
    }
}
