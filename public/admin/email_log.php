<?php
declare(strict_types=1);

/**
 * System → Wysłane e-maile: ile i jakich e-maili wysłał system, do kogo, z którego konta,
 * przy którym zamówieniu i czy się udało. Dane zbiera Mailer::send() (Pase\Services\EmailLog)
 * od wdrożenia tej funkcji - wcześniejsze wysyłki nie były nigdzie zapisywane.
 */

use Pase\Services\EmailLog;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
header('Cache-Control: no-store');
/** @var PDO $pdo */

$log  = new EmailLog($pdo);
$zone = new DateTimeZone('Europe/Warsaw');
$utc  = new DateTimeZone('UTC');
$today = new DateTimeImmutable('today', $zone);

// ---------- Okres (dni w czasie polskim, zamieniane na granice UTC) ----------
$periods = [
    'today' => 'Dziś',
    '7d'    => 'Ostatnie 7 dni',
    '30d'   => 'Ostatnie 30 dni',
    'month' => 'Ten miesiąc',
    'prev'  => 'Poprzedni miesiąc',
    '365d'  => 'Ostatni rok',
    'custom'=> 'Własny zakres',
];
$period = (string) ($_GET['period'] ?? '30d');
if (!isset($periods[$period])) {
    $period = '30d';
}
$parseDay = static function (string $v) use ($zone): ?DateTimeImmutable {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, $zone);
    return $d && $d->format('Y-m-d') === $v ? $d : null;
};
[$fromDay, $toDay] = match ($period) {
    'today' => [$today, $today],
    '7d'    => [$today->modify('-6 days'), $today],
    'month' => [$today->modify('first day of this month'), $today],
    'prev'  => [$today->modify('first day of last month'), $today->modify('last day of last month')],
    '365d'  => [$today->modify('-364 days'), $today],
    'custom'=> [$parseDay((string) ($_GET['from'] ?? '')) ?? $today->modify('-29 days'), $parseDay((string) ($_GET['to'] ?? '')) ?? $today],
    default => [$today->modify('-29 days'), $today],
};
if ($fromDay > $toDay) {
    [$fromDay, $toDay] = [$toDay, $fromDay];
}

$filters = [
    'from'     => $fromDay->setTimezone($utc)->format('Y-m-d H:i:s'),
    'to'       => $toDay->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
    'type'     => (string) ($_GET['type'] ?? ''),
    'status'   => (string) ($_GET['status'] ?? ''),
    'order_id' => max(0, (int) ($_GET['order'] ?? 0)),
    'q'        => trim((string) ($_GET['q'] ?? '')),
];
// Podsumowania liczone dla okresu (i zamówienia/szukania), bez filtra rodzaju i statusu -
// żeby tabela rodzajów zawsze pokazywała pełny obraz.
$summaryFilters = array_merge($filters, ['type' => '', 'status' => '']);

$byType     = $log->countsByType($summaryFilters);
$byDay      = $log->countsByDay($summaryFilters);
$byTemplate = $log->countsByTemplate($summaryFilters);
$bySender   = $log->countsBySender($summaryFilters);
$totalSent   = array_sum(array_column($byType, 'sent'));
$totalFailed = array_sum(array_column($byType, 'failed'));

$templateNames = [];
try {
    foreach ((new \Pase\Repository\EmailTemplateRepository($pdo))->all() as $t) {
        $templateNames[(string) $t['tpl_key']] = (string) $t['name'];
    }
} catch (\Throwable) {
}

// ---------- Lista (stronicowana) ----------
$perPage = 50;
$total   = $log->count($filters);
$pages   = max(1, (int) ceil($total / $perPage));
$page    = min(max(1, (int) ($_GET['p'] ?? 1)), $pages);
$rows    = $log->entries($filters, $perPage, ($page - 1) * $perPage);

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$local = static fn(string $utcTime): string => (new DateTimeImmutable($utcTime, $utc))->setTimezone($zone)->format('d.m.Y H:i');
$typeLabel = static fn(string $t): string => EmailLog::TYPES[$t] ?? $t;
$sourceLabels = ['panel' => 'Panel', 'panel_bg' => 'Panel', 'cron' => 'Automat (cron)', 'klient' => 'Strona klienta', 'webhook' => 'Webhook'];
$qs = array_filter([
    'period' => $period,
    'from'   => $period === 'custom' ? $fromDay->format('Y-m-d') : null,
    'to'     => $period === 'custom' ? $toDay->format('Y-m-d') : null,
    'type'   => $filters['type'] !== '' ? $filters['type'] : null,
    'status' => $filters['status'] !== '' ? $filters['status'] : null,
    'order'  => $filters['order_id'] ?: null,
    'q'      => $filters['q'] !== '' ? $filters['q'] : null,
], static fn($v): bool => $v !== null);
$link = static fn(array $over): string => 'email_log.php?' . http_build_query(array_filter(array_merge($qs, $over), static fn($v): bool => $v !== null && $v !== ''));
$fmtDay = static fn(string $ymd): string => (new DateTimeImmutable($ymd, $zone))->format('d.m.Y');

$PAGE_TITLE = 'Wysłane e-maile';
$PAGE_KEY   = 'email_log';
require __DIR__ . '/header.php';
?>
<style>
    .el-filters { display:flex; flex-wrap:wrap; gap:10px; align-items:end; }
    .el-filters label { display:block; font-size:12px; color:var(--ink-2); margin-bottom:4px; }
    .el-cols { display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:18px; }
    .el-num { font-family:var(--font-num); font-variant-numeric:tabular-nums; text-align:right; }
    .el-muted { color:var(--ink-2); font-size:13px; }
    .el-err { color:var(--danger-ink); font-size:12px; }
    .el-table-wrap { overflow-x:auto; }
    .el-pager { display:flex; gap:8px; align-items:center; margin-top:12px; }
</style>

<div class="card">
    <form method="get" class="el-filters">
        <div><label>Okres</label>
            <select name="period" onchange="document.getElementById('el-custom').style.display = this.value === 'custom' ? 'flex' : 'none'">
                <?php foreach ($periods as $k => $lbl): ?>
                    <option value="<?= $k ?>" <?= $period === $k ? 'selected' : '' ?>><?= $e($lbl) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div id="el-custom" style="display:<?= $period === 'custom' ? 'flex' : 'none' ?>;gap:10px">
            <div><label>Od</label><input type="date" name="from" value="<?= $e($fromDay->format('Y-m-d')) ?>"></div>
            <div><label>Do</label><input type="date" name="to" value="<?= $e($toDay->format('Y-m-d')) ?>"></div>
        </div>
        <div><label>Rodzaj</label>
            <select name="type">
                <option value="">— wszystkie —</option>
                <?php foreach (EmailLog::TYPES as $k => $lbl): ?>
                    <option value="<?= $k ?>" <?= $filters['type'] === $k ? 'selected' : '' ?>><?= $e($lbl) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div><label>Status</label>
            <select name="status">
                <option value="">— wszystkie —</option>
                <option value="sent" <?= $filters['status'] === 'sent' ? 'selected' : '' ?>>Wysłane</option>
                <option value="failed" <?= $filters['status'] === 'failed' ? 'selected' : '' ?>>Nieudane</option>
            </select></div>
        <div><label>Zamówienie (ID)</label><input name="order" value="<?= $filters['order_id'] ?: '' ?>" size="10" inputmode="numeric"></div>
        <div><label>Odbiorca / temat / szablon</label><input name="q" value="<?= $e($filters['q']) ?>" placeholder="np. jan@ lub wysłane"></div>
        <div><button class="btn" type="submit">Pokaż</button> <a class="btn secondary" href="email_log.php">Wyczyść</a></div>
    </form>
    <p class="el-muted" style="margin:12px 0 0">
        Okres: <?= $e($fmtDay($fromDay->format('Y-m-d'))) ?> – <?= $e($fmtDay($toDay->format('Y-m-d'))) ?> (czas polski).
        Zapisywane są wszystkie e-maile wysyłane przez system od wdrożenia tej funkcji; treści wiadomości nie są przechowywane,
        wpisy starsze niż <?= EmailLog::KEEP_DAYS ?> dni są usuwane.
    </p>
</div>

<div class="grid" style="margin-bottom:18px">
    <div class="stat"><div class="num"><?= $totalSent + $totalFailed ?></div><div class="lbl">Wszystkie próby wysyłki</div></div>
    <div class="stat"><div class="num" style="color:var(--success-ink)"><?= $totalSent ?></div><div class="lbl">Wysłane</div></div>
    <div class="stat"><div class="num" style="color:<?= $totalFailed ? 'var(--danger-ink)' : 'inherit' ?>"><?= $totalFailed ?></div><div class="lbl">Nieudane</div></div>
</div>

<div class="el-cols">
    <div class="card">
        <strong>Według rodzaju</strong>
        <table style="margin-top:8px">
            <tr><th>Rodzaj</th><th class="el-num">Wysłane</th><th class="el-num">Nieudane</th></tr>
            <?php if ($byType === []): ?><tr><td colspan="3" class="el-muted">Brak e-maili w tym okresie.</td></tr><?php endif; ?>
            <?php foreach (EmailLog::TYPES as $k => $lbl): if (!isset($byType[$k])) { continue; } ?>
                <tr>
                    <td><a href="<?= $e($link(['type' => $k, 'p' => null])) ?>"><?= $e($lbl) ?></a></td>
                    <td class="el-num"><?= $byType[$k]['sent'] ?></td>
                    <td class="el-num"><?= $byType[$k]['failed'] ? '<a href="' . $e($link(['type' => $k, 'status' => 'failed', 'p' => null])) . '" style="color:var(--danger-ink)">' . $byType[$k]['failed'] . '</a>' : '0' ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="card">
        <strong>Według szablonu (automatyzacje)</strong>
        <table style="margin-top:8px">
            <tr><th>Szablon</th><th class="el-num">Wysłane</th><th class="el-num">Nieudane</th></tr>
            <?php if ($byTemplate === []): ?><tr><td colspan="3" class="el-muted">Brak e-maili z szablonów w tym okresie.</td></tr><?php endif; ?>
            <?php foreach ($byTemplate as $r): ?>
                <tr>
                    <td><a href="<?= $e($link(['q' => $r['template_key'], 'p' => null])) ?>"><?= $e($templateNames[$r['template_key']] ?? $r['template_key']) ?></a>
                        <span class="el-muted"><?= $e($r['template_key']) ?></span></td>
                    <td class="el-num"><?= $r['sent'] ?></td>
                    <td class="el-num"><?= $r['failed'] ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="card">
        <strong>Według konta nadawcy</strong>
        <table style="margin-top:8px">
            <tr><th>Nadawca</th><th class="el-num">Wysłane</th><th class="el-num">Nieudane</th></tr>
            <?php if ($bySender === []): ?><tr><td colspan="3" class="el-muted">Brak e-maili w tym okresie.</td></tr><?php endif; ?>
            <?php foreach ($bySender as $r): ?>
                <tr>
                    <td><?= $r['sender'] !== '' ? $e($r['sender']) : '<span class="el-muted">(brak konfiguracji SMTP)</span>' ?></td>
                    <td class="el-num"><?= $r['sent'] ?></td>
                    <td class="el-num"><?= $r['failed'] ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="card">
        <strong>Dzień po dniu</strong>
        <div style="max-height:320px;overflow-y:auto;margin-top:8px">
        <table>
            <tr><th>Dzień</th><th class="el-num">Wysłane</th><th class="el-num">Nieudane</th></tr>
            <?php if ($byDay === []): ?><tr><td colspan="3" class="el-muted">Brak e-maili w tym okresie.</td></tr><?php endif; ?>
            <?php foreach ($byDay as $day => $c): ?>
                <tr>
                    <td><a href="<?= $e($link(['period' => 'custom', 'from' => $day, 'to' => $day, 'p' => null])) ?>"><?= $e($fmtDay($day)) ?></a></td>
                    <td class="el-num"><?= $c['sent'] ?></td>
                    <td class="el-num"><?= $c['failed'] ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
    </div>
</div>

<div class="card">
    <strong>Lista e-maili</strong> <span class="el-muted">(<?= $total ?>)</span>
    <div class="el-table-wrap">
    <table style="margin-top:8px">
        <tr><th>Czas</th><th>Rodzaj</th><th>Odbiorca</th><th>Temat</th><th>Zamówienie</th><th>Nadawca</th><th>Wywołał</th><th>Status</th></tr>
        <?php if ($rows === []): ?><tr><td colspan="8" class="el-muted">Brak e-maili dla wybranych filtrów.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td style="white-space:nowrap"><?= $e($local((string) $r['created_at'])) ?></td>
                <td><?= $e($typeLabel((string) $r['type'])) ?>
                    <?php if ($r['template_key']): ?><div class="el-muted"><?= $e($templateNames[$r['template_key']] ?? $r['template_key']) ?></div><?php endif; ?></td>
                <td style="overflow-wrap:anywhere"><?= $e($r['recipient']) ?></td>
                <td style="overflow-wrap:anywhere"><?= $e($r['subject']) ?></td>
                <td><?= $r['woo_order_id'] ? '<a href="order_view.php?id=' . (int) $r['woo_order_id'] . '">#' . (int) $r['woo_order_id'] . '</a>' : '—' ?></td>
                <td class="el-muted" style="overflow-wrap:anywhere"><?= $e($r['sender'] ?? '') ?></td>
                <td class="el-muted"><?= $e($r['actor_name'] ?: ($sourceLabels[$r['source'] ?? ''] ?? ($r['source'] ?? ''))) ?></td>
                <td><?php if ($r['status'] === 'sent'): ?><span class="pill ok">Wysłany</span>
                    <?php else: ?><span class="pill bad">Nieudany</span><?php if ($r['error']): ?><div class="el-err"><?= $e($r['error']) ?></div><?php endif; ?><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php if ($pages > 1): ?>
        <div class="el-pager">
            <?php if ($page > 1): ?><a class="btn secondary" href="<?= $e($link(['p' => $page - 1])) ?>">‹ Nowsze</a><?php endif; ?>
            <span class="el-muted">Strona <?= $page ?> z <?= $pages ?></span>
            <?php if ($page < $pages): ?><a class="btn secondary" href="<?= $e($link(['p' => $page + 1])) ?>">Starsze ›</a><?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
