<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;

/**
 * Allegro Ads w CRM.
 *
 * Allegro nie udostępnia publicznego API Allegro Ads dla sprzedawców (tylko kontom z listy
 * partnerów), więc kampanii nie da się tworzyć ani zmieniać z CRM. Panel składa to, co jest dostępne:
 *   - koszty Ads z rozliczeń Allegro (GET /billing/billing-entries, rodzaje opłat wybiera użytkownik),
 *   - statystyki kampanii z pliku CSV wyeksportowanego z panelu Allegro Ads (import tutaj),
 *   - własny miesięczny budżet i jego wykorzystanie.
 */
final class AllegroAds
{
    public const PANEL_URL        = 'https://ads.allegro.pl/';
    public const BUDGET_KEY       = 'allegro_ads_budget';
    public const TYPES_KEY        = 'allegro_ads_billing_types';
    public const PROBE_KEY        = 'allegro_ads_api_probe';

    /** Kolumny statystyk → fragmenty nagłówków z eksportu (PL/EN), sprawdzane po normalizacji. */
    private const COLUMNS = [
        'day'         => ['data', 'dzien', 'date', 'day'],
        'campaign'    => ['kampania', 'nazwa kampanii', 'campaign'],
        'ad_group'    => ['grupa reklam', 'grupa', 'ad group', 'adgroup'],
        'impressions' => ['wyswietlenia', 'odslony', 'impressions', 'views'],
        'clicks'      => ['klikniecia', 'clicks'],
        'cost'        => ['koszt', 'wydatki', 'cost', 'spend'],
        'sales_value' => ['wartosc sprzedazy', 'przychod', 'sprzedaz (zl)', 'sales value', 'revenue', 'gmv'],
        'sold_items'  => ['liczba sprzedanych', 'sprzedane sztuki', 'sztuki', 'transakcje', 'konwersje', 'items sold', 'conversions', 'orders'],
    ];

    public function __construct(private readonly PDO $pdo) {}

    public static function migrate(PDO $pdo): void
    {
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        $pdo->exec("CREATE TABLE IF NOT EXISTS allegro_ads_imports (
            id $id, file_name VARCHAR(255) NOT NULL, period_from DATE NOT NULL, period_to DATE NOT NULL,
            rows_count INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL
        )$tail");
        $pdo->exec("CREATE TABLE IF NOT EXISTS allegro_ads_stats (
            id $id, import_id BIGINT NOT NULL, day DATE NULL,
            campaign VARCHAR(255) NOT NULL, ad_group VARCHAR(255) NOT NULL DEFAULT '',
            impressions BIGINT NOT NULL DEFAULT 0, clicks BIGINT NOT NULL DEFAULT 0,
            cost DECIMAL(12,2) NOT NULL DEFAULT 0, sales_value DECIMAL(12,2) NOT NULL DEFAULT 0,
            sold_items INT NOT NULL DEFAULT 0
        )$tail");
        if ($sqlite) {
            $pdo->exec('CREATE INDEX IF NOT EXISTS allegro_ads_stats_import ON allegro_ads_stats (import_id)');
        } elseif (!$pdo->query("SHOW INDEX FROM allegro_ads_stats WHERE Key_name = 'allegro_ads_stats_import'")->fetch()) {
            $pdo->exec('CREATE INDEX allegro_ads_stats_import ON allegro_ads_stats (import_id)');
        }
    }

    // ------------------------------------------------------------
    //  Import CSV z panelu Allegro Ads
    // ------------------------------------------------------------

    /** Liczba z eksportu: „1 234,56 zł", „12,5%", „1,234.56" → float. */
    public static function number(string $v): float
    {
        $v = str_replace(["\u{00A0}", "\u{202F}", ' ', 'zł', 'PLN', '%'], '', trim($v));
        if ($v === '' || $v === '-' || $v === '—') {
            return 0.0;
        }
        if (str_contains($v, ',') && str_contains($v, '.')) {
            // Separatorem dziesiętnym jest ten, który występuje jako ostatni.
            $v = strrpos($v, ',') > strrpos($v, '.') ? str_replace(['.', ','], ['', '.'], $v) : str_replace(',', '', $v);
        } else {
            $v = str_replace(',', '.', $v);
        }
        return is_numeric($v) ? (float) $v : 0.0;
    }

    /** Data z eksportu (2026-09-01, 01.09.2026, 01/09/2026) → Y-m-d albo null. */
    public static function date(string $v): ?string
    {
        $v = trim($v);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
        }
        if (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})/', $v, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        }
        return null;
    }

    private static function normalize(string $h): string
    {
        $h = mb_strtolower(trim($h, " \t\"'\u{FEFF}"));
        return strtr($h, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
    }

    /**
     * Rozpoznaje kolumny po nagłówku. Dokładne dopasowanie wygrywa z częściowym,
     * a kolumny pochodne (CTR, średni CPC, koszt/sprzedaż) są pomijane.
     * @param string[] $header
     * @return array<string,int> pole => indeks kolumny
     */
    public static function mapHeader(array $header): array
    {
        $norm = array_map([self::class, 'normalize'], $header);
        $map = [];
        foreach (self::COLUMNS as $field => $needles) {
            foreach ([true, false] as $exact) {
                foreach ($norm as $i => $h) {
                    if (in_array($i, $map, true) || $h === '' || preg_match('/sredni|ctr|cpc|cpm|roas|acos|udzial|\/|na klik/', $h)) {
                        continue;
                    }
                    foreach ($needles as $n) {
                        if ($exact ? $h === $n : str_contains($h, $n)) {
                            $map[$field] = $i;
                            continue 4;
                        }
                    }
                }
            }
        }
        return $map;
    }

    /**
     * Parsuje CSV z panelu Allegro Ads. Szuka wiersza nagłówka (w eksporcie bywa nad nim opis raportu),
     * pomija wiersze sumy.
     * @return array{rows:array<int,array<string,mixed>>,errors:string[],columns:array<string,string>}
     */
    public static function parseCsv(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        if (!mb_check_encoding($csv, 'UTF-8')) {
            // Excel w Polsce zapisuje CSV w Windows-1250 (mbstring go nie zna, iconv tak).
            $conv = function_exists('iconv') ? @iconv('Windows-1250', 'UTF-8//IGNORE', $csv) : false;
            $csv = $conv !== false ? $conv : mb_convert_encoding($csv, 'UTF-8', 'ISO-8859-2');
        }
        $lines = preg_split('/\r\n|\n|\r/', $csv) ?: [];
        $first = '';
        foreach ($lines as $l) {
            if (trim($l) !== '') { $first = $l; break; }
        }
        $counts = [';' => substr_count($first, ';'), "\t" => substr_count($first, "\t"), ',' => substr_count($first, ',')];
        arsort($counts);
        $delim = (string) array_key_first($counts);

        $map = null;
        $header = [];
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, $delim, '"', '');
            if ($map === null) {
                $m = self::mapHeader($cells);
                if (isset($m['campaign']) && (isset($m['cost']) || isset($m['clicks']))) {
                    $map = $m;
                    $header = $cells;
                }
                continue;
            }
            $campaign = trim((string) ($cells[$map['campaign']] ?? ''));
            if ($campaign === '' || preg_match('/^(suma|razem|łącznie|total)\b/iu', $campaign)) {
                continue;
            }
            $get = static fn(string $f) => isset($map[$f]) ? (string) ($cells[$map[$f]] ?? '') : '';
            $rows[] = [
                'day'         => isset($map['day']) ? self::date($get('day')) : null,
                'campaign'    => mb_substr($campaign, 0, 255),
                'ad_group'    => mb_substr(trim($get('ad_group')), 0, 255),
                'impressions' => (int) round(self::number($get('impressions'))),
                'clicks'      => (int) round(self::number($get('clicks'))),
                'cost'        => round(self::number($get('cost')), 2),
                'sales_value' => round(self::number($get('sales_value')), 2),
                'sold_items'  => (int) round(self::number($get('sold_items'))),
            ];
        }
        if ($map === null) {
            return ['rows' => [], 'errors' => ['Nie znaleziono nagłówka z kolumnami „Kampania" i „Koszt"/„Kliknięcia". Wyeksportuj raport kampanii z panelu Allegro Ads do CSV.'], 'columns' => []];
        }
        $columns = [];
        foreach ($map as $f => $i) {
            $columns[$f] = trim((string) $header[$i], " \"\u{FEFF}");
        }
        return ['rows' => $rows, 'errors' => $rows === [] ? ['Plik nie zawiera wierszy z kampaniami.'] : [], 'columns' => $columns];
    }

    /**
     * Zapisuje import. Wcześniejszy import za ten sam okres jest zastępowany, żeby ponowne
     * wgranie raportu nie dublowało statystyk.
     * @param array<int,array<string,mixed>> $rows
     */
    public function import(string $fileName, string $from, string $to, array $rows): int
    {
        $this->pdo->beginTransaction();
        try {
            $old = $this->pdo->prepare('SELECT id FROM allegro_ads_imports WHERE period_from = ? AND period_to = ?');
            $old->execute([$from, $to]);
            foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $oldId) {
                $this->deleteImportRows((int) $oldId);
            }
            $this->pdo->prepare('INSERT INTO allegro_ads_imports (file_name, period_from, period_to, rows_count, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([mb_substr($fileName, 0, 255), $from, $to, count($rows), date('Y-m-d H:i:s')]);
            $id = (int) $this->pdo->lastInsertId();
            $ins = $this->pdo->prepare('INSERT INTO allegro_ads_stats (import_id, day, campaign, ad_group, impressions, clicks, cost, sales_value, sold_items) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($rows as $r) {
                $ins->execute([$id, $r['day'], $r['campaign'], $r['ad_group'], $r['impressions'], $r['clicks'], $r['cost'], $r['sales_value'], $r['sold_items']]);
            }
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function deleteImport(int $id): void
    {
        $this->pdo->beginTransaction();
        $this->deleteImportRows($id);
        $this->pdo->commit();
    }

    private function deleteImportRows(int $id): void
    {
        $this->pdo->prepare('DELETE FROM allegro_ads_stats WHERE import_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM allegro_ads_imports WHERE id = ?')->execute([$id]);
    }

    /** @return array<int,array<string,mixed>> */
    public function imports(int $limit = 20): array
    {
        return $this->pdo->query('SELECT * FROM allegro_ads_imports ORDER BY period_from DESC, id DESC LIMIT ' . max(1, $limit))->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Statystyki kampanii w okresie: wiersze dzienne z tego okresu oraz wiersze bez daty
     * z importów, których okres zaczyna się w tym przedziale.
     * @return array<int,array<string,mixed>> posortowane po koszcie malejąco, z CTR/CPC/ROAS
     */
    public function campaignStats(string $from, string $to): array
    {
        $st = $this->pdo->prepare(
            'SELECT s.campaign, SUM(s.impressions) AS impressions, SUM(s.clicks) AS clicks, SUM(s.cost) AS cost,
                    SUM(s.sales_value) AS sales_value, SUM(s.sold_items) AS sold_items
             FROM allegro_ads_stats s JOIN allegro_ads_imports i ON i.id = s.import_id
             WHERE (s.day IS NOT NULL AND s.day BETWEEN ? AND ?) OR (s.day IS NULL AND i.period_from BETWEEN ? AND ?)
             GROUP BY s.campaign ORDER BY SUM(s.cost) DESC, s.campaign'
        );
        $st->execute([$from, $to, $from, $to]);
        return array_map([self::class, 'withRatios'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public static function withRatios(array $r): array
    {
        $imp = (int) $r['impressions']; $clk = (int) $r['clicks'];
        $cost = (float) $r['cost']; $sales = (float) $r['sales_value'];
        return [
            'campaign' => (string) ($r['campaign'] ?? ''), 'impressions' => $imp, 'clicks' => $clk,
            'cost' => $cost, 'sales_value' => $sales, 'sold_items' => (int) $r['sold_items'],
            'ctr'  => $imp > 0 ? $clk / $imp * 100 : null,
            'cpc'  => $clk > 0 ? $cost / $clk : null,
            'roas' => $cost > 0 ? $sales / $cost : null,
            'acos' => $sales > 0 ? $cost / $sales * 100 : null,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $stats
     * @return array<string,mixed>
     */
    public static function totals(array $stats): array
    {
        $sum = ['campaign' => 'Razem', 'impressions' => 0, 'clicks' => 0, 'cost' => 0.0, 'sales_value' => 0.0, 'sold_items' => 0];
        foreach ($stats as $s) {
            foreach (['impressions', 'clicks', 'cost', 'sales_value', 'sold_items'] as $k) {
                $sum[$k] += $s[$k];
            }
        }
        return self::withRatios($sum);
    }

    // ------------------------------------------------------------
    //  Koszty z rozliczeń Allegro
    // ------------------------------------------------------------

    /**
     * Rodzaje opłat z rozliczeń, które wyglądają na Allegro Ads (podpowiedź, gdy użytkownik
     * jeszcze nic nie wybrał).
     * @param array<int,array{id:string,description:string}> $types
     * @return string[]
     */
    public static function guessAdsTypes(array $types): array
    {
        $ids = [];
        foreach ($types as $t) {
            if (preg_match('/\bads\b|reklam|kampani|sponsor/iu', $t['description'] . ' ' . $t['id'])) {
                $ids[] = $t['id'];
            }
        }
        return $ids;
    }

    /**
     * Koszt Ads z operacji rozliczeń: suma, per dzień (Europe/Warsaw), per rodzaj opłaty, per oferta.
     * Opłaty w rozliczeniach mają ujemną kwotę; zwroty dodatnią — koszt = −suma.
     * @param array<int,array<string,mixed>> $entries
     * @return array{total:float,byDay:array<string,float>,byType:array<string,float>,byOffer:array<int,array{id:string,name:string,cost:float}>,currency:string}
     */
    public static function summarizeBilling(array $entries): array
    {
        $tz = new \DateTimeZone('Europe/Warsaw');
        $total = 0.0; $byDay = $byType = $offers = [];
        $currency = 'PLN';
        foreach ($entries as $e) {
            $cost = -(float) ($e['value']['amount'] ?? 0);
            $currency = (string) ($e['value']['currency'] ?? $currency);
            $total += $cost;
            try {
                $day = (new \DateTimeImmutable((string) ($e['occurredAt'] ?? 'now')))->setTimezone($tz)->format('Y-m-d');
            } catch (\Throwable) {
                $day = '?';
            }
            $byDay[$day] = ($byDay[$day] ?? 0) + $cost;
            $type = (string) ($e['type']['name'] ?? $e['type']['id'] ?? '—');
            $byType[$type] = ($byType[$type] ?? 0) + $cost;
            if (!empty($e['offer']['id'])) {
                $oid = (string) $e['offer']['id'];
                $offers[$oid] ??= ['id' => $oid, 'name' => (string) ($e['offer']['name'] ?? $oid), 'cost' => 0.0];
                $offers[$oid]['cost'] += $cost;
            }
        }
        ksort($byDay);
        arsort($byType);
        usort($offers, static fn($a, $b) => $b['cost'] <=> $a['cost']);
        return ['total' => round($total, 2), 'byDay' => $byDay, 'byType' => $byType, 'byOffer' => array_values($offers), 'currency' => $currency];
    }

    /**
     * Wykorzystanie budżetu miesięcznego i prognoza na koniec miesiąca przy obecnym tempie.
     * @return array{budget:float,spent:float,percent:?float,forecast:float,level:string}
     */
    public static function budgetUsage(float $budget, float $spent, int $dayOfMonth, int $daysInMonth): array
    {
        $forecast = $dayOfMonth > 0 ? $spent / $dayOfMonth * $daysInMonth : $spent;
        $percent = $budget > 0 ? $spent / $budget * 100 : null;
        $level = $percent === null ? 'none' : ($percent >= 100 ? 'over' : ($percent >= 80 || $forecast > $budget ? 'warn' : 'ok'));
        return ['budget' => $budget, 'spent' => $spent, 'percent' => $percent, 'forecast' => round($forecast, 2), 'level' => $level];
    }
}
