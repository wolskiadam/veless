<?php
declare(strict_types=1);

/**
 * Marketplace → Erli: stan synchronizacji każdego sklepu ERLI w jednym miejscu.
 *  - liczniki zamówień i produktów, ostatnia synchronizacja, błędy stanów i numerów przesyłek (Services\Erli::status),
 *  - skróty do zamówień i produktów tego sklepu (erli_orders.php, erli_products.php) i do ustawień konta (tam „Synchronizuj teraz” i „Pobierz produkty”).
 * Strona tylko czyta - zapis i ręczna synchronizacja zostają na stronie integracji (integration_edit.php).
 */

use Pase\Services\Erli;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$PAGE_TITLE = 'Erli - pulpit';
$PAGE_KEY   = 'erli';

if (!Erli::available()) {
    require __DIR__ . '/header.php';
    echo '<div class="card"><p>Wtyczka ERLI jest wyłączona albo usunięta. Włącz ją w Konfiguracja → Wtyczki (administrator).</p></div>';
    require __DIR__ . '/footer.php';
    exit;
}

Erli::migrate($pdo);
$erli = new Erli($pdo);
$accounts = array_values(array_filter(
    (new \Pase\Repository\IntegrationAccountRepository($pdo))->all(),
    static fn(array $a): bool => ($a['type'] ?? '') === Erli::TYPE
));
$canSettings = canOpenPage('integration_edit.php');

require __DIR__ . '/header.php';
?>

<?php if (!$accounts): ?>
    <div class="card">
        <p style="margin:0">Nie ma jeszcze konta ERLI. Dodaj je w Konfiguracja → Integracje i wklej klucz API z panelu sprzedawcy ERLI.</p>
        <?php if ($canSettings): ?><p style="margin:12px 0 0"><a class="btn" href="integration_edit.php?type=erli">+ Dodaj konto ERLI</a></p><?php endif; ?>
    </div>
<?php endif; ?>

<?php foreach ($accounts as $acc): $es = $erli->status($acc); $accId = (int) $acc['id']; ?>
    <div class="card">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <h2 style="margin:0;font-size:17px"><?= htmlspecialchars((string) $acc['name']) ?></h2>
            <?php if (empty($acc['is_active'])): ?>
                <span class="pill muted">wyłączone</span>
            <?php elseif (!$es['configured']): ?>
                <span class="pill warn">brak klucza API</span>
            <?php elseif ($es['last_error'] !== ''): ?>
                <span class="pill bad">błąd synchronizacji</span>
            <?php else: ?>
                <span class="pill ok">działa</span>
            <?php endif; ?>
        </div>
        <p style="font-size:13px;color:var(--ink-2);margin:8px 0 0">
            Ostatnia synchronizacja: <?= $es['last_run'] !== '' ? htmlspecialchars($es['last_run']) : 'jeszcze nie było' ?>
            (worker co <?= Erli::RUN_EVERY_MIN ?> min).
        </p>
        <?php if ($es['last_error'] !== ''): ?><div class="flash err" style="margin-top:8px"><?= htmlspecialchars($es['last_error']) ?></div><?php endif; ?>

        <div class="grid" style="margin-top:14px">
            <div class="stat"><div class="num"><?= (int) $es['orders'] ?></div><div class="lbl">Zamówienia z ERLI w CRM</div></div>
            <div class="stat"><div class="num"><?= (int) $es['products'] ?></div><div class="lbl">Produkty w ERLI</div></div>
            <div class="stat"><div class="num"><?= (int) $es['products_linked'] ?></div><div class="lbl">Połączone po SKU z magazynem</div></div>
            <div class="stat"><div class="num" style="color:<?= ($es['stock_errors'] || $es['tracking_errors']) ? 'var(--danger-ink)' : 'inherit' ?>"><?= count($es['stock_errors']) + count($es['tracking_errors']) ?></div><div class="lbl">Odrzucone stany i przesyłki</div></div>
        </div>

        <p style="margin:14px 0 0;display:flex;gap:8px;flex-wrap:wrap">
            <a class="btn secondary" href="erli_orders.php?acc=<?= $accId ?>">Zamówienia i przesyłki</a>
            <a class="btn secondary" href="erli_products.php?acc=<?= $accId ?>">Produkty i stany</a>
            <a class="btn secondary" href="index.php?source=acc:<?= $accId ?>">Na liście zamówień</a>
            <?php if ($canSettings): ?><a class="btn secondary" href="integration_edit.php?id=<?= $accId ?>">Ustawienia i synchronizacja</a><?php endif; ?>
        </p>

        <?php if ($es['stock_errors']): ?>
            <p style="font-size:13px;margin:14px 0 4px"><strong>Stany, których ERLI nie przyjęło</strong> (kolejna próba przy następnej synchronizacji):</p>
            <table><tr><th>SKU</th><th>Produkt</th><th>Błąd</th></tr>
            <?php foreach ($es['stock_errors'] as $r): ?><tr><td><?= htmlspecialchars((string) $r['match_sku']) ?></td><td><?= htmlspecialchars((string) $r['product_name']) ?></td><td><?= htmlspecialchars((string) $r['error']) ?></td></tr><?php endforeach; ?>
            </table>
        <?php endif; ?>
        <?php if ($es['tracking_errors']): ?>
            <p style="font-size:13px;margin:14px 0 4px"><strong>Numery przesyłek, których ERLI nie przyjęło</strong>:</p>
            <table><tr><th>Zamówienie</th><th>Błąd</th></tr>
            <?php foreach ($es['tracking_errors'] as $r): ?><tr><td><a href="order_view.php?id=<?= (int) $r['woo_order_id'] ?>">#<?= htmlspecialchars((string) ($r['pase_number'] ?? $r['woo_order_id'])) ?></a> (ERLI <?= htmlspecialchars((string) $r['erli_order_id']) ?>)</td><td><?= htmlspecialchars((string) $r['tracking_error']) ?></td></tr><?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<?php require __DIR__ . '/footer.php'; ?>
