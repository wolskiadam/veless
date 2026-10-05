'use strict';
(() => {
    const canvas = document.getElementById('totp-qr');
    if (!canvas || typeof qrcodegen === 'undefined') return;
    try {
        const qr = qrcodegen.QrCode.encodeText(canvas.dataset.uri, qrcodegen.QrCode.Ecc.MEDIUM);
        const border = 4;
        const scale = Math.max(3, Math.floor(300 / (qr.size + border * 2)));
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
        canvas.hidden = true; // The manual enrollment key remains available.
    }
})();
