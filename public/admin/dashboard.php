<?php
declare(strict_types=1);

/** Pulpit panelu PASE - przegląd stanu systemu (liczniki, integracje, worker). */

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

// --- Statystyki ---
$mappings   = (int) $pdo->query('SELECT COUNT(*) FROM product_mappings')->fetchColumn();
$ordersDone = (int) $pdo->query("SELECT COUNT(*) FROM order_logs WHERE internal_status='completed'")->fetchColumn();
$ordersFail = (int) $pdo->query("SELECT COUNT(*) FROM order_logs WHERE internal_status='failed'")->fetchColumn();
$queuePend  = (int) $pdo->query("SELECT COUNT(*) FROM job_queue WHERE status='pending'")->fetchColumn();
$queueFail  = (int) $pdo->query("SELECT COUNT(*) FROM job_queue WHERE status='failed'")->fetchColumn();

try {
    $wooOrders = (int) $pdo->query('SELECT COUNT(*) FROM woo_orders')->fetchColumn();
} catch (\PDOException $e) {
    $wooOrders = null;
}

// Tokeny OAuth (tabela integrations) - dziś tylko Allegro.
$tokens = $pdo->query('SELECT platform_name, expires_at FROM integrations')->fetchAll(PDO::FETCH_KEY_PAIR);
// Konta integracji (sklepy, wFirma, kurierzy, SMS...) - to je dodajesz w „Integracjach".
$accounts = (new \Pase\Repository\IntegrationAccountRepository($pdo))->all();
$lastJob = $pdo->query('SELECT MAX(updated_at) FROM job_queue')->fetchColumn();

$PAGE_TITLE = 'Pulpit / stan systemu';
$PAGE_KEY   = 'dashboard';
require __DIR__ . '/header.php';
?>

<div class="grid">
    <?php if ($wooOrders !== null): ?>
        <div class="stat"><div class="num"><?= $wooOrders ?></div><div class="lbl">Zamówienia (pobrane)</div></div>
    <?php else: ?>
        <div class="stat"><div class="num" style="color:#b06000">!</div><div class="lbl">Brak tabeli woo_orders</div></div>
    <?php endif; ?>
    <div class="stat"><div class="num"><?= $mappings ?></div><div class="lbl">Mapowania SKU</div></div>
    <div class="stat"><div class="num"><?= $ordersDone ?></div><div class="lbl">Zamówienia ukończone</div></div>
    <div class="stat"><div class="num" style="color:<?= $ordersFail ? '#c5221f' : 'inherit' ?>"><?= $ordersFail ?></div><div class="lbl">Zamówienia z błędem</div></div>
    <div class="stat"><div class="num"><?= $queuePend ?></div><div class="lbl">W kolejce (pending)</div></div>
    <div class="stat"><div class="num" style="color:<?= $queueFail ? '#c5221f' : 'inherit' ?>"><?= $queueFail ?></div><div class="lbl">Kolejka — failed</div></div>
</div>

<div class="card">
    <strong>Integracje</strong>
    <table style="margin-top:10px">
        <tr><th>Integracja</th><th>Typ</th><th>Status</th></tr>
        <?php foreach ($accounts as $acc):
            $proto = \Pase\Plugin\PluginRegistry::get((string) $acc['type']);
            try { $mf = $proto?->manifest(); } catch (\Throwable $e) { $mf = null; }
            $active = !empty($acc['is_active']);
            $isAllegro = $acc['type'] === 'allegro';
            $exp = $isAllegro ? ($tokens['allegro'] ?? null) : null;
        ?>
            <tr>
                <td><a href="integration_edit.php?id=<?= (int) $acc['id'] ?>"><?= htmlspecialchars((string) ($acc['name'] ?: ($mf->name ?? $acc['type']))) ?></a></td>
                <td style="color:#666"><?= $mf !== null ? $mf->iconHtml() . ' ' : '' ?><?= htmlspecialchars((string) ($mf->name ?? $acc['type'])) ?></td>
                <td>
                    <?php if (!$active): ?>
                        <span class="pill muted">wyłączona</span>
                    <?php elseif ($isAllegro && !isset($tokens['allegro'])): ?>
                        <span class="pill warn">niepołączone z Allegro</span>
                    <?php else: ?>
                        <span class="pill ok">aktywna</span>
                        <?php if ($exp): ?><span style="color:#888;font-size:12px"> token ważny do <?= htmlspecialchars((new DateTime($exp, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('Y-m-d H:i')) ?> (odświeżany automatycznie)</span><?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($accounts === []): ?>
            <tr><td colspan="3" style="color:#888">Brak integracji — dodaj pierwszą w „Zarządzaj integracjami".</td></tr>
        <?php endif; ?>
    </table>
    <p style="margin-top:14px"><a class="btn" href="integrations.php">Zarządzaj integracjami</a></p>
</div>

<div class="card">
    <strong>Worker</strong>
    <p style="margin:10px 0 0">
        Ostatnia aktywność kolejki:
        <?php if ($lastJob): ?>
            <strong><?= htmlspecialchars($lastJob) ?> UTC</strong>
        <?php else: ?>
            <span class="pill muted">brak danych</span> (kolejka pusta lub worker nigdy nie ruszył)
        <?php endif; ?>
    </p>
    <p style="color:#888;font-size:13px;margin-top:6px">
        Worker uruchamiany jest cronem co 1 min (<code>cli/worker.php</code>). Jeśli ta data nie odświeża się przy zadaniach w kolejce — sprawdź wpis crona.
    </p>
</div>

<?php if (is_file(PASE_ROOT . '/storage/ADMIN_PASSWORD.txt')): ?>
<div class="flash err">
    Wygenerowano losowe hasło administratora i zapisano w <code>storage/ADMIN_PASSWORD.txt</code>.
    Odczytaj je, zmień hasło, a następnie <strong>usuń ten plik</strong>.
</div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
