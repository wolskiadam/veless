<?php
declare(strict_types=1);

/**
 * Producenci odpowiedzialni (GPSR) na Allegro - lista, dodawanie i edycja (GET/POST/PUT /sale/responsible-producers).
 * Producenta przypisujesz ofertom zbiorczo w Zarządzaniu ofertami → Operacje → „Przypisz producenta".
 * Allegro nie pozwala usuwać producentów przez API - nieużywanego zostawiasz albo edytujesz.
 */

use PasePlugin\Allegro\AllegroPlugin;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$config = require PASE_ROOT . '/config/config.php';
$client = AllegroPlugin::makeClient($pdo, $config['allegro'] ?? []);

$countries = ['PL' => 'Polska', 'DE' => 'Niemcy', 'CZ' => 'Czechy', 'SK' => 'Słowacja', 'LT' => 'Litwa', 'AT' => 'Austria',
    'NL' => 'Holandia', 'BE' => 'Belgia', 'FR' => 'Francja', 'IT' => 'Włochy', 'ES' => 'Hiszpania', 'HU' => 'Węgry',
    'SE' => 'Szwecja', 'DK' => 'Dania', 'IE' => 'Irlandia', 'CN' => 'Chiny', 'US' => 'USA', 'GB' => 'Wielka Brytania'];

$form = null;       // dane do ponownego wyświetlenia formularza po błędzie
$formErr = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && canEdit()) {
    csrfCheck();
    $id = trim((string) ($_POST['id'] ?? '')) ?: null;
    $f = array_map(static fn($v) => trim((string) $v), [
        'name' => $_POST['name'] ?? '', 'company' => $_POST['company'] ?? '', 'country' => $_POST['country'] ?? 'PL',
        'street' => $_POST['street'] ?? '', 'postal' => $_POST['postal'] ?? '', 'city' => $_POST['city'] ?? '',
        'email' => $_POST['email'] ?? '', 'phone' => $_POST['phone'] ?? '', 'form_url' => $_POST['form_url'] ?? '',
    ]);
    $errs = [];
    if ($f['name'] === '') { $errs[] = 'nazwa (dla Ciebie)'; }
    if ($f['company'] === '') { $errs[] = 'nazwa handlowa producenta'; }
    if ($f['street'] === '' || $f['postal'] === '' || $f['city'] === '') { $errs[] = 'pełny adres'; }
    if ($f['email'] === '' && $f['form_url'] === '') { $errs[] = 'e-mail albo adres formularza kontaktowego'; }
    if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) { $errs[] = 'poprawny e-mail'; }
    if ($f['form_url'] !== '' && !preg_match('#^https?://#i', $f['form_url'])) { $errs[] = 'adres formularza zaczynający się od https://'; }

    if ($errs) {
        $formErr = 'Uzupełnij: ' . implode(', ', $errs) . '.';
        $form = $f + ['id' => $id];
    } else {
        $contact = array_filter(['email' => $f['email'], 'phoneNumber' => $f['phone'], 'formUrl' => $f['form_url']], static fn($v) => $v !== '');
        $body = [
            'name' => $f['name'],
            'producerData' => [
                'tradeName' => $f['company'],
                'address' => ['countryCode' => isset($countries[$f['country']]) ? $f['country'] : 'PL', 'street' => $f['street'],
                              'postalCode' => $f['postal'], 'city' => $f['city']],
                'contact' => $contact,
            ],
        ];
        $r = $client->saveResponsibleProducer($id, $body);
        if ($r['ok']) {
            unset($_SESSION['al_producers_cache']);
            flash($id ? 'Zapisano zmiany producenta.' : 'Dodano producenta na Allegro.');
            header('Location: allegro_producers.php', true, 303);
            exit;
        }
        $formErr = 'Allegro odrzuciło dane: ' . $r['message'];
        $form = $f + ['id' => $id];
    }
}

$list    = $client->responsibleProducers();
$persons = $list['producers'];
$editId  = (string) ($_GET['edit'] ?? '');
$showNew = isset($_GET['new']) || ($form !== null && empty($form['id']));
if ($form === null && $editId !== '') {
    foreach ($persons as $p) {
        if ((string) $p['id'] === $editId) {
            $pd = $p['producerData'] ?? [];
            $form = ['id' => $editId, 'name' => $p['name'] ?? '', 'company' => $pd['tradeName'] ?? '', 'country' => $pd['address']['countryCode'] ?? 'PL',
                     'street' => $pd['address']['street'] ?? '', 'postal' => $pd['address']['postalCode'] ?? '', 'city' => $pd['address']['city'] ?? '',
                     'email' => $pd['contact']['email'] ?? '', 'phone' => $pd['contact']['phoneNumber'] ?? '', 'form_url' => $pd['contact']['formUrl'] ?? ''];
        }
    }
}
if ($form === null && $showNew) {
    $form = ['id' => '', 'name' => '', 'company' => '', 'country' => 'PL', 'street' => '', 'postal' => '', 'city' => '', 'email' => '', 'phone' => '', 'form_url' => ''];
}

$e = static fn($v) => htmlspecialchars((string) $v);
$PAGE_TITLE = 'Producenci odpowiedzialni';
$PAGE_KEY   = 'allegro';
require __DIR__ . '/header.php';
?>

<?php if (!$list['ok']): ?><div class="flash err"><?= $e($list['message']) ?> <a href="allegro_connect.php">Połącz konto Allegro →</a></div><?php endif; ?>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <div>
            <strong>Producenci odpowiedzialni (GPSR)</strong>
            <p style="color:#888;font-size:13px;margin:4px 0 0;max-width:720px">
                Dane producenta wyrobu (rozporządzenie GPSR): nazwa, adres i kontakt. Allegro wymaga ich w ofertach produktów objętych GPSR —
                dla własnych wyrobów (np. świec) producentem jest Twoja firma. Producentów zapisujemy bezpośrednio na koncie Allegro,
                a ofertom przypisujesz ich w <a href="allegro_offers.php">Zarządzaniu ofertami</a> → Operacje.
            </p>
        </div>
        <?php if (canEdit() && $form === null): ?><a class="btn" href="?new=1">＋ Dodaj producenta</a><?php endif; ?>
    </div>

    <?php if ($form !== null && canEdit()): ?>
        <form method="post" class="rp-form">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="id" value="<?= $e($form['id']) ?>">
            <h3><?= $form['id'] !== '' ? 'Edycja: ' . $e($form['name']) : 'Nowy producent' ?></h3>
            <?php if ($formErr): ?><div class="flash err"><?= $e($formErr) ?></div><?php endif; ?>
            <div class="rp-grid">
                <div class="rp-full"><label>Nazwa (widzisz ją tylko Ty, np. „Moja marka")</label><input name="name" value="<?= $e($form['name']) ?>" maxlength="50" required></div>
                <div class="rp-full"><label>Nazwa handlowa producenta (pełna nazwa firmy)</label><input name="company" value="<?= $e($form['company']) ?>" maxlength="200" required></div>
                <div><label>Kraj</label>
                    <select name="country"><?php foreach ($countries as $cc => $cn): ?><option value="<?= $cc ?>" <?= $form['country'] === $cc ? 'selected' : '' ?>><?= $e($cn) ?></option><?php endforeach; ?></select></div>
                <div><label>Ulica i numer</label><input name="street" value="<?= $e($form['street']) ?>" required></div>
                <div><label>Kod pocztowy</label><input name="postal" value="<?= $e($form['postal']) ?>" required></div>
                <div><label>Miasto</label><input name="city" value="<?= $e($form['city']) ?>" required></div>
                <div><label>E-mail</label><input name="email" type="email" value="<?= $e($form['email']) ?>"></div>
                <div><label>Telefon (opcjonalnie)</label><input name="phone" value="<?= $e($form['phone']) ?>"></div>
                <div class="rp-full"><label>Adres formularza kontaktowego (zamiast e-maila, opcjonalnie)</label><input name="form_url" value="<?= $e($form['form_url']) ?>" placeholder="https://"></div>
            </div>
            <p style="display:flex;gap:8px;margin-top:14px">
                <button class="btn" type="submit"><?= $form['id'] !== '' ? 'Zapisz zmiany' : 'Dodaj na Allegro' ?></button>
                <a class="btn secondary" href="allegro_producers.php">Anuluj</a>
            </p>
        </form>
    <?php endif; ?>

    <?php if ($persons === [] && $list['ok']): ?>
        <p style="color:#888;margin-top:16px">Nie masz jeszcze producentów na Allegro.</p>
    <?php elseif ($persons !== []): ?>
        <table class="rp-table">
            <tr><th>Nazwa</th><th>Producent</th><th>Adres</th><th>Kontakt</th><th></th></tr>
            <?php foreach ($persons as $p): $pd = $p['producerData'] ?? []; $a = $pd['address'] ?? []; $c = $pd['contact'] ?? []; ?>
                <tr>
                    <td><strong><?= $e($p['name'] ?? '') ?></strong><div class="rp-id">ID: <?= $e($p['id'] ?? '') ?></div></td>
                    <td><?= $e($pd['tradeName'] ?? '') ?></td>
                    <td><?= $e(trim(($a['street'] ?? '') . ', ' . ($a['postalCode'] ?? '') . ' ' . ($a['city'] ?? '') . ' (' . ($a['countryCode'] ?? '') . ')', ', ')) ?></td>
                    <td style="font-size:13px"><?= $e($c['email'] ?? '') ?><?php if (!empty($c['phoneNumber'])): ?><br><?= $e($c['phoneNumber']) ?><?php endif; ?>
                        <?php if (!empty($c['formUrl'])): ?><br><a href="<?= $e($c['formUrl']) ?>" target="_blank" rel="noopener">formularz ↗</a><?php endif; ?></td>
                    <td style="text-align:right"><?php if (canEdit()): ?><a class="btn secondary" href="?edit=<?= $e($p['id'] ?? '') ?>" style="padding:4px 10px;font-size:13px">Edytuj</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>

<style>
    .rp-form { margin-top:16px; background:#f8f9fb; border:1px solid #eef0f3; border-radius:12px; padding:16px 18px; }
    .rp-form h3 { margin:0 0 10px; font-size:16px; }
    .rp-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px 14px; max-width:720px; }
    .rp-grid .rp-full { grid-column:1 / -1; }
    .rp-grid label { display:block; font-size:12px; color:#888; margin-bottom:3px; }
    .rp-grid input, .rp-grid select { width:100%; }
    .rp-table { margin-top:16px; }
    .rp-id { color:#aaa; font-size:11px; }
    @media (max-width:640px){ .rp-grid{ grid-template-columns:1fr; } }
</style>

<?php require __DIR__ . '/footer.php'; ?>
