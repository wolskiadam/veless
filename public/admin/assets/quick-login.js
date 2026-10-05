'use strict';
// Szybkie logowanie: QR z adresem zatwierdzenia i pytanie serwera co 2 s, czy drugie urządzenie zatwierdziło.
(() => {
    const box = document.getElementById('quick');
    const script = document.getElementById('quick-script');
    if (!box || !script) return;
    const code = box.dataset.code;
    const deadline = Date.now() + Number(box.dataset.expiresIn) * 1000;
    const left = document.getElementById('quick-left');
    const status = document.getElementById('quick-status');
    const renew = document.getElementById('quick-new');

    const canvas = document.getElementById('quick-qr');
    try {
        const url = new URL('quick_approve.php?code=' + encodeURIComponent(code), location.href).href;
        const qr = qrcodegen.QrCode.encodeText(url, qrcodegen.QrCode.Ecc.MEDIUM);
        const border = 4;
        const scale = Math.max(3, Math.floor(220 / (qr.size + border * 2)));
        canvas.width = canvas.height = (qr.size + border * 2) * scale;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#000';
        for (let y = 0; y < qr.size; y++) {
            for (let x = 0; x < qr.size; x++) {
                if (qr.getModule(x, y)) ctx.fillRect((x + border) * scale, (y + border) * scale, scale, scale);
            }
        }
    } catch (_) {
        canvas.hidden = true; // Kod do przepisania nadal jest widoczny.
    }

    let done = false;
    const finish = (message) => {
        done = true;
        status.textContent = message;
        canvas.hidden = true;
        renew.hidden = false;
    };
    const tick = () => {
        if (done) return;
        const seconds = Math.max(0, Math.round((deadline - Date.now()) / 1000));
        if (left) left.textContent = String(seconds);
    };
    const poll = async () => {
        if (done) return;
        try {
            const body = new URLSearchParams({ csrf: script.dataset.csrf, action: 'poll' });
            const response = await fetch('login_quick.php', { method: 'POST', body, credentials: 'same-origin' });
            const data = await response.json();
            if (data.status === 'ok') {
                done = true;
                status.textContent = 'Zatwierdzono. Logowanie…';
                location.href = 'index.php';
                return;
            }
            if (data.status === 'rejected') return finish('Logowanie odrzucono na drugim urządzeniu.');
            if (data.status === 'expired' || data.status === 'error') return finish('Kod wygasł. Wygeneruj nowy.');
        } catch (_) {
            // Chwilowy brak sieci: spróbuj przy następnym takcie, dopóki kod jest ważny.
        }
        if (Date.now() > deadline + 5000) return finish('Kod wygasł. Wygeneruj nowy.');
        setTimeout(poll, 2000);
    };
    setInterval(tick, 1000);
    setTimeout(poll, 2000);
})();
