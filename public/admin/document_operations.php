<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
requireRole(['admin']);
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    try {
        if (($_POST['verified'] ?? '') !== '1') { throw new RuntimeException('Potwierdź sprawdzenie dokumentów na właściwym koncie wFirma.'); }
        $action = $_POST['action'] ?? '';
        if (!in_array($action, ['link', 'absent'], true)) { throw new RuntimeException('Nieprawidłowa akcja.'); }
        (new \Pase\Services\DocumentIssueGuard($pdo))->reconcile((int) ($_POST['order_id'] ?? 0),
            (string) ($_POST['document_type'] ?? ''), (string) ($_POST['attempt_token'] ?? ''),
            $action === 'link' ? trim((string) ($_POST['remote_id'] ?? '')) : null);
        flash('Zapisano wynik sprawdzenia. Nie wysłano żądania wystawienia dokumentu.');
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirectAfterPost();
}
$rows = $pdo->query("SELECT * FROM document_issue_operations WHERE state <> 'issued' ORDER BY created_at LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
$PAGE_TITLE = 'Dokumenty wymagające sprawdzenia'; $PAGE_KEY = 'orders';
require __DIR__ . '/header.php';
$esc = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="card">
<p>Nie ponawiamy automatycznie wystawiania po błędzie. Sprawdź dokumenty w wFirma na koncie integracji wskazanej poniżej.
Odblokowanie po błędnym potwierdzeniu braku dokumentu może spowodować duplikat. Operacji w stanie pending nie można odblokować czasem oczekiwania.</p>
<?php if (!$rows): ?><p>Brak nierozstrzygniętych operacji.</p><?php endif; ?>
<?php foreach ($rows as $row): ?>
<form method="post" style="padding:16px;border-top:1px solid #ddd">
<input type="hidden" name="csrf" value="<?= csrfToken() ?>">
<?php foreach (['order_id','document_type','attempt_token'] as $field): ?><input type="hidden" name="<?= $field ?>" value="<?= $esc($row[$field]) ?>"><?php endforeach; ?>
<p>Zamówienie #<?= (int) $row['order_id'] ?> · <?= $esc($row['document_type']) ?> · integracja #<?= (int) $row['integration_id'] ?>
 · <?= $esc($row['state']) ?> · <?= $esc($row['created_at']) ?> UTC</p>
<label>ID istniejącego dokumentu w wFirma <input name="remote_id" inputmode="numeric" value="<?= $esc($row['remote_id'] ?? '') ?>"></label>
<p><label><input type="checkbox" name="verified" value="1" required> Sprawdziłem właściwe konto wFirma, zakończenie operacji i wynik. Potwierdzam wskazane powiązanie albo brak dokumentu.</label></p>
<button class="btn" name="action" value="link">Powiąż istniejący dokument</button>
<?php if ($row['state'] === 'uncertain' && empty($row['remote_id'])): ?><button class="btn secondary" name="action" value="absent">Potwierdzam brak dokumentu — odblokuj</button><?php endif; ?>
</form>
<?php endforeach; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
