<?php
declare(strict_types=1);

/**
 * Dodawanie / edycja jednej integracji (integration_accounts).
 * Pola zależą od typu. Sekrety: puste = nie zmieniaj (przy edycji).
 */

use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\SettingsRepository;
use Pase\Support\Env;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$repo = new IntegrationAccountRepository($pdo);
$id   = (int) ($_GET['id'] ?? 0);
$existing = $id ? $repo->find($id) : null;

$flashOk = $flashErr = null;
$wooTest = null;

// Pola config per typ: [klucz => [etykieta, sekret?, opcje_selecta?]].
// Trzeci element (opcjonalny) = mapa wartość=>etykieta -> pole renderuje się jako <select>.
$schema = [
    'woocommerce' => [
        'base_url'        => ['Adres sklepu (https://...)', false],
        'consumer_key'    => ['Consumer key (ck_...)', true],
        'consumer_secret' => ['Consumer secret (cs_...)', true],
        'stock_master'    => ['Źródło prawdy dla stanów magazynowych', false, [
            'pase' => 'CRM (CRM wypycha stany do sklepu)',
            'shop' => 'Sklep (CRM nie nadpisuje stanów)',
        ]],
        'weight_unit'     => ['Jednostka wagi w sklepie', false, [
            'auto' => 'Automatycznie (z ustawień sklepu)', 'g' => 'gramy (g)', 'kg' => 'kilogramy (kg)',
            'lbs' => 'funty (lbs)', 'oz' => 'uncje (oz)',
        ], 'Import przelicza wagę na kg. Ustaw ręcznie, jeśli sklep nie udostępnia swoich ustawień przez API.'],
        'dimension_unit'  => ['Jednostka wymiarów w sklepie', false, [
            'auto' => 'Automatycznie (z ustawień sklepu)', 'cm' => 'centymetry (cm)', 'mm' => 'milimetry (mm)',
            'm' => 'metry (m)', 'in' => 'cale (in)',
        ], 'Import przelicza wymiary na cm.'],
    ],
    'wfirma' => [
        'base_url'   => ['Adres API (domyślnie https://api2.wfirma.pl)', false],
        'access_key' => ['Access key', true],
        'secret_key' => ['Secret key', true],
        'app_key'    => ['App key', true],
        'company_id' => ['Company ID', false],
        'default_vat' => ['Domyślna stawka VAT', false, [
            '23' => '23%', '8' => '8%', '5' => '5%', '0' => '0%',
        ]],
    ],
    'allegro' => [
        'client_id'     => ['Client ID', false],
        'client_secret' => ['Client Secret', true],
        'redirect_uri'  => ['Redirect URI', false],
        'env'           => ['Środowisko', false, ['sandbox' => 'Sandbox (testy)', 'production' => 'Produkcja']],
        'app_name'      => ['Nazwa aplikacji (do nagłówka User-Agent)', false],
        'app_version'   => ['Wersja aplikacji (do nagłówka User-Agent)', false],
        'app_url'       => ['Adres informacyjny (do nagłówka User-Agent, opcjonalnie)', false],
    ],
    'blpaczka' => [
        'login'        => ['Login (e-mail z konta BLPaczka)', false],
        'api_key'      => ['Klucz API', true],
        'env'          => ['Środowisko', false, ['sandbox' => 'Sandbox (testy)', 'production' => 'Produkcja']],
        'payment'      => ['Forma płatności za nadanie', false, [
            'bank' => 'Skarbonka (prepaid)', 'pay_later' => 'Płatność odroczona',
            'paynow' => 'Płatności online (Paynow)', 'online' => 'Płatności online (Transferuj)',
            'dotpay' => 'Płatności online (Dotpay)', 'monetivo' => 'Płatności online (Monetivo)',
        ]],
        'label_format' => ['Domyślny format etykiety', false, [
            'A4' => 'A4 (zwykła drukarka)', 'LBL' => 'Termiczna PDF (A6)',
            'ZPL' => 'ZPL (Zebra)', 'EPL' => 'EPL (Zebra)',
        ]],
        'pickup_mode'  => ['Domyślny sposób nadania', false, [
            'courier' => 'Odbiór przez kuriera', 'self' => 'Sam dostarczę do przewoźnika',
        ], 'Wstępnie zaznaczony przy nadawaniu paczki na zamówieniu (można zmienić per przesyłkę).'],
    ],
];

// Wtyczki spoza listy powyżej: pola formularza z manifestu wtyczki (integrations/README.md
// -> „Pola konfiguracji"). Dzięki temu nowa wtyczka nie wymaga zmian w tym pliku.
foreach (\Pase\Plugin\PluginRegistry::manifests() as $mf) {
    if (isset($schema[$mf->type])) {
        continue;
    }
    $schema[$mf->type] = [];
    foreach ($mf->fields as $f) {
        if (empty($f['key'])) {
            continue;
        }
        $schema[$mf->type][(string) $f['key']] = [
            (string) ($f['label'] ?? $f['key']),
            !empty($f['secret']) || ($f['type'] ?? '') === 'password',
            ($f['type'] ?? '') === 'select' && !empty($f['options']) ? $f['options'] : null,
            (string) ($f['help'] ?? ''),
            (string) ($f['type'] ?? 'text'),
        ];
    }
}

$type = $existing['type'] ?? ($_GET['type'] ?? 'woocommerce');
if (!isset($schema[$type])) {
    $type = 'woocommerce';
}

// Konta kurierskie (zdolność COURIER) mają wybór sklepów, których zamówienia obsługują.
$isCourierType = false;
try {
    $isCourierType = (bool) \Pase\Plugin\PluginRegistry::get($type)?->manifest()->hasCapability(\Pase\Plugin\Capability::COURIER);
} catch (\Throwable) {
}

// Allegro nie ma per-konto configu jak Woo/BLPaczka/wFirma - to JEDNA, globalna
// konfiguracja dla całego systemu (client_id/secret, redirect_uri, env, User-Agent),
// z której realnie korzysta AllegroPlugin::makeClient() - patrz config/config.php.
// Ten formularz jest jej jedynym źródłem prawdy: czyta i zapisuje wprost do tabeli
// settings (tej samej, z której config.php buduje 'allegro' => [...]).
$allegroSettingsMap = [
    'client_id'     => 'ALLEGRO_CLIENT_ID',
    'client_secret' => 'ALLEGRO_CLIENT_SECRET',
    'redirect_uri'  => 'ALLEGRO_REDIRECT_URI',
    'env'           => 'ALLEGRO_ENV',
    'app_name'      => 'ALLEGRO_APP_NAME',
    'app_version'   => 'ALLEGRO_APP_VERSION',
    'app_url'       => 'ALLEGRO_APP_URL',
];

// --- Akcje wtyczki (przyciski z adminActions(), np. włączenie Pushera ORLEN Paczka) ---
$pluginActionResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['plugin_action']) && $id && $existing) {
    csrfCheck();
    $actPlugin = \Pase\Plugin\PluginRegistry::forAccount($existing['type'], $existing['config']);
    $action = (string) $_POST['plugin_action'];
    if ($actPlugin !== null && method_exists($actPlugin, 'adminAction') && array_key_exists($action, $actPlugin->adminActions())) {
        try {
            $pluginActionResult = $actPlugin->adminAction($action, [
                'account_id' => $id,
                'base_url'   => (string) (new SettingsRepository($pdo))->get('APP_BASE_URL', ''),
                'pdo'        => $pdo,
                'local'      => \Pase\Support\AppMode::isLocal(),
                'save'       => static function (array $config) use ($repo, $id): void { $repo->updateConfig($id, $config); },
            ]);
        } catch (\Throwable $e) {
            $pluginActionResult = ['ok' => false, 'message' => $e->getMessage()];
        }
        $existing = $repo->find($id) ?? $existing;
    } else {
        $pluginActionResult = ['ok' => false, 'message' => 'Nieznana akcja.'];
    }
}

// --- Zapis ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['plugin_action'])) {
    csrfCheck();
    $type = $_POST['type'] ?? $type;
    $name = trim($_POST['name'] ?? '');
    $isActive = isset($_POST['is_active']);
    $webhookSecret = trim($_POST['webhook_secret'] ?? '');

    $config = $existing['config'] ?? [];
    foreach ($schema[$type] ?? [] as $key => [$label, $isSecret]) {
        $val = trim($_POST["cfg_{$key}"] ?? '');
        if ($isSecret && $val === '') {
            continue; // puste sekretne pole = nie zmieniaj
        }
        $config[$key] = $val;
    }

    // Allegro: te same pola ZAPISUJEMY DODATKOWO do tabeli settings (patrz wyżej) -
    // to stamtąd, nie z integration_accounts.config, korzysta realne połączenie.
    if ($type === 'allegro') {
        $settingsToSave = [];
        foreach ($allegroSettingsMap as $key => $settingsKey) {
            $isSecret = $schema['allegro'][$key][1] ?? false;
            $val = trim($_POST["cfg_{$key}"] ?? '');
            if ($isSecret && $val === '') {
                continue; // puste sekretne pole = nie zmieniaj
            }
            $settingsToSave[$settingsKey] = $val;
        }
        (new SettingsRepository($pdo))->setMany($settingsToSave);
    }
    // sync_status: Woo i Allegro (checkbox) - odsyłanie zmiany statusu z CRM z powrotem do kanału.
    if (in_array($type, ['woocommerce', 'allegro'], true)) {
        $config['sync_status'] = isset($_POST['sync_status']);
    }

    // Konto kurierskie: dla których sklepów (pusta lista = wszystkie) - patrz CourierShipping::servesShop().
    if ($isCourierType) {
        $shopsPosted = $_POST['shops'] ?? [];
        $config['shops'] = array_values(array_filter(array_map('strval', is_array($shopsPosted) ? $shopsPosted : []),
            static fn(string $s): bool => $s === \Pase\Services\CourierShipping::MANUAL_SHOP || ctype_digit($s)));
    }

    // Dane nadawcy + ulubieni kurierzy dla BLPaczka.
    if ($type === 'blpaczka') {
        // Ulubieni kurierzy (zaznaczone checkboxy -> tablica kodów).
        $fav = $_POST['favorite_couriers'] ?? [];
        $config['favorite_couriers'] = array_values(array_filter(array_map(
            static fn($c) => preg_replace('/[^a-z0-9_]/', '', (string) $c),
            is_array($fav) ? $fav : []
        )));

        $senderFields = ['name', 'email', 'phone', 'street', 'house_no', 'postal', 'city', 'account'];
        $sender = $existing['config']['sender'] ?? [];
        foreach ($senderFields as $sf) {
            $sender[$sf] = trim($_POST["snd_{$sf}"] ?? ($sender[$sf] ?? ''));
        }
        $config['sender'] = $sender;
    }

    if ($name === '') {
        $flashErr = 'Podaj nazwę integracji.';
    } else {
        // webhook_secret: puste przy edycji = zostaw stary.
        $secretToSave = $webhookSecret !== '' ? $webhookSecret : ($existing['webhook_secret'] ?? null);
        if ($id) {
            $repo->update($id, $name, $config, $secretToSave, $isActive);
            $flashOk = 'Zapisano zmiany.';
        } else {
            $id = $repo->create($type, $name, $config, $secretToSave);
            $flashOk = 'Dodano integrację.';
        }
        $existing = $repo->find($id);
    }
}

// --- Test połączenia ---
// Allegro ma OAuth (token w bazie) - testujemy przez klienta wtyczki (makeClient).
// Reszta integracji - przez rejestr wtyczek (testConnection).
$wooTest = null;
if ($id && $existing && ($_GET['action'] ?? '') === 'test') {
    if ($existing['type'] === 'allegro') {
        $allegroCfg = (require PASE_ROOT . '/config/config.php')['allegro'] ?? [];
        [$ok, $msg] = \PasePlugin\Allegro\AllegroPlugin::makeClient($pdo, $allegroCfg)->checkConnection();
        $wooTest = ['ok' => $ok, 'message' => $msg];
    } elseif ($existing['type'] === 'tiktokshop' && \Pase\Services\TiktokShop::available()) {
        // Token sprzedawcy jest w bazie - test przez usługę (lista sklepów z tokenem).
        \Pase\Services\TiktokShop::migrate($pdo);
        $wooTest = (new \Pase\Services\TiktokShop($pdo))->test($existing);
    } else {
        $plugin = \Pase\Plugin\PluginRegistry::forAccount($existing['type'], $existing['config']);
        if ($plugin !== null) {
            $wooTest = $plugin->testConnection();
        } else {
            $wooTest = ['ok' => false, 'message' => 'Brak zainstalowanej wtyczki dla typu „' . $existing['type'] . '”.'];
        }
    }
}

// TikTok Shop: komunikat po powrocie ze strony zgody + „Synchronizuj teraz”.
if (!empty($_SESSION['tiktokshop_flash'])) {
    $wooTest = $_SESSION['tiktokshop_flash'];
    unset($_SESSION['tiktokshop_flash']);
}
$tiktokStatus = null;
if ($id && $existing && $existing['type'] === 'tiktokshop' && \Pase\Services\TiktokShop::available()) {
    \Pase\Services\TiktokShop::migrate($pdo);
    $tiktok = new \Pase\Services\TiktokShop($pdo, null, new \Pase\Queue\Queue($pdo));
    if (in_array($_GET['action'] ?? '', ['tiktok_sync', 'tiktok_catalog'], true) && canEdit()) {
        try {
            $client = $tiktok->client($existing);
            if ($_GET['action'] === 'tiktok_catalog') {
                $n = $tiktok->refreshCatalog($existing, $client, true);
                $wooTest = ['ok' => true, 'message' => 'Pobrano ' . $n . ' SKU z TikTok Shop.'];
            } else {
                $o = $tiktok->syncOrders($existing, $client);
                $st = $tiktok->pushStock($existing, $client);
                $tr = $tiktok->pushTracking($existing, $client);
                $wooTest = ['ok' => true, 'message' => "Zamówienia: {$o}, wysłane stany: {$st}, wysłane numery przesyłek: {$tr}."];
            }
        } catch (\Throwable $e) {
            $wooTest = ['ok' => false, 'message' => $e->getMessage()];
        }
        $existing = $repo->find($id) ?? $existing;
    }
    $tiktokStatus = $tiktok->status($existing);
}

$settingsRepo = new SettingsRepository($pdo);
if ($type === 'allegro') {
    // Jedyne źródło prawdy dla Allegro - patrz notka przy $allegroSettingsMap wyżej.
    // Baza ma priorytet, ale jeśli pole nigdy nie było zapisane w panelu (bo wcześniej
    // te pola nic nie robiły), pokazujemy wartość z .env - dokładnie tak samo, jak
    // robi to $cfg() w config/config.php przy realnym połączeniu z Allegro. Inaczej
    // formularz pokazywałby puste pola mimo działającej konfiguracji z .env, a zapis
    // takiego "pustego" pola nadpisałby ją pustym ciągiem w bazie.
    $allegroDefaults = ['env' => 'sandbox', 'app_name' => 'Veless', 'app_version' => '1.0'];
    $cfg = [];
    foreach ($allegroSettingsMap as $key => $settingsKey) {
        $fromDb = $settingsRepo->get($settingsKey, '');
        $cfg[$key] = $fromDb !== '' && $fromDb !== null
            ? $fromDb
            : (Env::get($settingsKey, $allegroDefaults[$key] ?? '') ?? ($allegroDefaults[$key] ?? ''));
    }
    // sync_status żyje w integration_accounts.config (jak dla Woo), nie w settings -
    // to jedyne pole Allegro, które NIE jest globalne per system, tylko per konto.
    $cfg['sync_status'] = $existing['config']['sync_status'] ?? false;
} else {
    $cfg = $existing['config'] ?? [];
}
$appBaseUrl = $settingsRepo->get('APP_BASE_URL', 'https://twojsklep.pl/pase/public');
$webhookUrl = rtrim($appBaseUrl, '/') . '/webhook_woo_order.php?integration=' . ($id ?: 'ID');

$PAGE_TITLE = $existing ? ('Integracja: ' . $existing['name']) : 'Nowa integracja';
$PAGE_KEY   = 'integrations';
require __DIR__ . '/header.php';
?>

<p style="margin:-6px 0 16px"><a class="btn secondary" href="integrations.php">← Wszystkie integracje</a></p>

<?php if ($flashOk): ?><div class="flash ok"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="flash err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

<div class="card">
    <form method="post" id="integration-form">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">

        <?php
        // Etykieta/ikona typu z manifestu wtyczki (galeria); fallback dla wFirma.
        $typeMeta = ['label' => $type, 'icon' => '🔌'];
        foreach (\Pase\Plugin\PluginRegistry::manifests() as $mf) {
            if ($mf->type === $type) { $typeMeta = ['label' => $mf->name, 'icon' => $mf->iconHtml('1em')]; break; }
        }
        if ($type === 'wfirma') { $typeMeta = ['label' => 'wFirma', 'icon' => '📄']; }
        ?>
        <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:end">
            <div>
                <label style="font-size:12px;color:#888;display:block">Typ integracji</label>
                <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
                <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;border:1px solid #e5e7eb;border-radius:8px;background:#fafbfc">
                    <span style="font-size:20px"><?= $typeMeta['icon'] ?></span>
                    <strong><?= htmlspecialchars($typeMeta['label']) ?></strong>
                    <?php if (!$existing): ?>
                        <a href="integrations.php" style="font-size:12px;color:#1a73e8;margin-left:6px">← zmień</a>
                    <?php endif; ?>
                </div>
            </div>
            <div>
                <label style="font-size:12px;color:#888;display:block">Nazwa (Twoja etykieta)</label>
                <input name="name" required value="<?= htmlspecialchars($existing['name'] ?? '') ?>" placeholder="np. Mój Sklep" style="width:260px">
            </div>
            <div>
                <label style="font-size:12px;color:#888;display:block">Aktywna</label>
                <input type="checkbox" name="is_active" <?= ($existing['is_active'] ?? 1) ? 'checked' : '' ?>>
            </div>
        </div>

        <?php foreach ($schema[$type] as $key => $def):
            $label    = $def[0];
            $isSecret = $def[1] ?? false;
            $options  = $def[2] ?? null;   // mapa wartość=>etykieta dla selecta
            $help     = $def[3] ?? '';     // podpowiedź z manifestu wtyczki
            $ftype    = $def[4] ?? 'text'; // typ pola z manifestu (textarea = wiele linii)
            $has      = isset($cfg[$key]) && $cfg[$key] !== '';
            $current  = (string) ($cfg[$key] ?? '');
        ?>
            <label style="font-size:12px;color:#888;display:block;margin-top:12px"><?= htmlspecialchars($label) ?></label>
            <?php if ($options !== null): ?>
                <select name="cfg_<?= $key ?>" style="width:100%;max-width:480px">
                    <?php foreach ($options as $val => $optLabel): $val = (string) $val; /* klucze liczbowe (np. VAT 23) PHP zamienia na int */ ?>
                        <option value="<?= htmlspecialchars($val) ?>" <?= $current === $val ? 'selected' : '' ?>><?= htmlspecialchars($optLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php elseif ($isSecret): ?>
                <input name="cfg_<?= $key ?>" type="password" autocomplete="off"
                       placeholder="<?= $has ? '•••••••• (zapisane — wpisz, aby zmienić)' : 'nie ustawiono' ?>"
                       style="width:100%;max-width:480px">
            <?php elseif ($ftype === 'textarea'): ?>
                <textarea name="cfg_<?= $key ?>" rows="5" style="width:100%;max-width:640px;font-family:inherit"><?= htmlspecialchars($current) ?></textarea>
            <?php else: ?>
                <input name="cfg_<?= $key ?>" value="<?= htmlspecialchars($current) ?>" style="width:100%;max-width:480px">
            <?php endif; ?>
            <?php if ($help !== ''): ?><p style="color:#888;font-size:12px;margin:4px 0 0"><?= htmlspecialchars($help) ?></p><?php endif; ?>
        <?php endforeach; ?>

        <?php if ($isCourierType):
            // Sklepy = konta z pobieraniem zamówień (WooCommerce, Allegro, TikTok Shop...) + zamówienia dodane w CRM.
            $shopOptions = [];
            foreach ($repo->all() as $shopAcc) {
                try {
                    $shopMf = \Pase\Plugin\PluginRegistry::get((string) $shopAcc['type'])?->manifest();
                } catch (\Throwable) {
                    $shopMf = null;
                }
                if ($shopMf !== null && $shopMf->hasCapability(\Pase\Plugin\Capability::ORDER_SOURCE)) {
                    $shopOptions[(string) $shopAcc['id']] = ($shopAcc['name'] ?: $shopMf->name) . ' (' . $shopMf->name . ')';
                }
            }
            $shopOptions[\Pase\Services\CourierShipping::MANUAL_SHOP] = 'Zamówienia dodane ręcznie w CRM';
            $shopsSaved = array_map('strval', is_array($cfg['shops'] ?? null) ? $cfg['shops'] : []);
        ?>
            <label style="font-size:12px;color:#888;display:block;margin-top:16px">Używaj dla zamówień ze sklepów</label>
            <div style="display:flex;flex-direction:column;gap:4px;margin-top:4px">
                <?php foreach ($shopOptions as $shopKey => $shopLabel): ?>
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer">
                        <input type="checkbox" name="shops[]" value="<?= htmlspecialchars((string) $shopKey) ?>" <?= in_array((string) $shopKey, $shopsSaved, true) ? 'checked' : '' ?>>
                        <span><?= htmlspecialchars($shopLabel) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <p style="color:#888;font-size:12px;margin:4px 0 0">Nic nie zaznaczone = wszystkie sklepy. Przy zamówieniu „Nadaj przez” pokaże to konto tylko dla zaznaczonych sklepów — np. dwa konta z różnymi danymi nadawcy dla dwóch sklepów.</p>
        <?php endif; ?>

        <?php if ($type === 'woocommerce'): ?>
            <label style="font-size:12px;color:#888;display:block;margin-top:12px">Sekret webhooka</label>
            <input name="webhook_secret" type="password" autocomplete="off"
                   placeholder="<?= !empty($existing['webhook_secret']) ? '•••••••• (zapisane)' : 'wpisz losowy ciąg' ?>"
                   style="width:100%;max-width:480px">

            <label style="display:flex;align-items:center;gap:8px;margin-top:14px;cursor:pointer">
                <input type="checkbox" name="sync_status" <?= !empty($cfg['sync_status']) ? 'checked' : '' ?>>
                <span>Synchronizuj zmianę statusu z powrotem do WooCommerce</span>
            </label>
        <?php endif; ?>

        <?php if ($type === 'blpaczka'): $snd = $cfg['sender'] ?? []; ?>
            <hr style="margin:20px 0;border:0;border-top:1px solid #eee">
            <strong>Dane nadawcy</strong>
            <p style="color:#888;font-size:12px;margin:4px 0 10px">Używane jako nadawca przy każdej przesyłce.</p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:640px">
                <div><label style="font-size:12px;color:#888;display:block">Nazwa / firma</label>
                    <input name="snd_name" value="<?= htmlspecialchars($snd['name'] ?? '') ?>" style="width:100%"></div>
                <div><label style="font-size:12px;color:#888;display:block">E-mail</label>
                    <input name="snd_email" value="<?= htmlspecialchars($snd['email'] ?? '') ?>" style="width:100%"></div>
                <div><label style="font-size:12px;color:#888;display:block">Telefon</label>
                    <input name="snd_phone" value="<?= htmlspecialchars($snd['phone'] ?? '') ?>" style="width:100%"></div>
                <div><label style="font-size:12px;color:#888;display:block">Ulica</label>
                    <input name="snd_street" value="<?= htmlspecialchars($snd['street'] ?? '') ?>" style="width:100%"></div>
                <div><label style="font-size:12px;color:#888;display:block">Nr domu</label>
                    <input name="snd_house_no" value="<?= htmlspecialchars($snd['house_no'] ?? '') ?>" style="width:100%"></div>
                <div><label style="font-size:12px;color:#888;display:block">Kod pocztowy</label>
                    <input name="snd_postal" value="<?= htmlspecialchars($snd['postal'] ?? '') ?>" placeholder="00-000" style="width:100%"></div>
                <div><label style="font-size:12px;color:#888;display:block">Miasto</label>
                    <input name="snd_city" value="<?= htmlspecialchars($snd['city'] ?? '') ?>" style="width:100%"></div>
                <div style="grid-column:span 2"><label style="font-size:12px;color:#888;display:block">Nr konta (do pobrań/zwrotów, opcjonalne)</label>
                    <input name="snd_account" value="<?= htmlspecialchars($snd['account'] ?? '') ?>" placeholder="PL00 0000 ..." style="width:100%"></div>
            </div>

            <hr style="margin:20px 0;border:0;border-top:1px solid #eee">
            <strong>Ulubieni kurierzy</strong>
            <p style="color:#888;font-size:12px;margin:4px 0 10px">Zaznaczeni pojawią się jako szybkie przyciski przy nadawaniu paczki.</p>
            <?php $favSaved = $cfg['favorite_couriers'] ?? []; ?>
            <div id="favCouriers" data-saved='<?= htmlspecialchars(json_encode($favSaved), ENT_QUOTES) ?>'
                 style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px;max-width:760px">
                <span style="color:#888;font-size:13px">⏳ ładuję listę kurierów...</span>
            </div>
            <script>
            (function () {
                var box = document.getElementById('favCouriers');
                var saved = JSON.parse(box.dataset.saved || '[]');
                var p = new URLSearchParams({ csrf: <?= json_encode(csrfToken()) ?>, action: 'couriers', woo_order_id: '0' });
                fetch('shipment_create.php', { method: 'POST', body: p })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        var names = (d.raw && d.raw.courierNames) || {};
                        var keys = Object.keys(names);
                        if (!keys.length) { box.innerHTML = '<span style="color:#b06000;font-size:13px">Nie udało się pobrać listy (sprawdź połączenie BLPaczka).</span>'; return; }
                        keys.sort(function (a, b) { return names[a].localeCompare(names[b]); });
                        box.innerHTML = '';
                        keys.forEach(function (code) {
                            var lab = document.createElement('label');
                            lab.style.cssText = 'display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer';
                            var cb = document.createElement('input');
                            cb.type = 'checkbox'; cb.name = 'favorite_couriers[]'; cb.value = code;
                            if (saved.indexOf(code) !== -1) cb.checked = true;
                            lab.appendChild(cb);
                            lab.appendChild(document.createTextNode(names[code]));
                            box.appendChild(lab);
                        });
                    })
                    .catch(function () { box.innerHTML = '<span style="color:#c5221f">Błąd ładowania kurierów.</span>'; });
            })();
            </script>
        <?php endif; ?>

        <p style="margin-top:18px">
            <button class="btn" type="submit"><?= $existing ? 'Zapisz' : 'Dodaj integrację' ?></button>
            <?php if ($id && (in_array($type, ['woocommerce', 'blpaczka', 'allegro', 'wfirma', 'tiktokshop'], true) || $type === 'orlenpaczka')): ?>
                <a class="btn secondary" href="?id=<?= $id ?>&action=test">Testuj połączenie</a>
            <?php endif; ?>
            <?php if ($id && $type === 'allegro'): ?>
                <a class="btn" href="allegro_connect.php" style="background:#ff5a00">🔗 Połącz z Allegro</a>
            <?php endif; ?>
            <?php if ($id && $type === 'tiktokshop'): ?>
                <a class="btn" href="tiktokshop_connect.php?id=<?= (int) $id ?>" style="background:#111">🔗 Połącz z TikTok Shop</a>
            <?php endif; ?>
        </p>
    </form>

    <?php if ($wooTest !== null): ?>
        <div class="flash <?= $wooTest['ok'] ? 'ok' : 'err' ?>">
            <?= $wooTest['ok'] ? '✅ ' : '❌ ' ?><?= htmlspecialchars($wooTest['message']) ?>
            <?php if (!empty($wooTest['status'])): ?>(HTTP <?= (int)$wooTest['status'] ?>)<?php endif; ?>
        </div>
    <?php endif; ?>

    <?php
    // Panel wtyczki: opis stanu (adminInfo) + przyciski akcji (adminActions) - np. Uniwersalny Pusher ORLEN Paczka.
    $panelPlugin = ($id && $existing) ? \Pase\Plugin\PluginRegistry::forAccount($existing['type'], $existing['config']) : null;
    if ($panelPlugin !== null && method_exists($panelPlugin, 'adminActions') && method_exists($panelPlugin, 'adminInfo')):
        $panelInfo = $panelPlugin->adminInfo(['account_id' => $id, 'base_url' => (string) $appBaseUrl, 'pdo' => $pdo, 'local' => \Pase\Support\AppMode::isLocal()]);
    ?>
        <hr style="margin:16px 0;border:0;border-top:1px solid #eee">
        <?php foreach ($panelInfo as $i => $line): ?>
            <p style="font-size:13px;color:<?= $i === 0 ? '#555' : '#222' ?>;margin:6px 0 0"><?= htmlspecialchars((string) $line) ?></p>
        <?php endforeach; ?>
        <?php if ($pluginActionResult !== null): ?>
            <div class="flash <?= $pluginActionResult['ok'] ? 'ok' : 'err' ?>" style="margin-top:10px"><?= $pluginActionResult['ok'] ? '✅ ' : '❌ ' ?><?= htmlspecialchars((string) $pluginActionResult['message']) ?></div>
        <?php endif; ?>
        <?php if (canEdit()): ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
            <?php foreach ($panelPlugin->adminActions() as $actKey => $actLabel): ?>
                <form method="post" style="margin:0">
                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                    <input type="hidden" name="plugin_action" value="<?= htmlspecialchars((string) $actKey) ?>">
                    <button class="btn secondary" type="submit"><?= htmlspecialchars((string) $actLabel) ?></button>
                </form>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($id && $type === 'blpaczka'): ?>
        <hr style="margin:16px 0;border:0;border-top:1px solid #eee">
        <button class="btn secondary" type="button" id="btnPayments">💳 Pokaż dostępne formy płatności</button>
        <p style="color:#888;font-size:12px;margin:6px 0 0">Wpisz w polu „Forma płatności” dokładny <strong>kod</strong> z tej listy (np. <code>bank</code>).</p>
        <pre id="payRaw" style="display:none;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;overflow:auto;font-size:11px;max-height:300px;margin-top:10px"></pre>
        <script>
        document.getElementById('btnPayments').addEventListener('click', function () {
            var p = new URLSearchParams({ csrf: <?= json_encode(csrfToken()) ?>, action: 'payments', woo_order_id: '0' });
            var pre = document.getElementById('payRaw');
            pre.style.display = 'block'; pre.textContent = '⏳ ...';
            fetch('shipment_create.php', { method: 'POST', body: p })
                .then(function (r) { return r.json(); })
                .then(function (d) { pre.textContent = JSON.stringify(d.raw !== undefined ? d.raw : d, null, 2); })
                .catch(function () { pre.textContent = 'Błąd połączenia.'; });
        });
        </script>
    <?php endif; ?>

    <?php if ($id && $type === 'tiktokshop' && $tiktokStatus !== null): $ts = $tiktokStatus; ?>
        <hr style="margin:16px 0;border:0;border-top:1px solid #eee">
        <strong style="font-size:13px">Połączenie z TikTok Shop</strong>
        <?php if ($ts['connected']): ?>
            <p style="font-size:13px;margin:8px 0 0">✅ Połączono ze sklepem <strong><?= htmlspecialchars($ts['shop']) ?></strong><?= $ts['seller'] !== '' ? ' (' . htmlspecialchars($ts['seller']) . ')' : '' ?>.
                Token ważny do <?= htmlspecialchars((string) $ts['expires_at']) ?> — CRM odnawia go sam.</p>
            <p style="font-size:13px;color:#555;margin:6px 0 0">
                Ostatnia synchronizacja: <?= $ts['last_run'] !== '' ? htmlspecialchars($ts['last_run']) : 'jeszcze nie było (worker co ' . \Pase\Services\TiktokShop::RUN_EVERY_MIN . ' min)' ?>.
                Zamówień z TikTok w CRM: <strong><?= (int) $ts['orders'] ?></strong>. SKU w TikTok: <strong><?= (int) $ts['skus'] ?></strong>, w tym z tym samym SKU w magazynie CRM: <strong><?= (int) $ts['skus_linked'] ?></strong>.
            </p>
            <?php if ($ts['last_error'] !== ''): ?><div class="flash err" style="margin-top:8px"><?= htmlspecialchars($ts['last_error']) ?></div><?php endif; ?>
            <?php if (canEdit()): ?>
            <p style="margin:10px 0 0">
                <a class="btn secondary" href="?id=<?= (int) $id ?>&action=tiktok_sync">↻ Synchronizuj teraz</a>
                <a class="btn secondary" href="?id=<?= (int) $id ?>&action=tiktok_catalog">Pobierz produkty z TikTok</a>
            </p>
            <?php endif; ?>
            <?php if ($ts['sku_errors']): ?>
                <p style="font-size:13px;margin:12px 0 4px"><strong>Stany, których TikTok nie przyjął</strong> (kolejna próba przy następnej synchronizacji):</p>
                <table><tr><th>SKU</th><th>Produkt</th><th>Błąd</th></tr>
                <?php foreach ($ts['sku_errors'] as $r): ?><tr><td><?= htmlspecialchars((string) $r['seller_sku']) ?></td><td><?= htmlspecialchars((string) $r['product_title']) ?></td><td><?= htmlspecialchars((string) $r['error']) ?></td></tr><?php endforeach; ?>
                </table>
            <?php endif; ?>
            <?php if ($ts['tracking_errors']): ?>
                <p style="font-size:13px;margin:12px 0 4px"><strong>Numery przesyłek, których TikTok nie przyjął</strong>:</p>
                <table><tr><th>Zamówienie</th><th>Błąd</th></tr>
                <?php foreach ($ts['tracking_errors'] as $r): ?><tr><td><a href="order_view.php?id=<?= (int) $r['woo_order_id'] ?>">#<?= htmlspecialchars((string) ($r['pase_number'] ?? $r['woo_order_id'])) ?></a> (TikTok <?= htmlspecialchars((string) $r['tt_order_id']) ?>)</td><td><?= htmlspecialchars((string) $r['tracking_error']) ?></td></tr><?php endforeach; ?>
                </table>
            <?php endif; ?>
        <?php else: ?>
            <p style="font-size:13px;color:#b45309;margin:8px 0 0">Konto nie jest jeszcze połączone.</p>
        <?php endif; ?>
        <ol style="font-size:13px;color:#555;line-height:1.7;margin:10px 0 0;padding-left:18px">
            <li>W <a href="https://partner.tiktokshop.com" target="_blank" rel="noopener">TikTok Shop Partner Center</a> utwórz aplikację (App & Service → Create app), włącz jej zakresy API: Order, Product, Fulfillment, Logistics, Authorization.</li>
            <li>Jako <strong>Redirect URL</strong> aplikacji wpisz:
                <code style="display:block;background:#f1f3f4;padding:8px;border-radius:6px;margin-top:4px;word-break:break-all"><?= htmlspecialchars(rtrim((string) $appBaseUrl, '/') . '/admin/tiktokshop_connect.php') ?></code></li>
            <li>Przepisz powyżej App key, App secret i Service ID, zapisz, potem kliknij <strong>„Połącz z TikTok Shop”</strong> i zaloguj się kontem sprzedawcy.</li>
            <li>Produkty łączą się po SKU: <strong>SKU sprzedawcy</strong> w TikTok musi być takie samo jak SKU w magazynie CRM.</li>
        </ol>
    <?php endif; ?>

    <?php if ($id && $type === 'allegro'): ?>
        <hr style="margin:16px 0;border:0;border-top:1px solid #eee">
        <strong style="font-size:13px">Połączenie z Allegro (OAuth)</strong>
        <ol style="font-size:13px;color:#555;line-height:1.7;margin:8px 0 0;padding-left:18px">
            <li>Wypełnij i zapisz pola powyżej (Client ID/Secret, Redirect URI, środowisko, dane do User-Agent) — to jedyne miejsce, z którego CRM je czyta.</li>
            <li>W panelu Allegro Developer dodaj <strong>Redirect URI</strong> identyczny z polem powyżej:
                <code style="display:block;background:#f1f3f4;padding:8px;border-radius:6px;margin-top:4px;word-break:break-all"><?= htmlspecialchars($cfg['redirect_uri'] !== '' ? $cfg['redirect_uri'] : '(uzupełnij pole „Redirect URI” powyżej)') ?></code></li>
            <li>Kliknij <strong>„Połącz z Allegro"</strong> i zatwierdź zgodę — wrócisz z tokenem.</li>
            <li>Sprawdź <strong>„Testuj połączenie"</strong> — powinno pokazać login konta Allegro.</li>
        </ol>
        <p style="color:#888;font-size:12px;margin-top:8px">
            Wynikowy nagłówek <code>User-Agent</code> wysyłany do API Allegro:
            <code style="font-family:var(--font-num)"><?= htmlspecialchars(trim($cfg['app_name']) . '/' . trim($cfg['app_version']) . ($cfg['app_url'] !== '' ? ' (+' . trim($cfg['app_url']) . ')' : '')) ?></code>
        </p>

        <?php /* Checkbox stoi poza <form> (sekcja pod przyciskami) - atrybut form= przypina go
               do formularza zapisu. Bez tego "Zapisz" nigdy go nie wysyłał i zapisywał false. */ ?>
        <label style="display:flex;align-items:center;gap:8px;margin-top:14px;cursor:pointer">
            <input type="checkbox" name="sync_status" form="integration-form" <?= !empty($cfg['sync_status']) ? 'checked' : '' ?>>
            <span>Synchronizuj zmianę statusu z powrotem do Allegro</span>
        </label>
        <p style="color:#888;font-size:12px;margin:4px 0 0">
            Allegro nie ma dowolnych statusów jak Woo - „W realizacji" wysyła się jako <code>PROCESSING</code>,
            „Wysłane" jako <code>SENT</code>, „Anulowane" jako <code>CANCELLED</code>. „Nowe" i „Zwrot" nie mają
            odpowiednika po stronie Allegro i nie są wysyłane.
        </p>

        <hr style="margin:16px 0;border:0;border-top:1px solid #eee">
        <strong style="font-size:13px">Szablony opisu ofert</strong>
        <p style="color:#888;font-size:12px;margin:6px 0 8px">
            Zdefiniuj szablony opisu w stylu Allegro (moduły Tekst / Grafika) i przypisz je do kategorii.
            Opis oferty wypełni się automatycznie wg kategorii produktu.
        </p>
        <a class="btn secondary" href="allegro_templates.php">🧩 Szablony opisu Allegro</a>
    <?php endif; ?>
</div>

<?php if ($id && $type === 'woocommerce' && \Pase\Support\AppMode::isLocal()): ?>
<div class="card">
    <strong>Webhook nie jest potrzebny</strong>
    <p style="font-size:13px;color:#555;margin-top:8px">
        CRM działa w trybie lokalnym (na tym komputerze), więc sklep nie ma jak wysłać do niego webhooka.
        Zamiast tego CRM co minutę sam sprawdza w sklepie nowe i zmienione zamówienia, dopóki komputer jest włączony.
    </p>
</div>
<?php elseif ($id && $type === 'woocommerce'): ?>
<div class="card">
    <strong>Webhook tej integracji</strong>
    <p style="font-size:13px;color:#555;margin-top:8px">
        W WooCommerce tego sklepu dodaj webhook „Zamówienie utworzone” z adresem:
    </p>
    <code style="display:block;background:#f1f3f4;padding:10px;border-radius:6px;word-break:break-all"><?= htmlspecialchars($webhookUrl) ?></code>
    <p style="font-size:12px;color:#888;margin-top:8px">Sekret webhooka = wartość ustawiona wyżej. Adres zawiera <code>integration=<?= (int)$id ?></code>, dzięki czemu CRM wie, z którego sklepu pochodzi zamówienie.</p>
</div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
