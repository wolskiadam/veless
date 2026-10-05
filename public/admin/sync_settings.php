<?php
declare(strict_types=1);

/**
 * Ustawienia synchronizacji cyklicznej (Konfiguracja → Synchronizacja).
 * Interwały (w minutach) dla 4 przepływów; 0 = wyłączone. Realizuje je worker
 * (Scheduler) przy każdym przebiegu crona. Zapis w tabeli settings (SYNC_*_EVERY).
 */

use Pase\Repository\SettingsRepository;
use Pase\Services\TaskTimings;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$settings = new SettingsRepository($pdo);

// Definicje pól: klucz interwału => [etykieta, opis, znacznik ostatniego uruchomienia].
$flows = [
    'SYNC_ORDERS_EVERY'   => ['Pobieranie nowych zamówień', 'Zaciąga nowe zamówienia ze WSZYSTKICH kanałów naraz — ze sklepów WooCommerce i z Allegro.', 'SYNC_ORDERS_AT'],
    'SYNC_STOCK_EVERY'    => ['Synchronizacja stanów', 'Wypycha stany CRM → sklep (gdy CRM jest masterem stanów).', 'SYNC_STOCK_AT'],
    'SYNC_PRICE_EVERY'    => ['Synchronizacja cen', 'Wypycha ceny CRM → sklep (regular_price).', 'SYNC_PRICE_AT'],
    'SYNC_PRODUCTS_EVERY' => ['Auto-import produktów', 'Cyklicznie odświeża katalog produktów ze sklepu.', 'SYNC_PRODUCTS_AT'],
    'SYNC_TRACKING_EVERY' => ['Śledzenie przesyłek', 'Sprawdza u przewoźników (przez API Allegro) etap nadanych paczek: w drodze, w doręczeniu, doręczona… Zmiana etapu uruchamia automatyzacje „Zmieniono status przesyłki u kuriera".', 'SYNC_TRACKING_AT'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $values = [];
    if (($_POST['section'] ?? '') === 'warehouse') {
        $on = !empty($_POST['actual_stock']);
        \Pase\Services\ActualStock::setEnabled($pdo, $on);
        flash($on ? 'Pole „Stan faktyczny” włączone (Magazyn → Produkty i podgląd produktu).'
                  : 'Pole „Stan faktyczny” wyłączone. Wpisane liczby zostają - wrócą po ponownym włączeniu.');
        redirectAfterPost();
    }
    if (($_POST['section'] ?? '') === 'timings') {
        // Pozostałe zadania w tle - wartości przycinane do bezpiecznych zakresów (TaskTimings).
        $adjusted = [];
        foreach (TaskTimings::DEFS as $key => $def) {
            if (!isset($_POST[$key])) { continue; }
            $clean = TaskTimings::sanitize($key, trim((string) $_POST[$key]));
            if ((string) $clean !== trim((string) $_POST[$key])) { $adjusted[] = $def[0] . ': ' . $clean . ' ' . $def[2]; }
            $values[$key] = (string) $clean;
        }
        $settings->setMany($values);
        flash('Zapisano czasy zadań w tle.' . ($adjusted ? ' Poprawiono do dozwolonego zakresu - ' . implode('; ', $adjusted) . '.' : ''));
        redirectAfterPost();
    }
    foreach ($flows as $key => $_def) {
        $values[$key] = (string) max(0, (int) ($_POST[$key] ?? 0));
    }
    $settings->setMany($values);

    // PRG: komunikat do sesji + 303 na GET-a, żeby F5 nie powtarzało zapisu.
    flash('Zapisano ustawienia synchronizacji.');
    redirectAfterPost();
}

$s = $settings->all();

$PAGE_TITLE = 'Synchronizacja';
$PAGE_KEY   = 'sync_settings';
require __DIR__ . '/header.php';

/** Czytelny czas ostatniego uruchomienia z unix-timestampa. */
function lastRunLabel(array $s, string $atKey): string
{
    $ts = (int) ($s[$atKey] ?? 0);
    if ($ts <= 0) {
        return 'jeszcze nie uruchomiono';
    }
    $mins = (int) floor((time() - $ts) / 60);
    return $mins <= 0 ? 'przed chwilą' : "$mins min temu";
}

/**
 * Sygnał życia crona. Worker zapisuje WORKER_LAST_RUN_AT na początku KAŻDEGO
 * przebiegu, więc stary znacznik oznacza jedno: cron nie chodzi. To najczęstsza
 * przyczyna „nic się nie synchronizuje", a bez tego wskaźnika jest niewidoczna —
 * panel wygląda normalnie, po prostu nic się nie dzieje.
 *
 * @return array{state:'ok'|'late'|'never',label:string,mins:int}
 */
function workerHeartbeat(array $s): array
{
    $ts = (int) ($s['WORKER_LAST_RUN_AT'] ?? 0);
    if ($ts <= 0) {
        return ['state' => 'never', 'label' => 'nigdy', 'mins' => 0];
    }
    $mins = max(0, (int) floor((time() - $ts) / 60));
    return [
        // Worker ma chodzić co minutę; 5 minut ciszy to już awaria, a nie opóźnienie.
        'state' => $mins >= 5 ? 'late' : 'ok',
        'label' => $mins <= 0 ? 'przed chwilą' : "$mins min temu",
        'mins'  => $mins,
    ];
}

$hb = workerHeartbeat($s);
$hbColors = [
    'ok'    => ['#e8f5e9', '#2e7d32'],
    'late'  => ['#fdecea', '#a3341f'],
    'never' => ['#fdecea', '#a3341f'],
];
[$hbBg, $hbFg] = $hbColors[$hb['state']];
?>

<?php // Komunikaty po zapisie renderuje header.php (flash()/flashTake() w auth.php). ?>

<div class="card" style="background:<?= $hbBg ?>;border-left:4px solid <?= $hbFg ?>">
    <strong style="color:<?= $hbFg ?>">
        <?php if ($hb['state'] === 'ok'): ?>
            ✅ Cron działa — ostatni przebieg <?= htmlspecialchars($hb['label']) ?>
        <?php elseif ($hb['state'] === 'late'): ?>
            ⚠️ Cron milczy od <?= (int) $hb['mins'] ?> min
        <?php else: ?>
            ⚠️ Cron nie uruchomił się jeszcze ani razu
        <?php endif; ?>
    </strong>
    <p style="font-size:13px;margin:6px 0 0;color:#444">
        <?php if ($hb['state'] === 'ok'): ?>
            Worker odpala się cyklicznie i obsługuje wszystko: kolejkę zadań, pobieranie zamówień
            ze sklepów i z Allegro oraz odświeżanie tokenów Allegro.
        <?php else: ?>
            Dopóki worker nie chodzi, <strong>nic nie synchronizuje się samo</strong> — zamówienia nie
            przyjdą, stany nie pójdą do sklepu, a token Allegro wygaśnie (błędy 401). Sprawdź wpis
            w cronie hostingu:
            <code style="display:block;margin:8px 0;padding:8px;background:#fff;border-radius:6px;font-size:12px;overflow-x:auto">* * * * * /usr/local/bin/php <?= htmlspecialchars(PASE_ROOT) ?>/cli/worker.php &gt;&gt; <?= htmlspecialchars(PASE_ROOT) ?>/storage/cron.log 2&gt;&amp;1</code>
            Jeśli wpis istnieje, upewnij się, że katalog logu (<code>storage/</code>) naprawdę istnieje —
            przekierowanie do nieistniejącego katalogu sprawia, że cała linia crona nie wykonuje się wcale.
        <?php endif; ?>
    </p>
</div>

<div class="card">
    <strong>Synchronizacja cykliczna</strong>
    <p style="color:#888;font-size:13px;margin:6px 0 0">
        Ustaw, co ile minut CRM ma wykonywać każdy przepływ. <strong>0 = wyłączone.</strong>
        Zadania realizuje worker (cron co minutę) — faktyczna częstotliwość nie będzie mniejsza niż
        rozdzielczość crona. Webhook nadal pobiera nowe zamówienia natychmiast; pobieranie cykliczne
        jest zabezpieczeniem na wypadek, gdyby webhook czegoś nie dostarczył.
    </p>

    <form method="post" style="margin-top:16px">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <table>
            <tr><th>Przepływ</th><th style="width:160px">Co ile minut</th><th>Ostatnio</th></tr>
            <?php foreach ($flows as $key => [$label, $desc, $atKey]): $val = (int) ($s[$key] ?? (\Pase\Services\Scheduler::DEFAULT_EVERY[$key] ?? 0)); ?>
                <?php // id = kotwica z linków na stronie Obciążenie serwera (np. sync_settings.php#sync_orders_every). ?>
                <tr id="<?= strtolower($key) ?>" class="sync-row">
                    <td>
                        <strong><?= htmlspecialchars($label) ?></strong>
                        <div style="color:#888;font-size:12px;margin-top:2px"><?= htmlspecialchars($desc) ?></div>
                    </td>
                    <td>
                        <input type="number" name="<?= $key ?>" value="<?= $val ?>" min="0" step="1" style="width:90px">
                        <span style="color:#888;font-size:12px"><?= $val > 0 ? 'min' : '(wył.)' ?></span>
                    </td>
                    <td style="color:#888;font-size:12px"><?= $val > 0 ? htmlspecialchars(lastRunLabel($s, $atKey)) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p style="margin-top:16px"><button class="btn" type="submit">Zapisz</button></p>
    </form>
    <style>.sync-row { scroll-margin-top:90px; } .sync-row:target td { background:var(--accent-soft); }</style>
    <script>
    // Wejście z linku (#sync_..._every): kursor od razu w polu tego przepływu.
    // Po załadowaniu całej strony: wiersz może być niżej (np. „Pozostałe zadania w tle”), a przeglądarka
    // obsługuje skok do #kotwicy później i kasowałaby wcześniejszy fokus.
    window.addEventListener('load', function () {
        setTimeout(function () {
            var row = location.hash ? document.getElementById(location.hash.slice(1)) : null;
            var input = row && row.classList.contains('sync-row') ? row.querySelector('input[type=number]') : null;
            if (input) { input.focus(); input.select(); }
        }, 0);
    });
    </script>

    <p style="color:#888;font-size:12px;margin-top:6px">
        Wskazówka: zamówienia np. co <strong>5</strong> min, stany/ceny co <strong>15–30</strong> min,
        produkty co <strong>120</strong> min, śledzenie przesyłek co <strong>30–60</strong> min. Zbyt częste pobieranie zwiększa liczbę zapytań do sklepu.
    </p>
    <p style="color:#888;font-size:12px;margin-top:6px">
        Do działania całości wystarczy <strong>jeden wpis w cronie</strong> (worker co minutę) — obsługuje
        wszystkie kanały naraz i sam odświeża tokeny Allegro. Osobne wpisy dla
        <code>poll_allegro_orders.php</code> i <code>refresh_tokens.php</code> nie są już potrzebne;
        jeśli zostały ze starszej konfiguracji, nie przeszkadzają (nic się nie zaimportuje podwójnie).
    </p>
</div>

<div class="card" id="warehouse">
    <strong>Magazyn</strong>
    <form method="post" style="margin-top:12px">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="section" value="warehouse">
        <label style="display:inline-flex;gap:8px;align-items:center">
            <input type="checkbox" name="actual_stock" value="1" <?= \Pase\Services\ActualStock::enabled($pdo) ? 'checked' : '' ?>>
            <strong>Pole „Stan faktyczny”</strong>
        </label>
        <div style="color:#888;font-size:12px;margin:4px 0 0 26px">Drugi stan obok „Stan (CRM)”, tylko w CRM (nie idzie do sklepu ani na Allegro): ile naprawdę masz na półce, gdy stan w sklepie jest celowo wyższy.
            Włączone: pole na liście produktów i w podglądzie produktu, alerty niskiego stanu liczą z niego. Wyłączone: pole ukryte, liczy się tylko „Stan (CRM)”; wpisane liczby zostają.</div>
        <p style="margin-top:12px"><button class="btn" type="submit">Zapisz</button></p>
    </form>
</div>

<div class="card">
    <strong>Pozostałe zadania w tle</strong>
    <p style="color:var(--ink-2);font-size:13px;margin:6px 0 0">Czasy zadań, które nie są synchronizacją ze sklepem. Każde pole ma bezpieczny zakres — wartość spoza niego zostanie poprawiona przy zapisie.
        Wstrzymać dowolne zadanie na chwilę możesz w <a href="server_usage.php#processes">System → Obciążenie serwera</a>.</p>
    <form method="post" style="margin-top:16px">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="section" value="timings">
        <table>
            <tr><th>Zadanie</th><th style="width:190px">Wartość</th><th>Ostatnio</th></tr>
            <?php foreach (TaskTimings::DEFS as $key => [$label, $desc, $unit, $default, $min, $max, $zeroOff, $atKey]): $val = TaskTimings::get($settings, $key); ?>
                <tr id="<?= strtolower($key) ?>" class="sync-row">
                    <td>
                        <strong><?= htmlspecialchars($label) ?></strong>
                        <div style="color:#888;font-size:12px;margin-top:2px"><?= htmlspecialchars($desc) ?></div>
                    </td>
                    <td>
                        <input type="number" name="<?= $key ?>" value="<?= $val ?>" min="<?= $zeroOff ? 0 : $min ?>" max="<?= $max ?>" step="1" style="width:90px">
                        <span style="color:#888;font-size:12px"><?= $unit ?><?= $zeroOff && $val === 0 ? ' (wył.)' : '' ?></span>
                        <div style="color:#aaa;font-size:11px;margin-top:2px"><?= $zeroOff ? '0 = wył., ' : '' ?><?= $min ?>–<?= $max ?> <?= $unit ?> · domyślnie <?= $default ?></div>
                    </td>
                    <td style="color:#888;font-size:12px"><?= $atKey !== null ? htmlspecialchars(lastRunLabel($s, $atKey)) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p style="margin-top:16px"><button class="btn" type="submit">Zapisz</button></p>
    </form>
</div>

<?php require __DIR__ . '/footer.php'; ?>
