<?php
declare(strict_types=1);

/**
 * Pobieranie załącznika wiadomości (panel). Dostęp tylko dla zalogowanej obsługi.
 * Plik leży poza web-rootem (storage/uploads) - serwujemy go strumieniowo po weryfikacji.
 *
 * GET: id=<id_wiadomości> pobiera plik.
 * POST z parametrem URL agent=1 i csrf w treści kolejkuje wydruk (PDF/JPG/PNG
 * agent rasteryzuje i drukuje na Zebrze) i zwracamy JSON.
 */

use Pase\Repository\OrderMessageRepository;
use Pase\Repository\PrintJobRepository;
use Pase\Services\Attachments;

require __DIR__ . '/auth.php';
if (($_GET['agent'] ?? '') === '1') {
    requirePrintPost();
}
/** @var PDO $pdo */

$id = (int) ($_GET['id'] ?? 0);
$m = (new OrderMessageRepository($pdo))->find($id);
if ($m === null || empty($m['attachment_path'])) {
    http_response_code(404);
    exit('Nie znaleziono załącznika.');
}

$full = Attachments::absolutePath($m['attachment_path']);
if ($full === null || !is_file($full)) {
    http_response_code(404);
    exit('Plik niedostępny.');
}

$name = $m['attachment_name'] ?: basename($full);

// Tryb agenta: wrzuć załącznik do kolejki druku zamiast serwować go przeglądarce.
if (($_GET['agent'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');

    $extension = strtoupper(pathinfo($name, PATHINFO_EXTENSION));
    // Agent rozpoznaje PDF po sygnaturze pliku, ale format podajemy wprost -
    // dzięki temu w historii zadań widać, co poszło na drukarkę.
    $supported = ['PDF', 'JPG', 'JPEG', 'PNG', 'GIF', 'BMP', 'TIF', 'TIFF', 'ZPL', 'EPL'];
    if (!in_array($extension, $supported, true)) {
        http_response_code(422);
        echo json_encode([
            'ok'    => false,
            'error' => 'Nie umiem wydrukować pliku ' . $extension . ' - obsługiwane są PDF, obrazki oraz ZPL/EPL.',
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $content = file_get_contents($full);
    if ($content === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Nie udało się odczytać pliku.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // ZPL/EPL zawsze na drukarkę etykiet - na zwykłej wyszedłby stos znaków.
    $target = ($_GET['target'] ?? 'zebra') === 'a4' && !in_array($extension, ['ZPL', 'EPL'], true)
        ? 'a4'
        : 'zebra';

    $jobId = (new PrintJobRepository($pdo))->enqueue('attachment', $extension, $content, $name, $target);
    echo json_encode(['ok' => true, 'job_id' => $jobId, 'target' => $target]);
    return;
}

header('Content-Type: ' . Attachments::mimeFor($name));
header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
header('Content-Length: ' . (string) filesize($full));
header('X-Content-Type-Options: nosniff');
readfile($full);
