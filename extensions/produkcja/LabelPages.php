<?php
declare(strict_types=1);

namespace PaseExt\Produkcja;

use PaseExt\Produkcja\Ui as U;

/**
 * Etykiety CLP z gotowego pliku: do produktu (Magazyn) wgrywa się gotową etykietę (PNG, JPG, WEBP, PDF zamieniany w przeglądarce
 * na PNG) w prawdziwym rozmiarze i zaznacza prostokąt na numer partii (opcjonalnie datę produkcji i „najlepiej do”).
 * Druk z partii albo z karty produktu nakłada te dane na plik. Treść CLP jest w pliku - CRM jej nie układa ani nie sprawdza.
 */
final class LabelPages
{
    private const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    /** pdf.js tylko do zamiany PDF na obrazek przy wgrywaniu (ładowany, gdy wybrano PDF). */
    private const PDFJS = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/';
    /** Sumy SRI (npm pack pdfjs-dist@3.11.174, THIRD-PARTY.md). */
    private const PDFJS_SRI = ['pdf' => 'sha384-/1qUCSGwTur9vjf/z9lmu/eCUYbpOTgSjmpbMQZ1/CtX2v/WcAIKqRv+U1DUCG6e',
        'worker' => 'sha384-SnzOobpRMLXZ52iJvZm/C0fYw0OQemTXzTjIsdsfMcrCtCEe9qgzxTd3RSklO5x2'];

    public static function dir(): string
    {
        return TestPages::dir() . '/etykiety';
    }

    /** Plik z formularza: sprawdza, zapisuje na dysku. @return array{file:string,mime:string,px_w:int,px_h:int,source_name:string}|null */
    public static function storeFile(?array $f, string $sourceName = ''): ?array
    {
        if ($f === null || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $tmp = (string) ($f['tmp_name'] ?? '');
        if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('Nie udało się wgrać pliku etykiety.');
        }
        if (filesize($tmp) > 20 * 1024 * 1024) {
            throw new \RuntimeException('Plik jest za duży (najwyżej 20 MB).');
        }
        $info = getimagesize($tmp);
        $mime = is_array($info) ? (string) $info['mime'] : '';
        if (!isset(self::TYPES[$mime])) {
            throw new \RuntimeException('Etykieta musi być obrazkiem PNG, JPG albo WEBP (PDF zamienia się sam przy wyborze pliku).');
        }
        $dir = self::dir();
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Nie da się utworzyć katalogu na etykiety.');
        }
        $name = bin2hex(random_bytes(12)) . '.' . self::TYPES[$mime];
        if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
            throw new \RuntimeException('Nie udało się zapisać pliku etykiety.');
        }
        $src = trim($sourceName) !== '' ? $sourceName : (string) ($f['name'] ?? '');
        return ['file' => $name, 'mime' => $mime, 'px_w' => (int) $info[0], 'px_h' => (int) $info[1], 'source_name' => mb_substr(basename($src), 0, 255)];
    }

    public static function removeFile(string $name): void
    {
        $path = self::dir() . '/' . basename($name);
        if ($name !== '' && is_file($path)) {
            unlink($path);
        }
    }

    public static function fileUrl(array $l): string
    {
        return U::url('etykieta_plik', ['produkt' => (int) $l['product_id'], 'v' => substr(md5((string) $l['file']), 0, 8)]);
    }

    /** Rozdzielczość pliku w dpi przy zapisanym rozmiarze. */
    public static function dpi(array $l): int
    {
        return (int) round((int) $l['px_w'] / max(1.0, (float) $l['width_mm'] / 25.4));
    }

    // ---------------------------------------------------------------- etykieta

    public static function css(): string
    {
        return '.lb{position:relative;box-sizing:border-box;overflow:hidden;flex:none;background:#fff}.lb>img{position:absolute;inset:0;width:100%;height:100%;display:block}'
            . '.lb-f{position:absolute;display:flex;align-items:center;white-space:nowrap;overflow:hidden;line-height:1;font-family:Arial,Helvetica,sans-serif;font-weight:700;color:#000}';
    }

    /** Jedna etykieta: plik + dane w zaznaczonych miejscach. @param array<string,string> $values lot/produced/expires */
    public static function render(array $l, string $img, array $values): string
    {
        $w = (float) $l['width_mm'];
        $h = (float) $l['height_mm'];
        $just = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'][$l['boxes']['align']] ?? 'flex-start';
        $html = '<div class="lb" style="width:' . self::mm($w) . 'mm;height:' . self::mm($h) . 'mm"><img src="' . U::h($img) . '" alt="">';
        foreach (array_keys(Store::LABEL_FIELDS) as $k) {
            $b = $l['boxes'][$k] ?? null;
            $v = trim((string) ($values[$k] ?? ''));
            if ($b === null || $v === '') {
                continue;
            }
            $html .= '<span class="lb-f lb-' . $k . '" style="left:' . self::pct($b[0]) . ';top:' . self::pct($b[1]) . ';width:' . self::pct($b[2]) . ';height:' . self::pct($b[3])
                . ';font-size:' . self::mm($b[3] * $h * 0.8) . 'mm;justify-content:' . $just . '">' . U::h($v) . '</span>';
        }
        return $html . '</div>';
    }

    /** Zmniejsza czcionkę pola, gdy tekst nie mieści się w szerokości prostokąta. */
    public static function fitScript(): string
    {
        return '<script>(function(){function fit(){document.querySelectorAll(".lb-f").forEach(function(e){var f=parseFloat(e.style.fontSize);e.dataset.fs=e.dataset.fs||f;'
            . 'f=parseFloat(e.dataset.fs);e.style.fontSize=f+"mm";var n=0;while(e.scrollWidth>e.clientWidth+1&&n++<60){f*=0.94;e.style.fontSize=f.toFixed(3)+"mm";}});}'
            . 'window.lbFit=fit;if(document.readyState!=="loading")fit();else document.addEventListener("DOMContentLoaded",fit);})();</script>';
    }

    private static function mm(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    private static function pct(float $v): string
    {
        return rtrim(rtrim(number_format($v * 100, 2, '.', ''), '0'), '.') . '%';
    }

    /** Przykładowe dane do podglądu: ostatnia partia produktu albo przykład. @return array<string,string> */
    public static function sample(Store $s, array $p): array
    {
        $recent = $s->recentBatchesBySku([(string) ($p['sku'] ?? '')], 1)[mb_strtolower(trim((string) ($p['sku'] ?? '')))][0] ?? null;
        $b = $recent !== null ? $s->batchByCode((string) $recent['code']) : null;
        return ['lot' => (string) ($b['code'] ?? 'AQ001LX01AVBZ'), 'produced' => U::date($b['produced_at'] ?? date('Y-m-d')),
            'expires' => U::date($b['expires_at'] ?? date('Y-m-d', strtotime('+12 months')))];
    }

    /** Dane partii do etykiety. @return array<string,string> */
    public static function values(?array $batch, string $code = ''): array
    {
        return ['lot' => $batch !== null ? (string) $batch['code'] : $code,
            'produced' => !empty($batch['produced_at']) ? U::date((string) $batch['produced_at']) : '',
            'expires' => !empty($batch['expires_at']) ? U::date((string) $batch['expires_at']) : ''];
    }

    // ---------------------------------------------------------------- arkusz A4 do druku

    /** @param array<string,string> $hidden ukryte pola formularza */
    public static function sheet(array $l, array $values, int $n, string $page, array $hidden, string $pickers, string $info): string
    {
        $form = self::form($page, $hidden) . $pickers . ' Ile: <input type="number" name="n" min="1" max="500" value="' . $n . '" style="width:70px">' . self::skipInput() . ' <button>Pokaż</button></form>';
        return self::page($form, $info, self::blanks($l) . str_repeat(self::render($l, self::fileUrl($l), $values), $n));
    }

    /**
     * Jeden arkusz A4 z etykietami wielu partii (zaznaczonych na liście Partie): każda partia ze swoją etykietą CLP
     * (produkt CRM po SKU wyrobu) i swoim numerem; ile sztuk z każdej - n[id], domyślnie ilość z partii.
     * @param list<array<string,mixed>> $batches
     */
    public static function batchesSheet(Store $s, array $batches): string
    {
        $nIn = is_array($_GET['n'] ?? null) ? $_GET['n'] : [];
        $form = self::form('etykiety_partii', []);
        $items = [];
        $missing = [];
        $total = 0;
        foreach ($batches as $b) {
            $form .= '<input type="hidden" name="ids[]" value="' . U::h($b['id']) . '">';
            $w = $b['product_id'] !== null ? $s->product((string) $b['product_id']) : null;
            $crm = null;
            if (($w['sku'] ?? '') !== '') {
                try {
                    $crm = $s->one('SELECT id, name FROM products WHERE sku = ?', [(string) $w['sku']]);
                } catch (\PDOException) {
                    $crm = null;
                }
            }
            $l = $crm !== null ? $s->label((int) $crm['id']) : null;
            if ($l === null) {
                $missing[] = $b['code'] . ' (' . ($crm === null ? ($w === null ? 'partia bez wyrobu' : 'brak produktu o SKU wyrobu') : 'produkt bez etykiety') . ')';
                continue;
            }
            $n = isset($nIn[$b['id']]) ? max(0, min(500, (int) $nIn[$b['id']])) : max(1, (int) $b['qty']);
            $n = min($n, max(0, 1000 - $total));
            $total += $n;
            $items[] = [$b, $l, $n];
            $form .= '<label class="it"><b>' . U::h($b['code']) . '</b> <span>' . U::h($crm['name']) . '</span> <input type="number" name="n[' . U::h($b['id']) . ']" min="0" max="500" value="' . $n . '" style="width:64px"> szt.</label>';
        }
        $form .= self::skipInput() . ' <button>Pokaż</button></form>';
        $sheet = $items !== [] ? self::blanks($items[0][1]) : '';
        foreach ($items as [$b, $l, $n]) {
            $sheet .= str_repeat(self::render($l, self::fileUrl($l), self::values($b)), $n);
        }
        $info = $total . ' etykiet z ' . count($items) . ' partii.' . ($missing !== [] ? ' Pominięte, bo nie mają etykiety CLP: ' . implode(', ', $missing) . '.' : '');
        return self::page($form, $info, $sheet !== '' ? $sheet : '<p style="padding:12px">Żadna z zaznaczonych partii nie ma etykiety CLP. Wgraj etykietę na karcie produktu w Magazynie.</p>');
    }

    /** @param array<string,string> $hidden */
    private static function form(string $page, array $hidden): string
    {
        $form = '<form method="get" action="ext.php"><input type="hidden" name="page" value="produkcja.' . U::h($page) . '">';
        foreach ($hidden as $k => $v) {
            $form .= '<input type="hidden" name="' . U::h($k) . '" value="' . U::h($v) . '">';
        }
        return $form;
    }

    private static function skipInput(): string
    {
        return ' Pomiń: <input type="number" name="pomin" min="0" max="200" value="' . self::skip() . '" style="width:60px" title="Ile etykiet na arkuszu A4 jest już zużytych - druk zacznie się od następnej">';
    }

    /** Zużyte miejsca na rozpoczętym arkuszu. */
    private static function skip(): int
    {
        return max(0, min(200, (int) ($_GET['pomin'] ?? 0)));
    }

    private static function blanks(array $l): string
    {
        return str_repeat('<div class="lb lb-skip" aria-hidden="true" style="width:' . self::mm((float) $l['width_mm']) . 'mm;height:' . self::mm((float) $l['height_mm']) . 'mm"></div>', self::skip());
    }

    private static function page(string $form, string $info, string $sheet): string
    {
        return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Etykiety CLP</title>'
            . '<style>@page{size:A4;margin:6mm}body{font-family:system-ui,sans-serif;margin:0}.bar{padding:10px;background:#f6f5f2;display:flex;flex-wrap:wrap;gap:10px;align-items:center;font-size:14px}'
            . '.bar form{margin:0;display:flex;flex-wrap:wrap;gap:8px;align-items:center}.bar .it{display:inline-flex;gap:6px;align-items:center;background:#fff;border:1px solid #ddd;border-radius:6px;padding:3px 8px}'
            . '.bar .it b{font-family:ui-monospace,monospace}.bar .it span{color:#666;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
            . '.sheet{display:flex;flex-wrap:wrap;gap:2mm;padding:3mm;align-items:flex-start}'
            . '.info{color:#666;font-size:13px}.lb-skip{visibility:hidden}.sheet .lb{outline:0.2mm dashed #ccc}' . self::css()
            . '@media print{.bar{display:none!important}.sheet{padding:0}.sheet .lb{outline:0}}</style></head><body>'
            . '<div class="bar">' . $form . '<button onclick="print()">🖨 Drukuj</button><span class="info">' . U::h($info) . '</span></div>'
            . '<div class="sheet">' . $sheet . '</div>' . self::fitScript() . '</body></html>';
    }

    /** Podpowiedzi SKU: produkty, które mają etykietę. */
    public static function skuList(Store $s): string
    {
        $html = '<datalist id="lb-skus">';
        foreach ($s->labels() as $r) {
            $html .= '<option value="' . U::h($r['sku']) . '">' . U::h($r['name']) . '</option>';
        }
        return $html . '</datalist>';
    }

    // ---------------------------------------------------------------- karta produktu w Magazynie

    public static function section(Store $s, array $ctx, array $p): string
    {
        $pid = (int) $p['id'];
        $l = $s->label($pid);
        $edit = U::url('etykieta_edytuj', ['produkt' => $pid]);
        if ($l === null) {
            return '<p class="pr-small" style="margin:0">Brak etykiety. Wgraj gotową etykietę CLP (PNG, JPG albo PDF, np. z Canvy) i zaznacz miejsce na numer partii. '
                . 'Przy druku system wpisze tam numer partii.</p>' . ($ctx['canEdit'] ? '<p style="margin:10px 0 0"><a class="btn secondary" href="' . U::h($edit) . '">Wgraj etykietę</a></p>' : '');
        }
        $recent = $s->recentBatchesBySku([(string) $p['sku']], 8)[mb_strtolower(trim((string) $p['sku']))] ?? [];
        $dl = '';
        foreach ($recent as $b) {
            $dl .= '<option value="' . U::h($b['code']) . '">' . U::h(U::date($b['date']) . ' · ' . $b['qty'] . ' szt.') . '</option>';
        }
        $sample = self::sample($s, $p);
        return '<style>' . self::css() . '.lb-thumb{max-width:100%;overflow:auto;padding:8px;background:var(--surface-2);border-radius:8px;margin-bottom:10px}.lb-thumb .lb{box-shadow:0 0 0 1px var(--line)}</style>'
            . '<div class="lb-thumb">' . self::render($l, self::fileUrl($l), $sample) . '</div>'
            . '<p class="pr-small" style="margin:0 0 8px">' . U::n($l['width_mm']) . '×' . U::n($l['height_mm']) . ' mm'
            . ($ctx['canEdit'] ? ' · <a href="' . U::h($edit) . '">Zmień plik albo miejsce numeru</a>' : '') . '</p>'
            . '<form method="get" action="ext.php" target="_blank" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin:0">'
            . '<input type="hidden" name="page" value="produkcja.etykieta_produkt"><input type="hidden" name="produkt" value="' . $pid . '">'
            . '<label style="display:flex;flex-direction:column;gap:4px;font-size:13px">Numer partii<input name="partia" list="lb-batches-' . $pid . '" autocomplete="off" style="width:200px" placeholder="np. AQ001LX01AVBZ"></label>'
            . '<datalist id="lb-batches-' . $pid . '">' . $dl . '</datalist>'
            . '<label style="display:flex;flex-direction:column;gap:4px;font-size:13px">Ile<input type="number" name="n" min="1" max="500" value="1" style="width:70px"></label>'
            . '<button class="btn" type="submit">🖨 Drukuj etykiety</button></form>' . self::fitScript();
    }

    // ---------------------------------------------------------------- wgrywanie i zaznaczanie miejsca

    public static function editor(Store $s, array $ctx, array $p, ?array $l, ?array $posted = null): string
    {
        $pid = (int) $p['id'];
        $back = 'product_view.php?id=' . $pid;
        $boxes = $posted !== null ? Store::boxes((string) ($posted['boxes'] ?? '')) : ($l['boxes'] ?? ['align' => 'left']);
        $w = (string) ($posted['width_mm'] ?? ($l !== null ? U::n($l['width_mm']) : ''));
        $h = (string) ($posted['height_mm'] ?? ($l !== null ? U::n($l['height_mm']) : ''));
        $sample = self::sample($s, $p);
        $fields = '';
        foreach (Store::LABEL_FIELDS as $k => $label) {
            $fields .= '<label class="lb-pick"><input type="radio" name="lb_field" value="' . $k . '"' . ($k === 'lot' ? ' checked' : '') . '> <span class="lb-dot" style="background:' . ['lot' => '#d9480f', 'produced' => '#1971c2', 'expires' => '#2f9e44'][$k] . '"></span>' . $label
                . ($k === 'lot' ? '' : ' <small>(opcjonalnie)</small>') . '</label>';
        }
        $cfg = json_encode(['img' => $l !== null ? self::fileUrl($l) : '', 'pxW' => (int) ($l['px_w'] ?? 0), 'pxH' => (int) ($l['px_h'] ?? 0),
            'sample' => $sample, 'names' => Store::LABEL_FIELDS, 'pdfjs' => self::PDFJS, 'sri' => self::PDFJS_SRI], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        $html = U::style() . '<style>' . self::css() . '
.lb-ed-wrap{background:var(--surface-2);border-radius:8px;padding:12px;overflow:auto}
.lb-ed{position:relative;display:inline-block;max-width:100%;touch-action:none;user-select:none;cursor:crosshair;background:#fff;box-shadow:0 0 0 1px var(--line)}
.lb-ed img{display:block;max-width:100%;max-height:70vh;pointer-events:none}
.lb-ed .lb-box{position:absolute;border:2px solid #d9480f;background:rgba(217,72,15,.12);box-sizing:border-box;display:flex;align-items:center;white-space:nowrap;font:700 12px/1 Arial,sans-serif;color:#000;pointer-events:none}.lb-ed .lb-box b{display:block;overflow:hidden;max-width:100%}
.lb-ed .lb-box[data-k=produced]{border-color:#1971c2;background:rgba(25,113,194,.12)}.lb-ed .lb-box[data-k=expires]{border-color:#2f9e44;background:rgba(47,158,68,.12)}
.lb-dot{display:inline-block;width:12px;height:12px;border-radius:3px}
.lb-empty{padding:40px 20px;color:var(--ink-3);text-align:center}
.lb-pick{display:inline-flex!important;flex-direction:row!important;align-items:center;gap:6px;margin-right:14px;font-weight:600}
</style>' . U::back($back, (string) $p['name'])
            . '<form class="card pr-form" id="lb-form" method="post" enctype="multipart/form-data" action="' . U::h(U::url('etykieta_edytuj', ['produkt' => $pid])) . '">'
            . '<h3 style="margin:0">Etykieta CLP · ' . U::h($p['name']) . '</h3>' . U::csrf($ctx)
            . '<p class="pr-small" style="margin:0">Wgraj gotową etykietę (całą, z piktogramami i tekstami). Najlepiej PNG albo PDF w rozmiarze do druku. '
            . 'Potem zaznacz myszą albo palcem prostokąt, w który system wpisze numer partii. Za zgodność treści z CLP odpowiada plik.</p>'
            . '<div class="pr-grid">'
            . '<label>' . ($l !== null ? 'Nowy plik (zastąpi obecny)' : 'Plik etykiety') . '<input type="file" name="plik" id="lb-file" accept="image/png,image/jpeg,image/webp,application/pdf,.pdf"' . ($l === null ? ' required' : '') . '>'
            . '<small>' . ($l !== null ? 'Teraz: ' . U::h($l['source_name']) . ', ' . (int) $l['px_w'] . '×' . (int) $l['px_h'] . ' px' : 'PNG, JPG, WEBP albo PDF (pierwsza strona)') . '</small></label>'
            . U::input('width_mm', $w, 'Szerokość etykiety (mm)', 'id="lb-w" inputmode="decimal" required', 'rozmiar po wydrukowaniu')
            . U::input('height_mm', $h, 'Wysokość (mm)', 'id="lb-h" inputmode="decimal" placeholder="z proporcji pliku"', 'puste = z proporcji pliku')
            . U::select('align', ['left' => 'do lewej', 'center' => 'na środku', 'right' => 'do prawej'], (string) ($posted['align'] ?? $boxes['align']), 'Tekst w prostokącie', 'id="lb-align"')
            . '</div>'
            . '<input type="hidden" name="boxes" id="lb-boxes" value="' . U::h(json_encode($boxes)) . '"><input type="hidden" name="source_name" id="lb-src" value="">'
            . '<div><div style="margin-bottom:8px">Zaznaczasz: ' . $fields . '<button type="button" class="btn secondary" id="lb-clear" style="padding:4px 10px">Usuń zaznaczenie pola</button></div>'
            . '<div class="lb-ed-wrap"><div class="lb-ed" id="lb-ed">' . ($l !== null ? '<img id="lb-img" src="' . U::h(self::fileUrl($l)) . '" alt="Etykieta">' : '<div class="lb-empty" id="lb-none">Wybierz plik, żeby zobaczyć podgląd.</div>') . '</div></div>'
            . '<p class="pr-small" id="lb-msg" style="margin:8px 0 0"></p></div>'
            . '<div class="pr-actions"><button class="btn" type="submit">Zapisz</button><a class="btn secondary" href="' . U::h($back) . '">Anuluj</a>'
            . ($l !== null ? '<button class="btn secondary" type="submit" name="delete" value="1" formnovalidate style="margin-left:auto;color:var(--danger-ink)" onclick="return confirm(\'Usunąć etykietę tego produktu?\')">Usuń etykietę</button>' : '')
            . '</div></form>';
        return $html . '<script>window.LB=' . $cfg . ';</script><script>' . self::editorJs() . '</script>';
    }

    private static function editorJs(): string
    {
        return <<<'JS'
(function(){
var C=window.LB,ed=document.getElementById('lb-ed'),file=document.getElementById('lb-file'),W=document.getElementById('lb-w'),H=document.getElementById('lb-h'),
 hid=document.getElementById('lb-boxes'),msg=document.getElementById('lb-msg'),src=document.getElementById('lb-src'),boxes={};
try{boxes=JSON.parse(hid.value)||{};}catch(e){boxes={};}
var pxW=C.pxW,pxH=C.pxH,hAuto=!H.value;
function field(){var r=document.querySelector('input[name=lb_field]:checked');return r?r.value:'lot';}
function img(){return document.getElementById('lb-img');}
function save(){var o={align:document.getElementById('lb-align').value};for(var k in C.names){if(boxes[k])o[k]=boxes[k];}hid.value=JSON.stringify(o);}
function num(v){return parseFloat(String(v).replace(',','.'));}
function info(){var w=num(W.value),t=[];if(!boxes.lot)t.push('Zaznacz prostokąt na numer partii.');
 if(pxW&&w>0){var dpi=Math.round(pxW/(w/25.4));t.push('Rozdzielczość: '+dpi+' dpi'+(dpi<200?' - mało, wydruk może być nieostry. Wyeksportuj plik w większej rozdzielczości (najlepiej 300 dpi).':'.'));}
 msg.textContent=t.join(' ');msg.style.color=(!boxes.lot||(pxW&&w>0&&pxW/(w/25.4)<200))?'var(--warn-ink)':'';}
function draw(){ed.querySelectorAll('.lb-box').forEach(function(b){b.remove();});var i=img();if(!i)return;
 var h=num(H.value)||(num(W.value)*pxH/pxW)||0,scale=h>0?i.clientHeight/h:0;
 for(var k in C.names){var b=boxes[k];if(!b)continue;var d=document.createElement('div');d.className='lb-box';d.dataset.k=k;
  d.style.left=b[0]*100+'%';d.style.top=b[1]*100+'%';d.style.width=b[2]*100+'%';d.style.height=b[3]*100+'%';
  d.style.justifyContent={left:'flex-start',center:'center',right:'flex-end'}[document.getElementById('lb-align').value];
  var fs=b[3]*i.clientHeight*0.8;d.style.fontSize=fs+'px';var tx=document.createElement('b');tx.textContent=C.sample[k]||'';d.title=C.names[k];d.appendChild(tx);ed.appendChild(d);
  var n=0;while(tx.scrollWidth>d.clientWidth+1&&n++<60){fs*=0.94;d.style.fontSize=fs+'px';}}
 save();info();}
function setImg(url,w,h){var n=document.getElementById('lb-none');if(n)n.remove();var i=img();if(!i){i=document.createElement('img');i.id='lb-img';i.alt='Etykieta';ed.appendChild(i);}
 pxW=w;pxH=h;i.onload=draw;i.src=url;if(hAuto&&num(W.value)>0)H.value='';}
var start=null,cur=null;
function pt(e){var r=img().getBoundingClientRect();return [Math.max(0,Math.min(1,(e.clientX-r.left)/r.width)),Math.max(0,Math.min(1,(e.clientY-r.top)/r.height))];}
ed.addEventListener('pointerdown',function(e){if(!img())return;e.preventDefault();ed.setPointerCapture(e.pointerId);start=pt(e);});
ed.addEventListener('pointermove',function(e){if(!start)return;var p=pt(e);cur=[Math.min(start[0],p[0]),Math.min(start[1],p[1]),Math.abs(p[0]-start[0]),Math.abs(p[1]-start[1])];
 if(cur[2]>0.005&&cur[3]>0.005){boxes[field()]=cur.map(function(v){return Math.round(v*10000)/10000;});draw();}});
ed.addEventListener('pointerup',function(){start=null;cur=null;});
document.getElementById('lb-clear').onclick=function(){delete boxes[field()];draw();};
document.getElementById('lb-align').onchange=draw;W.oninput=function(){draw();};H.oninput=function(){hAuto=!H.value;draw();};
window.addEventListener('resize',draw);
function loadPdfJs(){if(window.pdfjsLib)return Promise.resolve(window.pdfjsLib);
 var lib=new Promise(function(ok,no){var s=document.createElement('script');s.src=C.pdfjs+'pdf.min.js';s.integrity=C.sri.pdf;s.crossOrigin='anonymous';
  s.onload=function(){ok(window.pdfjsLib);};s.onerror=function(){no(new Error('pdf.js'));};document.head.appendChild(s);});
 var wk=fetch(C.pdfjs+'pdf.worker.min.js',{integrity:C.sri.worker,mode:'cors'}).then(function(r){if(!r.ok)throw new Error('worker');return r.blob();});
 return Promise.all([lib,wk]).then(function(a){a[0].GlobalWorkerOptions.workerSrc=URL.createObjectURL(new Blob([a[1]],{type:'text/javascript'}));return a[0];});}
file.addEventListener('change',function(){var f=file.files[0];if(!f)return;src.value=f.name;
 if(f.type==='application/pdf'||/\.pdf$/i.test(f.name)){msg.textContent='Zamieniam PDF na obrazek do druku…';
  loadPdfJs().then(function(lib){return f.arrayBuffer().then(function(buf){return lib.getDocument({data:buf}).promise;});}).then(function(doc){return doc.getPage(1);}).then(function(pg){
   var v1=pg.getViewport({scale:1}),wmm=v1.width*25.4/72,hmm=v1.height*25.4/72,sc=Math.min(600/72,8000/Math.max(v1.width,v1.height)),v=pg.getViewport({scale:sc}),cv=document.createElement('canvas');
   cv.width=Math.round(v.width);cv.height=Math.round(v.height);var cx=cv.getContext('2d');cx.fillStyle='#fff';cx.fillRect(0,0,cv.width,cv.height);
   return pg.render({canvasContext:cx,viewport:v}).promise.then(function(){return new Promise(function(ok){cv.toBlob(function(b){ok([b,cv,wmm,hmm]);},'image/png');});});
  }).then(function(r){var png=new File([r[0]],f.name.replace(/\.pdf$/i,'')+'.png',{type:'image/png'}),dt=new DataTransfer();dt.items.add(png);file.files=dt.files;
   W.value=(Math.round(r[2]*10)/10+'').replace('.',',');H.value=(Math.round(r[3]*10)/10+'').replace('.',',');hAuto=false;setImg(URL.createObjectURL(png),r[1].width,r[1].height);
  }).catch(function(){file.value='';msg.textContent='Nie udało się otworzyć PDF. Zapisz etykietę jako PNG i wgraj ją jeszcze raz.';msg.style.color='var(--danger-ink)';});
  return;}
 var u=URL.createObjectURL(f),t=new Image();t.onload=function(){setImg(u,t.naturalWidth,t.naturalHeight);};t.src=u;});
document.getElementById('lb-form').addEventListener('submit',function(e){if(e.submitter&&e.submitter.name==='delete')return;save();
 if(!boxes.lot){e.preventDefault();info();msg.scrollIntoView({block:'center'});}});
if(img()){if(img().complete)draw();else img().onload=draw;}else info();
})();
JS;
    }

    // ---------------------------------------------------------------- zakładka Etykiety CLP

    public static function list(Store $s, array $ctx): string
    {
        $rows = $s->labels();
        $html = U::style() . '<style>' . self::css() . '.lb-mini{width:64px;height:48px;object-fit:contain;background:#fff;border:1px solid var(--line);border-radius:4px}</style>'
            . U::tabs('etykiety') . '<div class="pr-top"><p>Gotowe etykiety CLP produktów. Plik (np. z Canvy) wgrywasz na karcie produktu w Magazynie i zaznaczasz miejsce na numer partii. '
            . 'Druk z karty partii albo z karty produktu wpisuje tam numer partii.</p></div>';
        if ($ctx['canEdit']) {
            $opts = '';
            try {
                foreach ($s->all('SELECT id, sku, name FROM products ORDER BY name LIMIT 3000') as $p) {
                    $opts .= '<option value="' . U::h($p['sku'] !== '' ? $p['sku'] : '#' . $p['id']) . '">' . U::h($p['name']) . '</option>';
                }
            } catch (\PDOException) {
                $opts = '';
            }
            $html .= '<form class="card" method="get" action="ext.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-bottom:14px">'
                . '<input type="hidden" name="page" value="produkcja.etykieta_edytuj"><label style="display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:600">Dodaj etykietę do produktu (SKU albo nazwa)'
                . '<input name="sku" list="lb-all" required autocomplete="off" style="width:280px"></label><datalist id="lb-all">' . $opts . '</datalist><button class="btn">Dalej</button></form>';
        }
        if ($rows === []) {
            return $html . U::empty('Nie ma jeszcze etykiet', 'Wybierz produkt powyżej albo otwórz go w Magazynie i w sekcji „Etykieta CLP” wgraj plik.');
        }
        $html .= '<div class="card pr-table" style="padding:6px 10px"><table><thead><tr><th></th><th>Produkt</th><th class="pr-hide-m">SKU</th><th>Rozmiar</th><th class="pr-hide-m">Pola</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $f = array_values(array_intersect_key(Store::LABEL_FIELDS, $r['boxes']));
            $dpi = self::dpi($r);
            $html .= '<tr><td><img class="lb-mini" src="' . U::h(self::fileUrl($r)) . '" alt="" loading="lazy"></td>'
                . '<td><a href="product_view.php?id=' . (int) $r['product_id'] . '"><b>' . U::h($r['name']) . '</b></a>' . ($dpi < 200 ? ' <span class="pill bad" title="Wydruk może być nieostry">' . $dpi . ' dpi</span>' : '') . '</td>'
                . '<td class="pr-hide-m">' . U::h($r['sku']) . '</td><td class="pr-num">' . U::n($r['width_mm']) . '×' . U::n($r['height_mm']) . ' mm</td>'
                . '<td class="pr-hide-m pr-small">' . U::h(mb_strtolower(implode(', ', $f))) . '</td>'
                . '<td>' . ($ctx['canEdit'] ? '<a href="' . U::h(U::url('etykieta_edytuj', ['produkt' => $r['product_id']])) . '">Edytuj</a> · ' : '')
                . '<a target="_blank" rel="noopener" href="' . U::h(U::url('etykieta_produkt', ['produkt' => $r['product_id']])) . '">Drukuj</a></td></tr>';
        }
        return $html . '</tbody></table></div>';
    }
}
