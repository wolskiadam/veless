<?php
declare(strict_types=1);

/**
 * Allegro → Pobieranie zamówień: diagnostyka i ręczne wymuszenie.
 *
 * Powód istnienia: gdy zamówienia „nie przychodzą same", przyczyn jest kilka i z
 * panelu nie widać żadnej — cron może nie chodzić, przepływ może być wyłączony,
 * token może być wygasły, system może rozmawiać z sandboxem zamiast z produkcją,
 * a kursor zdarzeń może stać za zamówieniem, którego szukamy. Ta strona pokazuje
 * wszystkie te stany naraz i pozwala odpytać API na żywo, zamiast zgadywać z logów.
 *
 * „Sprawdź" nie zmienia niczego (podgląd bez skutków ubocznych). „Pobierz teraz"
 * robi dokładnie to, co robi cron — kolejkuje zamówienia i przesuwa kursor.
 */

use Pase\Queue\Queue;
use Pase\Repository\SettingsRepository;
use Pase\Services\AllegroOrderPoller;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$config    = require PASE_ROOT . '/config/config.php';
$allegroCfg = $config['allegro'] ?? [];
$settings  = new SettingsRepository($pdo);
$poller    = new AllegroOrderPoller($pdo, $settings, new Queue($pdo), $allegroCfg);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'reset_cursor') {
        $poller->resetCursor();
        flash('Kursor zdarzeń wyczyszczony. Kliknij „Pobierz teraz" — Allegro odda wszystkie zdarzenia z dostępnego okna.');
    } elseif ($action === 'poll') {
        $r = $poller->pollDetailed();
        if (!$r['ok']) {
            flash('Nie udało się pobrać zdarzeń: ' . $r['message'], 'err');
        } else {
            $known = max(0, $r['relevant'] - $r['queued']);
            $msg = "Zdarzeń z Allegro: {$r['events']}, z tego zamówień gotowych do realizacji: {$r['relevant']}. "
                 . "Nowych w kolejce: {$r['queued']}.";
            if (($r['revived'] ?? 0) > 0) {
                $msg .= " W tym {$r['revived']} ponownie — ich poprzednie zadanie padło.";
            }
            if ($r['cursorReset']) {
                $msg .= ' (Kursor był nieaktualny — pobrano od początku okna.)';
            }
            if ($r['events'] === 0) {
                $msg .= ' Allegro nie ma żadnych zdarzeń ZA KURSOREM — jeśli brakuje Ci konkretnego zamówienia, wyczyść kursor i spróbuj ponownie.';
            } elseif ($r['relevant'] === 0) {
                $msg .= ' Żadne z tych zdarzeń nie jest typu READY_FOR_PROCESSING (np. sama sprzedaż bez potwierdzonej płatności),'
                      . ' więc nie ma z czego zrobić zamówienia. Kliknij „Sprawdź, co widzi Allegro", żeby zobaczyć ich typy.';
            } elseif ($known > 0) {
                $msg .= " Pozostałe {$known} są już w kolejce (czekają albo zostały przetworzone) — stan każdego widać w tabeli „Co Allegro ma w oknie zdarzeń”.";
            }
            flash($msg);
        }
    }
    redirectAfterPost();
}

// --- Stan, bez odpytywania API ---
$integration = $poller->integration();
$connected   = $poller->isConnected();
$cursor      = $poller->cursor();
$s           = $settings->all();

$env          = (string) ($allegroCfg['env'] ?? 'sandbox');
$isProduction = $env === 'production';
$ordersEvery  = (int) ($s['SYNC_ORDERS_EVERY'] ?? 0);
$workerAt     = (int) ($s['WORKER_LAST_RUN_AT'] ?? 0);
$workerMins   = $workerAt > 0 ? max(0, (int) floor((time() - $workerAt) / 60)) : null;

$tokenExpires = (string) ($integration['expires_at'] ?? '');
$tokenLeft    = null;
if ($tokenExpires !== '') {
    // expires_at trzymamy w UTC (tak zapisuje IntegrationRepository::saveTokens).
    $tokenLeft = (int) floor((strtotime($tokenExpires . ' UTC') - time()) / 60);
}

// Czy Allegro istnieje jako KONTO integracji. To osobna rzecz od połączenia OAuth:
// tokeny siedzą w `integrations`, a przypisanie źródła do zamówienia bierze się
// z `integration_accounts`. Bez aktywnego konta zamówienia zapisują się bez źródła
// i nie da się ich odfiltrować jako „Allegro", choć w systemie są.
$hasAccount = false;
try {
    $hasAccount = (bool) $pdo->query(
        "SELECT 1 FROM integration_accounts WHERE type = 'allegro' AND is_active = 1 LIMIT 1"
    )->fetchColumn();
} catch (\PDOException $e) {
    $hasAccount = false;
}

// Ile zamówień z Allegro system w ogóle kiedykolwiek przyjął (order_logs to ślad
// importu, niezależny od tego, czy zamówienie ma przypisane źródło).
$allegroImported = 0;
$allegroLast     = null;
try {
    $row = $pdo->query(
        "SELECT COUNT(*) AS n, MAX(created_at) AS last_at FROM order_logs WHERE source_platform = 'allegro'"
    )->fetch(PDO::FETCH_ASSOC);
    $allegroImported = (int) ($row['n'] ?? 0);
    $allegroLast     = $row['last_at'] ?? null;
} catch (\PDOException $e) {
    // brak tabeli - zostaje 0
}

// --- Podgląd na żywo: tylko na wyraźne życzenie (?check=1), bo to zapytanie do API ---
$peek = isset($_GET['check']) ? $poller->peek(50, true) : null;

/**
 * Los konkretnego zamówienia Allegro w CRM, składany z dwóch źródeł:
 * `order_logs` (czy import się zaczął i czym się skończył) oraz `job_queue`
 * (czy zadanie czeka, czy padło i z jakim błędem).
 *
 * Bez tego użytkownik widzi tylko „0 nowych w kolejce" i musi sam przeszukiwać
 * kolejkę, żeby dowiedzieć się, czy zamówienie utknęło, padło, czy jest gotowe.
 *
 * @return array{text:string,color:string}
 */
$orderState = static function (string $type, string $orderId) use ($pdo): array {
    if ($orderId === '' || $type !== 'READY_FOR_PROCESSING') {
        return ['text' => '—', 'color' => '#aaa'];
    }

    try {
        $st = $pdo->prepare('SELECT internal_status, woo_order_id, last_error FROM order_logs
                             WHERE source_platform = ? AND source_order_id = ? LIMIT 1');
        $st->execute(['allegro', $orderId]);
        $log = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        $jq = $pdo->prepare('SELECT status, attempts, max_attempts, last_error FROM job_queue
                             WHERE dedup_key = ? LIMIT 1');
        $jq->execute(['allegro.order.new:' . $orderId]);
        $job = $jq->fetch(PDO::FETCH_ASSOC) ?: null;

        // Zamówienie z Allegro trafia do woo_orders z order_number = ID z Allegro.
        // Sprawdzamy to bezpośrednio - starsze importy nie zapisywały woo_order_id w order_logs.
        $wo = $pdo->prepare('SELECT woo_order_id, pase_number FROM woo_orders WHERE order_number = ? LIMIT 1');
        $wo->execute([$orderId]);
        $crmOrder = $wo->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\PDOException $e) {
        return ['text' => 'nie mogę sprawdzić', 'color' => '#aaa'];
    }

    if ($crmOrder !== null) {
        $no = $crmOrder['pase_number'] ?? null;
        return ['text' => '✅ w systemie' . ($no ? ' (nr ' . $no . ')' : ''), 'color' => '#2e7d32'];
    }
    if ($log !== null && !empty($log['woo_order_id'])) {
        return ['text' => '✅ w systemie (nr ' . $log['woo_order_id'] . ')', 'color' => '#2e7d32'];
    }
    if ($job !== null) {
        return match ((string) $job['status']) {
            'failed'   => ['text' => '❌ zadanie padło: ' . mb_substr((string) $job['last_error'], 0, 120), 'color' => '#a3341f'],
            'pending'  => ['text' => '⏳ czeka w kolejce (próba ' . (int) $job['attempts'] . '/' . (int) $job['max_attempts'] . ')', 'color' => '#9c6b2e'],
            'reserved' => ['text' => '⚙️ właśnie przetwarzane', 'color' => '#9c6b2e'],
            'done'     => ['text' => '✅ zadanie gotowe, ale brak zamówienia — sprawdź logi', 'color' => '#a3341f'],
            default    => ['text' => (string) $job['status'], 'color' => '#888'],
        };
    }
    if ($log !== null) {
        return ['text' => 'import: ' . (string) $log['internal_status'], 'color' => '#9c6b2e'];
    }
    return ['text' => '— nieznane w CRM', 'color' => '#a3341f'];
};

/**
 * Warunki, które MUSZĄ być spełnione, żeby zamówienia przychodziły same.
 * Każdy z osobna potrafi zatrzymać całość, dlatego pokazujemy je listą, a nie
 * jednym zbiorczym „działa / nie działa".
 *
 * @return array<int,array{ok:bool,label:string,detail:string}>
 */
$checks = [
    [
        'ok'     => $isProduction,
        'label'  => 'Środowisko Allegro',
        'detail' => $isProduction
            ? 'production — prawdziwe konto sprzedawcy.'
            : 'SANDBOX (środowisko testowe). Sandbox ma własne, puste konto — prawdziwe zamówienia '
              . 'nigdy się tu nie pojawią, a API będzie grzecznie odpowiadać „brak zdarzeń". '
              . 'Ustaw ALLEGRO_ENV=production w pliku .env i połącz konto od nowa.',
    ],
    [
        'ok'     => $workerMins !== null && $workerMins < 5,
        'label'  => 'Cron workera działa',
        'detail' => $workerMins === null
            ? 'Worker nie uruchomił się jeszcze ani razu — sprawdź wpis w cronie hostingu.'
            : ($workerMins < 5
                ? "Ostatni przebieg {$workerMins} min temu."
                : "Ostatni przebieg {$workerMins} min temu — cron prawdopodobnie nie chodzi."),
    ],
    [
        'ok'     => $ordersEvery > 0,
        'label'  => 'Przepływ „Pobieranie nowych zamówień" włączony',
        'detail' => $ordersEvery > 0
            ? "Co {$ordersEvery} min."
            : 'Ustawione na 0 (wyłączone) — Allegro nie będzie pobierane. Zmień w Konfiguracja → Synchronizacja.',
    ],
    [
        'ok'     => $connected,
        'label'  => 'Konto Allegro połączone (OAuth)',
        'detail' => !$connected
            ? 'Brak tokenu w bazie — połącz konto przez Integracje → Allegro.'
            : ($tokenLeft === null
                ? 'Token zapisany (brak daty wygaśnięcia).'
                : ($tokenLeft > 0
                    ? "Token ważny jeszcze {$tokenLeft} min."
                    : 'Token WYGASŁ ' . abs($tokenLeft) . ' min temu — powinien odświeżyć się sam przy najbliższym przebiegu workera.')),
    ],
    [
        'ok'     => $hasAccount,
        'label'  => 'Allegro jako konto integracji',
        'detail' => $hasAccount
            ? 'Jest — nowe zamówienia dostaną źródło „Allegro".'
            : 'BRAK aktywnego konta typu Allegro w Integracjach. Zamówienia i tak się zaimportują, ale '
              . 'zapiszą się BEZ przypisanego źródła — na liście będą widoczne, lecz filtr „Allegro" ich nie pokaże. '
              . 'Dodaj je w Integracje → Dodaj integrację → Allegro.',
    ],
];

$PAGE_TITLE = 'Allegro — pobieranie zamówień';
$PAGE_KEY   = 'allegro_sync';
require __DIR__ . '/header.php';

$e = static fn(?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>

<div class="card">
    <strong>Czy zamówienia będą przychodzić same?</strong>
    <p style="color:#888;font-size:13px;margin:6px 0 14px">
        Wszystkie punkty muszą być spełnione. Każdy z osobna potrafi zatrzymać pobieranie,
        i to po cichu — panel wygląda wtedy normalnie, po prostu nic nie przychodzi.
    </p>

    <table>
        <?php foreach ($checks as $c): ?>
            <tr>
                <td style="width:30px;font-size:16px;vertical-align:top;padding-top:10px"><?= $c['ok'] ? '✅' : '⚠️' ?></td>
                <td>
                    <strong><?= $e($c['label']) ?></strong>
                    <div style="color:#888;font-size:12px;margin-top:2px"><?= $e($c['detail']) ?></div>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>

    <p style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <a class="btn secondary" href="allegro_sync.php?check=1#podglad">🔍 Sprawdź, co widzi Allegro</a>
        <form method="post" style="display:inline">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="poll">
            <button class="btn" type="submit">⬇️ Pobierz teraz</button>
        </form>
    </p>
    <p style="color:#888;font-size:12px;margin-top:4px">
        „Sprawdź" pokazuje WSZYSTKIE zdarzenia z dostępnego okna Allegro, pomijając kursor —
        niczego nie zmienia i nie przesuwa. „Pobierz teraz" robi dokładnie to, co robi cron:
        kolejkuje nowe zamówienia od kursora i przesuwa go dalej.
    </p>
</div>

<?php if ($peek !== null): ?>
    <div class="card" id="podglad">
        <strong>Co Allegro ma w oknie zdarzeń (podgląd, bez zmian w systemie)</strong>
        <?php if (!$peek['ok']): ?>
            <div class="flash err" style="margin-top:10px">
                <?= $e($peek['message']) ?>
                <?php if ($peek['status'] === 401): ?>
                    <div style="margin-top:8px">
                        401 oznacza nieważny token. Jeśli po kilku minutach pracy crona błąd nie zniknie,
                        wygasł też <code>refresh_token</code> — wtedy trzeba połączyć konto od nowa
                        (Integracje → Allegro).
                    </div>
                <?php endif; ?>
            </div>
        <?php elseif ($peek['events'] === []): ?>
            <p style="color:#888;font-size:13px;margin-top:10px">
                Allegro odpowiedziało poprawnie (kod <?= (int) $peek['status'] ?>), ale w całym dostępnym
                oknie nie ma ani jednego zdarzenia. Jeśli zamówienie na pewno istnieje, pobierz je przez
                <a href="import_orders.php?source=allegro">Import zamówień</a>, który nie korzysta ze zdarzeń,
                tylko pyta wprost o zamówienia z danego zakresu dat.
            </p>
        <?php else: ?>
            <p style="color:#888;font-size:13px;margin-top:10px">
                Zdarzeń w całym oknie: <strong><?= count($peek['events']) ?></strong>.
                Na zamówienie w CRM zamieniamy tylko <code>READY_FOR_PROCESSING</code>.
                Kolumna „Stan w CRM" pokazuje, co się z takim zamówieniem stało u nas.
            </p>
            <table style="margin-top:8px">
                <tr><th>Typ zdarzenia</th><th>Zamówienie</th><th>Kiedy</th><th>Stan w CRM</th></tr>
                <?php foreach (array_slice($peek['events'], 0, 30) as $ev):
                    $evType = (string) ($ev['type'] ?? '?');
                    $evOid  = (string) ($ev['order']['checkoutForm']['id'] ?? '');
                    $state  = $orderState($evType, $evOid); ?>
                    <tr>
                        <td><?= $e($evType) ?></td>
                        <td style="font-size:12px;word-break:break-all"><?= $e($evOid !== '' ? $evOid : '—') ?></td>
                        <td style="color:#888;font-size:12px"><?= $e((string) ($ev['occurredAt'] ?? '')) ?></td>
                        <td style="font-size:12px;color:<?= $e($state['color']) ?>"><?= $e($state['text']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card">
    <strong>Kursor zdarzeń</strong>
    <p style="color:#888;font-size:13px;margin:6px 0 10px">
        Allegro nie udostępnia listy „wszystko od daty X" — udostępnia strumień zdarzeń, a my pamiętamy,
        na którym skończyliśmy. Kursor idzie tylko do przodu, więc jeśli stoi już <em>za</em> Twoim
        zamówieniem, kolejne pobrania będą zwracać <strong>0 zdarzeń</strong> — i to niezależnie od tego,
        czy zamówienie faktycznie trafiło do systemu.
        <br><br>
        Wyczyszczenie kursora każe Allegro oddać wszystko z dostępnego okna. Jest bezpieczne:
        zamówienia już zaimportowane zostaną pominięte dzięki kluczom dedup.
    </p>
    <p style="font-size:12px;color:#888;word-break:break-all;margin-bottom:12px">
        Aktualna pozycja: <?= $cursor === '' ? '<em>pusty (start od początku okna)</em>' : '<code>' . $e($cursor) . '</code>' ?>
    </p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="reset_cursor">
        <button class="btn secondary" type="submit"<?= $cursor === '' ? ' disabled' : '' ?>>♻️ Wyczyść kursor i zacznij od nowa</button>
    </form>
</div>

<div class="card">
    <strong>Ślad importu</strong>
    <p style="color:#888;font-size:13px;margin:6px 0 0">
        <?php if ($allegroImported > 0): ?>
            System przyjął dotąd <strong><?= $allegroImported ?></strong> zamówień z Allegro,
            ostatnie: <strong><?= $e((string) $allegroLast) ?></strong> (UTC).
            To znaczy, że sama ścieżka importu działa.
        <?php else: ?>
            System <strong>nigdy</strong> nie przyjął żadnego zamówienia z Allegro. Jeśli powyższe punkty
            są na zielono, a to nadal zero — najpewniej rozmawiamy z innym kontem niż myślisz
            (patrz „Środowisko Allegro") albo zdarzenia nigdy nie dotarły.
        <?php endif; ?>
    </p>
</div>



<div class="card">
    <strong>Najpewniejsza droga: import po dacie</strong>
    <p style="color:#888;font-size:13px;margin:6px 0 12px">
        Import nie korzysta ze strumienia zdarzeń ani z kursora — pyta Allegro wprost o zamówienia
        z wybranego zakresu. Jeśli zamówienie istnieje na koncie, tą drogą przyjdzie.
    </p>
    <a class="btn secondary" href="import_orders.php?source=allegro">📦 Import zamówień z Allegro</a>
</div>

<?php require __DIR__ . '/footer.php'; ?>
