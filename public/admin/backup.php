<?php
declare(strict_types=1);

/**
 * System → Kopia zapasowa. Osobne kopie części systemu (patrz Pase\Services\SectionBackup):
 * ustawienia, dane zamówień, katalog produktów. Tworzenie, pobieranie, wgrywanie,
 * sprawdzanie i przywracanie. Tylko administrator.
 *
 * Pełna kopia systemu do przeniesienia na inny serwer (Pase\Services\FullSystemPackage): ZIP z instalatorem.
 * Szyfrowana kopia serwera z kluczem w pliku to osobne narzędzie CLI - BACKUPS.md.
 */

use Pase\Services\FullSystemPackage;
use Pase\Services\SectionBackup;

require __DIR__ . '/auth.php';
requireRole(['admin']);
/** @var PDO $pdo */

$appVersion = '';
try { $appVersion = \Pase\Support\AppVersion::current()['label'] ?? ''; } catch (\Throwable) {}
$backup = new SectionBackup($pdo, PASE_ROOT . '/storage', $appVersion);
$full = new FullSystemPackage($pdo, PASE_ROOT, $appVersion);
try { $full->prune(); } catch (\Throwable) {}

$e  = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$tz = new DateTimeZone('Europe/Warsaw');
$fmtDate = static function (string $iso) use ($tz): string {
    try { return (new DateTimeImmutable($iso))->setTimezone($tz)->format('d.m.Y H:i'); } catch (\Throwable) { return '—'; }
};
$fmtBytes = static function (int $b): string {
    $u = ['B', 'KB', 'MB', 'GB']; $i = 0; $v = (float) $b;
    while ($v >= 1024 && $i < 3) { $v /= 1024; $i++; }
    return number_format($v, $i === 0 ? 0 : 1, ',', ' ') . ' ' . $u[$i];
};
$partLabel = static fn(string $p): string => SectionBackup::PARTS[$p]['label'] ?? 'Nieznana';
$fmtPeriod = static function (?array $p): string {
    if (!$p) { return ''; }
    $d = static fn(string $v): string => implode('.', array_reverse(explode('-', $v)));
    return $p['from'] === '2000-01-01' ? 'do ' . $d($p['to']) : $d($p['from']) . ' – ' . $d($p['to']);
};

// ---------------------------------------------------------------- pobieranie (GET)
if (isset($_GET['download'])) {
    try {
        $path = $backup->path((string) $_GET['download']);
    } catch (\Throwable $ex) {
        http_response_code(404);
        exit('Nie ma takiej kopii.');
    }
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

// Instrukcja przeniesienia (bez sekretów) - ta sama, która trafia do ZIP-a pełnej kopii.
$fullBaseUrl = static function () use ($pdo): string {
    $base = (string) ((new \Pase\Repository\SettingsRepository($pdo))->get('APP_BASE_URL', '') ?? '');
    if ($base === '') {
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . dirname(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/admin/x')));
    }
    return $base;
};
if (isset($_GET['download_guide'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="PRZENIESIENIE-' . date('Ymd') . '.txt"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo $full->guide($fullBaseUrl());
    exit;
}

// Pełna kopia: po pobraniu w całości plik jest usuwany z serwera (zawiera wszystkie sekrety i dane klientów).
if (isset($_GET['download_full'])) {
    try {
        $path = $full->path((string) $_GET['download_full']);
    } catch (\Throwable $ex) {
        http_response_code(404);
        exit('Nie ma takiej kopii - pełna kopia jest usuwana z serwera po pobraniu.');
    }
    @set_time_limit(0);
    ignore_user_abort(true);
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    $sent = readfile($path);
    flush();
    if ($sent === filesize($path) && !connection_aborted()) {
        @unlink($path);
        \Pase\Support\Logger::info('Pełna kopia systemu pobrana i usunięta z serwera przez ' . currentUserName() . ': ' . basename($path));
    }
    exit;
}

// ---------------------------------------------------------------- akcje (POST → redirect)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrfCheck();
    @set_time_limit(0);
    $action = (string) ($_POST['action'] ?? '');
    $file   = (string) ($_POST['file'] ?? '');
    $back   = null;
    try {
        switch ($action) {
            case 'create':
                $part = (string) ($_POST['part'] ?? '');
                $pass = (string) ($_POST['password'] ?? '');
                if ($pass !== (string) ($_POST['password2'] ?? '')) { throw new \RuntimeException('Hasła do kopii nie są takie same.'); }
                // Okres (tylko zamówienia): puste oba pola = wszystkie zamówienia.
                $from = trim((string) ($_POST['period_from'] ?? ''));
                $to   = trim((string) ($_POST['period_to'] ?? ''));
                $period = null;
                if ($part === 'orders' && ($from !== '' || $to !== '')) {
                    $period = ['from' => $from !== '' ? $from : '2000-01-01', 'to' => $to !== '' ? $to : (new DateTimeImmutable('now', $tz))->format('Y-m-d')];
                }
                $name = $backup->export($part, $pass, (string) ($_POST['note'] ?? ''), $period);
                flash('Utworzono kopię: ' . $partLabel($part) . ($period ? ' (' . $fmtPeriod($period) . ')' : '') . '. Zapisz hasło w bezpiecznym miejscu - bez niego kopii nie da się odtworzyć.');
                if (!empty($_POST['download_now'])) { $_SESSION['backup_autodownload'] = $name; }
                \Pase\Support\Logger::info('Kopia zapasowa (' . $part . ') utworzona przez ' . currentUserName() . ': ' . $name);
                break;

            case 'full_create':
                $pass = (string) ($_POST['password'] ?? '');
                if ($pass !== (string) ($_POST['password2'] ?? '')) { throw new \RuntimeException('Hasła do kopii nie są takie same.'); }
                ignore_user_abort(true);
                $name = $full->build($pass, $fullBaseUrl());
                flash('Utworzono pełną kopię systemu - pobieranie powinno zacząć się samo. Po pobraniu kopia znika z serwera (nieodebrana - po 24 godzinach).');
                $_SESSION['backup_autodownload_full'] = $name;
                \Pase\Support\Logger::info('Pełna kopia systemu utworzona przez ' . currentUserName() . ': ' . $name);
                break;

            case 'full_delete':
                $full->delete($file);
                flash('Usunięto pełną kopię z serwera.');
                break;

            case 'upload':
                $up = $_FILES['archive'] ?? null;
                if (!is_array($up) || ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new \RuntimeException(($up['error'] ?? 0) === UPLOAD_ERR_INI_SIZE
                        ? 'Plik jest większy niż pozwala serwer (upload_max_filesize).' : 'Wybierz plik kopii (.zip).');
                }
                $name = $backup->importUpload((string) $up['tmp_name']);
                flash('Wgrano kopię. Podaj hasło, żeby sprawdzić jej zawartość lub ją przywrócić.');
                $back = 'backup.php?file=' . rawurlencode($name);
                break;

            case 'inspect':
                $manifest = $backup->verify($backup->path($file), (string) ($_POST['password'] ?? ''));
                $_SESSION['backup_inspect'][$file] = [
                    'part' => $manifest['part'], 'created_at' => $manifest['created_at'],
                    'app_version' => $manifest['app_version'] ?? '', 'note' => $manifest['note'] ?? '',
                    'tables' => array_map(static fn($t) => (int) $t['rows'], $manifest['tables']),
                    'files' => count(array_filter(array_keys($manifest['files']), static fn($n) => str_starts_with($n, 'files/'))),
                    'missing_files' => (int) ($manifest['missing_files'] ?? 0),
                ];
                flash('Hasło poprawne, kopia jest kompletna (sumy kontrolne się zgadzają).');
                $back = 'backup.php?file=' . rawurlencode($file);
                break;

            case 'restore':
                if (empty($_POST['confirm'])) { throw new \RuntimeException('Zaznacz potwierdzenie, że rozumiesz, co zostanie zastąpione.'); }
                $result = $backup->restore($file, (string) ($_POST['password'] ?? ''));
                unset($_SESSION['backup_inspect'][$file]);
                flash('Przywrócono: ' . $partLabel($result['part'])
                    . ($result['orders'] !== null ? ' - zamówienia z okresu ' . $fmtPeriod($result['period']) . ' (zamówień: ' . $result['orders'] . ')' : '')
                    . ' (wierszy: ' . array_sum($result['tables']) . ', plików: '
                    . $result['files'] . '). Poprzedni stan zapisano jako osobną kopię - można do niego wrócić.');
                \Pase\Support\Logger::info('Przywrócono kopię ' . $file . ' (' . $result['part'] . ') przez ' . currentUserName() . '; kopia bezpieczeństwa: ' . $result['safety']);
                break;

            case 'delete':
                if (empty($_POST['confirm'])) { throw new \RuntimeException('Zaznacz potwierdzenie usunięcia.'); }
                $backup->delete($file);
                unset($_SESSION['backup_inspect'][$file]);
                flash('Usunięto kopię.');
                break;

            default:
                throw new \RuntimeException('Nieznana operacja.');
        }
    } catch (\Throwable $ex) {
        // Komunikaty serwisu są dla użytkownika; błędy bazy - ogólnie, bez szczegółów połączenia.
        $msg = $ex instanceof \RuntimeException ? $ex->getMessage() : 'Operacja nie powiodła się. Szczegóły w dzienniku systemu.';
        \Pase\Support\Logger::warn('Kopia zapasowa [' . $action . ']: ' . $ex->getMessage());
        flash($msg, 'err');
        if (in_array($action, ['inspect', 'restore'], true) && $file !== '') { $back = 'backup.php?file=' . rawurlencode($file); }
    }
    redirectAfterPost($back ?? 'backup.php');
}

// ---------------------------------------------------------------- widok
$error = null;
try {
    $backups = $backup->list();
} catch (\Throwable $ex) {
    $backups = [];
    $error = $ex->getMessage();
}
$byFile = array_column($backups, null, 'file');
$selected = isset($_GET['file'], $byFile[(string) $_GET['file']]) ? $byFile[(string) $_GET['file']] : null;
$autoDownload = $_SESSION['backup_autodownload'] ?? null;
unset($_SESSION['backup_autodownload']);
$fullPackages = [];
try { $fullPackages = $full->list(); } catch (\Throwable) {}
$autoDownloadFull = $_SESSION['backup_autodownload_full'] ?? null;
unset($_SESSION['backup_autodownload_full']);

$counts = [];
foreach (array_keys(SectionBackup::PARTS) as $p) {
    try { $counts[$p] = $backup->currentCounts($p); } catch (\Throwable) { $counts[$p] = []; }
}
$tableLabels = [
    'settings' => 'Ustawienia', 'integrations' => 'Integracje (stare)', 'integration_accounts' => 'Integracje',
    'mail_accounts' => 'Konta e-mail', 'order_statuses' => 'Statusy', 'automation_rules' => 'Automatyzacje',
    'email_templates' => 'Szablony e-mail', 'print_templates' => 'Szablony wydruku', 'print_assets' => 'Pliki do druku',
    'product_fields' => 'Własne pola', 'allegro_desc_templates' => 'Szablony opisów Allegro', 'user_layouts' => 'Układy widoków',
    'woo_orders' => 'Zamówienia', 'order_logs' => 'Rejestr importu', 'order_messages' => 'Wiadomości',
    'order_documents' => 'Dokumenty', 'document_issue_operations' => 'Rezerwacje dokumentów', 'shipments' => 'Przesyłki',
    'audit_events' => 'Historia zmian', 'products' => 'Produkty', 'product_mappings' => 'Mapowania SKU',
    'offer_templates' => 'Szablony ofert', 'allegro_offer_details' => 'Szczegóły ofert Allegro',
];
$summary = static function (array $c, array $labels): string {
    $parts = [];
    foreach ($c as $t => $n) { if ($n) { $parts[] = ($labels[$t] ?? $t) . ': ' . number_format($n, 0, ',', ' '); } }
    return $parts ? implode(' · ', array_slice($parts, 0, 4)) . (count($parts) > 4 ? ' …' : '') : 'brak danych';
};

$PAGE_TITLE = 'Kopia zapasowa';
$PAGE_KEY   = 'backup';
require __DIR__ . '/header.php';
?>
<style>
    .bk-parts { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:12px; margin:14px 0; }
    .bk-part > label { display:block; cursor:pointer; }
    .bk-period { margin-top:12px; padding-top:10px; border-top:1px dashed var(--line); }
    .bk-period-row { display:flex; gap:6px; align-items:center; }
    .bk-period-row input { flex:1; min-width:0; padding:6px 8px; font-size:13px; }
    .bk-presets { display:flex; flex-wrap:wrap; gap:4px; margin-top:6px; }
    .bk-presets button { background:var(--surface); border:1px solid var(--line); border-radius:6px; padding:3px 8px; font-size:12px; color:var(--ink-2); cursor:pointer; }
    .bk-presets button:hover { border-color:var(--accent); color:var(--accent-ink); }
    .bk-inline { display:inline; margin:0; }
    .bk-part { display:block; border:1px solid var(--line); border-radius:var(--radius-sm); padding:14px; cursor:pointer; background:var(--surface-2); }
    .bk-part:has(input:checked) { border-color:var(--accent); background:var(--accent-soft); }
    .bk-part strong { display:flex; gap:8px; align-items:center; }
    .bk-part p { margin:6px 0 0; font-size:13px; color:var(--ink-2); }
    .bk-part small { display:block; margin-top:8px; font-size:12px; color:var(--ink-3); font-family:var(--font-num); }
    .bk-fields { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px; }
    .bk-fields label, .bk-lbl { font-size:12px; color:var(--ink-2); display:block; margin-bottom:4px; }
    .bk-fields input { width:100%; }
    .bk-hint { font-size:13px; color:var(--ink-2); margin:10px 0 0; }
    .bk-check { display:flex; gap:8px; align-items:flex-start; font-size:14px; margin-top:12px; cursor:pointer; }
    .bk-actions { display:flex; gap:8px; justify-content:flex-end; flex-wrap:wrap; }
    .bk-table td { vertical-align:middle; }
    .bk-num { font-family:var(--font-num); font-size:13px; }
    .bk-warn { background:var(--warn-bg); color:var(--warn-ink); border-radius:var(--radius-sm); padding:12px 14px; font-size:13px; margin:12px 0; }
    .bk-cols { display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:18px; }
    @media (max-width:700px) { .bk-table .hide-sm { display:none; } }
</style>

<?php if ($autoDownload && isset($byFile[$autoDownload])): ?>
    <iframe src="backup.php?download=<?= $e(rawurlencode($autoDownload)) ?>" style="display:none" title="Pobieranie kopii"></iframe>
<?php endif; ?>
<?php if ($autoDownloadFull && in_array($autoDownloadFull, array_column($fullPackages, 'file'), true)): ?>
    <iframe src="backup.php?download_full=<?= $e(rawurlencode($autoDownloadFull)) ?>" style="display:none" title="Pobieranie pełnej kopii"></iframe>
<?php endif; ?>
<?php if ($error): ?><div class="flash err"><?= $e($error) ?></div><?php endif; ?>

<?php if ($selected): ?>
    <?php $info = $_SESSION['backup_inspect'][$selected['file']] ?? null; $part = $selected['part']; ?>
    <div class="card">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start">
            <div>
                <strong><?= $e($selected['label']) ?></strong><?= $selected['period'] ? ' · okres ' . $e($fmtPeriod($selected['period'])) : '' ?> · <?= $e($fmtDate($selected['created_at'])) ?>
                <?php if ($selected['note'] !== ''): ?><div class="bk-hint" style="margin-top:4px"><?= $e($selected['note']) ?></div><?php endif; ?>
                <div class="bk-hint bk-num" style="margin-top:4px"><?= $e($selected['file']) ?> · <?= $e($fmtBytes($selected['size'])) ?></div>
            </div>
            <div class="bk-actions">
                <a class="btn secondary" href="backup.php?download=<?= $e(rawurlencode($selected['file'])) ?>">Pobierz</a>
                <a class="btn secondary" href="backup.php">Wróć do listy</a>
            </div>
        </div>

        <?php if ($info): ?>
            <table class="bk-table" style="margin-top:16px">
                <tr><th>Zawartość</th><th style="text-align:right">W kopii</th><th style="text-align:right">Teraz w systemie</th></tr>
                <?php foreach ($info['tables'] as $t => $n): ?>
                    <tr>
                        <td><?= $e($tableLabels[$t] ?? $t) ?></td>
                        <td class="bk-num" style="text-align:right"><?= number_format($n, 0, ',', ' ') ?></td>
                        <td class="bk-num" style="text-align:right"><?= isset($counts[$part][$t]) ? number_format((int) $counts[$part][$t], 0, ',', ' ') : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr><td>Pliki (załączniki, grafiki)</td><td class="bk-num" style="text-align:right"><?= (int) $info['files'] ?></td><td></td></tr>
            </table>
            <?php if ($info['missing_files'] > 0): ?>
                <p class="bk-hint">W chwili tworzenia kopii brakowało na serwerze <?= (int) $info['missing_files'] ?> plików, do których odwoływały się dane - nie ma ich w kopii.</p>
            <?php endif; ?>
            <?php if ($info['app_version'] !== ''): ?><p class="bk-hint">Utworzona w wersji: <?= $e($info['app_version']) ?></p><?php endif; ?>
        <?php endif; ?>

        <div class="bk-cols" style="margin-top:18px">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= $e(csrfToken()) ?>">
                <input type="hidden" name="action" value="inspect">
                <input type="hidden" name="file" value="<?= $e($selected['file']) ?>">
                <strong>Sprawdź kopię</strong>
                <p class="bk-hint">Odczytuje całą kopię i porównuje sumy kontrolne. Niczego nie zmienia.</p>
                <label class="bk-lbl" style="margin-top:10px">Hasło do kopii</label>
                <input type="password" name="password" required autocomplete="off" style="width:100%">
                <p style="margin-top:12px"><button class="btn secondary" type="submit">Sprawdź</button></p>
            </form>

            <form method="post">
                <input type="hidden" name="csrf" value="<?= $e(csrfToken()) ?>">
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="file" value="<?= $e($selected['file']) ?>">
                <strong>Przywróć z tej kopii</strong>
                <div class="bk-warn">
                    <?php if ($selected['period']): ?>
                    To kopia zamówień z okresu <strong><?= $e($fmtPeriod($selected['period'])) ?></strong>. Zastąpione zostaną tylko zamówienia zawarte w kopii
                    (razem z ich wiadomościami, dokumentami, przesyłkami i historią) - pozostałe zamówienia zostają bez zmian.
                    Zamówienia z tego okresu dodane po utworzeniu kopii też zostają.
                    Kopia nie cofa niczego w Allegro, WooCommerce ani wFirma.
                    <?php else: ?>
                    Obecne dane z części <strong><?= $e($selected['label']) ?></strong> zostaną zastąpione zawartością kopii
                    (inne części systemu zostają bez zmian). Zmiany wprowadzone po <?= $e($fmtDate($selected['created_at'])) ?> w tej części znikną.
                    <?php if ($part === 'settings'): ?>Aktualne tokeny połączeń (np. Allegro) i znaczniki synchronizacji zostają - nie trzeba łączyć kont od nowa.<?php endif; ?>
                    <?php if ($part === 'orders'): ?>Kopia nie cofa niczego w Allegro, WooCommerce ani wFirma - dokumenty wystawione później mogą istnieć tylko tam. Najlepiej przywracać, gdy nikt nie pracuje na zamówieniach.<?php endif; ?>
                    <?php endif; ?>
                    Przed przywróceniem system sam zapisze kopię obecnego stanu.
                </div>
                <label class="bk-lbl">Hasło do kopii</label>
                <input type="password" name="password" required autocomplete="off" style="width:100%">
                <label class="bk-check"><input type="checkbox" name="confirm" value="1" required> Rozumiem - zastąp obecne dane tej części.</label>
                <p style="margin-top:12px"><button class="btn danger" type="submit">Przywróć</button></p>
            </form>
        </div>

        <form method="post" style="margin-top:18px;padding-top:14px;border-top:1px solid var(--line);display:flex;gap:14px;align-items:center;flex-wrap:wrap">
            <input type="hidden" name="csrf" value="<?= $e(csrfToken()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="file" value="<?= $e($selected['file']) ?>">
            <label class="bk-check" style="margin:0"><input type="checkbox" name="confirm" value="1" required> Usuń tę kopię z serwera</label>
            <button class="btn danger" type="submit">Usuń</button>
        </form>
    </div>
<?php else: ?>

    <div class="card">
        <strong>Utwórz kopię</strong>
        <p class="bk-hint">Każda część to osobny plik - ustawienia można przenieść bez zamówień i odwrotnie. Kopia jest zaszyfrowana hasłem, które podasz poniżej.</p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= $e(csrfToken()) ?>">
            <input type="hidden" name="action" value="create">
            <div class="bk-parts">
                <?php $first = true; foreach (SectionBackup::PARTS as $key => $def): ?>
                    <div class="bk-part">
                        <label>
                            <strong><input type="radio" name="part" value="<?= $e($key) ?>" <?= $first ? 'checked' : '' ?>> <?= $e($def['label']) ?></strong>
                            <p><?= $e($def['desc']) ?></p>
                            <small><?= $e($summary($counts[$key] ?? [], $tableLabels)) ?></small>
                        </label>
                        <?php if ($key === 'orders'): ?>
                            <div class="bk-period">
                                <span class="bk-lbl">Okres (data złożenia zamówienia) - puste = wszystkie</span>
                                <div class="bk-period-row">
                                    <input type="date" name="period_from" aria-label="Od" data-orders>
                                    <span>–</span>
                                    <input type="date" name="period_to" aria-label="Do" data-orders>
                                </div>
                                <div class="bk-presets">
                                    <button type="button" data-preset="this-month">Ten miesiąc</button>
                                    <button type="button" data-preset="last-month">Poprzedni miesiąc</button>
                                    <button type="button" data-preset="this-year">Ten rok</button>
                                    <button type="button" data-preset="last-year">Poprzedni rok</button>
                                    <button type="button" data-preset="clear">Wszystkie</button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php $first = false; endforeach; ?>
            </div>
            <div class="bk-fields">
                <div><label>Hasło do kopii</label><input type="password" name="password" required minlength="<?= SectionBackup::MIN_PASSWORD ?>" autocomplete="new-password" placeholder="min. <?= SectionBackup::MIN_PASSWORD ?> znaków"></div>
                <div><label>Powtórz hasło</label><input type="password" name="password2" required minlength="<?= SectionBackup::MIN_PASSWORD ?>" autocomplete="new-password"></div>
                <div><label>Opis (opcjonalnie)</label><input name="note" maxlength="120" placeholder="np. przed zmianą automatyzacji"></div>
            </div>
            <label class="bk-check"><input type="checkbox" name="download_now" value="1" checked> Pobierz od razu na komputer (kopia zostaje też na serwerze)</label>
            <p class="bk-hint">Hasła nie da się odzyskać ani zresetować - zapisz je np. w menedżerze haseł. Kopia ustawień zawiera klucze API i hasła SMTP, kopia zamówień - dane osobowe klientów.</p>
            <p style="margin-top:14px"><button class="btn" type="submit">Utwórz kopię</button></p>
        </form>
    </div>

    <div class="card">
        <strong>Zapisane kopie (<?= count($backups) ?>)</strong>
        <?php if (!$backups): ?>
            <p class="bk-hint">Nie ma jeszcze żadnej kopii.</p>
        <?php else: ?>
            <table class="bk-table" style="margin-top:12px">
                <tr><th>Data</th><th>Rodzaj</th><th class="hide-sm">Opis</th><th class="hide-sm" style="text-align:right">Rozmiar</th><th></th></tr>
                <?php foreach ($backups as $b): ?>
                    <tr>
                        <td class="bk-num"><?= $e($fmtDate($b['created_at'])) ?></td>
                        <td><span class="pill <?= $b['part'] === 'settings' ? 'warn' : ($b['part'] === 'orders' ? 'ok' : 'muted') ?>"><?= $e($b['label']) ?></span>
                            <?php if ($b['period']): ?><div class="bk-num" style="font-size:12px;color:var(--ink-2);margin-top:4px"><?= $e($fmtPeriod($b['period'])) ?></div><?php endif; ?></td>
                        <td class="hide-sm" style="font-size:13px;color:var(--ink-2)"><?= $e($b['note'] !== '' ? $b['note'] : (str_starts_with($b['file'], 'crm-upload-') ? 'Wgrana z komputera' : '—')) ?></td>
                        <td class="hide-sm bk-num" style="text-align:right"><?= $e($fmtBytes($b['size'])) ?></td>
                        <td><div class="bk-actions">
                            <a class="btn secondary" href="backup.php?download=<?= $e(rawurlencode($b['file'])) ?>">Pobierz</a>
                            <a class="btn secondary" href="backup.php?file=<?= $e(rawurlencode($b['file'])) ?>">Sprawdź / przywróć</a>
                            <form method="post" class="bk-inline" data-confirm="Usunąć kopię z <?= $e($fmtDate($b['created_at'])) ?> (<?= $e($b['label']) ?>)? Tej operacji nie można cofnąć.">
                                <input type="hidden" name="csrf" value="<?= $e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="file" value="<?= $e($b['file']) ?>">
                                <input type="hidden" name="confirm" value="1">
                                <button class="btn danger" type="submit" title="Usuń kopię z serwera">Usuń</button>
                            </form>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <strong>Pełna kopia systemu (przeniesienie na inny serwer)</strong>
        <p class="bk-hint">Jeden plik ZIP z całym CRM: kod, konfiguracja, cała baza danych, załączniki i grafiki, konta użytkowników z 2FA, integracje i klucze API.
            W środku jest instalator: wgrywasz zawartość ZIP-a do pustego katalogu na nowym serwerze, otwierasz <code>install.php</code> w przeglądarce,
            podajesz hasło kopii i dane nowej, pustej bazy - instalator przenosi wszystko, ustawia nowy adres i sam się usuwa.</p>
        <?php if ($fullPackages): ?>
            <table class="bk-table" style="margin-top:12px">
                <?php foreach ($fullPackages as $p): ?>
                    <tr>
                        <td class="bk-num"><?= $e((new DateTimeImmutable('@' . $p['created_at']))->setTimezone($tz)->format('d.m.Y H:i')) ?></td>
                        <td class="bk-num" style="text-align:right"><?= $e($fmtBytes($p['size'])) ?></td>
                        <td><div class="bk-actions">
                            <a class="btn" href="backup.php?download_full=<?= $e(rawurlencode($p['file'])) ?>">Pobierz</a>
                            <form method="post" class="bk-inline">
                                <input type="hidden" name="csrf" value="<?= $e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="full_delete">
                                <input type="hidden" name="file" value="<?= $e($p['file']) ?>">
                                <button class="btn danger" type="submit">Usuń</button>
                            </form>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <p class="bk-hint">Pełna kopia znika z serwera po pobraniu, a nieodebrana - po 24 godzinach.</p>
        <?php endif; ?>
        <form method="post" style="margin-top:12px">
            <input type="hidden" name="csrf" value="<?= $e(csrfToken()) ?>">
            <input type="hidden" name="action" value="full_create">
            <div class="bk-fields">
                <div><label>Hasło do kopii</label><input type="password" name="password" required minlength="<?= FullSystemPackage::MIN_PASSWORD ?>" autocomplete="new-password" placeholder="min. <?= FullSystemPackage::MIN_PASSWORD ?> znaków"></div>
                <div><label>Powtórz hasło</label><input type="password" name="password2" required minlength="<?= FullSystemPackage::MIN_PASSWORD ?>" autocomplete="new-password"></div>
            </div>
            <p class="bk-hint">Hasło będzie potrzebne w instalatorze; nie da się go odzyskać. Tworzenie może potrwać kilka minut - nie zamykaj strony.
                Do przeniesienia ręcznie: zadanie cron na nowym serwerze (i usunięcie go ze starego), adres przekierowania w aplikacji Allegro,
                adresy webhooków WooCommerce oraz domena, DNS i SSL. Wszystkie te dane są w pliku <code>PRZENIESIENIE.txt</code> w kopii
                (bez haseł i kluczy) - można go też <a href="backup.php?download_guide=1">pobrać osobno</a>.</p>
            <p style="margin-top:14px"><button class="btn" type="submit">Utwórz pełną kopię i pobierz</button></p>
        </form>
    </div>

    <div class="card">
        <strong>Wgraj kopię z komputera</strong>
        <p class="bk-hint">Plik .zip pobrany wcześniej z tej strony (także z innej instalacji CRM - np. przy przenosinach na nowy serwer). Po wgraniu pojawi się na liście, skąd można go sprawdzić i przywrócić.</p>
        <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px">
            <input type="hidden" name="csrf" value="<?= $e(csrfToken()) ?>">
            <input type="hidden" name="action" value="upload">
            <input type="file" name="archive" accept=".zip,application/zip" required>
            <button class="btn secondary" type="submit">Wgraj</button>
        </form>
        <p class="bk-hint">Maksymalny rozmiar pliku na tym serwerze: <?= $e(ini_get('upload_max_filesize') ?: '?') ?>.</p>
    </div>

    <div class="card">
        <strong>Czego nie ma w tych kopiach</strong>
        <p class="bk-hint">Kont użytkowników i ich 2FA, kolejki zadań i wydruków w toku, pamięci podręcznej, plików kodu i pliku .env. Znaczniki pracy systemu (kursor zamówień Allegro, czasy synchronizacji) celowo nie są kopiowane.
            To wszystko zawiera <strong>pełna kopia systemu</strong> powyżej.
            Kopie trzymane tylko na tym serwerze nie chronią przed awarią hostingu - pobieraj je co jakiś czas na komputer.</p>
    </div>
<?php endif; ?>

<script<?= isset($cspNonce) ? ' nonce="' . $e($cspNonce) . '"' : '' ?>>
(function () {
    // Potwierdzenie usunięcia kopii z listy.
    document.querySelectorAll('form[data-confirm]').forEach(function (f) {
        f.addEventListener('submit', function (ev) { if (!confirm(f.getAttribute('data-confirm'))) { ev.preventDefault(); } });
    });
    // Okres dla zamówień: szybkie zakresy; wpisanie daty zaznacza „Dane zamówień”.
    var from = document.querySelector('input[name=period_from]'), to = document.querySelector('input[name=period_to]');
    if (!from) { return; }
    var orders = document.querySelector('input[name=part][value=orders]');
    function iso(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
    document.querySelectorAll('[data-preset]').forEach(function (b) {
        b.addEventListener('click', function () {
            var n = new Date(), y = n.getFullYear(), m = n.getMonth(), p = b.getAttribute('data-preset'), a = '', z = '';
            if (p === 'this-month') { a = iso(new Date(y, m, 1)); z = iso(new Date(y, m + 1, 0)); }
            if (p === 'last-month') { a = iso(new Date(y, m - 1, 1)); z = iso(new Date(y, m, 0)); }
            if (p === 'this-year')  { a = y + '-01-01'; z = y + '-12-31'; }
            if (p === 'last-year')  { a = (y - 1) + '-01-01'; z = (y - 1) + '-12-31'; }
            from.value = a; to.value = z;
            orders.checked = true;
        });
    });
    [from, to].forEach(function (i) { i.addEventListener('change', function () { if (i.value) { orders.checked = true; } }); });
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
