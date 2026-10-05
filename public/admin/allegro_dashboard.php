<?php
declare(strict_types=1);

/**
 * Dashboard Allegro — jakość sprzedaży (poziom, punkty, miary, historia, postęp Super Sprzedawcy)
 * i finanse (dostępne środki, saldo rozliczeń z Allegro, opłaty w bieżącym miesiącu).
 * Dane z cache (AllegroDashboard); odświeża je Scheduler co godzinę albo przycisk „Odśwież".
 */

use Pase\Repository\SettingsRepository;
use Pase\Services\AllegroDashboard;
use PasePlugin\Allegro\AllegroPlugin;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$config    = require PASE_ROOT . '/config/config.php';
$client    = AllegroPlugin::makeClient($pdo, $config['allegro'] ?? []);
$settings  = new SettingsRepository($pdo);
$dash      = new AllegroDashboard($pdo);
$tz        = new DateTimeZone('Europe/Warsaw');
$connected = $client->bearerToken() !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refresh') {
    csrfCheck();
    if ($connected) {
        $r = $dash->refresh($client, $settings);
        flash($r['ok'] ? 'Pobrano aktualne dane z Allegro.' : 'Część danych nie została pobrana: ' . implode(' ', $r['messages']), $r['ok'] ? 'ok' : 'err');
    }
    redirectAfterPost();
}

$finance = AllegroDashboard::cachedFinance($settings);
if ($finance === null && $connected) {
    // Pierwsze wejście: nie czekamy na crona.
    $dash->refresh($client, $settings);
    $finance = AllegroDashboard::cachedFinance($settings);
}
$history      = $dash->qualityHistory(90);
$quality      = AllegroDashboard::currentQuality($history);
$superSeller  = AllegroDashboard::superSellerProgress($history);
$qualityError = (string) $settings->get(AllegroDashboard::QUALITY_ERROR_KEY, '');
$qualityError = $qualityError === '-' ? '' : $qualityError;

$e      = static fn($v) => htmlspecialchars((string) $v);
$money  = static fn(?float $v) => $v === null ? '—' : number_format($v, 2, ',', ' ') . ' zł';
$pct    = static fn(?float $v) => $v === null ? '—' : number_format($v, 1, ',', ' ') . '%';
$signed = static fn(?int $v) => $v === null ? '—' : ($v === 0 ? 'bez zmian' : ($v > 0 ? '+' : '') . $v . ' pkt');
$dmy    = static fn(?string $d) => $d ? date('d.m.Y', strtotime($d)) : '—';
$levels = ['NEEDS_IMPROVEMENT', 'NEUTRAL', 'GOOD', 'SUPER'];
$levelIdx = array_search($quality['level'], $levels, true);
$fees   = $finance['fees'] ?? null;
$sales  = isset($finance['sales']) ? (float) $finance['sales'] : null;

$PAGE_TITLE = 'Dashboard Allegro';
$PAGE_KEY   = 'allegro';
require __DIR__ . '/header.php';
?>

<div class="card ad-head">
    <div>
        <strong>📊 Dashboard Allegro</strong>
        <p class="ad-muted">Jakość sprzedaży i finanse z API Allegro. Dane odświeżają się co godzinę<?= $finance ? ', ostatnio ' . $e(date('d.m.Y H:i', strtotime((string) $finance['fetchedAt']))) : '' ?>. Allegro przelicza jakość sprzedaży raz dziennie.</p>
    </div>
    <div class="ad-links">
        <?php if ($connected): ?>
            <form method="post"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="refresh"><button class="btn" type="submit">Odśwież teraz</button></form>
        <?php endif; ?>
        <a class="btn secondary" href="<?= $e(AllegroDashboard::QUALITY_URL) ?>" target="_blank" rel="noopener">Jakość sprzedaży na Allegro ↗</a>
        <a class="btn secondary" href="<?= $e(AllegroDashboard::FINANCE_URL) ?>" target="_blank" rel="noopener">Centrum Finansów ↗</a>
    </div>
</div>

<?php if (!$connected): ?>
    <div class="card ad-alert">Konto Allegro nie jest połączone. <a href="allegro_settings.php">Połącz konto w ustawieniach Allegro</a>, żeby zobaczyć dane.</div>
<?php endif; ?>

<div class="ad-grid2">
    <div class="card">
        <strong>Jakość sprzedaży</strong>
        <?php if ($qualityError !== ''): ?><p class="ad-bad"><?= $e($qualityError) ?></p><?php endif; ?>
        <?php if ($quality['day'] === null): ?>
            <p class="ad-muted">Brak danych o jakości sprzedaży.</p>
        <?php else: ?>
            <div class="ad-level">
                <div><span class="ad-muted">POZIOM</span><b class="ad-lv-<?= $e(strtolower($quality['level'])) ?>"><?= $e(AllegroDashboard::LEVEL_LABELS[$quality['level']]) ?></b></div>
                <div class="ad-pts"><?= (int) $quality['score'] ?> z <?= (int) $quality['maxScore'] ?> pkt</div>
            </div>
            <div class="ad-segs" role="img" aria-label="Poziom <?= $e(AllegroDashboard::LEVEL_LABELS[$quality['level']]) ?>">
                <?php foreach ($levels as $i => $lv): ?><i class="<?= $levelIdx !== false && $i <= $levelIdx ? 'on ad-seg-' . $e(strtolower($quality['level'])) : '' ?>" title="<?= $e(AllegroDashboard::LEVEL_LABELS[$lv]) ?>"></i><?php endforeach; ?>
            </div>
            <div class="ad-facts">
                <div><span>w stosunku do wczoraj</span><b><?= $e($signed($quality['delta'])) ?></b></div>
                <div><span>wynik z dnia</span><b><?= $e($dmy($quality['day'])) ?></b></div>
                <div><span>największy spadek</span><b><?= $quality['biggestDrop'] ? $e($quality['biggestDrop']['name'] . ' ' . $signed($quality['biggestDrop']['delta'])) : '—' ?></b></div>
                <div><span>największy wzrost</span><b><?= $quality['biggestRise'] ? $e($quality['biggestRise']['name'] . ' ' . $signed($quality['biggestRise']['delta'])) : '—' ?></b></div>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <strong>Finanse</strong>
        <?php if ($finance === null): ?>
            <p class="ad-muted">Brak danych finansowych.</p>
        <?php else: ?>
            <div class="ad-money">
                <div><span>Dostępne środki</span><b><?= $e($money(isset($finance['available']) ? (float) $finance['available'] : null)) ?></b></div>
                <div><span>Środki oczekujące</span><b><?= $e($money(isset($finance['waiting']) ? (float) $finance['waiting'] : null)) ?></b></div>
                <div><span>Saldo rozliczeń z Allegro</span><b class="<?= isset($fees['balance']) && $fees['balance'] < 0 ? 'ad-neg' : '' ?>"><?= $e($money(isset($fees['balance']) ? (float) $fees['balance'] : null)) ?></b></div>
            </div>
            <?php if (!empty($finance['walletError'])): ?><p class="ad-bad"><?= $e($finance['walletError']) ?></p><?php endif; ?>
            <?php if (!empty($finance['wallets']) && count($finance['wallets']) > 1): ?>
                <p class="ad-muted" style="font-size:12px">
                    <?php foreach ($finance['wallets'] as $w): ?><?= $e(($w['operator'] ?: '?') . ' · ' . ($w['type'] === 'AVAILABLE' ? 'dostępne' : 'oczekujące') . ': ' . $money((float) $w['amount'])) ?><br><?php endforeach; ?>
                </p>
            <?php endif; ?>
            <p class="ad-muted" style="font-size:12px">Saldo portfela z ostatniej operacji płatności. Ujemne saldo rozliczeń to opłaty do zapłaty Allegro<?= !empty($fees['balanceAt']) ? ' (stan na ' . $e(date('d.m.Y H:i', strtotime((string) $fees['balanceAt']))) . ')' : '' ?>.</p>
        <?php endif; ?>
    </div>
</div>

<?php if ($finance !== null): ?>
<div class="card">
    <strong>Podsumowanie</strong>
    <span class="ad-muted"> · <?= $e($dmy($finance['from']) . ' – ' . $dmy($finance['to'])) ?></span>
    <?php if (!empty($finance['billingError'])): ?><p class="ad-bad">Rozliczenia: <?= $e($finance['billingError']) ?></p><?php endif; ?>
    <table class="ad-table ad-sum">
        <tr class="ad-strong"><td>Wpłaty za sprzedaż i dostawę</td><td><?= $e($money($sales)) ?></td><td><?= $sales !== null && $sales > 0 ? '100,0%' : '' ?></td></tr>
        <?php if ($fees !== null): ?>
            <tr class="ad-strong"><td>Suma opłat</td><td><?= $e($money((float) $fees['total'])) ?></td><td><?= $e($pct(AllegroDashboard::share((float) $fees['total'], (float) $sales))) ?></td></tr>
            <?php foreach (AllegroDashboard::FEE_GROUPS as $g => $label): ?>
                <tr><td><?= $e($label) ?></td><td><?= $e($money((float) ($fees['groups'][$g] ?? 0))) ?></td><td><?= $e($pct(AllegroDashboard::share((float) ($fees['groups'][$g] ?? 0), (float) $sales))) ?></td></tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </table>
    <p class="ad-muted" style="font-size:12px">Wpłaty kupujących przez Allegro (PLN) w bieżącym miesiącu<?= !empty($finance['salesTruncated']) ? ' — pierwsze 1000 wpłat' : '' ?>. Opłaty z rozliczeń Allegro; zwroty opłat pomniejszają sumę.
        Opłaty oznaczone w <a href="allegro_ads.php">panelu Allegro Ads</a> liczą się jako reklama.</p>
    <?php if ($fees !== null && $fees['byType']): ?>
        <details>
            <summary>Opłaty według rodzaju (<?= count($fees['byType']) ?>)</summary>
            <table class="ad-table">
                <tr><th>Rodzaj opłaty</th><th>Grupa</th><th>Kwota</th></tr>
                <?php foreach ($fees['byType'] as $t): ?>
                    <tr><td><?= $e($t['name']) ?></td><td><?= $e(AllegroDashboard::FEE_GROUPS[$t['group']] ?? $t['group']) ?></td><td><?= $e($money((float) $t['amount'])) ?></td></tr>
                <?php endforeach; ?>
            </table>
            <?php if (!empty($finance['feesTruncated'])): ?><p class="ad-muted">Pokazano pierwsze 3000 operacji z tego miesiąca.</p><?php endif; ?>
        </details>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($history): ?>
<div class="ad-grid-main">
    <div class="card">
        <div class="ad-level"><strong>Twój poziom jakości sprzedaży</strong><b class="ad-lv-<?= $e(strtolower($quality['level'])) ?>"><?= $e(AllegroDashboard::LEVEL_LABELS[$quality['level']]) ?> · <?= (int) $quality['score'] ?> pkt</b></div>
        <?php
        // Wykres: punkty dzień po dniu na tle progów poziomów.
        $W = 720; $H = 260; $padL = 8; $padR = 70; $padT = 12; $padB = 28;
        $yMin = min(-60, min(array_column($history, 'score')) - 20);
        $yMax = AllegroDashboard::MAX_SCORE;
        $n = count($history);
        $x = static fn(int $i) => $padL + ($n > 1 ? $i / ($n - 1) : 0.5) * ($W - $padL - $padR);
        $y = static fn(float $v) => $padT + ($yMax - $v) / ($yMax - $yMin) * ($H - $padT - $padB);
        $lines = [AllegroDashboard::MAX_SCORE => '400 pkt', AllegroDashboard::THRESHOLDS['SUPER'] => 'Super', AllegroDashboard::THRESHOLDS['GOOD'] => 'Dobry', AllegroDashboard::THRESHOLDS['NEUTRAL'] => 'Neutralny'];
        $path = '';
        foreach ($history as $i => $h) {
            $path .= ($i ? 'L' : 'M') . round($x($i), 1) . ' ' . round($y($h['score']), 1);
        }
        $step = max(1, (int) ceil($n / 8));
        ?>
        <svg class="ad-chart" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Historia punktów jakości sprzedaży">
            <?php foreach ($lines as $v => $label): ?>
                <line x1="<?= $padL ?>" x2="<?= $W - $padR ?>" y1="<?= round($y($v), 1) ?>" y2="<?= round($y($v), 1) ?>" class="ad-grid-line"/>
                <text x="<?= $W - $padR + 6 ?>" y="<?= round($y($v), 1) + 4 ?>" class="ad-axis"><?= $e($label) ?> <?= $v !== AllegroDashboard::MAX_SCORE ? '(' . $v . ')' : '' ?></text>
            <?php endforeach; ?>
            <path d="<?= $path ?>" class="ad-line"/>
            <?php foreach ($history as $i => $h): ?>
                <g class="ad-pt"><circle cx="<?= round($x($i), 1) ?>" cy="<?= round($y($h['score']), 1) ?>" r="4"/><circle cx="<?= round($x($i), 1) ?>" cy="<?= round($y($h['score']), 1) ?>" r="12" class="ad-hit"/><title><?= $e(date('d.m', strtotime($h['day'])) . ': ' . AllegroDashboard::LEVEL_LABELS[$h['level']] . ', ' . $h['score'] . ' pkt') ?></title></g>
                <?php if (($i % $step === 0 && $n - 1 - $i >= $step / 2) || $i === $n - 1): ?><text x="<?= round($x($i), 1) ?>" y="<?= $H - 8 ?>" text-anchor="middle" class="ad-axis"><?= $e(date('d.m', strtotime($h['day']))) ?></text><?php endif; ?>
            <?php endforeach; ?>
        </svg>
        <p class="ad-muted" style="font-size:12px">Każdy punkt to wynik z ostatnich 30 dni wyliczony przez Allegro na dany dzień. Historia starsza niż 30 dni zbiera się w CRM od dziś.</p>
    </div>

    <div class="card">
        <div class="ad-level"><strong>Super Sprzedawca</strong><a href="<?= $e(AllegroDashboard::SUPER_SELLER_URL) ?>" target="_blank" rel="noopener">warunki ↗</a></div>
        <?php foreach ($superSeller as $s): ?>
            <div class="ad-goal">
                <span class="ad-muted"><?= $e(mb_strtoupper($s['label'])) ?></span>
                <div class="ad-level"><span>Masz <b><?= (int) $s['days'] ?> dni</b></span><span>Cel <b><?= (int) $s['goal'] ?> dni</b></span></div>
                <div class="ad-bar"><i style="width:<?= min(100, (int) round($s['days'] / max(1, $s['goal']) * 100)) ?>%" class="<?= $s['met'] ? 'ok' : '' ?>"></i></div>
                <small class="<?= $s['met'] ? 'ad-ok' : 'ad-muted' ?>"><?= $s['met'] ? '✓ Spełniasz ten warunek.' : 'Nie spełniasz tego warunku.' ?></small>
            </div>
        <?php endforeach; ?>
        <p class="ad-muted" style="font-size:12px">Liczone w CRM z historii poziomów (dni z rzędu). Allegro nie udostępnia statusu programu przez API, więc sam tytuł sprawdzisz na <a href="<?= $e(AllegroDashboard::QUALITY_URL) ?>" target="_blank" rel="noopener">stronie jakości sprzedaży ↗</a>.</p>
    </div>
</div>

<div class="card">
    <strong>Twoje wyniki w miarach</strong>
    <span class="ad-muted"> · najgorszy wynik na górze · <a href="<?= $e(AllegroDashboard::QUALITY_HELP_URL) ?>" target="_blank" rel="noopener">jak liczone są miary ↗</a></span>
    <div class="ad-scroll"><table class="ad-table">
        <tr><th>Nazwa miary</th><th>Liczba punktów</th><th>Od wczoraj</th><th>Wartość</th></tr>
        <?php foreach ($quality['metrics'] as $m): ?>
            <?php $bad = $m['maxScore'] > 0 ? $m['score'] < $m['maxScore'] / 2 : $m['score'] < 0; ?>
            <tr>
                <td><span class="<?= $bad ? 'ad-bad' : 'ad-ok' ?>" aria-hidden="true"><?= $bad ? '⚠' : '✓' ?></span> <?= $e($m['name']) ?></td>
                <td><?= (int) $m['score'] ?> / <?= (int) $m['maxScore'] ?> pkt</td>
                <td class="<?= ($m['delta'] ?? 0) < 0 ? 'ad-bad' : '' ?>"><?= $e($signed($m['delta'])) ?></td>
                <td><?= $m['value'] !== '' ? $e($m['value']) : '<span class="ad-muted">—</span>' ?></td>
            </tr>
        <?php endforeach; ?>
    </table></div>
    <?php if (!array_filter(array_column($quality['metrics'], 'value'))): ?><p class="ad-muted" style="font-size:12px">Wartości miar (np. „100%", „Brak reklamacji") API Allegro nie zwraca — są na <a href="<?= $e(AllegroDashboard::QUALITY_URL) ?>" target="_blank" rel="noopener">stronie jakości sprzedaży ↗</a>.</p><?php endif; ?>
</div>
<?php endif; ?>

<style>
    .ad-muted { color:var(--ink-2); font-size:13px; }
    .ad-head { display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; align-items:flex-start; }
    .ad-head p { margin:6px 0 0; max-width:640px; }
    .ad-links { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .ad-grid2 { display:grid; grid-template-columns:repeat(auto-fit, minmax(min(340px, 100%), 1fr)); gap:12px; align-items:stretch; }
    .ad-grid-main { display:grid; grid-template-columns:minmax(0, 2fr) minmax(min(280px, 100%), 1fr); gap:12px; align-items:start; }
    @media (max-width: 900px) { .ad-grid-main { grid-template-columns:1fr; } }
    .ad-grid2 > *, .ad-grid-main > * { min-width:0; }
    .ad-level { display:flex; justify-content:space-between; align-items:baseline; gap:10px; flex-wrap:wrap; }
    .ad-level b { font-size:26px; display:block; }
    .ad-grid-main .ad-level b { font-size:inherit; display:inline; }
    .ad-pts { font-size:16px; }
    .ad-segs { display:grid; grid-template-columns:repeat(4, 1fr); gap:4px; margin:10px 0 14px; }
    .ad-segs i { height:8px; border-radius:4px; background:var(--muted-bg); }
    .ad-segs i.on { background:var(--success-ink); }
    .ad-segs i.on.ad-seg-neutral { background:var(--warn-ink); }
    .ad-segs i.on.ad-seg-needs_improvement { background:var(--danger-ink); }
    .ad-lv-needs_improvement { color:var(--danger-ink); }
    .ad-facts, .ad-money { display:grid; grid-template-columns:repeat(auto-fit, minmax(min(150px, 100%), 1fr)); gap:12px; margin-top:8px; }
    .ad-facts span, .ad-money span { display:block; color:var(--ink-2); font-size:12px; }
    .ad-facts b { font-size:15px; }
    .ad-money b { display:block; font-size:22px; margin-top:2px; }
    .ad-neg { color:var(--danger-ink); }
    .ad-bad { color:var(--danger-ink); }
    .ad-ok { color:var(--success-ink); }
    .ad-alert { border-left:4px solid var(--warn-ink); background:var(--warn-bg); }
    .ad-scroll { overflow-x:auto; }
    .ad-table { width:100%; margin-top:8px; }
    .ad-table td:not(:first-child), .ad-table th:not(:first-child) { text-align:right; white-space:nowrap; }
    .ad-sum { max-width:560px; }
    .ad-strong td { font-weight:600; }
    details summary { cursor:pointer; margin-top:8px; color:var(--ink-2); font-size:13px; }
    .ad-chart { width:100%; height:auto; margin-top:10px; overflow:visible; }
    .ad-grid-line { stroke:var(--line); stroke-width:1; }
    .ad-axis { fill:var(--ink-2); font-size:11px; }
    .ad-line { fill:none; stroke:var(--accent); stroke-width:2; stroke-linejoin:round; }
    .ad-pt circle { fill:var(--accent); stroke:var(--surface); stroke-width:2; }
    .ad-pt circle.ad-hit { fill:transparent; stroke:none; }
    .ad-pt:hover circle:first-child { r:6; }
    .ad-goal { border:1px solid var(--line); border-radius:8px; padding:10px 12px; margin-top:10px; }
    .ad-goal .ad-muted { font-size:11px; font-weight:600; }
    .ad-bar { height:6px; background:var(--muted-bg); border-radius:3px; overflow:hidden; margin:6px 0; }
    .ad-bar i { display:block; height:100%; background:var(--ink-2); }
    .ad-bar i.ok { background:var(--success-ink); }
</style>

<?php require __DIR__ . '/footer.php'; ?>
