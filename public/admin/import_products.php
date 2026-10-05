<?php
declare(strict_types=1);

/**
 * Import produktów z WooCommerce do magazynu PASE.
 * Pobiera produkty stronami przez API i wrzuca do kolejki (woo.product.import);
 * worker zapisuje je do tabeli products. Idempotentnie po SKU.
 */

use Pase\Plugin\IntegrationClient;
use Pase\Queue\Queue;
use Pase\Repository\IntegrationAccountRepository;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$intRepo = new IntegrationAccountRepository($pdo);
$wooIntegrations = $intRepo->activeByType('woocommerce');

$integrationId = (int) ($_GET['integration'] ?? 0);
$integration = $integrationId ? $intRepo->find($integrationId) : null;
$wooConfig = $integration['config'] ?? null;

/** Klient API Woo z wtyczki dla wybranej integracji (lub null). */
$wooClient = static fn() => $wooConfig !== null
    ? IntegrationClient::for('woocommerce', $wooConfig)
    : null;

// Postęp importu (JSON dla licznika na stronie): stan zadań woo.product.import w kolejce.
// Zakończone zadania czyścimy na starcie importu (strona 1), więc liczniki dotyczą bieżącego importu.
if (isset($_GET['progress'])) {
    header('Content-Type: application/json; charset=utf-8');
    $counts = ['pending' => 0, 'reserved' => 0, 'done' => 0, 'failed' => 0];
    foreach ($pdo->query("SELECT status, COUNT(*) AS n FROM job_queue WHERE job_type = 'woo.product.import' GROUP BY status")
                 ->fetchAll(PDO::FETCH_KEY_PAIR) as $st => $n) {
        $counts[$st] = (int) $n;
    }
    $errors = $pdo->query("SELECT last_error FROM job_queue WHERE job_type = 'woo.product.import' AND status = 'failed'
                           ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
    $workerAt = (int) ((new \Pase\Repository\SettingsRepository($pdo))->get('WORKER_LAST_RUN_AT', '0') ?? 0);
    echo json_encode($counts + [
        'total'      => array_sum($counts),
        'errors'     => array_values(array_filter($errors)),
        'worker_ago' => $workerAt > 0 ? max(0, time() - $workerAt) : null,
    ]);
    return;
}

$flashErr = null;
$done = false;
$nextUrl = null;
$queuedTotal = (int) ($_GET['queued'] ?? 0);
$skippedTotal = (int) ($_GET['skipped'] ?? 0);
// Typ importu (jak w Base). Stany i ceny CRM nie są nadpisywane w żadnym trybie
// (patrz ProductRepository::upsert) - CRM jest ich źródłem prawdy.
const IMPORT_MODES = [
    'link'        => 'Produkty już istnieją w magazynie — chcę tylko powiązać je z tym sklepem',
    'link_create' => 'Produkty częściowo istnieją — powiąż istniejące i utwórz brakujące',
    'all'         => 'Pobierz nowe produkty i zaktualizuj istniejące',
    'update'      => 'Tylko zaktualizuj wcześniej zaimportowane produkty',
    'new'         => 'Tylko pobierz nowe produkty (bez aktualizacji pobranych wcześniej)',
];
const IMPORT_MODE_HELP = [
    'link'        => 'Nic nie jest dodawane ani nadpisywane. Istniejące produkty (po SKU) dostają powiązanie z tym sklepem — dzięki temu CRM będzie wysyłał do niego stany. Produkty z tego sklepu, których nie ma w magazynie, są pomijane.',
    'link_create' => 'Istniejące produkty dostają tylko powiązanie z tym sklepem (bez zmiany ich danych), a brakujące są tworzone w magazynie.',
    'all'         => 'Dodaje brakujące produkty i nadpisuje dane istniejących danymi ze sklepu: nazwę, opisy, zdjęcia, kategorie, atrybuty, EAN, wagę i wymiary.',
    'update'      => 'Nadpisuje dane produktów, które już są w magazynie (nazwa, opisy, zdjęcia, kategorie, atrybuty, EAN, waga, wymiary). Nowych nie dodaje.',
    'new'         => 'Dodaje tylko produkty, których SKU nie ma jeszcze w magazynie. Istniejące zostają bez zmian.',
];
$mode = array_key_exists($_GET['mode'] ?? '', IMPORT_MODES) ? $_GET['mode'] : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

// Diagnostyka: surowa odpowiedź API dla 1 produktu (czy ma description/images/...).
$debug = null;
if (isset($_GET['debug']) && $wooConfig !== null) {
    $client = $wooClient();
    $debug = $client !== null ? $client->debugFetchProducts() : ['status' => 0, 'body' => 'Brak wtyczki WooCommerce.'];
}

if (isset($_GET['run']) && $wooConfig !== null && $mode === '') {
    $flashErr = 'Wybierz typ importu.';
} elseif (isset($_GET['run']) && $wooConfig !== null) {
    try {
        // Na starcie importu (pierwsza strona) czyścimy ZAKOŃCZONE zadania importu
        // produktów - inaczej ich dedup_key blokowałby ponowne pobranie po usunięciu
        // produktów z magazynu (INSERT IGNORE pomijałby je jako duplikaty).
        if ($page === 1) {
            $pdo->prepare("DELETE FROM job_queue WHERE job_type = 'woo.product.import' AND status IN ('done','failed')")
                ->execute();
        }

        $woo = $wooClient();
        if ($woo === null) {
            throw new \RuntimeException('Brak zainstalowanej/aktywnej wtyczki WooCommerce.');
        }
        $result = $woo->fetchProducts($page, $perPage);
        [$weightUnit, $dimUnit] = \Pase\Handlers\WooProductImportHandler::shopUnits($woo, $wooConfig ?? []);
        $queue = new Queue($pdo);
        $queuedNow = 0;
        $skippedNow = 0;
        // Tryb „tylko nowe”: czy SKU jest już w magazynie.
        $skuExists = $pdo->prepare('SELECT 1 FROM products WHERE sku = ? LIMIT 1');
        $inWarehouse = static function (string $sku) use ($skuExists): bool {
            $skuExists->execute([$sku]);
            return (bool) $skuExists->fetchColumn();
        };
        // Czy produkt z tego SKU w ogóle idzie do kolejki w wybranym trybie.
        $wanted = static fn(string $sku): bool => match ($mode) {
            'new'            => !$inWarehouse($sku),
            'update', 'link' => $inWarehouse($sku),
            default          => true,             // all, link_create
        };
        foreach ($result['products'] as $prod) {
            $id = (int) ($prod['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if (($prod['type'] ?? 'simple') === 'variable') {
                // Produkt wariantowy w Woo - sam rodzic zwykle nie ma własnego SKU/ceny
                // (te siedzą na wariantach), więc importujemy KAŻDY wariant osobno, ze
                // wspólnym woo_product_id (rodzic) i własnym woo_variation_id. products.php
                // grupuje je potem wizualnie po tym wspólnym woo_product_id.
                $vPage = 1;
                do {
                    $vResult = $woo->fetchVariations($id, $vPage);
                    foreach ($vResult['variations'] as $variation) {
                        $vid  = (int) ($variation['id'] ?? 0);
                        $vsku = trim((string) ($variation['sku'] ?? ''));
                        if ($vid <= 0 || $vsku === '') {
                            continue;
                        }
                        if (!$wanted($vsku)) {
                            $skippedNow++;
                            continue;
                        }
                        $variation['__import_mode'] = $mode;
                        $variation['__weight_unit'] = $weightUnit;
                        $variation['__dim_unit'] = $dimUnit;
                        // Nazwa/opisy/kategorie/zdjęcia dziedziczone po rodzicu (patrz variationPayload).
                        $variation = \Pase\Handlers\WooProductImportHandler::variationPayload($variation, $prod, $integrationId);

                        $added = $queue->enqueue(
                            jobType: 'woo.product.import',
                            payload: $variation,
                            dedupKey: "woo.product.import:{$integrationId}:{$vid}"
                        );
                        if ($added) {
                            $queuedNow++;
                        }
                    }
                    $hasMoreVariations = count($vResult['variations']) > 0;
                    $vPage++;
                } while ($hasMoreVariations);
                continue;
            }

            $sku = trim((string) ($prod['sku'] ?? ''));
            if ($sku === '') {
                continue; // bez SKU nie importujemy
            }
            if (!$wanted($sku)) {
                $skippedNow++;
                continue;
            }
            $prod['__integration_id'] = $integrationId;
            $prod['__import_mode'] = $mode;
            $prod['__weight_unit'] = $weightUnit;
            $prod['__dim_unit'] = $dimUnit;
            $added = $queue->enqueue(
                jobType: 'woo.product.import',
                payload: $prod,
                dedupKey: "woo.product.import:{$integrationId}:{$id}"
            );
            if ($added) {
                $queuedNow++;
            }
        }
        $queuedTotal += $queuedNow;
        $skippedTotal += $skippedNow;

        if (count($result['products']) > 0) {
            $next = $page + 1;
            // Nie przekierowujemy od razu - strona pokazuje postęp i sama przechodzi dalej.
            $nextUrl = "import_products.php?run=1&integration={$integrationId}&mode={$mode}&page={$next}&queued={$queuedTotal}&skipped={$skippedTotal}";
        }
        $done = true;
    } catch (\Throwable $e) {
        $flashErr = $e->getMessage();
    }
} elseif (isset($_GET['run'])) {
    $flashErr = 'Wybierz sklep WooCommerce do importu produktów.';
}

$PAGE_TITLE = 'Import produktów';
$PAGE_KEY   = 'products';
require __DIR__ . '/header.php';
?>

<?php if ($flashErr): ?><div class="flash err">❌ <?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

<?php if ($debug !== null): ?>
<div class="card">
    <strong>Diagnostyka — surowy produkt z API (HTTP <?= (int)$debug['status'] ?>)</strong>
    <p style="font-size:12px;color:#888;margin:6px 0">Sprawdź, czy są pola <code>description</code>, <code>short_description</code>, <code>images</code>. Jeśli puste tutaj — sklep ich nie zwraca.</p>
    <pre style="background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;overflow:auto;font-size:11px;max-height:420px"><?= htmlspecialchars($debug['body']) ?></pre>
</div>
<?php endif; ?>

<?php if ($done): ?>
    <div class="flash ok">
        ✅ Import zakończony — <?= htmlspecialchars(mb_strtolower(IMPORT_MODES[$mode] ?? '')) ?>.
        Dodano do kolejki <strong><?= $queuedTotal ?></strong> produktów<?php if ($skippedTotal > 0): ?>,
        pominięto <strong><?= $skippedTotal ?></strong> <?= $mode === 'new' ? '(już są w magazynie)' : '(brak w magazynie)' ?><?php endif; ?>.
        Worker zapisuje je w tle do <a href="products.php">Produktów</a> — postęp poniżej.
    </div>
    <div class="card"><?php $progressFetching = false; include __DIR__ . '/_import_progress.php'; ?></div>
<?php elseif (isset($_GET['run']) && !$flashErr): ?>
    <?php if ($nextUrl): ?>
        <meta http-equiv="refresh" content="1;url=<?= htmlspecialchars($nextUrl) ?>">
        <script>setTimeout(function () { location.href = <?= json_encode($nextUrl) ?>; }, 400);</script>
    <?php endif; ?>
    <div class="card">
        <strong>Import w toku — pobieram produkty ze sklepu…</strong>
        <p>Strona <?= $page ?>, dodano dotąd: <strong><?= $queuedTotal ?></strong><?php if ($skippedTotal > 0): ?>, pominięto: <strong><?= $skippedTotal ?></strong><?php endif; ?>. Trwa pobieranie kolejnej strony…</p>
        <p style="color:#888;font-size:13px">Nie zamykaj tej karty. Strona odświeża się automatycznie.</p>
        <?php $progressFetching = true; include __DIR__ . '/_import_progress.php'; ?>
    </div>
<?php else: ?>
    <div class="card">
        <strong>Pobierz produkty z WooCommerce do magazynu CRM</strong>
        <p style="color:#888;font-size:13px;margin-top:6px">
            Produkty trafią do centralnego magazynu CRM (po SKU). To fundament — CRM staje się
            źródłem danych o produktach. Produkty bez SKU są pomijane.
        </p>
        <?php if ($wooIntegrations === []): ?>
            <div class="flash err" style="margin-top:10px">Brak aktywnych integracji WooCommerce. Dodaj sklep w <a href="integrations.php">Integracjach</a>.</div>
        <?php endif; ?>
        <form method="get" style="margin-top:14px">
            <input type="hidden" name="run" value="1">
            <input type="hidden" name="page" value="1">
            <label style="font-size:12px;color:#888;display:block">Sklep (integracja)</label>
            <select name="integration" id="selInt" required style="min-width:340px">
                <option value="">— wybierz sklep —</option>
                <?php foreach ($wooIntegrations as $wi): ?>
                    <option value="<?= (int)$wi['id'] ?>"><?= htmlspecialchars($wi['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <label style="font-size:12px;color:#888;display:block;margin-top:14px">Typ importu</label>
            <select name="mode" id="selMode" required style="min-width:340px;max-width:100%">
                <option value="">— wybierz —</option>
                <?php foreach (IMPORT_MODES as $mk => $ml): ?>
                    <option value="<?= $mk ?>"><?= htmlspecialchars($ml) ?></option>
                <?php endforeach; ?>
            </select>
            <p id="modeHelp" style="color:#555;font-size:13px;margin:8px 0 0;max-width:720px;min-height:1em"></p>
            <div id="modeWarn" class="flash err" style="display:none;margin-top:10px;max-width:720px">
                ⚠️ Ten tryb nadpisze nazwy, opisy, zdjęcia, kategorie i atrybuty produktów w magazynie danymi ze sklepu.
                Ręczne zmiany wprowadzone w CRM w tych polach zostaną utracone. Stany i ceny się nie zmienią.
            </div>
            <script>
            (function () {
                var help = <?= json_encode(IMPORT_MODE_HELP, JSON_UNESCAPED_UNICODE) ?>;
                var sel = document.getElementById('selMode');
                sel.addEventListener('change', function () {
                    document.getElementById('modeHelp').textContent = help[sel.value] || '';
                    document.getElementById('modeWarn').style.display = (sel.value === 'all' || sel.value === 'update') ? '' : 'none';
                });
            })();
            </script>

            <p style="margin-top:14px">
                <button class="btn" type="submit">Importuj produkty</button>
                <button class="btn secondary" type="button" onclick="var v=document.getElementById('selInt').value; if(!v){alert('Wybierz sklep.');return;} location.href='import_products.php?debug=1&integration='+v;">🔍 Pokaż surowy produkt (diagnostyka)</button>
            </p>
        </form>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
