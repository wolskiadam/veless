<?php
declare(strict_types=1);

/**
 * Wspólny początek stron Marketplace → Erli, które pracują na jednym koncie ERLI (wystawianie, kategorie, cenniki).
 * Strona ustawia $PAGE_TITLE i $PAGE_KEY, potem require tego pliku. Daje:
 *   $erliAccounts - konta ERLI (aktywne), $erliAcc - wybrane konto (?acc=, domyślnie pierwsze) albo null,
 *   $erli (Services\Erli), $erliOffers (Services\ErliOffers), erliAccountPicker() - wybór konta, gdy jest ich kilka.
 * Bez wtyczki albo bez konta pokazuje komunikat i kończy stronę.
 */

use Pase\Services\Erli;
use Pase\Services\ErliOffers;

/** @var PDO $pdo */
if (!isset($PAGE_TITLE)) {
    http_response_code(404);
    exit;
}

if (!Erli::available()) {
    require __DIR__ . '/header.php';
    echo '<div class="card"><p>Wtyczka ERLI jest wyłączona albo usunięta. Włącz ją w Konfiguracja → Wtyczki (administrator).</p></div>';
    require __DIR__ . '/footer.php';
    exit;
}
Erli::migrate($pdo);
$erli = new Erli($pdo);
$erliOffers = new ErliOffers($pdo, $erli);
$erliAccounts = $erli->accounts();
$erliAcc = null;
foreach ($erliAccounts as $a) {
    if ($erliAcc === null || (int) $a['id'] === (int) ($_REQUEST['acc'] ?? 0)) {
        $erliAcc = $a;
    }
}
if ($erliAcc === null) {
    require __DIR__ . '/header.php';
    echo '<div class="card"><p style="margin:0">Nie ma aktywnego konta ERLI. Dodaj je w Konfiguracja → Integracje i wklej klucz API z panelu sprzedawcy ERLI.</p>'
        . (canOpenPage('integration_edit.php') ? '<p style="margin:12px 0 0"><a class="btn" href="integration_edit.php?type=erli">+ Dodaj konto ERLI</a></p>' : '') . '</div>';
    require __DIR__ . '/footer.php';
    exit;
}

/** Wybór sklepu ERLI (tylko przy kilku kontach); $keep - parametry adresu, które mają zostać. */
function erliAccountPicker(array $accounts, array $current, string $self, array $keep = []): string
{
    if (count($accounts) < 2) {
        return '';
    }
    $out = '<form method="get" style="display:flex;gap:8px;align-items:center;margin:0 0 12px"><span style="font-size:13px;color:var(--ink-2)">Sklep ERLI:</span>';
    foreach ($keep as $k => $v) {
        $out .= '<input type="hidden" name="' . htmlspecialchars((string) $k) . '" value="' . htmlspecialchars((string) $v) . '">';
    }
    $out .= '<select name="acc" onchange="this.form.submit()">';
    foreach ($accounts as $a) {
        $out .= '<option value="' . (int) $a['id'] . '"' . ((int) $a['id'] === (int) $current['id'] ? ' selected' : '') . '>' . htmlspecialchars((string) $a['name']) . '</option>';
    }
    return $out . '</select></form>';
}
