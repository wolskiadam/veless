<?php
declare(strict_types=1);

/**
 * Baza wiedzy PASE — instrukcje i dokumentacja systemu.
 * Treści w help_content.php. Lewy spis treści (po kategoriach) + artykuł po prawej.
 * Wybór artykułu przez ?a=slug; wyszukiwarka filtruje spis po stronie klienta.
 */

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

/** @var array<string,array{cat:string,title:string,body:string}> $articles */
$articles = require __DIR__ . '/help_content.php';

// Grupowanie po kategorii (z zachowaniem kolejności wystąpienia).
$byCat = [];
foreach ($articles as $slug => $a) {
    $byCat[$a['cat']][$slug] = $a;
}

$current = (string) ($_GET['a'] ?? '');
if (!isset($articles[$current])) {
    $current = array_key_first($articles);
}
$article = $articles[$current];

$PAGE_TITLE = 'Baza wiedzy';
$PAGE_KEY   = 'help';
require __DIR__ . '/header.php';
?>

<div class="kb">
    <aside class="kb-side">
        <input type="text" id="kbSearch" placeholder="Szukaj w bazie wiedzy…" class="kb-search">
        <nav id="kbNav">
            <?php foreach ($byCat as $cat => $items): ?>
                <div class="kb-cat" data-cat>
                    <div class="kb-cat-name"><?= htmlspecialchars($cat) ?></div>
                    <?php foreach ($items as $slug => $a): ?>
                        <a href="?a=<?= urlencode($slug) ?>"
                           class="kb-link<?= $slug === $current ? ' active' : '' ?>"
                           data-title="<?= htmlspecialchars(mb_strtolower($a['title'] . ' ' . $cat)) ?>">
                            <?= htmlspecialchars($a['title']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            <p id="kbEmpty" class="kb-empty" style="display:none">Brak wyników.</p>
        </nav>
    </aside>

    <article class="kb-main card">
        <div class="kb-crumb"><?= htmlspecialchars($article['cat']) ?></div>
        <h2 class="kb-title"><?= htmlspecialchars($article['title']) ?></h2>
        <div class="kb-body"><?= $article['body'] ?></div>
    </article>
</div>

<style>
    .kb { display:grid; grid-template-columns:260px 1fr; gap:20px; align-items:start; }
    .kb-side { position:sticky; top:18px; }
    .kb-search { width:100%; margin-bottom:12px; }
    .kb-cat { margin-bottom:14px; }
    .kb-cat-name { font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:#9aa3af; font-weight:700; margin:0 0 6px 4px; }
    .kb-link { display:block; padding:8px 12px; border-radius:8px; text-decoration:none; color:var(--ink); font-size:14px; }
    .kb-link:hover { background:#eef0f6; }
    .kb-link.active { background:#e8f0fe; color:#1a73e8; font-weight:600; }
    .kb-empty { color:#9aa3af; font-size:13px; padding:8px 12px; }
    .kb-main { padding:24px 28px; }
    .kb-crumb { font-size:12px; text-transform:uppercase; letter-spacing:.4px; color:#9aa3af; font-weight:700; }
    .kb-title { margin:4px 0 16px; font-size:22px; }
    .kb-body { font-size:15px; line-height:1.7; color:#333; }
    .kb-body h3 { font-size:16px; margin:20px 0 8px; }
    .kb-body ul, .kb-body ol { padding-left:22px; }
    .kb-body li { margin:4px 0; }
    .kb-body code { background:#f1f3f5; padding:1px 6px; border-radius:5px; font-size:13px; }
    .kb-body table { margin:12px 0; }
    .kb-body td code { background:transparent; padding:0; }
    .kb-body pre { background:#1f2733; color:#e6edf3; padding:14px 16px; border-radius:8px; overflow:auto; font-size:13px; line-height:1.5; margin:12px 0; }
    .kb-body pre code { background:transparent; color:inherit; padding:0; }
    .kb-note { background:#fff8e6; border:1px solid #f0d9a8; color:#7a5b18; border-radius:8px; padding:10px 12px; font-size:13px; }
    @media (max-width:820px) { .kb { grid-template-columns:1fr; } .kb-side { position:static; } }
</style>
<script>
(function () {
    var input = document.getElementById('kbSearch');
    if (!input) return;
    var links = Array.prototype.slice.call(document.querySelectorAll('.kb-link'));
    var cats  = Array.prototype.slice.call(document.querySelectorAll('[data-cat]'));
    var empty = document.getElementById('kbEmpty');
    input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase();
        var anyVisible = false;
        links.forEach(function (a) {
            var match = q === '' || (a.getAttribute('data-title') || '').indexOf(q) !== -1;
            a.style.display = match ? '' : 'none';
            if (match) anyVisible = true;
        });
        // Ukryj kategorie bez widocznych linków.
        cats.forEach(function (c) {
            var visible = c.querySelectorAll('.kb-link:not([style*="display: none"])').length;
            c.style.display = visible ? '' : 'none';
        });
        empty.style.display = anyVisible ? 'none' : 'block';
    });
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
