<?php
declare(strict_types=1);

/**
 * Marketplace → Erli → Szablony opisu: opisy ofert ERLI budowane z modułów tekst/grafika (edytor wspólny z Allegro,
 * _desc_templates.php). Szablon pasujący do kategorii produktu wypełnia opis przy wystawianiu (Services\ErliOffers).
 * ERLI przyjmuje ten sam prosty HTML co Allegro: nagłówki, akapity, pogrubienia i listy.
 */

use Pase\Services\Erli;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

if (!Erli::available()) {
    $PAGE_TITLE = 'Erli - szablony opisu';
    $PAGE_KEY   = 'erli';
    require __DIR__ . '/header.php';
    echo '<div class="card"><p>Wtyczka ERLI jest wyłączona albo usunięta. Włącz ją w Konfiguracja → Wtyczki (administrator).</p></div>';
    require __DIR__ . '/footer.php';
    exit;
}
Erli::migrate($pdo);

$DT = [
    'table'    => 'erli_desc_templates',
    'title'    => 'Erli - szablony opisu',
    'page_key' => 'erli',
    'self'     => 'erli_templates.php',
    'back'     => null,
    'color'    => '#4295b8',
    'market'   => 'ERLI',
    'hint'     => 'Przy wystawianiu oferty ERLI (Marketplace → Erli → Wystawianie) opis wypełni się z szablonu pasującego do kategorii produktu; bez szablonu idzie opis produktu z magazynu.',
];
require __DIR__ . '/_desc_templates.php';
