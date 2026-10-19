<?php
declare(strict_types=1);

/**
 * Marketplace → Erli → Cenniki wysyłek: cenniki dostaw sklepu ERLI i wybór domyślnego dla nowych ofert.
 * Cenniki tworzy się w panelu sprzedawcy ERLI - API daje je tylko do odczytu; CRM pamięta listę (Services\ErliOffers)
 * i przy wystawianiu wpisuje nazwę cennika (deliveryPriceList). Kategoria magazynu może mieć własny cennik (Kategorie i parametry).
 */

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$PAGE_TITLE = 'Erli - cenniki wysyłek';
$PAGE_KEY   = 'erli';
require __DIR__ . '/_erli_page.php';
/** @var array $erliAcc @var array $erliAccounts @var \Pase\Services\ErliOffers $erliOffers */
$accId = (int) $erliAcc['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if (!canEdit()) {
        flash('Brak uprawnień do zmian.', 'err');
    } elseif (($_POST['action'] ?? '') === 'refresh') {
        try {
            $n = $erliOffers->refreshPriceLists($erliAcc);
            flash('Pobrano cenniki z ERLI: ' . $n . '.');
        } catch (\Throwable $e) {
            flash($e->getMessage(), 'err');
        }
    } elseif (($_POST['action'] ?? '') === 'default') {
        $erliOffers->setDefaultPriceList($accId, trim((string) ($_POST['price_list'] ?? '')));
        flash('Zapisano domyślny cennik nowych ofert.');
    }
    redirectAfterPost('erli_shipping.php?acc=' . $accId);
}

$lists = $erliOffers->priceLists($accId);
$default = $erliOffers->defaultPriceList($accId);
$fetchedAt = (string) ((new \Pase\Repository\SettingsRepository($pdo))->get('ERLI_' . $accId . '_PRICE_LISTS_AT', '') ?? '');
$e = static fn($v): string => htmlspecialchars((string) $v);
$money = static fn($gr): string => number_format(((int) $gr) / 100, 2, ',', ' ') . ' zł';
$listLabel = static fn(string $n): string => $n === '*' ? 'Domyślny cennik ERLI (*)' : $n;

require __DIR__ . '/header.php';
?>
<?= erliAccountPicker($erliAccounts, $erliAcc, 'erli_shipping.php') ?>

<div class="card">
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;justify-content:space-between">
        <p style="margin:0;font-size:13px;color:var(--ink-2);max-width:720px">
            Cenniki wysyłek tworzysz w panelu sprzedawcy ERLI. Tu pobierasz ich listę i wybierasz, z którym cennikiem CRM wystawia nowe oferty.
            Inny cennik dla wybranej kategorii ustawisz w Kategorie i parametry.
        </p>
        <?php if (canEdit()): ?>
        <form method="post" style="margin:0">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="refresh">
            <input type="hidden" name="acc" value="<?= $accId ?>">
            <button class="btn secondary" type="submit">↻ Pobierz cenniki z ERLI</button>
        </form>
        <?php endif; ?>
    </div>
    <?php if ($fetchedAt !== ''): ?><p style="font-size:12px;color:var(--ink-3);margin:8px 0 0">Ostatnio pobrane: <?= $e($fetchedAt) ?></p><?php endif; ?>
</div>

<?php if (!$lists): ?>
    <div class="card"><p style="margin:0;color:var(--ink-2)">Jeszcze nie pobrano cenników. Kliknij „Pobierz cenniki z ERLI”.</p></div>
<?php else: ?>
    <div class="card">
        <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 14px">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="default">
            <input type="hidden" name="acc" value="<?= $accId ?>">
            <label style="font-size:13px;color:var(--ink-2)">Cennik nowych ofert:</label>
            <select name="price_list">
                <option value="">— cennik domyślny sklepu w ERLI —</option>
                <?php foreach ($lists as $l): ?>
                    <option value="<?= $e($l['name']) ?>" <?= $default === (string) $l['name'] ? 'selected' : '' ?>><?= $e($listLabel((string) $l['name'])) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (canEdit()): ?><button class="btn" type="submit">Zapisz</button><?php endif; ?>
        </form>

        <?php foreach ($lists as $l): ?>
            <div style="border-top:1px solid var(--line);padding:12px 0">
                <strong><?= $e($listLabel((string) $l['name'])) ?></strong>
                <?php if ($default === (string) $l['name']): ?><span class="pill ok" style="margin-left:6px">nowe oferty</span><?php endif; ?>
                <?php if (!empty($l['prices'])): ?>
                    <table style="margin-top:8px">
                        <tr><th>Metoda dostawy</th><th style="text-align:right">Pierwsza sztuka</th><th style="text-align:right">Kolejna sztuka</th><th>Czas dostawy</th></tr>
                        <?php foreach ($l['prices'] as $p): $dt = $p['deliveryMethod']['deliveryTime'] ?? []; ?>
                            <tr>
                                <td><?= $e($p['deliveryMethod']['id'] ?? '') ?></td>
                                <td style="text-align:right"><?= $money($p['basePrice'] ?? 0) ?></td>
                                <td style="text-align:right"><?= $money($p['nextItemPrice'] ?? 0) ?></td>
                                <td><?= isset($dt['minPeriod']) ? $e($dt['minPeriod'] . '–' . ($dt['maxPeriod'] ?? $dt['minPeriod']) . (($dt['unit'] ?? 'days') === 'hours' ? ' godz.' : ' dni')) : '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
