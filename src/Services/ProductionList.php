<?php
declare(strict_types=1);

namespace Pase\Services;

use Pase\Plugin\Hooks;
use PDO;

/**
 * Lista produkcji: produkty z zaznaczonych zamówień zsumowane po produkcie i wariancie - do wydrukowania
 * i odhaczania na papierze przed pakowaniem (lista zamówień → „Lista produkcji”).
 *
 * Parametry pozycji (zapach, knot, rozmiar...) biorą się z:
 *  - wariantu w zamówieniu (WooCommerce meta_data, jak w asystencie pakowania),
 *  - produktu w magazynie o tym samym SKU (atrybuty z jedną wartością i krótkie pola własne),
 *  - rozszerzeń przez filtr `production_list.details` (np. Produkcja dopisuje recepturę wyrobu: knot, zapach, pojemnik).
 *
 * Zamówienia scalone (OrderMerge) liczą się raz: główne razem z dołączonymi, dołączone same tylko wtedy,
 * gdy ich główne nie jest zaznaczone.
 */
final class ProductionList
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param int[] $ids woo_order_id zaznaczonych zamówień
     * @return array{rows:list<array>,orders:list<array>,materials:list<array>,total:int}
     */
    public function build(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i) => $i > 0)));
        if ($ids === []) {
            return ['rows' => [], 'orders' => [], 'materials' => [], 'total' => 0];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare("SELECT * FROM woo_orders WHERE woo_order_id IN ({$in})");
        $st->execute($ids);
        $byId = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byId[(int) $r['woo_order_id']] = $r;
        }

        $merge = new OrderMerge($this->pdo);
        $rows = [];
        $orders = [];
        foreach ($ids as $id) {
            $row = $byId[$id] ?? null;
            if ($row === null) {
                continue;
            }
            $items = OrderMerge::items($row);
            $parent = (int) ($row['merged_into'] ?? 0);
            if ($parent > 0 && isset($byId[$parent])) {
                $items = [];   // policzone w zamówieniu głównym
            } elseif ($parent === 0 && array_key_exists('merged_into', $row)) {
                try {
                    $items = array_merge($items, $merge->extraItems($id));
                } catch (\PDOException) {
                }
            }
            // Numer jak na liście zamówień (numer w sklepie; Allegro: pierwszy segment UUID).
            $num = (string) ($row['order_number'] ?? '');
            $label = $num !== '' ? (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', $num) ? explode('-', $num, 2)[0] : $num) : '#' . $id;
            $payload = json_decode((string) ($row['payload'] ?? ''), true);
            $payload = is_array($payload) ? $payload : [];
            $note = trim((string) ($payload['customer_note'] ?? ($payload['messageToSeller'] ?? '')));
            $count = 0;
            foreach ($items as $it) {
                $qty = max(0, (int) ($it['quantity'] ?? 0));
                if ($qty === 0) {
                    continue;
                }
                $count += $qty;
                $name = trim((string) ($it['name'] ?? '')) ?: 'Produkt';
                $sku = trim((string) ($it['sku'] ?? ''));
                $attrs = self::lineAttributes($it);
                $key = mb_strtolower(($sku !== '' ? $sku : $name) . '|' . implode('|', array_map(static fn($a) => $a[0] . '=' . $a[1], $attrs)));
                if (!isset($rows[$key])) {
                    $rows[$key] = ['name' => $name, 'sku' => $sku, 'attrs' => $attrs, 'qty' => 0, 'orders' => [], 'details' => []];
                }
                $rows[$key]['qty'] += $qty;
                $rows[$key]['orders'][$label] = ($rows[$key]['orders'][$label] ?? 0) + $qty;
            }
            $orders[] = ['id' => $id, 'label' => $label, 'customer' => (string) ($row['customer_name'] ?? ''), 'items' => $count, 'note' => $note];
        }

        // Parametry z magazynu (po SKU) i z rozszerzeń.
        $products = $this->productsBySku(array_column($rows, 'sku'));
        $fields = $this->fieldLabels();
        $materials = [];
        foreach ($rows as &$r) {
            $p = $r['sku'] !== '' ? ($products[mb_strtolower($r['sku'])] ?? null) : null;
            if ($p !== null) {
                $have = array_map(static fn($a) => mb_strtolower($a[0]), $r['attrs']);
                foreach (self::productParams($p, $fields) as [$k, $v]) {
                    if (!in_array(mb_strtolower($k), $have, true)) {
                        $r['attrs'][] = [$k, $v];
                        $have[] = mb_strtolower($k);
                    }
                }
            }
            $details = Hooks::applyFilters('production_list.details', [], $r['sku'], $r['qty'], $r);
            $variant = [];
            foreach ($r['attrs'] as [$k, $v]) {
                $variant[mb_strtolower($k)] = mb_strtolower($v);
            }
            foreach (is_array($details) ? $details : [] as $d) {
                if (!is_array($d) || trim((string) ($d['label'] ?? '')) === '' || trim((string) ($d['value'] ?? '')) === '') {
                    continue;
                }
                // Wariant z zamówienia wygrywa: wyrób „Zapach: Olejek Wanilia” przy wariancie „Zapach: Cynamon” byłby mylący.
                $vv = $variant[mb_strtolower((string) $d['label'])] ?? null;
                if ($vv !== null && !str_contains(mb_strtolower((string) $d['value']), $vv)) {
                    continue;
                }
                $per = isset($d['per']) && is_numeric($d['per']) ? (float) $d['per'] : null;
                $unit = (string) ($d['unit'] ?? '');
                $line = ['label' => (string) $d['label'], 'value' => (string) $d['value'], 'total' => $per !== null ? $per * $r['qty'] : null, 'unit' => $unit];
                $r['details'][] = $line;
                if ($line['total'] !== null) {
                    $mk = mb_strtolower($line['label'] . '|' . $line['value'] . '|' . $unit);
                    $materials[$mk] ??= ['label' => $line['label'], 'value' => $line['value'], 'unit' => $unit, 'total' => 0.0];
                    $materials[$mk]['total'] += $line['total'];
                }
            }
        }
        unset($r);

        $rows = array_values($rows);
        usort($rows, static fn(array $a, array $b) => strnatcasecmp($a['name'], $b['name'])
            ?: strnatcasecmp(self::attrText($a['attrs']), self::attrText($b['attrs'])));
        $materials = array_values($materials);
        usort($materials, static fn(array $a, array $b) => strnatcasecmp($a['label'], $b['label']) ?: strnatcasecmp($a['value'], $b['value']));

        return ['rows' => $rows, 'orders' => $orders, 'materials' => $materials, 'total' => array_sum(array_column($rows, 'qty'))];
    }

    /** „Zapach: Wanilia · Knot: drewniany” */
    public static function attrText(array $attrs): string
    {
        return implode(' · ', array_map(static fn(array $a) => $a[0] . ': ' . $a[1], $attrs));
    }

    /** Ilość z jednostką bez zbędnych zer: 90 g, 1,5 kg, 3 szt. */
    public static function amount(float $v, string $unit): string
    {
        if ($unit === 'g' && $v >= 1000) {
            [$v, $unit] = [$v / 1000, 'kg'];
        } elseif ($unit === 'ml' && $v >= 1000) {
            [$v, $unit] = [$v / 1000, 'l'];
        }
        $s = rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ',');
        return trim($s . ' ' . ($unit === 'szt' ? 'szt.' : $unit));
    }

    /** Wariant pozycji (Woo meta_data bez pól technicznych). @return list<array{0:string,1:string}> */
    public static function lineAttributes(array $it): array
    {
        $out = [];
        foreach ((array) ($it['meta_data'] ?? []) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $key = trim((string) ($m['display_key'] ?? $m['key'] ?? ''));
            $val = $m['display_value'] ?? $m['value'] ?? '';
            if ($key === '' || str_starts_with($key, '_') || !is_scalar($val)) {
                continue;
            }
            $val = trim(html_entity_decode(strip_tags((string) $val), ENT_QUOTES, 'UTF-8'));
            if ($val !== '') {
                $out[] = [$key, $val];
            }
        }
        return $out;
    }

    /** Atrybuty z jedną wartością i krótkie pola własne produktu. @return list<array{0:string,1:string}> */
    private static function productParams(array $p, array $fieldLabels): array
    {
        $out = [];
        foreach (ErliOffers::productAttributes($p) as $name => $vals) {
            if ($name !== '' && count($vals) === 1 && mb_strlen(trim($vals[0])) <= 60) {
                $out[] = [$name, trim($vals[0])];
            }
        }
        $custom = json_decode((string) ($p['custom_fields'] ?? ''), true);
        foreach (is_array($custom) ? $custom : [] as $k => $v) {
            if (!isset($fieldLabels[$k]) || !is_scalar($v) || is_bool($v)) {
                continue;
            }
            $v = trim(strip_tags((string) $v));
            if ($v !== '' && mb_strlen($v) <= 60) {
                $out[] = [$fieldLabels[$k], $v];
            }
        }
        return $out;
    }

    /** @return array<string,array> sku małymi literami => produkt */
    private function productsBySku(array $skus): array
    {
        $skus = array_values(array_unique(array_filter(array_map(static fn($s) => mb_strtolower(trim((string) $s)), $skus))));
        if ($skus === []) {
            return [];
        }
        $out = [];
        try {
            $in = implode(',', array_fill(0, count($skus), '?'));
            $st = $this->pdo->prepare("SELECT * FROM products WHERE LOWER(sku) IN ({$in})");
            $st->execute($skus);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $out[mb_strtolower((string) $p['sku'])] = $p;
            }
        } catch (\PDOException) {
        }
        return $out;
    }

    /** Pola własne produktu, które mają sens na liście (tekst, liczba, wybór). @return array<string,string> */
    private function fieldLabels(): array
    {
        try {
            $out = [];
            foreach ($this->pdo->query('SELECT field_key, label, type FROM product_fields ORDER BY position, id') as $f) {
                if (in_array((string) $f['type'], ['text', 'number', 'select'], true)) {
                    $out[(string) $f['field_key']] = (string) $f['label'];
                }
            }
            return $out;
        } catch (\PDOException) {
            return [];
        }
    }
}
