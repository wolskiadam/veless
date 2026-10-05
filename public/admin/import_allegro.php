<?php
declare(strict_types=1);

/**
 * Przekierowanie do wspólnej zakładki importu (Woo/Allegro w jednym miejscu).
 * Logika przeniesiona do import_orders.php - ten plik zostaje, żeby stare
 * linki/zakładki w przeglądarce nie umierały.
 */

require __DIR__ . '/auth.php';

$qs = $_GET;
$qs['source'] = 'allegro';
header('Location: import_orders.php?' . http_build_query($qs));
exit;
