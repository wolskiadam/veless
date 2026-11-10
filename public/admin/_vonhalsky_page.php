<?php
declare(strict_types=1);

/**
 * Wspólny początek stron Marketplace → InPost vonHalsky (wystawianie, oferty, kategorie, zwroty i reklamacje).
 * Strona ustawia $PAGE_TITLE i $PAGE_KEY, potem require tego pliku. Daje:
 *   $vhAccounts - aktywne konta vonHalsky, $vhAcc - wybrane konto (?acc=, domyślnie pierwsze),
 *   $vh (Services\Vonhalsky), $vhOffers (Services\VonhalskyOffers), $vhReturns (Services\VonhalskyReturns),
 *   vhAccountPicker() - wybór konta, gdy jest ich kilka.
 * Bez wtyczki albo bez konta pokazuje komunikat i kończy stronę.
 */

use Pase\Services\Vonhalsky;
use Pase\Services\VonhalskyOffers;
use Pase\Services\VonhalskyReturns;

/** @var PDO $pdo */
if (!isset($PAGE_TITLE)) {
    http_response_code(404);
    exit;
}

if (!Vonhalsky::available()) {
    require __DIR__ . '/header.php';
    echo '<div class="card"><p>Wtyczka InPost vonHalsky jest wyłączona albo usunięta. Włącz ją w Konfiguracja → Wtyczki (administrator).</p></div>';
    require __DIR__ . '/footer.php';
    exit;
}
Vonhalsky::migrate($pdo);
$vh = new Vonhalsky($pdo, null, new \Pase\Queue\Queue($pdo));
$vhOffers = new VonhalskyOffers($pdo, $vh);
$vhReturns = new VonhalskyReturns($pdo, $vh);
$vhAccounts = $vh->accounts();
$vhAcc = null;
foreach ($vhAccounts as $a) {
    if ($vhAcc === null || (int) $a['id'] === (int) ($_REQUEST['acc'] ?? 0)) {
        $vhAcc = $a;
    }
}
if ($vhAcc === null) {
    require __DIR__ . '/header.php';
    echo '<div class="card"><p style="margin:0">Nie ma aktywnego konta InPost vonHalsky. Dodaj je w Konfiguracja → Integracje, wpisz Client ID i Client Secret od InPost i połącz konto.</p>'
        . (canOpenPage('integration_edit.php') ? '<p style="margin:12px 0 0"><a class="btn" href="integration_edit.php?type=vonhalsky">+ Dodaj konto InPost vonHalsky</a></p>' : '') . '</div>';
    require __DIR__ . '/footer.php';
    exit;
}

/** Wybór sklepu vonHalsky (tylko przy kilku kontach); $keep - parametry adresu, które mają zostać. */
function vhAccountPicker(array $accounts, array $current, array $keep = []): string
{
    if (count($accounts) < 2) {
        return '';
    }
    $out = '<form method="get" style="display:flex;gap:8px;align-items:center;margin:0 0 12px"><span style="font-size:13px;color:var(--ink-2)">Sklep vonHalsky:</span>';
    foreach ($keep as $k => $v) {
        $out .= '<input type="hidden" name="' . htmlspecialchars((string) $k) . '" value="' . htmlspecialchars((string) $v) . '">';
    }
    $out .= '<select name="acc" onchange="this.form.submit()">';
    foreach ($accounts as $a) {
        $out .= '<option value="' . (int) $a['id'] . '"' . ((int) $a['id'] === (int) $current['id'] ? ' selected' : '') . '>' . htmlspecialchars((string) $a['name']) . '</option>';
    }
    return $out . '</select></form>';
}

/** Ostrzeżenie, gdy konto nie jest połączone (strony działają na danych w CRM, ale nic nie wyślą). */
function vhConnectionNotice(Vonhalsky $vh, array $acc): string
{
    $st = $vh->status($acc);
    if ($st['connected']) {
        return '';
    }
    return '<div class="flash err" style="margin-bottom:12px">Konto „' . htmlspecialchars((string) $acc['name']) . '” nie jest połączone z InPost - '
        . (canOpenPage('integration_edit.php') ? '<a href="integration_edit.php?id=' . (int) $acc['id'] . '">kliknij „Połącz z InPost vonHalsky”</a>' : 'połącz je na stronie integracji')
        . '. Bez tego nic nie pójdzie do vonHalsky.</div>';
}
