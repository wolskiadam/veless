/* Asystent pakowania (panel i telefon). Konfiguracja w window.PACK_CFG:
 *   mode: 'panel' | 'phone', api, photoUrl, orderUrl ('' = bez linku), csrf, ids[], openId,
 *   isAdmin, canEdit, backUrl, useQueue (lista = kolejka „na telefon”)
 */
'use strict';
(() => {
    const cfg = window.PACK_CFG || {};
    const $ = (id) => document.getElementById(id);
    const el = (tag, attrs = {}, ...children) => {
        const n = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) {
            if (v === null || v === undefined || v === false) continue;
            if (k === 'class') n.className = v;
            else if (k === 'text') n.textContent = v;
            else if (k.startsWith('on')) n.addEventListener(k.slice(2), v);
            else n.setAttribute(k, v === true ? '' : v);
        }
        for (const c of children.flat()) if (c !== null && c !== undefined && c !== false) n.append(c.nodeType ? c : String(c));
        return n;
    };
    const svg = (paths) => { const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); s.setAttribute('viewBox', '0 0 24 24'); s.innerHTML = paths; return s; };

    const state = {
        list: [],          // [{id, number, customer, packed, total, state}]
        current: null,     // pełne zamówienie
        undo: [], redo: [],
        busy: 0,
        settings: null,
        browse: false,     // widok „📋 Do spakowania”
        browseStatus: '', browseQ: '', browseOffset: 0,
    };

    // ---------------------------------------------------------------- API
    async function api(action, params = {}, post = false, file = null) {
        let url = cfg.api + (cfg.api.includes('?') ? '&' : '?');
        const opts = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
        if (post) {
            const fd = new FormData();
            fd.append('action', action);
            for (const [k, v] of Object.entries(params)) {
                if (Array.isArray(v)) v.forEach((x) => fd.append(k + '[]', x)); else fd.append(k, v);
            }
            if (file) fd.append('photo', file);
            opts.method = 'POST';
            opts.body = fd;
            if (cfg.csrf) opts.headers['X-CSRF-Token'] = cfg.csrf;
            opts.headers['X-Requested-With'] = 'pack';
            url = cfg.api;
        } else {
            const qs = new URLSearchParams({ action });
            for (const [k, v] of Object.entries(params)) {
                if (Array.isArray(v)) v.forEach((x) => qs.append(k + '[]', x)); else qs.append(k, v);
            }
            url += qs.toString();
        }
        state.busy++;
        try {
            const res = await fetch(url, opts);
            let data;
            try { data = await res.json(); } catch (_) {
                data = { ok: false, error: cfg.mode === 'panel' ? 'Sesja wygasła — zaloguj się ponownie.' : 'Brak połączenia z CRM.' };
            }
            if (data.unpaired) { location.reload(); }
            return data;
        } catch (_) {
            return { ok: false, error: 'Brak połączenia z internetem — spróbuj jeszcze raz.', offline: true };
        } finally {
            state.busy--;
        }
    }

    // ---------------------------------------------------------------- rozszerzenia (extensions/)
    // Skrypty rozszerzeń (Hooks::addAsset('packing', …)) ładują się po tym pliku i używają window.PackExt:
    //   PackExt.on('order', (o) => …)  - po narysowaniu zamówienia (o = null, gdy nic nie jest otwarte),
    //   PackExt.on('item', (it, o) => …), PackExt.on('packed', (o) => …), PackExt.on('problem', (o) => …),
    //   PackExt.api('nazwa', {id: o.id}) - akcja serwera z Hooks::addPackingAction('nazwa', …),
    //   PackExt.panel / PackExt.actions - miejsca na własne elementy (czyszczone przy każdym rysowaniu).
    const extHandlers = {};
    function emit(name, ...args) {
        for (const fn of extHandlers[name] || []) {
            try { fn(...args); } catch (e) { console.error('[rozszerzenie pakowania] ' + name, e); }
        }
    }

    // ---------------------------------------------------------------- dźwięk i komunikaty
    let audio;
    function beep(ok = true) {
        try {
            audio = audio || new (window.AudioContext || window.webkitAudioContext)();
            const o = audio.createOscillator(); const g = audio.createGain();
            o.frequency.value = ok ? 880 : 220; o.type = ok ? 'sine' : 'square';
            g.gain.value = 0.08; o.connect(g); g.connect(audio.destination);
            o.start(); o.stop(audio.currentTime + (ok ? 0.08 : 0.25));
        } catch (_) { /* bez dźwięku */ }
        if (!ok && navigator.vibrate) navigator.vibrate(180);
    }
    let toastTimer;
    function toast(msg, kind = '') {
        const t = $('pkToast');
        t.textContent = msg; t.className = 'pk-toast ' + kind; t.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { t.hidden = true; }, kind === 'bad' ? 4500 : 2800);
    }
    function confirmBox(title, text, okLabel = 'OK') {
        return new Promise((resolve) => {
            const d = $('pkConfirmDialog');
            $('pkConfirmTitle').textContent = title; $('pkConfirmText').textContent = text; $('pkConfirmOk').textContent = okLabel;
            d.returnValue = '';
            d.addEventListener('close', () => resolve(d.returnValue === 'ok'), { once: true });
            d.showModal();
        });
    }

    // ---------------------------------------------------------------- lista zamówień
    function summaryOf(o) {
        const packed = o.items.reduce((a, i) => a + i.packed, 0);
        const total = o.items.reduce((a, i) => a + i.qty, 0);
        return { id: o.id, number: o.number, customer: o.customer, packed, total, state: o.state };
    }
    function upsertSummary(s) {
        const i = state.list.findIndex((x) => x.id === s.id);
        if (i >= 0) state.list[i] = s; else state.list.push(s);
    }
    function renderList() {
        const box = $('pkList');
        box.replaceChildren();
        if (!state.list.length) {
            box.append(el('div', { class: 'pk-list-empty', text: cfg.mode === 'phone' ? 'Kolejka jest pusta.' : 'Brak zamówień.' }));
        }
        for (const s of state.list) {
            const done = s.state === 'packed';
            const card = el('div', {
                class: 'pk-card st-' + s.state + (!state.browse && state.current && state.current.id === s.id ? ' active' : ''),
                role: 'button', tabindex: '0', 'data-id': s.id,
                onclick: () => openOrder(s.id),
                onkeydown: (e) => { if (e.key === 'Enter') openOrder(s.id); },
            },
                el('div', { class: 'pk-card-num', text: s.number }),
                el('div', { class: 'pk-card-name', text: s.customer || '—' }),
                el('div', { class: 'pk-card-badge', text: done ? '✓' : (s.state === 'problem' ? '!' : s.packed + '/' + s.total) }),
                el('button', { type: 'button', class: 'pk-card-x', title: 'Usuń z listy', 'aria-label': 'Usuń z listy', text: '✕',
                    onclick: (e) => { e.stopPropagation(); removeFromList(s.id); } }),
            );
            box.append(card);
        }
        const packedCount = state.list.filter((s) => s.state === 'packed').length;
        $('pkCount').textContent = state.list.length ? '(' + packedCount + '/' + state.list.length + ')' : '';
        const active = box.querySelector('.pk-card.active');
        if (active && active.scrollIntoView) active.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }
    async function removeFromList(id) {
        state.list = state.list.filter((s) => s.id !== id);
        if (cfg.useQueue && cfg.canEdit) api('queue_remove', { id }, true);
        if (state.current && state.current.id === id) {
            state.current = null; state.undo = []; state.redo = [];
            const next = state.list.find((s) => s.state !== 'packed') || state.list[0];
            if (next) { openOrder(next.id); return; }
        }
        render();
    }

    // ---------------------------------------------------------------- zamówienie
    async function openOrder(id, silent = false) {
        const data = await api('order', { id });
        if (!data.ok) { if (!silent) { toast(data.error || 'Nie udało się otworzyć zamówienia.', 'bad'); beep(false); } return false; }
        if (!state.current || state.current.id !== id) { state.undo = []; state.redo = []; }
        state.browse = false;
        state.current = data.order;
        upsertSummary(summaryOf(data.order));
        const url = new URL(location.href);
        url.searchParams.set('open', String(id)); url.searchParams.delete('order');
        history.replaceState(null, '', url);
        render();
        return true;
    }
    function applyOrder(o) {
        state.current = o;
        upsertSummary(summaryOf(o));
        render();
    }

    function render() {
        renderList();
        const o = state.browse ? null : state.current;
        const hasOrder = !!o;
        $('pkBrowse').hidden = !state.browse;
        $('pkBrowseBtn').classList.toggle('on', state.browse);
        $('pkInfo').hidden = !hasOrder;
        $('pkBottom').hidden = !hasOrder;
        $('pkEmpty').hidden = hasOrder || state.browse;
        const num = $('pkNum');
        $('pkCarton').hidden = true;
        $('pkExt').replaceChildren(); $('pkExt').hidden = true;
        $('pkExtActions').replaceChildren();
        if (state.browse) { num.hidden = true; $('pkItems').replaceChildren(); emit('order', null); return; }
        if (!hasOrder) {
            num.hidden = true;
            $('pkItems').replaceChildren();
            renderEmpty();
            emit('order', null);
            return;
        }
        num.hidden = false;
        num.textContent = o.number + (cfg.orderUrl ? ' ↗' : '');
        if (cfg.orderUrl) { num.href = cfg.orderUrl + o.id; num.target = '_blank'; num.rel = 'noopener'; }
        else { num.removeAttribute('href'); }

        $('pkCustomer').textContent = o.customer || 'Zamówienie';
        const st = $('pkStatus');
        st.textContent = o.status.label || '—'; st.style.setProperty('--st', o.status.color || '#888');
        $('pkShip').textContent = o.shipping || 'brak metody dostawy';
        $('pkShip').hidden = !o.shipping;
        const note = $('pkNote'); note.hidden = !o.note; note.textContent = o.note || '';
        const stBox = $('pkState');
        if (o.state === 'packed') {
            stBox.hidden = false; stBox.className = 'pk-state packed';
            stBox.textContent = '✓ Spakowane' + (o.packedBy ? ' — ' + o.packedBy : '') + (o.packedAt ? ', ' + localTime(o.packedAt) : '');
        } else if (o.state === 'problem') {
            stBox.hidden = false; stBox.className = 'pk-state problem';
            stBox.textContent = '⚠ Nie spakowano: ' + (o.problem || '');
        } else { stBox.hidden = true; }

        const photos = $('pkPhotos');
        photos.replaceChildren(...o.photos.map((p) => {
            const src = cfg.photoUrl + (cfg.photoUrl.includes('?') ? '&' : '?') + 'id=' + o.id + '&n=' + p.n;
            return el('a', { href: src, onclick: (e) => { e.preventDefault(); lightbox(src, p.n); }, title: 'Zdjęcie paczki' + (p.by ? ' — ' + p.by : '') },
                el('img', { src, alt: '', loading: 'lazy' }));
        }));
        $('pkCameraBtn').hidden = !cfg.canEdit;
        if (o.photos.length) $('pkCameraBtn').dataset.count = String(o.photos.length); else delete $('pkCameraBtn').dataset.count;

        const box = $('pkItems');
        box.replaceChildren(...o.items.map(itemRow));
        if (!o.items.length) box.append(el('div', { class: 'pk-empty', text: 'To zamówienie nie ma pozycji do spakowania.' }));
        renderCarton(o);

        const packed = o.items.reduce((a, i) => a + i.packed, 0);
        const total = o.items.reduce((a, i) => a + i.qty, 0);
        $('pkProgressBar').style.width = (total ? (packed / total) * 100 : 0) + '%';
        $('pkProgressText').textContent = packed + ' z ' + total + ' szt.';
        const complete = total > 0 && packed >= total;
        const done = $('pkDone');
        done.classList.toggle('soft', !complete);
        done.disabled = !cfg.canEdit;
        $('pkProblem').disabled = !cfg.canEdit;
        $('pkReset').disabled = !cfg.canEdit || packed === 0;
        $('pkUndo').disabled = !state.undo.length;
        $('pkRedo').disabled = !state.redo.length;
        emit('order', o);
        $('pkExt').hidden = !$('pkExt').childElementCount;
    }

    // „Gabaryt paczki”: kartony z ustawień, SUGEST. = najmniejszy pasujący, wybór pakującego zapisany w CRM.
    function renderCarton(o) {
        const c = o.carton;
        const box = $('pkCarton');
        if (!c || !c.options.length || !o.items.length) { box.hidden = true; return; }
        box.hidden = false;
        const selected = c.chosen || c.suggested;
        const ro = !cfg.canEdit;
        $('pkCartonList').replaceChildren(...c.options.map((x) => el('button', {
                type: 'button', disabled: ro,
                class: 'pk-carton-opt' + (x.name === selected ? ' on' : '') + (x.fits === false ? ' no' : ''),
                'aria-pressed': x.name === selected ? 'true' : 'false',
                title: (x.fits === false ? 'Nie pasuje: ' + x.why : x.fits ? 'Zmieści się' : 'Nie wiadomo — brak wymiarów produktów')
                    + (x.maxKg ? ' · maks. ' + String(x.maxKg).replace('.', ',') + ' kg' : ''),
                onclick: () => chooseCarton(x.name === c.chosen ? '' : x.name),
            },
            el('b', { text: x.name }), el('span', { class: 'dims', text: x.dims + ' cm' }),
            x.name === c.suggested ? el('span', { class: 'sug', text: 'SUGEST.' }) : null)));
        $('pkCartonWeight').textContent = c.weight ? '· ok. ' + String(Math.round(c.weight * 100) / 100).replace('.', ',') + ' kg' : '';
        const notes = [];
        if (c.message) notes.push(c.message);
        if (c.missingDims.length && c.missingDims.length < o.items.length) notes.push('Bez wymiarów w magazynie (nie liczone): ' + c.missingDims.join(', ') + '.');
        if (c.suspectWeight && c.suspectWeight.length) notes.push('Waga w magazynie wygląda na gramy zapisane jako kg — liczę jako gramy: ' + c.suspectWeight.join(', ') + '. Popraw ją na karcie produktu (Pobierz ponownie ze sklepu).');
        if (c.missingWeight.length) notes.push('Bez wagi w magazynie: ' + c.missingWeight.join(', ') + '.');
        if (c.approx) notes.push('Dużo sztuk — sugestia liczona z objętości, orientacyjnie.');
        const note = $('pkCartonNote');
        note.hidden = !notes.length; note.textContent = notes.join(' ');
    }
    async function chooseCarton(name) {
        const o = state.current;
        if (!o || !cfg.canEdit) return;
        const data = await api('carton', { id: o.id, carton: name }, true);
        if (!data.ok) { toast(data.error || 'Nie zapisano kartonu.', 'bad'); return; }
        if (state.current && state.current.id === data.order.id) applyOrder(data.order);
    }

    function itemRow(it) {
        const full = it.packed >= it.qty;
        const thumb = el('div', { class: 'pk-thumb' });
        if (it.image) {
            thumb.append(el('img', { src: it.image, alt: '', loading: 'lazy' }));
            thumb.addEventListener('click', (e) => { e.stopPropagation(); lightbox(it.image); });
        } else {
            thumb.append(svg('<path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z"/><circle cx="12" cy="13.5" r="3.5"/>'));
        }
        const loc = it.location
            ? el('span', { class: 'pk-loc', title: it.location.label, text: '📍 ' + it.location.code })
            : (state.current && state.current.hasLocations ? el('span', { class: 'pk-loc none', text: '📍 brak lokalizacji' }) : null);
        const tags = el('div', { class: 'pk-tags' },
            loc,
            it.sku ? el('span', { class: 'pk-tag', title: 'SKU', text: it.sku }) : null,
            it.ean ? el('span', { class: 'pk-tag', title: 'EAN', text: 'EAN ' + it.ean }) : null,
            it.attrs ? el('span', { class: 'pk-attrs', text: it.attrs }) : null);
        const ro = !cfg.canEdit;
        const row = el('div', { class: 'pk-item' + (full ? ' full' : ''), 'data-idx': it.idx,
                onclick: () => { if (!ro && !full) change(it, it.packed + 1); } },
            thumb,
            el('div', { class: 'pk-item-text' },
                el('div', { class: 'pk-item-name' }, el('b', { text: it.qty + ' ×' }), ' ', it.name),
                tags),
            el('div', { class: 'pk-ctrl', onclick: (e) => e.stopPropagation() },
                el('div', { class: 'pk-stepper' },
                    el('button', { type: 'button', 'aria-label': 'Mniej', text: '−', disabled: ro || it.packed <= 0, onclick: () => change(it, it.packed - 1) }),
                    el('div', { class: 'pk-qty' }, String(it.packed), el('small', { text: 'z' }), String(it.qty)),
                    el('button', { type: 'button', 'aria-label': 'Więcej', text: '+', disabled: ro || full, onclick: () => change(it, it.packed + 1) })),
                el('button', { type: 'button', class: 'pk-all', title: full ? 'Cofnij odhaczenie' : 'Spakowane wszystkie sztuki',
                    'aria-label': 'Wszystkie sztuki', disabled: ro,
                    onclick: () => change(it, full ? 0 : it.qty) },
                    svg('<path d="m5 12.5 4.5 4.5L19 7.5"/>'))));
        return row;
    }

    function flashRow(idx, ok = true) {
        const row = document.querySelector('.pk-item[data-idx="' + idx + '"]');
        if (!row) return;
        row.classList.remove('flash', 'flash-bad'); void row.offsetWidth;
        row.classList.add(ok ? 'flash' : 'flash-bad');
        row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    let pending = Promise.resolve();
    function change(it, count, record = true) {
        const o = state.current;
        if (!o || !cfg.canEdit) return;
        count = Math.max(0, Math.min(it.qty, count));
        if (count === it.packed) return;
        if (record) { state.undo.push({ id: o.id, idx: it.idx, from: it.packed, to: count }); state.redo = []; }
        const item = o.items.find((x) => x.idx === it.idx);
        item.packed = count;  // od razu na ekranie, serwer potwierdza
        upsertSummary(summaryOf(o));
        render();
        flashRow(it.idx, true);
        if (count === it.qty) beep(true);
        const id = o.id;
        pending = pending.then(async () => {
            const data = await api('set', { id, idx: it.idx, count }, true);
            if (!data.ok) { toast(data.error || 'Nie zapisano.', 'bad'); beep(false); await openOrder(id, true); return; }
            if (state.current && state.current.id === id) applyOrder(data.order); else upsertSummary(summaryOf(data.order));
            emit('item', data.order.items.find((x) => x.idx === it.idx) || it, data.order);
        });
        return pending;
    }

    function undo() {
        const step = state.undo.pop();
        if (!step || !state.current || step.id !== state.current.id) { render(); return; }
        const it = state.current.items.find((x) => x.idx === step.idx);
        if (it) { state.redo.push(step); change(it, step.from, false); }
    }
    function redo() {
        const step = state.redo.pop();
        if (!step || !state.current || step.id !== state.current.id) { render(); return; }
        const it = state.current.items.find((x) => x.idx === step.idx);
        if (it) { state.undo.push(step); change(it, step.to, false); }
    }

    function nextUnpacked() {
        const cur = state.current ? state.current.id : null;
        const idx = state.list.findIndex((s) => s.id === cur);
        const after = state.list.slice(idx + 1).concat(state.list.slice(0, Math.max(0, idx)));
        return after.find((s) => s.state !== 'packed');
    }

    async function markPacked() {
        const o = state.current; if (!o) return;
        await pending;
        const complete = o.items.every((i) => i.packed >= i.qty) && o.items.length > 0;
        let force = false;
        if (!complete) {
            const missing = o.items.reduce((a, i) => a + Math.max(0, i.qty - i.packed), 0);
            if (!await confirmBox('Nie wszystko jest spakowane', 'Brakuje ' + missing + ' szt. Oznaczyć zamówienie jako spakowane mimo to?', 'Spakowano mimo to')) return;
            force = true;
        }
        const data = await api('packed', { id: o.id, force: force ? 1 : '' }, true);
        if (!data.ok) { toast(data.error || 'Nie zapisano.', 'bad'); beep(false); return; }
        beep(true);
        applyOrder(data.order);
        emit('packed', data.order);
        toast('✓ Spakowano ' + data.order.number + (data.message ? ' — ' + data.message : ''), 'ok');
        const next = nextUnpacked();
        if (next) setTimeout(() => openOrder(next.id), 700);
    }

    async function markProblem() {
        const o = state.current; if (!o) return;
        const d = $('pkProblemDialog');
        $('pkProblemNote').value = o.state === 'problem' ? (o.problem || '') : '';
        document.querySelectorAll('#pkProblemChips button').forEach((b) => b.classList.remove('on'));
        d.returnValue = '';
        d.showModal();
        d.addEventListener('close', async () => {
            if (d.returnValue !== 'ok') return;
            await pending;
            const data = await api('problem', { id: o.id, note: $('pkProblemNote').value }, true);
            if (!data.ok) { toast(data.error || 'Nie zapisano.', 'bad'); return; }
            applyOrder(data.order);
            emit('problem', data.order);
            toast('Zapisano: nie spakowano' + (data.message ? ' — ' + data.message : ''));
            const next = nextUnpacked();
            if (next) setTimeout(() => openOrder(next.id), 700);
        }, { once: true });
    }

    async function resetOrder() {
        const o = state.current; if (!o) return;
        if (!await confirmBox('Wyzerować pakowanie?', 'Wszystkie pozycje tego zamówienia wrócą do 0. Zdjęcia zostaną.', 'Wyzeruj')) return;
        await pending;
        const data = await api('reset', { id: o.id }, true);
        if (!data.ok) { toast(data.error || 'Nie zapisano.', 'bad'); return; }
        state.undo = []; state.redo = [];
        applyOrder(data.order);
    }

    async function uploadPhoto(file) {
        const o = state.current; if (!o || !file) return;
        const btn = $('pkCameraBtn');
        btn.classList.add('busy');
        toast('Wysyłam zdjęcie…');
        const small = await shrink(file);
        const data = await api('photo', { id: o.id }, true, small);
        btn.classList.remove('busy');
        if (!data.ok) { toast(data.error || 'Nie udało się wysłać zdjęcia.', 'bad'); return; }
        applyOrder(data.order);
        toast('📷 Zdjęcie zapisane', 'ok');
    }
    // Zdjęcie z telefonu (kilka MB) zmniejszamy do ~1600 px - szybciej i mniej miejsca na serwerze.
    function shrink(file) {
        return new Promise((resolve) => {
            if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { resolve(file); return; }
            const img = new Image();
            img.onload = () => {
                const max = 1600, scale = Math.min(1, max / Math.max(img.width, img.height));
                if (scale >= 1 && file.size < 1.5e6) { resolve(file); return; }
                const c = document.createElement('canvas');
                c.width = Math.round(img.width * scale); c.height = Math.round(img.height * scale);
                c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                c.toBlob((b) => resolve(b ? new File([b], 'paczka.jpg', { type: 'image/jpeg' }) : file), 'image/jpeg', 0.82);
                URL.revokeObjectURL(img.src);
            };
            img.onerror = () => resolve(file);
            img.src = URL.createObjectURL(file);
        });
    }

    // photoN: numer zdjęcia paczki (można je usunąć); zdjęcie produktu - bez usuwania.
    let lightboxPhoto = null;
    function lightbox(src, photoN = null) {
        lightboxPhoto = photoN;
        $('pkLightboxImg').src = src;
        $('pkLightboxDelete').hidden = photoN === null || !cfg.canEdit;
        $('pkLightbox').showModal();
    }
    async function deletePhoto() {
        const o = state.current, n = lightboxPhoto;
        if (!o || n === null) return;
        $('pkLightbox').close();
        if (!await confirmBox('Usunąć zdjęcie?', 'Zdjęcie paczki zostanie usunięte z zamówienia na stałe.', 'Usuń')) return;
        const data = await api('photo_delete', { id: o.id, n }, true);
        if (!data.ok) { toast(data.error || 'Nie udało się usunąć zdjęcia.', 'bad'); return; }
        applyOrder(data.order);
        toast('Zdjęcie usunięte.');
    }

    function localTime(utc) {
        const d = new Date(String(utc).replace(' ', 'T') + 'Z');
        return isNaN(d) ? utc : d.toLocaleString('pl-PL', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    }

    function renderEmpty() {
        const box = $('pkEmpty');
        if (cfg.mode === 'phone') {
            box.replaceChildren(
                el('div', { class: 'pk-big', text: '📦' }),
                el('h2', { text: state.list.length ? 'Wybierz zamówienie' : 'Nic do spakowania' }),
                el('p', { text: state.list.length
                    ? 'Stuknij zamówienie na liście powyżej albo wybierz inne z listy do spakowania.'
                    : 'Wybierz zamówienie z listy do spakowania albo wyślij je z komputera („📱 Na telefon”).' }),
                el('button', { type: 'button', class: 'pk-btn', text: '📋 Pokaż zamówienia do spakowania', onclick: () => openBrowse() }),
                el('br'),
                el('button', { type: 'button', class: 'pk-btn ghost', text: '📷 Zeskanuj kod zamówienia', onclick: () => openCamera() }));
        } else {
            box.replaceChildren(
                el('div', { class: 'pk-big', text: '📦' }),
                el('h2', { text: 'Wybierz zamówienia do spakowania' }),
                el('p', { text: 'Na liście zamówień zaznacz zamówienia i kliknij „📦 Pakuj” albo wybierz je z listy do spakowania.' }),
                el('button', { type: 'button', class: 'pk-btn', text: '📋 Pokaż zamówienia do spakowania', onclick: () => openBrowse() }));
        }
    }

    // ---------------------------------------------------------------- lista „Do spakowania”
    function openBrowse() {
        state.browse = true;
        state.browseOffset = 0;
        render();
        loadBrowse(false);
    }
    function closeBrowse() {
        state.browse = false;
        render();
    }
    let browseSeq = 0;
    async function loadBrowse(more) {
        const seq = ++browseSeq;
        const offset = more ? state.browseOffset : 0;
        const data = await api('browse', { status: state.browseStatus, q: state.browseQ, offset, limit: 40 });
        if (seq !== browseSeq || !state.browse) return;
        if (!data.ok) { toast(data.error || 'Nie udało się pobrać listy.', 'bad'); return; }
        const colors = {};
        const chips = $('pkBrowseChips');
        chips.replaceChildren(
            el('button', { type: 'button', class: state.browseStatus === '' ? 'on' : '', text: 'Wszystkie',
                onclick: () => { state.browseStatus = ''; loadBrowse(false); } }),
            ...data.statuses.map((s) => { colors[s.key] = s.color; return el('button', {
                type: 'button', class: state.browseStatus === s.key ? 'on' : '', style: '--c:' + s.color,
                onclick: () => { state.browseStatus = s.key; loadBrowse(false); } }, el('i'), s.label); }));
        const labels = Object.fromEntries(data.statuses.map((s) => [s.key, s.label]));
        $('pkBrowseTotal').textContent = '(' + data.total + ')';
        const list = $('pkBrowseList');
        if (!more) list.replaceChildren();
        const inList = new Set(state.list.map((s) => s.id));
        for (const o of data.orders) {
            const badge = o.state === 'packed' ? '✓' : (o.state === 'problem' ? '!' : o.packed + '/' + o.total);
            list.append(el('button', { type: 'button', class: 'pk-brow st-' + o.state + (inList.has(o.id) ? ' in-list' : ''),
                    'data-id': o.id, onclick: () => pickFromBrowse(o) },
                el('div', { class: 'pk-brow-top' }, el('span', { class: 'pk-brow-num', text: o.number }), el('span', { class: 'pk-brow-name', text: o.customer || '—' })),
                el('div', { class: 'pk-brow-sub', style: '--c:' + (colors[o.status] || '#888') }, el('i'),
                    (labels[o.status] || o.status) + ' · ' + o.lines + ' poz. / ' + o.total + ' szt.' + (o.first ? ' · ' + o.first : '')),
                el('span', { class: 'pk-brow-badge', text: badge })));
        }
        if (!more && !data.orders.length) {
            list.append(el('div', { class: 'pk-browse-empty', text: state.browseQ ? 'Nic nie pasuje do „' + state.browseQ + '”.' : 'Brak zamówień do spakowania 🎉' }));
        }
        state.browseOffset = offset + data.orders.length;
        $('pkBrowseMore').hidden = state.browseOffset >= data.total;
    }
    // Wybór z listy: zamówienie trafia do kolejki tego telefonu/konta (zostaje na pasku u góry) i od razu się otwiera.
    async function pickFromBrowse(o) {
        if (cfg.useQueue && cfg.canEdit && !state.list.some((s) => s.id === o.id)) {
            await api('queue_add', { ids: [o.id] }, true);
        }
        if (!state.list.some((s) => s.id === o.id)) {
            state.list.push({ id: o.id, number: o.number, customer: o.customer, packed: o.packed, total: o.total, state: o.state });
        }
        await openOrder(o.id);
    }
    let browseTimer;
    $('pkBrowseQ').addEventListener('input', (e) => {
        clearTimeout(browseTimer);
        browseTimer = setTimeout(() => { state.browseQ = e.target.value.trim(); loadBrowse(false); }, 300);
    });
    $('pkBrowseMore').addEventListener('click', () => loadBrowse(true));
    $('pkBrowseBtn').addEventListener('click', () => { if (state.browse) closeBrowse(); else openBrowse(); });

    // ---------------------------------------------------------------- skaner / wyszukiwanie
    // Warianty odczytanego kodu: sam kod, a z QR będącego adresem także ostatni fragment ścieżki
    // i parametry ?sku= / ?ean= / ?id= (np. QR z linkiem do produktu w sklepie).
    function codeVariants(code) {
        const out = [code];
        const digits = code.replace(/\s+/g, '');
        if (/^\d{12}$/.test(digits)) out.push('0' + digits);          // UPC-A zapisany jako EAN-13
        if (/^0\d{12}$/.test(digits)) out.push(digits.slice(1));
        if (/^https?:\/\//i.test(code)) {
            try {
                const u = new URL(code);
                for (const k of ['sku', 'ean', 'gtin', 'code', 'id']) { const v = u.searchParams.get(k); if (v) out.push(v); }
                const seg = u.pathname.split('/').filter(Boolean).pop();
                if (seg) out.push(decodeURIComponent(seg));
            } catch (_) { /* nie adres */ }
        }
        return [...new Set(out.map((s) => String(s).trim()).filter(Boolean))];
    }
    // Zwraca {ok, text} - skaner aparatem pokazuje to na podglądzie.
    async function handleCode(raw) {
        const code = String(raw || '').trim();
        if (code.length < 2) return { ok: false, text: '' };
        const o = state.current;
        const norm = (s) => String(s || '').trim().toLowerCase();
        const variants = codeVariants(code).map(norm);
        if (/^CRMLOC:/i.test(code)) {
            const r = await api('loc_scan', { code });
            const msg = r.ok ? 'To etykieta półki ' + r.location.code + ' (' + r.location.label + ')' : (r.error || 'Etykieta półki');
            toast(msg + (cfg.canLocations ? ' — przypisywanie: 📋 → 📍 Półki' : ''));
            return { ok: false, text: msg };
        }
        if (/\/pack\/pair\.php\?c=/.test(code)) {
            toast('To kod do parowania telefonu — otwórz go zwykłym aparatem.', 'bad'); beep(false);
            return { ok: false, text: 'To kod parowania telefonu' };
        }
        if (o && cfg.canEdit) {
            const hit = o.items.find((i) => variants.some((v) => (i.ean && norm(i.ean) === v) || (i.sku && norm(i.sku) === v)));
            if (hit) {
                if (hit.packed >= hit.qty) {
                    flashRow(hit.idx, false); beep(false);
                    const msg = '„' + hit.name + '” — już spakowane wszystkie sztuki (' + hit.qty + ')';
                    toast(msg + '.', 'bad');
                    return { ok: false, text: msg };
                }
                const next = hit.packed + 1;
                change(hit, next);
                return { ok: true, text: '✓ ' + hit.name + ' — ' + next + ' z ' + hit.qty };
            }
        }
        const inList = state.list.find((s) => variants.includes(norm(s.number)) || variants.includes(String(s.id)));
        if (inList) { await openOrder(inList.id); beep(true); return { ok: true, text: 'Zamówienie ' + inList.number }; }
        for (const v of codeVariants(code)) {
            const found = await api('find', { q: v });
            if (found.ok && await openOrder(found.id)) {
                beep(true);
                if (!state.list.some((s) => s.id === found.id) && cfg.useQueue && cfg.canEdit) api('queue_add', { ids: [found.id] }, true);
                return { ok: true, text: 'Zamówienie ' + (state.current ? state.current.number : v) };
            }
        }
        beep(false);
        const msg = o ? 'Kodu „' + code + '” nie ma w tym zamówieniu' : 'Nie znaleziono zamówienia „' + code + '”';
        toast(msg + '.', 'bad');
        return { ok: false, text: msg };
    }

    // ---------------------------------------------------------------- skaner aparatem
    // Android (Chrome): wbudowany BarcodeDetector. iPhone i reszta: biblioteka ZXing (wczytywana dopiero przy pierwszym użyciu).
    const cam = { stream: null, running: false, detector: null, zx: null, canvas: null, lastCode: '', misses: 0, missSince: 0, pauseUntil: 0, torch: false, flip: false };
    function loadZxing() {
        if (window.ZXing) return Promise.resolve();
        return new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = cfg.zxingUrl; s.onload = resolve; s.onerror = () => reject(new Error('Nie udało się wczytać skanera.'));
            document.head.append(s);
        });
    }
    async function makeDecoder() {
        if ('BarcodeDetector' in window) {
            try {
                const supported = await window.BarcodeDetector.getSupportedFormats();
                const want = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf', 'qr_code', 'data_matrix'].filter((f) => supported.includes(f));
                if (want.includes('ean_13') && want.includes('qr_code')) { cam.detector = new window.BarcodeDetector({ formats: want }); return; }
            } catch (_) { /* spróbujemy ZXing */ }
        }
        await loadZxing();
        const Z = window.ZXing;
        const hints = new Map();
        hints.set(Z.DecodeHintType.POSSIBLE_FORMATS, [Z.BarcodeFormat.EAN_13, Z.BarcodeFormat.EAN_8, Z.BarcodeFormat.UPC_A, Z.BarcodeFormat.UPC_E,
            Z.BarcodeFormat.CODE_128, Z.BarcodeFormat.CODE_39, Z.BarcodeFormat.ITF, Z.BarcodeFormat.QR_CODE, Z.BarcodeFormat.DATA_MATRIX]);
        hints.set(Z.DecodeHintType.TRY_HARDER, true);
        cam.zx = new Z.MultiFormatReader();
        cam.zx.setHints(hints);
    }
    function camResult(text, kind) {
        const r = $('pkCamResult');
        r.textContent = text; r.className = 'pk-cam-result ' + (kind || '');
        const f = $('pkCamFrame');
        f.classList.remove('ok', 'bad');
        if (kind) { f.classList.add(kind); setTimeout(() => f.classList.remove(kind), 700); }
    }
    function camProgress() {
        if (cam.mode === 'loc') {
            $('pkCamOrder').textContent = cam.loc ? '📍 Przypisujesz do: ' + cam.loc.code : '📍 Zeskanuj QR półki';
            $('pkCamBar').style.width = '0';
            $('pkCamProgress').textContent = cam.assigned ? 'Przypisano: ' + cam.assigned + ' prod.' : '';
            return;
        }
        const o = state.current;
        $('pkCamOrder').textContent = o ? o.number + (o.customer ? ' · ' + o.customer : '') : 'Zeskanuj numer zamówienia';
        const packed = o ? o.items.reduce((a, i) => a + i.packed, 0) : 0;
        const total = o ? o.items.reduce((a, i) => a + i.qty, 0) : 0;
        $('pkCamBar').style.width = (total ? packed / total * 100 : 0) + '%';
        $('pkCamProgress').textContent = o ? packed + ' z ' + total + ' szt.' + (total && packed >= total ? ' — wszystko spakowane ✓' : '') : '';
    }
    async function openCamera(mode) {
        cam.mode = mode === 'loc' ? 'loc' : 'pack';
        cam.loc = null; cam.assigned = 0;
        $('pkCam').classList.toggle('loc-mode', cam.mode === 'loc');
        $('pkCam').hidden = false;
        camProgress();
        camResult(cam.mode === 'loc' ? 'Zeskanuj QR z etykiety półki, potem kody produktów, które na niej stoją'
            : (state.current ? 'Skieruj aparat na kod EAN lub QR produktu' : 'Skieruj aparat na kod zamówienia'));
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            camResult('Ta przeglądarka nie daje dostępu do aparatu (potrzebne połączenie https).', 'bad'); return;
        }
        try {
            if (!cam.detector && !cam.zx) await makeDecoder();
            cam.stream = await navigator.mediaDevices.getUserMedia({ audio: false,
                video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } } });
        } catch (e) {
            const denied = e && (e.name === 'NotAllowedError' || e.name === 'SecurityError');
            camResult(denied ? 'Brak zgody na aparat — zezwól w ustawieniach przeglądarki (Safari: aA → Ustawienia witryny → Aparat).'
                : (e && e.message) || 'Nie udało się włączyć aparatu.', 'bad');
            return;
        }
        const video = $('pkCamVideo');
        video.srcObject = cam.stream;
        try { await video.play(); } catch (_) { /* autoplay i tak ruszy */ }
        const track = cam.stream.getVideoTracks()[0];
        const caps = track && track.getCapabilities ? track.getCapabilities() : {};
        $('pkCamTorch').hidden = !caps.torch;
        cam.running = true;
        scanLoop();
    }
    function closeCamera() {
        cam.running = false;
        if (cam.stream) cam.stream.getTracks().forEach((t) => t.stop());
        cam.stream = null; cam.torch = false;
        $('pkCamTorch').classList.remove('on');
        $('pkCamVideo').srcObject = null;
        $('pkCam').hidden = true;
        render();
    }
    async function decodeFrame(video) {
        const vw = video.videoWidth, vh = video.videoHeight;
        if (!vw || !vh) return null;
        if (cam.detector) {
            const found = await cam.detector.detect(video);
            return found && found.length ? found[0].rawValue : null;
        }
        // Wycinek ze środka kadru (tam jest ramka), zmniejszony - szybciej na telefonie.
        const cw = Math.round(vw * 0.8), ch = Math.round(vh * 0.6);
        const scale = Math.min(1, 800 / cw);
        const c = cam.canvas || (cam.canvas = document.createElement('canvas'));
        c.width = Math.round(cw * scale); c.height = Math.round(ch * scale);
        const ctx = c.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(video, (vw - cw) / 2, (vh - ch) / 2, cw, ch, 0, 0, c.width, c.height);
        const Z = window.ZXing;
        // Dwa sposoby progowania na zmianę: Hybrid radzi sobie z nierównym światłem,
        // Global z czystym, kontrastowym kodem (np. QR na ekranie albo naklejce).
        cam.flip = !cam.flip;
        const Bin = cam.flip ? Z.HybridBinarizer : Z.GlobalHistogramBinarizer;
        try {
            const bmp = new Z.BinaryBitmap(new Bin(new Z.HTMLCanvasElementLuminanceSource(c)));
            return cam.zx.decodeWithState(bmp).getText();
        } catch (_) {
            return null;
        } finally {
            cam.zx.reset();
        }
    }
    async function scanLoop() {
        if (!cam.running) return;
        const now = Date.now();
        if (now >= cam.pauseUntil && !document.hidden) {
            let code = null;
            try { code = await decodeFrame($('pkCamVideo')); } catch (_) { code = null; }
            // Ten sam kod trzymany przed aparatem liczy się raz. Kolejna sztuka tego samego produktu:
            // odsunąć kod z kadru (na chwilę) i pokazać ponownie.
            if (code && code === cam.lastCode) {
                cam.misses = 0; cam.missSince = 0;
            } else if (code) {
                cam.lastCode = code;
                cam.misses = 0; cam.missSince = 0;
                cam.pauseUntil = Date.now() + 600;
                if (navigator.vibrate) navigator.vibrate(60);
                const res = cam.mode === 'loc' ? await handleLocCode(code) : await handleCode(code);
                camResult(res.text || code, res.ok ? 'ok' : 'bad');
                await pending;
                camProgress();
            } else if (cam.lastCode) {
                // Kod zniknął z kadru (co najmniej 2 klatki i ~0,5 s) - następne pokazanie liczy się jako kolejna sztuka.
                // Pojedyncza nieudana klatka przy trzymaniu kodu nie wystarcza, więc nic nie liczy się podwójnie.
                cam.misses++;
                cam.missSince = cam.missSince || Date.now();
                if (cam.misses >= 2 && Date.now() - cam.missSince >= 450) cam.lastCode = '';
            }
        }
        setTimeout(scanLoop, cam.detector ? 120 : 160);
    }
    $('pkCamBtn').addEventListener('click', openCamera);
    $('pkBrowseCam').addEventListener('click', () => openCamera());
    if ($('pkBrowseLoc')) $('pkBrowseLoc').addEventListener('click', () => openCamera('loc'));

    // Tryb „📍 Półki”: QR półki ustawia bieżącą lokalizację, kolejne kody produktów dostają tę lokalizację.
    async function handleLocCode(code) {
        if (/^CRMLOC:/i.test(code)) {
            const r = await api('loc_scan', { code });
            if (!r.ok) { beep(false); return { ok: false, text: r.error || 'Nieznana półka' }; }
            cam.loc = r.location;
            beep(true);
            return { ok: true, text: '📍 ' + r.location.code + ' — ' + r.location.label + '. Teraz skanuj produkty.' };
        }
        if (!cam.loc) { beep(false); return { ok: false, text: 'Najpierw zeskanuj QR z etykiety półki' }; }
        const r = await api('loc_assign', { code, location: cam.loc.id }, true);
        if (!r.ok) { beep(false); return { ok: false, text: r.error || 'Nie przypisano' }; }
        cam.assigned += r.products.length;
        beep(true);
        const names = r.products.map((p) => p.name || p.sku).join(', ');
        return { ok: true, text: '✓ ' + names + ' → ' + r.location.code };
    }
    $('pkCamClose').addEventListener('click', closeCamera);
    $('pkCamTorch').addEventListener('click', async () => {
        const track = cam.stream && cam.stream.getVideoTracks()[0];
        if (!track) return;
        cam.torch = !cam.torch;
        try { await track.applyConstraints({ advanced: [{ torch: cam.torch }] }); } catch (_) { cam.torch = false; }
        $('pkCamTorch').classList.toggle('on', cam.torch);
    });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden && cam.running) closeCamera();   // aparat nie działa w tle
    });
    // Czytnik USB/Bluetooth „pisze” jak klawiatura i kończy Enterem - łapiemy to bez klikania w pole.
    let buf = '', last = 0;
    document.addEventListener('keydown', (e) => {
        const t = e.target;
        if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return;
        if (document.querySelector('dialog[open]')) return;
        const now = Date.now();
        if (now - last > 80) buf = '';
        last = now;
        if (e.key === 'Enter') { if (buf.length >= 3) { e.preventDefault(); handleCode(buf); } buf = ''; return; }
        if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) buf += e.key;
        else if (e.key === 'z' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); e.shiftKey ? redo() : undo(); }
    });
    $('pkScan').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault(); const v = e.target.value; e.target.value = ''; handleCode(v);
            if (cfg.mode === 'phone') { e.target.blur(); $('pk').classList.remove('scan-open'); }
        }
    });

    // ---------------------------------------------------------------- panel: telefon, QR, ustawienia
    let qrTimer;
    async function showQr() {
        const d = $('pkQrDialog');
        if (!d.open) d.showModal();
        const data = await api('pair_code', { id: state.current ? state.current.id : '' }, true);
        if (!data.ok) { d.close(); toast(data.error || 'Nie udało się utworzyć kodu.', 'bad'); return; }
        drawQr($('pkQrCanvas'), data.url);
        let left = data.expires;
        clearInterval(qrTimer);
        const tick = () => {
            const m = Math.floor(left / 60), s = String(left % 60).padStart(2, '0');
            $('pkQrTimer').textContent = left > 0 ? 'Kod ważny jeszcze ' + m + ':' + s + ' (jednorazowy).' : 'Kod wygasł — kliknij „Nowy kod”.';
            if (left-- <= 0) clearInterval(qrTimer);
        };
        tick(); qrTimer = setInterval(tick, 1000);
        loadDevices();
    }
    function drawQr(canvas, text) {
        if (typeof qrcodegen === 'undefined') return;
        const qr = qrcodegen.QrCode.encodeText(text, qrcodegen.QrCode.Ecc.MEDIUM);
        const border = 3, scale = Math.max(2, Math.floor(260 / (qr.size + border * 2)));
        canvas.width = canvas.height = (qr.size + border * 2) * scale;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#000';
        for (let y = 0; y < qr.size; y++) for (let x = 0; x < qr.size; x++) if (qr.getModule(x, y)) ctx.fillRect((x + border) * scale, (y + border) * scale, scale, scale);
    }
    async function loadDevices() {
        const data = await api('devices');
        const box = $('pkDevices');
        box.replaceChildren();
        if (!data.ok) return;
        box.append(el('h4', { text: data.devices.length ? 'Sparowane telefony' : 'Brak sparowanych telefonów' }));
        for (const dv of data.devices) {
            box.append(el('div', { class: 'pk-device' },
                el('span', {}, el('b', { text: dv.name }), ' · ostatnio ' + localTime(dv.last_seen_at || dv.created_at)),
                el('button', { type: 'button', text: 'Odłącz', onclick: async () => {
                    if (!await confirmBox('Odłączyć telefon?', 'Telefon straci dostęp do pakowania. Można go sparować ponownie kodem QR.', 'Odłącz')) { $('pkQrDialog').showModal(); return; }
                    await api('revoke_device', { device: dv.id }, true);
                    $('pkQrDialog').showModal(); loadDevices();
                } })));
        }
    }
    async function sendToPhone() {
        const ids = state.list.map((s) => s.id);
        if (!ids.length) { toast('Najpierw wybierz zamówienia.', 'bad'); return; }
        const data = await api('queue_add', { ids }, true);
        if (!data.ok) { toast(data.error || 'Nie wysłano.', 'bad'); return; }
        if (!data.devices) {
            toast('Wysłano. Zeskanuj kod QR telefonem, żeby go sparować.');
            showQr();
            return;
        }
        toast('📱 Na telefonie: ' + data.queue.length + ' zam. do spakowania' + (data.added ? ' (nowe: ' + data.added + ')' : ''), 'ok');
    }
    async function openSettings() {
        const data = await api('settings');
        if (!data.ok) { toast(data.error, 'bad'); return; }
        const s = data.settings;
        const fill = (sel, val) => {
            sel.replaceChildren(el('option', { value: '', text: '— nie zmieniaj statusu —' }),
                ...s.statuses.map((x) => el('option', { value: x.key, text: x.label, selected: x.key === val })));
        };
        fill($('pkSetDone'), s.done_status); fill($('pkSetProblem'), s.problem_status);
        $('pkSetRequire').checked = s.require_all;
        $('pkSetBrowse').replaceChildren(...s.statuses.map((x) => el('label', {},
            el('input', { type: 'checkbox', value: x.key, checked: s.browse_statuses.includes(x.key) }), x.label)));
        const rows = $('pkSetCartons');
        const cartonRow = (c = { name: '', dims: ['', '', ''], max_kg: '' }) => {
            const row = el('div', { class: 'pk-carton-row' },
                el('input', { type: 'text', maxlength: 40, placeholder: 'Nazwa, np. Karton M', value: c.name, 'aria-label': 'Nazwa kartonu' }),
                ...[0, 1, 2].map((i) => el('input', { type: 'text', inputmode: 'decimal', placeholder: 'cm', value: c.dims[i] ?? '', 'aria-label': 'Wymiar ' + (i + 1) + ' (cm)' })),
                el('input', { type: 'text', inputmode: 'decimal', placeholder: 'kg', value: c.max_kg ?? '', 'aria-label': 'Maks. waga (kg)' }),
                el('button', { type: 'button', title: 'Usuń karton', 'aria-label': 'Usuń karton', text: '✕', onclick: () => row.remove() }));
            return row;
        };
        rows.replaceChildren(el('div', { class: 'pk-carton-row hdr' }, el('span', { text: 'Nazwa' }), el('span', { text: 'Wymiar 1' }),
            el('span', { text: 'Wymiar 2' }), el('span', { text: 'Wymiar 3' }), el('span', { text: 'Maks. kg' }), el('span')),
            ...(s.cartons || []).map(cartonRow));
        $('pkSetCartonAdd').onclick = () => { const r = cartonRow(); rows.append(r); r.querySelector('input').focus(); };
        const d = $('pkSettingsDialog'); d.returnValue = ''; d.showModal();
        d.addEventListener('close', async () => {
            if (d.returnValue !== 'ok') return;
            const cartons = [...rows.querySelectorAll('.pk-carton-row:not(.hdr)')].map((r) => {
                const v = [...r.querySelectorAll('input')].map((i) => i.value.trim());
                return { name: v[0], dims: [v[1], v[2], v[3]], max_kg: v[4] === '' ? null : v[4] };
            }).filter((c) => c.name !== '' || c.dims.some((x) => x !== ''));
            const res = await api('settings_save', { done_status: $('pkSetDone').value, problem_status: $('pkSetProblem').value,
                require_all: $('pkSetRequire').checked ? 1 : '',
                browse_statuses: [...document.querySelectorAll('#pkSetBrowse input:checked')].map((i) => i.value),
                cartons: JSON.stringify(cartons) }, true);
            toast(res.ok ? 'Zapisano ustawienia pakowania.' : (res.error || 'Nie zapisano.'), res.ok ? 'ok' : 'bad');
            if (res.ok && state.current) openOrder(state.current.id, true);
        }, { once: true });
    }

    // ---------------------------------------------------------------- odświeżanie (drugie urządzenie)
    async function refresh() {
        if (document.hidden || state.busy || document.querySelector('dialog[open]')) return;
        if (cfg.useQueue) {
            const q = await api('queue');
            if (q.ok) {
                const known = new Set(state.list.map((s) => s.id));
                for (const s of q.queue) upsertSummary(s);
                // Nowe zamówienia wysłane z komputera: telefon bez otwartego zamówienia otwiera pierwsze.
                const fresh = q.queue.filter((s) => !known.has(s.id));
                if (fresh.length && !state.current && !state.browse) { openOrder(fresh[0].id); return; }
                if (fresh.length) { toast('📥 Nowe zamówienia do spakowania: ' + fresh.length); renderList(); }
            }
        }
        if (state.current && !state.busy && !state.browse) {
            const id = state.current.id;
            const data = await api('order', { id });
            if (data.ok && state.current && state.current.id === id && !state.busy
                && JSON.stringify(data.order) !== JSON.stringify(state.current)) applyOrder(data.order);
        }
    }

    // ---------------------------------------------------------------- blokada przybliżania (iOS ignoruje user-scalable=no)
    const noZoom = (e) => e.preventDefault();
    document.addEventListener('gesturestart', noZoom, { passive: false });
    document.addEventListener('gesturechange', noZoom, { passive: false });
    document.addEventListener('touchmove', (e) => { if (e.touches.length > 1 || (e.scale && e.scale !== 1)) e.preventDefault(); }, { passive: false });
    // Podwójne stuknięcie blokuje CSS (touch-action: manipulation) - szybkie „+ +” dalej dodaje 2 sztuki.

    // ---------------------------------------------------------------- start
    $('pkUndo').addEventListener('click', undo);
    $('pkRedo').addEventListener('click', redo);
    $('pkReset').addEventListener('click', resetOrder);
    $('pkDone').addEventListener('click', markPacked);
    $('pkProblem').addEventListener('click', markProblem);
    $('pkPhotoInput').addEventListener('change', (e) => { const f = e.target.files && e.target.files[0]; e.target.value = ''; uploadPhoto(f); });
    document.querySelectorAll('#pkProblemChips button').forEach((b) => b.addEventListener('click', () => {
        document.querySelectorAll('#pkProblemChips button').forEach((x) => x.classList.toggle('on', x === b));
        $('pkProblemNote').value = b.dataset.note;
    }));
    // Telefon: szczegóły zamówienia zwinięte (więcej miejsca na pozycje), pole kodu pod lupą,
    // pasek zamówień chowa się przy przewijaniu pozycji w dół.
    const infoText = $('pkInfoText');
    const toggleInfo = () => {
        const open = !$('pkInfo').classList.contains('open');
        $('pkInfo').classList.toggle('open', open);
        infoText.setAttribute('aria-expanded', String(open));
    };
    infoText.addEventListener('click', toggleInfo);
    infoText.addEventListener('keydown', (e) => { if (e.key === 'Enter') toggleInfo(); });
    $('pkScanToggle').addEventListener('click', () => {
        const open = $('pk').classList.toggle('scan-open');
        if (open) $('pkScan').focus(); else $('pkScan').blur();
    });
    let lastTop = 0;
    $('pkMain').addEventListener('scroll', () => {
        const top = $('pkMain').scrollTop;
        if (top > lastTop + 6 && top > 40) $('pk').classList.add('scrolled');
        else if (top < lastTop - 6 || top <= 10) $('pk').classList.remove('scrolled');
        lastTop = top;
    }, { passive: true });

    $('pkClose').addEventListener('click', () => {
        if (state.browse && state.current) { closeBrowse(); return; }
        if (cfg.mode === 'panel') { location.href = cfg.backUrl || 'index.php'; return; }
        state.current = null; render();
    });
    if ($('pkToPhone')) $('pkToPhone').addEventListener('click', sendToPhone);
    if ($('pkQr')) $('pkQr').addEventListener('click', showQr);
    if ($('pkQrNew')) $('pkQrNew').addEventListener('click', showQr);
    if ($('pkQrDialog')) $('pkQrDialog').addEventListener('close', () => clearInterval(qrTimer));
    if ($('pkSettings')) $('pkSettings').addEventListener('click', openSettings);
    $('pkLightboxDelete').addEventListener('click', deletePhoto);
    $('pkLightbox').addEventListener('click', (e) => { if (e.target === $('pkLightbox')) $('pkLightbox').close(); });

    window.PackExt = {
        cfg,
        el,
        on(name, fn) { (extHandlers[name] = extHandlers[name] || []).push(fn); },
        api: (action, params = {}) => api('ext_' + action, params, true),
        current: () => state.current,
        reload: () => (state.current ? openOrder(state.current.id, true) : Promise.resolve(false)),
        toast,
        beep,
        panel: $('pkExt'),
        actions: $('pkExtActions'),
    };

    (async () => {
        // Skrypty rozszerzeń są za tym plikiem - czekamy, aż się wykonają, zanim cokolwiek narysujemy.
        if (document.readyState === 'loading') await new Promise((r) => document.addEventListener('DOMContentLoaded', r, { once: true }));
        if (cfg.useQueue) {
            const q = await api('queue');
            if (q.ok) state.list = q.queue;
        }
        if (cfg.ids && cfg.ids.length) {
            const r = await api('orders', { ids: cfg.ids });
            if (r.ok) for (const s of r.orders) upsertSummary(s);
        }
        const want = cfg.openId || (state.list.find((s) => s.state !== 'packed') || state.list[0] || {}).id;
        if (want) { await openOrder(Number(want)); } else if (cfg.mode === 'phone') { openBrowse(); } else { render(); }
        setInterval(refresh, 5000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    })();
})();
