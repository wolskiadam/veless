'use strict';
// Zrzuty strony System → Kontrola stanu (komputer i telefon). Uruchamiany przez tests/kontrola_http.php z SHOTS=katalog:
// node kontrola_ui.js BASE KATALOG
const { chromium, devices } = require('playwright');
const fs = require('fs');

const [BASE, OUT] = process.argv.slice(2);

(async () => {
    const browser = await chromium.launch({ executablePath: fs.existsSync('/opt/pw-browsers/chromium') ? '/opt/pw-browsers/chromium' : undefined });
    const errors = [];
    try {
        const login = async (ctx) => {
            const page = await ctx.newPage();
            page.on('pageerror', (e) => errors.push(e.message));
            await page.goto(BASE + 'admin/login.php');
            await page.fill('#username', 'ola');
            await page.fill('#password', 'Only-Test-Password-42');
            await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
            return page;
        };
        const desk = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 });
        let page = await login(desk);
        await page.goto(BASE + 'admin/ext.php?page=kontrola.stan');
        await page.screenshot({ path: OUT + '/kontrola-komputer.png', fullPage: true });
        // Menu System z nową pozycją.
        const sys = page.locator('nav [data-group="system"], nav .nav-group:has-text("System")').first();
        if (await sys.count()) { await sys.click().catch(() => {}); await page.waitForTimeout(300); await page.screenshot({ path: OUT + '/menu-system.png' }); }

        const phone = await browser.newContext({ ...devices['iPhone 13'] });
        page = await login(phone);
        await page.goto(BASE + 'admin/ext.php?page=kontrola.stan');
        await page.screenshot({ path: OUT + '/kontrola-telefon.png', fullPage: true });
        await page.goto(BASE + 'admin/index.php?ids=601,603');
        await page.screenshot({ path: OUT + '/lista-wybrane-telefon.png' });
        if (errors.length) { throw new Error('Page errors: ' + errors.join(' | ')); }
    } finally {
        await browser.close();
    }
})().catch((e) => { console.error(e); process.exit(1); });
