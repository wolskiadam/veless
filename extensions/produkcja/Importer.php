<?php
declare(strict_types=1);

namespace PaseExt\Produkcja;

/**
 * Import danych: kopia JSON z Rejestru Partii (WordPress) i lista materiałów z pliku CSV.
 *
 * Z Rejestru Partii bierzemy woski, zapachy, knoty i pojemniki (jako materiały z kodami do numeru partii), pracowników
 * i zmiany, dostawy (data, numer partii dostawcy, ważność, cena) i historię partii. Rejestr nie liczył stanu magazynu,
 * więc dostawy wchodzą z zerową resztą - stan wpisuje się potem Inwentaryzacją. Ponowny import pomija to, co już jest
 * (materiały po nazwie, partie po numerze). Etykiet CLP nie przenosimy (etap 2).
 */
final class Importer
{
    private const DICTS = ['batch_waxes' => ['wosk', 'wax_name', 'wax_code', 'g'], 'batch_scents' => ['zapach', 'scent_name', 'scent_code', 'g'],
        'batch_wick_types' => ['knot', 'wick_name', 'wick_code', 'szt'], 'batch_containers' => ['pojemnik', 'container_name', '', 'szt']];
    private const SUPPLY_TYPES = ['wax' => 'batch_waxes', 'scent' => 'batch_scents', 'wick' => 'batch_wick_types', 'container' => 'batch_containers'];

    public function __construct(private readonly Store $s) {}

    /** @return array<string,int> co zaimportowano */
    public function bne(string $json, string $user): array
    {
        $d = json_decode($json, true);
        if (!is_array($d) || !isset($d['batch_records']) && !isset($d['batch_waxes'])) {
            throw new \RuntimeException('To nie jest kopia z Rejestru Partii (brak tabel batch_records / batch_waxes).');
        }
        $stat = ['materials' => 0, 'deliveries' => 0, 'products' => 0, 'batches' => 0, 'people' => 0, 'shifts' => 0, 'skipped' => 0];
        return $this->s->tx(function () use ($d, $user, &$stat): array {
            $map = [];
            $byName = [];
            foreach ($this->s->all('SELECT id, name FROM ext_prod_materials') as $m) {
                $byName[mb_strtolower($m['name'])] = $m['id'];
            }
            foreach (self::DICTS as $table => [$cat, $nameCol, $codeCol, $unit]) {
                foreach ((array) ($d[$table] ?? []) as $r) {
                    $name = trim((string) ($r[$nameCol] ?? ''));
                    if ($name === '' || in_array(mb_strtolower($name), ['brak', '-brak zapachu-'], true)) {
                        continue;
                    }
                    $key = mb_strtolower($name);
                    if (!isset($byName[$key])) {
                        $byName[$key] = Store::id();
                        $this->s->insert('ext_prod_materials', ['id' => $byName[$key], 'name' => mb_substr($name, 0, 200), 'category' => $cat,
                            'code' => strtoupper(mb_substr(trim((string) ($r[$codeCol] ?? '')), 0, 8)), 'unit' => $unit, 'min_stock' => null, 'supplier' => '',
                            'fill_g' => $cat === 'pojemnik' ? Store::num($r['weight_g'] ?? '') : null, 'notes' => $cat === 'pojemnik' && !empty($r['type']) ? (string) $r['type'] : '',
                            'archived' => 0, 'created_at' => Store::now(), 'updated_at' => Store::now()]);
                        $stat['materials']++;
                    }
                    $map[$table][(string) ($r['id'] ?? '')] = $byName[$key];
                }
            }

            $have = array_flip(array_map(static fn(array $r): string => mb_strtolower($r['name']) . '|' . $r['kind'], $this->s->all('SELECT name, kind FROM ext_prod_dict')));
            foreach (['batch_employees' => ['person', 'employee_name', ''], 'batch_parties' => ['shift', 'party_name', 'party_code']] as $table => [$kind, $col, $code]) {
                foreach ((array) ($d[$table] ?? []) as $r) {
                    $name = trim((string) ($r[$col] ?? ''));
                    if ($name === '' || isset($have[mb_strtolower($name) . '|' . $kind])) {
                        continue;
                    }
                    $have[mb_strtolower($name) . '|' . $kind] = true;
                    $this->s->insert('ext_prod_dict', ['id' => Store::id(), 'kind' => $kind, 'name' => mb_substr($name, 0, 100),
                        'code' => $code !== '' ? strtoupper(mb_substr((string) ($r[$code] ?? ''), 0, 1)) : '', 'rate' => Store::num($r['hourly_rate'] ?? '')]);
                    $stat[$kind === 'person' ? 'people' : 'shifts']++;
                }
            }

            $lots = array_flip(array_map(static fn(array $r): string => $r['material_id'] . '|' . $r['lot_no'] . '|' . $r['received_at'],
                $this->s->all('SELECT material_id, lot_no, received_at FROM ext_prod_deliveries')));
            foreach ((array) ($d['batch_supplies'] ?? []) as $r) {
                $mid = $map[self::SUPPLY_TYPES[(string) ($r['item_type'] ?? '')] ?? ''][(string) ($r['parent_id'] ?? '')] ?? null;
                $date = substr((string) ($r['date_received'] ?? ''), 0, 10);
                if ($mid === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    $stat['skipped']++;
                    continue;
                }
                $lot = mb_substr(trim((string) ($r['supply_number'] ?? '')), 0, 100);
                if (isset($lots[$mid . '|' . $lot . '|' . $date])) {
                    continue;
                }
                [$qty, $unitQty] = self::toBase(Store::num($r['quantity_total'] ?? ''), (string) ($r['unit'] ?? 'kg'));
                $this->s->insert('ext_prod_deliveries', ['id' => Store::id(), 'material_id' => $mid, 'received_at' => $date,
                    'supplier' => mb_substr((string) ($r['manufacturer'] ?? ''), 0, 200), 'lot_no' => $lot,
                    'expires_at' => preg_match('/^\d{4}-\d{2}-\d{2}/', (string) ($r['expiration_date'] ?? '')) ? substr((string) $r['expiration_date'], 0, 10) : null,
                    'qty' => $unitQty ?? 0, 'remaining' => 0, 'price_total' => Store::num($r['price_total'] ?? ''),
                    'notes' => trim('Z Rejestru Partii' . ((string) ($r['notes'] ?? '') !== '' ? ': ' . $r['notes'] : '')), 'created_by' => $user,
                    'created_at' => Store::now(), 'deleted_at' => null]);
                $stat['deliveries']++;
            }

            $products = [];
            foreach ($this->s->all('SELECT id, name FROM ext_prod_products') as $p) {
                $products[mb_strtolower($p['name'])] = $p['id'];
            }
            $codes = array_flip(array_map(static fn(array $r): string => strtoupper($r['code']), $this->s->all('SELECT code FROM ext_prod_batches')));
            foreach ((array) ($d['batch_records'] ?? []) as $r) {
                $code = strtoupper(trim((string) ($r['batch_code'] ?? '')));
                $name = trim((string) ($r['product_name'] ?? ''));
                $date = substr((string) ($r['production_date'] ?? ''), 0, 10);
                if ($code === '' || isset($codes[$code]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    $stat['skipped'] += $code !== '' && isset($codes[$code]) ? 0 : 1;
                    continue;
                }
                $codes[$code] = true;
                $pid = null;
                if ($name !== '') {
                    $k = mb_strtolower($name);
                    if (!isset($products[$k])) {
                        $products[$k] = Store::id();
                        $this->s->insert('ext_prod_products', ['id' => $products[$k], 'name' => mb_substr($name, 0, 200), 'sku' => '', 'shelf_life_months' => (int) ($r['shelf_life_months'] ?? 12),
                            'price' => null, 'vat' => 23, 'min_stock' => null, 'extra_cost' => null, 'labor_minutes' => null, 'notes' => 'Z Rejestru Partii',
                            'archived' => 0, 'created_at' => Store::now(), 'updated_at' => Store::now()]);
                        $stat['products']++;
                    }
                    $pid = $products[$k];
                }
                $months = (int) ($r['shelf_life_months'] ?? 12);
                $parts = array_filter([
                    'Wosk: ' . trim((string) ($r['wax_type'] ?? '')),
                    trim((string) ($r['scent'] ?? '')) !== '' ? 'Zapach: ' . $r['scent'] . (trim((string) ($r['concentration'] ?? '')) !== '' ? ' (' . $r['concentration'] . ')' : '') : '',
                    trim((string) ($r['wick_type'] ?? '')) !== '' ? 'Knot: ' . $r['wick_type'] : '',
                    trim((string) ($r['container'] ?? '')) !== '' ? 'Pojemnik: ' . $r['container'] : '',
                    trim((string) ($r['notes'] ?? '')),
                ], static fn(string $x): bool => $x !== '' && $x !== 'Wosk: ');
                $this->s->insert('ext_prod_batches', ['id' => Store::id(), 'code' => mb_substr($code, 0, 60), 'product_id' => $pid, 'product_name' => mb_substr($name !== '' ? $name : '-', 0, 200),
                    'produced_at' => $date, 'qty' => max(1, (int) ($r['quantity'] ?? 1)), 'expires_at' => $months > 0 ? date('Y-m-d', strtotime($date . ' +' . $months . ' months')) : null,
                    'person' => mb_substr((string) ($r['employee'] ?? ''), 0, 100), 'shift' => mb_substr((string) ($r['party'] ?? ''), 0, 100),
                    'work_hours' => Store::num($r['work_hours'] ?? ''), 'cost_materials' => Store::num($r['cost_total'] ?? ''), 'cost_labor' => null,
                    'lines_json' => '[]', 'stock_json' => '{}', 'is_draft' => !empty($r['is_valuation']) ? 1 : 0, 'imported' => 1, 'notes' => implode("\n", $parts),
                    'created_by' => $user, 'created_at' => (string) ($r['created_at'] ?? Store::now()), 'updated_at' => Store::now(), 'deleted_at' => null]);
                $stat['batches']++;
            }
            return $stat;
        });
    }

    /** Ilość z Rejestru (kg, l, g, ml, szt.) w jednostce materiału (g, ml, szt.). @return array{0:?float,1:?float} */
    private static function toBase(?float $qty, string $unit): array
    {
        if ($qty === null) {
            return [null, null];
        }
        $unit = strtolower(trim($unit));
        return [$qty, in_array($unit, ['kg', 'l'], true) ? $qty * 1000 : $qty];
    }

    /**
     * Materiały z CSV: nazwa; kategoria; jednostka; kod; stan; cena (za cały stan); minimum; dostawca; ważne do.
     * Pierwszy wiersz z „nazwa” to nagłówek. Separator ; albo , (wykrywany).
     * @return array{added:int,updated:int,skipped:int}
     */
    public function csv(string $text, string $user): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $lines = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];
        if ($lines === [] || trim($lines[0]) === '') {
            throw new \RuntimeException('Plik jest pusty.');
        }
        $sep = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';
        $head = array_map(static fn($c) => mb_strtolower(trim((string) $c)), str_getcsv($lines[0], $sep));
        $cols = ['nazwa' => 0, 'kategoria' => 1, 'jednostka' => 2, 'kod' => 3, 'stan' => 4, 'cena' => 5, 'minimum' => 6, 'dostawca' => 7, 'ważne do' => 8];
        if (in_array('nazwa', $head, true)) {
            array_shift($lines);
            $found = [];
            foreach ($head as $i => $h) {
                foreach (array_keys($cols) as $k) {
                    if (str_starts_with($h, $k) || ($k === 'ważne do' && str_starts_with($h, 'wazne'))) {
                        $found[$k] ??= $i;
                    }
                }
            }
            $cols = $found + array_fill_keys(array_keys($cols), -1);
        }
        $catBy = [];
        foreach (Store::CATEGORIES as $k => $label) {
            $catBy[mb_strtolower($label)] = $k;
            $catBy[$k] = $k;
        }
        $stat = ['added' => 0, 'updated' => 0, 'skipped' => 0];
        $this->s->tx(function () use ($lines, $sep, $cols, $catBy, $user, &$stat): void {
            foreach ($lines as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $c = str_getcsv($line, $sep);
                $get = static fn(string $k): string => $cols[$k] >= 0 ? trim((string) ($c[$cols[$k]] ?? '')) : '';
                $name = $get('nazwa');
                if ($name === '') {
                    $stat['skipped']++;
                    continue;
                }
                $unit = mb_strtolower($get('jednostka'));
                $mult = in_array($unit, ['kg', 'l'], true) ? 1000 : 1;
                $unit = ['kg' => 'g', 'g' => 'g', 'l' => 'ml', 'ml' => 'ml', 'szt' => 'szt', 'szt.' => 'szt', 'sztuki' => 'szt'][$unit] ?? '';
                $in = ['name' => $name, 'category' => $catBy[mb_strtolower($get('kategoria'))] ?? '', 'unit' => $unit, 'code' => $get('kod'),
                    'min_stock' => ($min = Store::num($get('minimum'))) !== null ? $min * $mult : '', 'supplier' => $get('dostawca'), 'notes' => ''];
                $existing = $this->s->one('SELECT * FROM ext_prod_materials WHERE LOWER(name) = LOWER(?) AND archived = 0', [$name]);
                $m = $this->s->saveMaterial($existing['id'] ?? null, $existing !== null ? array_merge($existing, array_filter($in, static fn($v) => $v !== '')) : $in);
                $stat[$existing !== null ? 'updated' : 'added']++;
                $stock = Store::num($get('stan'));
                if ($stock !== null && $stock > 0) {
                    $this->s->addDelivery((string) $m['id'], ['qty' => $stock * $mult, 'price_total' => $get('cena'), 'received_at' => date('Y-m-d'),
                        'expires_at' => $get('ważne do'), 'supplier' => $get('dostawca'), 'notes' => 'Import CSV'], $user);
                }
            }
        });
        return $stat;
    }
}
