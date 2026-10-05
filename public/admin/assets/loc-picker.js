/*
 * Wybór lokalizacji w magazynie z wyszukiwarką (public/admin/_loc_picker.php).
 * Jedna lista na stronę (window.CRM_LOCATIONS), jedno okienko wyboru dla wszystkich pól,
 * więc strona z setkami produktów i półek nie powtarza listy przy każdym wierszu.
 */
(function () {
    'use strict';
    var LIMIT = 80;
    var LOCS = (window.CRM_LOCATIONS || []).map(function (l) {
        return Object.assign({}, l, { key: norm(l.code + ' ' + l.label + ' ' + (l.note || '')), compact: norm(l.code).replace(/[^a-z0-9]/g, '') });
    });
    var pop = null, input = null, list = null, current = null, items = [], active = -1;

    function norm(s) {
        return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ł/g, 'l');
    }

    function extrasOf(picker) {
        try { return JSON.parse(picker.dataset.extras || '[]'); } catch (e) { return []; }
    }

    /** Pozycje pasujące do zapytania: wszystkie słowa w kodzie/opisie; kod bez myślników („r1a”); najpierw kody zaczynające się od zapytania. */
    function search(q) {
        var nq = norm(q).trim();
        if (nq === '') { return LOCS.slice(); }
        var words = nq.split(/\s+/), cq = nq.replace(/[^a-z0-9]/g, '');
        var starts = [], rest = [];
        LOCS.forEach(function (l) {
            var hit = words.every(function (w) { return l.key.indexOf(w) !== -1; }) || (cq !== '' && l.compact.indexOf(cq) !== -1);
            if (!hit) { return; }
            (cq !== '' && l.compact.indexOf(cq) === 0 ? starts : rest).push(l);
        });
        return starts.concat(rest);
    }

    function build() {
        pop = document.createElement('div');
        pop.className = 'loc-pop';
        pop.setAttribute('role', 'dialog');
        pop.innerHTML = '<input type="search" class="loc-pop-q" placeholder="Szukaj: kod lub opis, np. R1-B, r1b, świece" autocomplete="off" spellcheck="false">'
            + '<div class="loc-pop-list" role="listbox"></div>';
        input = pop.querySelector('.loc-pop-q');
        list = pop.querySelector('.loc-pop-list');
        document.body.appendChild(pop);
        input.addEventListener('input', function () { render(); });
        input.addEventListener('keydown', onKey);
        list.addEventListener('mousedown', function (e) { e.preventDefault(); });   // klik nie zabiera fokusu z pola
        list.addEventListener('click', function (e) {
            var row = e.target.closest('.loc-pop-item');
            if (row) { choose(+row.dataset.i); }
        });
        var style = document.createElement('style');
        style.textContent = ''
            + '.loc-picker{display:inline-block;position:relative}'
            + '.loc-picker-btn{display:inline-flex;align-items:center;gap:8px;min-width:90px;max-width:320px;padding:7px 10px;border:1px solid var(--line,#ddd);border-radius:7px;background:var(--surface,#fff);color:var(--ink,#222);font:inherit;font-size:14px;cursor:pointer;text-align:left}'
            + '.loc-picker-btn:hover{border-color:var(--accent,#9c6b2e)}'
            + '.loc-picker-btn.is-empty .loc-picker-text{color:var(--ink-3,#999)}'
            + '.loc-picker-text{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:var(--font-num,monospace)}'
            + '.loc-picker-caret{color:var(--ink-2,#666);font-size:11px}'
            + '.loc-pop{position:fixed;z-index:1000;width:320px;max-width:calc(100vw - 16px);background:var(--surface,#fff);border:1px solid var(--line,#ddd);border-radius:10px;box-shadow:0 10px 30px -8px rgba(30,25,15,.3);padding:8px;display:none}'
            + '.loc-pop.open{display:block}'
            + '.loc-pop-q{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--line,#ddd);border-radius:7px;font:inherit;font-size:14px}'
            + '.loc-pop-list{max-height:280px;overflow:auto;margin-top:6px}'
            + '.loc-pop-item{display:flex;gap:8px;align-items:baseline;padding:7px 8px;border-radius:6px;cursor:pointer;font-size:13px}'
            + '.loc-pop-item.active{background:var(--accent-soft,#f2e6d0)}'
            + '.loc-pop-item.sel .loc-pop-code::before{content:"✓ "}'
            + '.loc-pop-code{font-family:var(--font-num,monospace);font-weight:700;white-space:nowrap}'
            + '.loc-pop-label{color:var(--ink-2,#666);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
            + '.loc-pop-n{margin-left:auto;color:var(--ink-3,#999);font-size:12px}'
            + '.loc-pop-extra{color:var(--ink-2,#666);font-style:italic}'
            + '.loc-pop-more,.loc-pop-empty{padding:8px;color:var(--ink-3,#999);font-size:12px}';
        document.head.appendChild(style);
        document.addEventListener('mousedown', function (e) {
            if (pop.classList.contains('open') && !pop.contains(e.target) && !(current && current.contains(e.target))) { close(); }
        });
        window.addEventListener('resize', close);
        window.addEventListener('scroll', function (e) { if (!pop.contains(e.target)) { close(); } }, true);
    }

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
    }

    function render() {
        var q = input.value, value = current.querySelector('input').value, counts = current.dataset.counts === '1';
        var found = search(q);
        items = [];
        if (q.trim() === '') {
            extrasOf(current).forEach(function (x) { items.push({ value: x[0], text: x[1], extra: true }); });
        }
        found.slice(0, LIMIT).forEach(function (l) { items.push({ value: String(l.id), loc: l }); });
        var html = items.map(function (it, i) {
            var cls = 'loc-pop-item' + (it.value === value ? ' sel' : '');
            var body = it.extra ? '<span class="loc-pop-code loc-pop-extra">' + esc(it.text) + '</span>'
                : '<span class="loc-pop-code">' + esc(it.loc.code) + '</span><span class="loc-pop-label">' + esc(it.loc.label + (it.loc.note ? ' · ' + it.loc.note : '')) + '</span>'
                  + (counts ? '<span class="loc-pop-n">' + it.loc.n + '</span>' : '');
            return '<div class="' + cls + '" role="option" data-i="' + i + '">' + body + '</div>';
        }).join('');
        if (found.length > LIMIT) {
            html += '<div class="loc-pop-more">…i jeszcze ' + (found.length - LIMIT) + '. Wpisz więcej znaków, żeby zawęzić.</div>';
        }
        if (items.length === 0) {
            html = '<div class="loc-pop-empty">' + (LOCS.length ? 'Brak lokalizacji pasujących do „' + esc(q) + '”.' : 'Brak lokalizacji. Dodaj je w Magazyn → Lokalizacje.') + '</div>';
        }
        list.innerHTML = html;
        var sel = items.findIndex(function (it) { return it.value === value; });
        setActive(q.trim() !== '' ? 0 : (sel >= 0 ? sel : 0));
    }

    function setActive(i) {
        var rows = list.querySelectorAll('.loc-pop-item');
        if (rows.length === 0) { active = -1; return; }
        active = Math.max(0, Math.min(i, rows.length - 1));
        rows.forEach(function (r, k) { r.classList.toggle('active', k === active); });
        rows[active].scrollIntoView({ block: 'nearest' });
    }

    function onKey(e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
        else if (e.key === 'PageDown') { e.preventDefault(); setActive(active + 8); }
        else if (e.key === 'PageUp') { e.preventDefault(); setActive(active - 8); }
        else if (e.key === 'Enter') { e.preventDefault(); if (active >= 0) { choose(active); } }
        else if (e.key === 'Escape') { e.preventDefault(); var btn = current.querySelector('button'); close(); btn.focus(); }
        else if (e.key === 'Tab') { close(); }
    }

    function choose(i) {
        var it = items[i];
        if (!it || !current) { return; }
        var picker = current, hidden = picker.querySelector('input'), btn = picker.querySelector('button');
        hidden.value = it.value;
        hidden.dataset.chosen = '1';
        btn.querySelector('.loc-picker-text').textContent = it.extra ? it.text : it.loc.code + (picker.dataset.wide === '1' ? ' · ' + it.loc.label : '');
        btn.classList.remove('is-empty');
        close();
        btn.focus();
        hidden.dispatchEvent(new Event('change', { bubbles: true }));
        if (picker.dataset.submit === '1' && hidden.form) {
            hidden.form.requestSubmit ? hidden.form.requestSubmit() : hidden.form.submit();
        }
    }

    function open(picker) {
        if (!pop) { build(); }
        current = picker;
        input.value = '';
        pop.classList.add('open');
        // Skala interfejsu (CSS zoom na <html>): pozycje z getBoundingClientRect są w pikselach ekranu,
        // a style.left/top w pikselach strony - przeliczamy przez zoom.
        var z = document.documentElement.currentCSSZoom || parseFloat(getComputedStyle(document.documentElement).zoom) || 1;
        var r = picker.getBoundingClientRect(), w = pop.offsetWidth * z, h = pop.offsetHeight * z;
        var left = Math.min(r.left, window.innerWidth - w - 8);
        var top = r.bottom + 4 + h > window.innerHeight && r.top - 4 - h > 0 ? r.top - 4 - h : r.bottom + 4;
        pop.style.left = Math.max(8, left) / z + 'px';
        pop.style.top = top / z + 'px';
        render();
        input.focus();
    }

    function close() {
        if (pop) { pop.classList.remove('open'); }
        current = null;
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.loc-picker-btn');
        if (!btn) { return; }
        var picker = btn.closest('.loc-picker');
        if (current === picker) { close(); } else { open(picker); }
    });
    document.addEventListener('keydown', function (e) {
        // Na przycisku: strzałka w dół albo pisanie od razu otwiera wyszukiwarkę z tym znakiem.
        var btn = e.target.closest && e.target.closest('.loc-picker-btn');
        if (!btn || e.ctrlKey || e.metaKey || e.altKey) { return; }
        if (e.key === 'ArrowDown' || (e.key.length === 1 && e.key !== ' ')) {
            e.preventDefault();
            open(btn.closest('.loc-picker'));
            if (e.key.length === 1) { input.value = e.key; render(); }
        }
    });

    /** Formularz z polem „required”: bez wyboru lokalizacji nie wysyłamy (np. akcja zbiorcza). */
    window.locPickerChosen = function (hidden, message) {
        if (hidden && hidden.dataset.chosen === '1') { return true; }
        alert(message || 'Wybierz lokalizację.');
        var picker = hidden && hidden.closest('.loc-picker');
        if (picker) { open(picker); }
        return false;
    };
})();
