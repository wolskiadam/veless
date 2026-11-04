<?php
declare(strict_types=1);

namespace PaseExt\Produkcja;

/**
 * Eksport do CSV (średnik, UTF-8 z BOM - otwiera się w Excelu, liczby z przecinkiem). Oba pliki da się wczytać z powrotem
 * przez Import: partie przez Importer::batchesCsv, materiały przez Importer::csv.
 */
final class Exporter
{
    public const BATCH_HEAD = ['Numer partii', 'Data produkcji', 'Produkt', 'SKU', 'Ilość', 'Ważne do', 'Osoba', 'Zmiana', 'Materiały',
        'Koszt materiałów', 'Koszt pracy', 'Godziny pracy', 'Wycena robocza', 'Zamówienia', 'Uwagi'];

    public function __construct(private readonly Store $s) {}

    /** @param array<string,mixed> $filters jak w Store::batches() */
    public function batches(array $filters): string
    {
        $skus = [];
        foreach ($this->s->all('SELECT id, sku FROM ext_prod_products') as $p) {
            $skus[$p['id']] = (string) $p['sku'];
        }
        $orders = [];
        foreach ($this->s->all('SELECT ob.batch_code, ob.order_id, o.pase_number FROM ext_prod_order_batches ob
            LEFT JOIN woo_orders o ON o.woo_order_id = ob.order_id ORDER BY ob.created_at') as $o) {
            $k = strtoupper((string) $o['batch_code']);
            $n = '#' . ((string) ($o['pase_number'] ?? '') !== '' ? $o['pase_number'] : $o['order_id']);
            if (!in_array($n, $orders[$k] ?? [], true)) {
                $orders[$k][] = $n;
            }
        }
        $rows = [];
        foreach ($this->s->batches($filters, 100000) as $b) {
            $mats = [];
            foreach (json_decode((string) $b['lines_json'], true) ?: [] as $l) {
                $lots = array_values(array_filter(array_map(static fn(array $t): string => (string) ($t['lot_no'] ?? ''), (array) ($l['taken'] ?? []))));
                $mats[] = $l['name'] . ' ' . str_replace("\u{a0}", ' ', Ui::qty($l['need'], (string) $l['unit'])) . ($lots !== [] ? ' (' . implode(', ', array_unique($lots)) . ')' : '');
            }
            $rows[] = [$b['code'], $b['produced_at'], $b['product_name'], $skus[(string) $b['product_id']] ?? '', (int) $b['qty'], (string) ($b['expires_at'] ?? ''),
                $b['person'], $b['shift'], implode('; ', $mats), self::num($b['cost_materials']), self::num($b['cost_labor']), self::num($b['work_hours']),
                (int) $b['is_draft'] === 1 ? 'tak' : '', implode(' ', $orders[strtoupper((string) $b['code'])] ?? []), $b['notes']];
        }
        return self::csv(self::BATCH_HEAD, $rows);
    }

    /** Materiały w formacie importu: nazwa; kategoria; jednostka; kod; stan; cena (wartość stanu); minimum; dostawca; ważne do. */
    public function materials(): string
    {
        $rows = [];
        foreach ($this->s->materials() as $m) {
            $rows[] = [$m['name'], Store::CATEGORIES[$m['category']] ?? $m['category'], $m['unit'], $m['code'], self::num($m['stock']),
                $m['unit_price'] !== null && $m['stock'] > 0 ? self::num(round($m['unit_price'] * $m['stock'], 2)) : '', self::num($m['min_stock']),
                $m['supplier'], (string) ($m['expires'] ?? '')];
        }
        return self::csv(['nazwa', 'kategoria', 'jednostka', 'kod', 'stan', 'cena', 'minimum', 'dostawca', 'ważne do'], $rows);
    }

    private static function num(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        return str_replace('.', ',', rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.'));
    }

    /** @param list<string> $head @param list<list<mixed>> $rows */
    private static function csv(array $head, array $rows): string
    {
        $f = fopen('php://temp', 'w+');
        fputcsv($f, $head, ';', '"', '');
        foreach ($rows as $r) {
            // Komórka zaczynająca się od = + - @ byłaby w Excelu formułą.
            fputcsv($f, array_map(static fn($v): string => preg_match('/^[=+\-@]/', (string) $v) && !is_numeric((string) $v) ? "'" . $v : (string) $v, $r), ';', '"', '');
        }
        rewind($f);
        $out = (string) stream_get_contents($f);
        fclose($f);
        return "\xEF\xBB\xBF" . $out;
    }
}
