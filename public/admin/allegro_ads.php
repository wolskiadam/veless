<?php
declare(strict_types=1);

/**
 * Allegro Ads — koszty kampanii z rozliczeń Allegro, miesięczny budżet, statystyki kampanii
 * z raportu CSV z panelu Allegro Ads oraz skróty do panelu.
 *
 * Allegro nie udostępnia publicznego API Allegro Ads (tylko kontom z listy partnerów), dlatego
 * tworzenie kampanii, stawki i budżety kampanii zmienia się w panelu ads.allegro.pl.
 */

use Pase\Repository\SettingsRepository;
use Pase\Services\AllegroAds;
use PasePlugin\Allegro\AllegroPlugin;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$config   = require PASE_ROOT . '/config/config.php';
$client   = AllegroPlugin::makeClient($pdo, $config['allegro'] ?? []);
$settings = new SettingsRepository($pdo);
$ads      = new AllegroAds($pdo);
$tz       = new DateTimeZone('Europe/Warsaw');

$month = (string) ($_GET['m'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = (new DateTimeImmutable('now', $tz))->format('Y-m');
}
$monthStart = new DateTimeImmutable($month . '-01 00:00:00', $tz);
$monthEnd   = $monthStart->modify('last day of this month')->setTime(23, 59, 59);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEdit()) {
    csrfCheck();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'budget') {
        $budget = max(0, round(AllegroAds::number((string) ($_POST['budget'] ?? '')), 2));
        $settings->setMany([AllegroAds::BUDGET_KEY => (string) $budget]);
        flash($budget > 0 ? 'Zapisano budżet miesięczny.' : 'Budżet wyłączony.');
    } elseif ($action === 'types') {
        $types = array_values(array_unique(array_filter(array_map('strval', (array) ($_POST['types'] ?? [])), static fn($t) => preg_match('/^[A-Za-z0-9_-]{1,20}$/', $t))));
        // Pusty wybór zapisujemy jako "[]", żeby nie wracała automatyczna podpowiedź.
        $settings->setMany([AllegroAds::TYPES_KEY => json_encode($types)]);
        flash('Zapisano rodzaje opłat liczone jako Allegro Ads.');
    } elseif ($action === 'import') {
        $f = $_FILES['csv'] ?? null;
        $from = AllegroAds::date((string) ($_POST['from'] ?? ''));
        $to   = AllegroAds::date((string) ($_POST['to'] ?? ''));
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            flash('Wybierz plik CSV z raportem z panelu Allegro Ads.', 'err');
        } elseif ($from === null || $to === null || $from > $to) {
            flash('Podaj okres, którego dotyczy raport (od–do).', 'err');
        } elseif ((int) $f['size'] > 10 * 1024 * 1024) {
            flash('Plik jest za duży (limit 10 MB).', 'err');
        } else {
            $parsed = AllegroAds::parseCsv((string) file_get_contents((string) $f['tmp_name']));
            if ($parsed['errors'] !== []) {
                flash(implode(' ', $parsed['errors']), 'err');
            } else {
                $ads->import((string) $f['name'], $from, $to, $parsed['rows']);
                flash('Zaimportowano ' . count($parsed['rows']) . ' wierszy. Rozpoznane kolumny: ' . implode(', ', $parsed['columns']) . '.');
                redirectAfterPost('allegro_ads.php?m=' . substr($from, 0, 7));
            }
        }
    } elseif ($action === 'delete_import') {
        $ads->deleteImport((int) ($_POST['id'] ?? 0));
        flash('Usunięto import.');
    } elseif ($action === 'probe') {
        $r = $client->adsApiProbe();
        $settings->setMany([AllegroAds::PROBE_KEY => json_encode(['at' => time(), 'ok' => $r['ok'], 'status' => $r['status'], 'message' => $r['message'], 'count' => count($r['campaigns'])])]);
        flash($r['ok'] ? 'Allegro udostępniło API Ads dla tego konta (' . count($r['campaigns']) . ' kampanii).' : 'Brak dostępu do API Allegro Ads: ' . $r['message'], $r['ok'] ? 'ok' : 'err');
    }
    redirectAfterPost();
}

// --- Koszty z rozliczeń Allegro ---
$connected = $client->bearerToken() !== null;
$typesRes  = $connected ? $client->billingTypes() : ['ok' => false, 'items' => [], 'message' => 'Konto Allegro nie jest połączone.'];
$savedTypes = json_decode((string) $settings->get(AllegroAds::TYPES_KEY, ''), true);
$typesGuessed = !is_array($savedTypes);
$adsTypes = is_array($savedTypes) ? array_map('strval', $savedTypes) : AllegroAds::guessAdsTypes($typesRes['items']);

$billing = null;
$billingError = '';
if ($connected && $adsTypes !== []) {
    $utc = new DateTimeZone('UTC');
    $until = min($monthEnd, new DateTimeImmutable('now', $tz));
    if ($until >= $monthStart) {
        $res = $client->billingEntries($monthStart->setTimezone($utc), $until->setTimezone($utc), $adsTypes);
        $billing = AllegroAds::summarizeBilling($res['items']);
        $billing['truncated'] = $res['truncated'];
        $billingError = $res['ok'] ? '' : $res['message'];
    } else {
        $billing = AllegroAds::summarizeBilling([]);
        $billing['truncated'] = false;
    }
} elseif ($connected && !$typesRes['ok']) {
    $billingError = $typesRes['message'];
}

$now = new DateTimeImmutable('now', $tz);
$isCurrent = $now->format('Y-m') === $month;
$daysInMonth = (int) $monthStart->format('t');
$budget = (float) $settings->get(AllegroAds::BUDGET_KEY, '0');
$usage  = AllegroAds::budgetUsage($budget, (float) ($billing['total'] ?? 0), $isCurrent ? (int) $now->format('j') : $daysInMonth, $daysInMonth);

// --- Statystyki z importu CSV ---
$stats   = $ads->campaignStats($monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d'));
$totals  = AllegroAds::totals($stats);
$imports = $ads->imports();
$probe   = json_decode((string) $settings->get(AllegroAds::PROBE_KEY, ''), true);

$e     = static fn($v) => htmlspecialchars((string) $v);
$money = static fn(?float $v) => $v === null ? '—' : number_format($v, 2, ',', ' ') . ' zł';
$int   = static fn(int $v) => number_format($v, 0, ',', ' ');
$pct   = static fn(?float $v) => $v === null ? '—' : number_format($v, 1, ',', ' ') . '%';
$months = ['', 'styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
$monthLabel = $months[(int) $monthStart->format('n')] . ' ' . $monthStart->format('Y');
$prev = $monthStart->modify('-1 month')->format('Y-m');
$next = $monthStart->modify('+1 month')->format('Y-m');

$PAGE_TITLE = 'Allegro Ads';
$PAGE_KEY   = 'allegro';
require __DIR__ . '/header.php';
?>

<div class="card ads-head">
    <div>
        <strong>📣 Allegro Ads</strong>
        <p class="ads-muted">Koszty kampanii z rozliczeń Allegro, budżet miesięczny i statystyki kampanii z raportu z panelu Allegro Ads.
            Kampanie, stawki i budżety kampanii ustawiasz w panelu Allegro Ads — Allegro nie udostępnia do tego API.</p>
    </div>
    <div class="ads-links">
        <a class="btn" href="<?= $e(AllegroAds::PANEL_URL) ?>" target="_blank" rel="noopener">Otwórz panel Allegro Ads ↗</a>
        <a class="btn secondary" href="allegro_offers.php">Oferty Allegro</a>
    </div>
</div>

<div class="ads-month">
    <a class="btn secondary" href="?m=<?= $e($prev) ?>">←</a>
    <strong><?= $e($monthLabel) ?></strong>
    <?php if (!$isCurrent): ?><a class="btn secondary" href="?m=<?= $e($next) ?>">→</a><a href="allegro_ads.php" class="ads-muted">bieżący miesiąc</a><?php endif; ?>
</div>

<div class="ads-kpis">
    <div class="card ads-kpi">
        <span>Koszt Ads (rozliczenia Allegro)</span>
        <b><?= $billing !== null ? $e($money($billing['total'])) : '—' ?></b>
        <small><?= $billing === null ? ($connected ? 'wybierz rodzaje opłat poniżej' : 'połącz konto Allegro') : ($isCurrent ? 'do dziś' : 'cały miesiąc') ?></small>
    </div>
    <div class="card ads-kpi">
        <span>Budżet miesięczny</span>
        <b><?= $budget > 0 ? $e($money($budget)) : '—' ?></b>
        <?php if ($usage['percent'] !== null): ?>
            <div class="ads-bar ads-<?= $e($usage['level']) ?>"><i style="width:<?= min(100, (int) round($usage['percent'])) ?>%"></i></div>
            <small>wykorzystano <?= $e($pct($usage['percent'])) ?><?= $isCurrent ? ' · prognoza na koniec miesiąca ' . $e($money($usage['forecast'])) : '' ?></small>
        <?php else: ?><small>nie ustawiono</small><?php endif; ?>
    </div>
    <div class="card ads-kpi">
        <span>Sprzedaż z reklam (raport)</span>
        <b><?= $stats ? $e($money($totals['sales_value'])) : '—' ?></b>
        <small><?= $stats ? 'ROAS ' . ($totals['roas'] === null ? '—' : $e(number_format($totals['roas'], 2, ',', ' '))) . ' · koszt/sprzedaż ' . $e($pct($totals['acos'])) : 'zaimportuj raport z panelu Ads' ?></small>
    </div>
</div>

<?php if ($usage['level'] === 'over' || ($usage['level'] === 'warn' && $isCurrent)): ?>
    <div class="card ads-alert ads-<?= $e($usage['level']) ?>">
        <?= $usage['level'] === 'over'
            ? 'Budżet miesięczny został przekroczony. Obniż budżety dzienne kampanii albo je wstrzymaj w panelu Allegro Ads.'
            : 'Przy obecnym tempie wydatków budżet skończy się przed końcem miesiąca (prognoza ' . $e($money($usage['forecast'])) . ').' ?>
        <a href="<?= $e(AllegroAds::PANEL_URL) ?>" target="_blank" rel="noopener">Panel Allegro Ads ↗</a>
    </div>
<?php endif; ?>

<div class="card">
    <strong>Statystyki kampanii — <?= $e($monthLabel) ?></strong>
    <?php if (!$stats): ?>
        <p class="ads-muted">Brak statystyk za ten miesiąc. W panelu Allegro Ads wejdź w raport kampanii, wybierz okres i pobierz go jako CSV, a potem zaimportuj go niżej.</p>
    <?php else: ?>
        <div class="ads-scroll"><table class="ads-table">
            <tr><th>Kampania</th><th>Wyświetlenia</th><th>Kliknięcia</th><th>CTR</th><th>Koszt</th><th>Śr. CPC</th><th>Sprzedaż</th><th>Szt.</th><th>ROAS</th><th>Koszt/sprzedaż</th></tr>
            <?php foreach (array_merge($stats, [$totals]) as $i => $s): ?>
                <tr<?= $i === count($stats) ? ' class="ads-total"' : '' ?>>
                    <td><?= $e($s['campaign']) ?></td>
                    <td><?= $e($int($s['impressions'])) ?></td>
                    <td><?= $e($int($s['clicks'])) ?></td>
                    <td><?= $e($pct($s['ctr'])) ?></td>
                    <td><?= $e($money($s['cost'])) ?></td>
                    <td><?= $e($money($s['cpc'])) ?></td>
                    <td><?= $e($money($s['sales_value'])) ?></td>
                    <td><?= $e($int($s['sold_items'])) ?></td>
                    <td class="<?= $s['roas'] !== null && $s['roas'] < 1 ? 'ads-bad' : '' ?>"><?= $s['roas'] === null ? '—' : $e(number_format($s['roas'], 2, ',', ' ')) ?></td>
                    <td><?= $e($pct($s['acos'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </table></div>
        <p class="ads-muted" style="font-size:12px">ROAS = sprzedaż ÷ koszt (poniżej 1 reklama kosztuje więcej, niż przynosi). Dane z zaimportowanego raportu, nie na żywo.</p>
    <?php endif; ?>
</div>

<?php if ($billing !== null || $billingError !== ''): ?>
<div class="card">
    <strong>Koszty Ads z rozliczeń Allegro — <?= $e($monthLabel) ?></strong>
    <?php if ($billingError !== ''): ?><p class="ads-bad"><?= $e($billingError) ?></p><?php endif; ?>
    <?php if ($billing !== null && $billing['byDay']): ?>
        <?php $maxDay = max(array_map('abs', $billing['byDay'])) ?: 1; ?>
        <div class="ads-days">
            <?php foreach ($billing['byDay'] as $day => $cost): ?>
                <div title="<?= $e(date('d.m', strtotime($day)) . ': ' . $money($cost)) ?>"><i style="height:<?= max(2, (int) round(abs($cost) / $maxDay * 100)) ?>%"></i><span><?= $e(date('j', strtotime($day))) ?></span></div>
            <?php endforeach; ?>
        </div>
        <div class="ads-grid2">
            <table class="ads-table">
                <tr><th>Rodzaj opłaty</th><th>Kwota</th></tr>
                <?php foreach ($billing['byType'] as $type => $cost): ?><tr><td><?= $e($type) ?></td><td><?= $e($money($cost)) ?></td></tr><?php endforeach; ?>
            </table>
            <?php if ($billing['byOffer']): ?>
            <table class="ads-table">
                <tr><th>Oferta (najdroższe)</th><th>Kwota</th></tr>
                <?php foreach (array_slice($billing['byOffer'], 0, 10) as $o): ?>
                    <tr><td><a href="https://allegro.pl/oferta/<?= $e(rawurlencode($o['id'])) ?>" target="_blank" rel="noopener"><?= $e($o['name']) ?></a></td><td><?= $e($money($o['cost'])) ?></td></tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>
        <?php if ($billing['truncated']): ?><p class="ads-muted">Pokazano pierwsze 3000 operacji z tego miesiąca.</p><?php endif; ?>
    <?php elseif ($billing !== null && $billingError === ''): ?>
        <p class="ads-muted">Brak opłat wybranych rodzajów w tym miesiącu.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (canEdit()): ?>
<div class="ads-grid2">
    <div class="card">
        <strong>Import raportu z panelu Allegro Ads</strong>
        <p class="ads-muted">Plik CSV z raportem kampanii. Rozpoznawane kolumny: kampania, data (opcjonalnie), wyświetlenia, kliknięcia, koszt, wartość sprzedaży, liczba sprzedanych sztuk. Ponowny import za ten sam okres zastępuje poprzedni.</p>
        <form method="post" enctype="multipart/form-data" class="ads-form">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="import">
            <input type="file" name="csv" accept=".csv,.txt,text/csv,text/plain" required>
            <div class="ads-row">
                <label>Od <input type="date" name="from" value="<?= $e($monthStart->format('Y-m-d')) ?>" required></label>
                <label>Do <input type="date" name="to" value="<?= $e($monthEnd->format('Y-m-d')) ?>" required></label>
            </div>
            <button class="btn" type="submit" style="align-self:flex-start">Importuj</button>
        </form>
        <?php if ($imports): ?>
            <div class="ads-scroll"><table class="ads-table" style="margin-top:12px">
                <tr><th>Okres</th><th>Plik</th><th>Wiersze</th><th></th></tr>
                <?php foreach ($imports as $im): ?>
                    <tr>
                        <td><?= $e(date('d.m.Y', strtotime($im['period_from'])) . ' – ' . date('d.m.Y', strtotime($im['period_to']))) ?></td>
                        <td><?= $e($im['file_name']) ?></td>
                        <td><?= (int) $im['rows_count'] ?></td>
                        <td><form method="post" onsubmit="return confirm('Usunąć ten import?')"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="delete_import"><input type="hidden" name="id" value="<?= (int) $im['id'] ?>"><button class="btn secondary" type="submit">Usuń</button></form></td>
                    </tr>
                <?php endforeach; ?>
            </table></div>
        <?php endif; ?>
    </div>

    <div class="card">
        <strong>Budżet miesięczny</strong>
        <p class="ads-muted">Twój limit wydatków na Allegro Ads w miesiącu. CRM porównuje go z kosztami z rozliczeń i ostrzega, gdy wydatki idą za szybko. Budżety samych kampanii ustawiasz w panelu Allegro Ads.</p>
        <form method="post" class="ads-row">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="budget">
            <label>Kwota (zł) <input type="text" name="budget" inputmode="decimal" value="<?= $budget > 0 ? $e(number_format($budget, 2, ',', '')) : '' ?>" placeholder="np. 500"></label>
            <button class="btn" type="submit">Zapisz</button>
        </form>

        <strong style="display:block;margin-top:18px">Które opłaty liczyć jako Allegro Ads</strong>
        <?php if (!$connected): ?>
            <p class="ads-muted">Połącz konto Allegro, żeby pobierać koszty z rozliczeń.</p>
        <?php elseif (!$typesRes['ok']): ?>
            <p class="ads-bad"><?= $e($typesRes['message']) ?></p>
        <?php else: ?>
            <p class="ads-muted"><?= $typesGuessed ? 'Zaznaczono rodzaje, które wyglądają na Allegro Ads — sprawdź i zapisz.' : 'Rodzaje opłat z rozliczeń Allegro.' ?></p>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="types">
                <?php $typeItems = $typesRes['items']; usort($typeItems, static fn($a, $b) => (int) in_array($b['id'], $adsTypes, true) <=> (int) in_array($a['id'], $adsTypes, true) ?: strcmp($a['description'], $b['description'])); ?>
                <div class="ads-types">
                    <?php foreach ($typeItems as $t): ?>
                        <label><input type="checkbox" name="types[]" value="<?= $e($t['id']) ?>" <?= in_array($t['id'], $adsTypes, true) ? 'checked' : '' ?>> <?= $e($t['description']) ?> <span class="ads-muted">(<?= $e($t['id']) ?>)</span></label>
                    <?php endforeach; ?>
                </div>
                <button class="btn" type="submit" style="margin-top:8px">Zapisz rodzaje opłat</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <strong>Zarządzanie kampaniami przez API</strong>
    <p class="ads-muted">Allegro udostępnia API Allegro Ads (kampanie, stawki, budżety) tylko wybranym partnerom, na liście prowadzonej przez Allegro. Jeśli Allegro doda Twoje konto do tej listy, CRM może przejąć zarządzanie kampaniami. Sprawdź dostęp:</p>
    <form method="post" class="ads-row">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="probe">
        <button class="btn secondary" type="submit">Sprawdź dostęp do API Ads</button>
        <?php if (is_array($probe)): ?>
            <span class="<?= !empty($probe['ok']) ? '' : 'ads-bad' ?>">
                <?= !empty($probe['ok']) ? 'Dostęp jest (' . (int) $probe['count'] . ' kampanii)' : 'Brak dostępu (kod ' . (int) $probe['status'] . '): ' . $e($probe['message']) ?>
                <span class="ads-muted">· <?= $e((new DateTime('@' . (int) $probe['at']))->setTimezone($tz)->format('d.m.Y H:i')) ?></span>
            </span>
        <?php endif; ?>
    </form>
</div>
<?php endif; ?>

<style>
    .ads-muted { color:var(--ink-2); font-size:13px; }
    .ads-head { display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; align-items:flex-start; }
    .ads-head p { margin:6px 0 0; max-width:640px; }
    .ads-links { display:flex; gap:8px; flex-wrap:wrap; }
    .ads-month { display:flex; gap:10px; align-items:center; margin:4px 0 12px; }
    .ads-kpis { display:grid; grid-template-columns:repeat(auto-fit, minmax(min(220px, 100%), 1fr)); gap:12px; }
    .ads-kpi span { display:block; color:var(--ink-2); font-size:12px; }
    .ads-kpi b { display:block; font-size:24px; margin:4px 0; }
    .ads-kpi small { color:var(--ink-2); }
    .ads-bar { height:8px; background:var(--muted-bg); border-radius:4px; overflow:hidden; margin:6px 0; }
    .ads-bar i { display:block; height:100%; background:var(--success-ink); }
    .ads-bar.ads-warn i { background:var(--warn-ink); }
    .ads-bar.ads-over i { background:var(--danger-ink); }
    .ads-alert { border-left:4px solid var(--warn-ink); background:var(--warn-bg); }
    .ads-alert.ads-over { border-left-color:var(--danger-ink); background:var(--danger-bg); }
    .ads-scroll { overflow-x:auto; }
    .ads-table { width:100%; margin-top:8px; }
    .ads-table td, .ads-table th { white-space:nowrap; }
    .ads-table td:first-child { white-space:normal; }
    .ads-total td { font-weight:600; border-top:2px solid var(--line); }
    .ads-bad { color:var(--danger-ink); }
    .ads-grid2 > * { min-width:0; }
    .ads-grid2 { display:grid; grid-template-columns:repeat(auto-fit, minmax(min(320px, 100%), 1fr)); gap:12px; align-items:start; }
    .ads-days { display:flex; align-items:flex-end; gap:3px; height:120px; margin:12px 0; }
    .ads-days div { flex:1; display:flex; flex-direction:column; justify-content:flex-end; align-items:center; height:100%; }
    .ads-days i { display:block; width:100%; background:var(--accent); border-radius:2px 2px 0 0; }
    .ads-days span { font-size:10px; color:var(--ink-2); }
    .ads-form { display:flex; flex-direction:column; gap:8px; }
    .ads-row { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; }
    .ads-row label { font-size:12px; color:var(--ink-2); display:flex; flex-direction:column; gap:4px; }
    .ads-types { max-height:220px; overflow-y:auto; border:1px solid var(--line); border-radius:6px; padding:6px 10px; }
    .ads-types label { display:block; font-size:13px; padding:2px 0; }
</style>

<?php require __DIR__ . '/footer.php'; ?>
