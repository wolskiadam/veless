<?php
declare(strict_types=1);

/**
 * Ekran asystenta pakowania - wspólny dla panelu (public/admin/packing.php) i telefonu
 * (public/pack/index.php). Wołający ustawia $app (konfiguracja dla JS) i $assetBase
 * (ścieżka do public/pack/assets z punktu widzenia strony).
 *
 * @var array $app
 * @var string $assetBase
 * @var string $qrLib ścieżka do qrcodegen.js albo '' (telefon nie generuje QR)
 */
$v = static fn(string $f): string => $assetBase . '/' . $f . '?v=' . (@filemtime(__DIR__ . '/assets/' . $f) ?: 1);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#9c6b2e">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="robots" content="noindex">
    <title>Pakowanie — Veless</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600&display=swap">
    <link rel="stylesheet" href="<?= htmlspecialchars($v('packing.css')) ?>">
</head>
<body class="pk-mode-<?= htmlspecialchars((string) $app['mode']) ?>">
<div class="pk" id="pk">
    <header class="pk-top">
        <div class="pk-title">
            <span class="pk-title-main">📦 <span class="pk-hide-sm">Pakowanie</span></span>
            <span class="pk-count" id="pkCount"></span>
        </div>
        <a class="pk-num" id="pkNum" href="#" hidden></a>
        <div class="pk-top-actions">
            <button type="button" class="pk-btn ghost pk-browse-btn" id="pkBrowseBtn" title="Wszystkie zamówienia do spakowania" aria-label="Zamówienia do spakowania">📋 <span class="pk-hide-sm">Do spakowania</span></button>
            <button type="button" class="pk-btn ghost icon pk-scan-toggle" id="pkScanToggle" title="Wpisz albo zeskanuj kod" aria-label="Wpisz kod">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/></svg></button>
            <label class="pk-scan" title="Czytnik kodów działa od razu — nie trzeba klikać w to pole">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7V5a1 1 0 0 1 1-1h2M17 4h2a1 1 0 0 1 1 1v2M20 17v2a1 1 0 0 1-1 1h-2M7 20H5a1 1 0 0 1-1-1v-2M7 8v8M10 8v8M13 8v8M16 8v8"/></svg>
                <input id="pkScan" type="search" inputmode="search" autocomplete="off" placeholder="EAN, SKU lub nr zamówienia" aria-label="Skanuj albo wpisz kod">
            </label>
            <?php if ($app['mode'] === 'panel'): ?>
                <button type="button" class="pk-btn ghost" id="pkToPhone" title="Wyślij zamówienia z listy na sparowany telefon">📱 <span class="pk-hide-sm">Na telefon</span></button>
                <button type="button" class="pk-btn ghost" id="pkQr" title="Kod QR: otwórz to zamówienie na telefonie (i sparuj telefon)">▦ <span class="pk-hide-sm">QR</span></button>
                <?php if (!empty($app['isAdmin'])): ?>
                    <button type="button" class="pk-btn ghost icon" id="pkSettings" title="Ustawienia pakowania" aria-label="Ustawienia pakowania">⚙</button>
                <?php endif; ?>
            <?php else: ?>
                <span class="pk-user" title="Sparowany telefon"><?= htmlspecialchars((string) $app['userName']) ?></span>
            <?php endif; ?>
            <button type="button" class="pk-btn ghost icon" id="pkClose" title="Zamknij" aria-label="Zamknij">✕</button>
        </div>
    </header>

    <div class="pk-body">
        <aside class="pk-list" id="pkList" aria-label="Zamówienia do spakowania"></aside>
        <main class="pk-main" id="pkMain">
            <div class="pk-info" id="pkInfo" hidden>
                <div class="pk-info-text" id="pkInfoText" role="button" tabindex="0" aria-expanded="false" title="Pokaż / schowaj szczegóły">
                    <div class="pk-customer" id="pkCustomer"></div>
                    <div class="pk-meta">
                        <span class="pk-status" id="pkStatus"></span>
                        <span class="pk-ship" id="pkShip"></span>
                    </div>
                    <div class="pk-note" id="pkNote" hidden></div>
                    <div class="pk-state" id="pkState" hidden></div>
                    <span class="pk-more" aria-hidden="true">▾</span>
                </div>
                <div class="pk-photos">
                    <div class="pk-photo-list" id="pkPhotos"></div>
                    <label class="pk-camera" id="pkCameraBtn" title="Zdjęcie paczki">
                        <input type="file" id="pkPhotoInput" accept="image/*" capture="environment" hidden>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z"/><circle cx="12" cy="13.5" r="3.5"/></svg>
                        <span>Zdjęcie</span>
                    </label>
                </div>
            </div>
            <div class="pk-items" id="pkItems"></div>
            <section class="pk-carton" id="pkCarton" hidden aria-label="Gabaryt paczki">
                <div class="pk-carton-head">Gabaryt paczki <span class="pk-carton-weight" id="pkCartonWeight"></span></div>
                <div class="pk-carton-list" id="pkCartonList"></div>
                <div class="pk-carton-note" id="pkCartonNote" hidden></div>
            </section>
            <?php // Miejsce na elementy rozszerzeń (PackExt.panel w packing.js). ?>
            <section class="pk-ext" id="pkExt" hidden aria-label="Rozszerzenia"></section>
            <div class="pk-empty" id="pkEmpty" hidden></div>
            <section class="pk-browse" id="pkBrowse" hidden aria-label="Zamówienia do spakowania">
                <div class="pk-browse-head">
                    <h2>📋 Do spakowania <span id="pkBrowseTotal"></span></h2>
                    <button type="button" class="pk-btn ghost" id="pkBrowseCam" title="Zeskanuj kod zamówienia aparatem">📷 <span class="pk-hide-sm">Skanuj</span></button>
                    <?php if (!empty($app['canLocations'])): ?>
                        <button type="button" class="pk-btn ghost" id="pkBrowseLoc" title="Przypisz produkty do półek: QR półki, potem kody produktów">📍 Półki</button>
                    <?php endif; ?>
                    <input type="search" id="pkBrowseQ" placeholder="Szukaj: numer, klient" autocomplete="off" aria-label="Szukaj zamówienia">
                </div>
                <div class="pk-browse-chips" id="pkBrowseChips"></div>
                <div class="pk-browse-list" id="pkBrowseList"></div>
                <button type="button" class="pk-btn ghost pk-browse-more" id="pkBrowseMore" hidden>Pokaż więcej</button>
            </section>
        </main>
    </div>

    <footer class="pk-bottom" id="pkBottom" hidden>
        <div class="pk-tools">
            <button type="button" class="pk-round cam" id="pkCamBtn" title="Skanuj aparatem (EAN, QR)" aria-label="Skanuj aparatem">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M20 16v2a2 2 0 0 1-2 2h-2M8 20H6a2 2 0 0 1-2-2v-2M7 12h10"/></svg></button>
            <button type="button" class="pk-round" id="pkReset" title="Wyzeruj pakowanie tego zamówienia" aria-label="Wyzeruj">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/></svg></button>
            <button type="button" class="pk-round" id="pkUndo" title="Cofnij" aria-label="Cofnij" disabled>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/></svg></button>
            <button type="button" class="pk-round" id="pkRedo" title="Ponów" aria-label="Ponów" disabled>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 14 5-5-5-5"/><path d="M20 9H10a6 6 0 0 0 0 12h3"/></svg></button>
        </div>
        <div class="pk-progress" id="pkProgress"><div class="pk-progress-bar" id="pkProgressBar"></div><span id="pkProgressText"></span></div>
        <div class="pk-finish">
            <span class="pk-ext-actions" id="pkExtActions"></span>
            <button type="button" class="pk-btn outline" id="pkProblem">Nie spakowano</button>
            <button type="button" class="pk-btn done" id="pkDone">✓ Spakowano</button>
        </div>
    </footer>

    <div class="pk-toast" id="pkToast" role="status" aria-live="polite" hidden></div>
</div>

<!-- Skaner aparatem: EAN / kody kreskowe / QR. Trafienie = +1 sztuka tej pozycji (albo otwarcie zamówienia po jego numerze). -->
<div class="pk-cam" id="pkCam" hidden role="dialog" aria-label="Skaner aparatem">
    <video id="pkCamVideo" playsinline muted autoplay></video>
    <div class="pk-cam-frame" id="pkCamFrame"><span></span></div>
    <div class="pk-cam-top">
        <span class="pk-cam-order" id="pkCamOrder"></span>
        <button type="button" class="pk-cam-btn" id="pkCamTorch" hidden aria-label="Latarka">💡</button>
        <button type="button" class="pk-cam-btn" id="pkCamClose" aria-label="Zamknij skaner">✕</button>
    </div>
    <div class="pk-cam-bottom">
        <div class="pk-cam-result" id="pkCamResult">Skieruj aparat na kod EAN lub QR produktu</div>
        <div class="pk-cam-progress"><div id="pkCamBar"></div><span id="pkCamProgress"></span></div>
    </div>
</div>

<dialog class="pk-dialog" id="pkQrDialog">
    <form method="dialog" class="pk-dialog-box">
        <h3>Otwórz na telefonie</h3>
        <p class="pk-muted">Zeskanuj zwykłym aparatem telefonu. Otworzy się to zamówienie, a telefon zostanie
            sparowany jako stanowisko pakowania (bez logowania, tylko do pakowania).</p>
        <canvas id="pkQrCanvas" width="260" height="260"></canvas>
        <p class="pk-muted" id="pkQrTimer"></p>
        <div class="pk-devices" id="pkDevices"></div>
        <div class="pk-dialog-actions">
            <button type="button" class="pk-btn ghost" id="pkQrNew">Nowy kod</button>
            <button class="pk-btn">Zamknij</button>
        </div>
    </form>
</dialog>

<dialog class="pk-dialog" id="pkProblemDialog">
    <form method="dialog" class="pk-dialog-box" id="pkProblemForm">
        <h3>Nie spakowano — dlaczego?</h3>
        <div class="pk-chips" id="pkProblemChips">
            <button type="button" data-note="Brak towaru">Brak towaru</button>
            <button type="button" data-note="Uszkodzony produkt">Uszkodzony produkt</button>
            <button type="button" data-note="Czeka na dostawę">Czeka na dostawę</button>
            <button type="button" data-note="Do wyjaśnienia z klientem">Do wyjaśnienia z klientem</button>
        </div>
        <textarea id="pkProblemNote" rows="3" maxlength="500" placeholder="Opis (opcjonalnie)"></textarea>
        <div class="pk-dialog-actions">
            <button value="cancel" class="pk-btn ghost">Anuluj</button>
            <button value="ok" class="pk-btn danger" id="pkProblemOk">Zapisz</button>
        </div>
    </form>
</dialog>

<dialog class="pk-dialog" id="pkConfirmDialog">
    <form method="dialog" class="pk-dialog-box">
        <h3 id="pkConfirmTitle"></h3>
        <p id="pkConfirmText"></p>
        <div class="pk-dialog-actions">
            <button value="cancel" class="pk-btn ghost">Anuluj</button>
            <button value="ok" class="pk-btn" id="pkConfirmOk">OK</button>
        </div>
    </form>
</dialog>

<?php if ($app['mode'] === 'panel' && !empty($app['isAdmin'])): ?>
<dialog class="pk-dialog pk-dialog-wide" id="pkSettingsDialog">
    <form method="dialog" class="pk-dialog-box" id="pkSettingsForm">
        <h3>Ustawienia pakowania</h3>
        <label>Po kliknięciu „Spakowano” ustaw status
            <select name="done_status" id="pkSetDone"></select></label>
        <label>Po „Nie spakowano” ustaw status
            <select name="problem_status" id="pkSetProblem"></select></label>
        <fieldset class="pk-set-browse"><legend>Lista „📋 Do spakowania” pokazuje zamówienia ze statusem:</legend>
            <div id="pkSetBrowse"></div></fieldset>
        <label class="pk-check"><input type="checkbox" name="require_all" id="pkSetRequire">
            „Spakowano” dopiero, gdy wszystko jest odhaczone (inaczej pyta, czy mimo to)</label>
        <fieldset class="pk-set-browse pk-set-cartons"><legend>Kartony (wymiary wewnętrzne w cm, maks. waga w kg)</legend>
            <div class="pk-carton-rows" id="pkSetCartons"></div>
            <button type="button" class="pk-btn ghost" id="pkSetCartonAdd">+ Dodaj karton</button>
            <p class="pk-muted">Asystent podpowiada najmniejszy karton, w który zmieszczą się produkty (wg wymiarów i wagi z magazynu). Bez kartonów sekcja „Gabaryt paczki” się nie pokazuje.</p>
        </fieldset>
        <p class="pk-muted">Zmiana statusu działa jak ręczna: idzie do sklepu / Allegro (gdy włączona synchronizacja) i uruchamia automatyzacje.</p>
        <div class="pk-dialog-actions">
            <button value="cancel" class="pk-btn ghost">Anuluj</button>
            <button value="ok" class="pk-btn">Zapisz</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<dialog class="pk-dialog pk-lightbox" id="pkLightbox">
    <form method="dialog"><button class="pk-lightbox-close" aria-label="Zamknij">✕</button><img id="pkLightboxImg" alt="">
        <button type="button" class="pk-btn danger pk-lightbox-delete" id="pkLightboxDelete" hidden>🗑 Usuń zdjęcie</button></form>
</dialog>

<?php $app['zxingUrl'] = $v('zxing.min.js'); ?>
<script>window.PACK_CFG = <?= json_encode($app, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<?php if ($qrLib !== ''): ?><script src="<?= htmlspecialchars($qrLib) ?>"></script><?php endif; ?>
<script src="<?= htmlspecialchars($v('packing.js')) ?>"></script>
<?php // Rozszerzenia: style i skrypty asystenta pakowania (Hooks::addAsset('packing', …)), po packing.js - korzystają z window.PackExt. ?>
<?= \Pase\Plugin\Hooks::assetTags('packing') ?>
</body>
</html>
