<?php
declare(strict_types=1);

/**
 * Import historyczny zamówień - jedna zakładka, przełącznik źródła (Woo/Allegro).
 * Logika pobierania mocno się różni między platformami (Woo: per-sklep, paginacja
 * po numerze strony; Allegro: jedna globalna konfiguracja, paginacja po offsecie),
 * więc oba warianty żyją tu pod przełącznikiem `source`, żeby user miał jedno miejsce
 * w menu zamiast dwóch osobnych stron.
 */

use Pase\Plugin\IntegrationClient;
use Pase\Queue\Queue;
use Pase\Repository\IntegrationAccountRepository;
use PasePlugin\Allegro\AllegroPlugin;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$source = ($_GET['source'] ?? 'woo') === 'allegro' ? 'allegro' : 'woo';

$flashErr = null;
$done     = false;
$queuedTotal = (int) ($_GET['queued'] ?? 0);

// ============================================================
//  WOOCOMMERCE
// ============================================================
$intRepo = new IntegrationAccountRepository($pdo);
$wooIntegrations = $intRepo->activeByType('woocommerce');

$wooIntegrationId = (int) ($_GET['integration'] ?? 0);
$wooIntegration = $wooIntegrationId ? $intRepo->find($wooIntegrationId) : null;
$wooConfig = $wooIntegration['config'] ?? null;

/** Klient API Woo z wtyczki dla wybranej integracji (lub null). */
$wooClient = static fn() => $wooConfig !== null
    ? IntegrationClient::for('woocommerce', $wooConfig)
    : null;

$wooRanges = [
    'all'      => ['label' => 'Wszystkie (każdy status)',          'status' => 'any',                          'after' => null],
    'active'   => ['label' => 'Tylko aktywne (processing/completed/on-hold)', 'status' => 'processing,completed,on-hold', 'after' => null],
    'last30'   => ['label' => 'Z ostatnich 30 dni',                'status' => 'any',                          'after' => 'LAST30'],
];

$wooRange = $_GET['range'] ?? '';
$wooPage  = max(1, (int) ($_GET['page'] ?? 1));
$wooPerPage = 50;

if ($source === 'woo' && ($wooIntegration === null || $wooIntegration['type'] !== 'woocommerce') && (isset($_GET['run']) || isset($_GET['debug']))) {
    $flashErr = 'Wybierz integrację WooCommerce do importu.';
}

// --- Tryb diagnostyczny: pokaż surową odpowiedź API /orders + stan kolejki/tabeli ---
$wooDebug = null;
$wooQueueStats = null;
$wooOrdersCount = null;
if ($source === 'woo' && isset($_GET['debug']) && $wooConfig !== null) {
    $client = $wooClient();
    $wooDebug = $client !== null ? $client->debugFetchOrders(5) : ['status' => 0, 'body' => 'Brak wtyczki WooCommerce.', 'count' => -1];

    $stmt = $pdo->query(
        "SELECT status, COUNT(*) AS n FROM job_queue WHERE job_type='woo.order.import' GROUP BY status"
    );
    $wooQueueStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    try {
        $wooOrdersCount = (int) $pdo->query('SELECT COUNT(*) FROM woo_orders')->fetchColumn();
    } catch (\PDOException $e) {
        $wooOrdersCount = null;
    }
}

if ($source === 'woo' && isset($_GET['run']) && isset($wooRanges[$wooRange]) && $wooConfig !== null) {
    $cfg = $wooRanges[$wooRange];
    $after = $cfg['after'] === 'LAST30'
        ? (new DateTimeImmutable('-30 days', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s')
        : null;

    try {
        $woo = $wooClient();
        if ($woo === null) {
            throw new \RuntimeException('Brak zainstalowanej/aktywnej wtyczki WooCommerce.');
        }
        $result = $woo->fetchOrders($wooPage, $wooPerPage, $cfg['status'], $after);

        $queue = new Queue($pdo);
        $queuedNow = 0;
        foreach ($result['orders'] as $order) {
            $id = (int) ($order['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $status = $order['status'] ?? 'unknown';
            $order['__integration_id'] = $wooIntegrationId;
            $added = $queue->enqueue(
                jobType: 'woo.order.import',
                payload: $order,
                dedupKey: "woo.order.import:{$wooIntegrationId}:{$id}:{$status}"
            );
            if ($added) {
                $queuedNow++;
            }
        }

        $queuedTotal += $queuedNow;

        $hasMore = count($result['orders']) > 0;
        if ($hasMore) {
            $next = $wooPage + 1;
            header("Location: import_orders.php?source=woo&run=1&integration={$wooIntegrationId}&range={$wooRange}&page={$next}&queued={$queuedTotal}");
            exit;
        }
        $done = true;
    } catch (\Throwable $e) {
        $flashErr = $e->getMessage();
    }
}

// ============================================================
//  ALLEGRO
// ============================================================
$allegroConfig = require PASE_ROOT . '/config/config.php';
$allegroClient = AllegroPlugin::makeClient($pdo, $allegroConfig['allegro'] ?? []);

$allegroRanges = [
    'all'    => ['label' => 'Wszystkie (cała historia)', 'boughtAfter' => null],
    'last30' => ['label' => 'Z ostatnich 30 dni',        'boughtAfter' => '-30 days'],
    'last7'  => ['label' => 'Z ostatnich 7 dni',          'boughtAfter' => '-7 days'],
];

$allegroRange  = $_GET['range'] ?? '';
$allegroOffset = max(0, (int) ($_GET['offset'] ?? 0));
$allegroLimit  = 100;

if ($source === 'allegro' && isset($_GET['run']) && isset($allegroRanges[$allegroRange])) {
    $filters = [];
    $after = $allegroRanges[$allegroRange]['boughtAfter'];
    if ($after !== null) {
        $filters['boughtAt.gte'] = (new DateTimeImmutable($after, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    $result = $allegroClient->listCheckoutForms($filters + ['limit' => $allegroLimit, 'offset' => $allegroOffset]);

    if (!$result['ok']) {
        $flashErr = $result['message'];
    } else {
        $queue = new Queue($pdo);
        $queuedNow = 0;
        foreach ($result['checkoutForms'] as $order) {
            $orderId = (string) ($order['id'] ?? '');
            if ($orderId === '') {
                continue;
            }
            // Lista checkout-forms nie zawiera pozycji/kupującego w pełni - dociągamy
            // pełny obiekt, żeby payload w kolejce był identyczny jak z pollera.
            $full = $allegroClient->checkoutForm($orderId) ?? $order;
            $added = $queue->enqueue(
                jobType: 'allegro.order.new',
                payload: $full,
                dedupKey: "allegro.order.new:{$orderId}"
            );
            if ($added) {
                $queuedNow++;
            }
        }

        $queuedTotal += $queuedNow;
        $returned = count($result['checkoutForms']);
        $hasMore = $returned > 0 && ($allegroOffset + $returned) < $result['total'];

        if ($hasMore) {
            $nextOffset = $allegroOffset + $allegroLimit;
            header("Location: import_orders.php?source=allegro&run=1&range={$allegroRange}&offset={$nextOffset}&queued={$queuedTotal}");
            exit;
        }
        $done = true;
    }
}

$PAGE_TITLE = 'Import zamówień';
$PAGE_KEY   = 'orders';
require __DIR__ . '/header.php';
?>

<div style="display:flex;gap:8px;margin-bottom:16px">
    <a class="btn <?= $source === 'woo' ? '' : 'secondary' ?>" href="import_orders.php?source=woo">🛒 WooCommerce</a>
    <a class="btn <?= $source === 'allegro' ? '' : 'secondary' ?>" href="import_orders.php?source=allegro">📦 Allegro</a>
</div>

<?php if ($flashErr): ?><div class="flash err">❌ <?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

<?php if ($source === 'woo'): ?>

    <?php if ($wooDebug !== null): ?>
    <div class="card">
        <strong>Diagnostyka API zamówień</strong>
        <table style="max-width:520px;margin-top:8px">
            <tr><th>HTTP status</th><td><?= (int)$wooDebug['status'] ?></td></tr>
            <tr><th>Zamówień w odpowiedzi API</th><td><strong><?= (int)$wooDebug['count'] ?></strong></td></tr>
            <tr><th>Rekordów w tabeli woo_orders</th><td><strong><?= $wooOrdersCount === null ? 'brak tabeli' : (int)$wooOrdersCount ?></strong></td></tr>
            <tr><th>Zadania w kolejce (woo.order.import)</th><td>
                <?php if (empty($wooQueueStats)): ?>
                    <span style="color:#888">brak</span>
                <?php else: foreach ($wooQueueStats as $st => $n): ?>
                    <span class="pill muted"><?= htmlspecialchars($st) ?>: <?= (int)$n ?></span>
                <?php endforeach; endif; ?>
            </td></tr>
        </table>
        <p style="font-size:13px;margin-top:8px">
            Jeśli „woo_orders" = 0, a w kolejce są zadania <strong>pending</strong> → worker (cron) nie przetwarza.
            Jeśli w kolejce są <strong>done</strong>, a woo_orders = 0 → błąd zapisu w handlerze.
        </p>
        <p style="font-size:12px;color:#888;margin-top:10px">Surowa odpowiedź (pierwsze 4000 znaków):</p>
        <pre style="background:#0f172a;color:#e2e8f0;padding:14px;border-radius:8px;overflow:auto;font-size:12px;max-height:360px"><?= htmlspecialchars($wooDebug['body']) ?></pre>
    </div>
    <?php endif; ?>

    <?php if ($done): ?>
        <div class="flash ok">
            ✅ Import zakończony. Dodano do kolejki <strong><?= $queuedTotal ?></strong> zamówień.
            Worker przetwarza je w tle — pojawią się w <a href="index.php">Zamówieniach</a> w ciągu kilku minut.
        </div>
    <?php elseif (isset($_GET['run'])): ?>
        <div class="card">
            <strong>Import w toku…</strong>
            <p>Strona <?= $wooPage ?>, dodano dotąd: <strong><?= $queuedTotal ?></strong>. Trwa pobieranie kolejnej strony…</p>
            <p style="color:#888;font-size:13px">Nie zamykaj tej karty. Strona odświeża się automatycznie.</p>
        </div>
    <?php else: ?>
        <div class="card">
            <strong>Pobierz istniejące zamówienia z WooCommerce</strong>
            <p style="color:#888;font-size:13px;margin-top:6px">
                Webhook pobiera tylko <em>nowe</em> zamówienia. Tutaj jednorazowo ściągniesz
                <em>historyczne</em>. Import jest idempotentny — można go bezpiecznie powtórzyć,
                duplikaty nie powstaną.
            </p>
            <?php if ($wooIntegrations === []): ?>
                <div class="flash err" style="margin-top:10px">Brak aktywnych integracji WooCommerce. Dodaj sklep w <a href="integrations.php">Integracjach</a>.</div>
            <?php endif; ?>
            <form method="get" style="margin-top:14px">
                <input type="hidden" name="source" value="woo">
                <input type="hidden" name="run" value="1">
                <input type="hidden" name="page" value="1">
                <label style="font-size:12px;color:#888;display:block">Sklep (integracja)</label>
                <select name="integration" required style="min-width:340px;margin-bottom:12px">
                    <option value="">— wybierz sklep —</option>
                    <?php foreach ($wooIntegrations as $wi): ?>
                        <option value="<?= (int)$wi['id'] ?>" <?= $wooIntegrationId === (int)$wi['id'] ? 'selected' : '' ?>><?= htmlspecialchars($wi['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label style="font-size:12px;color:#888;display:block">Zakres importu</label>
                <select name="range" style="min-width:340px">
                    <?php foreach ($wooRanges as $key => $r): ?>
                        <option value="<?= $key ?>"><?= htmlspecialchars($r['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p style="margin-top:14px"><button class="btn" type="submit">Importuj zamówienia</button></p>
            </form>
        </div>
    <?php endif; ?>

<?php else: /* $source === 'allegro' */ ?>

    <?php if ($done): ?>
        <div class="flash ok">
            ✅ Import zakończony. Dodano do kolejki <strong><?= $queuedTotal ?></strong> zamówień.
            Worker przetwarza je w tle — pojawią się w <a href="index.php">Zamówieniach</a> w ciągu kilku minut.
        </div>
    <?php elseif (isset($_GET['run']) && !$flashErr): ?>
        <div class="card">
            <strong>Import w toku…</strong>
            <p>Offset <?= $allegroOffset ?>, dodano dotąd: <strong><?= $queuedTotal ?></strong>. Trwa pobieranie kolejnej strony…</p>
            <p style="color:#888;font-size:13px">Nie zamykaj tej karty. Strona odświeża się automatycznie.</p>
        </div>
    <?php else: ?>
        <div class="card">
            <strong>Pobierz istniejące zamówienia z Allegro</strong>
            <p style="color:#888;font-size:13px;margin-top:6px">
                Nowe zamówienia dociera automatycznie cron (Order Events API). Tutaj jednorazowo
                ściągniesz <em>historyczne</em>. Import jest idempotentny — można go bezpiecznie
                powtórzyć, duplikaty nie powstaną.
            </p>
            <form method="get" style="margin-top:14px">
                <input type="hidden" name="source" value="allegro">
                <input type="hidden" name="run" value="1">
                <input type="hidden" name="offset" value="0">
                <label style="font-size:12px;color:#888;display:block">Zakres importu</label>
                <select name="range" style="min-width:340px">
                    <?php foreach ($allegroRanges as $key => $r): ?>
                        <option value="<?= $key ?>"><?= htmlspecialchars($r['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p style="margin-top:14px"><button class="btn" type="submit">Importuj zamówienia</button></p>
            </form>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
