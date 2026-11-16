'use strict';
// Szybki podgląd zamówienia w przeglądarce: komputer (klik w oko, dłuższe najechanie na „Podgląd”, zamykanie)
// i telefon (dotknięcie oka = arkusz od dołu). Uruchamiany przez tests/order_preview_http.php (BASE = serwer testowy).
// SHOTS=katalog - zapisuje zrzuty.
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.BASE;
const SHOTS = process.env.SHOTS;
const pass = (m) => console.log('OK (browser): ' + m);
const fail = (m) => { throw new Error('FAIL (browser): ' + m); };

async function login(page) {
    await page.goto(BASE + 'admin/login.php');
    await page.fill('#username', 'boss');
    await page.fill('#password', 'Only-Test-Password-42');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    await page.goto(BASE + 'admin/index.php');
}

(async () => {
    const browser = await chromium.launch({ executablePath: fs.existsSync('/opt/pw-browsers/chromium') ? '/opt/pw-browsers/chromium' : undefined });
    const errors = [];
    try {
        // ---- Komputer
        const desk = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: SHOTS ? 2 : 1 });
        let page = await desk.newPage();
        page.on('pageerror', (e) => errors.push(e.message));
        await login(page);

        await page.click('.order-peek[data-peek="601"]');
        await page.waitForSelector('#peekPop.is-open .peek-item');
        const d = await page.evaluate(() => {
            const pop = document.getElementById('peekPop');
            const r = pop.getBoundingClientRect();
            const eye = document.querySelector('.order-peek[data-peek="601"]').getBoundingClientRect();
            return {
                text: pop.innerText, items: pop.querySelectorAll('.peek-item').length, imgs: pop.querySelectorAll('img.peek-thumb').length,
                inView: r.left >= 0 && r.top >= 0 && r.right <= innerWidth && r.bottom <= innerHeight, leftOfEye: r.right <= eye.left,
                expanded: document.querySelector('.order-peek[data-peek="601"]').getAttribute('aria-expanded'),
                noteHtml: pop.querySelector('.peek-note').innerHTML,
            };
        });
        if (d.items !== 3 || d.imgs !== 2) fail('items / thumbnails: ' + JSON.stringify(d));
        if (!/Zapach: Lawenda & wanilia/.test(d.text) || !/2 ×/.test(d.text) || !/WAW01M/.test(d.text) || !/Proszę o paczkę bez ulotek/.test(d.text)) fail('content: ' + d.text);
        if (d.noteHtml.includes('<b>')) fail('customer note is not escaped');
        if (!d.inView || !d.leftOfEye || d.expanded !== 'true') fail('position / state: ' + JSON.stringify(d));
        pass('click on the eye shows products, variant, quantity, delivery and note next to the row');
        if (SHOTS) {
            await page.waitForTimeout(300);
            await page.screenshot({ path: SHOTS + '/podglad-komputer.png' });
        }
        // Kliknięte okienko nie znika po zjechaniu kursorem; Escape zamyka.
        await page.mouse.move(10, 10);
        await page.waitForTimeout(400);
        if (!(await page.isVisible('#peekPop'))) fail('pinned preview closed on mouse leave');
        await page.keyboard.press('Escape');
        if (await page.isVisible('#peekPop')) fail('Escape does not close');
        pass('pinned preview stays until Escape');

        // Dłuższe najechanie na „Podgląd” (scalone 602) - pokazuje się samo i znika po zjechaniu.
        await page.hover('[data-peek-hover="602"]');
        await page.waitForSelector('#peekPop.is-open .peek-from', { timeout: 3000 });
        const h = await page.evaluate(() => document.getElementById('peekPop').innerText);
        if (!/z zamówienia 45961/.test(h)) fail('merged item: ' + h);
        // Kursor może przejść do okienka (np. żeby kliknąć „Otwórz zamówienie”).
        const box = await page.locator('#peekPop').boundingBox();
        await page.mouse.move(box.x + box.width / 2, box.y + 40, { steps: 6 });
        await page.waitForTimeout(400);
        if (!(await page.isVisible('#peekPop'))) fail('hover preview closed while moving into it');
        if (SHOTS) { await page.waitForTimeout(300); await page.screenshot({ path: SHOTS + '/podglad-komputer-najechanie.png' }); }
        await page.mouse.move(10, 880, { steps: 4 });
        await page.waitForTimeout(500);
        if (await page.isVisible('#peekPop')) fail('hover preview did not close after leaving');
        pass('long hover on "Podgląd" opens the preview, leaving closes it');

        // Krótkie przejechanie kursorem nie otwiera okienka; klik w „Podgląd” dalej otwiera kartę zamówienia.
        await page.hover('[data-peek-hover="604"]');
        await page.mouse.move(10, 880);
        await page.waitForTimeout(600);
        if (await page.isVisible('#peekPop')) fail('quick pass opened the preview');
        await Promise.all([page.waitForNavigation(), page.click('[data-peek-hover="604"]')]);
        if (!/order_view\.php\?id=604/.test(page.url())) fail('"Podgląd" no longer opens the order: ' + page.url());
        pass('quick pass does not open it and "Podgląd" still opens the order page');
        await desk.close();

        // ---- Telefon
        const mob = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: SHOTS ? 3 : 1, isMobile: true, hasTouch: true });
        page = await mob.newPage();
        page.on('pageerror', (e) => errors.push(e.message));
        await login(page);
        const eye = await page.evaluate(() => { const r = document.querySelector('.order-peek[data-peek="601"]').getBoundingClientRect(); return { w: r.width, h: r.height }; });
        if (eye.w < 40 || eye.h < 40) fail('eye too small to tap: ' + JSON.stringify(eye));
        await page.tap('.order-peek[data-peek="601"]');
        await page.waitForSelector('#peekPop.is-open .peek-item');
        await page.waitForTimeout(250);
        const m = await page.evaluate(() => {
            const r = document.getElementById('peekPop').getBoundingClientRect();
            return { left: r.left, right: r.right, bottom: r.bottom, top: r.top, vw: innerWidth, vh: innerHeight,
                backdrop: !document.getElementById('peekBackdrop').hidden, sw: document.documentElement.scrollWidth };
        });
        if (m.left !== 0 || Math.abs(m.right - m.vw) > 1 || Math.abs(m.bottom - m.vh) > 1 || m.top < m.vh * 0.2 || !m.backdrop || m.sw > m.vw) fail('bottom sheet: ' + JSON.stringify(m));
        pass('tap on the eye opens a bottom sheet');
        if (SHOTS) { await page.screenshot({ path: SHOTS + '/podglad-telefon.png' }); }
        await page.tap('#peekBackdrop', { position: { x: 20, y: 40 } });
        if (await page.isVisible('#peekPop')) fail('tap outside does not close');
        pass('tap outside closes the sheet');
        if (SHOTS) {
            await page.screenshot({ path: SHOTS + '/lista-telefon.png' });
        }
        await mob.close();
        if (errors.length) fail('page errors: ' + errors.join('; '));
    } finally {
        await browser.close();
    }
})().catch((e) => { console.error(e.message); process.exit(1); });
