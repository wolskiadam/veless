<?php
declare(strict_types=1);

/**
 * Wysyła plik z biblioteki wydruków do kolejki lokalnego agenta druku.
 * Odpowiednik attachment.php?agent=1, tylko dla plików niezwiązanych z zamówieniem.
 *
 * POST: parametry URL id=<id_pozycji>&target=zebra|a4&copies=<1..50>, csrf w treści.
 */

use Pase\Repository\PrintAssetRepository;
use Pase\Repository\PrintJobRepository;
use Pase\Services\Attachments;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
requirePrintPost();
/** @var PDO $pdo */

header('Content-Type: application/json; charset=utf-8');

$asset = (new PrintAssetRepository($pdo))->find((int) ($_GET['id'] ?? 0));
if ($asset === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Nie znaleziono pliku w bibliotece.'], JSON_UNESCAPED_UNICODE);
    return;
}

$full = Attachments::absolutePath((string) $asset['file_path']);
if ($full === null || !is_file($full)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Plik zniknął z dysku serwera.'], JSON_UNESCAPED_UNICODE);
    return;
}

$content = file_get_contents($full);
if ($content === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Nie udało się odczytać pliku.'], JSON_UNESCAPED_UNICODE);
    return;
}

$target = ($_GET['target'] ?? 'zebra') === 'a4' ? 'a4' : 'zebra';
// Kopie bierzemy z żądania (pole przy każdej pozycji), a nie z globalnego
// ustawienia liczby kopii etykiety - tu decyduje to, ile sztuk akurat pakujesz.
$copies = max(1, min(50, (int) ($_GET['copies'] ?? 1)));
$format = strtoupper(pathinfo((string) $asset['file_name'], PATHINFO_EXTENSION));

$jobs    = new PrintJobRepository($pdo);
$firstId = null;
// Obrazka ani PDF-a nie da się powielić sklejeniem bajtów - każda kopia to
// osobne zadanie w kolejce.
for ($copy = 0; $copy < $copies; $copy++) {
    $jobId = $jobs->enqueue('asset', $format, $content, (string) $asset['file_name'], $target);
    $firstId ??= $jobId;
}

echo json_encode([
    'ok'     => true,
    'job_id' => $firstId,
    'jobs'   => $copies,
    'target' => $target,
]);
