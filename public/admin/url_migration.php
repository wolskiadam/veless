<?php
declare(strict_types=1);

/**
 * Jednorazowa zmiana adresu URL panelu (Konfiguracja → Adres URL panelu).
 * Aktualizuje ustawienia PASE, które budują linki (APP_BASE_URL, redirect Allegro).
 * Webhook WooCommerce i redirect URI w panelu deweloperskim Allegro to rejestracje
 * na ZEWNĘTRZNYCH systemach - PASE nie ma jak ich zmienić za Ciebie, więc pokazujemy
 * gotowe adresy do ręcznego wklejenia.
 */

use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\SettingsRepository;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$settings = new SettingsRepository($pdo);
$flashOk  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $newBase = rtrim(trim($_POST['new_base_url'] ?? ''), '/');
    if ($newBase !== '' && filter_var($newBase, FILTER_VALIDATE_URL)) {
        $settings->setMany([
            'APP_BASE_URL'        => $newBase,
            'ALLEGRO_REDIRECT_URI' => $newBase . '/auth_allegro_callback.php',
        ]);
        $flashOk = 'Zapisano nowy adres bazowy. Zaktualizuj jeszcze ręcznie pozycje z listy poniżej.';
    }
}

$currentBase     = $settings->get('APP_BASE_URL', '');
$currentRedirect = $settings->get('ALLEGRO_REDIRECT_URI', '');

// Adresy webhooków - po jednym na każdą aktywną integrację WooCommerce (każda ma własny ?integration=ID).
$wooAccounts = [];
try {
    $wooAccounts = array_filter(
        (new IntegrationAccountRepository($pdo))->all(),
        static fn(array $a): bool => $a['type'] === 'woocommerce'
    );
} catch (\Throwable $e) {
    $wooAccounts = [];
}

$PAGE_TITLE = 'Adres URL panelu';
$PAGE_KEY   = 'url_migration';
require __DIR__ . '/header.php';
?>

<?php if ($flashOk): ?><div class="flash ok"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>

<div class="card">
    <strong>Obecne wartości</strong>
    <p style="color:var(--ink-2);font-size:13px;margin:6px 0 14px">
        Adres bazowy służy do budowania linków w mailach do klientów oraz redirectu logowania Allegro.
        Zmiana poniżej dotyczy <strong>tylko ustawień CRM</strong> - nie przenosi ani nie zmienia nazwy
        żadnych plików na serwerze.
    </p>
    <div style="display:grid;grid-template-columns:160px 1fr;gap:8px 14px;font-size:13px">
        <div style="color:var(--ink-2)">APP_BASE_URL</div>
        <div style="font-family:var(--font-num)"><?= $currentBase !== '' ? htmlspecialchars($currentBase) : '<span style="color:var(--ink-3)">— nie ustawiono —</span>' ?></div>
        <div style="color:var(--ink-2)">ALLEGRO_REDIRECT_URI</div>
        <div style="font-family:var(--font-num)"><?= $currentRedirect !== '' ? htmlspecialchars($currentRedirect) : '<span style="color:var(--ink-3)">— nie ustawiono, CRM użyje wartości z .env —</span>' ?></div>
    </div>
</div>

<div class="card">
    <strong>Zmień adres bazowy</strong>
    <p style="color:var(--ink-2);font-size:13px;margin:6px 0 14px">
        Wpisz nowy adres <strong>do folderu <code>public/</code></strong> (bez końcowego <code>/</code>), np.
        <code>https://twojsklep.pl/pase/public</code>. Jeśli zmieniasz też nazwę katalogu na serwerze,
        zrób to najpierw i upewnij się, że panel wczytuje się pod nowym adresem - dopiero potem zapisz go tutaj.
    </p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input name="new_base_url" value="<?= htmlspecialchars($currentBase) ?>" placeholder="https://twojsklep.pl/pase/public" style="width:100%;max-width:520px">
        <p style="margin-top:14px"><button class="btn" type="submit">Zaktualizuj</button></p>
    </form>
</div>

<div class="card">
    <strong>Do zrobienia ręcznie (poza CRM)</strong>
    <p style="color:var(--ink-2);font-size:13px;margin:6px 0 14px">
        Tych dwóch rzeczy CRM nie może zmienić samodzielnie - to rejestracje po stronie Allegro i Twojego sklepu
        WooCommerce. Skopiuj adresy poniżej i wklej je w odpowiednich miejscach.
    </p>

    <div style="margin-bottom:16px">
        <div style="font-size:13px;font-weight:600;margin-bottom:6px">1. Redirect URI w panelu deweloperskim Allegro</div>
        <p style="color:var(--ink-2);font-size:12.5px;margin:0 0 8px">
            Twoja aplikacja Allegro → ustawienia OAuth → „Adres przekierowania”. Bez tego logowanie do Allegro przestanie działać.
        </p>
        <div class="url-copy-row">
            <code id="urlAllegro"><?= htmlspecialchars($currentRedirect !== '' ? $currentRedirect : '(najpierw zapisz adres bazowy powyżej)') ?></code>
            <button type="button" class="btn secondary" data-copy="urlAllegro">Kopiuj</button>
        </div>
    </div>

    <div>
        <div style="font-size:13px;font-weight:600;margin-bottom:6px">2. Adres dostawy webhooka w WooCommerce</div>
        <p style="color:var(--ink-2);font-size:12.5px;margin:0 0 8px">
            Sklep → WooCommerce → Ustawienia → Zaawansowane → Webhooki → edytuj istniejący webhook zamówień → pole „Adres dostawy”.
        </p>
        <?php if ($wooAccounts === []): ?>
            <p style="color:var(--ink-3);font-size:12.5px">Brak skonfigurowanych integracji WooCommerce.</p>
        <?php endif; ?>
        <?php foreach ($wooAccounts as $i => $acc): $base = $currentBase !== '' ? $currentBase : '(najpierw zapisz adres bazowy powyżej)'; ?>
            <div class="url-copy-row" style="margin-bottom:6px">
                <code id="urlWoo<?= (int) $i ?>"><?= htmlspecialchars($base . '/webhook_woo_order.php?integration=' . (int) $acc['id']) ?></code>
                <button type="button" class="btn secondary" data-copy="urlWoo<?= (int) $i ?>">Kopiuj</button>
                <span style="color:var(--ink-2);font-size:12px;margin-left:4px"><?= htmlspecialchars($acc['name']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<style>
    .url-copy-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .url-copy-row code { background:var(--surface-2); border:1px solid var(--line); border-radius:7px; padding:7px 10px; font-family:var(--font-num); font-size:12.5px; word-break:break-all; }
    .url-copy-row .btn { padding:6px 12px; font-size:12.5px; flex-shrink:0; }
</style>
<script>
document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var el = document.getElementById(btn.dataset.copy);
        if (!el) { return; }
        navigator.clipboard.writeText(el.textContent).then(function () {
            var old = btn.textContent;
            btn.textContent = 'Skopiowano!';
            setTimeout(function () { btn.textContent = old; }, 1500);
        });
    });
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
