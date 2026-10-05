/* Przykładowe rozszerzenie: notatka wewnętrzna w asystencie pakowania (API: window.PackExt w packing.js). */
(() => {
    const P = window.PackExt;
    if (!P) return;
    P.on('order', (o) => {
        if (!o) return;
        const note = (o.ext && o.ext.przyklad && o.ext.przyklad.note) || '';
        if (note) P.panel.append(P.el('div', { class: 'pk-note', text: note }));
        if (!P.cfg.canEdit) return;
        P.actions.append(P.el('button', { type: 'button', class: 'pk-btn ghost', text: '📝 Notatka', onclick: async () => {
            const text = window.prompt('Notatka wewnętrzna do zamówienia ' + o.number, note);
            if (text === null) return;
            const r = await P.api('przyklad_note', { id: o.id, note: text });
            if (!r.ok) { P.toast(r.error || 'Nie zapisano.', 'bad'); return; }
            P.toast('Zapisano notatkę.', 'ok');
            P.reload();
        } }));
    });
})();
