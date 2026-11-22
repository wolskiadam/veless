<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;

/**
 * Pracownia świec w wersji demonstracyjnej: zapachy (wtyczka Zapachy) oraz materiały z dostawami,
 * wyroby z recepturą i kilka partii (wtyczka Produkcja), powiązane z produktami z DemoData po SKU.
 * Dzięki temu strony Produkcja i Zapachy w demo nie są puste. Wołane przez cli/demo_reset.php
 * po DemoData::seed(); gdy którejś wtyczki nie ma w instalacji, jej część jest pomijana.
 */
final class DemoWorkshop
{
    /** SKU => [zapach, kod wyrobu, gramy wosku, pojemność ml, knot] - zgodnie z produktami z DemoData. */
    private const CANDLES = [
        'SW-LAW-180' => ['Lawenda', 'LA', 150, 180, 'knot'],
        'SW-CYN-180' => ['Cynamon i pomarańcza', 'CY', 150, 180, 'knot'],
        'SW-DRZ-300' => ['Drzewo sandałowe', 'DS', 250, 300, 'drewno'],
        'SW-WAN-180' => ['Wanilia', 'WA', 150, 180, 'knot'],
        'SW-ROZ-300' => ['Róża i piżmo', 'RP', 250, 300, 'knot'],
        'SW-KAW-180' => ['Kawa z karmelem', 'KK', 150, 180, 'knot'],
    ];

    /** Zapachy: [nr miejsca, nazwa, nuty głowy, serca, bazy, maks. stężenie %]. */
    private const FRAGRANCES = [
        ['1', 'Lawenda', 'bergamotka, eukaliptus', 'lawenda, rozmaryn', 'piżmo, drewno cedrowe', 10],
        ['2', 'Cynamon i pomarańcza', 'pomarańcza, mandarynka', 'cynamon, goździk', 'wanilia, żywica', 8],
        ['3', 'Drzewo sandałowe', 'kardamon', 'drzewo sandałowe, irys', 'paczula, ambra', 10],
        ['4', 'Wanilia', 'karmel', 'wanilia, kwiat tiare', 'tonka, piżmo', 10],
        ['5', 'Róża i piżmo', 'liczi, różowy pieprz', 'róża damasceńska, piwonia', 'piżmo, drewno kaszmirowe', 9],
        ['6', 'Kawa z karmelem', 'espresso', 'karmel, orzech laskowy', 'wanilia, kakao', 8],
        ['7', 'Trawa cytrynowa', 'trawa cytrynowa, limonka', 'imbir, zielona herbata', 'wetiwer', 10],
        ['8', 'Sosnowy las', 'igliwie sosny, jałowiec', 'eukaliptus, mięta', 'mech dębowy, żywica', 10],
    ];

    public static function seed(PDO $pdo): void
    {
        self::autoload();
        $products = [];
        foreach ($pdo->query('SELECT id, sku FROM products')->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $products[(string) $p['sku']] = (int) $p['id'];
        }
        $materials = class_exists(\PaseExt\Produkcja\Store::class) ? self::production($pdo) : [];
        if (class_exists(\PaseExt\Zapachy\Store::class)) {
            self::fragrances($pdo, $products, $materials);
        }
    }

    /** @return array<string,string> nazwa zapachu => id materiału */
    private static function production(PDO $pdo): array
    {
        $s = new \PaseExt\Produkcja\Store($pdo);
        $day = static fn(int $ago): string => date('Y-m-d', time() - $ago * 86400);
        $mat = static function (array $in, array $delivery) use ($s, $day): string {
            $m = $s->saveMaterial(null, $in);
            $s->addDelivery((string) $m['id'], $delivery + ['received_at' => $day(26)], 'demo');
            return (string) $m['id'];
        };

        $wax = $mat(['name' => 'Wosk sojowy do pojemników', 'category' => 'wosk', 'code' => 'SOJ', 'unit' => 'g', 'min_stock' => 5000, 'supplier' => 'Hurtownia Wosków Demo'],
            ['qty' => 30000, 'price_total' => 780, 'lot_no' => 'WS-2611', 'expires_at' => $day(-700)]);
        $wick = $mat(['name' => 'Knot bawełniany ECO 8', 'category' => 'knot', 'code' => 'E8', 'unit' => 'szt', 'min_stock' => 50, 'supplier' => 'Knoty Demo'],
            ['qty' => 400, 'price_total' => 96, 'lot_no' => 'K-118']);
        $wood = $mat(['name' => 'Knot drewniany 13 mm', 'category' => 'knot', 'code' => 'D13', 'unit' => 'szt', 'min_stock' => 30, 'supplier' => 'Knoty Demo'],
            ['qty' => 120, 'price_total' => 84, 'lot_no' => 'KD-07']);
        $jar180 = $mat(['name' => 'Słoik szklany 180 ml, bursztynowy', 'category' => 'pojemnik', 'code' => 'S18', 'unit' => 'szt', 'min_stock' => 40, 'supplier' => 'Szkło Demo', 'fill_g' => 150],
            ['qty' => 300, 'price_total' => 750, 'lot_no' => 'SZ-4410']);
        $jar300 = $mat(['name' => 'Słoik szklany 300 ml, przezroczysty', 'category' => 'pojemnik', 'code' => 'S30', 'unit' => 'szt', 'min_stock' => 20, 'supplier' => 'Szkło Demo', 'fill_g' => 250],
            ['qty' => 120, 'price_total' => 420, 'lot_no' => 'SZ-4417']);
        $label = $mat(['name' => 'Etykieta CLP okrągła 50 mm', 'category' => 'etykieta', 'code' => 'ET5', 'unit' => 'szt', 'supplier' => 'Drukarnia Demo'],
            ['qty' => 1000, 'price_total' => 120]);

        $oils = [];
        foreach (self::FRAGRANCES as $i => [, $name]) {
            $oils[$name] = $mat(['name' => 'Olejek zapachowy ' . $name, 'category' => 'zapach', 'code' => 'Z' . ($i + 1), 'unit' => 'g', 'min_stock' => 200, 'supplier' => 'Hurtownia Zapachów Demo'],
                ['qty' => $i === 3 ? 180 : 1000, 'price_total' => $i === 3 ? 34 : 190, 'lot_no' => 'OZ-' . (5100 + $i * 7), 'expires_at' => $day(-500)]);
        }

        $s->saveDict('person', ['Ania', 'Kasia'], ['A', 'K'], [32, 30]);
        $s->saveDict('shift', ['Rano', 'Popołudnie'], ['R', 'P'], []);
        $people = array_column($s->dict('person'), 'id');
        $shifts = array_column($s->dict('shift'), 'id');

        $made = [];
        foreach (self::CANDLES as $sku => [$scent, $code, $grams, $ml, $wickKind]) {
            $made[$sku] = $s->saveProduct(null, [
                'name' => 'Świeca ' . $scent . ' ' . $ml . ' ml', 'sku' => $sku, 'code' => $code, 'shelf_life_months' => 24,
                'price' => $ml === 300 ? 64.90 : 39.90, 'min_stock' => 10, 'labor_minutes' => 6, 'weight_g' => $ml === 300 ? 420 : 260, 'extra_cost' => 1.20,
                'recipe' => [
                    ['material_id' => $wax, 'qty' => $grams],
                    ['material_id' => $oils[$scent], 'qty' => round($grams * 0.08, 1)],
                    ['material_id' => $wickKind === 'drewno' ? $wood : $wick, 'qty' => 1],
                    ['material_id' => $ml === 300 ? $jar300 : $jar180, 'qty' => 1],
                    ['material_id' => $label, 'qty' => 1],
                ],
            ]);
        }

        $prod = new \PaseExt\Produkcja\Production($s, ['stock_target' => 'none', 'hourly_rate' => 30]);
        $plan = [['SW-LAW-180', 24, 18], ['SW-CYN-180', 20, 14], ['SW-DRZ-300', 12, 11], ['SW-ROZ-300', 12, 7], ['SW-LAW-180', 18, 4], ['SW-KAW-180', 16, 2]];
        foreach ($plan as $i => [$sku, $qty, $ago]) {
            try {
                $prod->save(null, ['product_id' => (string) $made[$sku]['id'], 'qty' => $qty, 'produced_at' => $day($ago),
                    'person' => $people[$i % 2] ?? '', 'shift' => $shifts[$i % 2] ?? '', 'work_hours' => round($qty * 6 / 60, 1),
                    'notes' => $i === 2 ? 'Knot drewniany przycięty do 5 mm.' : ''], 'demo');
            } catch (\Throwable $e) {
                fwrite(STDERR, 'Demo: partia ' . $sku . ' pominięta: ' . $e->getMessage() . "\n");
            }
        }
        return $oils;
    }

    /**
     * @param array<string,int> $products SKU => products.id
     * @param array<string,string> $materials nazwa zapachu => id materiału (olejek w Produkcji)
     */
    private static function fragrances(PDO $pdo, array $products, array $materials): void
    {
        $z = new \PaseExt\Zapachy\Store($pdo);
        $bySku = [];
        foreach (self::CANDLES as $sku => [$scent]) {
            $bySku[$scent][] = $sku;
        }
        $bySku['Trawa cytrynowa'][] = 'WS-LEM-1';
        $bySku['Sosnowy las'][] = 'WS-SOS-1';
        foreach (self::FRAGRANCES as [$no, $name, $head, $heart, $base, $max]) {
            $f = $z->saveFragrance(null, ['storage_no' => $no, 'name' => $name, 'producer' => 'Hurtownia Zapachów Demo', 'producer_code' => 'HZ-' . (100 + (int) $no),
                'head_notes' => $head, 'heart_notes' => $heart, 'base_notes' => $base, 'max_concentration' => $max, 'price_per_g' => 0.19,
                'material_id' => $materials[$name] ?? '', 'description' => 'Olejek zapachowy do świec sojowych i wosków.']);
            $ids = array_values(array_filter(array_map(static fn(string $sku) => $products[$sku] ?? 0, $bySku[$name] ?? [])));
            if ($ids !== []) {
                $z->link((string) $f['id'], $ids, 'demo');
            }
        }
    }

    /** Klasy wtyczek (PaseExt\<Slug>\Klasa -> extensions/<slug>/Klasa.php), gdy rejestr wtyczek jeszcze ich nie załadował. */
    private static function autoload(): void
    {
        spl_autoload_register(static function (string $class): void {
            if (!str_starts_with($class, 'PaseExt\\')) {
                return;
            }
            $parts = explode('\\', substr($class, 8));
            $parts[0] = strtolower($parts[0]);
            $path = PASE_ROOT . '/extensions/' . implode('/', $parts) . '.php';
            if (is_file($path)) {
                require_once $path;
            }
        });
    }
}
