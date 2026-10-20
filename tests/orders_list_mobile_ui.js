'use strict';
// Lista zamówień na telefonie (390 px). Uruchamiany przez tests/orders_list_mobile_http.php (BASE = adres serwera testowego).
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.BASE;
const pass = (m) => console.log('OK (browser): ' + m);
const fail = (m) => { throw new Error('FAIL (browser): ' + m); };

(async () => {
    const browser = await chromium.launch({ executablePath: fs.existsSync('/opt/pw-browsers/chromium') ? '/opt/pw-browsers/chromium' : undefined });
    const errors = [];
    try {
        const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
        const page = await ctx.newPage();
        page.on('pageerror', (e) => errors.push(e.message));
        await page.goto(BASE + 'admin/login.php');
        await page.fill('#username', 'boss');
        await page.fill('#password', 'Only-Test-Password-42');
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
        await page.goto(BASE + 'admin/index.php');
        if (process.env.SHOTS) {
            await page.screenshot({ path: process.env.SHOTS + '/mobile-orders.png' });
            await page.screenshot({ path: process.env.SHOTS + '/mobile-orders-full.png', fullPage: true });
        }

        // Na górze tylko tytuł, „Pobierz teraz”, „+”, przycisk panelu, wyszukiwarka i zamówienia.
        // Filtry, statusy i sortowanie są w zamkniętym panelu z boku, a akcji na zaznaczonych nie widać, dopóki nic nie zaznaczono.
        const top = await page.evaluate(() => {
            const box = (sel) => document.querySelector(sel).getBoundingClientRect();
            const inDrawer = (sel) => !!document.querySelector('#ordersDrawer ' + sel);
            const newBtn = document.querySelector('.page-title-row .new-order-btn');
            return {
                title: box('.page-title-row h1'), sync: box('#ordersSyncNow button'), plus: newBtn.getBoundingClientRect(),
                plusText: newBtn.innerText.trim(), open: box('#ordersDrawerOpen'), search: box('#ordersSearchInput'),
                firstCard: box('.orders-table tr:not(.orders-head)'), vw: document.documentElement.clientWidth,
                pieces: ['.orders-mobile-selall', '.orders-mobile-sort', '#ordersFilterToggle', '#ordersFilterBar', '#ordersSideNav'].filter((s) => !inDrawer(s)),
                drawer: getComputedStyle(document.getElementById('ordersDrawer')).visibility,
                bar: getComputedStyle(document.querySelector('.actionbar')).display, barInForm: !!document.querySelector('#bulkForm .actionbar'),
            };
        });
        if (top.pieces.length) fail('not moved to the side panel: ' + top.pieces.join(', '));
        if (top.drawer !== 'hidden') fail('side panel is open on page load');
        if (top.bar !== 'none' || !top.barInForm) fail('action bar is shown before anything is selected: ' + top.bar);
        if (top.plusText !== '+' || top.plus.width > 48) fail('"Nowe zamówienie" is not a plain "+" button: ' + JSON.stringify([top.plusText, top.plus.width]));
        const row = (r) => (r.top + r.bottom) / 2;
        if ([top.sync, top.plus, top.open].some((r) => Math.abs(row(r) - row(top.title)) > 6 || r.right > top.vw)) fail('title row is not one line that fits: ' + JSON.stringify(top));
        if (!(top.search.top > top.title.bottom && top.firstCard.top > top.search.bottom && top.firstCard.top - top.search.bottom < 60)) fail('orders do not follow right after the search: ' + JSON.stringify(top));
        pass('top: title, refresh, "+", panel button, search and right after it the orders');

        // Lista nie przewija się w bok, a każda karta zamówienia mieści się w ekranie.
        const layout = await page.evaluate(() => {
            const scroll = document.querySelector('.orders-table-scroll');
            const vw = document.documentElement.clientWidth;
            const rows = [...document.querySelectorAll('.orders-table tr:not(.orders-head)')];
            return {
                sideways: scroll.scrollWidth - scroll.clientWidth,
                rows: rows.map((tr) => {
                    const r = tr.getBoundingClientRect();
                    const spill = [...tr.querySelectorAll('*')].filter((el) => {
                        const b = el.getBoundingClientRect();
                        return b.width && b.right > r.right + 0.5 && !el.closest('[data-col="email"]');
                    }).map((el) => el.tagName + '.' + el.className);
                    return { display: getComputedStyle(tr).display, right: r.right, vw, spill };
                }),
            };
        });
        if (layout.sideways > 0) fail('order list scrolls sideways by ' + layout.sideways + 'px');
        pass('order list does not scroll sideways');
        if (layout.rows.length !== 3 || layout.rows.some((r) => r.display !== 'grid')) fail('rows are not cards: ' + JSON.stringify(layout.rows));
        if (layout.rows.some((r) => r.right > r.vw)) fail('a card is wider than the screen');
        const spill = layout.rows.flatMap((r) => r.spill);
        if (spill.length) fail('content sticks out of a card: ' + spill.slice(0, 6).join('; '));
        pass('every order is a card that fits the 390px screen');

        // Na karcie widać wszystkie dane zamówienia, każde w swoim miejscu: status obok numeru, kwota i akcje na dole.
        const card = page.locator('.orders-table tr:not(.orders-head)').nth(2);
        for (const col of ['number', 'source', 'client', 'email', 'amount', 'delivery', 'date', 'actions']) {
            if (!(await card.locator(`td[data-col="${col}"]`).isVisible())) fail(col + ' is not visible on the card');
        }
        const pos = await card.evaluate((tr) => {
            const box = (sel) => tr.querySelector(sel).getBoundingClientRect();
            return { num: box('[data-col="number"]'), badge: box('[data-col="status"] > span'), client: box('[data-col="client"]'),
                amount: box('[data-col="amount"]'), act: box('[data-col="actions"]'), email: tr.querySelector('[data-col="email"]') };
        });
        if (Math.abs(pos.num.top - pos.badge.top) > 12 || pos.badge.left < pos.num.left) fail('status badge is not next to the order number');
        if (!(pos.client.top > pos.num.bottom && pos.amount.top > pos.client.bottom)) fail('client is not between the number and the amount');
        if (Math.abs(pos.amount.top - pos.act.top) > 2 || pos.act.left < pos.amount.right - 1) fail('amount and actions are not on the bottom row ' + JSON.stringify([pos.amount, pos.act]));
        pass('number + status, client, source, icons, amount + actions are laid out on the card');

        // Po zaznaczeniu karty pasek akcji wyskakuje na dole ekranu (licznik, status, Pakuj, Scal, Archiwizuj, Do kosza).
        await page.locator('.orders-table tr:not(.orders-head) .rowcheck').first().check();
        if ((await page.locator('#selCount').textContent()).trim() !== '1') fail('selecting a card does not update the counter');
        const shown = await page.evaluate(() => {
            const b = document.querySelector('.actionbar').getBoundingClientRect();
            return { b, vw: document.documentElement.clientWidth, vh: window.innerHeight, pos: getComputedStyle(document.querySelector('.actionbar')).position };
        });
        if (shown.pos !== 'fixed' || Math.abs(shown.b.bottom - (shown.vh - 8)) > 2 || shown.b.left < 0 || shown.b.right > shown.vw || shown.b.height > 220) fail('action bar does not pop up at the bottom of the screen: ' + JSON.stringify(shown));
        if (process.env.SHOTS) await page.screenshot({ path: process.env.SHOTS + '/mobile-selected.png' });
        pass('selecting a card pops the action bar up at the bottom of the screen');
        await page.locator('.actionbar-clear').click();
        if ((await page.locator('.orders-table .rowcheck:checked').count()) !== 0 || await page.locator('.actionbar').isVisible()) fail('"×" does not clear the selection and hide the bar');
        await page.locator('.orders-table tr:not(.orders-head) .rowcheck').first().check();
        pass('"×" clears the selection and hides the bar');

        // Panel wysuwa się z prawej i mieści w ekranie; tło i krzyżyk go zamykają.
        await page.click('#ordersDrawerOpen');
        await page.waitForTimeout(300);
        const dr = await page.evaluate(() => {
            const d = document.getElementById('ordersDrawer').getBoundingClientRect();
            return { d, vw: document.documentElement.clientWidth, vh: window.innerHeight, h: [...document.querySelectorAll('#ordersDrawer h3')].map((h) => h.textContent) };
        });
        if (Math.abs(dr.d.right - dr.vw) > 1 || dr.d.left < 0 || dr.d.top !== 0 || Math.abs(dr.d.height - dr.vh) > 1) fail('side panel is not on the right edge: ' + JSON.stringify(dr));
        if (dr.h.join() !== 'Status') fail('panel sections: ' + dr.h.join());
        // „☐ Wszystkie”, sortowanie i „Filtry” w jednym rzędzie, mieszczą się w panelu.
        const tools = await page.evaluate(() => {
            const box = (sel) => document.querySelector('#ordersDrawer ' + sel).getBoundingClientRect();
            const p = box('.orders-drawer-row');
            const items = [box('.orders-mobile-selall'), box('.orders-mobile-sort select'), box('#ordersFilterToggle')];
            return { p, items, texts: [document.querySelector('#ordersDrawer .orders-mobile-selall').innerText.trim(), document.querySelector('#ordersDrawer #ordersFilterToggle').innerText.trim()] };
        });
        const midY = (r) => (r.top + r.bottom) / 2;
        if (tools.items.some((r) => Math.abs(midY(r) - midY(tools.items[0])) > 2 || r.left < tools.p.left - 0.5 || r.right > tools.p.right + 0.5)) fail('select all, sort and filters are not one row: ' + JSON.stringify(row));
        if (tools.items[0].right > tools.items[1].left || tools.items[1].right > tools.items[2].left) fail('row items overlap: ' + JSON.stringify(tools.items));
        if (tools.texts.join('|') !== 'Wszystkie|Filtry') fail('short labels in the row: ' + tools.texts.join('|'));
        if (process.env.SHOTS) await page.screenshot({ path: process.env.SHOTS + '/mobile-drawer.png' });
        pass('side panel: select all, sort and filters in one row, statuses below');
        await page.click('#ordersDrawerClose');
        await page.waitForTimeout(300);

        // Pasek akcji: licznik na górze, „Zmień status” na całą szerokość, przyciski równo po dwa w rzędzie.
        const ab = await page.evaluate(() => {
            const box = (el) => el.getBoundingClientRect();
            const b = box(document.querySelector('.actionbar'));
            const btns = [...document.querySelectorAll('.actionbar > .btn')].map(box);
            return { bar: b, count: box(document.querySelector('.actionbar-count')), flag: box(document.querySelector('.actionbar .flag-menu')), btns };
        });
        if (ab.count.top >= ab.flag.top) fail('selected counter is not above the action buttons');
        if (ab.flag.width < ab.bar.width - 24) fail('"Zmień status" does not span the action bar');
        if (ab.btns.length < 4) fail('expected at least 4 action buttons, got ' + ab.btns.length);
        for (let i = 0; i + 1 < ab.btns.length; i += 2) {
            const [a, b] = [ab.btns[i], ab.btns[i + 1]];
            if (Math.abs(a.top - b.top) > 1 || Math.abs(a.width - b.width) > 1) fail('action buttons are not in even pairs: ' + JSON.stringify(ab.btns));
        }
        if (ab.btns.some((r) => r.right > ab.bar.right)) fail('an action button sticks out of the bar');
        pass('action bar: counter on top, status full width, buttons in even pairs');
        await page.locator('.actionbar .flag-btn').click();
        const dd = await page.evaluate(() => { const r = document.querySelector('.actionbar .flag-dropdown').getBoundingClientRect(); return { top: r.top, bottom: r.bottom, h: r.height, vh: window.innerHeight }; });
        if (!dd.h || dd.top < 0 || dd.bottom > dd.vh) fail('"Zmień status" list does not fit on the screen: ' + JSON.stringify(dd));
        if (process.env.SHOTS) await page.screenshot({ path: process.env.SHOTS + '/mobile-status.png' });
        await page.locator('.actionbar .flag-btn').click();
        pass('"Zmień status" list opens upwards and fits the screen');
        await page.locator('.actionbar-all').click();
        if ((await page.locator('#selCount').textContent()).trim() !== '3') fail('"Zaznacz wszystkie" on the bar does not select every card');
        await page.locator('.actionbar-clear').click();
        await page.click('#ordersDrawerOpen');
        await page.waitForTimeout(300);
        await page.locator('#ordersDrawer .orders-mobile-selall input').check();
        if ((await page.locator('#selCount').textContent()).trim() !== '3') fail('select all in the side panel does not select every card');
        pass('select all (on the bar and in the side panel) selects every card');

        // Filtry w panelu: zwinięte pod przyciskiem, po kliknięciu się rozwijają; statusy od razu widoczne.
        await page.click('#ordersDrawer #ordersFilterToggle');
        if (!(await page.locator('#ordersDrawer #ordersFilterBar select[name="source"]').isVisible())) fail('filters do not open in the side panel');
        if (!(await page.locator('#ordersDrawer #ordersSideNav .side-item').first().isVisible())) fail('statuses are not listed in the side panel');
        await page.click('#ordersDrawer #ordersFilterToggle');
        pass('filters open in the side panel, statuses are listed');

        // Sortowanie: nagłówki tabeli schowane, lista sortowania w panelu.
        const bar = await page.evaluate(() => ({
            headHidden: getComputedStyle(document.querySelector('.orders-table tr.orders-head')).display === 'none',
            selected: document.querySelector('.orders-mobile-sort select').selectedOptions[0].textContent.trim(),
        }));
        if (!bar.headHidden) fail('table header is shown on the phone');
        if (bar.selected !== 'Data ↓') fail('sort list does not show the current sort: ' + bar.selected);
        await Promise.all([page.waitForNavigation(), page.locator('.orders-mobile-sort select').selectOption({ label: 'Kwota ↑' })]);
        if (!page.url().includes('sort=amount') || !page.url().includes('dir=asc')) fail('choosing a sort does not reload the list: ' + page.url());
        const amounts = await page.$$eval('td[data-col="amount"]', (tds) => tds.map((td) => parseFloat(td.textContent)));
        if (amounts.join() !== [...amounts].sort((a, b) => a - b).join()) fail('list is not sorted by amount: ' + amounts.join());
        pass('sort list in the side panel, choosing a sort reloads the list');

        // „Pobierz teraz” przy tytule (kliknięcie sprawdza tests/orders_sync_now_mysql.php).
        if (await page.locator('#ordersSyncNow').getAttribute('action') !== 'orders_sync_now.php' || !(await page.locator('#ordersSyncNow button').isVisible())) fail('"Pobierz teraz" button is missing');
        pass('"Pobierz teraz" is next to the title');

        // Wyszukiwanie na żywo podmienia listę - „Zaznacz wszystkie” z nowej listy trafia do panelu (jedno, nie dwa), pasek akcji znika.
        await page.fill('#ordersSearchInput', 'Wiktoria');
        await page.waitForFunction(() => document.querySelectorAll('.orders-table tr:not(.orders-head)').length === 1);
        await page.waitForTimeout(50);
        const afterSearch = await page.evaluate(() => ({ bars: document.querySelectorAll('.actionbar').length,
            shown: getComputedStyle(document.querySelector('.actionbar')).display !== 'none',
            sel: document.querySelectorAll('.orders-mobile-selall').length, selInDrawer: !!document.querySelector('#ordersDrawer .orders-mobile-selall') }));
        if (afterSearch.bars !== 1 || afterSearch.shown || afterSearch.sel !== 1 || !afterSearch.selInDrawer) fail('after live search: ' + JSON.stringify(afterSearch));
        pass('live search: one select all in the side panel, action bar hidden until something is selected');

        // Akcja z paska na dole wysyła zaznaczone zamówienia: archiwizacja i przywrócenie.
        await page.goto(BASE + 'admin/index.php');
        await page.locator('.orders-table tr:not(.orders-head) .rowcheck').first().check();
        await Promise.all([page.waitForNavigation(), page.locator('.actionbar .act-sel[data-action="archive"]').click()]);
        if ((await page.locator('.orders-table tr:not(.orders-head)').count()) !== 2) fail('archiving from the side panel did not archive the selected order');
        await page.goto(BASE + 'admin/index.php?view=archived');
        await page.locator('.orders-table tr:not(.orders-head) .rowcheck').first().check();
        await Promise.all([page.waitForNavigation(), page.locator('.actionbar .act-sel[data-action="restore"]').click()]);
        await page.goto(BASE + 'admin/index.php');
        if ((await page.locator('.orders-table tr:not(.orders-head)').count()) !== 3) fail('restoring from the bottom bar did not work');
        pass('bulk actions on the bottom bar submit the selected orders');

        // Przeciągnij w dół na górze strony: wskaźnik rośnie, a po puszczeniu strona się odświeża (zwykły GET tego adresu).
        // Nowa karta: zrzut całej strony (SHOTS) potrafi wyłączyć emulację dotyku w bieżącej.
        const pp = await ctx.newPage();
        pp.on('pageerror', (e) => errors.push(e.message));
        await pp.goto(BASE + 'admin/index.php');
        const touch = (ys, release) => pp.evaluate(({ ys, release }) => {
            const el = document.querySelector('.orders-table');
            const mk = (y) => new Touch({ identifier: 1, target: el, clientX: 200, clientY: y });
            el.dispatchEvent(new TouchEvent('touchstart', { touches: [mk(ys[0])], changedTouches: [mk(ys[0])], bubbles: true, cancelable: true }));
            let prevented = false;
            for (const y of ys.slice(1)) {
                const ev = new TouchEvent('touchmove', { touches: [mk(y)], changedTouches: [mk(y)], bubbles: true, cancelable: true });
                el.dispatchEvent(ev);
                prevented = prevented || ev.defaultPrevented;
            }
            if (release) { el.dispatchEvent(new TouchEvent('touchend', { touches: [], changedTouches: [mk(ys[ys.length - 1])], bubbles: true, cancelable: true })); }
            const ind = document.querySelector('.ptr');
            return { prevented, ready: !!ind && ind.classList.contains('ready'), opacity: ind ? getComputedStyle(ind).opacity : null };
        }, { ys, release });
        await pp.evaluate(() => window.scrollTo(0, 0));
        const steps = [300, 320, 360, 400, 440, 480];
        const half = await touch(steps, false);
        if (!half.prevented || !half.ready) fail('pulling down at the top does not show the refresh indicator: ' + JSON.stringify(half));
        if (process.env.SHOTS) { await pp.waitForTimeout(50); await pp.screenshot({ path: process.env.SHOTS + '/pull-to-refresh.png' }); }
        await pp.evaluate(() => document.dispatchEvent(new TouchEvent('touchcancel', { bubbles: true })));
        await pp.evaluate(() => { window.__notReloaded = true; });
        await touch([300, 310, 320, 330], true);
        if (!(await pp.evaluate(() => window.__notReloaded === true))) fail('a short pull reloads the page');
        await pp.evaluate(() => window.scrollTo(0, 400));
        const scrolled = await touch(steps, true);
        if (scrolled.prevented || !(await pp.evaluate(() => window.__notReloaded === true))) fail('pulling down in the middle of the page refreshes instead of scrolling');
        await pp.evaluate(() => window.scrollTo(0, 0));
        const url = pp.url();
        await Promise.all([pp.waitForNavigation(), touch(steps, true)]);
        if (pp.url() !== url || (await pp.evaluate(() => window.__notReloaded === true))) fail('releasing a full pull does not reload the same page');
        pass('pull to refresh: indicator at the top, full pull reloads the list, short pull and scrolled page do not');


        // Komputer: dalej zwykła tabela, a pasek akcji, filtry i statusy wracają na swoje miejsca (panelu nie ma).
        await page.setViewportSize({ width: 1400, height: 900 });
        if (await page.locator('.orders-table tr:not(.orders-head)').first().evaluate((tr) => getComputedStyle(tr).display) !== 'table-row') fail('desktop lost the table');
        const desk = await page.evaluate(() => ({
            bar: !!document.querySelector('#bulkForm > .actionbar'), toggle: !!document.querySelector('.page-title-row #ordersFilterToggle'),
            nav: !!document.querySelector('.orders-side #ordersSideNav'), filters: !!document.querySelector('.orders-main > #ordersFilterBar'),
            drawer: getComputedStyle(document.getElementById('ordersDrawer')).display, open: getComputedStyle(document.getElementById('ordersDrawerOpen')).display,
            plus: document.querySelector('.page-title-row .new-order-btn').innerText.trim(), form: document.querySelectorAll('.actionbar-mobile:not([hidden])').length
                - [...document.querySelectorAll('.actionbar-mobile')].filter((b) => getComputedStyle(b).display === 'none').length,
            barShown: getComputedStyle(document.querySelector('.actionbar')).position !== 'fixed' && document.querySelector('.actionbar').offsetHeight > 0,
        }));
        if (!desk.bar || !desk.barShown || !desk.toggle || !desk.nav || !desk.filters || desk.drawer !== 'none' || desk.open !== 'none' || desk.form) fail('desktop layout changed: ' + JSON.stringify(desk));
        if (desk.plus !== '+ Nowe zamówienie') fail('desktop "Nowe zamówienie" button lost its label: ' + desk.plus);
        pass('desktop keeps the table, filters, statuses and action bar in place');
        const side = await page.evaluate(() => {
            const h = document.querySelector('.page-title-row h1').getBoundingClientRect();
            const b = document.querySelector('#ordersSyncNow button').getBoundingClientRect();
            return { gap: b.left - h.right, mid: Math.abs((h.top + h.bottom) - (b.top + b.bottom)) / 2, width: b.width };
        });
        if (side.gap < 0 || side.gap > 24 || side.mid > 6 || side.width > 48) fail('"Pobierz teraz" is not a small icon next to the title on desktop: ' + JSON.stringify(side));
        pass('desktop: "Pobierz teraz" is an icon right next to the title');
        if (errors.length) fail('page errors: ' + errors.join('; '));
    } finally {
        await browser.close();
    }
})().catch((e) => { console.error(e.message); process.exit(1); });
