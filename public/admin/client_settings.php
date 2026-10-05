<?php
declare(strict_types=1);

/**
 * Ustawienia STRONY KLIENTA (Konfiguracja → Strona klienta). Zapis w tabeli settings.
 * Dotyczy publicznej strony zamówienia (order.php): weryfikacja tożsamości, adres
 * bazowy do linków, adres powiadomień o wiadomościach klienta.
 */

use Pase\Repository\SettingsRepository;
use Pase\Services\ClientPageBoxes;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$settings = new SettingsRepository($pdo);
$links    = new \Pase\Services\ClientLinks($pdo);
$shops    = array_values(array_filter((new \Pase\Repository\IntegrationAccountRepository($pdo))->all(),
    static fn($a) => in_array($a['type'], ['woocommerce', 'allegro'], true)));

// Pobranie pliku-pośrednika dla sklepu (wgrywa się go na serwer sklepu).
if (isset($_GET['proxy_file'])) {
    $shopName = 'sklep';
    foreach ($shops as $sh) {
        if ((int) $sh['id'] === (int) $_GET['proxy_file']) { $shopName = (string) $sh['name']; }
    }
    $crmBase = rtrim((string) ($settings->get('APP_BASE_URL', '') ?? ''), '/');
    if ($crmBase === '') {
        flash('Najpierw ustaw „Adres bazowy strony klienta" (adres CRM).', 'err');
        header('Location: client_settings.php');
        exit;
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="zamowienie.php"');
    echo $links->proxyFile($crmBase, $shopName);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'shop_urls') {
    csrfCheck();
    $links->setUrlMap((array) ($_POST['shop_url'] ?? []));
    // Kolory: pole „użyj domyślnych" odznaczone = zapisujemy kolory sklepu.
    $themes = [];
    foreach ((array) ($_POST['theme'] ?? []) as $sid => $t) {
        if (!empty($t['custom'])) {
            $themes[$sid] = ['bg' => (string) ($t['bg'] ?? ''), 'accent' => (string) ($t['accent'] ?? '')];
        }
    }
    $links->setThemeMap($themes);
    flash('Zapisano ustawienia stron zamówień w sklepach.');
    redirectAfterPost();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $values = [
        // Checkbox: brak w POST = wyłączone ('0').
        'CLIENT_VERIFY_ENABLED' => isset($_POST['client_verify']) ? '1' : '0',
        'APP_BASE_URL'          => rtrim(trim($_POST['app_base_url'] ?? ''), '/'),
        'MAIL_ADMIN_NOTIFY'     => trim($_POST['admin_notify'] ?? ''),
    ];
    // Boxy widoczne klientowi: zaznaczony = '1', brak w POST = '0'.
    foreach (array_keys(ClientPageBoxes::catalog()) as $box) {
        $values[ClientPageBoxes::settingKey($box)] = isset($_POST['box'][$box]) ? '1' : '0';
    }
    // Kolejność boxów: ukryte pole z listą kluczy po przecinku (ustawiane przez drag&drop).
    $catalog = array_keys(ClientPageBoxes::catalog());
    $order   = array_values(array_filter(
        array_map('trim', explode(',', (string) ($_POST['box_order'] ?? ''))),
        static fn($k) => in_array($k, $catalog, true)
    ));
    if ($order !== []) {
        $values[ClientPageBoxes::orderSettingKey()] = implode(',', $order);
    }
    $settings->setMany($values);

    // PRG: komunikat do sesji + 303 na GET-a, żeby F5 nie powtarzało zapisu.
    flash('Zapisano ustawienia strony klienta.');
    redirectAfterPost();
}

$s = $settings->all();
$verifyEnabled = ($s['CLIENT_VERIFY_ENABLED'] ?? '1') !== '0';
$boxes   = ClientPageBoxes::fromSettings($s);
$catalog = ClientPageBoxes::catalog();
$boxOrder = ClientPageBoxes::order($s);

$PAGE_TITLE = 'Strona klienta';
$PAGE_KEY   = 'client_settings';
require __DIR__ . '/header.php';
?>

<?php // Komunikaty po zapisie renderuje header.php (flash()/flashTake() w auth.php). ?>

<div class="card">
    <strong>Publiczna strona zamówienia</strong>
    <p style="color:#888;font-size:13px;margin:6px 0 0">
        Strona, którą klient otwiera linkiem z tokenem (<code>order.php?token=…</code>): podsumowanie zamówienia,
        śledzenie przesyłki i wątek wiadomości.
    </p>

    <form method="post" style="margin-top:16px">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">

        <!-- Weryfikacja tożsamości -->
        <label style="display:flex;gap:8px;align-items:center;font-size:14px;color:#333;cursor:pointer">
            <input type="checkbox" name="client_verify" value="1" <?= $verifyEnabled ? 'checked' : '' ?>>
            Wymagaj potwierdzenia tożsamości na stronie zamówienia klienta
        </label>
        <p style="color:#888;font-size:12px;margin:4px 0 0">
            Gdy włączone, klient po wejściu z linku musi wpisać daną z zamówienia (e-mail lub numer telefonu).
            Wyłączenie sprawia, że link otwiera zamówienie od razu — wygodniej, ale mniej bezpiecznie.
        </p>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:18px">
            <div>
                <label style="font-size:12px;color:#888;display:block">Adres bazowy strony klienta (publiczny URL)</label>
                <input name="app_base_url" value="<?= htmlspecialchars($s['APP_BASE_URL'] ?? '') ?>" placeholder="https://twojsklep.pl/pase/public" style="width:100%">
            </div>
            <div>
                <label style="font-size:12px;color:#888;display:block">E-mail powiadomień o wiadomości klienta</label>
                <input name="admin_notify" value="<?= htmlspecialchars($s['MAIL_ADMIN_NOTIFY'] ?? '') ?>" placeholder="(domyślnie adres nadawcy e-mail)" style="width:100%">
            </div>
        </div>
        <p style="color:#888;font-size:12px;margin:6px 0 0">
            Adres bazowy buduje linki do strony klienta w e-mailach. Adres powiadomień otrzymuje informację,
            gdy klient napisze wiadomość (domyślnie adres nadawcy z ustawień e-mail).
        </p>

        <!-- Boxy widoczne klientowi: kolejność (drag&drop) + włączenie -->
        <div style="margin-top:18px;border-top:1px solid #eef0f3;padding-top:14px">
            <strong style="font-size:14px">Sekcje widoczne dla klienta</strong>
            <p style="color:#888;font-size:12px;margin:4px 0 10px">
                Zaznacz, które sekcje mają się pojawiać, oraz <strong>przeciągnij</strong> je za uchwyt
                <span style="color:#aaa">⠿</span>, aby ustawić kolejność na stronie zamówienia klienta.
            </p>
            <input type="hidden" name="box_order" id="box_order" value="<?= htmlspecialchars(implode(',', $boxOrder)) ?>">
            <ul id="box-sortable" style="list-style:none;margin:0;padding:0;max-width:520px">
                <?php foreach ($boxOrder as $box): $label = $catalog[$box] ?? $box; ?>
                    <li class="box-item" draggable="true" data-box="<?= htmlspecialchars($box) ?>"
                        style="display:flex;gap:10px;align-items:center;padding:10px 12px;margin-bottom:6px;border:1px solid #e3e7ec;border-radius:9px;background:#fff;cursor:grab">
                        <span class="box-handle" style="color:#bbb;font-size:18px;line-height:1;cursor:grab;user-select:none">⠿</span>
                        <label style="display:flex;gap:8px;align-items:center;font-size:14px;color:#333;cursor:pointer;flex:1;margin:0">
                            <input type="checkbox" name="box[<?= htmlspecialchars($box) ?>]" value="1" <?= $boxes[$box] ? 'checked' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <p style="margin-top:18px"><button class="btn" type="submit">Zapisz</button></p>
    </form>
</div>

<div class="card">
    <strong>Strona zamówienia w domenie sklepu</strong>
    <p style="color:#888;font-size:13px;margin:6px 0 0">
        Klient sklepu dostaje link w domenie <em>swojego</em> sklepu (np. <code>https://twojsklep.pl/zamowienie.php?token=…</code>), a nie CRM.
        Jak to ustawić dla sklepu: <strong>1)</strong> kliknij „Pobierz plik" i wgraj <code>zamowienie.php</code> przez FTP do głównego katalogu sklepu,
        <strong>2)</strong> wpisz poniżej jego adres i zapisz. Puste pole = link do CRM (adres bazowy wyżej).
    </p>
    <?php $urlMap = $links->urlMap(); ?>
    <?php if ($shops === []): ?>
        <p style="color:#888;margin-top:10px">Brak sklepów — dodaj integrację WooCommerce lub Allegro.</p>
    <?php else: ?>
    <form method="post" style="margin-top:12px">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="shop_urls">
        <table>
            <tr><th>Sklep</th><th>Adres strony zamówienia</th><th>Kolory strony</th><th></th></tr>
            <?php $themeMap = $links->themeMap(); ?>
            <?php foreach ($shops as $sh): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $sh['name']) ?> <span style="color:#888;font-size:12px">(<?= $sh['type'] === 'allegro' ? 'Allegro' : 'WooCommerce' ?>)</span></td>
                    <td><input name="shop_url[<?= (int) $sh['id'] ?>]" value="<?= htmlspecialchars($urlMap[(string) $sh['id']] ?? '') ?>"
                               placeholder="<?= $sh['type'] === 'woocommerce' && !empty($sh['config']['base_url']) ? htmlspecialchars(rtrim((string) $sh['config']['base_url'], '/') . '/zamowienie.php') : 'https://twoj-sklep.pl/zamowienie.php' ?>" style="width:100%;min-width:300px"></td>
                    <td style="white-space:nowrap">
                        <?php $th = $themeMap[(string) $sh['id']] ?? []; $custom = $th !== []; ?>
                        <label class="th-lbl" title="Własne kolory tej strony">
                            <input type="checkbox" name="theme[<?= (int) $sh['id'] ?>][custom]" value="1" <?= $custom ? 'checked' : '' ?> onchange="this.closest('td').querySelector('.th-pick').classList.toggle('th-off', !this.checked)"> własne
                        </label>
                        <span class="th-pick <?= $custom ? '' : 'th-off' ?>">
                            <label title="Tło strony">Tło <input type="color" name="theme[<?= (int) $sh['id'] ?>][bg]" value="<?= htmlspecialchars($th['bg'] ?? '#f6f5f2') ?>"></label>
                            <label title="Przyciski, linki i wyróżnienia">Przyciski <input type="color" name="theme[<?= (int) $sh['id'] ?>][accent]" value="<?= htmlspecialchars($th['accent'] ?? '#9c6b2e') ?>"></label>
                        </span>
                    </td>
                    <td style="white-space:nowrap"><a class="btn secondary" href="?proxy_file=<?= (int) $sh['id'] ?>" style="padding:4px 10px;font-size:13px">⬇ Pobierz plik</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p style="margin-top:12px"><button class="btn" type="submit">Zapisz</button></p>
        <style>
            .th-lbl{font-size:12px;color:#666;display:inline-flex;align-items:center;gap:4px;margin-right:8px;cursor:pointer}
            .th-pick label{font-size:12px;color:#666;display:inline-flex;align-items:center;gap:4px;margin-right:6px}
            .th-pick input[type=color]{width:34px;height:26px;padding:0;border:1px solid #ddd;border-radius:6px;background:none;cursor:pointer}
            .th-pick.th-off{opacity:.35;pointer-events:none}
        </style>
    </form>
    <p style="color:#888;font-size:12px;margin:8px 0 0">
        Plik działa jak pośrednik: pobiera stronę zamówienia z CRM po stronie serwera, więc działa też na iPhone (bez ramki/iframe).
        Wymaga PHP 7.3+ z cURL na hostingu sklepu. Link w e-mailach wstawisz tagiem <code>{{order_link}}</code>.
    </p>
    <?php endif; ?>
</div>

<script>
(function () {
    var list = document.getElementById('box-sortable');
    var field = document.getElementById('box_order');
    if (!list || !field) { return; }
    var dragged = null;

    function syncOrder() {
        var keys = [].map.call(list.querySelectorAll('.box-item'), function (li) { return li.dataset.box; });
        field.value = keys.join(',');
    }

    list.addEventListener('dragstart', function (e) {
        var li = e.target.closest('.box-item');
        if (!li) { return; }
        dragged = li;
        li.style.opacity = '0.4';
        e.dataTransfer.effectAllowed = 'move';
    });
    list.addEventListener('dragend', function () {
        if (dragged) { dragged.style.opacity = ''; dragged = null; }
        syncOrder();
    });
    list.addEventListener('dragover', function (e) {
        e.preventDefault();
        var over = e.target.closest('.box-item');
        if (!over || over === dragged || !dragged) { return; }
        var rect = over.getBoundingClientRect();
        var after = (e.clientY - rect.top) > rect.height / 2;
        list.insertBefore(dragged, after ? over.nextSibling : over);
    });
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
