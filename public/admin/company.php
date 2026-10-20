<?php
declare(strict_types=1);

/**
 * Ustawienia firmy (Konfiguracja → Firma).
 * Płatnik VAT: przy wyłączonym marża i statystyki liczą kwoty bez podziału na netto i brutto
 * (CompanySettings, OrderMargins). Zapis w tabeli settings (COMPANY_VAT_PAYER).
 * BaseLinker: linki „wystawione w BaseLinker? Powiąż z wFirma” na stronie zamówienia (COMPANY_BASELINKER_WFIRMA_LINKS).
 */

use Pase\Services\CompanySettings;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if (($_POST['form'] ?? '') === 'baselinker') {
        $show = ($_POST['baselinker_links'] ?? '0') === '1';
        CompanySettings::setShowBaselinkerLinks($pdo, $show);
        flash($show ? 'Zapisano: linki do powiązania dokumentów z BaseLinkera są włączone.' : 'Zapisano: linki do powiązania dokumentów z BaseLinkera są wyłączone.');
        redirectAfterPost();
    }
    $payer = ($_POST['vat_payer'] ?? '1') !== '0';
    $changed = $payer !== CompanySettings::isVatPayer($pdo);
    CompanySettings::setVatPayer($pdo, $payer);
    flash(($payer ? 'Zapisano: firma jest czynnym podatnikiem VAT.' : 'Zapisano: firma nie jest płatnikiem VAT.')
        . ($changed ? ' Marża zamówień przeliczy się przy najbliższym otwarciu Statystyk.' : ''));
    redirectAfterPost();
}

$vatPayer = CompanySettings::isVatPayer($pdo);
$baselinkerLinks = CompanySettings::showBaselinkerLinks($pdo);

$PAGE_TITLE = 'Firma';
$PAGE_KEY   = 'company';
require __DIR__ . '/header.php';
?>

<div class="card">
    <strong>Podatek VAT</strong>
    <form method="post" style="margin-top:10px">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <label style="display:flex;gap:8px;align-items:flex-start;margin-bottom:10px">
            <input type="radio" name="vat_payer" value="1" <?= $vatPayer ? 'checked' : '' ?>>
            <span><strong>Czynny podatnik VAT</strong><br>
            <span style="color:#888;font-size:12px">Marża i statystyki w kwotach netto: od ceny sprzedaży i opłat Allegro odejmujemy VAT, koszt zakupu wpisujesz netto.</span></span>
        </label>
        <label style="display:flex;gap:8px;align-items:flex-start">
            <input type="radio" name="vat_payer" value="0" <?= $vatPayer ? '' : 'checked' ?>>
            <span><strong>Nie jestem płatnikiem VAT (zwolnienie)</strong><br>
            <span style="color:#888;font-size:12px">Bez podziału na netto i brutto: przychód to kwota zapłacona przez klienta, koszt zakupu i opłaty Allegro to kwoty faktycznie zapłacone.</span></span>
        </label>
        <p style="margin-top:12px"><button class="btn" type="submit">Zapisz</button></p>
    </form>
</div>

<div class="card">
    <strong>Dokumenty z BaseLinkera</strong>
    <form method="post" style="margin-top:10px">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="form" value="baselinker">
        <input type="hidden" name="baselinker_links" value="0">
        <label style="display:flex;gap:8px;align-items:flex-start">
            <input type="checkbox" name="baselinker_links" value="1" <?= $baselinkerLinks ? 'checked' : '' ?>>
            <span><strong>Pokazuj „Wystawione w BaseLinker? Powiąż z wFirma” na stronie zamówienia</strong><br>
            <span style="color:#888;font-size:12px">Link przy pasku „Trzeba wystawić fakturę” i przy „Brak paragonu” otwiera Synchronizację z wFirma dla dat zamówienia. Strona Zamówienia → Synchronizacja z wFirma działa zawsze, niezależnie od tej opcji.</span></span>
        </label>
        <p style="margin-top:12px"><button class="btn" type="submit">Zapisz</button></p>
    </form>
</div>

<?php require __DIR__ . '/footer.php'; ?>
