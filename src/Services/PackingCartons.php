<?php
declare(strict_types=1);

namespace Pase\Services;

use Pase\Repository\SettingsRepository;

/**
 * Kartony do pakowania i sugestia kartonu dla zamówienia (asystent pakowania → „Gabaryt paczki”).
 *
 * Lista kartonów jest w ustawieniach pakowania (nazwa, wymiary wewnętrzne w cm, maks. waga w kg).
 * Dopóki nikt jej nie zapisał, są to gabaryty InPost A/B/C. Pusta lista = sekcja ukryta.
 *
 * Sugestia = najmniejszy karton, do którego mieszczą się wszystkie sztuki (sprawdzamy ułożenie
 * pudełek z obracaniem, nie tylko objętość) i który uniesie ich wagę. Wymiary i waga produktów
 * pochodzą z magazynu CRM (products.length/width/height w cm, weight w kg).
 */
final class PackingCartons
{
    public const SETTING = 'PACKING_CARTONS';
    public const MAX_CARTONS = 30;
    /** Powyżej tylu sztuk nie układamy pudełek, tylko liczymy objętość (szybko, orientacyjnie). */
    private const MAX_UNITS_TO_PLACE = 60;
    private const EPS = 1e-6;

    /** @return list<array{name:string,dims:array{0:float,1:float,2:float},max_kg:?float}> */
    public static function defaults(): array
    {
        return [
            ['name' => 'Gabaryt A', 'dims' => [8.0, 38.0, 64.0], 'max_kg' => 25.0],
            ['name' => 'Gabaryt B', 'dims' => [19.0, 38.0, 64.0], 'max_kg' => 25.0],
            ['name' => 'Gabaryt C', 'dims' => [41.0, 38.0, 64.0], 'max_kg' => 25.0],
        ];
    }

    /** @return list<array{name:string,dims:array{0:float,1:float,2:float},max_kg:?float}> */
    public static function load(SettingsRepository $settings): array
    {
        $raw = $settings->get(self::SETTING);
        if ($raw === null || trim($raw) === '') {
            return self::defaults();
        }
        $list = json_decode($raw, true);
        if (!is_array($list)) {
            return self::defaults();
        }
        try {
            return self::normalize($list);
        } catch (\RuntimeException) {
            return self::defaults();
        }
    }

    public static function save(SettingsRepository $settings, array $list): array
    {
        $list = self::normalize($list);
        $settings->setMany([self::SETTING => json_encode($list, JSON_UNESCAPED_UNICODE)]);
        return $list;
    }

    /**
     * Sprawdza i porządkuje listę z formularza: [{name, dims:[a,b,c]} albo {name, a, b, c}, max_kg].
     * @return list<array{name:string,dims:array{0:float,1:float,2:float},max_kg:?float}>
     */
    public static function normalize(array $list): array
    {
        if (count($list) > self::MAX_CARTONS) {
            throw new \RuntimeException('Za dużo kartonów (maks. ' . self::MAX_CARTONS . ').');
        }
        $out = [];
        $names = [];
        foreach (array_values($list) as $n => $c) {
            if (!is_array($c)) {
                throw new \RuntimeException('Nieprawidłowy karton.');
            }
            $name = trim((string) ($c['name'] ?? ''));
            $label = 'Karton ' . ($n + 1);
            if ($name === '' || mb_strlen($name) > 40) {
                throw new \RuntimeException($label . ': podaj nazwę (do 40 znaków).');
            }
            if (isset($names[mb_strtolower($name)])) {
                throw new \RuntimeException('Dwa kartony nazywają się „' . $name . '” — nazwy muszą być różne.');
            }
            $names[mb_strtolower($name)] = true;
            $dims = isset($c['dims']) && is_array($c['dims']) ? array_values($c['dims']) : [$c['a'] ?? null, $c['b'] ?? null, $c['c'] ?? null];
            $d = [];
            foreach (array_slice($dims, 0, 3) as $v) {
                $f = self::num($v);
                if ($f === null || $f <= 0 || $f > 500) {
                    throw new \RuntimeException('„' . $name . '”: wymiary w cm, większe od 0 (maks. 500).');
                }
                $d[] = round($f, 1);
            }
            if (count($d) !== 3) {
                throw new \RuntimeException('„' . $name . '”: podaj trzy wymiary.');
            }
            $kg = self::num($c['max_kg'] ?? null);
            if ($kg !== null && ($kg <= 0 || $kg > 1000)) {
                throw new \RuntimeException('„' . $name . '”: maks. waga w kg, większa od 0 (albo puste = bez limitu).');
            }
            $out[] = ['name' => $name, 'dims' => [$d[0], $d[1], $d[2]], 'max_kg' => $kg !== null ? round($kg, 2) : null];
        }
        usort($out, static fn(array $a, array $b) => self::volume($a['dims']) <=> self::volume($b['dims']));
        return $out;
    }

    /**
     * Sugestia kartonu.
     *
     * @param list<array{name:string,qty:int,dims:?array,weight:?float}> $items dims w cm, weight w kg za sztukę
     * @param list<array{name:string,dims:array,max_kg:?float}> $cartons
     * @return array{options:list<array>,suggested:?string,weight:?float,missingDims:list<string>,missingWeight:list<string>,suspectWeight:list<string>,message:?string,approx:bool}
     */
    public static function suggest(array $items, array $cartons): array
    {
        $boxes = [];
        $missingDims = [];
        $missingWeight = [];
        $suspect = [];
        $weight = 0.0;
        $hasWeight = false;
        $units = 0;
        foreach ($items as $it) {
            $qty = max(0, (int) $it['qty']);
            if ($qty === 0) {
                continue;
            }
            $units += $qty;
            $d = self::dims($it['dims'] ?? null);
            if ($d === null) {
                $missingDims[] = (string) $it['name'];
            } else {
                for ($i = 0; $i < $qty; $i++) {
                    $boxes[] = $d;
                }
            }
            $w = self::num($it['weight'] ?? null);
            if ($w !== null && $d !== null && \Pase\Support\Units::implausibleWeight($w, ...$d)) {
                // 240 „kg” przy świecy 6 × 6 × 6 cm to gramy zapisane jako kg - liczymy jako gramy.
                $suspect[] = (string) $it['name'] . ' (' . self::fmt($w) . ' kg → ' . self::fmt($w) . ' g)';
                $w /= 1000;
            }
            if ($w === null || $w <= 0) {
                $missingWeight[] = (string) $it['name'];
            } else {
                $weight += $w * $qty;
                $hasWeight = true;
            }
        }
        $missingDims = array_values(array_unique($missingDims));
        $missingWeight = array_values(array_unique($missingWeight));

        $approx = count($boxes) > self::MAX_UNITS_TO_PLACE;
        $options = [];
        $suggested = null;
        foreach ($cartons as $c) {
            $fits = null;       // null = nie wiadomo (brak wymiarów wszystkich produktów)
            $why = null;
            if ($boxes !== []) {
                $fits = $approx ? self::fitsByVolume($boxes, $c['dims']) : self::fits($boxes, $c['dims']);
                if (!$fits) {
                    $why = 'nie zmieści się';
                }
            }
            if ($c['max_kg'] !== null && $hasWeight && $weight > $c['max_kg'] + self::EPS) {
                $fits = false;
                $why = 'za ciężko (maks. ' . self::fmt($c['max_kg']) . ' kg)';
            }
            if ($fits === true && $suggested === null) {
                $suggested = $c['name'];
            }
            $options[] = ['name' => $c['name'], 'dims' => implode('×', array_map([self::class, 'fmt'], $c['dims'])),
                          'maxKg' => $c['max_kg'], 'fits' => $fits, 'why' => $why];
        }

        $message = null;
        if ($units > 0 && $boxes === []) {
            $message = 'Produkty nie mają wymiarów w magazynie — nie da się dobrać kartonu.';
        } elseif ($boxes !== [] && $suggested === null && $cartons !== []) {
            $tooBig = [];
            foreach ($items as $it) {
                $d = self::dims($it['dims'] ?? null);
                if ($d !== null && !self::fitsAnyAlone($d, $cartons)) {
                    $tooBig[] = (string) $it['name'];
                }
            }
            $message = $tooBig !== []
                ? 'Nie mieści się w żadnym kartonie: ' . implode(', ', array_unique($tooBig)) . '.'
                : 'Nic nie pasuje — podziel zamówienie na kilka paczek albo dodaj większy karton w ustawieniach.';
        }

        return [
            'options'       => $options,
            'suggested'     => $suggested,
            'weight'        => $hasWeight ? round($weight, 3) : null,
            'missingDims'   => $missingDims,
            'missingWeight' => $missingWeight,
            'suspectWeight' => array_values(array_unique($suspect)),
            'message'       => $message,
            'approx'        => $approx,
        ];
    }

    /**
     * Czy pudełka zmieszczą się w kartonie. Heurystyka „punktów narożnych”: największe pudełka
     * najpierw, każde w pierwszym wolnym miejscu (od dna, od ściany). Próbujemy kilku wariantów
     * (każdy obrót pierwszego pudełka × preferencja: na płasko / na boku / na stojąco).
     * Może czasem nie znaleźć ułożenia, które istnieje (wtedy podpowie większy karton), ale nigdy
     * nie powie „mieści się”, gdy się nie mieści.
     *
     * @param list<array{0:float,1:float,2:float}> $boxes
     * @param array{0:float,1:float,2:float} $carton
     */
    public static function fits(array $boxes, array $carton): bool
    {
        if ($boxes === []) {
            return true;
        }
        if (!self::fitsByVolume($boxes, $carton)) {
            return false;
        }
        $C = [(float) $carton[0], (float) $carton[1], (float) $carton[2]];
        usort($boxes, static fn(array $a, array $b) => self::volume($b) <=> self::volume($a) ?: max($b) <=> max($a));
        foreach (self::rotations($boxes[0]) as $first) {
            foreach ([2, 1, 0] as $axis) {   // która oś pudełka ma być najkrótsza
                if (self::greedy($boxes, $C, $first, $axis)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function greedy(array $boxes, array $C, array $first, int $axis): bool
    {
        $placed = [];                 // [x, y, z, dx, dy, dz]
        $points = [[0.0, 0.0, 0.0]];
        foreach ($boxes as $n => $b) {
            $rots = $n === 0 ? [$first] : self::rotations($b);
            if ($n > 0) {
                usort($rots, static fn(array $p, array $q) => $p[$axis] <=> $q[$axis]);
            }
            usort($points, static fn(array $p, array $q) => [$p[2], $p[1], $p[0]] <=> [$q[2], $q[1], $q[0]]);
            $done = false;
            foreach ($points as $pi => $p) {
                foreach ($rots as $r) {
                    if ($p[0] + $r[0] > $C[0] + self::EPS || $p[1] + $r[1] > $C[1] + self::EPS || $p[2] + $r[2] > $C[2] + self::EPS) {
                        continue;
                    }
                    foreach ($placed as $q) {
                        if ($p[0] < $q[0] + $q[3] - self::EPS && $q[0] < $p[0] + $r[0] - self::EPS
                            && $p[1] < $q[1] + $q[4] - self::EPS && $q[1] < $p[1] + $r[1] - self::EPS
                            && $p[2] < $q[2] + $q[5] - self::EPS && $q[2] < $p[2] + $r[2] - self::EPS) {
                            continue 2;
                        }
                    }
                    $placed[] = [$p[0], $p[1], $p[2], $r[0], $r[1], $r[2]];
                    unset($points[$pi]);
                    $points[] = [$p[0] + $r[0], $p[1], $p[2]];
                    $points[] = [$p[0], $p[1] + $r[1], $p[2]];
                    $points[] = [$p[0], $p[1], $p[2] + $r[2]];
                    $points = array_values($points);
                    $done = true;
                    break 2;
                }
            }
            if (!$done) {
                return false;
            }
        }
        return true;
    }

    /** Warunek konieczny: łączna objętość i każde pudełko osobno (po obróceniu). */
    private static function fitsByVolume(array $boxes, array $carton): bool
    {
        $c = $carton;
        sort($c);
        $vol = 0.0;
        foreach ($boxes as $b) {
            $s = $b;
            sort($s);
            if ($s[0] > $c[0] + self::EPS || $s[1] > $c[1] + self::EPS || $s[2] > $c[2] + self::EPS) {
                return false;
            }
            $vol += self::volume($b);
        }
        return $vol <= self::volume($carton) + self::EPS;
    }

    private static function fitsAnyAlone(array $box, array $cartons): bool
    {
        foreach ($cartons as $c) {
            if (self::fitsByVolume([$box], $c['dims'])) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array{0:float,1:float,2:float}> */
    private static function rotations(array $b): array
    {
        [$a, $c, $d] = $b;
        $all = [[$a, $c, $d], [$a, $d, $c], [$c, $a, $d], [$c, $d, $a], [$d, $a, $c], [$d, $c, $a]];
        $uniq = [];
        foreach ($all as $r) {
            $uniq[implode(':', $r)] = $r;
        }
        return array_values($uniq);
    }

    private static function volume(array $d): float
    {
        return (float) $d[0] * (float) $d[1] * (float) $d[2];
    }

    /** @return array{0:float,1:float,2:float}|null */
    private static function dims(mixed $d): ?array
    {
        if (!is_array($d) || count($d) !== 3) {
            return null;
        }
        $out = [];
        foreach (array_values($d) as $v) {
            $f = self::num($v);
            if ($f === null || $f <= 0) {
                return null;
            }
            $out[] = $f;
        }
        return [$out[0], $out[1], $out[2]];
    }

    private static function num(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (!is_string($v)) {
            return null;
        }
        $v = str_replace([',', ' '], ['.', ''], trim($v));
        return $v !== '' && is_numeric($v) ? (float) $v : null;
    }

    public static function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
    }
}
