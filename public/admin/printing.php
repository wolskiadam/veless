<?php
declare(strict_types=1);

/**
 * Ustawienia drukowania (globalne) - zapisywane w tabeli settings (klucze PRINT_*).
 * Sekcje: format etykiety, domyślna paczka, opcje druku, integracja druku (PrintNode).
 */

use Pase\Repository\PrintJobRepository;
use Pase\Repository\SettingsRepository;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$settings = new SettingsRepository($pdo);
$flashOk = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = $_POST['action'] ?? 'save_settings';

    if ($action === 'regenerate_agent_key') {
        $settings->setMany(['PRINT_AGENT_API_KEY' => bin2hex(random_bytes(24))]);
        $flashOk = 'Wygenerowano nowy klucz API agenta. Wpisz go w konfiguracji programu na komputerze z drukarką.';
    } else {
        $settings->setMany([
            'PRINT_LABEL_FORMAT'   => $_POST['label_format'] ?? 'A4',
            'PRINT_DEF_WEIGHT'     => trim($_POST['def_weight'] ?? '1'),
            'PRINT_DEF_X'          => trim($_POST['def_x'] ?? '20'),
            'PRINT_DEF_Y'          => trim($_POST['def_y'] ?? '15'),
            'PRINT_DEF_Z'          => trim($_POST['def_z'] ?? '10'),
            'PRINT_AUTO_DIALOG'    => isset($_POST['auto_dialog']) ? '1' : '0',
            'PRINT_COPIES'         => (string) max(1, (int) ($_POST['copies'] ?? 1)),
            'PRINT_PRINTNODE_KEY'  => trim($_POST['printnode_key'] ?? ''),
            'PRINT_PRINTNODE_PRINTER' => trim($_POST['printnode_printer'] ?? ''),
        ]);
        $flashOk = 'Zapisano ustawienia drukowania.';
    }

    // PRG: komunikat idzie do sesji, a przeglądarka dostaje 303 na GET-a.
    // Dzięki temu F5 odświeża stronę, a nie powtarza zapisu.
    if ($flashOk !== null) {
        flash($flashOk);
    }
    redirectAfterPost();
}

$s = $settings->all();
$agentKey = $s['PRINT_AGENT_API_KEY'] ?? '';
$agentLastSeen = $s['PRINT_AGENT_LAST_SEEN'] ?? null;
$recentPrintJobs = [];
try {
    $recentPrintJobs = (new PrintJobRepository($pdo))->recent(10);
} catch (\Throwable $e) {
    $recentPrintJobs = [];
}
$fmt = $s['PRINT_LABEL_FORMAT'] ?? 'A4';
$formats = ['A4' => 'A4 (zwykła drukarka)', 'LBL' => 'Termiczna PDF (A6)', 'ZPL' => 'ZPL (Zebra)', 'EPL' => 'EPL (Zebra)'];

$PAGE_TITLE = 'Drukowanie';
$PAGE_KEY   = 'printing';
require __DIR__ . '/header.php';
?>

<?php // Komunikaty po zapisie renderuje header.php (flash()/flashTake() w auth.php). ?>

<form method="post">
    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">

    <!-- Format etykiety -->
    <div class="card">
        <strong>Domyślny format etykiety</strong>
        <p style="color:#888;font-size:13px;margin:6px 0 10px">Używany domyślnie przy nadawaniu i druku etykiet (można zmienić przy konkretnej przesyłce).</p>
        <select name="label_format" style="min-width:280px">
            <?php foreach ($formats as $val => $lbl): ?>
                <option value="<?= $val ?>" <?= $fmt === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Domyślna paczka -->
    <div class="card">
        <strong>Domyślne wymiary i waga paczki</strong>
        <p style="color:#888;font-size:13px;margin:6px 0 10px">Podstawiane w formularzu nadania, żeby nie wpisywać za każdym razem.</p>
        <div style="display:grid;grid-template-columns:repeat(4,140px);gap:10px">
            <div><label style="font-size:12px;color:#888;display:block">Waga (kg)</label>
                <input name="def_weight" type="number" step="0.1" value="<?= htmlspecialchars($s['PRINT_DEF_WEIGHT'] ?? '1') ?>" style="width:100%"></div>
            <div><label style="font-size:12px;color:#888;display:block">Dł. X (cm)</label>
                <input name="def_x" type="number" value="<?= htmlspecialchars($s['PRINT_DEF_X'] ?? '20') ?>" style="width:100%"></div>
            <div><label style="font-size:12px;color:#888;display:block">Szer. Y (cm)</label>
                <input name="def_y" type="number" value="<?= htmlspecialchars($s['PRINT_DEF_Y'] ?? '15') ?>" style="width:100%"></div>
            <div><label style="font-size:12px;color:#888;display:block">Wys. Z (cm)</label>
                <input name="def_z" type="number" value="<?= htmlspecialchars($s['PRINT_DEF_Z'] ?? '10') ?>" style="width:100%"></div>
        </div>
    </div>

    <!-- Opcje druku -->
    <div class="card">
        <strong>Opcje druku</strong>
        <label style="display:flex;align-items:center;gap:8px;margin-top:10px;cursor:pointer">
            <input type="checkbox" name="auto_dialog" <?= ($s['PRINT_AUTO_DIALOG'] ?? '1') === '1' ? 'checked' : '' ?>>
            <span>Automatycznie otwieraj okno druku po kliknięciu „Drukuj”</span>
        </label>
        <div style="margin-top:12px">
            <label style="font-size:12px;color:#888;display:block">Liczba kopii etykiety</label>
            <input name="copies" type="number" min="1" max="10" value="<?= (int) ($s['PRINT_COPIES'] ?? 1) ?>" style="width:90px">
        </div>
    </div>

    <!-- Integracja druku -->
    <div class="card">
        <strong>Integracja druku zdalnego (PrintNode)</strong>
        <p style="color:#888;font-size:13px;margin:6px 0 10px">
            Pozwala drukować na drukarce podłączonej do innego komputera (np. termicznej Zebra) z dowolnego miejsca.
            Wymaga zainstalowania klienta <a href="https://www.printnode.com" target="_blank" rel="noopener">PrintNode</a> na komputerze z drukarką.
            <strong>Funkcja w przygotowaniu</strong> — na razie zapisz klucz, podłączenie dodamy w kolejnym kroku.
        </p>
        <label style="font-size:12px;color:#888;display:block">Klucz API PrintNode</label>
        <input name="printnode_key" value="<?= htmlspecialchars($s['PRINT_PRINTNODE_KEY'] ?? '') ?>" placeholder="(opcjonalne, na przyszłość)" style="width:100%;max-width:420px">
        <label style="font-size:12px;color:#888;display:block;margin-top:10px">ID drukarki PrintNode</label>
        <input name="printnode_printer" value="<?= htmlspecialchars($s['PRINT_PRINTNODE_PRINTER'] ?? '') ?>" placeholder="(opcjonalne)" style="width:200px">
    </div>

    <p><button class="btn" type="submit">Zapisz ustawienia drukowania</button></p>
</form>

<?php
    $appBaseUrl = rtrim($settings->get('APP_BASE_URL', 'https://twojsklep.pl/pase/public'), '/');
    $lastSeenAgo = null;
    if ($agentLastSeen !== null) {
        $lastSeenAgo = (int) floor((time() - strtotime($agentLastSeen . ' UTC')) / 60);
    }
    // Bez pracy agent pyta rzadziej (Synchronizacja → Agent drukarki) - „aktywny” obejmuje ten odstęp.
    $okWindow = max(2, (int) ceil(\Pase\Services\TaskTimings::get($settings, 'PRINT_POLL_IDLE') / 60) + 1);
    $agentVersion  = $settings->get('PRINT_AGENT_VERSION');          // null = agent sprzed 1.1 (nie melduje wersji)
    $latestVersion = \Pase\Services\PrintAgentPacing::latestAgentVersion(PASE_ROOT);
    $printPaused   = (new \Pase\Services\ProcessControl($settings))->pausedUntil('print');
?>
<!-- Własny agent druku (USB/Zebra) -->
<div class="card">
    <strong>Własny agent druku (program na komputerze z drukarką Zebra)</strong>
    <p style="color:#888;font-size:13px;margin:6px 0 10px">
        Mały program instalowany na komputerze, do którego podłączona jest drukarka Zebra (USB).
        Odpytuje ten serwer o nowe etykiety i drukuje je od razu, bez ręcznego pobierania pliku —
        podobnie jak łącznik BaseLinkera. Przy przesyłce wybierz format ZPL/EPL i kliknij
        „🖨➜ Wyślij do drukarki”.
    </p>

    <?php if ($agentKey === ''): ?>
        <div class="flash err" style="margin-bottom:10px">Brak wygenerowanego klucza — agent nie będzie mógł się połączyć.</div>
    <?php else: ?>
        <p style="font-size:13px;margin:0 0 4px">
            Status agenta:
            <?php if ($lastSeenAgo === null): ?>
                <span class="pill muted">nigdy się nie łączył</span>
            <?php elseif ($lastSeenAgo <= $okWindow): ?>
                <span class="pill ok">aktywny (widziany <?= $lastSeenAgo <= 1 ? 'przed chwilą' : $lastSeenAgo . ' min temu' ?>)</span>
            <?php elseif ($lastSeenAgo <= 15): ?>
                <span class="pill warn">widziany <?= $lastSeenAgo ?> min temu</span>
            <?php else: ?>
                <span class="pill bad">nie odpytuje od <?= $lastSeenAgo ?> min — sprawdź, czy program działa</span>
            <?php endif; ?>
            <?php if ($printPaused !== null): ?>
                <span class="pill warn">⏸ wydruki wstrzymane — <a href="server_usage.php#processes">wznów</a></span>
            <?php endif; ?>
        </p>
        <?php if ($lastSeenAgo !== null): ?>
        <p style="font-size:13px;margin:4px 0 0">
            Wersja programu: <strong><?= $agentVersion !== null ? htmlspecialchars($agentVersion) : 'starsza niż 1.1' ?></strong>
            <?php if ($latestVersion !== null && ($agentVersion === null || version_compare($agentVersion, $latestVersion, '<'))): ?>
                <span class="pill warn">dostępna wersja <?= htmlspecialchars($latestVersion) ?> — pobierz poniżej i podmień plik <code>print_agent.py</code></span>
                <span style="display:block;color:#888;font-size:12px;margin-top:4px">Nowa wersja pyta serwer rzadko, gdy nikt nie pracuje (częstotliwość: <a href="sync_settings.php#print_poll_active">Synchronizacja</a>). Starsza pyta stale co 4 s.</span>
            <?php endif; ?>
        </p>
        <?php endif; ?>
    <?php endif; ?>

    <label style="font-size:12px;color:#888;display:block;margin-top:10px">Klucz API agenta</label>
    <input readonly value="<?= htmlspecialchars($agentKey) ?>" placeholder="(jeszcze nie wygenerowany)" style="width:100%;max-width:420px;font-family:var(--font-num)" onclick="this.select()">

    <form method="post" style="margin-top:8px" onsubmit="return confirm('Wygenerować nowy klucz? Trzeba będzie zaktualizować konfigurację programu na komputerze z drukarką.')">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="regenerate_agent_key">
        <button class="btn secondary" type="submit"><?= $agentKey === '' ? 'Wygeneruj klucz' : 'Wygeneruj nowy klucz' ?></button>
    </form>

    <!-- Veless - program do drukowania z okienkiem (Windows / Mac), budowany przez GitHub Actions -->
    <?php
        $agentBuilds = new \Pase\Services\AgentBuilds(PASE_ROOT);
        $connectionCode = $agentKey !== '' ? \Pase\Services\AgentBuilds::connectionCode($appBaseUrl, $agentKey) : '';
    ?>
    <div id="pase-agent" style="border-top:1px solid var(--line);margin-top:16px;padding-top:14px">
        <p style="font-size:13px;margin:0 0 6px"><strong>🖥 Veless — program do drukowania (Windows i Mac)</strong></p>
        <p style="color:#888;font-size:12px;margin:0 0 10px">
            Zwykły program z okienkiem: uruchamia się sam po zalogowaniu, siedzi jako ikona przy zegarze
            (na Macu na górnym pasku) i sam się aktualizuje. Nie trzeba instalować Pythona.
        </p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <?php foreach (\Pase\Services\AgentBuilds::PLATFORMS as $pf => $pfInfo): $b = $agentBuilds->build($pf); ?>
                <?php if ($b !== null): ?>
                    <a class="btn" href="agent_app_download.php?platform=<?= $pf ?>">⬇ <?= htmlspecialchars($pfInfo['label']) ?>
                        <span style="opacity:.75;font-size:11px">v<?= htmlspecialchars($b['version']) ?> · <?= number_format($b['size'] / 1048576, 1, ',', '') ?> MB</span></a>
                <?php else: ?>
                    <span class="btn secondary" style="opacity:.6;cursor:default" title="Program buduje się na GitHubie po wdrożeniu — odśwież stronę za kilka minut.">⏳ <?= htmlspecialchars($pfInfo['label']) ?> — jeszcze niegotowy</span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <label style="font-size:12px;color:#888;display:block;margin-top:12px">Kod połączenia (wklej w programie: Ustawienia → Kod połączenia)</label>
        <?php if ($connectionCode === ''): ?>
            <p style="font-size:12px;color:#c5221f;margin:4px 0 0">Najpierw wygeneruj klucz API powyżej.</p>
        <?php else: ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <input id="agent-connection-code" readonly value="<?= htmlspecialchars($connectionCode) ?>" style="flex:1;min-width:0;max-width:520px;font-family:var(--font-num);font-size:12px" onclick="this.select()">
                <button type="button" class="btn secondary" onclick="(function(b){var i=document.getElementById('agent-connection-code');i.select();(navigator.clipboard?navigator.clipboard.writeText(i.value):Promise.reject()).catch(function(){document.execCommand('copy')}).finally(function(){b.textContent='✓ Skopiowano';setTimeout(function(){b.textContent='📋 Kopiuj'},1500)})})(this)">📋 Kopiuj</button>
            </div>
            <p style="color:#888;font-size:12px;margin:6px 0 0">Kod zawiera adres panelu i klucz API — traktuj go jak hasło. Po wygenerowaniu nowego klucza kod też się zmienia.</p>
        <?php endif; ?>

        <details style="margin-top:10px">
            <summary style="cursor:pointer;font-size:13px">Jak zainstalować</summary>
            <div style="font-size:13px;margin-top:8px">
                <p style="margin:0 0 4px"><strong>Windows</strong></p>
                <ol style="margin:0 0 10px 18px;padding:0">
                    <li>Pobierz <code>Veless.exe</code> i przenieś go np. do folderu <code>Dokumenty\Veless</code>.</li>
                    <li>Uruchom. Przy pierwszym razie Windows może pokazać „System Windows ochronił ten komputer” —
                        kliknij <em>Więcej informacji → Uruchom mimo to</em> (program nie ma płatnego podpisu).</li>
                    <li>W oknie programu: <em>Ustawienia</em> → wklej kod połączenia → <em>Użyj kodu</em>, wybierz drukarki → <em>Zapisz</em>.</li>
                </ol>
                <p style="margin:0 0 4px"><strong>Mac</strong></p>
                <ol style="margin:0 0 10px 18px;padding:0">
                    <li>Pobierz zip, rozpakuj i przeciągnij <code>Veless</code> do folderu <em>Programy</em>.</li>
                    <li>Uruchom. macOS pokaże, że programu „nie można zweryfikować” — kliknij <em>Gotowe</em>
                        (nie „Przenieś do Kosza”; program nie ma płatnego podpisu Apple).</li>
                    <li>Otwórz <em>Ustawienia systemowe → Prywatność i ochrona</em>, przewiń na dół i przy „Veless”
                        kliknij <em>Otwórz mimo to</em>, potwierdź hasłem. Uruchom program jeszcze raz → <em>Otwórz mimo to</em>.
                        Robi się to tylko raz — kolejne aktualizacje otwierają się normalnie.</li>
                    <li>Ikona pojawi się na górnym pasku. Dalej jak na Windows: kod połączenia, drukarki, <em>Zapisz</em>.</li>
                </ol>
                <p style="color:#888;font-size:12px;margin:0">Wcześniejszy agent (skrypt poniżej) może zostać — ale wyłącz go, zanim uruchomisz nowy program,
                    żeby dwa programy nie pobierały tych samych wydruków. Nowy program sam przejmie ustawienia starego, jeśli znajdzie jego <code>config.json</code> obok siebie.
                    Zainstalowany wcześniej „CRM Agent” zaktualizuje się sam do „Veless” i zachowa ustawienia.</p>
            </div>
        </details>
    </div>

    <!-- Program do pobrania prosto z panelu -->
    <div style="border-top:1px solid var(--line);margin-top:16px;padding-top:14px">
        <p style="font-size:13px;margin:0 0 10px"><strong>Starsza wersja — skrypt Python (print_agent.py):</strong></p>

        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <a class="btn" href="agent_download.php?config=1">⬇ Pobierz agenta (gotowy do instalacji)</a>
            <a class="btn secondary" href="agent_download.php">Pobierz bez konfiguracji</a>
        </div>
        <p style="color:#888;font-size:12px;margin:8px 0 0">
            Pierwsza paczka ma już wpisany adres panelu i klucz API — przy instalacji wybierasz
            tylko drukarki. <strong>Zawiera klucz</strong>, więc nie wysyłaj jej dalej mailem.
            Druga to czysty program: adres i klucz trzeba wpisać ręcznie.
        </p>

        <details style="margin-top:14px">
            <summary style="cursor:pointer;font-size:13px;font-weight:600">Instrukcja instalacji (kliknij, aby rozwinąć)</summary>
            <div style="font-size:13px;line-height:1.65;padding:12px 0 0">
                <ol style="padding-left:20px;margin:0">
                    <li>Na komputerze z drukarką rozpakuj paczkę do <code>C:\PaseAgent</code>
                        (tak, żeby w środku był <code>print_agent.py</code>, a nie kolejny folder).</li>
                    <li>Upewnij się, że drukarka Zebra jest zainstalowana w Windows ze sterownikiem
                        producenta (Zebra Setup Utilities) i widać ją w Ustawienia → Drukarki i skanery.</li>
                    <li>Kliknij dwa razy w <code>install.bat</code>. Skrypt sprawdzi Pythona (w razie braku
                        zainstaluje go sam), dociągnie biblioteki, zapyta o drukarki, doda program do
                        autostartu i wydrukuje etykietę testową.</li>
                    <li>Gotowe — program startuje razem z Windows. Ręcznie uruchamia się go
                        dwuklikiem w <code>start_agent.bat</code>; okno musi zostać otwarte.</li>
                </ol>

                <p style="margin:14px 0 4px"><strong>Jeśli instalujesz bez konfiguracji</strong>, program zapyta o te dwie rzeczy:</p>
                <code style="display:block;background:#f1f3f4;padding:8px;border-radius:6px;font-size:12px;word-break:break-all">Adres CRM: <?= htmlspecialchars($appBaseUrl) ?></code>
                <code style="display:block;background:#f1f3f4;padding:8px;border-radius:6px;font-size:12px;margin-top:6px;word-break:break-all">Klucz API: <?= $agentKey === '' ? '(najpierw wygeneruj klucz powyżej)' : htmlspecialchars($agentKey) ?></code>

                <p style="margin:14px 0 4px"><strong>Co program potrafi:</strong></p>
                <ul style="padding-left:20px;margin:0">
                    <li>etykiety ZPL/EPL — wysyła na Zebrę bez zmian;</li>
                    <li>PDF-y i obrazki (JPG, PNG) — sam zamienia na grafikę dla Zebry;</li>
                    <li>dokumenty A4 (spis produktów, karta zamówienia) — drukuje na zwykłej drukarce,
                        jeśli wskażesz ją przy instalacji.</li>
                </ul>

                <p style="margin:14px 0 4px"><strong>Gdy coś nie działa:</strong></p>
                <ul style="padding-left:20px;margin:0">
                    <li>uruchom <code>diagnoza.bat</code> z folderu programu — zapisze obok plik
                        <code>diagnoza.txt</code> z pełnymi logami;</li>
                    <li>komunikat „nie znaleziono Python; uruchom bez argumentów…" to zaślepka
                        Microsoft Store — zainstaluj Pythona z python.org, zaznaczając
                        „Add python.exe to PATH";</li>
                    <li>status agenta widać wyżej na tej stronie — jeśli pokazuje „nie odpytuje od X minut",
                        program na stacji nie działa albo stracił połączenie;</li>
                    <li>pełny opis, razem z ustawieniami rozmiaru etykiety, jest w pliku
                        <code>README.md</code> w paczce.</li>
                </ul>
            </div>
        </details>
    </div>

    <p style="font-size:13px;margin-top:14px"><strong>Adresy do konfiguracji programu:</strong></p>
    <code style="display:block;background:#f1f3f4;padding:8px;border-radius:6px;font-size:12px;word-break:break-all">Odpytywanie: <?= htmlspecialchars($appBaseUrl) ?>/print_agent_poll.php</code>
    <code style="display:block;background:#f1f3f4;padding:8px;border-radius:6px;font-size:12px;margin-top:6px;word-break:break-all">Potwierdzenie: <?= htmlspecialchars($appBaseUrl) ?>/print_agent_ack.php</code>

    <?php if ($recentPrintJobs !== []): ?>
        <p style="font-size:13px;margin-top:16px"><strong>Ostatnie zadania druku:</strong></p>
        <table>
            <tr><th>#</th><th>Format</th><th>Drukarka</th><th>Plik</th><th>Status</th><th>Utworzono</th></tr>
            <?php foreach ($recentPrintJobs as $pj): ?>
                <tr>
                    <td><?= (int) $pj['id'] ?></td>
                    <td><?= htmlspecialchars($pj['format']) ?></td>
                    <td style="font-size:12px"><?= ($pj['target'] ?? 'zebra') === 'a4' ? 'A4' : 'Zebra' ?></td>
                    <td style="font-size:12px;color:#888"><?= htmlspecialchars((string) ($pj['filename'] ?? '—')) ?></td>
                    <td><span class="pill <?= $pj['status'] === 'done' ? 'ok' : ($pj['status'] === 'failed' ? 'bad' : 'muted') ?>"><?= htmlspecialchars($pj['status']) ?></span>
                        <?php if ($pj['status'] === 'failed' && !empty($pj['error'])): ?><span style="color:#c5221f;font-size:11px"><?= htmlspecialchars($pj['error']) ?></span><?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:#888"><?= htmlspecialchars($pj['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/footer.php'; ?>
