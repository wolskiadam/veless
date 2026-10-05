<?php
declare(strict_types=1);

/**
 * Wymiana danych przy przekazaniu pracy online ⇄ komputer (Pase\Services\Handover).
 * Woła ją tylko CRM na komputerze, z sekretem z kodu połączenia w nagłówku X-CRM-Handover.
 *
 *   status                         stan tej instalacji i bieżące zadanie
 *   export                         wstrzymaj się i spakuj dane (pakiet buduje worker)
 *   download&offset=N              kolejna część gotowego pakietu
 *   finish                         komputer wgrał dane - usuń pakiet (instalacja zostaje wstrzymana)
 *   abort                          komputer nie przejął danych - wróć do pracy
 *   upload_start                   przyjmij dane z komputera (tylko gdy ta instalacja jest wstrzymana)
 *   upload&id=…&offset=N           kolejna część (treść żądania)
 *   upload_finish&id=…&sha256=…    sprawdź sumę i zleć wgranie (worker)
 */

use Pase\Repository\SettingsRepository;
use Pase\Services\Handover;

$config = require dirname(__DIR__) . '/config/config.php';
$pdo = (require PASE_ROOT . '/config/database.php')($config['db']);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$fail = static function (int $code, string $message): never {
    http_response_code($code);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
};

$handover = new Handover($pdo, new SettingsRepository($pdo), PASE_ROOT);
$token = (string) ($_SERVER['HTTP_X_CRM_HANDOVER'] ?? '');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$handover->verifyToken($token)) {
    $fail(403, 'Zły kod połączenia - wygeneruj nowy w panelu online (System → Przeniesienie danych).');
}
$handover->rememberToken($token);

try {
    $action = (string) ($_GET['action'] ?? '');
    switch ($action) {
        case 'status':
            echo json_encode(['away' => $handover->away(), 'task' => $handover->task()], JSON_UNESCAPED_UNICODE);
            break;

        case 'export':
            $handover->serverStartExport();
            echo json_encode(['ok' => true]);
            break;

        case 'download':
            $path = $handover->readyExportPath();
            $offset = max(0, (int) ($_GET['offset'] ?? 0));
            $in = fopen($path, 'rb');
            fseek($in, $offset);
            $chunk = (string) fread($in, Handover::CHUNK);
            fclose($in);
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . strlen($chunk));
            echo $chunk;
            break;

        case 'finish':
            $handover->clearTask();
            foreach (glob($handover->dir() . '/out-*.zip') ?: [] as $f) { @unlink($f); }
            echo json_encode(['ok' => true]);
            break;

        case 'abort':
            $handover->serverAbort();
            echo json_encode(['ok' => true]);
            break;

        case 'upload_start':
            echo json_encode(['id' => $handover->serverStartUpload()]);
            break;

        case 'upload':
            $size = $handover->serverAppendUpload((string) ($_GET['id'] ?? ''), (int) ($_GET['offset'] ?? -1), (string) file_get_contents('php://input'));
            echo json_encode(['size' => $size]);
            break;

        case 'upload_finish':
            $handover->serverFinishUpload((string) ($_GET['id'] ?? ''), (string) ($_GET['sha256'] ?? ''));
            echo json_encode(['ok' => true]);
            break;

        default:
            $fail(400, 'Nieznana akcja.');
    }
} catch (\Throwable $e) {
    $fail(409, $e->getMessage());
}
