/* Produkcja: numer partii przy pozycji zamówienia w asystencie pakowania (API: window.PackExt). */
(() => {
    const P = window.PackExt;
    if (!P) return;
    P.on('order', (o) => {
        const ext = o && o.ext && o.ext.produkcja;
        if (!ext) return;
        const idxs = Object.keys(ext.items || {});
        if (!idxs.length) return;
        const box = P.el('div', { class: 'pk-prod', style: 'background:var(--surface,#fff);border:1px solid var(--line,#e6e3da);border-radius:8px;padding:8px 10px;font-size:14px' });
        box.append(P.el('b', { text: '🕯 Numer partii' }));
        idxs.forEach((idx) => {
            const it = o.items.find((x) => String(x.idx) === idx);
            if (!it) return;
            const listId = 'pr-pk-' + idx;
            const list = P.el('datalist', { id: listId });
            (ext.items[idx] || []).forEach((c) => list.append(P.el('option', { value: c })));
            const input = P.el('input', { value: (ext.assigned && ext.assigned[idx]) || '', placeholder: 'np. ' + ((ext.items[idx] || [])[0] || 'AL001BW02AABA'),
                list: listId, style: 'width:170px;text-transform:uppercase;font-family:var(--font-num, monospace);padding:6px 8px;border:1px solid var(--line);border-radius:6px' });
            if (!P.cfg.canEdit) input.disabled = true;
            const save = async () => {
                const code = input.value.trim().toUpperCase();
                if (code === ((ext.assigned && ext.assigned[idx]) || '')) return;
                const r = await P.api('produkcja_partia', { id: o.id, idx: Number(idx), code });
                if (!r.ok) { P.toast(r.error || 'Nie zapisano numeru partii.', 'bad'); return; }
                ext.assigned[idx] = code;
                P.toast(code === '' ? 'Usunięto numer partii.' : (r.known ? 'Partia ' + code + ' przypisana.' : 'Zapisano ' + code + ' (nie ma takiej partii w produkcji).'), r.known ? 'ok' : 'bad');
            };
            input.addEventListener('change', save);
            input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); input.blur(); } });
            box.append(P.el('div', { style: 'display:flex;gap:10px;align-items:center;justify-content:space-between;margin-top:6px;flex-wrap:wrap' },
                P.el('span', { text: it.qty + ' × ' + it.name }), input, list));
        });
        P.panel.append(box);
    });
})();
