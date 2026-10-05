<?php
declare(strict_types=1);

/**
 * Etykiety półek do wydruku na A4 (zwykła drukarka): kod QR „CRMLOC:R1-B” + duży kod + opis.
 * ?ids=1,2,3 - wybrane; bez parametru - wszystkie. QR rysuje przeglądarka (assets/qrcodegen.js).
 */

use Pase\Services\WarehouseLocations;

require __DIR__ . '/auth.php';
/** @var PDO $pdo */

WarehouseLocations::migrate($pdo);
$all = (new WarehouseLocations($pdo))->all();
$ids = array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? ''))));
$pick = $ids ? array_values(array_filter($all, static fn($l) => in_array($l['id'], $ids, true))) : $all;
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Etykiety półek — Veless</title>
<style>
    @page { size:A4; margin:10mm; }
    * { box-sizing:border-box; }
    body { margin:0; font-family:system-ui, "Segoe UI", sans-serif; color:#111; background:#f3f1ec; }
    .bar { position:sticky; top:0; display:flex; gap:10px; align-items:center; padding:12px 16px; background:#fff; border-bottom:1px solid #ddd; }
    .bar button { padding:9px 16px; border:0; border-radius:8px; background:#9c6b2e; color:#fff; font-weight:700; cursor:pointer; font-size:14px; }
    .sheet { width:190mm; margin:12px auto; display:grid; grid-template-columns:1fr 1fr; gap:4mm; }
    .label { background:#fff; border:1px dashed #bbb; height:50mm; padding:4mm; display:flex; gap:4mm; align-items:center; break-inside:avoid; }
    .label canvas { width:40mm; height:40mm; flex-shrink:0; image-rendering:pixelated; }
    .code { font-family:ui-monospace, "IBM Plex Mono", monospace; font-weight:800; font-size:30pt; line-height:1; letter-spacing:-.02em; }
    .desc { font-size:11pt; margin-top:3mm; }
    .note { font-size:9pt; color:#555; margin-top:1mm; }
    @media print { body { background:#fff; } .bar { display:none; } .sheet { margin:0; } .label { border-color:#ccc; } }
</style>
</head>
<body>
<div class="bar">
    <button onclick="window.print()">🖨 Drukuj</button>
    <span><?= count($pick) ?> etykiet · po 2 w rzędzie, 50 mm wysokości — wytnij i przyklej na półki.</span>
</div>
<div class="sheet">
    <?php foreach ($pick as $l): ?>
        <div class="label">
            <canvas data-qr="<?= htmlspecialchars(WarehouseLocations::QR_PREFIX . $l['code']) ?>"></canvas>
            <div>
                <div class="code"><?= htmlspecialchars($l['code']) ?></div>
                <div class="desc"><?= htmlspecialchars($l['label']) ?></div>
                <?php if (!empty($l['note'])): ?><div class="note"><?= htmlspecialchars($l['note']) ?></div><?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if ($pick === []): ?><p>Brak lokalizacji do wydruku.</p><?php endif; ?>
</div>
<script src="assets/qrcodegen.js"></script>
<script>
document.querySelectorAll('canvas[data-qr]').forEach((c) => {
    const qr = qrcodegen.QrCode.encodeText(c.dataset.qr, qrcodegen.QrCode.Ecc.MEDIUM);
    const border = 2, scale = 8;
    c.width = c.height = (qr.size + border * 2) * scale;
    const ctx = c.getContext('2d');
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height); ctx.fillStyle = '#000';
    for (let y = 0; y < qr.size; y++) for (let x = 0; x < qr.size; x++) if (qr.getModule(x, y)) ctx.fillRect((x + border) * scale, (y + border) * scale, scale, scale);
});
</script>
</body>
</html>
