<?php
declare(strict_types=1);

/**
 * Harmonogram czasu wysyłki ofert Allegro: dla każdego dnia tygodnia czas wysyłki, który system
 * sam ustawia wszystkim aktywnym ofertom (np. pn–czw 2 dni, pt–nd 24 godziny). Wykonuje worker (cron).
 */

use Pase\Repository\SettingsRepository;
use Pase\Services\AllegroHandlingSchedule;
use Pase\Services\AllegroOfferOperations;
use PasePlugin\Allegro\AllegroPlugin;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$config   = require PASE_ROOT . '/config/config.php';
$client   = AllegroPlugin::makeClient($pdo, $config['allegro'] ?? []);
$settings = new SettingsRepository($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEdit()) {
    csrfCheck();
    $days = $times = [];
    foreach (AllegroHandlingSchedule::DAYS as $n => $_) {
        $v = (string) ($_POST['day'][$n] ?? '');
        $days[$n] = isset(AllegroOfferOperations::HANDLING_TIMES[$v]) ? $v : '';
        $times[$n] = sprintf('%02d:%02d', min(23, max(0, (int) ($_POST['hour'][$n] ?? 0))), min(59, max(0, (int) ($_POST['minute'][$n] ?? 5))));
    }
    $cfg = ['enabled' => isset($_POST['enabled']), 'time' => $times[1], 'days' => $days, 'times' => $times, 'rate' => trim((string) ($_POST['rate'] ?? ''))];
    $settings->setMany([AllegroHandlingSchedule::CONFIG_KEY => json_encode($cfg)]);
    // Po zmianie harmonogramu dzisiejsze ustawienie liczymy od nowa.
    $st = AllegroHandlingSchedule::state($settings);
    unset($st['done']);
    $settings->setMany([AllegroHandlingSchedule::STATE_KEY => json_encode($st)]);

    if (($_POST['action'] ?? '') === 'run_now') {
        @set_time_limit(300);
        $r = (new AllegroHandlingSchedule($pdo, $client))->tick(true);
        flash('Zapisano. ' . $r['message'], $r['changed'] > 0 || $r['left'] === 0 ? 'ok' : 'err');
    } else {
        flash($cfg['enabled'] ? 'Zapisano harmonogram — system będzie sam zmieniał czas wysyłki.' : 'Zapisano harmonogram (wyłączony).');
    }
    redirectAfterPost();
}

$cfg   = AllegroHandlingSchedule::config($settings);
$state = AllegroHandlingSchedule::state($settings);
$curSched   = AllegroHandlingSchedule::current($cfg);
$nextSched  = AllegroHandlingSchedule::next($cfg);
$rates = $client->shippingRates();
$tz    = new DateTimeZone('Europe/Warsaw');
$e = static fn($v) => htmlspecialchars((string) $v);

$PAGE_TITLE = 'Harmonogram czasu wysyłki';
$PAGE_KEY   = 'allegro';
require __DIR__ . '/header.php';
?>

<div class="card">
    <strong>🗓 Harmonogram czasu wysyłki (Allegro)</strong>
    <p style="color:#888;font-size:13px;margin:6px 0 16px">
        System sam zmienia czas wysyłki ofert według dnia tygodnia — np. od poniedziałku do czwartku 2 dni, w weekend 24 godziny.
        Dla każdego dnia wybierasz czas wysyłki i godzinę, o której ma się zmienić. „Bez zmian" = dalej obowiązuje poprzednia zmiana.
    </p>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <label class="hs-switch"><input type="checkbox" name="enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>> <strong>Harmonogram włączony</strong></label>

        <table class="hs-table">
            <tr><th>Dzień</th><th>Czas wysyłki</th><th>Od godziny</th></tr>
            <?php foreach (AllegroHandlingSchedule::DAYS as $n => $dayName): ?>
                <tr class="<?= $curSched['day'] === $n ? 'hs-today' : '' ?>">
                    <td><?= $e($dayName) ?><?= $curSched['day'] === $n ? ' <span class="pill ok">dziś</span>' : '' ?></td>
                    <td>
                        <select name="day[<?= $n ?>]">
                            <option value="">— bez zmian —</option>
                            <?php foreach (AllegroOfferOperations::HANDLING_TIMES as $hk => $hl): ?>
                                <option value="<?= $hk ?>" <?= $cfg['days'][$n] === $hk ? 'selected' : '' ?>><?= $e($hl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="hs-time">
                        <?php [$dh, $dm] = array_map('intval', explode(':', $cfg['times'][$n])); ?>
                        <select name="hour[<?= $n ?>]"><?php for ($h = 0; $h < 24; $h++): ?><option value="<?= $h ?>" <?= $h === $dh ? 'selected' : '' ?>><?= sprintf('%02d', $h) ?></option><?php endfor; ?></select>
                        :
                        <select name="minute[<?= $n ?>]"><?php foreach ([0, 5, 10, 15, 20, 30, 40, 45, 50] as $m): ?><option value="<?= $m ?>" <?= $m === $dm ? 'selected' : '' ?>><?= sprintf('%02d', $m) ?></option><?php endforeach; ?></select>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <div class="hs-row">
            <div>
                <label>Których ofert dotyczy</label>
                <select name="rate">
                    <option value="">wszystkie aktywne oferty</option>
                    <?php foreach ($rates as $r): $rid = (string) ($r['id'] ?? ''); ?>
                        <option value="<?= $e($rid) ?>" <?= $cfg['rate'] === $rid ? 'selected' : '' ?>>tylko z cennikiem: <?= $e($r['name'] ?? $rid) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <p style="margin-top:18px;display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn" type="submit" name="action" value="save">Zapisz harmonogram</button>
            <button class="btn secondary" type="submit" name="action" value="run_now" onclick="return confirm('Ustawić teraz czas wysyłki z harmonogramu wszystkim ofertom?')">Zapisz i ustaw teraz</button>
        </p>
    </form>
</div>

<div class="card">
    <strong>Stan</strong>
    <table style="margin-top:8px">
        <tr><th style="width:220px">Teraz obowiązuje</th><td><?= $curSched['target'] !== '' ? '<strong>' . $e(AllegroOfferOperations::handlingLabel($curSched['target'])) . '</strong>' : '<span style="color:#888">— (brak ustawień)</span>' ?></td></tr>
        <tr><th>Następna zmiana</th><td><?= $nextSched !== null ? $e(AllegroOfferOperations::handlingLabel($nextSched['target'])) . ' — ' . $e($nextSched['at']->format('d.m.Y H:i')) : '<span style="color:#888">—</span>' ?></td></tr>
        <tr><th>Ostatni przebieg</th><td><?= !empty($state['last_run'])
            ? $e((new DateTime('@' . (int) $state['last_run']))->setTimezone($tz)->format('d.m.Y H:i')) . ' — ustawiono „' . $e(AllegroOfferOperations::handlingLabel($state['last_target'] ?? '')) . '" w ' . (int) ($state['last_changed'] ?? 0) . ' ofertach'
            : '<span style="color:#888">jeszcze nie było</span>' ?></td></tr>
        <?php if (!empty($state['last_error'])): ?><tr><th>Błąd</th><td style="color:#c5221f"><?= $e($state['last_error']) ?></td></tr><?php endif; ?>
        <?php if (!empty($state['last_errors'])): ?><tr><th>Oferty z błędem</th><td style="color:#c5221f;font-size:13px"><?= $e(implode(' · ', $state['last_errors'])) ?></td></tr><?php endif; ?>
    </table>
    <p style="color:#888;font-size:12px;margin-top:10px">Godziny w czasie polskim. Zmiany wykonuje worker (cron co minutę). Przy wielu ofertach zmienia je partiami po 100 na minutę.</p>
</div>

<style>
    .hs-switch { display:flex; gap:8px; align-items:center; font-size:14px; margin-bottom:12px; cursor:pointer; }
    .hs-table { max-width:520px; }
    .hs-table select { width:100%; }
    .hs-today td { background:#f3f8ff; }
    .hs-row { display:grid; grid-template-columns:1fr; gap:18px; margin-top:16px; max-width:640px; }
    .hs-table { max-width:640px; }
    .hs-time { white-space:nowrap; }
    .hs-time select { width:auto !important; min-width:64px; }
    .hs-row label { display:block; font-size:12px; color:#888; margin-bottom:4px; }
    .hs-row select { min-width:80px; }
</style>

<?php require __DIR__ . '/footer.php'; ?>
