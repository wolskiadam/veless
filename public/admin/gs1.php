<?php
declare(strict_types=1);

/**
 * Magazyn → GS1 (MojeGS1): karty produktów i numery GTIN/EAN z GS1 Polska obok produktów CRM.
 *  - lista z GS1 odświeża się sama przy wejściu (co Gs1::AUTO_SYNC_HOURS h) albo przyciskiem; GS1 jest wtedy tylko czytane,
 *  - „Nadaj GTIN” tworzy kartę w MojeGS1 i wpisuje numer jako EAN produktu - wyłącznie po potwierdzeniu formularza.
 * Szczegóły: Services\Gs1. Połączenie z API i dane konta daje wtyczka integrations/gs1 (Konfiguracja → Integracje).
 */

use Pase\Services\Gs1;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
header('Cache-Control: no-store');
/** @var PDO $pdo */

Gs1::migrate($pdo);
$gs1 = new Gs1($pdo);
if (!Gs1::available()) {
    $PAGE_TITLE = 'GS1 (kody EAN)';
    $PAGE_KEY   = 'gs1';
    require __DIR__ . '/header.php';
    echo '<div class="card"><p>Wtyczka GS1 jest wyłączona albo usunięta. Włącz ją w Konfiguracja → Wtyczki (administrator).</p></div>';
    require __DIR__ . '/footer.php';
    exit;
}
$gs1Account = $gs1->account();
$gs1AccountLink = $gs1Account !== null ? 'integration_edit.php?id=' . (int) $gs1Account['id'] : 'integration_edit.php?type=gs1';
$canEdit = canEdit();
$assignId = (int) ($_REQUEST['assign'] ?? 0);
$formValues = null;
$formError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if (!$canEdit) {
        flash('Brak uprawnień do zmian.', 'err');
        redirectAfterPost();
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'sync') {
            $r = $gs1->sync();
            flash('Pobrano z GS1 kart produktów: ' . $r['total'] . ', powiązanych z produktami CRM po EAN: ' . $r['linked'] . '.'
                . ($r['truncated'] ? ' Lista jest bardzo długa - pobrano tylko część (limit MojeGS1 60 zapytań/min). Kliknij ponownie za minutę.' : ''),
                $r['truncated'] ? 'err' : 'ok');
        } elseif ($action === 'link') {
            $w = $gs1->linkEan((int) ($_POST['product_id'] ?? 0), (string) ($_POST['gtin'] ?? ''), $gs1->pushToWoo() ? Gs1::wooPusher($pdo) : null);
            flash('Wpisano EAN ' . $_POST['gtin'] . ' do produktu.' . ($gs1->pushToWoo() ? ($w === null ? ' Wysłano też do sklepu WooCommerce.' : ' Nie udało się wysłać do sklepu: ' . $w . '.') : ''),
                $w === null ? 'ok' : 'err');
        } elseif ($action === 'assign') {
            $formValues = array_map(static fn($v) => is_string($v) ? $v : '', (array) ($_POST['f'] ?? []));
            $r = $gs1->assign($assignId, $formValues, $gs1->pushToWoo() ? Gs1::wooPusher($pdo) : null);
            flash('Nadano GTIN ' . $r['gtin'] . ' i utworzono kartę w MojeGS1. Numer jest już EAN-em produktu w CRM.'
                . ($gs1->pushToWoo() ? ($r['woo'] === null ? ' Wysłano też do sklepu WooCommerce.' : ' Nie udało się wysłać do sklepu: ' . $r['woo'] . ' - wpisz go tam ręcznie.') : '')
                . ($r['suggestions'] ? ' Wskazówki GS1: ' . implode(' · ', $r['suggestions']) : ''),
                $r['woo'] === null ? 'ok' : 'err');
            redirectAfterPost('product_view.php?id=' . $assignId);
        }
    } catch (\Throwable $e) {
        if ($action === 'assign') {
            $formError = $e->getMessage();   // formularz zostaje z wpisanymi danymi
        } else {
            flash($e->getMessage(), 'err');
        }
    }
    if ($formError === null) {
        redirectAfterPost('gs1.php');
    }
}

// Automatyczny odczyt z GS1 przy wejściu na stronę (tylko odczyt, co kilka godzin).
$syncError = null;
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $assignId === 0 && $gs1->configured() && $gs1->syncDue()) {
    try {
        $gs1->sync();
    } catch (\Throwable $e) {
        $syncError = $e->getMessage();
    }
}

$product = null;
if ($assignId > 0) {
    $st = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $st->execute([$assignId]);
    $product = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($product !== null && $formValues === null) {
        $formValues = $gs1->formDefaults($product);
    }
}
$ov = $gs1->overview();
$tab = in_array($_GET['tab'] ?? '', ['cards', 'without', 'foreign'], true) ? $_GET['tab'] : 'cards';
// Wyszukiwarka (GTIN/EAN, nazwa, SKU, symbol) i paginacja po 50 w każdej zakładce.
$q = trim((string) ($_GET['q'] ?? ''));
$found = ['cards' => Gs1::search($ov['cards'], $q), 'without' => Gs1::search($ov['without_ean'], $q), 'foreign' => Gs1::search($ov['foreign'], $q)];
$perPage = 50;
$pages = max(1, (int) ceil(count($found[$tab]) / $perPage));
$page = max(1, min($pages, (int) ($_GET['p'] ?? 1)));
$rows = array_slice($found[$tab], ($page - 1) * $perPage, $perPage);
$tabLink = static fn(string $t, int $p = 1): string => 'gs1.php?' . http_build_query(array_filter(['tab' => $t, 'q' => $q, 'p' => $p > 1 ? $p : null], static fn($v) => $v !== null && $v !== ''));
$e = static fn(mixed $v): string => htmlspecialchars((string) $v);
$statusLabel = static fn(?string $s): string => match (strtoupper((string) $s)) { 'ACT' => 'aktywny', '' => '—', default => (string) $s };

$PAGE_TITLE = 'GS1 (kody EAN)';
$PAGE_KEY   = 'gs1';
require __DIR__ . '/header.php';
?>
<style>
    .g1-muted { color:var(--ink-2); font-size:12.5px; }
    .g1-mono { font-family:var(--font-num); font-variant-numeric:tabular-nums; white-space:nowrap; }
    .g1-link { color:inherit; text-decoration:underline; text-decoration-color:var(--line, #ccc); text-underline-offset:2px; }
    .g1-link:hover { color:var(--accent); text-decoration-color:currentColor; }
    .g1-search { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:0 0 12px; }
    .g1-search input[type=search] { flex:1; min-width:220px; max-width:420px; }
    .g1-tabs { display:flex; gap:8px; flex-wrap:wrap; margin:0 0 12px; }
    .g1-tabs a { padding:6px 12px; border:1px solid var(--line); border-radius:999px; text-decoration:none; color:inherit; font-size:13px; }
    .g1-tabs a.on { background:var(--accent); border-color:var(--accent); color:#fff; }
    .g1-form { display:grid; grid-template-columns:repeat(auto-fill, minmax(230px, 1fr)); gap:12px; }
    .g1-form label { display:flex; flex-direction:column; gap:4px; font-size:13px; }
    .g1-form .wide { grid-column:1 / -1; }
    .g1-pill { display:inline-block; padding:1px 8px; border-radius:999px; font-size:12px; background:#eef0f6; color:#4b5563; }
    .g1-pill.ok { background:#e6f4ea; color:#137333; }
    .g1-pill.warn { background:#fff1d6; color:#975500; }
</style>

<?php if ($syncError !== null): ?><div class="flash err">Odczyt z GS1: <?= $e($syncError) ?></div><?php endif; ?>

<?php if ($assignId > 0): ?>
<div class="card">
    <?php if ($product === null): ?>
        <p>Nie ma takiego produktu. <a href="gs1.php">Wróć</a></p>
    <?php elseif (trim((string) ($product['ean'] ?? '')) !== ''): ?>
        <p>Produkt <b><?= $e($product['sku']) ?></b> ma już EAN <span class="g1-mono"><?= $e($product['ean']) ?></span>. <a href="product_view.php?id=<?= (int) $product['id'] ?>">Wróć do produktu</a></p>
    <?php elseif (!$gs1->configured()): ?>
        <p>Najpierw wpisz dane dostępu do MojeGS1 w ustawieniach połączenia poniżej.</p>
    <?php else: ?>
        <h3 style="margin-top:0">Nadaj GTIN z GS1: <?= $e($product['name'] ?: $product['sku']) ?> <span class="g1-muted">(<?= $e($product['sku']) ?>)</span></h3>
        <p class="g1-muted">Po kliknięciu „Utwórz kartę w GS1” CRM sprawdzi, że numer jest wolny, utworzy kartę produktu w MojeGS1 (status aktywny) i wpisze numer jako EAN produktu<?= $gs1->pushToWoo() ? ' oraz w sklepie WooCommerce' : '' ?>. Numery GS1 są płatne i limitowane, więc upewnij się, że produkt naprawdę potrzebuje nowego kodu. Nazwę na karcie GS1 składa sama z marki, podmarki, nazwy zwyczajowej, wariantu i zawartości.</p>
        <?php if ($formError !== null): ?><div class="flash err"><?= $e($formError) ?></div><?php endif; ?>
        <?php if (($formValues['gtin'] ?? '') === '' && $gs1->prefix() === ''): ?>
            <div class="flash err">Brak prefiksu firmy GS1 w ustawieniach, więc CRM nie zaproponuje numeru. Wpisz prefiks poniżej albo sam numer z MojeGS1.</div>
        <?php endif; ?>
        <form method="post" class="g1-form">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="assign">
            <input type="hidden" name="assign" value="<?= (int) $assignId ?>">
            <label>Numer GTIN-13 <input name="f[gtin]" value="<?= $e($formValues['gtin'] ?? '') ?>" inputmode="numeric" maxlength="13" required class="g1-mono"><span class="g1-muted">Kolejny wolny z Twojego prefiksu; możesz zmienić.</span></label>
            <label>Marka * <input name="f[brandName]" value="<?= $e($formValues['brandName'] ?? '') ?>" maxlength="200" required></label>
            <label>Podmarka <input name="f[subBrandName]" value="<?= $e($formValues['subBrandName'] ?? '') ?>" maxlength="200"></label>
            <label>Nazwa zwyczajowa * <input name="f[commonName]" value="<?= $e($formValues['commonName'] ?? '') ?>" maxlength="150" required><span class="g1-muted">Co to jest, np. „Wosk zapachowy sojowy”.</span></label>
            <label>Wariant <input name="f[variant]" value="<?= $e($formValues['variant'] ?? '') ?>" maxlength="70"><span class="g1-muted">Np. zapach: „Lawenda”.</span></label>
            <label>Zawartość netto * <input name="f[netContent]" value="<?= $e($formValues['netContent'] ?? '') ?>" inputmode="decimal" required></label>
            <label>Jednostka *
                <select name="f[netContentUnit]">
                    <?php foreach (Gs1::UNITS as $code => $label): ?>
                        <option value="<?= $e($code) ?>" <?= ($formValues['netContentUnit'] ?? '') === $code ? 'selected' : '' ?>><?= $e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Kod GPC * <input name="f[gpcCode]" value="<?= $e($formValues['gpcCode'] ?? '') ?>" inputmode="numeric" maxlength="8" required class="g1-mono"><span class="g1-muted">Kategoria GS1 (8 cyfr), podpowiedź: najczęstsza z Twoich kart. Szukaj w <a href="https://gpc-browser.gs1.org" target="_blank" rel="noopener noreferrer">GPC Browser ↗</a>.</span></label>
            <label>Rynek docelowy <input name="f[targetMarket]" value="<?= $e($formValues['targetMarket'] ?? 'PL') ?>"><span class="g1-muted">Kody krajów po przecinku, np. PL, DE.</span></label>
            <label>Symbol wewnętrzny <input name="f[internalSymbol]" value="<?= $e($formValues['internalSymbol'] ?? '') ?>" maxlength="255"><span class="g1-muted">SKU - po nim CRM rozpozna kartę.</span></label>
            <label class="wide">Strona produktu <input name="f[productWebsite]" value="<?= $e($formValues['productWebsite'] ?? '') ?>" maxlength="1000"></label>
            <label class="wide">Opis <textarea name="f[description]" rows="4" maxlength="4000"><?= $e($formValues['description'] ?? '') ?></textarea><span class="g1-muted">20–4000 znaków albo puste.</span></label>
            <div class="wide" style="display:flex;gap:10px;align-items:center">
                <button class="btn" type="submit" onclick="return confirm('Utworzyć kartę w MojeGS1 i nadać produktowi numer ' + this.form['f[gtin]'].value + '?')">Utwórz kartę w GS1</button>
                <a href="product_view.php?id=<?= (int) $product['id'] ?>">Anuluj</a>
            </div>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($gs1->configured()): ?>
<div class="card">
    <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
        <div><b><?= count($ov['cards']) ?></b> <span class="g1-muted">kart w GS1</span></div>
        <div><b><?= count(array_filter($ov['cards'], static fn($c) => $c['product_id'] !== null)) ?></b> <span class="g1-muted">powiązanych z CRM</span></div>
        <div><b><?= count($ov['without_ean']) ?></b> <span class="g1-muted">produktów CRM bez EAN</span></div>
        <div class="g1-muted">Ostatnio pobrano: <?= $e($gs1->lastSync() ?? 'jeszcze nie') ?></div>
        <?php if ($canEdit): ?>
        <form method="post" style="margin:0 0 0 auto">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="sync">
            <button class="btn secondary" type="submit">⟳ Pobierz z GS1</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <form method="get" class="g1-search">
        <input type="hidden" name="tab" value="<?= $e($tab) ?>">
        <input type="search" name="q" value="<?= $e($q) ?>" placeholder="Szukaj: GTIN / EAN, nazwa, SKU…" aria-label="Szukaj" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other">
        <button class="btn secondary" type="submit">Szukaj</button>
        <?php if ($q !== ''): ?><a href="<?= $e($tabLink($tab)) ?>" class="g1-muted">wyczyść</a><?php endif; ?>
    </form>
    <div class="g1-tabs">
        <a href="<?= $e($tabLink('cards')) ?>" class="<?= $tab === 'cards' ? 'on' : '' ?>">Karty w GS1 (<?= count($found['cards']) ?>)</a>
        <a href="<?= $e($tabLink('without')) ?>" class="<?= $tab === 'without' ? 'on' : '' ?>">Produkty bez EAN (<?= count($found['without']) ?>)</a>
        <a href="<?= $e($tabLink('foreign')) ?>" class="<?= $tab === 'foreign' ? 'on' : '' ?>">EAN spoza GS1 (<?= count($found['foreign']) ?>)</a>
    </div>

    <?php if ($tab === 'cards'): ?>
        <?php if ($rows === []): ?>
            <p class="g1-muted"><?= $q !== '' ? 'Nic nie pasuje do wyszukiwania.' : 'Brak kart. Kliknij „Pobierz z GS1”.' ?></p>
        <?php else: ?>
        <table>
            <tr><th>GTIN</th><th>Nazwa w GS1</th><th>Status</th><th>Produkt w CRM</th></tr>
            <?php foreach ($rows as $c): ?>
            <tr>
                <td class="g1-mono"><a href="<?= $e($gs1->cardUrl($c['gtin'])) ?>" class="g1-link" target="_blank" rel="noopener noreferrer" title="Otwórz kartę w GS1"><?= $e($c['gtin']) ?> ↗</a></td>
                <td><?= $e($c['name'] ?: $c['common_name'] ?: '—') ?><?php if ($c['internal_symbol']): ?><div class="g1-muted">Symbol: <?= $e($c['internal_symbol']) ?></div><?php endif; ?>
                    <?php foreach (json_decode((string) $c['suggestions'], true) ?: [] as $s): ?><div class="g1-muted">💡 <?= $e($s) ?></div><?php endforeach; ?></td>
                <td><span class="g1-pill <?= strtoupper((string) $c['status']) === 'ACT' ? 'ok' : '' ?>"><?= $e($statusLabel($c['status'])) ?></span></td>
                <td>
                    <?php if ($c['product_id'] !== null): ?>
                        <a href="product_view.php?id=<?= (int) $c['product_id'] ?>"><?= $e($c['product_sku']) ?></a> <span class="g1-muted"><?= $e($c['product_name']) ?></span>
                    <?php elseif ($c['sku_match'] !== null && trim((string) $c['sku_match']['ean']) === ''): ?>
                        <span class="g1-pill warn">SKU pasuje, brak EAN</span> <a href="product_view.php?id=<?= (int) $c['sku_match']['id'] ?>"><?= $e($c['sku_match']['sku']) ?></a>
                        <?php if ($canEdit): ?>
                        <form method="post" style="display:inline;margin:0">
                            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="link">
                            <input type="hidden" name="product_id" value="<?= (int) $c['sku_match']['id'] ?>">
                            <input type="hidden" name="gtin" value="<?= $e($c['gtin']) ?>">
                            <button class="btn secondary" type="submit" style="padding:3px 10px">Wpisz EAN</button>
                        </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="g1-muted">brak produktu z tym EAN</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    <?php elseif ($tab === 'without'): ?>
        <p class="g1-muted">Produkty CRM bez kodu EAN. „Nadaj GTIN” otwiera formularz; nic nie trafi do GS1 bez potwierdzenia.</p>
        <table>
            <tr><th>SKU</th><th>Nazwa</th><th></th></tr>
            <?php foreach ($rows as $p): ?>
            <tr>
                <td><a href="product_view.php?id=<?= (int) $p['id'] ?>"><?= $e($p['sku']) ?></a></td>
                <td><?= $e($p['name']) ?></td>
                <td><?php if ($canEdit): ?><a class="btn secondary" style="padding:3px 10px" href="gs1.php?assign=<?= (int) $p['id'] ?>">Nadaj GTIN</a><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p class="g1-muted">Produkty z EAN, którego nie ma wśród Twoich kart w GS1 (np. kod producenta albo karta usunięta w MojeGS1).</p>
        <table>
            <tr><th>SKU</th><th>Nazwa</th><th>EAN</th></tr>
            <?php foreach ($rows as $p): ?>
            <tr>
                <td><a href="product_view.php?id=<?= (int) $p['id'] ?>"><?= $e($p['sku']) ?></a></td>
                <td><?= $e($p['name']) ?></td>
                <td class="g1-mono"><a href="<?= $e(Gs1::VERIFY_URL . rawurlencode(Gs1::normalize((string) $p['ean']))) ?>" class="g1-link" target="_blank" rel="noopener noreferrer" title="Sprawdź w GS1, kto nadał ten numer"><?= $e($p['ean']) ?> ↗</a><?= Gs1::validGtin(preg_replace('/\D/', '', (string) $p['ean']) ?? '') ? '' : ' <span class="g1-pill warn">zła cyfra kontrolna</span>' ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
    <?php if ($tab !== 'cards' && $rows === [] && $q !== ''): ?><p class="g1-muted">Nic nie pasuje do wyszukiwania.</p><?php endif; ?>
    <?php $pagerPage = $page; $pagerPages = $pages; $pagerLink = static fn(int $n): string => $tabLink($tab, $n); require __DIR__ . '/_pager.php'; ?>
</div>
<?php endif; ?>

<div class="card">
    <?php $guess = $gs1->prefix() === '' ? $gs1->guessPrefix() : null; ?>
    <b>⚙ Połączenie z GS1</b> <span class="g1-muted"><?= $gs1->configured() ? 'połączono' : 'nie skonfigurowano' ?><?= $gs1->prefix() !== '' ? ' · prefiks ' . $e($gs1->prefix()) : '' ?></span>
    <p class="g1-muted" style="margin-bottom:0">Login, hasło API i prefiks firmy są w ustawieniach wtyczki GS1<?= isAdmin() ? ': <a href="' . $e($gs1AccountLink) . '">' . ($gs1Account !== null ? 'zmień' : 'dodaj konto GS1') . '</a>' : ' (może je zmienić administrator)' ?>.
        Dane API: <a href="https://mojegs1.pl" target="_blank" rel="noopener noreferrer">mojegs1.pl</a> → Moje dane → Profile użytkowników → otwórz swojego użytkownika → Menu → Zmień dane api. Każde kliknięcie „Zmień dane api” tworzy nowe dane, a stare przestają działać.
        <?= $guess !== null ? 'Prefiks firmy nie jest wpisany; z Twoich kart wygląda na: <span class="g1-mono">' . $e($guess) . '</span>.' : '' ?></p>
</div>
<?php require __DIR__ . '/footer.php'; ?>
