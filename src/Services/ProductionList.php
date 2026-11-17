<?php
declare(strict_types=1);

namespace Pase\Services;

use Pase\Plugin\Hooks;
use PDO;
use Pase\Support\Units;

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
                    $rows[$key] = ['name' => $name, 'sku' => $sku, 'attrs' => $attrs, 'qty' => 0, 'orders' => [], 'details' => [],
                        'pid' => (int) ($it['product_id'] ?? 0), 'vid' => (int) ($it['variation_id'] ?? 0)];
                }
                $rows[$key]['qty'] += $qty;
                $rows[$key]['orders'][$label] = ($rows[$key]['orders'][$label] ?? 0) + $qty;
            }
            $orders[] = ['id' => $id, 'label' => $label, 'customer' => (string) ($row['customer_name'] ?? ''), 'items' => $count, 'note' => $note];
        }

        // Parametry z magazynu (po SKU) i z rozszerzeń.
        $products = $this->productsBySku(array_column($rows, 'sku'));
        $byWoo = $this->productsByWooId($rows, $products);
        $fields = $this->fieldLabels();
        $materials = [];
        foreach ($rows as &$r) {
            $p = $r['sku'] !== '' ? ($products[mb_strtolower($r['sku'])] ?? null) : null;
            // Bez SKU (albo SKU spoza magazynu): produkt po ID wariantu / produktu ze sklepu.
            $p ??= $byWoo['v' . $r['vid']] ?? ($r['vid'] === 0 ? ($byWoo['p' . $r['pid']] ?? null) : null);
            unset($r['pid'], $r['vid']);
            if ($p !== null) {
                $have = array_map(static fn($a) => mb_strtolower($a[0]), $r['attrs']);
                foreach (self::productParams($p, $fields) as [$k, $v]) {
                    if (!in_array(mb_strtolower($k), $have, true)) {
                        $r['attrs'][] = [$k, $v];
                        $have[] = mb_strtolower($k);
                    }
                }
            }
            // Nuty zapachowe (Aroma head/heart/base, nuta głowy...) nie są potrzebne przy produkcji.
            $r['attrs'] = array_values(array_filter($r['attrs'], static fn(array $a) => !self::isScentNote($a[0])));
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
            // Zapach do grupowania: numer i nazwa z wtyczki Zapachy, potem wariant („Zapach: Wanilia”), potem olejek z receptury.
            $r['scent'] = '';
            foreach ($r['details'] as $d) {
                if ($r['scent'] === '' && mb_strtolower($d['label']) === 'nr zapachu') {
                    $r['scent'] = $d['value'];
                }
            }
            foreach ($r['attrs'] as [$k, $v]) {
                if ($r['scent'] === '' && self::isScent($k)) {
                    $r['scent'] = $v;
                }
            }
            foreach ($r['details'] as $d) {
                if ($r['scent'] === '' && mb_strtolower($d['label']) === 'zapach') {
                    $r['scent'] = $d['value'];
                }
            }
            // Wariant „knot: drewniany” przy recepturze „Knot: Drewniany 15 mm 0.6” - zostaje tylko dokładny z receptury.
            $exact = [];
            foreach ($r['details'] as $d) {
                $exact[mb_strtolower(trim($d['label']))][] = mb_strtolower($d['value']);
            }
            $r['attrs'] = array_values(array_filter($r['attrs'], static function (array $a) use ($exact): bool {
                foreach ($exact[mb_strtolower(trim($a[0]))] ?? [] as $v) {
                    if (trim($a[1]) !== '' && str_contains($v, mb_strtolower(trim($a[1])))) {
                        return false;
                    }
                }
                return true;
            }));
            // Waga osobno (kolumna „Waga”): pole „Waga” wyrobu z Produkcji, potem waga produktu w magazynie (karta produktu, z Woo),
            // potem atrybut „Waga”/„Gramatura”, potem nazwa („... 200 g”).
            $r['weight'] = '';
            foreach ($r['details'] as $i => $d) {
                if ($d['total'] === null && mb_strtolower($d['label']) === 'waga') {
                    $r['weight'] = $r['weight'] === '' ? $d['value'] : $r['weight'];
                    unset($r['details'][$i]);
                }
            }
            $r['details'] = array_values($r['details']);
            if ($r['weight'] === '' && $p !== null && is_numeric($p['weight'] ?? null) && (float) $p['weight'] > 0) {
                $kg = (float) $p['weight'];
                // Sklep podał gramy, a magazyn zapisał je jako kg (240 „kg” przy słoiku 6×6×6 cm) - to są gramy.
                if (Units::implausibleWeight($kg, $p['length'] ?? null, $p['width'] ?? null, $p['height'] ?? null)
                    || ($kg >= 50 && !((float) ($p['length'] ?? 0) > 0))) {
                    $kg /= 1000;
                }
                $r['weight'] = self::amount(round($kg * 1000, 1), 'g');
            }
            foreach ($r['attrs'] as $i => [$k, $v]) {
                if (self::isWeight($k)) {
                    $r['weight'] = $r['weight'] === '' ? $v : $r['weight'];
                    unset($r['attrs'][$i]);
                }
            }
            $r['attrs'] = array_values($r['attrs']);
            if ($r['weight'] === '') {
                $r['weight'] = self::weightIn($r['name']);
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

    /** Nazwa parametru to nuta zapachowa (Aroma head, Nuta serca, Notes...). */
    public static function isScentNote(string $name): bool
    {
        return (bool) preg_match('/aroma|\bnut[aąeyo]\w*|\bnotes?\b|akord/iu', $name);
    }

    /** Nazwa parametru to zapach wariantu (Zapach, pa_zapach, Scent, Aromat). */
    public static function isScent(string $name): bool
    {
        return (bool) preg_match('/^(pa_)?(zapach|scent|fragrance|aromat\b)/iu', trim($name));
    }

    /**
     * Pozycje ułożone po zapachu: ten sam zapach (różne produkty, knoty) obok siebie, bez zapachu na końcu.
     * Każda pozycja z numerem zapachu dostaje znak (mark) = numer („7”) - na wydruku w kółku; zapach bez numeru dostaje literę („A”),
     * ale tylko gdy jest wspólny dla kilku pozycji. shared = zapach w kilku pozycjach (kółko wypełnione na czarno).
     * Numer jest tylko w kółku - w parametrach zostaje sama nazwa zapachu. Bez nagłówków grup.
     * @return list<array> pozycje z kluczami mark ('' gdy brak numeru i zapach jest w jednej pozycji albo go nie ma) i shared
     */
    public static function markScents(array $rows): array
    {
        $count = [];
        foreach ($rows as $r) {
            $k = mb_strtolower(trim((string) ($r['scent'] ?? '')));
            $count[$k] = ($count[$k] ?? 0) + 1;
        }
        $keyed = array_map(static fn(array $r, int $i) => [mb_strtolower(trim((string) ($r['scent'] ?? ''))), $i, $r], $rows, array_keys($rows));
        usort($keyed, static fn(array $a, array $b) => ($a[0] === '') <=> ($b[0] === '') ?: strnatcasecmp($a[0], $b[0]) ?: $a[1] <=> $b[1]);
        $letters = [];
        $out = [];
        $nextLetter = 0;
        foreach ($keyed as [$k, , $r]) {
            if ($k !== '' && !isset($letters[$k])) {
                // Numer zapachu z wtyczki Zapachy („7 · Pumpkin pie” → 7) przy każdej pozycji; bez numeru litera A, B... tylko przy wspólnym.
                if (preg_match('/^\s*([\w-]{1,6})\s*·/u', (string) $r['scent'], $m)) {
                    $letters[$k] = $m[1];
                } elseif ($count[$k] > 1) {
                    $n = $nextLetter++;
                    $letters[$k] = ($n >= 26 ? chr(65 + intdiv($n, 26) - 1) : '') . chr(65 + $n % 26);
                }
            }
            $r['mark'] = $letters[$k] ?? '';
            $r['shared'] = $r['mark'] !== '' && $count[$k] > 1;
            // Numer jest w kółku - w parametrach zostaje sama nazwa („Nr zapachu: 7 · Pumpkin pie” → „Zapach: Pumpkin pie”),
            // a gdy receptura już ją podaje („Zapach: Pumpkin pie (8%)”), wpis z numerem znika.
            if ($r['mark'] !== '' && preg_match('/^\s*' . preg_quote($r['mark'], '/') . '\s*·\s*(.+)$/u', (string) $r['scent'], $m)) {
                $name = trim($m[1]);
                $named = false;
                foreach ($r['details'] ?? [] as $d) {
                    $named = $named || (mb_strtolower($d['label']) === 'zapach' && str_contains(mb_strtolower($d['value']), mb_strtolower($name)));
                }
                foreach ($r['attrs'] ?? [] as [$ak, $av]) {
                    $named = $named || (self::isScent($ak) && mb_strtolower(trim($av)) === mb_strtolower($name));
                }
                $details = [];
                foreach ($r['details'] ?? [] as $d) {
                    if (mb_strtolower($d['label']) === 'nr zapachu') {
                        if ($named) {
                            continue;
                        }
                        $d = ['label' => 'Zapach', 'value' => $name] + $d;
                    }
                    $details[] = $d;
                }
                $r['details'] = $details;
            }
            $out[] = $r;
        }
        return $out;
    }

    /** Nazwa parametru oznacza wagę świecy (Waga, Waga netto, Gramatura, Weight). */
    public static function isWeight(string $name): bool
    {
        return (bool) preg_match('/^(pa_)?(waga|gramatur|weight)/iu', trim($name));
    }

    /** Waga z tekstu: „Świeca 200 g” → „200 g”, „Wosk 1,5 kg” → „1,5 kg”. */
    public static function weightIn(string $text): string
    {
        return preg_match('/(?<![\w,.])(\d+(?:[.,]\d+)?)\s?(g|kg)(?![\w])/iu', $text, $m) ? $m[1] . ' ' . mb_strtolower($m[2]) : '';
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

    /**
     * Produkty z magazynu po ID ze sklepu dla pozycji bez dopasowanego SKU: klucz „v<id wariantu>” i „p<id produktu>” (produkt bez wariantu).
     * @return array<string,array>
     */
    private function productsByWooId(array $rows, array $bySku): array
    {
        $vids = $pids = [];
        foreach ($rows as $r) {
            if ($r['sku'] !== '' && isset($bySku[mb_strtolower($r['sku'])])) {
                continue;
            }
            if ($r['vid'] > 0) {
                $vids[$r['vid']] = true;
            } elseif ($r['pid'] > 0) {
                $pids[$r['pid']] = true;
            }
        }
        $out = [];
        try {
            if ($vids !== []) {
                $ids = array_keys($vids);
                $st = $this->pdo->prepare('SELECT * FROM products WHERE woo_variation_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
                $st->execute($ids);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
                    $out['v' . (int) $p['woo_variation_id']] ??= $p;
                }
            }
            if ($pids !== []) {
                $ids = array_keys($pids);
                $st = $this->pdo->prepare('SELECT * FROM products WHERE woo_product_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
                    . ' AND (woo_variation_id IS NULL OR woo_variation_id = 0)');
                $st->execute($ids);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
                    $out['p' . (int) $p['woo_product_id']] ??= $p;
                }
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
