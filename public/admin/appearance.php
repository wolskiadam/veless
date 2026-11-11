<?php
declare(strict_types=1);

/**
 * Konfiguracja → Wygląd. Administrator wybiera kolor systemu (akcent: przyciski, linki,
 * zaznaczenia) i odcień tła. Ustawienie jest wspólne dla całej instalacji: panel, ekran
 * logowania, pakowanie na telefonie i ikona aplikacji. Zapis i wyliczanie odcieni: Support\Theme.
 * Strona klienta (podgląd zamówienia) ma osobne kolory per sklep w Konfiguracja → Strona klienta.
 */

use Pase\Support\Theme;

require __DIR__ . '/auth.php';
requireRole(['admin']);
/** @var PDO $pdo */

$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrfCheck();
    if (($_POST['action'] ?? '') === 'reset') {
        Theme::save($pdo, Theme::defaults());
        flash('Przywrócono domyślne kolory.');
    } else {
        $wanted = Theme::hex((string) ($_POST['accent'] ?? ''));
        $saved = Theme::save($pdo, ['accent' => (string) $wanted, 'tone' => (string) ($_POST['tone'] ?? '')]);
        flash($wanted !== null && $saved['accent'] !== $wanted
            ? 'Zapisano. Wybrany kolor był za jasny dla białych napisów na przyciskach, więc lekko go przyciemniliśmy.'
            : 'Zapisano kolory systemu.');
    }
    redirectAfterPost();
}

$theme = Theme::load($pdo);
$presetKey = null;
foreach (Theme::PRESETS as $k => [, $hex]) {
    if ($hex === $theme['accent']) { $presetKey = $k; }
}

$PAGE_TITLE = 'Wygląd';
$PAGE_KEY   = 'appearance';
require __DIR__ . '/header.php';
?>
<style>
    .ap-grid { display:grid; grid-template-columns:minmax(0, 1fr) minmax(0, 380px); gap:18px; align-items:start; }
    .ap-card h2 { font-size:16px; margin:0 0 4px; }
    .ap-card .hint { color:var(--ink-2); font-size:13px; margin:0 0 14px; line-height:1.45; }
    .ap-swatches { display:grid; grid-template-columns:repeat(auto-fill, minmax(118px, 1fr)); gap:10px; }
    .ap-sw { position:relative; display:flex; align-items:center; gap:10px; padding:10px 12px; border:1px solid var(--line);
        border-radius:var(--radius-sm); background:var(--surface); cursor:pointer; font-size:14px; font-weight:600; }
    .ap-sw:hover { border-color:var(--ink-3); }
    .ap-sw input[type=radio] { position:absolute; opacity:0; pointer-events:none; }
    .ap-sw .dot { width:24px; height:24px; border-radius:50%; flex:none; box-shadow:inset 0 0 0 1px rgba(0,0,0,.12); }
    .ap-sw:has(input:checked) { border-color:var(--accent); box-shadow:0 0 0 2px var(--accent-soft); }
    .ap-sw:has(input:checked)::after { content:'✓'; margin-left:auto; color:var(--accent); font-weight:800; }
    .ap-sw:has(input:focus-visible) { outline:2px solid var(--accent); outline-offset:2px; }
    .ap-custom input[type=color] { width:24px; height:24px; padding:0; border:0; border-radius:50%; background:none; cursor:pointer; flex:none; }
    .ap-custom input[type=color]::-webkit-color-swatch-wrapper { padding:0; }
    .ap-custom input[type=color]::-webkit-color-swatch { border:0; border-radius:50%; box-shadow:inset 0 0 0 1px rgba(0,0,0,.12); }
    .ap-custom input[type=color]::-moz-color-swatch { border:0; border-radius:50%; }
    .ap-custom .hexv { font-family:var(--font-num); font-size:12px; color:var(--ink-2); font-weight:500; }
    .ap-tones { display:grid; grid-template-columns:repeat(auto-fill, minmax(170px, 1fr)); gap:10px; }
    .ap-tone .dot { border-radius:6px; }
    .ap-note { margin-top:12px; font-size:13px; color:var(--warn-ink); background:var(--warn-bg); padding:8px 10px; border-radius:var(--radius-sm); }
    .ap-actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:4px; }
    .ap-actions .muted { color:var(--ink-2); font-size:13px; }
    /* Podgląd - te same klasy co w panelu, więc pokazuje dokładnie to, co zobaczą użytkownicy. */
    .ap-preview { position:sticky; top:12px; }
    .ap-prev-bar { display:flex; gap:6px; align-items:center; margin-bottom:14px; }
    .ap-prev-tab { padding:6px 10px; border-radius:7px; font-size:13px; font-weight:600; color:var(--ink-2); }
    .ap-prev-tab.on { background:var(--accent-soft); color:var(--accent-ink); }
    .ap-prev-row { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin:12px 0; }
    .ap-prev-box { background:var(--bg); border:1px solid var(--line); border-radius:var(--radius-sm); padding:12px; font-size:14px; }
    .ap-prev-box .sel { border-left:3px solid var(--accent); background:var(--accent-soft); padding:6px 10px; border-radius:0 6px 6px 0; margin-top:8px; color:var(--accent-ink); font-weight:600; }
    @media (max-width:900px) { .ap-grid { grid-template-columns:1fr; } .ap-preview { position:static; } }
    @media (max-width:520px) { .ap-swatches { grid-template-columns:repeat(2, minmax(0, 1fr)); } .ap-tones { grid-template-columns:1fr; } }
</style>

<form method="post" id="apForm">
    <input type="hidden" name="csrf" value="<?= $e(csrfToken()) ?>">
    <div class="ap-grid">
        <div>
            <div class="card ap-card">
                <h2>Kolor systemu</h2>
                <p class="hint">Przyciski, linki, zaznaczenia w menu i na listach. Obowiązuje wszystkich użytkowników tej instalacji,
                    a także ekran logowania, pakowanie na telefonie i ikonę aplikacji na telefonie.</p>
                <div class="ap-swatches">
                    <?php foreach (Theme::PRESETS as $k => [$name, $hex]): ?>
                        <label class="ap-sw">
                            <input type="radio" name="preset" value="<?= $e($hex) ?>"<?= $presetKey === $k ? ' checked' : '' ?>>
                            <span class="dot" style="background:<?= $e($hex) ?>"></span><?= $e($name) ?>
                        </label>
                    <?php endforeach; ?>
                    <label class="ap-sw ap-custom" title="Dowolny kolor, np. z logo firmy">
                        <input type="radio" name="preset" value="custom"<?= $presetKey === null ? ' checked' : '' ?>>
                        <input type="color" id="apColor" value="<?= $e($theme['accent']) ?>" aria-label="Własny kolor">
                        <span>Własny<br><span class="hexv" id="apHex"><?= $e($theme['accent']) ?></span></span>
                    </label>
                </div>
                <input type="hidden" name="accent" id="apAccent" value="<?= $e($theme['accent']) ?>">
                <div class="ap-note" id="apNote" hidden>Ten kolor jest za jasny dla białych napisów na przyciskach - zapiszemy go lekko przyciemnionego (<span></span>).</div>
            </div>

            <div class="card ap-card">
                <h2>Tło</h2>
                <p class="hint">Odcień tła stron i linii oddzielających. Karty i tabele zostają białe.</p>
                <div class="ap-tones">
                    <?php foreach (Theme::TONES as $k => [$name, $bg, , $line]): ?>
                        <label class="ap-sw ap-tone">
                            <input type="radio" name="tone" value="<?= $e($k) ?>"<?= $theme['tone'] === $k ? ' checked' : '' ?>>
                            <span class="dot" style="background:<?= $e($bg) ?>;box-shadow:inset 0 0 0 2px <?= $e($line) ?>"></span><?= $e($name) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="ap-actions">
                <button class="btn" type="submit">Zapisz kolory</button>
                <button class="btn secondary" type="submit" name="action" value="reset" formnovalidate>Przywróć domyślne</button>
                <span class="muted">Zmiany widać od razu na tej stronie - zapis obejmie cały system.</span>
            </div>
        </div>

        <div class="card ap-card ap-preview" aria-label="Podgląd">
            <h2>Podgląd</h2>
            <p class="hint">Tak będą wyglądać elementy panelu.</p>
            <div class="ap-prev-bar"><span class="ap-prev-tab on">Zamówienia</span><span class="ap-prev-tab">Produkty</span><span class="ap-prev-tab">Konfiguracja</span></div>
            <div class="ap-prev-row">
                <span class="btn">Zapisz</span>
                <span class="btn secondary">Anuluj</span>
                <a href="#" onclick="return false">Link w tekście</a>
            </div>
            <div class="ap-prev-row">
                <span class="pill ok">Spakowane</span><span class="pill warn">Czeka</span><span class="pill muted">Nowe</span>
            </div>
            <input type="text" value="Pole w trakcie edycji" style="width:100%" tabindex="-1" readonly class="ap-prev-focus">
            <div class="ap-prev-box" style="margin-top:12px">Zamówienie #1042 · 2 produkty
                <div class="sel">Zaznaczona pozycja menu</div>
            </div>
        </div>
    </div>
</form>

<script<?= isset($cspNonce) ? ' nonce="' . $e($cspNonce) . '"' : '' ?>>
(() => {
    // Te same wzory co Support\Theme (PHP) - podgląd na żywo bez zapisu.
    const TONES = <?= json_encode(array_map(static fn($t) => ['bg' => $t[1], 's2' => $t[2], 'line' => $t[3]], Theme::TONES)) ?>;
    const form = document.getElementById('apForm');
    const color = document.getElementById('apColor');
    const hidden = document.getElementById('apAccent');
    const hexLbl = document.getElementById('apHex');
    const note = document.getElementById('apNote');
    const rgb = h => [1, 3, 5].map(i => parseInt(h.slice(i, i + 2), 16));
    const mix = (a, b, t) => { const x = rgb(a), y = rgb(b); return '#' + x.map((v, i) => Math.round(v + (y[i] - v) * t).toString(16).padStart(2, '0')).join(''); };
    const lum = h => { const [r, g, b] = rgb(h).map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
    const readable = h => { let c = h; for (let i = 0; i < 40 && 1.05 / (lum(c) + 0.05) < 3; i++) c = mix(c, '#000000', 0.05); return c; };
    const root = document.documentElement.style;

    function apply() {
        const preset = form.querySelector('input[name=preset]:checked');
        const wanted = (preset && preset.value !== 'custom' ? preset.value : color.value).toLowerCase();
        const ac = readable(wanted);
        hidden.value = ac;
        hexLbl.textContent = color.value.toLowerCase();
        note.hidden = ac === wanted;
        note.querySelector('span').textContent = ac;
        root.setProperty('--accent', ac);
        root.setProperty('--accent-hover', mix(ac, '#000000', 0.16));
        root.setProperty('--accent-soft', mix(ac, '#ffffff', 0.84));
        root.setProperty('--accent-ink', mix(ac, '#000000', 0.42));
        const tone = TONES[(form.querySelector('input[name=tone]:checked') || {}).value] || TONES.warm;
        root.setProperty('--bg', tone.bg); root.setProperty('--surface-2', tone.s2); root.setProperty('--line', tone.line);
    }
    // Kliknięcie w próbnik koloru wybiera też „Własny”.
    color.addEventListener('input', () => { form.querySelector('input[name=preset][value=custom]').checked = true; apply(); });
    color.addEventListener('click', () => { form.querySelector('input[name=preset][value=custom]').checked = true; apply(); });
    form.addEventListener('change', apply);
    apply();
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
