'use strict';
// Asystent pakowania w przeglądarce: komputer (panel) i telefon (sparowany kodem QR).
// Uruchamiany przez tests/packing_http.php (BASE = adres serwera testowego, SHOTS = katalog na zrzuty).
const { chromium, devices } = require('playwright');
const fs = require('fs');

const BASE = process.env.BASE;
const SHOTS = process.env.SHOTS || '/tmp/packing-shots';
fs.mkdirSync(SHOTS, { recursive: true });
let n = 0;
const pass = (m) => console.log('OK (browser): ' + m);
const fail = (m) => { throw new Error('FAIL (browser): ' + m); };

(async () => {
    const browser = await chromium.launch({ executablePath: fs.existsSync('/opt/pw-browsers/chromium') ? '/opt/pw-browsers/chromium' : undefined });
    const errors = [];
    try {
        // ---------------- komputer
        const desk = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        const page = await desk.newPage();
        page.on('pageerror', (e) => errors.push('desktop: ' + e.message));
        await page.goto(BASE + 'admin/login.php');
        await page.fill('#username', 'boss');
        await page.fill('#password', 'Only-Test-Password-42');
        await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
        await page.goto(BASE + 'admin/packing.php?ids=1,2');
        await page.waitForSelector('.pk-item');
        if (await page.locator('.pk-card').count() !== 2) fail('two orders in the list');
        if (await page.locator('.pk-item').count() !== 6) fail('six items');
        pass('desktop shows orders and items');

        // Gabaryt paczki: sugestia z wymiarów produktów, wybór innego kartonu zapisuje się w CRM
        await page.waitForSelector('#pkCarton:not([hidden])');
        const sug = await page.locator('.pk-carton-opt:has(.sug)').innerText();
        if (!sug.includes('Gabaryt B')) fail('carton suggestion is B, got ' + sug);
        if (!(await page.locator('.pk-carton-opt.on').innerText()).includes('Gabaryt B')) fail('suggested carton selected');
        if (!(await page.locator('#pkCartonNote').innerText()).includes('Pumpkin Woods')) fail('note lists product without dimensions');
        await page.locator('#pkCarton').scrollIntoViewIfNeeded();
        await page.screenshot({ path: SHOTS + '/' + (++n) + '-carton.png' });
        await page.locator('.pk-carton-opt', { hasText: 'Gabaryt C' }).click();
        await page.waitForSelector('.pk-carton-opt.on:has-text("Gabaryt C")');
        await page.reload();
        await page.waitForSelector('.pk-carton-opt.on:has-text("Gabaryt C")');
        await page.locator('.pk-carton-opt', { hasText: 'Gabaryt C' }).click();
        await page.waitForSelector('.pk-carton-opt.on:has-text("Gabaryt B")');
        pass('carton suggestion shown, packer can pick another and go back');
        await page.screenshot({ path: `${SHOTS}/${++n}-desktop-start.png` });

        // klik w wiersz = +1, ✓ = wszystkie, − = cofnięcie
        await page.locator('.pk-item').nth(0).click();
        await page.locator('.pk-item').nth(1).locator('.pk-all').click();
        await page.waitForFunction(() => document.querySelectorAll('.pk-item.full').length === 2);
        pass('row click and tick mark items packed');
        await page.locator('.pk-item').nth(1).locator('.pk-stepper button').first().click();
        await page.waitForFunction(() => document.querySelectorAll('.pk-item.full').length === 1);
        await page.click('#pkUndo');
        await page.waitForFunction(() => document.querySelectorAll('.pk-item.full').length === 2);
        pass('minus and undo');

        // czytnik kodów kreskowych (klawiatura + Enter, bez klikania w pole)
        await page.click('.pk-title');
        await page.keyboard.type('5901000000036', { delay: 5 });
        await page.keyboard.press('Enter');
        await page.waitForFunction(() => document.querySelectorAll('.pk-item.full').length === 3);
        pass('barcode scanner adds a piece by EAN');
        await page.fill('#pkScan', 'slc-500');
        await page.press('#pkScan', 'Enter');
        await page.waitForFunction(() => document.querySelectorAll('.pk-item.full').length === 4);
        pass('typed SKU adds a piece');
        await page.screenshot({ path: `${SHOTS}/${++n}-desktop-progress.png` });

        // ustawienia: status po spakowaniu
        await page.click('#pkSettings');
        await page.selectOption('#pkSetDone', 'packed');
        await page.click('#pkSettingsDialog button[value=ok]');
        await page.waitForSelector('.pk-toast.ok');
        pass('settings saved');

        // „Spakowano” przy brakach pyta o potwierdzenie
        await page.click('#pkDone');
        await page.waitForSelector('#pkConfirmDialog[open]');
        await page.screenshot({ path: `${SHOTS}/${++n}-desktop-confirm.png` });
        await page.click('#pkConfirmDialog button[value=cancel]');
        for (const idx of [4, 5]) await page.locator('.pk-item').nth(idx).locator('.pk-all').click();
        await page.waitForFunction(() => document.querySelectorAll('.pk-item.full').length === 6);
        await page.click('#pkDone');
        await page.waitForFunction(() => document.querySelector('.pk-card.st-packed'));
        pass('packed; moves to the next order');
        await page.waitForFunction(() => document.querySelector('.pk-card.active .pk-card-num').textContent === '73937002');
        await page.screenshot({ path: `${SHOTS}/${++n}-desktop-next.png` });

        // QR do telefonu
        await page.click('#pkQr');
        await page.waitForSelector('#pkQrDialog[open]');
        await page.waitForFunction(() => document.getElementById('pkQrTimer').textContent.includes('ważny'));
        await page.screenshot({ path: `${SHOTS}/${++n}-desktop-qr.png` });
        const pairUrl = await page.evaluate(async (csrf) => {
            const fd = new FormData(); fd.append('action', 'pair_code'); fd.append('id', '2');
            const r = await fetch('packing_api.php', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrf } });
            return (await r.json()).url;
        }, await page.evaluate(() => window.PACK_CFG.csrf));
        await page.click('#pkQrDialog button:not([type=button])');
        pass('QR dialog');

        // ---------------- telefon
        const mob = await browser.newContext({ ...devices['iPhone 13'] });
        // Udawany aparat: obraz z kodem (ustawiany z testu) jako strumień wideo; bez BarcodeDetector - jak na iPhonie (ZXing).
        await mob.addInitScript(() => {
            delete window.BarcodeDetector;
            window.__fakeCode = null;
            navigator.mediaDevices.getUserMedia = async () => {
                const c = document.createElement('canvas'); c.width = 1280; c.height = 720;
                const ctx = c.getContext('2d');
                const img = new Image(); let src = null;
                setInterval(() => {
                    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
                    if (window.__fakeCode !== src) { src = window.__fakeCode; if (src) img.src = src; }
                    if (src && img.complete && img.naturalWidth) {
                        const w = Math.min(380, img.naturalWidth * 2), h = w * img.naturalHeight / img.naturalWidth;
                        ctx.drawImage(img, (c.width - w) / 2, (c.height - h) / 2, w, h);
                    }
                }, 60);
                return c.captureStream(15);
            };
        });
        const phone = await mob.newPage();
        phone.on('pageerror', (e) => errors.push('phone: ' + e.message));
        await phone.goto(pairUrl.replace(/^https?:\/\/[^/]+\//, BASE));
        await phone.waitForSelector('.pk-item');
        if (!(await phone.locator('#pkNum').textContent()).includes('73937002')) fail('phone opened the QR order');
        pass('phone paired by QR and opened the order');
        const oneRow = await phone.evaluate(() => {
            const r = (id) => document.getElementById(id).getBoundingClientRect();
            return Math.abs(r('pkNum').top - r('pkClose').top) < 12 && r('pkClose').right <= window.innerWidth;
        });
        if (!oneRow) fail('phone header: number and close button in one row');
        if (await phone.isVisible('#pkScan')) fail('code field hidden under the search button on phone');
        await phone.tap('#pkScanToggle');
        if (!(await phone.isVisible('#pkScan'))) fail('search button shows the code field');
        await phone.tap('#pkScanToggle');
        pass('compact phone header');
        await phone.screenshot({ path: `${SHOTS}/${++n}-phone-order.png` });
        await phone.locator('.pk-item').first().locator('.pk-stepper button').last().tap();
        await phone.waitForFunction(() => document.querySelector('.pk-qty').textContent.startsWith('1'));
        pass('phone tap adds a piece');

        // zdjęcie paczki
        const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
        await phone.setInputFiles('#pkPhotoInput', { name: 'paczka.png', mimeType: 'image/png', buffer: png });
        await phone.waitForSelector('#pkCameraBtn[data-count="1"]');
        pass('parcel photo uploaded from phone');

        // komputer wysyła kolejne zamówienie na telefon
        const sent = await page.evaluate(async (csrf) => {
            const fd = new FormData(); fd.append('action', 'queue_add'); fd.append('ids[]', '3');
            const r = await fetch('packing_api.php', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrf } });
            return (await r.json()).added;
        }, await page.evaluate(() => window.PACK_CFG.csrf));
        if (sent !== 1) fail('queue add');
        await phone.waitForFunction(() => [...document.querySelectorAll('.pk-card-num')].some((e) => e.textContent === 'b7e1c2d4'), null, { timeout: 12000 });
        pass('order sent from computer appears on phone');

        // „Nie spakowano”
        await phone.locator('.pk-card', { hasText: 'b7e1c2d4' }).tap();
        await phone.waitForFunction(() => document.getElementById('pkNum').textContent.includes('b7e1c2d4'));
        await phone.tap('#pkProblem');
        await phone.tap('#pkProblemChips button[data-note="Brak towaru"]');
        await phone.screenshot({ path: `${SHOTS}/${++n}-phone-problem.png` });
        await phone.tap('#pkProblemOk');
        await phone.waitForSelector('.pk-card.active.st-problem');
        pass('phone marks not packed with reason');
        await phone.locator('.pk-card', { hasText: '73937002' }).tap();
        await phone.waitForSelector('#pkCameraBtn[data-count="1"]');
        await phone.tap('#pkInfoText');
        await phone.waitForSelector('#pkPhotos img');
        await phone.tap('#pkPhotos a');
        await phone.waitForSelector('#pkLightboxDelete:not([hidden])');
        await phone.screenshot({ path: `${SHOTS}/${++n}-phone-photo.png` });
        await phone.tap('#pkLightbox .pk-lightbox-close');
        pass('photo opens with a delete button');
        await phone.screenshot({ path: `${SHOTS}/${++n}-phone-list.png`, fullPage: false });

        // desktop widzi zmianę z telefonu (odświeżanie co 5 s)
        await page.waitForFunction(() => document.querySelector('#pkPhotos img'), null, { timeout: 12000 });
        pass('computer sees photo taken on phone');
        await page.click('#pkPhotos a');
        await page.click('#pkLightboxDelete');
        await page.waitForSelector('#pkConfirmDialog[open]');
        await page.click('#pkConfirmOk');
        await page.waitForFunction(() => !document.querySelector('#pkPhotos img') && !document.getElementById('pkCameraBtn').dataset.count);
        pass('photo deleted on computer');

        // pusty ekran telefonu po zamknięciu zamówienia
        await phone.tap('#pkClose');
        await phone.waitForSelector('#pkEmpty:not([hidden])');

        // lista „Do spakowania”: telefon sam wybiera kolejne zamówienie
        await phone.tap('#pkBrowseBtn');
        await phone.waitForSelector('.pk-brow');
        await phone.screenshot({ path: `${SHOTS}/${++n}-phone-browse.png` });
        const rows = await phone.locator('.pk-brow').count();
        if (rows < 3) fail('browse list shows orders to pack');
        await phone.fill('#pkBrowseQ', 'Lewandowska');
        await phone.waitForFunction(() => document.querySelectorAll('.pk-brow').length === 1);
        await phone.locator('.pk-brow').first().tap();
        await phone.waitForFunction(() => document.getElementById('pkNum').textContent.includes('73937005'));
        if (!(await phone.locator('.pk-card', { hasText: '73937005' }).count())) fail('picked order added to phone strip');
        pass('phone picks an order from the to-pack list');

        // skaner aparatem: EAN produktu, QR z linkiem do produktu, kod zamówienia
        let bwip = null;
        try { bwip = require('bwip-js'); } catch (_) { console.log('SKIP (browser): camera scanner (no bwip-js)'); }
        if (bwip) {
            const img = async (bcid, text, extra = {}) => {
                const opts = { bcid, text, scale: 3, height: 14, paddingwidth: 12, paddingheight: 12, backgroundcolor: 'FFFFFF', ...extra };
                if (bcid === 'qrcode') delete opts.height;
                return 'data:image/png;base64,' + (await bwip.toBuffer(opts)).toString('base64');
            };
            const show = (code) => phone.evaluate((c) => { window.__fakeCode = c; }, code);
            const ean = await img('ean13', '5901000000012', { includetext: true });
            const qr = await img('qrcode', 'https://twojsklep.pl/produkt/MW-1L', { scale: 6 });
            const order = await img('code128', '73937004', { includetext: true });
            await phone.tap('#pkCamBtn');
            await phone.waitForSelector('#pkCam:not([hidden])');
            await show(ean);
            await phone.waitForSelector('.pk-cam-result.ok', { timeout: 15000 });
            if (!(await phone.textContent('#pkCamResult')).includes('GINGERBREAD 1l — 1 z 1')) fail('EAN scan marks the product');
            await phone.screenshot({ path: `${SHOTS}/${++n}-phone-camera.png` });
            pass('camera: EAN marks product packed');
            await show(null); await phone.waitForTimeout(1600);
            await show(ean);
            await phone.waitForFunction(() => document.getElementById('pkCamResult').textContent.includes('już spakowane'), null, { timeout: 15000 });
            pass('camera: extra piece over the ordered quantity refused');
            await show(null); await phone.waitForTimeout(1600);
            await show(qr);
            await phone.waitForFunction(() => /MULLED WINE.*1 z 2/.test(document.getElementById('pkCamResult').textContent), null, { timeout: 15000 });
            await phone.waitForTimeout(2500);   // kod trzymany przed aparatem - dalej jedna sztuka
            if (!/1 z 2/.test(await phone.textContent('#pkCamResult'))) fail('code held in front of the camera counts once');
            await show(null); await phone.waitForTimeout(1600);
            await show(qr);
            await phone.waitForFunction(() => /MULLED WINE.*2 z 2/.test(document.getElementById('pkCamResult').textContent), null, { timeout: 15000 });
            pass('camera: QR with product link counts each shown piece once');
            await show(null); await phone.waitForTimeout(1600);
            await show(order);
            await phone.waitForFunction(() => document.getElementById('pkCamOrder').textContent.includes('73937004'), null, { timeout: 15000 });
            pass('camera: order barcode opens that order');
            await show(null);
            await phone.tap('#pkCamClose');
            await phone.waitForSelector('#pkCam', { state: 'hidden' });
            if (!(await phone.locator('#pkNum').textContent()).includes('73937004')) fail('scanned order open after closing camera');

            // 📍 Półki: QR z etykiety półki, potem EAN produktu - produkt dostaje tę lokalizację
            const shelf = await img('qrcode', 'CRMLOC:R1-B', { scale: 6 });
            const cc = await img('ean13', '5901000000036', { includetext: true });
            await phone.tap('#pkBrowseBtn');
            await phone.tap('#pkBrowseLoc');
            await phone.waitForSelector('#pkCam.loc-mode:not([hidden])');
            await show(cc);
            await phone.waitForFunction(() => document.getElementById('pkCamResult').textContent.includes('Najpierw zeskanuj QR'), null, { timeout: 15000 });
            await show(null); await phone.waitForTimeout(1600);
            await show(shelf);
            await phone.waitForFunction(() => document.getElementById('pkCamResult').textContent.includes('R1-B'), null, { timeout: 15000 });
            await show(null); await phone.waitForTimeout(1600);
            await show(cc);
            await phone.waitForFunction(() => /→ R1-B/.test(document.getElementById('pkCamResult').textContent), null, { timeout: 15000 });
            await phone.screenshot({ path: `${SHOTS}/${++n}-phone-shelf.png` });
            await show(null);
            await phone.tap('#pkCamClose');
            await phone.waitForSelector('#pkCam', { state: 'hidden' });
            pass('shelf scanner: shelf QR, then product EAN assigns the location');
        }

        const mobS = await mob.newPage();
        await mobS.setViewportSize({ width: 360, height: 740 });
        await mobS.goto(BASE + 'pack/index.php?order=1');
        await mobS.waitForSelector('.pk-item');
        if (bwip) {   // półkę R1-B nadał wyżej skaner półek (CLEMENTINE CUPCAKE)
            const first = mobS.locator('.pk-item').first();
            if (!(await first.textContent()).includes('CLEMENTINE') || !(await first.locator('.pk-loc').textContent()).includes('R1-B')) fail('item with a shelf first, with its badge');
            if (!(await mobS.locator('.pk-loc.none').count())) fail('items without a shelf are marked');
            pass('shelf badges on items, walking order');
        }
        const overflow = await mobS.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
        if (overflow) fail('no horizontal scroll on a small phone');
        await mobS.screenshot({ path: `${SHOTS}/${++n}-phone-small-packed.png` });
        pass('small phone layout fits the screen');

        if (errors.length) fail('JS errors: ' + errors.join(' | '));
        pass('no JavaScript errors');
    } finally {
        await browser.close();
    }
})().catch((e) => { console.error(e.message || e); process.exit(1); });
