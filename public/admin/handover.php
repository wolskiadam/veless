<?php
declare(strict_types=1);

/**
 * System → Przeniesienie danych: przekazanie pracy między CRM online a CRM na komputerze
 * (Pase\Services\Handover). Online: kod połączenia. Komputer: „Pobierz z online” / „Wyślij na online”.
 * Zawsze pracuje jedna strona - druga jest wstrzymana (tylko podgląd). Tylko administrator.
 */

use Pase\Repository\SettingsRepository;
use Pase\Services\Handover;
use Pase\Support\AppMode;

require __DIR__ . '/auth.php';
requireRole(['admin']);
/** @var PDO $pdo */

$settingsRepo = new SettingsRepository($pdo);
$handover = new Handover($pdo, $settingsRepo, PASE_ROOT);
$local = AppMode::isLocal();
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$newCode = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    try {
        switch ((string) ($_POST['action'] ?? '')) {
            case 'code':
                $base = (string) $settingsRepo->get('APP_BASE_URL', '');
                if ($base === '') { throw new \RuntimeException('Najpierw ustaw adres panelu (Konfiguracja → Adres URL panelu).'); }
                $newCode = $handover->newPairingCode($base);
                break;
            case 'save_code':
                $handover->savePeerCode((string) ($_POST['code'] ?? ''));
                flash('Kod połączenia zapisany.');
                break;
            case 'pull':
                $handover->requestPull();
                flash('Zlecono pobranie danych z online. Postęp widać poniżej.');
                break;
            case 'push':
                $handover->requestPush();
                flash('Zlecono wysłanie danych na online. Ta instalacja jest już wstrzymana.');
                break;
            case 'resume':
                $handover->resumeHere();
                flash('Ta instalacja znowu pracuje. Upewnij się, że druga strona jest wstrzymana.');
                break;
            case 'clear':
                if (!$handover->busy()) { $handover->clearTask(); }
                break;
        }
    } catch (\Throwable $ex) {
        flash($ex->getMessage(), 'err');
    }
    if ($newCode === null) {
        header('Location: handover.php');
        exit;
    }
}

$away = $handover->away();
$task = $handover->task();
$busy = $handover->busy();
$fmt = static fn(int $ts): string => (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('d.m.Y H:i');
$taskLabels = ['pull' => 'Pobieranie danych z online', 'push' => 'Wysyłanie danych na online',
    'export' => 'Przekazanie danych na komputer', 'import' => 'Przyjmowanie danych z komputera', 'upload' => 'Przyjmowanie danych z komputera'];

$PAGE_TITLE = 'Przeniesienie danych';
$PAGE_KEY   = 'handover';
require __DIR__ . '/header.php';
?>
<?php if ($busy): ?><meta http-equiv="refresh" content="5"><?php endif; ?>

<div class="card">
    <strong>Ta instalacja: <?= $local ? 'CRM na komputerze' : 'CRM online' ?></strong>
    <p style="margin:8px 0 0;font-size:14px">
        <?php if ($away): ?>
            ⏸ <b>Wstrzymana</b> od <?= $e($fmt($away['since'])) ?> - pracuje <?= $away['to'] === 'local' ? 'CRM na komputerze' : 'CRM online' ?>.
            Tutaj nie przyjmujemy zmian, nie pobieramy zamówień i nie wysyłamy maili.
        <?php else: ?>
            ✅ <b>Pracuje</b> - to tutaj przychodzą zamówienia i stąd idą maile oraz synchronizacja.
        <?php endif; ?>
    </p>
    <p style="font-size:13px;color:#666;margin:10px 0 0">
        Zawsze pracuje tylko jedna strona, żeby to samo zamówienie nie zostało obsłużone dwa razy.
        Przeniesienie zabiera całą bazę i pliki (załączniki, dokumenty, grafiki) i zastępuje nimi dane po drugiej stronie.
        Kod CRM i plik <code>.env</code> zostają po każdej stronie swoje. Logujesz się potem tymi samymi kontami co po stronie, z której przyszły dane.
    </p>
    <p style="font-size:13px;margin:8px 0 0">
        Instrukcja: <a href="help.php?a=handover">Przeniesienie danych krok po kroku</a>
        · <a href="help.php?a=install-offline">Instalacja CRM na komputerze</a>
    </p>
</div>

<?php if ($task): ?>
<div class="card">
    <strong><?= $e($taskLabels[$task['type']] ?? 'Przekazanie') ?></strong>
    <p style="margin:8px 0 0;font-size:14px">
        <?php if ($task['status'] === 'failed'): ?>
            ❌ Nie udało się: <?= $e($task['error'] ?? '') ?>
        <?php elseif (in_array($task['status'], ['done', 'ready'], true)): ?>
            ✅ <?= $e($task['step'] ?? 'Gotowe') ?>
        <?php else: ?>
            ⏳ <?= $e($task['step'] ?? '…') ?> <span style="color:#888">(strona odświeża się sama; pracuje worker, więc chwilę to trwa)</span>
        <?php endif; ?>
    </p>
    <?php if (!$busy): ?>
        <form method="post" style="margin-top:8px"><input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <button class="btn secondary" name="action" value="clear">Ukryj</button></form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$local): ?>
<div class="card">
    <strong>Kod połączenia dla CRM na komputerze</strong>
    <p style="font-size:13px;color:#555;margin-top:8px">
        Komputer nie ma publicznego adresu, więc przeniesienie uruchamia się na komputerze:
        wygeneruj tu kod, wklej go w CRM na komputerze (System → Przeniesienie danych) i kliknij tam
        „Przenieś dane z online na ten komputer”. Ten CRM sam się wtedy wstrzyma.
    </p>
    <?php if ($newCode !== null): ?>
        <p style="font-size:13px;margin:10px 0 4px"><b>Skopiuj kod teraz</b> - nie pokażemy go drugi raz:</p>
        <code style="display:block;background:#f1f3f4;padding:10px;border-radius:6px;word-break:break-all"><?= $e($newCode) ?></code>
    <?php endif; ?>
    <form method="post" style="margin-top:10px"><input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <button class="btn<?= $handover->paired() ? ' secondary' : '' ?>" name="action" value="code"
            <?= $handover->paired() ? 'onclick="return confirm(\'Nowy kod unieważni poprzedni. Kontynuować?\')"' : '' ?>>
            <?= $handover->paired() ? 'Wygeneruj nowy kod' : 'Wygeneruj kod połączenia' ?></button>
    </form>
</div>
<?php else: ?>
<div class="card">
    <strong>Połączenie z CRM online</strong>
    <p style="font-size:13px;color:#555;margin-top:8px">Wklej kod z panelu online (System → Przeniesienie danych → Wygeneruj kod połączenia).
        <?php if ($handover->peerUrl() !== ''): ?><br>Połączono z: <code><?= $e($handover->peerUrl()) ?></code><?php endif; ?></p>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap"><input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="password" name="code" placeholder="CRM1:https://…" style="flex:1;min-width:260px" autocomplete="off">
        <button class="btn secondary" name="action" value="save_code">Zapisz kod</button>
    </form>
</div>

<?php if ($handover->peerUrl() !== ''): ?>
<div class="card">
    <strong>Przenieś pracę</strong>
    <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px"><input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <button class="btn" name="action" value="pull" <?= $busy ? 'disabled' : '' ?>
            onclick="return confirm('Online się wstrzyma, a dane na TYM komputerze zostaną zastąpione danymi z online. Kontynuować?')">
            ⬇️ Przenieś dane z online na ten komputer</button>
        <button class="btn" name="action" value="push" <?= $busy || $away ? 'disabled' : '' ?>
            onclick="return confirm('Ten komputer się wstrzyma, a dane ONLINE zostaną zastąpione danymi z tego komputera. Kontynuować?')">
            ⬆️ Wyślij dane z tego komputera na online</button>
    </form>
    <p style="font-size:12px;color:#888;margin-top:8px">
        Wysłać na online można tylko wtedy, gdy online jest wstrzymany (czyli wcześniej pobrałeś z niego dane na komputer) -
        inaczej nadpisałbyś nowsze zamówienia z online.
    </p>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($away && !$busy): ?>
<div class="card">
    <strong>Awaryjnie: wznów pracę tutaj</strong>
    <p style="font-size:13px;color:#555;margin-top:8px">Tylko gdy druga strona jest niedostępna na stałe (np. zepsuty komputer).
        Zmiany zrobione po drugiej stronie od <?= $e($fmt($away['since'])) ?> tu nie trafią, a jeśli druga strona dalej działa, obie będą obsługiwać te same zamówienia.</p>
    <form method="post"><input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <button class="btn danger" name="action" value="resume" onclick="return confirm('Na pewno wznowić pracę tutaj?')">Wznów pracę tutaj</button>
    </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
