<?php
declare(strict_types=1);

namespace PaseExt\Produkcja;

/**
 * Partie: zapis, zdjęcie materiałów z magazynu, dołożenie gotowych sztuk do produktu CRM i cofnięcie tego wszystkiego
 * (edycja, usunięcie do kosza, przywrócenie).
 *
 * Sztuki trafiają do produktu CRM o tym samym SKU co wyrób. Gdzie - decyduje ustawienie stock_target:
 *  - actual (domyślnie): stan faktyczny (products.actual_stock), który nie idzie do sklepu ani na Allegro;
 *    pusty stan faktyczny (jeszcze nie policzony) zostaje pusty,
 *  - crm: stan CRM (pase_stock) z wypychem do sklepu, both: oba, none: nic.
 * Co partia dołożyła, zapisujemy w stock_json, więc cofnięcie odejmuje dokładnie to samo.
 * Sprzedaż zdejmuje sztuki jak dotąd (rdzeń CRM), wtyczka jej nie liczy drugi raz.
 */
final class Production
{
    public function __construct(private readonly Store $s, private readonly array $settings = []) {}

    public function pattern(): string
    {
        $p = trim((string) ($this->settings['pattern'] ?? ''));
        return $p === '' ? BatchCode::DEFAULT_PATTERN : $p;
    }

    /**
     * Dane do numeru partii z receptury wyrobu: kod wyrobu, forma/pojemnik, kod wosku i zapachu (tego, którego jest
     * najwięcej), knot.
     * @return array{wax:string,scent:string,wick:string,product:string,form:string}
     */
    public function codeParts(?array $product): array
    {
        $best = ['wosk' => [0.0, ''], 'zapach' => [0.0, '']];
        $wick = '';
        $form = '';
        foreach ($product !== null ? $this->s->recipe((string) $product['id']) : [] as $l) {
            if (isset($best[$l['category']]) && (float) $l['qty'] > $best[$l['category']][0]) {
                $best[$l['category']] = [(float) $l['qty'], (string) $l['code']];
            }
            if ($l['category'] === 'knot' && $wick === '') {
                $wick = (string) $l['name'];
            }
            if ($l['category'] === 'pojemnik' && $form === '') {
                $form = (string) $l['code'];
            }
        }
        return ['wax' => $best['wosk'][1], 'scent' => $best['zapach'][1], 'wick' => $wick, 'form' => $form,
            'product' => $product === null ? '' : (trim((string) ($product['code'] ?? '')) !== '' ? (string) $product['code'] : BatchCode::initials((string) $product['name']))];
    }

    /**
     * Dane do numeru partii bez wyrobu: wybrany wosk, zapach, knot, pojemnik i kod produktu (wpisany albo z nazwy).
     * @param list<array<string,mixed>> $plan z freePlan()
     */
    public static function freeCodeParts(array $plan, string $name = '', string $productCode = ''): array
    {
        $productCode = substr(BatchCode::clean($productCode), 0, 2);
        $out = ['wax' => '', 'scent' => '', 'wick' => '', 'form' => '', 'product' => $productCode !== '' ? $productCode : BatchCode::initials($name)];
        foreach ($plan as $l) {
            match ($l['category'] ?? '') {
                'wosk' => $out['wax'] = (string) $l['code'],
                'zapach' => $out['scent'] = (string) $l['code'],
                'knot' => $out['wick'] = (string) $l['name'],
                'pojemnik' => $out['form'] = (string) $l['code'],
                default => null,
            };
        }
        return $out;
    }

    /** @param ?array $parts gotowe dane do numeru (partia bez wyrobu), inaczej z receptury wyrobu */
    public function code(?array $product, string $date, int $qty, string $shiftCode, ?string $exceptId = null, ?array $parts = null): string
    {
        // Wszystkie numery, także z kosza (przywrócona partia nie może dostać cudzego numeru).
        $existing = array_map('strval', array_column($this->s->all('SELECT code FROM ext_prod_batches'
            . ($exceptId !== null ? ' WHERE id <> ?' : ''), $exceptId !== null ? [$exceptId] : []), 'code'));
        return BatchCode::generate($this->pattern(), $date, ($parts ?? $this->codeParts($product)) + [
            'qty' => $qty, 'shift' => $shiftCode, 'seq' => $this->s->countOnDay($date, $exceptId) + 1,
        ], $existing);
    }

    /**
     * Nowa partia albo zmiana istniejącej (cofa starą i zapisuje od nowa).
     * Wejście: product_id, produced_at, qty, person, shift, work_hours, notes, draft (wycena robocza), pick[material_id] = dostawa,
     * allow_short (zapisz mimo braków), keep_code.
     * Partia bez wyrobu (jak w Rejestrze Partii): free=1, product_name, shelf_life_months i f[wosk|zapach|knot|pojemnik] = [id, qty]
     * - materiały wybrane ręcznie, ilość na całą partię (pusta = tylko do numeru i historii, bez zdejmowania ze stanu).
     */
    public function save(?string $id, array $in, string $user): array
    {
        $old = $id !== null ? ($this->s->batch($id) ?? throw new \RuntimeException('Nie ma takiej partii.')) : null;
        $free = !empty($in['free']);
        $product = $free ? null : ($this->s->product((string) ($in['product_id'] ?? '')) ?? throw new \RuntimeException('Wybierz wyrób albo zrób partię bez wyrobu.'));
        $name = $free ? mb_substr(trim((string) ($in['product_name'] ?? '')), 0, 200) : (string) $product['name'];
        if ($name === '') {
            throw new \RuntimeException('Wpisz nazwę produktu.');
        }
        $qty = (int) ($in['qty'] ?? 0);
        if ($qty < 1 || $qty > 99999) {
            throw new \RuntimeException('Wpisz ilość sztuk (1-99999).');
        }
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in['produced_at'] ?? '')) ? (string) $in['produced_at'] : date('Y-m-d');
        $draft = !empty($in['draft']);
        $shift = $this->dictRow('shift', (string) ($in['shift'] ?? ''));
        $person = $this->dictRow('person', (string) ($in['person'] ?? ''));
        $hours = Store::num($in['work_hours'] ?? '');
        $months = $free ? max(0, min(120, (int) ($in['shelf_life_months'] ?? 12))) : (int) $product['shelf_life_months'];

        return $this->s->tx(function () use ($old, $product, $name, $free, $qty, $date, $draft, $shift, $person, $hours, $months, $in, $user): array {
            if ($old !== null) {
                $this->revert($old, $user, 'zmiana partii');
            }
            $plan = $free ? $this->freePlan((array) ($in['f'] ?? []), (array) ($in['pick'] ?? [])) : $this->plan($product, $qty, (array) ($in['pick'] ?? []));
            $id = $old['id'] ?? Store::id();
            $keep = $old !== null && !empty($in['keep_code']) && (int) $old['is_draft'] === ($draft ? 1 : 0);
            $code = $keep ? (string) $old['code'] : ($draft ? BatchCode::draft($date)
                : $this->code($product, $date, $qty, (string) ($shift['code'] ?? ''), $old['id'] ?? null, $free ? self::freeCodeParts($plan, $name, (string) ($in['product_code'] ?? '')) : null));
            $row = [
                'code' => $code,
                'product_id' => $product['id'] ?? null,
                'product_name' => $name,
                'produced_at' => $date,
                'qty' => $qty,
                'expires_at' => $months > 0 ? date('Y-m-d', strtotime($date . ' +' . $months . ' months')) : null,
                'person' => (string) ($person['name'] ?? trim((string) ($in['person'] ?? ''))),
                'shift' => (string) ($shift['name'] ?? ''),
                'work_hours' => $hours,
                'is_draft' => $draft ? 1 : 0,
                'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 2000),
                'updated_at' => Store::now(),
            ];
            if (!$draft && empty($in['allow_short'])) {
                $short = array_filter($plan, static fn(array $l): bool => $l['need'] > $l['stock'] + 0.0005);
                if ($short !== []) {
                    throw new \RuntimeException('Brakuje materiałów: ' . implode(', ', array_map(static fn(array $l): string =>
                        $l['name'] . ' (potrzeba ' . Ui::qty($l['need'], $l['unit']) . ', jest ' . Ui::qty($l['stock'], $l['unit']) . ')', $short))
                        . '. Przyjmij dostawę albo zaznacz „Zapisz mimo braków”.');
                }
            }
            $rate = $person !== null && $person['rate'] !== null ? (float) $person['rate'] : (float) ($this->settings['hourly_rate'] ?? 0);
            $row['cost_labor'] = $hours !== null && $rate > 0 ? round($hours * $rate, 2) : null;
            if ($old === null) {
                $this->s->insert('ext_prod_batches', ['id' => $id, 'cost_materials' => null, 'lines_json' => '[]', 'stock_json' => '{}', 'imported' => 0,
                    'created_by' => $user, 'created_at' => Store::now(), 'deleted_at' => null] + $row);
            } else {
                $this->s->update('ext_prod_batches', $id, $row);
            }
            $this->apply($id, $plan, $draft, $user);
            return $this->s->batch($id) ?? [];
        });
    }

    /**
     * Co partia zużyje: potrzebna ilość każdego materiału z receptury, stan, wybrana dostawa.
     * @return list<array{material_id:string,name:string,unit:string,need:float,stock:float,pick:string}>
     */
    public function plan(array $product, int $qty, array $pick = []): array
    {
        $out = [];
        foreach ($this->s->recipe((string) $product['id']) as $l) {
            $mid = (string) $l['material_id'];
            $out[] = ['material_id' => $mid, 'name' => (string) $l['name'], 'unit' => (string) $l['unit'], 'need' => round((float) $l['qty'] * $qty, 3),
                'stock' => $this->s->stock($mid), 'pick' => (string) ($pick[$mid] ?? '')];
        }
        return $out;
    }

    public const FREE_KINDS = ['wosk' => 'Wosk', 'zapach' => 'Zapach', 'knot' => 'Knot', 'pojemnik' => 'Pojemnik'];

    /**
     * Materiały partii bez wyrobu: wybrany wosk, zapach, knot i pojemnik z ilością na całą partię.
     * @param array<string,array{id?:string,qty?:string}> $f
     * @return list<array<string,mixed>> jak plan(), z kategorią i kodem
     */
    public function freePlan(array $f, array $pick = []): array
    {
        $out = [];
        foreach (array_keys(self::FREE_KINDS) as $cat) {
            $m = $this->s->material((string) ($f[$cat]['id'] ?? ''));
            if ($m === null) {
                continue;
            }
            $need = Store::num($f[$cat]['qty'] ?? '');
            $out[] = ['material_id' => (string) $m['id'], 'name' => (string) $m['name'], 'unit' => (string) $m['unit'], 'category' => $cat, 'code' => (string) $m['code'],
                'need' => $need !== null && $need > 0 ? round($need, 3) : 0.0, 'stock' => $this->s->stock((string) $m['id']), 'pick' => (string) ($pick[$m['id']] ?? '')];
        }
        return $out;
    }

    /** Pola partii bez wyrobu z zapisanej partii (do edycji i zatwierdzenia wyceny). @return array<string,array{id:string,qty:string}> */
    public function freeFields(array $b): array
    {
        $f = [];
        foreach (json_decode((string) $b['lines_json'], true) ?: [] as $l) {
            $cat = (string) ($l['category'] ?? ($this->s->material((string) $l['material_id'])['category'] ?? ''));
            if (isset(self::FREE_KINDS[$cat]) && !isset($f[$cat])) {
                $f[$cat] = ['id' => (string) $l['material_id'], 'qty' => (float) $l['need'] > 0 ? Ui::n($l['need']) : ''];
            }
        }
        return $f;
    }

    /** Okres przydatności partii w miesiącach (z dat), do edycji partii bez wyrobu. */
    public static function shelfMonths(array $b): int
    {
        if (empty($b['expires_at'])) {
            return 0;
        }
        $a = new \DateTimeImmutable((string) $b['produced_at']);
        $d = $a->diff(new \DateTimeImmutable((string) $b['expires_at']));
        return max(0, $d->y * 12 + $d->m);
    }

    /** Zdjęcie materiałów i dołożenie sztuk (wycena robocza tylko liczy koszt z cen). */
    private function apply(string $id, array $plan, bool $draft, string $user): void
    {
        $b = $this->s->batch($id) ?? [];
        $lines = [];
        $cost = 0.0;
        $known = true;
        $prices = $draft ? $this->s->unitPrices() : [];
        foreach ($plan as $l) {
            $extra = isset($l['category']) ? ['category' => $l['category']] : [];
            if ((float) $l['need'] <= 0) {
                // Partia bez wyrobu: materiał wybrany bez ilości - tylko do numeru i historii.
                $c = 0.0;
                $lines[] = ['material_id' => $l['material_id'], 'name' => $l['name'], 'unit' => $l['unit'], 'need' => 0, 'taken' => [], 'missing' => 0, 'cost' => null] + $extra;
            } elseif ($draft) {
                $unit = $prices[$l['material_id']] ?? null;
                $c = $unit !== null ? round($unit * $l['need'], 4) : null;
                $lines[] = ['material_id' => $l['material_id'], 'name' => $l['name'], 'unit' => $l['unit'], 'need' => $l['need'], 'taken' => [], 'missing' => 0, 'cost' => $c] + $extra;
            } else {
                $r = $this->s->consume($l['material_id'], $l['need'], $l['pick'], $id, 'partia', 'partia ' . $b['code'], $user);
                $costs = array_column($r['taken'], 'cost');
                $c = in_array(null, $costs, true) || $costs === [] ? null : array_sum($costs);
                $lines[] = ['material_id' => $l['material_id'], 'name' => $l['name'], 'unit' => $l['unit'], 'need' => $l['need'],
                    'taken' => $r['taken'], 'missing' => $r['missing'], 'cost' => $c !== null ? round($c, 4) : null] + $extra;
            }
            if ($c === null) {
                $known = false;
            } else {
                $cost += $c;
            }
        }
        $stock = $draft ? [] : $this->addToProduct((string) $b['product_id'], (int) $b['qty'], (string) $b['code']);
        $this->s->update('ext_prod_batches', $id, ['lines_json' => json_encode($lines, JSON_UNESCAPED_UNICODE),
            'stock_json' => json_encode($stock, JSON_UNESCAPED_UNICODE),
            'cost_materials' => array_filter($plan, static fn(array $l): bool => (float) $l['need'] > 0) === [] ? null : round($cost, 2) + ($known ? 0 : 0)]);
    }

    /** Cofnięcie partii: materiały wracają do swoich dostaw, sztuki schodzą z produktu. */
    public function revert(array $b, string $user, string $why): void
    {
        foreach (json_decode((string) $b['lines_json'], true) ?: [] as $l) {
            foreach ($l['taken'] ?? [] as $t) {
                if ((float) $t['qty'] > 0) {
                    $this->s->giveBack((string) $l['material_id'], (string) $t['delivery_id'], (float) $t['qty'], (string) $b['id'], $why . ' ' . $b['code'], $user);
                }
            }
        }
        $st = json_decode((string) $b['stock_json'], true) ?: [];
        if (!empty($st['product_id'])) {
            $this->changeProduct((int) $st['product_id'], -(int) ($st['actual'] ?? 0), -(int) ($st['crm'] ?? 0));
        }
        $this->s->update('ext_prod_batches', (string) $b['id'], ['lines_json' => '[]', 'stock_json' => '{}']);
    }

    public function trash(string $id, string $user): array
    {
        $b = $this->s->batch($id) ?? throw new \RuntimeException('Nie ma takiej partii.');
        $this->s->tx(function () use ($b, $user): void {
            $keep = $b['lines_json'];
            $this->revert($b, $user, 'usunięcie partii');
            // Zapamiętujemy zużycie, żeby przywrócenie z kosza zrobiło to samo.
            $this->s->update('ext_prod_batches', (string) $b['id'], ['deleted_at' => Store::now(), 'lines_json' => $keep]);
        });
        return $b;
    }

    public function restore(string $id, string $user): array
    {
        $b = $this->s->batch($id) ?? throw new \RuntimeException('Nie ma takiej partii.');
        return $this->s->tx(function () use ($b, $user): array {
            $this->s->update('ext_prod_batches', (string) $b['id'], ['deleted_at' => null]);
            $product = $b['product_id'] !== null ? $this->s->product((string) $b['product_id']) : null;
            if ((int) $b['is_draft'] === 0 && (int) $b['imported'] === 0) {
                $pick = [];
                foreach (json_decode((string) $b['lines_json'], true) ?: [] as $l) {
                    $pick[$l['material_id']] = (string) ($l['taken'][0]['delivery_id'] ?? '');
                }
                if ($product !== null) {
                    $this->apply((string) $b['id'], $this->plan($product, (int) $b['qty'], $pick), false, $user);
                } elseif ($b['product_id'] === null) {
                    $this->apply((string) $b['id'], $this->freePlan($this->freeFields($b), $pick), false, $user);
                }
            }
            return $this->s->batch((string) $b['id']) ?? [];
        });
    }

    /** Wycena robocza → partia z prawdziwym numerem (wg daty produkcji). */
    public function approve(string $id, string $user, bool $allowShort = false): array
    {
        $b = $this->s->batch($id) ?? throw new \RuntimeException('Nie ma takiej wyceny.');
        $shift = $this->s->one('SELECT id FROM ext_prod_dict WHERE kind = ? AND name = ?', ['shift', $b['shift']]);
        $person = $this->s->one('SELECT id FROM ext_prod_dict WHERE kind = ? AND name = ?', ['person', $b['person']]);
        $free = $b['product_id'] === null ? ['free' => 1, 'product_name' => $b['product_name'], 'shelf_life_months' => self::shelfMonths($b), 'f' => $this->freeFields($b)] : [];
        return $this->save($id, $free + ['product_id' => $b['product_id'], 'produced_at' => $b['produced_at'], 'qty' => $b['qty'],
            'person' => $person['id'] ?? $b['person'], 'shift' => $shift['id'] ?? '', 'work_hours' => $b['work_hours'], 'notes' => $b['notes'],
            'allow_short' => $allowShort ? 1 : 0], $user);
    }

    /** @return array<string,mixed> co dołożono (do cofnięcia) */
    private function addToProduct(string $productId, int $qty, string $code): array
    {
        $target = (string) ($this->settings['stock_target'] ?? 'actual');
        $product = $productId !== '' ? $this->s->product($productId) : null;
        if ($target === 'none' || $product === null || trim((string) $product['sku']) === '') {
            return [];
        }
        $crm = $this->crmProduct((string) $product['sku']);
        if ($crm === null) {
            return ['note' => 'Brak produktu CRM o SKU ' . $product['sku']];
        }
        $actual = 0;
        if (in_array($target, ['actual', 'both'], true) && array_key_exists('actual_stock', $crm) && $crm['actual_stock'] !== null) {
            $actual = $qty;
        }
        $pase = in_array($target, ['crm', 'both'], true) ? $qty : 0;
        $this->changeProduct((int) $crm['id'], $actual, $pase);
        return ['product_id' => (int) $crm['id'], 'sku' => $crm['sku'], 'actual' => $actual, 'crm' => $pase,
            'note' => $actual === 0 && in_array($target, ['actual', 'both'], true) ? 'stan faktyczny produktu nie jest wpisany' : ''];
    }

    public function crmProduct(string $sku): ?array
    {
        try {
            return $this->s->one('SELECT * FROM products WHERE sku = ?', [$sku]);
        } catch (\PDOException) {
            return null;
        }
    }

    private function changeProduct(int $id, int $actual, int $crm): void
    {
        if ($actual !== 0) {
            $this->s->run('UPDATE products SET actual_stock = CASE WHEN COALESCE(actual_stock, 0) + ? < 0 THEN 0 ELSE COALESCE(actual_stock, 0) + ? END
                WHERE id = ? AND actual_stock IS NOT NULL', [$actual, $actual, $id]);
        }
        if ($crm !== 0) {
            $this->s->run('UPDATE products SET pase_stock = CASE WHEN COALESCE(pase_stock, 0) + ? < 0 THEN 0 ELSE COALESCE(pase_stock, 0) + ? END WHERE id = ?',
                [$crm, $crm, $id]);
            // Stan CRM idzie do sklepu jak przy ręcznej zmianie na liście produktów (handler sam sprawdzi, czy CRM jest nadrzędny).
            try {
                (new \Pase\Queue\Queue($this->s->pdo))->enqueue('woo.stock.push', ['product_id' => $id], 'woo.stock.push:' . $id . ':' . substr((string) microtime(true), -8));
            } catch (\Throwable) {
                // bez kolejki (np. test na SQLite) stan zostaje w CRM
            }
        }
    }

    private function dictRow(string $kind, string $id): ?array
    {
        return $id === '' ? null : $this->s->one('SELECT * FROM ext_prod_dict WHERE kind = ? AND id = ?', [$kind, $id]);
    }
}
