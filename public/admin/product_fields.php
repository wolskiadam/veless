<?php
declare(strict_types=1);

/**
 * Definicje własnych pól produktu (globalne). Typ: tekst / liczba / lista wyboru.
 * Wartości wypełnia się per produkt na stronie podglądu produktu albo pobiera ze sklepu
 * WooCommerce (pole z kluczem Woo - meta_data produktu, np. pola ACF).
 */

use Pase\Repository\ProductFieldRepository;
use Pase\Repository\ProductRepository;
use Pase\Services\WooCustomFields;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

WooCustomFields::migrate($pdo);
$repo = new ProductFieldRepository($pdo);
$wooFields = WooCustomFields::withShopMedia($pdo);
$allCategories = array_keys((new ProductRepository($pdo))->distinctCategories());
$flashOk = $flashErr = null;

$types = ['text' => 'Tekst', 'number' => 'Liczba', 'select' => 'Lista wyboru', 'image' => 'Zdjęcie (link)'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $label = trim($_POST['label'] ?? '');
        $type  = in_array($_POST['type'] ?? '', array_keys($types), true) ? $_POST['type'] : 'text';
        $pos   = (int) ($_POST['position'] ?? 100);
        // Opcje listy: jedna na linię.
        $options = [];
        if ($type === 'select') {
            foreach (preg_split('/\r\n|\r|\n/', $_POST['options'] ?? '') as $o) {
                $o = trim($o);
                if ($o !== '') {
                    $options[] = $o;
                }
            }
        }
        // Kategorie, do których pole należy (puste = pole globalne).
        $cats = array_values(array_filter(array_map(
            static fn($c) => trim((string) $c),
            (array) ($_POST['categories'] ?? [])
        )));
        $wooKey = trim((string) ($_POST['woo_meta_key'] ?? ''));
        if ($label === '') {
            $flashErr = 'Podaj nazwę pola.';
        } else {
            if ($action === 'create') {
                $fid = $repo->create($label, $type, $options, $cats, $pos);
                $flashOk = 'Dodano pole.';
            } else {
                $fid = (int) $_POST['id'];
                $repo->update($fid, $label, $type, $options, $cats, $pos);
                $flashOk = 'Zapisano pole.';
            }
            $repo->setWooKey($fid, $wooKey);
            if ($wooKey !== '') {
                $n = $wooFields->backfill();
                $flashOk .= " Wartości ze sklepu uzupełnione w {$n} produktach (z ostatnio pobranych danych; kolejne odświeżenia produktów aktualizują je same).";
            }
        }
    } elseif ($action === 'add_from_woo') {
        $wooKey = trim((string) ($_POST['woo_meta_key'] ?? ''));
        if ($wooKey !== '' && !isset($wooFields->mapping()[$wooKey])) {
            $fid = $repo->create(WooCustomFields::labelFromKey($wooKey), ($_POST['type'] ?? '') === 'image' ? 'image' : 'text', [], [], 100);
            $repo->setWooKey($fid, $wooKey);
            $n = $wooFields->backfill();
            $flashOk = "Dodano pole „" . WooCustomFields::labelFromKey($wooKey) . "” pobierane ze sklepu (klucz {$wooKey}). Wypełnione w {$n} produktach. Nazwę, typ i kategorie możesz zmienić przez „Edytuj”.";
        }
    } elseif ($action === 'delete') {
        $repo->delete((int) $_POST['id']);
        $flashOk = 'Usunięto pole.';
    }
}

$edit = isset($_GET['edit']) ? $repo->find((int) $_GET['edit']) : null;
$fields = $repo->all();
$discovered = $wooFields->discover();

$PAGE_TITLE = 'Pola produktów';
$PAGE_KEY   = 'product_fields';
require __DIR__ . '/header.php';
?>

<?php if ($flashOk): ?><div class="flash ok"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="flash err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

<div class="card">
    <strong><?= $edit ? 'Edytuj pole' : 'Dodaj własne pole' ?></strong>
    <form method="post" style="margin-top:10px">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
        <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:end">
            <div><label style="font-size:12px;color:#888;display:block">Nazwa pola</label>
                <input name="label" required value="<?= htmlspecialchars($edit['label'] ?? '') ?>" placeholder="np. Kod producenta" style="width:240px"></div>
            <div><label style="font-size:12px;color:#888;display:block">Typ</label>
                <select name="type" id="fldType" onchange="document.getElementById('optWrap').style.display=this.value==='select'?'block':'none'">
                    <?php foreach ($types as $tk => $tl): ?>
                        <option value="<?= $tk ?>" <?= ($edit['type'] ?? 'text') === $tk ? 'selected' : '' ?>><?= htmlspecialchars($tl) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div><label style="font-size:12px;color:#888;display:block">Kolejność</label>
                <input name="position" type="number" value="<?= (int)($edit['position'] ?? 100) ?>" style="width:90px"></div>
        </div>
        <div style="margin-top:12px">
            <label style="font-size:12px;color:#888;display:block">Pobieraj ze sklepu WooCommerce — klucz pola (opcjonalnie)</label>
            <input name="woo_meta_key" list="wooKeys" value="<?= htmlspecialchars((string) ($edit['woo_meta_key'] ?? '')) ?>" placeholder="np. sklad (puste = wpisywane ręcznie w CRM)" style="width:360px">
            <datalist id="wooKeys"><?php foreach ($discovered as $d): ?><option value="<?= htmlspecialchars($d['key']) ?>"><?php endforeach; ?></datalist>
            <p style="font-size:12px;color:#888;margin:2px 0 0">Z kluczem wartość przychodzi ze sklepu przy każdym odświeżeniu produktu i nie da się jej zmienić w CRM (zmieniasz w WooCommerce). Do sklepu nic nie wysyłamy.</p>
        </div>
        <div id="optWrap" style="margin-top:12px;display:<?= ($edit['type'] ?? '') === 'select' ? 'block' : 'none' ?>">
            <label style="font-size:12px;color:#888;display:block">Opcje listy (jedna na linię)</label>
            <textarea name="options" rows="4" style="width:100%;max-width:360px" placeholder="np.&#10;bawełna&#10;len&#10;poliester"><?= htmlspecialchars(implode("\n", $edit['options'] ?? [])) ?></textarea>
        </div>
        <div style="margin-top:14px">
            <label style="font-size:12px;color:#888;display:block">Kategorie produktów</label>
            <p style="font-size:12px;color:#888;margin:2px 0 8px">Zaznacz kategorie, dla których to pole ma się pojawiać. Nic nie zaznaczone = pole globalne (widoczne we wszystkich produktach).</p>
            <?php if ($allCategories === []): ?>
                <p style="color:#888;font-size:13px">Brak zaimportowanych kategorii. Najpierw zaimportuj produkty z Woo.</p>
            <?php else: $editCats = $edit['categories'] ?? []; ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:4px 14px;max-height:220px;overflow:auto;border:1px solid #e5e7eb;border-radius:8px;padding:10px">
                    <?php foreach ($allCategories as $cat): ?>
                        <label style="font-size:13px;display:flex;align-items:center;gap:6px;cursor:pointer">
                            <input type="checkbox" name="categories[]" value="<?= htmlspecialchars($cat) ?>" <?= in_array($cat, $editCats, true) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($cat) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <p style="margin-top:14px">
            <button class="btn" type="submit"><?= $edit ? 'Zapisz' : 'Dodaj pole' ?></button>
            <?php if ($edit): ?><a class="btn secondary" href="product_fields.php">Anuluj</a><?php endif; ?>
        </p>
    </form>
</div>

<div class="card">
    <strong>Zdefiniowane pola</strong>
    <table style="margin-top:10px">
        <tr><th>Nazwa</th><th>Klucz</th><th>Ze sklepu</th><th>Typ</th><th>Opcje</th><th>Kategorie</th><th>Kolejność</th><th></th></tr>
        <?php if ($fields === []): ?>
            <tr><td colspan="8" style="color:#888">Brak własnych pól. Dodaj pierwsze powyżej.</td></tr>
        <?php endif; ?>
        <?php foreach ($fields as $f): ?>
            <tr>
                <td><strong><?= htmlspecialchars($f['label']) ?></strong></td>
                <td style="font-size:12px;color:#888"><code><?= htmlspecialchars($f['field_key']) ?></code></td>
                <td style="font-size:12px"><?= ($f['woo_meta_key'] ?? '') !== '' ? '🛒 <code>' . htmlspecialchars($f['woo_meta_key']) . '</code>' : '<span style="color:#888">ręcznie</span>' ?></td>
                <td><?= htmlspecialchars($types[$f['type']] ?? $f['type']) ?></td>
                <td style="font-size:12px;color:#555"><?= $f['type'] === 'select' ? htmlspecialchars(implode(', ', $f['options'])) : '—' ?></td>
                <td style="font-size:12px;color:#555"><?= ($f['categories'] ?? []) === [] ? '<span style="color:#1a73e8">Wszystkie</span>' : htmlspecialchars(implode(', ', $f['categories'])) ?></td>
                <td><?= (int)$f['position'] ?></td>
                <td style="text-align:right;white-space:nowrap">
                    <a class="btn secondary" href="?edit=<?= (int)$f['id'] ?>">Edytuj</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('Usunąć pole „<?= htmlspecialchars($f['label']) ?>”? Wartości w produktach pozostaną w danych, ale znikną z formularza.')">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                        <button class="btn danger" type="submit">Usuń</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    <p style="color:#888;font-size:12px;margin-top:10px">Wartości tych pól wypełniasz przy każdym produkcie (Magazyn → Produkty → Podgląd).</p>
</div>

<?php
$wooRow = static function (array $d): void { ?>
    <tr data-k="<?= htmlspecialchars(mb_strtolower($d['key'] . ' ' . mb_substr($d['sample'], 0, 200))) ?>">
        <td><code><?= htmlspecialchars($d['key']) ?></code></td>
        <td><?= (int) $d['count'] ?></td>
        <td style="font-size:12px;color:#555;max-width:420px;overflow-wrap:anywhere" title="Przykład z produktu <?= htmlspecialchars($d['sample_sku']) ?>"><?= htmlspecialchars(mb_strimwidth(strip_tags($d['sample']), 0, 140, '…')) ?></td>
        <td style="text-align:right;white-space:nowrap">
            <?php if ($d['field'] !== null): ?>
                <span style="color:#16a34a;font-size:12px">✓ pole <code><?= htmlspecialchars($d['field']) ?></code></span>
            <?php else: ?>
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="add_from_woo">
                    <input type="hidden" name="woo_meta_key" value="<?= htmlspecialchars($d['key']) ?>">
                    <button class="btn secondary" type="submit">+ Dodaj jako własne pole</button>
                </form>
                <?php if (WooCustomFields::looksLikeImage($d['sample'], $d['key'])): ?>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="add_from_woo">
                        <input type="hidden" name="type" value="image">
                        <input type="hidden" name="woo_meta_key" value="<?= htmlspecialchars($d['key']) ?>">
                        <button class="btn secondary" type="submit" title="Wartość wygląda na zdjęcie (numer z biblioteki mediów) - CRM zamieni ją na link do pliku">+ Jako zdjęcie</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </td>
    </tr>
<?php };
$wooMain = array_values(array_filter($discovered, static fn($d) => !$d['technical']));
$wooTech = array_values(array_filter($discovered, static fn($d) => $d['technical']));
?>
<div class="card">
    <strong>Pola znalezione w sklepie WooCommerce</strong>
    <p style="font-size:12px;color:#888;margin:4px 0 8px">Dodatkowe pola produktów, które zwraca API sklepu (np. pola ACF / JetEngine / „Własne pola” WordPressa), z ostatnio pobranych danych produktów. Jeśli brakuje świeżo dodanych pól, uruchom import produktów albo „Pobierz ponownie ze sklepu” na produkcie.</p>
    <?php if ($discovered === []): ?>
        <p style="color:#888;font-size:13px">Sklep nie zwrócił żadnych dodatkowych pól w pobranych produktach.</p>
    <?php else: ?>
        <input type="search" id="wooFilter" placeholder="Szukaj pola, np. image albo color" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-form-type="other" style="width:320px;margin-bottom:8px"
               oninput="var q=this.value.toLowerCase();document.querySelectorAll('.woo-meta tr[data-k]').forEach(function(r){r.style.display=r.dataset.k.indexOf(q)>-1?'':'none'});if(q)document.querySelectorAll('.woo-meta-more').forEach(function(d){d.open=true})">
        <table class="woo-meta">
            <tr><th>Klucz w sklepie</th><th>Produktów</th><th>Przykładowa wartość</th><th></th></tr>
            <?php foreach ($wooMain as $d) { $wooRow($d); } ?>
            <?php if ($wooMain === []): ?><tr><td colspan="4" style="color:#888">Brak pól opisowych — tylko techniczne (niżej).</td></tr><?php endif; ?>
        </table>
        <?php if ($wooTech !== []): ?>
            <details class="woo-meta-more" style="margin-top:10px">
                <summary style="cursor:pointer;font-size:13px">Pola techniczne wtyczek (klucz od „_”) — <?= count($wooTech) ?></summary>
                <table class="woo-meta" style="margin-top:8px">
                    <tr><th>Klucz w sklepie</th><th>Produktów</th><th>Przykładowa wartość</th><th></th></tr>
                    <?php foreach ($wooTech as $d) { $wooRow($d); } ?>
                </table>
            </details>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/footer.php'; ?>
