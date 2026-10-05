<?php
declare(strict_types=1);

/**
 * Nowe zamówienie dodane ręcznie (Services\ManualOrders): klient, adresy, pozycje z magazynu albo dowolne,
 * dostawa, płatność. Zamówienie jest tylko w CRM. Przy płatności „PayU - link” można od razu utworzyć link
 * do płatności (PayuPayments::createLink) - pokaże się na stronie zamówienia do skopiowania.
 *
 * ?from=ID - dane klienta i adresy skopiowane z innego zamówienia (np. z karty klienta).
 */

use Pase\Automation\RuleEngine;
use Pase\Repository\AutomationRuleRepository;
use Pase\Repository\OrderStatusRepository;
use Pase\Services\InvoiceData;
use Pase\Services\ManualOrders;
use Pase\Services\PayuPayments;
use Pase\Services\PayuPayouts;

require __DIR__ . '/auth.php';
header('Cache-Control: no-store');
/** @var PDO $pdo */

$config = require PASE_ROOT . '/config/config.php';
if (!canEdit()) {
    flash('Brak uprawnień do dodawania zamówień.', 'err');
    redirectAfterPost('index.php');
}

$statuses = (new OrderStatusRepository($pdo))->all();
$payuShops = [];
if (PayuPayouts::available()) {
    try {
        foreach ((new PayuPayouts($pdo))->accounts() as $pa) {
            $payuShops[(int) $pa['id']] = (string) $pa['name'];
        }
    } catch (\Throwable) {
        $payuShops = [];
    }
}

$errors = [];
$form = $_POST;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $svc = new ManualOrders($pdo, new \Pase\Queue\Queue($pdo));
    $checked = $svc->validate($_POST);
    $wantLink = ($_POST['payment_method'] ?? '') === 'payu_link' && !empty($_POST['payu_create']) && $payuShops !== [];
    $errors = $checked['errors'];
    if ($errors === [] && !in_array($checked['order']['status'], array_column($statuses, 'status_key'), true) && $statuses !== []) {
        $checked['order']['status'] = 'new';
    }
    if ($errors === []) {
        $id = $svc->create($checked['order'], currentUserName());
        $messages = ['Dodano zamówienie ręczne (tylko w CRM).'];
        $error = null;

        if (!empty($_POST['run_automations'])) {
            try {
                $row = (new \Pase\Repository\WooOrderRepository($pdo))->find($id);
                $engine = new RuleEngine($pdo, new AutomationRuleRepository($pdo), $config['allegro'] ?? []);
                $engine->dispatch('order.imported', $id, json_decode((string) ($row['payload'] ?? ''), true) ?: []);
                (new \Pase\Repository\WooOrderRepository($pdo))->markAutomationsDone($id);
                \Pase\Automation\OrderEvents::paidTransition($pdo, $id, null, $engine);
            } catch (\Throwable $e) {
                $error = 'Automatyzacje nie zadziałały: ' . $e->getMessage();
            }
        }

        if ($wantLink) {
            try {
                PayuPayments::migrate($pdo);
                $row = (new \Pase\Repository\WooOrderRepository($pdo))->find($id);
                $l = (new PayuPayments($pdo, new PayuPayouts($pdo)))->createLink(
                    $id, (int) ($_POST['payu_shop'] ?? array_key_first($payuShops)), (int) round($checked['order']['total'] * 100),
                    'Zamówienie #' . (int) ($row['pase_number'] ?? 0), (int) ($_POST['payu_days'] ?? 7), PayuPayments::newLinkExtId($id),
                    currentUserName(), PayuPayments::linkOptions($pdo, (array) $row));
                $messages[] = 'Utworzono link do płatności PayU - skopiuj go w sekcji „Płatność PayU”.';
            } catch (\Throwable $e) {
                $error = ($error !== null ? $error . ' ' : '') . 'Link PayU nie powstał: ' . $e->getMessage() . ' Możesz go utworzyć na stronie zamówienia.';
            }
        }
        $_SESSION['order_view_flash'][$id] = ['ok' => implode(' ', $messages), 'error' => $error];
        redirectAfterPost('order_view.php?id=' . $id);
    }
}

// Dane klienta z innego zamówienia (?from=ID).
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && (int) ($_GET['from'] ?? 0) > 0) {
    $src = (new \Pase\Repository\WooOrderRepository($pdo))->find((int) $_GET['from']);
    if ($src !== null) {
        $p = json_decode((string) $src['payload'], true) ?: [];
        $b = InvoiceData::originalBilling($p);
        foreach (['first_name', 'last_name', 'company', 'address_1', 'postcode', 'city', 'country', 'email', 'phone', 'nip'] as $k) {
            $form['billing_' . $k] = trim((string) ($b[$k] ?? '') . ($k === 'address_1' && !empty($b['address_2']) ? ' ' . $b['address_2'] : ''));
        }
        $form['billing_email'] = (string) ($src['local_billing_email'] ?? '') ?: $form['billing_email'];
        $form['billing_phone'] = (string) ($src['local_billing_phone'] ?? '') ?: $form['billing_phone'];
        $sh = is_array($p['shipping'] ?? null) ? $p['shipping'] : [];
        if (isset($p['delivery']['address']) && is_array($p['delivery']['address'])) {   // Allegro
            $a = $p['delivery']['address'];
            $sh = ['first_name' => $a['firstName'] ?? '', 'last_name' => $a['lastName'] ?? '', 'company' => $a['companyName'] ?? '',
                'address_1' => $a['street'] ?? '', 'postcode' => $a['zipCode'] ?? '', 'city' => $a['city'] ?? '', 'country' => $a['countryCode'] ?? 'PL', 'phone' => $a['phoneNumber'] ?? ''];
        }
        if (trim((string) ($sh['address_1'] ?? '') . (string) ($sh['city'] ?? '')) !== ''
            && trim((string) ($sh['address_1'] ?? '')) . (string) ($sh['city'] ?? '') !== $form['billing_address_1'] . $form['billing_city']) {
            $form['ship_different'] = '1';
            foreach (['first_name', 'last_name', 'company', 'address_1', 'postcode', 'city', 'country', 'phone'] as $k) {
                $form['shipping_' . $k] = (string) ($sh[$k] ?? '');
            }
        }
        if ($form['billing_nip'] !== '') {
            $form['wants_invoice'] = '1';
        }
    }
}

// Szukanie klienta (?q=) - podpowiedź danych z jego ostatniego zamówienia.
$customerHits = [];
$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $cs = new \Pase\Services\Customers($pdo);
        foreach ($cs->list($q, 'last', 0, 8)['rows'] as $c) {
            $last = null;
            foreach ($cs->ordersOf((int) $c['id']) as $co) {
                if ((int) $co['in_crm'] === 1) { $last = $co; break; }
            }
            if ($last !== null) {
                $customerHits[] = ['name' => (string) $c['name'], 'keys' => implode(', ', array_merge(...array_values(array_map('array_values', $c['keys'] ?? [])))),
                    'from' => (int) $last['woo_order_id'], 'count' => (int) $c['orders_count']];
            }
        }
    } catch (\Throwable) {
        $customerHits = [];
    }
}

$catalog = [];
try {
    foreach ($pdo->query("SELECT sku, name, pase_price, pase_stock FROM products WHERE sku IS NOT NULL AND sku <> '' ORDER BY name LIMIT 3000")->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $catalog[(string) $p['sku']] = ['n' => (string) $p['name'], 'p' => number_format((float) ($p['pase_price'] ?? 0), 2, '.', ''), 's' => $p['pase_stock'] !== null ? (int) $p['pase_stock'] : null];
    }
} catch (\Throwable) {
    $catalog = [];
}

$v = static fn(string $k, string $def = ''): string => htmlspecialchars((string) ($form[$k] ?? $def));
$rows = [];
foreach (array_keys((array) ($form['item_name'] ?? []) + (array) ($form['item_sku'] ?? [])) as $i) {
    $rows[] = ['sku' => (string) ($form['item_sku'][$i] ?? ''), 'name' => (string) ($form['item_name'][$i] ?? ''),
        'price' => (string) ($form['item_price'][$i] ?? ''), 'qty' => (string) ($form['item_qty'][$i] ?? '1')];
}
if ($rows === []) {
    $rows[] = ['sku' => '', 'name' => '', 'price' => '', 'qty' => '1'];
}
$method = (string) ($form['payment_method'] ?? 'bacs');
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';

$PAGE_TITLE = 'Nowe zamówienie';
$PAGE_KEY   = 'orders';
$PAGE_HEAD = <<<'CSS'
<style>
    #newOrderForm [hidden] { display:none !important; }
    .no-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:10px 12px; margin-top:10px; }
    .no-grid label, .no-field label { display:block; font-size:11px; color:var(--ink-2); margin-bottom:3px; }
    .no-grid input, .no-grid select, .no-field input, .no-field select, .no-field textarea { width:100%; box-sizing:border-box; }
    .no-wide { grid-column:1 / -1; }
    .no-items { width:100%; border-collapse:collapse; margin-top:10px; }
    .no-items th { font-size:11px; color:var(--ink-2); text-align:left; font-weight:600; padding:0 6px 4px; }
    .no-items td { padding:4px 6px; vertical-align:top; }
    .no-items input { width:100%; box-sizing:border-box; }
    .no-items .num { text-align:right; font-family:var(--font-num); font-variant-numeric:tabular-nums; }
    .no-line { white-space:nowrap; padding-top:10px !important; }
    .no-hint { font-size:11px; color:var(--ink-3); margin-top:2px; }
    .no-sum { display:flex; justify-content:flex-end; gap:14px; align-items:baseline; margin-top:12px; font-size:15px; }
    .no-sum strong { font-family:var(--font-num); font-size:20px; }
    .no-check { display:flex; gap:8px; align-items:center; font-size:14px; margin-top:10px; }
    .no-err { background:#fff4f2; border:1px solid #f3c2bc; color:#a3341f; border-radius:8px; padding:10px 12px; margin-bottom:14px; }
    .no-hits a { display:block; padding:6px 0; border-top:1px solid var(--line); text-decoration:none; color:inherit; }
    .no-hits a:hover strong { color:var(--accent); }
    @media (max-width: 700px) { .no-items thead { display:none; } .no-items tr { display:grid; grid-template-columns:1fr 1fr; gap:4px; border-top:1px solid var(--line); padding:6px 0; } .no-items td:nth-child(-n+2) { grid-column:1 / -1; } }
</style>
CSS;
require __DIR__ . '/header.php';
?>

<?php if ($errors !== []): ?>
<div class="no-err"><strong>Nie zapisano zamówienia.</strong><?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<div class="card">
    <strong>Klient z poprzednich zamówień</strong>
    <form method="get" style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
        <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="E-mail, telefon, nazwisko albo numer zamówienia" style="flex:1;min-width:240px">
        <button class="btn secondary" type="submit">Szukaj</button>
    </form>
    <?php if ($q !== ''): ?>
        <div class="no-hits" style="margin-top:8px">
        <?php if ($customerHits === []): ?><p style="color:#888;font-size:13px">Nie znaleziono klienta. Wpisz dane poniżej.</p><?php endif; ?>
        <?php foreach ($customerHits as $h): ?>
            <a href="order_new.php?from=<?= (int) $h['from'] ?>"><strong><?= htmlspecialchars($h['name'] ?: 'Klient') ?></strong>
                <span style="color:#888;font-size:12px"> · <?= htmlspecialchars($h['keys']) ?> · zamówień: <?= (int) $h['count'] ?> · użyj danych</span></a>
        <?php endforeach; ?>
        </div>
    <?php elseif ((int) ($_GET['from'] ?? 0) > 0 && !$posted): ?>
        <p style="color:#2f7a45;font-size:13px;margin-top:8px">Dane klienta skopiowane z poprzedniego zamówienia - sprawdź je przed zapisaniem.</p>
    <?php endif; ?>
</div>

<form method="post" id="newOrderForm">
<input type="hidden" name="csrf" value="<?= csrfToken() ?>">

<div class="card">
    <strong>Dane klienta</strong>
    <div class="no-grid">
        <div><label>Imię</label><input name="billing_first_name" value="<?= $v('billing_first_name') ?>" autocomplete="off"></div>
        <div><label>Nazwisko</label><input name="billing_last_name" value="<?= $v('billing_last_name') ?>" autocomplete="off"></div>
        <div><label>Firma (opcjonalnie)</label><input name="billing_company" value="<?= $v('billing_company') ?>" autocomplete="off"></div>
        <div><label>E-mail</label><input type="email" name="billing_email" value="<?= $v('billing_email') ?>" autocomplete="off"></div>
        <div><label>Telefon</label><input name="billing_phone" value="<?= $v('billing_phone') ?>" autocomplete="off"></div>
        <div class="no-wide" style="height:0"></div>
        <div style="grid-column:span 2"><label>Ulica i numer</label><input name="billing_address_1" value="<?= $v('billing_address_1') ?>" autocomplete="off"></div>
        <div><label>Kod pocztowy</label><input name="billing_postcode" value="<?= $v('billing_postcode') ?>" autocomplete="off"></div>
        <div><label>Miasto</label><input name="billing_city" value="<?= $v('billing_city') ?>" autocomplete="off"></div>
        <div><label>Kraj</label><input name="billing_country" value="<?= $v('billing_country', 'PL') ?>" maxlength="2" style="text-transform:uppercase"></div>
    </div>
    <label class="no-check"><input type="checkbox" name="wants_invoice" value="1" id="wantsInvoice" <?= !empty($form['wants_invoice']) ? 'checked' : '' ?>> Faktura na firmę (NIP)</label>
    <div class="no-grid" id="invoiceBox" <?= empty($form['wants_invoice']) ? 'hidden' : '' ?>>
        <div><label>NIP</label><input name="billing_nip" value="<?= $v('billing_nip') ?>" autocomplete="off"></div>
        <div class="no-wide no-hint">Na fakturze będą dane klienta powyżej (firma, adres). Dane firmy z GUS pobierzesz potem na stronie zamówienia.</div>
    </div>
    <label class="no-check"><input type="checkbox" name="ship_different" value="1" id="shipDifferent" <?= !empty($form['ship_different']) ? 'checked' : '' ?>> Inny adres dostawy</label>
    <div class="no-grid" id="shipBox" <?= empty($form['ship_different']) ? 'hidden' : '' ?>>
        <div><label>Imię</label><input name="shipping_first_name" value="<?= $v('shipping_first_name') ?>" autocomplete="off"></div>
        <div><label>Nazwisko</label><input name="shipping_last_name" value="<?= $v('shipping_last_name') ?>" autocomplete="off"></div>
        <div><label>Firma</label><input name="shipping_company" value="<?= $v('shipping_company') ?>" autocomplete="off"></div>
        <div><label>Telefon</label><input name="shipping_phone" value="<?= $v('shipping_phone') ?>" autocomplete="off"></div>
        <div style="grid-column:span 2"><label>Ulica i numer</label><input name="shipping_address_1" value="<?= $v('shipping_address_1') ?>" autocomplete="off"></div>
        <div><label>Kod pocztowy</label><input name="shipping_postcode" value="<?= $v('shipping_postcode') ?>" autocomplete="off"></div>
        <div><label>Miasto</label><input name="shipping_city" value="<?= $v('shipping_city') ?>" autocomplete="off"></div>
        <div><label>Kraj</label><input name="shipping_country" value="<?= $v('shipping_country', 'PL') ?>" maxlength="2" style="text-transform:uppercase"></div>
    </div>
</div>

<div class="card">
    <strong>Produkty</strong>
    <div class="no-hint">Wybierz produkt z magazynu po SKU albo nazwie - stan w CRM zmniejszy się jak przy sprzedaży. Pozycja bez SKU (sama nazwa i cena) nie zmienia magazynu. Ceny brutto.</div>
    <table class="no-items">
        <thead><tr><th style="width:22%">SKU (magazyn)</th><th>Nazwa</th><th style="width:110px">Cena</th><th style="width:80px">Ilość</th><th style="width:100px" class="num">Razem</th><th style="width:36px"></th></tr></thead>
        <tbody id="itemRows">
        <?php foreach ($rows as $r): ?>
            <tr class="item-row">
                <td><input name="item_sku[]" list="catalogList" value="<?= htmlspecialchars($r['sku']) ?>" class="item-sku" placeholder="szukaj…" autocomplete="off"><div class="no-hint item-stock"></div></td>
                <td><input name="item_name[]" value="<?= htmlspecialchars($r['name']) ?>" class="item-name" autocomplete="off"></td>
                <td><input name="item_price[]" value="<?= htmlspecialchars($r['price']) ?>" class="item-price num" inputmode="decimal" placeholder="0.00" autocomplete="off"></td>
                <td><input name="item_qty[]" type="number" min="1" value="<?= htmlspecialchars($r['qty']) ?>" class="item-qty num"></td>
                <td class="num no-line item-total">0.00</td>
                <td><button type="button" class="btn secondary item-remove" title="Usuń pozycję" aria-label="Usuń pozycję" style="padding:5px 9px">✕</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <datalist id="catalogList">
        <?php foreach ($catalog as $sku => $c): ?><option value="<?= htmlspecialchars((string) $sku) ?>"><?= htmlspecialchars($c['n'] . ' — ' . $c['p'] . ' PLN') ?></option><?php endforeach; ?>
    </datalist>
    <p style="margin-top:8px"><button type="button" class="btn secondary" id="addItem">+ Dodaj pozycję</button></p>

    <div class="no-grid" style="margin-top:16px;padding-top:14px;border-top:1px solid var(--line)">
        <div style="grid-column:span 2"><label>Dostawa</label><input name="shipping_method" list="shipList" value="<?= $v('shipping_method') ?>" placeholder="np. InPost Paczkomat" autocomplete="off">
            <datalist id="shipList"><option value="InPost Paczkomat"><option value="Kurier InPost"><option value="Kurier DPD"><option value="Kurier DHL"><option value="ORLEN Paczka"><option value="Poczta Polska"><option value="Odbiór osobisty"></datalist></div>
        <div><label>Koszt dostawy</label><input name="shipping_cost" id="shipCost" value="<?= $v('shipping_cost') ?>" inputmode="decimal" placeholder="0.00" class="num" autocomplete="off"></div>
    </div>
    <label class="no-check"><input type="checkbox" name="no_shipping" value="1" <?= !empty($form['no_shipping']) ? 'checked' : '' ?>> Wirtualne</label>
    <div class="no-sum"><span>Do zapłaty</span><strong id="orderTotal">0.00 PLN</strong></div>
</div>

<div class="card">
    <strong>Płatność i status</strong>
    <div class="no-grid">
        <div><label>Metoda płatności</label>
            <select name="payment_method" id="payMethod">
                <?php foreach (ManualOrders::PAYMENT_METHODS as $k => $label): ?>
                    <option value="<?= $k ?>" <?= $method === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div id="payOtherBox" <?= $method === 'other' ? '' : 'hidden' ?>><label>Jaka?</label><input name="payment_method_other" value="<?= $v('payment_method_other') ?>" placeholder="np. BLIK na telefon"></div>
        <div><label>Status zamówienia</label>
            <select name="pase_status">
                <?php foreach ($statuses as $s): ?>
                    <option value="<?= htmlspecialchars((string) $s['status_key']) ?>" <?= ($form['pase_status'] ?? 'new') === $s['status_key'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $s['label']) ?></option>
                <?php endforeach; ?>
            </select></div>
    </div>
    <label class="no-check" id="paidBox" <?= $method === 'payu_link' ? 'hidden' : '' ?>><input type="checkbox" name="paid" value="1" <?= !empty($form['paid']) ? 'checked' : '' ?>> Klient już zapłacił (całość)</label>
    <div id="payuBox" <?= $method === 'payu_link' ? '' : 'hidden' ?> style="margin-top:10px;padding:10px 12px;border:1px solid var(--line);border-radius:8px">
        <?php if ($payuShops === []): ?>
            <p style="color:#888;font-size:13px;margin:0">Brak sklepu PayU. Dodaj go w Konfiguracja → Integracje → Dodaj integrację → PayU, żeby tworzyć linki do płatności.</p>
        <?php else: ?>
            <label class="no-check" style="margin-top:0"><input type="checkbox" name="payu_create" value="1" <?= !$posted || !empty($form['payu_create']) ? 'checked' : '' ?>> Od razu utwórz link do płatności PayU na kwotę zamówienia</label>
            <div class="no-grid">
                <?php if (count($payuShops) > 1): ?>
                <div><label>Sklep PayU</label><select name="payu_shop"><?php foreach ($payuShops as $sid => $sname): ?><option value="<?= $sid ?>" <?= (int) ($form['payu_shop'] ?? 0) === $sid ? 'selected' : '' ?>><?= htmlspecialchars($sname) ?></option><?php endforeach; ?></select></div>
                <?php else: ?><input type="hidden" name="payu_shop" value="<?= (int) array_key_first($payuShops) ?>"><?php endif; ?>
                <div><label>Link ważny</label><select name="payu_days"><?php foreach ([1 => '1 dzień', 3 => '3 dni', 7 => '7 dni', 14 => '14 dni', 30 => '30 dni'] as $d => $dl): ?><option value="<?= $d ?>" <?= (int) ($form['payu_days'] ?? 7) === $d ? 'selected' : '' ?>><?= $dl ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="no-hint" style="margin-top:6px">Link pojawi się na stronie zamówienia do skopiowania. CRM nie wysyła go klientowi. Gdy klient zapłaci, wpłata zapisze się przy zamówieniu.</div>
        <?php endif; ?>
    </div>
    <div class="no-field" style="margin-top:12px"><label>Uwagi do zamówienia</label><textarea name="customer_note" rows="2"><?= $v('customer_note') ?></textarea></div>
    <label class="no-check"><input type="checkbox" name="run_automations" value="1" <?= !$posted || !empty($form['run_automations']) ? 'checked' : '' ?>> Uruchom automatyzacje „Pobrano zamówienie” (jak dla zamówień ze sklepu i Allegro)</label>
    <p style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
        <button type="submit" class="btn">Dodaj zamówienie</button>
        <a class="btn secondary" href="index.php">Anuluj</a>
    </p>
    <p class="no-hint">Zamówienie powstaje tylko w CRM - nie trafia do sklepu ani na Allegro.</p>
</div>
</form>

<script>
(() => {
    const catalog = <?= json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const rows = document.getElementById('itemRows');
    const num = (s) => { const v = parseFloat(String(s).replace(/\s/g, '').replace(',', '.')); return isFinite(v) ? v : 0; };
    const recalc = () => {
        let sum = 0;
        rows.querySelectorAll('.item-row').forEach((tr) => {
            const t = num(tr.querySelector('.item-price').value) * Math.max(0, parseInt(tr.querySelector('.item-qty').value || '0', 10));
            tr.querySelector('.item-total').textContent = t.toFixed(2);
            sum += t;
        });
        sum += num(document.getElementById('shipCost').value);
        document.getElementById('orderTotal').textContent = sum.toFixed(2) + ' PLN';
    };
    const fillFromSku = (tr, force) => {
        const c = catalog[tr.querySelector('.item-sku').value.trim()];
        const stock = tr.querySelector('.item-stock');
        stock.textContent = c ? (c.s !== null ? 'stan w CRM: ' + c.s : '') : '';
        if (!c) { return; }
        const name = tr.querySelector('.item-name'), price = tr.querySelector('.item-price');
        if (force || name.value === '') { name.value = c.n; }
        if (force || price.value === '') { price.value = c.p; }
    };
    rows.addEventListener('input', (e) => { if (e.target.classList.contains('item-sku')) { fillFromSku(e.target.closest('tr'), true); } recalc(); });
    rows.addEventListener('click', (e) => {
        if (!e.target.classList.contains('item-remove')) { return; }
        const all = rows.querySelectorAll('.item-row');
        const tr = e.target.closest('tr');
        if (all.length > 1) { tr.remove(); } else { tr.querySelectorAll('input').forEach((i) => { i.value = i.classList.contains('item-qty') ? '1' : ''; }); }
        recalc();
    });
    document.getElementById('addItem').addEventListener('click', () => {
        const tr = rows.querySelector('.item-row').cloneNode(true);
        tr.querySelectorAll('input').forEach((i) => { i.value = i.classList.contains('item-qty') ? '1' : ''; });
        tr.querySelector('.item-stock').textContent = '';
        rows.appendChild(tr);
        tr.querySelector('.item-sku').focus();
        recalc();
    });
    document.getElementById('shipCost').addEventListener('input', recalc);
    const toggle = (box, id) => { const cb = document.getElementById(id); cb.addEventListener('change', () => { document.getElementById(box).hidden = !cb.checked; }); };
    toggle('invoiceBox', 'wantsInvoice');
    toggle('shipBox', 'shipDifferent');
    const pay = document.getElementById('payMethod');
    pay.addEventListener('change', () => {
        document.getElementById('payuBox').hidden = pay.value !== 'payu_link';
        document.getElementById('paidBox').hidden = pay.value === 'payu_link';
        document.getElementById('payOtherBox').hidden = pay.value !== 'other';
    });
    rows.querySelectorAll('.item-row').forEach((tr) => fillFromSku(tr, false));
    recalc();
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
