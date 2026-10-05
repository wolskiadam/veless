<?php
declare(strict_types=1);

/**
 * Pobranie etykiety przewozowej dla przesyłki - przez wtyczkę kurierską konta (Courier::getLabel).
 * GET: shipment=<id_przesyłki w shipments>&printer=A4|LBL|ZPL|EPL
 * Domyślnie serwuje plik (PDF/inny) bezpośrednio do pobrania/druku.
 * POST z parametrem URL agent=1 i csrf w treści kolejkuje zadanie dla lokalnego
 * agenta druku (patrz print_agent_poll.php) i zwraca JSON.
 */

use Pase\Plugin\Contract\Courier;
use Pase\Plugin\PluginRegistry;
use Pase\Repository\IntegrationAccountRepository;
use Pase\Repository\PrintJobRepository;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
if (($_GET['agent'] ?? '') === '1') {
    requirePrintPost();
}
/** @var PDO $pdo */

$shipmentId = (int) ($_GET['shipment'] ?? 0);
$printer    = in_array($_GET['printer'] ?? '', ['A4', 'LBL', 'ZPL', 'EPL'], true) ? $_GET['printer'] : 'A4';

$stmt = $pdo->prepare('SELECT * FROM shipments WHERE id = ?');
$stmt->execute([$shipmentId]);
$sh = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sh) {
    http_response_code(404);
    echo 'Nie znaleziono przesyłki.';
    return;
}

// ID przesyłki u przewoźnika (kolumna bl_order_id - historycznie BLPaczka, dziś każda wtyczka).
// BLPaczka ma liczby, „Wysyłam z Allegro" UUID - dlatego tekst, nie (int).
$externalId = trim((string) ($sh['bl_order_id'] ?? ''));
if ($externalId === '' || $externalId === '0' || ($sh['status'] ?? '') === 'pending') {
    http_response_code(422);
    echo 'Przesyłka nie ma jeszcze numeru u przewoźnika — etykieta niedostępna.';
    return;
}

$bl = (new IntegrationAccountRepository($pdo))->find((int) $sh['integration_id']);
if ($bl === null) {
    http_response_code(422);
    echo 'Brak integracji kurierskiej.';
    return;
}
$courierApi = PluginRegistry::forAccount($bl['type'], $bl['config'] ?? []);
if (!$courierApi instanceof Courier) {
    http_response_code(422);
    echo 'Wtyczka kurierska niedostępna lub wyłączona.';
    return;
}

$r = $courierApi->getLabel($externalId, $printer);

// Typ pliku decyduje wtyczka (np. „Wysyłam z Allegro" ustala PDF/ZPL przy nadaniu) -
// dopasuj tryb obsługi do tego, co faktycznie przyszło.
if ($r['ok'] && str_ends_with(strtolower((string) ($r['filename'] ?? '')), '.zpl')) {
    $printer = 'ZPL';
} elseif ($r['ok'] && ($r['mime'] ?? '') === 'application/pdf' && in_array($printer, ['ZPL', 'EPL'], true)) {
    $printer = 'LBL';
}

if (!$r['ok'] || $r['content'] === null) {
    http_response_code(502);
    echo 'Nie udało się pobrać etykiety: ' . htmlspecialchars($r['message']);
    return;
}

// Tryb agenta: zamiast serwować plik, wrzuć etykietę do kolejki lokalnego programu
// drukującego. Działa dla każdego formatu:
//   ZPL/EPL - surowe komendy, agent posyła je na drukarkę bez zmian;
//   PDF/A4/LBL - agent sam renderuje stronę i wysyła jako grafikę ZPL (^GFA).
// Dzięki temu na Zebrze drukują się też etykiety kurierskie w PDF, których
// większość przewoźników nie oddaje w ZPL-u.
if (($_GET['agent'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');

    $copies   = max(1, (int) ((new \Pase\Repository\SettingsRepository($pdo))->get('PRINT_COPIES', '1')));
    $jobsRepo = new PrintJobRepository($pdo);
    $filename = basename($r['filename']);
    // Etykieta domyślnie idzie na Zebrę, ale PDF-a można też wypchnąć na zwykłą
    // drukarkę (np. gdy Zebra akurat nie działa albo etykieta ma iść na A4).
    $target   = ($_GET['target'] ?? 'zebra') === 'a4' ? 'a4' : 'zebra';

    if (in_array($printer, ['ZPL', 'EPL'], true)) {
        // Surowe komendy można po prostu powielić w jednym zadaniu.
        $jobId = $jobsRepo->enqueue('label', $printer, str_repeat($r['content'], $copies), $filename, 'zebra');
        echo json_encode(['ok' => true, 'job_id' => $jobId, 'jobs' => 1]);
        return;
    }

    // Pliku graficznego nie da się "powielić" sklejeniem bajtów (dwa PDF-y
    // zlepione w jeden ciąg to uszkodzony plik) - każda kopia to osobne zadanie.
    $format  = str_contains((string) ($r['mime'] ?? ''), 'image/')
        ? strtoupper((string) (explode('/', (string) $r['mime'])[1] ?? 'PNG'))
        : 'PDF';
    $firstId = null;
    for ($copy = 0; $copy < $copies; $copy++) {
        $jobId = $jobsRepo->enqueue('label', $format, $r['content'], $filename, $target);
        $firstId ??= $jobId;
    }

    echo json_encode(['ok' => true, 'job_id' => $firstId, 'jobs' => $copies, 'format' => $format, 'target' => $target]);
    return;
}

// ZPL/EPL to surowe komendy drukarki Zebra (nie PDF) - serwujemy jako pobranie
// pliku, który wysyłasz na drukarkę (Zebra Setup Utilities / 'Send file to printer').
if (in_array($printer, ['ZPL', 'EPL'], true)) {
    $ext = strtolower($printer);
    $name = preg_replace('/\.[a-z0-9]+$/i', '', basename($r['filename'])) . '.' . $ext;
    // Liczba kopii (ustawienia drukowania) - dla surowych komend powielamy zawartość.
    $copies = max(1, (int) ((new \Pase\Repository\SettingsRepository($pdo))->get('PRINT_COPIES', '1')));
    $content = str_repeat($r['content'], $copies);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    return;
}

// Tryb druku: strona HTML z PDF w iframe (data-URI) + automatyczne okno druku.
if (($_GET['print'] ?? '') === '1' && ($r['mime'] === 'application/pdf')) {
    $dataUri = 'data:application/pdf;base64,' . base64_encode($r['content']);
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html><html lang="pl"><head><meta charset="utf-8"><title>Druk etykiety</title>
<style>html,body{margin:0;height:100%}iframe{border:0;width:100%;height:100vh}</style></head>
<body>
<iframe id="lbl" src="<?= htmlspecialchars($dataUri) ?>"></iframe>
<script>
  // Po załadowaniu PDF wywołaj okno druku iframe (działa, bo to nasza domena).
  var f = document.getElementById('lbl');
  f.addEventListener('load', function () {
    try { f.contentWindow.focus(); f.contentWindow.print(); }
    catch (e) { window.print(); }
  });
</script>
</body></html>
    <?php
    return;
}

// Domyślnie: serwuj plik inline (pobranie/podgląd).
header('Content-Type: ' . $r['mime']);
header('Content-Disposition: inline; filename="' . basename($r['filename']) . '"');
header('Content-Length: ' . strlen($r['content']));
echo $r['content'];
