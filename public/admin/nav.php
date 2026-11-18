<?php
declare(strict_types=1);

/**
 * Globalny pasek nawigacji: zakładki z panelami (kolumny, nagłówki grup, nazwa + opis, bez ikon),
 * obszar użytkownika z rolą i "Wyloguj".
 * Menu dopasowane do funkcji middleware.
 */

// Definicja pozycji menu: Pase\Support\AdminMenu (wspólna z edytorem układu menu).
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
// Układ ustawiony przez administratora (Konfiguracja → Układ menu): własne zakładki, podkategorie, ukryte pozycje.
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $menuGroups = \Pase\Support\AdminMenu::applyLayout(
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
                    <?= \Pase\Support\AdminMenu::plain((string) $group['label']) ?>
                    <?php if ($unreadMsgs > 0 && $gkey === 'orders'): ?>
                        <span class="nav-badge" title="<?= htmlspecialchars(t('nav.msgs')) ?>"><?= (int) $unreadMsgs ?></span>
                    <?php endif; ?>
                    <svg class="nav-arrow" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <?php
                // Panel jak w katalogu: kolumny z sekcjami pod małym szarym nagłówkiem, pozycja = nazwa + opis, bez ikon.
                // Podkategorie (kanały: Allegro, Erli…) to lista nazw - ich pozycje rozwijają się obok po najechaniu/kliknięciu.
                // Na telefonie kolumny układają się jedna pod drugą, a kanał rozwija się pod swoją nazwą.
                $navLoose = array_values(array_filter($group['items'], static fn(array $i): bool => !isset($i['children'])));
                $navSubs = array_values(array_filter($group['items'], static fn(array $i): bool => isset($i['children'])));
                $navColumns = $navLoose ? \Pase\Support\AdminMenu::columns(\Pase\Support\AdminMenu::sections($navLoose)) : [];
                $navLink = static function (array $item) use ($currentFile): string {
                    return '<a href="' . $item['href'] . '" class="nav-dropdown-item' . ($currentFile === $item['href'] ? ' active' : '') . '">'
                        . '<span class="nav-item-label">' . \Pase\Support\AdminMenu::plain((string) $item['label']) . '</span>'
                        . (!empty($item['desc']) ? '<span class="nav-item-desc">' . $item['desc'] . '</span>' : '') . '</a>';
                };
                ?>
                <div class="nav-dropdown<?= $navSubs ? ' nav-has-subs' : '' ?>">
                    <?php foreach ($navColumns as $navCol): ?>
                        <div class="nav-col">
                            <?php foreach ($navCol as $navSec): ?>
                                <div class="nav-section">
                                    <?php if ($navSec['title'] !== ''): ?><div class="nav-section-title"><?= $navSec['title'] ?></div><?php endif; ?>
                                    <?php foreach ($navSec['items'] as $item): ?><?= $navLink($item) ?><?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($navSubs): ?>
                        <div class="nav-col nav-col-subs">
                            <div class="nav-section">
                                <?php foreach ($navSubs as $item):
                                    $navActive = in_array($currentFile, array_column($item['children'], 'href'), true); ?>
                                    <div class="nav-sub<?= $navActive ? ' active' : '' ?>">
                                        <button type="button" class="nav-dropdown-item nav-sub-btn" aria-expanded="false">
                                            <span class="nav-sub-text">
                                                <span class="nav-item-label"><?= \Pase\Support\AdminMenu::plain((string) $item['label']) ?></span>
                                                <?php if (($navDesc = \Pase\Support\AdminMenu::subDesc($item)) !== ''): ?><span class="nav-item-desc"><?= $navDesc ?></span><?php endif; ?>
                                            </span>
                                            <svg class="nav-sub-arrow" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 6 15 12 9 18"/></svg>
                                        </button>
                                        <div class="nav-flyout">
                                            <?php foreach (\Pase\Support\AdminMenu::columns([['title' => '', 'items' => $item['children']]]) as $navCol): ?>
                                                <div class="nav-col">
                                                    <?php foreach ($navCol as $navSec): ?>
                                                        <div class="nav-section"><?php foreach ($navSec['items'] as $c): ?><?= $navLink($c) ?><?php endforeach; ?></div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php // Na telefonie dane konta (nazwa, język, 2FA, Wyloguj) są w rozwijanym menu, żeby pasek się mieścił. ?>
        <div class="nav-account-mobile">
            <span class="nav-account-name"><?= htmlspecialchars(currentUserName()) ?></span>
            <span class="nav-lang">
                <?php foreach (\Pase\Support\I18n::available() as $code => $name):
                    $cur = $code === \Pase\Support\I18n::locale(); ?>
                    <a href="?setlang=<?= htmlspecialchars($code) ?>" class="<?= $cur ? 'on' : '' ?>" title="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars(strtoupper($code)) ?></a>
                <?php endforeach; ?>
            </span>
            <a href="security.php" class="logout-btn">2FA</a>
            <a href="?logout=1" class="logout-btn"><?= htmlspecialchars(t('common.logout')) ?></a>
        </div>
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
                <?php // Powiadomienia push tego urządzenia (Services\WebPush) - pod ustawieniami dzwoneczka. ?>
                <div class="nav-bell-push" id="navPush"><div class="nav-push-txt">Sprawdzanie powiadomień push…</div></div>
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
        <a href="security.php" class="logout-btn nav-2fa" title="Bezpieczeństwo konta — logowanie dwuetapowe i szybkie logowanie innym urządzeniem">2FA</a>
        <a href="?logout=1" class="logout-btn nav-logout" title="<?= htmlspecialchars(t('common.logout')) ?>" aria-label="<?= htmlspecialchars(t('common.logout')) ?>">
            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="10.5" width="14" height="9.5" rx="1.5"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/></svg>
            <span class="nav-logout-label"><?= htmlspecialchars(t('common.logout')) ?></span>
        </a>
    </div>
</nav>
<?php // Przypomnienie w aplikacji z ekranu początkowego: po reinstalacji telefon zapomina zgodę na powiadomienia push. ?>
<div class="nav-push-remind" id="navPushRemind" hidden></div>

<script<?= isset($cspNonce) ? ' nonce="' . htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
function toggleNavGroup(btn) {
    const group = btn.closest('.nav-group');
    const wasOpen = group.classList.contains('open');
    document.querySelectorAll('.nav-group.open').forEach(g => g.classList.remove('open'));
    document.querySelectorAll('.nav-sub.open').forEach(s => s.classList.remove('open'));
    if (!wasOpen) {
        group.classList.add('open');
        // Panel przy prawej krawędzi ekranu przesuwa się w lewo, żeby się zmieścił.
        const panel = group.querySelector('.nav-dropdown');
        if (panel && window.matchMedia('(min-width: 901px)').matches) {
            panel.style.left = '';
            const r = panel.getBoundingClientRect();
            const over = r.right - (document.documentElement.clientWidth - 16);
            if (over > 0) { panel.style.left = (-Math.min(over, r.left - 16)) + 'px'; }
        }
    }
}
// Bind events in the script so the 2FA page can block inline event handlers.
document.querySelector('.nav-toggle').addEventListener('click', function () {
    document.querySelector('.nav-links').classList.toggle('open');
});
document.querySelectorAll('.nav-group-btn').forEach(function (button) {
    button.addEventListener('click', function () { toggleNavGroup(button); });
});
// Kanał (podkategoria): klik rozwija; na komputerze także najechanie (z krótką zwłoką, żeby przejście
// myszą do panelu obok przez inny kanał go nie przełączało). Otwarty kanał zostaje otwarty do wyboru innego.
(function () {
    const desktop = () => window.matchMedia('(min-width: 901px) and (hover: hover)').matches;
    function openSub(sub, on) {
        sub.parentElement.querySelectorAll(':scope > .nav-sub.open').forEach(function (o) {
            if (o !== sub) { o.classList.remove('open'); o.querySelector('.nav-sub-btn').setAttribute('aria-expanded', 'false'); }
        });
        sub.classList.toggle('open', on);
        sub.querySelector('.nav-sub-btn').setAttribute('aria-expanded', on ? 'true' : 'false');
        const fly = sub.querySelector('.nav-flyout');
        if (on && fly && desktop()) {
            fly.classList.remove('nav-flip');
            if (fly.getBoundingClientRect().right > document.documentElement.clientWidth - 16) { fly.classList.add('nav-flip'); }
        }
    }
    let timer = null;
    document.querySelectorAll('.nav-sub').forEach(function (sub) {
        const btn = sub.querySelector('.nav-sub-btn');
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            clearTimeout(timer);
            openSub(sub, desktop() ? true : !sub.classList.contains('open'));
        });
        btn.addEventListener('mouseenter', function () {
            if (!desktop() || sub.classList.contains('open')) { return; }
            clearTimeout(timer);
            const anyOpen = sub.parentElement.querySelector(':scope > .nav-sub.open');
            timer = setTimeout(function () { openSub(sub, true); }, anyOpen ? 180 : 60);
        });
        btn.addEventListener('mouseleave', function () { clearTimeout(timer); });
        sub.querySelector('.nav-flyout').addEventListener('mouseenter', function () { clearTimeout(timer); });
    });
})();
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
    // Niski stan zostaje tylko w dzwoneczku (tam każdy produkt ma własny link).
    var chipSkip = ['low_stock'];
    var chipIcons = { client_msgs: '✉️', allegro_msgs: '💬', allegro_issues: '⚖️', allegro_ratings: '👎', returns: '↩️',
                      new_orders: '🛒', allegro_token: '🔑', failed_jobs: '⚠️' };
    var chipLabels = { client_msgs: 'Wiadomości klientów', allegro_msgs: 'Wiadomości Allegro', new_orders: 'Nowe zamówienia',
                       allegro_token: 'Token Allegro wygasł', failed_jobs: 'Błędy kolejki' };
    function renderChips(d) {
        var box = document.getElementById('alertChips');
        if (!box) { return; }
        var items = (d.items || []).filter(function (g) { return g.count > 0 && chipSkip.indexOf(g.key) === -1; });
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
        if (pop.hidden) { document.dispatchEvent(new CustomEvent('navpop', { detail: 'bell' })); pop.hidden = false; bell.classList.add('open'); load(); } else { close(); }
    });
    // Otwarcie innego okienka w pasku (skala) zamyka to.
    document.addEventListener('navpop', function (ev) { if (ev.detail !== 'bell' && !pop.hidden) { close(); } });
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

<script>
// Powiadomienia push na tym urządzeniu (push.php + push-sw.js). Na iPhonie tylko w panelu otwartym z ikony na ekranie początkowym.
(function () {
    var box = document.getElementById('navPush');
    if (!box) { return; }
    var csrf = <?= json_encode(csrfToken()) ?>;
    var supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    var ios = /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var standalone = window.navigator.standalone === true || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
    var endpoint = null;
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    // Pasek nad stroną w aplikacji z ekranu początkowego, gdy powiadomienia są tu wyłączone - po reinstalacji aplikacji
    // telefon kasuje zgodę i subskrypcję, a ustawienie w dzwoneczku łatwo przeoczyć. „Nie teraz” ukrywa go na 7 dni
    // (pamięć przeglądarki znika razem z aplikacją, więc po kolejnej reinstalacji pasek wraca od razu).
    var remind = document.getElementById('navPushRemind');
    var REMIND_KEY = 'velessPushRemindUntil';
    function remindSnoozed() { try { return Number(localStorage.getItem(REMIND_KEY) || 0) > Date.now(); } catch (e) { return false; } }
    function showRemind(state) {
        if (!remind) { return; }
        if (state !== 'off' || !standalone || remindSnoozed()) { remind.hidden = true; return; }
        remind.innerHTML = '<span>🔔 Powiadomienia o nowych zamówieniach są na tym urządzeniu <b>wyłączone</b> '
            + '(np. po ponownej instalacji aplikacji).</span>'
            + '<button type="button" class="on" data-remind="on">Włącz powiadomienia</button>'
            + '<button type="button" data-remind="later">Nie teraz</button>';
        remind.hidden = false;
    }
    function post(fields) {
        var fd = new FormData(); fd.append('csrf', csrf);
        Object.keys(fields).forEach(function (k) { [].concat(fields[k]).forEach(function (v) { fd.append(k, v); }); });
        return fetch('push.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    // Klucz serwera (VAPID) - raz na stronę; porównywany z kluczem, z którym urządzenie się zapisało.
    var serverKeyP = null;
    function serverKey() {
        serverKeyP = serverKeyP || fetch('push.php', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.ok) { serverKeyP = null; throw new Error(d.error || 'brak klucza'); }
            return d.publicKey;
        });
        return serverKeyP;
    }
    function b64u(buf) {
        var b = new Uint8Array(buf), s = '';
        for (var i = 0; i < b.length; i++) { s += String.fromCharCode(b[i]); }
        return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }
    // Klucz, z którym zapisano subskrypcję (null, gdy przeglądarka go nie podaje).
    function subKey(sub) {
        try { var k = sub.options && sub.options.applicationServerKey; return k ? b64u(k) : null; } catch (e) { return null; }
    }
    function key(b64) {
        var s = b64.replace(/-/g, '+').replace(/_/g, '/');
        while (s.length % 4) { s += '='; }
        var raw = atob(s), out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) { out[i] = raw.charCodeAt(i); }
        return out;
    }
    // Nazwa urządzenia do listy w dzwoneczku i historii zamówienia. iPhone/iPad z „wersją komputerową” Safari udaje Maca
    // w nagłówku, więc rozpoznajemy go po ekranie dotykowym; aplikacja z ekranu początkowego ma dopisek.
    function deviceName() {
        var ua = navigator.userAgent, touchMac = navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1, n;
        if (/iPhone|iPod/.test(ua) || (touchMac && Math.min(screen.width, screen.height) < 600)) { n = 'iPhone'; }
        else if (/iPad/.test(ua) || touchMac) { n = 'iPad'; }
        else if (/Android/.test(ua)) { n = 'Android'; }
        else if (/Macintosh/.test(ua)) { n = 'Mac'; }
        else if (/Windows/.test(ua)) { n = 'Windows'; }
        else if (/Linux/.test(ua)) { n = 'Linux'; }
        else { n = 'Urządzenie'; }
        var b = /Edg\//.test(ua) ? 'Edge' : /Firefox\//.test(ua) ? 'Firefox' : /(Chrome|CriOS)\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : '';
        return n + (standalone ? ' (aplikacja)' : (b ? ' · ' + b : ''));
    }
    function save(sub) {
        var j = sub.toJSON();
        endpoint = j.endpoint;
        return post({ action: 'subscribe', endpoint: j.endpoint, p256dh: j.keys.p256dh, auth: j.keys.auth, device: deviceName() }).then(function (d) {
            if (!d.ok) { throw new Error(d.error || 'zapis nie powiódł się'); }
            return d;
        });
    }
    function render(state, msg) {
        var t = {
            on: '🔔 Powiadomienia o nowych zamówieniach na tym urządzeniu: <b>włączone</b>.',
            off: 'Powiadomienia o nowych zamówieniach na tym urządzeniu (push): <b>wyłączone</b>.',
            denied: 'Powiadomienia są zablokowane dla tej strony. Odblokuj je w ustawieniach '
                + (ios ? 'iPhone’a (Ustawienia → Powiadomienia → Veless)' : 'przeglądarki') + ' i odśwież stronę.',
            ios: 'Powiadomienia push na iPhonie: w Safari stuknij <b>Udostępnij → Do ekranu początkowego</b>, otwórz panel z nowej ikony, '
                + 'zaloguj się i włącz je tutaj (iOS 16.4 lub nowszy).',
            unsupported: 'Ta przeglądarka nie obsługuje powiadomień push.'
        }[state];
        var btns = state === 'on' ? '<button type="button" data-push="test">Wyślij test</button><button type="button" data-push="off">Wyłącz</button>'
            : state === 'off' ? '<button type="button" class="on" data-push="on">Włącz powiadomienia</button>' : '';
        box.innerHTML = '<div class="nav-push-txt">' + t + '</div>' + (btns ? '<div class="nav-push-btns">' + btns + '</div>' : '')
            + (msg ? '<div class="nav-push-msg">' + esc(msg) + '</div>' : '')
            + (state === 'on' && endpoint ? '<div class="nav-push-src"></div><div class="nav-push-dev"></div>' : '');
        if (state === 'on' && endpoint) { loadSources(); loadStatus(); loadDevices(); }
        showRemind(state);
    }
    // Subskrypcja push tego urządzenia; bez zgody najpierw pyta (na iPhonie tylko po stuknięciu, więc wołane z kliknięcia).
    // Stara subskrypcja z innym kluczem (serwer utworzył nowe klucze) jest najpierw wyrejestrowana - inaczej
    // przeglądarka odmawia nowej, a usługa push odrzuca każde powiadomienie (403).
    function subscribe() {
        return serverKey().then(function (pub) {
            return navigator.serviceWorker.ready.then(function (reg) {
                return reg.pushManager.getSubscription().then(function (old) {
                    if (old && subKey(old) === pub) { return old; }
                    return (old ? old.unsubscribe().catch(function () {}) : Promise.resolve()).then(function () {
                        return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key(pub) });
                    });
                });
            });
        }).then(save);
    }
    // Pod „włączone”: kiedy ostatnio coś tu doszło i ostatni błąd usługi push (Services\WebPush::deviceStatus).
    function when(ts) {
        var d = new Date(ts * 1000), p = function (n) { return (n < 10 ? '0' : '') + n; };
        return p(d.getDate()) + '.' + p(d.getMonth() + 1) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
    }
    var resaved = false;
    function loadStatus() {
        post({ action: 'status', endpoint: endpoint }).then(function (d) {
            var txt = box.querySelector('.nav-push-txt');
            if (!txt || !d.ok) { return; }
            if (!d.saved && !resaved) {
                // Serwer nie ma tego urządzenia (np. usunięte po zmianie kluczy) - zapisujemy je od nowa, zgoda już jest.
                resaved = true;
                subscribe().then(loadStatus).catch(function () {});
                return;
            }
            var line = !d.saved ? 'Serwer nie zna tego urządzenia - stuknij „Wyłącz” i włącz ponownie.'
                : (d.last_ok_at ? 'Ostatnio dostarczone: ' + when(d.last_ok_at) + '.' : 'Jeszcze nic tu nie wysłano.')
                  + (d.last_error && (!d.last_ok_at || d.last_try_at > d.last_ok_at) ? ' Ostatni błąd: ' + d.last_error + '.' : '');
            var el = txt.querySelector('.nav-push-last') || txt.appendChild(document.createElement('div'));
            el.className = 'nav-push-last';
            el.textContent = line;
        }).catch(function () {});
    }
    function enable() {
        // Zgoda pytana od razu po stuknięciu - iPhone nie pokaże pytania później.
        return Notification.requestPermission().then(function (perm) {
            if (perm !== 'granted') { render(perm === 'denied' ? 'denied' : 'off'); return; }
            return subscribe().then(function () { render('on', 'Gotowe. Stuknij „Wyślij test”, żeby sprawdzić.'); return true; });
        }).catch(function (e) { render('off', 'Nie udało się włączyć: ' + e.message); });
    }
    if (remind) {
        remind.addEventListener('click', function (ev) {
            var b = ev.target.closest('[data-remind]');
            if (!b) { return; }
            if (b.getAttribute('data-remind') === 'later') {
                try { localStorage.setItem(REMIND_KEY, String(Date.now() + 7 * 86400000)); } catch (e) {}
                remind.hidden = true;
                return;
            }
            remind.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
            enable().then(function (ok) {
                if (ok) {
                    remind.hidden = false;
                    remind.innerHTML = '<span>🔔 Powiadomienia włączone. Test wyślesz z dzwoneczka (koło zębate).</span>';
                    setTimeout(function () { remind.hidden = true; }, 6000);
                }
            });
        });
    }
    // Z których sklepów/źródeł przychodzą powiadomienia na tym urządzeniu (wszystkie zaznaczone = także sklepy dodane później).
    function loadSources() {
        post({ action: 'sources', endpoint: endpoint }).then(function (d) {
            var el = box.querySelector('.nav-push-src');
            if (!el || !d.ok || !d.options || d.options.length < 2) { return; }
            el.innerHTML = '<div>Powiadamiaj o zamówieniach z:</div>' + d.options.map(function (o) {
                var on = d.selected === null || d.selected.indexOf(o.id) !== -1;
                return '<label><input type="checkbox" data-src="' + o.id + '"' + (on ? ' checked' : '') + '> ' + esc(o.label) + '</label>';
            }).join('');
        }).catch(function () {});
    }
    // Urządzenia tego konta, które dostają powiadomienia (bieżące zaznaczone); inne można usunąć.
    function loadDevices() {
        post({ action: 'devices', endpoint: endpoint }).then(function (d) {
            var el = box.querySelector('.nav-push-dev');
            if (!el || !d.ok || !d.devices) { return; }
            el.innerHTML = '<div>Twoje urządzenia z powiadomieniami (' + d.devices.length + '):</div>' + d.devices.map(function (x) {
                return '<div class="nav-push-devrow"><span><b>' + esc(x.device) + '</b>' + (x.current ? ' (tutaj)' : '')
                    + ' · ' + (x.last_ok_at ? 'ostatnio ' + esc(when(x.last_ok_at)) : 'jeszcze nic nie doszło')
                    + (x.last_error ? ' · błąd: ' + esc(x.last_error) : '') + '</span>'
                    + (x.current ? '' : '<button type="button" data-push-remove="' + x.id + '" title="Usuń z powiadomień">Usuń</button>') + '</div>';
            }).join('');
        }).catch(function () {});
    }
    box.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-push-remove]');
        if (!b) { return; }
        ev.stopPropagation();
        b.disabled = true;
        post({ action: 'remove_device', id: b.getAttribute('data-push-remove') }).then(loadDevices).catch(function () { b.disabled = false; });
    });
    box.addEventListener('change', function (ev) {
        if (!ev.target.matches('[data-src]')) { return; }
        var all = box.querySelectorAll('[data-src]'), ids = [];
        all.forEach(function (c) { if (c.checked) { ids.push(c.getAttribute('data-src')); } });
        var f = { action: 'save_sources', endpoint: endpoint };
        if (ids.length === all.length) { f.all = '1'; } else { f['sources[]'] = ids.length ? ids : ['-1']; }
        post(f).then(function (d) {
            var m = box.querySelector('.nav-push-msg') || box.appendChild(document.createElement('div'));
            m.className = 'nav-push-msg';
            m.textContent = d.ok ? (ids.length ? 'Zapisano wybór sklepów.' : 'Nie wybrano żadnego źródła - powiadomienia o zamówieniach nie będą przychodzić.') : (d.error || 'Nie zapisano.');
        }).catch(function () {});
    });
    if (!supported) { render(ios && !standalone ? 'ios' : 'unsupported'); return; }
    navigator.serviceWorker.register('push-sw.js').then(function (reg) { return reg.pushManager.getSubscription(); }).then(function (sub) {
        if (sub && Notification.permission === 'granted') {
            endpoint = sub.endpoint;
            var have = subKey(sub);
            serverKey().then(function (pub) {
                if (have && have !== pub) {
                    // Urządzenie zapisane ze starym kluczem - po cichu zapisujemy je od nowa (bez pytania, zgoda jest).
                    return subscribe().then(function () { render('on', 'Odnowiono zapis tego urządzenia (zmienił się klucz powiadomień).'); });
                }
                render('on');
                // Raz na sesję odświeżamy zapis na serwerze (np. po zmianie konta na tym urządzeniu).
                try {
                    if (!sessionStorage.getItem('velessPushSynced2')) {
                        save(sub).then(function () { try { sessionStorage.setItem('velessPushSynced2', '1'); } catch (e) {} loadStatus(); }).catch(function () {});
                    }
                } catch (e) {}
            }).catch(function () { render('on'); });
        } else if (!sub && Notification.permission === 'granted') {
            // Zgoda jest, a subskrypcji nie ma (np. przeglądarka ją wyczyściła) - zapisujemy ją od nowa bez pytania.
            subscribe().then(function () { render('on'); }).catch(function () { render('off'); });
        } else {
            render(Notification.permission === 'denied' ? 'denied' : 'off');
        }
    }).catch(function () { render('unsupported'); });

    box.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-push]');
        if (!b) { return; }
        ev.stopPropagation();
        var what = b.getAttribute('data-push');
        box.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
        if (what === 'on') {
            enable();
        } else if (what === 'off') {
            navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager.getSubscription(); }).then(function (sub) {
                if (!sub) { return; }
                var ep = sub.endpoint;
                return sub.unsubscribe().then(function () { return post({ action: 'unsubscribe', endpoint: ep }); });
            }).then(function () { endpoint = null; render('off', 'Wyłączono na tym urządzeniu.'); })
              .catch(function (e) { render('on', 'Nie udało się wyłączyć: ' + e.message); });
        } else if (what === 'test') {
            post({ action: 'test' }).then(function (d) {
                render('on', d.ok ? 'Wysłano - powiadomienie powinno pojawić się za chwilę.' : (d.error || 'Nie udało się wysłać.'));
            }).catch(function (e) { render('on', 'Nie udało się wysłać: ' + e.message); });
        }
    });
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
    btn.addEventListener('click', function (ev) {
        ev.stopPropagation();
        if (pop.hidden) { document.dispatchEvent(new CustomEvent('navpop', { detail: 'scale' })); }
        toggle(pop.hidden);
    });
    document.addEventListener('navpop', function (ev) { if (ev.detail !== 'scale' && !pop.hidden) { toggle(false); } });
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
