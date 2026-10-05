<?php
declare(strict_types=1);

namespace Pase\Services;

use PDO;
use Pase\Repository\SettingsRepository;

/**
 * Dashboard Allegro: jakość sprzedaży i finanse.
 *
 * Źródła w REST API Allegro:
 *   - GET /sale/quality — poziom, punkty i wyniki w miarach z ostatnich 30 dni (zapisujemy każdy dzień
 *     w allegro_sales_quality, więc historia rośnie dłużej niż 30 dni),
 *   - GET /payments/payment-operations — saldo portfeli (dostępne / oczekujące środki) i wpłaty kupujących,
 *   - GET /billing/billing-entries — opłaty Allegro w miesiącu i saldo rozliczeń z Allegro.
 * Statusu programu Super Sprzedawca API nie udostępnia — postęp liczymy z historii poziomów.
 *
 * Dane odświeża Scheduler (co REFRESH_EVERY_MIN) albo przycisk na stronie; strona czyta tylko cache.
 */
final class AllegroDashboard
{
    public const FINANCE_KEY       = 'allegro_dashboard_finance';
    public const QUALITY_ERROR_KEY = 'allegro_dashboard_quality_error';
    public const AT_KEY            = 'ALLEGRO_DASHBOARD_AT';
    public const REFRESH_EVERY_MIN = 60;

    public const QUALITY_URL      = 'https://salescenter.allegro.com/sales-quality';
    public const FINANCE_URL      = 'https://salescenter.allegro.com/finance-center?marketplaceId=all';
    public const SUPER_SELLER_URL = 'https://help.allegro.com/sell/en/d/super-seller-program';
    public const QUALITY_HELP_URL = 'https://help.allegro.com/sell/en/d/sales-quality-dashboard-and-metrics';

    /** Progi punktowe poziomów (pomoc Allegro): Super 230–400, Dobry 101–229, Neutralny 0–100, poniżej 0 wymaga poprawy. */
    public const THRESHOLDS = ['SUPER' => 230, 'GOOD' => 101, 'NEUTRAL' => 0];
    public const MAX_SCORE  = 400;

    public const LEVEL_LABELS = ['SUPER' => 'Super', 'GOOD' => 'Dobry', 'NEUTRAL' => 'Neutralny', 'NEEDS_IMPROVEMENT' => 'Wymaga poprawy'];

    /** Warunki programu Super Sprzedawca widoczne w panelu Allegro: dni z rzędu na danym poziomie. */
    public const SUPER_SELLER_GOALS = [
        'super' => ['label' => 'Poziom Super przez 14 dni', 'days' => 14, 'levels' => ['SUPER']],
        'good'  => ['label' => 'Poziom Dobry lub wyższy przez 30 dni', 'days' => 30, 'levels' => ['SUPER', 'GOOD']],
    ];

    public const FEE_GROUPS = ['mandatory' => 'Obowiązkowe', 'delivery' => 'Dostawa', 'promo' => 'Reklama i promowanie'];

    public function __construct(private readonly PDO $pdo) {}

    public static function migrate(PDO $pdo): void
    {
        $tail = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        $pdo->exec("CREATE TABLE IF NOT EXISTS allegro_sales_quality (
            result_for DATE NOT NULL PRIMARY KEY, score INT NOT NULL DEFAULT 0, max_score INT NOT NULL DEFAULT 0,
            grade VARCHAR(40) NOT NULL DEFAULT '', metrics TEXT NULL, fetched_at DATETIME NOT NULL
        )$tail");
    }

    // ------------------------------------------------------------
    //  Jakość sprzedaży
    // ------------------------------------------------------------

    /**
     * Poziom (SUPER / GOOD / NEUTRAL / NEEDS_IMPROVEMENT) z pola grade, a gdy Allegro zwróci
     * nieznaną wartość — z progów punktowych.
     */
    public static function level(string $grade, int $score): string
    {
        $g = strtoupper($grade);
        return match (true) {
            str_contains($g, 'SUPER')                                                            => 'SUPER',
            str_contains($g, 'NEED') || str_contains($g, 'IMPROV') || str_contains($g, 'BAD') || str_contains($g, 'POOR') => 'NEEDS_IMPROVEMENT',
            str_contains($g, 'GOOD')                                                             => 'GOOD',
            str_contains($g, 'NEUTRAL')                                                          => 'NEUTRAL',
            $score >= self::THRESHOLDS['SUPER']                                                  => 'SUPER',
            $score >= self::THRESHOLDS['GOOD']                                                   => 'GOOD',
            $score >= self::THRESHOLDS['NEUTRAL']                                                => 'NEUTRAL',
            default                                                                              => 'NEEDS_IMPROVEMENT',
        };
    }

    /**
     * Zapisuje dni z GET /sale/quality (ten sam dzień nadpisujemy — Allegro liczy go raz dziennie).
     * @param array<int,array<string,mixed>> $items
     */
    public function saveQuality(array $items): int
    {
        $sqlite = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $st = $this->pdo->prepare($sqlite
            ? 'INSERT INTO allegro_sales_quality (result_for, score, max_score, grade, metrics, fetched_at) VALUES (?, ?, ?, ?, ?, ?)
               ON CONFLICT(result_for) DO UPDATE SET score = excluded.score, max_score = excluded.max_score, grade = excluded.grade, metrics = excluded.metrics, fetched_at = excluded.fetched_at'
            : 'INSERT INTO allegro_sales_quality (result_for, score, max_score, grade, metrics, fetched_at) VALUES (?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE score = VALUES(score), max_score = VALUES(max_score), grade = VALUES(grade), metrics = VALUES(metrics), fetched_at = VALUES(fetched_at)');
        $n = 0;
        foreach ($items as $q) {
            $day = substr((string) ($q['resultFor'] ?? $q['date'] ?? ''), 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                continue;
            }
            $metrics = array_values(array_filter((array) ($q['metrics'] ?? []), 'is_array'));
            $st->execute([
                $day, (int) ($q['score'] ?? 0), (int) ($q['maxScore'] ?? 0), mb_substr((string) ($q['grade'] ?? ''), 0, 40),
                json_encode($metrics, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'),
            ]);
            $n++;
        }
        return $n;
    }

    /**
     * Historia jakości od najstarszego dnia.
     * @return array<int,array{day:string,score:int,maxScore:int,grade:string,level:string,metrics:array<int,array<string,mixed>>}>
     */
    public function qualityHistory(int $days = 90): array
    {
        $rows = $this->pdo->query('SELECT * FROM allegro_sales_quality ORDER BY result_for DESC LIMIT ' . max(1, $days))->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach (array_reverse($rows) as $r) {
            $out[] = [
                'day' => (string) $r['result_for'], 'score' => (int) $r['score'], 'maxScore' => (int) $r['max_score'],
                'grade' => (string) $r['grade'], 'level' => self::level((string) $r['grade'], (int) $r['score']),
                'metrics' => array_values(array_filter((array) json_decode((string) $r['metrics'], true), 'is_array')),
            ];
        }
        return $out;
    }

    /**
     * Wartość miary do kolumny „Wartość" (np. „100%", „Brak reklamacji"). Allegro nie opisuje tego
     * pola w przykładzie odpowiedzi, więc bierzemy pierwsze znane pole tekstowe/liczbowe, jeśli jest.
     * @param array<string,mixed> $m
     */
    public static function metricValue(array $m): string
    {
        foreach (['value', 'result', 'displayValue', 'description'] as $k) {
            $v = $m[$k] ?? null;
            if (is_array($v)) {
                $v = $v['value'] ?? $v['amount'] ?? $v['text'] ?? null;
            }
            if (is_scalar($v) && (string) $v !== '') {
                return (string) $v;
            }
        }
        return '';
    }

    /**
     * Miary z ostatniego dnia ze zmianą względem dnia poprzedniego, najgorszy wynik (najwięcej brakujących punktów) na górze.
     * @param array<int,array<string,mixed>> $history z qualityHistory()
     * @return array{day:?string,score:int,maxScore:int,level:string,delta:?int,metrics:array<int,array<string,mixed>>,biggestDrop:?array<string,mixed>,biggestRise:?array<string,mixed>}
     */
    public static function currentQuality(array $history): array
    {
        $last = $history[count($history) - 1] ?? null;
        if ($last === null) {
            return ['day' => null, 'score' => 0, 'maxScore' => self::MAX_SCORE, 'level' => 'NEUTRAL', 'delta' => null, 'metrics' => [], 'biggestDrop' => null, 'biggestRise' => null];
        }
        $prev = null;
        $yesterday = date('Y-m-d', strtotime($last['day'] . ' -1 day'));
        foreach ($history as $h) {
            if ($h['day'] === $yesterday) {
                $prev = $h;
            }
        }
        $prevScores = [];
        foreach ($prev['metrics'] ?? [] as $m) {
            $prevScores[(string) ($m['code'] ?? $m['name'] ?? '')] = (int) ($m['score'] ?? 0);
        }
        $metrics = [];
        foreach ($last['metrics'] as $m) {
            $code = (string) ($m['code'] ?? $m['name'] ?? '');
            $score = (int) ($m['score'] ?? 0);
            $metrics[] = [
                'code' => $code, 'name' => (string) ($m['name'] ?? $code), 'score' => $score, 'maxScore' => (int) ($m['maxScore'] ?? 0),
                'value' => self::metricValue($m),
                'delta' => array_key_exists($code, $prevScores) ? $score - $prevScores[$code] : null,
            ];
        }
        usort($metrics, static fn($a, $b) => ($b['maxScore'] - $b['score']) <=> ($a['maxScore'] - $a['score']) ?: $b['maxScore'] <=> $a['maxScore']);
        $drop = $rise = null;
        foreach ($metrics as $m) {
            if ($m['delta'] !== null && $m['delta'] < 0 && ($drop === null || $m['delta'] < $drop['delta'])) {
                $drop = $m;
            }
            if ($m['delta'] !== null && $m['delta'] > 0 && ($rise === null || $m['delta'] > $rise['delta'])) {
                $rise = $m;
            }
        }
        return [
            'day' => $last['day'], 'score' => $last['score'], 'maxScore' => $last['maxScore'] > 0 ? $last['maxScore'] : self::MAX_SCORE,
            'level' => $last['level'], 'delta' => $prev !== null ? $last['score'] - $prev['score'] : null,
            'metrics' => $metrics, 'biggestDrop' => $drop, 'biggestRise' => $rise,
        ];
    }

    /**
     * Postęp warunków Super Sprzedawcy: ile kolejnych dni (licząc wstecz od ostatniego wyniku) konto
     * ma wymagany poziom. Przerwa w historii przerywa serię.
     * @param array<int,array<string,mixed>> $history
     * @return array<string,array{label:string,days:int,goal:int,met:bool}>
     */
    public static function superSellerProgress(array $history): array
    {
        $out = [];
        foreach (self::SUPER_SELLER_GOALS as $key => $goal) {
            $streak = 0;
            $expected = null;
            for ($i = count($history) - 1; $i >= 0; $i--) {
                $h = $history[$i];
                if (($expected !== null && $h['day'] !== $expected) || !in_array($h['level'], $goal['levels'], true)) {
                    break;
                }
                $streak++;
                $expected = date('Y-m-d', strtotime($h['day'] . ' -1 day'));
            }
            $out[$key] = ['label' => $goal['label'], 'days' => $streak, 'goal' => $goal['days'], 'met' => $streak >= $goal['days']];
        }
        return $out;
    }

    // ------------------------------------------------------------
    //  Finanse
    // ------------------------------------------------------------

    /**
     * Grupa opłaty jak w Centrum Finansów: null = operacja rozliczeniowa (spłata, pobranie z wpływów,
     * przelew) — to nie jest koszt, tylko ruch salda.
     * @param string[] $adsTypes rodzaje opłat wybrane w panelu Allegro Ads jako reklama
     */
    public static function feeGroup(string $typeId, string $typeName, array $adsTypes = []): ?string
    {
        if (in_array($typeId, $adsTypes, true)) {
            return 'promo';
        }
        $n = mb_strtolower($typeId . ' ' . $typeName);
        if ($typeId === 'PAD' || preg_match('/wpłat|spłat|pobranie opłat|przelew|zasilen|nadpłat|wypłat/u', $n)) {
            return null;
        }
        if (preg_match('/reklam|promow|wyróżn|\bads\b|kampani|sponsor|podświetl|pogrubi|strona działu|pakiet/u', $n)) {
            return 'promo';
        }
        if (preg_match('/dostaw|wysył|przesył|kurier|paczk|inpost|dpd|orlen|\bups\b|\bdhl\b|\bgls\b|poczt|etykiet|smart|one box|one punkt|delivery/u', $n)) {
            return 'delivery';
        }
        return 'mandatory';
    }

    /**
     * Opłaty z rozliczeń w miesiącu: suma, grupy i rodzaje, plus saldo rozliczeń po najnowszej operacji.
     * Opłaty mają ujemną kwotę, zwroty opłat dodatnią — sumy zostają ze znakiem jak w Allegro.
     * @param array<int,array<string,mixed>> $entries
     * @param string[] $adsTypes
     * @return array{total:float,groups:array<string,float>,byType:array<int,array{name:string,group:string,amount:float}>,balance:?float,balanceAt:?string}
     */
    public static function summarizeFees(array $entries, array $adsTypes = []): array
    {
        $groups = array_fill_keys(array_keys(self::FEE_GROUPS), 0.0);
        $types = [];
        $balance = null; $balanceAt = null;
        foreach ($entries as $e) {
            $at = (string) ($e['occurredAt'] ?? '');
            if (isset($e['balance']['amount']) && ($balanceAt === null || strcmp($at, $balanceAt) > 0)) {
                $balance = (float) $e['balance']['amount'];
                $balanceAt = $at;
            }
            $id = (string) ($e['type']['id'] ?? '');
            $name = (string) ($e['type']['name'] ?? $id);
            $group = self::feeGroup($id, $name, $adsTypes);
            if ($group === null) {
                continue;
            }
            $amount = (float) ($e['value']['amount'] ?? 0);
            $groups[$group] += $amount;
            $key = $id !== '' ? $id : $name;
            $types[$key] ??= ['name' => $name, 'group' => $group, 'amount' => 0.0];
            $types[$key]['amount'] += $amount;
        }
        $groups = array_map(static fn($v) => round($v, 2), $groups);
        $types = array_values(array_map(static fn($t) => ['amount' => round($t['amount'], 2)] + $t, $types));
        usort($types, static fn($a, $b) => $a['amount'] <=> $b['amount']);
        return ['total' => round(array_sum($groups), 2), 'groups' => $groups, 'byType' => $types, 'balance' => $balance, 'balanceAt' => $balanceAt];
    }

    /**
     * Saldo portfeli z operacji płatności: najnowsza operacja per operator (AF, PAYU, P24) i rodzaj portfela.
     * @param array<int,array<string,mixed>> $ops
     * @return array{AVAILABLE:?float,WAITING:?float,wallets:array<int,array{operator:string,type:string,amount:float,currency:string,at:string}>}
     */
    public static function walletBalances(array $ops, string $currency = 'PLN'): array
    {
        $latest = [];
        foreach ($ops as $o) {
            $w = (array) ($o['wallet'] ?? []);
            $cur = (string) ($w['balance']['currency'] ?? $o['value']['currency'] ?? '');
            if (!isset($w['balance']['amount']) || ($cur !== '' && $cur !== $currency)) {
                continue;
            }
            $type = strtoupper((string) ($w['type'] ?? ''));
            $op = (string) ($w['paymentOperator'] ?? $w['operator'] ?? '');
            $at = (string) ($o['occurredAt'] ?? '');
            $k = $op . '|' . $type;
            if (!isset($latest[$k]) || strcmp($at, $latest[$k]['at']) > 0) {
                $latest[$k] = ['operator' => $op, 'type' => $type, 'amount' => (float) $w['balance']['amount'], 'currency' => $currency, 'at' => $at];
            }
        }
        $sum = ['AVAILABLE' => null, 'WAITING' => null];
        foreach ($latest as $l) {
            if (array_key_exists($l['type'], $sum)) {
                $sum[$l['type']] = round(($sum[$l['type']] ?? 0) + $l['amount'], 2);
            }
        }
        return $sum + ['wallets' => array_values($latest)];
    }

    /**
     * Wpłaty kupujących w okresie (operacje CONTRIBUTION z grupy INCOME), bez zwrotów. Inne operacje
     * INCOME (np. przesunięcia między portfelami) liczyłyby tę samą wpłatę drugi raz.
     * @param array<int,array<string,mixed>> $ops
     */
    public static function incomeTotal(array $ops, string $currency = 'PLN'): float
    {
        $sum = 0.0;
        foreach ($ops as $o) {
            if (strtoupper((string) ($o['type'] ?? '')) === 'CONTRIBUTION' && (string) ($o['value']['currency'] ?? $currency) === $currency) {
                $sum += (float) ($o['value']['amount'] ?? 0);
            }
        }
        return round($sum, 2);
    }

    /** Udział kwoty w wartości sprzedaży (procent albo null, gdy brak sprzedaży). */
    public static function share(float $amount, float $sales): ?float
    {
        return $sales > 0 ? abs($amount) / $sales * 100 : null;
    }

    // ------------------------------------------------------------
    //  Odświeżanie z API
    // ------------------------------------------------------------

    /**
     * Pobiera jakość sprzedaży i finanse z Allegro i zapisuje w cache.
     * @param \PasePlugin\Allegro\AllegroClient $client
     * @return array{ok:bool,messages:string[]}
     */
    public function refresh(object $client, SettingsRepository $settings): array
    {
        $messages = [];
        $tz = new \DateTimeZone('Europe/Warsaw');
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $tz);
        $monthStart = $now->modify('first day of this month')->setTime(0, 0);
        $iso = static fn(\DateTimeImmutable $d) => $d->setTimezone($utc)->format('Y-m-d\TH:i:s.v\Z');

        $q = $client->saleQuality();
        if ($q['ok']) {
            $this->saveQuality($q['items']);
            $settings->setMany([self::QUALITY_ERROR_KEY => '-']);
        } else {
            $settings->setMany([self::QUALITY_ERROR_KEY => $q['message']]);
            $messages[] = 'Jakość sprzedaży: ' . $q['message'];
        }

        $adsTypes = json_decode((string) $settings->get(AllegroAds::TYPES_KEY, ''), true);
        $billing = $client->billingEntries($monthStart->setTimezone($utc), $now->setTimezone($utc), []);
        $fees = self::summarizeFees($billing['items'], is_array($adsTypes) ? array_map('strval', $adsTypes) : []);
        if ($fees['balance'] === null && $billing['ok']) {
            // Na początku miesiąca może nie być operacji — saldo bierzemy z ostatnich 90 dni.
            $older = $client->billingEntries($now->modify('-90 days')->setTimezone($utc), $monthStart->setTimezone($utc), [], 5);
            $prevFees = self::summarizeFees($older['items']);
            $fees['balance'] = $prevFees['balance'];
            $fees['balanceAt'] = $prevFees['balanceAt'];
        }
        if (!$billing['ok']) {
            $messages[] = 'Rozliczenia: ' . $billing['message'];
        }

        // Opłaty z numerem zamówienia — do marży w Statystykach. Przy pierwszym razie także 90 dni wstecz.
        try {
            $margins = new OrderMargins($this->pdo);
            $adsList = is_array($adsTypes) ? array_map('strval', $adsTypes) : [];
            $margins->saveAllegroFees($billing['items'], $adsList);
            if ($billing['ok'] && $settings->get(OrderMargins::FEES_BACKFILL_KEY, '') === '') {
                $back = $client->billingEntries($now->modify('-90 days')->setTimezone($utc), $monthStart->setTimezone($utc), []);
                if ($back['ok']) {
                    $margins->saveAllegroFees($back['items'], $adsList);
                    $settings->setMany([OrderMargins::FEES_BACKFILL_KEY => (string) time()]);
                }
            }
        } catch (\Throwable $e) {
            $messages[] = 'Opłaty do marży: ' . $e->getMessage();
        }

        $wallets = ['AVAILABLE' => null, 'WAITING' => null, 'wallets' => []];
        $walletMsg = '';
        foreach (['AVAILABLE', 'WAITING'] as $type) {
            $r = $client->paymentOperations(['wallet.type' => $type, 'currency' => 'PLN'], 2);
            if (!$r['ok']) {
                $walletMsg = $r['message'];
                continue;
            }
            $b = self::walletBalances($r['items']);
            $wallets[$type] = $b[$type];
            array_push($wallets['wallets'], ...$b['wallets']);
        }
        if ($walletMsg !== '') {
            $messages[] = 'Płatności: ' . $walletMsg;
        }

        $income = $client->paymentOperations(['group' => 'INCOME', 'currency' => 'PLN', 'occurredAt.gte' => $iso($monthStart), 'occurredAt.lte' => $iso($now)], 20);

        $settings->setMany([self::FINANCE_KEY => json_encode([
            'fetchedAt'    => $now->format('Y-m-d H:i:s'),
            'from'         => $monthStart->format('Y-m-d'),
            'to'           => $now->format('Y-m-d'),
            'available'    => $wallets['AVAILABLE'],
            'waiting'      => $wallets['WAITING'],
            'wallets'      => $wallets['wallets'],
            'walletError'  => $walletMsg,
            'sales'        => $income['ok'] ? self::incomeTotal($income['items']) : null,
            'salesTruncated' => $income['truncated'],
            'fees'         => $fees,
            'billingOk'    => $billing['ok'],
            'billingError' => $billing['ok'] ? '' : $billing['message'],
            'feesTruncated' => $billing['truncated'],
        ], JSON_UNESCAPED_UNICODE)]);
        $settings->setMany([self::AT_KEY => (string) time()]);

        return ['ok' => $messages === [], 'messages' => $messages];
    }

    /** @return array<string,mixed>|null */
    public static function cachedFinance(SettingsRepository $settings): ?array
    {
        $f = json_decode((string) $settings->get(self::FINANCE_KEY, ''), true);
        return is_array($f) ? $f : null;
    }
}
