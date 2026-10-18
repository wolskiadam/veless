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
            await page.screenshot({ path: process.env.SHOTS + '/mobile-orders.png', fullPage: true });
            await page.locator('.actionbar').screenshot({ path: process.env.SHOTS + '/mobile-actionbar.png' });
        }

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

        // Zaznaczanie działa jak w tabeli (licznik na pasku akcji), a „Zaznacz wszystkie” zaznacza wszystkie karty.
        await page.locator('.orders-table tr:not(.orders-head) .rowcheck').first().check();
        if ((await page.locator('#selCount').textContent()).trim() !== '1') fail('selecting a card does not update the counter');
        pass('selecting a card updates the counter');

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
        await page.locator('.orders-mobile-selall input').check();
        if ((await page.locator('#selCount').textContent()).trim() !== '3') fail('select all does not select every card');
        pass('select all selects every card');

        // Sortowanie: nagłówki tabeli schowane, zamiast nich jedna lista w jednym wierszu z „Zaznacz wszystkie”.
        const bar = await page.evaluate(() => {
            const head = document.querySelector('.orders-table tr.orders-head');
            const sel = document.querySelector('.orders-mobile-sort select').getBoundingClientRect();
            const all = document.querySelector('.orders-mobile-selall input').getBoundingClientRect();
            return { headHidden: getComputedStyle(head).display === 'none', oneRow: Math.abs((sel.top + sel.bottom) - (all.top + all.bottom)) < 4,
                selected: document.querySelector('.orders-mobile-sort select').selectedOptions[0].textContent.trim() };
        });
        if (!bar.headHidden || !bar.oneRow) fail('sort bar is not one row: ' + JSON.stringify(bar));
        if (bar.selected !== 'Data ↓') fail('sort list does not show the current sort: ' + bar.selected);
        await Promise.all([page.waitForNavigation(), page.locator('.orders-mobile-sort select').selectOption({ label: 'Kwota ↑' })]);
        if (!page.url().includes('sort=amount') || !page.url().includes('dir=asc')) fail('choosing a sort does not reload the list: ' + page.url());
        const amounts = await page.$$eval('td[data-col="amount"]', (tds) => tds.map((td) => parseFloat(td.textContent)));
        if (amounts.join() !== [...amounts].sort((a, b) => a - b).join()) fail('list is not sorted by amount: ' + amounts.join());
        pass('one-row sort list next to select all, choosing a sort reloads the list');

        // „Pobierz teraz” obok filtrów: widoczny, mieści się w ekranie (kliknięcie sprawdza tests/orders_sync_now_mysql.php).
        const sync = await page.evaluate(() => {
            const b = document.querySelector('#ordersSyncNow button').getBoundingClientRect();
            const f = document.getElementById('ordersFilterToggle').getBoundingClientRect();
            return { b, f, vw: document.documentElement.clientWidth, form: document.getElementById('ordersSyncNow').getAttribute('action') };
        });
        if (sync.form !== 'orders_sync_now.php' || !sync.b.width) fail('"Pobierz teraz" button is missing');
        if (sync.b.right > sync.vw || Math.abs(sync.b.top - sync.f.top) > 2) fail('"Pobierz teraz" is not next to the filters: ' + JSON.stringify(sync));
        pass('"Pobierz teraz" sits next to the filters and fits the screen');

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


        // Komputer: dalej zwykła tabela.
        await page.setViewportSize({ width: 1400, height: 900 });
        if (await page.locator('.orders-table tr:not(.orders-head)').first().evaluate((tr) => getComputedStyle(tr).display) !== 'table-row') fail('desktop lost the table');
        pass('desktop keeps the table');
        if (errors.length) fail('page errors: ' + errors.join('; '));
    } finally {
        await browser.close();
    }
})().catch((e) => { console.error(e.message); process.exit(1); });
