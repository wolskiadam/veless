<?php
declare(strict_types=1);

/**
 * Magazyn → Lokalizacje: regały i półki („R1-B” = regał 1, półka B), kolejność chodzenia
 * po magazynie (tak asystent pakowania układa pozycje), etykiety z QR na półki (A4 i Zebra),
 * import z CSV. Przypisywanie produktów: tutaj (CSV), na liście produktów, w produkcie
 * albo telefonem w asystencie pakowania (📍 Półki: skan QR półki, potem kody produktów).
 */

use Pase\Repository\PrintJobRepository;
use Pase\Repository\SettingsRepository;
use Pase\Services\WarehouseLocations;

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

WarehouseLocations::migrate($pdo);
$svc = new WarehouseLocations($pdo);
$canEdit = canEdit();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    csrfCheck();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            $newId = $svc->create((string) ($_POST['rack'] ?? ''), (string) ($_POST['shelf'] ?? ''), (string) ($_POST['level'] ?? ''), (string) ($_POST['note'] ?? ''));
            flash('Dodano lokalizację ' . ($svc->find($newId)['code'] ?? '') . '.');
        } elseif ($action === 'create_many') {
            // Cały regał naraz: półki od–do (litery albo cyfry).
            $rack = (string) ($_POST['rack'] ?? '');
            $from = strtoupper(trim((string) ($_POST['from'] ?? 'A')));
            $to = strtoupper(trim((string) ($_POST['to'] ?? '')));
            $range = ctype_digit($from) && ctype_digit($to) ? range((int) $from, (int) $to)
                : (strlen($from) === 1 && strlen($to) === 1 && ctype_alpha($from . $to) ? range($from, $to) : []);
            if ($range === [] || count($range) > 30) {
                throw new \RuntimeException('Podaj zakres półek, np. A–F albo 1–8 (najwyżej 30).');
            }
            $made = [];
            foreach ($range as $shelf) {
                try {
                    $made[] = $svc->find($svc->create($rack, (string) $shelf))['code'];
                } catch (\RuntimeException) {
                    // już istnieje - pomijamy
                }
            }
            flash($made ? 'Dodano: ' . implode(', ', $made) . '.' : 'Wszystkie te półki już istnieją.', $made ? 'ok' : 'err');
        } elseif ($action === 'update') {
            $svc->update((int) $_POST['id'], (string) ($_POST['rack'] ?? ''), (string) ($_POST['shelf'] ?? ''), (string) ($_POST['level'] ?? ''),
                (string) ($_POST['note'] ?? ''), (int) ($_POST['sort'] ?? 0));
            flash('Zapisano lokalizację.');
            redirectAfterPost('locations.php');
        } elseif ($action === 'delete') {
            $loc = $svc->find((int) ($_POST['id'] ?? 0));
            $n = $svc->delete((int) ($_POST['id'] ?? 0));
            flash('Usunięto ' . ($loc['code'] ?? 'lokalizację') . ($n ? " — {$n} produktów jest teraz bez lokalizacji." : '.'));
        } elseif ($action === 'save_order') {
            $svc->saveOrder(array_map('intval', (array) ($_POST['sort'] ?? [])));
            flash('Zapisano kolejność. W tej kolejności asystent pakowania układa pozycje zamówienia.');
        } elseif ($action === 'import') {
            $content = (string) ($_POST['csv'] ?? '');
            if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
                $content .= "\n" . (string) file_get_contents($_FILES['csv_file']['tmp_name']);
            }
            $r = $svc->importCsv($content);
            $msg = "Przypisano lokalizację {$r['assigned']} produktom.";
            if ($r['created']) { $msg .= ' Nowe lokalizacje: ' . implode(', ', array_unique($r['created'])) . '.'; }
            if ($r['missing']) { $msg .= ' Nie znaleziono produktów: ' . implode(', ', array_slice($r['missing'], 0, 15)) . (count($r['missing']) > 15 ? '…' : '') . '.'; }
            if ($r['bad']) { $msg .= ' Pominięte wiersze (zły format): ' . count($r['bad']) . '.'; }
            flash($msg, $r['missing'] || $r['bad'] ? 'err' : 'ok');
        } elseif ($action === 'print_zebra') {
            $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
            $all = $svc->all();
            $pick = $ids ? array_values(array_filter($all, static fn($l) => in_array($l['id'], $ids, true))) : $all;
            if ($pick === []) {
                throw new \RuntimeException('Brak lokalizacji do wydruku.');
            }
            $s = new SettingsRepository($pdo);
            $dpi = (int) ($s->get('PRINT_AGENT_DPI', '203') ?? 203) ?: 203;
            $mm = (float) ($s->get('PRINT_AGENT_LABEL_MM', '100') ?? 100) ?: 100.0;
            $zpl = implode("\n", array_map(static fn($l) => WarehouseLocations::zpl($l, $dpi, $mm), $pick));
            (new PrintJobRepository($pdo))->enqueue('location', 'ZPL', $zpl, 'lokalizacje.zpl', 'zebra');
            flash('Wysłano ' . count($pick) . ' etykiet na drukarkę Zebra (program do drukowania musi być włączony).');
        }
    } catch (\RuntimeException $e) {
        flash($e->getMessage(), 'err');
    }
    redirectAfterPost();
}

$locations = $svc->all();
$edit = isset($_GET['edit']) ? $svc->find((int) $_GET['edit']) : null;
$withoutLoc = 0;
try {
    $withoutLoc = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE location_id IS NULL')->fetchColumn();
} catch (\PDOException) {
}

$PAGE_TITLE = 'Lokalizacje w magazynie';
$PAGE_KEY   = 'locations';
require __DIR__ . '/header.php';
?>
<style>
    .loc-grid { display:grid; grid-template-columns:minmax(0,1.35fr) minmax(0,1fr); gap:18px; align-items:start; }
    @media (max-width: 980px) { .loc-grid { grid-template-columns:1fr; } }
    .loc-code { font-family:var(--font-num); font-weight:700; font-size:16px; }
    .loc-form { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; }
    .loc-form label { display:flex; flex-direction:column; gap:4px; font-size:12px; color:var(--ink-2); font-weight:600; }
    .loc-form input { width:90px; }
    .loc-form input.wide { width:220px; }
    .loc-preview { font-family:var(--font-num); font-weight:800; font-size:18px; color:var(--accent-ink); background:var(--accent-soft); padding:6px 12px; border-radius:8px; }
    .loc-sort { width:70px; }
    .loc-help li { margin-bottom:6px; }
</style>

<div class="loc-grid">
<div>
    <?php if ($canEdit): ?>
    <div class="card">
        <strong><?= $edit ? 'Edycja lokalizacji ' . htmlspecialchars($edit['code']) : 'Dodaj lokalizację' ?></strong>
        <form method="post" class="loc-form" style="margin-top:10px" oninput="locPreview(this)">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
            <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>
            <label>Regał<input name="rack" required placeholder="np. 1" value="<?= htmlspecialchars(preg_replace('/^R(?=\d)/', '', (string) ($edit['rack'] ?? ''))) ?>"></label>
            <label>Półka<input name="shelf" required placeholder="np. B" value="<?= htmlspecialchars((string) ($edit['shelf'] ?? '')) ?>"></label>
            <label>Poziom <span style="font-weight:400">(opcjonalnie)</span><input name="level" placeholder="np. 3" value="<?= htmlspecialchars((string) ($edit['level'] ?? '')) ?>"></label>
            <label>Opis <span style="font-weight:400">(opcjonalnie)</span><input name="note" class="wide" maxlength="190" placeholder="np. świece 1 l" value="<?= htmlspecialchars((string) ($edit['note'] ?? '')) ?>"></label>
            <?php if ($edit): ?><label>Kolejność<input name="sort" type="number" value="<?= (int) $edit['sort'] ?>"></label><?php endif; ?>
            <span class="loc-preview" data-preview><?= htmlspecialchars($edit['code'] ?? 'R1-B') ?></span>
            <button class="btn" type="submit"><?= $edit ? 'Zapisz' : '＋ Dodaj' ?></button>
            <?php if ($edit): ?><a class="btn secondary" href="locations.php">Anuluj</a><?php endif; ?>
        </form>
        <?php if (!$edit): ?>
        <details style="margin-top:12px">
            <summary style="cursor:pointer;font-size:13px">Dodaj cały regał naraz</summary>
            <form method="post" class="loc-form" style="margin-top:10px">
                <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="create_many">
                <label>Regał<input name="rack" required placeholder="np. 2"></label>
                <label>Półki od<input name="from" required placeholder="A"></label>
                <label>do<input name="to" required placeholder="F"></label>
                <button class="btn secondary" type="submit">Dodaj półki</button>
            </form>
        </details>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
            <strong>Lokalizacje (<?= count($locations) ?>)</strong>
            <?php if ($locations !== []): ?>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
                <button type="button" class="btn secondary" onclick="locLabels()">🖨 Etykiety A4</button>
                <?php if ($canEdit): ?><button type="submit" class="btn secondary" form="locZebraForm">🖨 Na Zebrę</button><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <p style="color:#888;font-size:12px;margin:6px 0 10px">Etykiety drukują się dla zaznaczonych (bez zaznaczenia — dla wszystkich).
            Kolejność = trasa po magazynie: w takiej kolejności asystent pakowania pokazuje pozycje zamówienia.</p>
        <?php if ($locations === []): ?>
            <p style="color:#888">Brak lokalizacji. Dodaj pierwszą powyżej, np. regał 1, półka B → <strong>R1-B</strong>.</p>
        <?php else: ?>
        <form method="post" id="locOrderForm">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save_order">
        </form>
        <form method="post" id="locZebraForm" onsubmit="return locPickIds(this)">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="print_zebra">
            <span data-ids></span>
        </form>
        <table>
            <tr><th style="width:28px"><input type="checkbox" onclick="document.querySelectorAll('.loc-check').forEach(c=>c.checked=this.checked)"></th>
                <th>Kod</th><th>Miejsce</th><th>Produkty</th><th>Kolejność</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr>
            <?php foreach ($locations as $l): ?>
                <tr>
                    <td><input type="checkbox" class="loc-check" value="<?= $l['id'] ?>"></td>
                    <td><span class="loc-code"><?= htmlspecialchars($l['code']) ?></span></td>
                    <td><?= htmlspecialchars($l['label']) ?><?php if (!empty($l['note'])): ?><br><span style="color:#888;font-size:12px"><?= htmlspecialchars($l['note']) ?></span><?php endif; ?></td>
                    <td><a href="products.php?loc=<?= $l['id'] ?>"><?= $l['products'] ?></a></td>
                    <td><?php if ($canEdit): ?><input class="loc-sort" type="number" name="sort[<?= $l['id'] ?>]" form="locOrderForm" value="<?= (int) $l['sort'] ?>"><?php else: ?><?= (int) $l['sort'] ?><?php endif; ?></td>
                    <?php if ($canEdit): ?>
                    <td style="text-align:right;white-space:nowrap">
                        <a class="btn secondary" href="locations.php?edit=<?= $l['id'] ?>">Edytuj</a>
                        <form method="post" style="display:inline" onsubmit="return confirm(<?= htmlspecialchars(json_encode('Usunąć lokalizację ' . $l['code'] . '?' . ($l['products'] ? ' ' . $l['products'] . ' produktów zostanie bez lokalizacji.' : ''), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
                            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $l['id'] ?>">
                            <button class="btn danger" type="submit">Usuń</button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php if ($canEdit): ?><p style="margin-top:10px"><button class="btn secondary" type="submit" form="locOrderForm">Zapisz kolejność</button></p><?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div>
    <div class="card loc-help">
        <strong>Jak przypisać produkty do półek</strong>
        <p style="font-size:13px;margin:8px 0 4px">Produktów bez lokalizacji: <a href="products.php?loc=none"><strong><?= $withoutLoc ?></strong></a></p>
        <ol style="font-size:13px;padding-left:18px;margin:8px 0 0">
            <li><strong>Telefonem (najszybciej):</strong> wydrukuj etykiety półek, w asystencie pakowania na telefonie otwórz
                <em>📋 → 📍 Półki</em>, zeskanuj QR półki, a potem kody EAN produktów, które na niej stoją.</li>
            <li><strong>Na liście produktów:</strong> kolumna 📍 albo zaznacz produkty → „Lokalizacja zaznaczonych”.</li>
            <li><strong>W produkcie:</strong> pole „📍 Lokalizacja” w danych podstawowych.</li>
            <li><strong>Z pliku CSV</strong> — poniżej.</li>
        </ol>
    </div>

    <?php if ($canEdit): ?>
    <div class="card">
        <strong>Import z pliku (CSV)</strong>
        <p style="color:#888;font-size:12px;margin:6px 0 10px">Każdy wiersz: <code>SKU;lokalizacja</code> (albo EAN zamiast SKU), np. <code>GB-1L;R1-B</code>.
            Separator: średnik, przecinek lub tabulator. Brakujące lokalizacje (R1-B, R1-B-3) zostaną dodane same.</p>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="import">
            <textarea name="csv" rows="6" style="width:100%;font-family:var(--font-num);font-size:13px;padding:8px;border:1px solid var(--line);border-radius:7px" placeholder="GB-1L;R1-B&#10;MW-1L;R1-C&#10;5901234567890;R2-A-3"></textarea>
            <div style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap">
                <input type="file" name="csv_file" accept=".csv,.txt,text/csv,text/plain">
                <button class="btn" type="submit">Importuj</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>
</div>

<script>
function locPreview(form) {
    const p = (v) => String(v || '').trim().toUpperCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/Ł/g, 'L').replace(/[^A-Z0-9]/g, '');
    let r = p(form.rack.value); if (/^\d+$/.test(r)) r = 'R' + r;
    const parts = [r || '?', p(form.shelf.value) || '?'];
    if (p(form.level.value)) parts.push(p(form.level.value));
    form.querySelector('[data-preview]').textContent = parts.join('-');
}
function locSelected() { return Array.from(document.querySelectorAll('.loc-check:checked')).map((c) => c.value); }
function locLabels() {
    const ids = locSelected();
    window.open('location_labels.php' + (ids.length ? '?ids=' + ids.join(',') : ''), '_blank');
}
function locPickIds(form) {
    const box = form.querySelector('[data-ids]'); box.innerHTML = '';
    const ids = locSelected();
    ids.forEach((id) => { const h = document.createElement('input'); h.type = 'hidden'; h.name = 'ids[]'; h.value = id; box.append(h); });
    return confirm('Wydrukować na Zebrze ' + (ids.length || 'wszystkie') + ' etykiet półek?');
}
</script>

<?php require __DIR__ . '/footer.php'; ?>
