<?php
declare(strict_types=1);

/**
 * Konfiguracja → Kolejność menu. Administrator ustala kolejność grup w pasku,
 * pozycji w każdej grupie i pozycji pod-menu Allegro (przeciąganie albo strzałki).
 * Kolejność jest wspólna dla wszystkich użytkowników; uprawnień nie zmienia.
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
        flash('Przywrócono domyślną kolejność menu.');
    } else {
        $posted = json_decode((string) ($_POST['order'] ?? ''), true);
        if (!is_array($posted)) {
            flash('Nie udało się odczytać kolejności - odśwież stronę i spróbuj ponownie.', 'err');
        } else {
            $clean = AdminMenu::sanitizeOrder($posted, $allGroups);
            $settings->setMany([AdminMenu::SETTING => json_encode($clean, JSON_UNESCAPED_UNICODE)]);
            flash('Zapisano kolejność menu.');
        }
    }
    redirectAfterPost();
}

$groups = AdminMenu::applyOrder($allGroups, $settings->get(AdminMenu::SETTING));
$adminOnly = ['document_operations.php', 'plugins.php', 'menu_order.php', 'users.php', 'server_usage.php', 'backup.php', 'handover.php'];

$PAGE_TITLE = 'Kolejność menu';
$PAGE_KEY   = 'menu_order';
require __DIR__ . '/header.php';
?>
<style>
    .mo-groups { display:flex; flex-direction:column; gap:12px; margin:0; padding:0; list-style:none; }
    .mo-group { border:1px solid var(--line); border-radius:var(--radius-sm); background:var(--surface-2); }
    .mo-head { display:flex; align-items:center; gap:10px; padding:10px 12px; font-weight:800; }
    .mo-list { list-style:none; margin:0; padding:0 12px 12px 34px; display:flex; flex-direction:column; gap:4px; }
    .mo-list .mo-list { padding:6px 0 4px 28px; }
    .mo-item > .mo-row { display:flex; align-items:center; gap:10px; padding:7px 10px; background:var(--surface); border:1px solid var(--line); border-radius:7px; font-size:14px; }
    .mo-grip { cursor:grab; color:var(--ink-3); user-select:none; font-size:16px; line-height:1; }
    .mo-label { flex:1; min-width:0; }
    .mo-label small { color:var(--ink-3); font-weight:500; margin-left:6px; }
    .mo-arrows { display:flex; gap:2px; }
    .mo-arrows button { background:none; border:1px solid transparent; border-radius:6px; cursor:pointer; color:var(--ink-2); padding:2px 7px; font-size:12px; }
    .mo-arrows button:hover { border-color:var(--line); color:var(--ink); background:var(--surface-2); }
    .mo-dragging { opacity:.45; }
    .mo-over > .mo-row, .mo-group.mo-over { outline:2px dashed var(--accent); outline-offset:2px; }
    .mo-bar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:16px; }
    .mo-hint { font-size:13px; color:var(--ink-2); margin:0 0 14px; }
</style>

<div class="card">
    <p class="mo-hint">Przeciągnij pozycję za uchwyt ⋮⋮ albo użyj strzałek. Kolejność grup ustala pasek menu, kolejność pozycji - rozwijane listy.
        Zmiana dotyczy wszystkich użytkowników i nie zmienia uprawnień (pozycje oznaczone „tylko admin” nadal widzi tylko administrator).
        Nowe pozycje dodane w przyszłości pojawią się na końcu swojej grupy.</p>

    <ul class="mo-groups" id="moGroups">
        <?php foreach ($groups as $gk => $group): ?>
            <li class="mo-group" draggable="true" data-key="<?= $e($gk) ?>">
                <div class="mo-head">
                    <span class="mo-grip" title="Przeciągnij">⋮⋮</span>
                    <span class="mo-label"><?= $e($group['label']) ?></span>
                    <span class="mo-arrows"><button type="button" data-move="-1" aria-label="W górę">▲</button><button type="button" data-move="1" aria-label="W dół">▼</button></span>
                </div>
                <ul class="mo-list" data-list="<?= $e($gk) ?>">
                    <?php foreach ($group['items'] as $item): $key = AdminMenu::key($item); ?>
                        <li class="mo-item" draggable="true" data-key="<?= $e($key) ?>">
                            <div class="mo-row">
                                <span class="mo-grip" title="Przeciągnij">⋮⋮</span>
                                <span class="mo-label"><?= $e($item['label']) ?><?php if (in_array($key, $adminOnly, true)): ?><small>tylko admin</small><?php endif; ?><?php if (in_array($key, ['order_history.php', 'email_log.php'], true)): ?><small>admin i edytor</small><?php endif; ?></span>
                                <span class="mo-arrows"><button type="button" data-move="-1" aria-label="W górę">▲</button><button type="button" data-move="1" aria-label="W dół">▼</button></span>
                            </div>
                            <?php if (!empty($item['children'])): ?>
                                <ul class="mo-list" data-list="<?= $e($key) ?>">
                                    <?php foreach ($item['children'] as $child): ?>
                                        <li class="mo-item" draggable="true" data-key="<?= $e(AdminMenu::key($child)) ?>">
                                            <div class="mo-row">
                                                <span class="mo-grip" title="Przeciągnij">⋮⋮</span>
                                                <span class="mo-label"><?= $e($child['label']) ?></span>
                                                <span class="mo-arrows"><button type="button" data-move="-1" aria-label="W górę">▲</button><button type="button" data-move="1" aria-label="W dół">▼</button></span>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="mo-bar">
        <form method="post" id="moForm">
            <input type="hidden" name="csrf" value="<?= $e(csrfToken()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="order" id="moOrder">
            <button class="btn" type="submit">Zapisz kolejność</button>
        </form>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= $e(csrfToken()) ?>">
            <input type="hidden" name="action" value="reset">
            <button class="btn secondary" type="submit">Przywróć domyślną</button>
        </form>
    </div>
</div>

<script<?= isset($cspNonce) ? ' nonce="' . $e($cspNonce) . '"' : '' ?>>
(function () {
    var root = document.getElementById('moGroups');
    var dragged = null;

    // Strzałki: przesuwają element w obrębie jego własnej listy.
    root.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-move]');
        if (!btn) { return; }
        var li = btn.closest('li');
        if (btn.getAttribute('data-move') === '-1') {
            if (li.previousElementSibling) { li.parentNode.insertBefore(li, li.previousElementSibling); }
        } else if (li.nextElementSibling) {
            li.parentNode.insertBefore(li.nextElementSibling, li);
        }
    });

    // Przeciąganie: tylko w obrębie tej samej listy (pozycje nie przechodzą między grupami).
    root.addEventListener('dragstart', function (ev) {
        dragged = ev.target.closest('li');
        ev.stopPropagation();
        dragged.classList.add('mo-dragging');
        ev.dataTransfer.effectAllowed = 'move';
        try { ev.dataTransfer.setData('text/plain', dragged.getAttribute('data-key')); } catch (e) {}
    });
    root.addEventListener('dragend', function () {
        if (dragged) { dragged.classList.remove('mo-dragging'); }
        root.querySelectorAll('.mo-over').forEach(function (x) { x.classList.remove('mo-over'); });
        dragged = null;
    });
    root.addEventListener('dragover', function (ev) {
        if (!dragged) { return; }
        var target = ev.target.closest('li');
        while (target && target.parentNode !== dragged.parentNode) { target = target.parentNode.closest('li'); }
        if (!target || target === dragged) { return; }
        ev.preventDefault();
        root.querySelectorAll('.mo-over').forEach(function (x) { x.classList.remove('mo-over'); });
        target.classList.add('mo-over');
        var rect = target.getBoundingClientRect();
        var after = ev.clientY > rect.top + Math.min(rect.height, 40) / 2;
        target.parentNode.insertBefore(dragged, after ? target.nextElementSibling : target);
    });
    root.addEventListener('drop', function (ev) { if (dragged) { ev.preventDefault(); } });

    // Zapis: kolejność grup + kolejność każdej listy (grupy i pod-menu).
    document.getElementById('moForm').addEventListener('submit', function () {
        var keys = function (list) { return Array.prototype.map.call(list.children, function (li) { return li.getAttribute('data-key'); }); };
        var order = { groups: keys(root), items: {} };
        root.querySelectorAll('ul[data-list]').forEach(function (ul) { order.items[ul.getAttribute('data-list')] = keys(ul); });
        document.getElementById('moOrder').value = JSON.stringify(order);
    });
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
