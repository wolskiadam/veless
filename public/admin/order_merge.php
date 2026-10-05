<?php
declare(strict_types=1);

/**
 * Scalanie zamówień: order_merge.php?ids=1,2[,3]&target=1
 * Podgląd zaznaczonych zamówień, wybór zamówienia głównego (pod nim idzie paczka), błędy i ostrzeżenia,
 * potem „Scal”. Zasady i to, co scalenie zmienia (a czego nie) - Services\OrderMerge.
 * Odłączenie: POST action=unmerge z order_view.php.
 */

use Pase\Services\OrderMerge;

require __DIR__ . '/auth.php';
header('Cache-Control: no-store');
/** @var PDO $pdo */

$merge = new OrderMerge($pdo);
$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_REQUEST['ids'] ?? ''))))));
$ids = array_slice($ids, 0, 10);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if (!canEdit()) {
        flash('Brak uprawnień do zmian.', 'err');
        redirectAfterPost('index.php');
    }
    $act = (string) ($_POST['action'] ?? '');
    if ($act === 'unmerge') {
        $sourceId = (int) ($_POST['source'] ?? 0);
        $target = $merge->unmerge($sourceId);
        $back = (int) ($_POST['back'] ?? 0) ?: ($target ?? $sourceId);
        $_SESSION['order_view_flash'][$back] = $target === null
            ? ['ok' => null, 'error' => 'To zamówienie nie jest dołączone do innego.']
            : ['ok' => 'Odłączono zamówienie - znowu jest osobnym zamówieniem do wysłania.', 'error' => null];
        redirectAfterPost('order_view.php?id=' . $back);
    }
    $targetId = (int) ($_POST['target'] ?? 0);
    $sources = array_values(array_filter($ids, static fn(int $i): bool => $i !== $targetId));
    try {
        $n = $merge->merge($targetId, $sources, ($_POST['accept'] ?? '') === '1');
        $_SESSION['order_view_flash'][$targetId] = ['ok' => 'Scalono: dołączono zamówień ' . $n
            . '. Produkty są na tym zamówieniu, stany magazynowe bez zmian.', 'error' => null];
        redirectAfterPost('order_view.php?id=' . $targetId);
    } catch (\Throwable $e) {
        flash('Nie scalono: ' . $e->getMessage(), 'err');
        redirectAfterPost('order_merge.php?' . http_build_query(['ids' => implode(',', $ids), 'target' => $targetId]));
    }
}

$st = $ids === [] ? null : $pdo->prepare('SELECT wo.*, ia.type AS source_type FROM woo_orders wo
    LEFT JOIN integration_accounts ia ON ia.id = wo.integration_id
    WHERE wo.woo_order_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY wo.date_created, wo.woo_order_id');
$orders = [];
if ($st !== null) {
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $orders[(int) $r['woo_order_id']] = $r;
    }
}
// Domyślnie główne = najstarsze (pierwsze zamówienie klienta), chyba że wybrano inne.
$targetId = (int) ($_GET['target'] ?? 0);
if (!isset($orders[$targetId])) {
    $targetId = (int) (array_key_first($orders) ?? 0);
}
$check = count($orders) >= 2
    ? $merge->check($targetId, array_keys($orders))
    : ['errors' => ['Zaznacz na liście co najmniej dwa zamówienia do scalenia.'], 'warnings' => [], 'orders' => $orders];

$statusMap = (new \Pase\Repository\OrderStatusRepository($pdo))->map();
$e = static fn(mixed $v): string => htmlspecialchars((string) $v);
$money = static fn(float $v, string $cur): string => number_format($v, 2, ',', ' ') . ' ' . ($cur ?: 'PLN');
$groupTotal = 0.0;
foreach ($orders as $o) {
    $groupTotal += OrderMerge::orderTotal($o);
}

$PAGE_TITLE = 'Scal zamówienia';
$PAGE_KEY   = 'orders';
require __DIR__ . '/header.php';
?>
<style>
    .om-muted { color:var(--ink-2); font-size:12.5px; }
    .om-num { font-family:var(--font-num); font-variant-numeric:tabular-nums; text-align:right; white-space:nowrap; }
    .om-table a { color:inherit; text-decoration:underline; text-decoration-color:var(--line); text-underline-offset:3px; }
    .om-table a:hover { color:var(--accent); }
    .om-items { margin:4px 0 0; padding-left:16px; font-size:12.5px; color:var(--ink-2); }
    .om-list { margin:6px 0 0; padding-left:18px; }
</style>

<div class="card">
    <p style="margin-top:0">Scalone zamówienia wysyłasz jedną paczką. Zamówienie główne dostaje produkty pozostałych, a suma to suma wszystkich. Scalenie działa tylko w CRM: zamówienia w sklepie i na Allegro zostają bez zmian, a stany magazynowe się nie zmieniają (każde zamówienie odjęło już swoje produkty). Faktura lub paragon jest jeden na całą paczkę i wystawiasz go na zamówieniu głównym. Scalenie możesz cofnąć na stronie zamówienia.</p>

    <?php if ($orders !== []): ?>
    <form method="get" id="omTargetForm">
        <input type="hidden" name="ids" value="<?= $e(implode(',', array_keys($orders))) ?>">
        <table class="om-table">
            <tr><th>Główne</th><th>Zamówienie</th><th>Klient</th><th>Status</th><th>Produkty</th><th class="om-num">Suma</th></tr>
            <?php foreach ($orders as $id => $o): ?>
            <tr>
                <td><input type="radio" name="target" value="<?= (int) $id ?>" <?= $id === $targetId ? 'checked' : '' ?> onchange="this.form.submit()" aria-label="Zamówienie główne"></td>
                <td><a href="order_view.php?id=<?= (int) $id ?>" target="_blank"><strong><?= $e(OrderMerge::label($o)) ?></strong></a>
                    <div class="om-muted"><?= $e(($o['source_type'] ?? '') === 'allegro' ? 'Allegro' : 'Sklep') ?> · <?= $e(substr((string) $o['date_created'], 0, 16)) ?></div></td>
                <td><?= $e($o['customer_name'] ?: '—') ?></td>
                <td><?= $e($statusMap[$o['pase_status'] ?? '']['label'] ?? ($o['pase_status'] ?? '—')) ?></td>
                <td><ul class="om-items"><?php foreach (OrderMerge::items($o) as $it): ?><li><?= (int) ($it['quantity'] ?? 1) ?> × <?= $e($it['name'] ?? '') ?></li><?php endforeach; ?></ul></td>
                <td class="om-num"><?= $money(OrderMerge::orderTotal($o), (string) $o['currency']) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr><td colspan="5" style="text-align:right"><strong>Razem po scaleniu</strong></td><td class="om-num"><strong><?= $money($groupTotal, (string) ($orders[$targetId]['currency'] ?? 'PLN')) ?></strong></td></tr>
        </table>
        <noscript><button class="btn secondary" type="submit">Zmień główne</button></noscript>
    </form>
    <p class="om-muted">Paczka pójdzie na adres i do punktu odbioru zamówienia głównego. Statusy zmieniasz na zamówieniu głównym, a dołączone dostają ten sam (poza anulowaniem i zwrotem).</p>
    <?php endif; ?>
</div>

<?php if ($check['errors'] !== []): ?>
    <div class="flash err">Tych zamówień nie da się scalić:<ul class="om-list"><?php foreach ($check['errors'] as $msg): ?><li><?= $e($msg) ?></li><?php endforeach; ?></ul></div>
    <p><a class="btn secondary" href="index.php">← Wróć do listy</a></p>
<?php elseif (canEdit()): ?>
<div class="card">
    <form method="post">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="ids" value="<?= $e(implode(',', array_keys($orders))) ?>">
        <input type="hidden" name="target" value="<?= (int) $targetId ?>">
        <?php if ($check['warnings'] !== []): ?>
            <div class="flash err" style="margin-top:0">Sprawdź przed scaleniem:<ul class="om-list"><?php foreach ($check['warnings'] as $msg): ?><li><?= $e($msg) ?></li><?php endforeach; ?></ul></div>
            <label style="display:block;margin:0 0 12px"><input type="checkbox" name="accept" value="1" required> Rozumiem, scal mimo to</label>
        <?php endif; ?>
        <button class="btn" type="submit">Scal do <?= $e(OrderMerge::label($orders[$targetId])) ?></button>
        <a class="btn secondary" href="index.php">Anuluj</a>
    </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php';
