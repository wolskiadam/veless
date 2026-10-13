'use strict';
// Strona zamówienia na telefonie (390 px). Uruchamiany przez tests/order_view_mobile_http.php (BASE = adres serwera testowego).
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
        await page.goto(BASE + 'admin/order_view.php?id=9000000551');

        // Każda karta mieści się w ekranie (strona ma overflow-x:clip, więc to, co wystaje, jest po prostu ucięte).
        const wide = await page.evaluate(() => {
            const vw = document.documentElement.clientWidth;
            return [...document.querySelectorAll('.layout-col > .card')]
                .map((c) => ({ id: c.dataset.section, right: Math.round(c.getBoundingClientRect().right) }))
                .filter((c) => c.right > vw);
        });
        if (wide.length) fail('cards wider than the screen: ' + JSON.stringify(wide));
        pass('every section card fits the 390px screen');

        // W kartach nic nie wychodzi poza kartę (tabela z informacjami, link dla klienta, wiadomości, pola formularza);
        // szeroka tabela produktów przewija się we własnej ramce (footer.php), a sama ramka mieści się w karcie.
        const spill = await page.evaluate(() => {
            const out = [];
            for (const sel of ['[data-section="info"]', '[data-section="messages"]', '[data-section="products"]']) {
                const card = document.querySelector(sel);
                const edge = card.getBoundingClientRect().right;
                for (const el of card.querySelectorAll('*')) {
                    const r = el.getBoundingClientRect();
                    if (r.width && r.right > edge + 0.5 && !el.closest('[hidden]') && !el.closest('.msg-thread') && !el.closest('.tbl-scroll')) out.push(sel + ' ' + el.tagName + '.' + el.className + ' ' + Math.round(r.right - edge) + 'px');
                }
                const thread = card.querySelector('.msg-thread');
                if (thread && thread.scrollWidth > thread.clientWidth) out.push(sel + ' .msg-thread scrolls sideways');
            }
            return out;
        });
        if (spill.length) fail('content sticks out of its card: ' + spill.slice(0, 8).join('; '));
        pass('order info, messages and products stay inside their cards');

        if (process.env.SHOTS) {
            await page.locator('[data-section="info"]').screenshot({ path: process.env.SHOTS + '/mobile-info.png' });
            await page.locator('[data-section="messages"]').screenshot({ path: process.env.SHOTS + '/mobile-messages.png' });
            await page.locator('[data-section="products"]').screenshot({ path: process.env.SHOTS + '/mobile-products.png' });
            await page.screenshot({ path: process.env.SHOTS + '/mobile-full.png', fullPage: true });
        }
        if (errors.length) fail('page errors: ' + errors.join('; '));
    } finally {
        await browser.close();
    }
})().catch((e) => { console.error(e.message); process.exit(1); });
