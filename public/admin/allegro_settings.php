<?php
declare(strict_types=1);

/**
 * Przekierowanie do prawdziwych ustawień Allegro. Wcześniej to była osobna
 * strona z formularzem - scalone z integration_edit.php (Konfiguracja →
 * Integracje → konto Allegro), żeby nie było dwóch miejsc do tego samego.
 * Link w menu (Marketplace → Allegro → Ustawienia) zostaje, tylko prowadzi dalej.
 */

use Pase\Repository\IntegrationAccountRepository;

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

$allegroAccount = null;
foreach ((new IntegrationAccountRepository($pdo))->all() as $acc) {
    if ($acc['type'] === 'allegro') {
        $allegroAccount = $acc;
        break;
    }
}

header('Location: ' . ($allegroAccount
    ? 'integration_edit.php?id=' . (int) $allegroAccount['id']
    : 'integration_edit.php?type=allegro'));
exit;
