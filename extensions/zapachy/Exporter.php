<?php
declare(strict_types=1);

namespace PaseExt\Zapachy;

/**
 * Eksport do CSV dla Excela (średnik, UTF-8 z BOM) w formacie, który Importer czyta z powrotem:
 * zapachy (z SKU produktów, do których są przypisane) i słownik nut.
 */
final class Exporter
{
    public const FRAGRANCE_HEADERS = ['numer', 'nazwa', 'inne nazwy', 'producent', 'kod producenta', 'nuty głowy', 'nuty serca', 'nuty bazy',
        'opis', 'uwagi', 'cena za gram', 'maks stężenie', 'ufi', 'produkty sku', 'archiwum'];

    public function __construct(private readonly Store $s) {}

    public function fragrances(): string
    {
        $rows = $this->s->all('SELECT * FROM ext_zap_fragrances');
        usort($rows, static fn(array $a, array $b) => [(int) $a['archived'], is_numeric($a['storage_no']) ? (float) $a['storage_no'] : PHP_FLOAT_MAX, mb_strtolower((string) $a['name'])]
            <=> [(int) $b['archived'], is_numeric($b['storage_no']) ? (float) $b['storage_no'] : PHP_FLOAT_MAX, mb_strtolower((string) $b['name'])]);
        $skus = [];
        try {
            foreach ($this->s->all("SELECT l.fragrance_id, p.sku FROM ext_zap_links l JOIN products p ON p.id = l.product_id WHERE p.sku IS NOT NULL AND p.sku <> '' ORDER BY p.sku") as $r) {
                $skus[$r['fragrance_id']][] = (string) $r['sku'];
            }
        } catch (\PDOException) {
        }
        $num = static fn($v): string => $v === null || $v === '' ? '' : str_replace('.', ',', rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.'));
        $out = [self::FRAGRANCE_HEADERS];
        foreach ($rows as $f) {
            $out[] = [$f['storage_no'], $f['name'], $f['aliases'], $f['producer'], $f['producer_code'], $f['head_notes'], $f['heart_notes'], $f['base_notes'],
                $f['description'], $f['comments'], $num($f['price_per_g']), $num($f['max_concentration']), $f['ufi'], implode(', ', $skus[$f['id']] ?? []),
                (int) $f['archived'] === 1 ? 'tak' : ''];
        }
        return self::csv($out);
    }

    public function notes(): string
    {
        $out = [['nuta', 'kategoria']];
        foreach ($this->s->noteDict() as $n) {
            $out[] = [$n['name'], $n['category']];
        }
        return self::csv($out);
    }

    /** @param list<list<mixed>> $rows */
    private static function csv(array $rows): string
    {
        $h = fopen('php://temp', 'r+');
        foreach ($rows as $r) {
            // Wzory Excela (=, +, -, @ na początku) neutralizujemy apostrofem.
            fputcsv($h, array_map(static fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v, $r), ';', '"', '\\');
        }
        rewind($h);
        $csv = (string) stream_get_contents($h);
        fclose($h);
        return "\xEF\xBB\xBF" . $csv;
    }
}
