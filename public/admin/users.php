<?php
declare(strict_types=1);

/**
 * Zarządzanie kontami panelu (System → Użytkownicy). Tylko dla administratora.
 * Tabela admin_users jest tworzona w bootstrap_admin.php (require z auth.php).
 */

use Pase\Support\PagePermissions;

require __DIR__ . '/auth.php';
requireRole(['admin']);
/** @var PDO $pdo */

$roles = ['admin', 'editor', 'viewer'];
$flashOk = $flashErr = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['action'] ?? '';

    if ($act === 'create') {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $display  = trim($_POST['display_name'] ?? '');
        $role     = in_array($_POST['role'] ?? '', $roles, true) ? $_POST['role'] : 'viewer';
        $password = (string) ($_POST['password'] ?? '');
        $totpRequired = ($_POST['totp_required'] ?? '') === '1' ? 1 : 0;

        if ($username === '' || $password === '') {
            $flashErr = 'Podaj nazwę użytkownika i hasło.';
        } elseif (strlen($password) < 8) {
            $flashErr = 'Hasło musi mieć co najmniej 8 znaków.';
        } else {
            $exists = $pdo->prepare('SELECT COUNT(*) FROM admin_users WHERE username = ?');
            $exists->execute([$username]);
            if ((int) $exists->fetchColumn() > 0) {
                $flashErr = 'Taka nazwa użytkownika już istnieje.';
            } else {
                $pdo->prepare(
                    'INSERT INTO admin_users (username, email, password_hash, display_name, role, is_active, totp_required)
                     VALUES (?, ?, ?, ?, ?, 1, ?)'
                )->execute([$username, $email !== '' ? $email : null, password_hash($password, PASSWORD_DEFAULT), $display !== '' ? $display : null, $role, $totpRequired]);
                // Bez pól uprawnień w żądaniu konto działa wg roli (nic nie odbieramy po cichu).
                if ($role !== 'admin' && is_array($_POST['perms'] ?? null)) {
                    PagePermissions::save($pdo, (int) $pdo->lastInsertId(), (array) ($_POST['perms'] ?? []));
                }
                $flashOk = 'Dodano użytkownika „' . $username . '”.';
            }
        }
    } elseif ($act === 'update') {
        $id      = (int) ($_POST['id'] ?? 0);
        $email   = trim($_POST['email'] ?? '');
        $display = trim($_POST['display_name'] ?? '');
        $role    = in_array($_POST['role'] ?? '', $roles, true) ? $_POST['role'] : 'viewer';
        $active  = isset($_POST['is_active']) ? 1 : 0;
        $newPass = (string) ($_POST['password'] ?? '');
        $totpRequired = ($_POST['totp_required'] ?? '') === '1' ? 1 : 0;

        if ($id === currentUserId() && ($role !== 'admin' || $active === 0)) {
            // Zabezpieczenie przed zablokowaniem samego siebie z panelu.
            $flashErr = 'Nie możesz odebrać samemu sobie roli administratora ani się dezaktywować.';
        } elseif ($newPass !== '' && strlen($newPass) < 8) {
            $flashErr = 'Nowe hasło musi mieć co najmniej 8 znaków.';
        } else {
            if ($newPass !== '') {
                $pdo->prepare('UPDATE admin_users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?')
                    ->execute([password_hash($newPass, PASSWORD_DEFAULT), $id]);
            }
            $pdo->prepare('UPDATE admin_users SET email = ?, display_name = ?, role = ?, is_active = ?, totp_required = ?, session_version = session_version + 1 WHERE id = ?')
                ->execute([$email !== '' ? $email : null, $display !== '' ? $display : null, $role, $active, $totpRequired, $id]);
            // Uprawnienia do stron: admin ma pełny dostęp (czyścimy), pozostali - zapis z formularza.
            if ($role === 'admin') {
                PagePermissions::clear($pdo, $id);
            } elseif (is_array($_POST['perms'] ?? null)) {
                PagePermissions::save($pdo, $id, $_POST['perms']);
            }
            $flashOk = 'Zapisano zmiany.';
        }
    } elseif ($act === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === currentUserId()) {
            $flashErr = 'Nie możesz usunąć własnego konta.';
        } else {
            $pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
            PagePermissions::clear($pdo, $id);
            $flashOk = 'Usunięto użytkownika.';
        }
    }

    // PRG: bez tego F5 po zapisie próbowałoby powtórzyć operację (np. dodać
    // użytkownika drugi raz). Przy błędzie zostajemy w tym samym kontekście
    // (np. ?edit=5), po udanej operacji wracamy na czystą listę.
    if ($flashErr !== null) {
        flash($flashErr, 'err');
        redirectAfterPost();
    }
    if ($flashOk !== null) {
        flash($flashOk);
    }
    redirectAfterPost('users.php');
}

$users = $pdo->query('SELECT * FROM admin_users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

$editId   = (int) ($_GET['edit'] ?? 0);
$editUser = null;
if ($editId) {
    foreach ($users as $u) {
        if ((int) $u['id'] === $editId) {
            $editUser = $u;
            break;
        }
    }
}

$roleLabels = ['admin' => 'Administrator', 'editor' => 'Edytor', 'viewer' => 'Podgląd'];
$rolePill   = ['admin' => 'ok', 'editor' => 'warn', 'viewer' => 'muted'];

/**
 * Siatka uprawnień do stron (jak w sds-generator): Ukryte / Podgląd / Edycja dla każdej strony,
 * pogrupowana wg menu, z szybkim ustawieniem całej grupy albo wszystkiego.
 * @param array<string,string> $values
 */
$permsGrid = static function (array $values, string $idPrefix): void {
    $groups = [];
    foreach (PagePermissions::registry() as $key => $page) { $groups[$page['group']][$key] = $page; }
    $names = ['hidden' => 'Ukryte', 'view' => 'Podgląd', 'edit' => 'Edycja'];
    ?>
    <div class="perms-grid" data-perms-grid>
        <div class="perms-head">
            <strong>Uprawnienia do stron</strong>
            <span class="perms-quick">Ustaw wszystko:
                <?php foreach ($names as $lv => $nm): ?><button type="button" data-set-all="<?= $lv ?>"><?= $nm ?></button><?php endforeach; ?>
                <span class="perms-sep">|</span>
                <button type="button" data-set-role title="Takie uprawnienia, jakie dawała sama rola">Domyślne dla roli</button>
            </span>
        </div>
        <?php foreach ($groups as $group => $pages): ?>
            <div class="perms-group" data-perms-group>
                <div class="perms-group-head">
                    <span><?= htmlspecialchars($group) ?></span>
                    <span class="perms-quick"><?php foreach ($names as $lv => $nm): ?><button type="button" data-set-group="<?= $lv ?>"><?= $nm ?></button><?php endforeach; ?></span>
                </div>
                <?php foreach ($pages as $key => $page): $cur = $values[$key] ?? 'hidden'; ?>
                    <div class="perms-row">
                        <span class="perms-label"><?= htmlspecialchars($page['label']) ?></span>
                        <span class="perms-radios">
                            <?php foreach ($names as $lv => $nm): ?>
                                <label><input type="radio" name="perms[<?= htmlspecialchars($key) ?>]" value="<?= $lv ?>" <?= $cur === $lv ? 'checked' : '' ?>> <?= $nm ?></label>
                            <?php endforeach; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <div class="perms-note">Podgląd - strona się otwiera, ale zapisywanie jest zablokowane. Strony ukryte znikają z menu.
            Administrator ma zawsze pełny dostęp - dla roli Admin uprawnienia nie mają zastosowania.
            Zarządzanie użytkownikami pozostaje tylko dla administratora.</div>
    </div>
    <?php
};
$roleDefaultsJson = json_encode(['editor' => PagePermissions::roleDefaults('editor'), 'viewer' => PagePermissions::roleDefaults('viewer')]);

$PAGE_TITLE = 'Użytkownicy';
$PAGE_KEY   = 'users';
require __DIR__ . '/header.php';
?>

<?php // Komunikaty renderuje header.php (flash()/flashTake() w auth.php). ?>

<?php if ($editUser): ?>
<div class="card">
    <strong>Edytuj użytkownika: <?= htmlspecialchars($editUser['username']) ?></strong>
    <form method="post" style="margin-top:14px">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= (int) $editUser['id'] ?>">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px">
            <div>
                <label style="font-size:12px;color:var(--ink-2);display:block">Nazwa wyświetlana</label>
                <input name="display_name" value="<?= htmlspecialchars($editUser['display_name'] ?? '') ?>" style="width:100%">
            </div>
            <div>
                <label style="font-size:12px;color:var(--ink-2);display:block">E-mail</label>
                <input name="email" type="email" value="<?= htmlspecialchars($editUser['email'] ?? '') ?>" style="width:100%">
            </div>
            <div>
                <label style="font-size:12px;color:var(--ink-2);display:block">Rola</label>
                <select name="role" style="width:100%">
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= $r ?>" <?= $editUser['role'] === $r ? 'selected' : '' ?>><?= htmlspecialchars($roleLabels[$r]) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:12px;color:var(--ink-2);display:block">Nowe hasło (zostaw puste, aby nie zmieniać)</label>
                <input name="password" type="password" placeholder="min. 8 znaków" style="width:100%">
            </div>
        </div>
        <label style="display:flex;gap:8px;align-items:center;font-size:14px;color:var(--ink);cursor:pointer;margin-top:14px">
            <input type="checkbox" name="is_active" value="1" <?= $editUser['is_active'] ? 'checked' : '' ?>>
            Konto aktywne
        </label>
        <label style="display:flex;gap:8px;align-items:center;margin-top:14px">
            <input type="checkbox" name="totp_required" value="1" <?= !empty($editUser['totp_required']) ? 'checked' : '' ?>>
            Wymagaj 2FA
        </label>
        <p style="font-size:13px;color:var(--ink-2)">Użytkownik sam skonfiguruje aplikację przed wejściem do pozostałych części CRM. Zapis unieważnia jego dotychczasowe sesje. Odznaczenie nie wyłącza już aktywnego 2FA ani globalnego wymogu dla administratorów.</p>
        <?php
            $editPerms = PagePermissions::load($pdo, (int) $editUser['id']);
            if ($editPerms === null && $editUser['role'] !== 'admin'): ?>
            <p class="perms-legacy">To konto działa teraz według roli „<?= htmlspecialchars($roleLabels[$editUser['role']] ?? $editUser['role']) ?>”. Poniżej zaznaczono dokładnie to, co ta rola daje - po zapisie obowiązują te ustawienia.</p>
        <?php endif; ?>
        <div data-perms-wrap>
            <?php $permsGrid($editPerms ?? PagePermissions::roleDefaults($editUser['role']), 'e'); ?>
        </div>
        <p style="margin-top:16px;display:flex;gap:10px;align-items:center">
            <button class="btn" type="submit">Zapisz</button>
            <a class="btn secondary" href="users.php">Anuluj</a>
        </p>
    </form>

    <?php if ((int) $editUser['id'] !== currentUserId()): ?>
        <form method="post" style="margin-top:10px;padding-top:14px;border-top:1px solid var(--line)"
              onsubmit="return confirm('Usunąć użytkownika „<?= htmlspecialchars($editUser['username'], ENT_QUOTES) ?>”? Tej operacji nie można cofnąć.');">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $editUser['id'] ?>">
            <button class="btn danger" type="submit">Usuń użytkownika</button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <strong>Dodaj użytkownika</strong>
    <form method="post" style="margin-top:14px">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="create">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px">
            <div>
                <label style="font-size:12px;color:var(--ink-2);display:block">Nazwa użytkownika</label>
                <input name="username" required style="width:100%">
            </div>
            <div>
                <label style="font-size:12px;color:var(--ink-2);display:block">Nazwa wyświetlana</label>
                <input name="display_name" style="width:100%">
            </div>
            <div>
                <label style="font-size:12px;color:var(--ink-2);display:block">E-mail</label>
                <input name="email" type="email" style="width:100%">
            </div>
            <div>
                <label style="font-size:12px;color:var(--ink-2);display:block">Hasło</label>
                <input name="password" type="password" required placeholder="min. 8 znaków" style="width:100%">
            </div>
            <div>
                <label style="font-size:12px;color:var(--ink-2);display:block">Rola</label>
                <select name="role" style="width:100%">
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= $r ?>" <?= $r === 'viewer' ? 'selected' : '' ?>><?= htmlspecialchars($roleLabels[$r]) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <label style="display:flex;gap:8px;align-items:center;margin-top:14px">
            <input type="checkbox" name="totp_required" value="1"> Wymagaj 2FA
        </label>
        <div data-perms-wrap>
            <?php $permsGrid(PagePermissions::roleDefaults('viewer'), 'n'); ?>
        </div>
        <p style="margin-top:16px"><button class="btn" type="submit">Dodaj</button></p>
    </form>
</div>

<div class="card">
    <strong>Konta (<?= count($users) ?>)</strong>
    <table style="margin-top:12px">
        <tr>
            <th>Użytkownik</th><th>E-mail</th><th>Rola</th><th>Dostęp do stron</th><th>Status</th><th>2FA</th><th>Ostatnie logowanie</th><th></th>
        </tr>
        <?php foreach ($users as $u): ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($u['username']) ?></strong>
                    <?php if (!empty($u['display_name'])): ?><div style="font-size:12px;color:var(--ink-2)"><?= htmlspecialchars($u['display_name']) ?></div><?php endif; ?>
                </td>
                <td style="font-size:13px;color:var(--ink-2)"><?= htmlspecialchars($u['email'] ?? '—') ?></td>
                <td><span class="pill <?= $rolePill[$u['role']] ?? 'muted' ?>"><?= htmlspecialchars($roleLabels[$u['role']] ?? $u['role']) ?></span></td>
                <td style="font-size:12px;color:var(--ink-2)">
                    <?php if ($u['role'] === 'admin'): ?>Pełny
                    <?php else: $up = PagePermissions::load($pdo, (int) $u['id']); ?>
                        <?php if ($up === null): ?>Wg roli
                        <?php else: $cnt = array_count_values($up); ?>Edycja: <?= (int) ($cnt['edit'] ?? 0) ?> · Podgląd: <?= (int) ($cnt['view'] ?? 0) ?> · Ukryte: <?= (int) ($cnt['hidden'] ?? 0) ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td><span class="pill <?= $u['is_active'] ? 'ok' : 'bad' ?>"><?= $u['is_active'] ? 'Aktywny' : 'Nieaktywny' ?></span></td>
                <td><span class="pill <?= !empty($u['totp_secret']) ? 'ok' : 'muted' ?>"><?= !empty($u['totp_secret']) ? 'Włączone' : 'Wyłączone' ?></span>
                    <?php if (\Pase\Services\TwoFactorService::required($u)): ?><small>Wymagane</small><?php endif; ?>
                </td>
                <td style="font-size:12px;font-family:var(--font-num);color:var(--ink-2)"><?= htmlspecialchars($u['last_login'] ?? '—') ?></td>
                <td style="text-align:right"><a class="btn secondary" href="users.php?edit=<?= (int) $u['id'] ?>">Edytuj</a></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>

<style>
    .perms-grid { margin-top:16px; border:1px solid var(--line); border-radius:var(--radius-sm); overflow:hidden; }
    .perms-head { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; padding:10px 14px; background:var(--surface-2); border-bottom:1px solid var(--line); }
    .perms-group-head { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:8px 14px; font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:var(--ink-2); background:var(--surface-2); border-top:1px solid var(--line); }
    .perms-quick { display:inline-flex; gap:4px; align-items:center; font-size:12px; font-weight:500; color:var(--ink-2); text-transform:none; letter-spacing:0; flex-wrap:wrap; }
    .perms-quick button { background:var(--surface); border:1px solid var(--line); border-radius:6px; padding:2px 8px; font-size:12px; cursor:pointer; color:var(--ink-2); }
    .perms-quick button:hover { border-color:var(--accent); color:var(--accent-ink); }
    .perms-sep { color:var(--ink-3); }
    .perms-row { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:7px 14px; border-top:1px solid var(--line); font-size:14px; }
    .perms-row:nth-child(even) { background:var(--surface-2); }
    .perms-radios { display:flex; gap:14px; flex-shrink:0; }
    .perms-radios label { display:inline-flex; gap:5px; align-items:center; font-size:13px; color:var(--ink-2); cursor:pointer; }
    .perms-radios input { accent-color:var(--accent); margin:0; }
    .perms-note { padding:10px 14px; font-size:12.5px; color:var(--ink-2); border-top:1px solid var(--line); background:var(--surface-2); }
    .perms-legacy { background:var(--warn-bg); color:var(--warn-ink); border-radius:var(--radius-sm); padding:10px 12px; font-size:13px; margin:14px 0 0; }
    [data-perms-wrap].is-admin .perms-grid { display:none; }
    @media (max-width:640px) { .perms-row { flex-direction:column; align-items:flex-start; } }
</style>
<script<?= isset($cspNonce) ? ' nonce="' . htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
(function () {
    var roleDefaults = <?= $roleDefaultsJson ?>;
    function setLevel(scope, level) {
        scope.querySelectorAll('input[type=radio][value="' + level + '"]').forEach(function (r) { r.checked = true; });
    }
    document.querySelectorAll('form').forEach(function (form) {
        var wrap = form.querySelector('[data-perms-wrap]');
        if (!wrap) { return; }
        var role = form.querySelector('select[name=role]');
        function applyRoleDefaults() {
            var d = roleDefaults[role.value];
            if (!d) { return; }
            Object.keys(d).forEach(function (k) {
                var r = wrap.querySelector('input[name="perms[' + k + ']"][value="' + d[k] + '"]');
                if (r) { r.checked = true; }
            });
        }
        // Admin: siatka zbędna (pełny dostęp) - chowamy.
        function syncAdmin() { wrap.classList.toggle('is-admin', role.value === 'admin'); }
        role.addEventListener('change', function () {
            syncAdmin();
            // Przy dodawaniu zmiana roli podpowiada jej domyślne uprawnienia.
            if (form.querySelector('input[name=action][value=create]')) { applyRoleDefaults(); }
        });
        syncAdmin();
        wrap.addEventListener('click', function (ev) {
            var b = ev.target.closest('button');
            if (!b) { return; }
            if (b.hasAttribute('data-set-all')) { setLevel(wrap, b.getAttribute('data-set-all')); }
            if (b.hasAttribute('data-set-group')) { setLevel(b.closest('[data-perms-group]'), b.getAttribute('data-set-group')); }
            if (b.hasAttribute('data-set-role')) { applyRoleDefaults(); }
        });
    });
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
