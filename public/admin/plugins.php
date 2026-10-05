<?php
declare(strict_types=1);

/**
 * Wtyczki (Konfiguracja → Wtyczki). Tylko administrator.
 * Dwa rodzaje: integracje (integrations/ - połączenia ze sklepami, kurierami, księgowością)
 * i rozszerzenia (extensions/ - nowe funkcje w samym CRM, przez haki Pase\Plugin\Hooks).
 * Lista zainstalowanych + wgrywanie ZIP + włącz/wyłącz/usuń + ustawienia rozszerzeń.
 * Zakładki dokumentacji pokazują integrations/README.md i extensions/README.md.
 *
 * UWAGA: instalacja wtyczki wykonuje obcy kod PHP z pełnymi prawami aplikacji.
 * Brak sandboxa na shared hostingu - instaluj tylko wtyczki z zaufanego źródła.
 */

use Pase\Plugin\Capability;
use Pase\Plugin\ExtensionRegistry;
use Pase\Plugin\ExtensionSettings;
use Pase\Plugin\PluginInstaller;
use Pase\Plugin\PluginRegistry;
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
            ExtensionSettings::save($pdo, $found, (array) ($_POST['cfg'] ?? []));
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

<div class="card">
    <strong>Integracje (<?= count($installed) ?>)</strong>
    <p style="color:#888;font-size:12px;margin:4px 0 0">Połączenia z innymi systemami: sklepy, marketplace, kurierzy, księgowość, SMS.</p>
    <table style="margin-top:10px">
        <tr><th>Wtyczka</th><th>Typ</th><th>Wersja</th><th>Zdolności</th><th>Status</th><th></th></tr>
        <?php if ($installed === []): ?>
            <tr><td colspan="6" style="color:#888">Brak wtyczek. Wgraj ZIP powyżej lub dodaj katalog do <code>integrations/</code> przez FTP.</td></tr>
        <?php endif; ?>
        <?php foreach ($installed as $p): $m = $p['manifest']; ?>
            <tr<?= $p['error'] ? ' style="background:#fdf3f3"' : '' ?>>
                <td>
                    <strong><?= htmlspecialchars($m?->name ?? $p['slug']) ?></strong>
                    <div style="font-size:12px;color:#888"><code><?= htmlspecialchars($p['slug']) ?></code><?php if ($m && $m->author !== ''): ?> · <?= htmlspecialchars($m->author) ?><?php endif; ?></div>
                    <?php if ($p['error']): ?><div style="font-size:12px;color:#c0392b">Błąd: <?= htmlspecialchars($p['error']) ?></div><?php endif; ?>
                </td>
                <td><?= $m ? '<code>' . htmlspecialchars($m->type) . '</code>' : '—' ?></td>
                <td><?= htmlspecialchars($m?->version ?? '—') ?></td>
                <td style="font-size:12px;color:#555">
                    <?php foreach (($m?->capabilities ?? []) as $cap): ?>
                        <span class="pill muted" style="margin:1px"><?= htmlspecialchars(Capability::label($cap)) ?></span>
                    <?php endforeach; ?>
                    <?= ($m && $m->capabilities === []) ? '—' : '' ?>
                </td>
                <td><?= $p['enabled'] ? '<span class="pill ok">włączona</span>' : '<span class="pill muted">wyłączona</span>' ?></td>
                <td style="text-align:right;white-space:nowrap">
                    <form method="post" style="display:inline">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="kind" value="integration">
                        <input type="hidden" name="slug" value="<?= htmlspecialchars($p['slug']) ?>">
                        <input type="hidden" name="to" value="<?= $p['enabled'] ? '0' : '1' ?>">
                        <button class="btn secondary" type="submit"><?= $p['enabled'] ? 'Wyłącz' : 'Włącz' ?></button>
                    </form>
                    <form method="post" style="display:inline" onsubmit="return confirm('Usunąć wtyczkę „<?= htmlspecialchars($p['slug']) ?>”? Kopia zapasowa zostanie zachowana.')">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="uninstall">
                        <input type="hidden" name="kind" value="integration">
                        <input type="hidden" name="slug" value="<?= htmlspecialchars($p['slug']) ?>">
                        <button class="btn danger" type="submit">Usuń</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    <p style="color:#888;font-size:12px;margin-top:10px">
        Wyłączenie wtyczki nie usuwa jej kont integracji — tylko przestaje być aktywna. Usunięcie zostawia kopię w <code>storage/plugin_backups/</code>.
    </p>
</div>

<div class="card">
    <strong>Rozszerzenia (<?= count($extensions) ?>)</strong>
    <p style="color:#888;font-size:12px;margin:4px 0 0">Nowe funkcje w samym CRM: kolumny listy zamówień, sekcje karty zamówienia, pakowanie, automatyzacje, statystyki, własne strony.</p>
    <table style="margin-top:10px">
        <tr><th>Rozszerzenie</th><th>Wersja</th><th>Opis</th><th>Status</th><th></th></tr>
        <?php if ($extensions === []): ?>
            <tr><td colspan="5" style="color:#888">Brak rozszerzeń. Wgraj ZIP powyżej lub dodaj katalog do <code>extensions/</code> przez FTP.</td></tr>
        <?php endif; ?>
        <?php foreach ($extensions as $p): $m = $p['manifest']; ?>
            <tr<?= $p['error'] ? ' style="background:#fdf3f3"' : '' ?>>
                <td>
                    <strong><?= htmlspecialchars(($m ? $m->icon . ' ' . $m->name : $p['slug'])) ?></strong>
                    <div style="font-size:12px;color:#888"><code><?= htmlspecialchars($p['slug']) ?></code><?php if ($m && $m->author !== ''): ?> · <?= htmlspecialchars($m->author) ?><?php endif; ?></div>
                    <?php if ($p['error']): ?><div style="font-size:12px;color:#c0392b">Błąd: <?= htmlspecialchars($p['error']) ?></div><?php endif; ?>
                </td>
                <td><?= htmlspecialchars($m?->version ?? '—') ?></td>
                <td style="font-size:13px;color:#555"><?= htmlspecialchars($m?->description ?: '—') ?></td>
                <td><?= $p['enabled'] ? '<span class="pill ok">włączone</span>' : '<span class="pill muted">wyłączone</span>' ?></td>
                <td style="text-align:right;white-space:nowrap">
                    <form method="post" style="display:inline">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="kind" value="extension">
                        <input type="hidden" name="slug" value="<?= htmlspecialchars($p['slug']) ?>">
                        <input type="hidden" name="to" value="<?= $p['enabled'] ? '0' : '1' ?>">
                        <button class="btn secondary" type="submit"><?= $p['enabled'] ? 'Wyłącz' : 'Włącz' ?></button>
                    </form>
                    <form method="post" style="display:inline" onsubmit="return confirm('Usunąć rozszerzenie „<?= htmlspecialchars($p['slug']) ?>”? Kopia zapasowa zostanie zachowana.')">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="uninstall">
                        <input type="hidden" name="kind" value="extension">
                        <input type="hidden" name="slug" value="<?= htmlspecialchars($p['slug']) ?>">
                        <button class="btn danger" type="submit">Usuń</button>
                    </form>
                </td>
            </tr>
            <?php if ($m && $m->fields !== []): $vals = ExtensionSettings::get($m->type, $pdo); ?>
            <tr><td colspan="5" style="background:var(--surface-2)">
                <details>
                    <summary style="cursor:pointer;font-weight:600;font-size:13px">⚙ Ustawienia</summary>
                    <form method="post" class="ext-cfg">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="ext_settings">
                        <input type="hidden" name="slug" value="<?= htmlspecialchars($p['slug']) ?>">
                        <?php foreach ($m->fields as $f): $k = (string) ($f['key'] ?? ''); if ($k === '') { continue; }
                            $type = (string) ($f['type'] ?? 'text'); $val = (string) ($vals[$k] ?? ($f['default'] ?? ''));
                            $name = 'cfg[' . htmlspecialchars($k) . ']'; ?>
                            <label>
                                <?php if ($type === 'checkbox'): ?>
                                    <span><input type="checkbox" name="<?= $name ?>" value="1"<?= $val === '1' ? ' checked' : '' ?>> <?= htmlspecialchars((string) ($f['label'] ?? $k)) ?></span>
                                <?php else: ?>
                                    <span><?= htmlspecialchars((string) ($f['label'] ?? $k)) ?></span>
                                    <?php if ($type === 'select'): ?>
                                        <select name="<?= $name ?>">
                                            <?php foreach ((array) ($f['options'] ?? []) as $ov => $ol): ?>
                                                <option value="<?= htmlspecialchars((string) $ov) ?>"<?= (string) $ov === $val ? ' selected' : '' ?>><?= htmlspecialchars((string) $ol) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($type === 'textarea'): ?>
                                        <textarea name="<?= $name ?>" rows="4"><?= htmlspecialchars($val) ?></textarea>
                                    <?php else: ?>
                                        <input type="<?= in_array($type, ['password', 'url', 'number'], true) ? $type : 'text' ?>" name="<?= $name ?>"
                                            value="<?= !empty($f['secret']) ? '' : htmlspecialchars($val) ?>"<?= !empty($f['secret']) && $val !== '' ? ' placeholder="•••••• (zostaw puste, by nie zmieniać)"' : '' ?>>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (!empty($f['help'])): ?><small><?= htmlspecialchars((string) $f['help']) ?></small><?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                        <button class="btn" type="submit">Zapisz ustawienia</button>
                    </form>
                </details>
            </td></tr>
            <?php endif; ?>
        <?php endforeach; ?>
    </table>
    <style>
        .ext-cfg { display:grid; gap:10px; max-width:560px; margin:10px 0 4px; }
        .ext-cfg label { display:grid; gap:4px; font-size:13px; }
        .ext-cfg label > span { font-weight:600; }
        .ext-cfg small { color:var(--ink-2); }
        .ext-cfg .btn { justify-self:start; }
    </style>
</div>

<?php require __DIR__ . '/footer.php'; ?>
