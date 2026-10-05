<?php
declare(strict_types=1);

/**
 * Powiązania ofert Allegro z produktami magazynu (offer_id ↔ SKU).
 *
 * Skąd bierze się dopasowanie: w ofercie Allegro pole `external.id` to własny
 * identyfikator sprzedawcy — u większości sprzedawców jest tam SKU. Pobieramy
 * więc oferty i szukamy produktu o takim SKU w magazynie. Gdy `external.id`
 * jest puste, próbujemy jeszcze dopasować po dokładnej nazwie oferty — takie
 * dopasowanie oznaczamy jako słabsze, bo nazwa handlowa bywa inna niż magazynowa.
 *
 * Zapis idzie do product_mappings.allegro_offer_id — tej samej kolumny, z której
 * korzysta synchronizacja stanów (StockSyncHandler) i plakietki w magazynie.
 *
 * Świadomie w dwóch krokach: najpierw podgląd, potem zapis zaznaczonych. Automat,
 * który sam nadpisuje mapowania, potrafi po cichu podpiąć stan magazynowy jednego
 * produktu pod ofertę zupełnie innego.
 */

use PasePlugin\Allegro\AllegroPlugin;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$config = require PASE_ROOT . '/config/config.php';

// Ile ofert brać w jednym zapytaniu. Allegro pozwala na więcej, ale przy 100
// odpowiedzi wracają szybciej i łagodniej obchodzą się z limitami hostingu.
const OFFERS_PER_REQUEST = 100;
const MAX_OFFER_PAGES    = 20;     // twardy limit: 2000 ofert na jedno pobranie

$flashErr = null;

// ============================================================
//  Zapis zaznaczonych powiązań
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();

    $pairs = $_POST['link'] ?? [];
    $saved = 0;

    if (is_array($pairs) && $pairs !== []) {
        // Tabela ma UNIQUE po SKU, więc istniejący wiersz dostaje tylko nowy
        // offer_id — powiązania z Woo zostają nietknięte.
        $stmt = $pdo->prepare(
            'INSERT INTO product_mappings (sku, allegro_offer_id)
             VALUES (:sku, :offer)
             ON DUPLICATE KEY UPDATE allegro_offer_id = VALUES(allegro_offer_id)'
        );

        foreach ($pairs as $sku => $offerId) {
            $sku     = trim((string) $sku);
            $offerId = trim((string) $offerId);
            if ($sku === '' || $offerId === '') {
                continue;
            }
            $stmt->execute([':sku' => $sku, ':offer' => $offerId]);
            $saved++;
        }
    }

    flash(
        $saved > 0 ? "Zapisano powiązania: {$saved}." : 'Nie zaznaczono żadnego powiązania.',
        $saved > 0 ? 'ok' : 'err'
    );
    redirectAfterPost('allegro_links.php');
}

// ============================================================
//  Pobranie ofert i dopasowanie (tylko na żądanie — to ruch do API Allegro)
// ============================================================
$scan       = ($_GET['scan'] ?? '') === '1';
$status     = preg_replace('/[^A-Z]/', '', strtoupper((string) ($_GET['status'] ?? 'ACTIVE'))) ?: 'ACTIVE';
$matched    = [];   // gotowe do powiązania
$already    = [];   // już powiązane tym samym offer_id
$unmatched  = [];   // oferta bez odpowiednika w magazynie
$offersSeen = 0;

if ($scan) {
    $client = AllegroPlugin::makeClient($pdo, $config['allegro'] ?? []);

    // Magazyn do pamięci: dopasowanie robimy w PHP, bo potrzebujemy dwóch prób
    // (SKU i nazwa), a produktów są setki, nie miliony.
    $bySku = $byName = [];
    foreach ($pdo->query('SELECT sku, name FROM products')->fetchAll(PDO::FETCH_ASSOC) as $product) {
        $sku = trim((string) $product['sku']);
        if ($sku === '') {
            continue;
        }
        $bySku[mb_strtolower($sku)] = $sku;

        $name = trim((string) ($product['name'] ?? ''));
        if ($name !== '') {
            // Przy powtórzonych nazwach nie zgadujemy — taka nazwa wypada z dopasowania.
            $key = mb_strtolower($name);
            $byName[$key] = array_key_exists($key, $byName) ? null : $sku;
        }
    }

    // Aktualne mapowania — żeby odróżnić "nowe" od "już jest".
    $existing = [];
    foreach ($pdo->query('SELECT sku, allegro_offer_id FROM product_mappings')->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $existing[(string) $m['sku']] = (string) ($m['allegro_offer_id'] ?? '');
    }

    for ($page = 0; $page < MAX_OFFER_PAGES; $page++) {
        $result = $client->listOffers([
            'publication.status' => $status,
            'limit'              => OFFERS_PER_REQUEST,
            'offset'             => $page * OFFERS_PER_REQUEST,
        ]);

        if (!$result['ok']) {
            $flashErr = $result['message'] !== '' ? $result['message'] : 'Nie udało się pobrać ofert z Allegro.';
            break;
        }

        $offers = $result['offers'];
        if ($offers === []) {
            break;
        }

        foreach ($offers as $offer) {
            $offersSeen++;
            $offerId   = (string) ($offer['id'] ?? '');
            $offerName = trim((string) ($offer['name'] ?? ''));
            $external  = trim((string) ($offer['external']['id'] ?? ''));
            if ($offerId === '') {
                continue;
            }

            $sku = null;
            $how = '';
            if ($external !== '' && isset($bySku[mb_strtolower($external)])) {
                $sku = $bySku[mb_strtolower($external)];
                $how = 'external.id';
            } elseif ($offerName !== '' && !empty($byName[mb_strtolower($offerName)])) {
                $sku = $byName[mb_strtolower($offerName)];
                $how = 'nazwa';
            }

            $entry = [
                'offer_id' => $offerId,
                'name'     => $offerName,
                'external' => $external,
                'sku'      => $sku,
                'how'      => $how,
                'image'    => (string) ($offer['primaryImage']['url'] ?? ''),
                'replaces' => '',
            ];

            if ($sku === null) {
                $unmatched[] = $entry;
            } elseif (($existing[$sku] ?? '') === $offerId) {
                $already[] = $entry;
            } else {
                $entry['replaces'] = $existing[$sku] ?? '';
                $matched[] = $entry;
            }
        }

        if (count($offers) < OFFERS_PER_REQUEST) {
            break;   // ostatnia strona
        }
    }
}

$PAGE_TITLE = 'Powiązania Allegro';
$PAGE_KEY   = 'allegro';
require __DIR__ . '/header.php';

$e = static fn($v) => htmlspecialchars((string) $v);
?>

<?php if ($flashErr !== null): ?><div class="flash err"><?= $e($flashErr) ?></div><?php endif; ?>

<div class="card">
    <strong>Powiązania ofert Allegro z magazynem</strong>
    <p style="color:#888;font-size:13px;margin:6px 0 14px">
        Pobiera Twoje oferty z Allegro i szuka dla nich produktów w magazynie. Dopasowuje po polu
        <code>external.id</code> w ofercie (tam zwykle wpisuje się własne SKU), a gdy jest puste —
        po dokładnej nazwie. Powiązania trafiają do mapowań SKU i korzysta z nich synchronizacja
        stanów oraz plakietki kanałów w magazynie.
    </p>

    <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="scan" value="1">
        <label style="font-size:13px;color:#888">Oferty ze statusem</label>
        <select name="status">
            <?php foreach (['ACTIVE' => 'Aktywne', 'ACTIVATING' => 'Aktywowane', 'ENDED' => 'Zakończone'] as $val => $label): ?>
                <option value="<?= $val ?>" <?= $status === $val ? 'selected' : '' ?>><?= $e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn" type="submit">🔄 Pobierz z Allegro i dopasuj</button>
        <a class="btn secondary" href="mappings.php">Mapowania SKU</a>
    </form>

    <?php if ($scan): ?>
        <p style="font-size:13px;margin-top:14px">
            Przejrzano ofert: <strong><?= (int) $offersSeen ?></strong> ·
            nowych powiązań: <strong><?= count($matched) ?></strong> ·
            już powiązanych: <strong><?= count($already) ?></strong> ·
            bez odpowiednika: <strong><?= count($unmatched) ?></strong>
        </p>
    <?php endif; ?>
</div>

<?php if ($scan && $matched !== []): ?>
<div class="card">
    <strong>Do powiązania (<?= count($matched) ?>)</strong>
    <p style="color:#888;font-size:13px;margin:6px 0 12px">
        Zaznaczone zostaną zapisane w mapowaniach. Dopasowania po nazwie sprawdź uważniej —
        nazwa oferty bywa inna niż nazwa produktu i łatwiej tu o pomyłkę niż przy SKU.
    </p>

    <form method="post">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <table>
            <tr>
                <th style="width:28px"><input type="checkbox" checked onclick="document.querySelectorAll('.link-check').forEach(c=>c.checked=this.checked)"></th>
                <th style="width:48px"></th>
                <th>Oferta na Allegro</th>
                <th>SKU w magazynie</th>
                <th>Dopasowano po</th>
            </tr>
            <?php foreach ($matched as $m): ?>
                <tr>
                    <td>
                        <input type="checkbox" class="link-check" checked
                               name="link[<?= $e($m['sku']) ?>]" value="<?= $e($m['offer_id']) ?>">
                    </td>
                    <td>
                        <?php if ($m['image'] !== ''): ?>
                            <img src="<?= $e($m['image']) ?>" alt="" loading="lazy"
                                 style="width:40px;height:40px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;display:block">
                        <?php endif; ?>
                    </td>
                    <td>
                        <div><?= $e($m['name'] !== '' ? $m['name'] : '(bez tytułu)') ?></div>
                        <div style="color:#aaa;font-size:11px">
                            ID: <a href="https://allegro.pl/oferta/<?= rawurlencode($m['offer_id']) ?>" target="_blank" rel="noopener"><?= $e($m['offer_id']) ?></a>
                            <?php if ($m['external'] !== ''): ?> · external.id: <?= $e($m['external']) ?><?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <strong><?= $e($m['sku']) ?></strong>
                        <?php if ($m['replaces'] !== ''): ?>
                            <div style="color:#b06000;font-size:11px">zastąpi ofertę <?= $e($m['replaces']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($m['how'] === 'external.id'): ?>
                            <span class="pill ok" style="font-size:11px">SKU w ofercie</span>
                        <?php else: ?>
                            <span class="pill warn" style="font-size:11px">nazwę — sprawdź</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p style="margin-top:14px"><button class="btn" type="submit">Zapisz zaznaczone powiązania</button></p>
    </form>
</div>
<?php endif; ?>

<?php if ($scan && $unmatched !== []): ?>
<div class="card">
    <strong>Bez odpowiednika w magazynie (<?= count($unmatched) ?>)</strong>
    <p style="color:#888;font-size:13px;margin:6px 0 12px">
        Te oferty nie mają w magazynie produktu o pasującym SKU ani nazwie. Najczęstszy powód:
        w ofercie nie jest wypełnione pole „sygnatura” (external.id). Można je uzupełnić na Allegro
        albo powiązać ręcznie w Mapowaniach SKU.
    </p>
    <table>
        <tr><th style="width:48px"></th><th>Oferta</th><th>external.id</th></tr>
        <?php foreach (array_slice($unmatched, 0, 100) as $u): ?>
            <tr>
                <td><?php if ($u['image'] !== ''): ?><img src="<?= $e($u['image']) ?>" alt="" loading="lazy" style="width:36px;height:36px;object-fit:cover;border-radius:6px;display:block"><?php endif; ?></td>
                <td>
                    <?= $e($u['name'] !== '' ? $u['name'] : '(bez tytułu)') ?>
                    <div style="color:#aaa;font-size:11px">ID: <?= $e($u['offer_id']) ?></div>
                </td>
                <td style="font-size:12px;color:#888"><?= $u['external'] !== '' ? $e($u['external']) : '— puste —' ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php if (count($unmatched) > 100): ?>
        <p style="color:#888;font-size:12px;margin-top:8px">Pokazano pierwszych 100 z <?= count($unmatched) ?>.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($scan && $already !== []): ?>
<div class="card">
    <strong>Już powiązane (<?= count($already) ?>)</strong>
    <p style="color:#888;font-size:13px;margin:6px 0 0">
        Te oferty mają już zapisane to samo powiązanie — nic do zrobienia.
    </p>
</div>
<?php endif; ?>

<?php if (!$scan): ?>
<div class="card" style="color:#888;font-size:13px">
    Kliknij „Pobierz z Allegro i dopasuj”, żeby zobaczyć propozycje powiązań.
    Samo pobranie niczego nie zapisuje — zmiany zapisujesz dopiero przyciskiem pod listą.
</div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
