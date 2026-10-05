<?php
declare(strict_types=1);

/**
 * Zarządzanie statusami zamówień. 5 systemowych (edycja koloru/nazwy, bez usuwania)
 * + własne dodatkowe (pełny CRUD). Kolor per status.
 */

use Pase\Domain\OrderStatus;
use Pase\Repository\OrderStatusRepository;
use Pase\Repository\StatusMapRepository;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$repo = new OrderStatusRepository($pdo);
$flashOk = $flashErr = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $label = trim($_POST['label'] ?? '');
        $color = trim($_POST['color'] ?? '#888888');
        $pos   = (int) ($_POST['position'] ?? 100);
        if ($label === '') {
            $flashErr = 'Podaj nazwę statusu.';
        } else {
            $repo->create($label, $color, $pos);
            $flashOk = 'Dodano status.';
        }
    } elseif ($action === 'update') {
        $id    = (int) $_POST['id'];
        $label = trim($_POST['label'] ?? '');
        $color = trim($_POST['color'] ?? '#888888');
        $pos   = (int) ($_POST['position'] ?? 100);
        if ($label !== '') {
            $repo->update($id, $label, $color, $pos);
            $flashOk = 'Zapisano status.';
        }
    } elseif ($action === 'delete') {
        $ok = $repo->delete((int) $_POST['id']);
        $flashErr = $ok ? null : 'Nie można usunąć statusu systemowego.';
        $flashOk  = $ok ? 'Usunięto status.' : null;
    } elseif ($action === 'save_maps') {
        $maps = new StatusMapRepository($pdo);

        // PASE -> kanał: pusty wybór zapisujemy jako pusty string ("nie wysyłaj"),
        // a nie NULL - NULL oznacza "nie ustawiono, użyj domyślnego" i wtedy
        // nie dałoby się świadomie wyłączyć synchronizacji danego statusu.
        foreach (($_POST['out_woo'] ?? []) as $statusKey => $value) {
            $maps->setOutbound((string) $statusKey, 'woo_status', (string) $value);
        }
        foreach (($_POST['out_allegro'] ?? []) as $statusKey => $value) {
            $maps->setOutbound((string) $statusKey, 'allegro_status', (string) $value);
        }

        // kanał -> PASE
        $maps->saveInbound(StatusMapRepository::SETTING_IN_WOO, (array) ($_POST['in_woo'] ?? []));
        $maps->saveInbound(StatusMapRepository::SETTING_IN_ALLEGRO, (array) ($_POST['in_allegro'] ?? []));

        // Mapowania są zapamiętywane w pamięci procesu - po zapisie trzeba je odświeżyć.
        OrderStatus::forgetCustomMaps();
        $flashOk = 'Zapisano mapowanie statusów.';
    }

    // PRG: komunikaty do sesji i 303 na GET-a, żeby F5 nie powtarzało operacji.
    if ($flashOk !== null) {
        flash($flashOk);
    }
    if ($flashErr !== null) {
        flash($flashErr, 'err');
    }
    redirectAfterPost();
}

$statuses = $repo->all();

// Aktualne mapowania do wypełnienia formularza.
$maps = new StatusMapRepository($pdo);
$outWoo     = $maps->outbound('woo_status');
$outAllegro = $maps->outbound('allegro_status');
$inWoo      = $maps->inbound(StatusMapRepository::SETTING_IN_WOO);
$inAllegro  = $maps->inbound(StatusMapRepository::SETTING_IN_ALLEGRO);

// Statusy PASE do selektorów w kierunku "kanał -> PASE".
$paseOptions = [];
foreach ($statuses as $s) {
    $paseOptions[(string) $s['status_key']] = (string) $s['label'];
}

/** Co system zrobi domyślnie, gdy nic nie zmapowano - pokazujemy to przy selektorach. */
$defaultOut = static function (string $statusKey, string $channel): string {
    $value = $channel === 'woo' ? OrderStatus::toWoo($statusKey) : OrderStatus::toAllegro($statusKey);
    return $value ?? 'nic nie wysyła';
};

$PAGE_TITLE = 'Statusy zamówień';
$PAGE_KEY   = 'statuses';
require __DIR__ . '/header.php';
?>

<?php // Komunikaty renderuje header.php (flash()/flashTake() w auth.php). ?>

<div class="card">
    <strong>Dodaj własny status</strong>
    <form method="post" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;margin-top:10px">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="create">
        <div><label style="font-size:12px;color:#888;display:block">Nazwa</label>
            <input name="label" required placeholder="np. Reklamacja" style="width:220px"></div>
        <div><label style="font-size:12px;color:#888;display:block">Kolor</label>
            <input name="color" type="color" value="#9b59b6" style="width:60px;height:38px;padding:2px"></div>
        <div><label style="font-size:12px;color:#888;display:block">Kolejność</label>
            <input name="position" type="number" value="100" style="width:90px"></div>
        <button class="btn" type="submit">Dodaj status</button>
    </form>
</div>

<div class="card">
    <strong>Wszystkie statusy</strong>
    <table style="margin-top:10px">
        <tr><th>Podgląd</th><th>Nazwa</th><th>Kolor</th><th>Kolejność</th><th>Typ</th><th></th></tr>
        <?php foreach ($statuses as $s): ?>
            <tr>
                <form method="post">
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <td>
                    <span style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;color:#fff;background:<?= htmlspecialchars($s['color']) ?>"><?= htmlspecialchars($s['label']) ?></span>
                </td>
                <td><input name="label" value="<?= htmlspecialchars($s['label']) ?>" style="width:180px"></td>
                <td><input name="color" type="color" value="<?= htmlspecialchars($s['color']) ?>" style="width:54px;height:34px;padding:2px"></td>
                <td><input name="position" type="number" value="<?= (int)$s['position'] ?>" style="width:80px"></td>
                <td><?= $s['is_system'] ? '<span class="pill muted">systemowy</span>' : '<span class="pill ok">własny</span>' ?></td>
                <td style="white-space:nowrap;text-align:right">
                    <button class="btn secondary" type="submit">Zapisz</button>
                </form>
                    <?php if (!$s['is_system']): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Usunąć status „<?= htmlspecialchars($s['label']) ?>”?')">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <button class="btn danger" type="submit">Usuń</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    <p style="color:#888;font-size:12px;margin-top:10px">
        Statusy <strong>systemowe</strong> (Nowe, W realizacji, Wysłane, Anulowane, Zwrot) można przemianować
        i przekolorować, ale nie da się ich usunąć. Powiązanie z kanałami sprzedaży ustawiasz poniżej —
        dotyczy tak samo statusów systemowych, jak i własnych.
    </p>
</div>

<!-- Mapowanie statusów na kanały sprzedaży -->
<form method="post">
<input type="hidden" name="csrf" value="<?= csrfToken() ?>">
<input type="hidden" name="action" value="save_maps">

<div class="card">
    <strong>Gdy zmieniasz status w CRM → co wysłać do kanału</strong>
    <p style="color:#888;font-size:13px;margin:6px 0 12px">
        Dla każdego swojego statusu wybierz, na co ma się przełożyć w sklepie i na Allegro.
        „— nie wysyłaj —” zostawia zmianę tylko w CRM. Przy pustym wyborze zadziała wartość
        domyślna, wypisana szarym pod selektorem.
    </p>

    <table>
        <tr><th>Status w CRM</th><th>WooCommerce</th><th>Allegro</th></tr>
        <?php foreach ($statuses as $s): $key = (string) $s['status_key']; ?>
            <tr>
                <td>
                    <span style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;color:#fff;background:<?= htmlspecialchars($s['color']) ?>"><?= htmlspecialchars($s['label']) ?></span>
                    <div style="color:#aaa;font-size:11px;margin-top:3px"><?= htmlspecialchars($key) ?></div>
                </td>
                <td>
                    <select name="out_woo[<?= htmlspecialchars($key) ?>]" style="min-width:230px">
                        <option value="">— nie wysyłaj —</option>
                        <?php foreach (OrderStatus::WOO_STATUSES as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value) ?>" <?= ($outWoo[$key] ?? null) === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!array_key_exists($key, $outWoo)): ?>
                        <div style="color:#aaa;font-size:11px;margin-top:3px">domyślnie: <?= htmlspecialchars($defaultOut($key, 'woo')) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <select name="out_allegro[<?= htmlspecialchars($key) ?>]" style="min-width:250px">
                        <option value="">— nie wysyłaj —</option>
                        <?php foreach (OrderStatus::ALLEGRO_STATUSES as $value => $label): ?>
                            <?php if (in_array($value, OrderStatus::ALLEGRO_STATUSES_NOT_SENDABLE, true)) { continue; } ?>
                            <option value="<?= htmlspecialchars($value) ?>" <?= ($outAllegro[$key] ?? null) === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!array_key_exists($key, $outAllegro)): ?>
                        <div style="color:#aaa;font-size:11px;margin-top:3px">domyślnie: <?= htmlspecialchars($defaultOut($key, 'allegro')) ?></div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    <p style="color:#888;font-size:12px;margin-top:10px">
        Statusu <code>NEW</code> nie ma na liście Allegro — ustawia go samo Allegro w chwili zakupu.
        Zwrotów Allegro też nie przyjmuje tą drogą; obsługuje je osobnym procesem reklamacji.
    </p>
</div>

<div class="card">
    <strong>Gdy zamówienie przychodzi z kanału → jaki status nadać w CRM</strong>
    <p style="color:#888;font-size:13px;margin:6px 0 12px">
        Kilka statusów kanału może prowadzić do jednego statusu CRM — i odwrotnie niż wyżej,
        to tu decydujesz, czy „gotowe do wysyłki” z Allegro ma trafiać do Twojego „do wysłania”,
        czy wpadać razem z resztą do „w realizacji”.
    </p>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:24px">
        <div>
            <p style="font-weight:600;font-size:13px;margin:0 0 8px">Z WooCommerce</p>
            <table>
                <tr><th>Status w sklepie</th><th>Status w CRM</th></tr>
                <?php foreach (OrderStatus::WOO_STATUSES as $value => $label): ?>
                    <tr>
                        <td style="font-size:13px"><?= htmlspecialchars($label) ?></td>
                        <td>
                            <select name="in_woo[<?= htmlspecialchars($value) ?>]" style="min-width:170px">
                                <option value="">— domyślnie (<?= htmlspecialchars(OrderStatus::label(OrderStatus::fromWoo($value))) ?>) —</option>
                                <?php foreach ($paseOptions as $paseKey => $paseLabel): ?>
                                    <option value="<?= htmlspecialchars($paseKey) ?>" <?= ($inWoo[$value] ?? null) === $paseKey ? 'selected' : '' ?>><?= htmlspecialchars($paseLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div>
            <p style="font-weight:600;font-size:13px;margin:0 0 8px">Z Allegro</p>
            <table>
                <tr><th>Status realizacji</th><th>Status w CRM</th></tr>
                <?php foreach (OrderStatus::ALLEGRO_STATUSES as $value => $label): $lower = strtolower($value); ?>
                    <tr>
                        <td style="font-size:13px"><?= htmlspecialchars($label) ?></td>
                        <td>
                            <select name="in_allegro[<?= htmlspecialchars($lower) ?>]" style="min-width:170px">
                                <option value="">— domyślnie (<?= htmlspecialchars(OrderStatus::label(OrderStatus::fromAllegro($value))) ?>) —</option>
                                <?php foreach ($paseOptions as $paseKey => $paseLabel): ?>
                                    <option value="<?= htmlspecialchars($paseKey) ?>" <?= ($inAllegro[$lower] ?? null) === $paseKey ? 'selected' : '' ?>><?= htmlspecialchars($paseLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <p style="margin-top:16px"><button class="btn" type="submit">Zapisz mapowanie statusów</button></p>
</div>
</form>

<?php require __DIR__ . '/footer.php'; ?>
