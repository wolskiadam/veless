<?php
declare(strict_types=1);

/** Zdjęcie paczki dla sparowanego telefonu: ?id=<zamówienie>&n=<numer>. */

use Pase\Services\Packing;

require __DIR__ . '/_device.php';

$path = $packDevice !== null && $packAccess !== 'hidden'
    ? $packing->photoPath((int) ($_GET['id'] ?? 0), (int) ($_GET['n'] ?? -1)) : null;
if ($path === null) {
    http_response_code(404);
    exit('Nie ma takiego zdjęcia.');
}
Packing::streamPhoto($path);
