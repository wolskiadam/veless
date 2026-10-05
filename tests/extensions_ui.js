'use strict';
// Rozszerzenie w asystencie pakowania (przeglądarka): skrypt z examples/extensions/przyklad rysuje notatkę
// w PackExt.panel i przycisk w PackExt.actions, a przycisk zapisuje notatkę przez akcję serwera.
// Uruchamiany przez tests/extensions_http.php (BASE = adres serwera testowego).
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.BASE;
const SHOTS = process.env.SHOTS || '';   // katalog na zrzuty ekranu (opcjonalnie)
const shot = async (page, name) => { if (SHOTS) { fs.mkdirSync(SHOTS, { recursive: true }); await page.screenshot({ path: SHOTS + '/' + name + '.png', fullPage: false }); } };
const pass = (m) => console.log('OK (browser): ' + m);
const fail = (m) => { throw new Error('FAIL (browser): ' + m); };

(async () => {
    const browser = await chromium.launch({ executablePath: fs.existsSync('/opt/pw-browsers/chromium') ? '/opt/pw-browsers/chromium' : undefined });
    const errors = [];
    try {
        const page = await (await browser.newContext({ viewport: { width: 1280, height: 860 } })).newPage();
        page.on('pageerror', (e) => errors.push(e.message));
        await page.goto(BASE + 'admin/login.php');
        await page.fill('#username', 'boss');
        await page.fill('#password', 'Only-Test-Password-42');
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);

        await page.goto(BASE + 'admin/index.php');
        await shot(page, 'lista-zamowien');
        await page.goto(BASE + 'admin/order_view.php?id=501');
        await shot(page, 'karta-zamowienia');
        await page.goto(BASE + 'admin/packing.php?ids=501');
        await page.waitForSelector('.pk-item');
        const note = await page.locator('#pkExt .pk-note').textContent();
        if (!note.includes('dwa')) fail('note in the extension panel: ' + note);
        if (await page.locator('#pkExt').isHidden()) fail('extension panel visible');
        pass('extension panel shows the note');
        await shot(page, 'pakowanie');

        page.once('dialog', (d) => d.accept('Uwaga: szkło'));
        await page.locator('#pkExtActions button', { hasText: 'Notatka' }).click();
        await page.waitForFunction(() => (document.querySelector('#pkExt .pk-note') || {}).textContent === 'Uwaga: szkło');
        pass('extension button saves the note and redraws');

        await page.goto(BASE + 'admin/packing.php?ids=502');
        await page.waitForSelector('.pk-item');
        if ((await page.locator('#pkExt .pk-note').textContent()) !== 'Delikatne') fail('note of the second order');
        await page.waitForTimeout(5500); // odświeżenie co 5 s rysuje ekran ponownie
        if (await page.locator('#pkExtActions button').count() !== 1 || await page.locator('#pkExt .pk-note').count() !== 1) fail('extension elements not duplicated after redraws');
        pass('elements follow the open order and are not duplicated');
        if (errors.length) fail('JavaScript errors: ' + errors.join(' | '));
        pass('no JavaScript errors');
    } finally {
        await browser.close();
    }
})().catch((e) => { console.error(e.message); process.exit(1); });
