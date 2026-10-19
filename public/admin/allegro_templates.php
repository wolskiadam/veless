<?php
declare(strict_types=1);

/**
 * Szablony OPISU oferty Allegro (Konfiguracja → Integracje → Allegro → Szablony opisu).
 * Edytor wspólny z ERLI: _desc_templates.php. Szablon przypisujesz do kategorii produktów -
 * przy wystawianiu oferty opis wypełni się automatycznie.
 */

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$DT = [
    'table'    => 'allegro_desc_templates',
    'title'    => 'Szablony opisu Allegro',
    'page_key' => 'integrations',
    'self'     => 'allegro_templates.php',
    'back'     => ['href' => 'integrations.php', 'label' => 'Integracje'],
    'color'    => '#ff5a00',
    'market'   => 'Allegro',
    'hint'     => 'Przy przygotowaniu oferty Allegro opis wypełni się automatycznie z szablonu pasującego do kategorii produktu (Magazyn → Produkt → Marketplace → Allegro).',
];
require __DIR__ . '/_desc_templates.php';
