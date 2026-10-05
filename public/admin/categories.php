<?php
declare(strict_types=1);

/**
 * Przegląd kategorii produktów (zaimportowanych z Woo) wraz z liczbą produktów
 * oraz własnymi polami przypisanymi do każdej kategorii.
 */

use Pase\Repository\ProductFieldRepository;
use Pase\Repository\ProductRepository;

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

$categories = (new ProductRepository($pdo))->distinctCategories(); // nazwa => liczba produktów
$fields     = (new ProductFieldRepository($pdo))->all();

// Pola globalne (bez kategorii) - dotyczą wszystkich produktów.
$globalFields = array_values(array_filter($fields, static fn($f) => ($f['categories'] ?? []) === []));

$PAGE_TITLE = 'Kategorie produktów';
$PAGE_KEY   = 'categories';
require __DIR__ . '/header.php';
?>

<div class="card">
    <strong>Kategorie produktów</strong>
    <p style="color:#888;font-size:13px;margin:6px 0 0">
        Kategorie pochodzą z zaimportowanych produktów (z WooCommerce). Własne pola możesz przypisać do kategorii
        w <a href="product_fields.php">Pola produktów</a> — przy produkcie pokazują się tylko pola jego kategorii oraz pola globalne.
    </p>
</div>

<?php if ($globalFields !== []): ?>
    <div class="card">
        <strong>Pola globalne (wszystkie kategorie)</strong>
        <div style="margin-top:8px">
            <?php foreach ($globalFields as $f): ?>
                <span class="pill" style="margin:2px;background:#e8f0fe;color:#1a73e8"><?= htmlspecialchars($f['label']) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <strong>Lista kategorii (<?= count($categories) ?>)</strong>
    <table style="margin-top:10px">
        <tr><th>Kategoria</th><th style="text-align:right">Produkty</th><th>Przypisane pola własne</th></tr>
        <?php if ($categories === []): ?>
            <tr><td colspan="3" style="color:#888">Brak kategorii. Zaimportuj produkty z Woo (Magazyn → Import produktów).</td></tr>
        <?php endif; ?>
        <?php foreach ($categories as $cat => $cnt): ?>
            <?php
            $catFields = array_values(array_filter(
                $fields,
                static fn($f) => in_array($cat, $f['categories'] ?? [], true)
            ));
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($cat) ?></strong></td>
                <td style="text-align:right">
                    <a href="products.php?q=<?= urlencode($cat) ?>"><?= (int)$cnt ?></a>
                </td>
                <td>
                    <?php if ($catFields === []): ?>
                        <span style="color:#888;font-size:13px">— brak własnych pól</span>
                    <?php else: ?>
                        <?php foreach ($catFields as $f): ?>
                            <span class="pill muted" style="margin:2px"><?= htmlspecialchars($f['label']) ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <a href="product_fields.php" style="font-size:12px;color:#1a73e8;margin-left:6px">+ przypisz pole</a>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php require __DIR__ . '/footer.php'; ?>
