<?php
declare(strict_types=1);

/**
 * Podgląd wydruku pliku z biblioteki: pokazuje, jak duży będzie na taśmie
 * i ile z niej zajmie. Liczby pochodzą z tej samej arytmetyki co w agencie
 * (Pase\Services\PrintPreview ↔ agent/print_agent.py).
 *
 * Ustawienia taśmy bierzemy z tego, co agent zameldował przy odpytywaniu
 * (PRINT_AGENT_DPI / PRINT_AGENT_LABEL_MM). Gdy jeszcze się nie zgłosił,
 * używamy wartości domyślnych i mówimy o tym wprost — inaczej podgląd
 * udawałby wiedzę, której nie ma.
 *
 * GET: id=<id_pozycji_biblioteki>
 */

use Pase\Repository\PrintAssetRepository;
use Pase\Repository\SettingsRepository;
use Pase\Services\Attachments;
use Pase\Services\PrintPreview;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$asset = (new PrintAssetRepository($pdo))->find((int) ($_GET['id'] ?? 0));
if ($asset === null) {
    http_response_code(404);
    exit('Nie znaleziono pliku w bibliotece.');
}

$full = Attachments::absolutePath((string) $asset['file_path']);
if ($full === null || !is_file($full)) {
    http_response_code(404);
    exit('Plik zniknął z dysku serwera.');
}

$settings = new SettingsRepository($pdo);
$reportedDpi     = (int) ($settings->get('PRINT_AGENT_DPI', '0') ?? 0);
$reportedLabelMm = (float) ($settings->get('PRINT_AGENT_LABEL_MM', '0') ?? 0);

$fromAgent    = $reportedDpi > 0 && $reportedLabelMm > 0;
$dpi          = $fromAgent ? $reportedDpi : PrintPreview::DEFAULT_DPI;
$labelWidthMm = $fromAgent ? $reportedLabelMm : PrintPreview::DEFAULT_LABEL_WIDTH_MM;

$extension = strtolower(pathinfo((string) $asset['file_name'], PATHINFO_EXTENSION));
$isPdf     = $extension === 'pdf';

$sourceMm = PrintPreview::sourceSizeMm($full);
$sourcePx = PrintPreview::sourcePixels($full);
$printed  = PrintPreview::printedSizeMm($labelWidthMm, $sourceMm, $sourcePx);

$fileUrl = 'print_asset_image.php?id=' . (int) $asset['id'];

$PAGE_TITLE = 'Podgląd wydruku: ' . ($asset['name'] ?: $asset['file_name']);
$PAGE_KEY   = 'print_templates';
require __DIR__ . '/header.php';

$e = static fn($v) => htmlspecialchars((string) $v);
?>

<div style="margin:-6px 0 16px">
    <a class="btn secondary" href="print_templates.php">← Powrót do szablonów</a>
</div>

<?php if ($printed === null): ?>
    <div class="flash err">
        Nie udało się odczytać rozmiaru tego pliku.
        <?php if ($isPdf): ?>
            PDF trzyma strukturę w skompresowanych strumieniach — agent i tak wydrukuje go
            poprawnie, ale podglądu w skali nie da się policzyć bez otwarcia pliku.
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card">
    <strong>Co pójdzie na drukarkę</strong>

    <?php if (!$fromAgent): ?>
        <div class="flash err" style="margin:10px 0 0">
            Agent jeszcze nie zgłosił swoich ustawień, więc podgląd liczy rozmiar dla
            <strong>domyślnej taśmy <?= $e(number_format(PrintPreview::DEFAULT_LABEL_WIDTH_MM, 1, ',', ' ')) ?> mm
            przy <?= (int) PrintPreview::DEFAULT_DPI ?> dpi</strong>. Uruchom agenta na komputerze
            z drukarką i odśwież tę stronę — wtedy zobaczysz rozmiar dla tej konkretnej stacji.
        </div>
    <?php endif; ?>

    <table style="margin-top:12px;max-width:560px">
        <tr><th style="width:230px">Plik</th><td><?= $e($asset['file_name']) ?> (<?= $e(strtoupper($extension)) ?>)</td></tr>
        <tr><th>Rozmiar w pliku</th><td>
            <?php if ($sourceMm !== null): ?>
                <?= $e(number_format($sourceMm['width'], 1, ',', ' ')) ?> × <?= $e(number_format($sourceMm['height'], 1, ',', ' ')) ?> mm
            <?php elseif ($sourcePx !== null): ?>
                <?= (int) $sourcePx['width'] ?> × <?= (int) $sourcePx['height'] ?> px
                <span style="color:var(--ink-2);font-size:12px">— obrazek nie niesie rozmiaru w mm</span>
            <?php else: ?>
                — nieznany —
            <?php endif; ?>
        </td></tr>
        <tr><th>Taśma w drukarce</th><td>
            <?= $e(number_format($labelWidthMm, 1, ',', ' ')) ?> mm szerokości przy <?= (int) $dpi ?> dpi
            <?= $fromAgent ? '<span class="pill ok" style="font-size:11px">z agenta</span>' : '<span class="pill muted" style="font-size:11px">domyślne</span>' ?>
        </td></tr>
        <?php if ($printed !== null): ?>
        <tr><th>Wydruk będzie miał</th><td>
            <strong><?= $e(number_format($printed['width'], 1, ',', ' ')) ?> × <?= $e(number_format($printed['height'], 1, ',', ' ')) ?> mm</strong>
            <span style="color:var(--ink-2);font-size:12px">
                (<?= (int) round($printed['width'] * $dpi / 25.4) ?> × <?= (int) round($printed['height'] * $dpi / 25.4) ?> punktów)
            </span>
        </td></tr>
        <tr><th>Wykorzystanie szerokości</th><td>
            <?php if ($printed['fills']): ?>
                <span class="pill ok">wypełnia taśmę</span>
                <?php if ($printed['scaled']): ?>
                    <span style="color:var(--ink-2);font-size:12px">— plik jest szerszy niż taśma, więc został zmniejszony</span>
                <?php endif; ?>
            <?php else: ?>
                <span class="pill warn">zajmie <?= (int) round($printed['width'] / $labelWidthMm * 100) ?>% szerokości</span>
                <span style="color:var(--ink-2);font-size:12px">— tak jest zaprojektowany plik, agent go nie rozciąga</span>
            <?php endif; ?>
        </td></tr>
        <?php endif; ?>
    </table>
</div>

<?php if ($printed !== null): ?>
<div class="card">
    <strong>Podgląd w skali</strong>
    <p style="color:var(--ink-2);font-size:13px;margin:6px 0 16px">
        Szara ramka to szerokość taśmy, biały prostokąt to wydruk. Proporcje są zachowane;
        rozmiar na ekranie zależy od monitora, więc miarodajne są liczby powyżej.
    </p>

    <?php
        // Rysujemy w milimetrach CSS - przeglądarka przelicza je po swojemu, ale
        // proporcja taśma : wydruk zostaje zachowana, a o to tu chodzi.
        $tapeMm    = max($labelWidthMm, $printed['width']);
        $contentMm = $printed['width'];
        $heightMm  = $printed['height'];
    ?>

    <div style="display:flex;gap:26px;align-items:flex-start;flex-wrap:wrap">
        <div>
            <!-- Szerokość taśmy -->
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;color:var(--ink-2);font-size:11px">
                <span style="flex:none">◀</span>
                <span style="flex:1;border-top:1px dashed #bbb"></span>
                <span style="flex:none"><?= $e(number_format($tapeMm, 1, ',', ' ')) ?> mm taśmy</span>
                <span style="flex:1;border-top:1px dashed #bbb"></span>
                <span style="flex:none">▶</span>
            </div>

            <div style="width:<?= $tapeMm ?>mm;background:#eceff3;border:1px solid #d6dae0;border-radius:3px;padding:0;position:relative">
                <div style="width:<?= $contentMm ?>mm;height:<?= $heightMm ?>mm;background:#fff;box-shadow:0 0 0 1px #c9ced6;overflow:hidden">
                    <?php if ($isPdf): ?>
                        <embed src="<?= $e($fileUrl) ?>#toolbar=0&navpanes=0&scrollbar=0&view=Fit"
                               type="application/pdf" style="width:100%;height:100%;border:0">
                    <?php else: ?>
                        <img src="<?= $e($fileUrl) ?>" alt="" style="width:100%;height:100%;object-fit:fill;display:block">
                    <?php endif; ?>
                </div>
            </div>

            <div style="color:var(--ink-2);font-size:11px;margin-top:4px">
                wysokość wydruku: <?= $e(number_format($heightMm, 1, ',', ' ')) ?> mm
            </div>
        </div>

        <div style="max-width:380px;font-size:13px;color:var(--ink-2)">
            <p style="margin:0 0 10px"><strong style="color:var(--ink)">Wydruk nie zgadza się z tym podglądem?</strong></p>
            <p style="margin:0 0 8px">
                Zmierz papier linijką i porównaj z liczbami obok. Rozbieżność znaczy, że ustawienia
                agenta nie odpowiadają drukarce:
            </p>
            <ul style="padding-left:18px;margin:0 0 10px">
                <li><strong>ok. 1,5× mniejszy</strong> — drukarka ma 300 dpi, a agent liczy dla 203
                    (albo odwrotnie). Popraw <code>dpi</code>.</li>
                <li><strong>dokładnie 2× mniejszy</strong> — <code>label_width_mm</code> jest o połowę
                    za małe.</li>
                <li><strong>obcięty z boku</strong> — taśma jest węższa niż <code>label_width_mm</code>.</li>
            </ul>
            <p style="margin:0">
                Na komputerze z drukarką sprawdzisz wszystko poleceniem
                <code>py -3 print_agent.py --info</code>, a poprawisz przez
                <code>--reconfigure</code>.
            </p>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
