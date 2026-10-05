<?php
declare(strict_types=1);

/**
 * Integracje - model multi-integracji (jak w Base): lista wszystkich integracji
 * (także kilka tego samego typu) + dodawanie + ustawienia per wiersz.
 * Edycja konkretnej integracji: integration_edit.php.
 */

use Pase\Repository\IntegrationAccountRepository;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$repo = new IntegrationAccountRepository($pdo);
$flashOk = null;

// Usuwanie / przełączanie aktywności.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'delete' && $id) {
        $repo->delete($id);
        $flashOk = 'Usunięto integrację.';
    }
}

$all = $repo->all();

$typeLabels = ['woocommerce' => 'WooCommerce', 'wfirma' => 'wFirma', 'allegro' => 'Allegro', 'blpaczka' => 'BLPaczka'];
$typeBadge  = ['woocommerce' => '#7f54b3', 'wfirma' => '#2aa9e0', 'allegro' => '#ff5a00', 'blpaczka' => '#16a34a'];
// Pozostałe wtyczki: nazwa i kolor z manifestu.
foreach (\Pase\Plugin\PluginRegistry::manifests() as $mf) {
    $typeLabels[$mf->type] ??= $mf->name;
    $typeBadge[$mf->type]  ??= $mf->color;
}

// --- Dostępne typy integracji do GALERII (kafelki pogrupowane w kategorie) ---
// Źródło: manifesty zainstalowanych wtyczek + statyczne (wFirma - jeszcze nie wtyczka).
$catalog = []; // [ katKey => [ {type,name,icon,color,desc}, ... ] ]
foreach (\Pase\Plugin\PluginRegistry::manifests() as $m) {
    $catalog[$m->categoryKey()][] = [
        'type' => $m->type, 'name' => $m->name, 'icon' => $m->icon,
        'color' => $m->color, 'desc' => $m->description,
    ];
}
// wFirma - jeszcze nie wtyczka, ale dodawalna (księgowość).
$catalog[\Pase\Plugin\Capability::CAT_ACCOUNTING][] = [
    'type' => 'wfirma', 'name' => 'wFirma', 'icon' => '📄', 'color' => '#2aa9e0',
    'desc' => 'Faktury i paragony.',
];
$catLabels = \Pase\Plugin\Capability::categoryLabels();

$PAGE_TITLE = 'Integracje';
$PAGE_KEY   = 'integrations';
require __DIR__ . '/header.php';
?>

<?php if ($flashOk): ?><div class="flash ok"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center">
        <strong>Podłączone integracje</strong>
        <button class="btn" type="button" onclick="document.getElementById('intPicker').style.display='flex'">+ Dodaj integrację</button>
    </div>

    <?php $flows = new \Pase\Services\IntegrationFlows($pdo); ?>
    <div class="it-wrap">
    <table class="it-table">
        <tr><th>Typ</th><th>Nazwa</th><th>Pobierane dane</th><th>Wysyłane dane</th><th></th></tr>
        <?php if ($all === []): ?>
            <tr><td colspan="5" style="color:#888">Brak integracji. Dodaj pierwszą przyciskiem „Dodaj integrację”.</td></tr>
        <?php endif; ?>
        <?php foreach ($all as $i): $fl = $flows->forAccount($i); ?>
            <tr class="<?= $i['is_active'] ? '' : 'it-off' ?>">
                <td>
                    <span class="it-type" style="background:<?= htmlspecialchars($typeBadge[$i['type']] ?? '#888') ?>">
                        <?= htmlspecialchars($typeLabels[$i['type']] ?? $i['type']) ?>
                    </span>
                </td>
                <td>
                    <strong><?= htmlspecialchars($i['name']) ?></strong>
                    <?php if (!$i['is_active']): ?><div><span class="pill muted">wyłączona</span></div><?php endif; ?>
                </td>
                <?php foreach (['in', 'out'] as $dir): ?>
                <td>
                    <div class="it-flows">
                        <?php foreach ($fl[$dir] as $f): ?>
                            <a class="it-flow <?= $f['on'] ? 'on' : 'off' ?>" href="<?= htmlspecialchars($f['link']) ?>" title="<?= htmlspecialchars($f['hint']) ?>"><?php if (!empty($f['push']) && $f['on']): ?><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2 4 14h7l-1 8 9-12h-7l1-8z"/></svg><?php endif; ?><?= htmlspecialchars($f['label']) ?></a>
                        <?php endforeach; ?>
                        <?php if ($fl[$dir] === []): ?><span class="it-none">—</span><?php endif; ?>
                    </div>
                </td>
                <?php endforeach; ?>
                <td style="text-align:right;white-space:nowrap">
                    <a class="btn secondary" href="integration_edit.php?id=<?= (int)$i['id'] ?>">Ustawienia</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('Usunąć integrację „<?= htmlspecialchars($i['name']) ?>”? Zamówienia pozostaną, ale stracą powiązanie.')">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                        <button class="it-del" type="submit" title="Usuń integrację" aria-label="Usuń integrację">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <p class="it-legend"><span class="it-flow on">włączone</span> <span class="it-flow off">wyłączone</span>
        W nawiasie: jak często działa. Błyskawica — na bieżąco (webhook). Najedź na etykietę, żeby zobaczyć opis; kliknij, żeby przejść do ustawień.</p>
</div>

<p style="color:#888;font-size:12px">
    Możesz dodać wiele integracji, także kilka tego samego typu (np. kilka sklepów WooCommerce).
    Każdy sklep ma własne klucze API i własny adres webhooka.
</p>

<!-- ===== Galeria wyboru integracji (modal) ===== -->
<div id="intPicker" class="ip-overlay" style="display:none">
    <div class="ip-modal">
        <div class="ip-head">
            <strong style="font-size:18px">Nowa integracja</strong>
            <button type="button" class="ip-close" onclick="document.getElementById('intPicker').style.display='none'">×</button>
        </div>
        <div class="ip-search">
            <input type="text" id="ipSearch" placeholder="🔍 Wyszukaj integrację…">
        </div>
        <div class="ip-body" id="ipBody">
            <?php foreach ($catLabels as $catKey => $catLabel): $tiles = $catalog[$catKey] ?? []; if ($tiles === []) continue; ?>
                <div class="ip-cat" data-cat>
                    <div class="ip-cat-name"><?= htmlspecialchars($catLabel) ?> (<?= count($tiles) ?>)</div>
                    <div class="ip-grid">
                        <?php foreach ($tiles as $t): ?>
                            <a href="integration_edit.php?type=<?= htmlspecialchars($t['type']) ?>" class="ip-tile"
                               data-name="<?= htmlspecialchars(mb_strtolower($t['name'] . ' ' . $catLabel)) ?>"
                               title="<?= htmlspecialchars($t['desc']) ?>">
                                <span class="ip-icon" style="color:<?= htmlspecialchars($t['color']) ?>"><?= $t['icon'] ?></span>
                                <span class="ip-name"><?= htmlspecialchars($t['name']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <p id="ipEmpty" class="ip-empty" style="display:none">Brak wyników.</p>
        </div>
    </div>
</div>

<style>
    .it-wrap { overflow-x:auto; margin-top:14px; }
    .it-table td { vertical-align:middle; }
    .it-table tr.it-off td { opacity:.75; }
    .it-type { display:inline-block; color:#fff; font-size:13px; font-weight:600; padding:5px 10px; border-radius:6px; white-space:nowrap; }
    .it-flows { display:flex; flex-wrap:wrap; gap:6px; max-width:440px; }
    .it-flow { display:inline-flex; align-items:center; gap:4px; font-size:12px; font-weight:600; padding:4px 8px; border-radius:5px; text-decoration:none; white-space:nowrap; line-height:1.2; }
    .it-flow.on { background:#2f6f3e; color:#fff; }
    .it-flow.off { background:#c9cdd3; color:#fff; }
    a.it-flow:hover { filter:brightness(1.12); }
    .it-none { color:#bbb; }
    .it-del { background:none; border:0; color:#8a8f98; cursor:pointer; padding:6px; border-radius:6px; vertical-align:middle; }
    .it-del:hover { color:#c0392b; background:#fdecea; }
    .it-legend { color:#888; font-size:12px; margin:12px 0 0; display:flex; flex-wrap:wrap; align-items:center; gap:6px; }
    .ip-overlay { position:fixed; inset:0; background:rgba(20,24,33,.5); z-index:2000; align-items:flex-start; justify-content:center; padding:40px 16px; overflow:auto; }
    .ip-modal { background:#fff; width:100%; max-width:980px; border-radius:14px; box-shadow:0 20px 60px rgba(0,0,0,.3); overflow:hidden; }
    .ip-head { display:flex; justify-content:space-between; align-items:center; padding:18px 22px; border-bottom:1px solid #eef0f3; }
    .ip-close { background:none; border:0; font-size:26px; line-height:1; color:#888; cursor:pointer; }
    .ip-close:hover { color:#222; }
    .ip-search { padding:18px 22px 6px; }
    .ip-search input { width:100%; padding:12px 16px; border:1px solid #d4d9e0; border-radius:10px; font-size:15px; }
    .ip-body { padding:10px 22px 24px; max-height:62vh; overflow:auto; }
    .ip-cat-name { font-size:15px; font-weight:700; color:#2c3e50; margin:18px 0 10px; }
    .ip-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:12px; }
    .ip-tile { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8px;
        height:110px; border:1px solid #e5e7eb; border-radius:12px; text-decoration:none; color:#2c3e50; transition:.15s; background:#fff; }
    .ip-tile:hover { border-color:#667eea; box-shadow:0 6px 18px rgba(16,24,40,.1); transform:translateY(-2px); }
    .ip-icon { font-size:34px; line-height:1; }
    .ip-name { font-size:13px; font-weight:600; text-align:center; padding:0 6px; }
    .ip-empty { color:#9aa3af; text-align:center; padding:24px; }
</style>
<script>
(function () {
    var overlay = document.getElementById('intPicker');
    // Zamknij klikiem w tło lub Esc.
    overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.style.display = 'none'; });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') overlay.style.display = 'none'; });

    var input = document.getElementById('ipSearch');
    var tiles = Array.prototype.slice.call(document.querySelectorAll('.ip-tile'));
    var cats  = Array.prototype.slice.call(document.querySelectorAll('#ipBody [data-cat]'));
    var empty = document.getElementById('ipEmpty');
    input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase();
        var any = false;
        tiles.forEach(function (t) {
            var ok = q === '' || (t.getAttribute('data-name') || '').indexOf(q) !== -1;
            t.style.display = ok ? '' : 'none';
            if (ok) any = true;
        });
        cats.forEach(function (c) {
            var visible = c.querySelectorAll('.ip-tile:not([style*="display: none"])').length;
            c.style.display = visible ? '' : 'none';
        });
        empty.style.display = any ? 'none' : 'block';
    });
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
