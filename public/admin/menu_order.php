<?php
declare(strict_types=1);

/**
 * Konfiguracja → Układ menu. Administrator układa menu po swojemu: zmienia kolejność
 * i nazwy zakładek, tworzy własne zakładki i podkategorie (pod-menu), przenosi dowolną
 * pozycję w dowolne miejsce i może ją ukryć. Układ jest wspólny dla wszystkich użytkowników
 * i nie zmienia uprawnień (ukrycie pozycji nie blokuje strony). Zapis: AdminMenu::sanitizeLayout.
 */

use Pase\Repository\SettingsRepository;
use Pase\Support\AdminMenu;

require __DIR__ . '/auth.php';
requireRole(['admin']);
/** @var PDO $pdo */

$settings = new SettingsRepository($pdo);
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// Pełne menu (jak widzi je administrator) - edytor pokazuje wszystkie pozycje.
$allGroups = AdminMenu::build(true, true);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrfCheck();
    if (($_POST['action'] ?? '') === 'reset') {
        $settings->setMany([AdminMenu::SETTING => '']);
        flash('Przywrócono domyślny układ menu.');
    } else {
        $posted = json_decode((string) ($_POST['layout'] ?? ''), true);
        if (!is_array($posted)) {
            flash('Nie udało się odczytać układu - odśwież stronę i spróbuj ponownie.', 'err');
        } else {
            $clean = AdminMenu::sanitizeLayout($posted, $allGroups);
            $settings->setMany([AdminMenu::SETTING => json_encode($clean, JSON_UNESCAPED_UNICODE)]);
            flash('Zapisano układ menu.');
        }
    }
    redirectAfterPost();
}

$groups = AdminMenu::applyLayout($allGroups, $settings->get(AdminMenu::SETTING), true);
// Stary format (sama kolejność) nie ma nazw - uzupełnij, żeby edytor miał co pokazać.
foreach ($groups as $gk => $g) {
    $groups[$gk] += ['name' => '', 'default' => $g['label']];
    foreach ($g['items'] as $i => $it) {
        if (isset($it['children'])) { $groups[$gk]['items'][$i] += ['name' => '', 'default' => $it['label']]; }
    }
}
$adminOnly = ['document_operations.php', 'plugins.php', 'menu_order.php', 'users.php', 'server_usage.php', 'backup.php', 'handover.php'];
$custom = static fn(string $k): bool => (bool) preg_match(AdminMenu::CUSTOM_KEY, $k);

// Jedna pozycja menu (strona).
$leafRow = static function (array $item) use ($e, $adminOnly): string {
    $key = AdminMenu::key($item);
    $tags = '';
    if (in_array($key, $adminOnly, true)) { $tags .= '<small>tylko admin</small>'; }
    if (in_array($key, ['order_history.php', 'email_log.php'], true)) { $tags .= '<small>admin i edytor</small>'; }
    $hide = in_array($key, AdminMenu::NOT_HIDEABLE, true) ? ''
        : '<label class="mo-hide" title="Ukryj w menu (strona nadal działa)"><input type="checkbox" class="mo-hidden"' . (!empty($item['hidden']) ? ' checked' : '') . '> ukryj</label>';
    return '<li class="mo-item' . (!empty($item['hidden']) ? ' is-hidden' : '') . '" data-type="item" data-key="' . $e($key) . '">'
        . '<div class="mo-row"><span class="mo-grip" title="Przeciągnij">⋮⋮</span>'
        . '<span class="mo-label">' . $e(html_entity_decode(strip_tags((string) $item['label']), ENT_QUOTES, 'UTF-8')) . $tags . '</span>'
        . $hide
        . '<select class="mo-move" aria-label="Przenieś do"><option value="">Przenieś do…</option></select>'
        . '<span class="mo-arrows"><button type="button" data-move="-1" aria-label="W górę">▲</button><button type="button" data-move="1" aria-label="W dół">▼</button></span>'
        . '</div></li>';
};

$PAGE_TITLE = 'Układ menu';
$PAGE_KEY   = 'menu_order';
require __DIR__ . '/header.php';
?>
<style>
    .mo-groups { display:flex; flex-direction:column; gap:12px; margin:0; padding:0; list-style:none; }
    .mo-group { border:1px solid var(--line); border-radius:var(--radius-sm); background:var(--surface-2); }
    .mo-head { display:flex; align-items:center; gap:8px; padding:10px 12px; font-weight:800; flex-wrap:wrap; }
    .mo-list { list-style:none; margin:0; padding:0 12px 12px 34px; display:flex; flex-direction:column; gap:4px; min-height:30px; }
    .mo-sub > .mo-list { padding:6px 0 4px 28px; min-height:34px; }
    .mo-sub > .mo-list:empty::after { content:'Przeciągnij tu pozycje albo użyj „Przenieś do…”'; display:block; padding:7px 10px; border:1px dashed var(--line); border-radius:7px; color:var(--ink-3); font-size:13px; font-weight:500; }
    .mo-group > .mo-list:empty::after { content:'Pusta zakładka - przeciągnij tu pozycje. Pusta nie pokaże się w menu.'; display:block; padding:7px 10px; border:1px dashed var(--line); border-radius:7px; color:var(--ink-3); font-size:13px; font-weight:500; }
    .mo-item > .mo-row { display:flex; align-items:center; gap:8px; padding:6px 10px; background:var(--surface); border:1px solid var(--line); border-radius:7px; font-size:14px; flex-wrap:wrap; }
    .mo-sub > .mo-row { background:var(--accent-soft); font-weight:700; }
    .mo-item.is-hidden > .mo-row .mo-label { opacity:.5; text-decoration:line-through; }
    .mo-grip { cursor:grab; color:var(--ink-3); user-select:none; font-size:16px; line-height:1; }
    .mo-label { flex:1; min-width:140px; }
    .mo-label small { color:var(--ink-3); font-weight:500; margin-left:6px; }
    .mo-name { flex:1; min-width:140px; max-width:320px; font:inherit; padding:4px 8px; border:1px solid var(--line); border-radius:6px; background:var(--surface); color:var(--ink); }
    .mo-tag { font-size:11px; font-weight:600; color:var(--ink-3); }
    .mo-hide { display:inline-flex; align-items:center; gap:4px; font-size:12px; color:var(--ink-2); cursor:pointer; }
    .mo-move { font:inherit; font-size:12px; padding:2px 4px; border:1px solid var(--line); border-radius:6px; background:var(--surface); color:var(--ink-2); max-width:170px; }
    .mo-arrows { display:flex; gap:2px; }
    .mo-arrows button, .mo-tool { background:none; border:1px solid transparent; border-radius:6px; cursor:pointer; color:var(--ink-2); padding:2px 7px; font-size:12px; font-family:inherit; }
    .mo-arrows button:hover, .mo-tool:hover { border-color:var(--line); color:var(--ink); background:var(--surface-2); }
    .mo-dragging { opacity:.45; }
    .mo-over > .mo-row, .mo-group.mo-over { outline:2px dashed var(--accent); outline-offset:2px; }
    .mo-bar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:16px; }
    .mo-hint { font-size:13px; color:var(--ink-2); margin:0 0 14px; }
    .mo-top { display:flex; gap:10px; margin-bottom:14px; flex-wrap:wrap; }
</style>

<div class="card">
    <p class="mo-hint">Ułóż menu po swojemu: przeciągnij pozycję za uchwyt ⋮⋮ (także do innej zakładki lub podkategorii), użyj „Przenieś do…” albo strzałek.
        Nazwę zakładki lub podkategorii zmienisz w polu obok - puste pole oznacza nazwę domyślną.
        „Ukryj” chowa pozycję z menu, ale strona nadal działa pod swoim adresem.
        Układ dotyczy wszystkich użytkowników i nie zmienia uprawnień (pozycje „tylko admin” nadal widzi tylko administrator).
        Nowe funkcje dodane w przyszłości pojawią się na swoim domyślnym miejscu.</p>

    <div class="mo-top">
        <button type="button" class="btn secondary" id="moAddGroup">+ Nowa zakładka</button>
    </div>

    <ul class="mo-groups" id="moGroups">
        <?php foreach ($groups as $gk => $group): $gk = (string) $gk; ?>
            <li class="mo-group" data-type="group" data-key="<?= $e($gk) ?>">
                <div class="mo-head">
                    <span class="mo-grip" title="Przeciągnij">⋮⋮</span>
                    <input class="mo-name" type="text" maxlength="40" value="<?= $e($group['name']) ?>" placeholder="<?= $e($custom($gk) ? 'Nazwa zakładki' : html_entity_decode(strip_tags((string) $group['default']), ENT_QUOTES, 'UTF-8')) ?>" aria-label="Nazwa zakładki">
                    <?php if ($custom($gk)): ?><span class="mo-tag">własna</span><?php endif; ?>
                    <button type="button" class="mo-tool" data-add-sub>+ Podkategoria</button>
                    <?php if ($custom($gk)): ?><button type="button" class="mo-tool" data-delete title="Usuń zakładkę">✕ Usuń</button><?php endif; ?>
                    <span class="mo-arrows"><button type="button" data-move="-1" aria-label="W górę">▲</button><button type="button" data-move="1" aria-label="W dół">▼</button></span>
                </div>
                <ul class="mo-list" data-accept="item sub">
                    <?php foreach ($group['items'] as $item): ?>
                        <?php if (isset($item['children'])): $sk = AdminMenu::key($item); ?>
                            <li class="mo-item mo-sub" data-type="sub" data-key="<?= $e($sk) ?>">
                                <div class="mo-row">
                                    <span class="mo-grip" title="Przeciągnij">⋮⋮</span>
                                    <span aria-hidden="true">📁</span>
                                    <input class="mo-name" type="text" maxlength="40" value="<?= $e($item['name']) ?>" placeholder="<?= $e($custom($sk) ? 'Nazwa podkategorii' : html_entity_decode(strip_tags((string) $item['default']), ENT_QUOTES, 'UTF-8')) ?>" aria-label="Nazwa podkategorii">
                                    <?php if ($custom($sk)): ?><span class="mo-tag">własna</span><?php endif; ?>
                                    <button type="button" class="mo-tool" data-delete title="Usuń podkategorię (pozycje zostaną w zakładce)">✕ Usuń</button>
                                    <select class="mo-move" aria-label="Przenieś do"><option value="">Przenieś do…</option></select>
                                    <span class="mo-arrows"><button type="button" data-move="-1" aria-label="W górę">▲</button><button type="button" data-move="1" aria-label="W dół">▼</button></span>
                                </div>
                                <ul class="mo-list" data-accept="item"><?php foreach ($item['children'] as $child) { echo $leafRow($child); } ?></ul>
                            </li>
                        <?php else: ?>
                            <?= $leafRow($item) ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="mo-bar">
        <form method="post" id="moForm">
            <input type="hidden" name="csrf" value="<?= $e(csrfToken()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="layout" id="moLayout">
            <button class="btn" type="submit">Zapisz układ</button>
        </form>
        <form method="post" id="moReset">
            <input type="hidden" name="csrf" value="<?= $e(csrfToken()) ?>">
            <input type="hidden" name="action" value="reset">
            <button class="btn secondary" type="submit">Przywróć domyślny</button>
        </form>
    </div>
</div>

<template id="moGroupTpl">
    <li class="mo-group" data-type="group">
        <div class="mo-head">
            <span class="mo-grip" title="Przeciągnij">⋮⋮</span>
            <input class="mo-name" type="text" maxlength="40" placeholder="Nazwa zakładki" aria-label="Nazwa zakładki">
            <span class="mo-tag">własna</span>
            <button type="button" class="mo-tool" data-add-sub>+ Podkategoria</button>
            <button type="button" class="mo-tool" data-delete title="Usuń zakładkę">✕ Usuń</button>
            <span class="mo-arrows"><button type="button" data-move="-1" aria-label="W górę">▲</button><button type="button" data-move="1" aria-label="W dół">▼</button></span>
        </div>
        <ul class="mo-list" data-accept="item sub"></ul>
    </li>
</template>
<template id="moSubTpl">
    <li class="mo-item mo-sub" data-type="sub">
        <div class="mo-row">
            <span class="mo-grip" title="Przeciągnij">⋮⋮</span>
            <span aria-hidden="true">📁</span>
            <input class="mo-name" type="text" maxlength="40" placeholder="Nazwa podkategorii" aria-label="Nazwa podkategorii">
            <span class="mo-tag">własna</span>
            <button type="button" class="mo-tool" data-delete title="Usuń podkategorię (pozycje zostaną w zakładce)">✕ Usuń</button>
            <select class="mo-move" aria-label="Przenieś do"><option value="">Przenieś do…</option></select>
            <span class="mo-arrows"><button type="button" data-move="-1" aria-label="W górę">▲</button><button type="button" data-move="1" aria-label="W dół">▼</button></span>
        </div>
        <ul class="mo-list" data-accept="item"></ul>
    </li>
</template>

<script<?= isset($cspNonce) ? ' nonce="' . $e($cspNonce) . '"' : '' ?>>
(function () {
    var root = document.getElementById('moGroups');
    var dragged = null;
    var newKey = function () { return 'custom_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7); };
    var accepts = function (ul, type) { return ul && (' ' + (ul.getAttribute('data-accept') || '') + ' ').indexOf(' ' + type + ' ') >= 0; };
    var nameOf = function (li) {
        var input = li.querySelector(':scope > .mo-head .mo-name, :scope > .mo-row .mo-name');
        return (input && (input.value.trim() || input.placeholder)) || '';
    };
    var clone = function (id) {
        var li = document.getElementById(id).content.firstElementChild.cloneNode(true);
        li.setAttribute('data-key', newKey());
        return li;
    };

    document.getElementById('moAddGroup').addEventListener('click', function () {
        var li = clone('moGroupTpl');
        root.appendChild(li);
        li.querySelector('.mo-name').focus();
    });

    root.addEventListener('click', function (ev) {
        var add = ev.target.closest('[data-add-sub]');
        if (add) {
            var sub = clone('moSubTpl');
            add.closest('.mo-group').querySelector(':scope > .mo-list').appendChild(sub);
            sub.querySelector('.mo-name').focus();
            return;
        }
        var del = ev.target.closest('[data-delete]');
        if (del) {
            var li = del.closest('li');
            if (li.getAttribute('data-type') === 'sub') {
                // Pozycje z podkategorii zostają w zakładce, w miejscu podkategorii.
                var list = li.querySelector(':scope > .mo-list');
                while (list.firstElementChild) { li.parentNode.insertBefore(list.firstElementChild, li); }
                li.remove();
            } else if (!li.querySelector('[data-type="item"]') || confirm('Usunąć zakładkę „' + nameOf(li) + '”? Pozycje z niej wrócą na swoje domyślne miejsca w menu.')) {
                li.remove();
            }
            return;
        }
        var btn = ev.target.closest('[data-move]');
        if (!btn) { return; }
        var row = btn.closest('li');
        if (btn.getAttribute('data-move') === '-1') {
            if (row.previousElementSibling) { row.parentNode.insertBefore(row, row.previousElementSibling); }
        } else if (row.nextElementSibling) {
            row.parentNode.insertBefore(row.nextElementSibling, row);
        }
    });

    root.addEventListener('change', function (ev) {
        if (ev.target.classList.contains('mo-hidden')) {
            ev.target.closest('li').classList.toggle('is-hidden', ev.target.checked);
        }
    });

    // „Przenieś do…” - lista zakładek (i podkategorii dla pozycji) budowana przy otwarciu; działa też na telefonie.
    var fillMove = function (sel) {
        var li = sel.closest('li');
        var type = li.getAttribute('data-type');
        while (sel.options.length > 1) { sel.remove(1); }
        root.querySelectorAll(':scope > .mo-group').forEach(function (g, gi) {
            var gname = nameOf(g);
            var opt = new Option(gname, 'g' + gi);
            opt.disabled = li.parentNode === g.querySelector(':scope > .mo-list');
            sel.add(opt);
            if (type !== 'item') { return; }
            g.querySelectorAll(':scope > .mo-list > [data-type="sub"]').forEach(function (s, si) {
                var o = new Option('  ' + gname + ' › ' + nameOf(s), 'g' + gi + 's' + si);
                o.disabled = li.parentNode === s.querySelector(':scope > .mo-list');
                sel.add(o);
            });
        });
    };
    root.addEventListener('focusin', function (ev) { if (ev.target.classList.contains('mo-move')) { fillMove(ev.target); } });
    root.addEventListener('mousedown', function (ev) { if (ev.target.classList.contains('mo-move')) { fillMove(ev.target); } });
    root.addEventListener('change', function (ev) {
        var sel = ev.target;
        if (!sel.classList.contains('mo-move') || !sel.value) { return; }
        var m = sel.value.match(/^g(\d+)(?:s(\d+))?$/);
        var g = root.querySelectorAll(':scope > .mo-group')[+m[1]];
        var target = g.querySelector(':scope > .mo-list');
        if (m[2] !== undefined) { target = target.querySelectorAll(':scope > [data-type="sub"]')[+m[2]].querySelector(':scope > .mo-list'); }
        var li = sel.closest('li');
        sel.value = '';
        target.appendChild(li);
        li.querySelector('.mo-row').scrollIntoView({ block: 'nearest' });
    });

    // Przeciąganie: zakładki między sobą, podkategorie do dowolnej zakładki, pozycje do dowolnej zakładki lub podkategorii.
    // Przeciąga się tylko za uchwyt ⋮⋮ - dzięki temu w polach nazw da się zaznaczać tekst.
    root.addEventListener('mousedown', function (ev) {
        var grip = ev.target.closest('.mo-grip');
        if (grip) { grip.closest('li').setAttribute('draggable', 'true'); }
    });
    root.addEventListener('mouseup', function () {
        root.querySelectorAll('[draggable]').forEach(function (x) { x.removeAttribute('draggable'); });
    });
    root.addEventListener('dragstart', function (ev) {
        dragged = ev.target.closest('li');
        if (!dragged) { return; }
        ev.stopPropagation();
        dragged.classList.add('mo-dragging');
        ev.dataTransfer.effectAllowed = 'move';
        try { ev.dataTransfer.setData('text/plain', dragged.getAttribute('data-key') || ''); } catch (e) {}
    });
    root.addEventListener('dragend', function () {
        if (dragged) { dragged.classList.remove('mo-dragging'); }
        root.querySelectorAll('[draggable]').forEach(function (x) { x.removeAttribute('draggable'); });
        root.querySelectorAll('.mo-over').forEach(function (x) { x.classList.remove('mo-over'); });
        dragged = null;
    });
    root.addEventListener('dragover', function (ev) {
        if (!dragged) { return; }
        var type = dragged.getAttribute('data-type');
        var list, target;
        if (type === 'group') {
            list = root;
            target = ev.target.closest('.mo-group');
        } else {
            var head = ev.target.closest('.mo-head');
            list = head ? head.parentNode.querySelector(':scope > .mo-list') : ev.target.closest('ul');
            while (list && !accepts(list, type)) { list = list.parentNode.closest('ul'); }
            if (!list || dragged.contains(list)) { return; }
            target = head ? null : ev.target.closest('li');
            while (target && target.parentNode !== list) { target = target.parentNode.closest('li'); }
        }
        ev.preventDefault();
        root.querySelectorAll('.mo-over').forEach(function (x) { x.classList.remove('mo-over'); });
        if (target === dragged) { return; }
        if (!target) {
            if (dragged.parentNode !== list) { list.appendChild(dragged); }
            return;
        }
        target.classList.add('mo-over');
        var rect = (target.querySelector(':scope > .mo-row, :scope > .mo-head') || target).getBoundingClientRect();
        var after = ev.clientY > rect.top + rect.height / 2;
        var ref = after ? target.nextElementSibling : target;
        if (ref !== dragged && dragged.nextElementSibling !== ref) { list.insertBefore(dragged, ref); }
    });
    root.addEventListener('drop', function (ev) { if (dragged) { ev.preventDefault(); } });

    document.getElementById('moReset').addEventListener('submit', function (ev) {
        if (!confirm('Przywrócić domyślny układ menu? Własne zakładki i podkategorie zostaną usunięte.')) { ev.preventDefault(); }
    });

    // Zapis: zakładki → pozycje / podkategorie → pozycje, plus lista ukrytych.
    document.getElementById('moForm').addEventListener('submit', function () {
        var val = function (li) { var i = li.querySelector(':scope > .mo-head .mo-name, :scope > .mo-row .mo-name'); return i ? i.value.trim() : ''; };
        var leaves = function (ul) {
            return Array.prototype.filter.call(ul.children, function (li) { return li.getAttribute('data-type') === 'item'; })
                .map(function (li) { return li.getAttribute('data-key'); });
        };
        var layout = { v: 2, groups: [], hidden: [] };
        root.querySelectorAll(':scope > .mo-group').forEach(function (g) {
            var items = [];
            Array.prototype.forEach.call(g.querySelector(':scope > .mo-list').children, function (li) {
                if (li.getAttribute('data-type') === 'sub') {
                    items.push({ key: li.getAttribute('data-key'), name: val(li), children: leaves(li.querySelector(':scope > .mo-list')) });
                } else {
                    items.push(li.getAttribute('data-key'));
                }
            });
            layout.groups.push({ key: g.getAttribute('data-key'), name: val(g), items: items });
        });
        root.querySelectorAll('.mo-hidden:checked').forEach(function (cb) { layout.hidden.push(cb.closest('li').getAttribute('data-key')); });
        document.getElementById('moLayout').value = JSON.stringify(layout);
    });
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
