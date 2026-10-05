'use strict';
// Skala interfejsu w przeglądarce. Uruchamiany przez tests/ui_scale_http.php (BASE = adres serwera testowego).
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.BASE;
const pass = (m) => console.log('OK (browser): ' + m);
const fail = (m) => { throw new Error('FAIL (browser): ' + m); };

(async () => {
    const browser = await chromium.launch({ executablePath: fs.existsSync('/opt/pw-browsers/chromium') ? '/opt/pw-browsers/chromium' : undefined });
    const errors = [];
    try {
        const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
        page.on('pageerror', (e) => errors.push(e.message));
        await page.goto(BASE + 'admin/login.php');
        await page.fill('#username', 'boss');
        await page.fill('#password', 'Only-Test-Password-42');
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);

        await page.goto(BASE + 'admin/products.php');
        const zoom = () => page.evaluate(() => document.documentElement.currentCSSZoom);
        const navRight = () => page.evaluate(() => document.documentElement.clientWidth - document.querySelector('.nav-bar').getBoundingClientRect().right);
        if (Math.abs(await zoom() - 1) > 0.001) fail('starts at 100%');

        await page.click('.nav-scale-btn');
        if (!(await page.locator('.nav-scale-pop').isVisible())) fail('switch opens its panel');
        for (let i = 0; i < 4; i++) { await page.click('.nav-scale-step[data-d="-1"]'); }
        if (Math.abs(await zoom() - 0.8) > 0.001) fail('four clicks on − give 80% at once');
        if ((await page.locator('.nav-scale-val').textContent()) !== '80%') fail('button shows 80%');
        // Pasek dalej na całą szerokość okna (zoom nie zostawia pustego miejsca po prawej).
        const gap = await navRight();
        if (gap < 0 || gap > 30) fail('navigation bar still spans the window at 80% (gap ' + gap + ')');
        pass('− button scales the panel to 80% immediately');

        await page.waitForResponse((r) => r.url().includes('ui_scale_save.php'));
        await page.reload();
        if (Math.abs(await zoom() - 0.8) > 0.001) fail('80% kept after reload');
        pass('80% saved and kept after reload');

        // Rozwijane okno lokalizacji (position:fixed, liczone w JS) otwiera się tuż pod przyciskiem.
        const btn = page.locator('.loc-picker:has(input[name="loc[1]"]) button');
        await btn.click();
        const pop = page.locator('.loc-pop.open');
        await pop.waitFor();
        const b = await btn.boundingBox(), p = await pop.boundingBox();
        if (Math.abs(p.x - b.x) > 3 || Math.abs(p.y - (b.y + b.height + 4 * 0.8)) > 4) {
            fail('location popup sits under its button at 80% (button ' + JSON.stringify(b) + ', popup ' + JSON.stringify(p) + ')');
        }
        await page.keyboard.press('Escape');
        pass('location popup positioned under its button at 80%');
        if (process.env.SHOTS) { await page.click('.nav-scale-btn'); await page.screenshot({ path: process.env.SHOTS + '/skala-80.png' }); await page.click('.nav-scale-btn'); }

        // Gotowe wartości (zamiast czułego suwaka) + "Przywróć 100%".
        await page.click('.nav-scale-btn');
        if (await page.locator('.nav-scale-range').count() !== 0) fail('no slider');
        if (!(await page.locator('.nav-scale-preset[data-v="80"]').evaluate((b) => b.classList.contains('on')))) fail('current preset (80%) is highlighted');
        await page.click('.nav-scale-preset[data-v="120"]');
        if (Math.abs(await zoom() - 1.2) > 0.001) fail('preset button sets 120%');
        if (!(await page.locator('.nav-scale-preset[data-v="120"]').evaluate((b) => b.classList.contains('on')))) fail('clicked preset is highlighted');
        if (process.env.SHOTS) { await page.screenshot({ path: process.env.SHOTS + '/skala-120.png' }); }
        await page.click('.nav-scale-reset');
        if (Math.abs(await zoom() - 1) > 0.001 || (await page.locator('.nav-scale-cur').textContent()) !== '100%') fail('reset returns to 100%');
        await page.waitForResponse((r) => r.url().includes('ui_scale_save.php'));
        await page.mouse.click(5, 880);
        if (await page.locator('.nav-scale-pop').isVisible()) fail('click outside closes the panel');
        pass('preset buttons and reset to 100%');

        if (errors.length) fail('no JS errors: ' + errors.join(' | '));
    } finally {
        await browser.close();
    }
})().catch((e) => { console.error(e.message); process.exit(1); });
