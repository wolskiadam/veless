<?php
declare(strict_types=1);

/**
 * Szybki wydruk: plik upuszczony na pole w bibliotece leci prosto do kolejki
 * agenta. Domyślnie NIE zapisujemy go w bibliotece - to droga na jeden raz
 * (etykieta kurierska, wydruk od kontrahenta), a biblioteka ma zostać miejscem
 * na rzeczy używane wielokrotnie, nie śmietnikiem jednorazówek.
 *
 * POST (multipart): file, target=zebra|a4, copies=1..50, save=0|1, csrf
 * Odpowiedź: JSON
 */

use Pase\Repository\PrintAssetRepository;
use Pase\Repository\PrintJobRepository;
use Pase\Services\Attachments;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

header('Content-Type: application/json; charset=utf-8');

/** Zwraca błąd w formacie, którego oczekuje strona, i kończy. */
$fail = static function (string $message, int $status = 422): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $fail('Nieprawidłowe żądanie.', 405);
}

// CSRF leci w FormData - ta sama ochrona co przy zwykłych formularzach panelu.
if (!is_string($_POST['csrf'] ?? null) || empty($_SESSION['csrf'])
    || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    $fail('Nieprawidłowy token CSRF — odśwież stronę.', 419);
}

$file = $_FILES['file'] ?? null;
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    $fail('Nie przesłano pliku.');
}
if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
    $fail('Błąd przesyłania pliku (kod ' . (int) $file['error'] . ').');
}
if (!is_uploaded_file((string) $file['tmp_name'])) {
    $fail('Plik nie pochodzi z przesyłki HTTP.');
}
if ((int) ($file['size'] ?? 0) > 8 * 1024 * 1024) {
    $fail('Plik jest za duży (max 8 MB).');
}

$originalName = (string) ($file['name'] ?? 'wydruk');
$extension    = strtoupper(pathinfo($originalName, PATHINFO_EXTENSION));
$printable    = ['PDF', 'JPG', 'JPEG', 'PNG', 'GIF', 'WEBP', 'BMP', 'TIF', 'TIFF', 'ZPL', 'EPL'];

if (!in_array($extension, $printable, true)) {
    $fail('Nie umiem wydrukować pliku ' . ($extension !== '' ? $extension : 'bez rozszerzenia')
        . ' — obsługiwane są PDF, obrazki oraz ZPL/EPL.');
}

// ZPL/EPL to surowe komendy Zebry - na zwykłej drukarce wyszedłby stos znaków.
$target = ($_POST['target'] ?? 'zebra') === 'a4' && !in_array($extension, ['ZPL', 'EPL'], true)
    ? 'a4'
    : 'zebra';
$copies = max(1, min(50, (int) ($_POST['copies'] ?? 1)));

$content = @file_get_contents((string) $file['tmp_name']);
if ($content === false || $content === '') {
    $fail('Nie udało się odczytać przesłanego pliku.', 500);
}

// Opcjonalny zapis w bibliotece - wtedy plik ląduje w storage i zostaje na przyszłość.
$savedToLibrary = false;
if (($_POST['save'] ?? '0') === '1') {
    try {
        $stored = Attachments::store($file);
        if ($stored !== null) {
            [$path, $storedName] = $stored;
            $name = trim((string) ($_POST['name'] ?? '')) ?: (pathinfo($storedName, PATHINFO_FILENAME) ?: $storedName);
            (new PrintAssetRepository($pdo))->create($name, $path, $storedName);
            $savedToLibrary = true;
        }
    } catch (\RuntimeException $e) {
        // Nieudany zapis do biblioteki nie może zablokować samego wydruku -
        // użytkownik upuścił plik po to, żeby się wydrukował.
        $savedToLibrary = false;
    }
}

$jobs    = new PrintJobRepository($pdo);
$firstId = null;
for ($copy = 0; $copy < $copies; $copy++) {
    $jobId = $jobs->enqueue('quick', $extension, $content, $originalName, $target);
    $firstId ??= $jobId;
}

echo json_encode([
    'ok'     => true,
    'job_id' => $firstId,
    'jobs'   => $copies,
    'target' => $target,
    'file'   => $originalName,
    'saved'  => $savedToLibrary,
], JSON_UNESCAPED_UNICODE);
