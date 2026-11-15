/* Zapachy: numer zapachu (miejsce olejku w magazynie) przy pozycji zamówienia w asystencie pakowania (API: window.PackExt). */
(() => {
    const P = window.PackExt;
    if (!P) return;
    const css = document.createElement('style');
    css.textContent = '.zp-pk{display:inline-flex;gap:5px;align-items:baseline;padding:2px 9px;border-radius:999px;background:#f3ecfb;color:#4b2a7a;font-size:13px;font-weight:500;white-space:nowrap}'
        + '.zp-pk b{font-family:var(--font-num, monospace);font-weight:700;font-size:14px}';
    document.head.append(css);
    let last = null;
    const paint = (o) => {
        const ext = o && o.ext && o.ext.zapachy;
        if (!ext) return;
        Object.keys(ext).forEach((idx) => {
            const row = document.querySelector('.pk-item[data-idx="' + idx + '"]');
            const tags = row && row.querySelector('.pk-tags');
            if (!tags || tags.querySelector('.zp-pk')) return;
            (ext[idx] || []).forEach((f) => {
                tags.prepend(P.el('span', { class: 'zp-pk', title: 'Zapach - numer miejsca w magazynie' },
                    P.el('b', { text: f.no ? '#' + f.no : '#–' }), f.name));
            });
        });
    };
    P.on('order', (o) => { last = o; paint(o); });
    // Pozycja rysuje się od nowa po zmianie liczby sztuk - plakietkę dokładamy ponownie.
    P.on('item', (it, o) => { setTimeout(() => paint(o || last), 0); });
})();
