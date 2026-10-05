<?php
declare(strict_types=1);

/**
 * Cenniki wysyłek Allegro: lista cenników z konta, domyślny cennik i czas wysyłki dla nowych
 * ofert oraz edycja cenników bez wchodzenia na Allegro (dodawanie, kopiowanie, zmiana stawek).
 *   GET  /sale/shipping-rates, /sale/shipping-rates/{id}, /sale/delivery-methods
 *   POST /sale/shipping-rates, PUT /sale/shipping-rates/{id}
 * Zmiana cennika działa od razu we wszystkich ofertach, które go używają.
 */

use Pase\Repository\SettingsRepository;
use Pase\Services\AllegroOfferOperations;
use PasePlugin\Allegro\AllegroPlugin;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$config   = require PASE_ROOT . '/config/config.php';
$client   = AllegroPlugin::makeClient($pdo, $config['allegro'] ?? []);
$settings = new SettingsRepository($pdo);
$e = static fn($v) => htmlspecialchars((string) $v);
$money = static fn($v) => $v === null || $v === '' ? '' : number_format((float) $v, 2, ',', '');
$parseMoney = static function ($v): ?string {
    $v = str_replace([' ', ','], ['', '.'], trim((string) $v));
    return $v !== '' && is_numeric($v) && (float) $v >= 0 ? number_format((float) $v, 2, '.', '') : null;
};

/** Metody dostawy (lista długa i rzadko się zmienia - trzymamy godzinę w sesji). */
$methods = static function () use ($client): array {
    $c = $_SESSION['al_delivery_methods'] ?? null;
    if (!is_array($c) || ($c['t'] ?? 0) < time() - 3600 || $c['list'] === []) {
        $c = ['t' => time(), 'list' => $client->deliveryMethods()];
        $_SESSION['al_delivery_methods'] = $c;
    }
    return $c['list'];
};

$mode   = isset($_GET['new']) ? 'new' : (isset($_GET['copy']) ? 'copy' : (isset($_GET['edit']) ? 'edit' : 'list'));
$rateId = (string) ($_GET['edit'] ?? $_GET['copy'] ?? '');
$formError = null;
$posted = null;

// ---------- Zapis domyślnych ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEdit() && ($_POST['action'] ?? '') === 'defaults') {
    csrfCheck();
    $values = ['allegro_default_shipping_rate' => trim((string) ($_POST['default_rate'] ?? ''))];
    if (isset(AllegroOfferOperations::HANDLING_TIMES[$_POST['default_handling'] ?? ''])) {
        $values[AllegroOfferOperations::DEFAULT_HANDLING_SETTING] = $_POST['default_handling'];
    }
    $settings->setMany($values);
    flash('Zapisano domyślny cennik i czas wysyłki.');
    redirectAfterPost('allegro_shipping.php');
}

// ---------- Zapis cennika ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEdit() && ($_POST['action'] ?? '') === 'save_rate') {
    csrfCheck();
    $id   = trim((string) ($_POST['id'] ?? '')) ?: null;
    $name = trim((string) ($_POST['name'] ?? ''));
    $orig = [];
    if ($id !== null) {
        foreach (($client->shippingRate($id)['rates'] ?? []) as $r) {
            $orig[(string) ($r['deliveryMethod']['id'] ?? '')] = $r;
        }
    }
    $byId = [];
    foreach ($methods() as $m) { $byId[(string) $m['id']] = $m; }

    $rates = [];
    $errs  = [];
    foreach ((array) ($_POST['m'] ?? []) as $mid => $row) {
        if (empty($row['on'])) {
            continue;
        }
        $mid   = (string) $mid;
        $label = (string) ($byId[$mid]['name'] ?? $mid);
        $first = $parseMoney($row['first'] ?? '');
        $next  = $parseMoney(($row['next'] ?? '') === '' ? ($row['first'] ?? '') : $row['next']);
        $qty   = max(1, (int) ($row['qty'] ?? 1));
        if ($first === null) {
            $errs[] = "„{$label}”: podaj cenę";
            continue;
        }
        $cons = $byId[$mid]['shippingRatesConstraints'] ?? [];
        $maxQty = (int) ($cons['maxQuantityPerPackage']['max'] ?? 0);
        if ($maxQty > 0 && $qty > $maxQty) {
            $qty = $maxQty;
        }
        // Pola, których nie edytujemy (czas dostawy, waga paczki), zostają jak były.
        $item = $orig[$mid] ?? [];
        $item['deliveryMethod'] = ['id' => $mid];
        $item['maxQuantityPerPackage'] = $qty;
        $item['firstItemRate'] = ['amount' => $first, 'currency' => 'PLN'];
        $item['nextItemRate']  = ['amount' => $next, 'currency' => 'PLN'];
        if (!isset($item['maxPackageWeight']) && !empty($cons['maxPackageWeight']['supported']) && isset($cons['maxPackageWeight']['max'])) {
            $item['maxPackageWeight'] = ['value' => (string) $cons['maxPackageWeight']['max'], 'unit' => (string) ($cons['maxPackageWeight']['unit'] ?? 'KILOGRAM')];
        }
        unset($item['lastModified']);
        $rates[] = $item;
    }
    if ($name === '') { array_unshift($errs, 'podaj nazwę cennika'); }
    if ($rates === []) { $errs[] = 'włącz co najmniej jedną metodę dostawy'; }

    if ($errs) {
        $formError = 'Popraw: ' . implode('; ', $errs) . '.';
    } else {
        $r = $client->saveShippingRate($id, ['name' => $name, 'rates' => $rates]);
        if ($r['ok']) {
            flash($id ? "Zapisano cennik „{$name}” — zmiana działa od razu we wszystkich ofertach z tym cennikiem." : "Dodano cennik „{$name}”.");
            redirectAfterPost('allegro_shipping.php');
        }
        $formError = 'Allegro odrzuciło cennik: ' . $r['message'];
    }
    $posted = ['id' => $id, 'name' => $name, 'm' => (array) ($_POST['m'] ?? [])];
    $mode = $id ? 'edit' : 'new';
    $rateId = (string) $id;
}

$PAGE_TITLE = 'Cenniki wysyłek Allegro';
$PAGE_KEY   = 'allegro';

// ================= EDYTOR =================
if ($mode !== 'list') {
    $rate = ($mode === 'edit' || $mode === 'copy') && $rateId !== '' ? $client->shippingRate($rateId) : null;
    if (($mode === 'edit' || $mode === 'copy') && $rate === null && $posted === null) {
        flash('Nie udało się pobrać cennika z Allegro.', 'err');
        redirectAfterPost('allegro_shipping.php');
    }
    $current = [];
    foreach ($rate['rates'] ?? [] as $r) { $current[(string) ($r['deliveryMethod']['id'] ?? '')] = $r; }
    // Uwaga: nie „$name" - nav.php (header) nadpisuje tę zmienną w pętli języków.
    $rateName = $posted['name'] ?? ($mode === 'copy' ? 'Kopia — ' . ($rate['name'] ?? '') : (string) ($rate['name'] ?? ''));
    $formId = $mode === 'edit' ? $rateId : '';

    $list = $methods();
    $groups = ['IN_ADVANCE' => 'Przedpłata', 'CASH_ON_DELIVERY' => 'Za pobraniem', '' => 'Inne'];
    $grouped = [];
    foreach ($list as $m) {
        if (isset($m['shippingRatesConstraints']['allowed']) && !$m['shippingRatesConstraints']['allowed'] && !isset($current[(string) $m['id']])) {
            continue;
        }
        $pp = (string) ($m['paymentPolicy'] ?? '');
        $grouped[isset($groups[$pp]) ? $pp : ''][] = $m;
    }
    $isOn = static fn(array $m): int => (int) (isset($current[(string) $m['id']]) || !empty($posted['m'][(string) $m['id']]['on']));
    foreach ($grouped as &$g) {
        // Włączone na górze, potem alfabetycznie.
        usort($g, static fn($a, $b) => ($isOn($b) <=> $isOn($a)) ?: strcmp((string) $a['name'], (string) $b['name']));
    }
    unset($g);

    require __DIR__ . '/header.php';
    ?>
    <p><a href="allegro_shipping.php" class="sr-back">← Cenniki wysyłek</a></p>
    <form method="post" class="card sr-form" id="srForm">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="save_rate">
        <input type="hidden" name="id" value="<?= $e($formId) ?>">
        <div class="sr-top">
            <div style="flex:1;min-width:260px">
                <label class="sr-lbl">Nazwa cennika</label>
                <input name="name" value="<?= $e($rateName) ?>" maxlength="100" required style="width:100%">
            </div>
            <div class="sr-tools">
                <input type="search" id="srSearch" placeholder="Szukaj metody dostawy…">
                <label class="sr-chk"><input type="checkbox" id="srOnlyOn"> tylko włączone</label>
            </div>
        </div>
        <?php if ($formError): ?><div class="flash err" style="margin-top:12px"><?= $e($formError) ?></div><?php endif; ?>
        <?php if ($mode === 'edit'): ?><p class="sr-note">Zmiana zadziała od razu we wszystkich ofertach, które używają tego cennika.</p><?php endif; ?>
        <?php if ($list === []): ?><div class="flash err" style="margin-top:12px">Nie udało się pobrać metod dostawy z Allegro.</div><?php endif; ?>

        <?php foreach ($groups as $gk => $gl): if (empty($grouped[$gk])) { continue; } ?>
            <h3 class="sr-group"><?= $e($gl) ?></h3>
            <table class="sr-table">
                <tr><th style="width:36px"></th><th>Metoda dostawy</th><th class="r">Pierwsza sztuka</th><th class="r">Kolejna sztuka</th><th class="r">Sztuk w paczce</th></tr>
                <?php foreach ($grouped[$gk] as $m):
                    $mid = (string) $m['id'];
                    $cur = $current[$mid] ?? null;
                    $p   = $posted['m'][$mid] ?? null;
                    $on  = $p !== null ? !empty($p['on']) : $cur !== null;
                    $first = $p['first'] ?? $money($cur['firstItemRate']['amount'] ?? '');
                    $next  = $p['next'] ?? $money($cur['nextItemRate']['amount'] ?? '');
                    $qty   = $p['qty'] ?? ($cur['maxQuantityPerPackage'] ?? 1);
                    $cons  = $m['shippingRatesConstraints'] ?? [];
                    $maxQty = (int) ($cons['maxQuantityPerPackage']['max'] ?? 0);
                    $hint = [];
                    if (isset($cons['firstItemRate']['min']) || isset($cons['firstItemRate']['max'])) {
                        $hint[] = 'cena ' . ($cons['firstItemRate']['min'] ?? '0') . '–' . ($cons['firstItemRate']['max'] ?? '…') . ' zł';
                    }
                    if (!empty($cons['maxPackageWeight']['supported'])) {
                        $hint[] = 'paczka do ' . ($cur['maxPackageWeight']['value'] ?? $cons['maxPackageWeight']['max'] ?? '?') . ' kg';
                    }
                ?>
                    <tr class="sr-row <?= $on ? 'on' : '' ?>" data-name="<?= $e(mb_strtolower((string) $m['name'])) ?>">
                        <td><input type="checkbox" name="m[<?= $e($mid) ?>][on]" value="1" <?= $on ? 'checked' : '' ?> class="sr-on" aria-label="Włącz <?= $e($m['name']) ?>"></td>
                        <td><?= $e($m['name']) ?><?php if ($hint): ?><div class="sr-hint"><?= $e(implode(' · ', $hint)) ?></div><?php endif; ?></td>
                        <td class="r"><span class="sr-money"><input name="m[<?= $e($mid) ?>][first]" value="<?= $e($first) ?>" inputmode="decimal" placeholder="0,00"> zł</span></td>
                        <td class="r"><span class="sr-money"><input name="m[<?= $e($mid) ?>][next]" value="<?= $e($next) ?>" inputmode="decimal" placeholder="jak pierwsza"> zł</span></td>
                        <td class="r"><input name="m[<?= $e($mid) ?>][qty]" value="<?= (int) $qty ?>" type="number" min="1" <?= $maxQty ? 'max="' . $maxQty . '"' : '' ?> class="sr-qty"></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endforeach; ?>

        <div class="sr-save">
            <span class="sr-count" id="srCount"></span>
            <a class="btn secondary" href="allegro_shipping.php">Anuluj</a>
            <button class="btn" type="submit"><?= $mode === 'edit' ? 'Zapisz cennik na Allegro' : 'Utwórz cennik na Allegro' ?></button>
        </div>
    </form>

    <style>
        .sr-back { color:var(--ink-2); text-decoration:none; font-size:14px; }
        .sr-top { display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap; }
        .sr-lbl { display:block; font-size:12px; color:var(--ink-2); margin-bottom:4px; }
        .sr-tools { display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
        .sr-tools input[type=search] { width:240px; }
        .sr-chk { display:flex; align-items:center; gap:6px; font-size:14px; color:var(--ink-2); white-space:nowrap; }
        .sr-note { color:var(--warn-ink); background:var(--warn-bg); padding:8px 12px; border-radius:8px; font-size:13px; margin:12px 0 0; }
        .sr-group { font-size:14px; margin:22px 0 4px; }
        .sr-table { width:100%; font-size:14px; table-layout:fixed; }
        .sr-table th:nth-child(3), .sr-table th:nth-child(4) { width:150px; }
        .sr-table th:nth-child(5) { width:120px; }
        .sr-table .r { text-align:right; white-space:nowrap; }
        .sr-row:not(.on) td { color:var(--ink-3); }
        .sr-row:not(.on) input:not(.sr-on) { opacity:.45; }
        .sr-hint { font-size:11px; color:var(--ink-3); }
        .sr-money input { width:90px; text-align:right; }
        .sr-qty { width:70px; text-align:right; }
        .sr-save { position:sticky; bottom:0; background:var(--surface); display:flex; justify-content:flex-end; align-items:center; gap:10px; padding:14px 0 4px; margin-top:12px; border-top:1px solid var(--line); }
        .sr-count { margin-right:auto; color:var(--ink-2); font-size:13px; }
    </style>
    <script>
    (function () {
        var form = document.getElementById('srForm'), q = document.getElementById('srSearch'), only = document.getElementById('srOnlyOn'), cnt = document.getElementById('srCount');
        var rows = Array.prototype.slice.call(form.querySelectorAll('.sr-row'));
        function refresh() {
            var term = q.value.trim().toLowerCase(), n = 0;
            rows.forEach(function (r) {
                var on = r.querySelector('.sr-on').checked;
                r.classList.toggle('on', on);
                if (on) { n++; }
                r.style.display = (term === '' || r.getAttribute('data-name').indexOf(term) !== -1) && (!only.checked || on) ? '' : 'none';
            });
            cnt.textContent = 'Włączone metody: ' + n;
        }
        form.addEventListener('change', refresh);
        q.addEventListener('input', refresh);
        // Wpisanie ceny w wyłączonym wierszu od razu go włącza.
        form.addEventListener('input', function (ev) {
            var row = ev.target.closest('.sr-row');
            if (row && !ev.target.classList.contains('sr-on') && ev.target.value !== '') { row.querySelector('.sr-on').checked = true; refresh(); }
        });
        refresh();
    })();
    </script>
    <?php
    require __DIR__ . '/footer.php';
    return;
}

// ================= LISTA =================
$defaultRate = (string) ($settings->get('allegro_default_shipping_rate', '') ?? '');
$rates = $client->shippingRates();
require __DIR__ . '/header.php';
?>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <strong>Cenniki wysyłek</strong>
        <?php if (canEdit()): ?><a class="btn" href="?new=1">+ Nowy cennik</a><?php endif; ?>
    </div>
    <p style="color:#888;font-size:12px;margin:4px 0 14px">
        Cenniki są zapisane na Twoim koncie Allegro. Możesz je tu edytować, kopiować i dodawać nowe — zmiana działa od razu we wszystkich ofertach z danym cennikiem.
        Zaznacz domyślny, który CRM podstawi przy wystawianiu ofert.
    </p>

    <?php if ($rates === []): ?>
        <p style="color:#888">Brak cenników lub brak połączenia z Allegro. <a href="allegro_connect.php">Połącz konto →</a></p>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="defaults">
            <table class="al-table">
                <thead><tr><th style="width:80px">Domyślny</th><th>Nazwa cennika</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rates as $r): $id = (string) ($r['id'] ?? ''); ?>
                    <tr>
                        <td><input type="radio" name="default_rate" value="<?= $e($id) ?>" <?= $defaultRate === $id ? 'checked' : '' ?> aria-label="Domyślny cennik"></td>
                        <td style="font-weight:600"><?= $e($r['name'] ?? '(bez nazwy)') ?></td>
                        <td style="text-align:right;white-space:nowrap">
                            <?php if (canEdit()): ?>
                                <a class="btn secondary" href="?edit=<?= $e(urlencode($id)) ?>" style="padding:5px 12px;font-size:13px">Edytuj</a>
                                <a class="btn secondary" href="?copy=<?= $e(urlencode($id)) ?>" style="padding:5px 12px;font-size:13px">Kopiuj</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php $defHandling = (string) ($settings->get(AllegroOfferOperations::DEFAULT_HANDLING_SETTING, 'PT24H') ?? 'PT24H'); ?>
            <div style="margin-top:14px;max-width:320px">
                <label style="font-size:12px;color:#888;display:block">Domyślny czas wysyłki nowych ofert</label>
                <select name="default_handling" style="width:100%">
                    <?php foreach (AllegroOfferOperations::HANDLING_TIMES as $hk => $hl): ?>
                        <option value="<?= $hk ?>" <?= $hk === $defHandling ? 'selected' : '' ?>><?= $e($hl) ?></option>
                    <?php endforeach; ?>
                </select>
                <p style="color:#888;font-size:12px;margin:4px 0 0">Istniejące oferty zmienisz zbiorczo w Zarządzaniu ofertami → Operacje → „Zmień czas wysyłki”.</p>
            </div>
            <?php if (canEdit()): ?>
                <p style="margin-top:14px"><button class="btn" type="submit">Zapisz domyślne</button></p>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</div>

<style>
    .al-table { width:100%; border-collapse:collapse; margin-top:6px; }
    .al-table th { text-align:left; font-size:11px; color:#888; text-transform:uppercase; padding:6px 8px; border-bottom:2px solid #eef0f3; }
    .al-table td { padding:10px 8px; border-bottom:1px solid #f1f3f5; vertical-align:middle; font-size:14px; }
</style>

<?php require __DIR__ . '/footer.php'; ?>
