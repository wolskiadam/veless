'use strict';
// Zrzuty listy produkcji: przycisk na liście zamówień (komputer, pasek na telefonie) i strona do druku.
// Uruchamiany przez tests/production_list_http.php z SHOTS=katalog: node production_list_ui.js BASE KATALOG
const { chromium } = require('playwright');
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
            await page.fill('#username', 'boss');
            await page.fill('#password', 'Only-Test-Password-42');
            await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
            return page;
        };
        const select = async (page, ids) => {
            for (const id of ids) { await page.check('.rowcheck[value="' + id + '"]'); }
        };

        // Komputer: lista zamówień z trzema zaznaczonymi.
        const desk = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 });
        let page = await login(desk);
        await page.goto(BASE + 'admin/index.php');
        await select(page, [101, 102, 103]);
        await page.screenshot({ path: OUT + '/lista-zamowien-komputer.png', clip: { x: 0, y: 0, width: 1440, height: 560 } });
        await Promise.all([page.waitForNavigation(), page.click('.btn-prodlist')]);
        if (!page.url().includes('production_list.php?ids=')) { throw new Error('Button did not open the list'); }
        await page.screenshot({ path: OUT + '/lista-produkcji-komputer.png', fullPage: true });
        await page.emulateMedia({ media: 'print' });
        await page.pdf({ path: OUT + '/lista-produkcji.pdf', format: 'A4', printBackground: true });
        await page.setViewportSize({ width: 794, height: 1123 });
        await page.screenshot({ path: OUT + '/lista-produkcji-wydruk.png', fullPage: true });

        // Telefon: pasek akcji na dole po zaznaczeniu, potem lista.
        const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
        page = await login(phone);
        await page.goto(BASE + 'admin/index.php');
        await select(page, [101, 102, 103]);
        await page.waitForTimeout(200);
        await page.screenshot({ path: OUT + '/lista-zamowien-telefon.png' });
        await Promise.all([page.waitForNavigation(), page.click('.btn-prodlist')]);
        await page.screenshot({ path: OUT + '/lista-produkcji-telefon.png', fullPage: true });

        // iPhone, aplikacja z ekranu początkowego (navigator.standalone): „Drukuj” udostępnia plik PDF (arkusz iOS z „Drukuj”).
        const app = await browser.newContext({ ...require('playwright').devices['iPhone 13'] });
        await app.addInitScript(() => {
            Object.defineProperty(Navigator.prototype, 'standalone', { get: () => true });
            window.__shared = [];
            window.__printed = 0;
            window.print = () => { window.__printed++; };
            Navigator.prototype.canShare = () => true;
            Navigator.prototype.share = (d) => { window.__shared.push({ name: d.files[0].name, type: d.files[0].type, size: d.files[0].size }); return Promise.resolve(); };
        });
        page = await login(app);
        await page.goto(BASE + 'admin/production_list.php?ids=101,102,103');
        await page.waitForFunction(() => !document.getElementById('printBtn').disabled);
        await page.screenshot({ path: OUT + '/aplikacja-drukuj.png', clip: { x: 0, y: 0, width: 390, height: 300 } });
        await page.click('#printBtn');
        let shared = await page.evaluate(() => ({ s: window.__shared, p: window.__printed }));
        if (shared.p !== 0 || shared.s.length !== 1 || shared.s[0].type !== 'application/pdf' || shared.s[0].size < 1000) { throw new Error('App mode: PDF not shared ' + JSON.stringify(shared)); }
        if (page.url().indexOf('production_list.php?ids=') < 0 || app.pages().length !== 1) { throw new Error('App mode: left the page or opened a new one'); }
        await page.uncheck('#showOrders');
        await page.waitForFunction(() => !document.getElementById('printBtn').disabled);
        await page.click('#printBtn');
        shared = await page.evaluate(() => window.__shared);
        if (shared.length !== 2 || shared[1].size >= shared[0].size) { throw new Error('App mode: PDF without numbers not refreshed ' + JSON.stringify(shared)); }

        // Ta sama strona otwarta zwykle (nie z aplikacji): zwykły przycisk drukowania.
        const plain = await browser.newContext({ ...require('playwright').devices['iPhone 13'] });
        page = await login(plain);
        await page.goto(BASE + 'admin/production_list.php?ids=101');
        await page.evaluate(() => { window.__printed = 0; window.print = () => { window.__printed++; }; });
        await page.click('#printBtn');
        if (await page.evaluate(() => window.__printed) !== 1) { throw new Error('Safari: window.print expected'); }

        if (errors.length) { throw new Error('Page errors: ' + errors.join('; ')); }
        console.log('OK (browser): screenshots in ' + OUT);
    } finally {
        await browser.close();
    }
})().catch((e) => { console.error(e.message); process.exit(1); });
