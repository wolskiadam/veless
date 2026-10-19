<?php
declare(strict_types=1);

/**
 * Wtyczki (Konfiguracja → Wtyczki). Tylko administrator.
 * Dwa rodzaje: integracje (integrations/ - połączenia ze sklepami, kurierami, księgowością)
 * i rozszerzenia (extensions/ - nowe funkcje w samym CRM, przez haki Pase\Plugin\Hooks).
 * Użytkownik widzi jedną listę wtyczek z uprawnieniami (PluginPermissions) zamiast rodzaju; ustawienia w oknie
 * pod ikoną koła zębatego (rozszerzenie - pola z manifestu, integracja - jej konta). Do tego wgrywanie ZIP i włącz/wyłącz/usuń.
 * Zakładki dokumentacji pokazują integrations/README.md i extensions/README.md.
 *
 * UWAGA: instalacja wtyczki wykonuje obcy kod PHP z pełnymi prawami aplikacji.
 * Brak sandboxa na shared hostingu - instaluj tylko wtyczki z zaufanego źródła.
 */

use Pase\Plugin\ExtensionRegistry;
use Pase\Plugin\ExtensionSettings;
use Pase\Plugin\PluginInstaller;
use Pase\Plugin\PluginPermissions;
use Pase\Plugin\PluginRegistry;
use Pase\Repository\IntegrationAccountRepository;
use Pase\Support\Markdown;

require __DIR__ . '/auth.php';
requireRole('admin'); // najwyższe ryzyko - tylko admin
/** @var PDO $pdo */

$flashOk = $flashErr = null;
$warnings = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = $_POST['action'] ?? '';
    $kind = ($_POST['kind'] ?? '') === 'extension' ? 'extension' : 'integration';

    if ($action === 'upload') {
        if (empty($_POST['confirm_risk'])) {
            $flashErr = 'Potwierdź, że instalujesz wtyczkę z zaufanego źródła.';
        } else {
            $res = PluginInstaller::installFromZip($_FILES['plugin_zip'] ?? []);
            if ($res['ok']) {
                $flashOk = $res['message'];
                $warnings = $res['warnings'] ?? [];
            } else {
                $flashErr = $res['message'];
            }
        }
    } elseif ($action === 'toggle') {
        $res = PluginInstaller::setEnabled((string) ($_POST['slug'] ?? ''), ($_POST['to'] ?? '') === '1', $kind);
        $flashOk = $res['ok'] ? $res['message'] : null;
        $flashErr = $res['ok'] ? null : $res['message'];
    } elseif ($action === 'uninstall') {
        $res = PluginInstaller::uninstall((string) ($_POST['slug'] ?? ''), $kind);
        $flashOk = $res['ok'] ? $res['message'] : null;
        $flashErr = $res['ok'] ? null : $res['message'];
    } elseif ($action === 'ext_settings') {
        $slug = (string) ($_POST['slug'] ?? '');
        $found = null;
        foreach (ExtensionRegistry::installed() as $e) {
            if ($e['slug'] === $slug && $e['manifest'] !== null) { $found = $e['manifest']; }
        }
        if ($found === null) {
            $flashErr = 'Nie ma takiego rozszerzenia.';
        } else {
            ExtensionSettings::save($pdo, $found, (array) ($_POST['cfg'] ?? []), (array) ($_POST['cfg_clear'] ?? []));
            $flashOk = 'Zapisano ustawienia: ' . $found->name . '.';
        }
    }
}

PluginRegistry::reset();
$installed = PluginRegistry::installed();
$extensions = ExtensionRegistry::installed();

$tab = in_array($_GET['tab'] ?? '', ['docs', 'ext_docs'], true) ? $_GET['tab'] : 'list';
$docsFile = dirname(__DIR__, 2) . ($tab === 'ext_docs' ? '/extensions/README.md' : '/integrations/README.md');

$PAGE_TITLE = 'Wtyczki';
$PAGE_KEY   = 'plugins';
require __DIR__ . '/header.php';
?>

<style>
    .pl-tabs { display:flex; gap:4px; border-bottom:1px solid #e3e6ea; margin-bottom:16px; flex-wrap:wrap; }
    .pl-tabs a { padding:9px 16px; text-decoration:none; color:#555; border-bottom:2px solid transparent; margin-bottom:-1px; font-weight:600; font-size:14px; }
    .pl-tabs a.on { color:#667eea; border-bottom-color:#667eea; }
    .pl-docs { line-height:1.6; font-size:14px; max-width:920px; }
    .pl-docs h1 { font-size:22px; margin:0 0 12px; }
    .pl-docs h2 { font-size:17px; margin:26px 0 8px; padding-top:14px; border-top:1px solid #eef0f3; }
    .pl-docs h3 { font-size:15px; margin:18px 0 6px; }
    .pl-docs p, .pl-docs ul, .pl-docs ol { margin:8px 0; }
    .pl-docs li { margin:3px 0; }
    .pl-docs code { background:#f3f4f7; padding:1px 5px; border-radius:4px; font-size:12.5px; }
    .pl-docs pre { background:#1f2330; color:#e6e8ef; padding:12px 14px; border-radius:8px; overflow:auto; font-size:12.5px; line-height:1.5; }
    .pl-docs pre code { background:none; padding:0; color:inherit; }
    .pl-docs blockquote { margin:10px 0; padding:8px 14px; border-left:3px solid #f0c36d; background:#fffdf6; color:#6b5520; }
    .pl-docs table { margin:10px 0; }
</style>

<nav class="pl-tabs">
    <a href="plugins.php" class="<?= $tab === 'list' ? 'on' : '' ?>">Zainstalowane</a>
    <a href="plugins.php?tab=docs" class="<?= $tab === 'docs' ? 'on' : '' ?>">Dokumentacja integracji</a>
    <a href="plugins.php?tab=ext_docs" class="<?= $tab === 'ext_docs' ? 'on' : '' ?>">Dokumentacja rozszerzeń</a>
</nav>

<?php if ($tab !== 'list'): ?>
<div class="card pl-docs">
    <?php if (is_readable($docsFile)): ?>
        <?= Markdown::toHtml((string) file_get_contents($docsFile)) ?>
    <?php else: ?>
        <p style="color:#888">Brak pliku <code><?= htmlspecialchars(basename(dirname($docsFile)) . '/README.md') ?></code> na serwerze.</p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; return; ?>
<?php endif; ?>

<?php if ($flashOk): ?><div class="flash ok"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="flash err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>
<?php if ($warnings): ?>
    <div class="flash err" style="background:#fff7e6;color:#92600a">
        ⚠ Wtyczka używa potencjalnie niebezpiecznych funkcji: <strong><?= htmlspecialchars(implode(', ', $warnings)) ?></strong>.
        Upewnij się, że pochodzi z zaufanego źródła.
    </div>
<?php endif; ?>

<?php foreach (PluginInstaller::modifiedSinceInstall() as $m): ?>
    <div class="flash err">
        ⚠ Pliki wtyczki <strong><?= htmlspecialchars($m['slug']) ?></strong> zmieniły się poza panelem od instalacji
        (<?= htmlspecialchars(substr($m['installed_at'], 0, 10)) ?>). Jeśli nikt jej nie aktualizował przez FTP, ktoś mógł podrzucić kod -
        wyłącz ją i wgraj ponownie z zaufanego źródła.
    </div>
<?php endforeach; ?>

<div class="card" style="border:1px solid #f0d9a8;background:#fffdf6">
    <strong>⚠ Instalacja wtyczki uruchamia obcy kod PHP</strong>
    <p style="color:#7a5b18;font-size:13px;margin:6px 0 0">
        Wtyczka ma pełny dostęp do bazy, kluczy API i plików.
        Instaluj wyłącznie wtyczki, którym ufasz. Przed instalacją robiona jest kopia zapasowej poprzedniej wersji.
    </p>
</div>

<?php if (!PluginInstaller::uploadAllowed()): ?>
<div class="card">
    <strong>Zainstaluj wtyczkę z pliku ZIP</strong>
    <p style="color:#555;font-size:13px;margin:6px 0 0">Wgrywanie z panelu jest wyłączone na tym serwerze (<code>PLUGIN_UPLOAD=0</code> w <code>.env</code>).
        Wtyczki dodaje się przez FTP albo repozytorium.</p>
</div>
<?php else: ?>
<div class="card">
    <strong>Zainstaluj wtyczkę z pliku ZIP</strong>
    <form method="post" enctype="multipart/form-data" style="margin-top:12px">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="upload">
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <input type="file" name="plugin_zip" accept=".zip" required>
            <button class="btn" type="submit">Wgraj i zainstaluj</button>
        </div>
        <label style="display:flex;gap:8px;align-items:center;margin-top:12px;font-size:13px;color:#555">
            <input type="checkbox" name="confirm_risk" value="1">
            Rozumiem, że instaluję obcy kod PHP i robię to na własną odpowiedzialność.
        </label>
        <p style="color:#888;font-size:12px;margin-top:8px">
            Paczka musi zawierać katalog wtyczki z plikiem <code>register.php</code>. Rodzaj rozpoznajemy sam:
            klasa z <code>PaseExt\</code> to rozszerzenie (<a href="plugins.php?tab=ext_docs">dokumentacja</a>),
            z <code>PasePlugin\</code> integracja (<a href="plugins.php?tab=docs">dokumentacja</a>).
        </p>
    </form>
</div>
<?php endif; ?>

<?php
// Integracje i rozszerzenia w jednej tabeli, alfabetycznie. Zamiast rodzaju - uprawnienia (do czego wtyczka sięga),
// a ustawienia w oknie otwieranym ikoną: rozszerzenie - formularz z manifestu, integracja - jej konta z Integracji.
$all = [];
foreach ($installed as $p) {
    $all[] = $p + ['kind' => 'integration', 'perms' => $p['manifest'] ? PluginPermissions::forIntegration($p['manifest']) : []];
}
foreach ($extensions as $p) {
    $ext = $p['manifest'] ? ExtensionRegistry::loadFile(ExtensionRegistry::dir() . '/' . $p['slug'] . '/register.php') : null;
    $all[] = $p + ['kind' => 'extension', 'perms' => PluginPermissions::forExtension($p['slug'], $ext)];
}
usort($all, static fn(array $a, array $b): int => strcasecmp((string) ($a['manifest']?->name ?? $a['slug']), (string) ($b['manifest']?->name ?? $b['slug'])));

$accounts = [];
try {
    foreach ((new IntegrationAccountRepository($pdo))->all() as $acc) { $accounts[$acc['type']][] = $acc; }
} catch (\Throwable) {
    // brak tabeli kont (np. świeża baza) - okno integracji pokaże tylko „Dodaj konto”
}
$gearIcon = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>';
$trashIcon = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>';
$dialogs = '';
?>
<div class="card">
    <strong>Wtyczki (<?= count($all) ?>)</strong>
    <p style="color:#888;font-size:12px;margin:4px 0 0">
        Pod opisem każdej wtyczki są jej uprawnienia, czyli części CRM, do których sięga. Ustawienia otwiera ikona koła zębatego.
    </p>
    <table class="pl-table" style="margin-top:10px">
        <tr><th>Wtyczka</th><th>Opis i uprawnienia</th><th>Wersja</th><th>Status</th><th></th></tr>
        <?php if ($all === []): ?>
            <tr><td colspan="5" style="color:#888">Brak wtyczek. Wgraj ZIP powyżej lub dodaj katalog do <code>integrations/</code> albo <code>extensions/</code> przez FTP.</td></tr>
        <?php endif; ?>
        <?php foreach ($all as $p): $m = $p['manifest']; $kind = $p['kind']; $isExt = $kind === 'extension';
            $name = $m ? $m->name : $p['slug'];
            $dlgId = 'pl-cfg-' . $kind . '-' . preg_replace('/[^a-z0-9_]/', '', $p['slug']);
            $hasSettings = $m !== null && (!$isExt || $m->fields !== []); ?>
            <tr<?= $p['error'] ? ' style="background:#fdf3f3"' : '' ?> data-kind="<?= $kind ?>">
                <td>
                    <strong><?= $m ? ($m instanceof \Pase\Plugin\PluginManifest ? $m->iconHtml('1em') : htmlspecialchars($m->icon)) . ' ' . htmlspecialchars($m->name) : htmlspecialchars($p['slug']) ?></strong>
                    <div style="font-size:12px;color:#888"><code><?= htmlspecialchars($p['slug']) ?></code><?php if ($m && $m->author !== ''): ?> · <?= htmlspecialchars($m->author) ?><?php endif; ?></div>
                    <?php if ($p['error']): ?><div style="font-size:12px;color:#c0392b">Błąd: <?= htmlspecialchars($p['error']) ?></div><?php endif; ?>
                </td>
                <td style="font-size:13px;color:#555">
                    <div><?= htmlspecialchars($m?->description ?: '—') ?></div>
                    <?php if ($p['perms'] !== []): ?>
                        <div class="pl-perms">
                            <span class="pl-perms-label">Uprawnienia:</span>
                            <?php foreach ($p['perms'] as [$label, $hint]): ?>
                                <span class="pl-perm"<?= $hint !== '' ? ' title="' . htmlspecialchars($hint) . '"' : '' ?>><?= htmlspecialchars($label) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($m?->version ?? '—') ?></td>
                <td><?= $p['enabled'] ? '<span class="pill ok">włączona</span>' : '<span class="pill muted">wyłączona</span>' ?></td>
                <td class="pl-actions">
                    <?php if ($hasSettings): ?>
                        <button class="pl-icon" type="button" data-open="<?= $dlgId ?>" title="Ustawienia: <?= htmlspecialchars($name) ?>" aria-label="Ustawienia: <?= htmlspecialchars($name) ?>"><?= $gearIcon ?></button>
                    <?php endif; ?>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="kind" value="<?= $kind ?>">
                        <input type="hidden" name="slug" value="<?= htmlspecialchars($p['slug']) ?>">
                        <input type="hidden" name="to" value="<?= $p['enabled'] ? '0' : '1' ?>">
                        <button class="btn secondary pl-toggle" type="submit"><?= $p['enabled'] ? 'Wyłącz' : 'Włącz' ?></button>
                    </form>
                    <form method="post" style="display:inline" onsubmit="return confirm('Usunąć wtyczkę „<?= htmlspecialchars($p['slug']) ?>”? Kopia zapasowa zostanie zachowana.')">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="uninstall">
                        <input type="hidden" name="kind" value="<?= $kind ?>">
                        <input type="hidden" name="slug" value="<?= htmlspecialchars($p['slug']) ?>">
                        <button class="pl-icon pl-del" type="submit" title="Usuń wtyczkę" aria-label="Usuń wtyczkę: <?= htmlspecialchars($name) ?>"><?= $trashIcon ?></button>
                    </form>
                </td>
            </tr>
            <?php if (!$hasSettings) { continue; } ob_start(); ?>
            <dialog class="pl-dialog" id="<?= $dlgId ?>" aria-labelledby="<?= $dlgId ?>-t">
                <div class="pl-dialog-head">
                    <h2 id="<?= $dlgId ?>-t"><?= $m instanceof \Pase\Plugin\PluginManifest ? $m->iconHtml('1em') : htmlspecialchars($m->icon) ?> <?= htmlspecialchars($name) ?></h2>
                    <button class="pl-close" type="button" data-close aria-label="Zamknij">×</button>
                </div>
                <?php if ($isExt): $vals = ExtensionSettings::get($m->type, $pdo); ?>
                    <form method="post" class="ext-cfg">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="ext_settings">
                        <input type="hidden" name="slug" value="<?= htmlspecialchars($p['slug']) ?>">
                        <?php foreach ($m->fields as $f): $k = (string) ($f['key'] ?? ''); if ($k === '') { continue; }
                            $type = (string) ($f['type'] ?? 'text'); $val = (string) ($vals[$k] ?? ($f['default'] ?? ''));
                            $fname = 'cfg[' . htmlspecialchars($k) . ']'; ?>
                            <label>
                                <?php if ($type === 'checkbox'): ?>
                                    <span><input type="checkbox" name="<?= $fname ?>" value="1"<?= $val === '1' ? ' checked' : '' ?>> <?= htmlspecialchars((string) ($f['label'] ?? $k)) ?></span>
                                <?php else: ?>
                                    <span><?= htmlspecialchars((string) ($f['label'] ?? $k)) ?></span>
                                    <?php if ($type === 'select'): ?>
                                        <select name="<?= $fname ?>">
                                            <?php foreach ((array) ($f['options'] ?? []) as $ov => $ol): ?>
                                                <option value="<?= htmlspecialchars((string) $ov) ?>"<?= (string) $ov === $val ? ' selected' : '' ?>><?= htmlspecialchars((string) $ol) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($type === 'textarea'): ?>
                                        <textarea name="<?= $fname ?>" rows="4"><?= htmlspecialchars($val) ?></textarea>
                                    <?php else: ?>
                                        <input type="<?= in_array($type, ['password', 'url', 'number'], true) ? $type : 'text' ?>" name="<?= $fname ?>"<?= !empty($f['secret']) ? ' autocomplete="off"' : '' ?>
                                            value="<?= !empty($f['secret']) ? '' : htmlspecialchars($val) ?>"<?= !empty($f['secret']) && $val !== '' ? ' placeholder="•••••• zapisany (zostaw puste, by nie zmieniać)"' : '' ?>>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (!empty($f['help'])): ?><small><?= htmlspecialchars((string) $f['help']) ?></small><?php endif; ?>
                            </label>
                            <?php if (!empty($f['secret']) && $val !== ''): ?>
                                <label class="ext-clear"><span><input type="checkbox" name="cfg_clear[<?= htmlspecialchars($k) ?>]" value="1"> usuń zapisany: <?= htmlspecialchars((string) ($f['label'] ?? $k)) ?></span></label>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <div class="pl-dialog-foot">
                            <button class="btn secondary" type="button" data-close>Anuluj</button>
                            <button class="btn" type="submit">Zapisz ustawienia</button>
                        </div>
                    </form>
                <?php else: $accs = $accounts[$m->type] ?? []; ?>
                    <p class="pl-dialog-note">Wtyczka łączy się przez konta w Integracjach. Dane logowania, sklepy i opcje ustawiasz osobno dla każdego konta.</p>
                    <?php if ($accs === []): ?>
                        <p class="pl-dialog-note">Nie ma jeszcze żadnego konta.</p>
                    <?php else: ?>
                        <ul class="pl-accounts">
                            <?php foreach ($accs as $acc): ?>
                                <li>
                                    <span><strong><?= htmlspecialchars(trim((string) $acc['name']) !== '' ? (string) $acc['name'] : $m->name) ?></strong>
                                        <?= $acc['is_active'] ? '' : ' <span class="pill muted">wyłączone</span>' ?></span>
                                    <a class="btn secondary" href="integration_edit.php?id=<?= (int) $acc['id'] ?>">Ustawienia konta</a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <div class="pl-dialog-foot">
                        <button class="btn secondary" type="button" data-close>Zamknij</button>
                        <a class="btn" href="integration_edit.php?type=<?= htmlspecialchars($m->type) ?>">+ Dodaj konto</a>
                    </div>
                <?php endif; ?>
            </dialog>
            <?php $dialogs .= ob_get_clean(); ?>
        <?php endforeach; ?>
    </table>
    <p style="color:#888;font-size:12px;margin-top:10px">
        Wyłączenie wtyczki nie usuwa jej kont ani ustawień. Usunięcie wtyczki zostawia kopię w <code>storage/plugin_backups/</code>.
    </p>
</div>
<?= $dialogs ?>
<style>
    .pl-table td { vertical-align:middle; }
    .pl-perms { display:flex; flex-wrap:wrap; align-items:center; gap:4px; margin-top:6px; }
    .pl-perms-label { font-size:12px; color:var(--ink-2); margin-right:2px; }
    .pl-perm { display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; font-weight:600; background:var(--muted-bg); color:var(--muted-ink); cursor:default; }
    .pl-actions { text-align:right; white-space:nowrap; }
    .pl-toggle { min-width:84px; }
    .pl-icon { background:none; border:0; color:var(--ink-2); cursor:pointer; padding:6px; border-radius:6px; vertical-align:middle; line-height:0; }
    .pl-icon:hover { color:var(--ink); background:var(--muted-bg); }
    .pl-icon.pl-del:hover { color:#c0392b; background:#fdecea; }
    .pl-dialog { width:min(600px, calc(100vw - 32px)); max-height:calc(100vh - 48px); box-sizing:border-box; padding:20px 24px; border:1px solid var(--line); border-radius:16px; background:var(--surface); color:var(--ink); box-shadow:0 20px 60px rgba(0,0,0,.2); }
    .pl-dialog::backdrop { background:rgba(30,25,15,.45); }
    .pl-dialog-head { display:flex; align-items:center; gap:12px; margin-bottom:14px; }
    .pl-dialog-head h2 { margin:0; font-size:19px; }
    .pl-close { margin-left:auto; background:none; border:0; font-size:24px; line-height:1; cursor:pointer; color:var(--ink-2); padding:4px 8px; }
    .pl-dialog-note { color:var(--ink-2); font-size:13px; margin:0 0 12px; }
    .pl-dialog-foot { display:flex; justify-content:flex-end; gap:8px; margin-top:16px; }
    .pl-accounts { list-style:none; margin:0; padding:0; border-top:1px solid var(--line); }
    .pl-accounts li { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:10px 0; border-bottom:1px solid var(--line); font-size:14px; }
    .ext-cfg { display:grid; gap:10px; margin:0; }
    .ext-cfg label { display:grid; gap:4px; font-size:13px; }
    .ext-cfg label > span { font-weight:600; }
    .ext-cfg small { color:var(--ink-2); }
    .ext-cfg .ext-clear { margin-top:-6px; }
    .ext-cfg .ext-clear > span { font-weight:400; color:var(--ink-2); font-size:12px; }
    .ext-cfg .pl-dialog-foot { margin-top:6px; position:sticky; bottom:-20px; background:var(--surface); padding:12px 0 4px; border-top:1px solid var(--line); }
</style>
<script>
document.addEventListener('click', function (e) {
    var open = e.target.closest('[data-open]');
    if (open) { var d = document.getElementById(open.getAttribute('data-open')); if (d && d.showModal) { d.showModal(); } return; }
    var close = e.target.closest('[data-close]');
    if (close) { close.closest('dialog').close(); return; }
    if (e.target.tagName === 'DIALOG' && e.target.classList.contains('pl-dialog')) { e.target.close(); } // klik w tło
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
