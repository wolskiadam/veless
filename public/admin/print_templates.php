<?php
declare(strict_types=1);

/**
 * Szablony wydruku dla zamówień (Konfiguracja → Szablony wydruku): karty
 * zamówienia, listy pakowania, etykiety Zebra itd. Podpięte pod dropdown
 * "Drukuj / Eksportuj" na stronie zamówienia (order_view.php).
 */

use Pase\Repository\PrintAssetRepository;
use Pase\Repository\PrintTemplateRepository;
use Pase\Services\Attachments;

require __DIR__ . '/auth.php';
requireRole(['admin', 'editor']);
/** @var PDO $pdo */

$repo = new PrintTemplateRepository($pdo);
$formats = ['A4' => 'A4 (HTML, drukowane przez przeglądarkę)', 'A5' => 'A5 (HTML, drukowane przez przeglądarkę)', 'ZPL' => 'Zebra / ZPL (plik do pobrania)'];
$flashOk = $flashErr = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $id      = (int) ($_POST['id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $format  = $_POST['format'] ?? 'A4';
        $body    = (string) ($_POST['body'] ?? '');
        $active  = isset($_POST['is_active']);

        if ($name === '' || trim($body) === '') {
            $flashErr = 'Podaj nazwę i treść szablonu.';
        } else {
            if ($id > 0) {
                $repo->update($id, $name, $format, $body, $active);
                $flashOk = 'Zapisano szablon „' . $name . '”.';
            } else {
                $id = $repo->create($name, $format, $body, $active);
                $flashOk = 'Dodano szablon „' . $name . '”.';
            }

            // Obrazek: usuń zaznaczony do skasowania, albo zapisz nowo przesłany (max 1 plik).
            if (isset($_POST['remove_image'])) {
                $repo->clearImage($id);
            } elseif (!empty($_FILES['template_image']['name'])) {
                try {
                    $stored = Attachments::store($_FILES['template_image']);
                    if ($stored !== null) {
                        [$path, $origName] = $stored;
                        $repo->setImage($id, $path, $origName);
                    }
                } catch (\RuntimeException $e) {
                    $flashErr = $e->getMessage();
                }
            }
        }
    } elseif ($act === 'delete') {
        $repo->delete((int) ($_POST['id'] ?? 0));
        $flashOk = 'Usunięto szablon.';
    } elseif ($act === 'upload_asset') {
        // Biblioteka wydruków: dodanie gotowego pliku (etykieta produktowa, naklejka).
        try {
            $stored = Attachments::store($_FILES['asset_file'] ?? []);
            if ($stored === null) {
                $flashErr = 'Nie wybrano pliku.';
            } else {
                [$path, $originalName] = $stored;
                $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                // Attachments dopuszcza też dokumenty biurowe - do druku nadają się
                // tylko obrazki i PDF, więc resztę odrzucamy i sprzątamy plik.
                if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'], true)) {
                    $full = Attachments::absolutePath($path);
                    if ($full !== null && is_file($full)) {
                        @unlink($full);
                    }
                    $flashErr = 'Do druku nadają się tylko obrazki (JPG, PNG, GIF, WEBP) i PDF-y.';
                } else {
                    $assetName = trim((string) ($_POST['asset_name'] ?? ''));
                    if ($assetName === '') {
                        // Bez nazwy bierzemy nazwę pliku bez rozszerzenia - lepsze niż puste pole.
                        $assetName = pathinfo($originalName, PATHINFO_FILENAME) ?: $originalName;
                    }
                    (new PrintAssetRepository($pdo))->create($assetName, $path, $originalName);
                    $flashOk = 'Dodano „' . $assetName . '” do biblioteki wydruków.';
                }
            }
        } catch (\RuntimeException $e) {
            $flashErr = $e->getMessage();
        }
    } elseif ($act === 'delete_asset') {
        $removed = (new PrintAssetRepository($pdo))->delete((int) ($_POST['asset_id'] ?? 0));
        if ($removed !== null) {
            // Wpis zniknął z bazy - usuwamy też plik, żeby storage nie puchło.
            $full = Attachments::absolutePath((string) $removed['file_path']);
            if ($full !== null && is_file($full)) {
                @unlink($full);
            }
            $flashOk = 'Usunięto „' . $removed['name'] . '” z biblioteki.';
        }
    }

    // PRG: komunikat do sesji i 303 na GET-a. Bez tego odświeżenie strony po
    // wgraniu pliku dodałoby go drugi raz (i po raz kolejny przy każdym F5).
    if ($flashOk !== null) {
        flash($flashOk);
    }
    if ($flashErr !== null) {
        flash($flashErr, 'err');
    }
    // Przy błędzie zostajemy w kontekście edycji, po udanym zapisie wracamy na listę.
    redirectAfterPost($flashErr !== null ? null : 'print_templates.php');
}

$templates = $repo->all();

// Biblioteka wydruków - gotowe pliki drukowane wielokrotnie, niezwiązane z zamówieniem.
$printAssets = [];
try {
    $printAssets = (new PrintAssetRepository($pdo))->all();
} catch (\Throwable $e) {
    $printAssets = [];
}

$editId   = (int) ($_GET['edit'] ?? 0);
$editTpl  = null;
if ($editId) {
    foreach ($templates as $t) {
        if ((int) $t['id'] === $editId) { $editTpl = $t; break; }
    }
}
$isNew = ($_GET['new'] ?? '') === '1';

$placeholders = [
    'sklep' => 'Nazwa sklepu',
    'numer_pase' => 'Numer zamówienia w CRM',
    'numer_sklepu' => 'Numer zamówienia w sklepie',
    'data_zlozenia' => 'Data złożenia zamówienia',
    'klient_imie_nazwisko' => 'Imię i nazwisko klienta',
    'klient_email' => 'E-mail klienta',
    'klient_telefon' => 'Telefon klienta',
    'adres_dostawy' => 'Adres dostawy (kilka linii)',
    'adres_faktury' => 'Adres do faktury (kilka linii)',
    'nip'           => 'NIP z danych do faktury (puste, gdy brak)',
    'waluta' => 'Waluta',
    'suma' => 'Suma zamówienia',
    'sposob_platnosci' => 'Metoda płatności',
    'liczba_pozycji' => 'Liczba pozycji w zamówieniu',
    'produkty_tabela' => 'Wiersze &lt;tr&gt; tabeli produktów (do A4/A5)',
    'produkty_lista' => 'Lista produktów jako czysty tekst (do ZPL)',
    'scalone_zamowienia' => 'Numery zamówień dołączonych do tego (scalone w jedną paczkę)',
    'obrazek' => 'Adres wgranego obrazka - użyj jako &lt;img src="{{obrazek}}"&gt; (tylko A4/A5)',
];

$PAGE_TITLE = 'Szablony wydruku';
$PAGE_KEY   = 'print_templates';
require __DIR__ . '/header.php';
?>

<?php // Komunikaty po zapisie renderuje header.php (flash()/flashTake() w auth.php). ?>

<?php if ($editTpl || $isNew): ?>
<div class="card">
    <strong><?= $editTpl ? 'Edytuj szablon: ' . htmlspecialchars($editTpl['name']) : 'Nowy szablon' ?></strong>
    <div style="display:grid;grid-template-columns:minmax(0,2fr) 260px;gap:20px;margin-top:14px;align-items:start">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save">
            <?php if ($editTpl): ?><input type="hidden" name="id" value="<?= (int) $editTpl['id'] ?>"><?php endif; ?>

            <div style="display:grid;grid-template-columns:2fr 1fr;gap:14px">
                <div>
                    <label style="font-size:12px;color:var(--ink-2);display:block">Nazwa</label>
                    <input name="name" required value="<?= htmlspecialchars($editTpl['name'] ?? '') ?>" style="width:100%">
                </div>
                <div>
                    <label style="font-size:12px;color:var(--ink-2);display:block">Format</label>
                    <select name="format" style="width:100%">
                        <?php foreach ($formats as $key => $label): ?>
                            <option value="<?= $key ?>" <?= ($editTpl['format'] ?? 'A4') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <label style="font-size:12px;color:var(--ink-2);display:block;margin-top:14px">
                Treść szablonu (HTML dla A4/A5, komendy ZPL dla Zebry) — użyj placeholderów <code>{{...}}</code> z listy obok
            </label>
            <textarea name="body" rows="18" style="width:100%;font-family:var(--font-num);font-size:13px;margin-top:5px"><?= htmlspecialchars($editTpl['body'] ?? '') ?></textarea>

            <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--line)">
                <label style="font-size:12px;color:var(--ink-2);display:block">Obrazek do szablonu (logo, grafika) — opcjonalnie, tylko A4/A5</label>
                <?php if (!empty($editTpl['image_path'])): ?>
                    <div style="display:flex;align-items:center;gap:12px;margin-top:6px">
                        <img src="print_template_image.php?tpl=<?= (int) $editTpl['id'] ?>" alt="" style="height:56px;border:1px solid var(--line);border-radius:6px;background:var(--surface-2)">
                        <span style="font-size:12px;color:var(--ink-2)"><?= htmlspecialchars($editTpl['image_name'] ?? '') ?></span>
                        <label style="display:flex;gap:6px;align-items:center;font-size:12px;color:var(--danger-ink);cursor:pointer;margin-left:auto">
                            <input type="checkbox" name="remove_image" value="1"> Usuń obrazek
                        </label>
                    </div>
                    <p style="font-size:11px;color:var(--ink-2);margin:6px 0 0">Wybranie nowego pliku poniżej zastąpi obecny.</p>
                <?php endif; ?>
                <input type="file" name="template_image" accept="image/*" style="margin-top:8px">
            </div>

            <label style="display:flex;gap:8px;align-items:center;font-size:14px;margin-top:12px;cursor:pointer">
                <input type="checkbox" name="is_active" value="1" <?= ($editTpl['is_active'] ?? 1) ? 'checked' : '' ?>>
                Widoczny w menu „Drukuj / Eksportuj” przy zamówieniu
            </label>

            <p style="margin-top:16px;display:flex;gap:10px;align-items:center">
                <button class="btn" type="submit">Zapisz</button>
                <a class="btn secondary" href="print_templates.php">Anuluj</a>
                <?php if ($editTpl): ?>
                    <a class="btn secondary" href="print_document.php?tpl=<?= (int) $editTpl['id'] ?>" target="_blank" rel="noopener" style="margin-left:auto">Podgląd (dane przykładowe) ↗</a>
                <?php endif; ?>
            </p>
        </form>

        <div class="card" style="margin:0;background:var(--surface-2)">
            <strong style="font-size:13px">Dostępne placeholdery</strong>
            <table style="margin-top:8px;font-size:12px">
                <?php foreach ($placeholders as $key => $label): ?>
                    <tr><td style="font-family:var(--font-num);white-space:nowrap;padding:4px 6px 4px 0">{{<?= $key ?>}}</td><td style="color:var(--ink-2);padding:4px 0"><?= $label ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <?php if ($editTpl): ?>
        <form method="post" style="margin-top:16px;padding-top:14px;border-top:1px solid var(--line)"
              onsubmit="return confirm('Usunąć szablon „<?= htmlspecialchars($editTpl['name'], ENT_QUOTES) ?>”?');">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $editTpl['id'] ?>">
            <button class="btn danger" type="submit">Usuń szablon</button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between">
        <strong>Szablony (<?= count($templates) ?>)</strong>
        <a class="btn" href="print_templates.php?new=1">+ Nowy szablon</a>
    </div>
    <table style="margin-top:12px">
        <tr><th>Nazwa</th><th>Format</th><th>Status</th><th></th></tr>
        <?php foreach ($templates as $t): ?>
            <tr>
                <td><strong><?= htmlspecialchars($t['name']) ?></strong></td>
                <td><span class="pill muted"><?= htmlspecialchars($t['format']) ?></span></td>
                <td><span class="pill <?= $t['is_active'] ? 'ok' : 'bad' ?>"><?= $t['is_active'] ? 'Aktywny' : 'Ukryty' ?></span></td>
                <td style="text-align:right;white-space:nowrap">
                    <a class="btn secondary" href="print_document.php?tpl=<?= (int) $t['id'] ?>" target="_blank" rel="noopener">Podgląd</a>
                    <a class="btn secondary" href="print_templates.php?edit=<?= (int) $t['id'] ?>">Edytuj</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($templates === []): ?><tr><td colspan="4" style="color:var(--ink-2)">Brak szablonów.</td></tr><?php endif; ?>
    </table>
</div>

<!-- Szybki wydruk: plik upuszczony tutaj idzie prosto na drukarkę -->
<div class="card">
    <strong>Szybki wydruk</strong>
    <p style="color:var(--ink-2);font-size:13px;margin:6px 0 14px">
        Przeciągnij plik w pole poniżej — pójdzie prosto na drukarkę, bez zapisywania w bibliotece.
        Do jednorazowych wydruków: etykieta od kuriera, plik od kontrahenta, cokolwiek gotowego.
    </p>

    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
        <label style="font-size:12px;color:var(--ink-2)">Drukarka</label>
        <select id="drop-target" style="width:auto">
            <option value="zebra">Zebra (etykiety)</option>
            <option value="a4">Zwykła A4</option>
        </select>

        <label style="font-size:12px;color:var(--ink-2)">Kopie</label>
        <input id="drop-copies" type="number" value="1" min="1" max="50" style="width:70px">

        <label style="display:flex;gap:6px;align-items:center;font-size:12px;color:var(--ink-2);cursor:pointer">
            <input id="drop-save" type="checkbox">
            zapisz też w bibliotece
        </label>
    </div>

    <div id="dropzone" tabindex="0" role="button"
         style="border:2px dashed #c8cdd5;border-radius:12px;padding:30px 20px;text-align:center;
                cursor:pointer;background:#fbfcfd;transition:background .15s,border-color .15s">
        <div style="font-size:30px;line-height:1">🖨️</div>
        <div style="font-weight:600;margin-top:8px">Upuść plik tutaj, żeby wydrukować</div>
        <div style="color:var(--ink-2);font-size:12px;margin-top:4px">
            albo kliknij i wybierz z dysku · PDF, JPG, PNG, ZPL · do 8 MB · można kilka naraz
        </div>
        <input id="drop-input" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.bmp,.tif,.tiff,.zpl,.epl" style="display:none">
    </div>

    <div id="drop-log" style="margin-top:12px;font-size:13px"></div>
</div>

<!-- Biblioteka wydruków: gotowe pliki drukowane wielokrotnie, niezwiązane z zamówieniem -->
<div class="card">
    <strong>Biblioteka wydruków</strong>
    <p style="color:var(--ink-2);font-size:13px;margin:6px 0 14px">
        Gotowe pliki, które drukujesz wielokrotnie — etykiety produktowe, naklejki, metki.
        W odróżnieniu od szablonów powyżej nic tu nie jest wypełniane danymi zamówienia:
        wgrywasz gotowy plik i drukujesz go jednym kliknięciem. Obrazki i PDF-y agent sam
        przygotuje pod wybraną drukarkę.
    </p>

    <?php if ($printAssets === []): ?>
        <p style="color:var(--ink-2);font-size:13px;margin:0 0 14px">Biblioteka jest pusta — dodaj pierwszy plik poniżej.</p>
    <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;margin-bottom:18px">
            <?php foreach ($printAssets as $asset):
                $extension = strtolower(pathinfo((string) $asset['file_name'], PATHINFO_EXTENSION));
                $isImage = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
            ?>
                <div style="border:1px solid var(--line);border-radius:10px;padding:12px;display:flex;flex-direction:column;gap:10px">
                    <div style="height:130px;display:flex;align-items:center;justify-content:center;background:#fafbfc;border-radius:8px;overflow:hidden">
                        <?php if ($isImage): ?>
                            <img src="print_asset_image.php?id=<?= (int) $asset['id'] ?>" alt="" style="max-width:100%;max-height:130px;object-fit:contain">
                        <?php else: ?>
                            <span style="font-size:34px">📄</span>
                        <?php endif; ?>
                    </div>

                    <div>
                        <strong style="font-size:14px"><?= htmlspecialchars((string) $asset['name']) ?></strong>
                        <div style="color:var(--ink-2);font-size:11px;margin-top:2px">
                            <?= htmlspecialchars(strtoupper($extension)) ?> ·
                            <a href="print_asset_preview.php?id=<?= (int) $asset['id'] ?>">podgląd wydruku</a> ·
                            <a href="print_asset_image.php?id=<?= (int) $asset['id'] ?>" target="_blank" rel="noopener">otwórz plik</a>
                        </div>
                    </div>

                    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                        <label style="font-size:12px;color:var(--ink-2)">Kopie</label>
                        <input type="number" class="asset-copies" value="1" min="1" max="50" style="width:64px">
                        <a class="btn secondary asset-print" href="#" data-asset="<?= (int) $asset['id'] ?>" data-target="zebra"
                           style="padding:4px 9px;font-size:12px" title="Drukuj na drukarce etykiet">🖨➜ Zebra</a>
                        <a class="btn secondary asset-print" href="#" data-asset="<?= (int) $asset['id'] ?>" data-target="a4"
                           style="padding:4px 9px;font-size:12px" title="Drukuj na zwykłej drukarce">🖨➜ A4</a>
                    </div>

                    <form method="post" onsubmit="return confirm('Usunąć „<?= htmlspecialchars((string) $asset['name'], ENT_QUOTES) ?>” z biblioteki? Plik zostanie skasowany z serwera.')">
                        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="delete_asset">
                        <input type="hidden" name="asset_id" value="<?= (int) $asset['id'] ?>">
                        <button type="submit" class="btn secondary" style="padding:3px 8px;font-size:11px;color:#b42318">Usuń</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" style="border-top:1px solid var(--line);padding-top:14px">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="upload_asset">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <div>
                <label style="font-size:12px;color:var(--ink-2);display:block">Nazwa (opcjonalna)</label>
                <input name="asset_name" placeholder="np. Tealight sojowy 12 szt." style="width:260px">
            </div>
            <div>
                <label style="font-size:12px;color:var(--ink-2);display:block">Plik (JPG, PNG, PDF — max 8 MB)</label>
                <input type="file" name="asset_file" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf">
            </div>
            <button class="btn" type="submit">Dodaj do biblioteki</button>
        </div>
    </form>
</div>

<script>
// Szybki wydruk przez upuszczenie pliku.
(function () {
    var zone   = document.getElementById('dropzone');
    var input  = document.getElementById('drop-input');
    var log    = document.getElementById('drop-log');
    if (!zone || !input || !log) { return; }

    var CSRF = '<?= csrfToken() ?>';
    var IDLE_BG = '#fbfcfd', IDLE_BORDER = '#c8cdd5';

    function podswietl(aktywne) {
        zone.style.background   = aktywne ? '#eef4ff' : IDLE_BG;
        zone.style.borderColor  = aktywne ? '#7aa7f0' : IDLE_BORDER;
    }

    function wpis(tekst, stan) {
        var kolor = stan === 'err' ? '#b42318' : (stan === 'ok' ? '#137333' : 'var(--ink-2)');
        var linia = document.createElement('div');
        linia.style.cssText = 'padding:4px 0;color:' + kolor;
        linia.textContent = tekst;
        log.insertBefore(linia, log.firstChild);
        return linia;
    }

    function wyslij(plik) {
        var dane = new FormData();
        dane.append('csrf', CSRF);
        dane.append('file', plik);
        dane.append('target', document.getElementById('drop-target').value);
        dane.append('copies', Math.max(1, parseInt(document.getElementById('drop-copies').value, 10) || 1));
        dane.append('save', document.getElementById('drop-save').checked ? '1' : '0');

        var linia = wpis('⏳ ' + plik.name + ' — wysyłam…');

        fetch('print_drop.php', { method: 'POST', body: dane })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Serwer zwrócił nieczytelną odpowiedź.' }; }); })
            .then(function (data) {
                if (data.ok) {
                    var gdzie = data.target === 'a4' ? 'drukarka A4' : 'Zebra';
                    var kopie = data.jobs > 1 ? (', ' + data.jobs + ' kopie') : '';
                    linia.textContent = '✅ ' + data.file + ' → ' + gdzie + kopie
                                      + ' (zadanie #' + data.job_id + ')'
                                      + (data.saved ? ' · zapisano w bibliotece' : '');
                    linia.style.color = '#137333';
                    // Zapis w bibliotece zmienia listę powyżej - odświeżamy, żeby
                    // kafelek pojawił się bez ręcznego F5.
                    if (data.saved) { setTimeout(function () { location.reload(); }, 1200); }
                } else {
                    linia.textContent = '❌ ' + plik.name + ' — ' + (data.error || 'nieznany błąd');
                    linia.style.color = '#b42318';
                }
            })
            .catch(function () {
                linia.textContent = '❌ ' + plik.name + ' — błąd połączenia z serwerem.';
                linia.style.color = '#b42318';
            });
    }

    function obsluz(pliki) {
        // Kolejno, nie równolegle - przy kilku plikach zachowana zostaje kolejność
        // wydruku, a serwer nie dostaje naraz kilku megabajtów.
        Array.prototype.slice.call(pliki).forEach(wyslij);
    }

    ['dragenter', 'dragover'].forEach(function (nazwa) {
        zone.addEventListener(nazwa, function (e) { e.preventDefault(); e.stopPropagation(); podswietl(true); });
    });
    ['dragleave', 'drop'].forEach(function (nazwa) {
        zone.addEventListener(nazwa, function (e) { e.preventDefault(); e.stopPropagation(); podswietl(false); });
    });

    zone.addEventListener('drop', function (e) {
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
            obsluz(e.dataTransfer.files);
        }
    });

    zone.addEventListener('click', function () { input.click(); });
    zone.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
    });
    input.addEventListener('change', function () {
        if (input.files.length) { obsluz(input.files); input.value = ''; }
    });

    // Plik upuszczony obok pola nie może otworzyć się w karcie i zgubić pracy.
    ['dragover', 'drop'].forEach(function (nazwa) {
        window.addEventListener(nazwa, function (e) {
            if (!zone.contains(e.target)) { e.preventDefault(); }
        });
    });
})();

(function () {
    document.querySelectorAll('.asset-print').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            // Pole z liczbą kopii siedzi w tym samym kafelku co przycisk.
            var box = button.closest('div');
            var copiesField = box ? box.querySelector('.asset-copies') : null;
            var copies = copiesField ? Math.max(1, parseInt(copiesField.value, 10) || 1) : 1;
            var label = button.textContent;

            button.textContent = '⏳ wysyłam…';
            fetch('print_asset.php?id=' + button.dataset.asset
                  + '&target=' + button.dataset.target
                  + '&copies=' + copies, {
                method: 'POST', body: new URLSearchParams({csrf: <?= json_encode(csrfToken()) ?>})
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data.ok) {
                        button.textContent = '✅ ' + data.jobs + ' szt.';
                        setTimeout(function () { button.textContent = label; }, 2500);
                    } else {
                        button.textContent = label;
                        alert('Nie udało się wysłać: ' + (data.error || 'nieznany błąd'));
                    }
                })
                .catch(function () {
                    button.textContent = label;
                    alert('Błąd połączenia z serwerem.');
                });
        });
    });
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
