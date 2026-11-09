<?php
declare(strict_types=1);

/**
 * Statystyki → Odbiory kurierów: o której kurier zwykle zabiera paczki (z automatu, punktu, od nadawcy),
 * osobno dla każdej usługi i dnia tygodnia. Liczone z historii śledzenia przesyłek (Services\CourierPickupStats).
 */

use Pase\Services\CourierPickupStats as Stats;

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

$rangeOptions = ['30' => '30 dni', '60' => '60 dni', '90' => '90 dni', '180' => '180 dni', '365' => 'rok'];
$range = array_key_exists((string) ($_GET['range'] ?? ''), $rangeOptions) ? (string) $_GET['range'] : '90';
$since = date('Y-m-d', strtotime('-' . ((int) $range - 1) . ' days'));

$services = [];
$error = null;
try {
    $services = (new Stats($pdo))->report($since);
} catch (\Throwable $e) {
    \Pase\Support\Logger::warn('Odbiory kurierów: ' . $e->getMessage());
    $error = 'Nie udało się policzyć statystyk odbiorów.';
}

$hm = static fn(int $m): string => Stats::hm($m);
$dateFmt = static fn(?\DateTimeImmutable $d): string => $d ? $d->format('d.m') . ' ' . $d->format('G:i') : '—';
$plural = static function (int $n): string {
    if ($n === 1) { return 'paczka'; }
    $t = $n % 100; $u = $n % 10;
    return ($u >= 2 && $u <= 4 && ($t < 12 || $t > 14)) ? 'paczki' : 'paczek';
};

$PAGE_TITLE = 'Odbiory kurierów';
$PAGE_KEY   = 'courier_pickups';
require __DIR__ . '/header.php';
?>

<div class="card cp-intro">
    <form method="get" class="cp-range">
        <label for="cpRange">Okres</label>
        <select id="cpRange" name="range" onchange="this.form.submit()">
            <?php foreach ($rangeOptions as $k => $lbl): ?>
                <option value="<?= $k ?>"<?= (string) $k === $range ? ' selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button class="btn secondary" type="submit">Pokaż</button></noscript>
    </form>
    <p>O której kurier zabiera paczki — liczone z historii śledzenia przesyłek nadanych w wybranym okresie, osobno dla każdej firmy
        i sposobu nadania (wrzucone do automatu/punktu albo odebrane przez kuriera od Ciebie). Nazwa metody dostawy u klienta nie ma znaczenia.
        <b>Wrzuć do</b> to godzina, przed którą kurier zabierał paczkę tego samego dnia w 9 na 10 przypadków.</p>
</div>

<?php if ($error !== null): ?>
    <div class="flash error"><?= htmlspecialchars($error) ?></div>
<?php elseif ($services === []): ?>
    <div class="card"><p class="cp-dim">Brak odbiorów w historii śledzenia w tym okresie. Statystyki pojawią się, gdy przesyłki zostaną odebrane przez kurierów
        (śledzenie przesyłek musi być włączone — Konfiguracja → Synchronizacja).</p></div>
<?php endif; ?>

<?php foreach ($services as $s):
    $hoursWithData = array_keys(array_filter($s['hours']));
    $hFrom = max(0, min($hoursWithData) - 1);
    $hTo   = min(23, max($hoursWithData) + 1);
    $maxCell = 1;
    foreach ($s['grid'] as $row) { $maxCell = max($maxCell, ...array_values($row)); }
    $few = $s['count'] < Stats::FEW;
    $drop = $s['dropoff'];
?>
<section class="card cp-card">
    <header class="cp-head">
        <div>
            <h2 class="cp-name"><?= htmlspecialchars($s['name']) ?> <span class="cp-how cp-how-<?= $s['handover'] ?>"><?= htmlspecialchars(Stats::HANDOVER[$s['handover']]) ?></span></h2>
            <div class="cp-dim"><?= $s['count'] ?> <?= $plural($s['count']) ?> · ostatni odbiór <?= htmlspecialchars($dateFmt($s['last'])) ?></div>
            <?php if ($s['variants'] !== [] && ($s['variants'] !== [$s['name']])): ?>
                <div class="cp-dim cp-variants">Metody dostawy: <?= htmlspecialchars(implode(', ', array_slice($s['variants'], 0, 6))) ?><?= count($s['variants']) > 6 ? ' i ' . (count($s['variants']) - 6) . ' inne' : '' ?></div>
            <?php endif; ?>
        </div>
        <div class="cp-tiles">
            <div class="cp-tile">
                <div class="cp-lbl">Kurier odbiera zwykle<?= $s['scope'] !== '' ? ' (' . $s['scope'] . ')' : '' ?></div>
                <div class="cp-num"><?= $hm($s['from']) ?>–<?= $hm($s['to']) ?></div>
            </div>
            <div class="cp-tile cp-tile-accent">
                <div class="cp-lbl"><?= $s['handover'] === Stats::DROP ? 'Wrzuć do' : 'Przygotuj do' ?><?= $s['scope'] !== '' ? ' (' . $s['scope'] . ')' : '' ?></div>
                <div class="cp-num"><?= $hm($s['drop_by']) ?></div>
            </div>
        </div>
    </header>
    <?php if ($few): ?><p class="cp-note">Mało danych — godziny są orientacyjne.</p><?php endif; ?>

    <div class="cp-scroll">
        <table class="cp-grid">
            <thead>
                <tr>
                    <th></th>
                    <?php for ($h = $hFrom; $h <= $hTo; $h++): ?><th class="cp-h"><?= $h ?></th><?php endfor; ?>
                    <th class="cp-win">Zwykle</th>
                    <th class="cp-win"><?= $s['handover'] === Stats::DROP ? 'Wrzuć do' : 'Przygotuj do' ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach (Stats::DAYS as $d => $dayLbl):
                $w = $s['days'][$d] ?? null; ?>
                <tr<?= $w === null ? ' class="cp-empty"' : '' ?>>
                    <th class="cp-day"><?= $dayLbl ?></th>
                    <?php for ($h = $hFrom; $h <= $hTo; $h++):
                        $c = (int) ($s['grid'][$d][$h] ?? 0);
                        $pct = $c > 0 ? (int) round(18 + 82 * $c / $maxCell) : 0; ?>
                        <td class="cp-cell"<?= $c > 0 ? ' style="--cp:' . $pct . '%" title="' . $dayLbl . ' ' . $h . ':00–' . ($h + 1) . ':00 · ' . $c . ' ' . $plural($c) . '"' : '' ?>><?= $c > 0 ? $c : '' ?></td>
                    <?php endfor; ?>
                    <?php if ($w !== null): ?>
                        <td class="cp-win<?= $w['n'] < Stats::FEW ? ' cp-few' : '' ?>"><?= $hm($w['from']) ?>–<?= $hm($w['to']) ?></td>
                        <td class="cp-win<?= $w['n'] < Stats::FEW ? ' cp-few' : '' ?>"><b><?= $hm($w['drop_by']) ?></b></td>
                    <?php else: ?>
                        <td class="cp-win cp-dim">—</td><td class="cp-win cp-dim">—</td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <ul class="cp-facts">
        <li>Środek odbiorów<?= $s['scope'] !== '' ? ' (' . $s['scope'] . ')' : '' ?>: <b><?= $hm($s['median']) ?></b></li>
        <?php if ($drop['same_day'] + $drop['next_day'] > 0): ?>
            <li>Wrzucone do automatu/punktu: <b><?= $drop['same_day'] ?></b> zabrane tego samego dnia, <b><?= $drop['next_day'] ?></b> później<?php if ($drop['latest_same_day'] !== null): ?>
                — najpóźniej wrzucona i zabrana tego dnia o <b><?= $hm((int) $drop['latest_same_day']) ?></b><?php endif; ?></li>
        <?php endif; ?>
        <?php if ($s['exact'] < $s['count']): ?>
            <li class="cp-dim"><?= $s['count'] - $s['exact'] ?> z <?= $s['count'] ?> odbiorów w przybliżeniu (w historii nie ma odbioru przez kuriera — liczymy pierwsze „w drodze” po wrzuceniu paczki).</li>
        <?php endif; ?>
    </ul>
</section>
<?php endforeach; ?>

<p class="cp-dim cp-foot">Godziny w czasie polskim. Zwykle = środkowe 60% odbiorów. Dni z mniej niż <?= Stats::FEW ?> odbiorami są wyszarzone.
    <a href="statistics.php">← Statystyki zamówień</a></p>

<style>
    .cp-intro { display:flex; gap:18px; align-items:center; flex-wrap:wrap; }
    .cp-intro p { margin:0; flex:1 1 320px; color:var(--ink-2); font-size:13.5px; line-height:1.5; }
    .cp-range { display:flex; gap:8px; align-items:center; }
    .cp-range label { font-size:11px; color:var(--ink-2); font-weight:600; text-transform:uppercase; letter-spacing:.03em; }
    .cp-card { margin-top:16px; }
    .cp-head { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; flex-wrap:wrap; }
    .cp-name { margin:0 0 4px; font-size:18px; }
    .cp-dim { color:var(--ink-2); font-size:12.5px; }
    .cp-variants { margin-top:2px; }
    .cp-how { display:inline-block; vertical-align:middle; margin-left:6px; padding:2px 9px; border-radius:999px; font-size:12px; font-weight:600;
              background:color-mix(in srgb, var(--accent) 14%, transparent); color:var(--ink); }
    .cp-how-door { background:color-mix(in srgb, var(--ink-2) 12%, transparent); color:var(--ink-2); }
    .cp-tiles { display:flex; gap:10px; }
    .cp-tile { border:1px solid var(--line); border-radius:10px; padding:10px 14px; min-width:130px; }
    .cp-tile-accent { border-color:var(--accent); background:color-mix(in srgb, var(--accent) 8%, transparent); }
    .cp-lbl { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:var(--ink-2); font-weight:600; }
    .cp-num { font-family:var(--font-num); font-variant-numeric:tabular-nums; font-size:24px; font-weight:600; margin-top:4px; white-space:nowrap; }
    .cp-note { margin:10px 0 0; font-size:12.5px; color:#b06000; }
    .cp-scroll { overflow-x:auto; margin-top:14px; }
    .cp-grid { border-collapse:separate; border-spacing:3px; font-size:12px; width:auto; }
    .cp-grid th, .cp-grid td { padding:0; border:0; background:none; }
    .cp-grid .cp-h { width:30px; min-width:30px; text-align:center; color:var(--ink-2); font-weight:500; font-family:var(--font-num); }
    .cp-grid .cp-day { text-align:left; padding-right:6px; color:var(--ink-2); font-weight:600; }
    .cp-cell { width:30px; min-width:30px; height:26px; text-align:center; border-radius:5px; background:color-mix(in srgb, var(--ink-2) 7%, transparent) !important;
               font-family:var(--font-num); font-variant-numeric:tabular-nums; }
    .cp-cell[style] { background:color-mix(in srgb, var(--accent) var(--cp), transparent) !important; color:var(--ink); font-weight:600; }
    .cp-grid .cp-win { padding:0 0 0 14px; text-align:left; white-space:nowrap; font-family:var(--font-num); font-variant-numeric:tabular-nums; }
    .cp-grid thead .cp-win { font-family:inherit; color:var(--ink-2); font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:.03em; }
    .cp-few { color:var(--ink-2); }
    .cp-empty .cp-day { opacity:.55; }
    .cp-facts { margin:12px 0 0; padding-left:18px; font-size:13px; line-height:1.7; }
    .cp-foot { margin-top:16px; }
    @media (max-width:640px) {
        .cp-tiles { width:100%; }
        .cp-tile { flex:1; min-width:0; }
        .cp-num { font-size:18px; }
        .cp-tile { padding:9px 10px; }
    }
</style>

<?php require __DIR__ . '/footer.php'; ?>
