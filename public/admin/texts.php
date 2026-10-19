<?php
declare(strict_types=1);

/**
 * Teksty systemu (Konfiguracja → Teksty). Edycja tekstów ze słowników lang/<język>.php
 * bez zmiany kodu: zmieniony tekst trafia do tabeli settings ("text.<język>.<klucz>")
 * i ma pierwszeństwo nad plikiem (Pase\Support\I18n::useOverrides). Pusty albo równy
 * domyślnemu = zmiana usunięta, wraca tekst z pliku.
 */

use Pase\Repository\SettingsRepository;
use Pase\Support\I18n;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$settings = new SettingsRepository($pdo);
$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$languages = I18n::available();
$lang = (string) ($_GET['lang'] ?? I18n::DEFAULT);
if (!isset($languages[$lang])) {
    $lang = I18n::DEFAULT;
}

// Domyślne teksty: z pliku języka, a brakujące - z polskiego (tak jak w t()).
$defaults = I18n::dictionary($lang) + I18n::dictionary(I18n::DEFAULT);
ksort($defaults);

/** @return array<string,string> zmiany zapisane w bazie dla języka */
$loadOverrides = static function () use ($settings, $lang): array {
    $all = $settings->all();
    I18n::useOverrides($all);
    return I18n::overrides($lang);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $current = $loadOverrides();
    $save = [];
    $drop = [];
    $warn = [];

    $reset = (string) ($_POST['reset'] ?? '');
    if ($reset !== '' && isset($defaults[$reset])) {
        $drop[] = I18n::overrideKey($lang, $reset);
    } else {
        foreach ((array) ($_POST['text'] ?? []) as $key => $value) {
            $key = (string) $key;
            if (!isset($defaults[$key]) || strlen(I18n::overrideKey($lang, $key)) > 64) {
                continue;
            }
            $value = I18n::clean((string) $value);
            if ($value === '' || $value === $defaults[$key]) {
                if (isset($current[$key])) {
                    $drop[] = I18n::overrideKey($lang, $key);
                }
                continue;
            }
            if (($current[$key] ?? null) === $value) {
                continue;
            }
            $save[I18n::overrideKey($lang, $key)] = $value;
            $missing = array_diff(I18n::placeholders($defaults[$key]), I18n::placeholders($value));
            if ($missing !== []) {
                $warn[] = $key . ' (brak: {' . implode('}, {', $missing) . '})';
            }
        }
    }

    $settings->setMany($save);
    $settings->deleteMany($drop);

    if ($reset !== '') {
        flash('Przywrócono domyślny tekst.');
    } elseif ($save === [] && $drop === []) {
        flash('Brak zmian do zapisania.');
    } else {
        flash('Zapisano teksty: zmienione ' . count($save) . ', przywrócone do domyślnych ' . count($drop) . '.');
    }
    if ($warn !== []) {
        flash('Uwaga, w tych tekstach brakuje wstawianych wartości (numeru, liczby itp.), więc nie będzie ich widać: ' . implode('; ', $warn) . '.', 'err');
    }
    redirectAfterPost();
}

$overrides = $loadOverrides();

// Obszary = pierwszy człon klucza (client.verify.title → client).
$groupNames = [
    'client' => 'Strona zamówienia klienta',
    'email'  => 'E-maile',
    'login'  => 'Logowanie',
    'nav'    => 'Menu panelu',
    'orders' => 'Lista zamówień',
    'stats'  => 'Statystyki',
    'common' => 'Wspólne (przyciski, komunikaty)',
    'pager'  => 'Stronicowanie',
];
$groupOf = static fn(string $key): string => explode('.', $key, 2)[0];
$groups = [];
foreach (array_keys($defaults) as $key) {
    $g = $groupOf($key);
    $groups[$g] = $groupNames[$g] ?? $g;
}
asort($groups);

$area   = (string) ($_GET['group'] ?? '');
$q       = trim((string) ($_GET['q'] ?? ''));
$changed = !empty($_GET['changed']);

$rows = [];
foreach ($defaults as $key => $default) {
    if ($area !== '' && $groupOf($key) !== $area) {
        continue;
    }
    if ($changed && !isset($overrides[$key])) {
        continue;
    }
    $value = $overrides[$key] ?? $default;
    if ($q !== '' && mb_stripos($key . ' ' . $default . ' ' . $value, $q) === false) {
        continue;
    }
    $rows[$key] = ['default' => $default, 'value' => $value, 'changed' => isset($overrides[$key])];
}

$PAGE_TITLE = 'Teksty';
$PAGE_KEY   = 'texts';
require __DIR__ . '/header.php';
?>
<style>
    .tx-filters { display:flex; flex-wrap:wrap; gap:10px; align-items:end; }
    .tx-filters label { display:block; font-size:12px; color:var(--ink-2); margin-bottom:4px; }
    .tx-table { width:100%; }
    .tx-table td { vertical-align:top; }
    .tx-key { font-size:11px; color:var(--ink-2); word-break:break-all; }
    .tx-default { font-size:12px; color:var(--ink-2); margin-top:3px; }
    .tx-table input[type=text] { width:100%; box-sizing:border-box; }
    .tx-badge { display:inline-block; font-size:11px; padding:1px 6px; border-radius:8px; background:var(--accent, #4a6cf7); color:#fff; margin-left:4px; }
    .tx-save { position:sticky; bottom:0; background:var(--surface, #fff); padding:10px 0; display:flex; gap:10px; align-items:center; }
    @media (max-width: 700px) {
        .tx-table tr, .tx-table td { display:block; }
        .tx-table tr:first-child { display:none; }
    }
</style>

<div class="card">
    <strong>Teksty systemu</strong>
    <p style="color:#888;font-size:13px;margin:6px 0 0">
        Tu zmienisz teksty widoczne dla klienta (strona zamówienia, e-maile) oraz część napisów panelu
        (menu, lista zamówień, statystyki, logowanie). Zmiana działa od razu, a przycisk „Przywróć” wraca do tekstu domyślnego.
        Fragmenty w nawiasach klamrowych, np. <code>{order}</code> albo <code>{n}</code>, system zamienia na numer zamówienia,
        liczbę itp., więc zostaw je w tekście. Treść e-maili z automatyzacji zmienisz w <a href="email_templates.php">Szablonach e-mail</a>.
    </p>

    <form method="get" class="tx-filters" style="margin-top:14px">
        <?php if (count($languages) > 1): ?>
            <div><label>Język</label>
                <select name="lang">
                    <?php foreach ($languages as $code => $name): ?>
                        <option value="<?= $e($code) ?>" <?= $code === $lang ? 'selected' : '' ?>><?= $e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div><label>Obszar</label>
            <select name="group">
                <option value="">Wszystkie</option>
                <?php foreach ($groups as $g => $name): ?>
                    <option value="<?= $e($g) ?>" <?= $g === $area ? 'selected' : '' ?>><?= $e($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label>Szukaj</label><input type="search" name="q" value="<?= $e($q) ?>" placeholder="np. zamówienie"></div>
        <div><label style="display:flex;gap:6px;align-items:center;margin-bottom:8px;cursor:pointer">
            <input type="checkbox" name="changed" value="1" <?= $changed ? 'checked' : '' ?>> tylko zmienione</label></div>
        <div><button class="btn" type="submit">Pokaż</button> <a class="btn secondary" href="texts.php<?= $lang !== I18n::DEFAULT ? '?lang=' . $e($lang) : '' ?>">Wyczyść</a></div>
    </form>
    <p style="color:#888;font-size:12px;margin:8px 0 0">
        Pokazano <?= count($rows) ?> z <?= count($defaults) ?> tekstów, zmienionych: <?= count($overrides) ?>.
    </p>
</div>

<div class="card">
    <?php if ($rows === []): ?>
        <p style="color:#888">Brak tekstów dla podanych filtrów.</p>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <table class="tx-table">
                <tr><th style="width:30%">Gdzie</th><th>Tekst</th><th style="width:1%"></th></tr>
                <?php foreach ($rows as $key => $r): ?>
                    <tr>
                        <td>
                            <?= $e($groups[$groupOf($key)] ?? '') ?>
                            <?php if ($r['changed']): ?><span class="tx-badge">zmieniony</span><?php endif; ?>
                            <div class="tx-key"><?= $e($key) ?></div>
                        </td>
                        <td>
                            <input type="text" name="text[<?= $e($key) ?>]" value="<?= $e($r['value']) ?>" maxlength="1000" aria-label="<?= $e($key) ?>">
                            <?php if ($r['changed']): ?><div class="tx-default">Domyślnie: <?= $e($r['default']) ?></div><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['changed']): ?>
                                <button class="btn secondary" type="submit" form="tx-reset" name="reset" value="<?= $e($key) ?>">Przywróć</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <div class="tx-save"><button class="btn" type="submit">Zapisz teksty</button>
                <span style="color:#888;font-size:12px">Zapisują się tylko zmienione teksty.</span></div>
        </form>
        <?php // Osobny formularz dla „Przywróć” - Enter w polu tekstu ma zapisywać, a nie przywracać pierwszego wiersza. ?>
        <form method="post" id="tx-reset"><input type="hidden" name="csrf" value="<?= csrfToken() ?>"></form>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/footer.php'; ?>
