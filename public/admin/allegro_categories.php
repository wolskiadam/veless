<?php
declare(strict_types=1);

/**
 * Kategorie i parametry Allegro — przeglądarka drzewa kategorii (GET /sale/categories)
 * z podglądem parametrów wybranej kategorii (GET /sale/categories/{id}/parameters).
 * Pomaga dobrać właściwą kategorię i jej wymagane parametry przed wystawieniem oferty.
 */

use PasePlugin\Allegro\AllegroPlugin;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$config = require PASE_ROOT . '/config/config.php';
$client = AllegroPlugin::makeClient($pdo, $config['allegro'] ?? []);

$parent     = isset($_GET['cat']) ? trim((string) $_GET['cat']) : null;
$categories = $client->categories($parent);
$params     = ($parent !== null && $parent !== '') ? $client->categoryParameters($parent) : [];

$e = static fn($v) => htmlspecialchars((string) $v);
$PAGE_TITLE = 'Kategorie i parametry Allegro';
$PAGE_KEY   = 'allegro';
require __DIR__ . '/header.php';
?>

<div class="card">
    <strong>📂 Kategorie i parametry</strong>
    <p style="color:#888;font-size:12px;margin:4px 0 14px">
        Przeglądaj drzewo kategorii Allegro i podejrzyj wymagane parametry. Kliknij kategorię, aby wejść głębiej.
    </p>

    <?php if ($parent !== null && $parent !== ''): ?>
        <p><a class="btn secondary" href="allegro_categories.php">⬆ Kategorie główne</a></p>
    <?php endif; ?>

    <?php if ($categories === [] && $params === []): ?>
        <p style="color:#888">Brak danych lub brak połączenia z Allegro. <a href="allegro_connect.php">Połącz konto →</a></p>
    <?php endif; ?>

    <div class="al-cat-layout">
        <div>
            <h4 style="margin:0 0 8px;font-size:13px;color:#888">Podkategorie</h4>
            <?php if ($categories === []): ?>
                <p style="color:#aaa;font-size:13px">Brak podkategorii (kategoria liściowa).</p>
            <?php else: ?>
                <ul class="al-cat-list">
                    <?php foreach ($categories as $c): $id = (string) ($c['id'] ?? ''); ?>
                        <li>
                            <a href="allegro_categories.php?cat=<?= urlencode($id) ?>">
                                <?= $e($c['name'] ?? '(bez nazwy)') ?>
                                <?php if (!empty($c['leaf'])): ?><span class="al-leaf">liść</span><?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div>
            <h4 style="margin:0 0 8px;font-size:13px;color:#888">Parametry wybranej kategorii</h4>
            <?php if ($parent === null || $parent === ''): ?>
                <p style="color:#aaa;font-size:13px">Wybierz kategorię, aby zobaczyć jej parametry.</p>
            <?php elseif ($params === []): ?>
                <p style="color:#aaa;font-size:13px">Ta kategoria nie ma parametrów (lub jeszcze nie jest liściem).</p>
            <?php else: ?>
                <table class="al-table">
                    <thead><tr><th>Parametr</th><th>Typ</th><th>Wymagany</th></tr></thead>
                    <tbody>
                    <?php foreach ($params as $p): ?>
                        <tr>
                            <td style="font-weight:600"><?= $e($p['name'] ?? '') ?></td>
                            <td style="color:#666;font-size:12px"><?= $e($p['type'] ?? '') ?></td>
                            <td><?= !empty($p['required']) ? '<span class="al-req">tak</span>' : '<span style="color:#aaa">nie</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
    .al-cat-layout { display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-top:8px; }
    .al-cat-list { list-style:none; padding:0; margin:0; }
    .al-cat-list li a { display:block; padding:9px 12px; border-radius:7px; text-decoration:none; color:#2c3e50; border:1px solid #eef0f3; margin-bottom:5px; font-size:14px; }
    .al-cat-list li a:hover { background:#fff4ef; border-color:#ffd0bb; }
    .al-leaf { font-size:10px; color:#0a8537; background:#e6f4ea; padding:1px 6px; border-radius:8px; margin-left:6px; }
    .al-table { width:100%; border-collapse:collapse; }
    .al-table th { text-align:left; font-size:11px; color:#888; text-transform:uppercase; padding:6px 8px; border-bottom:2px solid #eef0f3; }
    .al-table td { padding:8px; border-bottom:1px solid #f1f3f5; font-size:14px; }
    .al-req { font-size:11px; font-weight:700; color:#c5221f; background:#fce8e6; padding:1px 8px; border-radius:8px; }
    @media (max-width:900px) { .al-cat-layout { grid-template-columns:1fr; } }
</style>

<?php require __DIR__ . '/footer.php'; ?>
