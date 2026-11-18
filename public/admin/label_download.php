<?php
declare(strict_types=1);

/**
 * Pobranie etykiety przewozowej dla przesyłki - przez wtyczkę kurierską konta (Courier::getLabel).
 * GET: shipment=<id_przesyłki w shipments>&printer=A4|LBL|ZPL|EPL
 * Domyślnie serwuje plik (PDF/inny) bezpośrednio do pobrania/druku.
 * POST z parametrem URL agent=1 i csrf w treści kolejkuje zadanie dla lokalnego
 * agenta druku (patrz print_agent_poll.php) i zwraca JSON.
 * Każde udane pobranie/druk zapisuje Services\LabelPrints. Gdy etykieta była już drukowana,
 * bez again=1 zwraca ostrzeżenie (agent: JSON already=true, reszta: strona z potwierdzeniem).
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

// Licznik druku (Services\LabelPrints): ta sama etykieta drugi raz tylko po świadomym potwierdzeniu
// (again=1) - żeby nie nakleić jej na dwie różne paczki. Karta zamówienia pyta wcześniej we własnym
// okienku; to sprawdzenie łapie też nieaktualną kartę (np. druk z drugiej karty przeglądarki).
$labelMode = ($_GET['agent'] ?? '') === '1' ? 'agent' : (($_GET['print'] ?? '') === '1' ? 'print' : 'download');
$labelPrints = new \Pase\Services\LabelPrints($pdo);
if (($_GET['again'] ?? '') !== '1') {
    $printed = $labelPrints->forShipments([$shipmentId])[$shipmentId] ?? null;
    if ($printed !== null) {
        $warning = \Pase\Services\LabelPrints::warning($printed);
        if ($labelMode === 'agent') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'already' => true, 'count' => $printed['count'], 'warning' => $warning], JSON_UNESCAPED_UNICODE);
            return;
        }
        $againUrl = 'label_download.php?' . http_build_query(array_merge($_GET, ['again' => '1']));
        $orderUrl = 'order_view.php?id=' . (int) ($sh['woo_order_id'] ?? 0);
        http_response_code(409);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        ?>
<!DOCTYPE html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Etykieta była już drukowana</title>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f6f5f2;font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;color:#2c2a26;padding:16px;box-sizing:border-box}
.box{max-width:440px;background:#fff;border:2px solid #dc2626;border-radius:12px;padding:22px 22px 18px;box-shadow:0 8px 24px rgba(0,0,0,.08)}
h1{margin:0 0 10px;font-size:19px;color:#b91c1c}
p{margin:0 0 12px}
.acts{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}
a{display:inline-block;padding:9px 14px;border-radius:8px;text-decoration:none;font-weight:600}
.no{background:#2c2a26;color:#fff}.yes{background:#fff;color:#b91c1c;border:1px solid #dc2626}
</style></head><body>
<div class="box">
    <h1>⚠ Ta etykieta była już drukowana</h1>
    <p><?= htmlspecialchars($warning) ?></p>
    <p>Każda paczka musi mieć własną etykietę. Drukuj ponownie tylko wtedy, gdy poprzedni wydruk się nie udał albo zniszczył.</p>
    <div class="acts">
        <a class="no" href="<?= htmlspecialchars($orderUrl) ?>" onclick="if (window.opener) { window.close(); return false; }">Nie drukuj</a>
        <a class="yes" href="<?= htmlspecialchars($againUrl) ?>">Drukuj ponownie</a>
    </div>
</div>
</body></html>
        <?php
        return;
    }
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

// Etykieta jest w ręku - liczy się jako wydrukowana (agent, okno druku albo pobrany plik).
$printCount = $labelPrints->record($shipmentId, isset($sh['woo_order_id']) ? (int) $sh['woo_order_id'] : null,
    $labelMode, $printer, currentUserId(), currentUserName());
header('X-Label-Prints: ' . $printCount);

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
        echo json_encode(['ok' => true, 'job_id' => $jobId, 'jobs' => 1, 'prints' => $printCount]);
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

    echo json_encode(['ok' => true, 'job_id' => $firstId, 'jobs' => $copies, 'format' => $format, 'target' => $target, 'prints' => $printCount]);
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
