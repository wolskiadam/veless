            <?php try { $appVer = \Pase\Support\AppVersion::current(); } catch (\Throwable) { $appVer = null; } ?>
            <?php if ($appVer): ?>
                <footer class="app-version" title="Numer rośnie o 1 przy każdym wdrożeniu z GitHuba; godzina = moment wdrożenia.<?= !empty($appVer['commit']) ? ' Commit: ' . htmlspecialchars($appVer['commit']) : '' ?>"><?= htmlspecialchars($appVer['label']) ?></footer>
            <?php endif; ?>
        </main>
        <style>.app-version { margin:32px 0 8px; text-align:center; font-size:12px; color:var(--ink-3, #a1a3a8); white-space:pre; }</style>
    </div>
    <?= \Pase\Plugin\Hooks::render('admin.footer', $pageKey ?? '') ?>
</body>
</html>
