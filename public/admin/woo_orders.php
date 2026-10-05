<?php
declare(strict_types=1);

/**
 * Zamówienia Woo są teraz stroną główną panelu (index.php).
 * Ten plik pozostaje jako przekierowanie dla starych zakładek/linków.
 */

$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: index.php' . ($qs !== '' ? '?' . $qs : ''));
exit;
