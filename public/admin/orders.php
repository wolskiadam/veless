<?php
declare(strict_types=1);

/** Lista zamówień (order_logs) z filtrem statusu. */

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

$statusFilter = $_GET['status'] ?? '';
$valid = ['received','processing','woo_created','invoiced','completed','failed'];

if (in_array($statusFilter, $valid, true)) {
    $stmt = $pdo->prepare('SELECT * FROM order_logs WHERE internal_status = ? ORDER BY id DESC LIMIT 300');
    $stmt->execute([$statusFilter]);
} else {
    $stmt = $pdo->query('SELECT * FROM order_logs ORDER BY id DESC LIMIT 300');
}
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pillClass = static fn(string $s): string => match ($s) {
    'completed' => 'ok',
    'failed'    => 'bad',
    'received', 'processing' => 'muted',
    default     => 'warn',
};

$PAGE_TITLE = 'Zamówienia';
$PAGE_KEY   = 'orders';
require __DIR__ . '/header.php';
?>

<div class="card">
    <form method="get" style="margin-bottom:14px">
        <select name="status" onchange="this.form.submit()">
            <option value="">— wszystkie statusy —</option>
            <?php foreach ($valid as $s): ?>
                <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <table>
        <tr>
            <th>#</th><th>Źródło</th><th>ID zamówienia</th><th>Status</th>
            <th>Woo order</th><th>Faktura wFirma</th><th>Błąd</th><th>Utworzono (UTC)</th>
        </tr>
        <?php if ($rows === []): ?>
            <tr><td colspan="8" style="color:#888">Brak zamówień w dzienniku.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><?= htmlspecialchars($r['source_platform']) ?></td>
                <td><?= htmlspecialchars($r['source_order_id']) ?></td>
                <td><span class="pill <?= $pillClass($r['internal_status']) ?>"><?= htmlspecialchars($r['internal_status']) ?></span></td>
                <td><?= htmlspecialchars((string)($r['woo_order_id'] ?? '—')) ?></td>
                <td><?= htmlspecialchars($r['wfirma_invoice_id'] ?? '—') ?></td>
                <td style="max-width:220px;color:#c5221f;font-size:12px"><?= htmlspecialchars($r['last_error'] ?? '') ?></td>
                <td style="white-space:nowrap;font-size:12px"><?= htmlspecialchars($r['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <p style="color:#888;font-size:12px;margin-top:10px">Wyświetlono maks. 300 najnowszych.</p>
</div>

<?php require __DIR__ . '/footer.php'; ?>
