<?php
declare(strict_types=1);

/** Zdjęcie paczki z asystenta pakowania: ?id=<zamówienie>&n=<numer zdjęcia>. */

use Pase\Services\Packing;

require __DIR__ . '/auth.php';

Packing::migrate($pdo);
$path = (new Packing($pdo))->photoPath((int) ($_GET['id'] ?? 0), (int) ($_GET['n'] ?? -1));
if ($path === null) {
    http_response_code(404);
    exit('Nie ma takiego zdjęcia.');
}
Packing::streamPhoto($path);
