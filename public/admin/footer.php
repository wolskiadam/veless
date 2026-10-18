            <?php try { $appVer = \Pase\Support\AppVersion::current(); } catch (\Throwable) { $appVer = null; } ?>
            <?php if ($appVer): ?>
                <footer class="app-version" title="Numer rośnie o 1 przy każdym wdrożeniu z GitHuba; godzina = moment wdrożenia.<?= !empty($appVer['commit']) ? ' Commit: ' . htmlspecialchars($appVer['commit']) : '' ?>"><?= htmlspecialchars($appVer['label']) ?></footer>
            <?php endif; ?>
        </main>
        <style>.app-version { margin:32px 0 8px; text-align:center; font-size:12px; color:var(--ink-3, #a1a3a8); white-space:pre; }
            .tbl-scroll { overflow-x:auto; max-width:100%; -webkit-overflow-scrolling:touch; }</style>
    </div>
    <script<?= isset($cspNonce) ? ' nonce="' . htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
    // Telefon/tablet: strona nie przesuwa się w bok (header.php), więc tabela szersza od ekranu dostaje własny pasek przewijania.
    (function () {
        function scrollable(el) {
            for (var p = el.parentElement; p && p.tagName !== 'MAIN'; p = p.parentElement) {
                var o = getComputedStyle(p).overflowX;
                if (o === 'auto' || o === 'scroll') { return true; }
            }
            return false;
        }
        function wrap() {
            if (!window.matchMedia('(max-width:900px)').matches) { return; }
            document.querySelectorAll('main table').forEach(function (t) {
                if (t.parentElement.closest('table') || scrollable(t)) { return; }
                if (t.offsetWidth <= t.parentElement.clientWidth + 1 && t.getBoundingClientRect().right <= document.documentElement.clientWidth + 1) { return; }
                var w = document.createElement('div');
                w.className = 'tbl-scroll';
                t.parentNode.insertBefore(w, t);
                w.appendChild(t);
            });
        }
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', wrap); } else { wrap(); }
        window.addEventListener('resize', wrap);
    })();
    </script>
    <style>
        /* Przeciągnij w dół, aby odświeżyć (telefon/tablet). Wbudowane odświeżanie Chrome wyłączone, żeby nie było dwóch naraz. */
        @media (pointer:coarse) { html { overscroll-behavior-y:contain; } }
        .ptr { position:fixed; top:-46px; left:50%; z-index:1000; width:40px; height:40px; margin-left:-20px; border-radius:50%;
            display:flex; align-items:center; justify-content:center; background:var(--surface, #fff); color:var(--ink-2, #5f6368);
            border:1px solid var(--line, #e5e5e5); box-shadow:0 4px 14px rgba(0,0,0,.14); pointer-events:none; opacity:0; }
        .ptr.back { transition:transform .2s ease, opacity .2s ease; }
        .ptr svg { width:20px; height:20px; transition:color .15s ease; }
        .ptr.ready, .ptr.loading { color:var(--accent, #9a6a2f); }
        .ptr.loading svg { animation:ptr-spin .9s linear infinite; }
        @keyframes ptr-spin { to { transform:rotate(360deg); } }
    </style>
    <script<?= isset($cspNonce) ? ' nonce="' . htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
    // Przeciągnij w dół na samej górze strony, aby ją odświeżyć - na telefonie, także w aplikacji z ekranu głównego
    // (tam przeglądarka nie ma własnego odświeżania). Nie rusza przewijanych okienek, pól formularza ani otwartych okien.
    (function () {
        if (!(navigator.maxTouchPoints > 0 || 'ontouchstart' in window) || !window.matchMedia('(pointer:coarse)').matches) { return; }
        var READY = 70, MAX = 110;
        var ind = document.createElement('div');
        ind.className = 'ptr';
        ind.setAttribute('aria-hidden', 'true');
        ind.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><polyline points="21 3 21 9 15 9"/></svg>';
        document.body.appendChild(ind);
        var startX = 0, startY = null, pull = 0, active = false, busy = false;

        function blocked(target) {
            if (document.querySelector('dialog[open]')) { return true; }
            var a = document.activeElement;
            if (a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)) { return true; }   // otwarta klawiatura
            for (var el = target; el && el !== document.body && el.nodeType === 1; el = el.parentElement) {
                if (el.isContentEditable || el.hasAttribute('data-no-pull-refresh')) { return true; }
                var o = getComputedStyle(el).overflowY;
                if ((o === 'auto' || o === 'scroll') && el.scrollTop > 0) { return true; }   // okienko przewinięte w dół - najpierw przewija się ono
            }
            return false;
        }
        function show() {
            ind.style.opacity = String(Math.min(1, pull / READY));
            ind.style.transform = 'translateY(' + (pull + 10) + 'px) rotate(' + (pull * 3) + 'deg)';
            ind.classList.toggle('ready', pull >= READY);
        }
        function reset() {
            ind.classList.add('back');
            ind.classList.remove('ready');
            ind.style.opacity = '0';
            ind.style.transform = '';
            setTimeout(function () { ind.classList.remove('back'); }, 220);
        }

        document.addEventListener('touchstart', function (e) {
            startY = null;
            if (busy || e.touches.length !== 1 || window.scrollY > 0 || blocked(e.target)) { return; }
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
            pull = 0;
            active = false;
        }, { passive: true });

        document.addEventListener('touchmove', function (e) {
            if (startY === null) { return; }
            var dy = e.touches[0].clientY - startY, dx = e.touches[0].clientX - startX;
            if (!active) {
                if (dy < 0 || Math.abs(dx) > Math.abs(dy)) { startY = null; return; }   // przewijanie w górę albo w bok
                if (dy < 8) { return; }
                if (window.scrollY > 0) { startY = null; return; }
                active = true;
            }
            e.preventDefault();
            pull = Math.min(MAX, dy * 0.5);
            show();
        }, { passive: false });

        function end() {
            if (startY === null || !active) { startY = null; return; }
            startY = null;
            active = false;
            if (pull >= READY) {
                busy = true;
                ind.classList.add('back', 'loading');
                ind.style.opacity = '1';
                ind.style.transform = 'translateY(' + (READY + 10) + 'px)';
                // Zwykłe wejście na ten sam adres (GET) - bez pytania o ponowne wysłanie formularza po zapisie.
                location.replace(location.href.split('#')[0]);
            } else {
                reset();
            }
        }
        document.addEventListener('touchend', end);
        document.addEventListener('touchcancel', function () { if (active) { startY = null; active = false; reset(); } });
    })();
    </script>
    <?= \Pase\Plugin\Hooks::render('admin.footer', $pageKey ?? '') ?>
</body>
</html>
