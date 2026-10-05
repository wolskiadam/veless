<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Dashboard Allegro: jakość sprzedaży (poziomy, miary, Super Sprzedawca) i finanse (saldo, opłaty). Bez sieci.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Pase\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php'; }
});
use Pase\Repository\SettingsRepository;
use Pase\Services\AllegroDashboard as D;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

check(D::level('GOOD', 140) === 'GOOD' && D::level('SUPER', 10) === 'SUPER', 'grade from API wins');
check(D::level('NEEDS_IMPROVEMENT', 150) === 'NEEDS_IMPROVEMENT' && D::level('NEUTRAL', 50) === 'NEUTRAL', 'other grades');
check(D::level('', 230) === 'SUPER' && D::level('', 101) === 'GOOD' && D::level('', 100) === 'NEUTRAL' && D::level('', -1) === 'NEEDS_IMPROVEMENT', 'unknown grade falls back to thresholds');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE settings (setting_key VARCHAR(64) PRIMARY KEY, setting_value TEXT NULL)');
D::migrate($pdo);
D::migrate($pdo);
$dash = new D($pdo);

$metrics = static fn(int $fast, int $refunds) => [
    ['code' => 'FAST_DISPATCH', 'name' => 'Szybka wysyłka', 'score' => $fast, 'maxScore' => 160],
    ['code' => 'FAST_REFUNDS', 'name' => 'Szybkie zwroty wpłat', 'score' => $refunds, 'maxScore' => 100],
    ['code' => 'DISPATCH_IN_TIME', 'name' => 'Wysyłka w terminie', 'score' => 40, 'maxScore' => 40, 'value' => '100%'],
];
$items = [];
for ($d = 1; $d <= 26; $d++) {
    $day = sprintf('2026-09-%02d', $d);
    $score = $d >= 25 ? 140 : 100;
    $items[] = ['resultFor' => $day, 'score' => $score, 'maxScore' => 400, 'grade' => $d >= 25 ? 'GOOD' : 'NEUTRAL', 'metrics' => $metrics($d >= 26 ? 0 : 10, $d >= 25 ? 50 : 10)];
}
$items[] = ['resultFor' => 'bogus', 'score' => 1];
check($dash->saveQuality($items) === 26, 'quality days saved, bad date skipped');
check($dash->saveQuality([$items[25]]) === 1 && count($dash->qualityHistory()) === 26, 'same day overwritten, not duplicated');

$h = $dash->qualityHistory();
check($h[0]['day'] === '2026-09-01' && $h[25]['day'] === '2026-09-26', 'history oldest first');
$q = D::currentQuality($h);
check($q['day'] === '2026-09-26' && $q['score'] === 140 && $q['level'] === 'GOOD' && $q['delta'] === 0, 'current level and change vs yesterday');
check($q['metrics'][0]['code'] === 'FAST_DISPATCH' && $q['metrics'][0]['delta'] === -10, 'worst metric first with its change');
check($q['biggestDrop']['code'] === 'FAST_DISPATCH' && $q['biggestRise'] === null, 'biggest drop and rise');
check($q['metrics'][2]['value'] === '100%' && $q['metrics'][1]['value'] === '', 'metric value when API sends one');
check(D::currentQuality([])['day'] === null, 'empty history');

$ss = D::superSellerProgress($h);
check($ss['good']['days'] === 2 && !$ss['good']['met'] && $ss['super']['days'] === 0, 'Super Seller streaks like the Allegro panel (2 days Good)');
$gap = [['day' => '2026-09-20', 'level' => 'SUPER'], ['day' => '2026-09-22', 'level' => 'SUPER'], ['day' => '2026-09-23', 'level' => 'SUPER']];
check(D::superSellerProgress($gap)['super']['days'] === 2, 'missing day breaks the streak');

// Opłaty: screenshot Centrum Finansów (obowiązkowe −30,45, dostawa −6,38, reklama 0, saldo −3,19).
check(D::feeGroup('SUC', 'Prowizja od sprzedaży') === 'mandatory', 'commission is mandatory');
check(D::feeGroup('HB4', 'Opłata za dostawę InPost') === 'delivery', 'delivery fee');
check(D::feeGroup('FEA', 'Opłata za wyróżnienie') === 'promo' && D::feeGroup('X1', 'Kampania', ['X1']) === 'promo', 'promotion and Ads types');
check(D::feeGroup('PAD', 'Pobranie opłat z wpływów') === null && D::feeGroup('WPL', 'Wpłata') === null, 'settlements are not fees');
$entries = [
    ['occurredAt' => '2026-09-10T10:00:00.000Z', 'type' => ['id' => 'SUC', 'name' => 'Prowizja od sprzedaży'], 'value' => ['amount' => '-20.45'], 'balance' => ['amount' => '-20.45']],
    ['occurredAt' => '2026-09-12T10:00:00.000Z', 'type' => ['id' => 'SUC', 'name' => 'Prowizja od sprzedaży'], 'value' => ['amount' => '-10.00'], 'balance' => ['amount' => '-30.45']],
    ['occurredAt' => '2026-09-20T10:00:00.000Z', 'type' => ['id' => 'HB4', 'name' => 'Opłata za dostawę InPost'], 'value' => ['amount' => '-6.38'], 'balance' => ['amount' => '-36.83']],
    ['occurredAt' => '2026-09-25T10:00:00.000Z', 'type' => ['id' => 'PAD', 'name' => 'Pobranie opłat z wpływów'], 'value' => ['amount' => '33.64'], 'balance' => ['amount' => '-3.19']],
];
$f = D::summarizeFees(array_reverse($entries));
check($f['groups'] === ['mandatory' => -30.45, 'delivery' => -6.38, 'promo' => 0.0] && $f['total'] === -36.83, 'fee groups and total');
check($f['balance'] === -3.19 && $f['balanceAt'] === '2026-09-25T10:00:00.000Z', 'settlement balance from newest entry, whatever the order');
check($f['byType'][0]['name'] === 'Prowizja od sprzedaży' && $f['byType'][0]['amount'] === -30.45, 'fees by type, biggest first');
check(abs(D::share(-36.83, 224.92) - 16.37) < 0.01 && D::share(-1, 0) === null, 'fee share of sales');

$ops = [
    ['occurredAt' => '2026-09-20T10:00:00Z', 'type' => 'CONTRIBUTION', 'group' => 'INCOME', 'wallet' => ['paymentOperator' => 'AF', 'type' => 'AVAILABLE', 'balance' => ['amount' => '50.00', 'currency' => 'PLN']], 'value' => ['amount' => '50.00', 'currency' => 'PLN']],
    ['occurredAt' => '2026-09-25T10:00:00Z', 'type' => 'PAYOUT', 'group' => 'OUTCOME', 'wallet' => ['paymentOperator' => 'AF', 'type' => 'AVAILABLE', 'balance' => ['amount' => '0.00', 'currency' => 'PLN']], 'value' => ['amount' => '-50.00', 'currency' => 'PLN']],
    ['occurredAt' => '2026-09-01T10:00:00Z', 'type' => 'CONTRIBUTION', 'group' => 'INCOME', 'wallet' => ['paymentOperator' => 'PAYU', 'type' => 'AVAILABLE', 'balance' => ['amount' => '12.50', 'currency' => 'PLN']], 'value' => ['amount' => '12.50', 'currency' => 'PLN']],
    ['occurredAt' => '2026-09-26T10:00:00Z', 'type' => 'CONTRIBUTION', 'group' => 'INCOME', 'wallet' => ['paymentOperator' => 'AF', 'type' => 'AVAILABLE', 'balance' => ['amount' => '9.00', 'currency' => 'EUR']], 'value' => ['amount' => '9.00', 'currency' => 'EUR']],
];
$w = D::walletBalances($ops);
check($w['AVAILABLE'] === 12.5 && $w['WAITING'] === null && count($w['wallets']) === 2, 'available funds: newest balance per operator, other currencies skipped');
check(D::incomeTotal($ops) === 62.5, 'buyer payments counted once, PLN only');

// Odświeżanie z atrapą klienta.
$client = new class {
    public array $calls = [];
    public function saleQuality(): array { return ['ok' => true, 'items' => [['resultFor' => '2026-09-27', 'score' => 250, 'maxScore' => 400, 'grade' => 'SUPER', 'metrics' => []]], 'message' => '']; }
    public function billingEntries(\DateTimeInterface $from, \DateTimeInterface $to, array $types, int $maxPages = 30): array {
        $this->calls[] = 'billing';
        return ['ok' => true, 'items' => count($this->calls) === 1 ? [] : [['occurredAt' => '2026-08-30T10:00:00Z', 'type' => ['id' => 'SUC', 'name' => 'Prowizja'], 'value' => ['amount' => '-1'], 'balance' => ['amount' => '-7.00']]], 'message' => '', 'truncated' => false];
    }
    public function paymentOperations(array $q, int $pages = 1): array {
        $this->calls[] = 'payments:' . ($q['wallet.type'] ?? $q['group'] ?? '');
        if (($q['wallet.type'] ?? '') === 'WAITING') { return ['ok' => false, 'items' => [], 'message' => 'Brak uprawnień', 'truncated' => false]; }
        return ['ok' => true, 'items' => [['occurredAt' => '2026-09-26T10:00:00Z', 'type' => 'CONTRIBUTION', 'group' => 'INCOME', 'wallet' => ['paymentOperator' => 'AF', 'type' => 'AVAILABLE', 'balance' => ['amount' => '99.99', 'currency' => 'PLN']], 'value' => ['amount' => '99.99', 'currency' => 'PLN']]], 'message' => '', 'truncated' => false];
    }
};
$settings = new SettingsRepository($pdo);
$r = $dash->refresh($client, $settings);
$fin = D::cachedFinance($settings);
check(!$r['ok'] && str_contains(implode(' ', $r['messages']), 'Brak uprawnień'), 'partial failure reported');
check($fin['available'] === 99.99 && $fin['waiting'] === null && $fin['sales'] === 99.99, 'finance cached');
check((float) $fin['fees']['balance'] === -7.0 && (float) $fin['fees']['total'] === 0.0, 'balance from older entries when the month has none');
check(count($dash->qualityHistory()) === 27 && (int) $settings->get(D::AT_KEY) > 0, 'quality stored and refresh time saved');

echo "PASS: $checks Allegro dashboard checks\n";
