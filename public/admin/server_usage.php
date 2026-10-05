<?php
declare(strict_types=1);

/**
 * System → Obciążenie serwera: ile miejsca zajmuje system (pliki i baza), ile żądań
 * obsługuje, ile zapytań wysyła do zewnętrznych API, jak długo trwają odpowiedzi,
 * stan kolejki zadań i crona. Dane o żądaniach zbiera Pase\Support\UsageStats.
 */

use Pase\Repository\SettingsRepository;
use Pase\Services\HostingQuota;
use Pase\Services\ProcessControl;
use Pase\Support\UsageStats;

require __DIR__ . '/auth.php';
requireRole(['admin']);
/** @var PDO $pdo */

$settingsRepo = new SettingsRepository($pdo);
$control      = new ProcessControl($settingsRepo);
$quotaService = new HostingQuota($settingsRepo, PASE_ROOT . '/storage/stats/quota-cache.json');

// ---------- Akcje: wstrzymywanie procesów, limit kolejki, konto hostingu ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrfCheck();
    $act  = (string) ($_POST['action'] ?? '');
    $proc = (string) ($_POST['process'] ?? '');
    $durations = ['1h' => 3600, '4h' => 14400, '24h' => 86400, 'indef' => null];
    try {
        if ($act === 'pause' && isset(ProcessControl::PROCESSES[$proc]) && array_key_exists((string) ($_POST['duration'] ?? ''), $durations)) {
            $until = $control->pause($proc, $durations[$_POST['duration']]);
            flash('Wstrzymano: ' . ProcessControl::PROCESSES[$proc][0] . ($until >= ProcessControl::INDEFINITE ? ' (do odwołania).' : ' do ' . (new DateTimeImmutable('@' . $until))->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('d.m H:i') . '.'));
            \Pase\Support\Logger::info('Wstrzymano proces ' . $proc . ' przez ' . currentUserName());
        } elseif ($act === 'resume' && isset(ProcessControl::PROCESSES[$proc])) {
            $control->resume($proc);
            flash('Wznowiono: ' . ProcessControl::PROCESSES[$proc][0] . '.');
            \Pase\Support\Logger::info('Wznowiono proces ' . $proc . ' przez ' . currentUserName());
        } elseif ($act === 'resume_all') {
            foreach (array_keys(ProcessControl::PROCESSES) as $p) { $control->resume($p); }
            flash('Wznowiono całą pracę w tle.');
        } elseif ($act === 'batch') {
            $control->setBatchSize(($_POST['batch'] ?? '') === '' ? null : (int) $_POST['batch']);
            flash('Zapisano limit zadań kolejki.');
        } elseif ($act === 'quota') {
            $gb = trim(str_replace(',', '.', (string) ($_POST['manual_gb'] ?? '')));
            if ($gb !== '' && (!is_numeric($gb) || (float) $gb <= 0 || (float) $gb > 100000)) { throw new \RuntimeException('Podaj limit w GB, np. 20.'); }
            $url = trim((string) ($_POST['da_url'] ?? ''));
            if ($url !== '' && !preg_match('#^https://[^\s/]+(:\d+)?/?$#i', $url)) { throw new \RuntimeException('Adres panelu podaj w formie https://serwer:2222 (bez ścieżki).'); }
            $values = [HostingQuota::S_MANUAL_GB => $gb, HostingQuota::S_URL => $url, HostingQuota::S_USER => trim((string) ($_POST['da_user'] ?? ''))];
            $key = trim((string) ($_POST['da_key'] ?? ''));
            if ($key !== '') { $values[HostingQuota::S_KEY] = $key; }          // puste = zostaje zapisany klucz
            if (!empty($_POST['da_clear'])) { $values[HostingQuota::S_KEY] = ''; $values[HostingQuota::S_URL] = ''; $values[HostingQuota::S_USER] = ''; }
            $settingsRepo->setMany($values);
            $st = $quotaService->status(true);
            if ($st['source'] === 'directadmin') {
                flash('Połączono z panelem hostingu - zajętość konta odczytana.');
            } elseif ($st['error'] !== null) {
                flash('Zapisano, ale odczyt z panelu się nie udał: ' . $st['error'], 'err');
            } else {
                flash('Zapisano limit miejsca.');
            }
        }
    } catch (\Throwable $ex) {
        flash($ex instanceof \RuntimeException ? $ex->getMessage() : 'Nie udało się zapisać.', 'err');
    }
    redirectAfterPost('server_usage.php');
}

$e   = static fn($v) => htmlspecialchars((string) $v);
$tz  = new DateTimeZone('Europe/Warsaw');
$fmtBytes = static function (float $b): string {
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
    return number_format($b, $i === 0 ? 0 : ($b < 100 ? 1 : 0), ',', ' ') . ' ' . $u[$i];
};
$fmtNum = static fn($n) => number_format((float) $n, 0, ',', ' ');

// ---------- Miejsce na dysku (skan katalogu projektu, wynik trzymany 15 min) ----------
$diskCacheFile = PASE_ROOT . '/storage/stats/disk-cache.json';
$disk = is_file($diskCacheFile) ? json_decode((string) @file_get_contents($diskCacheFile), true) : null;
if (!is_array($disk) || ($disk['t'] ?? 0) < time() - 900 || isset($_GET['rescan'])) {
    $dirSize = static function (string $path): array {
        $size = 0; $files = 0;
        if (!is_dir($path)) { return [0, 0]; }
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) { $size += $f->getSize(); $files++; }
            }
        } catch (\Throwable) {}
        return [$size, $files];
    };
    $parts = [];
    foreach (scandir(PASE_ROOT) ?: [] as $name) {
        if ($name === '.' || $name === '..' || $name === 'storage') { continue; }
        $p = PASE_ROOT . '/' . $name;
        [$sz, $n] = is_dir($p) ? $dirSize($p) : [(int) @filesize($p), 1];
        $parts['kod'] = [($parts['kod'][0] ?? 0) + $sz, ($parts['kod'][1] ?? 0) + $n];
    }
    // storage/ rozbijamy na podkatalogi, bo tam rośnie to, co da się sprzątać.
    foreach (scandir(PASE_ROOT . '/storage') ?: [] as $name) {
        if ($name === '.' || $name === '..') { continue; }
        $p = PASE_ROOT . '/storage/' . $name;
        $parts['storage/' . $name] = is_dir($p) ? $dirSize($p) : [(int) @filesize($p), 1];
    }
    arsort($parts);
    $disk = ['t' => time(), 'parts' => $parts];
    @file_put_contents($diskCacheFile, json_encode($disk));
}
$diskTotal = array_sum(array_map(static fn($p) => $p[0], $disk['parts']));
// Miejsce na KONCIE hostingu (limit pakietu). disk_free_space() pokazywałby wolne miejsce całego
// dysku serwera współdzielonego z innymi klientami - patrz Pase\Services\HostingQuota.
$quota = $quotaService->status(isset($_GET['quota_refresh']));
$storageLabels = [
    'kod' => 'Kod systemu (src, public, wtyczki, biblioteki)', 'storage/app.log' => 'Dziennik zdarzeń (app.log)',
    'storage/sessions' => 'Sesje zalogowanych', 'storage/uploads' => 'Załączniki i wgrane pliki', 'storage/labels' => 'Etykiety przesyłek',
    'storage/documents' => 'Dokumenty (faktury, PDF)', 'storage/backups' => 'Kopie zapasowe', 'storage/stats' => 'Statystyki obciążenia',
    'storage/security' => 'Limity logowania', 'storage/cache' => 'Pamięć podręczna', 'storage/logs' => 'Dzienniki',
];

// ---------- Baza danych ----------
$tables = [];
try {
    $tables = $pdo->query("SELECT table_name AS name, table_rows AS rows_est, data_length + index_length AS bytes
                           FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY bytes DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable) {}
$dbTotal = array_sum(array_map(static fn($t) => (float) $t['bytes'], $tables));

// ---------- Żądania i API (UsageStats, dni w UTC, godziny pokazujemy po polsku) ----------
$days = UsageStats::read(30);
$kindLabels = ['panel' => 'Panel (strony)', 'panel_bg' => 'Panel w tle (dzwoneczek, śledzenie)', 'klient' => 'Strona zamówienia klienta',
               'webhook' => 'Webhooki ze sklepów i Allegro', 'cron' => 'Cron i zadania w tle', 'drukarka' => 'Agent drukarki', 'inne' => 'Inne'];
$sumReq = static function (array $day, ?array $hours = null): array {
    $n = $ms = $max = $mem = 0; $byKind = [];
    foreach ($day['req'] ?? [] as $h => $kinds) {
        if ($hours !== null && !in_array((string) $h, $hours, true)) { continue; }
        foreach ($kinds as $k => $r) {
            $n += $r['n']; $ms += $r['ms']; $max = max($max, $r['max']); $mem = max($mem, $r['mem']);
            $byKind[$k] = ($byKind[$k] ?? 0) + $r['n'];
        }
    }
    return ['n' => $n, 'avg' => $n ? (int) round($ms / $n) : 0, 'max' => $max, 'mem' => $mem, 'kinds' => $byKind];
};
$sumApi = static function (array $day): array {
    $out = [];
    foreach ($day['api'] ?? [] as $svcs) {
        foreach ($svcs as $s => $a) {
            $out[$s]['n'] = ($out[$s]['n'] ?? 0) + $a['n'];
            $out[$s]['err'] = ($out[$s]['err'] ?? 0) + $a['err'];
        }
    }
    return $out;
};

// Ostatnie 24 godziny, godzina po godzinie (klucze: dzień UTC + godzina UTC).
$hours24 = [];
for ($i = 23; $i >= 0; $i--) {
    $ts  = time() - $i * 3600;
    $d   = gmdate('Y-m-d', $ts); $h = gmdate('H', $ts);
    $req = 0; $api = 0; $kinds = [];
    foreach ($days[$d]['req'][$h] ?? [] as $k => $r) { $req += $r['n']; $kinds[$k] = $r['n']; }
    foreach ($days[$d]['api'][$h] ?? [] as $a) { $api += $a['n']; }
    $hours24[] = ['label' => (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('H:00'), 'req' => $req, 'api' => $api, 'kinds' => $kinds];
}
$req24 = array_sum(array_column($hours24, 'req'));
$api24 = array_sum(array_column($hours24, 'api'));

$today = gmdate('Y-m-d');
$todayReq = $sumReq($days[$today] ?? []);
$periods = ['Dziś' => 1, '7 dni' => 7, '30 dni' => 30];
$reqByPeriod = $apiByPeriod = [];
foreach ($periods as $label => $n) {
    $slice = array_slice($days, -$n, null, true);
    $agg = ['n' => 0, 'ms' => 0, 'max' => 0, 'mem' => 0, 'kinds' => []];
    $apiAgg = [];
    foreach ($slice as $d) {
        $r = $sumReq($d);
        $agg['n'] += $r['n']; $agg['ms'] += $r['avg'] * $r['n']; $agg['max'] = max($agg['max'], $r['max']); $agg['mem'] = max($agg['mem'], $r['mem']);
        foreach ($r['kinds'] as $k => $v) { $agg['kinds'][$k] = ($agg['kinds'][$k] ?? 0) + $v; }
        foreach ($sumApi($d) as $s => $a) {
            $apiAgg[$s]['n'] = ($apiAgg[$s]['n'] ?? 0) + $a['n'];
            $apiAgg[$s]['err'] = ($apiAgg[$s]['err'] ?? 0) + $a['err'];
        }
    }
    $agg['avg'] = $agg['n'] ? (int) round($agg['ms'] / $agg['n']) : 0;
    $reqByPeriod[$label] = $agg;
    $apiByPeriod[$label] = $apiAgg;
}
$apiServices = array_keys($apiByPeriod['30 dni']);
usort($apiServices, static fn($a, $b) => ($apiByPeriod['30 dni'][$b]['n'] ?? 0) <=> ($apiByPeriod['30 dni'][$a]['n'] ?? 0));
$svcLabels = ['allegro' => 'Allegro', 'wfirma' => 'wFirma', 'blpaczka' => 'BLPaczka', 'smsapi' => 'SMSAPI', 'smtp' => 'E-mail (SMTP)',
              'inpost' => 'InPost', 'empik' => 'Empik', 'erli' => 'Erli', 'google' => 'Google'];
$statsSince = null;
foreach ($days as $d => $data) { if (!empty($data['req'])) { $statsSince = $d; break; } }

// ---------- Kolejka i cron ----------
$settings = new SettingsRepository($pdo);
$workerAt = (int) ($settings->get('WORKER_LAST_RUN_AT', '0') ?? 0);
$queue = ['pending' => 0, 'reserved' => 0, 'failed' => 0, 'done24' => 0, 'failed24' => 0];
try {
    foreach ($pdo->query("SELECT status, COUNT(*) n FROM job_queue GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR) as $st => $n) { $queue[$st] = (int) $n; }
    $queue['done24']   = (int) $pdo->query("SELECT COUNT(*) FROM job_queue WHERE status='done' AND updated_at > NOW() - INTERVAL 1 DAY")->fetchColumn();
    $queue['failed24'] = (int) $pdo->query("SELECT COUNT(*) FROM job_queue WHERE status='failed' AND updated_at > NOW() - INTERVAL 1 DAY")->fetchColumn();
} catch (\Throwable) {}

// ---------- Serwer ----------
$load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;
$memLimit = ini_get('memory_limit') === '-1' ? 'bez limitu' : (string) ini_get('memory_limit');

$PAGE_TITLE = 'Obciążenie serwera';
$PAGE_KEY   = 'server_usage';
require __DIR__ . '/header.php';
$maxBar = max(1, max(array_column($hours24, 'req')));
?>

<div class="su-tiles">
    <div class="su-tile"><div class="v"><?= $fmtBytes($diskTotal + $dbTotal) ?></div><div class="l">Zajmuje łącznie</div><div class="s">pliki <?= $fmtBytes($diskTotal) ?> · baza <?= $fmtBytes($dbTotal) ?></div></div>
    <div class="su-tile"><div class="v"><?= $fmtNum($req24) ?></div><div class="l">Żądań w 24 h</div><div class="s">śr. <?= $fmtNum($req24 / 24) ?> na godzinę</div></div>
    <div class="su-tile"><div class="v"><?= $fmtNum($api24) ?></div><div class="l">Zapytań do API w 24 h</div><div class="s">wysłanych przez system na zewnątrz</div></div>
    <div class="su-tile"><div class="v"><?= $fmtNum($reqByPeriod['Dziś']['avg']) ?> ms</div><div class="l">Średni czas odpowiedzi</div><div class="s">dziś; najdłuższe <?= $reqByPeriod['Dziś']['max'] >= 1000 ? number_format($reqByPeriod['Dziś']['max'] / 1000, 1, ',', '') . ' s' : $fmtNum($reqByPeriod['Dziś']['max']) . ' ms' ?></div></div>
    <div class="su-tile"><div class="v"><?= $reqByPeriod['Dziś']['mem'] ?> MB</div><div class="l">Szczyt pamięci</div><div class="s">limit PHP: <?= $e($memLimit) ?></div></div>
    <?php
    $crmBytes = $diskTotal + $dbTotal;
    $qPct = ($quota['used'] !== null && $quota['limit']) ? $quota['used'] / $quota['limit'] * 100 : null;
    $qCls = $qPct === null ? '' : ($qPct >= 95 ? ' su-tile-bad' : ($qPct >= 80 ? ' su-tile-warn' : ''));
    ?>
    <a class="su-tile su-tile-link<?= $qCls ?>" href="#quota">
        <?php if ($quota['source'] === 'directadmin' && $quota['limit']): ?>
            <div class="v"><?= number_format($qPct, 1, ',', '') ?> %</div><div class="l">Miejsce na koncie hostingu</div>
            <div class="s"><?= $fmtBytes($quota['used']) ?> z <?= $fmtBytes($quota['limit']) ?> · zostało <?= $fmtBytes(max(0, $quota['limit'] - $quota['used'])) ?></div>
        <?php elseif ($quota['source'] === 'directadmin'): ?>
            <div class="v"><?= $fmtBytes($quota['used']) ?></div><div class="l">Zajęte na koncie hostingu</div><div class="s">pakiet bez limitu miejsca</div>
        <?php elseif ($quota['source'] === 'manual'): ?>
            <div class="v"><?= $fmtBytes($quota['limit']) ?></div><div class="l">Limit konta hostingu</div>
            <div class="s">CRM zajmuje <?= $fmtBytes($crmBytes) ?> (<?= number_format($crmBytes / $quota['limit'] * 100, 1, ',', '') ?> %) · resztę konta widać w panelu hostingu</div>
        <?php else: ?>
            <div class="v">—</div><div class="l">Miejsce na koncie hostingu</div><div class="s">ustaw limit lub połącz z panelem ↓</div>
        <?php endif; ?>
    </a>
</div>

<?php
// Gdzie zmienić częstotliwość każdego procesu (link + aktualna wartość).
$everyOf = static function (string $key) use ($settingsRepo): string {
    $min = (int) ($settingsRepo->get($key, \Pase\Services\Scheduler::DEFAULT_EVERY[$key] ?? '0') ?? '0');
    return $min > 0 ? 'co ' . $min . ' min' : 'wyłączone';
};
$handlingOn = (function () use ($settingsRepo): ?bool {
    try { return (bool) (\Pase\Services\AllegroHandlingSchedule::config($settingsRepo)['enabled'] ?? false); } catch (\Throwable) { return null; }
})();
$procLinks = [
    'worker'   => ['sync_settings.php', 'Stan crona i częstotliwość synchronizacji', 'cron co 1 min · tokeny ' . \Pase\Services\TaskTimings::label($settingsRepo, 'TOKENS_EVERY')],
    'queue'    => ['sync_settings.php#queue_stuck_minutes', 'Zmień czas ponawiania', 'zawieszone ponawiane po ' . \Pase\Services\TaskTimings::label($settingsRepo, 'QUEUE_STUCK_MINUTES')],
    'orders'   => ['sync_settings.php#sync_orders_every', 'Zmień częstotliwość', $everyOf('SYNC_ORDERS_EVERY')],
    'stock'    => ['sync_settings.php#sync_stock_every', 'Zmień częstotliwość', $everyOf('SYNC_STOCK_EVERY')],
    'price'    => ['sync_settings.php#sync_price_every', 'Zmień częstotliwość', $everyOf('SYNC_PRICE_EVERY')],
    'products' => ['sync_settings.php#sync_products_every', 'Zmień częstotliwość', $everyOf('SYNC_PRODUCTS_EVERY')],
    'tracking' => ['sync_settings.php#sync_tracking_every', 'Zmień częstotliwość', $everyOf('SYNC_TRACKING_EVERY') . ' · paczki z ' . \Pase\Services\TaskTimings::label($settingsRepo, 'TRACKING_MAX_AGE_DAYS')],
    'handling' => ['allegro_handling_schedule.php', 'Ustawienia harmonogramu', $handlingOn === null ? null : ($handlingOn ? 'włączony, raz dziennie' : 'wyłączony')],
    'print'    => ['sync_settings.php#print_poll_active', 'Zmień częstotliwość', 'w pracy co ' . \Pase\Services\TaskTimings::get($settingsRepo, 'PRINT_POLL_ACTIVE') . ' s · bez pracy co '
        . \Pase\Services\TaskTimings::get($settingsRepo, 'PRINT_POLL_IDLE') . ' s (po ' . \Pase\Services\TaskTimings::get($settingsRepo, 'PRINT_IDLE_AFTER') . ' min bez aktywności)'],
    'cleanup'  => ['sync_settings.php#queue_keep_done_days', 'Zmień', \Pase\Services\TaskTimings::get($settingsRepo, 'QUEUE_KEEP_DONE_DAYS') > 0
        ? \Pase\Services\TaskTimings::label($settingsRepo, 'QUEUE_CLEANUP_EVERY') . ' · wykonane starsze niż ' . \Pase\Services\TaskTimings::label($settingsRepo, 'QUEUE_KEEP_DONE_DAYS') : 'wyłączone'],
    'archive'  => ['sync_settings.php#archive_every', 'Zmień częstotliwość', \Pase\Services\TaskTimings::get($settingsRepo, 'ARCHIVE_EVERY') > 0
        ? \Pase\Services\TaskTimings::label($settingsRepo, 'ARCHIVE_EVERY') . ' · starsze niż ' . \Pase\Services\TaskTimings::label($settingsRepo, 'ARCHIVE_AFTER_DAYS') : 'wyłączona'],
];
?>
<?php $pauses = $control->activePauses(); $fmtUntil = static fn(int $u): string => $u >= ProcessControl::INDEFINITE ? 'do odwołania' : 'do ' . (new DateTimeImmutable('@' . $u))->setTimezone($tz)->format('d.m H:i'); ?>
<div class="card" id="processes">
    <div class="su-head">
        <strong>Praca w tle — wstrzymywanie i ograniczanie</strong>
        <?php if ($pauses): ?>
            <form method="post" class="su-inline"><input type="hidden" name="csrf" value="<?= $e(csrfToken()) ?>"><input type="hidden" name="action" value="resume_all"><button class="btn" type="submit">▶ Wznów wszystko</button></form>
        <?php endif; ?>
    </div>
    <p class="su-muted">Gdy serwer nie daje rady, wstrzymaj wybraną część pracy w tle na określony czas — po nim wróci sama. Nie da się wstrzymać odświeżania tokenów (inaczej trzeba by łączyć konta od nowa) ani webhooków — zamówienia z nich czekają w kolejce i nic nie ginie.</p>
    <table class="su-table su-proc">
        <?php foreach (ProcessControl::PROCESSES as $key => [$name, $desc]): $until = $pauses[$key] ?? null; ?>
            <tr class="<?= $until ? 'paused' : '' ?>">
                <td><strong><?= $e($name) ?></strong><div class="su-muted"><?= $e($desc) ?></div>
                    <?php [$lnHref, $lnLabel, $lnNow] = $procLinks[$key] ?? [null, null, null]; ?>
                    <div class="su-proc-link">
                        <?php if ($lnNow !== null): ?><span class="su-proc-now"><?= $e($lnNow) ?></span><?php endif; ?>
                        <?php if ($lnHref !== null && (!function_exists('canOpenPage') || canOpenPage(strtok($lnHref, '#')))): ?><a href="<?= $e($lnHref) ?>"><?= $e($lnLabel) ?> →</a><?php endif; ?>
                    </div>
                </td>
                <td class="r"><?= $until ? '<span class="pill warn">⏸ wstrzymane ' . $e($fmtUntil($until)) . '</span>' : '<span class="pill ok">działa</span>' ?></td>
                <td class="r">
                    <form method="post" class="su-inline">
                        <input type="hidden" name="csrf" value="<?= $e(csrfToken()) ?>">
                        <input type="hidden" name="process" value="<?= $e($key) ?>">
                        <?php if ($until): ?>
                            <input type="hidden" name="action" value="resume">
                            <button class="btn secondary" type="submit">▶ Wznów</button>
                        <?php else: ?>
                            <input type="hidden" name="action" value="pause">
                            <select name="duration" aria-label="Na jak długo">
                                <option value="1h">na 1 godz.</option><option value="4h">na 4 godz.</option><option value="24h">na 24 godz.</option><option value="indef">do odwołania</option>
                            </select>
                            <button class="btn secondary" type="submit">⏸ Wstrzymaj</button>
                        <?php endif; ?>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <td><strong>Limit zadań kolejki na minutę</strong><div class="su-muted">Mniej zadań na przebieg crona = mniejsze obciążenie, kolejka rozładowuje się wolniej.</div></td>
            <td></td>
            <td class="r">
                <form method="post" class="su-inline">
                    <input type="hidden" name="csrf" value="<?= $e(csrfToken()) ?>"><input type="hidden" name="action" value="batch">
                    <?php $curBatch = (int) ($settingsRepo->get(ProcessControl::BATCH_SETTING, '0') ?? '0'); $defBatch = (int) (\Pase\Support\Env::int('WORKER_BATCH_SIZE', 50)); ?>
                    <select name="batch" aria-label="Limit zadań">
                        <option value="">domyślnie (<?= $defBatch ?>)</option>
                        <?php foreach (ProcessControl::BATCH_OPTIONS as $n): ?><option value="<?= $n ?>" <?= $curBatch === $n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?>
                    </select>
                    <button class="btn secondary" type="submit">Zapisz</button>
                </form>
            </td>
        </tr>
    </table>
</div>

<?php if ($statsSince === null): ?>
    <div class="flash warn" style="margin-top:14px">Licznik żądań właśnie ruszył — wykres i tabele zapełnią się w ciągu najbliższych godzin.</div>
<?php endif; ?>

<div class="card">
    <strong>Żądania w ostatnich 24 godzinach</strong>
    <p class="su-muted">Każde otwarcie strony panelu, odświeżenie dzwoneczka, webhook ze sklepu, wizyta klienta na stronie zamówienia i przebieg crona. Najedź na słupek, żeby zobaczyć szczegóły.</p>
    <div class="su-chart" role="img" aria-label="Liczba żądań na godzinę w ostatnich 24 godzinach">
        <?php foreach ($hours24 as $i => $h):
            $tip = $h['label'] . ' — ' . $fmtNum($h['req']) . ' żądań, ' . $fmtNum($h['api']) . ' zapytań do API';
            foreach ($h['kinds'] as $k => $n) { $tip .= "\n" . ($kindLabels[$k] ?? $k) . ': ' . $fmtNum($n); }
        ?>
            <div class="su-col" title="<?= $e($tip) ?>">
                <div class="su-bar" style="height:<?= $h['req'] > 0 ? max(2, round($h['req'] / $maxBar * 100)) : 0 ?>%"></div>
                <div class="su-x"><?= $i % 3 === 0 ? $e($h['label']) : '' ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="su-axis"><span>najwięcej: <?= $fmtNum($maxBar) ?> / godz.</span></div>
</div>

<div class="su-grid">
    <div class="card">
        <strong>Żądania według źródła</strong>
        <table class="su-table">
            <tr><th>Źródło</th><?php foreach ($periods as $l => $_): ?><th class="r"><?= $e($l) ?></th><?php endforeach; ?></tr>
            <?php foreach ($kindLabels as $k => $label):
                if (($reqByPeriod['30 dni']['kinds'][$k] ?? 0) === 0) { continue; } ?>
                <tr><td><?= $e($label) ?></td><?php foreach ($periods as $l => $_): ?><td class="r"><?= $fmtNum($reqByPeriod[$l]['kinds'][$k] ?? 0) ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
            <tr class="tot"><td>Razem</td><?php foreach ($periods as $l => $_): ?><td class="r"><?= $fmtNum($reqByPeriod[$l]['n']) ?></td><?php endforeach; ?></tr>
            <tr><td class="su-muted">Średni czas odpowiedzi</td><?php foreach ($periods as $l => $_): ?><td class="r su-muted"><?= $fmtNum($reqByPeriod[$l]['avg']) ?> ms</td><?php endforeach; ?></tr>
        </table>
    </div>

    <div class="card">
        <strong>Zapytania wysyłane do zewnętrznych usług</strong>
        <?php if ($apiServices === []): ?>
            <p class="su-muted">Jeszcze brak danych.</p>
        <?php else: ?>
        <table class="su-table">
            <tr><th>Usługa</th><?php foreach ($periods as $l => $_): ?><th class="r"><?= $e($l) ?></th><?php endforeach; ?><th class="r">Błędy (30 dni)</th></tr>
            <?php foreach ($apiServices as $s): $err = $apiByPeriod['30 dni'][$s]['err'] ?? 0; ?>
                <tr><td><?= $e($svcLabels[$s] ?? $s) ?></td>
                    <?php foreach ($periods as $l => $_): ?><td class="r"><?= $fmtNum($apiByPeriod[$l][$s]['n'] ?? 0) ?></td><?php endforeach; ?>
                    <td class="r <?= $err > 0 ? 'su-err' : 'su-muted' ?>"><?= $fmtNum($err) ?></td></tr>
            <?php endforeach; ?>
        </table>
        <p class="su-muted">Domeny sklepów to zapytania do WooCommerce. Allegro ogranicza liczbę zapytań na minutę — przy tysiącach dziennie warto wydłużyć interwały w <a href="sync_settings.php">Synchronizacji</a>.</p>
        <?php endif; ?>
    </div>
</div>

<div class="su-grid">
    <div class="card">
        <div class="su-head"><strong>Miejsce na dysku</strong><a href="?rescan=1" class="su-muted">przelicz teraz</a></div>
        <table class="su-table">
            <tr><th>Co</th><th class="r">Rozmiar</th><th class="r">Plików</th></tr>
            <?php foreach ($disk['parts'] as $k => [$sz, $n]): ?>
                <tr><td><?= $e($storageLabels[$k] ?? $k) ?></td><td class="r"><?= $fmtBytes((float) $sz) ?></td><td class="r"><?= $fmtNum($n) ?></td></tr>
            <?php endforeach; ?>
            <tr class="tot"><td>Razem pliki</td><td class="r"><?= $fmtBytes($diskTotal) ?></td><td></td></tr>
        </table>
        <p class="su-muted">Policzono <?= $e((new DateTimeImmutable('@' . $disk['t']))->setTimezone($tz)->format('d.m.Y H:i')) ?>. Wynik jest pamiętany 15 minut, żeby nie skanować dysku przy każdym wejściu.</p>
    </div>

    <div class="card">
        <strong>Baza danych</strong>
        <table class="su-table">
            <tr><th>Tabela</th><th class="r">Wierszy (ok.)</th><th class="r">Rozmiar</th></tr>
            <?php foreach (array_slice($tables, 0, 12) as $t): ?>
                <tr><td><code><?= $e($t['name']) ?></code></td><td class="r"><?= $fmtNum($t['rows_est']) ?></td><td class="r"><?= $fmtBytes((float) $t['bytes']) ?></td></tr>
            <?php endforeach; ?>
            <tr class="tot"><td>Razem (<?= count($tables) ?> tabel)</td><td></td><td class="r"><?= $fmtBytes($dbTotal) ?></td></tr>
        </table>
        <p class="su-muted">Liczba wierszy to szacunek MySQL. Najwięcej zwykle zajmują zamówienia (pełne dane z kanałów sprzedaży) i dziennik zmian.</p>
    </div>
</div>

<?php $daUrl = (string) ($settingsRepo->get(HostingQuota::S_URL, '') ?? ''); $daUser = (string) ($settingsRepo->get(HostingQuota::S_USER, '') ?? ''); $daHasKey = $settingsRepo->get(HostingQuota::S_KEY) !== null; ?>
<div class="card" id="quota">
    <div class="su-head"><strong>Miejsce na koncie hostingu</strong><?php if ($quotaService->configured()): ?><a href="?quota_refresh=1#quota" class="su-muted">odśwież teraz</a><?php endif; ?></div>
    <?php if ($quota['source'] === 'directadmin'): ?>
        <table class="su-table">
            <tr><td>Zajęte na koncie (wszystkie strony, poczta<?= $quota['db'] !== null ? '' : ', bazy' ?>)</td><td class="r"><?= $fmtBytes($quota['used']) ?></td></tr>
            <?php if ($quota['db'] !== null): ?><tr><td>w tym / dodatkowo bazy danych (wg panelu)</td><td class="r"><?= $fmtBytes($quota['db']) ?></td></tr><?php endif; ?>
            <tr><td>Limit pakietu</td><td class="r"><?= $quota['limit'] ? $fmtBytes($quota['limit']) : 'bez limitu' ?></td></tr>
            <?php if ($quota['limit']): ?><tr class="tot"><td>Zostało</td><td class="r"><?= $fmtBytes(max(0, $quota['limit'] - $quota['used'])) ?></td></tr><?php endif; ?>
            <tr><td class="su-muted">z tego sam CRM (pliki + baza)</td><td class="r su-muted"><?= $fmtBytes($diskTotal + $dbTotal) ?></td></tr>
        </table>
        <p class="su-muted">Dane z panelu DirectAdmin, odczytane <?= $e((new DateTimeImmutable('@' . $quota['checked_at']))->setTimezone($tz)->format('d.m.Y H:i')) ?> (pamiętane 15 minut).</p>
    <?php else: ?>
        <p class="su-muted">Hosting liczy limit dla całego konta: wszystkie strony, poczta, bazy i kopie — sam CRM to tylko <?= $fmtBytes($diskTotal + $dbTotal) ?>. Wolne miejsce na dysku serwera (np. setki GB) jest wspólne dla wielu klientów hostingu i nie mówi nic o Twoim limicie, dlatego nie jest tu pokazywane.</p>
    <?php endif; ?>
    <?php if ($quota['error'] !== null): ?><div class="flash err" style="margin-top:10px">Odczyt z panelu hostingu nie powiódł się: <?= $e($quota['error']) ?></div><?php endif; ?>
    <details class="su-details" <?= $quota['source'] === null ? 'open' : '' ?>>
        <summary>Ustawienia: połączenie z panelem hostingu lub limit wpisany ręcznie</summary>
        <form method="post" class="su-form">
            <input type="hidden" name="csrf" value="<?= $e(csrfToken()) ?>"><input type="hidden" name="action" value="quota">
            <p class="su-muted"><strong>Automatycznie (zalecane):</strong> w panelu DirectAdmin → <em>Klucze logowania</em> (Login Keys) utwórz klucz, zezwól tylko na polecenia <code>CMD_API_SHOW_USER_USAGE</code> i <code>CMD_API_SHOW_USER_CONFIG</code> i wklej go tutaj. Adres panelu to ten, pod którym się logujesz, np. <code>https://serwer.hostido.net.pl:2222</code>.</p>
            <div class="su-fields">
                <label>Adres panelu DirectAdmin<input name="da_url" value="<?= $e($daUrl) ?>" placeholder="https://…:2222"></label>
                <label>Login do panelu<input name="da_user" value="<?= $e($daUser) ?>" autocomplete="off"></label>
                <label>Klucz logowania<input name="da_key" type="password" autocomplete="new-password" placeholder="<?= $daHasKey ? 'zapisany — zostaw puste, by nie zmieniać' : '' ?>"></label>
            </div>
            <p class="su-muted" style="margin-top:12px"><strong>Ręcznie:</strong> jeśli nie chcesz łączyć z panelem, wpisz limit pakietu — CRM pokaże, ile z niego zajmuje.</p>
            <div class="su-fields"><label>Limit pakietu (GB)<input name="manual_gb" value="<?= $e((string) ($settingsRepo->get(HostingQuota::S_MANUAL_GB, '') ?? '')) ?>" inputmode="decimal" placeholder="np. 20"></label></div>
            <p style="margin-top:12px;display:flex;gap:14px;align-items:center;flex-wrap:wrap">
                <button class="btn" type="submit">Zapisz i sprawdź</button>
                <?php if ($daHasKey): ?><label class="su-muted" style="display:inline-flex;gap:6px;align-items:center"><input type="checkbox" name="da_clear" value="1"> odłącz panel (usuń klucz)</label><?php endif; ?>
            </p>
        </form>
    </details>
</div>

<div class="su-grid">
    <div class="card">
        <strong>Kolejka zadań i cron</strong>
        <table class="su-table">
            <tr><td>Ostatni przebieg crona</td><td class="r <?= $workerAt && $workerAt > time() - 300 ? '' : 'su-err' ?>"><?= $workerAt ? $e((new DateTimeImmutable('@' . $workerAt))->setTimezone($tz)->format('d.m H:i')) : 'nigdy' ?></td></tr>
            <tr><td>Czeka w kolejce</td><td class="r"><?= $fmtNum($queue['pending']) ?></td></tr>
            <tr><td>W trakcie</td><td class="r"><?= $fmtNum($queue['reserved']) ?></td></tr>
            <tr><td>Wykonane w 24 h</td><td class="r"><?= $fmtNum($queue['done24']) ?></td></tr>
            <tr><td>Nieudane w 24 h</td><td class="r <?= $queue['failed24'] ? 'su-err' : '' ?>"><?= $fmtNum($queue['failed24']) ?></td></tr>
        </table>
        <p class="su-muted">Szczegóły w <a href="queue.php">Kolejce</a>, interwały w <a href="sync_settings.php">Synchronizacji</a>.</p>
    </div>

    <div class="card">
        <strong>Serwer</strong>
        <table class="su-table">
            <tr><td>Wersja PHP</td><td class="r"><?= $e(PHP_VERSION) ?></td></tr>
            <tr><td>Limit pamięci PHP</td><td class="r"><?= $e($memLimit) ?></td></tr>
            <tr><td>Limit czasu skryptu</td><td class="r"><?= (int) ini_get('max_execution_time') > 0 ? (int) ini_get('max_execution_time') . ' s' : 'bez limitu' ?></td></tr>
            <tr><td>Obciążenie procesora (1 / 5 / 15 min)</td><td class="r"><?= $load ? $e(implode(' / ', array_map(static fn($x) => number_format((float) $x, 2, ',', ''), $load))) : 'niedostępne na tym hostingu' ?></td></tr>
            <tr><td>Wersja bazy</td><td class="r"><?= $e((string) $pdo->query('SELECT VERSION()')->fetchColumn()) ?></td></tr>
        </table>
        <p class="su-muted">Obciążenie procesora dotyczy całego serwera (na hostingu współdzielonym także innych klientów), nie tylko tego systemu.</p>
    </div>
</div>

<style>
    .su-tiles { display:grid; grid-template-columns:repeat(auto-fill, minmax(190px, 1fr)); gap:12px; margin-bottom:16px; }
    .su-tile { background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); padding:14px 16px; box-shadow:var(--shadow); }
    .su-tile .v { font-family:var(--font-num); font-size:22px; font-weight:600; color:var(--ink); }
    .su-tile .l { font-size:13px; font-weight:600; color:var(--ink); margin-top:2px; }
    .su-tile .s { font-size:12px; color:var(--ink-2); margin-top:2px; }
    a.su-tile-link { display:block; text-decoration:none; }
    a.su-tile-link:hover { border-color:var(--accent); }
    .su-tile-warn { background:var(--warn-bg); border-color:#ecd3a4; }
    .su-tile-warn .v, .su-tile-warn .s { color:var(--warn-ink); }
    .su-tile-bad { background:var(--danger-bg); border-color:#efc3b7; }
    .su-tile-bad .v, .su-tile-bad .s { color:var(--danger-ink); }
    .su-inline { display:inline-flex; gap:6px; align-items:center; margin:0; }
    .su-inline select { padding:6px 8px; font-size:13px; }
    .su-proc td { vertical-align:middle; padding-top:10px; padding-bottom:10px; }
    .su-proc-link { display:flex; gap:10px; align-items:center; margin-top:5px; font-size:12px; }
    .su-proc-link a { color:var(--accent-ink); font-weight:600; text-decoration:none; }
    .su-proc-link a:hover { text-decoration:underline; }
    .su-proc-now { font-family:var(--font-num); background:var(--surface-2); border:1px solid var(--line); border-radius:6px; padding:1px 7px; color:var(--ink-2); }
    .su-proc tr.paused td:first-child { border-left:3px solid var(--warn-ink); padding-left:10px; }
    .su-details { margin-top:12px; }
    .su-details summary { cursor:pointer; font-size:13px; font-weight:600; color:var(--ink-2); }
    .su-form { margin-top:10px; }
    .su-fields { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px; margin-top:8px; }
    .su-fields label { display:flex; flex-direction:column; gap:4px; font-size:12px; color:var(--ink-2); }
    .su-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(420px, 1fr)); gap:16px; }
    .su-grid .card { margin-top:0; }
    .su-muted { color:var(--ink-2); font-size:12px; }
    p.su-muted { margin:6px 0 0; }
    .su-err { color:var(--danger-ink); font-weight:600; }
    .su-head { display:flex; justify-content:space-between; align-items:center; }
    .su-table { width:100%; margin-top:10px; font-size:13px; }
    .su-table .r { text-align:right; white-space:nowrap; font-family:var(--font-num); }
    .su-table th.r { font-family:inherit; }
    .su-table tr.tot td { font-weight:700; border-top:1px solid var(--line); }
    .su-chart { display:flex; align-items:flex-end; gap:2px; height:160px; margin-top:14px; padding-bottom:20px; border-bottom:1px solid var(--line); }
    .su-col { flex:1; height:100%; display:flex; flex-direction:column; justify-content:flex-end; position:relative; cursor:default; }
    .su-col:hover { background:var(--surface-2); }
    .su-bar { background:var(--accent); border-radius:4px 4px 0 0; min-height:0; }
    .su-col:hover .su-bar { background:var(--accent-hover); }
    .su-x { position:absolute; bottom:-18px; left:0; font-size:10px; color:var(--ink-3); white-space:nowrap; }
    .su-axis { font-size:11px; color:var(--ink-3); margin-top:22px; text-align:right; }
    @media (max-width:640px) { .su-grid { grid-template-columns:1fr; } }
</style>

<?php require __DIR__ . '/footer.php'; ?>
