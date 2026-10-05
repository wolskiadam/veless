<?php
declare(strict_types=1);

/**
 * Szablony OPISU oferty Allegro (Konfiguracja → Integracje → Allegro → Szablony opisu).
 *
 * Edytor sekcji jak w Allegro: dodajesz moduły TEKST i GRAFIKA. Sekcje zapisujemy
 * w formacie zgodnym z API Allegro (description.sections[].items[]). Szablon przypisujesz
 * do kategorii produktów - przy wystawianiu oferty opis wypełni się automatycznie.
 */

use Pase\Repository\AllegroDescTemplateRepository;
use Pase\Repository\ProductRepository;
use Pase\Services\AllegroOfferTags;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$repo = new AllegroDescTemplateRepository($pdo);
$allCategories = array_keys((new ProductRepository($pdo))->distinctCategories());
$flashOk = $flashErr = null;

/**
 * Parsuje sekcje z POST (pola tablicowe). Każda sekcja ma typ modułów i treść.
 * Tekst -> {items:[{type:TEXT, content}]}, Grafika -> {items:[{type:IMAGE, url}]}.
 * @return array<int,array<string,mixed>>
 */
function parseSections(array $post): array
{
    $types  = $post['sec_type'] ?? [];
    $texts  = $post['sec_text'] ?? [];
    $images = $post['sec_image'] ?? [];
    $out = [];
    foreach ($types as $i => $type) {
        if ($type === 'image') {
            $url = trim((string) ($images[$i] ?? ''));
            if ($url !== '') {
                $out[] = ['items' => [['type' => 'IMAGE', 'url' => $url]]];
            }
        } else { // text
            $content = trim((string) ($texts[$i] ?? ''));
            if ($content !== '') {
                $out[] = ['items' => [['type' => 'TEXT', 'content' => $content]]];
            }
        }
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $name = trim($_POST['name'] ?? '');
        $cats = array_values(array_filter(array_map(
            static fn($c) => trim((string) $c),
            (array) ($_POST['categories'] ?? [])
        )));
        $sections = parseSections($_POST);

        if ($name === '') {
            $flashErr = 'Podaj nazwę szablonu.';
        } elseif ($sections === []) {
            $flashErr = 'Dodaj przynajmniej jeden moduł (tekst lub grafikę).';
        } elseif ($action === 'create') {
            $repo->create($name, $cats, $sections);
            $flashOk = 'Dodano szablon opisu.';
        } else {
            $repo->update((int) $_POST['id'], $name, $cats, $sections);
            $flashOk = 'Zapisano szablon opisu.';
        }
    } elseif ($action === 'delete') {
        $repo->delete((int) $_POST['id']);
        $flashOk = 'Usunięto szablon.';
    }
}

$edit = isset($_GET['edit']) ? $repo->find((int) $_GET['edit']) : null;
$list = $repo->all();

// Sekcje edytowanego szablonu spłaszczone do formy edytora: [{type, text|url}].
$editSections = [];
foreach (($edit['sections'] ?? []) as $sec) {
    $item = $sec['items'][0] ?? null;
    if (!is_array($item)) {
        continue;
    }
    if (($item['type'] ?? '') === 'IMAGE') {
        $editSections[] = ['type' => 'image', 'url' => $item['url'] ?? ''];
    } else {
        $editSections[] = ['type' => 'text', 'text' => $item['content'] ?? ''];
    }
}

$PAGE_TITLE = 'Szablony opisu Allegro';
$PAGE_KEY   = 'integrations';
require __DIR__ . '/header.php';
?>

<p style="margin:-6px 0 16px"><a class="btn secondary" href="integrations.php">← Integracje</a></p>
<?php if ($flashOk): ?><div class="flash ok"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="flash err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

<div class="card">
    <strong><?= $edit ? 'Edytuj szablon opisu' : 'Nowy szablon opisu' ?></strong>
    <p style="color:#888;font-size:13px;margin:4px 0 14px">
        Buduj opis jak na Allegro — dodawaj moduły <strong>Tekst</strong> i <strong>Grafika</strong>.
        Każdy moduł to osobna sekcja (zgodnie z formatem Allegro).
    </p>

    <form method="post" id="tplForm">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>

        <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:end">
            <div style="flex:1;min-width:260px">
                <label class="ol">Nazwa szablonu</label>
                <input name="name" required value="<?= htmlspecialchars($edit['name'] ?? '') ?>" style="width:100%" placeholder="np. Świece dekoracyjne">
            </div>
        </div>

        <div style="margin-top:14px">
            <label class="ol">Kategorie produktów (puste = szablon domyślny)</label>
            <?php if ($allCategories === []): ?>
                <p style="color:#888;font-size:13px">Brak kategorii — zaimportuj produkty z Woo.</p>
            <?php else: $editCats = $edit['categories'] ?? []; ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:2px 14px;max-height:160px;overflow:auto;border:1px solid #e5e7eb;border-radius:8px;padding:10px">
                    <?php foreach ($allCategories as $cat): ?>
                        <label style="font-size:13px;display:flex;gap:6px;align-items:center;cursor:pointer">
                            <input type="checkbox" name="categories[]" value="<?= htmlspecialchars($cat) ?>" <?= in_array($cat, $editCats, true) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($cat) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div style="margin-top:16px;display:grid;grid-template-columns:200px 1fr 340px;gap:18px;align-items:start">
            <!-- TAGI: klikalne placeholdery z danymi produktu -->
            <div class="tag-panel">
                <div class="tag-title">Tagi produktu</div>
                <p style="font-size:11px;color:#888;margin:0 0 8px">Kliknij, aby wstawić w moduł tekstowy. Podstawią się dane produktu przy wystawianiu.</p>
                <?php foreach (AllegroOfferTags::catalog() as $tag => $desc): ?>
                    <button type="button" class="tag-chip" data-tag="[<?= htmlspecialchars($tag) ?>]" title="<?= htmlspecialchars($desc) ?>">[<?= htmlspecialchars($tag) ?>]</button>
                <?php endforeach; ?>
            </div>
            <!-- ŚRODEK: edytor modułów -->
            <div>
                <label class="ol">Moduły opisu</label>
                <div id="sections"></div>
                <div style="display:flex;gap:8px;margin-top:10px">
                    <button type="button" class="btn secondary" onclick="addSection('text')">＋ Moduł tekst</button>
                    <button type="button" class="btn secondary" onclick="addSection('image')">＋ Moduł grafika</button>
                </div>
            </div>
            <!-- PRAWA: podgląd -->
            <div>
                <label class="ol">Podgląd opisu</label>
                <div id="tplPreview" class="al-desc-prev"></div>
            </div>
        </div>

        <p style="margin-top:16px">
            <button class="btn" type="submit" style="background:#ff5a00"><?= $edit ? 'Zapisz szablon' : 'Dodaj szablon' ?></button>
            <?php if ($edit): ?><a class="btn secondary" href="allegro_templates.php">Anuluj</a><?php endif; ?>
        </p>
    </form>
</div>

<div class="card">
    <strong>Zdefiniowane szablony</strong>
    <table style="margin-top:10px">
        <tr><th>Nazwa</th><th>Kategorie</th><th>Moduły</th><th></th></tr>
        <?php if ($list === []): ?>
            <tr><td colspan="4" style="color:#888">Brak szablonów. Dodaj pierwszy powyżej.</td></tr>
        <?php endif; ?>
        <?php foreach ($list as $t): ?>
            <tr>
                <td><strong><?= htmlspecialchars($t['name']) ?></strong></td>
                <td style="font-size:12px;color:#555"><?= ($t['categories'] ?? []) === [] ? '<span style="color:#1a73e8">domyślny (wszystkie)</span>' : htmlspecialchars(implode(', ', $t['categories'])) ?></td>
                <td style="font-size:12px;color:#555"><?= count($t['sections'] ?? []) ?> sekcji</td>
                <td style="text-align:right;white-space:nowrap">
                    <a class="btn secondary" href="?edit=<?= (int)$t['id'] ?>">Edytuj</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('Usunąć szablon „<?= htmlspecialchars($t['name']) ?>”?')">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                        <button class="btn danger" type="submit">Usuń</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    <p style="color:#888;font-size:12px;margin-top:10px">Przy przygotowaniu oferty Allegro opis wypełni się automatycznie z szablonu pasującego do kategorii produktu (Magazyn → Produkt → Marketplace → Allegro).</p>
</div>

<style>
    .ol { font-size:12px; color:#888; display:block; margin-bottom:4px; }
    .sec { border:1px solid #e5e7eb; border-radius:8px; padding:10px; margin-bottom:8px; background:#fafbfc; }
    .sec-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; }
    .sec-type { font-size:12px; font-weight:700; color:#ff5a00; }
    .sec-move { background:#fff; border:1px solid #e5e7eb; border-radius:5px; width:26px; height:26px; cursor:pointer; }
    .sec textarea, .sec input[type=url] { width:100%; font-size:13px; }
    .al-desc-prev { border:1px solid #e5e7eb; border-radius:8px; padding:14px; background:#fff; min-height:200px; font-size:14px; line-height:1.6; color:#333; position:sticky; top:18px; max-height:520px; overflow:auto; }
    .tag-panel { background:#f7f8fa; border:1px solid #eef0f3; border-radius:8px; padding:12px; max-height:520px; overflow:auto; position:sticky; top:18px; }
    .tag-title { font-size:13px; font-weight:700; color:#2c3e50; margin-bottom:6px; }
    .tag-chip { display:block; width:100%; text-align:left; background:#fff; border:1px solid #e5e7eb; border-radius:6px;
        padding:5px 9px; margin-bottom:5px; font-size:12px; color:#1a73e8; font-family:monospace; cursor:pointer; }
    .tag-chip:hover { background:#eef4ff; border-color:#cfe0ff; }
    .al-desc-prev img { max-width:100%; border-radius:6px; margin:6px 0; }
</style>

<template id="secText">
    <div class="sec" data-type="text">
        <div class="sec-head">
            <span class="sec-type">📝 Tekst</span>
            <span>
                <button type="button" class="sec-move" onclick="moveSec(this,-1)" title="W górę">↑</button>
                <button type="button" class="sec-move" onclick="moveSec(this,1)" title="W dół">↓</button>
                <button type="button" class="sec-move" onclick="this.closest('.sec').remove();syncPreview()" title="Usuń" style="color:#c5221f">✕</button>
            </span>
        </div>
        <input type="hidden" name="sec_type[]" value="text">
        <textarea name="sec_text[]" rows="4" placeholder="Tekst (możesz użyć prostego HTML: <b>, <p>, <ul><li>...)" oninput="syncPreview()"></textarea>
    </div>
</template>

<template id="secImage">
    <div class="sec" data-type="image">
        <div class="sec-head">
            <span class="sec-type">🖼️ Grafika</span>
            <span>
                <button type="button" class="sec-move" onclick="moveSec(this,-1)" title="W górę">↑</button>
                <button type="button" class="sec-move" onclick="moveSec(this,1)" title="W dół">↓</button>
                <button type="button" class="sec-move" onclick="this.closest('.sec').remove();syncPreview()" title="Usuń" style="color:#c5221f">✕</button>
            </span>
        </div>
        <input type="hidden" name="sec_type[]" value="image">
        <input type="url" name="sec_image[]" placeholder="Adres URL obrazka (https://...)" oninput="syncPreview()">
    </div>
</template>

<script>
var SECTIONS = <?= json_encode($editSections, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function addSection(type, data) {
    var tpl = document.getElementById(type === 'image' ? 'secImage' : 'secText');
    var node = tpl.content.cloneNode(true);
    document.getElementById('sections').appendChild(node);
    var sec = document.getElementById('sections').lastElementChild;
    if (data) {
        if (type === 'image') sec.querySelector('[name="sec_image[]"]').value = data.url || '';
        else sec.querySelector('[name="sec_text[]"]').value = data.text || '';
    }
    syncPreview();
}

function moveSec(btn, dir) {
    var sec = btn.closest('.sec');
    if (dir < 0 && sec.previousElementSibling) sec.parentNode.insertBefore(sec, sec.previousElementSibling);
    if (dir > 0 && sec.nextElementSibling) sec.parentNode.insertBefore(sec.nextElementSibling, sec);
    syncPreview();
}

function syncPreview() {
    var prev = document.getElementById('tplPreview');
    var html = '';
    document.querySelectorAll('#sections .sec').forEach(function (sec) {
        if (sec.dataset.type === 'image') {
            var url = sec.querySelector('[name="sec_image[]"]').value.trim();
            if (url) html += '<img src="' + url.replace(/"/g, '&quot;') + '" alt="">';
        } else {
            var t = sec.querySelector('[name="sec_text[]"]').value.trim();
            if (t) html += '<div>' + t + '</div>';
        }
    });
    prev.innerHTML = html || '<span style="color:#aaa">Podgląd pojawi się tu po dodaniu modułów.</span>';
}

// ===== Wstawianie tagów do aktywnego modułu tekstowego =====
var lastTextarea = null;
document.addEventListener('focusin', function (e) {
    if (e.target.matches && e.target.matches('textarea[name="sec_text[]"]')) {
        lastTextarea = e.target;
    }
});
document.querySelectorAll('.tag-chip').forEach(function (chip) {
    chip.addEventListener('click', function () {
        var ta = lastTextarea || document.querySelector('textarea[name="sec_text[]"]');
        if (!ta) { alert('Najpierw dodaj moduł tekst, potem kliknij tag.'); return; }
        var s = ta.selectionStart != null ? ta.selectionStart : ta.value.length;
        var en = ta.selectionEnd != null ? ta.selectionEnd : ta.value.length;
        ta.value = ta.value.slice(0, s) + chip.dataset.tag + ta.value.slice(en);
        ta.focus();
        ta.selectionStart = ta.selectionEnd = s + chip.dataset.tag.length;
        lastTextarea = ta;
        syncPreview();
    });
});

// Odtworzenie zapisanych sekcji (edycja) albo jeden pusty moduł tekstowy na start.
if (SECTIONS.length) {
    SECTIONS.forEach(function (s) { addSection(s.type, s); });
} else {
    addSection('text');
}
syncPreview();
</script>

<?php require __DIR__ . '/footer.php'; ?>
