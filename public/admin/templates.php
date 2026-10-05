<?php
declare(strict_types=1);

/**
 * Hub „Szablony" (Konfiguracja → Szablony) — szablony opisu ofert MARKETPLACE z wtyczek.
 * Szablony e-mail mają osobną zakładkę (Konfiguracja → Szablony e-mail) i NIE są tutaj.
 *
 * Rozszerzalny: każda wtyczka implementująca Pase\Plugin\Contract\ProvidesTemplates
 * sama pojawia się tutaj (deklaruje nazwę + link).
 */

use Pase\Plugin\Contract\ProvidesTemplates;
use Pase\Plugin\PluginRegistry;
use Pase\Repository\AllegroDescTemplateRepository;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

// Liczniki szablonów (best-effort) - per znana strona zarządzania.
$counts = [];
try { $counts['allegro_templates.php'] = count((new AllegroDescTemplateRepository($pdo))->all()); } catch (\Throwable $e) {}

// Zbierz pozycje z wtyczek (marketplace) deklarujących ProvidesTemplates.
// Szablony e-mail mają osobną zakładkę (Konfiguracja → Szablony e-mail) - tu ich nie ma.
$groups = []; // [ 'Allegro' => [ pozycje ] ]
foreach (PluginRegistry::all() as $plugin) {
    if (!$plugin instanceof ProvidesTemplates) {
        continue;
    }
    try {
        $manifest = $plugin->manifest();
        $items = $plugin->templates();
    } catch (\Throwable $e) {
        continue;
    }
    if ($items === []) {
        continue;
    }
    $groups[$manifest->name] = ['icon' => $manifest->icon, 'color' => $manifest->color, 'items' => $items];
}

ksort($groups);

$PAGE_TITLE = 'Szablony';
$PAGE_KEY   = 'templates';
require __DIR__ . '/header.php';
?>

<div class="card">
    <strong>Szablony marketplace</strong>
    <p style="color:#888;font-size:13px;margin:6px 0 0">
        Szablony opisu ofert dla kanałów sprzedaży (z wtyczek). Nowa wtyczka marketplace, która
        udostępnia szablony, pojawi się tu automatycznie.
        <?php /* Szablony e-mail mają osobną zakładkę. */ ?>
    </p>
</div>

<?php if ($groups === []): ?>
    <div class="card"><p style="color:#888">Brak szablonów marketplace. Pojawią się po dodaniu wtyczki, która je udostępnia (np. Allegro).</p></div>
<?php endif; ?>

<?php foreach ($groups as $groupName => $g): ?>
    <div class="card">
        <strong style="font-size:15px"><?= htmlspecialchars($g['icon']) ?> <?= htmlspecialchars($groupName) ?></strong>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;margin-top:12px">
            <?php foreach ($g['items'] as $it): $cnt = $counts[$it['url']] ?? null; ?>
                <a href="<?= htmlspecialchars($it['url']) ?>" class="tpl-card">
                    <div class="tpl-head">
                        <span class="tpl-name"><?= htmlspecialchars(($it['icon'] ?? '') . ' ' . $it['name']) ?></span>
                        <?php if ($cnt !== null): ?><span class="tpl-count"><?= (int)$cnt ?></span><?php endif; ?>
                    </div>
                    <?php if (!empty($it['desc'])): ?>
                        <div class="tpl-desc"><?= htmlspecialchars($it['desc']) ?></div>
                    <?php endif; ?>
                    <span class="tpl-go" style="color:<?= htmlspecialchars($g['color']) ?>">Zarządzaj →</span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>

<style>
    .tpl-card { display:flex; flex-direction:column; gap:8px; border:1px solid #e5e7eb; border-radius:10px;
        padding:14px 16px; text-decoration:none; color:var(--ink); transition:.15s; background:#fff; }
    .tpl-card:hover { border-color:#cfd8ee; box-shadow:0 4px 14px rgba(16,24,40,.06); }
    .tpl-head { display:flex; justify-content:space-between; align-items:center; gap:8px; }
    .tpl-name { font-weight:600; font-size:14px; }
    .tpl-count { background:#eef0f6; color:#555; font-size:12px; font-weight:700; border-radius:999px; padding:1px 8px; }
    .tpl-desc { font-size:12px; color:#888; line-height:1.5; }
    .tpl-go { font-size:12px; font-weight:600; margin-top:auto; }
</style>

<?php require __DIR__ . '/footer.php'; ?>
