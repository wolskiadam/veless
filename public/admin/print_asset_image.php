<?php
declare(strict_types=1);

/**
 * Serwuje plik z biblioteki wydruków (miniatura / podgląd w panelu).
 * Plik leży poza web-rootem (storage/uploads) - tak jak załączniki wiadomości,
 * patrz public/admin/attachment.php.
 *
 * GET: id=<id_pozycji>
 */

use Pase\Repository\PrintAssetRepository;
use Pase\Services\Attachments;

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

$asset = (new PrintAssetRepository($pdo))->find((int) ($_GET['id'] ?? 0));
if ($asset === null) {
    http_response_code(404);
    exit('Nie znaleziono pliku.');
}

$full = Attachments::absolutePath((string) $asset['file_path']);
if ($full === null || !is_file($full)) {
    http_response_code(404);
    exit('Plik niedostępny.');
}

$name = (string) $asset['file_name'];
header('Content-Type: ' . Attachments::mimeFor($name));
header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
header('Content-Length: ' . (string) filesize($full));
header('X-Content-Type-Options: nosniff');
readfile($full);
