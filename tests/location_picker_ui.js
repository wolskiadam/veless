'use strict';
// Wybór lokalizacji z wyszukiwarką w przeglądarce. Uruchamiany przez tests/location_picker_http.php (BASE = adres serwera testowego).
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
        page.on('dialog', (d) => d.accept());
        await page.goto(BASE + 'admin/login.php');
        await page.fill('#username', 'boss');
        await page.fill('#password', 'Only-Test-Password-42');
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);

        // Wiersz produktu CC-1L (id 3): szukamy po opisie półki, wybieramy Enterem.
        await page.goto(BASE + 'admin/products.php');
        const row = page.locator('.loc-picker:has(input[name="loc[3]"])');
        await row.locator('button').click();
        const q = page.locator('.loc-pop.open .loc-pop-q');
        await q.waitFor();
        if (!(await q.evaluate((el) => el === document.activeElement))) fail('search field gets focus');
        if (await page.locator('.loc-pop-item').count() > 90) fail('long list is capped');
        if (!(await page.locator('.loc-pop-more').isVisible())) fail('"more" hint shown for 300 shelves');
        await q.fill('r7');
        if (process.env.SHOTS) { await page.screenshot({ path: process.env.SHOTS + '/lista-produktow.png', clip: { x: 500, y: 250, width: 940, height: 520 } }); }
        await q.fill('swiece');
        if (await page.locator('.loc-pop-item').count() !== 1) fail('description search without Polish letters finds one shelf');
        await q.press('Enter');
        if (await row.locator('input').inputValue() === '' || (await row.locator('.loc-picker-text').textContent()) !== 'R7-K') fail('Enter picks the shelf');
        if (await page.locator('.loc-pop.open').count() !== 0) fail('popup closes after picking');
        pass('search by description + Enter');

        // Kod bez myślnika i strzałki.
        const row2 = page.locator('.loc-picker:has(input[name="loc[2]"])');
        await row2.locator('button').click();
        await q.fill('r1');
        const first = await page.locator('.loc-pop-item.active .loc-pop-code').textContent();
        if (!first.startsWith('R1')) fail('code prefix matches first, got ' + first);
        await q.fill('r12y');
        if ((await page.locator('.loc-pop-item.active .loc-pop-code').textContent()).replace('✓ ', '') !== 'R12-Y') fail('compact code "r12y" finds R12-Y');
        await q.press('Escape');
        if (await page.locator('.loc-pop.open').count() !== 0 || (await row2.locator('input').inputValue()) !== '') fail('Escape closes without changing');
        pass('compact code search, Escape cancels');

        // Pisanie na przycisku otwiera wyszukiwarkę z tą literą.
        await row2.locator('button').focus();
        await page.keyboard.type('r3c');
        if ((await q.inputValue()) !== 'r3c') fail('typing on the button starts the search');
        await page.keyboard.press('Escape');
        pass('type-to-search from the button');

        // Akcja zbiorcza bez wyboru nie wysyła formularza.
        await page.locator('.prod-check[value="2"]').check();
        await page.locator('button[form="bulkLocForm"]').click();
        await page.locator('.loc-pop.open').waitFor();
        if (!page.url().endsWith('products.php')) fail('bulk without a choice stays on the page');
        await page.keyboard.press('Escape');
        pass('bulk action requires a choice');

        // Zapis wiersza przyciskiem „Zapisz wszystkie zmiany”. Stany i ceny pomijamy: ich zapis kolejkuje
        // wypych do sklepu (INSERT IGNORE w MySQL), którego baza SQLite w teście nie obsługuje.
        await page.evaluate(() => document.querySelectorAll('input[name^="stock["], input[name^="price["]').forEach((el) => el.remove()));
        await Promise.all([page.waitForNavigation(), page.locator('button[form="saveAllForm"]').first().click()]);
        if (!(await page.content()).includes('Zmieniono lokalizację: 1')) fail('save reports one changed location');
        pass('row choice saved');

        // Filtr: wybór wysyła formularz.
        await page.locator('.loc-picker:has(input#loc) button').click();
        await q.fill('R7-K');
        await Promise.all([page.waitForNavigation(), q.press('Enter')]);
        if (!/[?&]loc=\d+/.test(page.url()) || !(await page.content()).includes('CC-1L') || (await page.content()).includes('MW-1L')) fail('filter applies on pick');
        pass('filter applies immediately');

        // Strona produktu: kod + opis na przycisku, wyszukiwanie działa tak samo.
        await page.goto(BASE + 'admin/product_view.php?id=1');
        await page.locator('.loc-picker button').click();
        await q.fill('r12');
        if (process.env.SHOTS) { await page.screenshot({ path: process.env.SHOTS + '/produkt.png', fullPage: false }); }
        await q.press('ArrowDown');
        await q.press('Enter');
        if (!(await page.locator('.loc-picker-text').textContent()).startsWith('R12-B · Regał 12, półka B')) fail('arrow + Enter on the product page');
        pass('product page picker');
        if (errors.length) fail('page errors: ' + errors.join(' | '));
    } finally {
        await browser.close();
    }
})().catch((e) => { console.error(e.message); process.exit(1); });
