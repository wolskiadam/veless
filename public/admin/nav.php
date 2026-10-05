<?php
declare(strict_types=1);

/**
 * Globalny pasek nawigacji PASE - wygląd 1:1 z sds-generator (ciemny gradient,
 * zgrupowane menu z dropdownami, obszar użytkownika z rolą i "Wyloguj").
 * Menu dopasowane do funkcji middleware.
 */

// Definicja pozycji menu: Pase\Support\AdminMenu (wspólna z edytorem kolejności).
// Konto ze szczegółowymi uprawnieniami: pełne menu przefiltrowane wg uprawnień do stron;
// strony spoza rejestru (np. Użytkownicy) - jak dotąd, wg roli. Bez uprawnień - wg roli.
$roleEdit  = function_exists('roleCanEdit') ? roleCanEdit() : (function_exists('canEdit') && canEdit());
$roleAdmin = function_exists('isAdmin') && isAdmin();
$menuGroups = \Pase\Support\AdminMenu::build($roleEdit, $roleAdmin);
if (function_exists('usesPagePermissions') && usesPagePermissions()) {
    $roleHrefs = [];
    foreach ($menuGroups as $g) {
        foreach ($g['items'] as $it) { if (isset($it['href'])) { $roleHrefs[$it['href']] = true; } }
    }
    $menuGroups = \Pase\Support\AdminMenu::filter(
        \Pase\Support\AdminMenu::build(true, true),
        static fn(string $href): bool => \Pase\Support\PagePermissions::keyForFile($href) !== null
            ? canOpenPage($href) : isset($roleHrefs[$href])
    );
}
// Kolejność ustawiona przez administratora (Konfiguracja → Kolejność menu).
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $menuGroups = \Pase\Support\AdminMenu::applyOrder(
            $menuGroups,
            (new \Pase\Repository\SettingsRepository($pdo))->get(\Pase\Support\AdminMenu::SETTING)
        );
    } catch (\Throwable) {
        // brak tabeli/ustawienia - zostaje kolejność domyślna
    }
}

$currentFile = basename($_SERVER['PHP_SELF']);

// Liczba nieprzeczytanych wiadomości od klientów (badge przy „Zamówienia").
$unreadMsgs = 0;
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $unreadMsgs = (new \Pase\Repository\OrderMessageRepository($pdo))->unreadCount();
    } catch (\Throwable) {
        $unreadMsgs = 0;
    }
}
?>
<nav class="nav-bar">
    <button class="nav-toggle" aria-label="Menu">☰</button>
    <div class="nav-quick">
        <a href="index.php" class="nav-home" title="<?= htmlspecialchars(t('nav.home')) ?>">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v9.5a1 1 0 0 0 1 1H9.5v-6h5v6H17.5a1 1 0 0 0 1-1V10"/></svg>
        </a>
    </div>
    <div class="nav-links">
        <?php foreach ($menuGroups as $gkey => $group): ?>
            <div class="nav-group">
                <button class="nav-group-btn">
                    <?= $group['label'] ?>
                    <?php if ($unreadMsgs > 0 && $gkey === 'orders'): ?>
                        <span class="nav-badge" title="<?= htmlspecialchars(t('nav.msgs')) ?>"><?= (int) $unreadMsgs ?></span>
                    <?php endif; ?>
                    <svg class="nav-arrow" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div class="nav-dropdown">
                    <?php foreach ($group['items'] as $item): ?>
                        <?php if (!empty($item['children'])):
                            // Marketplace z pod-menu (flyout w bok). Aktywny, gdy bieżąca strona należy do dzieci.
                            $childActive = false;
                            foreach ($item['children'] as $c) { if ($currentFile === $c['href']) { $childActive = true; break; } }
                        ?>
                            <div class="nav-sub <?= $childActive ? 'active' : '' ?>">
                                <button type="button" class="nav-dropdown-item nav-sub-btn">
                                    <span class="nav-item-label"><?= $item['label'] ?></span>
                                    <svg class="nav-sub-arrow" viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 6 15 12 9 18"/></svg>
                                </button>
                                <div class="nav-flyout">
                                    <?php foreach ($item['children'] as $c): ?>
                                        <a href="<?= $c['href'] ?>" class="nav-dropdown-item <?= ($currentFile === $c['href']) ? 'active' : '' ?>">
                                            <span class="nav-item-label"><?= $c['label'] ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <a href="<?= $item['href'] ?>" class="nav-dropdown-item <?= ($currentFile === $item['href']) ? 'active' : '' ?>">
                                <span class="nav-item-label"><?= $item['label'] ?></span>
                                <?php if (!empty($item['desc'])): ?><span class="nav-item-desc"><?= $item['desc'] ?></span><?php endif; ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="nav-user-area">
        <div class="nav-bell" id="navBell">
            <button type="button" class="nav-bell-btn" aria-label="Powiadomienia" title="Powiadomienia">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
                <span class="nav-bell-count" <?= $unreadMsgs > 0 ? '' : 'hidden' ?>><?= (int) $unreadMsgs ?></span>
            </button>
            <div class="nav-bell-pop" hidden>
                <div class="nav-bell-head">
                    <span>Powiadomienia</span>
                    <span class="nav-bell-tools">
                        <button type="button" class="nav-bell-all" hidden>Odczytaj wszystkie</button>
                        <button type="button" class="nav-bell-gear" title="Co pokazywać" aria-label="Ustawienia powiadomień">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        </button>
                    </span>
                </div>
                <div class="nav-bell-prefs" hidden></div>
                <div class="nav-bell-list"><div class="nav-bell-empty">Ładowanie…</div></div>
            </div>
        </div>
        <?php $navScale = $uiScale ?? \Pase\Support\UiScale::DEFAULT; ?>
        <div class="nav-scale" id="navScale" data-min="<?= \Pase\Support\UiScale::MIN ?>" data-max="<?= \Pase\Support\UiScale::MAX ?>" data-step="<?= \Pase\Support\UiScale::STEP ?>">
            <button type="button" class="nav-scale-btn" title="Skala interfejsu - powiększ lub pomniejsz cały panel" aria-label="Skala interfejsu">
                <span class="aa">Aa</span><span class="nav-scale-val"><?= (int) $navScale ?>%</span>
            </button>
            <div class="nav-scale-pop" hidden>
                <div class="nav-scale-head">Skala interfejsu</div>
                <div class="nav-scale-row">
                    <button type="button" class="nav-scale-step" data-d="-1" aria-label="Pomniejsz">−</button>
                    <strong class="nav-scale-cur"><?= (int) $navScale ?>%</strong>
                    <button type="button" class="nav-scale-step" data-d="1" aria-label="Powiększ">+</button>
                </div>
                <div class="nav-scale-presets">
                    <?php foreach ([70, 75, 80, 85, 90, 100, 110, 120] as $pv): ?>
                        <button type="button" class="nav-scale-preset<?= $pv === (int) $navScale ? ' on' : '' ?>" data-v="<?= $pv ?>"><?= $pv ?>%</button>
                    <?php endforeach; ?>
                </div>
                <div class="nav-scale-foot">
                    <button type="button" class="nav-scale-reset">Przywróć 100%</button>
                </div>
                <p class="nav-scale-hint">Zapamiętywane na Twoim koncie - działa na każdym komputerze. Ustaw powiększenie przeglądarki na 100%, żeby się nie sumowały.</p>
            </div>
        </div>
        <span class="nav-lang">
            <?php foreach (\Pase\Support\I18n::available() as $code => $name):
                $cur = $code === \Pase\Support\I18n::locale(); ?>
                <a href="?setlang=<?= htmlspecialchars($code) ?>" class="<?= $cur ? 'on' : '' ?>" title="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars(strtoupper($code)) ?></a>
            <?php endforeach; ?>
        </span>
        <span class="nav-user-info" title="Zalogowany: <?= htmlspecialchars(currentUserName()) ?> (rola: <?= htmlspecialchars(currentUserRole()) ?>)"><?= htmlspecialchars(currentUserName()) ?></span>
        <a href="security.php" class="logout-btn" title="Bezpieczeństwo konta — logowanie dwuetapowe i szybkie logowanie innym urządzeniem">2FA</a>
        <a href="?logout=1" class="logout-btn">
            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="10.5" width="14" height="9.5" rx="1.5"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/></svg>
            <?= htmlspecialchars(t('common.logout')) ?>
        </a>
    </div>
</nav>

<style>
    .nav-bar { display:flex; justify-content:space-between; align-items:center;
        background:var(--surface); font-family:var(--font-ui); border:1px solid var(--line);
        padding:0 16px 0 8px; border-radius:var(--radius); margin-bottom:25px;
        box-shadow:var(--shadow); min-height:52px; position:relative; z-index:1000; }
    .nav-quick { display:flex; align-items:center; gap:2px; margin-right:4px; flex-shrink:0; }
    .nav-home { display:inline-flex; color:var(--ink-2); text-decoration:none; padding:8px 10px; border-radius:8px; transition:all .15s; flex-shrink:0; }
    .nav-home:hover { color:var(--accent-ink); background:var(--accent-soft); }
    .nav-links { display:contents; gap:2px; align-items:stretch; height:100%; }
    .nav-group { position:relative; }
    .nav-group-btn { display:flex; align-items:center; gap:5px; color:var(--ink-2);
        background:transparent; border:none; padding:14px 14px; font-size:.82em; font-weight:600;
        cursor:pointer; transition:all .15s; white-space:nowrap; font-family:inherit; }
    .nav-group-btn:hover { color:var(--ink); background:var(--surface-2); }
    .nav-arrow { opacity:.55; transition:transform .2s; flex-shrink:0; }
    .nav-badge { background:#c0392b; color:#fff; font-size:.7em; font-weight:700; min-width:18px; height:18px; line-height:18px; text-align:center; border-radius:9px; padding:0 5px; margin-left:2px; }
    .nav-bell { position:relative; display:flex; align-items:center; }
    .nav-bell-btn { position:relative; display:inline-flex; align-items:center; justify-content:center; line-height:0; color:var(--ink-2); background:transparent; border:none; padding:8px 10px; border-radius:8px; cursor:pointer; transition:all .15s; }
    .nav-bell-btn:hover, .nav-bell.open .nav-bell-btn { color:var(--accent-ink); background:var(--accent-soft); }
    .nav-bell-count { position:absolute; top:2px; right:2px; background:#c0392b; color:#fff; font-size:10px; font-weight:700; min-width:16px; height:16px; line-height:16px; text-align:center; border-radius:8px; padding:0 4px; }
    .nav-bell-count[hidden], .nav-bell-pop[hidden] { display:none; }
    .nav-bell.ring .nav-bell-btn svg { animation:bellRing .9s ease; transform-origin:50% 10%; }
    @keyframes bellRing { 0%,100%{transform:rotate(0)} 20%{transform:rotate(14deg)} 40%{transform:rotate(-12deg)} 60%{transform:rotate(8deg)} 80%{transform:rotate(-4deg)} }
    .nav-bell-pop { position:absolute; top:calc(100% + 8px); right:0; left:auto; width:340px; max-width:calc(100vw - 32px); max-height:70vh; overflow:auto; background:var(--surface);
        border-radius:var(--radius); box-shadow:0 10px 34px -10px rgba(20,18,12,.28), 0 2px 8px rgba(20,18,12,.08); z-index:1000; padding:6px 0; }
    @media (max-width:700px) { .nav-bell-pop { left:0; right:auto; } }
    .nav-bell-head { display:flex; justify-content:space-between; align-items:center; gap:8px; font-weight:700; font-size:.85em; padding:8px 10px 6px 14px; color:var(--ink); }
    .nav-bell-tools { display:flex; align-items:center; gap:4px; }
    .nav-bell-all, .nav-bell-gear, .nav-bell-x, .nav-bell-read { background:none; border:0; cursor:pointer; font-family:inherit; color:var(--ink-3, #999); border-radius:6px; }
    .nav-bell-all { font-size:.85em; font-weight:600; padding:4px 6px; color:var(--accent-ink); }
    .nav-bell-all:hover, .nav-bell-read:hover { text-decoration:underline; }
    .nav-bell-all[hidden], .nav-bell-prefs[hidden] { display:none; }
    .nav-bell-gear { display:inline-flex; padding:5px; }
    .nav-bell-gear:hover, .nav-bell-gear.on { color:var(--ink); background:var(--surface-2); }
    .nav-bell-prefs { border-top:1px solid var(--line, #eef0f3); padding:8px 14px 10px; font-size:.82em; }
    .nav-bell-prefs label { display:flex; align-items:center; gap:8px; padding:4px 0; color:var(--ink-2); cursor:pointer; font-weight:500; }
    .nav-bell-prefs .hint { color:#999; font-size:.9em; margin:0 0 4px; }
    .nav-bell-read { font-size:.85em; font-weight:500; padding:0 2px; }
    .nav-bell-gh .r { display:flex; align-items:center; gap:8px; }
    .nav-bell-row { display:flex; align-items:center; }
    /* Długa grupa (np. niski stan) przewija się w sobie, reszta grup zostaje widoczna. */
    .nav-bell-rows { max-height:320px; overflow-y:auto; overscroll-behavior:contain; }
    .nav-bell-more { display:block; padding:6px 14px 4px; font-size:.8em; font-weight:600; color:var(--accent-ink); text-decoration:none; }
    .nav-bell-more:hover { text-decoration:underline; }
    .nav-bell-row .nav-bell-it { flex:1; min-width:0; }
    .nav-bell-x { padding:2px 8px 2px 4px; font-size:16px; line-height:1; visibility:hidden; }
    .nav-bell-row:hover .nav-bell-x { visibility:visible; }
    .nav-bell-x:hover { color:#c0392b; }
    .nav-bell-empty { color:#999; font-size:.85em; padding:10px 14px 14px; }
    .nav-bell-grp { border-top:1px solid var(--line, #eef0f3); padding:8px 0 4px; }
    .nav-bell-gh { display:flex; justify-content:space-between; align-items:center; gap:8px; padding:0 14px 4px; font-size:.8em; font-weight:700; color:var(--ink); }
    .nav-bell-gh a { color:inherit; text-decoration:none; }
    .nav-bell-gh a:hover { text-decoration:underline; }
    .nav-bell-gh .n { background:var(--accent-soft); color:var(--accent-ink); border-radius:9px; padding:0 7px; font-size:.95em; }
    .nav-bell-grp.err .nav-bell-gh { color:#b3261e; }
    .nav-bell-grp.err .nav-bell-gh .n { background:#fdecea; color:#b3261e; }
    .nav-bell-it { display:flex; justify-content:space-between; gap:10px; padding:5px 14px; font-size:.8em; color:var(--ink-2); text-decoration:none; }
    .nav-bell-it:hover { background:var(--surface-2); }
    .nav-bell-it .t { color:#999; white-space:nowrap; flex-shrink:0; }
    .nav-msgs { display:inline-flex; align-items:center; gap:3px; text-decoration:none; background:var(--warn-ink); color:#fff;
        font-size:.8em; font-weight:700; padding:5px 10px; border-radius:8px; margin-left:2px; transition:background .15s; }
    .nav-msgs:hover { background:#7a4f0c; }
    .nav-group.open .nav-arrow { transform:rotate(180deg); }
    .nav-group.open .nav-group-btn { color:var(--ink); background:var(--surface-2); }
    .nav-dropdown { display:none; position:absolute; top:100%; left:0; min-width:240px; background:var(--surface);
        border-radius:var(--radius); box-shadow:0 10px 34px -10px rgba(20,18,12,.28), 0 2px 8px rgba(20,18,12,.08);
        padding:6px; z-index:1001; animation:navDropIn .15s ease-out; }
    .nav-group.open .nav-dropdown { display:block; }
    @keyframes navDropIn { from{opacity:0;transform:translateY(-6px);} to{opacity:1;transform:translateY(0);} }
    .nav-dropdown-item { display:flex; flex-direction:column; padding:10px 14px; text-decoration:none; color:var(--ink); border-radius:7px; transition:all .15s; }
    .nav-dropdown-item:hover { background:var(--accent-soft); color:var(--accent-ink); }
    .nav-dropdown-item.active { background:var(--accent-soft); border-left:3px solid var(--accent); }
    .nav-item-label { font-size:.88em; font-weight:600; color:var(--ink); }
    .nav-item-desc { font-size:.72em; color:var(--ink-2); margin-top:1px; }
    .nav-dropdown-item:hover .nav-item-label { color:var(--accent-ink); }
    /* Pod-menu marketplace (flyout w bok) */
    .nav-sub { position:relative; }
    .nav-sub-btn { width:100%; flex-direction:row; align-items:center; justify-content:space-between; background:none; border:0; cursor:pointer; font-family:inherit; }
    .nav-sub-arrow { color:var(--ink-3); transition:transform .2s; flex-shrink:0; }
    .nav-sub.active > .nav-sub-btn { background:var(--accent-soft); border-radius:7px; }
    .nav-flyout { display:none; position:absolute; top:-6px; left:100%; min-width:220px; background:var(--surface);
        border-radius:var(--radius); box-shadow:0 10px 34px -10px rgba(20,18,12,.28), 0 2px 8px rgba(20,18,12,.08); padding:6px; z-index:1002; }
    .nav-sub:hover > .nav-flyout, .nav-sub.open > .nav-flyout { display:block; }
    .nav-sub:hover > .nav-sub-btn .nav-sub-arrow { transform:translateX(2px); }
    .nav-user-area { display:flex; align-items:center; gap:10px; flex-shrink:0; }
    .nav-scale { position:relative; display:flex; align-items:center; }
    .nav-scale-btn { display:inline-flex; align-items:baseline; gap:4px; background:transparent; border:0; cursor:pointer; color:var(--ink-2); padding:7px 8px; border-radius:8px; font-family:inherit; }
    .nav-scale-btn .aa { font-weight:800; font-size:.9em; }
    .nav-scale-val { font-size:.72em; font-weight:600; font-variant-numeric:tabular-nums; }
    .nav-scale-btn:hover, .nav-scale.open .nav-scale-btn { color:var(--accent-ink); background:var(--accent-soft); }
    .nav-scale-pop { position:absolute; top:calc(100% + 8px); right:0; width:260px; background:var(--surface); border-radius:var(--radius);
        box-shadow:0 10px 34px -10px rgba(20,18,12,.28), 0 2px 8px rgba(20,18,12,.08); z-index:1000; padding:12px 14px; }
    .nav-scale-pop[hidden] { display:none; }
    @media (max-width:700px) { .nav-scale-pop { right:auto; left:0; } }
    .nav-scale-head { font-weight:700; font-size:.85em; margin-bottom:10px; }
    .nav-scale-row { display:flex; align-items:center; justify-content:space-between; gap:8px; }
    .nav-scale-row .nav-scale-cur { font-size:1.05em; }
    .nav-scale-presets { display:grid; grid-template-columns:repeat(4, 1fr); gap:6px; margin-top:10px; }
    .nav-scale-preset { border:1px solid var(--line); background:var(--surface); border-radius:7px; padding:6px 0; cursor:pointer; font-family:inherit; font-size:.8em; font-weight:600; color:var(--ink); font-variant-numeric:tabular-nums; }
    .nav-scale-preset:hover { border-color:var(--ink-3); background:var(--surface-2); }
    .nav-scale-preset.on { background:var(--accent); border-color:var(--accent); color:#fff; }
    .nav-scale-step { width:30px; height:30px; flex-shrink:0; border:1px solid var(--line); background:var(--surface); border-radius:8px; cursor:pointer; font-size:16px; font-weight:700; color:var(--ink); line-height:1; }
    .nav-scale-step:hover { background:var(--surface-2); border-color:var(--ink-3); }
    .nav-scale-foot { display:flex; justify-content:flex-end; align-items:center; margin-top:10px; font-size:.85em; }
    .nav-scale-cur { font-variant-numeric:tabular-nums; }
    .nav-scale-reset { background:none; border:0; color:var(--accent-ink); font-weight:600; cursor:pointer; font-family:inherit; font-size:1em; padding:2px 0; }
    .nav-scale-reset:hover { text-decoration:underline; }
    .nav-scale-hint { color:var(--ink-2); font-size:.72em; margin:10px 0 0; line-height:1.4; }
    .nav-lang { display:flex; gap:4px; }
    .nav-lang a { color:var(--ink-3); text-decoration:none; font-size:.72em; font-weight:700; padding:2px 5px; border-radius:5px; }
    .nav-lang a.on { color:var(--ink); background:var(--surface-2); }
    .nav-lang a:hover { color:var(--ink); }
    .nav-user-info { color:var(--ink-2); font-size:.8em; font-weight:600; max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .nav-user-role { background:var(--muted-bg); color:var(--muted-ink); padding:2px 8px; border-radius:10px; font-size:.85em; margin-left:4px; }
    .logout-btn { display:inline-flex; align-items:center; gap:6px; color:var(--ink-2); text-decoration:none; padding:7px 14px; border-radius:8px; font-size:.8em;
        font-weight:600; background:var(--surface); border:1px solid var(--line); transition:all .15s; }
    .logout-btn:hover { color:var(--ink); background:var(--surface-2); border-color:var(--ink-3); }
    .nav-toggle { display:none; background:var(--surface-2); border:1px solid var(--line); color:var(--ink); font-size:1.4em; padding:6px 10px; border-radius:6px; cursor:pointer; line-height:1; }
    .nav-toggle:hover { background:var(--accent-soft); }
    @media (max-width:900px) {
        .nav-bar { flex-wrap:wrap; padding:10px 14px; gap:6px; }
        .nav-toggle { display:block; order:0; }
        .nav-user-area { order:1; margin-left:auto; }
        .nav-links { order:2; width:100%; flex-direction:column; gap:2px; display:none; padding-top:8px; border-top:1px solid var(--line); }
        .nav-links.open { display:flex; }
        .nav-group-btn { width:100%; justify-content:space-between; padding:10px 12px; border-radius:6px; }
        .nav-dropdown { position:static; box-shadow:none; background:var(--surface-2); border-radius:6px; margin:2px 0 4px; padding:4px; }
        .nav-dropdown-item { color:var(--ink-2); padding:8px 12px; }
        .nav-dropdown-item:hover { background:var(--accent-soft); }
        .nav-item-label { color:var(--ink); }
        .nav-item-desc { color:var(--ink-2); }
        .nav-dropdown-item.active { background:var(--accent-soft); border-left-color:var(--accent); }
        /* Na mobile flyout staje się statyczną, wciętą listą rozwijaną klikiem. */
        .nav-flyout { position:static; box-shadow:none; background:var(--surface); margin:2px 0 4px 12px; padding:4px; min-width:0; }
        .nav-sub:hover > .nav-flyout { display:none; }
        .nav-sub.open > .nav-flyout { display:block; }
        .nav-sub.active > .nav-sub-btn { background:var(--accent-soft); }
        .nav-sub.open > .nav-sub-btn .nav-sub-arrow { transform:rotate(90deg); }
    }
</style>

<script<?= isset($cspNonce) ? ' nonce="' . htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
function toggleNavGroup(btn) {
    const group = btn.closest('.nav-group');
    const wasOpen = group.classList.contains('open');
    document.querySelectorAll('.nav-group.open').forEach(g => g.classList.remove('open'));
    if (!wasOpen) group.classList.add('open');
}
// Pod-menu marketplace: klik rozwija (głównie mobile; na desktopie działa też hover).
function toggleNavSub(btn, event) {
    const sub = btn.closest('.nav-sub');
    sub.classList.toggle('open');
    event.stopPropagation();
}
// Bind events in the script so the 2FA page can block inline event handlers.
document.querySelector('.nav-toggle').addEventListener('click', function () {
    document.querySelector('.nav-links').classList.toggle('open');
});
document.querySelectorAll('.nav-group-btn').forEach(function (button) {
    button.addEventListener('click', function () { toggleNavGroup(button); });
});
document.querySelectorAll('.nav-sub-btn').forEach(function (button) {
    button.addEventListener('click', function (event) { toggleNavSub(button, event); });
});
document.addEventListener('click', function(e) {
    if (!e.target.closest('.nav-group')) {
        document.querySelectorAll('.nav-group.open').forEach(g => g.classList.remove('open'));
        document.querySelectorAll('.nav-sub.open').forEach(s => s.classList.remove('open'));
    }
});
</script>

<script>
(function () {
    var bell = document.getElementById('navBell');
    if (!bell) { return; }
    var btn = bell.querySelector('.nav-bell-btn'), pop = bell.querySelector('.nav-bell-pop'),
        list = bell.querySelector('.nav-bell-list'), cnt = bell.querySelector('.nav-bell-count'),
        allBtn = bell.querySelector('.nav-bell-all'), gear = bell.querySelector('.nav-bell-gear'),
        prefsBox = bell.querySelector('.nav-bell-prefs');
    var last = null, baseTitle = document.title, csrf = <?= json_encode(csrfToken()) ?>, data = null;
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function post(fields, keepalive) {
        var fd = new FormData(); fd.append('csrf', csrf);
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        return fetch('notifications.php', { method: 'POST', body: fd, credentials: 'same-origin', keepalive: !!keepalive });
    }
    function render(d) {
        allBtn.hidden = !(d.items && d.items.length);
        if (!d.items || !d.items.length) {
            var anyOn = d.prefs && Object.keys(d.prefs).some(function (k) { return d.prefs[k]; });
            list.innerHTML = '<div class="nav-bell-empty">' + (anyOn ? 'Brak nowych powiadomień.' : 'Wszystkie powiadomienia są wyłączone — włącz je kołem zębatym.') + '</div>';
            return;
        }
        list.innerHTML = d.items.map(function (g) {
            var canRead = ['new_orders', 'client_msgs', 'allegro_msgs', 'low_stock'].indexOf(g.key) !== -1;
            return '<div class="nav-bell-grp ' + esc(g.level) + '"><div class="nav-bell-gh"><a href="' + esc(g.link) + '">' + esc(g.title) + '</a><span class="r">'
                + (canRead ? '<button type="button" class="nav-bell-read" data-read="' + esc(g.key) + '">odczytane</button>' : '')
                + '<span class="n">' + g.count + '</span></span></div><div class="nav-bell-rows">'
                + (g.details || []).map(function (it) {
                    return '<div class="nav-bell-row"><a class="nav-bell-it" href="' + esc(it.link) + '"' + (it.order ? ' data-order="' + it.order + '"' : '') + '><span>' + esc(it.text) + '</span><span class="t">' + esc(it.time) + '</span></a>'
                        + (it.order ? '<button type="button" class="nav-bell-x" data-dismiss="' + it.order + '" title="Odczytane">×</button>' : '') + '</div>';
                }).join('') + '</div>'
                + ((g.details || []).length && g.count > g.details.length
                    ? '<a class="nav-bell-more" href="' + esc(g.link) + '">Pokaż wszystkie (' + g.count + ') →</a>' : '')
                + '</div>';
        }).join('');
    }
    // Pasek ikon obok tytułu strony: każda grupa z dzwoneczka jako ikona z liczbą i linkiem.
    var chipIcons = { client_msgs: '✉️', allegro_msgs: '💬', allegro_issues: '⚖️', allegro_ratings: '👎', returns: '↩️', low_stock: '📉',
                      new_orders: '🛒', allegro_token: '🔑', failed_jobs: '⚠️' };
    var chipLabels = { client_msgs: 'Wiadomości klientów', allegro_msgs: 'Wiadomości Allegro', new_orders: 'Nowe zamówienia',
                       allegro_token: 'Token Allegro wygasł', failed_jobs: 'Błędy kolejki' };
    function renderChips(d) {
        var box = document.getElementById('alertChips');
        if (!box) { return; }
        var items = (d.items || []).filter(function (g) { return g.count > 0; });
        box.hidden = !items.length;
        box.innerHTML = items.map(function (g) {
            var label = g.short || chipLabels[g.key] || g.title;
            return '<a class="alert-chip ' + esc(g.level) + '" href="' + esc(g.link) + '" title="' + esc(g.title) + '">'
                + '<span class="ic">' + (chipIcons[g.key] || '🔔') + '</span><span class="lb">' + esc(label) + '</span>'
                + (g.key === 'allegro_token' ? '' : '<span class="n">' + (g.count > 99 ? '99+' : g.count) + '</span>') + '</a>';
        }).join('');
    }
    function renderPrefs(d) {
        if (!d || !d.types) { return; }
        prefsBox.innerHTML = '<p class="hint">Pokazuj w dzwoneczku:</p>' + Object.keys(d.types).map(function (k) {
            return '<label><input type="checkbox" data-pref="' + esc(k) + '"' + (d.prefs[k] ? ' checked' : '') + '> ' + esc(d.types[k]) + '</label>';
        }).join('');
    }
    function load() {
        return fetch('notifications.php', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d || !d.ok) { return; }
            data = d;
            cnt.textContent = d.total > 99 ? '99+' : d.total;
            cnt.hidden = d.total <= 0;
            document.title = (d.total > 0 ? '(' + d.total + ') ' : '') + baseTitle;
            if (last !== null && d.total > last) { bell.classList.remove('ring'); void bell.offsetWidth; bell.classList.add('ring'); }
            last = d.total;
            render(d);
            renderChips(d);
            if (prefsBox.hidden) { renderPrefs(d); }
        }).catch(function () {});
    }
    function close() { pop.hidden = true; bell.classList.remove('open'); prefsBox.hidden = true; gear.classList.remove('on'); }
    btn.addEventListener('click', function (ev) {
        ev.stopPropagation();
        if (pop.hidden) { pop.hidden = false; bell.classList.add('open'); load(); } else { close(); }
    });
    gear.addEventListener('click', function (ev) {
        ev.stopPropagation();
        prefsBox.hidden = !prefsBox.hidden;
        gear.classList.toggle('on', !prefsBox.hidden);
        if (!prefsBox.hidden) { renderPrefs(data); }
    });
    prefsBox.addEventListener('change', function () {
        var f = { action: 'prefs' };
        prefsBox.querySelectorAll('[data-pref]').forEach(function (c) { if (c.checked) { f['prefs[' + c.getAttribute('data-pref') + ']'] = '1'; } });
        post(f).then(load);
    });
    allBtn.addEventListener('click', function (ev) { ev.stopPropagation(); post({ action: 'read', key: 'all' }).then(load); });
    list.addEventListener('click', function (ev) {
        var r = ev.target.closest('[data-read]'), x = ev.target.closest('[data-dismiss]'), a = ev.target.closest('a[data-order]');
        if (r) { ev.preventDefault(); ev.stopPropagation(); post({ action: 'read', key: r.getAttribute('data-read') }).then(load); return; }
        if (x) { ev.preventDefault(); ev.stopPropagation(); post({ action: 'dismiss_order', order: x.getAttribute('data-dismiss') }).then(load); return; }
        // Kliknięte zamówienie = odczytane (wysyłamy w tle, przejście na stronę nie czeka).
        if (a) { post({ action: 'dismiss_order', order: a.getAttribute('data-order') }, true); }
    });
    pop.addEventListener('click', function (ev) { ev.stopPropagation(); });
    document.addEventListener('click', function () { if (!pop.hidden) { close(); } });
    load();
    setInterval(function () { if (!document.hidden) { load(); } }, 60000);
})();
</script>

<script<?= isset($cspNonce) ? ' nonce="' . htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
// Skala interfejsu: zmiana od razu na stronie, zapis na koncie chwilę po ostatnim kliknięciu.
(function () {
    var box = document.getElementById('navScale');
    if (!box) { return; }
    var btn = box.querySelector('.nav-scale-btn'), pop = box.querySelector('.nav-scale-pop'),
        min = +box.getAttribute('data-min'), max = +box.getAttribute('data-max'), step = +box.getAttribute('data-step'),
        csrf = <?= json_encode(csrfToken()) ?>, timer = null, value = parseInt(box.querySelector('.nav-scale-cur').textContent, 10) || 100;
    function apply(v, save) {
        v = Math.max(min, Math.min(max, Math.round(v / step) * step));
        value = v;
        box.querySelectorAll('.nav-scale-preset').forEach(function (b) { b.classList.toggle('on', +b.getAttribute('data-v') === v); });
        document.documentElement.style.setProperty('--ui-zoom', String(v / 100));
        box.querySelectorAll('.nav-scale-val, .nav-scale-cur').forEach(function (el) { el.textContent = v + '%'; });
        if (save) {
            clearTimeout(timer);
            timer = setTimeout(function () {
                var fd = new FormData(); fd.append('csrf', csrf); fd.append('scale', String(v));
                fetch('ui_scale_save.php', { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true });
            }, 400);
        }
    }
    function toggle(open) { pop.hidden = !open; box.classList.toggle('open', open); }
    btn.addEventListener('click', function (ev) { ev.stopPropagation(); toggle(pop.hidden); });
    pop.addEventListener('click', function (ev) {
        ev.stopPropagation();
        var s = ev.target.closest('.nav-scale-step');
        if (s) { apply(value + step * +s.getAttribute('data-d'), true); }
        var pr = ev.target.closest('.nav-scale-preset');
        if (pr) { apply(+pr.getAttribute('data-v'), true); }
        if (ev.target.closest('.nav-scale-reset')) { apply(100, true); }
    });
    document.addEventListener('click', function () { if (!pop.hidden) { toggle(false); } });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !pop.hidden) { toggle(false); } });
})();
</script>
