<?php
declare(strict_types=1);

/**
 * CRUD mapowań SKU (tabela product_mappings).
 * Bez tego dane trzeba było wpisywać w phpMyAdmin - to główny powód panelu.
 */

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

$canEdit  = canEdit();
$flashOk  = null;
$flashErr = null;

// --- Operacje zapisu (tylko admin/editor) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if (!$canEdit) {
        $flashErr = 'Brak uprawnień do edycji.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save') {
            $id        = (int) ($_POST['id'] ?? 0);
            $sku       = trim($_POST['sku'] ?? '');
            $wooProd   = $_POST['woo_product_id'] !== '' ? (int) $_POST['woo_product_id'] : null;
            $wooVar    = $_POST['woo_variant_id'] !== '' ? (int) $_POST['woo_variant_id'] : null;
            $allegro   = trim($_POST['allegro_offer_id'] ?? '');
            $allegro   = $allegro !== '' ? $allegro : null;

            if ($sku === '') {
                $flashErr = 'SKU jest wymagane.';
            } else {
                try {
                    if ($id > 0) {
                        $stmt = $pdo->prepare(
                            'UPDATE product_mappings
                             SET sku=:sku, woo_product_id=:wp, woo_variant_id=:wv, allegro_offer_id=:ao
                             WHERE id=:id'
                        );
                        $stmt->execute([':sku'=>$sku, ':wp'=>$wooProd, ':wv'=>$wooVar, ':ao'=>$allegro, ':id'=>$id]);
                        $flashOk = "Zaktualizowano mapowanie SKU {$sku}.";
                    } else {
                        $stmt = $pdo->prepare(
                            'INSERT INTO product_mappings (sku, woo_product_id, woo_variant_id, allegro_offer_id)
                             VALUES (:sku, :wp, :wv, :ao)'
                        );
                        $stmt->execute([':sku'=>$sku, ':wp'=>$wooProd, ':wv'=>$wooVar, ':ao'=>$allegro]);
                        $flashOk = "Dodano mapowanie SKU {$sku}.";
                    }
                } catch (\PDOException $e) {
                    $flashErr = str_contains($e->getMessage(), 'Duplicate')
                        ? "SKU {$sku} już istnieje."
                        : 'Błąd zapisu: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $pdo->prepare('DELETE FROM product_mappings WHERE id = ?')->execute([$id]);
            $flashOk = 'Usunięto mapowanie.';
        }
    }
}

// --- Wyszukiwanie + lista ---
$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    // Dwa różne placeholdery na tę samą wartość - PDO z wyłączoną emulacją nie obsługuje
    // użycia tego samego nazwanego placeholdera dwa razy w jednym zapytaniu.
    $stmt = $pdo->prepare(
        "SELECT * FROM product_mappings
         WHERE sku LIKE :q1 OR allegro_offer_id LIKE :q2 OR woo_product_id = :n
         ORDER BY sku ASC LIMIT 500"
    );
    $stmt->execute([':q1' => "%{$q}%", ':q2' => "%{$q}%", ':n' => ctype_digit($q) ? (int) $q : -1]);
} else {
    $stmt = $pdo->query('SELECT * FROM product_mappings ORDER BY sku ASC LIMIT 500');
}
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Edycja istniejącego wiersza?
$edit = null;
if (isset($_GET['edit'])) {
    $e = $pdo->prepare('SELECT * FROM product_mappings WHERE id = ?');
    $e->execute([(int) $_GET['edit']]);
    $edit = $e->fetch(PDO::FETCH_ASSOC) ?: null;
}

$PAGE_TITLE = 'Mapowania SKU';
$PAGE_KEY   = 'mappings';
require __DIR__ . '/header.php';
?>

<?php if ($flashOk): ?><div class="flash ok"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>
<?php if ($flashErr): ?><div class="flash err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

<?php if ($canEdit): ?>
<div class="card">
    <strong><?= $edit ? 'Edytuj mapowanie' : 'Dodaj mapowanie' ?></strong>
    <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-top:12px">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
        <div><label style="font-size:12px;color:#888;display:block">SKU *</label>
            <input name="sku" required value="<?= htmlspecialchars($edit['sku'] ?? '') ?>"></div>
        <div><label style="font-size:12px;color:#888;display:block">Woo product ID</label>
            <input name="woo_product_id" value="<?= htmlspecialchars((string)($edit['woo_product_id'] ?? '')) ?>"></div>
        <div><label style="font-size:12px;color:#888;display:block">Woo variant ID</label>
            <input name="woo_variant_id" value="<?= htmlspecialchars((string)($edit['woo_variant_id'] ?? '')) ?>"></div>
        <div><label style="font-size:12px;color:#888;display:block">Allegro offer ID</label>
            <input name="allegro_offer_id" value="<?= htmlspecialchars($edit['allegro_offer_id'] ?? '') ?>"></div>
        <button class="btn" type="submit"><?= $edit ? 'Zapisz' : 'Dodaj' ?></button>
        <?php if ($edit): ?><a class="btn secondary" href="mappings.php">Anuluj</a><?php endif; ?>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <form method="get" style="margin-bottom:14px">
        <input name="q" placeholder="Szukaj po SKU / offer ID / Woo ID" value="<?= htmlspecialchars($q) ?>" style="width:320px">
        <button class="btn secondary" type="submit">Szukaj</button>
        <?php if ($q !== ''): ?><a class="btn secondary" href="mappings.php">Wyczyść</a><?php endif; ?>
    </form>

    <table>
        <tr><th>SKU</th><th>Woo product</th><th>Woo variant</th><th>Allegro offer</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr>
        <?php if ($rows === []): ?>
            <tr><td colspan="5" style="color:#888">Brak mapowań. Dodaj pierwsze powyżej.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><strong><?= htmlspecialchars($r['sku']) ?></strong></td>
                <td><?= htmlspecialchars((string)($r['woo_product_id'] ?? '—')) ?></td>
                <td><?= htmlspecialchars((string)($r['woo_variant_id'] ?? '—')) ?></td>
                <td><?= htmlspecialchars($r['allegro_offer_id'] ?? '—') ?></td>
                <?php if ($canEdit): ?>
                <td style="text-align:right;white-space:nowrap">
                    <a class="btn secondary" href="?edit=<?= (int)$r['id'] ?>">Edytuj</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('Usunąć mapowanie <?= htmlspecialchars($r['sku']) ?>?')">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn danger" type="submit">Usuń</button>
                    </form>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
    </table>
    <p style="color:#888;font-size:12px;margin-top:10px">Wyświetlono maks. 500 wierszy.</p>
</div>

<?php require __DIR__ . '/footer.php'; ?>
