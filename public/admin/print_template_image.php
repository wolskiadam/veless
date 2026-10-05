<?php
declare(strict_types=1);

/**
 * Serwuje obrazek dołączony do szablonu wydruku (placeholder {{obrazek}}).
 * Plik leży poza web-rootem (storage/uploads), tak jak załączniki wiadomości -
 * patrz public/admin/attachment.php.
 */

use Pase\Repository\PrintTemplateRepository;
use Pase\Services\Attachments;

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

$id = (int) ($_GET['tpl'] ?? 0);
$tpl = (new PrintTemplateRepository($pdo))->find($id);
if ($tpl === null || empty($tpl['image_path'])) {
    http_response_code(404);
    exit('Nie znaleziono obrazka.');
}

$full = Attachments::absolutePath($tpl['image_path']);
if ($full === null || !is_file($full)) {
    http_response_code(404);
    exit('Plik niedostępny.');
}

$name = $tpl['image_name'] ?: basename($full);
header('Content-Type: ' . Attachments::mimeFor($name));
header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
header('Content-Length: ' . (string) filesize($full));
header('X-Content-Type-Options: nosniff');
readfile($full);
