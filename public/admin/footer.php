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
    <?= \Pase\Plugin\Hooks::render('admin.footer', $pageKey ?? '') ?>
</body>
</html>
