'use strict';
// Okienko „Ta etykieta była już drukowana” na karcie zamówienia. Uruchamiany przez tests/label_prints_http.php.
// Przesyłka 72 ma już jeden druk: „Nie drukuj” nic nie robi, „Drukuj ponownie” wysyła do agenta z again=1.
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.BASE;
const pass = (m) => console.log('OK (browser): ' + m);
const SHOTS = process.env.SHOT_DIR || ''; // zrzuty ekranu do opisu zmian (opcjonalnie)
const fail = (m) => { throw new Error('FAIL (browser): ' + m); };

(async () => {
    const browser = await chromium.launch({ executablePath: fs.existsSync('/opt/pw-browsers/chromium') ? '/opt/pw-browsers/chromium' : undefined });
    const errors = [];
    try {
        const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
        const page = await ctx.newPage();
        page.on('pageerror', (e) => errors.push(e.message));
        page.on('dialog', (d) => { errors.push('unexpected dialog: ' + d.message()); d.dismiss(); });
        await page.goto(BASE + 'admin/login.php');
        await page.fill('#username', 'boss');
        await page.fill('#password', 'Only-Test-Password-42');
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
        await page.goto(BASE + 'admin/order_view.php?id=552');

        const agentRequests = [];
        page.on('request', (r) => { if (r.url().includes('label_download.php') && r.url().includes('agent=1')) agentRequests.push(r.url()); });

        await page.click('.lbl-menu[data-ship="72"] .lbl-toggle');
        await page.click('.lbl-agent[data-ship="72"]');
        await page.waitForSelector('.lbl-again:not([hidden])');
        const txt = await page.textContent('.lbl-again-txt');
        if (!/Wydrukowano już 1×\. Pierwszy raz .* \(Adam\)\./.test(txt)) fail('warning text: ' + txt);
        pass('second print opens the warning with who printed first');

        const box = await page.evaluate(() => { const b = document.querySelector('.lbl-again-box').getBoundingClientRect(); return { l: b.left, r: b.right, vw: innerWidth }; });
        if (box.l < 0 || box.r > box.vw) fail('warning wider than the phone screen: ' + JSON.stringify(box));
        pass('warning fits the phone screen');

        if (SHOTS) await page.screenshot({ path: SHOTS + '/ostrzezenie-telefon.png' });
        await page.click('.lbl-again-no');
        await page.waitForTimeout(300);
        if (agentRequests.length) fail('„Nie drukuj” still sent: ' + agentRequests.join(', '));
        if (!(await page.isHidden('.lbl-again'))) fail('warning did not close');
        pass('„Nie drukuj” closes without printing');

        await page.click('.lbl-menu[data-ship="72"] .lbl-toggle');
        await page.click('.lbl-agent[data-ship="72"]');
        await page.waitForSelector('.lbl-again:not([hidden])');
        await page.click('.lbl-again-yes');
        await page.waitForFunction(() => /Wydrukowano 2×/.test(document.querySelector('.lbl-count[data-ship="72"]').textContent));
        if (agentRequests.length !== 1 || !agentRequests[0].includes('again=1')) fail('confirmed reprint request: ' + agentRequests.join(', '));
        const red = await page.evaluate(() => document.querySelector('.lbl-count[data-ship="72"]').classList.contains('many'));
        if (!red) fail('badge not red after 2nd print');
        pass('„Drukuj ponownie” sends with again=1 and the badge turns red „Wydrukowano 2×”');
        if (SHOTS) {
            const desk = await browser.newPage({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 });
            await desk.goto(BASE + 'admin/login.php');
            await desk.fill('#username', 'boss');
            await desk.fill('#password', 'Only-Test-Password-42');
            await Promise.all([desk.waitForNavigation(), desk.click('button[type=submit]')]);
            await desk.goto(BASE + 'admin/order_view.php?id=552');
            const t = await desk.$('.ship-table');
            await t.evaluate((el) => el.closest('.card').scrollIntoView());
            await (await t.evaluateHandle((el) => el.closest('.card'))).screenshot({ path: SHOTS + '/karta-zamowienia.png' });
            await desk.goto(BASE + 'admin/index.php');
            await desk.screenshot({ path: SHOTS + '/lista-zamowien.png' });
            await page.goto(BASE + 'admin/index.php');
            await page.screenshot({ path: SHOTS + '/lista-telefon.png' });
            await desk.close();
        }

        if (errors.length) fail('page errors: ' + errors.join(' | '));
    } finally {
        await browser.close();
    }
})().catch((e) => { console.error(e.message); process.exit(1); });
