'use strict';
// Wybór szablonu odpowiedzi w przeglądarce. Uruchamiany przez tests/reply_templates_http.php (BASE = adres serwera testowego).
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
        const dialogs = [];
        page.on('dialog', (d) => { dialogs.push(d.message()); d.dismiss(); });
        await page.goto(BASE + 'admin/login.php');
        await page.fill('#username', 'boss');
        await page.fill('#password', 'Only-Test-Password-42');
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);

        await page.goto(BASE + 'admin/order_view.php?id=501');
        const body = page.locator('#msgBody');
        await page.click('#tplPickBtn');
        const q = page.locator('#tplPickSearch');
        await q.waitFor();
        if (!(await q.evaluate((el) => el === document.activeElement))) fail('search field gets focus');
        await q.fill('faktu');
        if (await page.locator('#tplPickList .tpl-pick-opt:visible').count() !== 1) fail('search narrows the list to "Faktura"');
        if (process.env.SHOTS) { await page.locator('#tplPick').screenshot({ path: process.env.SHOTS + '/szablon-szukaj.png' }); }
        await q.press('Enter');
        if ((await body.inputValue()) !== 'W załączniku faktura do 501.') fail('Enter fills the reply with the template');
        if ((await page.locator('#tplPickLabel').textContent()) !== 'Faktura') fail('picker shows the chosen template');
        if (await page.locator('#tplPickPanel').isVisible()) fail('panel closes after picking');
        pass('search + Enter fills the reply box');

        // Szablon nadpisuje poprzedni szablon bez pytania, ale własny tekst tylko po potwierdzeniu.
        await page.click('#tplPickBtn');
        await page.locator('#tplPickList .tpl-pick-opt', { hasText: 'Default' }).click();
        if ((await body.inputValue()) !== 'Dzień dobry Anna Kowalska' || dialogs.length !== 0) fail('switching templates replaces the text without asking');
        await body.fill('Mój własny tekst');
        await page.click('#tplPickBtn');
        await page.locator('#tplPickSearch').fill('fakt');
        await page.locator('#tplPickSearch').press('Enter');
        if (dialogs.length !== 1 || (await body.inputValue()) !== 'Mój własny tekst') fail('own text is kept when the operator cancels');
        pass('own text is not overwritten without confirmation');

        await page.click('#tplPickBtn');
        await page.locator('#tplPickSearch').fill('xyz');
        if (!(await page.locator('#tplPickNone').isVisible())) fail('"nothing found" shown');
        await page.locator('#tplPickSearch').press('Escape');
        if (await page.locator('#tplPickPanel').isVisible()) fail('Escape closes the panel');
        pass('empty search result + Escape');

        // Wątek wiadomości: stała wysokość z przewijaniem, przewinięty do najnowszej, długa wiadomość zwinięta.
        const th = await page.locator('#msgThread').evaluate((t) => ({ sh: t.scrollHeight, ch: t.clientHeight, st: t.scrollTop }));
        if (!(th.sh > th.ch && th.ch <= 470)) fail('message thread has fixed height with scrolling');
        if (th.st + th.ch < th.sh - 4) fail('message thread starts scrolled to the newest message');
        const more = page.locator('#msgThread .msg-more');
        if (await more.count() !== 1) fail('only the long message gets "Pokaż całość"');
        const last = more.locator('xpath=preceding-sibling::div[contains(@class,"msg-body")][1]');
        const h1 = await last.evaluate((b) => b.clientHeight);
        await more.click();
        const h2 = await last.evaluate((b) => b.clientHeight);
        if (!(h2 > h1 * 2) || (await more.textContent()).indexOf('Zwiń') !== 0) fail('"Pokaż całość" expands the message');
        await more.click();
        pass('message thread scrolls, long messages collapsed');

        if (process.env.SHOTS) {
            await body.fill('');
            await page.click('#tplPickBtn');
            await page.locator('.sortable-section[data-section="messages"]').screenshot({ path: process.env.SHOTS + '/szablon-lista.png' });
        }
        if (errors.length) fail('page errors: ' + errors.join('; '));
    } finally {
        await browser.close();
    }
})().catch((e) => { console.error(e.message); process.exit(1); });
