<?php
declare(strict_types=1);

/**
 * Podgląd kolejki job_queue + ręczne ponowienie zadań, które padły (failed).
 * "Ponów" przestawia zadanie z powrotem na pending i zeruje available_at,
 * więc najbliższy przebieg workera weźmie je ponownie.
 */

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

$canEdit  = canEdit();
$flashOk  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    csrfCheck();
    $action = $_POST['action'] ?? '';
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'retry' && $id > 0) {
        $pdo->prepare(
            "UPDATE job_queue
             SET status='pending', attempts=0, reserved_at=NULL,
                 available_at=UTC_TIMESTAMP(), last_error=NULL
             WHERE id=?"
        )->execute([$id]);
        $flashOk = "Zadanie #{$id} zostało ustawione do ponowienia.";
    } elseif ($action === 'delete' && $id > 0) {
        $pdo->prepare('DELETE FROM job_queue WHERE id=?')->execute([$id]);
        $flashOk = "Zadanie #{$id} usunięte.";
    } elseif ($action === 'delete_selected') {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) ($_POST['ids'] ?? [])),
            static fn(int $selectedId): bool => $selectedId > 0
        )));

        if ($ids === []) {
            $flashOk = 'Nie zaznaczono żadnych zadań do usunięcia.';
        } else {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM job_queue WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $deleted = $stmt->rowCount();
            $flashOk = "Usunięto {$deleted} zaznaczonych zadań.";
        }
    } elseif ($action === 'retry_all_failed') {
        $n = $pdo->exec(
            "UPDATE job_queue
             SET status='pending', attempts=0, reserved_at=NULL,
                 available_at=UTC_TIMESTAMP(), last_error=NULL
             WHERE status='failed'"
        );
        $flashOk = "Ponowiono {$n} zadań ze statusem „Błąd”.";
    } elseif ($action === 'reset_allegro_order') {
        $allegroOrderId = trim($_POST['allegro_order_id'] ?? '');
        if ($allegroOrderId === '') {
            $flashOk = 'Podaj ID zamówienia Allegro.';
        } else {
            // Dwie osobne blokady przed duplikatami trzeba wyzerować: order_logs
            // (pamięta "już przetworzone", niezależnie od tego co się stało dalej z
            // zamówieniem w sklepie) i sam dedup_key w job_queue (inaczej ponowny
            // import zgłosi "dodano 0" - INSERT IGNORE pominie identyczny klucz).
            $pdo->prepare("DELETE FROM order_logs WHERE source_platform = 'allegro' AND source_order_id = ?")
                ->execute([$allegroOrderId]);
            $pdo->prepare('DELETE FROM job_queue WHERE dedup_key = ?')
                ->execute(["allegro.order.new:{$allegroOrderId}"]);
            $flashOk = "Zresetowano zamówienie Allegro {$allegroOrderId}. Pobierz je ponownie przez "
                . '„Zamówienia → Import → Allegro” albo poczekaj na najbliższy przebieg pollera, '
                . 'jeśli nadal jest w oknie zdarzeń.';
        }
    }
}

$statusFilter = $_GET['status'] ?? '';
$valid = ['pending','reserved','done','failed'];
$where  = in_array($statusFilter, $valid, true) ? ' WHERE status = ?' : '';
$params = $where !== '' ? [$statusFilter] : [];

// Paginacja + liczba na stronę (lista rozwijana jak w zamówieniach), zapamiętana dla użytkownika.
$perPageOptions = [25, 50, 75, 100, 150, 200];
$perPageKey  = 'QUEUE_PER_PAGE_' . (int) currentUserId();
$perPageRepo = new \Pase\Repository\SettingsRepository($pdo);
$perPage = (int) ($perPageRepo->get($perPageKey, '50') ?? '50');
if (isset($_GET['per_page']) && in_array((int) $_GET['per_page'], $perPageOptions, true)) {
    if ((int) $_GET['per_page'] !== $perPage) {
        try { $perPageRepo->setMany([$perPageKey => (string) (int) $_GET['per_page']]); } catch (\Throwable) {}
    }
    $perPage = (int) $_GET['per_page'];
}
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 50;
}
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM job_queue' . $where);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$pages  = max(1, (int) ceil($totalRows / $perPage));
$page   = min(max(1, (int) ($_GET['p'] ?? 1)), $pages);
$offset = ($page - 1) * $perPage;
$stmt = $pdo->prepare('SELECT * FROM job_queue' . $where . ' ORDER BY id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$qs = $_GET;
$buildLink = static function (int $p) use ($qs): string {
    $qs['p'] = $p;
    return 'queue.php?' . http_build_query($qs);
};
$perPageLink = static function (int $n) use ($qs): string {
    $qs['per_page'] = $n;
    unset($qs['p']);
    return 'queue.php?' . http_build_query($qs);
};

$failedCount = (int) $pdo->query("SELECT COUNT(*) FROM job_queue WHERE status='failed'")->fetchColumn();

$pillClass = static fn(string $s): string => match ($s) {
    'done'     => 'ok',
    'failed'   => 'bad',
    'reserved' => 'warn',
    default    => 'muted',
};

// Polskie etykiety statusów (klucze w bazie pozostają angielskie).
$statusLabels = [
    'pending'  => 'Oczekuje',
    'reserved' => 'W trakcie',
    'done'     => 'Wykonane',
    'failed'   => 'Błąd',
];
$statusLabel = static fn(string $s): string => $statusLabels[$s] ?? $s;

// Ostatnie zamówienia z Allegro w wewnętrznym dzienniku - do wyboru przy resecie,
// żeby nie trzeba było znać/szukać ID zamówienia z Allegro.
$allegroLogLabels = [
    'received'    => 'Odebrane',
    'processing'  => 'Przetwarzane',
    'woo_created' => 'Zamówienie utworzone',
    'invoiced'    => 'Zafakturowane',
    'completed'   => 'Ukończone',
    'failed'      => 'Błąd',
];
$allegroLogs = [];
try {
    $allegroLogs = $pdo->query(
        "SELECT * FROM order_logs WHERE source_platform = 'allegro' ORDER BY updated_at DESC LIMIT 50"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    $allegroLogs = [];
}

$PAGE_TITLE = 'Kolejka';
$PAGE_KEY   = 'queue';
require __DIR__ . '/header.php';
?>

<?php if ($flashOk): ?><div class="flash ok"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>

<div class="card">
    <form method="get" style="display:inline-block;margin-right:12px">
        <select name="status" onchange="this.form.submit()">
            <option value="">— wszystkie —</option>
            <?php foreach ($valid as $s): ?>
                <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= htmlspecialchars($statusLabel($s)) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php if ($canEdit && $failedCount > 0): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Ponowić wszystkie zadania ze statusem „Błąd” (<?= $failedCount ?>)?')">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="retry_all_failed">
            <button class="btn" type="submit">Ponów wszystkie z błędem (<?= $failedCount ?>)</button>
        </form>
    <?php endif; ?>
    <?php if ($canEdit && $rows !== []): ?>
        <form method="post" id="deleteSelectedForm" style="display:inline"
              onsubmit="return confirmDeleteSelected()">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="delete_selected">
            <button class="btn danger" id="deleteSelectedButton" type="submit" disabled>
                Usuń zaznaczone (<span id="selectedCount">0</span>)
            </button>
        </form>
    <?php endif; ?>
</div>

<?php if ($canEdit): ?>
<div class="card">
    <strong>Zresetuj zamówienie Allegro do ponownego pobrania</strong>
    <p style="color:#888;font-size:13px;margin:6px 0 10px">
        Usunięcie zamówienia w sklepie NIE cofa jego przetworzenia w CRM - zostaje ślad
        w wewnętrznym dzienniku, który blokuje ponowne pobranie. Wybierz zamówienie z listy
        poniżej (nie trzeba znać/szukać jego ID) - „Resetuj” czyści ten ślad razem z zapisem
        w kolejce, żeby dało się je pobrać od nowa przez „Zamówienia → Import → Allegro”.
    </p>
    <?php if ($allegroLogs === []): ?>
        <p style="color:#888;font-size:13px">Brak zamówień z Allegro w dzienniku.</p>
    <?php else: ?>
        <table>
            <tr><th>ID zamówienia Allegro</th><th>Status</th><th>Ostatnia zmiana</th><th>Błąd</th><th></th></tr>
            <?php foreach ($allegroLogs as $log): ?>
                <tr>
                    <td><code><?= htmlspecialchars($log['source_order_id']) ?></code></td>
                    <td><span class="pill <?= $log['internal_status'] === 'failed' ? 'bad' : ($log['internal_status'] === 'completed' ? 'ok' : 'muted') ?>">
                        <?= htmlspecialchars($allegroLogLabels[$log['internal_status']] ?? $log['internal_status']) ?>
                    </span></td>
                    <td style="font-size:12px;white-space:nowrap"><?= htmlspecialchars($log['updated_at']) ?></td>
                    <td style="max-width:240px;color:#c5221f;font-size:12px"><?= htmlspecialchars($log['last_error'] ?? '') ?></td>
                    <td style="text-align:right">
                        <form method="post" style="display:inline" onsubmit="return confirm('Zresetować zamówienie Allegro <?= htmlspecialchars($log['source_order_id']) ?>?')">
                            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="reset_allegro_order">
                            <input type="hidden" name="allegro_order_id" value="<?= htmlspecialchars($log['source_order_id']) ?>">
                            <button class="btn" type="submit">Resetuj</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p style="color:#888;font-size:12px;margin-top:10px">Wyświetlono maks. 50 najnowszych.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <table>
        <tr>
            <?php if ($canEdit): ?>
                <th style="width:28px"><input type="checkbox" id="selectAllJobs" aria-label="Zaznacz wszystkie widoczne zadania"></th>
            <?php endif; ?>
            <th>#</th><th>Typ</th><th>Status</th><th>Próby</th>
            <th>Dostępne od (UTC)</th><th>Błąd</th><?php if ($canEdit): ?><th></th><?php endif; ?>
        </tr>
        <?php if ($rows === []): ?>
            <tr><td colspan="<?= $canEdit ? 8 : 6 ?>" style="color:#888">Kolejka pusta.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <?php if ($canEdit): ?>
                    <td><input type="checkbox" class="job-check" name="ids[]" value="<?= (int)$r['id'] ?>" form="deleteSelectedForm" aria-label="Zaznacz zadanie #<?= (int)$r['id'] ?>"></td>
                <?php endif; ?>
                <td><?= (int)$r['id'] ?></td>
                <td><code><?= htmlspecialchars($r['job_type']) ?></code></td>
                <td><span class="pill <?= $pillClass($r['status']) ?>"><?= htmlspecialchars($statusLabel($r['status'])) ?></span></td>
                <td><?= (int)$r['attempts'] ?>/<?= (int)$r['max_attempts'] ?></td>
                <td style="font-size:12px;white-space:nowrap"><?= htmlspecialchars($r['available_at']) ?></td>
                <td style="max-width:240px;color:#c5221f;font-size:12px"><?= htmlspecialchars($r['last_error'] ?? '') ?></td>
                <?php if ($canEdit): ?>
                <td style="white-space:nowrap;text-align:right">
                    <?php if ($r['status'] === 'failed'): ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="retry">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn" type="submit">Ponów</button>
                        </form>
                    <?php endif; ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Usunąć zadanie #<?= (int)$r['id'] ?>?')">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn danger" type="submit">Usuń</button>
                    </form>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php $pagerPage = $page; $pagerPages = $pages; $pagerLink = $buildLink; require __DIR__ . '/_pager.php'; ?>
    <div style="display:flex;align-items:center;justify-content:center;gap:8px;margin-top:12px;font-size:12px;color:var(--ink-2)">
        <?php $keepDone = \Pase\Services\TaskTimings::get($perPageRepo, 'QUEUE_KEEP_DONE_DAYS'); ?>
        <span>Zadań: <?= number_format($totalRows, 0, ',', ' ') ?> · <?= $keepDone > 0 ? 'wykonane starsze niż ' . $keepDone . ' dni są usuwane automatycznie' : 'automatyczne usuwanie wyłączone' ?>
            (<a href="sync_settings.php#queue_keep_done_days">zmień</a>) · na stronę:</span>
        <select aria-label="Zadań na stronę" onchange="window.location.href = this.value" style="padding:5px 8px;font-size:13px">
            <?php foreach ($perPageOptions as $n): ?>
                <option value="<?= htmlspecialchars($perPageLink($n)) ?>"<?= $n === $perPage ? ' selected' : '' ?>><?= $n ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<?php if ($canEdit && $rows !== []): ?>
<script>
(function () {
    var selectAll = document.getElementById('selectAllJobs');
    var button = document.getElementById('deleteSelectedButton');
    var count = document.getElementById('selectedCount');

    function checks() {
        return Array.from(document.querySelectorAll('.job-check'));
    }

    function refreshSelection() {
        var all = checks();
        var selected = all.filter(function (checkbox) { return checkbox.checked; }).length;
        count.textContent = String(selected);
        button.disabled = selected === 0;
        selectAll.checked = all.length > 0 && selected === all.length;
        selectAll.indeterminate = selected > 0 && selected < all.length;
    }

    selectAll.addEventListener('change', function () {
        checks().forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
        refreshSelection();
    });

    checks().forEach(function (checkbox) {
        checkbox.addEventListener('change', refreshSelection);
    });

    window.confirmDeleteSelected = function () {
        var selected = checks().filter(function (checkbox) { return checkbox.checked; }).length;
        if (selected === 0) {
            alert('Zaznacz zadania do usunięcia.');
            return false;
        }
        return confirm('Usunąć zaznaczone zadania (' + selected + ')? Tej operacji nie można cofnąć.');
    };
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
