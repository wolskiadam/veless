<?php
declare(strict_types=1);

/**
 * System → Aktualizacja. Wgranie paczki veless-<wersja>.zip (tej samej, którą instaluje kreator),
 * podgląd zmian, aktualizacja z kopią zmienianych plików i cofnięcie ostatnich aktualizacji.
 * Logika: Pase\Services\AppUpdater. Tylko administrator.
 */

use Pase\Services\AppUpdater;
use Pase\Services\UpdateChecker;

require __DIR__ . '/auth.php';
requireRole(['admin']);
/** @var PDO $pdo */

$updater = new AppUpdater($pdo, PASE_ROOT);
$blocked = $updater->blockedReason();
$checker = new UpdateChecker($updater, new \Pase\Repository\SettingsRepository($pdo), PASE_ROOT);
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$tz = new DateTimeZone('Europe/Warsaw');
$fmtDate = static function (string $iso) use ($tz): string {
    try { return (new DateTimeImmutable($iso))->setTimezone($tz)->format('d.m.Y H:i'); } catch (\Throwable) { return '—'; }
};
$fmtBytes = static fn(int $b): string => $b >= 1048576 ? number_format($b / 1048576, 1, ',', ' ') . ' MB' : number_format(max(1, (int) round($b / 1024)), 0, ',', ' ') . ' KB';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrfCheck();
    @set_time_limit(300);
    ignore_user_abort(true);
    $action = (string) ($_POST['action'] ?? '');
    $file = (string) ($_POST['file'] ?? '');
    $back = 'update.php';
    try {
        if ($blocked !== null && $action !== 'delete') { throw new \RuntimeException($blocked); }
        switch ($action) {
            case 'upload':
                $up = $_FILES['package'] ?? null;
                if (!is_array($up) || ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $code = is_array($up) ? (int) ($up['error'] ?? 0) : UPLOAD_ERR_NO_FILE;
                    throw new \RuntimeException(in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                        ? 'Plik jest większy niż limit wgrywania na tym serwerze (' . (ini_get('upload_max_filesize') ?: '?') . '). Wgraj paczkę przez FTP do katalogu storage/updates/.'
                        : 'Wybierz plik veless-<wersja>.zip.');
                }
                $name = $updater->importUpload((string) $up['tmp_name']);
                flash('Wgrano paczkę ' . $name . '. Sprawdź zmiany poniżej i kliknij „Aktualizuj”.');
                $back = 'update.php?file=' . rawurlencode($name);
                break;
            case 'apply':
                if (empty($_POST['confirm'])) { throw new \RuntimeException('Zaznacz potwierdzenie kopii zapasowej.'); }
                $r = $updater->apply($updater->path($file));
                flash('Zaktualizowano Veless' . ($r['from'] ? ' z wersji ' . $r['from'] : '') . ' do ' . $r['to']
                    . ' (zmienione pliki: ' . $r['changed'] . ', nowe: ' . $r['added'] . ', usunięte: ' . $r['removed'] . '). Baza została dostosowana do nowej wersji.');
                \Pase\Support\Logger::info('Aktualizację uruchomił ' . currentUserName());
                break;
            case 'check_now':
                $info = $checker->check();
                flash($info === null ? 'Serwer aktualizacji nie ma teraz żadnej wersji dla tej instalacji.'
                    : ($checker->isNewer($info['version']) ? 'Dostępna jest wersja ' . $info['version'] . ' - paczka pobrana i sprawdzona.' : 'Masz najnowszą wersję (' . $info['version'] . ').'));
                break;
            case 'install_latest':
                if (empty($_POST['confirm'])) { throw new \RuntimeException('Zaznacz potwierdzenie kopii zapasowej.'); }
                $r = $checker->install();
                flash('Zaktualizowano Veless' . ($r['from'] ? ' z wersji ' . $r['from'] : '') . ' do ' . $r['to'] . '. Baza została dostosowana do nowej wersji.');
                \Pase\Support\Logger::info('Aktualizację z serwera uruchomił ' . currentUserName());
                break;
            case 'auto_settings':
                $mode = (string) ($_POST['mode'] ?? 'patch');
                $url = trim((string) ($_POST['server'] ?? ''));
                if (!isset(UpdateChecker::MODES[$mode])) { throw new \RuntimeException('Nieznany tryb.'); }
                if ($url !== '' && !preg_match('#^https://[^\s"<>]+$#', $url)) { throw new \RuntimeException('Adres serwera musi zaczynać się od https://'); }
                (new \Pase\Repository\SettingsRepository($pdo))->setMany(['UPDATE_AUTO' => $mode, 'UPDATE_SERVER_URL' => $url, 'UPDATE_AUTO_FAILED' => '']);
                flash('Zapisano ustawienia aktualizacji.');
                break;
            case 'delete':
                $updater->delete($file);
                flash('Usunięto paczkę z serwera.');
                break;
            case 'rollback':
                if (empty($_POST['confirm'])) { throw new \RuntimeException('Zaznacz potwierdzenie cofnięcia.'); }
                $meta = $updater->rollback($file);
                flash('Przywrócono pliki sprzed aktualizacji do wersji ' . ($meta['to'] ?? '?') . '. Działa teraz wersja ' . ($meta['from'] ?: 'poprzednia') . '.');
                break;
            default:
                throw new \RuntimeException('Nieznana akcja.');
        }
    } catch (\Throwable $ex) {
        flash($ex->getMessage(), 'err');
        if ($file !== '' && $action === 'apply') { $back = 'update.php?file=' . rawurlencode($file); }
    }
    // Przekierowanie = nowe żądanie z nowym kodem, które przy starcie uruchamia migracje bazy.
    header('Location: ' . $back, true, 303);
    exit;
}

$installed = $updater->installedVersion();
$label = '';
try { $label = \Pase\Support\AppVersion::current()['version'] ?? ''; } catch (\Throwable) {}
$incoming = $blocked === null ? $updater->incoming() : [];
$backups = $updater->backups();
$status = $checker->status();
$selected = null; $plan = null; $planError = '';
if ($blocked === null && isset($_GET['file'])) {
    try { $selected = (string) $_GET['file']; $plan = $updater->inspect($updater->path($selected)); }
    catch (\Throwable $ex) { $planError = $ex->getMessage(); $selected = null; }
}

$PAGE_TITLE = 'Aktualizacja';
$PAGE_KEY   = 'update';
require __DIR__ . '/header.php';
?>
<style>
    .up-hint { font-size:13px; color:var(--ink-2); margin:8px 0 0; }
    .up-ver { font-family:var(--font-num); font-size:22px; font-weight:600; margin:4px 0 0; }
    .up-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin:12px 0; }
    .up-grid > div { border:1px solid var(--line); border-radius:var(--radius-sm); padding:10px 12px; background:var(--surface-2); }
    .up-grid small { display:block; font-size:12px; color:var(--ink-3); }
    .up-grid strong { font-family:var(--font-num); font-size:18px; }
    .up-warn { background:var(--warn-bg); color:var(--warn-ink); border-radius:var(--radius-sm); padding:12px 14px; font-size:13px; margin:12px 0; }
    .up-files { max-height:220px; overflow:auto; font-family:var(--font-num); font-size:12px; color:var(--ink-2); margin:6px 0 0; padding-left:18px; }
    .up-check { display:flex; gap:8px; align-items:flex-start; font-size:14px; margin-top:12px; cursor:pointer; }
    .up-row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:12px; }
    .up-table td { vertical-align:middle; }
    .up-inline { display:inline; margin:0; }
</style>

<div class="card">
    <strong>Zainstalowana wersja</strong>
    <p class="up-ver"><?= $e($installed ?? ($label !== '' ? $label : 'nieznana')) ?></p>
    <?php if ($blocked !== null): ?>
        <div class="up-warn"><?= $e($blocked) ?></div>
    <?php else: ?>
        <p class="up-hint">Nową wersję pobierasz jako plik <code>veless-&lt;wersja&gt;.zip</code> (ten sam, który wgrywa się przy instalacji).
            Aktualizacja podmienia tylko pliki programu. Twoje dane, ustawienia, plik .env i wgrane pliki zostają bez zmian.</p>
    <?php endif; ?>
</div>

<?php if ($blocked === null): ?>
    <?php $latest = $status['latest']; $newer = $latest && $checker->isNewer((string) $latest['version']) && ($latest['file'] ?? '') !== ''; ?>
    <?php if ($newer): ?>
        <div class="card">
            <strong>Dostępna nowa wersja: <?= $e($latest['version']) ?></strong>
            <?php if (($latest['notes'] ?? '') !== ''): ?><p class="up-hint" style="white-space:pre-line"><?= $e($latest['notes']) ?></p><?php endif; ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= $e(csrfToken()) ?>">
                <input type="hidden" name="action" value="install_latest">
                <label class="up-check"><input type="checkbox" name="confirm" value="1" required>
                    <span>Mam aktualną kopię zapasową. Przed podmianą system zapisze kopię zmienianych plików, żeby można było cofnąć aktualizację.</span></label>
                <p class="up-row"><button class="btn" type="submit">Zainstaluj <?= $e($latest['version']) ?> teraz</button>
                    <?php if ($status['mode'] !== 'off' && $checker->autoAllowed((string) $latest['version'], (bool) ($latest['auto'] ?? true), (new DateTimeImmutable('today', $tz))->setTime($checker->hours()[0], 30)->getTimestamp())): ?>
                        <span class="up-hint" style="margin:0">albo poczekaj - zainstaluje się sama w nocy (<?= $e(implode('-', $checker->hours())) ?>).</span>
                    <?php endif; ?></p>
            </form>
        </div>
    <?php endif; ?>

    <div class="card">
        <strong>Aktualizacje z serwera Veless</strong>
        <?php if ($status['server'] === ''): ?>
            <p class="up-hint">Nie ustawiono adresu serwera aktualizacji. Wpisz go poniżej (dostaniesz go razem z subskrypcją).</p>
        <?php else: ?>
            <p class="up-hint">Raz dziennie system sprawdza, czy jest nowa wersja.
                Ostatnio: <?= $status['checked_at'] ? $e((new DateTimeImmutable('@' . $status['checked_at']))->setTimezone($tz)->format('d.m.Y H:i')) : 'jeszcze nie' ?>.
                <?php if (!$newer && $latest): ?>Masz najnowszą wersję.<?php endif; ?></p>
            <?php if ($status['error'] !== ''): ?><div class="up-warn"><?= $e($status['error']) ?></div><?php endif; ?>
            <?php $ar = $status['auto_result']; if ($ar): ?>
                <p class="up-hint"><?= $ar['ok'] ? 'Ostatnia automatyczna aktualizacja: ' . $e(($ar['from'] ?? '?') . ' → ' . $ar['to']) . ', ' . $e((new DateTimeImmutable('@' . (int) $ar['at']))->setTimezone($tz)->format('d.m.Y H:i')) . '.'
                    : 'Automatyczna instalacja ' . $e($ar['to']) . ' nie udała się: ' . $e($ar['error'] ?? '') . ' Spróbuj przyciskiem powyżej.' ?></p>
            <?php endif; ?>
        <?php endif; ?>
        <form method="post" class="up-row" style="align-items:flex-end">
            <input type="hidden" name="csrf" value="<?= $e(csrfToken()) ?>">
            <input type="hidden" name="action" value="auto_settings">
            <div><label class="up-hint" style="display:block;margin:0 0 4px">Instaluj samo w nocy</label>
                <select name="mode"><?php foreach (UpdateChecker::MODES as $k => $l): ?><option value="<?= $e($k) ?>"<?= $status['mode'] === $k ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></div>
            <div style="flex:1;min-width:220px"><label class="up-hint" style="display:block;margin:0 0 4px">Adres serwera aktualizacji</label>
                <input name="server" value="<?= $e((string) (new \Pase\Repository\SettingsRepository($pdo))->get('UPDATE_SERVER_URL', '')) ?>" placeholder="<?= $e($status['server'] !== '' ? $status['server'] : 'https://…') ?>" style="width:100%"></div>
            <button class="btn secondary" type="submit">Zapisz</button>
        </form>
        <?php if ($status['server'] !== ''): ?>
            <form method="post" class="up-row">
                <input type="hidden" name="csrf" value="<?= $e(csrfToken()) ?>">
                <input type="hidden" name="action" value="check_now">
                <button class="btn secondary" type="submit">Sprawdź teraz</button>
                <span class="up-hint" style="margin:0">Serwer dostaje: wersję systemu, adres panelu, wersję PHP i losowy identyfikator instalacji. Żadnych danych zamówień ani klientów.</span>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($planError !== ''): ?><div class="flash err"><?= $e($planError) ?></div><?php endif; ?>

    <?php if ($plan !== null): ?>
        <div class="card">
            <strong>Paczka <?= $e($selected) ?></strong>
            <p class="up-hint">Wersja <?= $e($plan['version']) ?><?= $plan['created_at'] !== '' ? ', zbudowana ' . $e($fmtDate($plan['created_at'])) : '' ?>.
                <?= ['ok' => '<strong style="color:var(--ok-ink,#1d7a43)">Podpisana przez Veless.</strong>', 'none' => '<strong>Paczka bez podpisu</strong> - instaluj ją tylko, jeśli wiesz, skąd pochodzi.', 'unknown' => 'Podpisu nie da się sprawdzić (brak rozszerzenia PHP sodium).'][$plan['signature']] ?? '' ?></p>
            <div class="up-grid">
                <div><small>Zmienione pliki</small><strong><?= count($plan['changed']) ?></strong></div>
                <div><small>Nowe pliki</small><strong><?= count($plan['added']) ?></strong></div>
                <div><small>Do usunięcia</small><strong><?= count($plan['removed']) ?></strong></div>
            </div>
            <?php if ($plan['downgrade']): ?>
                <div class="up-warn">To starsza wersja niż zainstalowana (<?= $e($plan['installed']) ?>). Cofnięcie wersji z paczki nie jest możliwe - użyj przycisku „Cofnij” przy kopii poniżej.</div>
            <?php elseif ($plan['unwritable']): ?>
                <div class="up-warn">Brak prawa zapisu do <?= count($plan['unwritable']) ?> plików (np. <?= $e(implode(', ', array_slice($plan['unwritable'], 0, 3))) ?>). Popraw uprawnienia w menedżerze plików hostingu i odśwież stronę.</div>
            <?php else: ?>
                <?php if ($plan['same']): ?><div class="up-warn">Ta wersja jest już zainstalowana. Aktualizacja przywróci tylko pliki programu zmienione na serwerze.</div><?php endif; ?>
                <?php if ($plan['changed'] || $plan['added'] || $plan['removed']): ?>
                    <details><summary class="up-hint">Lista plików</summary><ul class="up-files">
                        <?php foreach (array_slice(array_merge($plan['changed'], $plan['added']), 0, 400) as $f): ?><li><?= $e($f) ?></li><?php endforeach; ?>
                        <?php foreach ($plan['removed'] as $f): ?><li>usunięty: <?= $e($f) ?></li><?php endforeach; ?>
                    </ul></details>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= $e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="apply">
                    <input type="hidden" name="file" value="<?= $e($selected) ?>">
                    <label class="up-check"><input type="checkbox" name="confirm" value="1" required>
                        <span>Mam aktualną kopię zapasową (<a href="backup.php">System → Kopia zapasowa</a>). Przed podmianą system i tak zapisze kopię zmienianych plików programu, żeby można było cofnąć aktualizację.</span></label>
                    <p class="up-row"><button class="btn" type="submit">Aktualizuj do <?= $e($plan['version']) ?></button>
                        <span class="up-hint" style="margin:0">Na czas aktualizacji (zwykle kilka sekund) zadania w tle są wstrzymane.</span></p>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <strong>Wgraj nową wersję</strong>
        <form method="post" enctype="multipart/form-data" class="up-row">
            <input type="hidden" name="csrf" value="<?= $e(csrfToken()) ?>">
            <input type="hidden" name="action" value="upload">
            <input type="file" name="package" accept=".zip,application/zip" required>
            <button class="btn secondary" type="submit">Wgraj i sprawdź</button>
        </form>
        <p class="up-hint">Limit wgrywania na tym serwerze: <?= $e(ini_get('upload_max_filesize') ?: '?') ?>. Przy większej paczce wgraj ją przez FTP do katalogu
            <code>storage/updates/</code> i odśwież tę stronę.</p>
        <?php if ($incoming): ?>
            <table class="up-table" style="margin-top:12px"><tbody>
            <?php foreach ($incoming as $pkg): ?>
                <tr>
                    <td><strong><?= $e($pkg['file']) ?></strong> <span class="up-hint"><?= $e($fmtBytes($pkg['size'])) ?></span></td>
                    <td style="text-align:right;white-space:nowrap">
                        <a class="btn secondary" href="update.php?file=<?= $e(rawurlencode($pkg['file'])) ?>">Sprawdź</a>
                        <form method="post" class="up-inline">
                            <input type="hidden" name="csrf" value="<?= $e(csrfToken()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="file" value="<?= $e($pkg['file']) ?>">
                            <button class="btn secondary" type="submit">Usuń</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($backups): ?>
    <div class="card">
        <strong>Cofnięcie aktualizacji</strong>
        <p class="up-hint">Kopie plików programu sprzed ostatnich aktualizacji (trzy najnowsze). Cofnięcie przywraca pliki, nie zmienia danych w bazie.</p>
        <table class="up-table" style="margin-top:8px"><tbody>
        <?php foreach ($backups as $i => $b): ?>
            <tr>
                <td><?= $e($fmtDate($b['created_at'])) ?></td>
                <td><?= $e(($b['from'] ?: '?') . ' → ' . $b['to']) ?></td>
                <td style="text-align:right">
                    <?php if ($i === 0 && $blocked === null): ?>
                        <form method="post" class="up-inline">
                            <input type="hidden" name="csrf" value="<?= $e(csrfToken()) ?>">
                            <input type="hidden" name="action" value="rollback">
                            <input type="hidden" name="file" value="<?= $e($b['file']) ?>">
                            <label class="up-check" style="display:inline-flex;margin:0 8px 0 0"><input type="checkbox" name="confirm" value="1" required> wróć do <?= $e($b['from'] ?: 'poprzedniej') ?></label>
                            <button class="btn secondary" type="submit">Cofnij</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
