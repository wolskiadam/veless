<?php
declare(strict_types=1);

/**
 * Renderuje jeden szablon wydruku (print_templates) dla konkretnego zamówienia
 * (albo z danymi przykładowymi - podgląd z edytora szablonu, gdy brak ?order=).
 *
 * GET: tpl=<id_szablonu>&order=<woo_order_id opcjonalnie>
 * POST z agent=1 w URL i csrf w treści: kolejka agenta, tylko admin/edytor.
 *
 * Format A4/A5: strona HTML wielkości A4/A5, drukowana przez przeglądarkę
 * (bez biblioteki PDF - zgodnie z resztą PASE, patrz label_download.php).
 * Format ZPL: surowy tekst dla drukarki Zebra, serwowany jako plik do pobrania.
 */

use Pase\Repository\PrintJobRepository;
use Pase\Repository\PrintTemplateRepository;
use Pase\Repository\SettingsRepository;
use Pase\Services\Attachments;
use Pase\Services\PrintTemplateRenderer;

require __DIR__ . '/auth.php';
if (($_GET['agent'] ?? '') === '1') {
    requirePrintPost();
}
/** @var PDO $pdo */

$tplId   = (int) ($_GET['tpl'] ?? 0);
$orderId = (int) ($_GET['order'] ?? 0);

$template = (new PrintTemplateRepository($pdo))->find($tplId);
if (!$template) {
    http_response_code(404);
    echo 'Nie znaleziono szablonu wydruku.';
    return;
}

$shopName = (new \Pase\Services\MailAccounts($pdo))->shopName($orderId > 0 ? $orderId : null, 'Sklep');

if ($orderId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM woo_orders WHERE woo_order_id = ?');
    $stmt->execute([$orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo 'Nie znaleziono zamówienia.';
        return;
    }
    $payload = json_decode((string) $row['payload'], true) ?: [];
    // Scalone zamówienia jadą w tej paczce - na wydruku ich produkty i suma razem (Services\OrderMerge).
    try {
        $mergedRows = (new \Pase\Services\OrderMerge($pdo))->absorbed($orderId);
    } catch (\Throwable) {
        $mergedRows = [];
    }
    $context = PrintTemplateRenderer::contextForOrder($row, $payload, $shopName, $mergedRows);
    $fileTag = 'zam-' . ($row['pase_number'] ?? $orderId);
} else {
    // Brak zamówienia = podgląd szablonu z edytora, dane przykładowe.
    $context = PrintTemplateRenderer::sampleContext();
    $fileTag = 'podglad';
}

$isAgentMode = ($_GET['agent'] ?? '') === '1';
// Cel wydruku: drukarka etykiet czy zwykła drukarka A4 podpięta do agenta.
$target = ($_GET['target'] ?? 'zebra') === 'a4' ? 'a4' : 'zebra';

// Obrazek dołączony do szablonu (logo/grafika) - dostępny jako placeholder {{obrazek}}.
// Tylko A4/A5 (HTML) - ZPL wymagałby konwersji na komendy graficzne drukarki, nieobsługiwane.
//
// W trybie agenta obrazek musi być wbudowany w HTML jako data-URI: agent renderuje
// stronę lokalnie, przeglądarką bez sesji panelu, więc zwykły link do
// print_template_image.php zwróciłby mu ekran logowania zamiast grafiki.
$context['obrazek'] = '';
if (!empty($template['image_path'])) {
    $context['obrazek'] = 'print_template_image.php?tpl=' . (int) $template['id'];

    if ($isAgentMode) {
        $imagePath = Attachments::absolutePath((string) $template['image_path']);
        if ($imagePath !== null && is_file($imagePath)) {
            $imageName = $template['image_name'] ?: basename($imagePath);
            $imageData = file_get_contents($imagePath);
            if ($imageData !== false) {
                $context['obrazek'] = 'data:' . Attachments::mimeFor($imageName)
                    . ';base64,' . base64_encode($imageData);
            }
        }
    }
}

$body = PrintTemplateRenderer::render((string) $template['body'], $context, (string) $template['format']);

/**
 * Składa kompletną stronę HTML arkusza (A4/A5). Ta sama treść trafia do
 * przeglądarki i do agenta - agent renderuje ją u siebie do PDF-a, więc wydruk
 * z panelu i wydruk automatyczny wyglądają identycznie.
 *
 * $withToolbar=false wycina pasek z przyciskiem i automatyczne okno druku -
 * w renderowaniu bezobsługowym nie ma ich kto kliknąć, a pasek wszedłby na wydruk.
 */
function renderPrintablePage(string $pageSize, string $title, string $body, bool $withToolbar): string
{
    $nonce = base64_encode(random_bytes(24));
    $policy = "default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; form-action 'none'";
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Security-Policy" content="<?= htmlspecialchars($policy, ENT_QUOTES, 'UTF-8') ?>">
<title><?= htmlspecialchars($title) ?></title>
<style>
    @page { size: <?= $pageSize ?>; margin: 15mm; }
    * { box-sizing: border-box; }
    body { margin: 0; padding: <?= $withToolbar ? '24px' : '0' ?>; font-family: -apple-system, "Segoe UI", sans-serif; color: #22252b; }
    table { border-collapse: collapse; }
    .print-toolbar { display: flex; justify-content: flex-end; gap: 8px; margin-bottom: 16px; }
    .print-toolbar button { background: #9c6b2e; color: #fff; border: 0; border-radius: 8px; padding: 9px 16px; font-size: 14px; font-weight: 700; cursor: pointer; }
    @media print { .print-toolbar { display: none; } body { padding: 0; } }
</style>
</head>
<body>
    <?php if ($withToolbar): ?>
        <div class="print-toolbar"><button type="button" id="print-button">🖨 Drukuj</button></div>
    <?php endif; ?>
    <?= $body ?>
    <?php if ($withToolbar): ?>
        <script nonce="<?= $nonce ?>">document.getElementById('print-button').addEventListener('click', function () { window.print(); }); window.addEventListener('load', function () { window.print(); });</script>
    <?php endif; ?>
</body>
</html>
    <?php
    return (string) ob_get_clean();
}

// --- Tryb agenta (&agent=1): zamiast pliku wrzuć wydruk do kolejki lokalnego
// programu drukującego (patrz print_agent_poll.php) i odpowiedz JSON-em.
//   ZPL    -> surowe komendy, zawsze na drukarkę etykiet;
//   A4/A5  -> kompletny HTML; agent renderuje go u siebie do PDF-a i drukuje
//             na wskazanej drukarce (etykiet albo zwykłej).
// Ten sam wzorzec co label_download.php?agent=1. ---
if ($isAgentMode) {
    header('Content-Type: application/json; charset=utf-8');

    $copies = max(1, (int) ((new SettingsRepository($pdo))->get('PRINT_COPIES', '1')));

    if ($template['format'] === 'ZPL') {
        // Surowy ZPL zawsze na Zebrę - na zwykłej drukarce wyszedłby stos znaków.
        $content = str_repeat($body, $copies);
        $jobFormat = 'ZPL';
        $jobTarget = 'zebra';
        $jobName   = $fileTag . '.zpl';
    } else {
        $content = renderPrintablePage(
            $template['format'] === 'A5' ? 'A5' : 'A4',
            (string) $template['name'],
            $body,
            false
        );
        $jobFormat = 'HTML';
        $jobTarget = $target;
        $jobName   = $fileTag . '.html';
    }

    try {
        $jobs    = new PrintJobRepository($pdo);
        $firstId = null;
        // HTML-a nie da się powielić sklejeniem - każda kopia to osobne zadanie.
        $jobCount = $jobFormat === 'ZPL' ? 1 : $copies;
        for ($copy = 0; $copy < $jobCount; $copy++) {
            $jobId = $jobs->enqueue('document', $jobFormat, $content, $jobName, $jobTarget);
            $firstId ??= $jobId;
        }
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Nie udało się dodać zadania do kolejki druku.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    echo json_encode([
        'ok'     => true,
        'job_id' => $firstId,
        'jobs'   => $jobCount,
        'target' => $jobTarget,
    ]);
    return;
}

// --- ZPL: surowy tekst dla drukarki Zebra, jako plik do pobrania (bez podglądu HTML). ---
if ($template['format'] === 'ZPL') {
    $copies = max(1, (int) ((new SettingsRepository($pdo))->get('PRINT_COPIES', '1')));
    $content = str_repeat($body, $copies);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $fileTag . '.zpl"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    return;
}

// --- A4/A5: strona HTML wielkości arkusza, drukowana przez przeglądarkę. ---
echo renderPrintablePage(
    $template['format'] === 'A5' ? 'A5' : 'A4',
    (string) $template['name'],
    $body,
    true
);
